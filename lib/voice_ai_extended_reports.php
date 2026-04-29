<?php
/**
 * Extended Voice AI Reports - Funciones avanzadas para reporteria completa de GHL
 * Expande significativamente la extraccion de datos de la API con enfoque en disposiciones
 * y análisis detallado de interacciones
 */

if (!function_exists('voiceAiFetchDispositionAnalytics')) {
    /**
     * Obtiene análisis completo de disposiciones con detalles por agente, canal y período
     */
    function voiceAiFetchDispositionAnalytics(PDO $pdo, array $filters = []): array
    {
        $integrationId = $filters['integration_id'] ?? null;
        $config = voiceAiGetConfig($pdo, $integrationId);
        $configStatus = voiceAiGetConfigStatus($pdo, $integrationId);

        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'Configuracion de GHL incompleta.',
            ];
        }

        // Obtener todas las llamadas del rango especificado
        $maxPages = isset($filters['max_pages']) ? (int) $filters['max_pages'] : $config['max_pages'];
        $calls = [];
        $pagesFetched = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = voiceAiHttpRequest(
                $config,
                'GET',
                '/voice-ai/dashboard/call-logs',
                voiceAiBuildListQuery($config, $filters, $page)
            );

            if (!$response['success']) {
                break;
            }

            $items = voiceAiExtractCallItems($response['data']);
            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    $calls[] = voiceAiNormalizeCall($item);
                }
            }

            $pagesFetched++;
            if (count($items) < ($filters['page_size'] ?? $config['page_size'])) {
                break;
            }
        }

        // Análisis de disposiciones
        $dispositionStats = [];
        $dispositionByAgent = [];
        $dispositionByChannel = [];
        $dispositionByUser = [];
        $dispositionTimeline = [];
        $dispositionConversion = [];

        foreach ($calls as $call) {
            $disposition = trim((string) ($call['disposition'] ?? ''));
            if ($disposition === '') {
                $disposition = 'Sin disposición';
            }

            $agentName = $call['agent_name'] ?? 'Sin agente';
            $userId = $call['agent_id'] ?? '';
            $callType = $call['call_type'] ?? 'Unknown';
            $duration = (int) ($call['duration_seconds'] ?? 0);
            $status = $call['status'] ?? 'Unknown';
            $timestamp = $call['started_at_ts'] ?? null;

            // Estadísticas generales de disposición
            if (!isset($dispositionStats[$disposition])) {
                $dispositionStats[$disposition] = [
                    'disposition' => $disposition,
                    'total' => 0,
                    'inbound' => 0,
                    'outbound' => 0,
                    'avg_duration_seconds' => 0,
                    'total_duration_seconds' => 0,
                    'recorded_calls' => 0,
                    'users' => [],
                    'statuses' => [],
                    'contact_count' => 0,
                ];
            }
            $dispositionStats[$disposition]['total']++;
            if (stripos($callType, 'inbound') !== false || stripos($callType, 'in') === 0) {
                $dispositionStats[$disposition]['inbound']++;
            } else {
                $dispositionStats[$disposition]['outbound']++;
            }
            $dispositionStats[$disposition]['total_duration_seconds'] += $duration;
            if (!empty($call['recording_url'])) {
                $dispositionStats[$disposition]['recorded_calls']++;
            }
            $dispositionStats[$disposition]['users'][$userId] = true;
            $dispositionStats[$disposition]['statuses'][$status] = ($dispositionStats[$disposition]['statuses'][$status] ?? 0) + 1;

            // Disposición por agente
            $agentKey = $userId !== '' ? $userId : $agentName;
            if (!isset($dispositionByAgent[$agentKey])) {
                $dispositionByAgent[$agentKey] = [
                    'agent_id' => $userId,
                    'agent_name' => $agentName,
                    'dispositions' => [],
                    'total_calls' => 0,
                    'total_handled' => 0,
                ];
            }
            if (!isset($dispositionByAgent[$agentKey]['dispositions'][$disposition])) {
                $dispositionByAgent[$agentKey]['dispositions'][$disposition] = 0;
            }
            $dispositionByAgent[$agentKey]['dispositions'][$disposition]++;
            $dispositionByAgent[$agentKey]['total_calls']++;
            if ($disposition !== 'Sin disposición' && $disposition !== '') {
                $dispositionByAgent[$agentKey]['total_handled']++;
            }

            // Disposición por canal
            if (!isset($dispositionByChannel[$callType])) {
                $dispositionByChannel[$callType] = [
                    'channel' => $callType,
                    'dispositions' => [],
                    'total_calls' => 0,
                ];
            }
            if (!isset($dispositionByChannel[$callType]['dispositions'][$disposition])) {
                $dispositionByChannel[$callType]['dispositions'][$disposition] = 0;
            }
            $dispositionByChannel[$callType]['dispositions'][$disposition]++;
            $dispositionByChannel[$callType]['total_calls']++;

            // Disposición por usuario
            if (!isset($dispositionByUser[$userId])) {
                $dispositionByUser[$userId] = [
                    'user_id' => $userId,
                    'user_name' => $agentName,
                    'dispositions' => [],
                    'top_disposition' => '',
                    'top_disposition_calls' => 0,
                    'total_calls' => 0,
                ];
            }
            if (!isset($dispositionByUser[$userId]['dispositions'][$disposition])) {
                $dispositionByUser[$userId]['dispositions'][$disposition] = 0;
            }
            $dispositionByUser[$userId]['dispositions'][$disposition]++;
            $dispositionByUser[$userId]['total_calls']++;

            // Timeline de disposiciones
            if ($timestamp) {
                $dayKey = date('Y-m-d', $timestamp);
                if (!isset($dispositionTimeline[$dayKey])) {
                    $dispositionTimeline[$dayKey] = [];
                }
                if (!isset($dispositionTimeline[$dayKey][$disposition])) {
                    $dispositionTimeline[$dayKey][$disposition] = 0;
                }
                $dispositionTimeline[$dayKey][$disposition]++;
            }
        }

        // Calcular promedios y top disposiciones
        foreach ($dispositionStats as &$stat) {
            $stat['users'] = count($stat['users']);
            $stat['avg_duration_seconds'] = $stat['total'] > 0 ? (int) round($stat['total_duration_seconds'] / $stat['total']) : 0;
        }
        unset($stat);

        foreach ($dispositionByUser as &$user) {
            arsort($user['dispositions']);
            $top = reset($user['dispositions']);
            $topKey = key($user['dispositions']);
            $user['top_disposition'] = $topKey ?: '';
            $user['top_disposition_calls'] = $top ?: 0;
            unset($user['dispositions']);
        }
        unset($user);

        // Ordenar
        arsort($dispositionStats);
        uasort($dispositionByAgent, function($a, $b) {
            return $b['total_calls'] <=> $a['total_calls'];
        });

        return [
            'success' => true,
            'disposition_stats' => array_values($dispositionStats),
            'disposition_by_agent' => array_values($dispositionByAgent),
            'disposition_by_channel' => array_values($dispositionByChannel),
            'disposition_by_user' => array_values($dispositionByUser),
            'disposition_timeline' => $dispositionTimeline,
            'meta' => [
                'pages_fetched' => $pagesFetched,
                'total_calls_analyzed' => count($calls),
                'unique_dispositions' => count($dispositionStats),
            ],
        ];
    }
}

