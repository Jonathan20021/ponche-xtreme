<?php
/**
 * lib/vacation_calculator.php
 *
 * Cálculo de días de vacaciones conforme a la normativa dominicana y a la
 * jornada de la empresa.
 *
 * EL PROBLEMA QUE RESUELVE: el sistema contaba días CALENDARIO
 * (`$start->diff($end)->days + 1`), así que un período de dos semanas consumía
 * 14 días aunque incluyera 2 domingos y 2 sábados de media jornada. Por eso
 * había colaboradores con 20 días usados de 14 disponibles y saldos negativos.
 *
 * Reglas que aplica (todas configurables desde settings.php):
 *   - Lunes a viernes  -> 1 día
 *   - Sábado           -> 0.5 día (la jornada del sábado es de 4 horas)
 *   - Domingo          -> 0 (no se cuenta)
 *   - Feriado          -> 0 (no se cuenta, aunque caiga entre semana)
 *
 * Los feriados salen de `payroll_holidays`, que es la tabla que ya usa la
 * nómina: así no hay dos listas de feriados que puedan contradecirse.
 */

require_once __DIR__ . '/../db.php';

if (!function_exists('vacationSettings')) {
    /** @return array<string,string> */
    function vacationSettings(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $defaults = [
            'vacation_saturday_value'      => '0.5',  // sábado = media jornada (4 h)
            'vacation_sunday_value'        => '0',    // domingo no cuenta
            'vacation_holiday_value'       => '0',    // feriado no cuenta
            'vacation_base_days'           => '14',   // días por año (art. 177 CT)
            'vacation_days_after_5_years'  => '18',   // a partir del 5.º año
            'vacation_notice_enabled'      => '1',
            'vacation_notice_days_before'  => '30',
            'vacation_notice_roles'        => 'HR,Admin',
            'vacation_notice_user_ids'     => '',
        ];

        try {
            $keys = array_keys($defaults);
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($ph)");
            $stmt->execute($keys);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $defaults[$r['setting_key']] = (string) ($r['setting_value'] ?? '');
            }
        } catch (Throwable $e) {
            error_log('vacationSettings: ' . $e->getMessage());
        }

        $cache = $defaults;
        return $cache;
    }
}

