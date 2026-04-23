<?php
/**
 * Daily Executive Dashboard Summary Report ("Cierre del día")
 *
 * Captures the core metrics of the Executive Dashboard at the end of the day:
 *   - Headcount (active / trial / suspended / terminated)
 *   - Who worked today, who didn't
 *   - Hours worked + estimated cost (USD + DOP, with exchange-rate conversion)
 *   - Active campaigns and their hours/cost
 *   - Department distribution
 *   - Attendance punch-type breakdown
 *   - Top N employees by hours
 *   - Optional Claude narrative summary
 *
 * Reuses the dashboard's paid-hour calculation logic so numbers match.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/claude_api_client.php';

if (!function_exists('getExecutiveDashboardReportSettings')) {
    function getExecutiveDashboardReportSettings(PDO $pdo): array
    {
        $defaults = [
            'executive_dashboard_report_enabled'              => '0',
            'executive_dashboard_report_time'                 => '19:00',
            'executive_dashboard_report_recipients'           => '',
            'executive_dashboard_report_top_employees_count'  => '10',
            'executive_dashboard_report_exclude_weekends'     => '0',
            'executive_dashboard_report_claude_enabled'       => '0',
            'executive_dashboard_report_claude_model'         => 'claude-sonnet-4-6',
            'executive_dashboard_report_claude_max_tokens'    => '900',
            'executive_dashboard_report_claude_prompt'        => '',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'executive_dashboard_report_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getExecutiveDashboardReportSettings: ' . $e->getMessage());
        }
        return $defaults;
    }
}

if (!function_exists('getExecutiveDashboardReportRecipients')) {
    function getExecutiveDashboardReportRecipients(PDO $pdo): array
    {
        $raw = (string) (getExecutiveDashboardReportSettings($pdo)['executive_dashboard_report_recipients'] ?? '');
        if ($raw === '') return [];
        $emails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($emails, static fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('execDashSpanishDate')) {
    function execDashSpanishDate(string $date): string
    {
        $days = ['Sunday' => 'Domingo', 'Monday' => 'Lunes', 'Tuesday' => 'Martes',
                 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes',
                 'Saturday' => 'Sábado'];
        $months = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo',
                   'April' => 'abril', 'May' => 'mayo', 'June' => 'junio', 'July' => 'julio',
                   'August' => 'agosto', 'September' => 'septiembre', 'October' => 'octubre',
                   'November' => 'noviembre', 'December' => 'diciembre'];
        $ts = strtotime($date);
        if ($ts === false) return $date;
        return sprintf('%s, %d de %s de %s',
            $days[date('l', $ts)] ?? date('l', $ts),
            (int) date('j', $ts),
            $months[date('F', $ts)] ?? date('F', $ts),
            date('Y', $ts));
    }
}

if (!function_exists('execDashFormatHours')) {
    function execDashFormatHours(float $hours): string
    {
        if ($hours <= 0) return '0h';
        $h = (int) floor($hours);
        $m = (int) round(($hours - $h) * 60);
        if ($m === 60) { $h++; $m = 0; }
        return $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '');
    }
}

if (!function_exists('execDashFormatMoney')) {
    function execDashFormatMoney(float $amount, string $currency = 'USD'): string
    {
        $prefix = strtoupper($currency) === 'DOP' ? 'RD$' : '$';
        return $prefix . number_format($amount, 2, '.', ',');
    }
}

/**
 * Paid-hour calculator — mirrors calculateDailyPaidHours() in executive_dashboard_api.php
 */
if (!function_exists('execDashCalcDailyPaidHours')) {
    function execDashCalcDailyPaidHours(PDO $pdo, int $userId, string $date, array $paidTypes): float
    {
        $stmt = $pdo->prepare("
            SELECT type, timestamp
            FROM attendance
            WHERE user_id = ?
            AND DATE(timestamp) = ?
            ORDER BY timestamp ASC
        ");
        $stmt->execute([$userId, $date]);
        $punches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($punches)) return 0;

        $paidTypesUpper = array_map('strtoupper', $paidTypes);
        $totalSeconds = 0;
        $lastPaidPunch = null;

        foreach ($punches as $punch) {
            $type = strtoupper((string) $punch['type']);
            $ts = strtotime((string) $punch['timestamp']);

            if (in_array($type, $paidTypesUpper, true)) {
                if ($lastPaidPunch !== null) {
                    $totalSeconds += $ts - $lastPaidPunch;
                }
                $lastPaidPunch = $ts;
            } else {
                if ($lastPaidPunch !== null) {
                    $totalSeconds += $ts - $lastPaidPunch;
                    $lastPaidPunch = null;
                }
            }
        }

        $lastPunch = end($punches);
        if ($lastPaidPunch !== null && strtoupper((string) $lastPunch['type']) !== 'EXIT') {
            if ($date === date('Y-m-d')) {
                $totalSeconds += time() - $lastPaidPunch;
            } else {
                $endOfDay = strtotime($date . ' 23:59:59');
                $totalSeconds += $endOfDay - $lastPaidPunch;
            }
        }

        return $totalSeconds / 3600;
    }
}