if (!function_exists('voiceAiFetchCallQualityMetrics')) {
    /**
     * Obtiene métricas de calidad de llamadas (duración, grabaciones, transcripciones, sentimiento)
     */
    function voiceAiFetchCallQualityMetrics(PDO $pdo, array $filters = []): array
    {
        $integrationId = $filters['integration_id'] ?? null;
        $config = voiceAiGetConfig($pdo, $integrationId);
        $configStatus = voiceAiGetConfigStatus($pdo, $integrationId);

        if (!$configStatus['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.'];
        }

        $calls = [];
        $maxPages = isset($filters['max_pages']) ? (int) $filters['max_pages'] : $config['max_pages'];

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = voiceAiHttpRequest(
                $config,
                'GET',
                '/voice-ai/dashboard/call-logs',
                voiceAiBuildListQuery($config, $filters, $page)
            );

            if (!$response['success']) break;

            $items = voiceAiExtractCallItems($response['data']);
            if (empty($items)) break;

            foreach ($items as $item) {
                if (is_array($item)) {
                    $calls[] = voiceAiNormalizeCall($item);
                }
            }

            if (count($items) < ($filters['page_size'] ?? $config['page_size'])) break;
        }

        // Análisis de calidad
        $qualityMetrics = [
            'total_calls' => count($calls),
            'with_transcript' => 0,
            'with_summary' => 0,
            'with_recording' => 0,
            'with_sentiment' => 0,
            'with_actions' => 0,
            'avg_duration' => 0,
            'min_duration' => null,
            'max_duration' => null,
            'duration_distribution' => [],
            'sentiment_distribution' => [],
            'recording_coverage_pct' => 0.0,
            'transcript_coverage_pct' => 0.0,
            'summary_coverage_pct' => 0.0,
            'calls_by_duration_range' => [
                '0-30s' => 0,
                '31-120s' => 0,
                '2-5m' => 0,
                '5-15m' => 0,
                '15m+' => 0,
            ],
            'agent_quality' => [],
            'quality_scores_by_agent' => [],
        ];

        $totalDuration = 0;

        foreach ($calls as $call) {
            $duration = (int) ($call['duration_seconds'] ?? 0);
            $totalDuration += $duration;

            if (!empty($call['has_transcript'])) {
                $qualityMetrics['with_transcript']++;
            }
            if (!empty($call['has_summary'])) {
                $qualityMetrics['with_summary']++;
            }
            if (!empty($call['recording_url'])) {
                $qualityMetrics['with_recording']++;
            }
            if (!empty($call['sentiment'])) {
                $qualityMetrics['with_sentiment']++;
                $sentiment = $call['sentiment'];
                $qualityMetrics['sentiment_distribution'][$sentiment] = ($qualityMetrics['sentiment_distribution'][$sentiment] ?? 0) + 1;
            }
            if (!empty($call['action_types'])) {
                $qualityMetrics['with_actions']++;
            }

            // Distribución por duración
            if ($duration <= 30) {
                $qualityMetrics['calls_by_duration_range']['0-30s']++;
            } elseif ($duration <= 120) {
                $qualityMetrics['calls_by_duration_range']['31-120s']++;
            } elseif ($duration <= 300) {
                $qualityMetrics['calls_by_duration_range']['2-5m']++;
            } elseif ($duration <= 900) {
                $qualityMetrics['calls_by_duration_range']['5-15m']++;
            } else {
                $qualityMetrics['calls_by_duration_range']['15m+']++;
            }

            // Calidad por agente
            $agentId = $call['agent_id'] ?? $call['agent_name'];
            if ($agentId) {
                if (!isset($qualityMetrics['agent_quality'][$agentId])) {
                    $qualityMetrics['agent_quality'][$agentId] = [
                        'agent_name' => $call['agent_name'],
                        'calls' => 0,
                        'calls_with_transcript' => 0,
                        'calls_with_summary' => 0,
                        'calls_with_recording' => 0,
                        'avg_call_duration' => 0,
                        'total_duration' => 0,
                    ];
                }

                $qualityMetrics['agent_quality'][$agentId]['calls']++;
                $qualityMetrics['agent_quality'][$agentId]['total_duration'] += $duration;
                if (!empty($call['has_transcript'])) {
                    $qualityMetrics['agent_quality'][$agentId]['calls_with_transcript']++;
                }
                if (!empty($call['has_summary'])) {
                    $qualityMetrics['agent_quality'][$agentId]['calls_with_summary']++;
                }
                if (!empty($call['recording_url'])) {
                    $qualityMetrics['agent_quality'][$agentId]['calls_with_recording']++;
                }
            }

            if ($qualityMetrics['min_duration'] === null || $duration < $qualityMetrics['min_duration']) {
                $qualityMetrics['min_duration'] = $duration;
            }
            if ($qualityMetrics['max_duration'] === null || $duration > $qualityMetrics['max_duration']) {
                $qualityMetrics['max_duration'] = $duration;
            }
        }

        if (count($calls) > 0) {
            $qualityMetrics['avg_duration'] = (int) round($totalDuration / count($calls));
            $qualityMetrics['recording_coverage_pct'] = round(($qualityMetrics['with_recording'] / count($calls)) * 100, 2);
            $qualityMetrics['transcript_coverage_pct'] = round(($qualityMetrics['with_transcript'] / count($calls)) * 100, 2);
            $qualityMetrics['summary_coverage_pct'] = round(($qualityMetrics['with_summary'] / count($calls)) * 100, 2);
        }

        // Calcular puntuaciones de calidad por agente
        foreach ($qualityMetrics['agent_quality'] as $agentId => &$agent) {
            $agent['avg_call_duration'] = $agent['calls'] > 0 ? (int) round($agent['total_duration'] / $agent['calls']) : 0;
            
            $score = 0;
            if ($agent['calls'] > 0) {
                $transcriptScore = ($agent['calls_with_transcript'] / $agent['calls']) * 25;
                $summaryScore = ($agent['calls_with_summary'] / $agent['calls']) * 25;
                $recordingScore = ($agent['calls_with_recording'] / $agent['calls']) * 25;
                $durationScore = 25; // Base score
                
                if ($agent['avg_call_duration'] > 180) {
                    $durationScore = 25;
                } elseif ($agent['avg_call_duration'] > 120) {
                    $durationScore = 20;
                } elseif ($agent['avg_call_duration'] > 60) {
                    $durationScore = 15;
                } else {
                    $durationScore = 5;
                }
                
                $score = $transcriptScore + $summaryScore + $recordingScore + $durationScore;
            }
            
            $qualityMetrics['quality_scores_by_agent'][$agentId] = [
                'agent_name' => $agent['agent_name'],
                'quality_score' => round($score, 1),
                'transcript_pct' => $agent['calls'] > 0 ? round(($agent['calls_with_transcript'] / $agent['calls']) * 100, 1) : 0,
                'summary_pct' => $agent['calls'] > 0 ? round(($agent['calls_with_summary'] / $agent['calls']) * 100, 1) : 0,
                'recording_pct' => $agent['calls'] > 0 ? round(($agent['calls_with_recording'] / $agent['calls']) * 100, 1) : 0,
            ];
        }
        unset($agent);

        return [
            'success' => true,
            'quality_metrics' => $qualityMetrics,
        ];
    }
}