if (!function_exists('vacationHolidayMap')) {
    /**
     * Feriados activos dentro de un rango. Misma fuente que la nómina.
     *
     * @return array<string,string> fecha (Y-m-d) => nombre
     */
    function vacationHolidayMap(PDO $pdo, string $from, string $to): array
    {
        $out = [];
        try {
            $stmt = $pdo->prepare("
                SELECT holiday_date, name
                FROM payroll_holidays
                WHERE is_active = 1 AND holiday_date BETWEEN ? AND ?
            ");
            $stmt->execute([$from, $to]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[$r['holiday_date']] = $r['name'];
            }
        } catch (Throwable $e) {
            error_log('vacationHolidayMap: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('calculateVacationDays')) {
    /**
     * Días de vacaciones que consume un período.
     *
     * @return array{
     *   total:float,
     *   calendar_days:int,
     *   detail:array<int,array{date:string,weekday:int,label:string,value:float,reason:string}>,
     *   breakdown:array{full:int,half:int,sundays:int,holidays:int}
     * }
     */
    function calculateVacationDays(PDO $pdo, string $startDate, string $endDate): array
    {
        $empty = [
            'total' => 0.0, 'calendar_days' => 0, 'detail' => [],
            'breakdown' => ['full' => 0, 'half' => 0, 'sundays' => 0, 'holidays' => 0],
        ];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return $empty;
        }
        if ($endDate < $startDate) {
            return $empty;
        }

        $cfg          = vacationSettings($pdo);
        $saturdayVal  = (float) ($cfg['vacation_saturday_value'] ?? 0.5);
        $sundayVal    = (float) ($cfg['vacation_sunday_value'] ?? 0);
        $holidayVal   = (float) ($cfg['vacation_holiday_value'] ?? 0);

        $holidays = vacationHolidayMap($pdo, $startDate, $endDate);

        $dias = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

        $total = 0.0;
        $detail = [];
        $breakdown = ['full' => 0, 'half' => 0, 'sundays' => 0, 'holidays' => 0];

        $cursor = $startDate;
        $calendarDays = 0;

        while ($cursor <= $endDate) {
            $calendarDays++;
            $dow = (int) date('N', strtotime($cursor)); // 1=lunes ... 7=domingo

            // El feriado manda sobre el día de la semana: un feriado en martes
            // tampoco se cuenta.
            if (isset($holidays[$cursor])) {
                $value = $holidayVal;
                $reason = 'Feriado: ' . $holidays[$cursor];
                $breakdown['holidays']++;
            } elseif ($dow === 7) {
                $value = $sundayVal;
                $reason = 'Domingo';
                $breakdown['sundays']++;
            } elseif ($dow === 6) {
                $value = $saturdayVal;
                $reason = 'Sábado (media jornada)';
                if ($value > 0) { $breakdown['half']++; }
            } else {
                $value = 1.0;
                $reason = 'Día laborable';
                $breakdown['full']++;
            }

            $total += $value;
            $detail[] = [
                'date'    => $cursor,
                'weekday' => $dow,
                'label'   => $dias[$dow] ?? '',
                'value'   => $value,
                'reason'  => $reason,
            ];

            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        return [
            'total'         => round($total, 2),
            'calendar_days' => $calendarDays,
            'detail'        => $detail,
            'breakdown'     => $breakdown,
        ];
    }
}

if (!function_exists('vacationEntitlementDays')) {
    /**
     * Días que le corresponden al colaborador según su antigüedad.
     *
     * Código de Trabajo de RD, art. 177: 14 días laborables al cumplir un año
     * de trabajo continuo; 18 días a partir de los cinco años.
     */
    function vacationEntitlementDays(PDO $pdo, ?string $hireDate, ?string $atDate = null): float
    {
        $cfg  = vacationSettings($pdo);
        $base = (float) ($cfg['vacation_base_days'] ?? 14);
        $after5 = (float) ($cfg['vacation_days_after_5_years'] ?? 18);

        if (empty($hireDate)) {
            return $base;
        }

        try {
            $hire = new DateTimeImmutable($hireDate);
            $at   = new DateTimeImmutable($atDate ?: 'today');
        } catch (Throwable $e) {
            return $base;
        }

        if ($at < $hire) {
            return 0.0;
        }

        $years = (int) $hire->diff($at)->y;

        // Antes del primer año no ha generado derecho a vacaciones.
        if ($years < 1) {
            return 0.0;
        }

        return $years >= 5 ? $after5 : $base;
    }
}

if (!function_exists('vacationRecalculateBalance')) {
    /**
     * Reconstruye el balance de un colaborador para un año, sumando sus
     * solicitudes APROBADAS con el cálculo correcto.
     *
     * @return array{total:float, used:float, remaining:float, requests:int}
     */
    function vacationRecalculateBalance(PDO $pdo, int $employeeId, ?int $year = null): array
    {
        $year = $year ?: (int) date('Y');

        $hireDate = null;
        try {
            $st = $pdo->prepare("SELECT hire_date FROM employees WHERE id = ?");
            $st->execute([$employeeId]);
            $hireDate = $st->fetchColumn() ?: null;
        } catch (Throwable $e) { /* sin fecha de ingreso */ }

        $total = vacationEntitlementDays($pdo, $hireDate, $year . '-12-31');

        $used = 0.0;
        $count = 0;
        try {
            $st = $pdo->prepare("
                SELECT id, start_date, end_date, total_days
                FROM vacation_requests
                WHERE employee_id = ?
                  AND UPPER(status) IN ('APPROVED','APROBADO')
                  AND YEAR(start_date) = ?
            ");
            $st->execute([$employeeId, $year]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $count++;
                $used += (float) $r['total_days'];
            }
        } catch (Throwable $e) {
            error_log('vacationRecalculateBalance: ' . $e->getMessage());
        }

        $remaining = $total - $used;

        try {
            $up = $pdo->prepare("
                INSERT INTO vacation_balances (employee_id, year, total_days, used_days, remaining_days)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    total_days = VALUES(total_days),
                    used_days = VALUES(used_days),
                    remaining_days = VALUES(remaining_days)
            ");
            $up->execute([$employeeId, $year, $total, $used, $remaining]);
        } catch (Throwable $e) {
            error_log('vacationRecalculateBalance guardar: ' . $e->getMessage());
        }

        return ['total' => $total, 'used' => round($used, 2), 'remaining' => round($remaining, 2), 'requests' => $count];
    }
}

if (!function_exists('vacationUpcomingAnniversaries')) {
    /**
     * Colaboradores próximos a cumplir un año de antigüedad, para planificar
     * sus vacaciones antes de que les corresponda el derecho.
     *
     * @return array<int,array<string,mixed>>
     */
    function vacationUpcomingAnniversaries(PDO $pdo, int $daysBefore = 30): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT e.id AS employee_id, e.employee_code, e.first_name, e.last_name,
                       e.hire_date, e.position, d.name AS department_name,
                       DATE_ADD(e.hire_date, INTERVAL 1 YEAR) AS anniversary,
                       DATEDIFF(DATE_ADD(e.hire_date, INTERVAL 1 YEAR), CURDATE()) AS days_left
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE e.employment_status <> 'TERMINATED'
                  AND e.hire_date IS NOT NULL
                  AND DATEDIFF(DATE_ADD(e.hire_date, INTERVAL 1 YEAR), CURDATE()) BETWEEN 0 AND ?
                ORDER BY days_left ASC
            ");
            $stmt->execute([max(1, $daysBefore)]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('vacationUpcomingAnniversaries: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('vacationCalendarByMonth')) {
    /**
     * Calendario de vacaciones: en qué mes le toca a cada colaborador.
     *
     * Combina dos cosas:
     *   - Vacaciones YA solicitadas o aprobadas (fecha real).
     *   - Vacaciones PREVISTAS: el mes del aniversario de ingreso, que es cuando
     *     le corresponde el disfrute a quien todavía no ha solicitado.
     *
     * @return array<int,array{month:int,name:string,scheduled:array,expected:array}>
     */
    function vacationCalendarByMonth(PDO $pdo, int $year): array
    {
        $meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        $out = [];
        foreach ($meses as $n => $name) {
            $out[$n] = ['month' => $n, 'name' => $name, 'scheduled' => [], 'expected' => []];
        }

        // 1. Vacaciones ya solicitadas en el año
        try {
            $stmt = $pdo->prepare("
                SELECT vr.id, vr.employee_id, vr.start_date, vr.end_date, vr.total_days,
                       vr.status, vr.vacation_type,
                       e.first_name, e.last_name, e.employee_code, e.hire_date,
                       d.name AS department_name
                FROM vacation_requests vr
                INNER JOIN employees e ON e.id = vr.employee_id
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE YEAR(vr.start_date) = ?
                  AND UPPER(vr.status) NOT IN ('REJECTED','RECHAZADO','CANCELLED','CANCELADO')
                ORDER BY vr.start_date
            ");
            $stmt->execute([$year]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $m = (int) date('n', strtotime($r['start_date']));
                $r['name'] = trim($r['first_name'] . ' ' . $r['last_name']);
                $out[$m]['scheduled'][] = $r;
            }
        } catch (Throwable $e) {
            error_log('vacationCalendarByMonth solicitadas: ' . $e->getMessage());
        }

        // 2. Previstas por aniversario, solo para quien no tiene solicitud ese año
        try {
            $conSolicitud = [];
            foreach ($out as $mes) {
                foreach ($mes['scheduled'] as $s) {
                    $conSolicitud[(int) $s['employee_id']] = true;
                }
            }

            $stmt = $pdo->prepare("
                SELECT e.id AS employee_id, e.first_name, e.last_name, e.employee_code,
                       e.hire_date, e.position, d.name AS department_name,
                       TIMESTAMPDIFF(YEAR, e.hire_date, ?) AS years_at_year_end
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE e.employment_status <> 'TERMINATED' AND e.hire_date IS NOT NULL
                ORDER BY e.first_name, e.last_name
            ");
            $stmt->execute([$year . '-12-31']);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
                if (isset($conSolicitud[(int) $e['employee_id']])) {
                    continue;
                }
                // Sin cumplir el año todavía no le corresponde disfrute.
                if ((int) $e['years_at_year_end'] < 1) {
                    continue;
                }
                $m = (int) date('n', strtotime($e['hire_date']));
                $e['name'] = trim($e['first_name'] . ' ' . $e['last_name']);
                $e['entitlement'] = vacationEntitlementDays($pdo, $e['hire_date'], $year . '-12-31');
                $out[$m]['expected'][] = $e;
            }
        } catch (Throwable $e) {
            error_log('vacationCalendarByMonth previstas: ' . $e->getMessage());
        }

        return array_values($out);
    }
}
