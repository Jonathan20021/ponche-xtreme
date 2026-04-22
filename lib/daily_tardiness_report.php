<?php
/**
 * Daily Tardiness Report
 *
 * Alert-style report of employees who arrived late today (scheduled entry +
 * configured tolerance). Includes:
 *   - Today's tardy list with minutes-late per employee.
 *   - Today's tardy rate (late_today / total_entries_today).
 *   - Month-to-date tardy rate for context.
 *   - Recurring offenders (employees with 3+ tardies this month).
 *   - Optional Claude narrative summary.
 *
 * Uses per-user schedules via getScheduleConfigForUser() — respects employees
 * with custom entry times.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/claude_api_client.php';

if (!function_exists('getTardinessReportSettings')) {
    function getTardinessReportSettings(PDO $pdo): array
    {
        $defaults = [
            'tardiness_report_enabled'            => '0',
            'tardiness_report_time'               => '11:00',
            'tardiness_report_recipients'         => '',
            'tardiness_report_tolerance_minutes'  => '10',
            'tardiness_report_exclude_weekends'   => '1',
            'tardiness_report_only_with_tardies'  => '0',
            'tardiness_report_claude_enabled'     => '0',
            'tardiness_report_claude_model'       => 'claude-sonnet-4-6',
            'tardiness_report_claude_max_tokens'  => '700',
            'tardiness_report_claude_prompt'      => '',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'tardiness_report_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getTardinessReportSettings: ' . $e->getMessage());
        }
        return $defaults;
    }
}

if (!function_exists('getTardinessReportRecipients')) {
    function getTardinessReportRecipients(PDO $pdo): array
    {
        $raw = (string) (getTardinessReportSettings($pdo)['tardiness_report_recipients'] ?? '');
        if ($raw === '') return [];
        $emails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($emails, static fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('tardinessSpanishDate')) {
    function tardinessSpanishDate(string $date): string
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

if (!function_exists('generateDailyTardinessReport')) {
    /**
     * Builds the report for a target date (default: today).
     *
     * @param PDO $pdo
     * @param string|null $date YYYY-MM-DD. Default: today (America/Santo_Domingo).
     * @return array
     */
    function generateDailyTardinessReport(PDO $pdo, ?string $date = null): array
    {
        $date = $date ?: date('Y-m-d');
        $settings = getTardinessReportSettings($pdo);
        $tolerance = max(0, (int) ($settings['tardiness_report_tolerance_minutes'] ?? 10));
        $toleranceSeconds = $tolerance * 60;

        // --- Step 1: fetch today's first ENTRY per user ---
        $stmt = $pdo->prepare("
            SELECT
                a.user_id,
                MIN(a.timestamp) AS first_entry,
                u.username,
                u.full_name,
                e.first_name,
                e.last_name,
                e.employee_code,
                d.name AS department_name
            FROM attendance a
            INNER JOIN users u      ON u.id = a.user_id
            LEFT JOIN employees e   ON e.user_id = u.id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE DATE(a.timestamp) = ?
              AND UPPER(a.type) = 'ENTRY'
            GROUP BY a.user_id, u.username, u.full_name, e.first_name, e.last_name, e.employee_code, d.name
            ORDER BY u.full_name
        ");
        $stmt->execute([$date]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalEntries = count($entries);
        $tardies      = [];
        $onTime       = 0;
        $byDepartment = [];

        foreach ($entries as $row) {
            $uid = (int) $row['user_id'];
            $firstEntry = $row['first_entry'];
            $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if ($fullName === '') $fullName = $row['full_name'] ?: $row['username'];
            $dept = $row['department_name'] ?? 'Sin departamento';

            // Resolve scheduled entry time (respects per-user schedules)
            $schedule = getScheduleConfigForUser($pdo, $uid, $date);
            $scheduledEntry = $schedule['entry_time'] ?? null;

            if (!$scheduledEntry) {
                // Can't evaluate without a schedule; count as on-time (or skip)
                $onTime++;
                continue;
            }

            $scheduledTs = strtotime($date . ' ' . $scheduledEntry);
            $actualTs    = strtotime($firstEntry);
            if ($scheduledTs === false || $actualTs === false) {
                $onTime++;
                continue;
            }

            $diffSeconds = $actualTs - $scheduledTs;

            if ($diffSeconds > $toleranceSeconds) {
                $lateMinutes = (int) round($diffSeconds / 60);
                $tardies[] = [
                    'user_id'         => $uid,
                    'username'        => $row['username'],
                    'full_name'       => $fullName,
                    'employee_code'   => $row['employee_code'] ?? '',
                    'department'      => $dept,
                    'scheduled_entry' => $scheduledEntry,
                    'actual_entry'    => $firstEntry,
                    'late_minutes'    => $lateMinutes,
                ];
                if (!isset($byDepartment[$dept])) {
                    $byDepartment[$dept] = ['name' => $dept, 'count' => 0, 'total_minutes' => 0];
                }
                $byDepartment[$dept]['count']++;
                $byDepartment[$dept]['total_minutes'] += $lateMinutes;
            } else {
                $onTime++;
            }
        }

        // Sort tardies by late_minutes DESC (most egregious first)
        usort($tardies, static fn($a, $b) => $b['late_minutes'] <=> $a['late_minutes']);

        // Sort departments by count DESC
        uasort($byDepartment, static fn($a, $b) => $b['count'] <=> $a['count']);
        $byDepartment = array_values($byDepartment);

        $tardyCount = count($tardies);
        $todayRate  = $totalEntries > 0 ? round(($tardyCount / $totalEntries) * 100, 2) : 0.0;
        $avgLateMin = $tardyCount > 0 ? round(array_sum(array_column($tardies, 'late_minutes')) / $tardyCount, 1) : 0.0;

        // --- Step 2: month-to-date rate + recurring offenders ---
        $monthStart = date('Y-m-01', strtotime($date));
        $monthStats = computeMonthTardinessStats($pdo, $monthStart, $date, $tolerance);

        return [
            'date'              => $date,
            'date_formatted'    => tardinessSpanishDate($date),
            'tolerance_minutes' => $tolerance,
            'totals'            => [
                'total_entries_today' => $totalEntries,
                'tardies_today'       => $tardyCount,
                'on_time_today'       => $onTime,
                'today_rate_pct'      => $todayRate,
                'avg_late_minutes'    => $avgLateMin,
                'month_rate_pct'      => $monthStats['rate'],
                'month_late_entries'  => $monthStats['late_entries'],
                'month_total_entries' => $monthStats['total_entries'],
                'month_start'         => $monthStart,
            ],
            'tardies'           => $tardies,
            'by_department'     => $byDepartment,
            'recurring'         => $monthStats['recurring'],
            'generated_at'      => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('computeMonthTardinessStats')) {
    /**
     * Computes month-to-date tardy rate and identifies recurring offenders (3+ tardies).
     *
     * Note: uses schedule_config global entry_time as a fast approximation when
     * there's no employee_schedule — this matches the behavior of dashboard.php but
     * respects custom schedules via a fallback check.
     */
    function computeMonthTardinessStats(PDO $pdo, string $startDate, string $endDate, int $toleranceMinutes): array
    {
        $toleranceSeconds = max(0, $toleranceMinutes) * 60;

        // Fetch all first-ENTRY per user-day within the month
        $stmt = $pdo->prepare("
            SELECT
                a.user_id,
                DATE(a.timestamp) AS work_date,
                MIN(a.timestamp) AS first_entry,
                u.username,
                u.full_name
            FROM attendance a
            INNER JOIN users u ON u.id = a.user_id
            WHERE DATE(a.timestamp) BETWEEN ? AND ?
              AND UPPER(a.type) = 'ENTRY'
            GROUP BY a.user_id, DATE(a.timestamp), u.username, u.full_name
            ORDER BY a.user_id, work_date
        ");
        $stmt->execute([$startDate, $endDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $total = count($rows);
        $late  = 0;
        $offenderCounts = [];

        // Cache schedules per user (the same schedule usually applies all month)
        $scheduleCache = [];

        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            if (!isset($scheduleCache[$uid])) {
                $sched = getScheduleConfigForUser($pdo, $uid, $row['work_date']);
                $scheduleCache[$uid] = $sched['entry_time'] ?? null;
            }
            $scheduledEntry = $scheduleCache[$uid];
            if (!$scheduledEntry) continue;

            $scheduledTs = strtotime($row['work_date'] . ' ' . $scheduledEntry);
            $actualTs    = strtotime($row['first_entry']);
            if ($scheduledTs === false || $actualTs === false) continue;

            if (($actualTs - $scheduledTs) > $toleranceSeconds) {
                $late++;
                if (!isset($offenderCounts[$uid])) {
                    $offenderCounts[$uid] = [
                        'user_id'   => $uid,
                        'username'  => $row['username'],
                        'full_name' => $row['full_name'],
                        'count'     => 0,
                    ];
                }
                $offenderCounts[$uid]['count']++;
            }
        }

        $rate = $total > 0 ? round(($late / $total) * 100, 2) : 0.0;

        // Recurring = 3+ tardies this month, sorted desc
        $recurring = array_values(array_filter($offenderCounts, static fn($o) => $o['count'] >= 3));
        usort($recurring, static fn($a, $b) => $b['count'] <=> $a['count']);
        $recurring = array_slice($recurring, 0, 15);

        return [
            'total_entries' => $total,
            'late_entries'  => $late,
            'rate'          => $rate,
            'recurring'     => $recurring,
        ];
    }
}

if (!function_exists('generateAITardinessSummary')) {
    function generateAITardinessSummary(PDO $pdo, array $reportData): string
    {
        $settings = getTardinessReportSettings($pdo);
        if (($settings['tardiness_report_claude_enabled'] ?? '0') !== '1') {
            return '';
        }
        if (empty($reportData['tardies'])) {
            return '';
        }

        $model = trim((string) ($settings['tardiness_report_claude_model'] ?? '')) ?: resolveAnthropicDefaultModel($pdo);
        $maxTokens = max(100, (int) ($settings['tardiness_report_claude_max_tokens'] ?? 700));
        $systemPrompt = (string) ($settings['tardiness_report_claude_prompt'] ?? '');

        $payload = [
            'fecha'       => $reportData['date'],
            'tolerancia'  => $reportData['tolerance_minutes'],
            'totales'     => $reportData['totals'],
            'tardanzas'   => array_map(static fn($t) => [
                'nombre'         => $t['full_name'],
                'departamento'   => $t['department'],
                'horario_prog'   => $t['scheduled_entry'],
                'entrada_real'   => date('H:i:s', strtotime($t['actual_entry'])),
                'minutos_tarde'  => $t['late_minutes'],
            ], $reportData['tardies']),
            'por_departamento' => $reportData['by_department'],
            'recurrentes_mes'  => $reportData['recurring'],
        ];

        $userPrompt = "Aquí están las tardanzas del día en JSON. Genera el resumen ejecutivo según las instrucciones:\n\n"
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
            error_log('[tardiness_report] Claude API error: ' . ($result['error'] ?? 'unknown'));
            return '';
        }
        return (string) $result['content'];
    }
}

if (!function_exists('generateTardinessReportHTML')) {
    function generateTardinessReportHTML(array $reportData, string $aiSummary = ''): string
    {
        $date       = htmlspecialchars($reportData['date_formatted']);
        $tolerance  = (int) $reportData['tolerance_minutes'];
        $totals     = $reportData['totals'];
        $tardies    = $reportData['tardies'];
        $byDept     = $reportData['by_department'];
        $recurring  = $reportData['recurring'];

        $aiBlock = '';
        if (trim($aiSummary) !== '') {
            $safe = nl2br(htmlspecialchars($aiSummary), false);
            $aiBlock = "<div class='ai-summary'>"
                . "<div class='ai-badge'>Resumen ejecutivo generado por IA</div>"
                . "<div class='ai-body'>{$safe}</div>"
                . "</div>";
        }

        // Tardy rows (sorted by minutes DESC)
        $tardyRows = '';
        if (!empty($tardies)) {
            foreach ($tardies as $t) {
                $name      = htmlspecialchars($t['full_name']);
                $code      = htmlspecialchars($t['employee_code'] ?? '');
                $dept      = htmlspecialchars($t['department']);
                $scheduled = htmlspecialchars($t['scheduled_entry']);
                $actual    = htmlspecialchars(date('H:i:s', strtotime($t['actual_entry'])));
                $minutes   = (int) $t['late_minutes'];

                // Severity bucket for the badge
                if ($minutes >= 60) {
                    $sev = 'badge-critical';
                    $sevLabel = 'Crítico';
                } elseif ($minutes >= 30) {
                    $sev = 'badge-danger';
                    $sevLabel = 'Grave';
                } elseif ($minutes >= 15) {
                    $sev = 'badge-warning';
                    $sevLabel = 'Moderado';
                } else {
                    $sev = 'badge-light';
                    $sevLabel = 'Leve';
                }

                $tardyRows .= "<tr>"
                    . "<td><strong>{$name}</strong><br><span class='muted'>{$code}</span></td>"
                    . "<td>{$dept}</td>"
                    . "<td class='num'>{$scheduled}</td>"
                    . "<td class='num'>{$actual}</td>"
                    . "<td class='num'><strong>+{$minutes} min</strong></td>"
                    . "<td><span class='badge {$sev}'>{$sevLabel}</span></td>"
                    . "</tr>";
            }
        }

        // Department rows
        $deptRows = '';
        foreach ($byDept as $d) {
            $avgMin = $d['count'] > 0 ? round($d['total_minutes'] / $d['count'], 1) : 0;
            $deptRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars($d['name']) . "</strong></td>"
                . "<td class='num'>{$d['count']}</td>"
                . "<td class='num'>{$d['total_minutes']} min</td>"
                . "<td class='num'>{$avgMin} min</td>"
                . "</tr>";
        }

        // Recurring offenders rows
        $recurringRows = '';
        foreach ($recurring as $o) {
            $name = htmlspecialchars($o['full_name']);
            $recurringRows .= "<tr>"
                . "<td><strong>{$name}</strong><br><span class='muted'>" . htmlspecialchars($o['username']) . "</span></td>"
                . "<td class='num'><strong>{$o['count']}</strong> días</td>"
                . "</tr>";
        }

        $monthStart = htmlspecialchars($totals['month_start']);
        $todayRate  = number_format($totals['today_rate_pct'], 2);
        $monthRate  = number_format($totals['month_rate_pct'], 2);
        $monthLate  = $totals['month_late_entries'];
        $monthTotal = $totals['month_total_entries'];
        $avgLate    = $totals['avg_late_minutes'];

        $trendArrow = '';
        if ($totals['month_rate_pct'] > 0) {
            if ($totals['today_rate_pct'] > $totals['month_rate_pct']) {
                $trendArrow = " <span style='color:#ef4444;'>↑ por encima del promedio</span>";
            } elseif ($totals['today_rate_pct'] < $totals['month_rate_pct']) {
                $trendArrow = " <span style='color:#10b981;'>↓ mejor que el promedio</span>";
            }
        }

        $successBlock = '';
        if (empty($tardies)) {
            $successBlock = "<div class='success-card'><h3>✅ ¡Excelente!</h3><p>No hubo tardanzas registradas hoy con una tolerancia de {$tolerance} minutos. Todos llegaron a tiempo.</p></div>";
        }

        $recurringSection = '';
        if (!empty($recurring)) {
            $recurringSection = "<div class='section'><h2>Empleados recurrentes ({$monthStart} al presente)</h2>"
                . "<p class='muted-small'>Empleados con 3 o más tardanzas este mes. Considerar intervención.</p>"
                . "<table><thead><tr><th>Empleado</th><th style='text-align:right;'>Días con tardanza</th></tr></thead><tbody>{$recurringRows}</tbody></table></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
  .header { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #fff; padding: 28px; text-align: center; border-radius: 10px; }
  .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
  .header p { margin: 8px 0 0 0; font-size: 15px; opacity: 0.95; }
  .stats-grid { display: table; width: 100%; margin: 18px 0; border-spacing: 10px; }
  .stat-card { display: table-cell; background: #fff; padding: 18px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .stat-card.danger  { border-top: 4px solid #ef4444; }
  .stat-card.warning { border-top: 4px solid #f59e0b; }
  .stat-card.primary { border-top: 4px solid #0ea5e9; }
  .stat-card.success { border-top: 4px solid #10b981; }
  .stat-card.muted   { border-top: 4px solid #64748b; }
  .stat-number { font-size: 26px; font-weight: 700; margin: 8px 0 0 0; }
  .stat-sub { font-size: 12px; color: #666; margin-top: 4px; }
  .stat-label  { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
  .ai-summary { background: #fffbeb; border: 1px solid #fcd34d; border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 8px; margin: 18px 0; }
  .ai-badge { display: inline-block; background: #f59e0b; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 10px; }
  .ai-body { color: #333; font-size: 14px; white-space: pre-wrap; }
  .section { background: #fff; margin: 18px 0; padding: 22px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .section h2 { margin: 0 0 14px 0; font-size: 18px; border-bottom: 2px solid #f59e0b; padding-bottom: 8px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
  th { color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  tbody tr:nth-child(even) { background-color: #fafafa; }
  td.num { font-family: 'Courier New', monospace; white-space: nowrap; text-align: right; }
  .muted { color: #888; font-size: 11px; }
  .muted-small { color: #666; font-size: 12px; margin-bottom: 8px; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; color: #fff; }
  .badge-critical { background: #7f1d1d; }
  .badge-danger   { background: #ef4444; }
  .badge-warning  { background: #f59e0b; }
  .badge-light    { background: #fcd34d; color: #78350f; }
  .success-card { background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 8px; padding: 24px; text-align: center; color: #065f46; }
  .footer { text-align: center; padding: 18px; color: #777; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Reporte de Tardanzas del Día</h1>
    <p>{$date} · Tolerancia: {$tolerance} min</p>
  </div>

  <div class="stats-grid">
    <div class="stat-card danger">
      <div class="stat-label">Tardanzas hoy</div>
      <div class="stat-number">{$totals['tardies_today']}</div>
      <div class="stat-sub">de {$totals['total_entries_today']} entradas</div>
    </div>
    <div class="stat-card warning">
      <div class="stat-label">Tasa de tardanza hoy</div>
      <div class="stat-number">{$todayRate}%</div>
      <div class="stat-sub">{$trendArrow}</div>
    </div>
    <div class="stat-card primary">
      <div class="stat-label">Tasa acumulada del mes</div>
      <div class="stat-number">{$monthRate}%</div>
      <div class="stat-sub">{$monthLate} / {$monthTotal} entradas</div>
    </div>
    <div class="stat-card muted">
      <div class="stat-label">Retraso promedio hoy</div>
      <div class="stat-number">{$avgLate} min</div>
    </div>
  </div>

  {$aiBlock}
  {$successBlock}

HTML
            . (!empty($tardies)
                ? "<div class='section'><h2>Listado de tardanzas de hoy ({$totals['tardies_today']})</h2>"
                    . "<table><thead><tr><th>Empleado</th><th>Departamento</th><th style='text-align:right;'>Horario programado</th><th style='text-align:right;'>Entrada real</th><th style='text-align:right;'>Retraso</th><th>Severidad</th></tr></thead><tbody>{$tardyRows}</tbody></table></div>"
                : "")
            . (!empty($deptRows)
                ? "<div class='section'><h2>Tardanzas por departamento</h2>"
                    . "<table><thead><tr><th>Departamento</th><th style='text-align:right;'># Tardanzas</th><th style='text-align:right;'>Minutos totales</th><th style='text-align:right;'>Promedio/tardanza</th></tr></thead><tbody>{$deptRows}</tbody></table></div>"
                : "")
            . $recurringSection
            . "<div class='footer'><p><strong>Reporte generado automáticamente</strong></p>"
            . "<p>{$reportData['generated_at']} — Sistema de Control de Asistencia</p></div>"
            . "</div></body></html>";
    }
}

if (!function_exists('sendTardinessReportByEmail')) {
    function sendTardinessReportByEmail(PDO $pdo, array $reportData, array $recipients, string $aiSummary = ''): bool
    {
        if (empty($recipients)) {
            error_log('[tardiness_report] No recipients configured');
            return false;
        }

        $html = generateTardinessReportHTML($reportData, $aiSummary);
        require_once __DIR__ . '/email_functions.php';

        $result = sendDailyTardinessReport($html, $recipients, $reportData);

        if ($result['success']) {
            error_log('[tardiness_report] Sent: ' . $result['message']);
            return true;
        }
        error_log('[tardiness_report] Failed: ' . $result['message']);
        return false;
    }
}