if (!function_exists('voiceAiFetchInteractionTotals')) {
    /**
     * Obtiene totales completos de interacciones por canal
     */
    function voiceAiFetchInteractionTotals(PDO $pdo, array $filters = [], array $allItems = [], array $paginationMeta = []): array
    {
        $totals = [
            'by_channel' => [],
            'by_direction' => [],
            'by_status' => [],
            'by_source' => [],
            'total' => 0,
        ];

        $channels = ['Call', 'SMS', 'Email', 'WhatsApp'];
        $directions = ['Inbound', 'Outbound'];

        foreach ($channels as $channel) {
            $totals['by_channel'][$channel] = [
                'channel' => $channel,
                'total' => 0,
                'inbound' => 0,
                'outbound' => 0,
            ];
        }

        foreach ($directions as $direction) {
            $totals['by_direction'][$direction] = 0;
        }

        foreach ($allItems as $item) {
            $channel = (string) ($item['channel'] ?? '');
            $direction = (string) ($item['direction'] ?? '');
            $status = (string) ($item['status'] ?? '');
            $source = (string) ($item['source'] ?? '');

            $totals['total']++;
            $totals['by_direction'][$direction] = ($totals['by_direction'][$direction] ?? 0) + 1;
            $totals['by_status'][$status] = ($totals['by_status'][$status] ?? 0) + 1;
            $totals['by_source'][$source] = ($totals['by_source'][$source] ?? 0) + 1;

            if (isset($totals['by_channel'][$channel])) {
                $totals['by_channel'][$channel]['total']++;
                if ($direction === 'Inbound') {
                    $totals['by_channel'][$channel]['inbound']++;
                } elseif ($direction === 'Outbound') {
                    $totals['by_channel'][$channel]['outbound']++;
                }
            }
        }

        return [
            'success' => true,
            'totals' => $totals,
        ];
    }
}

