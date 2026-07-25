<?php
/**
 * lib/overtime_reports.php
 *
 * Dos reportes de nómina:
 *
 *   1. generateWeeklyOvertimeReport()  -> horas extra acumuladas por colaborador
 *      en la semana (lunes a domingo), con el excedente sobre las 44 h de ley.
 *   2. generateDailyOver8hReport()     -> quiénes pasaron de 8 horas ayer.
 *
 * Las horas NO se recalculan aquí: se piden a computePeriodHoursForUser()
 * (lib/agent_hours.php), que es la misma función que alimenta la nómina y el
 * portal del agente. Eso garantiza que el reporte no pueda decir una cosa y el
 * pago otra, y respeta automáticamente si el colaborador se mide por ponche o
 * por Vicidial.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/agent_hours.php';
require_once __DIR__ . '/work_hours_calculator.php';

if (!function_exists('overtimeReportSettings')) {
    /** @return array<string,string> */
    function overtimeReportSettings(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $defaults = [
            'overtime_report_enabled'          => '1',
            'overtime_report_time'             => '08:00',
            'overtime_report_weekday'          => '1',
            'overtime_report_recipients'       => '',
            'overtime_report_min_hours'        => '0',
            'over8h_report_enabled'            => '1',
            'over8h_report_time'               => '08:15',
            'over8h_report_threshold_hours'    => '8',
            'over8h_report_recipients'         => '',
            'over8h_report_exclude_weekends'   => '1',
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
            error_log('overtimeReportSettings: ' . $e->getMessage());
        }
        $cache = $defaults;
        return $cache;
    }
}

