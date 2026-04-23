<?php
/**
 * Daily Activity Logs Report
 *
 * Reads from activity_logs (the table fed by lib/logging_functions.php's
 * log_activity() / log_custom_action() across the app) and produces a daily
 * audit of all changes made in the system for the previous day:
 *   - Total actions + unique modules + unique users + peak hour
 *   - Breakdown by module (employees, schedules, payroll, permissions, rates, ...)
 *   - Breakdown by action (create, update, delete, approve, reject, generate, ...)
 *   - Top users by activity count
 *   - Hourly distribution
 *   - Sensitive actions (permissions, rates, banking, system_settings, user deactivation)
 *   - Deletions (every action == 'delete' across all modules)
 *   - Last N actions (chronological tail for quick audit)
 *   - Optional Claude narrative summary flagging anomalies
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/claude_api_client.php';

if (!function_exists('getActivityLogsReportSettings')) {
    function getActivityLogsReportSettings(PDO $pdo): array
    {
        $defaults = [
            'activity_logs_report_enabled'          => '0',
            'activity_logs_report_time'             => '08:15',
            'activity_logs_report_recipients'       => '',
            'activity_logs_report_exclude_modules'  => 'reports',
            'activity_logs_report_sensitive_modules'=> 'permissions,rates,banking,system_settings,users',
            'activity_logs_report_top_users_limit'  => '15',
            'activity_logs_report_recent_tail'      => '20',
            'activity_logs_report_claude_enabled'   => '0',
            'activity_logs_report_claude_model'     => 'claude-sonnet-4-6',
            'activity_logs_report_claude_max_tokens'=> '800',
            'activity_logs_report_claude_prompt'    => '',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'activity_logs_report_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getActivityLogsReportSettings: ' . $e->getMessage());
        }
        return $defaults;
    }
}

if (!function_exists('getActivityLogsReportRecipients')) {
    function getActivityLogsReportRecipients(PDO $pdo): array
    {
        $raw = (string) (getActivityLogsReportSettings($pdo)['activity_logs_report_recipients'] ?? '');
        if ($raw === '') return [];
        $emails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($emails, static fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('activityLogsSpanishDate')) {
    function activityLogsSpanishDate(string $date): string
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

if (!function_exists('activityLogsParseCsvList')) {
    function activityLogsParseCsvList(string $raw): array
    {
        if ($raw === '') return [];
        $parts = preg_split('/[,;\s]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if ($p !== '') $out[$p] = true;
        }
        return array_keys($out);
    }
}

if (!function_exists('generateDailyActivityLogsReport')) {
    /**
     * Builds the activity audit report for the target date (default: yesterday).
     */
    function generateDailyActivityLogsReport(PDO $pdo, ?string $date = null): array
    {
        $date = $date ?: date('Y-m-d', strtotime('yesterday'));
        $settings = getActivityLogsReportSettings($pdo);

        $excludeModules = activityLogsParseCsvList((string) ($settings['activity_logs_report_exclude_modules'] ?? 'reports'));
        $sensitiveModules = activityLogsParseCsvList((string) ($settings['activity_logs_report_sensitive_modules'] ?? 'permissions,rates,banking,system_settings,users'));
        $topUsersLimit = max(5, (int) ($settings['activity_logs_report_top_users_limit'] ?? 15));
        $recentTail = max(5, (int) ($settings['activity_logs_report_recent_tail'] ?? 20));

        try {
            $stmt = $pdo->prepare("
                SELECT
                    id, user_id, user_name, user_role, module, action, description,
                    entity_type, entity_id, ip_address, created_at
                FROM activity_logs
                WHERE DATE(created_at) = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$date]);
            $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [
                'available' => false,
                'error'     => $e->getMessage(),
                'date'      => $date,
                'date_formatted' => activityLogsSpanishDate($date),
                'rows'      => [],
                'totals'    => [],
                'by_module' => [],
                'by_action' => [],
                'by_hour'   => [],
                'by_user'   => [],
                'sensitive' => [],
                'deletes'   => [],
                'recent'    => [],
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        // Apply exclude filter
        $rows = [];
        $excludedCount = 0;
        foreach ($allRows as $r) {
            $mod = strtolower((string) ($r['module'] ?? ''));
            if (in_array($mod, $excludeModules, true)) {
                $excludedCount++;
                continue;
            }
            $rows[] = $r;
        }

        $byModule = [];
        $byAction = [];
        $byHour = [];
        $byUser = [];
        $sensitive = [];
        $deletes = [];
        $uniqueUsers = [];

        foreach ($rows as $r) {
            $mod = (string) ($r['module'] ?? '—');
            $act = (string) ($r['action'] ?? '—');
            $uname = (string) ($r['user_name'] ?? ($r['user_id'] !== null ? ('user #' . $r['user_id']) : 'Sistema'));
            $hour = (int) date('G', strtotime((string) $r['created_at']));

            $byModule[$mod] = ($byModule[$mod] ?? 0) + 1;
            $byAction[$act] = ($byAction[$act] ?? 0) + 1;
            $byHour[$hour]  = ($byHour[$hour]  ?? 0) + 1;

            $key = $uname . '|' . ((int) ($r['user_id'] ?? 0));
            if (!isset($byUser[$key])) {
                $byUser[$key] = [
                    'user_id'     => (int) ($r['user_id'] ?? 0),
                    'user_name'   => $uname,
                    'user_role'   => strtoupper((string) ($r['user_role'] ?? '—')),
                    'count'       => 0,
                    'modules'     => [],
                    'actions'     => [],
                    'first'       => $r['created_at'],
                    'last'        => $r['created_at'],
                ];
            }
            $byUser[$key]['count']++;
            $byUser[$key]['modules'][$mod] = true;
            $byUser[$key]['actions'][$act] = true;
            if ($r['created_at'] < $byUser[$key]['first']) $byUser[$key]['first'] = $r['created_at'];
            if ($r['created_at'] > $byUser[$key]['last'])  $byUser[$key]['last']  = $r['created_at'];

            $uniqueUsers[$key] = true;

            // Flag sensitive: either module is in sensitive_modules, OR action == 'delete' on any module
            $isSensitive = in_array(strtolower($mod), $sensitiveModules, true);
            $isDelete = strtolower($act) === 'delete';
            if ($isSensitive) {
                $sensitive[] = $r;
            }
            if ($isDelete) {
                $deletes[] = $r;
            }
        }

        // Finalize by_user
        $byUserArr = [];
        foreach ($byUser as $u) {
            $u['modules_count'] = count($u['modules']);
            $u['actions_count'] = count($u['actions']);
            $u['modules_list']  = array_keys($u['modules']);
            $u['actions_list']  = array_keys($u['actions']);
            $byUserArr[] = $u;
        }
        usort($byUserArr, static fn($a, $b) => $b['count'] <=> $a['count']);

        // Peak hour
        $peakHour = null;
        $peakCount = 0;
        foreach ($byHour as $h => $c) {
            if ($c > $peakCount) { $peakCount = $c; $peakHour = $h; }
        }
        ksort($byHour);

        // Hourly histogram 0..23
        $hourlyHistogram = [];
        for ($h = 0; $h < 24; $h++) {
            $hourlyHistogram[] = ['hour' => $h, 'count' => $byHour[$h] ?? 0];
        }

        // Sort module / action maps descending
        arsort($byModule);
        arsort($byAction);

        // Recent tail (last N, reverse chronological)
        $recent = array_slice(array_reverse($rows), 0, $recentTail);

        $totals = [
            'total_actions'   => count($rows),
            'excluded_count'  => $excludedCount,
            'modules_touched' => count($byModule),
            'unique_actions'  => count($byAction),
            'unique_users'    => count($uniqueUsers),
            'peak_hour'       => $peakHour,
            'peak_hour_count' => $peakCount,
            'sensitive'       => count($sensitive),
            'deletes'         => count($deletes),
        ];

        return [
            'available'        => true,
            'date'             => $date,
            'date_formatted'   => activityLogsSpanishDate($date),
            'exclude_modules'  => $excludeModules,
            'sensitive_modules'=> $sensitiveModules,
            'top_users_limit'  => $topUsersLimit,
            'recent_tail'      => $recentTail,
            'rows'             => $rows,
            'totals'           => $totals,
            'by_module'        => $byModule,
            'by_action'        => $byAction,
            'by_hour'          => $hourlyHistogram,
            'by_user'          => $byUserArr,
            'sensitive'        => $sensitive,
            'deletes'          => $deletes,
            'recent'           => $recent,
            'generated_at'     => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('generateAIActivityLogsSummary')) {
    function generateAIActivityLogsSummary(PDO $pdo, array $reportData): string
    {
        $settings = getActivityLogsReportSettings($pdo);
        if (($settings['activity_logs_report_claude_enabled'] ?? '0') !== '1') {
            return '';
        }
        if (empty($reportData['rows'])) {
            return '';
        }

        $model = trim((string) ($settings['activity_logs_report_claude_model'] ?? '')) ?: resolveAnthropicDefaultModel($pdo);
        $maxTokens = max(100, (int) ($settings['activity_logs_report_claude_max_tokens'] ?? 800));
        $systemPrompt = (string) ($settings['activity_logs_report_claude_prompt'] ?? '');

        $topUsers = array_slice(array_map(static fn($u) => [
            'usuario'      => $u['user_name'],
            'rol'          => $u['user_role'],
            'acciones'     => $u['count'],
            'modulos'      => $u['modules_count'],
            'tipos_accion' => $u['actions_count'],
        ], $reportData['by_user']), 0, 10);

        $sensitiveSample = array_slice(array_map(static fn($r) => [
            'hora'         => date('H:i', strtotime($r['created_at'])),
            'usuario'      => $r['user_name'],
            'rol'          => $r['user_role'],
            'modulo'       => $r['module'],
            'accion'       => $r['action'],
            'descripcion'  => $r['description'],
            'entidad'      => $r['entity_type'] . ($r['entity_id'] ? '#' . $r['entity_id'] : ''),
        ], $reportData['sensitive']), 0, 25);

        $deletesSample = array_slice(array_map(static fn($r) => [
            'hora'         => date('H:i', strtotime($r['created_at'])),
            'usuario'      => $r['user_name'],
            'rol'          => $r['user_role'],
            'modulo'       => $r['module'],
            'descripcion'  => $r['description'],
        ], $reportData['deletes']), 0, 20);

        $payload = [
            'fecha'            => $reportData['date'],
            'totales'          => $reportData['totals'],
            'por_modulo'       => $reportData['by_module'],
            'por_accion'       => $reportData['by_action'],
            'top_usuarios'     => $topUsers,
            'acciones_sensibles' => $sensitiveSample,
            'eliminaciones'    => $deletesSample,
            'modulos_sensibles'=> $reportData['sensitive_modules'],
            'modulos_excluidos'=> $reportData['exclude_modules'],
        ];

        $userPrompt = "Aquí está el JSON con toda la actividad administrativa del día anterior. Genera el resumen ejecutivo según las instrucciones:\n\n"
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
            error_log('[activity_logs_report] Claude API error: ' . ($result['error'] ?? 'unknown'));
            return '';
        }
        return (string) $result['content'];
    }
}

if (!function_exists('generateActivityLogsReportHTML')) {
    function generateActivityLogsReportHTML(array $reportData, string $aiSummary = ''): string
    {
        $date       = htmlspecialchars($reportData['date_formatted']);
        $totals     = $reportData['totals'];
        $byModule   = $reportData['by_module'];
        $byAction   = $reportData['by_action'];
        $hourly     = $reportData['by_hour'];
        $byUser     = $reportData['by_user'];
        $sensitive  = $reportData['sensitive'];
        $deletes    = $reportData['deletes'];
        $recent     = $reportData['recent'];
        $rows       = $reportData['rows'];

        $aiBlock = '';
        if (trim($aiSummary) !== '') {
            $safe = nl2br(htmlspecialchars($aiSummary), false);
            $aiBlock = "<div style='background:#fffbeb;border:1px solid #fcd34d;border-left:4px solid #f59e0b;padding:16px 20px;border-radius:8px;margin:18px 0;'>"
                . "<div style='display:inline-block;background:#f59e0b;color:#fff;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase;margin-bottom:10px;'>Resumen ejecutivo generado por IA</div>"
                . "<div style='color:#333;font-size:14px;white-space:pre-wrap;'>{$safe}</div>"
                . "</div>";
        }

        // Modules table
        $totalActions = max(1, (int) $totals['total_actions']);
        $moduleRows = '';
        foreach ($byModule as $mod => $count) {
            $pct = round(($count / $totalActions) * 100, 1);
            $moduleRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars($mod) . "</strong></td>"
                . "<td class='num'>{$count}</td>"
                . "<td class='num'>{$pct}%</td>"
                . "</tr>";
        }

        // Actions table
        $actionRows = '';
        foreach ($byAction as $act => $count) {
            $actionRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars($act) . "</strong></td>"
                . "<td class='num'>{$count}</td>"
                . "</tr>";
        }

        // Top users
        $userRows = '';
        foreach (array_slice($byUser, 0, $reportData['top_users_limit']) as $u) {
            $first = date('H:i', strtotime($u['first']));
            $last  = date('H:i', strtotime($u['last']));
            $modsShort = htmlspecialchars(implode(', ', array_slice($u['modules_list'], 0, 5)));
            $userRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars($u['user_name']) . "</strong></td>"
                . "<td>" . htmlspecialchars($u['user_role']) . "</td>"
                . "<td class='num'>{$u['count']}</td>"
                . "<td><span class='muted'>{$modsShort}</span></td>"
                . "<td class='num'>{$first} → {$last}</td>"
                . "</tr>";
        }

        // Hourly histogram
        $hourCounts = array_column($hourly, 'count');
        $maxHour = !empty($hourCounts) ? (int) max($hourCounts) : 0;
        if ($maxHour < 1) $maxHour = 1;
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

        // Sensitive actions
        $sensitiveRows = '';
        foreach ($sensitive as $r) {
            $time = htmlspecialchars(date('H:i:s', strtotime((string) $r['created_at'])));
            $entity = '';
            if (!empty($r['entity_type'])) {
                $entity = htmlspecialchars($r['entity_type']) . ($r['entity_id'] ? ' #' . (int) $r['entity_id'] : '');
            }
            $sensitiveRows .= "<tr>"
                . "<td class='num'>{$time}</td>"
                . "<td><strong>" . htmlspecialchars((string) $r['user_name']) . "</strong><br><span class='muted'>" . htmlspecialchars(strtoupper((string) $r['user_role'])) . "</span></td>"
                . "<td><span class='pill'>" . htmlspecialchars((string) $r['module']) . "</span> · " . htmlspecialchars((string) $r['action']) . "</td>"
                . "<td>" . htmlspecialchars((string) ($r['description'] ?? '')) . "</td>"
                . "<td><span class='muted'>{$entity}</span></td>"
                . "</tr>";
        }

        // Deletes
        $deleteRows = '';
        foreach ($deletes as $r) {
            $time = htmlspecialchars(date('H:i:s', strtotime((string) $r['created_at'])));
            $entity = '';
            if (!empty($r['entity_type'])) {
                $entity = htmlspecialchars($r['entity_type']) . ($r['entity_id'] ? ' #' . (int) $r['entity_id'] : '');
            }
            $deleteRows .= "<tr>"
                . "<td class='num'>{$time}</td>"
                . "<td><strong>" . htmlspecialchars((string) $r['user_name']) . "</strong><br><span class='muted'>" . htmlspecialchars(strtoupper((string) $r['user_role'])) . "</span></td>"
                . "<td><span class='pill'>" . htmlspecialchars((string) $r['module']) . "</span></td>"
                . "<td>" . htmlspecialchars((string) ($r['description'] ?? '')) . "</td>"
                . "<td><span class='muted'>{$entity}</span></td>"
                . "</tr>";
        }

        // Recent tail (chronological DESC)
        $recentRows = '';
        foreach ($recent as $r) {
            $time = htmlspecialchars(date('H:i:s', strtotime((string) $r['created_at'])));
            $recentRows .= "<tr>"
                . "<td class='num'>{$time}</td>"
                . "<td><strong>" . htmlspecialchars((string) $r['user_name']) . "</strong></td>"
                . "<td><span class='pill'>" . htmlspecialchars((string) $r['module']) . "</span> · " . htmlspecialchars((string) $r['action']) . "</td>"
                . "<td>" . htmlspecialchars((string) ($r['description'] ?? '')) . "</td>"
                . "</tr>";
        }

        $peakHourLabel = $totals['peak_hour'] !== null
            ? sprintf('%02d:00 (%d acciones)', $totals['peak_hour'], $totals['peak_hour_count'])
            : '—';

        $emptyBlock = '';
        if (empty($rows)) {
            $emptyBlock = "<div style='background:#e0f2fe;border:1px solid #7dd3fc;border-radius:8px;padding:24px;text-align:center;color:#075985;'><h3 style='margin:0 0 8px 0;'>ℹ️ Sin actividad</h3><p style='margin:0;'>No se registró actividad administrativa en esta fecha.</p></div>";
        }

        $excludedLabel = htmlspecialchars(implode(', ', $reportData['exclude_modules']) ?: '—');

        // Build stat cards as REAL <table>+<td> with inline styles (email-safe).
        $statCardStyle = 'background:#ffffff;padding:18px;border-radius:8px;text-align:center;box-shadow:0 2px 4px rgba(0,0,0,0.08);';
        $statLabelStyle = 'color:#666;font-size:12px;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px 0;';
        $statNumberStyle = 'font-size:26px;font-weight:700;margin:0;color:#111;';
        $statSubStyle = 'font-size:12px;color:#666;margin:4px 0 0 0;';

        $statsRow1 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCardStyle}border-top:4px solid #0ea5e9;'><p style='{$statLabelStyle}'>Total acciones</p><p style='{$statNumberStyle}'>{$totals['total_actions']}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #0ea5e9;'><p style='{$statLabelStyle}'>Módulos tocados</p><p style='{$statNumberStyle}'>{$totals['modules_touched']}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #0ea5e9;'><p style='{$statLabelStyle}'>Usuarios activos</p><p style='{$statNumberStyle}'>{$totals['unique_users']}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #f59e0b;'><p style='{$statLabelStyle}'>Hora pico</p><p style='{$statNumberStyle};font-size:18px;'>{$peakHourLabel}</p></td>"
            . "</tr></table>";

        $sensitiveLabel = htmlspecialchars(implode(', ', $reportData['sensitive_modules']) ?: '—');
        $statsRow2 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCardStyle}border-top:4px solid #ef4444;'><p style='{$statLabelStyle}'>Eliminaciones</p><p style='{$statNumberStyle}'>{$totals['deletes']}</p><p style='{$statSubStyle}'>acciones con action = delete</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #ef4444;'><p style='{$statLabelStyle}'>Acciones sensibles</p><p style='{$statNumberStyle}'>{$totals['sensitive']}</p><p style='{$statSubStyle}'>módulos: {$sensitiveLabel}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #64748b;'><p style='{$statLabelStyle}'>Tipos de acción</p><p style='{$statNumberStyle}'>{$totals['unique_actions']}</p></td>"
            . "<td style='{$statCardStyle}border-top:4px solid #64748b;'><p style='{$statLabelStyle}'>Ocultas (automatización)</p><p style='{$statNumberStyle}'>{$totals['excluded_count']}</p><p style='{$statSubStyle}'>módulos: {$excludedLabel}</p></td>"
            . "</tr></table>";

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
  .header { background: linear-gradient(135deg, #0f172a 0%, #1e40af 100%); color: #fff; padding: 28px; text-align: center; border-radius: 10px; }
  .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
  .header p { margin: 8px 0 0 0; font-size: 15px; opacity: 0.95; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead { background: linear-gradient(135deg, #1e293b 0%, #475569 100%); }
  th { color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  tbody tr:nth-child(even) { background-color: #fafafa; }
  td.num { font-family: 'Courier New', monospace; white-space: nowrap; text-align: right; }
  .muted { color: #888; font-size: 11px; }
  .bar { background: linear-gradient(90deg, #0ea5e9, #6366f1); height: 16px; border-radius: 3px; min-width: 2px; }
  .pill { display: inline-block; background: #e2e8f0; color: #1e293b; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
  .footer { text-align: center; padding: 18px; color: #777; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>📋 Auditoría de Actividad Administrativa</h1>
    <p>{$date}</p>
  </div>

  {$statsRow1}
  {$statsRow2}

  {$aiBlock}
  {$emptyBlock}

HTML
            . ($totals['total_actions'] > 0
                ? "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='border-collapse:separate;'><tr>"
                    . "<td style='width:50%;vertical-align:top;'><div style='background:#fff;margin:0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Por módulo</h2><table width='100%' cellspacing='0' cellpadding='0' style='border-collapse:collapse;font-size:13px;'><thead style='background:linear-gradient(135deg,#1e293b 0%,#475569 100%);'><tr><th style='color:#fff;padding:10px 8px;text-align:left;font-weight:600;font-size:12px;'>Módulo</th><th style='color:#fff;padding:10px 8px;text-align:right;font-weight:600;font-size:12px;'># Acciones</th><th style='color:#fff;padding:10px 8px;text-align:right;font-weight:600;font-size:12px;'>%</th></tr></thead><tbody>{$moduleRows}</tbody></table></div></td>"
                    . "<td style='width:50%;vertical-align:top;'><div style='background:#fff;margin:0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Por tipo de acción</h2><table width='100%' cellspacing='0' cellpadding='0' style='border-collapse:collapse;font-size:13px;'><thead style='background:linear-gradient(135deg,#1e293b 0%,#475569 100%);'><tr><th style='color:#fff;padding:10px 8px;text-align:left;font-weight:600;font-size:12px;'>Acción</th><th style='color:#fff;padding:10px 8px;text-align:right;font-weight:600;font-size:12px;'>#</th></tr></thead><tbody>{$actionRows}</tbody></table></div></td>"
                    . "</tr></table>"
                : "")
            . (!empty($sensitive)
                ? "<div style='background:#fff;margin:18px 0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #ef4444;padding-bottom:8px;'>🚨 Acciones sensibles ({$totals['sensitive']})</h2>"
                    . "<p class='muted'>Módulos marcados como sensibles: {$sensitiveLabel}. Revisar cada entrada.</p>"
                    . "<table><thead><tr><th>Hora</th><th>Usuario</th><th>Módulo · Acción</th><th>Descripción</th><th>Entidad</th></tr></thead><tbody>{$sensitiveRows}</tbody></table></div>"
                : "")
            . (!empty($deletes)
                ? "<div style='background:#fff;margin:18px 0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #ef4444;padding-bottom:8px;'>🗑️ Eliminaciones ({$totals['deletes']})</h2>"
                    . "<p class='muted'>Todas las acciones con <code>action = delete</code> del día.</p>"
                    . "<table><thead><tr><th>Hora</th><th>Usuario</th><th>Módulo</th><th>Descripción</th><th>Entidad</th></tr></thead><tbody>{$deleteRows}</tbody></table></div>"
                : "")
            . ($totals['total_actions'] > 0
                ? "<div style='background:#fff;margin:18px 0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Top {$reportData['top_users_limit']} usuarios</h2>"
                    . "<table><thead><tr><th>Usuario</th><th>Rol</th><th style='text-align:right;'># Acciones</th><th>Módulos tocados</th><th style='text-align:right;'>Ventana</th></tr></thead><tbody>{$userRows}</tbody></table></div>"
                : "")
            . ($totals['total_actions'] > 0
                ? "<div style='background:#fff;margin:18px 0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Distribución por hora</h2>"
                    . "<table><tbody>{$hourRows}</tbody></table></div>"
                : "")
            . (!empty($recent)
                ? "<div style='background:#fff;margin:18px 0;padding:22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'><h2 style='margin:0 0 14px 0;font-size:18px;border-bottom:2px solid #1e293b;padding-bottom:8px;'>Últimas {$reportData['recent_tail']} acciones del día</h2>"
                    . "<table><thead><tr><th>Hora</th><th>Usuario</th><th>Módulo · Acción</th><th>Descripción</th></tr></thead><tbody>{$recentRows}</tbody></table></div>"
                : "")
            . "<div class='footer'><p><strong>Reporte generado automáticamente</strong></p>"
            . "<p>{$reportData['generated_at']} — Tabla activity_logs · Sistema de Control de Asistencia</p></div>"
            . "</div></body></html>";
    }
}

if (!function_exists('sendActivityLogsReportByEmail')) {
    function sendActivityLogsReportByEmail(PDO $pdo, array $reportData, array $recipients, string $aiSummary = ''): bool
    {
        if (empty($recipients)) {
            error_log('[activity_logs_report] No recipients configured');
            return false;
        }

        $html = generateActivityLogsReportHTML($reportData, $aiSummary);
        require_once __DIR__ . '/email_functions.php';

        $result = sendDailyActivityLogsReport($html, $recipients, $reportData);

        if ($result['success']) {
            error_log('[activity_logs_report] Sent: ' . $result['message']);
            return true;
        }
        error_log('[activity_logs_report] Failed: ' . $result['message']);
        return false;
    }
}
