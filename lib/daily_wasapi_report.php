<?php
/**
 * Daily Wasapi (WhatsApp) Executive Report
 *
 * Produce un reporte ejecutivo diario completo de la operación del canal
 * WhatsApp en Wasapi: KPIs globales, agentes en línea, conversaciones por
 * estado, top/bottom performers, SLA (P50/P90/P95), tendencia diaria,
 * distribución por día de la semana y campañas activas. Opcionalmente añade
 * un análisis ejecutivo generado por Claude AI.
 *
 * Datos: API externa de Wasapi (https://api.wasapi.io/prod/api/v1/).
 * Configuración: tabla system_settings (claves wasapi_report_* + wasapi_api_token).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/claude_api_client.php';

// -------------------------------------------------------------
// Settings & recipients
// -------------------------------------------------------------

if (!function_exists('getWasapiReportSettings')) {
    function getWasapiReportSettings(PDO $pdo): array
    {
        $defaults = [
            'wasapi_report_enabled'                  => '0',
            'wasapi_report_time'                     => '08:30',
            'wasapi_report_recipients'               => '',
            'wasapi_report_days_back'                => '1',
            'wasapi_report_top_agents_limit'         => '10',
            'wasapi_report_pending_alert_threshold'  => '15',
            'wasapi_report_exclude_weekends'         => '0',
            'wasapi_report_claude_enabled'           => '0',
            'wasapi_report_claude_model'             => 'claude-sonnet-4-6',
            'wasapi_report_claude_max_tokens'        => '1200',
            'wasapi_report_claude_prompt'            => '',
            'wasapi_api_token'                       => '',
            'wasapi_base_url'                        => 'https://api.wasapi.io/prod/api/v1/',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'wasapi_report_%' OR setting_key IN ('wasapi_api_token','wasapi_base_url')");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getWasapiReportSettings: ' . $e->getMessage());
        }
        return $defaults;
    }
}

if (!function_exists('getWasapiReportRecipients')) {
    function getWasapiReportRecipients(PDO $pdo): array
    {
        $raw = (string) (getWasapiReportSettings($pdo)['wasapi_report_recipients'] ?? '');
        if ($raw === '') return [];
        $emails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($emails, static fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('wasapiSpanishDate')) {
    function wasapiSpanishDate(string $date): string
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

// -------------------------------------------------------------
// Wasapi data fetch helpers (self-contained — no session required)
// -------------------------------------------------------------

if (!function_exists('wasapiReportFmtSeconds')) {
    function wasapiReportFmtSeconds($sec): string
    {
        $sec = max(0, (int) $sec);
        if ($sec < 60) return $sec . 's';
        if ($sec < 3600) return floor($sec / 60) . 'm ' . ($sec % 60) . 's';
        $h = floor($sec / 3600);
        $m = floor(($sec % 3600) / 60);
        return $h . 'h ' . $m . 'm';
    }
}

if (!function_exists('wasapiReportPercentile')) {
    function wasapiReportPercentile(array $arr, float $p): float
    {
        if (empty($arr)) return 0.0;
        sort($arr);
        $idx = ($p / 100) * (count($arr) - 1);
        $floor = (int) floor($idx);
        $frac = $idx - $floor;
        if ($floor + 1 < count($arr)) {
            return $arr[$floor] + $frac * ($arr[$floor + 1] - $arr[$floor]);
        }
        return $arr[$floor];
    }
}

if (!function_exists('wasapiReportMultiRequest')) {
    function wasapiReportMultiRequest(string $baseUrl, string $token, array $endpoints): array
    {
        $baseUrl = rtrim($baseUrl, '/') . '/';
        $mh = curl_multi_init();
        $handles = [];
        foreach ($endpoints as $key => $endpoint) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $baseUrl . $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $results[$key] = json_decode((string) $body, true) ?? [];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }
}

// -------------------------------------------------------------
// Build the report data structure
// -------------------------------------------------------------

if (!function_exists('generateDailyWasapiReport')) {
    /**
     * Build the Wasapi report for the target date (default: yesterday).
     * Returns a normalized array with KPIs, agents, SLA, trend, campaigns.
     */
    function generateDailyWasapiReport(PDO $pdo, ?string $date = null): array
    {
        $settings = getWasapiReportSettings($pdo);
        $date = $date ?: date('Y-m-d', strtotime('yesterday'));

        $token   = trim((string) ($settings['wasapi_api_token'] ?? ''));
        $baseUrl = trim((string) ($settings['wasapi_base_url']  ?? '')) ?: 'https://api.wasapi.io/prod/api/v1/';
        $topLimit = max(3, (int) ($settings['wasapi_report_top_agents_limit'] ?? 10));
        $pendingAlert = max(1, (int) ($settings['wasapi_report_pending_alert_threshold'] ?? 15));

        // For trend context we look at the last 14 days ending on the target date
        $startTrend = date('Y-m-d', strtotime($date . ' -13 days'));
        $endTrend   = $date;

        if ($token === '') {
            return [
                'available'      => false,
                'error'          => 'Falta el token de API de Wasapi (system_settings.wasapi_api_token).',
                'date'           => $date,
                'date_formatted' => wasapiSpanishDate($date),
                'totals'         => ['total_conversations' => 0],
                'top_agents'     => [],
                'bottom_agents'  => [],
                'campaigns'      => [],
                'trend'          => [],
                'generated_at'   => date('Y-m-d H:i:s'),
            ];
        }

        // Single-day request for "today's" KPIs + 14d trend in one batch
        $datesParam = 'dates%5B0%5D=' . $date . '&dates%5B1%5D=' . $date;
        $endpoints = [
            'online_agents'       => 'dashboard/metrics/online-agents',
            'conversations'       => 'dashboard/metrics/consolidated-conversations?' . $datesParam,
            'agent_conversations' => 'dashboard/metrics/agent-conversations?' . $datesParam,
            'performance_day'     => 'reports/performance-by-agent?start_date=' . $date . '&end_date=' . $date,
            'workflow_trend'      => 'reports/volume-of-workflow?start_date=' . $startTrend . '&end_date=' . $endTrend,
            'performance_trend'   => 'reports/performance-by-agent?start_date=' . $startTrend . '&end_date=' . $endTrend,
            'campaigns'           => 'campaigns',
        ];

        $r = wasapiReportMultiRequest($baseUrl, $token, $endpoints);

        // --- Agentes online (snapshot actual) ---
        $totalAgents = 0; $onlineAgents = 0; $onlineList = [];
        foreach ($r['online_agents']['users'] ?? [] as $u) {
            $totalAgents++;
            if (!empty($u['online'])) {
                $onlineAgents++;
                $onlineList[] = [
                    'id'    => (int) ($u['id'] ?? 0),
                    'name'  => (string) ($u['name'] ?? '—'),
                    'email' => (string) ($u['email'] ?? ''),
                ];
            }
        }

        // --- Conversaciones por estado ---
        $byStatus = ['open' => 0, 'closed' => 0, 'pending' => 0, 'hold' => 0];
        foreach ($r['conversations']['conversations']['data'] ?? [] as $c) {
            $s = (string) ($c['status'] ?? '');
            $n = (int) ($c['cant'] ?? 0);
            if (isset($byStatus[$s])) $byStatus[$s] += $n;
        }
        $totalConv = array_sum($byStatus);

        // --- Performance por agente (día objetivo) ---
        $agentMap = [];
        $resolutionTimes = [];
        $firstRespTimes = [];
        foreach ($r['performance_day']['data'] ?? [] as $p) {
            $id = (int) ($p['agent_id'] ?? 0);
            if ($id <= 0) continue;
            if (!isset($agentMap[$id])) {
                $agentMap[$id] = [
                    'id' => $id,
                    'name' => $p['agent']['name'] ?? ('Agente #' . $id),
                    'email' => $p['agent']['email'] ?? '',
                    'opened' => 0, 'closed' => 0,
                    'res_time' => 0, 'res_count' => 0,
                    'first_resp_time' => 0, 'first_resp_count' => 0,
                    'escalations' => 0,
                ];
            }
            $agentMap[$id]['opened'] += (int) ($p['total_open_conversations'] ?? 0);
            $agentMap[$id]['closed'] += (int) ($p['total_close_conversations'] ?? 0);
            $rt = (float) ($p['total_resolution_time'] ?? 0);
            $cc = (int)   ($p['total_close_conversations'] ?? 0);
            if ($rt > 0 && $cc > 0) {
                $agentMap[$id]['res_time']  += $rt;
                $agentMap[$id]['res_count'] += $cc;
                $resolutionTimes[] = $rt / $cc;
            }
            $ft = (float) ($p['total_first_response_time'] ?? 0);
            $fc = (int)   ($p['total_first_response_count'] ?? 0);
            if ($ft > 0 && $fc > 0) {
                $agentMap[$id]['first_resp_time']  += $ft;
                $agentMap[$id]['first_resp_count'] += $fc;
                $firstRespTimes[] = $ft / $fc;
            }
            $agentMap[$id]['escalations'] += (int) ($p['total_scaled_to_agents'] ?? 0);
        }

        $agents = [];
        foreach ($agentMap as $a) {
            $total = $a['opened'] + $a['closed'];
            $avgRes   = $a['res_count'] > 0 ? $a['res_time'] / $a['res_count'] : 0;
            $avgFirst = $a['first_resp_count'] > 0 ? $a['first_resp_time'] / $a['first_resp_count'] : 0;
            $resRate  = $total > 0 ? round(($a['closed'] / $total) * 100, 1) : 0;
            $agents[] = [
                'id'                          => $a['id'],
                'name'                        => $a['name'],
                'email'                       => $a['email'],
                'total_conversations'         => $total,
                'opened'                      => $a['opened'],
                'closed'                      => $a['closed'],
                'resolution_rate'             => $resRate,
                'avg_resolution_seconds'      => (int) round($avgRes),
                'avg_resolution_formatted'    => wasapiReportFmtSeconds($avgRes),
                'avg_first_response_seconds'  => (int) round($avgFirst),
                'avg_first_response_formatted'=> wasapiReportFmtSeconds($avgFirst),
                'escalations'                 => $a['escalations'],
            ];
        }
        usort($agents, fn($a, $b) => $b['closed'] <=> $a['closed']);
        $topAgents = array_slice($agents, 0, $topLimit);
        $bottomAgents = array_slice(array_filter(array_reverse($agents), static fn($a) => $a['total_conversations'] > 0), 0, max(3, (int) round($topLimit / 2)));

        // --- KPIs globales del día ---
        $totalClosed = array_sum(array_column($agents, 'closed'));
        $totalAll    = array_sum(array_column($agents, 'total_conversations'));
        $teamResolutionRate = $totalAll > 0 ? round(($totalClosed / $totalAll) * 100, 1) : 0.0;
        $avgRes   = count($resolutionTimes) ? array_sum($resolutionTimes) / count($resolutionTimes) : 0;
        $avgFirst = count($firstRespTimes)  ? array_sum($firstRespTimes)  / count($firstRespTimes)  : 0;

        // --- Tendencia diaria 14d ---
        $byDate = [];
        foreach ($r['workflow_trend']['data'] ?? [] as $w) {
            $d = substr((string) ($w['date'] ?? ''), 0, 10);
            if (!$d) continue;
            if (!isset($byDate[$d])) {
                $byDate[$d] = ['date' => $d, 'opened' => 0, 'closed' => 0,
                               'first_responses' => 0, 'escalations' => 0, 'agents' => []];
            }
            $byDate[$d]['opened']          += (int) ($w['total_open_conversations']  ?? 0);
            $byDate[$d]['closed']          += (int) ($w['total_close_conversations'] ?? 0);
            $byDate[$d]['first_responses'] += (int) ($w['total_first_response_count']?? 0);
            $byDate[$d]['escalations']     += (int) ($w['total_scaled_to_agents']    ?? 0);
            $aid = (int) ($w['agent_id'] ?? 0);
            if ($aid > 0) $byDate[$d]['agents'][$aid] = true;
        }
        ksort($byDate);
        $daily = [];
        foreach ($byDate as $d) {
            $daily[] = [
                'date'           => $d['date'],
                'weekday'        => date('D', strtotime($d['date'])),
                'opened'         => $d['opened'],
                'closed'         => $d['closed'],
                'first_responses'=> $d['first_responses'],
                'escalations'    => $d['escalations'],
                'active_agents'  => count($d['agents']),
                'is_target'      => $d['date'] === $date,
            ];
        }

        // Compute previous-day deltas vs target
        $prevDay = null;
        for ($i = count($daily) - 1; $i >= 0; $i--) {
            if ($daily[$i]['is_target']) {
                if ($i > 0) $prevDay = $daily[$i - 1];
                break;
            }
        }
        $targetDayRow = null;
        foreach ($daily as $d) if ($d['is_target']) { $targetDayRow = $d; break; }

        $deltas = ['closed_pct' => null, 'opened_pct' => null];
        if ($targetDayRow && $prevDay) {
            if ((int) $prevDay['closed'] > 0) {
                $deltas['closed_pct'] = round((($targetDayRow['closed'] - $prevDay['closed']) / max(1, $prevDay['closed'])) * 100, 1);
            }
            if ((int) $prevDay['opened'] > 0) {
                $deltas['opened_pct'] = round((($targetDayRow['opened'] - $prevDay['opened']) / max(1, $prevDay['opened'])) * 100, 1);
            }
        }

        // --- Distribución por día de la semana (últimos 14d) ---
        $weekdayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        $weekdayTotals = [];
        for ($i = 0; $i < 7; $i++) {
            $weekdayTotals[$i] = ['day' => $weekdayNames[$i], 'opened' => 0, 'closed' => 0, 'occurrences' => 0];
        }
        foreach ($daily as $d) {
            $w = (int) date('w', strtotime($d['date']));
            $weekdayTotals[$w]['opened'] += $d['opened'];
            $weekdayTotals[$w]['closed'] += $d['closed'];
            $weekdayTotals[$w]['occurrences']++;
        }
        $weekdayAvg = [];
        foreach ($weekdayTotals as $w) {
            $occ = max(1, $w['occurrences']);
            $weekdayAvg[] = [
                'day'         => $w['day'],
                'avg_opened'  => round($w['opened'] / $occ, 1),
                'avg_closed'  => round($w['closed'] / $occ, 1),
                'occurrences' => $w['occurrences'],
            ];
        }

        // --- SLA percentiles del día ---
        $slaPercentiles = [
            'resolution_p50'      => (int) round(wasapiReportPercentile($resolutionTimes, 50)),
            'resolution_p90'      => (int) round(wasapiReportPercentile($resolutionTimes, 90)),
            'resolution_p95'      => (int) round(wasapiReportPercentile($resolutionTimes, 95)),
            'first_response_p50'  => (int) round(wasapiReportPercentile($firstRespTimes,  50)),
            'first_response_p90'  => (int) round(wasapiReportPercentile($firstRespTimes,  90)),
            'first_response_p95'  => (int) round(wasapiReportPercentile($firstRespTimes,  95)),
        ];

        // --- Campañas activas ---
        $campaigns = [];
        foreach ($r['campaigns']['data'] ?? [] as $c) {
            $campaigns[] = [
                'id'   => (int) ($c['id'] ?? 0),
                'name' => (string) ($c['name'] ?? ('Campaña #' . ($c['id'] ?? '?'))),
            ];
        }

        // --- Alertas detectadas ---
        $alerts = [];
        $pendingTotal = (int) ($byStatus['pending'] + $byStatus['hold']);
        if ($pendingTotal >= $pendingAlert) {
            $alerts[] = [
                'level' => 'warning',
                'text'  => "Hay {$pendingTotal} conversaciones pendientes/hold al cierre (umbral configurado: {$pendingAlert}). Revisar cobertura.",
            ];
        }
        if ($totalAgents > 0 && $onlineAgents === 0) {
            $alerts[] = ['level' => 'critical', 'text' => 'Cero agentes en línea ahora mismo.'];
        }
        if ($totalConv > 0 && $teamResolutionRate < 50) {
            $alerts[] = [
                'level' => 'warning',
                'text'  => "Tasa de resolución del equipo {$teamResolutionRate}% (por debajo del 50%).",
            ];
        }
        if (count($resolutionTimes) > 0 && $slaPercentiles['first_response_p90'] > 1800) {
            $alerts[] = [
                'level' => 'warning',
                'text'  => 'Primera respuesta P90 supera 30 minutos (' . wasapiReportFmtSeconds($slaPercentiles['first_response_p90']) . ').',
            ];
        }

        $totals = [
            'total_conversations'           => $totalConv,
            'conversations_by_status'       => $byStatus,
            'team_resolution_rate'          => $teamResolutionRate,
            'avg_resolution_seconds'        => (int) round($avgRes),
            'avg_resolution_formatted'      => wasapiReportFmtSeconds($avgRes),
            'avg_first_response_seconds'    => (int) round($avgFirst),
            'avg_first_response_formatted'  => wasapiReportFmtSeconds($avgFirst),
            'total_escalations'             => array_sum(array_column($agents, 'escalations')),
            'agents_total'                  => $totalAgents,
            'agents_online'                 => $onlineAgents,
            'agents_offline'                => $totalAgents - $onlineAgents,
            'agents_availability_rate'      => $totalAgents > 0 ? round(($onlineAgents / $totalAgents) * 100, 1) : 0.0,
            'agents_with_activity'          => count($agents),
            'campaigns_count'               => count($campaigns),
        ];

        return [
            'available'         => true,
            'date'              => $date,
            'date_formatted'    => wasapiSpanishDate($date),
            'period'            => ['start_date' => $startTrend, 'end_date' => $endTrend, 'days' => 14],
            'totals'            => $totals,
            'sla_percentiles'   => $slaPercentiles,
            'agents_online_now' => $onlineList,
            'all_agents'        => $agents,
            'top_agents'        => $topAgents,
            'bottom_agents'     => $bottomAgents,
            'trend_daily'       => $daily,
            'trend_target_day'  => $targetDayRow,
            'trend_prev_day'    => $prevDay,
            'trend_deltas'      => $deltas,
            'weekday_avg'       => $weekdayAvg,
            'campaigns'         => $campaigns,
            'alerts'            => $alerts,
            'pending_threshold' => $pendingAlert,
            'top_agents_limit'  => $topLimit,
            'generated_at'      => date('Y-m-d H:i:s'),
        ];
    }
}

