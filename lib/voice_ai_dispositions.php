<?php
/**
 * Voice AI · Unified Dispositions Report
 *
 * Cruza todas las fuentes disponibles en la API de GHL para devolver un
 * desglose completo de disposiciones de llamadas:
 *   - /conversations/messages/export   → disposiciones manuales, callStatus
 *   - /voice-ai/dashboard/call-logs    → disposiciones de Voice AI + sentiment
 *   - callStatus / duration / recording → métricas derivadas
 *
 * Salida:
 *   [
 *     'summary' => [...],          // KPIs
 *     'stats' => [...],            // Por disposición: totales, inbound, outbound,
 *                                  //   duration media/total, % grabadas, % con transcript,
 *                                  //   usuarios únicos, contactos únicos.
 *     'by_user' => [...],          // Usuario × disposición (matriz)
 *     'by_direction' => [...],     // Inbound vs Outbound por disposición
 *     'by_hour' => [...],          // 0..23 × disposición
 *     'by_weekday' => [...],       // Dom..Sáb × disposición
 *     'timeline' => [...],         // Día × disposición
 *     'duration_buckets' => [...], // 0-30s, 31-2m, 2-5m, 5-15m, 15m+ por disposición
 *     'status_map' => [...],       // callStatus × disposición
 *     'no_disposition' => [...],   // Análisis de llamadas sin disposición
 *     'sources' => [...],          // De dónde salió cada dato
 *     'meta' => [...],
 *   ]
 */

if (!function_exists('voiceAiNormalizeDispositionLabel')) {
    function voiceAiNormalizeDispositionLabel(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || strcasecmp($raw, 'unknown') === 0 || strcasecmp($raw, 'null') === 0) {
            return 'Sin disposicion';
        }
        // Title-case single-word keys (completed → Completed) to avoid
        // fragmenting the same disposition under different casings.
        if (preg_match('/^[a-zA-Z_]+$/', $raw)) {
            return ucwords(strtolower(str_replace('_', ' ', $raw)));
        }
        return $raw;
    }
}

if (!function_exists('voiceAiClassifyDispositionOutcome')) {
    /**
     * Clasifica una disposición en una categoría de alto nivel. Sirve
     * para construir KPIs como win-rate / miss-rate sin depender del
     * nombre exacto de disposición que configuró cada cuenta.
     */
    function voiceAiClassifyDispositionOutcome(string $label, string $callStatus = ''): string
    {
        $l = strtolower($label . ' ' . $callStatus);
        if ($l === '' || strpos($l, 'sin dispos') !== false) return 'unknown';
        foreach (['sale', 'won', 'closed', 'success', 'complet', 'positive', 'booked', 'venta', 'ganad', 'exitos'] as $needle) {
            if (strpos($l, $needle) !== false) return 'positive';
        }
        foreach (['lost', 'perdid', 'negative', 'no interest', 'not interest', 'rechaz'] as $needle) {
            if (strpos($l, $needle) !== false) return 'negative';
        }
        foreach (['no answer', 'no-answer', 'noanswer', 'missed', 'busy', 'failed', 'voicemail', 'buzon'] as $needle) {
            if (strpos($l, $needle) !== false) return 'unreachable';
        }
        foreach (['callback', 'follow', 'seguim', 'volver', 'later', 'transfer'] as $needle) {
            if (strpos($l, $needle) !== false) return 'pending';
        }
        return 'other';
    }
}

