<?php
/**
 * Daily GHL (GoHighLevel / Voice AI) Executive Report
 *
 * Genera un reporte ejecutivo diario completo de la operación de GHL Voice AI:
 * - KPIs de llamadas (totales, in/out, duración, cobertura de grabación/transcripción/resumen)
 * - Top disposiciones del día con tasa de aparición
 * - Top agentes por volumen + score de calidad
 * - Distribución por duración (buckets) y por canal (Call/SMS/Email/WhatsApp)
 * - Sentimiento (cuando esté disponible)
 * - Alertas detectadas (calls sin disposición, baja cobertura, etc.)
 * - Análisis ejecutivo opcional generado por Claude AI
 *
 * Datos: API en vivo de GHL/Voice AI vía lib/voice_ai_client.php y
 *        lib/voice_ai_extended_reports.php (config en tabla ghl_integrations).
 * Configuración: tabla system_settings (claves ghl_report_*).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/claude_api_client.php';
require_once __DIR__ . '/voice_ai_client.php';
require_once __DIR__ . '/voice_ai_extended_reports.php';

// -------------------------------------------------------------
// Settings & recipients
// -------------------------------------------------------------

if (!function_exists('getGhlReportSettings')) {
    function getGhlReportSettings(PDO $pdo): array
    {
        $defaults = [
            'ghl_report_enabled'                  => '0',
            'ghl_report_time'                     => '08:45',
            'ghl_report_recipients'               => '',
            'ghl_report_days_back'                => '1',
            'ghl_report_max_pages'                => '10',
            'ghl_report_page_size'                => '50',
            'ghl_report_top_agents_limit'         => '10',
            'ghl_report_top_dispositions_limit'   => '10',
            'ghl_report_quality_alert_threshold'  => '70',
            'ghl_report_recording_alert_threshold'=> '90',
            'ghl_report_no_disposition_alert_pct' => '10',
            'ghl_report_exclude_weekends'         => '0',
            'ghl_report_integration_id'           => '',
            'ghl_report_claude_enabled'           => '0',
            'ghl_report_claude_model'             => 'claude-sonnet-4-6',
            'ghl_report_claude_max_tokens'        => '1400',
            'ghl_report_claude_prompt'            => '',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'ghl_report_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getGhlReportSettings: ' . $e->getMessage());
        }
        return $defaults;
    }
}

if (!function_exists('getGhlReportRecipients')) {
    function getGhlReportRecipients(PDO $pdo): array
    {
        $raw = (string) (getGhlReportSettings($pdo)['ghl_report_recipients'] ?? '');
        if ($raw === '') return [];
        $emails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($emails, static fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('ghlSpanishDate')) {
    function ghlSpanishDate(string $date): string
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

if (!function_exists('ghlReportFmtSeconds')) {
    function ghlReportFmtSeconds($sec): string
    {
        $sec = max(0, (int) $sec);
        if ($sec < 60) return $sec . 's';
        if ($sec < 3600) return floor($sec / 60) . 'm ' . ($sec % 60) . 's';
        $h = floor($sec / 3600);
        $m = floor(($sec % 3600) / 60);
        return $h . 'h ' . $m . 'm';
    }
}

// -------------------------------------------------------------
// Build report
// -------------------------------------------------------------

if (!function_exists('generateGhlSingleIntegrationReport')) {
    /**
     * Build the GHL report data for ONE integration. Pure function — caller
     * supplies all settings/thresholds. Returns the per-integration breakdown
     * which is later aggregated by generateDailyGhlReport().
     */
    function generateGhlSingleIntegrationReport(
        PDO $pdo,
        string $date,
        $integrationId,
        int $maxPages,
        int $pageSize,
        int $topAgentsLimit,
        int $topDispLimit,
        int $qualityThreshold,
        int $recordingThreshold,
        float $noDispoAlertPct
    ): array {
        $configStatus = voiceAiGetConfigStatus($pdo, $integrationId);
        if (empty($configStatus['is_ready'])) {
            return [
                'available'   => false,
                'error'       => 'Configuración GHL incompleta (falta API key o Location ID).',
                'integration' => (string) ($configStatus['integration_name'] ?? 'Sin nombre'),
                'integration_id' => $integrationId,
                'totals'      => ['total_calls' => 0],
                'top_dispositions' => [],
                'top_agents'  => [],
                'alerts'      => [],
            ];
        }

        $filters = [
            'start_date'    => $date,
            'end_date'      => $date,
            'max_pages'     => $maxPages,
            'page_size'     => $pageSize,
            'integration_id'=> $integrationId,
        ];

        // 1. Disposition analytics (covers calls + dispositions + by-agent + by-channel + timeline)
        $dispositionAnalytics = voiceAiFetchDispositionAnalytics($pdo, $filters);

        // 2. Quality metrics (recording/transcript/summary coverage + agent quality scores + duration buckets + sentiment)
        $qualityMetrics = voiceAiFetchCallQualityMetrics($pdo, $filters);

        if (empty($dispositionAnalytics['success']) && empty($qualityMetrics['success'])) {
            return [
                'available'   => false,
                'error'       => 'No se pudieron obtener datos: '
                    . ($dispositionAnalytics['message'] ?? $qualityMetrics['message'] ?? 'desconocido'),
                'integration' => (string) ($configStatus['integration_name'] ?? 'Sin nombre'),
                'integration_id' => $integrationId,
                'totals'      => ['total_calls' => 0],
                'top_dispositions' => [],
                'top_agents'  => [],
                'alerts'      => [],
            ];
        }

        $dispositionStats   = $dispositionAnalytics['disposition_stats']   ?? [];
        $dispositionByAgent = $dispositionAnalytics['disposition_by_agent']?? [];
        $dispositionByChannel = $dispositionAnalytics['disposition_by_channel'] ?? [];
        $dispositionTimeline = $dispositionAnalytics['disposition_timeline'] ?? [];
        $totalCallsAnalyzed = (int) ($dispositionAnalytics['meta']['total_calls_analyzed'] ?? 0);

        $qm = $qualityMetrics['quality_metrics'] ?? [];
        $totalCallsQuality = (int) ($qm['total_calls'] ?? 0);
        $totalCalls = max($totalCallsAnalyzed, $totalCallsQuality);

        // -- Aggregate inbound/outbound + no-disposition counts from disposition stats
        $inbound = 0; $outbound = 0; $noDispoCount = 0;
        foreach ($dispositionStats as $ds) {
            $inbound  += (int) ($ds['inbound']  ?? 0);
            $outbound += (int) ($ds['outbound'] ?? 0);
            $disp = trim((string) ($ds['disposition'] ?? ''));
            if ($disp === 'Sin disposición' || $disp === '') {
                $noDispoCount += (int) ($ds['total'] ?? 0);
            }
        }

        // -- Top dispositions with percentage + sample agents
        $top = $dispositionStats; // already sorted desc by total in voiceAiFetchDispositionAnalytics
        usort($top, static fn($a, $b) => (int) ($b['total'] ?? 0) <=> (int) ($a['total'] ?? 0));
        $topDispositions = [];
        $maxDispShare = max(1, $totalCalls);
        foreach (array_slice($top, 0, $topDispLimit) as $d) {
            $tot = (int) ($d['total'] ?? 0);
            $topDispositions[] = [
                'disposition'           => (string) ($d['disposition'] ?? '—'),
                'total'                 => $tot,
                'inbound'               => (int) ($d['inbound']  ?? 0),
                'outbound'              => (int) ($d['outbound'] ?? 0),
                'pct'                   => round(($tot / $maxDispShare) * 100, 1),
                'avg_duration_seconds'  => (int) ($d['avg_duration_seconds'] ?? 0),
                'avg_duration_formatted'=> ghlReportFmtSeconds($d['avg_duration_seconds'] ?? 0),
                'recorded_calls'        => (int) ($d['recorded_calls'] ?? 0),
                'unique_users'          => (int) ($d['users'] ?? 0),
            ];
        }

        // -- Top agents (merge volume + quality scores)
        $qualityByAgent = $qm['quality_scores_by_agent'] ?? [];
        $agentQualityRaw = $qm['agent_quality'] ?? [];
        $agents = [];
        foreach ($dispositionByAgent as $a) {
            $agentId = $a['agent_id'] !== '' ? $a['agent_id'] : ($a['agent_name'] ?? '');
            $totalCallsA   = (int) ($a['total_calls']   ?? 0);
            $totalHandledA = (int) ($a['total_handled'] ?? 0);
            $handledPct = $totalCallsA > 0 ? round(($totalHandledA / $totalCallsA) * 100, 1) : 0.0;

            $q = $qualityByAgent[$agentId] ?? null;
            $qualityScore  = $q ? (float) ($q['quality_score']  ?? 0) : 0.0;
            $transcriptPct = $q ? (float) ($q['transcript_pct'] ?? 0) : 0.0;
            $summaryPct    = $q ? (float) ($q['summary_pct']    ?? 0) : 0.0;
            $recordingPct  = $q ? (float) ($q['recording_pct']  ?? 0) : 0.0;

            $aq = $agentQualityRaw[$agentId] ?? null;
            $avgCallDuration = $aq ? (int) ($aq['avg_call_duration'] ?? 0) : 0;

            $agents[] = [
                'agent_id'                => $agentId,
                'agent_name'              => (string) ($a['agent_name'] ?? '—'),
                'total_calls'             => $totalCallsA,
                'total_handled'           => $totalHandledA,
                'handled_pct'             => $handledPct,
                'quality_score'           => $qualityScore,
                'transcript_pct'          => $transcriptPct,
                'summary_pct'             => $summaryPct,
                'recording_pct'           => $recordingPct,
                'avg_call_duration'       => $avgCallDuration,
                'avg_call_duration_formatted' => ghlReportFmtSeconds($avgCallDuration),
            ];
        }
        usort($agents, static fn($a, $b) => $b['total_calls'] <=> $a['total_calls']);
        $topAgents = array_slice($agents, 0, $topAgentsLimit);

        // -- Bottom (low-quality) agents: agents with calls AND quality_score below threshold
        $qualityIssues = array_filter($agents, static fn($a) => $a['total_calls'] >= 3 && $a['quality_score'] > 0 && $a['quality_score'] < $qualityThreshold);
        usort($qualityIssues, static fn($a, $b) => $a['quality_score'] <=> $b['quality_score']);
        $qualityIssues = array_slice($qualityIssues, 0, max(3, (int) round($topAgentsLimit / 2)));

        // -- Channel breakdown
        $channelBreakdown = [];
        foreach ($dispositionByChannel as $ch) {
            $channelBreakdown[] = [
                'channel'      => (string) ($ch['channel'] ?? '—'),
                'total_calls'  => (int) ($ch['total_calls'] ?? 0),
                'dispositions' => array_slice($ch['dispositions'] ?? [], 0, 5, true),
            ];
        }
        usort($channelBreakdown, static fn($a, $b) => $b['total_calls'] <=> $a['total_calls']);

        // -- Sentiment
        $sentimentDist = $qm['sentiment_distribution'] ?? [];
        $withSentiment = (int) ($qm['with_sentiment'] ?? 0);

        // -- Duration buckets
        $durationBuckets = $qm['calls_by_duration_range'] ?? [];

        // -- Total/avg duration
        $avgDuration = (int) ($qm['avg_duration'] ?? 0);
        $totalDuration = 0;
        foreach ($agents as $a) {
            $totalDuration += $a['avg_call_duration'] * $a['total_calls'];
        }

        // -- Coverage percentages (already computed by voiceAiFetchCallQualityMetrics)
        $recordingCoverage  = (float) ($qm['recording_coverage_pct']  ?? 0);
        $transcriptCoverage = (float) ($qm['transcript_coverage_pct'] ?? 0);
        $summaryCoverage    = (float) ($qm['summary_coverage_pct']    ?? 0);

        // -- No-disposition pct
        $noDispoPct = $totalCalls > 0 ? round(($noDispoCount / $totalCalls) * 100, 2) : 0.0;

        // -- Alerts
        $alerts = [];
        if ($totalCalls > 0 && $recordingCoverage < $recordingThreshold) {
            $alerts[] = [
                'level' => 'warning',
                'text'  => "Cobertura de grabación {$recordingCoverage}% (umbral {$recordingThreshold}%). Verificar que los agentes están grabando todas las llamadas.",
            ];
        }
        if ($totalCalls > 0 && $noDispoPct >= $noDispoAlertPct) {
            $alerts[] = [
                'level' => 'warning',
                'text'  => "{$noDispoCount} llamadas sin disposición ({$noDispoPct}% del total). Revisar cierre de disposiciones.",
            ];
        }
        if (count($qualityIssues) > 0) {
            $names = array_map(static fn($a) => $a['agent_name'], $qualityIssues);
            $alerts[] = [
                'level' => 'warning',
                'text'  => count($qualityIssues) . " agentes con score de calidad < {$qualityThreshold}: " . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? '…' : ''),
            ];
        }
        if ($totalCalls === 0) {
            $alerts[] = ['level' => 'info', 'text' => 'No se registraron llamadas en este día.'];
        }

        $totals = [
            'total_calls'                => $totalCalls,
            'inbound_calls'              => $inbound,
            'outbound_calls'             => $outbound,
            'unique_agents'              => count($agents),
            'unique_dispositions'        => (int) ($dispositionAnalytics['meta']['unique_dispositions'] ?? count($dispositionStats)),
            'no_disposition'             => $noDispoCount,
            'no_disposition_pct'         => $noDispoPct,
            'with_recording'             => (int) ($qm['with_recording']  ?? 0),
            'with_transcript'            => (int) ($qm['with_transcript'] ?? 0),
            'with_summary'               => (int) ($qm['with_summary']    ?? 0),
            'with_sentiment'             => $withSentiment,
            'recording_coverage_pct'     => $recordingCoverage,
            'transcript_coverage_pct'    => $transcriptCoverage,
            'summary_coverage_pct'       => $summaryCoverage,
            'avg_duration_seconds'       => $avgDuration,
            'avg_duration_formatted'     => ghlReportFmtSeconds($avgDuration),
            'total_duration_seconds'     => $totalDuration,
            'total_duration_formatted'   => ghlReportFmtSeconds($totalDuration),
            'min_duration_seconds'       => (int) ($qm['min_duration'] ?? 0),
            'max_duration_seconds'       => (int) ($qm['max_duration'] ?? 0),
        ];

        return [
            'available'           => true,
            'integration'         => (string) ($configStatus['integration_name'] ?? 'Sin nombre'),
            'integration_id'      => $configStatus['selected_integration_id'] ?? $integrationId,
            'location_id'         => $configStatus['location_id'] ?? '',
            'totals'              => $totals,
            'top_dispositions'    => $topDispositions,
            'top_agents'          => $topAgents,
            'quality_issues'      => $qualityIssues,
            'channel_breakdown'   => $channelBreakdown,
            'sentiment_distribution' => $sentimentDist,
            'duration_buckets'    => $durationBuckets,
            'disposition_timeline'=> $dispositionTimeline,
            'all_agents'          => $agents,
            'all_dispositions'    => $dispositionStats,  // raw, for aggregation
            'alerts'              => $alerts,
            'pages_fetched'       => (int) ($dispositionAnalytics['meta']['pages_fetched'] ?? 0),
        ];
    }
}