// -------------------------------------------------------------
// Claude AI summary
// -------------------------------------------------------------

if (!function_exists('generateAIWasapiSummary')) {
    function generateAIWasapiSummary(PDO $pdo, array $reportData): string
    {
        $settings = getWasapiReportSettings($pdo);
        if (($settings['wasapi_report_claude_enabled'] ?? '0') !== '1') {
            return '';
        }
        if (empty($reportData['available']) || (int) ($reportData['totals']['total_conversations'] ?? 0) === 0) {
            return '';
        }

        $model     = trim((string) ($settings['wasapi_report_claude_model'] ?? '')) ?: resolveAnthropicDefaultModel($pdo);
        $maxTokens = max(200, (int) ($settings['wasapi_report_claude_max_tokens'] ?? 1200));
        $systemPrompt = (string) ($settings['wasapi_report_claude_prompt'] ?? '');

        $payload = [
            'fecha'              => $reportData['date'],
            'totales'            => $reportData['totals'],
            'sla'                => $reportData['sla_percentiles'],
            'agentes_top'        => $reportData['top_agents'],
            'agentes_bottom'     => $reportData['bottom_agents'],
            'tendencia_14d'      => array_slice($reportData['trend_daily'], -14),
            'dia_objetivo'       => $reportData['trend_target_day'],
            'dia_anterior'       => $reportData['trend_prev_day'],
            'deltas'             => $reportData['trend_deltas'],
            'distribucion_dow'   => $reportData['weekday_avg'],
            'campañas'           => array_slice($reportData['campaigns'], 0, 30),
            'alertas_detectadas' => $reportData['alerts'],
            'umbral_pendientes'  => $reportData['pending_threshold'],
        ];

        $userPrompt = "Aquí está el JSON con la operación de WhatsApp (Wasapi) del día anterior y la tendencia de los últimos 14 días. Genera el análisis ejecutivo según las instrucciones:\n\n"
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
            error_log('[wasapi_report] Claude API error: ' . ($result['error'] ?? 'unknown'));
            return '';
        }
        return (string) $result['content'];
    }
}

