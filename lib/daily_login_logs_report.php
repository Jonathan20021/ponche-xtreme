<?php
/**
 * Daily Login Logs Security Audit Report
 *
 * Reads from admin_login_logs (the table fed by index.php on each successful
 * admin login) and produces a security audit for the previous day:
 *   - Total logins + unique users + unique IPs
 *   - Breakdown by role (ADMIN, HR, IT, AGENT, QA, ...)
 *   - Peak hour and hourly distribution
 *   - Shared IPs (same IP used by N+ distinct users → possible account sharing)
 *   - Off-hours logins (before X AM or after Y PM)
 *   - Users with excessive logins (possible session expiry / re-login loops)
 *   - Full log listing
 *   - Optional Claude narrative summary flagging anomalies
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/claude_api_client.php';

if (!function_exists('getLoginLogsReportSettings')) {
    function getLoginLogsReportSettings(PDO $pdo): array
    {
        $defaults = [
            'login_logs_report_enabled'             => '0',
            'login_logs_report_time'                => '07:45',
            'login_logs_report_recipients'          => '',
            'login_logs_report_off_hours_start'     => '22:00',
            'login_logs_report_off_hours_end'       => '06:00',
            'login_logs_report_shared_ip_threshold' => '3',
            'login_logs_report_excessive_logins'    => '5',
            'login_logs_report_claude_enabled'      => '0',
            'login_logs_report_claude_model'        => 'claude-sonnet-4-6',
            'login_logs_report_claude_max_tokens'   => '800',
            'login_logs_report_claude_prompt'       => '',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'login_logs_report_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getLoginLogsReportSettings: ' . $e->getMessage());
        }
        return $defaults;
    }
}

if (!function_exists('getLoginLogsReportRecipients')) {
    function getLoginLogsReportRecipients(PDO $pdo): array
    {
        $raw = (string) (getLoginLogsReportSettings($pdo)['login_logs_report_recipients'] ?? '');
        if ($raw === '') return [];
        $emails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($emails, static fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('loginLogsSpanishDate')) {
    function loginLogsSpanishDate(string $date): string
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

if (!function_exists('isOffHoursLogin')) {
    /**
     * Determines whether a login_time is outside of the configured business hours.
     * Off-hours = before $endHHMM OR at/after $startHHMM (wrapping around midnight).
     */
    function isOffHoursLogin(string $loginTime, string $startHHMM, string $endHHMM): bool
    {
        $ts = strtotime($loginTime);
        if ($ts === false) return false;
        $hm = (int) date('G', $ts) * 60 + (int) date('i', $ts);

        $sp = explode(':', $startHHMM);
        $ep = explode(':', $endHHMM);
        $startM = ((int) ($sp[0] ?? 22)) * 60 + (int) ($sp[1] ?? 0);
        $endM   = ((int) ($ep[0] ?? 6))  * 60 + (int) ($ep[1] ?? 0);

        // Default: 22:00–06:00 wraps midnight → off-hours is >=22:00 OR <06:00
        if ($startM > $endM) {
            return $hm >= $startM || $hm < $endM;
        }
        // Same-day window (edge case)
        return $hm >= $startM && $hm < $endM;
    }
}