if (!function_exists('generateDailyGhlReport')) {
    /**
     * Build the GHL Voice AI report for the target date (default: yesterday).
     *
     * Behavior:
     * - If `ghl_report_integration_id` setting is empty → iterate ALL enabled
     *   integrations from voice_ai_integrations and produce an aggregated
     *   report + per-integration breakdown.
     * - If `ghl_report_integration_id` is set to a specific ID → only that one.
     */
    function generateDailyGhlReport(PDO $pdo, ?string $date = null): array
    {
        $settings = getGhlReportSettings($pdo);
        $date = $date ?: date('Y-m-d', strtotime('yesterday'));

        $maxPages           = max(1, min(50, (int) ($settings['ghl_report_max_pages'] ?? 10)));
        $pageSize           = max(10, min(50, (int) ($settings['ghl_report_page_size'] ?? 50)));
        $topAgentsLimit     = max(3, (int) ($settings['ghl_report_top_agents_limit'] ?? 10));
        $topDispLimit       = max(3, (int) ($settings['ghl_report_top_dispositions_limit'] ?? 10));
        $qualityThreshold   = max(0, (int) ($settings['ghl_report_quality_alert_threshold'] ?? 70));
        $recordingThreshold = max(0, (int) ($settings['ghl_report_recording_alert_threshold'] ?? 90));
        $noDispoAlertPct    = max(0, (float) ($settings['ghl_report_no_disposition_alert_pct'] ?? 10));
        $configuredId       = trim((string) ($settings['ghl_report_integration_id'] ?? ''));

        // Resolve the list of integrations to process
        $allIntegrations = voiceAiGetIntegrations($pdo);
        $targets = [];
        if ($configuredId !== '') {
            foreach ($allIntegrations as $i) {
                if ((int) ($i['integration_id'] ?? 0) === (int) $configuredId) {
                    $targets[] = $i;
                    break;
                }
            }
            if (empty($targets)) {
                return [
                    'available'        => false,
                    'error'            => "La integración configurada (#{$configuredId}) no existe o está deshabilitada.",
                    'date'             => $date,
                    'date_formatted'   => ghlSpanishDate($date),
                    'integrations_count' => 0,
                    'integrations'     => [],
                    'totals'           => ['total_calls' => 0],
                    'top_dispositions' => [],
                    'top_agents'       => [],
                    'alerts'           => [],
                    'generated_at'     => date('Y-m-d H:i:s'),
                ];
            }
        } else {
            $targets = $allIntegrations;
        }

        if (empty($targets)) {
            return [
                'available'        => false,
                'error'            => 'No hay integraciones GHL habilitadas. Configura al menos una en /ghl_voice_ai_dashboard.php.',
                'date'             => $date,
                'date_formatted'   => ghlSpanishDate($date),
                'integrations_count' => 0,
                'integrations'     => [],
                'totals'           => ['total_calls' => 0],
                'top_dispositions' => [],
                'top_agents'       => [],
                'alerts'           => [],
                'generated_at'     => date('Y-m-d H:i:s'),
            ];
        }

        // Run the per-integration report for every target
        $perIntegration = [];
        foreach ($targets as $i) {
            $iid = (int) ($i['integration_id'] ?? 0);
            if ($iid <= 0) continue;
            $perIntegration[] = generateGhlSingleIntegrationReport(
                $pdo,
                $date,
                $iid,
                $maxPages,
                $pageSize,
                $topAgentsLimit,
                $topDispLimit,
                $qualityThreshold,
                $recordingThreshold,
                $noDispoAlertPct
            );
        }

        // -- Aggregate totals across integrations
        $aggTotalCalls = 0; $aggInbound = 0; $aggOutbound = 0;
        $aggNoDispo = 0; $aggWithRec = 0; $aggWithTrans = 0; $aggWithSum = 0; $aggWithSent = 0;
        $aggTotalDuration = 0; $aggMin = null; $aggMax = null;
        $aggUniqueAgentKeys = [];
        $aggUniqueDispositionKeys = [];
        $aggDispositions = [];   // disposition_name => [total, inbound, outbound, total_duration, recorded, integrations[]]
        $aggAgents = [];         // (integration_id . '|' . agent_id) => agent row
        $aggChannels = [];       // channel_name => total_calls
        $aggSentiment = [];      // sentiment => count
        $aggBuckets = ['0-30s' => 0, '31-120s' => 0, '2-5m' => 0, '5-15m' => 0, '15m+' => 0];
        $aggQualityIssues = [];
        $aggAlerts = [];

        foreach ($perIntegration as $pi) {
            $name = $pi['integration'] ?? '—';
            if (!empty($pi['alerts']) && is_array($pi['alerts'])) {
                foreach ($pi['alerts'] as $a) {
                    $aggAlerts[] = [
                        'level' => $a['level'] ?? 'info',
                        'text'  => "[{$name}] " . (string) ($a['text'] ?? ''),
                    ];
                }
            }
            if (empty($pi['available'])) {
                continue;
            }

            $t = $pi['totals'] ?? [];
            $aggTotalCalls    += (int) ($t['total_calls']     ?? 0);
            $aggInbound       += (int) ($t['inbound_calls']   ?? 0);
            $aggOutbound      += (int) ($t['outbound_calls']  ?? 0);
            $aggNoDispo       += (int) ($t['no_disposition']  ?? 0);
            $aggWithRec       += (int) ($t['with_recording']  ?? 0);
            $aggWithTrans     += (int) ($t['with_transcript'] ?? 0);
            $aggWithSum       += (int) ($t['with_summary']    ?? 0);
            $aggWithSent      += (int) ($t['with_sentiment']  ?? 0);
            $aggTotalDuration += (int) ($t['total_duration_seconds'] ?? 0);

            $minD = (int) ($t['min_duration_seconds'] ?? 0);
            $maxD = (int) ($t['max_duration_seconds'] ?? 0);
            if ($minD > 0 && ($aggMin === null || $minD < $aggMin)) $aggMin = $minD;
            if ($aggMax === null || $maxD > $aggMax) $aggMax = $maxD;

            // Aggregate dispositions
            foreach ($pi['all_dispositions'] ?? [] as $d) {
                $key = (string) ($d['disposition'] ?? '—');
                if (!isset($aggDispositions[$key])) {
                    $aggDispositions[$key] = [
                        'disposition'           => $key,
                        'total'                 => 0,
                        'inbound'               => 0,
                        'outbound'              => 0,
                        'total_duration_seconds'=> 0,
                        'recorded_calls'        => 0,
                        'integrations'          => [],
                    ];
                    $aggUniqueDispositionKeys[$key] = true;
                }
                $aggDispositions[$key]['total']                  += (int) ($d['total']                  ?? 0);
                $aggDispositions[$key]['inbound']                += (int) ($d['inbound']                ?? 0);
                $aggDispositions[$key]['outbound']               += (int) ($d['outbound']               ?? 0);
                $aggDispositions[$key]['total_duration_seconds'] += (int) ($d['total_duration_seconds'] ?? 0);
                $aggDispositions[$key]['recorded_calls']         += (int) ($d['recorded_calls']         ?? 0);
                $aggDispositions[$key]['integrations'][$name]    = true;
            }

            // Aggregate agents — keyed by integration so same name in 2 orgs stays separate
            foreach ($pi['all_agents'] ?? [] as $a) {
                $aid = (string) ($a['agent_id'] ?? '');
                $aname = (string) ($a['agent_name'] ?? '—');
                $key = ($pi['integration_id'] ?? 0) . '|' . ($aid !== '' ? $aid : $aname);
                $aggAgents[$key] = array_merge($a, [
                    'integration'    => $name,
                    'integration_id' => $pi['integration_id'] ?? null,
                ]);
                $aggUniqueAgentKeys[$key] = true;
            }

            // Aggregate channels
            foreach ($pi['channel_breakdown'] ?? [] as $ch) {
                $cn = (string) ($ch['channel'] ?? '—');
                $aggChannels[$cn] = ($aggChannels[$cn] ?? 0) + (int) ($ch['total_calls'] ?? 0);
            }

            // Aggregate sentiment
            foreach ($pi['sentiment_distribution'] ?? [] as $sName => $cnt) {
                $aggSentiment[$sName] = ($aggSentiment[$sName] ?? 0) + (int) $cnt;
            }

            // Aggregate duration buckets
            foreach ($pi['duration_buckets'] ?? [] as $bName => $cnt) {
                if (!isset($aggBuckets[$bName])) $aggBuckets[$bName] = 0;
                $aggBuckets[$bName] += (int) $cnt;
            }

            // Aggregate quality issues with integration label
            foreach ($pi['quality_issues'] ?? [] as $qi) {
                $aggQualityIssues[] = array_merge($qi, [
                    'integration'    => $name,
                    'integration_id' => $pi['integration_id'] ?? null,
                ]);
            }
        }

        // -- Build per-integration summary table for the email
        $integrationsSummary = [];
        foreach ($perIntegration as $pi) {
            if (empty($pi['available'])) {
                $integrationsSummary[] = [
                    'integration'              => $pi['integration'] ?? '—',
                    'integration_id'           => $pi['integration_id'] ?? null,
                    'available'                => false,
                    'error'                    => $pi['error'] ?? 'Sin datos',
                    'total_calls'              => 0,
                    'inbound_calls'            => 0,
                    'outbound_calls'           => 0,
                    'unique_agents'            => 0,
                    'recording_coverage_pct'   => 0.0,
                    'transcript_coverage_pct'  => 0.0,
                    'no_disposition'           => 0,
                    'avg_duration_formatted'   => '—',
                ];
                continue;
            }
            $t = $pi['totals'] ?? [];
            $integrationsSummary[] = [
                'integration'              => $pi['integration'] ?? '—',
                'integration_id'           => $pi['integration_id'] ?? null,
                'available'                => true,
                'total_calls'              => (int)   ($t['total_calls']             ?? 0),
                'inbound_calls'            => (int)   ($t['inbound_calls']           ?? 0),
                'outbound_calls'           => (int)   ($t['outbound_calls']          ?? 0),
                'unique_agents'            => (int)   ($t['unique_agents']           ?? 0),
                'unique_dispositions'      => (int)   ($t['unique_dispositions']     ?? 0),
                'recording_coverage_pct'   => (float) ($t['recording_coverage_pct']  ?? 0),
                'transcript_coverage_pct'  => (float) ($t['transcript_coverage_pct'] ?? 0),
                'summary_coverage_pct'     => (float) ($t['summary_coverage_pct']    ?? 0),
                'no_disposition'           => (int)   ($t['no_disposition']          ?? 0),
                'no_disposition_pct'       => (float) ($t['no_disposition_pct']      ?? 0),
                'avg_duration_seconds'     => (int)   ($t['avg_duration_seconds']    ?? 0),
                'avg_duration_formatted'   => (string)($t['avg_duration_formatted']  ?? '—'),
                'top_disposition'          => $pi['top_dispositions'][0]['disposition'] ?? '—',
                'top_agent'                => $pi['top_agents'][0]['agent_name']        ?? '—',
            ];
        }

        // -- Top dispositions aggregated
        $aggDispList = array_values($aggDispositions);
        usort($aggDispList, static fn($a, $b) => $b['total'] <=> $a['total']);
        $topDispositions = [];
        $maxShare = max(1, $aggTotalCalls);
        foreach (array_slice($aggDispList, 0, $topDispLimit) as $d) {
            $tot = (int) $d['total'];
            $avgDur = $tot > 0 ? (int) round($d['total_duration_seconds'] / $tot) : 0;
            $topDispositions[] = [
                'disposition'           => $d['disposition'],
                'total'                 => $tot,
                'inbound'               => (int) $d['inbound'],
                'outbound'              => (int) $d['outbound'],
                'pct'                   => round(($tot / $maxShare) * 100, 1),
                'avg_duration_seconds'  => $avgDur,
                'avg_duration_formatted'=> ghlReportFmtSeconds($avgDur),
                'recorded_calls'        => (int) $d['recorded_calls'],
                'unique_users'          => 0, // not aggregated cross-integration
                'integrations_count'    => count($d['integrations']),
                'integrations_list'     => array_keys($d['integrations']),
            ];
        }

        // -- Top agents aggregated (already keyed per integration+agent)
        $aggAgentsList = array_values($aggAgents);
        usort($aggAgentsList, static fn($a, $b) => $b['total_calls'] <=> $a['total_calls']);
        $topAgents = array_slice($aggAgentsList, 0, $topAgentsLimit);

        // -- Channels aggregated
        $channelBreakdown = [];
        arsort($aggChannels);
        foreach ($aggChannels as $cn => $cnt) {
            $channelBreakdown[] = ['channel' => $cn, 'total_calls' => (int) $cnt, 'dispositions' => []];
        }

        // -- Coverage % aggregated
        $totalCalls = $aggTotalCalls;
        $recordingCoverage  = $totalCalls > 0 ? round(($aggWithRec   / $totalCalls) * 100, 2) : 0.0;
        $transcriptCoverage = $totalCalls > 0 ? round(($aggWithTrans / $totalCalls) * 100, 2) : 0.0;
        $summaryCoverage    = $totalCalls > 0 ? round(($aggWithSum   / $totalCalls) * 100, 2) : 0.0;
        $noDispoPct         = $totalCalls > 0 ? round(($aggNoDispo   / $totalCalls) * 100, 2) : 0.0;
        $avgDuration        = $totalCalls > 0 ? (int) round($aggTotalDuration / $totalCalls) : 0;

        // -- Aggregate-level alerts (in addition to per-integration)
        if ($totalCalls > 0 && $recordingCoverage < $recordingThreshold) {
            $aggAlerts[] = [
                'level' => 'warning',
                'text'  => "[GLOBAL] Cobertura de grabación agregada {$recordingCoverage}% (umbral {$recordingThreshold}%).",
            ];
        }
        if ($totalCalls > 0 && $noDispoPct >= $noDispoAlertPct) {
            $aggAlerts[] = [
                'level' => 'warning',
                'text'  => "[GLOBAL] {$aggNoDispo} llamadas sin disposición ({$noDispoPct}% del total agregado).",
            ];
        }
        if ($totalCalls === 0) {
            $aggAlerts[] = ['level' => 'info', 'text' => '[GLOBAL] No se registraron llamadas en este día en ninguna integración.'];
        }

        $totalsAgg = [
            'total_calls'                => $totalCalls,
            'inbound_calls'              => $aggInbound,
            'outbound_calls'             => $aggOutbound,
            'unique_agents'              => count($aggUniqueAgentKeys),
            'unique_dispositions'        => count($aggUniqueDispositionKeys),
            'no_disposition'             => $aggNoDispo,
            'no_disposition_pct'         => $noDispoPct,
            'with_recording'             => $aggWithRec,
            'with_transcript'            => $aggWithTrans,
            'with_summary'               => $aggWithSum,
            'with_sentiment'             => $aggWithSent,
            'recording_coverage_pct'     => $recordingCoverage,
            'transcript_coverage_pct'    => $transcriptCoverage,
            'summary_coverage_pct'       => $summaryCoverage,
            'avg_duration_seconds'       => $avgDuration,
            'avg_duration_formatted'     => ghlReportFmtSeconds($avgDuration),
            'total_duration_seconds'     => $aggTotalDuration,
            'total_duration_formatted'   => ghlReportFmtSeconds($aggTotalDuration),
            'min_duration_seconds'       => (int) ($aggMin ?? 0),
            'max_duration_seconds'       => (int) ($aggMax ?? 0),
        ];

        return [
            'available'              => true,
            'date'                   => $date,
            'date_formatted'         => ghlSpanishDate($date),
            'integrations_count'     => count($perIntegration),
            'integration'            => count($perIntegration) === 1
                ? ($perIntegration[0]['integration'] ?? '—')
                : (count($perIntegration) . ' integraciones'),
            'integrations_summary'   => $integrationsSummary,
            'integrations_detail'    => $perIntegration,
            'totals'                 => $totalsAgg,
            'top_dispositions'       => $topDispositions,
            'top_agents'             => $topAgents,
            'quality_issues'         => $aggQualityIssues,
            'channel_breakdown'      => $channelBreakdown,
            'sentiment_distribution' => $aggSentiment,
            'duration_buckets'       => $aggBuckets,
            'alerts'                 => $aggAlerts,
            'thresholds' => [
                'quality_score'         => $qualityThreshold,
                'recording_coverage_pct'=> $recordingThreshold,
                'no_disposition_pct'    => $noDispoAlertPct,
            ],
            'top_agents_limit'       => $topAgentsLimit,
            'top_dispositions_limit' => $topDispLimit,
            'pagination' => [
                'max_pages'     => $maxPages,
                'page_size'     => $pageSize,
                'pages_fetched' => array_sum(array_column($perIntegration, 'pages_fetched')),
            ],
            'generated_at'           => date('Y-m-d H:i:s'),
        ];
    }
}

