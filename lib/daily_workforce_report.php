<?php
/**
 * Daily Workforce (Active vs. Absent) Report
 *
 * Replicates the Executive Dashboard's getWorkforceSummary() logic and produces
 * a snapshot at 9 AM (or shift-start) showing:
 *   - Active employees (employment_status = 'ACTIVE')
 *   - Trial employees  (employment_status = 'TRIAL')
 *   - Absent employees  (ACTIVE or TRIAL with no attendance today)
 *   - Breakdown by department and by role
 *   - Detailed list of absentees with last-punch info (how many days ago)
 *   - Optional Claude executive summary
 *
 * All employees with users.is_active = 1 are considered. Terminated / suspended
 * are excluded (match dashboard behavior).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/claude_api_client.php';

if (!function_exists('getWorkforceReportSettings')) {
    function getWorkforceReportSettings(PDO $pdo): array
    {
        $defaults = [
            'workforce_report_enabled'            => '0',
            'workforce_report_time'               => '09:00',
            'workforce_report_recipients'         => '',
            'workforce_report_exclude_weekends'   => '1',
            'workforce_report_only_with_absences' => '0',
            'workforce_report_claude_enabled'     => '0',
            'workforce_report_claude_model'       => 'claude-sonnet-4-6',
            'workforce_report_claude_max_tokens'  => '700',
            'workforce_report_claude_prompt'      => '',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'workforce_report_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getWorkforceReportSettings: ' . $e->getMessage());
        }
        return $defaults;
    }
}

if (!function_exists('getWorkforceReportRecipients')) {
    function getWorkforceReportRecipients(PDO $pdo): array
    {
        $raw = (string) (getWorkforceReportSettings($pdo)['workforce_report_recipients'] ?? '');
        if ($raw === '') return [];
        $emails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($emails, static fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('workforceSpanishDate')) {
    function workforceSpanishDate(string $date): string
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

if (!function_exists('generateDailyWorkforceReport')) {
    /**
     * Builds the workforce snapshot for the target date (default: today).
     */
    function generateDailyWorkforceReport(PDO $pdo, ?string $date = null): array
    {
        $date = $date ?: date('Y-m-d');

        // --- Counts by employment_status (active employees only in users.is_active = 1) ---
        $statusStmt = $pdo->query("
            SELECT e.employment_status, COUNT(*) AS total
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE u.is_active = 1
            GROUP BY e.employment_status
        ");
        $statusMap = ['ACTIVE' => 0, 'TRIAL' => 0, 'SUSPENDED' => 0, 'TERMINATED' => 0];
        foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $s = strtoupper((string) $row['employment_status']);
            $statusMap[$s] = (int) $row['total'];
        }

        $activeCount = $statusMap['ACTIVE'];
        $trialCount  = $statusMap['TRIAL'];
        $totalEligible = $activeCount + $trialCount;

        // --- Detailed list: all eligible employees and whether they have a punch today ---
        $stmt = $pdo->prepare("
            SELECT
                e.id                AS employee_id,
                e.employee_code,
                e.first_name,
                e.last_name,
                e.position,
                e.employment_status,
                u.id                AS user_id,
                u.username,
                u.full_name,
                u.role,
                d.name              AS department_name,
                (SELECT MIN(a.timestamp) FROM attendance a WHERE a.user_id = u.id AND DATE(a.timestamp) = ?) AS first_punch_today,
                (SELECT MAX(DATE(a.timestamp)) FROM attendance a WHERE a.user_id = u.id AND DATE(a.timestamp) < ?) AS last_punch_before
            FROM employees e
            INNER JOIN users u      ON u.id = e.user_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE u.is_active = 1
              AND e.employment_status IN ('ACTIVE', 'TRIAL')
            ORDER BY e.last_name, e.first_name
        ");
        $stmt->execute([$date, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $present = [];
        $absent  = [];
        $byDepartmentAbs = [];
        $byRoleAbs       = [];
        $trialAbsent     = [];

        $dateTs = strtotime($date);

        foreach ($rows as $r) {
            $fullName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            if ($fullName === '') $fullName = $r['full_name'] ?: $r['username'];
            $dept = $r['department_name'] ?? 'Sin departamento';
            $role = $r['role'] ?? '—';
            $status = strtoupper((string) $r['employment_status']);

            $record = [
                'employee_id'      => (int) $r['employee_id'],
                'user_id'          => (int) $r['user_id'],
                'username'         => $r['username'],
                'full_name'        => $fullName,
                'employee_code'    => $r['employee_code'] ?? '',
                'position'         => $r['position'] ?? '',
                'employment_status'=> $status,
                'role'             => $role,
                'department'       => $dept,
                'first_punch_today'=> $r['first_punch_today'],
                'last_punch_before'=> $r['last_punch_before'],
            ];

            if ($r['first_punch_today']) {
                $present[] = $record;
            } else {
                // Days since last punch
                $daysSince = null;
                if ($r['last_punch_before']) {
                    $lastTs = strtotime($r['last_punch_before']);
                    if ($lastTs !== false && $dateTs !== false) {
                        $daysSince = (int) floor(($dateTs - $lastTs) / 86400);
                    }
                }
                $record['days_since_last_punch'] = $daysSince;

                $absent[] = $record;

                if (!isset($byDepartmentAbs[$dept])) {
                    $byDepartmentAbs[$dept] = ['name' => $dept, 'count' => 0];
                }
                $byDepartmentAbs[$dept]['count']++;

                if (!isset($byRoleAbs[$role])) {
                    $byRoleAbs[$role] = ['name' => $role, 'count' => 0];
                }
                $byRoleAbs[$role]['count']++;

                if ($status === 'TRIAL') {
                    $trialAbsent[] = $record;
                }
            }
        }

        // Sort absences: those without any prior punch first (new hires no-show),
        // then by days_since_last_punch desc
        usort($absent, static function ($a, $b) {
            $aNever = $a['days_since_last_punch'] === null ? 1 : 0;
            $bNever = $b['days_since_last_punch'] === null ? 1 : 0;
            if ($aNever !== $bNever) return $bNever <=> $aNever;
            return ($b['days_since_last_punch'] ?? 0) <=> ($a['days_since_last_punch'] ?? 0);
        });

        uasort($byDepartmentAbs, static fn($a, $b) => $b['count'] <=> $a['count']);
        $byDepartmentAbs = array_values($byDepartmentAbs);

        uasort($byRoleAbs, static fn($a, $b) => $b['count'] <=> $a['count']);
        $byRoleAbs = array_values($byRoleAbs);

        $presentCount = count($present);
        $absentCount  = count($absent);
        $presentRate  = $totalEligible > 0 ? round(($presentCount / $totalEligible) * 100, 2) : 0.0;
        $absentRate   = $totalEligible > 0 ? round(($absentCount  / $totalEligible) * 100, 2) : 0.0;

        return [
            'date'           => $date,
            'date_formatted' => workforceSpanishDate($date),
            'totals'         => [
                'active_employees'  => $activeCount,
                'trial_employees'   => $trialCount,
                'total_eligible'    => $totalEligible,
                'present_today'     => $presentCount,
                'absent_today'      => $absentCount,
                'present_rate_pct'  => $presentRate,
                'absent_rate_pct'   => $absentRate,
                'trial_absent'      => count($trialAbsent),
                'suspended'         => $statusMap['SUSPENDED'],
                'terminated'        => $statusMap['TERMINATED'],
            ],
            'present'        => $present,
            'absent'         => $absent,
            'trial_absent'   => $trialAbsent,
            'by_department_absent' => $byDepartmentAbs,
            'by_role_absent'       => $byRoleAbs,
            'generated_at'   => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('generateAIWorkforceSummary')) {
    function generateAIWorkforceSummary(PDO $pdo, array $reportData): string
    {
        $settings = getWorkforceReportSettings($pdo);
        if (($settings['workforce_report_claude_enabled'] ?? '0') !== '1') {
            return '';
        }

        $model = trim((string) ($settings['workforce_report_claude_model'] ?? '')) ?: resolveAnthropicDefaultModel($pdo);
        $maxTokens = max(100, (int) ($settings['workforce_report_claude_max_tokens'] ?? 700));
        $systemPrompt = (string) ($settings['workforce_report_claude_prompt'] ?? '');

        $payload = [
            'fecha'     => $reportData['date'],
            'totales'   => $reportData['totals'],
            'por_departamento_ausentes' => $reportData['by_department_absent'],
            'por_rol_ausentes'          => $reportData['by_role_absent'],
            'ausentes_en_prueba' => array_map(static fn($t) => [
                'nombre'       => $t['full_name'],
                'departamento' => $t['department'],
                'rol'          => $t['role'],
                'dias_sin_ponchar' => $t['days_since_last_punch'],
            ], $reportData['trial_absent']),
            'lista_ausentes' => array_map(static fn($a) => [
                'nombre'       => $a['full_name'],
                'departamento' => $a['department'],
                'rol'          => $a['role'],
                'estado'       => $a['employment_status'],
                'dias_sin_ponchar' => $a['days_since_last_punch'],
            ], array_slice($reportData['absent'], 0, 50)),
        ];

        $userPrompt = "Aquí está el snapshot de fuerza laboral del día en JSON. Genera el resumen ejecutivo según las instrucciones:\n\n"
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
            error_log('[workforce_report] Claude API error: ' . ($result['error'] ?? 'unknown'));
            return '';
        }
        return (string) $result['content'];
    }
}

if (!function_exists('generateWorkforceReportHTML')) {
    function generateWorkforceReportHTML(array $reportData, string $aiSummary = ''): string
    {
        $date       = htmlspecialchars($reportData['date_formatted']);
        $totals     = $reportData['totals'];
        $absent     = $reportData['absent'];
        $byDept     = $reportData['by_department_absent'];
        $byRole     = $reportData['by_role_absent'];
        $trialAbs   = $reportData['trial_absent'];

        $aiBlock = '';
        if (trim($aiSummary) !== '') {
            $safe = nl2br(htmlspecialchars($aiSummary), false);
            $aiBlock = "<div class='ai-summary'>"
                . "<div class='ai-badge'>Resumen ejecutivo generado por IA</div>"
                . "<div class='ai-body'>{$safe}</div>"
                . "</div>";
        }

        // Absent rows
        $absentRows = '';
        if (!empty($absent)) {
            foreach ($absent as $a) {
                $name  = htmlspecialchars($a['full_name']);
                $code  = htmlspecialchars($a['employee_code'] ?? '');
                $dept  = htmlspecialchars($a['department']);
                $role  = htmlspecialchars($a['role']);
                $status = htmlspecialchars($a['employment_status']);
                $statusBadge = $status === 'TRIAL'
                    ? "<span class='badge badge-trial'>En Prueba</span>"
                    : "<span class='badge badge-active'>Activo</span>";

                $daysSince = $a['days_since_last_punch'];
                if ($daysSince === null) {
                    $lastInfo = "<span class='badge badge-critical'>Nunca ha ponchado</span>";
                } elseif ($daysSince === 0) {
                    $lastInfo = "Ayer";
                } elseif ($daysSince <= 3) {
                    $lastInfo = "Hace <strong>{$daysSince}</strong> días";
                } else {
                    $lastInfo = "Hace <strong>{$daysSince}</strong> días <span class='badge badge-danger'>Crítico</span>";
                }

                $absentRows .= "<tr>"
                    . "<td><strong>{$name}</strong><br><span class='muted'>{$code}</span></td>"
                    . "<td>{$role}</td>"
                    . "<td>{$dept}</td>"
                    . "<td>{$statusBadge}</td>"
                    . "<td>{$lastInfo}</td>"
                    . "</tr>";
            }
        }

        // Department rows
        $deptRows = '';
        foreach ($byDept as $d) {
            $deptRows .= "<tr><td><strong>" . htmlspecialchars($d['name']) . "</strong></td><td class='num'>{$d['count']}</td></tr>";
        }

        // Role rows
        $roleRows = '';
        foreach ($byRole as $r) {
            $roleRows .= "<tr><td><strong>" . htmlspecialchars($r['name']) . "</strong></td><td class='num'>{$r['count']}</td></tr>";
        }

        $trialAbsentSection = '';
        if (!empty($trialAbs)) {
            $trialRows = '';
            foreach ($trialAbs as $t) {
                $name = htmlspecialchars($t['full_name']);
                $dept = htmlspecialchars($t['department']);
                $days = $t['days_since_last_punch'];
                $daysLabel = $days === null ? 'Nunca' : "Hace {$days} días";
                $trialRows .= "<tr><td><strong>{$name}</strong></td><td>{$dept}</td><td>{$daysLabel}</td></tr>";
            }
            $trialAbsentSection = "<div class='section alert-trial'>"
                . "<h2>⚠️ Empleados EN PRUEBA ausentes hoy ({$totals['trial_absent']})</h2>"
                . "<p class='muted-small'>Punto crítico — candidatos a seguimiento inmediato del período de prueba.</p>"
                . "<table><thead><tr><th>Empleado</th><th>Departamento</th><th>Último punch</th></tr></thead><tbody>{$trialRows}</tbody></table>"
                . "</div>";
        }

        $successBlock = '';
        if (empty($absent)) {
            $successBlock = "<div class='success-card'><h3>✅ ¡Excelente!</h3><p>Todos los empleados activos y en prueba han registrado entrada hoy. Asistencia al 100%.</p></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
  .header { background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%); color: #fff; padding: 28px; text-align: center; border-radius: 10px; }
  .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
  .header p { margin: 8px 0 0 0; font-size: 15px; opacity: 0.95; }
  .stats-grid { display: table; width: 100%; margin: 18px 0; border-spacing: 10px; }
  .stat-card { display: table-cell; background: #fff; padding: 18px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .stat-card.success { border-top: 4px solid #10b981; }
  .stat-card.primary { border-top: 4px solid #6366f1; }
  .stat-card.trial   { border-top: 4px solid #f59e0b; }
  .stat-card.danger  { border-top: 4px solid #ef4444; }
  .stat-card.muted   { border-top: 4px solid #64748b; }
  .stat-number { font-size: 28px; font-weight: 700; margin: 8px 0 0 0; }
  .stat-sub { font-size: 12px; color: #666; margin-top: 4px; }
  .stat-label  { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
  .ai-summary { background: #fffbeb; border: 1px solid #fcd34d; border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 8px; margin: 18px 0; }
  .ai-badge { display: inline-block; background: #f59e0b; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 10px; }
  .ai-body { color: #333; font-size: 14px; white-space: pre-wrap; }
  .section { background: #fff; margin: 18px 0; padding: 22px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .section h2 { margin: 0 0 14px 0; font-size: 18px; border-bottom: 2px solid #6366f1; padding-bottom: 8px; }
  .section.alert-trial h2 { border-bottom-color: #f59e0b; }
  .two-col { display: table; width: 100%; border-spacing: 10px; }
  .two-col > .col { display: table-cell; width: 50%; vertical-align: top; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead { background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%); }
  th { color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  tbody tr:nth-child(even) { background-color: #fafafa; }
  td.num { font-family: 'Courier New', monospace; white-space: nowrap; text-align: right; }
  .muted { color: #888; font-size: 11px; }
  .muted-small { color: #666; font-size: 12px; margin-bottom: 8px; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; color: #fff; }
  .badge-active   { background: #10b981; }
  .badge-trial    { background: #f59e0b; }
  .badge-danger   { background: #ef4444; }
  .badge-critical { background: #7f1d1d; }
  .success-card { background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 8px; padding: 24px; text-align: center; color: #065f46; }
  .footer { text-align: center; padding: 18px; color: #777; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Fuerza Laboral del Día</h1>
    <p>{$date} · Foto al inicio del turno</p>
  </div>

  <div class="stats-grid">
    <div class="stat-card primary">
      <div class="stat-label">Total elegibles</div>
      <div class="stat-number">{$totals['total_eligible']}</div>
      <div class="stat-sub">Activos + En Prueba</div>
    </div>
    <div class="stat-card success">
      <div class="stat-label">Presentes hoy</div>
      <div class="stat-number">{$totals['present_today']}</div>
      <div class="stat-sub">{$totals['present_rate_pct']}%</div>
    </div>
    <div class="stat-card danger">
      <div class="stat-label">Ausentes hoy</div>
      <div class="stat-number">{$totals['absent_today']}</div>
      <div class="stat-sub">{$totals['absent_rate_pct']}%</div>
    </div>
    <div class="stat-card trial">
      <div class="stat-label">En prueba ausentes</div>
      <div class="stat-number">{$totals['trial_absent']}</div>
      <div class="stat-sub">requieren atención</div>
    </div>
  </div>

  <div class="stats-grid">
    <div class="stat-card success">
      <div class="stat-label">Activos (total)</div>
      <div class="stat-number">{$totals['active_employees']}</div>
    </div>
    <div class="stat-card trial">
      <div class="stat-label">En Prueba (total)</div>
      <div class="stat-number">{$totals['trial_employees']}</div>
    </div>
    <div class="stat-card muted">
      <div class="stat-label">Suspendidos</div>
      <div class="stat-number">{$totals['suspended']}</div>
    </div>
    <div class="stat-card muted">
      <div class="stat-label">Terminados</div>
      <div class="stat-number">{$totals['terminated']}</div>
    </div>
  </div>

  {$aiBlock}
  {$successBlock}
  {$trialAbsentSection}

HTML
            . (!empty($absent)
                ? "<div class='section'><h2>Listado de ausentes ({$totals['absent_today']})</h2>"
                    . "<p class='muted-small'>Empleados activos o en prueba sin ningún registro de entrada hoy. Ordenados por días sin ponchar (descendente).</p>"
                    . "<table><thead><tr><th>Empleado</th><th>Rol</th><th>Departamento</th><th>Estado</th><th>Último punch</th></tr></thead><tbody>{$absentRows}</tbody></table></div>"
                : "")
            . ((!empty($byDept) || !empty($byRole))
                ? "<div class='two-col'>"
                    . (!empty($byDept)
                        ? "<div class='col'><div class='section'><h2>Ausentes por departamento</h2><table><thead><tr><th>Departamento</th><th style='text-align:right;'># Ausentes</th></tr></thead><tbody>{$deptRows}</tbody></table></div></div>"
                        : "<div class='col'></div>")
                    . (!empty($byRole)
                        ? "<div class='col'><div class='section'><h2>Ausentes por rol</h2><table><thead><tr><th>Rol</th><th style='text-align:right;'># Ausentes</th></tr></thead><tbody>{$roleRows}</tbody></table></div></div>"
                        : "<div class='col'></div>")
                    . "</div>"
                : "")
            . "<div class='footer'><p><strong>Reporte generado automáticamente</strong></p>"
            . "<p>{$reportData['generated_at']} — Sistema de Control de Asistencia</p></div>"
            . "</div></body></html>";
    }
}

if (!function_exists('sendWorkforceReportByEmail')) {
    function sendWorkforceReportByEmail(PDO $pdo, array $reportData, array $recipients, string $aiSummary = ''): bool
    {
        if (empty($recipients)) {
            error_log('[workforce_report] No recipients configured');
            return false;
        }

        $html = generateWorkforceReportHTML($reportData, $aiSummary);
        require_once __DIR__ . '/email_functions.php';

        $result = sendDailyWorkforceReport($html, $recipients, $reportData);

        if ($result['success']) {
            error_log('[workforce_report] Sent: ' . $result['message']);
            return true;
        }
        error_log('[workforce_report] Failed: ' . $result['message']);
        return false;
    }
}
