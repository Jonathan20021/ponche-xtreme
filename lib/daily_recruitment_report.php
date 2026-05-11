<?php
/**
 * Daily Recruitment Report
 *
 * Snapshot of the recruitment pipeline:
 *   - Active job postings + applications per posting
 *   - Today's activity (new applications, status changes, interviews)
 *   - Period totals (configurable window): applications received, hires, rejections
 *   - Funnel by status (with conversion rates)
 *   - Top AI-scored shortlist candidates pending action
 *   - Upcoming interviews (today + next N days)
 *   - Application sources breakdown
 *   - Bottlenecks: applications stuck in a stage
 *   - Recent hires & rejections
 *   - Time-to-hire average
 *   - Optional Claude executive summary
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/claude_api_client.php';

if (!function_exists('getRecruitmentReportSettings')) {
    function getRecruitmentReportSettings(PDO $pdo): array
    {
        $defaults = [
            'recruitment_report_enabled'              => '0',
            'recruitment_report_time'                 => '08:30',
            'recruitment_report_recipients'           => '',
            'recruitment_report_exclude_weekends'     => '1',
            'recruitment_report_only_with_activity'   => '0',
            'recruitment_report_period_days'          => '7',
            'recruitment_report_upcoming_days'        => '3',
            'recruitment_report_bottleneck_days'      => '7',
            'recruitment_report_min_ai_score'         => '70',
            'recruitment_report_claude_enabled'       => '0',
            'recruitment_report_claude_model'         => 'claude-sonnet-4-6',
            'recruitment_report_claude_max_tokens'    => '900',
            'recruitment_report_claude_prompt'        => '',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'recruitment_report_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getRecruitmentReportSettings: ' . $e->getMessage());
        }
        return $defaults;
    }
}

if (!function_exists('getRecruitmentReportRecipients')) {
    function getRecruitmentReportRecipients(PDO $pdo): array
    {
        $raw = (string) (getRecruitmentReportSettings($pdo)['recruitment_report_recipients'] ?? '');
        if ($raw === '') return [];
        $emails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($emails, static fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('recruitmentReportSpanishDate')) {
    function recruitmentReportSpanishDate(string $date): string
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

if (!function_exists('recruitmentStatusLabel')) {
    function recruitmentStatusLabel(string $status): string
    {
        $map = [
            'new'                 => 'Nuevo',
            'reviewing'           => 'En revisión',
            'shortlisted'         => 'Preseleccionado',
            'interview_scheduled' => 'Entrevista agendada',
            'interviewed'         => 'Entrevistado',
            'offer_extended'      => 'Oferta extendida',
            'hired'               => 'Contratado',
            'rejected'            => 'Rechazado',
            'withdrawn'           => 'Retirado',
        ];
        return $map[$status] ?? ucfirst($status);
    }
}

if (!function_exists('recruitmentStatusColor')) {
    function recruitmentStatusColor(string $status): string
    {
        $map = [
            'new'                 => '#6366f1',
            'reviewing'           => '#0ea5e9',
            'shortlisted'         => '#8b5cf6',
            'interview_scheduled' => '#f59e0b',
            'interviewed'         => '#ec4899',
            'offer_extended'      => '#3b82f6',
            'hired'               => '#10b981',
            'rejected'            => '#94a3b8',
            'withdrawn'           => '#64748b',
        ];
        return $map[$status] ?? '#64748b';
    }
}

if (!function_exists('generateDailyRecruitmentReport')) {
    function generateDailyRecruitmentReport(PDO $pdo, ?string $date = null, array $overrides = []): array
    {
        $date = $date ?: date('Y-m-d');
        $settings = getRecruitmentReportSettings($pdo);

        $periodDays     = (int) ($overrides['period_days']     ?? $settings['recruitment_report_period_days']     ?? 7);
        $upcomingDays   = (int) ($overrides['upcoming_days']   ?? $settings['recruitment_report_upcoming_days']   ?? 3);
        $bottleneckDays = (int) ($overrides['bottleneck_days'] ?? $settings['recruitment_report_bottleneck_days'] ?? 7);
        $minAiScore     = (int) ($overrides['min_ai_score']    ?? $settings['recruitment_report_min_ai_score']    ?? 70);

        if ($periodDays     < 1)  $periodDays     = 7;
        if ($upcomingDays   < 0)  $upcomingDays   = 3;
        if ($bottleneckDays < 1)  $bottleneckDays = 7;
        if ($minAiScore     < 0 || $minAiScore > 100) $minAiScore = 70;

        // --- Active job postings + applications per posting
        $postStmt = $pdo->query("
            SELECT jp.id, jp.title, jp.department, jp.location, jp.employment_type, jp.posted_date, jp.closing_date,
                   (SELECT COUNT(*) FROM job_applications a WHERE a.job_posting_id = jp.id) AS apps_total,
                   (SELECT COUNT(*) FROM job_applications a WHERE a.job_posting_id = jp.id AND a.status IN ('new','reviewing','shortlisted','interview_scheduled','interviewed','offer_extended')) AS apps_pipeline,
                   (SELECT COUNT(*) FROM job_applications a WHERE a.job_posting_id = jp.id AND a.status = 'hired') AS apps_hired,
                   (SELECT COUNT(*) FROM job_applications a WHERE a.job_posting_id = jp.id AND DATE(a.applied_date) = CURDATE()) AS apps_today
            FROM job_postings jp
            WHERE jp.status = 'active'
            ORDER BY apps_pipeline DESC, jp.posted_date DESC
        ");
        $postings = $postStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // --- Totals
        $totalActivePostings = count($postings);
        $closedPostings = (int) $pdo->query("SELECT COUNT(*) FROM job_postings WHERE status IN ('inactive','closed')")->fetchColumn();

        // --- Today's activity
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_applications WHERE DATE(applied_date) = ?");
        $stmt->execute([$date]);
        $todayNewApps = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM application_status_history WHERE DATE(changed_at) = ?");
        $stmt->execute([$date]);
        $todayStatusChanges = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM recruitment_interviews WHERE DATE(interview_date) = ?");
        $stmt->execute([$date]);
        $todayInterviews = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_applications WHERE DATE(applied_date) = ? AND ai_score IS NOT NULL");
        $stmt->execute([$date]);
        $todayAiScored = (int) $stmt->fetchColumn();

        // --- Period totals (last N days, inclusive)
        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN DATE(applied_date) BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ? THEN 1 ELSE 0 END) AS apps_received,
                SUM(CASE WHEN status = 'hired'    AND DATE(last_updated) BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ? THEN 1 ELSE 0 END) AS apps_hired,
                SUM(CASE WHEN status = 'rejected' AND DATE(last_updated) BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ? THEN 1 ELSE 0 END) AS apps_rejected,
                SUM(CASE WHEN status = 'shortlisted' AND DATE(last_updated) BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ? THEN 1 ELSE 0 END) AS apps_shortlisted
            FROM job_applications
        ");
        $stmt->execute([
            $date, $periodDays - 1, $date,
            $date, $periodDays - 1, $date,
            $date, $periodDays - 1, $date,
            $date, $periodDays - 1, $date,
        ]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $periodReceived    = (int) ($period['apps_received'] ?? 0);
        $periodHired       = (int) ($period['apps_hired'] ?? 0);
        $periodRejected    = (int) ($period['apps_rejected'] ?? 0);
        $periodShortlisted = (int) ($period['apps_shortlisted'] ?? 0);
        $periodConversion  = $periodReceived > 0 ? round(($periodHired / $periodReceived) * 100, 2) : 0.0;

        // --- Pipeline funnel (counts by status of all applications)
        $funnelStmt = $pdo->query("SELECT status, COUNT(*) AS n FROM job_applications GROUP BY status");
        $funnelMap = [];
        foreach ($funnelStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $funnelMap[$r['status']] = (int) $r['n'];
        }
        $statusOrder = ['new', 'reviewing', 'shortlisted', 'interview_scheduled', 'interviewed', 'offer_extended', 'hired', 'rejected', 'withdrawn'];
        $funnel = [];
        $grandTotal = array_sum($funnelMap);
        foreach ($statusOrder as $s) {
            $cnt = $funnelMap[$s] ?? 0;
            $funnel[] = [
                'status' => $s,
                'label'  => recruitmentStatusLabel($s),
                'color'  => recruitmentStatusColor($s),
                'count'  => $cnt,
                'pct'    => $grandTotal > 0 ? round(($cnt / $grandTotal) * 100, 2) : 0,
            ];
        }

        // Pipeline active subset (everyone not closed)
        $pipelineActive = 0;
        foreach (['new', 'reviewing', 'shortlisted', 'interview_scheduled', 'interviewed', 'offer_extended'] as $s) {
            $pipelineActive += $funnelMap[$s] ?? 0;
        }

        // --- Top AI-scored shortlist candidates pending action (not hired/rejected/withdrawn)
        $aiStmt = $pdo->prepare("
            SELECT a.id, a.application_code, a.first_name, a.last_name, a.email, a.phone,
                   a.status, a.ai_score, a.ai_recommendation, a.ai_summary,
                   a.applied_date, a.last_updated,
                   jp.title AS job_title, jp.department
            FROM job_applications a
            LEFT JOIN job_postings jp ON jp.id = a.job_posting_id
            WHERE a.ai_score IS NOT NULL
              AND a.ai_score >= ?
              AND a.status NOT IN ('hired', 'rejected', 'withdrawn')
            ORDER BY a.ai_score DESC, a.applied_date DESC
            LIMIT 15
        ");
        $aiStmt->bindValue(1, $minAiScore, PDO::PARAM_INT);
        $aiStmt->execute();
        $topAiCandidates = $aiStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // --- Upcoming interviews (today + next N days)
        $upcomingStmt = $pdo->prepare("
            SELECT i.id, i.interview_type, i.interview_date, i.duration_minutes,
                   i.location, i.meeting_link, i.status,
                   a.id AS application_id, a.application_code, a.first_name, a.last_name, a.email,
                   a.ai_score, jp.title AS job_title, jp.department
            FROM recruitment_interviews i
            JOIN job_applications a ON a.id = i.application_id
            LEFT JOIN job_postings jp ON jp.id = a.job_posting_id
            WHERE i.status IN ('scheduled', 'rescheduled')
              AND DATE(i.interview_date) BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY)
            ORDER BY i.interview_date ASC
            LIMIT 30
        ");
        $upcomingStmt->execute([$date, $date, $upcomingDays]);
        $upcomingInterviews = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // --- Recent hires & rejections (in period)
        $recentHiredStmt = $pdo->prepare("
            SELECT a.id, a.application_code, a.first_name, a.last_name, a.email,
                   a.ai_score, a.last_updated, a.applied_date,
                   DATEDIFF(DATE(a.last_updated), DATE(a.applied_date)) AS days_to_hire,
                   jp.title AS job_title, jp.department
            FROM job_applications a
            LEFT JOIN job_postings jp ON jp.id = a.job_posting_id
            WHERE a.status = 'hired'
              AND DATE(a.last_updated) BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ?
            ORDER BY a.last_updated DESC
            LIMIT 15
        ");
        $recentHiredStmt->execute([$date, $periodDays - 1, $date]);
        $recentHires = $recentHiredStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $recentRejStmt = $pdo->prepare("
            SELECT a.id, a.application_code, a.first_name, a.last_name,
                   a.ai_score, a.last_updated, a.applied_date,
                   jp.title AS job_title
            FROM job_applications a
            LEFT JOIN job_postings jp ON jp.id = a.job_posting_id
            WHERE a.status = 'rejected'
              AND DATE(a.last_updated) BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ?
            ORDER BY a.last_updated DESC
            LIMIT 10
        ");
        $recentRejStmt->execute([$date, $periodDays - 1, $date]);
        $recentRejections = $recentRejStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // --- Avg time-to-hire (lifetime)
        $ttHireStmt = $pdo->query("
            SELECT AVG(DATEDIFF(DATE(last_updated), DATE(applied_date))) AS avg_days
            FROM job_applications
            WHERE status = 'hired' AND applied_date IS NOT NULL AND last_updated IS NOT NULL
        ");
        $avgDaysToHire = (float) ($ttHireStmt->fetchColumn() ?: 0);

        // --- Top sources (in period)
        $sourceStmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(source, ''), 'Sin especificar') AS source,
                   COUNT(*) AS apps,
                   SUM(CASE WHEN status = 'hired' THEN 1 ELSE 0 END) AS hires
            FROM job_applications
            WHERE DATE(applied_date) BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ?
            GROUP BY COALESCE(NULLIF(source, ''), 'Sin especificar')
            ORDER BY apps DESC
            LIMIT 10
        ");
        $sourceStmt->execute([$date, $periodDays - 1, $date]);
        $sources = $sourceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($sources as &$s) {
            $s['apps']  = (int) $s['apps'];
            $s['hires'] = (int) $s['hires'];
            $s['conv_pct'] = $s['apps'] > 0 ? round(($s['hires'] / $s['apps']) * 100, 2) : 0;
        }
        unset($s);

        // --- Bottlenecks: applications stuck > N days in an early stage
        $bottleneckStmt = $pdo->prepare("
            SELECT a.id, a.application_code, a.first_name, a.last_name, a.status,
                   a.ai_score, a.applied_date, a.last_updated,
                   DATEDIFF(?, DATE(a.last_updated)) AS days_in_stage,
                   jp.title AS job_title
            FROM job_applications a
            LEFT JOIN job_postings jp ON jp.id = a.job_posting_id
            WHERE a.status IN ('new', 'reviewing', 'shortlisted', 'interview_scheduled', 'interviewed', 'offer_extended')
              AND DATEDIFF(?, DATE(a.last_updated)) >= ?
            ORDER BY days_in_stage DESC, a.ai_score DESC
            LIMIT 20
        ");
        $bottleneckStmt->execute([$date, $date, $bottleneckDays]);
        $bottlenecks = $bottleneckStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // --- Department breakdown (active pipeline)
        $deptStmt = $pdo->query("
            SELECT COALESCE(NULLIF(jp.department, ''), 'Sin departamento') AS dept,
                   COUNT(a.id) AS apps_count,
                   SUM(CASE WHEN a.status IN ('new','reviewing','shortlisted','interview_scheduled','interviewed','offer_extended') THEN 1 ELSE 0 END) AS in_pipeline,
                   SUM(CASE WHEN a.status = 'hired'    THEN 1 ELSE 0 END) AS hired,
                   SUM(CASE WHEN a.status = 'rejected' THEN 1 ELSE 0 END) AS rejected
            FROM job_applications a
            LEFT JOIN job_postings jp ON jp.id = a.job_posting_id
            GROUP BY COALESCE(NULLIF(jp.department, ''), 'Sin departamento')
            ORDER BY in_pipeline DESC, apps_count DESC
            LIMIT 15
        ");
        $byDepartment = $deptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($byDepartment as &$d) {
            $d['apps_count']  = (int) $d['apps_count'];
            $d['in_pipeline'] = (int) $d['in_pipeline'];
            $d['hired']       = (int) $d['hired'];
            $d['rejected']    = (int) $d['rejected'];
        }
        unset($d);

        // --- Alerts count (sum of things that need attention)
        $alertsCount = 0;
        $alertsCount += count($bottlenecks);
        $alertsCount += count(array_filter($topAiCandidates, static fn($c) => ($c['status'] ?? '') === 'new'));

        $hasAnyActivity = $todayNewApps + $todayStatusChanges + $todayInterviews > 0
                       || $periodReceived + $periodHired + $periodRejected > 0;

        return [
            'date'           => $date,
            'date_formatted' => recruitmentReportSpanishDate($date),
            'config' => [
                'period_days'     => $periodDays,
                'upcoming_days'   => $upcomingDays,
                'bottleneck_days' => $bottleneckDays,
                'min_ai_score'    => $minAiScore,
            ],
            'totals' => [
                'active_postings'      => $totalActivePostings,
                'closed_postings'      => $closedPostings,
                'pipeline_active'      => $pipelineActive,
                'total_applications'   => $grandTotal,
                'today_new_apps'       => $todayNewApps,
                'today_status_changes' => $todayStatusChanges,
                'today_interviews'     => $todayInterviews,
                'today_ai_scored'      => $todayAiScored,
                'period_received'      => $periodReceived,
                'period_hired'         => $periodHired,
                'period_rejected'      => $periodRejected,
                'period_shortlisted'   => $periodShortlisted,
                'period_conversion_pct'=> $periodConversion,
                'upcoming_interviews'  => count($upcomingInterviews),
                'top_ai_candidates'    => count($topAiCandidates),
                'bottlenecks_count'    => count($bottlenecks),
                'avg_days_to_hire'     => round($avgDaysToHire, 1),
                'alerts_count'         => $alertsCount,
                'has_activity'         => $hasAnyActivity,
            ],
            'postings'           => $postings,
            'funnel'             => $funnel,
            'top_ai_candidates'  => $topAiCandidates,
            'upcoming_interviews'=> $upcomingInterviews,
            'recent_hires'       => $recentHires,
            'recent_rejections'  => $recentRejections,
            'sources'            => $sources,
            'bottlenecks'        => $bottlenecks,
            'by_department'      => $byDepartment,
            'generated_at'       => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('generateAIRecruitmentSummary')) {
    function generateAIRecruitmentSummary(PDO $pdo, array $reportData): string
    {
        $settings = getRecruitmentReportSettings($pdo);
        if (($settings['recruitment_report_claude_enabled'] ?? '0') !== '1') {
            return '';
        }

        $model = trim((string) ($settings['recruitment_report_claude_model'] ?? '')) ?: resolveAnthropicDefaultModel($pdo);
        $maxTokens = max(100, (int) ($settings['recruitment_report_claude_max_tokens'] ?? 900));
        $systemPrompt = (string) ($settings['recruitment_report_claude_prompt'] ?? '');

        $payload = [
            'fecha'   => $reportData['date'],
            'config'  => $reportData['config'],
            'totales' => $reportData['totals'],
            'funnel'  => array_map(static fn($f) => [
                'estado' => $f['label'],
                'total'  => $f['count'],
                'pct'    => $f['pct'],
            ], $reportData['funnel']),
            'postings_activos' => array_map(static fn($p) => [
                'puesto'        => $p['title'],
                'departamento'  => $p['department'],
                'aplicaciones_total' => (int) $p['apps_total'],
                'en_pipeline'   => (int) $p['apps_pipeline'],
                'contratados'   => (int) $p['apps_hired'],
            ], $reportData['postings']),
            'top_ai' => array_map(static fn($c) => [
                'nombre'       => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
                'puesto'       => $c['job_title'],
                'estado'       => recruitmentStatusLabel($c['status']),
                'ai_score'     => (int) $c['ai_score'],
                'ai_recomendacion' => $c['ai_recommendation'],
            ], array_slice($reportData['top_ai_candidates'], 0, 10)),
            'cuellos_de_botella' => array_map(static fn($b) => [
                'nombre'    => trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')),
                'puesto'    => $b['job_title'],
                'estado'    => recruitmentStatusLabel($b['status']),
                'dias_estancado' => (int) $b['days_in_stage'],
                'ai_score'  => $b['ai_score'] !== null ? (int) $b['ai_score'] : null,
            ], array_slice($reportData['bottlenecks'], 0, 15)),
            'entrevistas_proximas' => array_map(static fn($i) => [
                'candidato' => trim(($i['first_name'] ?? '') . ' ' . ($i['last_name'] ?? '')),
                'puesto'    => $i['job_title'],
                'tipo'      => $i['interview_type'],
                'fecha'     => $i['interview_date'],
            ], array_slice($reportData['upcoming_interviews'], 0, 10)),
            'fuentes' => $reportData['sources'],
            'contrataciones_periodo' => array_map(static fn($h) => [
                'nombre'      => trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')),
                'puesto'      => $h['job_title'],
                'dias_para_contratar' => (int) $h['days_to_hire'],
            ], array_slice($reportData['recent_hires'], 0, 10)),
        ];

        $userPrompt = "Aquí está el snapshot diario del módulo de reclutamiento en JSON. Genera el resumen ejecutivo según las instrucciones:\n\n"
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
            error_log('[recruitment_report] Claude API error: ' . ($result['error'] ?? 'unknown'));
            return '';
        }
        return (string) $result['content'];
    }
}

if (!function_exists('generateRecruitmentReportHTML')) {
    function generateRecruitmentReportHTML(array $reportData, string $aiSummary = ''): string
    {
        $date     = htmlspecialchars($reportData['date_formatted']);
        $totals   = $reportData['totals'];
        $cfg      = $reportData['config'];
        $postings = $reportData['postings'];
        $funnel   = $reportData['funnel'];
        $topAi    = $reportData['top_ai_candidates'];
        $upcoming = $reportData['upcoming_interviews'];
        $hires    = $reportData['recent_hires'];
        $rejs     = $reportData['recent_rejections'];
        $sources  = $reportData['sources'];
        $bottles  = $reportData['bottlenecks'];
        $byDept   = $reportData['by_department'];

        $aiBlock = '';
        if (trim($aiSummary) !== '') {
            $safe = nl2br(htmlspecialchars($aiSummary), false);
            $aiBlock = "<div class='ai-summary'>"
                . "<div class='ai-badge'>Resumen ejecutivo generado por IA</div>"
                . "<div class='ai-body'>{$safe}</div>"
                . "</div>";
        }

        // Funnel rows (horizontal bars)
        $maxFunnel = 0;
        foreach ($funnel as $f) $maxFunnel = max($maxFunnel, (int) $f['count']);
        $funnelRows = '';
        foreach ($funnel as $f) {
            if ((int) $f['count'] === 0) continue;
            $w = $maxFunnel > 0 ? max(2, (int) round(($f['count'] / $maxFunnel) * 100)) : 0;
            $label = htmlspecialchars($f['label']);
            $cnt   = (int) $f['count'];
            $pct   = $f['pct'];
            $color = $f['color'];
            $funnelRows .= "<tr>"
                . "<td><strong>{$label}</strong></td>"
                . "<td class='num'>{$cnt}</td>"
                . "<td class='num'>{$pct}%</td>"
                . "<td style='width:55%;'>"
                . "<div style='background:#e5e7eb;height:14px;border-radius:6px;overflow:hidden;'>"
                . "<div style='background:{$color};height:100%;width:{$w}%;'></div>"
                . "</div>"
                . "</td>"
                . "</tr>";
        }
        $funnelBlock = "<div class='section'><h2>📊 Embudo de aplicaciones (acumulado)</h2>"
            . "<table><thead><tr><th>Estado</th><th style='text-align:right;'>Total</th><th style='text-align:right;'>%</th><th>Distribución</th></tr></thead><tbody>{$funnelRows}</tbody></table></div>";

        // Active postings rows
        $postingsBlock = '';
        if (!empty($postings)) {
            $postRows = '';
            foreach ($postings as $p) {
                $title = htmlspecialchars($p['title']);
                $dept  = htmlspecialchars((string) ($p['department'] ?? '—'));
                $loc   = htmlspecialchars((string) ($p['location'] ?? '—'));
                $tot   = (int) $p['apps_total'];
                $pipe  = (int) $p['apps_pipeline'];
                $hired = (int) $p['apps_hired'];
                $todayN = (int) $p['apps_today'];
                $postedRel = '';
                if (!empty($p['posted_date'])) {
                    $diff = (int) floor((time() - strtotime($p['posted_date'])) / 86400);
                    $postedRel = "<br><span class='muted'>Publicado hace {$diff} días</span>";
                }
                $todayBadge = $todayN > 0 ? "<span class='badge badge-warn'>+{$todayN} hoy</span>" : '';
                $postRows .= "<tr>"
                    . "<td><strong>{$title}</strong>{$postedRel}</td>"
                    . "<td>{$dept}<br><span class='muted'>{$loc}</span></td>"
                    . "<td class='num'>{$tot}</td>"
                    . "<td class='num'><span class='badge badge-info'>{$pipe}</span></td>"
                    . "<td class='num'><span class='badge badge-success'>{$hired}</span></td>"
                    . "<td>{$todayBadge}</td>"
                    . "</tr>";
            }
            $postingsBlock = "<div class='section'><h2>💼 Posiciones activas ({$totals['active_postings']})</h2>"
                . "<table><thead><tr><th>Puesto</th><th>Departamento / Lugar</th><th style='text-align:right;'>Total apps</th><th style='text-align:right;'>En pipeline</th><th style='text-align:right;'>Contratados</th><th>Hoy</th></tr></thead><tbody>{$postRows}</tbody></table></div>";
        }

        // Top AI candidates
        $aiBlock2 = '';
        if (!empty($topAi)) {
            $aiRows = '';
            foreach ($topAi as $c) {
                $name = htmlspecialchars(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
                $job  = htmlspecialchars((string) ($c['job_title'] ?? '—'));
                $status = recruitmentStatusLabel((string) $c['status']);
                $statusColor = recruitmentStatusColor((string) $c['status']);
                $score = (int) $c['ai_score'];
                $rec   = htmlspecialchars((string) ($c['ai_recommendation'] ?? '—'));
                $email = htmlspecialchars((string) $c['email']);
                $scoreClass = $score >= 85 ? 'score-high' : ($score >= 70 ? 'score-mid' : 'score-low');
                $aiRows .= "<tr>"
                    . "<td><strong>{$name}</strong><br><span class='muted'>{$email}</span></td>"
                    . "<td>{$job}</td>"
                    . "<td><span class='badge' style='background:{$statusColor};'>{$status}</span></td>"
                    . "<td class='num'><span class='{$scoreClass}'>{$score}/100</span></td>"
                    . "<td>{$rec}</td>"
                    . "</tr>";
            }
            $aiBlock2 = "<div class='section'><h2>🤖 Top candidatos por IA (score ≥ {$cfg['min_ai_score']}) - pendientes de acción</h2>"
                . "<p class='muted-small'>Candidatos con alta puntuación de IA que aún no han sido contratados ni rechazados.</p>"
                . "<table><thead><tr><th>Candidato</th><th>Puesto</th><th>Estado</th><th style='text-align:right;'>Score</th><th>Recomendación IA</th></tr></thead><tbody>{$aiRows}</tbody></table></div>";
        }

        // Upcoming interviews
        $upcomingBlock = '';
        if (!empty($upcoming)) {
            $rows = '';
            foreach ($upcoming as $i) {
                $name = htmlspecialchars(trim(($i['first_name'] ?? '') . ' ' . ($i['last_name'] ?? '')));
                $job  = htmlspecialchars((string) ($i['job_title'] ?? '—'));
                $type = htmlspecialchars((string) $i['interview_type']);
                $when = date('d/m H:i', strtotime((string) $i['interview_date']));
                $loc  = htmlspecialchars((string) ($i['location'] ?? ($i['meeting_link'] ?? '—')));
                $dur  = (int) ($i['duration_minutes'] ?? 0);
                $durStr = $dur > 0 ? "{$dur} min" : '—';
                $score = $i['ai_score'] !== null ? "<span class='muted'>Score IA: " . (int) $i['ai_score'] . "</span>" : '';
                $rows .= "<tr>"
                    . "<td><strong>{$when}</strong><br><span class='muted'>{$durStr}</span></td>"
                    . "<td><strong>{$name}</strong><br>{$score}</td>"
                    . "<td>{$job}</td>"
                    . "<td><span class='badge badge-warn'>{$type}</span></td>"
                    . "<td>{$loc}</td>"
                    . "</tr>";
            }
            $upcomingBlock = "<div class='section'><h2>📅 Entrevistas próximas (hoy + {$cfg['upcoming_days']} días)</h2>"
                . "<table><thead><tr><th>Cuándo</th><th>Candidato</th><th>Puesto</th><th>Tipo</th><th>Ubicación</th></tr></thead><tbody>{$rows}</tbody></table></div>";
        }

        // Bottlenecks
        $bottleBlock = '';
        if (!empty($bottles)) {
            $rows = '';
            foreach ($bottles as $b) {
                $name = htmlspecialchars(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')));
                $job  = htmlspecialchars((string) ($b['job_title'] ?? '—'));
                $status = recruitmentStatusLabel((string) $b['status']);
                $statusColor = recruitmentStatusColor((string) $b['status']);
                $days = (int) $b['days_in_stage'];
                $score = $b['ai_score'] !== null ? (int) $b['ai_score'] . '/100' : '—';
                $daysBadge = $days >= 14
                    ? "<span class='badge badge-critical'>{$days} días</span>"
                    : ($days >= 7 ? "<span class='badge badge-danger'>{$days} días</span>" : "<span class='badge badge-warn'>{$days} días</span>");
                $rows .= "<tr>"
                    . "<td><strong>{$name}</strong></td>"
                    . "<td>{$job}</td>"
                    . "<td><span class='badge' style='background:{$statusColor};'>{$status}</span></td>"
                    . "<td>{$daysBadge}</td>"
                    . "<td class='num'>{$score}</td>"
                    . "</tr>";
            }
            $bottleBlock = "<div class='section'><h2>⏱️ Cuellos de botella (≥ {$cfg['bottleneck_days']} días sin movimiento)</h2>"
                . "<p class='muted-small'>Candidatos estancados en una etapa intermedia. Requieren seguimiento.</p>"
                . "<table><thead><tr><th>Candidato</th><th>Puesto</th><th>Estado</th><th>Días estancado</th><th style='text-align:right;'>Score IA</th></tr></thead><tbody>{$rows}</tbody></table></div>";
        }

        // Recent hires
        $hiresBlock = '';
        if (!empty($hires)) {
            $rows = '';
            foreach ($hires as $h) {
                $name = htmlspecialchars(trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')));
                $job  = htmlspecialchars((string) ($h['job_title'] ?? '—'));
                $when = date('d/m', strtotime((string) $h['last_updated']));
                $days = (int) $h['days_to_hire'];
                $score = $h['ai_score'] !== null ? (int) $h['ai_score'] . '/100' : '—';
                $rows .= "<tr>"
                    . "<td><strong>{$name}</strong></td>"
                    . "<td>{$job}</td>"
                    . "<td>{$when}</td>"
                    . "<td class='num'>{$days} días</td>"
                    . "<td class='num'>{$score}</td>"
                    . "</tr>";
            }
            $hiresBlock = "<div class='section'><h2>✅ Contratados en los últimos {$cfg['period_days']} días ({$totals['period_hired']})</h2>"
                . "<table><thead><tr><th>Candidato</th><th>Puesto</th><th>Fecha</th><th style='text-align:right;'>Días al contratar</th><th style='text-align:right;'>Score IA</th></tr></thead><tbody>{$rows}</tbody></table></div>";
        }

        // Sources
        $sourcesBlock = '';
        if (!empty($sources)) {
            $rows = '';
            foreach ($sources as $s) {
                $src = htmlspecialchars((string) $s['source']);
                $rows .= "<tr><td><strong>{$src}</strong></td><td class='num'>{$s['apps']}</td><td class='num'>{$s['hires']}</td><td class='num'>{$s['conv_pct']}%</td></tr>";
            }
            $sourcesBlock = "<div class='section'><h2>📥 Top fuentes (últimos {$cfg['period_days']} días)</h2>"
                . "<table><thead><tr><th>Fuente</th><th style='text-align:right;'>Aplicaciones</th><th style='text-align:right;'>Contratados</th><th style='text-align:right;'>Conversión</th></tr></thead><tbody>{$rows}</tbody></table></div>";
        }

        // Departments
        $deptBlock = '';
        if (!empty($byDept)) {
            $rows = '';
            foreach ($byDept as $d) {
                $name = htmlspecialchars((string) $d['dept']);
                $rows .= "<tr><td><strong>{$name}</strong></td><td class='num'>{$d['apps_count']}</td><td class='num'><span class='badge badge-info'>{$d['in_pipeline']}</span></td><td class='num'><span class='badge badge-success'>{$d['hired']}</span></td><td class='num'><span class='badge badge-muted'>{$d['rejected']}</span></td></tr>";
            }
            $deptBlock = "<div class='section'><h2>🏢 Aplicaciones por departamento (acumulado)</h2>"
                . "<table><thead><tr><th>Departamento</th><th style='text-align:right;'>Total</th><th style='text-align:right;'>En pipeline</th><th style='text-align:right;'>Contratados</th><th style='text-align:right;'>Rechazados</th></tr></thead><tbody>{$rows}</tbody></table></div>";
        }

        $emptyBlock = '';
        if (!$totals['has_activity']) {
            $emptyBlock = "<div class='success-card'><h3>📋 Sin actividad reciente</h3><p>No se registraron nuevas aplicaciones, cambios de estado o entrevistas hoy ni en el período configurado.</p></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
  .header { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); color: #fff; padding: 28px; text-align: center; border-radius: 10px; }
  .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
  .header p { margin: 8px 0 0 0; font-size: 15px; opacity: 0.95; }
  .stats-grid { display: table; width: 100%; margin: 18px 0; border-spacing: 10px; }
  .stat-card { display: table-cell; background: #fff; padding: 16px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .stat-card.primary  { border-top: 4px solid #8b5cf6; }
  .stat-card.success  { border-top: 4px solid #10b981; }
  .stat-card.warn     { border-top: 4px solid #f59e0b; }
  .stat-card.danger   { border-top: 4px solid #ef4444; }
  .stat-card.info     { border-top: 4px solid #6366f1; }
  .stat-card.muted    { border-top: 4px solid #64748b; }
  .stat-number { font-size: 26px; font-weight: 700; margin: 8px 0 0 0; }
  .stat-sub { font-size: 12px; color: #666; margin-top: 4px; }
  .stat-label { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
  .ai-summary { background: #fffbeb; border: 1px solid #fcd34d; border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 8px; margin: 18px 0; }
  .ai-badge { display: inline-block; background: #f59e0b; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 10px; }
  .ai-body { color: #333; font-size: 14px; white-space: pre-wrap; }
  .section { background: #fff; margin: 18px 0; padding: 22px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .section h2 { margin: 0 0 14px 0; font-size: 18px; border-bottom: 2px solid #8b5cf6; padding-bottom: 8px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
  th { color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  tbody tr:nth-child(even) { background-color: #fafafa; }
  td.num { font-family: 'Courier New', monospace; white-space: nowrap; text-align: right; }
  .muted { color: #888; font-size: 11px; }
  .muted-small { color: #666; font-size: 12px; margin-bottom: 8px; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; color: #fff; }
  .badge-info     { background: #6366f1; }
  .badge-warn     { background: #f59e0b; }
  .badge-success  { background: #10b981; }
  .badge-danger   { background: #ef4444; }
  .badge-critical { background: #7f1d1d; }
  .badge-muted    { background: #94a3b8; }
  .score-high { color: #047857; font-weight: 700; }
  .score-mid  { color: #b45309; font-weight: 700; }
  .score-low  { color: #6b7280; font-weight: 600; }
  .success-card { background: #ede9fe; border: 1px solid #c4b5fd; border-radius: 8px; padding: 24px; text-align: center; color: #5b21b6; }
  .footer { text-align: center; padding: 18px; color: #777; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>🎯 Reporte Diario de Reclutamiento</h1>
    <p>{$date} · Ventana de período: {$cfg['period_days']} días</p>
  </div>

  <div class="stats-grid">
    <div class="stat-card primary">
      <div class="stat-label">Posiciones activas</div>
      <div class="stat-number">{$totals['active_postings']}</div>
      <div class="stat-sub">{$totals['closed_postings']} cerradas</div>
    </div>
    <div class="stat-card info">
      <div class="stat-label">En pipeline</div>
      <div class="stat-number">{$totals['pipeline_active']}</div>
      <div class="stat-sub">de {$totals['total_applications']} totales</div>
    </div>
    <div class="stat-card success">
      <div class="stat-label">Contratados ({$cfg['period_days']}d)</div>
      <div class="stat-number">{$totals['period_hired']}</div>
      <div class="stat-sub">{$totals['period_conversion_pct']}% conversión</div>
    </div>
    <div class="stat-card warn">
      <div class="stat-label">Cuellos de botella</div>
      <div class="stat-number">{$totals['bottlenecks_count']}</div>
      <div class="stat-sub">requieren seguimiento</div>
    </div>
  </div>

  <div class="stats-grid">
    <div class="stat-card primary">
      <div class="stat-label">Nuevas hoy</div>
      <div class="stat-number">{$totals['today_new_apps']}</div>
    </div>
    <div class="stat-card info">
      <div class="stat-label">Cambios estado hoy</div>
      <div class="stat-number">{$totals['today_status_changes']}</div>
    </div>
    <div class="stat-card warn">
      <div class="stat-label">Entrevistas hoy</div>
      <div class="stat-number">{$totals['today_interviews']}</div>
    </div>
    <div class="stat-card muted">
      <div class="stat-label">Próximas entrevistas</div>
      <div class="stat-number">{$totals['upcoming_interviews']}</div>
      <div class="stat-sub">próximos {$cfg['upcoming_days']} días</div>
    </div>
  </div>

  <div class="stats-grid">
    <div class="stat-card info">
      <div class="stat-label">Apps recibidas ({$cfg['period_days']}d)</div>
      <div class="stat-number">{$totals['period_received']}</div>
    </div>
    <div class="stat-card primary">
      <div class="stat-label">Preseleccionados ({$cfg['period_days']}d)</div>
      <div class="stat-number">{$totals['period_shortlisted']}</div>
    </div>
    <div class="stat-card muted">
      <div class="stat-label">Rechazados ({$cfg['period_days']}d)</div>
      <div class="stat-number">{$totals['period_rejected']}</div>
    </div>
    <div class="stat-card success">
      <div class="stat-label">Tiempo prom. al contratar</div>
      <div class="stat-number">{$totals['avg_days_to_hire']}</div>
      <div class="stat-sub">días (histórico)</div>
    </div>
  </div>

  {$aiBlock}
  {$emptyBlock}
  {$postingsBlock}
  {$funnelBlock}
  {$aiBlock2}
  {$upcomingBlock}
  {$bottleBlock}
  {$hiresBlock}
  {$sourcesBlock}
  {$deptBlock}

  <div class='footer'>
    <p><strong>Reporte generado automáticamente</strong></p>
    <p>{$reportData['generated_at']} — Módulo de Reclutamiento</p>
  </div>
</div>
</body>
</html>
HTML;
    }
}

if (!function_exists('sendRecruitmentReportByEmail')) {
    function sendRecruitmentReportByEmail(PDO $pdo, array $reportData, array $recipients, string $aiSummary = ''): bool
    {
        if (empty($recipients)) {
            error_log('[recruitment_report] No recipients configured');
            return false;
        }

        $html = generateRecruitmentReportHTML($reportData, $aiSummary);
        require_once __DIR__ . '/email_functions.php';

        $result = sendDailyRecruitmentReport($html, $recipients, $reportData);

        if ($result['success']) {
            error_log('[recruitment_report] Sent: ' . $result['message']);
            return true;
        }
        error_log('[recruitment_report] Failed: ' . $result['message']);
        return false;
    }
}
