<?php
/**
 * Daily Login Hours Report
 * Aggregates Entry/Exit/Break/Baño/Coaching/Disponible/Digitación/Wasapi
 * punches for a given date (default: yesterday) grouped by employee.
 *
 * Produces:
 *   - Structured data array (per employee metrics)
 *   - Optional AI narrative summary via Anthropic Claude
 *   - HTML email body
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/work_hours_calculator.php';
require_once __DIR__ . '/claude_api_client.php';

if (!function_exists('getLoginHoursReportSettings')) {
    /**
     * Returns all login_hours_report_* settings as an associative array,
     * with sensible defaults.
     */
    function getLoginHoursReportSettings(PDO $pdo): array
    {
        $defaults = [
            'login_hours_report_enabled'                 => '0',
            'login_hours_report_time'                    => '07:00',
            'login_hours_report_recipients'              => '',
            'login_hours_report_late_threshold_minutes'  => '10',
            'login_hours_report_break_threshold_minutes' => '45',
            'login_hours_report_claude_enabled'          => '0',
            'login_hours_report_claude_api_key'          => '',
            'login_hours_report_claude_model'            => 'claude-sonnet-4-6',
            'login_hours_report_claude_max_tokens'       => '800',
            'login_hours_report_claude_prompt'           => '',
        ];

        try {
            $stmt = $pdo->query("
                SELECT setting_key, setting_value
                FROM system_settings
                WHERE setting_key LIKE 'login_hours_report_%'
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getLoginHoursReportSettings: ' . $e->getMessage());
        }

        return $defaults;
    }
}

if (!function_exists('getLoginHoursReportRecipients')) {
    /**
     * Parses recipients from settings and returns valid emails.
     */
    function getLoginHoursReportRecipients(PDO $pdo): array
    {
        $settings = getLoginHoursReportSettings($pdo);
        $raw = (string) ($settings['login_hours_report_recipients'] ?? '');
        if ($raw === '') {
            return [];
        }
        $emails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($emails, static function ($email) {
            return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
        }));
    }
}

if (!function_exists('formatSecondsAsHMS')) {
    /**
     * Formats seconds as "Hh MMm" (e.g. 28545 -> "7h 55m").
     * Returns "—" when $seconds is 0 or negative.
     */
    function formatSecondsAsHMS(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($h === 0) {
            return sprintf('%dm', $m);
        }
        return sprintf('%dh %02dm', $h, $m);
    }
}