// -------------------------------------------------------------
// Claude AI summary
// -------------------------------------------------------------

if (!function_exists('generateAIGhlSummary')) {
    function generateAIGhlSummary(PDO $pdo, array $reportData): string
    {
        $settings = getGhlReportSettings($pdo);
        if (($settings['ghl_report_claude_enabled'] ?? '0') !== '1') {
            return '';
        }
        if (empty($reportData['available']) || (int) ($reportData['totals']['total_calls'] ?? 0) === 0) {
            return '';
        }

        $model     = trim((string) ($settings['ghl_report_claude_model'] ?? '')) ?: resolveAnthropicDefaultModel($pdo);
        $maxTokens = max(200, (int) ($settings['ghl_report_claude_max_tokens'] ?? 1400));
        $systemPrompt = (string) ($settings['ghl_report_claude_prompt'] ?? '');

        $payload = [
            'fecha'                  => $reportData['date'],
            'integraciones_count'    => $reportData['integrations_count'] ?? 1,
            'integraciones_resumen'  => $reportData['integrations_summary'] ?? [],
            'totales_agregados'      => $reportData['totals'],
            'top_disposiciones'      => $reportData['top_dispositions'],
            'top_agentes'            => $reportData['top_agents'],
            'agentes_con_problemas'  => $reportData['quality_issues'],
            'canales'                => $reportData['channel_breakdown'],
            'sentimiento'            => $reportData['sentiment_distribution'],
            'distribucion_duracion'  => $reportData['duration_buckets'],
            'alertas_detectadas'     => $reportData['alerts'],
            'umbrales_configurados'  => $reportData['thresholds'],
        ];

        $userPrompt = "Aquí está el JSON con la operación de GHL Voice AI del día. Genera el análisis ejecutivo según las instrucciones:\n\n"
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
            error_log('[ghl_report] Claude API error: ' . ($result['error'] ?? 'unknown'));
            return '';
        }
        return (string) $result['content'];
    }
}