if (!function_exists('voiceAiFetchInteractions')) {
    /**
     * Obtiene todas las interacciones (llamadas, mensajes, email) del location
     */
    function voiceAiFetchInteractions(PDO $pdo, array $filters = []): array
    {
        $integrationId = $filters['integration_id'] ?? null;
        $config = voiceAiGetConfig($pdo, $integrationId);
        $configStatus = voiceAiGetConfigStatus($pdo, $integrationId);

        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'Configuracion incompleta.',
                'config_status' => $configStatus,
            ];
        }

        $maxPages = isset($filters['interaction_max_pages']) ? (int) $filters['interaction_max_pages'] : $config['interaction_max_pages'];
        $allItems = [];
        $pagesFetched = 0;
        $paginationMeta = [];
        $warnings = [];

        $channels = ['Call', 'SMS', 'Email', 'WhatsApp'];
        
        foreach ($channels as $channel) {
            $cursor = null;
            for ($page = 1; $page <= min($maxPages, 3); $page++) {
                $response = voiceAiHttpRequest(
                    $config,
                    'GET',
                    '/conversations/messages/export',
                    voiceAiBuildMessageExportQuery($config, $filters, $channel, $cursor)
                );

                if (!$response['success']) {
                    $warnings[] = "No se pudo obtener mensajes de {$channel}: " . $response['message'];
                    break;
                }

                $data = $response['data'];
                $messages = $data['messages'] ?? [];
                
                if (!is_array($messages)) {
                    break;
                }

                foreach ($messages as $msg) {
                    if (is_array($msg)) {
                        $allItems[] = $msg;
                    }
                }

                $cursor = $data['nextCursor'] ?? null;
                if (empty($cursor)) {
                    break;
                }
            }
        }

        // Obtener usuarios
        $usersResult = voiceAiFetchUsers($pdo);
        $users = $usersResult['success'] ? ($usersResult['users'] ?? []) : [];
        $userMap = $usersResult['success'] ? ($usersResult['user_map'] ?? []) : [];

        // Obtener números
        $numbersResult = voiceAiFetchPhoneNumbers($pdo);
        $numbers = $numbersResult['success'] ? ($numbersResult['numbers'] ?? []) : [];
        $numberMap = $numbersResult['success'] ? ($numbersResult['number_map'] ?? []) : [];

        // Filtros disponibles
        $availableFilters = voiceAiBuildAvailableFiltersFromInteractions($allItems, $userMap);

        return [
            'success' => true,
            'items' => $allItems,
            'users' => $users,
            'numbers' => $numbers,
            'user_map' => $userMap,
            'number_map' => $numberMap,
            'available_filters' => $availableFilters,
            'config_status' => $configStatus,
            'meta' => [
                'fetched_count' => count($allItems),
                'users_total' => count($users),
                'numbers_total' => count($numbers),
                'warnings' => $warnings,
            ],
        ];
    }
}