if (!function_exists('generateDailyLoginLogsReport')) {
    /**
     * Builds the security audit report for the target date (default: yesterday).
     */
    function generateDailyLoginLogsReport(PDO $pdo, ?string $date = null): array
    {
        $date = $date ?: date('Y-m-d', strtotime('yesterday'));
        $settings = getLoginLogsReportSettings($pdo);

        $offHoursStart = (string) ($settings['login_logs_report_off_hours_start'] ?? '22:00');
        $offHoursEnd   = (string) ($settings['login_logs_report_off_hours_end']   ?? '06:00');
        $sharedIpThreshold   = max(2, (int) ($settings['login_logs_report_shared_ip_threshold'] ?? 3));
        $excessiveLoginThreshold = max(2, (int) ($settings['login_logs_report_excessive_logins'] ?? 5));

        try {
            $stmt = $pdo->prepare("
                SELECT
                    l.id,
                    l.user_id,
                    l.username,
                    l.role,
                    l.ip_address,
                    l.public_ip,
                    l.location,
                    l.login_time,
                    COALESCE(u.full_name, l.username) AS full_name
                FROM admin_login_logs l
                LEFT JOIN users u ON u.id = l.user_id
                WHERE DATE(l.login_time) = ?
                ORDER BY l.login_time ASC
            ");
            $stmt->execute([$date]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [
                'available' => false,
                'error'     => $e->getMessage(),
                'date'      => $date,
                'date_formatted' => loginLogsSpanishDate($date),
                'rows'      => [],
                'totals'    => [],
                'by_role'   => [],
                'by_hour'   => [],
                'by_user'   => [],
                'shared_ips'=> [],
                'off_hours' => [],
                'excessive' => [],
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        $byRole = [];
        $byHour = [];
        $byUser = [];
        $ipToUsers = [];
        $offHours  = [];
        $uniqueIps = [];
        $uniqueUsers = [];

        foreach ($rows as $r) {
            $role = strtoupper($r['role'] ?? '—');
            $hour = (int) date('G', strtotime($r['login_time']));
            $uname = $r['username'];
            $ip = $r['public_ip'] ?: ($r['ip_address'] ?: '—');

            $byRole[$role] = ($byRole[$role] ?? 0) + 1;
            $byHour[$hour] = ($byHour[$hour] ?? 0) + 1;

            if (!isset($byUser[$uname])) {
                $byUser[$uname] = [
                    'username'  => $uname,
                    'full_name' => $r['full_name'],
                    'role'      => $role,
                    'count'     => 0,
                    'ips'       => [],
                    'first'     => $r['login_time'],
                    'last'      => $r['login_time'],
                ];
            }
            $byUser[$uname]['count']++;
            if ($ip !== '—') {
                $byUser[$uname]['ips'][$ip] = true;
            }
            if ($r['login_time'] < $byUser[$uname]['first']) $byUser[$uname]['first'] = $r['login_time'];
            if ($r['login_time'] > $byUser[$uname]['last'])  $byUser[$uname]['last']  = $r['login_time'];

            if ($ip !== '—') {
                $uniqueIps[$ip] = true;
                if (!isset($ipToUsers[$ip])) {
                    $ipToUsers[$ip] = [
                        'ip'    => $ip,
                        'users' => [],
                        'count' => 0,
                        'location' => $r['location'] ?: null,
                    ];
                }
                $ipToUsers[$ip]['users'][$uname] = true;
                $ipToUsers[$ip]['count']++;
                if (!$ipToUsers[$ip]['location'] && $r['location']) {
                    $ipToUsers[$ip]['location'] = $r['location'];
                }
            }

            $uniqueUsers[$uname] = true;

            if (isOffHoursLogin($r['login_time'], $offHoursStart, $offHoursEnd)) {
                $offHours[] = [
                    'username'   => $uname,
                    'full_name'  => $r['full_name'],
                    'role'       => $role,
                    'login_time' => $r['login_time'],
                    'ip'         => $ip,
                    'location'   => $r['location'] ?: null,
                ];
            }
        }

        // Finalize per-IP stats
        $ipStats = [];
        foreach ($ipToUsers as $ip => $data) {
            $ipStats[] = [
                'ip'          => $ip,
                'count'       => $data['count'],
                'users_count' => count($data['users']),
                'users'       => array_keys($data['users']),
                'location'    => $data['location'],
            ];
        }
        // Shared IPs = at least N distinct users
        $sharedIps = array_values(array_filter($ipStats, static fn($x) => $x['users_count'] >= $sharedIpThreshold));
        usort($sharedIps, static fn($a, $b) => $b['users_count'] <=> $a['users_count']);

        // Top users by login count
        $byUserArr = [];
        foreach ($byUser as $u) {
            $u['ips_count'] = count($u['ips']);
            $u['ips_list']  = array_keys($u['ips']);
            $byUserArr[] = $u;
        }
        usort($byUserArr, static fn($a, $b) => $b['count'] <=> $a['count']);

        // Excessive login users
        $excessive = array_values(array_filter($byUserArr, static fn($u) => $u['count'] >= $excessiveLoginThreshold));

        // Peak hour
        $peakHour = null;
        $peakCount = 0;
        foreach ($byHour as $h => $c) {
            if ($c > $peakCount) { $peakCount = $c; $peakHour = $h; }
        }
        ksort($byHour);

        // Build hourly histogram array (0..23 filled with 0)
        $hourlyHistogram = [];
        for ($h = 0; $h < 24; $h++) {
            $hourlyHistogram[] = ['hour' => $h, 'count' => $byHour[$h] ?? 0];
        }

        $totals = [
            'total_logins'  => count($rows),
            'unique_users'  => count($uniqueUsers),
            'unique_ips'    => count($uniqueIps),
            'off_hours'     => count($offHours),
            'shared_ips'    => count($sharedIps),
            'excessive_users' => count($excessive),
            'peak_hour'     => $peakHour,
            'peak_hour_count' => $peakCount,
        ];

        return [
            'available'      => true,
            'date'           => $date,
            'date_formatted' => loginLogsSpanishDate($date),
            'off_hours_start'=> $offHoursStart,
            'off_hours_end'  => $offHoursEnd,
            'shared_ip_threshold'     => $sharedIpThreshold,
            'excessive_threshold'     => $excessiveLoginThreshold,
            'rows'           => $rows,
            'totals'         => $totals,
            'by_role'        => $byRole,
            'by_hour'        => $hourlyHistogram,
            'by_user'        => $byUserArr,
            'shared_ips'     => $sharedIps,
            'off_hours'      => $offHours,
            'excessive'      => $excessive,
            'generated_at'   => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('generateAILoginLogsSummary')) {
    function generateAILoginLogsSummary(PDO $pdo, array $reportData): string
    {
        $settings = getLoginLogsReportSettings($pdo);
        if (($settings['login_logs_report_claude_enabled'] ?? '0') !== '1') {
            return '';
        }
        if (empty($reportData['rows'])) {
            return '';
        }

        $model = trim((string) ($settings['login_logs_report_claude_model'] ?? '')) ?: resolveAnthropicDefaultModel($pdo);
        $maxTokens = max(100, (int) ($settings['login_logs_report_claude_max_tokens'] ?? 800));
        $systemPrompt = (string) ($settings['login_logs_report_claude_prompt'] ?? '');

        $payload = [
            'fecha'        => $reportData['date'],
            'horario_normal' => [
                'inicio_off_hours' => $reportData['off_hours_start'],
                'fin_off_hours'    => $reportData['off_hours_end'],
            ],
            'totales'      => $reportData['totals'],
            'por_rol'      => $reportData['by_role'],
            'por_hora'     => $reportData['by_hour'],
            'top_usuarios' => array_slice(array_map(static fn($u) => [
                'usuario'    => $u['username'],
                'nombre'     => $u['full_name'],
                'rol'        => $u['role'],
                'accesos'    => $u['count'],
                'ips_distintas' => $u['ips_count'],
            ], $reportData['by_user']), 0, 15),
            'ips_compartidas' => $reportData['shared_ips'],
            'fuera_horario'   => $reportData['off_hours'],
            'usuarios_excesivos' => $reportData['excessive'],
        ];

        $userPrompt = "Aquí está el JSON con los accesos del día anterior. Genera el resumen ejecutivo según las instrucciones:\n\n"
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
            error_log('[login_logs_report] Claude API error: ' . ($result['error'] ?? 'unknown'));
            return '';
        }
        return (string) $result['content'];
    }
}

if (!function_exists('generateLoginLogsReportHTML')) {
    function generateLoginLogsReportHTML(array $reportData, string $aiSummary = ''): string
    {
        $date       = htmlspecialchars($reportData['date_formatted']);
        $totals     = $reportData['totals'];
        $byRole     = $reportData['by_role'];
        $hourly     = $reportData['by_hour'];
        $byUser     = $reportData['by_user'];
        $sharedIps  = $reportData['shared_ips'];
        $offHours   = $reportData['off_hours'];
        $excessive  = $reportData['excessive'];
        $rows       = $reportData['rows'];

        $aiBlock = '';
        if (trim($aiSummary) !== '') {
            $safe = nl2br(htmlspecialchars($aiSummary), false);
            $aiBlock = "<div class='ai-summary'>"
                . "<div class='ai-badge'>Resumen ejecutivo generado por IA</div>"
                . "<div class='ai-body'>{$safe}</div>"
                . "</div>";
        }

        // By role rows
        $roleRows = '';
        arsort($byRole);
        foreach ($byRole as $role => $count) {
            $roleRows .= "<tr><td><strong>" . htmlspecialchars($role) . "</strong></td><td class='num'>{$count}</td></tr>";
        }

        // Hourly histogram (simple bar chart using CSS widths)
        $hourCounts = array_column($hourly, 'count');
        $maxHour = !empty($hourCounts) ? (int) max($hourCounts) : 0;
        if ($maxHour < 1) $maxHour = 1; // prevent division by zero
        $hourRows = '';
        foreach ($hourly as $h) {
            if ((int) $h['count'] === 0) continue;
            $widthPct = ((int) $h['count'] / $maxHour) * 100;
            $hourLabel = sprintf('%02d:00', (int) $h['hour']);
            $hourRows .= "<tr>"
                . "<td style='width:60px;'>{$hourLabel}</td>"
                . "<td><div class='bar' style='width:{$widthPct}%;'>&nbsp;</div></td>"
                . "<td class='num' style='width:50px;'>{$h['count']}</td>"
                . "</tr>";
        }

        // Top users
        $userRows = '';
        foreach (array_slice($byUser, 0, 15) as $u) {
            $first = date('H:i', strtotime($u['first']));
            $last  = date('H:i', strtotime($u['last']));
            $userRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars($u['full_name']) . "</strong><br><span class='muted'>" . htmlspecialchars($u['username']) . "</span></td>"
                . "<td>" . htmlspecialchars($u['role']) . "</td>"
                . "<td class='num'>{$u['count']}</td>"
                . "<td class='num'>{$u['ips_count']}</td>"
                . "<td class='num'>{$first} → {$last}</td>"
                . "</tr>";
        }

        // Shared IPs
        $sharedIpRows = '';
        foreach ($sharedIps as $ip) {
            $users = htmlspecialchars(implode(', ', $ip['users']));
            $loc   = htmlspecialchars($ip['location'] ?? '—');
            $sharedIpRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars($ip['ip']) . "</strong><br><span class='muted'>{$loc}</span></td>"
                . "<td class='num'>{$ip['users_count']}</td>"
                . "<td class='num'>{$ip['count']}</td>"
                . "<td>{$users}</td>"
                . "</tr>";
        }

        // Off hours
        $offHoursRows = '';
        foreach ($offHours as $o) {
            $time = htmlspecialchars(date('Y-m-d H:i:s', strtotime($o['login_time'])));
            $offHoursRows .= "<tr>"
                . "<td>{$time}</td>"
                . "<td><strong>" . htmlspecialchars($o['full_name']) . "</strong><br><span class='muted'>" . htmlspecialchars($o['username']) . "</span></td>"
                . "<td>" . htmlspecialchars($o['role']) . "</td>"
                . "<td class='num'>" . htmlspecialchars($o['ip']) . "</td>"
                . "<td>" . htmlspecialchars($o['location'] ?? '—') . "</td>"
                . "</tr>";
        }

        // Excessive logins
        $excessiveRows = '';
        foreach ($excessive as $u) {
            $excessiveRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars($u['full_name']) . "</strong><br><span class='muted'>" . htmlspecialchars($u['username']) . "</span></td>"
                . "<td>" . htmlspecialchars($u['role']) . "</td>"
                . "<td class='num'>{$u['count']}</td>"
                . "<td class='num'>{$u['ips_count']}</td>"
                . "</tr>";
        }

        $peakHourLabel = $totals['peak_hour'] !== null
            ? sprintf('%02d:00 (%d accesos)', $totals['peak_hour'], $totals['peak_hour_count'])
            : '—';

        $emptyBlock = '';
        if (empty($rows)) {
            $emptyBlock = "<div class='empty-card' style='background:#e0f2fe;border:1px solid #7dd3fc;border-radius:8px;padding:24px;text-align:center;color:#075985;'><h3 style='margin:0 0 8px 0;'>ℹ️ Sin accesos</h3><p style='margin:0;'>No se registraron accesos al sistema en esta fecha.</p></div>";
        }

        $offHoursStartLabel = htmlspecialchars($reportData['off_hours_start']);
        $offHoursEndLabel   = htmlspecialchars($reportData['off_hours_end']);

        // Build stat cards as a REAL <table> with inline styles.
        // Gmail and Outlook are unreliable with CSS display:table on <div>;
        // using actual <table>+<td> guarantees horizontal layout everywhere.
        $statCardStyle = 'background:#ffffff;padding:18px;border-radius:8px;text-align:center;box-shadow:0 2px 4px rgba(0,0,0,0.08);';
        $statLabelStyle = 'color:#666;font-size:12px;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px 0;';
        $statNumberStyle = 'font-size:26px;font-weight:700;margin:0;color:#111;';
        $statSubStyle = 'font-size:12px;color:#666;margin:4px 0 0 0;';

        $statsRow1 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCardStyle}border-top:4px solid #0ea5e9;'><p style='{$statLabelStyle}'>Total accesos</p><p style='{$statNumberStyle}'>{$totals['total_logins']}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #0ea5e9;'><p style='{$statLabelStyle}'>Usuarios únicos</p><p style='{$statNumberStyle}'>{$totals['unique_users']}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #64748b;'><p style='{$statLabelStyle}'>IPs únicas</p><p style='{$statNumberStyle}'>{$totals['unique_ips']}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #f59e0b;'><p style='{$statLabelStyle}'>Hora pico</p><p style='{$statNumberStyle};font-size:18px;'>{$peakHourLabel}</p></td>"
            . "</tr></table>";

        $statsRow2 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCardStyle}border-top:4px solid #ef4444;'><p style='{$statLabelStyle}'>IPs compartidas</p><p style='{$statNumberStyle}'>{$totals['shared_ips']}</p><p style='{$statSubStyle}'>≥ {$reportData['shared_ip_threshold']} usuarios distintos</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #ef4444;'><p style='{$statLabelStyle}'>Accesos fuera de horario</p><p style='{$statNumberStyle}'>{$totals['off_hours']}</p><p style='{$statSubStyle}'>&gt;= {$offHoursStartLabel} o &lt; {$offHoursEndLabel}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #f59e0b;'><p style='{$statLabelStyle}'>Usuarios con accesos excesivos</p><p style='{$statNumberStyle}'>{$totals['excessive_users']}</p><p style='{$statSubStyle}'>≥ {$reportData['excessive_threshold']} accesos</p></td>"
            . "</tr></table>";

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
  .header { background: linear-gradient(135deg, #1e293b 0%, #475569 100%); color: #fff; padding: 28px; text-align: center; border-radius: 10px; }
  .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
  .header p { margin: 8px 0 0 0; font-size: 15px; opacity: 0.95; }
  .stats-grid { display: table; width: 100%; margin: 18px 0; border-spacing: 10px; }
  .stat-card { display: table-cell; background: #fff; padding: 18px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .stat-card.primary { border-top: 4px solid #0ea5e9; }
  .stat-card.danger  { border-top: 4px solid #ef4444; }
  .stat-card.warning { border-top: 4px solid #f59e0b; }
  .stat-card.muted   { border-top: 4px solid #64748b; }
  .stat-number { font-size: 26px; font-weight: 700; margin: 8px 0 0 0; }
  .stat-sub { font-size: 12px; color: #666; margin-top: 4px; }
  .stat-label  { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
  .ai-summary { background: #fffbeb; border: 1px solid #fcd34d; border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 8px; margin: 18px 0; }
  .ai-badge { display: inline-block; background: #f59e0b; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 10px; }
  .ai-body { color: #333; font-size: 14px; white-space: pre-wrap; }
  .section { background: #fff; margin: 18px 0; padding: 22px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .section h2 { margin: 0 0 14px 0; font-size: 18px; border-bottom: 2px solid #1e293b; padding-bottom: 8px; }
  .section.alert h2 { border-bottom-color: #ef4444; }
  .two-col { display: table; width: 100%; border-spacing: 10px; }
  .two-col > .col { display: table-cell; width: 50%; vertical-align: top; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead { background: linear-gradient(135deg, #1e293b 0%, #475569 100%); }
  th { color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  tbody tr:nth-child(even) { background-color: #fafafa; }
  td.num { font-family: 'Courier New', monospace; white-space: nowrap; text-align: right; }
  .muted { color: #888; font-size: 11px; }
  .bar { background: linear-gradient(90deg, #0ea5e9, #6366f1); height: 16px; border-radius: 3px; min-width: 2px; }
  .empty-card { background: #e0f2fe; border: 1px solid #7dd3fc; border-radius: 8px; padding: 24px; text-align: center; color: #075985; }
  .footer { text-align: center; padding: 18px; color: #777; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>🔒 Auditoría de Accesos al Sistema</h1>
    <p>{$date}</p>
  </div>

  {$statsRow1}
  {$statsRow2}

  {$aiBlock}
  {$emptyBlock}

HTML
            . ($totals['total_logins'] > 0
                ? "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='border-collapse:separate;'><tr>"
                    . "<td style='width:50%;vertical-align:top;'><div class='section' style='background:#fff;margin:0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Por rol</h2><table width='100%' cellspacing='0' cellpadding='0' style='border-collapse:collapse;font-size:13px;'><thead style='background:linear-gradient(135deg,#1e293b 0%,#475569 100%);'><tr><th style='color:#fff;padding:10px 8px;text-align:left;font-weight:600;font-size:12px;'>Rol</th><th style='color:#fff;padding:10px 8px;text-align:right;font-weight:600;font-size:12px;'># Accesos</th></tr></thead><tbody>{$roleRows}</tbody></table></div></td>"
                    . "<td style='width:50%;vertical-align:top;'><div class='section' style='background:#fff;margin:0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Distribución por hora</h2><table width='100%' cellspacing='0' cellpadding='0' style='border-collapse:collapse;font-size:13px;'><tbody>{$hourRows}</tbody></table></div></td>"
                    . "</tr></table>"
                : "")
            . (!empty($sharedIps)
                ? "<div class='section alert'><h2>🚨 IPs compartidas ({$totals['shared_ips']})</h2>"
                    . "<p class='muted'>Misma IP usada por varios usuarios el mismo día — posible cuenta compartida o acceso desde oficina común.</p>"
                    . "<table><thead><tr><th>IP (ubicación)</th><th style='text-align:right;'># Usuarios</th><th style='text-align:right;'># Accesos</th><th>Usuarios</th></tr></thead><tbody>{$sharedIpRows}</tbody></table></div>"
                : "")
            . (!empty($offHours)
                ? "<div class='section alert'><h2>🌙 Accesos fuera de horario ({$totals['off_hours']})</h2>"
                    . "<p class='muted'>Accesos antes de {$offHoursEndLabel} o después de {$offHoursStartLabel}.</p>"
                    . "<table><thead><tr><th>Hora</th><th>Usuario</th><th>Rol</th><th>IP</th><th>Ubicación</th></tr></thead><tbody>{$offHoursRows}</tbody></table></div>"
                : "")
            . (!empty($excessive)
                ? "<div class='section'><h2>⚠ Usuarios con accesos excesivos ({$totals['excessive_users']})</h2>"
                    . "<p class='muted'>≥ {$reportData['excessive_threshold']} accesos en el día — posibles problemas de expiración de sesión.</p>"
                    . "<table><thead><tr><th>Usuario</th><th>Rol</th><th style='text-align:right;'># Accesos</th><th style='text-align:right;'>IPs distintas</th></tr></thead><tbody>{$excessiveRows}</tbody></table></div>"
                : "")
            . ($totals['total_logins'] > 0
                ? "<div class='section'><h2>Top 15 usuarios del día</h2>"
                    . "<table><thead><tr><th>Usuario</th><th>Rol</th><th style='text-align:right;'># Accesos</th><th style='text-align:right;'>IPs</th><th style='text-align:right;'>Ventana</th></tr></thead><tbody>{$userRows}</tbody></table></div>"
                : "")
            . "<div class='footer'><p><strong>Reporte generado automáticamente</strong></p>"
            . "<p>{$reportData['generated_at']} — Tabla admin_login_logs · Sistema de Control de Asistencia</p></div>"
            . "</div></body></html>";
    }
}

if (!function_exists('sendLoginLogsReportByEmail')) {
    function sendLoginLogsReportByEmail(PDO $pdo, array $reportData, array $recipients, string $aiSummary = ''): bool
    {
        if (empty($recipients)) {
            error_log('[login_logs_report] No recipients configured');
            return false;
        }

        $html = generateLoginLogsReportHTML($reportData, $aiSummary);
        require_once __DIR__ . '/email_functions.php';

        $result = sendDailyLoginLogsReport($html, $recipients, $reportData);

        if ($result['success']) {
            error_log('[login_logs_report] Sent: ' . $result['message']);
            return true;
        }
        error_log('[login_logs_report] Failed: ' . $result['message']);
        return false;
    }
}