/**
 * Workforce summary — mirrors getWorkforceSummary() in executive_dashboard_api.php
 */
if (!function_exists('execDashWorkforceSummary')) {
    function execDashWorkforceSummary(PDO $pdo, string $startDate, string $endDate): array
    {
        $defaults = [
            'active_employees'    => 0,
            'trial_employees'     => 0,
            'suspended_employees' => 0,
            'terminated_employees'=> 0,
            'new_hires'           => 0,
            'terminations'        => 0,
            'absent_employees'    => 0,
            'attendance_records'  => 0,
            'attendance_users'    => 0,
        ];

        try {
            $statusStmt = $pdo->query("
                SELECT e.employment_status, COUNT(*) as total
                FROM employees e
                INNER JOIN users u ON u.id = e.user_id
                WHERE u.is_active = 1
                GROUP BY e.employment_status
            ");
            foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $status = strtoupper(trim((string) ($row['employment_status'] ?? '')));
                $count = (int) ($row['total'] ?? 0);
                if ($status === 'ACTIVE')     $defaults['active_employees']     = $count;
                elseif ($status === 'TRIAL')  $defaults['trial_employees']      = $count;
                elseif ($status === 'SUSPENDED') $defaults['suspended_employees'] = $count;
                elseif ($status === 'TERMINATED') $defaults['terminated_employees'] = $count;
            }

            $s = $pdo->prepare("SELECT COUNT(*) FROM employees e INNER JOIN users u ON u.id = e.user_id WHERE u.is_active = 1 AND DATE(e.hire_date) BETWEEN ? AND ?");
            $s->execute([$startDate, $endDate]);
            $defaults['new_hires'] = (int) $s->fetchColumn();

            $s = $pdo->prepare("SELECT COUNT(*) FROM employees e INNER JOIN users u ON u.id = e.user_id WHERE u.is_active = 1 AND e.termination_date IS NOT NULL AND DATE(e.termination_date) BETWEEN ? AND ?");
            $s->execute([$startDate, $endDate]);
            $defaults['terminations'] = (int) $s->fetchColumn();

            $s = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(timestamp) BETWEEN ? AND ?");
            $s->execute([$startDate, $endDate]);
            $defaults['attendance_records'] = (int) $s->fetchColumn();

            $s = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE DATE(timestamp) BETWEEN ? AND ?");
            $s->execute([$startDate, $endDate]);
            $defaults['attendance_users'] = (int) $s->fetchColumn();

            $s = $pdo->prepare("
                SELECT COUNT(*) FROM employees e
                INNER JOIN users u ON u.id = e.user_id
                WHERE u.is_active = 1
                AND e.employment_status IN ('ACTIVE', 'TRIAL')
                AND NOT EXISTS (
                    SELECT 1 FROM attendance a
                    WHERE a.user_id = u.id AND DATE(a.timestamp) BETWEEN ? AND ?
                )
            ");
            $s->execute([$startDate, $endDate]);
            $defaults['absent_employees'] = (int) $s->fetchColumn();
        } catch (Exception $e) {
            error_log('execDashWorkforceSummary: ' . $e->getMessage());
        }

        return $defaults;
    }
}

/**
 * Department summary — mirrors getDepartmentSummary() in executive_dashboard_api.php
 */
if (!function_exists('execDashDepartmentSummary')) {
    function execDashDepartmentSummary(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query("
                SELECT d.id, d.name,
                       COUNT(e.id) as employees,
                       SUM(CASE WHEN e.employment_status IN ('ACTIVE', 'TRIAL') THEN 1 ELSE 0 END) as active_employees
                FROM departments d
                LEFT JOIN employees e ON e.department_id = d.id
                LEFT JOIN users u ON u.id = e.user_id AND u.is_active = 1
                GROUP BY d.id, d.name
                ORDER BY active_employees DESC, d.name ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $noDeptStmt = $pdo->query("
                SELECT COUNT(*) as employees,
                       SUM(CASE WHEN e.employment_status IN ('ACTIVE','TRIAL') THEN 1 ELSE 0 END) as active_employees
                FROM employees e
                INNER JOIN users u ON u.id = e.user_id AND u.is_active = 1
                WHERE e.department_id IS NULL
            ");
            $noDept = $noDeptStmt->fetch(PDO::FETCH_ASSOC);
            if ($noDept && ((int) $noDept['employees'] > 0)) {
                $rows[] = [
                    'id'               => 0,
                    'name'             => 'Sin departamento',
                    'employees'        => (int) $noDept['employees'],
                    'active_employees' => (int) $noDept['active_employees'],
                ];
            }
            return $rows;
        } catch (Exception $e) {
            error_log('execDashDepartmentSummary: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * Attendance punch-type summary — mirrors getAttendanceTypeSummary()
 */
if (!function_exists('execDashAttendanceTypeSummary')) {
    function execDashAttendanceTypeSummary(PDO $pdo, string $startDate, string $endDate): array
    {
        $summary = ['total_punches' => 0, 'paid_punches' => 0, 'unpaid_punches' => 0, 'by_type' => []];
        try {
            // COLLATE clause prevents "Illegal mix of collations" when
            // attendance.type and attendance_types.slug come from columns with
            // different default collations.
            $stmt = $pdo->prepare("
                SELECT UPPER(a.type) as slug, COUNT(*) as total, t.label, t.is_paid
                FROM attendance a
                LEFT JOIN attendance_types t ON UPPER(t.slug) COLLATE utf8mb4_general_ci = UPPER(a.type) COLLATE utf8mb4_general_ci
                WHERE DATE(a.timestamp) BETWEEN ? AND ?
                GROUP BY UPPER(a.type), t.label, t.is_paid
                ORDER BY total DESC
            ");
            $stmt->execute([$startDate, $endDate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $count = (int) ($row['total'] ?? 0);
                $isPaid = (int) ($row['is_paid'] ?? 0);
                $summary['total_punches'] += $count;
                if ($isPaid === 1) $summary['paid_punches'] += $count;
                else $summary['unpaid_punches'] += $count;
                $summary['by_type'][] = [
                    'slug'    => $row['slug'],
                    'label'   => $row['label'] ?: $row['slug'],
                    'count'   => $count,
                    'is_paid' => $isPaid,
                ];
            }
        } catch (Exception $e) {
            error_log('execDashAttendanceTypeSummary: ' . $e->getMessage());
        }
        return $summary;
    }
}

/**
 * Core employee + campaign + department aggregation for the target day.
 * Simplified vs. dashboard's getEmployeesData — no real-time state, just totals.
 */
if (!function_exists('execDashBuildDailySnapshot')) {
    function execDashBuildDailySnapshot(PDO $pdo, string $date, array $paidTypes, float $exchangeRate): array
    {
        $stmt = $pdo->prepare("
            SELECT
                e.id AS employee_id,
                e.first_name, e.last_name, e.employment_status, e.hire_date,
                u.id AS user_id, u.username, u.full_name,
                u.hourly_rate, u.hourly_rate_dop, u.preferred_currency,
                d.id AS department_id, d.name AS department_name,
                c.id AS campaign_id, c.name AS campaign_name, c.code AS campaign_code, c.color AS campaign_color
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN campaigns c ON e.campaign_id = c.id
            WHERE (e.employment_status IN ('ACTIVE', 'TRIAL')
                   OR EXISTS (SELECT 1 FROM attendance a WHERE a.user_id = u.id AND DATE(a.timestamp) = ?))
            AND u.is_active = 1
            ORDER BY c.name ASC, e.first_name ASC
        ");
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $employees   = [];
        $campaigns   = [];
        $departments = [];
        $totals = [
            'eligible'           => 0,
            'worked_today'       => 0,
            'hours_usd'          => 0.0,
            'hours_dop'          => 0.0,
            'earnings_usd'       => 0.0,
            'earnings_dop'       => 0.0,
        ];

        foreach ($rows as $r) {
            $userId   = (int) $r['user_id'];
            $currency = strtoupper((string) ($r['preferred_currency'] ?? 'USD'));
            $rate     = $currency === 'DOP' ? (float) $r['hourly_rate_dop'] : (float) $r['hourly_rate'];
            $status   = strtoupper((string) ($r['employment_status'] ?? ''));

            $hours = execDashCalcDailyPaidHours($pdo, $userId, $date, $paidTypes);
            $earnings = $hours * $rate;

            if (in_array($status, ['ACTIVE', 'TRIAL'], true)) {
                $totals['eligible']++;
            }
            if ($hours > 0) {
                $totals['worked_today']++;
            }

            if ($currency === 'DOP') {
                $totals['hours_dop']    += $hours;
                $totals['earnings_dop'] += $earnings;
            } else {
                $totals['hours_usd']    += $hours;
                $totals['earnings_usd'] += $earnings;
            }

            // By-campaign aggregation
            $cid = (int) ($r['campaign_id'] ?? 0);
            $cKey = $cid ?: 'none';
            if (!isset($campaigns[$cKey])) {
                $campaigns[$cKey] = [
                    'id'                => $cid,
                    'name'              => $r['campaign_name'] ?? 'Sin Campaña',
                    'code'              => $r['campaign_code'] ?? 'N/A',
                    'color'             => $r['campaign_color'] ?? '#6b7280',
                    'employees'         => 0,
                    'worked_today'      => 0,
                    'hours_usd'         => 0.0,
                    'hours_dop'         => 0.0,
                    'cost_usd'          => 0.0,
                    'cost_dop'          => 0.0,
                ];
            }
            $campaigns[$cKey]['employees']++;
            if ($hours > 0) $campaigns[$cKey]['worked_today']++;
            if ($currency === 'DOP') {
                $campaigns[$cKey]['hours_dop'] += $hours;
                $campaigns[$cKey]['cost_dop']  += $earnings;
            } else {
                $campaigns[$cKey]['hours_usd'] += $hours;
                $campaigns[$cKey]['cost_usd']  += $earnings;
            }

            // By-department aggregation
            $did  = (int) ($r['department_id'] ?? 0);
            $dKey = $did ?: 'none';
            if (!isset($departments[$dKey])) {
                $departments[$dKey] = [
                    'id'            => $did,
                    'name'          => $r['department_name'] ?? 'Sin Departamento',
                    'employees'     => 0,
                    'worked_today'  => 0,
                    'hours_total'   => 0.0,
                    'cost_total_usd'=> 0.0,
                ];
            }
            $departments[$dKey]['employees']++;
            if ($hours > 0) $departments[$dKey]['worked_today']++;
            $departments[$dKey]['hours_total'] += $hours;
            $costInUSD = $currency === 'DOP' ? ($exchangeRate > 0 ? $earnings / $exchangeRate : 0) : $earnings;
            $departments[$dKey]['cost_total_usd'] += $costInUSD;

            $employees[] = [
                'user_id'        => $userId,
                'full_name'      => $r['full_name'] ?: ($r['first_name'] . ' ' . $r['last_name']),
                'department'     => $r['department_name'] ?? 'Sin Departamento',
                'campaign'       => $r['campaign_name'] ?? 'Sin Campaña',
                'status'         => $status,
                'hours'          => round($hours, 2),
                'rate'           => $rate,
                'currency'       => $currency,
                'earnings'       => round($earnings, 2),
            ];
        }

        // Combined cost (USD + converted DOP) for exec single-number
        $totals['earnings_combined_usd'] = round(
            $totals['earnings_usd'] + ($exchangeRate > 0 ? $totals['earnings_dop'] / $exchangeRate : 0),
            2
        );
        $totals['earnings_combined_dop'] = round(
            $totals['earnings_dop'] + ($totals['earnings_usd'] * $exchangeRate),
            2
        );
        $totals['hours_total'] = round($totals['hours_usd'] + $totals['hours_dop'], 2);
        $totals['hours_avg_per_worker'] = $totals['worked_today'] > 0
            ? round($totals['hours_total'] / $totals['worked_today'], 2) : 0;
        $totals['attendance_rate_pct'] = $totals['eligible'] > 0
            ? round(($totals['worked_today'] / $totals['eligible']) * 100, 1) : 0;

        // Sort campaigns by cost desc (USD-equivalent)
        $campaignList = array_values($campaigns);
        usort($campaignList, static function ($a, $b) use ($exchangeRate) {
            $costA = ($a['cost_usd'] ?? 0) + ($exchangeRate > 0 ? ($a['cost_dop'] ?? 0) / $exchangeRate : 0);
            $costB = ($b['cost_usd'] ?? 0) + ($exchangeRate > 0 ? ($b['cost_dop'] ?? 0) / $exchangeRate : 0);
            return $costB <=> $costA;
        });

        // Sort departments by cost desc
        $deptList = array_values($departments);
        usort($deptList, static fn($a, $b) => ($b['cost_total_usd'] ?? 0) <=> ($a['cost_total_usd'] ?? 0));

        // Sort employees by hours desc
        usort($employees, static fn($a, $b) => ($b['hours'] ?? 0) <=> ($a['hours'] ?? 0));

        return [
            'employees'    => $employees,
            'campaigns'    => $campaignList,
            'departments'  => $deptList,
            'totals'       => $totals,
        ];
    }
}

if (!function_exists('generateDailyExecutiveDashboardReport')) {
    /**
     * Builds the end-of-day executive summary for the target date (default: today).
     */
    function generateDailyExecutiveDashboardReport(PDO $pdo, ?string $date = null): array
    {
        $date = $date ?: date('Y-m-d');
        $settings = getExecutiveDashboardReportSettings($pdo);
        $topCount = max(5, (int) ($settings['executive_dashboard_report_top_employees_count'] ?? 10));

        $exchangeRate = (float) getSystemSetting($pdo, 'exchange_rate_usd_to_dop', 58.50);
        if ($exchangeRate <= 0) $exchangeRate = 58.50;

        $paidTypes = function_exists('getPaidAttendanceTypeSlugs')
            ? getPaidAttendanceTypeSlugs($pdo)
            : ['DISPONIBLE', 'WASAPI', 'DIGITACION'];

        try {
            $snapshot = execDashBuildDailySnapshot($pdo, $date, $paidTypes, $exchangeRate);
        } catch (Exception $e) {
            return [
                'available' => false,
                'error'     => $e->getMessage(),
                'date'      => $date,
                'date_formatted' => execDashSpanishDate($date),
                'generated_at'   => date('Y-m-d H:i:s'),
            ];
        }

        $workforce   = execDashWorkforceSummary($pdo, $date, $date);
        $departments = execDashDepartmentSummary($pdo);
        $attendance  = execDashAttendanceTypeSummary($pdo, $date, $date);

        // Month-to-date new hires / terminations for context
        $monthStart = date('Y-m-01', strtotime($date));
        $workforceMonth = execDashWorkforceSummary($pdo, $monthStart, $date);

        return [
            'available'      => true,
            'date'           => $date,
            'date_formatted' => execDashSpanishDate($date),
            'exchange_rate'  => $exchangeRate,
            'paid_types'     => $paidTypes,
            'top_employees_count' => $topCount,

            'totals'         => $snapshot['totals'],
            'employees'      => $snapshot['employees'],
            'top_employees'  => array_slice($snapshot['employees'], 0, $topCount),
            'campaigns'      => $snapshot['campaigns'],
            'departments_daily' => $snapshot['departments'],

            'workforce'      => $workforce,
            'workforce_mtd'  => $workforceMonth,
            'departments'    => $departments,
            'attendance'     => $attendance,

            'generated_at'   => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('generateAIExecutiveDashboardSummary')) {
    function generateAIExecutiveDashboardSummary(PDO $pdo, array $reportData): string
    {
        $settings = getExecutiveDashboardReportSettings($pdo);
        if (($settings['executive_dashboard_report_claude_enabled'] ?? '0') !== '1') {
            return '';
        }
        if (empty($reportData['employees'])) {
            return '';
        }

        $model = trim((string) ($settings['executive_dashboard_report_claude_model'] ?? '')) ?: resolveAnthropicDefaultModel($pdo);
        $maxTokens = max(100, (int) ($settings['executive_dashboard_report_claude_max_tokens'] ?? 900));
        $systemPrompt = (string) ($settings['executive_dashboard_report_claude_prompt'] ?? '');

        $payload = [
            'fecha'         => $reportData['date'],
            'tasa_cambio'   => $reportData['exchange_rate'],
            'totales'       => $reportData['totals'],
            'fuerza_laboral'=> $reportData['workforce'],
            'fuerza_laboral_mtd' => $reportData['workforce_mtd'],
            'top_campanas'  => array_slice(array_map(static fn($c) => [
                'nombre'   => $c['name'],
                'empleados'=> $c['employees'],
                'trabajaron'=>$c['worked_today'],
                'horas_usd'=> round($c['hours_usd'], 2),
                'horas_dop'=> round($c['hours_dop'], 2),
                'costo_usd'=> round($c['cost_usd'], 2),
                'costo_dop'=> round($c['cost_dop'], 2),
            ], $reportData['campaigns']), 0, 10),
            'departamentos' => array_slice(array_map(static fn($d) => [
                'nombre'       => $d['name'],
                'empleados'    => $d['employees'],
                'trabajaron'   => $d['worked_today'],
                'horas'        => round($d['hours_total'], 2),
                'costo_usd_equiv' => round($d['cost_total_usd'], 2),
            ], $reportData['departments_daily']), 0, 10),
            'asistencia_por_tipo' => $reportData['attendance']['by_type'],
            'top_empleados' => array_slice(array_map(static fn($e) => [
                'nombre'    => $e['full_name'],
                'campana'   => $e['campaign'],
                'horas'     => $e['hours'],
                'ingreso'   => $e['earnings'],
                'moneda'    => $e['currency'],
            ], $reportData['top_employees']), 0, 10),
        ];

        $userPrompt = "Aquí está el JSON con el cierre del día del Dashboard Ejecutivo. Genera el resumen según las instrucciones:\n\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $result = callClaudeAPI([
            'api_key'       => '',
            'model'         => $model,
            'system_prompt' => $systemPrompt,
            'user_prompt'   => $userPrompt,
            'max_tokens'    => $maxTokens,
            'temperature'   => 0.3,
            'pdo'           => $pdo,
        ]);

        if (!$result['success']) {
            error_log('[executive_dashboard_report] Claude API error: ' . ($result['error'] ?? 'unknown'));
            return '';
        }
        return (string) $result['content'];
    }
}

if (!function_exists('generateExecutiveDashboardReportHTML')) {
    function generateExecutiveDashboardReportHTML(array $reportData, string $aiSummary = ''): string
    {
        $date       = htmlspecialchars($reportData['date_formatted']);
        $totals     = $reportData['totals'];
        $workforce  = $reportData['workforce'];
        $workforceMtd = $reportData['workforce_mtd'];
        $campaigns  = $reportData['campaigns'];
        $deptDaily  = $reportData['departments_daily'];
        $departments= $reportData['departments'];
        $attendance = $reportData['attendance'];
        $topEmps    = $reportData['top_employees'];
        $rate       = (float) $reportData['exchange_rate'];

        $aiBlock = '';
        if (trim($aiSummary) !== '') {
            $safe = nl2br(htmlspecialchars($aiSummary), false);
            $aiBlock = "<div style='background:#fffbeb;border:1px solid #fcd34d;border-left:4px solid #f59e0b;padding:16px 20px;border-radius:8px;margin:18px 0;'>"
                . "<div style='display:inline-block;background:#f59e0b;color:#fff;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase;margin-bottom:10px;'>Resumen ejecutivo del día</div>"
                . "<div style='color:#333;font-size:14px;white-space:pre-wrap;'>{$safe}</div>"
                . "</div>";
        }

        // Stat cards — email-safe <table>+<td> layout
        $statCardStyle = 'background:#ffffff;padding:18px;border-radius:8px;text-align:center;box-shadow:0 2px 4px rgba(0,0,0,0.08);';
        $statLabelStyle = 'color:#666;font-size:12px;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px 0;';
        $statNumberStyle = 'font-size:24px;font-weight:700;margin:0;color:#111;';
        $statSubStyle = 'font-size:12px;color:#666;margin:4px 0 0 0;';

        $rateLabel = execDashFormatMoney($rate, 'DOP') . '/USD';

        $statsRow1 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCardStyle}border-top:4px solid #10b981;'><p style='{$statLabelStyle}'>Asistencia del día</p><p style='{$statNumberStyle}'>{$totals['worked_today']} / {$totals['eligible']}</p><p style='{$statSubStyle}'>{$totals['attendance_rate_pct']}%</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #0ea5e9;'><p style='{$statLabelStyle}'>Horas pagadas</p><p style='{$statNumberStyle}'>" . execDashFormatHours((float) $totals['hours_total']) . "</p><p style='{$statSubStyle}'>promedio " . execDashFormatHours((float) $totals['hours_avg_per_worker']) . "/persona</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #6366f1;'><p style='{$statLabelStyle}'>Costo del día</p><p style='{$statNumberStyle};font-size:18px;'>" . execDashFormatMoney((float) $totals['earnings_combined_usd'], 'USD') . "</p><p style='{$statSubStyle}'>" . execDashFormatMoney((float) $totals['earnings_combined_dop'], 'DOP') . " · tasa {$rateLabel}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #f59e0b;'><p style='{$statLabelStyle}'>Campañas activas</p><p style='{$statNumberStyle}'>" . count($campaigns) . "</p><p style='{$statSubStyle}'>con empleados hoy</p></td>"
            . "</tr></table>";

        $statsRow2 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCardStyle}border-top:4px solid #64748b;'><p style='{$statLabelStyle}'>Activos</p><p style='{$statNumberStyle}'>{$workforce['active_employees']}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #f59e0b;'><p style='{$statLabelStyle}'>En prueba</p><p style='{$statNumberStyle}'>{$workforce['trial_employees']}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #ef4444;'><p style='{$statLabelStyle}'>Ausentes hoy</p><p style='{$statNumberStyle}'>{$workforce['absent_employees']}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #10b981;'><p style='{$statLabelStyle}'>Altas / Bajas (mes)</p><p style='{$statNumberStyle};font-size:20px;'>+{$workforceMtd['new_hires']} / -{$workforceMtd['terminations']}</p></td>"
            . "</tr></table>";

        // Campaigns table
        $campaignRows = '';
        foreach ($campaigns as $c) {
            $costUsdEq = (float) $c['cost_usd'] + ($rate > 0 ? (float) $c['cost_dop'] / $rate : 0);
            $hoursTotal = (float) $c['hours_usd'] + (float) $c['hours_dop'];
            $campaignRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $c['name']) . "</strong>"
                    . ($c['code'] !== 'N/A' ? "<br><span class='muted'>" . htmlspecialchars((string) $c['code']) . "</span>" : "")
                    . "</td>"
                . "<td class='num'>{$c['worked_today']} / {$c['employees']}</td>"
                . "<td class='num'>" . execDashFormatHours($hoursTotal) . "</td>"
                . "<td class='num'>" . execDashFormatMoney((float) $c['cost_usd'], 'USD') . "</td>"
                . "<td class='num'>" . execDashFormatMoney((float) $c['cost_dop'], 'DOP') . "</td>"
                . "<td class='num'><strong>" . execDashFormatMoney($costUsdEq, 'USD') . "</strong></td>"
                . "</tr>";
        }

        // Departments (activity today)
        $deptDailyRows = '';
        foreach ($deptDaily as $d) {
            $deptDailyRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $d['name']) . "</strong></td>"
                . "<td class='num'>{$d['worked_today']} / {$d['employees']}</td>"
                . "<td class='num'>" . execDashFormatHours((float) $d['hours_total']) . "</td>"
                . "<td class='num'>" . execDashFormatMoney((float) $d['cost_total_usd'], 'USD') . "</td>"
                . "</tr>";
        }

        // Departments (headcount)
        $deptHeadRows = '';
        foreach ($departments as $d) {
            $deptHeadRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $d['name']) . "</strong></td>"
                . "<td class='num'>{$d['employees']}</td>"
                . "<td class='num'>{$d['active_employees']}</td>"
                . "</tr>";
        }

        // Top employees
        $empRows = '';
        foreach ($topEmps as $e) {
            if ($e['hours'] <= 0) continue;
            $empRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $e['full_name']) . "</strong><br><span class='muted'>" . htmlspecialchars((string) $e['department']) . "</span></td>"
                . "<td>" . htmlspecialchars((string) $e['campaign']) . "</td>"
                . "<td class='num'>" . execDashFormatHours((float) $e['hours']) . "</td>"
                . "<td class='num'>" . execDashFormatMoney((float) $e['earnings'], (string) $e['currency']) . "</td>"
                . "</tr>";
        }

        // Attendance breakdown
        $attRows = '';
        foreach ($attendance['by_type'] as $t) {
            $label = htmlspecialchars((string) $t['label']);
            $badge = $t['is_paid'] === 1
                ? "<span style='background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;'>pagado</span>"
                : "<span style='background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;'>no pagado</span>";
            $attRows .= "<tr><td><strong>{$label}</strong> {$badge}</td><td class='num'>{$t['count']}</td></tr>";
        }

        $emptyBlock = '';
        if ((int) $totals['worked_today'] === 0) {
            $emptyBlock = "<div style='background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:24px;text-align:center;color:#78350f;margin:18px 0;'><h3 style='margin:0 0 8px 0;'>⚠️ Sin actividad laboral</h3><p style='margin:0;'>Ningún empleado registró horas pagadas en esta fecha.</p></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
  .header { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: #fff; padding: 28px; text-align: center; border-radius: 10px; }
  .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
  .header p { margin: 8px 0 0 0; font-size: 15px; opacity: 0.95; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead { background: linear-gradient(135deg, #1e293b 0%, #475569 100%); }
  th { color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  tbody tr:nth-child(even) { background-color: #fafafa; }
  td.num { font-family: 'Courier New', monospace; white-space: nowrap; text-align: right; }
  .muted { color: #888; font-size: 11px; }
  .footer { text-align: center; padding: 18px; color: #777; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>📊 Cierre Ejecutivo del Día</h1>
    <p>{$date}</p>
  </div>

  {$statsRow1}
  {$statsRow2}

  {$aiBlock}
  {$emptyBlock}

HTML
            . (!empty($campaignRows)
                ? "<div style='background:#fff;margin:18px 0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Campañas del día</h2>"
                    . "<table><thead><tr><th>Campaña</th><th style='text-align:right;'>Asistencia</th><th style='text-align:right;'>Horas</th><th style='text-align:right;'>Costo USD</th><th style='text-align:right;'>Costo DOP</th><th style='text-align:right;'>USD equiv.</th></tr></thead><tbody>{$campaignRows}</tbody></table></div>"
                : "")
            . (!empty($deptDailyRows)
                ? "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='border-collapse:separate;'><tr>"
                    . "<td style='width:50%;vertical-align:top;'><div style='background:#fff;margin:0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Actividad por departamento</h2><table width='100%' cellspacing='0' cellpadding='0' style='border-collapse:collapse;font-size:13px;'><thead style='background:linear-gradient(135deg,#1e293b 0%,#475569 100%);'><tr><th style='color:#fff;padding:10px 8px;text-align:left;font-weight:600;font-size:12px;'>Departamento</th><th style='color:#fff;padding:10px 8px;text-align:right;font-weight:600;font-size:12px;'>Asist.</th><th style='color:#fff;padding:10px 8px;text-align:right;font-weight:600;font-size:12px;'>Horas</th><th style='color:#fff;padding:10px 8px;text-align:right;font-weight:600;font-size:12px;'>Costo USD</th></tr></thead><tbody>{$deptDailyRows}</tbody></table></div></td>"
                    . "<td style='width:50%;vertical-align:top;'><div style='background:#fff;margin:0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Headcount por departamento</h2><table width='100%' cellspacing='0' cellpadding='0' style='border-collapse:collapse;font-size:13px;'><thead style='background:linear-gradient(135deg,#1e293b 0%,#475569 100%);'><tr><th style='color:#fff;padding:10px 8px;text-align:left;font-weight:600;font-size:12px;'>Departamento</th><th style='color:#fff;padding:10px 8px;text-align:right;font-weight:600;font-size:12px;'>Total</th><th style='color:#fff;padding:10px 8px;text-align:right;font-weight:600;font-size:12px;'>Activos</th></tr></thead><tbody>{$deptHeadRows}</tbody></table></div></td>"
                    . "</tr></table>"
                : "")
            . (!empty($empRows)
                ? "<div style='background:#fff;margin:18px 0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Top {$reportData['top_employees_count']} empleados del día (por horas)</h2>"
                    . "<table><thead><tr><th>Empleado / Departamento</th><th>Campaña</th><th style='text-align:right;'>Horas</th><th style='text-align:right;'>Ingreso</th></tr></thead><tbody>{$empRows}</tbody></table></div>"
                : "")
            . (!empty($attRows)
                ? "<div style='background:#fff;margin:18px 0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Distribución de punches del día</h2>"
                    . "<p class='muted'>Total de punches: {$attendance['total_punches']} · Pagados: {$attendance['paid_punches']} · No pagados: {$attendance['unpaid_punches']}</p>"
                    . "<table><thead><tr><th>Tipo</th><th style='text-align:right;'># Punches</th></tr></thead><tbody>{$attRows}</tbody></table></div>"
                : "")
            . "<div class='footer'><p><strong>Cierre generado automáticamente</strong></p>"
            . "<p>{$reportData['generated_at']} — Dashboard Ejecutivo · Sistema de Control de Asistencia</p>"
            . "<p>Tasa de cambio aplicada: {$rateLabel}</p></div>"
            . "</div></body></html>";
    }
}

if (!function_exists('sendExecutiveDashboardReportByEmail')) {
    function sendExecutiveDashboardReportByEmail(PDO $pdo, array $reportData, array $recipients, string $aiSummary = ''): bool
    {
        if (empty($recipients)) {
            error_log('[executive_dashboard_report] No recipients configured');
            return false;
        }

        $html = generateExecutiveDashboardReportHTML($reportData, $aiSummary);
        require_once __DIR__ . '/email_functions.php';

        $result = sendDailyExecutiveDashboardReport($html, $recipients, $reportData);

        if ($result['success']) {
            error_log('[executive_dashboard_report] Sent: ' . $result['message']);
            return true;
        }
        error_log('[executive_dashboard_report] Failed: ' . $result['message']);
        return false;
    }
}