if (!function_exists('voiceAiBuildAvailableFiltersFromInteractions')) {
    /**
     * Construye filtros disponibles desde las interacciones obtenidas
     */
    function voiceAiBuildAvailableFiltersFromInteractions(array $items, array $userMap = []): array
    {
        $channels = [];
        $directions = [];
        $statuses = [];
        $sources = [];
        $users = [];

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $channel = trim((string) ($item['channel'] ?? ''));
            $direction = trim((string) ($item['direction'] ?? ''));
            $status = trim((string) ($item['status'] ?? ''));
            $source = trim((string) ($item['source'] ?? ''));
            $userId = trim((string) ($item['userId'] ?? ''));

            if ($channel) $channels[$channel] = true;
            if ($direction) $directions[$direction] = true;
            if ($status) $statuses[$status] = true;
            if ($source) $sources[$source] = true;
            
            if ($userId && isset($userMap[$userId])) {
                $users[$userId] = [
                    'id' => $userMap[$userId]['id'],
                    'name' => $userMap[$userId]['name'],
                ];
            }
        }

        ksort($channels);
        ksort($directions);
        ksort($statuses);
        ksort($sources);
        uasort($users, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return [
            'interaction_channels' => array_keys($channels),
            'interaction_directions' => array_keys($directions),
            'interaction_statuses' => array_keys($statuses),
            'interaction_sources' => array_keys($sources),
            'interaction_users' => array_values($users),
        ];
    }
}