if (!function_exists('generateDailyLoginHoursReport')) {
    /**
     * Builds the structured report for a given date.
     *
     * @param string|null $date  YYYY-MM-DD. Defaults to yesterday (America/Santo_Domingo).
     * @return array Report data.
     */
    function generateDailyLoginHoursReport(PDO $pdo, ?string $date = null): array
    {
        $date = $date ?: date('Y-m-d', strtotime('yesterday'));
        $settings = getLoginHoursReportSettings($pdo);
        $lateThresholdMinutes  = (int) ($settings['login_hours_report_late_threshold_minutes']  ?? 10);
        $breakThresholdMinutes = (int) ($settings['login_hours_report_break_threshold_minutes'] ?? 45);
        $breakThresholdSeconds = $breakThresholdMinutes * 60;

        // Fetch attendance types once
        $attendanceTypes = getAttendanceTypes($pdo, true);
        $paidSlugs = array_map('sanitizeAttendanceTypeSlug', getPaidAttendanceTypeSlugs($pdo));
        $allSlugs = [];
        foreach ($attendanceTypes as $t) {
            $allSlugs[sanitizeAttendanceTypeSlug($t['slug'])] = $t['label'];
        }

        // Slugs that represent "break" style (unpaid & non-entry/exit)
        $breakLikeSlugs = ['BREAK', 'PAUSA', 'BA_NO'];

        // Pull all attendance rows for the day, joined with employee info
        $stmt = $pdo->prepare("
            SELECT
                a.user_id,
                a.type,
                a.timestamp,
                u.username,
                COALESCE(u.full_name, u.username) AS display_name,
                e.first_name,
                e.last_name,
                e.employee_code,
                d.name AS department_name
            FROM attendance a
            INNER JOIN users u      ON u.id = a.user_id
            LEFT JOIN employees e   ON e.user_id = u.id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE DATE(a.timestamp) = ?
            ORDER BY a.user_id, a.timestamp
        ");
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Group by user_id
        $byUser = [];
        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            if (!isset($byUser[$uid])) {
                $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                if ($fullName === '') {
                    $fullName = $row['display_name'] ?: $row['username'];
                }
                $byUser[$uid] = [
                    'user_id'        => $uid,
                    'username'       => $row['username'],
                    'full_name'      => $fullName,
                    'employee_code'  => $row['employee_code'] ?? '',
                    'department'     => $row['department_name'] ?? 'Sin departamento',
                    'punches'        => [],
                ];
            }
            $byUser[$uid]['punches'][] = [
                'type'      => $row['type'],
                'timestamp' => $row['timestamp'],
            ];
        }

        // Compute metrics per employee
        $employees = [];
        $totals = [
            'employees_with_activity' => 0,
            'late_count'              => 0,
            'no_exit_count'           => 0,
            'break_excess_count'      => 0,
            'total_work_seconds'      => 0,
            'total_break_seconds'     => 0,
        ];

        foreach ($byUser as $uid => $row) {
            $punches = $row['punches'];

            // First Entry and last Exit
            $firstEntry = null;
            $lastExit   = null;
            $breakCount = 0;
            foreach ($punches as $p) {
                $slug = sanitizeAttendanceTypeSlug($p['type']);
                if ($slug === 'ENTRY' && $firstEntry === null) {
                    $firstEntry = $p['timestamp'];
                }
                if ($slug === 'EXIT') {
                    $lastExit = $p['timestamp']; // keep overwriting to get last
                }
                if (in_array($slug, $breakLikeSlugs, true)) {
                    $breakCount++;
                }
            }

            // Compute durations per slug (and total paid work seconds)
            $calc = calculateWorkSecondsFromPunches($punches, $paidSlugs);
            $durations = $calc['durations_all'];
            $workSeconds = $calc['work_seconds'];

            $breakSeconds = 0;
            foreach ($breakLikeSlugs as $slug) {
                $breakSeconds += (int) ($durations[$slug] ?? 0);
            }

            // Get scheduled entry time (if any) to flag lateness
            $schedule = getScheduleConfigForUser($pdo, $uid, $date);
            $scheduledEntry = $schedule['entry_time'] ?? null;
            $isLate = false;
            $lateMinutes = 0;
            if ($firstEntry && $scheduledEntry) {
                $scheduledTs = strtotime($date . ' ' . $scheduledEntry);
                $actualTs = strtotime($firstEntry);
                if ($scheduledTs && $actualTs) {
                    $diffSeconds = $actualTs - $scheduledTs;
                    if ($diffSeconds > ($lateThresholdMinutes * 60)) {
                        $isLate = true;
                        $lateMinutes = (int) round($diffSeconds / 60);
                    }
                }
            }

            $hasExit = $lastExit !== null;
            $breakExcess = $breakSeconds > $breakThresholdSeconds;

            if ($isLate) {
                $totals['late_count']++;
            }
            if (!$hasExit) {
                $totals['no_exit_count']++;
            }
            if ($breakExcess) {
                $totals['break_excess_count']++;
            }
            $totals['employees_with_activity']++;
            $totals['total_work_seconds']  += $workSeconds;
            $totals['total_break_seconds'] += $breakSeconds;

            $employees[] = [
                'user_id'          => $uid,
                'username'         => $row['username'],
                'full_name'        => $row['full_name'],
                'employee_code'    => $row['employee_code'],
                'department'       => $row['department'],
                'first_entry'      => $firstEntry,
                'last_exit'        => $lastExit,
                'scheduled_entry'  => $scheduledEntry,
                'is_late'          => $isLate,
                'late_minutes'     => $lateMinutes,
                'has_exit'         => $hasExit,
                'work_seconds'     => $workSeconds,
                'break_seconds'    => $breakSeconds,
                'break_count'      => $breakCount,
                'break_excess'     => $breakExcess,
                'durations'        => $durations,
                'total_punches'    => count($punches),
            ];
        }

        // Sort by full_name
        usort($employees, static fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));

        return [
            'date'            => $date,
            'date_formatted'  => strftime_safe('%A, %d de %B de %Y', $date),
            'generated_at'    => date('Y-m-d H:i:s'),
            'late_threshold_minutes'  => $lateThresholdMinutes,
            'break_threshold_minutes' => $breakThresholdMinutes,
            'all_slugs'       => $allSlugs,
            'paid_slugs'      => $paidSlugs,
            'employees'       => $employees,
            'totals'          => $totals,
        ];
    }
}