// -------------------------------------------------------------
// HTML rendering
// -------------------------------------------------------------

if (!function_exists('generateWasapiReportHTML')) {
    function generateWasapiReportHTML(array $reportData, string $aiSummary = ''): string
    {
        $date    = htmlspecialchars($reportData['date_formatted'] ?? '');
        $totals  = $reportData['totals'] ?? [];
        $sla     = $reportData['sla_percentiles'] ?? [];
        $top     = $reportData['top_agents'] ?? [];
        $bottom  = $reportData['bottom_agents'] ?? [];
        $online  = $reportData['agents_online_now'] ?? [];
        $daily   = $reportData['trend_daily'] ?? [];
        $weekly  = $reportData['weekday_avg'] ?? [];
        $camps   = $reportData['campaigns'] ?? [];
        $alerts  = $reportData['alerts'] ?? [];
        $deltas  = $reportData['trend_deltas'] ?? [];
        $byStatus = $totals['conversations_by_status'] ?? ['open' => 0, 'closed' => 0, 'pending' => 0, 'hold' => 0];

        if (empty($reportData['available'])) {
            $err = htmlspecialchars($reportData['error'] ?? 'Datos no disponibles.');
            return "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;padding:20px;'>"
                . "<h2>📱 Reporte Wasapi — {$date}</h2>"
                . "<p style='background:#fee2e2;padding:14px;border-left:4px solid #ef4444;border-radius:6px;'>"
                . "<strong>No fue posible generar el reporte:</strong> {$err}</p></body></html>";
        }

        $aiBlock = '';
        if (trim($aiSummary) !== '') {
            $safe = nl2br(htmlspecialchars($aiSummary), false);
            $aiBlock = "<div style='background:#fffbeb;border:1px solid #fcd34d;border-left:4px solid #f59e0b;padding:16px 20px;border-radius:8px;margin:18px 0;'>"
                . "<div style='display:inline-block;background:#f59e0b;color:#fff;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase;margin-bottom:10px;'>Análisis ejecutivo generado por Claude AI</div>"
                . "<div style='color:#333;font-size:14px;white-space:pre-wrap;'>{$safe}</div>"
                . "</div>";
        }

        // Alerts block
        $alertsBlock = '';
        if (!empty($alerts)) {
            $items = '';
            foreach ($alerts as $a) {
                $level = $a['level'] ?? 'info';
                $color = $level === 'critical' ? '#991b1b' : ($level === 'warning' ? '#92400e' : '#1e40af');
                $bg    = $level === 'critical' ? '#fee2e2' : ($level === 'warning' ? '#fef3c7' : '#dbeafe');
                $items .= "<li style='padding:8px 12px;margin:4px 0;background:{$bg};color:{$color};border-radius:4px;font-size:13px;'>"
                    . htmlspecialchars((string) ($a['text'] ?? '')) . "</li>";
            }
            $alertsBlock = "<div style='background:#fff;margin:18px 0;padding:18px 22px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);'>"
                . "<h2 style='margin:0 0 12px 0;font-size:17px;border-bottom:2px solid #f59e0b;padding-bottom:6px;'>🚨 Alertas detectadas</h2>"
                . "<ul style='margin:0;padding:0;list-style:none;'>{$items}</ul></div>";
        }

        // Stat cards
        $statCard = 'background:#ffffff;padding:18px;border-radius:8px;text-align:center;box-shadow:0 2px 4px rgba(0,0,0,0.08);';
        $statLabel = 'color:#666;font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px 0;';
        $statNum   = 'font-size:24px;font-weight:700;margin:0;color:#111;';
        $statSub   = 'font-size:12px;color:#666;margin:4px 0 0 0;';

        $deltaClosed = $deltas['closed_pct'] !== null
            ? ($deltas['closed_pct'] > 0
                ? "<span style='color:#16a34a;'>+{$deltas['closed_pct']}%</span>"
                : "<span style='color:#dc2626;'>{$deltas['closed_pct']}%</span>")
            : '<span class="muted">vs. ayer: —</span>';

        $row1 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCard}border-top:4px solid #25d366;'><p style='{$statLabel}'>Conversaciones (día)</p><p style='{$statNum}'>" . (int) ($totals['total_conversations'] ?? 0) . "</p><p style='{$statSub}'>Cerradas: " . (int) $byStatus['closed'] . " · {$deltaClosed} vs. ayer</p></td>"
            . "<td style='{$statCard}border-top:4px solid #0ea5e9;'><p style='{$statLabel}'>Tasa resolución equipo</p><p style='{$statNum}'>" . number_format((float) ($totals['team_resolution_rate'] ?? 0), 1) . "%</p><p style='{$statSub}'>Escaladas: " . (int) ($totals['total_escalations'] ?? 0) . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #6366f1;'><p style='{$statLabel}'>1ª respuesta promedio</p><p style='{$statNum};font-size:20px;'>" . htmlspecialchars($totals['avg_first_response_formatted'] ?? '—') . "</p><p style='{$statSub}'>P90: " . htmlspecialchars(wasapiReportFmtSeconds($sla['first_response_p90'] ?? 0)) . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #6366f1;'><p style='{$statLabel}'>Resolución promedio</p><p style='{$statNum};font-size:20px;'>" . htmlspecialchars($totals['avg_resolution_formatted'] ?? '—') . "</p><p style='{$statSub}'>P90: " . htmlspecialchars(wasapiReportFmtSeconds($sla['resolution_p90'] ?? 0)) . "</p></td>"
            . "</tr></table>";

        $row2 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCard}border-top:4px solid #16a34a;'><p style='{$statLabel}'>Abiertas</p><p style='{$statNum}'>" . (int) $byStatus['open'] . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #06b6d4;'><p style='{$statLabel}'>Cerradas</p><p style='{$statNum}'>" . (int) $byStatus['closed'] . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #f59e0b;'><p style='{$statLabel}'>Pendientes</p><p style='{$statNum}'>" . (int) $byStatus['pending'] . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #f97316;'><p style='{$statLabel}'>En espera (hold)</p><p style='{$statNum}'>" . (int) $byStatus['hold'] . "</p></td>"
            . "</tr></table>";

        $row3 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCard}border-top:4px solid #25d366;'><p style='{$statLabel}'>Agentes en línea ahora</p><p style='{$statNum}'>" . (int) ($totals['agents_online'] ?? 0) . " / " . (int) ($totals['agents_total'] ?? 0) . "</p><p style='{$statSub}'>Disponibilidad " . number_format((float) ($totals['agents_availability_rate'] ?? 0), 1) . "%</p></td>"
            . "<td style='{$statCard}border-top:4px solid #8b5cf6;'><p style='{$statLabel}'>Agentes con actividad (día)</p><p style='{$statNum}'>" . (int) ($totals['agents_with_activity'] ?? 0) . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #64748b;'><p style='{$statLabel}'>Campañas configuradas</p><p style='{$statNum}'>" . (int) ($totals['campaigns_count'] ?? 0) . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #f43f5e;'><p style='{$statLabel}'>Pendientes + hold</p><p style='{$statNum}'>" . ((int) $byStatus['pending'] + (int) $byStatus['hold']) . "</p><p style='{$statSub}'>umbral alerta: " . (int) ($reportData['pending_threshold'] ?? 0) . "</p></td>"
            . "</tr></table>";

        // SLA table
        $slaRows = '';
        $slaRows .= "<tr><td><strong>1ª respuesta</strong></td><td class='num'>" . htmlspecialchars(wasapiReportFmtSeconds($sla['first_response_p50'] ?? 0)) . "</td><td class='num'>" . htmlspecialchars(wasapiReportFmtSeconds($sla['first_response_p90'] ?? 0)) . "</td><td class='num'>" . htmlspecialchars(wasapiReportFmtSeconds($sla['first_response_p95'] ?? 0)) . "</td></tr>";
        $slaRows .= "<tr><td><strong>Resolución</strong></td><td class='num'>" . htmlspecialchars(wasapiReportFmtSeconds($sla['resolution_p50'] ?? 0)) . "</td><td class='num'>" . htmlspecialchars(wasapiReportFmtSeconds($sla['resolution_p90'] ?? 0)) . "</td><td class='num'>" . htmlspecialchars(wasapiReportFmtSeconds($sla['resolution_p95'] ?? 0)) . "</td></tr>";

        // Top agents table
        $topRows = '';
        foreach ($top as $a) {
            $topRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $a['name']) . "</strong><br><span class='muted'>" . htmlspecialchars((string) ($a['email'] ?? '')) . "</span></td>"
                . "<td class='num'>" . (int) $a['total_conversations'] . "</td>"
                . "<td class='num'>" . (int) $a['closed'] . "</td>"
                . "<td class='num'>" . number_format((float) $a['resolution_rate'], 1) . "%</td>"
                . "<td class='num'>" . htmlspecialchars((string) $a['avg_first_response_formatted']) . "</td>"
                . "<td class='num'>" . htmlspecialchars((string) $a['avg_resolution_formatted']) . "</td>"
                . "<td class='num'>" . (int) $a['escalations'] . "</td>"
                . "</tr>";
        }

        // Bottom agents
        $bottomRows = '';
        foreach ($bottom as $a) {
            $bottomRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $a['name']) . "</strong></td>"
                . "<td class='num'>" . (int) $a['total_conversations'] . "</td>"
                . "<td class='num'>" . (int) $a['closed'] . "</td>"
                . "<td class='num'>" . number_format((float) $a['resolution_rate'], 1) . "%</td>"
                . "<td class='num'>" . htmlspecialchars((string) $a['avg_first_response_formatted']) . "</td>"
                . "<td class='num'>" . htmlspecialchars((string) $a['avg_resolution_formatted']) . "</td>"
                . "</tr>";
        }

        // Daily trend (last 14)
        $maxClosed = 1;
        foreach ($daily as $d) $maxClosed = max($maxClosed, (int) $d['closed']);
        $trendRows = '';
        foreach ($daily as $d) {
            $width = ((int) $d['closed'] / $maxClosed) * 100;
            $highlight = !empty($d['is_target']) ? "background:#fef3c7;font-weight:600;" : "";
            $trendRows .= "<tr style='{$highlight}'>"
                . "<td>" . htmlspecialchars($d['date']) . " <span class='muted'>(" . htmlspecialchars($d['weekday']) . ")</span></td>"
                . "<td class='num'>" . (int) $d['opened'] . "</td>"
                . "<td><div class='bar' style='width:{$width}%;'>&nbsp;</div></td>"
                . "<td class='num'>" . (int) $d['closed'] . "</td>"
                . "<td class='num'>" . (int) $d['active_agents'] . "</td>"
                . "</tr>";
        }

        // Weekday averages
        $weeklyRows = '';
        foreach ($weekly as $w) {
            $weeklyRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars($w['day']) . "</strong></td>"
                . "<td class='num'>" . number_format((float) $w['avg_opened'], 1) . "</td>"
                . "<td class='num'>" . number_format((float) $w['avg_closed'], 1) . "</td>"
                . "<td class='num muted'>" . (int) $w['occurrences'] . "</td>"
                . "</tr>";
        }

        // Online agents list (compact)
        $onlineRows = '';
        foreach (array_slice($online, 0, 20) as $u) {
            $onlineRows .= "<tr><td><strong>" . htmlspecialchars((string) $u['name']) . "</strong></td><td><span class='muted'>" . htmlspecialchars((string) $u['email']) . "</span></td></tr>";
        }
        if (count($online) > 20) {
            $onlineRows .= "<tr><td colspan='2' class='muted'>… y " . (count($online) - 20) . " más</td></tr>";
        }

        // Campaigns list (compact)
        $campRows = '';
        foreach (array_slice($camps, 0, 30) as $c) {
            $campRows .= "<tr><td>#" . (int) $c['id'] . "</td><td><strong>" . htmlspecialchars((string) $c['name']) . "</strong></td></tr>";
        }

        $emptyBlock = '';
        if ((int) ($totals['total_conversations'] ?? 0) === 0) {
            $emptyBlock = "<div style='background:#e0f2fe;border:1px solid #7dd3fc;border-radius:8px;padding:24px;text-align:center;color:#075985;'><h3 style='margin:0 0 8px 0;'>ℹ️ Sin actividad registrada</h3><p style='margin:0;'>No hubo conversaciones en Wasapi en esta fecha.</p></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
  .header { background: linear-gradient(135deg, #075e54 0%, #25d366 100%); color: #fff; padding: 28px; text-align: center; border-radius: 10px; }
  .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
  .header p { margin: 8px 0 0 0; font-size: 15px; opacity: 0.95; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead { background: linear-gradient(135deg, #1e293b 0%, #475569 100%); }
  th { color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  tbody tr:nth-child(even) { background-color: #fafafa; }
  td.num { font-family: 'Courier New', monospace; white-space: nowrap; text-align: right; }
  .muted { color: #888; font-size: 11px; }
  .bar { background: linear-gradient(90deg, #25d366, #075e54); height: 14px; border-radius: 3px; min-width: 2px; }
  .pill { display: inline-block; background: #e2e8f0; color: #1e293b; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
  .footer { text-align: center; padding: 18px; color: #777; font-size: 12px; margin-top: 20px; }
  .card { background:#fff; margin:18px 0; padding:22px; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
  .card h2 { margin:0 0 14px 0; font-size:18px; border-bottom:2px solid #075e54; padding-bottom:8px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>📱 Reporte Ejecutivo Wasapi (WhatsApp)</h1>
    <p>{$date}</p>
  </div>

  {$row1}
  {$row2}
  {$row3}

  {$aiBlock}
  {$alertsBlock}
  {$emptyBlock}

  <div class="card">
    <h2>SLA / Tiempos del día (percentiles)</h2>
    <table>
      <thead><tr><th>Métrica</th><th style='text-align:right;'>P50 (mediana)</th><th style='text-align:right;'>P90</th><th style='text-align:right;'>P95</th></tr></thead>
      <tbody>{$slaRows}</tbody>
    </table>
  </div>

  <div class="card">
    <h2>Top {$reportData['top_agents_limit']} agentes (por volumen cerrado)</h2>
    <table>
      <thead><tr>
        <th>Agente</th>
        <th style='text-align:right;'>Total</th>
        <th style='text-align:right;'>Cerradas</th>
        <th style='text-align:right;'>Tasa res.</th>
        <th style='text-align:right;'>1ª resp. prom.</th>
        <th style='text-align:right;'>Resolución prom.</th>
        <th style='text-align:right;'>Escalaciones</th>
      </tr></thead>
      <tbody>{$topRows}</tbody>
    </table>
  </div>
HTML
            . (!empty($bottom)
                ? "<div class='card'><h2>Agentes con menor desempeño (atención requerida)</h2><table><thead><tr><th>Agente</th><th style='text-align:right;'>Total</th><th style='text-align:right;'>Cerradas</th><th style='text-align:right;'>Tasa res.</th><th style='text-align:right;'>1ª resp.</th><th style='text-align:right;'>Resolución</th></tr></thead><tbody>{$bottomRows}</tbody></table></div>"
                : "")
            . (!empty($daily)
                ? "<div class='card'><h2>Tendencia 14 días</h2><table><thead><tr><th>Fecha</th><th style='text-align:right;'>Abiertas</th><th>Cerradas (barra)</th><th style='text-align:right;'>Cerradas</th><th style='text-align:right;'>Agentes activos</th></tr></thead><tbody>{$trendRows}</tbody></table></div>"
                : "")
            . (!empty($weekly)
                ? "<div class='card'><h2>Promedio por día de la semana (14d)</h2><table><thead><tr><th>Día</th><th style='text-align:right;'>Abiertas (avg)</th><th style='text-align:right;'>Cerradas (avg)</th><th style='text-align:right;'>Ocurrencias</th></tr></thead><tbody>{$weeklyRows}</tbody></table></div>"
                : "")
            . (!empty($online)
                ? "<div class='card'><h2>Agentes en línea ahora ({$totals['agents_online']})</h2><table><thead><tr><th>Nombre</th><th>Email</th></tr></thead><tbody>{$onlineRows}</tbody></table></div>"
                : "")
            . (!empty($camps)
                ? "<div class='card'><h2>Campañas configuradas ({$totals['campaigns_count']})</h2><table><thead><tr><th>ID</th><th>Nombre</th></tr></thead><tbody>{$campRows}</tbody></table></div>"
                : "")
            . "<div class='footer'><p><strong>Reporte generado automáticamente</strong></p>"
            . "<p>{$reportData['generated_at']} — Datos en vivo de Wasapi · Sistema Ponche Xtreme</p></div>"
            . "</div></body></html>";
    }
}

// -------------------------------------------------------------
// Email send
// -------------------------------------------------------------

if (!function_exists('sendWasapiReportByEmail')) {
    function sendWasapiReportByEmail(PDO $pdo, array $reportData, array $recipients, string $aiSummary = ''): bool
    {
        if (empty($recipients)) {
            error_log('[wasapi_report] No recipients configured');
            return false;
        }
        $html = generateWasapiReportHTML($reportData, $aiSummary);
        require_once __DIR__ . '/email_functions.php';

        $result = sendDailyWasapiReport($html, $recipients, $reportData);

        if ($result['success']) {
            error_log('[wasapi_report] Sent: ' . $result['message']);
            return true;
        }
        error_log('[wasapi_report] Failed: ' . $result['message']);
        return false;
    }
}