if (!function_exists('voiceAiBuildInteractionsDashboard')) {
    /**
     * Construye un dashboard completo desde las interacciones
     */
    function voiceAiBuildInteractionsDashboard(
        array $items,
        array $users = [],
        array $assignmentTotals = [],
        array $numbers = [],
        array $interactionTotals = []
    ): array {
        $dashboard = [
            'kpis' => [],
            'distributions' => [
                'channels' => [],
                'directions' => [],
                'statuses' => [],
                'sources' => [],
            ],
            'timeline' => [
                'by_day' => [],
                'by_hour' => array_fill(0, 24, 0),
            ],
            'users' => [],
            'contacts' => [],
            'recent_calls' => [],
            'recent_messages' => [],
            'recent_interactions' => [],
            'queue_by_user' => [],
            'numbers' => $numbers,
            'summary' => [
                'total_interactions' => count($items),
                'unique_users' => count($users),
                'unique_numbers' => count($numbers),
            ],
        ];

        $channels = [];
        $directions = [];
        $statuses = [];
        $sources = [];
        $byUser = [];
        $byContact = [];
        $recentCalls = [];
        $recentMessages = [];

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $channel = (string) ($item['channel'] ?? '');
            $direction = (string) ($item['direction'] ?? '');
            $status = (string) ($item['status'] ?? '');
            $source = (string) ($item['source'] ?? '');
            $userId = (string) ($item['userId'] ?? '');
            $contactId = (string) ($item['contactId'] ?? '');
            $timestamp = voiceAiToTimestamp($item['createdAt'] ?? null);

            if ($channel) $channels[$channel] = ($channels[$channel] ?? 0) + 1;
            if ($direction) $directions[$direction] = ($directions[$direction] ?? 0) + 1;
            if ($status) $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            if ($source) $sources[$source] = ($sources[$source] ?? 0) + 1;

            if ($userId) {
                if (!isset($byUser[$userId])) {
                    $byUser[$userId] = [
                        'user_id' => $userId,
                        'user_name' => '',
                        'interactions' => 0,
                        'calls' => 0,
                        'messages' => 0,
                    ];
                }
                $byUser[$userId]['interactions']++;
                if ($channel === 'Call') $byUser[$userId]['calls']++;
                else $byUser[$userId]['messages']++;
            }

            if ($contactId) {
                if (!isset($byContact[$contactId])) {
                    $byContact[$contactId] = [
                        'contact_id' => $contactId,
                        'contact_name' => (string) ($item['contactName'] ?? 'Sin nombre'),
                        'interactions' => 0,
                        'calls' => 0,
                        'last_activity_at' => null,
                    ];
                }
                $byContact[$contactId]['interactions']++;
                if ($channel === 'Call') $byContact[$contactId]['calls']++;
                if ($timestamp) $byContact[$contactId]['last_activity_at'] = date('c', $timestamp);
            }

            if ($channel === 'Call') {
                $recentCalls[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'date_added' => (string) ($item['createdAt'] ?? ''),
                    'contact_name' => (string) ($item['contactName'] ?? ''),
                    'contact_phone' => (string) ($item['contactPhone'] ?? ''),
                    'user_name' => (string) ($item['userName'] ?? ''),
                    'status' => $status,
                    'direction' => $direction,
                    'duration_seconds' => (int) ($item['duration'] ?? 0),
                ];
            } else {
                $recentMessages[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'date_added' => (string) ($item['createdAt'] ?? ''),
                    'contact_name' => (string) ($item['contactName'] ?? ''),
                    'channel' => $channel,
                    'direction' => $direction,
                    'preview' => substr((string) ($item['body'] ?? ''), 0, 100),
                ];
            }

            if ($timestamp) {
                $dayKey = date('Y-m-d', $timestamp);
                $dashboard['timeline']['by_day'][$dayKey] = ($dashboard['timeline']['by_day'][$dayKey] ?? 0) + 1;
                $hour = (int) date('G', $timestamp);
                $dashboard['timeline']['by_hour'][$hour]++;
            }
        }

        arsort($channels);
        arsort($directions);
        arsort($statuses);
        arsort($sources);
        
        usort($recentCalls, function($a, $b) {
            return strtotime($b['date_added']) <=> strtotime($a['date_added']);
        });
        usort($recentMessages, function($a, $b) {
            return strtotime($b['date_added']) <=> strtotime($a['date_added']);
        });
        usort($byContact, function($a, $b) {
            return $b['interactions'] <=> $a['interactions'];
        });

        $dashboard['distributions']['channels'] = $channels;
        $dashboard['distributions']['directions'] = $directions;
        $dashboard['distributions']['statuses'] = $statuses;
        $dashboard['distributions']['sources'] = $sources;
        $dashboard['recent_calls'] = array_slice($recentCalls, 0, 50);
        $dashboard['recent_messages'] = array_slice($recentMessages, 0, 50);
        $dashboard['recent_interactions'] = array_merge(
            array_slice($recentCalls, 0, 25),
            array_slice($recentMessages, 0, 25)
        );
        $dashboard['contacts'] = array_slice($byContact, 0, 20);

        // Queue por usuario
        $userQueue = array_values($assignmentTotals);
        usort($userQueue, function($a, $b) {
            return ($b['assigned_conversations'] ?? 0) <=> ($a['assigned_conversations'] ?? 0);
        });
        $dashboard['queue_by_user'] = array_slice($userQueue, 0, 10);

        // KPIs
        $dashboard['kpis'] = [
            'total_interactions' => [
                'value' => count($items),
                'formatted' => number_format(count($items)),
                'label' => 'Total interacciones',
                'icon' => 'fa-comments',
                'color' => 'cyan',
            ],
            'total_channels' => [
                'value' => count($channels),
                'formatted' => number_format(count($channels)),
                'label' => 'Canales activos',
                'icon' => 'fa-layer-group',
                'color' => 'blue',
            ],
            'calls_total' => [
                'value' => (int) ($channels['Call'] ?? 0),
                'formatted' => number_format((int) ($channels['Call'] ?? 0)),
                'label' => 'Llamadas',
                'icon' => 'fa-phone',
                'color' => 'emerald',
            ],
            'messages_total' => [
                'value' => (int) (($channels['SMS'] ?? 0) + ($channels['Email'] ?? 0) + ($channels['WhatsApp'] ?? 0)),
                'formatted' => number_format((int) (($channels['SMS'] ?? 0) + ($channels['Email'] ?? 0) + ($channels['WhatsApp'] ?? 0))),
                'label' => 'Mensajes',
                'icon' => 'fa-envelope',
                'color' => 'amber',
            ],
        ];

        return $dashboard;
    }
}