if (!function_exists('strftime_safe')) {
    /**
     * Formats a date in Spanish without relying on locale settings (strftime is deprecated in PHP 8.1+).
     */
    function strftime_safe(string $format, string $date): string
    {
        $days = [
            'Sunday'    => 'Domingo',
            'Monday'    => 'Lunes',
            'Tuesday'   => 'Martes',
            'Wednesday' => 'Miércoles',
            'Thursday'  => 'Jueves',
            'Friday'    => 'Viernes',
            'Saturday'  => 'Sábado',
        ];
        $months = [
            'January' => 'enero',     'February' => 'febrero', 'March'     => 'marzo',
            'April'   => 'abril',     'May'      => 'mayo',    'June'      => 'junio',
            'July'    => 'julio',     'August'   => 'agosto',  'September' => 'septiembre',
            'October' => 'octubre',   'November' => 'noviembre','December' => 'diciembre',
        ];
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        $day   = $days[date('l', $ts)]   ?? date('l', $ts);
        $month = $months[date('F', $ts)] ?? date('F', $ts);
        return sprintf('%s, %d de %s de %s', $day, (int) date('j', $ts), $month, date('Y', $ts));
    }
}

if (!function_exists('generateAILoginHoursSummary')) {
    /**
     * Calls Claude to produce a narrative executive summary.
     * Returns the narrative text, or '' on error/disabled (graceful fallback).
     */
    function generateAILoginHoursSummary(PDO $pdo, array $reportData): string
    {
        $settings = getLoginHoursReportSettings($pdo);
        if (($settings['login_hours_report_claude_enabled'] ?? '0') !== '1') {
            return '';
        }

        $apiKey = trim((string) ($settings['login_hours_report_claude_api_key'] ?? ''));
        $model  = trim((string) ($settings['login_hours_report_claude_model']   ?? 'claude-sonnet-4-6')) ?: 'claude-sonnet-4-6';
        $maxTokens = max(100, (int) ($settings['login_hours_report_claude_max_tokens'] ?? 800));
        $systemPrompt = (string) ($settings['login_hours_report_claude_prompt'] ?? '');

        // Compact JSON payload (avoid huge tokens)
        $payload = [
            'fecha'   => $reportData['date'],
            'umbrales' => [
                'tardanza_min' => $reportData['late_threshold_minutes'],
                'breaks_min'   => $reportData['break_threshold_minutes'],
            ],
            'totales' => $reportData['totals'],
            'empleados' => array_map(static function ($e) {
                return [
                    'nombre'          => $e['full_name'],
                    'username'        => $e['username'],
                    'departamento'    => $e['department'],
                    'entrada'         => $e['first_entry'] ? date('H:i', strtotime($e['first_entry'])) : null,
                    'salida'          => $e['last_exit']   ? date('H:i', strtotime($e['last_exit']))   : null,
                    'horario_entrada' => $e['scheduled_entry'],
                    'tardanza_min'    => $e['is_late'] ? $e['late_minutes'] : 0,
                    'sin_salida'      => !$e['has_exit'],
                    'horas_netas'     => round($e['work_seconds'] / 3600, 2),
                    'break_total_min' => (int) round($e['break_seconds'] / 60),
                    'breaks_tomados'  => $e['break_count'],
                    'exceso_break'    => $e['break_excess'],
                    'duraciones_min'  => array_map(static fn($s) => (int) round($s / 60), $e['durations']),
                ];
            }, $reportData['employees']),
        ];

        $userPrompt = "Aquí está el resumen de ayer en JSON. Genera el resumen ejecutivo según las instrucciones:\n\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $result = callClaudeAPI([
            'api_key'       => $apiKey,
            'model'         => $model,
            'system_prompt' => $systemPrompt,
            'user_prompt'   => $userPrompt,
            'max_tokens'    => $maxTokens,
            'temperature'   => 0.3,
        ]);

        if (!$result['success']) {
            error_log('[login_hours_report] Claude API error: ' . ($result['error'] ?? 'unknown'));
            return '';
        }

        return (string) $result['content'];
    }
}