if (!function_exists('voiceAiFetchUnifiedDispositions')) {
    function voiceAiFetchUnifiedDispositions(PDO $pdo, array $filters = []): array
    {
        $start = microtime(true);
        $config = voiceAiGetConfig($pdo);
        $status = voiceAiGetConfigStatus($pdo);
        if (!$status['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion de GHL incompleta.'];
        }

        // --------------------------------------------------------------
        // Fuente 1: /conversations/messages/export (todas las cuentas)
        // --------------------------------------------------------------
        $messageFilters = $filters;
        $messageFilters['interaction_channel'] = 'Call';
        $interactions = voiceAiFetchInteractions($pdo, $messageFilters);
        $callItems = [];
        $warnings = [];
        if (!empty($interactions['success'])) {
            foreach ($interactions['items'] ?? [] as $it) {
                if (!is_array($it)) continue;
                if (empty($it['is_call'])) continue;
                $callItems[] = $it;
            }
        } else {
            $warnings[] = 'conversations/messages/export: ' . ($interactions['message'] ?? 'desconocido');
        }

        // --------------------------------------------------------------
        // Fuente 2: /voice-ai/dashboard/call-logs (solo cuentas con Voice AI)
        // --------------------------------------------------------------
        $voiceAiCalls = [];
        $voiceAiResult = voiceAiFetchCalls($pdo, $filters);
        if (!empty($voiceAiResult['success'])) {
            foreach (($voiceAiResult['calls'] ?? []) as $c) {
                if (!is_array($c)) continue;
                $voiceAiCalls[$c['id'] ?? ''] = $c;
            }
        } else {
            $warnings[] = 'voice-ai/dashboard/call-logs: ' . ($voiceAiResult['message'] ?? 'plan no incluye Voice AI');
        }

        // Index Voice AI calls by the altId → conversations.id cross-ref.
        $voiceAiByAlt = [];
        foreach ($voiceAiCalls as $vc) {
            $alt = trim((string) ($vc['alt_id'] ?? ($vc['conversation_message_id'] ?? '')));
            if ($alt !== '') $voiceAiByAlt[$alt] = $vc;
        }

        // --------------------------------------------------------------
        // Unificación: iteramos mensajes-call, enriquecemos con Voice AI.
        // --------------------------------------------------------------
        $stats = [];             // disposicion → agregados
        $byUser = [];            // userId → { user_name, total_calls, dispositions: {name→count}, outcome_counts }
        $byDirection = [];       // inbound|outbound → { disposition → count }
        $byHour = array_fill(0, 24, []); // 0..23 → { disposition → count }
        $byWeekday = [];         // Sun..Sat → { disposition → count }
        $timeline = [];          // YYYY-MM-DD → { disposition → count }
        $durationBuckets = [];   // disposition → { '0-30s', '31-120s', '2-5m', '5-15m', '15m+' }
        $statusMap = [];         // callStatus → { disposition → count }
        $contactByDisp = [];     // disposition → contactIds unique
        $userByDisp = [];        // disposition → userIds unique
        $noDispCalls = [];       // muestra de sin-disposicion
        $outcomeCounts = ['positive' => 0, 'negative' => 0, 'pending' => 0, 'unreachable' => 0, 'other' => 0, 'unknown' => 0];
        $totalCalls = 0;
        $totalDuration = 0;
        $recordedCount = 0;
        $transcriptCount = 0;
        $sentimentBuckets = ['positive' => 0, 'neutral' => 0, 'negative' => 0];

        $bucketOf = function (int $sec): string {
            if ($sec <= 30) return '0-30s';
            if ($sec <= 120) return '31-120s';
            if ($sec <= 300) return '2-5m';
            if ($sec <= 900) return '5-15m';
            return '15m+';
        };

        foreach ($callItems as $item) {
            $rawLabel = trim((string) ($item['call_disposition'] ?? ''));
            $callStatus = trim((string) ($item['status'] ?? ''));
            $disposition = voiceAiNormalizeDispositionLabel($rawLabel !== '' ? $rawLabel : $callStatus);
            $direction = strtolower((string) ($item['direction'] ?? 'unknown'));
            $userId = (string) ($item['user_id'] ?? '');
            $userName = (string) ($item['user_name'] ?? ($userId ?: 'Sin usuario'));
            $contactId = (string) ($item['contact_id'] ?? ($item['counterparty_phone'] ?? ''));
            $duration = (int) ($item['duration_seconds'] ?? 0);
            $hasRecording = !empty($item['has_recording']);
            $ts = (int) ($item['timestamp'] ?? 0);

            // Cross-ref with Voice AI when id/altId matches.
            $crossRef = $voiceAiByAlt[$item['id'] ?? ''] ?? ($voiceAiByAlt[$item['alt_id'] ?? ''] ?? null);
            $hasTranscript = !empty($crossRef['has_transcript']) || !empty($item['has_transcript']);
            $sentiment = strtolower(trim((string) ($crossRef['sentiment'] ?? '')));

            $totalCalls++;
            $totalDuration += $duration;
            if ($hasRecording) $recordedCount++;
            if ($hasTranscript) $transcriptCount++;
            if (isset($sentimentBuckets[$sentiment])) $sentimentBuckets[$sentiment]++;

            $outcome = voiceAiClassifyDispositionOutcome($disposition, $callStatus);
            $outcomeCounts[$outcome] = ($outcomeCounts[$outcome] ?? 0) + 1;

            // stats[disposition]
            if (!isset($stats[$disposition])) {
                $stats[$disposition] = [
                    'disposition' => $disposition,
                    'outcome' => $outcome,
                    'total' => 0,
                    'inbound' => 0,
                    'outbound' => 0,
                    'unknown_direction' => 0,
                    'total_duration_seconds' => 0,
                    'avg_duration_seconds' => 0,
                    'recorded_calls' => 0,
                    'transcribed_calls' => 0,
                    'users' => [],
                    'contacts' => [],
                    'with_voice_ai' => 0,
                    'first_seen' => $ts ?: null,
                    'last_seen' => $ts ?: null,
                ];
            }
            $stats[$disposition]['total']++;
            $stats[$disposition]['total_duration_seconds'] += $duration;
            if ($hasRecording) $stats[$disposition]['recorded_calls']++;
            if ($hasTranscript) $stats[$disposition]['transcribed_calls']++;
            if ($crossRef) $stats[$disposition]['with_voice_ai']++;
            if ($direction === 'inbound') $stats[$disposition]['inbound']++;
            elseif ($direction === 'outbound') $stats[$disposition]['outbound']++;
            else $stats[$disposition]['unknown_direction']++;
            if ($userId) $stats[$disposition]['users'][$userId] = true;
            if ($contactId) $stats[$disposition]['contacts'][$contactId] = true;
            if ($ts) {
                if (!$stats[$disposition]['first_seen'] || $ts < $stats[$disposition]['first_seen']) {
                    $stats[$disposition]['first_seen'] = $ts;
                }
                if ($ts > $stats[$disposition]['last_seen']) {
                    $stats[$disposition]['last_seen'] = $ts;
                }
            }

            // byUser[userId]
            $userKey = $userId ?: ('noid-' . $userName);
            if (!isset($byUser[$userKey])) {
                $byUser[$userKey] = [
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'total_calls' => 0,
                    'total_duration_seconds' => 0,
                    'dispositions' => [],
                    'outcomes' => ['positive' => 0, 'negative' => 0, 'pending' => 0, 'unreachable' => 0, 'other' => 0, 'unknown' => 0],
                    'top_disposition' => '',
                    'top_disposition_count' => 0,
                ];
            }
            $byUser[$userKey]['total_calls']++;
            $byUser[$userKey]['total_duration_seconds'] += $duration;
            $byUser[$userKey]['dispositions'][$disposition] = ($byUser[$userKey]['dispositions'][$disposition] ?? 0) + 1;
            $byUser[$userKey]['outcomes'][$outcome] = ($byUser[$userKey]['outcomes'][$outcome] ?? 0) + 1;

            // byDirection
            $dirKey = in_array($direction, ['inbound', 'outbound'], true) ? $direction : 'unknown';
            $byDirection[$dirKey][$disposition] = ($byDirection[$dirKey][$disposition] ?? 0) + 1;

            // byHour
            if ($ts) {
                $h = (int) date('G', $ts);
                $byHour[$h][$disposition] = ($byHour[$h][$disposition] ?? 0) + 1;

                $wd = date('D', $ts);
                $byWeekday[$wd][$disposition] = ($byWeekday[$wd][$disposition] ?? 0) + 1;

                $day = date('Y-m-d', $ts);
                $timeline[$day][$disposition] = ($timeline[$day][$disposition] ?? 0) + 1;
            }

            // durationBuckets
            $bucket = $bucketOf($duration);
            if (!isset($durationBuckets[$disposition])) {
                $durationBuckets[$disposition] = ['0-30s' => 0, '31-120s' => 0, '2-5m' => 0, '5-15m' => 0, '15m+' => 0];
            }
            $durationBuckets[$disposition][$bucket]++;

            // statusMap
            $statusKey = $callStatus !== '' ? $callStatus : 'unknown';
            $statusMap[$statusKey][$disposition] = ($statusMap[$statusKey][$disposition] ?? 0) + 1;

            if (strpos(strtolower($disposition), 'sin dispos') !== false && count($noDispCalls) < 25) {
                $noDispCalls[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'contact_name' => (string) ($item['contact_name'] ?? ''),
                    'contact_phone' => (string) ($item['contact_phone'] ?? $item['counterparty_phone'] ?? ''),
                    'user_name' => $userName,
                    'direction' => $direction,
                    'status' => $callStatus,
                    'duration_seconds' => $duration,
                    'date' => $ts ? date('c', $ts) : '',
                ];
            }
        }

        // --------------------------------------------------------------
        // Post-procesamiento: promedios, top por usuario, orden.
        // --------------------------------------------------------------
        foreach ($stats as &$s) {
            $s['users_unique'] = count($s['users']);
            $s['contacts_unique'] = count($s['contacts']);
            unset($s['users'], $s['contacts']);
            $s['avg_duration_seconds'] = $s['total'] > 0 ? (int) round($s['total_duration_seconds'] / $s['total']) : 0;
            $s['recording_pct'] = $s['total'] > 0 ? round(($s['recorded_calls'] / $s['total']) * 100, 1) : 0.0;
            $s['transcript_pct'] = $s['total'] > 0 ? round(($s['transcribed_calls'] / $s['total']) * 100, 1) : 0.0;
            $s['first_seen'] = $s['first_seen'] ? date('c', $s['first_seen']) : null;
            $s['last_seen'] = $s['last_seen'] ? date('c', $s['last_seen']) : null;
            $s['share_pct'] = $totalCalls > 0 ? round(($s['total'] / $totalCalls) * 100, 1) : 0.0;
        }
        unset($s);

        uasort($stats, fn($a, $b) => $b['total'] <=> $a['total']);

        foreach ($byUser as &$u) {
            $top = '';
            $topCount = 0;
            foreach ($u['dispositions'] as $name => $count) {
                if ($count > $topCount) {
                    $topCount = $count;
                    $top = $name;
                }
            }
            $u['top_disposition'] = $top;
            $u['top_disposition_count'] = $topCount;
            $u['avg_duration_seconds'] = $u['total_calls'] > 0 ? (int) round($u['total_duration_seconds'] / $u['total_calls']) : 0;
            $u['positive_rate_pct'] = $u['total_calls'] > 0
                ? round((($u['outcomes']['positive'] ?? 0) / $u['total_calls']) * 100, 1) : 0.0;
        }
        unset($u);
        uasort($byUser, fn($a, $b) => $b['total_calls'] <=> $a['total_calls']);

        ksort($timeline);
        $timelineRows = [];
        foreach ($timeline as $day => $disps) {
            $row = ['date' => $day, 'total' => array_sum($disps)];
            foreach ($disps as $name => $count) $row[$name] = $count;
            $timelineRows[] = $row;
        }

        // Hour array needs to be flattened for consumers.
        $hourRows = [];
        for ($h = 0; $h < 24; $h++) {
            $disps = $byHour[$h];
            $total = is_array($disps) ? array_sum($disps) : 0;
            $hourRows[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'total' => $total,
                'dispositions' => is_array($disps) ? $disps : [],
            ];
        }

        $weekdayOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $weekdayRows = [];
        foreach ($weekdayOrder as $wd) {
            $disps = $byWeekday[$wd] ?? [];
            $weekdayRows[] = [
                'weekday' => $wd,
                'total' => array_sum($disps),
                'dispositions' => $disps,
            ];
        }

        $summary = [
            'total_calls' => $totalCalls,
            'total_duration_seconds' => $totalDuration,
            'avg_duration_seconds' => $totalCalls > 0 ? (int) round($totalDuration / $totalCalls) : 0,
            'recorded_calls' => $recordedCount,
            'recording_coverage_pct' => $totalCalls > 0 ? round(($recordedCount / $totalCalls) * 100, 1) : 0.0,
            'transcribed_calls' => $transcriptCount,
            'transcript_coverage_pct' => $totalCalls > 0 ? round(($transcriptCount / $totalCalls) * 100, 1) : 0.0,
            'dispositions_unique' => count($stats),
            'top_disposition' => (function () use ($stats) {
                foreach ($stats as $s) return $s['disposition'];
                return null;
            })(),
            'top_disposition_share_pct' => (function () use ($stats) {
                foreach ($stats as $s) return $s['share_pct'];
                return 0;
            })(),
            'no_disposition_count' => (int) ($stats['Sin disposicion']['total'] ?? 0),
            'no_disposition_pct' => $totalCalls > 0 && isset($stats['Sin disposicion'])
                ? round(($stats['Sin disposicion']['total'] / $totalCalls) * 100, 1) : 0.0,
            'outcome_counts' => $outcomeCounts,
            'outcome_pct' => (function () use ($outcomeCounts, $totalCalls) {
                $out = [];
                foreach ($outcomeCounts as $k => $v) {
                    $out[$k] = $totalCalls > 0 ? round(($v / $totalCalls) * 100, 1) : 0.0;
                }
                return $out;
            })(),
            'sentiment_counts' => $sentimentBuckets,
            'sentiment_coverage_pct' => $totalCalls > 0
                ? round((array_sum($sentimentBuckets) / $totalCalls) * 100, 1) : 0.0,
        ];

        return [
            'success' => true,
            'generated_at' => date('c'),
            'summary' => $summary,
            'stats' => array_values($stats),
            'by_user' => array_values($byUser),
            'by_direction' => $byDirection,
            'by_hour' => $hourRows,
            'by_weekday' => $weekdayRows,
            'timeline' => $timelineRows,
            'duration_buckets' => $durationBuckets,
            'status_map' => $statusMap,
            'no_disposition_sample' => $noDispCalls,
            'sources' => [
                'conversations_messages_export' => count($callItems),
                'voice_ai_dashboard_call_logs' => count($voiceAiCalls),
                'cross_referenced' => array_sum(array_map(fn($s) => $s['with_voice_ai'], $stats)),
            ],
            'warnings' => $warnings,
            'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }
}