if (!function_exists('voiceAiGenerateComprehensiveReport')) {
    /**
     * Genera un reporte comprehensive que combina todos los análisis
     */
    function voiceAiGenerateComprehensiveReport(PDO $pdo, array $filters = []): array
    {
        $startTime = microtime(true);

        $runtimeFilters = $filters;
        $fastMode = !array_key_exists('fast_mode', $filters) || !empty($filters['fast_mode']);

        $runtimeFilters['max_pages'] = max(1, min(50, (int) ($runtimeFilters['max_pages'] ?? 10)));
        $runtimeFilters['page_size'] = max(10, min(50, (int) ($runtimeFilters['page_size'] ?? 50)));
        $runtimeFilters['interaction_max_pages'] = max(1, min(250, (int) ($runtimeFilters['interaction_max_pages'] ?? 50)));
        $runtimeFilters['interaction_page_size'] = max(10, min(100, (int) ($runtimeFilters['interaction_page_size'] ?? 100)));

        if ($fastMode) {
            $runtimeFilters['max_pages'] = min($runtimeFilters['max_pages'], 5);
            $runtimeFilters['page_size'] = min($runtimeFilters['page_size'], 50);
            $runtimeFilters['interaction_max_pages'] = min($runtimeFilters['interaction_max_pages'], 12);
            $runtimeFilters['interaction_page_size'] = min($runtimeFilters['interaction_page_size'], 50);
        }

        // 1. Análisis de disposiciones
        $dispositionAnalytics = voiceAiFetchDispositionAnalytics($pdo, $runtimeFilters);
        
        // 2. Métricas de calidad
        $qualityMetrics = voiceAiFetchCallQualityMetrics($pdo, $runtimeFilters);
        
        // 3. Interacciones completas
        $interactions = voiceAiFetchInteractions($pdo, $runtimeFilters);
        
        // 4. Totales de interacciones
        $interactionTotals = voiceAiFetchInteractionTotals($pdo, $runtimeFilters, $interactions['items'] ?? [], $interactions['meta'] ?? []);

        $report = [
            'success' => true,
            'generated_at' => date('c'),
            'version' => '2026-03-29-comprehensive-v1',
            'disposition_analytics' => $dispositionAnalytics['success'] ? $dispositionAnalytics : [],
            'quality_metrics' => $qualityMetrics['success'] ? $qualityMetrics : [],
            'interactions' => $interactions['success'] ? [
                'total' => count($interactions['items'] ?? []),
                'by_channel' => $interactionTotals['success'] ? ($interactionTotals['totals']['by_channel'] ?? []) : [],
                'by_direction' => $interactionTotals['success'] ? ($interactionTotals['totals']['by_direction'] ?? []) : [],
            ] : [],
            'warnings' => array_merge(
                !$dispositionAnalytics['success'] ? [$dispositionAnalytics['message'] ?? ''] : [],
                !$qualityMetrics['success'] ? [$qualityMetrics['message'] ?? ''] : [],
                !$interactions['success'] ? [$interactions['message'] ?? ''] : [],
                $interactions['meta']['warnings'] ?? []
            ),
            'runtime_limits' => [
                'fast_mode' => $fastMode,
                'max_pages' => $runtimeFilters['max_pages'],
                'page_size' => $runtimeFilters['page_size'],
                'interaction_max_pages' => $runtimeFilters['interaction_max_pages'],
                'interaction_page_size' => $runtimeFilters['interaction_page_size'],
            ],
            'performance_ms' => (int) round((microtime(true) - $startTime) * 1000),
        ];

        return $report;
    }
}