// -------------------------------------------------------------
// HTML rendering
// -------------------------------------------------------------

if (!function_exists('generateGhlReportHTML')) {
    function generateGhlReportHTML(array $reportData, string $aiSummary = ''): string
    {
        $date     = htmlspecialchars($reportData['date_formatted'] ?? '');
        $integ    = htmlspecialchars((string) ($reportData['integration'] ?? '—'));
        $integCount = (int) ($reportData['integrations_count'] ?? 1);
        $integSummary = $reportData['integrations_summary'] ?? [];
        $totals   = $reportData['totals'] ?? [];
        $topDisp  = $reportData['top_dispositions'] ?? [];
        $topAg    = $reportData['top_agents'] ?? [];
        $qIssues  = $reportData['quality_issues'] ?? [];
        $channels = $reportData['channel_breakdown'] ?? [];
        $sent     = $reportData['sentiment_distribution'] ?? [];
        $buckets  = $reportData['duration_buckets'] ?? [];
        $alerts   = $reportData['alerts'] ?? [];

        if (empty($reportData['available'])) {
            $err = htmlspecialchars($reportData['error'] ?? 'Datos no disponibles.');
            return "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;padding:20px;'>"
                . "<h2>📞 Reporte GHL Voice AI — {$date}</h2>"
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

        $row1 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCard}border-top:4px solid #4f46e5;'><p style='{$statLabel}'>Llamadas totales</p><p style='{$statNum}'>" . (int) ($totals['total_calls'] ?? 0) . "</p><p style='{$statSub}'>In: " . (int) ($totals['inbound_calls'] ?? 0) . " · Out: " . (int) ($totals['outbound_calls'] ?? 0) . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #06b6d4;'><p style='{$statLabel}'>Duración promedio</p><p style='{$statNum};font-size:20px;'>" . htmlspecialchars($totals['avg_duration_formatted'] ?? '—') . "</p><p style='{$statSub}'>Total: " . htmlspecialchars($totals['total_duration_formatted'] ?? '—') . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #16a34a;'><p style='{$statLabel}'>Cobertura grabación</p><p style='{$statNum}'>" . number_format((float) ($totals['recording_coverage_pct'] ?? 0), 1) . "%</p><p style='{$statSub}'>" . (int) ($totals['with_recording'] ?? 0) . " grabadas</p></td>"
            . "<td style='{$statCard}border-top:4px solid #f97316;'><p style='{$statLabel}'>Cobertura transcripción</p><p style='{$statNum}'>" . number_format((float) ($totals['transcript_coverage_pct'] ?? 0), 1) . "%</p><p style='{$statSub}'>" . (int) ($totals['with_transcript'] ?? 0) . " transcritas</p></td>"
            . "</tr></table>";

        $row2 = "<table role='presentation' width='100%' cellspacing='10' cellpadding='0' border='0' style='margin:18px 0;border-collapse:separate;'><tr>"
            . "<td style='{$statCard}border-top:4px solid #8b5cf6;'><p style='{$statLabel}'>Cobertura resumen IA</p><p style='{$statNum}'>" . number_format((float) ($totals['summary_coverage_pct'] ?? 0), 1) . "%</p><p style='{$statSub}'>" . (int) ($totals['with_summary'] ?? 0) . " resúmenes</p></td>"
            . "<td style='{$statCard}border-top:4px solid #64748b;'><p style='{$statLabel}'>Agentes únicos</p><p style='{$statNum}'>" . (int) ($totals['unique_agents'] ?? 0) . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #14b8a6;'><p style='{$statLabel}'>Disposiciones únicas</p><p style='{$statNum}'>" . (int) ($totals['unique_dispositions'] ?? 0) . "</p></td>"
            . "<td style='{$statCard}border-top:4px solid #ef4444;'><p style='{$statLabel}'>Sin disposición</p><p style='{$statNum}'>" . (int) ($totals['no_disposition'] ?? 0) . "</p><p style='{$statSub}'>" . number_format((float) ($totals['no_disposition_pct'] ?? 0), 1) . "% del total</p></td>"
            . "</tr></table>";

        // Top dispositions
        $maxTotal = 1;
        foreach ($topDisp as $d) $maxTotal = max($maxTotal, (int) $d['total']);
        $dispRows = '';
        foreach ($topDisp as $d) {
            $width = ((int) $d['total'] / $maxTotal) * 100;
            $dispRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $d['disposition']) . "</strong></td>"
                . "<td class='num'>" . (int) $d['total'] . "</td>"
                . "<td><div class='bar' style='width:{$width}%;'>&nbsp;</div></td>"
                . "<td class='num'>" . number_format((float) $d['pct'], 1) . "%</td>"
                . "<td class='num'>" . (int) $d['inbound'] . " / " . (int) $d['outbound'] . "</td>"
                . "<td class='num'>" . htmlspecialchars((string) $d['avg_duration_formatted']) . "</td>"
                . "<td class='num'>" . (int) $d['recorded_calls'] . "</td>"
                . "</tr>";
        }

        // Top agents (with integration column)
        $agentRows = '';
        foreach ($topAg as $a) {
            $qScore = (float) ($a['quality_score'] ?? 0);
            $qColor = $qScore >= 85 ? '#16a34a' : ($qScore >= 70 ? '#f59e0b' : '#ef4444');
            $integName = htmlspecialchars((string) ($a['integration'] ?? '—'));
            $agentRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $a['agent_name']) . "</strong></td>"
                . "<td><span class='pill'>{$integName}</span></td>"
                . "<td class='num'>" . (int) $a['total_calls'] . "</td>"
                . "<td class='num'>" . (int) $a['total_handled'] . "</td>"
                . "<td class='num'>" . number_format((float) $a['handled_pct'], 1) . "%</td>"
                . "<td class='num'><span style='color:{$qColor};font-weight:700;'>" . number_format($qScore, 1) . "</span></td>"
                . "<td class='num'>" . number_format((float) $a['recording_pct'], 1) . "%</td>"
                . "<td class='num'>" . number_format((float) $a['transcript_pct'], 1) . "%</td>"
                . "<td class='num'>" . htmlspecialchars((string) $a['avg_call_duration_formatted']) . "</td>"
                . "</tr>";
        }

        // Quality issues (with integration column)
        $qRows = '';
        foreach ($qIssues as $a) {
            $integName = htmlspecialchars((string) ($a['integration'] ?? '—'));
            $qRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $a['agent_name']) . "</strong></td>"
                . "<td><span class='pill'>{$integName}</span></td>"
                . "<td class='num'>" . (int) $a['total_calls'] . "</td>"
                . "<td class='num'><span style='color:#ef4444;font-weight:700;'>" . number_format((float) $a['quality_score'], 1) . "</span></td>"
                . "<td class='num'>" . number_format((float) $a['recording_pct'], 1) . "%</td>"
                . "<td class='num'>" . number_format((float) $a['transcript_pct'], 1) . "%</td>"
                . "<td class='num'>" . number_format((float) $a['summary_pct'], 1) . "%</td>"
                . "</tr>";
        }

        // Channels
        $maxCh = 1;
        foreach ($channels as $c) $maxCh = max($maxCh, (int) $c['total_calls']);
        $chRows = '';
        foreach ($channels as $c) {
            $width = ((int) $c['total_calls'] / $maxCh) * 100;
            $disp = '';
            foreach ($c['dispositions'] ?? [] as $dn => $cnt) {
                $disp .= htmlspecialchars((string) $dn) . " ({$cnt}), ";
            }
            $disp = rtrim($disp, ', ');
            $chRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $c['channel']) . "</strong></td>"
                . "<td class='num'>" . (int) $c['total_calls'] . "</td>"
                . "<td><div class='bar' style='width:{$width}%;'>&nbsp;</div></td>"
                . "<td><span class='muted'>{$disp}</span></td>"
                . "</tr>";
        }

        // Sentiment
        $sentRows = '';
        $totalSent = array_sum($sent);
        if ($totalSent > 0) {
            foreach ($sent as $label => $cnt) {
                $pct = round(($cnt / $totalSent) * 100, 1);
                $sentRows .= "<tr><td><strong>" . htmlspecialchars((string) $label) . "</strong></td><td class='num'>{$cnt}</td><td class='num'>{$pct}%</td></tr>";
            }
        }

        // Duration buckets
        $bucketRows = '';
        $maxBucket = 1;
        foreach ($buckets as $b) $maxBucket = max($maxBucket, (int) $b);
        foreach ($buckets as $label => $cnt) {
            $width = ($cnt / $maxBucket) * 100;
            $bucketRows .= "<tr>"
                . "<td><strong>" . htmlspecialchars((string) $label) . "</strong></td>"
                . "<td><div class='bar' style='width:{$width}%;'>&nbsp;</div></td>"
                . "<td class='num'>" . (int) $cnt . "</td>"
                . "</tr>";
        }

        $emptyBlock = '';
        if ((int) ($totals['total_calls'] ?? 0) === 0) {
            $emptyBlock = "<div style='background:#e0f2fe;border:1px solid #7dd3fc;border-radius:8px;padding:24px;text-align:center;color:#075985;'><h3 style='margin:0 0 8px 0;'>ℹ️ Sin actividad registrada</h3><p style='margin:0;'>No hubo llamadas en GHL/Voice AI en esta fecha.</p></div>";
        }

        // Per-integration summary table (always shown when there's >0 integrations)
        $integBlock = '';
        if (!empty($integSummary)) {
            $integRows = '';
            $maxIntCalls = 1;
            foreach ($integSummary as $is) $maxIntCalls = max($maxIntCalls, (int) ($is['total_calls'] ?? 0));
            foreach ($integSummary as $is) {
                if (empty($is['available'])) {
                    $integRows .= "<tr>"
                        . "<td><strong>" . htmlspecialchars((string) $is['integration']) . "</strong></td>"
                        . "<td colspan='8' style='color:#dc2626;'>" . htmlspecialchars((string) ($is['error'] ?? 'Sin datos')) . "</td>"
                        . "</tr>";
                    continue;
                }
                $width = ((int) $is['total_calls'] / $maxIntCalls) * 100;
                $recColor = ((float) $is['recording_coverage_pct']) >= 90 ? '#16a34a' : (((float) $is['recording_coverage_pct']) >= 70 ? '#f59e0b' : '#ef4444');
                $integRows .= "<tr>"
                    . "<td><strong>" . htmlspecialchars((string) $is['integration']) . "</strong>"
                    . "<br><span class='muted'>ID #" . (int) ($is['integration_id'] ?? 0) . "</span></td>"
                    . "<td class='num'>" . (int) $is['total_calls'] . "</td>"
                    . "<td><div class='bar' style='width:{$width}%;'>&nbsp;</div></td>"
                    . "<td class='num'>" . (int) $is['inbound_calls'] . " / " . (int) $is['outbound_calls'] . "</td>"
                    . "<td class='num'>" . (int) $is['unique_agents'] . "</td>"
                    . "<td class='num'>" . htmlspecialchars((string) $is['avg_duration_formatted']) . "</td>"
                    . "<td class='num'><span style='color:{$recColor};font-weight:700;'>" . number_format((float) $is['recording_coverage_pct'], 1) . "%</span></td>"
                    . "<td class='num'>" . number_format((float) $is['transcript_coverage_pct'], 1) . "%</td>"
                    . "<td class='num'>" . (int) $is['no_disposition'] . " <span class='muted'>(" . number_format((float) $is['no_disposition_pct'], 1) . "%)</span></td>"
                    . "</tr>";
            }
            $integBlock = "<div class='card'>"
                . "<h2>📊 Desglose por integración ({$integCount} cuentas GHL)</h2>"
                . "<p class='muted' style='margin:-8px 0 12px 0;'>Cada fila es una integración GHL configurada. Los KPIs grandes en la cabecera son la suma agregada.</p>"
                . "<table>"
                . "<thead><tr>"
                . "<th>Integración</th>"
                . "<th style='text-align:right;'>Llamadas</th>"
                . "<th>Distribución</th>"
                . "<th style='text-align:right;'>In / Out</th>"
                . "<th style='text-align:right;'>Agentes</th>"
                . "<th style='text-align:right;'>Dur. prom.</th>"
                . "<th style='text-align:right;'>Grab.</th>"
                . "<th style='text-align:right;'>Trans.</th>"
                . "<th style='text-align:right;'>Sin disp.</th>"
                . "</tr></thead>"
                . "<tbody>{$integRows}</tbody>"
                . "</table></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
  .header { background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%); color: #fff; padding: 28px; text-align: center; border-radius: 10px; }
  .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
  .header p { margin: 8px 0 0 0; font-size: 15px; opacity: 0.95; }
  .header .integ { display:inline-block;background:rgba(255,255,255,.18);padding:4px 12px;border-radius:14px;font-size:12px;margin-top:6px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead { background: linear-gradient(135deg, #1e293b 0%, #475569 100%); }
  th { color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  tbody tr:nth-child(even) { background-color: #fafafa; }
  td.num { font-family: 'Courier New', monospace; white-space: nowrap; text-align: right; }
  .muted { color: #888; font-size: 11px; }
  .bar { background: linear-gradient(90deg, #06b6d4, #4f46e5); height: 14px; border-radius: 3px; min-width: 2px; }
  .pill { display: inline-block; background: #e2e8f0; color: #1e293b; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
  .footer { text-align: center; padding: 18px; color: #777; font-size: 12px; margin-top: 20px; }
  .card { background:#fff; margin:18px 0; padding:22px; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
  .card h2 { margin:0 0 14px 0; font-size:18px; border-bottom:2px solid #4f46e5; padding-bottom:8px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>📞 Reporte Ejecutivo GHL Voice AI</h1>
    <p>{$date}</p>
    <div class="integ">{$integ}</div>
  </div>

  {$row1}
  {$row2}

  {$aiBlock}
  {$alertsBlock}
  {$emptyBlock}
  {$integBlock}

  <div class="card">
    <h2>Top {$reportData['top_dispositions_limit']} disposiciones</h2>
    <table>
      <thead><tr>
        <th>Disposición</th>
        <th style='text-align:right;'>Total</th>
        <th>Distribución</th>
        <th style='text-align:right;'>%</th>
        <th style='text-align:right;'>In/Out</th>
        <th style='text-align:right;'>Duración prom.</th>
        <th style='text-align:right;'>Grabadas</th>
      </tr></thead>
      <tbody>{$dispRows}</tbody>
    </table>
  </div>

  <div class="card">
    <h2>Top {$reportData['top_agents_limit']} agentes (volumen + calidad)</h2>
    <p class="muted" style="margin:-8px 0 12px 0;">Agregado a través de todas las integraciones. Cada agente queda etiquetado con su integración de origen.</p>
    <table>
      <thead><tr>
        <th>Agente</th>
        <th>Integración</th>
        <th style='text-align:right;'>Llamadas</th>
        <th style='text-align:right;'>Disposicionadas</th>
        <th style='text-align:right;'>% disp.</th>
        <th style='text-align:right;'>Calidad</th>
        <th style='text-align:right;'>Grab. %</th>
        <th style='text-align:right;'>Trans. %</th>
        <th style='text-align:right;'>Duración prom.</th>
      </tr></thead>
      <tbody>{$agentRows}</tbody>
    </table>
  </div>
HTML
            . (!empty($qIssues)
                ? "<div class='card'><h2>⚠️ Agentes con calidad bajo umbral ({$reportData['thresholds']['quality_score']})</h2><table><thead><tr><th>Agente</th><th>Integración</th><th style='text-align:right;'>Llamadas</th><th style='text-align:right;'>Calidad</th><th style='text-align:right;'>Grab. %</th><th style='text-align:right;'>Trans. %</th><th style='text-align:right;'>Resumen %</th></tr></thead><tbody>{$qRows}</tbody></table></div>"
                : "")
            . (!empty($channels)
                ? "<div class='card'><h2>Distribución por canal</h2><table><thead><tr><th>Canal</th><th style='text-align:right;'>Llamadas</th><th>Volumen</th><th>Top disposiciones</th></tr></thead><tbody>{$chRows}</tbody></table></div>"
                : "")
            . (!empty($buckets)
                ? "<div class='card'><h2>Distribución por duración</h2><table><thead><tr><th>Rango</th><th>Volumen</th><th style='text-align:right;'>Llamadas</th></tr></thead><tbody>{$bucketRows}</tbody></table></div>"
                : "")
            . ($totalSent > 0
                ? "<div class='card'><h2>Sentimiento ({$totals['with_sentiment']} llamadas analizadas)</h2><table><thead><tr><th>Sentimiento</th><th style='text-align:right;'>Llamadas</th><th style='text-align:right;'>%</th></tr></thead><tbody>{$sentRows}</tbody></table></div>"
                : "")
            . "<div class='footer'><p><strong>Reporte generado automáticamente</strong></p>"
            . "<p>{$reportData['generated_at']} — Datos en vivo de GHL Voice AI · "
            . "Páginas: {$reportData['pagination']['pages_fetched']}/{$reportData['pagination']['max_pages']} · "
            . "Sistema Ponche Xtreme</p></div>"
            . "</div></body></html>";
    }
}

// -------------------------------------------------------------
// Email send
// -------------------------------------------------------------

if (!function_exists('sendGhlReportByEmail')) {
    function sendGhlReportByEmail(PDO $pdo, array $reportData, array $recipients, string $aiSummary = ''): bool
    {
        if (empty($recipients)) {
            error_log('[ghl_report] No recipients configured');
            return false;
        }
        $html = generateGhlReportHTML($reportData, $aiSummary);
        require_once __DIR__ . '/email_functions.php';

        $result = sendDailyGhlReport($html, $recipients, $reportData);

        if ($result['success']) {
            error_log('[ghl_report] Sent: ' . $result['message']);
            return true;
        }
        error_log('[ghl_report] Failed: ' . $result['message']);
        return false;
    }
}
