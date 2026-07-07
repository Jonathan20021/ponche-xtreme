<?php
/**
 * lib/agent_hours.php
 *
 * Cálculo de horas trabajadas por período para el PORTAL DEL AGENTE, alineado
 * EXACTO con la nómina (hr/payroll.php). Fuente única para "Mis Horas" y el
 * dashboard del agente, para que el agente vea siempre lo mismo que se le paga.
 *
 * Regla de fuente (idéntica a hr/payroll.php:214-229):
 *   - payroll_source = 'manual'   -> horas del ponche (tabla attendance).
 *   - payroll_source = 'vicidial' -> horas pagables de Vicidial SI hay datos en
 *     el período; si NO hay ningún dato de Vicidial, se cae al ponche como
 *     respaldo (nunca mostrar 0 cuando la nómina pagaría desde el ponche).
 *
 * Las horas extra se separan por semana ISO (lunes-domingo): las primeras 44h
 * de la semana son regulares y solo el excedente cuenta como extra. Si el día
 * es feriado y el usuario califica, se aplica el multiplicador del feriado.
 */

require_once __DIR__ . '/work_hours_calculator.php';
require_once __DIR__ . '/vicidial_api_client.php';
require_once __DIR__ . '/../hr/payroll_functions.php';

if (!function_exists('computePeriodHoursForUser')) {
    /**
     * @param string[] $paidTypeSlugs  Slugs de tipos de asistencia pagados (ponche).
     * @return array{
     *   total_seconds:int, regular_seconds:int, overtime_seconds:int,
     *   days_worked:int, by_day:array<string,int>, holiday_days:array<string,array>,
     *   source_used:string
     * }
     */
    function computePeriodHoursForUser(
        PDO $pdo,
        int $userId,
        string $startDate,
        string $endDate,
        array $paidTypeSlugs,
        float $weeklyThresholdHours = 44.0,
        bool $applyHolidayDouble = false,
        string $payrollSource = 'manual'
    ): array {
        $today = date('Y-m-d');
        $effectiveEnd = ($endDate > $today) ? $today : $endDate;
        $contextStart = getIsoWeekStartDate($startDate);

        $result = [
            'total_seconds' => 0,
            'regular_seconds' => 0,
            'overtime_seconds' => 0,
            'days_worked' => 0,
            'by_day' => [],
            'holiday_days' => [],
            'source_used' => $payrollSource === 'vicidial' ? 'vicidial' : 'manual',
        ];

        if ($startDate > $effectiveEnd) {
            return $result;
        }

        $holidaysMap = getPayrollHolidaysMap($pdo, $startDate, $effectiveEnd);

        // 1) Ponche (attendance) — SIEMPRE se calcula, para poder servir de respaldo
        //    a un agente de Vicidial que no tenga datos en el período (igual que la
        //    nómina: nunca pagar 0).
        $stmt = $pdo->prepare("
            SELECT id, type, timestamp, DATE(timestamp) AS work_date
            FROM attendance
            WHERE user_id = ?
              AND DATE(timestamp) BETWEEN ? AND ?
            ORDER BY timestamp ASC
        ");
        $stmt->execute([$userId, $contextStart, $effectiveEnd]);
        $punchRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dailyWorkSeconds = calculateDailyWorkSecondsFromPunchRows($punchRows, $paidTypeSlugs);

        // 2) Si el agente se paga por Vicidial: Vicidial manda en los días que
        //    registró, y el ponche RESPALDA los días sin fila en Vicidial (mismo
        //    merge por día que hr/payroll.php, para que estas horas coincidan EXACTO
        //    con lo que se paga). Sin ningún dato de Vicidial, todo el ponche.
        if ($payrollSource === 'vicidial') {
            $punchDaily = $dailyWorkSeconds; // ponche ya calculado arriba
            $vd = vicidialGetPaidSecondsByDate($pdo, $userId, $contextStart, $effectiveEnd);
            if (($vd['days'] ?? 0) > 0) {
                $seenDays = array_flip($vd['seen_dates'] ?? array_keys($vd['by_date']));
                $backfill = array_diff_key($punchDaily, $seenDays);
                $dailyWorkSeconds = $vd['by_date'] + $backfill;
                $result['source_used'] = empty($backfill) ? 'vicidial' : 'mixta';
            } else {
                $result['source_used'] = 'manual'; // respaldo ponche total (sin datos Vicidial)
            }
        }

        $weeklySplit = splitWeeklyRegularOvertimeSeconds(
            $dailyWorkSeconds,
            (int) round($weeklyThresholdHours * 3600)
        );

        foreach ($weeklySplit['by_day'] as $date => $daySplit) {
            if ($date < $startDate || $date > $effectiveEnd) {
                continue;
            }

            $workSeconds = (int) ($daySplit['work_seconds'] ?? 0);
            $rawSeconds = $workSeconds;
            $isHoliday = isset($holidaysMap[$date]);
            $regSeconds = (int) ($daySplit['regular_seconds'] ?? 0);
            $otSeconds = (int) ($daySplit['overtime_seconds'] ?? 0);

            if ($isHoliday && $applyHolidayDouble) {
                $multiplier = (float) $holidaysMap[$date]['multiplier'];
                $regSeconds = (int) round($regSeconds * $multiplier);
                $otSeconds = (int) round($otSeconds * $multiplier);
            }

            $daySeconds = $regSeconds + $otSeconds;
            $result['days_worked']++;
            $result['total_seconds'] += $daySeconds;
            $result['regular_seconds'] += $regSeconds;
            $result['overtime_seconds'] += $otSeconds;
            $result['by_day'][$date] = $daySeconds;

            if ($isHoliday) {
                $result['holiday_days'][$date] = [
                    'name' => $holidaysMap[$date]['name'],
                    'multiplier' => (float) $holidaysMap[$date]['multiplier'],
                    'applied' => $applyHolidayDouble,
                    'raw_seconds' => $rawSeconds,
                ];
            }
        }

        ksort($result['by_day']);
        return $result;
    }
}

if (!function_exists('getAgentVisiblePeriods')) {
    /**
     * Períodos de nómina habilitados para que los agentes los vean.
     * @return array<int,array<string,mixed>>
     */
    function getAgentVisiblePeriods(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query("
                SELECT id, name, period_type, start_date, end_date, payment_date, status
                FROM payroll_periods
                WHERE visible_to_agents = 1
                ORDER BY start_date DESC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('pickCurrentAgentPeriod')) {
    /**
     * Escoge el período cuyo rango contiene HOY; si ninguno, el más reciente.
     * @param array<int,array<string,mixed>> $periods
     */
    function pickCurrentAgentPeriod(array $periods, string $todayISO): ?array
    {
        foreach ($periods as $p) {
            if ($todayISO >= $p['start_date'] && $todayISO <= $p['end_date']) {
                return $p;
            }
        }
        return $periods[0] ?? null;
    }
}