if (!function_exists('generateLoginHoursReportHTML')) {
    /**
     * Produces the HTML body for the email.
     */
    function generateLoginHoursReportHTML(array $reportData, string $aiSummary = ''): string
    {
        $date          = htmlspecialchars($reportData['date_formatted']);
        $totals        = $reportData['totals'];
        $employees     = $reportData['employees'];
        $allSlugs      = $reportData['all_slugs'];
        $paidSlugs     = $reportData['paid_slugs'];
        $lateThreshold = (int) $reportData['late_threshold_minutes'];
        $breakThreshold = (int) $reportData['break_threshold_minutes'];

        $totalWorkHrs  = formatSecondsAsHMS((int) $totals['total_work_seconds']);
        $totalBreakHrs = formatSecondsAsHMS((int) $totals['total_break_seconds']);

        $aiBlock = '';
        if (trim($aiSummary) !== '') {
            $safeSummary = nl2br(htmlspecialchars($aiSummary), false);
            $aiBlock = "
            <div class='ai-summary'>
                <div class='ai-badge'>Resumen ejecutivo generado por IA</div>
                <div class='ai-body'>{$safeSummary}</div>
            </div>";
        }

        $rowsHtml = '';
        if (empty($employees)) {
            $rowsHtml = "
                <tr><td colspan='9' class='no-data'>No hay registros de asistencia para esta fecha.</td></tr>";
        } else {
            foreach ($employees as $emp) {
                $name       = htmlspecialchars($emp['full_name']);
                $code       = htmlspecialchars($emp['employee_code'] ?? '');
                $dept       = htmlspecialchars($emp['department'] ?? '');
                $entry      = $emp['first_entry'] ? date('H:i:s', strtotime($emp['first_entry'])) : '—';
                $exit       = $emp['last_exit']   ? date('H:i:s', strtotime($emp['last_exit']))   : '—';
                $workHrs    = formatSecondsAsHMS((int) $emp['work_seconds']);
                $breakHrs   = formatSecondsAsHMS((int) $emp['break_seconds']);
                $breaks     = (int) $emp['break_count'];

                $entryBadge = '';
                if ($emp['is_late']) {
                    $entryBadge = " <span class='badge badge-warning'>+{$emp['late_minutes']}m tarde</span>";
                }
                $exitBadge = '';
                if (!$emp['has_exit']) {
                    $exitBadge = " <span class='badge badge-danger'>sin Exit</span>";
                }
                $breakBadge = '';
                if ($emp['break_excess']) {
                    $breakBadge = " <span class='badge badge-warning'>exceso</span>";
                }

                // Build a small "durations" chip list for productive states
                $chips = '';
                foreach ($paidSlugs as $slug) {
                    if (!isset($emp['durations'][$slug])) continue;
                    $label = htmlspecialchars($allSlugs[$slug] ?? $slug);
                    $dur   = formatSecondsAsHMS((int) $emp['durations'][$slug]);
                    $chips .= "<span class='state-chip'>{$label}: <strong>{$dur}</strong></span> ";
                }

                $rowsHtml .= "
                    <tr>
                        <td><strong>{$name}</strong><br><span class='muted'>{$code}</span></td>
                        <td>{$dept}</td>
                        <td class='num'>{$entry}{$entryBadge}</td>
                        <td class='num'>{$exit}{$exitBadge}</td>
                        <td class='num'><strong>{$workHrs}</strong></td>
                        <td class='num'>{$breakHrs}{$breakBadge}</td>
                        <td class='num'>{$breaks}</td>
                        <td>{$chips}</td>
                    </tr>";
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
  .header { background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%); color: #fff; padding: 28px; text-align: center; border-radius: 10px; }
  .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
  .header p { margin: 8px 0 0 0; font-size: 15px; opacity: 0.95; }
  .stats-grid { display: table; width: 100%; margin: 18px 0; border-spacing: 10px; }
  .stat-card { display: table-cell; background: #fff; padding: 18px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .stat-card.primary { border-top: 4px solid #0ea5e9; }
  .stat-card.warning { border-top: 4px solid #f59e0b; }
  .stat-card.danger  { border-top: 4px solid #ef4444; }
  .stat-card.success { border-top: 4px solid #10b981; }
  .stat-number { font-size: 28px; font-weight: 700; margin: 8px 0 0 0; }
  .stat-label  { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
  .ai-summary { background: #fffbeb; border: 1px solid #fcd34d; border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 8px; margin: 18px 0; }
  .ai-badge { display: inline-block; background: #f59e0b; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 10px; }
  .ai-body { color: #333; font-size: 14px; white-space: pre-wrap; }
  .section { background: #fff; margin: 18px 0; padding: 22px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .section h2 { margin: 0 0 14px 0; font-size: 18px; border-bottom: 2px solid #0ea5e9; padding-bottom: 8px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead { background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%); }
  th { color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  tbody tr:nth-child(even) { background-color: #fafafa; }
  td.num { font-family: 'Courier New', monospace; white-space: nowrap; }
  .muted { color: #888; font-size: 11px; }
  .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
  .badge-danger  { background: #ef4444; color: #fff; }
  .badge-warning { background: #f59e0b; color: #fff; }
  .state-chip { display: inline-block; background: #eef2ff; color: #3730a3; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin: 2px 2px 2px 0; }
  .no-data { text-align: center; padding: 24px; color: #666; font-style: italic; }
  .footer { text-align: center; padding: 18px; color: #777; font-size: 12px; margin-top: 20px; }
  .config-note { font-size: 12px; color: #666; margin-top: 6px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Reporte Diario de Horas de Login</h1>
    <p>{$date}</p>
  </div>

  <div class="stats-grid">
    <div class="stat-card primary">
      <div class="stat-label">Empleados con registro</div>
      <div class="stat-number">{$totals['employees_with_activity']}</div>
    </div>
    <div class="stat-card warning">
      <div class="stat-label">Llegadas tarde</div>
      <div class="stat-number">{$totals['late_count']}</div>
    </div>
    <div class="stat-card danger">
      <div class="stat-label">Sin registro de Exit</div>
      <div class="stat-number">{$totals['no_exit_count']}</div>
    </div>
    <div class="stat-card success">
      <div class="stat-label">Exceso de breaks</div>
      <div class="stat-number">{$totals['break_excess_count']}</div>
    </div>
  </div>

  <div class="stats-grid">
    <div class="stat-card primary">
      <div class="stat-label">Total horas netas (equipo)</div>
      <div class="stat-number">{$totalWorkHrs}</div>
    </div>
    <div class="stat-card warning">
      <div class="stat-label">Total horas de break (equipo)</div>
      <div class="stat-number">{$totalBreakHrs}</div>
    </div>
  </div>

  {$aiBlock}

  <div class="section">
    <h2>Detalle por empleado</h2>
    <p class="config-note">Umbral de tardanza: {$lateThreshold} min · Umbral de breaks: {$breakThreshold} min</p>
    <table>
      <thead>
        <tr>
          <th>Empleado</th>
          <th>Depto</th>
          <th>Entry</th>
          <th>Exit</th>
          <th>Horas netas</th>
          <th>Breaks</th>
          <th>#</th>
          <th>Desglose estados productivos</th>
        </tr>
      </thead>
      <tbody>{$rowsHtml}</tbody>
    </table>
  </div>

  <div class="footer">
    <p><strong>Reporte generado automáticamente</strong></p>
    <p>{$reportData['generated_at']} — Sistema de Control de Asistencia</p>
  </div>
</div>
</body>
</html>
HTML;
    }
}

if (!function_exists('sendLoginHoursReportByEmail')) {
    /**
     * Sends the rendered report via the email_functions helper.
     */
    function sendLoginHoursReportByEmail(PDO $pdo, array $reportData, array $recipients, string $aiSummary = ''): bool
    {
        if (empty($recipients)) {
            error_log('[login_hours_report] No recipients configured');
            return false;
        }

        $html = generateLoginHoursReportHTML($reportData, $aiSummary);
        require_once __DIR__ . '/email_functions.php';

        $result = sendDailyLoginHoursReport($html, $recipients, $reportData);

        if ($result['success']) {
            error_log('[login_hours_report] Sent: ' . $result['message']);
            return true;
        }

        error_log('[login_hours_report] Failed: ' . $result['message']);
        return false;
    }
}