if (!function_exists('overtimeReportRecipients')) {
    /** @return string[] */
    function overtimeReportRecipients(PDO $pdo, string $key): array
    {
        $raw = (string) (overtimeReportSettings($pdo)[$key] ?? '');
        if (trim($raw) === '') {
            return [];
        }
        $mails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($mails, static fn($m) => $m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('overtimeReportEmployees')) {
    /**
     * Colaboradores activos con su fuente de horas.
     * @return array<int,array<string,mixed>>
     */
    function overtimeReportEmployees(PDO $pdo): array
    {
        try {
            return $pdo->query("
                SELECT e.id AS employee_id, e.employee_code, e.first_name, e.last_name,
                       e.position, u.id AS user_id, u.full_name, u.username,
                       COALESCE(u.payroll_source, 'manual') AS payroll_source,
                       u.hourly_rate, u.hourly_rate_dop, u.monthly_salary, u.monthly_salary_dop,
                       u.preferred_currency, u.compensation_type, u.role,
                       u.overtime_multiplier,
                       d.name AS department_name,
                       c.name AS campaign_name
                FROM employees e
                INNER JOIN users u ON u.id = e.user_id
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN campaigns c ON c.id = e.campaign_id
                WHERE e.employment_status <> 'TERMINATED' AND u.is_active = 1
                ORDER BY e.first_name, e.last_name
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('overtimeReportEmployees: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('overtimeReportSpanishDate')) {
    function overtimeReportSpanishDate(string $date): string
    {
        $dias = ['Monday' => 'lunes', 'Tuesday' => 'martes', 'Wednesday' => 'miércoles',
                 'Thursday' => 'jueves', 'Friday' => 'viernes', 'Saturday' => 'sábado', 'Sunday' => 'domingo'];
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        return sprintf('%s %d de %s de %s',
            $dias[date('l', $ts)] ?? '', (int) date('j', $ts),
            $meses[(int) date('n', $ts)] ?? '', date('Y', $ts));
    }
}

if (!function_exists('overtimeFormatHours')) {
    function overtimeFormatHours(float $hours): string
    {
        $neg = $hours < 0;
        $hours = abs($hours);
        $h = (int) floor($hours);
        $m = (int) round(($hours - $h) * 60);
        if ($m === 60) { $h++; $m = 0; }
        return ($neg ? '-' : '') . $h . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }
}

// ===========================================================================
// 1. Reporte SEMANAL de horas extra acumuladas
// ===========================================================================
if (!function_exists('generateWeeklyOvertimeReport')) {
    /**
     * @param string|null $weekStart lunes de la semana; por defecto la semana
     *                               COMPLETA anterior (no la que está en curso:
     *                               reportar extras a medias no sirve).
     */
    function generateWeeklyOvertimeReport(PDO $pdo, ?string $weekStart = null): array
    {
        if ($weekStart === null) {
            $weekStart = date('Y-m-d', strtotime('monday last week'));
        }
        $weekStart = getIsoWeekStartDate($weekStart);
        $weekEnd   = getIsoWeekEndDate($weekStart);

        $cfg       = overtimeReportSettings($pdo);
        $minHours  = max(0.0, (float) ($cfg['overtime_report_min_hours'] ?? 0));
        $paidTypes = getPaidAttendanceTypeSlugs($pdo);

        // Multiplicador de recargo del sistema (ley RD: 1.35 sobre 44 h/semana).
        $globalMultiplier = 1.35;
        try {
            $sc = getScheduleConfig($pdo);
            if (!empty($sc['overtime_multiplier']) && (float) $sc['overtime_multiplier'] > 0) {
                $globalMultiplier = (float) $sc['overtime_multiplier'];
            }
        } catch (Throwable $e) { /* se queda 1.35 */ }

        $rows = [];
        $noRate = [];   // con horas extra pero sin tarifa cargada: no se puede costear
        $totOvertime = 0.0; $totRegular = 0.0; $totCost = 0.0;

        foreach (overtimeReportEmployees($pdo) as $emp) {
            $hours = computePeriodHoursForUser(
                $pdo,
                (int) $emp['user_id'],
                $weekStart,
                $weekEnd,
                $paidTypes,
                44.0,
                false,
                (string) $emp['payroll_source']
            );

            $otHours  = $hours['overtime_seconds'] / 3600;
            $regHours = $hours['regular_seconds'] / 3600;

            if ($otHours <= $minHours || $otHours <= 0) {
                $totRegular += $regHours;
                continue;
            }

            // Costo estimado del recargo, en la moneda del colaborador.
            $prefersDop = strtoupper((string) ($emp['preferred_currency'] ?? 'DOP')) === 'DOP';
            $paymentType = resolvePaymentType(
                $emp['compensation_type'] ?? '',
                $emp['role'] ?? '',
                max((float) $emp['monthly_salary'], (float) $emp['monthly_salary_dop'])
            );

            // Tarifa para estimar el costo del recargo. Si la moneda preferida no
            // tiene monto cargado se usa la otra: si no, el reporte muestra costo
            // 0 para gente que sí cobra (pasa mucho con los agentes).
            if ($paymentType === 'fixed') {
                $monthly = $prefersDop ? (float) $emp['monthly_salary_dop'] : (float) $emp['monthly_salary'];
                if ($monthly <= 0) {
                    $monthly = $prefersDop ? (float) $emp['monthly_salary'] : (float) $emp['monthly_salary_dop'];
                    $prefersDop = !$prefersDop;
                }
                // Mismo factor que la nómina (23.83 días × 8 h de la ley RD).
                $rate = $monthly > 0 ? round($monthly / 23.83 / 8, 2) : 0.0;
            } else {
                $rate = $prefersDop ? (float) $emp['hourly_rate_dop'] : (float) $emp['hourly_rate'];
                if ($rate <= 0) {
                    $rate = $prefersDop ? (float) $emp['hourly_rate'] : (float) $emp['hourly_rate_dop'];
                    if ($rate > 0) {
                        $prefersDop = !$prefersDop;
                    }
                }
            }

            $multiplier = (!empty($emp['overtime_multiplier']) && (float) $emp['overtime_multiplier'] > 0)
                ? (float) $emp['overtime_multiplier']
                : $globalMultiplier;

            $cost = round($otHours * $rate * $multiplier, 2);

            if ($rate <= 0) {
                // Sin tarifa no se puede costear el recargo. Se avisa para que
                // RRHH la complete, en vez de mostrar un RD$0.00 engañoso.
                $noRate[] = trim($emp['first_name'] . ' ' . $emp['last_name']) ?: $emp['full_name'];
            }

            $rows[] = [
                'employee_id'   => (int) $emp['employee_id'],
                'employee_code' => $emp['employee_code'],
                'name'          => trim($emp['first_name'] . ' ' . $emp['last_name']) ?: $emp['full_name'],
                'department'    => $emp['department_name'] ?: 'Sin departamento',
                'campaign'      => $emp['campaign_name'] ?: '—',
                'source'        => $hours['source_used'],
                'days_worked'   => (int) $hours['days_worked'],
                'regular_hours' => round($regHours, 2),
                'overtime_hours'=> round($otHours, 2),
                'total_hours'   => round($hours['total_seconds'] / 3600, 2),
                'rate'          => $rate,
                'multiplier'    => $multiplier,
                'currency'      => $prefersDop ? 'DOP' : 'USD',
                'cost'          => $cost,
                'payment_type'  => $paymentType,
                'by_day'        => $hours['by_day'],
            ];

            $totOvertime += $otHours;
            $totRegular  += $regHours;
            $totCost     += $prefersDop ? $cost : 0; // el total en DOP es el que interesa
        }

        // Más horas extra primero.
        usort($rows, static fn($a, $b) => $b['overtime_hours'] <=> $a['overtime_hours']);

        // Acumulado por departamento
        $byDepartment = [];
        foreach ($rows as $r) {
            $d = $r['department'];
            if (!isset($byDepartment[$d])) {
                $byDepartment[$d] = ['name' => $d, 'employees' => 0, 'overtime_hours' => 0.0, 'cost' => 0.0];
            }
            $byDepartment[$d]['employees']++;
            $byDepartment[$d]['overtime_hours'] += $r['overtime_hours'];
            $byDepartment[$d]['cost'] += $r['currency'] === 'DOP' ? $r['cost'] : 0;
        }
        $byDepartment = array_values($byDepartment);
        usort($byDepartment, static fn($a, $b) => $b['overtime_hours'] <=> $a['overtime_hours']);

        return [
            'week_start'      => $weekStart,
            'week_end'        => $weekEnd,
            'week_label'      => overtimeReportSpanishDate($weekStart) . ' al ' . overtimeReportSpanishDate($weekEnd),
            'rows'            => $rows,
            'by_department'   => $byDepartment,
            'no_rate'         => $noRate,
            'totals'          => [
                'employees'      => count($rows),
                'overtime_hours' => round($totOvertime, 2),
                'regular_hours'  => round($totRegular, 2),
                'cost_dop'       => round($totCost, 2),
                'avg_overtime'   => count($rows) > 0 ? round($totOvertime / count($rows), 2) : 0.0,
            ],
            'threshold_hours' => 44,
            'multiplier'      => $globalMultiplier,
            'generated_at'    => date('Y-m-d H:i:s'),
        ];
    }
}

// ===========================================================================
// 2. Reporte DIARIO de quienes excedieron 8 horas
// ===========================================================================
if (!function_exists('generateDailyOver8hReport')) {
    /**
     * @param string|null $date día a revisar; por defecto AYER.
     */
    function generateDailyOver8hReport(PDO $pdo, ?string $date = null): array
    {
        $date = $date ?: date('Y-m-d', strtotime('-1 day'));

        $cfg       = overtimeReportSettings($pdo);
        $threshold = max(0.5, (float) ($cfg['over8h_report_threshold_hours'] ?? 8));
        $paidTypes = getPaidAttendanceTypeSlugs($pdo);

        $rows = [];
        $totalExcess = 0.0;

        foreach (overtimeReportEmployees($pdo) as $emp) {
            $hours = computePeriodHoursForUser(
                $pdo,
                (int) $emp['user_id'],
                $date,
                $date,
                $paidTypes,
                44.0,
                false,
                (string) $emp['payroll_source']
            );

            $daySeconds = (int) ($hours['by_day'][$date] ?? 0);
            if ($daySeconds <= 0) {
                continue;
            }

            $dayHours = $daySeconds / 3600;
            if ($dayHours <= $threshold) {
                continue;
            }

            $excess = $dayHours - $threshold;
            $totalExcess += $excess;

            $rows[] = [
                'employee_id'   => (int) $emp['employee_id'],
                'employee_code' => $emp['employee_code'],
                'name'          => trim($emp['first_name'] . ' ' . $emp['last_name']) ?: $emp['full_name'],
                'department'    => $emp['department_name'] ?: 'Sin departamento',
                'campaign'      => $emp['campaign_name'] ?: '—',
                'position'      => $emp['position'] ?: '—',
                'source'        => $hours['by_day_source'][$date] ?? $hours['source_used'],
                'hours'         => round($dayHours, 2),
                'excess_hours'  => round($excess, 2),
            ];
        }

        usort($rows, static fn($a, $b) => $b['hours'] <=> $a['hours']);

        return [
            'date'         => $date,
            'date_label'   => overtimeReportSpanishDate($date),
            'threshold'    => $threshold,
            'rows'         => $rows,
            'totals'       => [
                'employees'     => count($rows),
                'excess_hours'  => round($totalExcess, 2),
                'max_hours'     => $rows ? $rows[0]['hours'] : 0.0,
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }
}

// ===========================================================================
// HTML de los correos
// ===========================================================================
if (!function_exists('overtimeReportStyles')) {
    function overtimeReportStyles(): string
    {
        return '<style>'
            . 'body{font-family:Arial,Helvetica,sans-serif;background:#f4f6fb;margin:0;padding:24px;color:#1f2937;}'
            . '.wrap{max-width:900px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;'
            . 'box-shadow:0 6px 20px rgba(15,23,42,.08);}'
            . '.head{background:linear-gradient(135deg,#244886,#1e3a8a);color:#fff;padding:24px;}'
            . '.head h1{margin:0 0 4px;font-size:20px;}'
            . '.head p{margin:0;opacity:.85;font-size:13px;}'
            . '.body{padding:22px;}'
            . '.cards{display:block;margin-bottom:20px;}'
            . '.card{display:inline-block;width:31%;margin-right:2%;vertical-align:top;background:#f8fafc;'
            . 'border:1px solid #e2e8f0;border-radius:10px;padding:14px;text-align:center;}'
            . '.card .n{font-size:24px;font-weight:bold;color:#244886;display:block;}'
            . '.card .l{font-size:11px;color:#64748b;text-transform:uppercase;}'
            . 'table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:18px;}'
            . 'th{background:#eef2f8;color:#334155;text-align:left;padding:9px;font-size:11px;'
            . 'text-transform:uppercase;border-bottom:2px solid #cbd5e1;}'
            . 'td{padding:9px;border-bottom:1px solid #eef2f8;}'
            . '.num{text-align:right;font-variant-numeric:tabular-nums;}'
            . '.ot{color:#b45309;font-weight:bold;}'
            . '.tag{display:inline-block;padding:2px 7px;border-radius:99px;font-size:10px;'
            . 'background:#e0e7ff;color:#3730a3;}'
            . 'h2{font-size:15px;color:#244886;margin:22px 0 8px;}'
            . '.foot{padding:16px 22px;background:#f8fafc;color:#64748b;font-size:11px;text-align:center;}'
            . '.empty{padding:30px;text-align:center;color:#059669;font-size:14px;}'
            . '</style>';
    }
}

if (!function_exists('generateWeeklyOvertimeReportHTML')) {
    function generateWeeklyOvertimeReportHTML(array $d): string
    {
        $h = '<!DOCTYPE html><html><head><meta charset="UTF-8">' . overtimeReportStyles() . '</head><body><div class="wrap">';
        $h .= '<div class="head"><h1>Horas Extra de la Semana</h1><p>'
            . htmlspecialchars($d['week_label']) . '</p></div><div class="body">';

        $t = $d['totals'];
        $h .= '<div class="cards">'
            . '<div class="card"><span class="n">' . $t['employees'] . '</span><span class="l">Con horas extra</span></div>'
            . '<div class="card"><span class="n">' . overtimeFormatHours($t['overtime_hours']) . '</span><span class="l">Horas extra totales</span></div>'
            . '<div class="card"><span class="n">RD$' . number_format($t['cost_dop'], 2) . '</span><span class="l">Costo estimado</span></div>'
            . '</div>';

        if (empty($d['rows'])) {
            $h .= '<div class="empty">Ningún colaborador superó las ' . (int) $d['threshold_hours']
                . ' horas semanales. Sin horas extra que pagar.</div>';
        } else {
            $h .= '<h2>Detalle por colaborador</h2><table><tr>'
                . '<th>Colaborador</th><th>Departamento</th><th>Fuente</th>'
                . '<th class="num">Días</th><th class="num">Regulares</th><th class="num">Extra</th>'
                . '<th class="num">Tarifa</th><th class="num">Costo recargo</th></tr>';

            foreach ($d['rows'] as $r) {
                $sym = $r['currency'] === 'DOP' ? 'RD$' : '$';
                $h .= '<tr>'
                    . '<td><strong>' . htmlspecialchars($r['name']) . '</strong><br>'
                    . '<span style="color:#94a3b8;font-size:11px;">' . htmlspecialchars((string) $r['employee_code']) . '</span></td>'
                    . '<td>' . htmlspecialchars($r['department']) . '</td>'
                    . '<td><span class="tag">' . htmlspecialchars($r['source']) . '</span></td>'
                    . '<td class="num">' . (int) $r['days_worked'] . '</td>'
                    . '<td class="num">' . overtimeFormatHours($r['regular_hours']) . '</td>'
                    . '<td class="num ot">' . overtimeFormatHours($r['overtime_hours']) . '</td>'
                    . '<td class="num">' . $sym . number_format($r['rate'], 2)
                    . '<br><span style="color:#94a3b8;font-size:10px;">×' . number_format($r['multiplier'], 2) . '</span></td>'
                    . '<td class="num"><strong>' . $sym . number_format($r['cost'], 2) . '</strong></td>'
                    . '</tr>';
            }
            $h .= '</table>';

            if (!empty($d['by_department'])) {
                $h .= '<h2>Acumulado por departamento</h2><table><tr>'
                    . '<th>Departamento</th><th class="num">Colaboradores</th>'
                    . '<th class="num">Horas extra</th><th class="num">Costo estimado</th></tr>';
                foreach ($d['by_department'] as $dep) {
                    $h .= '<tr><td>' . htmlspecialchars($dep['name']) . '</td>'
                        . '<td class="num">' . (int) $dep['employees'] . '</td>'
                        . '<td class="num ot">' . overtimeFormatHours($dep['overtime_hours']) . '</td>'
                        . '<td class="num">RD$' . number_format($dep['cost'], 2) . '</td></tr>';
                }
                $h .= '</table>';
            }

            if (!empty($d['no_rate'])) {
                $h .= '<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;'
                    . 'padding:12px;font-size:12px;color:#92400e;margin-top:6px;">'
                    . '<strong>Sin tarifa configurada (' . count($d['no_rate']) . '):</strong> '
                    . htmlspecialchars(implode(', ', array_slice($d['no_rate'], 0, 12)))
                    . (count($d['no_rate']) > 12 ? ' y ' . (count($d['no_rate']) - 12) . ' más' : '')
                    . '.<br>Tienen horas extra pero no se les puede calcular el costo hasta que se les cargue la tarifa.'
                    . '</div>';
            }
        }

        $h .= '</div><div class="foot">'
            . 'Horas extra = excedente sobre ' . (int) $d['threshold_hours'] . ' h por semana (lunes a domingo), '
            . 'con recargo ×' . number_format($d['multiplier'], 2) . '.<br>'
            . 'Calculadas con la misma lógica que paga la nómina. Generado el '
            . date('d/m/Y H:i', strtotime($d['generated_at'])) . '.'
            . '</div></div></body></html>';

        return $h;
    }
}

if (!function_exists('generateDailyOver8hReportHTML')) {
    function generateDailyOver8hReportHTML(array $d): string
    {
        $h = '<!DOCTYPE html><html><head><meta charset="UTF-8">' . overtimeReportStyles() . '</head><body><div class="wrap">';
        $h .= '<div class="head"><h1>Jornadas de más de ' . rtrim(rtrim(number_format($d['threshold'], 1), '0'), '.') . ' horas</h1><p>'
            . htmlspecialchars($d['date_label']) . '</p></div><div class="body">';

        $t = $d['totals'];
        $h .= '<div class="cards">'
            . '<div class="card"><span class="n">' . $t['employees'] . '</span><span class="l">Colaboradores</span></div>'
            . '<div class="card"><span class="n">' . overtimeFormatHours($t['excess_hours']) . '</span><span class="l">Exceso acumulado</span></div>'
            . '<div class="card"><span class="n">' . overtimeFormatHours($t['max_hours']) . '</span><span class="l">Jornada más larga</span></div>'
            . '</div>';

        if (empty($d['rows'])) {
            $h .= '<div class="empty">Nadie superó las ' . rtrim(rtrim(number_format($d['threshold'], 1), '0'), '.')
                . ' horas. Jornadas dentro de lo normal.</div>';
        } else {
            $h .= '<table><tr><th>Colaborador</th><th>Departamento</th><th>Campaña</th>'
                . '<th>Fuente</th><th class="num">Horas</th><th class="num">Exceso</th></tr>';
            foreach ($d['rows'] as $r) {
                $h .= '<tr>'
                    . '<td><strong>' . htmlspecialchars($r['name']) . '</strong><br>'
                    . '<span style="color:#94a3b8;font-size:11px;">' . htmlspecialchars((string) $r['employee_code']) . '</span></td>'
                    . '<td>' . htmlspecialchars($r['department']) . '</td>'
                    . '<td>' . htmlspecialchars($r['campaign']) . '</td>'
                    . '<td><span class="tag">' . htmlspecialchars($r['source']) . '</span></td>'
                    . '<td class="num"><strong>' . overtimeFormatHours($r['hours']) . '</strong></td>'
                    . '<td class="num ot">+' . overtimeFormatHours($r['excess_hours']) . '</td>'
                    . '</tr>';
            }
            $h .= '</table>';
        }

        $h .= '</div><div class="foot">'
            . 'Horas pagadas del día, con la misma lógica de la nómina (ponche o Vicidial según el colaborador).<br>'
            . 'Generado el ' . date('d/m/Y H:i', strtotime($d['generated_at'])) . '.'
            . '</div></div></body></html>';

        return $h;
    }
}

if (!function_exists('sendOvertimeReportEmail')) {
    /** Envía cualquiera de los dos reportes. */
    function sendOvertimeReportEmail(PDO $pdo, string $subject, string $html, array $recipients): bool
    {
        if (empty($recipients)) {
            return false;
        }
        require_once __DIR__ . '/email_functions.php';
        if (!function_exists('sendEmail')) {
            return false;
        }

        $sentAny = false;
        foreach ($recipients as $to) {
            try {
                if (sendEmail($to, $subject, $html)) {
                    $sentAny = true;
                }
            } catch (Throwable $e) {
                error_log('sendOvertimeReportEmail (' . $to . '): ' . $e->getMessage());
            }
        }
        return $sentAny;
    }
}
