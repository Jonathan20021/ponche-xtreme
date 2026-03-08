<?php
/**
 * API de Reportes Avanzados - Wasapi Reports
 * Explota al máximo la API de Wasapi para análisis profundo
 * 
 * Endpoints disponibles:
 * - action=dashboard         : KPIs principales y resumen ejecutivo
 * - action=agent_performance : Performance detallado de agentes
 * - action=trends            : Análisis de tendencias temporales
 * - action=predictions       : Predicciones basadas en datos históricos
 * - action=productivity      : Métricas de productividad
 * - action=sla               : Análisis de SLA y tiempos de respuesta
 * - action=hourly_analysis   : Distribución por horas del día
 * - action=comparison        : Comparativas entre períodos
 * - action=rankings          : Rankings de agentes
 * - action=alerts            : Sistema de alertas inteligentes
 * - action=export_data       : Datos para exportación
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

define('WASAPI_TOKEN', '338529|NeQrFHvdJ3lX6O2Hs26QPjc0IyrgzKFxQGwVcvCM0575a229');
define('WASAPI_BASE_URL', 'https://api.wasapi.io/prod/api/v1/');

/**
 * Ejecuta múltiples peticiones a Wasapi en paralelo
 */
function wasapiMultiRequest($endpoints) {
    $multiHandle = curl_multi_init();
    $handles = [];
    
    foreach ($endpoints as $key => $endpoint) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, WASAPI_BASE_URL . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . WASAPI_TOKEN,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        curl_multi_add_handle($multiHandle, $ch);
        $handles[$key] = $ch;
    }
    
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);
    
    $results = [];
    foreach ($handles as $key => $ch) {
        $response = curl_multi_getcontent($ch);
        $results[$key] = json_decode($response, true) ?? [];
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    curl_multi_close($multiHandle);
    
    return $results;
}

/**
 * Calcula percentil de un array
 */
function percentile($array, $percentile) {
    if (empty($array)) return 0;
    sort($array);
    $index = ($percentile / 100) * (count($array) - 1);
    $floor = floor($index);
    $fraction = $index - $floor;
    
    if ($floor + 1 < count($array)) {
        return $array[$floor] + $fraction * ($array[$floor + 1] - $array[$floor]);
    }
    return $array[$floor];
}

/**
 * Formatea segundos a formato legible
 */
function formatTime($seconds) {
    if ($seconds < 60) return round($seconds) . 's';
    if ($seconds < 3600) return round($seconds / 60, 1) . 'm';
    return round($seconds / 3600, 1) . 'h';
}

try {
    $action = $_GET['action'] ?? 'dashboard';
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $datesParam = 'dates%5B0%5D=' . $startDate . '&dates%5B1%5D=' . $endDate;
    
    // Calcular días en el rango
    $daysDiff = max(1, (strtotime($endDate) - strtotime($startDate)) / 86400 + 1);
    
    switch ($action) {
        
        // ================================================================
        // DASHBOARD EJECUTIVO - KPIs principales
        // ================================================================
        case 'dashboard':
            $endpoints = [
                'online_agents' => 'dashboard/metrics/online-agents',
                'conversations' => 'dashboard/metrics/consolidated-conversations?' . $datesParam,
                'agent_conversations' => 'dashboard/metrics/agent-conversations?' . $datesParam,
                'contacts' => 'dashboard/metrics/contacts',
                'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate,
                'workflow' => 'reports/volume-of-workflow?start_date=' . $startDate . '&end_date=' . $endDate,
                'users' => 'users',
                'campaigns' => 'campaigns'
            ];
            
            $results = wasapiMultiRequest($endpoints);
            
            // === Procesar agentes online ===
            $onlineUsers = $results['online_agents']['users'] ?? [];
            $totalAgents = count($onlineUsers);
            $onlineAgents = 0;
            foreach ($onlineUsers as $u) {
                if (isset($u['online']) && $u['online'] == 1) $onlineAgents++;
            }
            
            // === Procesar conversaciones ===
            $convData = $results['conversations']['conversations']['data'] ?? [];
            $totalOpen = 0;
            $totalClosed = 0;
            $totalPending = 0;
            $totalHold = 0;
            
            foreach ($convData as $c) {
                $status = $c['status'] ?? '';
                $count = intval($c['cant'] ?? 0);
                switch ($status) {
                    case 'open': $totalOpen += $count; break;
                    case 'closed': $totalClosed += $count; break;
                    case 'pending': $totalPending += $count; break;
                    case 'hold': $totalHold += $count; break;
                }
            }
            $totalConversations = $totalOpen + $totalClosed + $totalPending + $totalHold;
            
            // === Procesar performance ===
            $perfData = $results['performance']['data'] ?? [];
            $totalFirstResponses = 0;
            $totalResolutionTime = 0;
            $totalFirstResponseTime = 0;
            $resolutionCount = 0;
            $firstResponseCount = 0;
            $allResolutionTimes = [];
            $allFirstResponseTimes = [];
            
            foreach ($perfData as $p) {
                $totalFirstResponses += intval($p['total_first_response_count'] ?? 0);
                
                $avgResTime = floatval($p['avg_resolution_time_per_conversation'] ?? 0);
                $closeConv = intval($p['total_close_conversations'] ?? 0);
                if ($avgResTime > 0 && $closeConv > 0) {
                    $totalResolutionTime += $avgResTime * $closeConv;
                    $resolutionCount += $closeConv;
                    $allResolutionTimes[] = $avgResTime;
                }
                
                $avgFirstResp = floatval($p['avg_first_response_time'] ?? 0);
                if ($avgFirstResp > 0) {
                    $totalFirstResponseTime += $avgFirstResp;
                    $firstResponseCount++;
                    $allFirstResponseTimes[] = $avgFirstResp;
                }
            }
            
            $avgResolutionTime = $resolutionCount > 0 ? $totalResolutionTime / $resolutionCount : 0;
            $avgFirstResponseTimeSec = $firstResponseCount > 0 ? $totalFirstResponseTime / $firstResponseCount : 0;
            
            // === Procesar workflow ===
            $workflowData = $results['workflow']['data'] ?? [];
            $totalOpenFromWf = 0;
            $totalClosedFromWf = 0;
            $totalScaled = 0;
            $totalAutoAssigned = 0;
            $totalManualAssigned = 0;
            
            foreach ($workflowData as $wf) {
                $totalOpenFromWf += intval($wf['total_open_conversations'] ?? 0);
                $totalClosedFromWf += intval($wf['total_close_conversations'] ?? 0);
                $totalScaled += intval($wf['total_scaled_to_agents'] ?? 0);
                $totalAutoAssigned += intval($wf['total_asigned_by_automatic'] ?? 0);
                $totalManualAssigned += intval($wf['total_asigned_by_manual'] ?? 0);
            }
            
            // === Contactos ===
            $contactsData = $results['contacts']['data'] ?? [];
            $totalContacts = is_array($contactsData) ? count($contactsData) : 0;
            
            // === Calcular KPIs ===
            // Tasa de resolución: cerradas / (abiertas + cerradas) * 100
            $totalConversationsForRate = $totalOpenFromWf + $totalClosedFromWf;
            $resolutionRate = $totalConversationsForRate > 0 ? round(($totalClosedFromWf / $totalConversationsForRate) * 100, 1) : 0;
            $avgConversationsPerAgent = $totalAgents > 0 ? round($totalConversations / $totalAgents, 1) : 0;
            $avgClosedPerAgent = $totalAgents > 0 ? round($totalClosed / $totalAgents, 1) : 0;
            $avgConversationsPerDay = round($totalConversations / $daysDiff, 1);
            $avgClosedPerDay = round($totalClosed / $daysDiff, 1);
            $escalationRate = $totalConversations > 0 ? round(($totalScaled / $totalConversations) * 100, 1) : 0;
            $autoAssignmentRate = ($totalAutoAssigned + $totalManualAssigned) > 0 
                ? round(($totalAutoAssigned / ($totalAutoAssigned + $totalManualAssigned)) * 100, 1) : 0;
            
            // === Campañas ===
            $campaignsData = $results['campaigns']['data'] ?? [];
            $totalCampaigns = count($campaignsData);
            
            echo json_encode([
                'success' => true,
                'dashboard' => [
                    // KPIs principales
                    'kpis' => [
                        'total_conversations' => $totalConversations,
                        'conversations_open' => $totalOpen,
                        'conversations_closed' => $totalClosed,
                        'conversations_pending' => $totalPending,
                        'conversations_hold' => $totalHold,
                        'resolution_rate' => $resolutionRate,
                        'avg_resolution_time' => round($avgResolutionTime),
                        'avg_resolution_time_formatted' => formatTime($avgResolutionTime),
                        'avg_first_response_time' => round($avgFirstResponseTimeSec),
                        'avg_first_response_formatted' => formatTime($avgFirstResponseTimeSec),
                        'total_first_responses' => $totalFirstResponses,
                        'escalation_rate' => $escalationRate,
                        'auto_assignment_rate' => $autoAssignmentRate
                    ],
                    
                    // Agentes
                    'agents' => [
                        'total' => $totalAgents,
                        'online' => $onlineAgents,
                        'offline' => $totalAgents - $onlineAgents,
                        'availability_rate' => $totalAgents > 0 ? round(($onlineAgents / $totalAgents) * 100, 1) : 0,
                        'avg_conversations_per_agent' => $avgConversationsPerAgent,
                        'avg_closed_per_agent' => $avgClosedPerAgent
                    ],
                    
                    // Promedios diarios
                    'daily_averages' => [
                        'conversations_per_day' => $avgConversationsPerDay,
                        'closed_per_day' => $avgClosedPerDay,
                        'first_responses_per_day' => round($totalFirstResponses / $daysDiff, 1),
                        'escalations_per_day' => round($totalScaled / $daysDiff, 1)
                    ],
                    
                    // Distribución
                    'distribution' => [
                        'by_status' => [
                            ['status' => 'Abiertas', 'count' => $totalOpen, 'color' => '#3B82F6'],
                            ['status' => 'Cerradas', 'count' => $totalClosed, 'color' => '#10B981'],
                            ['status' => 'Pendientes', 'count' => $totalPending, 'color' => '#F59E0B'],
                            ['status' => 'En espera', 'count' => $totalHold, 'color' => '#6B7280']
                        ],
                        'by_assignment' => [
                            ['type' => 'Automático', 'count' => $totalAutoAssigned],
                            ['type' => 'Manual', 'count' => $totalManualAssigned],
                            ['type' => 'Escaladas', 'count' => $totalScaled]
                        ]
                    ],
                    
                    // SLA percentiles
                    'sla_metrics' => [
                        'resolution_p50' => round(percentile($allResolutionTimes, 50)),
                        'resolution_p90' => round(percentile($allResolutionTimes, 90)),
                        'resolution_p95' => round(percentile($allResolutionTimes, 95)),
                        'first_response_p50' => round(percentile($allFirstResponseTimes, 50)),
                        'first_response_p90' => round(percentile($allFirstResponseTimes, 90)),
                        'first_response_p95' => round(percentile($allFirstResponseTimes, 95))
                    ],
                    
                    // Metadata
                    'campaigns_count' => $totalCampaigns,
                    'contacts_count' => $totalContacts,
                    'period_days' => $daysDiff
                ],
                'period' => ['start' => $startDate, 'end' => $endDate],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        // ================================================================
        // PERFORMANCE DETALLADO POR AGENTE
        // ================================================================
        case 'agent_performance':
            $endpoints = [
                'online_agents' => 'dashboard/metrics/online-agents',
                'agent_conversations' => 'dashboard/metrics/agent-conversations?' . $datesParam,
                'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate,
                'users' => 'users'
            ];
            
            $results = wasapiMultiRequest($endpoints);
            
            // Crear mapa de usuarios
            $userMap = [];
            foreach ($results['users']['users'] ?? [] as $u) {
                $userMap[$u['id']] = $u;
            }
            
            // Estado online
            $onlineStatus = [];
            foreach ($results['online_agents']['users'] ?? [] as $u) {
                $onlineStatus[$u['id']] = $u['online'] == 1;
            }
            
            // Conversaciones actuales por agente
            $agentConvs = [];
            foreach ($results['agent_conversations']['conversations'] ?? [] as $c) {
                $agentId = $c['agent_id'] ?? 0;
                if ($agentId > 0) {
                    if (!isset($agentConvs[$agentId])) {
                        $agentConvs[$agentId] = ['open' => 0, 'closed' => 0, 'hold' => 0, 'pending' => 0];
                    }
                    $status = $c['status'] ?? '';
                    $count = intval($c['count'] ?? 0);
                    if (isset($agentConvs[$agentId][$status])) {
                        $agentConvs[$agentId][$status] += $count;
                    }
                }
            }
            
            // AGRUPAR datos de performance por agente (la API devuelve por día)
            $agentData = [];
            foreach ($results['performance']['data'] ?? [] as $p) {
                $agentId = $p['agent_id'] ?? 0;
                if ($agentId <= 0) continue;
                
                if (!isset($agentData[$agentId])) {
                    $agentInfo = $p['agent'] ?? [];
                    $agentData[$agentId] = [
                        'id' => $agentId,
                        'name' => $agentInfo['name'] ?? $userMap[$agentId]['name'] ?? 'Agente #' . $agentId,
                        'email' => $agentInfo['email'] ?? $userMap[$agentId]['email'] ?? '',
                        'total_open' => 0,
                        'total_closed' => 0,
                        'total_resolution_time' => 0,
                        'total_first_response_time' => 0,
                        'total_first_response_count' => 0,
                        'total_scaled' => 0,
                        'total_auto_assigned' => 0,
                        'total_manual_assigned' => 0,
                        'days_active' => 0
                    ];
                }
                
                // Sumar métricas del día
                $agentData[$agentId]['total_open'] += intval($p['total_open_conversations'] ?? 0);
                $agentData[$agentId]['total_closed'] += intval($p['total_close_conversations'] ?? 0);
                $agentData[$agentId]['total_resolution_time'] += floatval($p['total_resolution_time'] ?? 0);
                $agentData[$agentId]['total_first_response_time'] += floatval($p['total_first_response_time'] ?? 0);
                $agentData[$agentId]['total_first_response_count'] += intval($p['total_first_response_count'] ?? 0);
                $agentData[$agentId]['total_scaled'] += intval($p['total_scaled_to_agents'] ?? 0);
                $agentData[$agentId]['total_auto_assigned'] += intval($p['total_asigned_by_automatic'] ?? 0);
                $agentData[$agentId]['total_manual_assigned'] += intval($p['total_asigned_by_manual'] ?? 0);
                $agentData[$agentId]['days_active']++;
            }
            
            // Construir array final de agentes
            $agents = [];
            $teamTotals = [
                'total_conversations' => 0,
                'total_closed' => 0,
                'total_open' => 0,
                'total_resolution_time' => 0,
                'total_first_response_time' => 0,
                'resolution_count' => 0,
                'first_response_count' => 0
            ];
            
            foreach ($agentData as $agentId => $data) {
                $totalConv = $data['total_open'] + $data['total_closed'];
                
                // Calcular promedios ponderados
                $avgResTime = $data['total_closed'] > 0 
                    ? $data['total_resolution_time'] / $data['total_closed'] : 0;
                $avgFirstResp = $data['total_first_response_count'] > 0 
                    ? $data['total_first_response_time'] / $data['total_first_response_count'] : 0;
                
                // Tasa de resolución: cerradas / total atendidas * 100
                $resolutionRate = $totalConv > 0 ? round(($data['total_closed'] / $totalConv) * 100, 1) : 0;
                $productivity = $daysDiff > 0 ? round($data['total_closed'] / $daysDiff, 1) : 0;
                
                $agents[] = [
                    'id' => $agentId,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'is_online' => $onlineStatus[$agentId] ?? false,
                    
                    // Conversaciones
                    'conversations' => [
                        'total' => $totalConv,
                        'open' => $data['total_open'],
                        'closed' => $data['total_closed'],
                        'current_open' => $agentConvs[$agentId]['open'] ?? 0,
                        'current_hold' => $agentConvs[$agentId]['hold'] ?? 0
                    ],
                    
                    // Tiempos
                    'times' => [
                        'avg_resolution' => round($avgResTime),
                        'avg_resolution_formatted' => formatTime($avgResTime),
                        'avg_first_response' => round($avgFirstResp),
                        'avg_first_response_formatted' => formatTime($avgFirstResp),
                        'total_resolution_time' => round($data['total_resolution_time']),
                        'total_first_responses' => $data['total_first_response_count']
                    ],
                    
                    // Métricas de calidad
                    'metrics' => [
                        'resolution_rate' => $resolutionRate,
                        'productivity_per_day' => $productivity,
                        'escalation_count' => $data['total_scaled'],
                        'auto_assigned' => $data['total_auto_assigned'],
                        'manual_assigned' => $data['total_manual_assigned'],
                        'days_active' => $data['days_active']
                    ]
                ];
                
                // Acumular totales
                $teamTotals['total_conversations'] += $totalConv;
                $teamTotals['total_closed'] += $data['total_closed'];
                $teamTotals['total_open'] += $data['total_open'];
                $teamTotals['total_resolution_time'] += $data['total_resolution_time'];
                $teamTotals['resolution_count'] += $data['total_closed'];
                $teamTotals['total_first_response_time'] += $data['total_first_response_time'];
                $teamTotals['first_response_count'] += $data['total_first_response_count'];
            }
            
            // Ordenar por productividad
            usort($agents, fn($a, $b) => $b['metrics']['productivity_per_day'] <=> $a['metrics']['productivity_per_day']);
            
            // Calcular promedios del equipo
            $teamAvg = [
                'avg_resolution' => $teamTotals['resolution_count'] > 0 
                    ? round($teamTotals['total_resolution_time'] / $teamTotals['resolution_count']) : 0,
                'avg_first_response' => $teamTotals['first_response_count'] > 0 
                    ? round($teamTotals['total_first_response_time'] / $teamTotals['first_response_count']) : 0,
                'avg_productivity' => count($agents) > 0 
                    ? round(array_sum(array_column(array_column($agents, 'metrics'), 'productivity_per_day')) / count($agents), 1) : 0,
                'resolution_rate' => $teamTotals['total_conversations'] > 0 
                    ? round(($teamTotals['total_closed'] / $teamTotals['total_conversations']) * 100, 1) : 0
            ];
            
            echo json_encode([
                'success' => true,
                'agents' => $agents,
                'team_totals' => $teamTotals,
                'team_averages' => $teamAvg,
                'total_agents' => count($agents),
                'period' => ['start' => $startDate, 'end' => $endDate, 'days' => $daysDiff],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        // ================================================================
        // ANÁLISIS DE TENDENCIAS
        // ================================================================
        case 'trends':
            $endpoints = [
                'workflow' => 'reports/volume-of-workflow?start_date=' . $startDate . '&end_date=' . $endDate,
                'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate
            ];
            
            $results = wasapiMultiRequest($endpoints);
            
            // Agrupar por fecha
            $dailyData = [];
            
            foreach ($results['workflow']['data'] ?? [] as $wf) {
                $date = substr($wf['date'] ?? '', 0, 10);
                if (!isset($dailyData[$date])) {
                    $dailyData[$date] = [
                        'date' => $date,
                        'conversations_opened' => 0,
                        'conversations_closed' => 0,
                        'first_responses' => 0,
                        'escalations' => 0,
                        'total_resolution_time' => 0,
                        'resolution_count' => 0,
                        'total_first_response_time' => 0,
                        'first_response_count' => 0,
                        'agents_active' => 0
                    ];
                }
                
                $dailyData[$date]['conversations_opened'] += intval($wf['total_open_conversations'] ?? 0);
                $dailyData[$date]['conversations_closed'] += intval($wf['total_close_conversations'] ?? 0);
                $dailyData[$date]['first_responses'] += intval($wf['total_first_response_count'] ?? 0);
                $dailyData[$date]['escalations'] += intval($wf['total_scaled_to_agents'] ?? 0);
                $dailyData[$date]['agents_active']++;
                
                $resTime = floatval($wf['total_resolution_time'] ?? 0);
                $closeCount = intval($wf['total_close_conversations'] ?? 0);
                if ($resTime > 0 && $closeCount > 0) {
                    $dailyData[$date]['total_resolution_time'] += $resTime;
                    $dailyData[$date]['resolution_count'] += $closeCount;
                }
                
                $firstRespTime = floatval($wf['total_first_response_time'] ?? 0);
                $firstRespCount = intval($wf['total_first_response_count'] ?? 0);
                if ($firstRespTime > 0 && $firstRespCount > 0) {
                    $dailyData[$date]['total_first_response_time'] += $firstRespTime;
                    $dailyData[$date]['first_response_count'] += $firstRespCount;
                }
            }
            
            // Calcular promedios y preparar para gráficos
            $trends = [];
            $prevData = null;
            
            ksort($dailyData);
            foreach ($dailyData as $date => $data) {
                $avgResTime = $data['resolution_count'] > 0 
                    ? $data['total_resolution_time'] / $data['resolution_count'] : 0;
                $avgFirstResp = $data['first_response_count'] > 0 
                    ? $data['total_first_response_time'] / $data['first_response_count'] : 0;
                
                // Tasa de resolución: cerradas / total conversaciones
                $totalConv = $data['conversations_opened'] + $data['conversations_closed'];
                $resolutionRate = $totalConv > 0 
                    ? round(($data['conversations_closed'] / $totalConv) * 100, 1) : 0;
                
                // Calcular cambio vs día anterior
                $changeOpened = 0;
                $changeClosed = 0;
                if ($prevData) {
                    $changeOpened = $prevData['conversations_opened'] > 0 
                        ? round((($data['conversations_opened'] - $prevData['conversations_opened']) / $prevData['conversations_opened']) * 100, 1) : 0;
                    $changeClosed = $prevData['conversations_closed'] > 0 
                        ? round((($data['conversations_closed'] - $prevData['conversations_closed']) / $prevData['conversations_closed']) * 100, 1) : 0;
                }
                
                $dayName = date('D', strtotime($date));
                
                $trends[] = [
                    'date' => $date,
                    'day_name' => $dayName,
                    'conversations_opened' => $data['conversations_opened'],
                    'conversations_closed' => $data['conversations_closed'],
                    'first_responses' => $data['first_responses'],
                    'escalations' => $data['escalations'],
                    'resolution_rate' => $resolutionRate,
                    'avg_resolution_time' => round($avgResTime),
                    'avg_first_response' => round($avgFirstResp),
                    'agents_active' => $data['agents_active'],
                    'change_opened' => $changeOpened,
                    'change_closed' => $changeClosed
                ];
                
                $prevData = $data;
            }
            
            // Calcular tendencia general (regresión lineal simple)
            $n = count($trends);
            $sumX = 0; $sumY = 0; $sumXY = 0; $sumX2 = 0;
            foreach ($trends as $i => $t) {
                $sumX += $i;
                $sumY += $t['conversations_opened'];
                $sumXY += $i * $t['conversations_opened'];
                $sumX2 += $i * $i;
            }
            
            $trendSlope = 0;
            if ($n > 1 && ($n * $sumX2 - $sumX * $sumX) != 0) {
                $trendSlope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
            }
            
            $trendDirection = $trendSlope > 0.5 ? 'increasing' : ($trendSlope < -0.5 ? 'decreasing' : 'stable');
            
            echo json_encode([
                'success' => true,
                'trends' => $trends,
                'summary' => [
                    'total_days' => $n,
                    'trend_direction' => $trendDirection,
                    'trend_slope' => round($trendSlope, 2),
                    'avg_daily_opened' => $n > 0 ? round($sumY / $n, 1) : 0,
                    'best_day' => $n > 0 ? $trends[array_search(max(array_column($trends, 'conversations_closed')), array_column($trends, 'conversations_closed'))]['date'] : null,
                    'worst_day' => $n > 0 ? $trends[array_search(min(array_column($trends, 'conversations_closed')), array_column($trends, 'conversations_closed'))]['date'] : null
                ],
                'period' => ['start' => $startDate, 'end' => $endDate],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        // ================================================================
        // PREDICCIONES
        // ================================================================
        case 'predictions':
            // Obtener datos históricos (últimos 30 días para predicción)
            $historyStart = date('Y-m-d', strtotime('-30 days'));
            $historyEnd = date('Y-m-d');
            
            $endpoints = [
                'workflow' => 'reports/volume-of-workflow?start_date=' . $historyStart . '&end_date=' . $historyEnd
            ];
            
            $results = wasapiMultiRequest($endpoints);
            
            // Agrupar por día de la semana y hora
            $byDayOfWeek = [];
            $byDate = [];
            
            foreach ($results['workflow']['data'] ?? [] as $wf) {
                $date = substr($wf['date'] ?? '', 0, 10);
                $dayOfWeek = date('w', strtotime($date)); // 0=domingo, 6=sábado
                
                if (!isset($byDayOfWeek[$dayOfWeek])) {
                    $byDayOfWeek[$dayOfWeek] = ['opened' => [], 'closed' => []];
                }
                
                if (!isset($byDate[$date])) {
                    $byDate[$date] = ['opened' => 0, 'closed' => 0];
                }
                
                $opened = intval($wf['total_open_conversations'] ?? 0);
                $closed = intval($wf['total_close_conversations'] ?? 0);
                
                $byDate[$date]['opened'] += $opened;
                $byDate[$date]['closed'] += $closed;
            }
            
            // Agrupar datos diarios por día de semana
            foreach ($byDate as $date => $data) {
                $dayOfWeek = date('w', strtotime($date));
                $byDayOfWeek[$dayOfWeek]['opened'][] = $data['opened'];
                $byDayOfWeek[$dayOfWeek]['closed'][] = $data['closed'];
            }
            
            // Calcular promedios por día de la semana
            $dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
            $predictions = [];
            
            // Predecir próximos 7 días
            for ($i = 1; $i <= 7; $i++) {
                $futureDate = date('Y-m-d', strtotime("+$i days"));
                $futureDayOfWeek = date('w', strtotime($futureDate));
                
                $avgOpened = 0;
                $avgClosed = 0;
                
                if (isset($byDayOfWeek[$futureDayOfWeek])) {
                    $openedArr = $byDayOfWeek[$futureDayOfWeek]['opened'];
                    $closedArr = $byDayOfWeek[$futureDayOfWeek]['closed'];
                    
                    $avgOpened = count($openedArr) > 0 ? array_sum($openedArr) / count($openedArr) : 0;
                    $avgClosed = count($closedArr) > 0 ? array_sum($closedArr) / count($closedArr) : 0;
                    
                    // Ajustar por tendencia reciente
                    $recentDates = array_slice(array_keys($byDate), -7);
                    $recentTrend = 1;
                    if (count($recentDates) >= 2) {
                        $firstWeekAvg = array_sum(array_slice(array_column(array_intersect_key($byDate, array_flip($recentDates)), 'opened'), 0, 3)) / 3;
                        $lastWeekAvg = array_sum(array_slice(array_column(array_intersect_key($byDate, array_flip($recentDates)), 'opened'), -3)) / 3;
                        if ($firstWeekAvg > 0) {
                            $recentTrend = $lastWeekAvg / $firstWeekAvg;
                        }
                    }
                    
                    $avgOpened *= $recentTrend;
                    $avgClosed *= $recentTrend;
                }
                
                $predictions[] = [
                    'date' => $futureDate,
                    'day_name' => $dayNames[$futureDayOfWeek],
                    'predicted_opened' => round($avgOpened),
                    'predicted_closed' => round($avgClosed),
                    'confidence' => isset($byDayOfWeek[$futureDayOfWeek]) && count($byDayOfWeek[$futureDayOfWeek]['opened']) >= 3 ? 'high' : 'medium'
                ];
            }
            
            // Calcular factor de estacionalidad semanal
            $weekdayPattern = [];
            foreach ($byDayOfWeek as $dow => $data) {
                $avgOpened = count($data['opened']) > 0 ? array_sum($data['opened']) / count($data['opened']) : 0;
                $weekdayPattern[] = [
                    'day' => $dayNames[$dow],
                    'day_number' => $dow,
                    'avg_conversations' => round($avgOpened),
                    'is_peak' => false
                ];
            }
            
            // Marcar días pico
            usort($weekdayPattern, fn($a, $b) => $b['avg_conversations'] <=> $a['avg_conversations']);
            if (count($weekdayPattern) >= 2) {
                $weekdayPattern[0]['is_peak'] = true;
                $weekdayPattern[1]['is_peak'] = true;
            }
            usort($weekdayPattern, fn($a, $b) => $a['day_number'] <=> $b['day_number']);
            
            echo json_encode([
                'success' => true,
                'predictions' => $predictions,
                'weekly_pattern' => $weekdayPattern,
                'recommendation' => [
                    'peak_days' => array_map(fn($d) => $d['day'], array_filter($weekdayPattern, fn($d) => $d['is_peak'])),
                    'suggested_staffing' => 'Aumentar personal en días pico',
                    'based_on_days' => count($byDate)
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        // ================================================================
        // RANKINGS DE AGENTES
        // ================================================================
        case 'rankings':
            $endpoints = [
                'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate
            ];
            
            $results = wasapiMultiRequest($endpoints);
            
            // AGRUPAR datos por agente (la API devuelve por día)
            $agentData = [];
            foreach ($results['performance']['data'] ?? [] as $p) {
                $agentId = $p['agent_id'] ?? 0;
                if ($agentId <= 0) continue;
                
                if (!isset($agentData[$agentId])) {
                    $agentInfo = $p['agent'] ?? [];
                    $agentData[$agentId] = [
                        'id' => $agentId,
                        'name' => $agentInfo['name'] ?? 'Agente #' . $agentId,
                        'closed' => 0,
                        'opened' => 0,
                        'total_resolution_time' => 0,
                        'total_first_response_time' => 0,
                        'total_first_response_count' => 0
                    ];
                }
                
                $agentData[$agentId]['closed'] += intval($p['total_close_conversations'] ?? 0);
                $agentData[$agentId]['opened'] += intval($p['total_open_conversations'] ?? 0);
                $agentData[$agentId]['total_resolution_time'] += floatval($p['total_resolution_time'] ?? 0);
                $agentData[$agentId]['total_first_response_time'] += floatval($p['total_first_response_time'] ?? 0);
                $agentData[$agentId]['total_first_response_count'] += intval($p['total_first_response_count'] ?? 0);
            }
            
            // Construir array de agentes con métricas calculadas
            $agents = [];
            foreach ($agentData as $data) {
                $avgResTime = $data['closed'] > 0 ? $data['total_resolution_time'] / $data['closed'] : 0;
                $avgFirstResp = $data['total_first_response_count'] > 0 
                    ? $data['total_first_response_time'] / $data['total_first_response_count'] : 0;
                $totalConv = $data['closed'] + $data['opened'];
                
                $agents[] = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'closed' => $data['closed'],
                    'opened' => $data['opened'],
                    'avg_resolution_time' => $avgResTime,
                    'avg_first_response' => $avgFirstResp,
                    'first_responses' => $data['total_first_response_count'],
                    'resolution_rate' => $totalConv > 0 ? round(($data['closed'] / $totalConv) * 100, 1) : 0,
                    'productivity' => round($data['closed'] / max($daysDiff, 1), 1)
                ];
            }
            
            // Crear diferentes rankings
            $rankings = [
                'by_productivity' => array_slice(
                    array_map(fn($a, $i) => array_merge($a, ['rank' => $i + 1]),
                        collect_sorted($agents, 'productivity', true),
                        array_keys(collect_sorted($agents, 'productivity', true))
                    ), 0, 10
                ),
                'by_resolution_rate' => array_slice(
                    array_map(fn($a, $i) => array_merge($a, ['rank' => $i + 1]),
                        collect_sorted($agents, 'resolution_rate', true),
                        array_keys(collect_sorted($agents, 'resolution_rate', true))
                    ), 0, 10
                ),
                'by_speed' => array_slice(
                    array_map(fn($a, $i) => array_merge($a, ['rank' => $i + 1]),
                        collect_sorted(array_filter($agents, fn($a) => $a['avg_resolution_time'] > 0), 'avg_resolution_time', false),
                        array_keys(collect_sorted(array_filter($agents, fn($a) => $a['avg_resolution_time'] > 0), 'avg_resolution_time', false))
                    ), 0, 10
                ),
                'by_first_response' => array_slice(
                    array_map(fn($a, $i) => array_merge($a, ['rank' => $i + 1]),
                        collect_sorted($agents, 'first_responses', true),
                        array_keys(collect_sorted($agents, 'first_responses', true))
                    ), 0, 10
                )
            ];
            
            echo json_encode([
                'success' => true,
                'rankings' => $rankings,
                'total_agents' => count($agents),
                'period' => ['start' => $startDate, 'end' => $endDate],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        // ================================================================
        // ANÁLISIS SLA
        // ================================================================
        case 'sla':
            $endpoints = [
                'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate
            ];
            
            $results = wasapiMultiRequest($endpoints);
            
            // Definir SLAs objetivo
            $slaTargets = [
                'first_response' => intval($_GET['sla_first_response'] ?? 300), // 5 min default
                'resolution' => intval($_GET['sla_resolution'] ?? 3600) // 1 hora default
            ];
            
            // AGRUPAR datos por agente (la API devuelve por día)
            $agentData = [];
            foreach ($results['performance']['data'] ?? [] as $p) {
                $agentId = $p['agent_id'] ?? 0;
                if ($agentId <= 0) continue;
                
                if (!isset($agentData[$agentId])) {
                    $agentInfo = $p['agent'] ?? [];
                    $agentData[$agentId] = [
                        'name' => $agentInfo['name'] ?? 'Agente #' . $agentId,
                        'total_closed' => 0,
                        'total_first_response_time' => 0,
                        'total_first_response_count' => 0,
                        'total_resolution_time' => 0
                    ];
                }
                
                $agentData[$agentId]['total_closed'] += intval($p['total_close_conversations'] ?? 0);
                $agentData[$agentId]['total_first_response_time'] += floatval($p['total_first_response_time'] ?? 0);
                $agentData[$agentId]['total_first_response_count'] += intval($p['total_first_response_count'] ?? 0);
                $agentData[$agentId]['total_resolution_time'] += floatval($p['total_resolution_time'] ?? 0);
            }
            
            $allFirstResponse = [];
            $allResolution = [];
            $agentSLA = [];
            
            foreach ($agentData as $agentId => $data) {
                // Calcular promedios ponderados
                $avgFirstResp = $data['total_first_response_count'] > 0 
                    ? $data['total_first_response_time'] / $data['total_first_response_count'] : 0;
                $avgResTime = $data['total_closed'] > 0 
                    ? $data['total_resolution_time'] / $data['total_closed'] : 0;
                
                if ($avgFirstResp > 0) $allFirstResponse[] = $avgFirstResp;
                if ($avgResTime > 0) $allResolution[] = $avgResTime;
                
                $withinFirstRespSLA = $avgFirstResp > 0 && $avgFirstResp <= $slaTargets['first_response'];
                $withinResSLA = $avgResTime > 0 && $avgResTime <= $slaTargets['resolution'];
                
                $agentSLA[] = [
                    'id' => $agentId,
                    'name' => $data['name'],
                    'avg_first_response' => round($avgFirstResp),
                    'avg_resolution' => round($avgResTime),
                    'conversations_handled' => $data['total_closed'],
                    'first_response_sla_met' => $withinFirstRespSLA,
                    'resolution_sla_met' => $withinResSLA,
                    'sla_score' => ($withinFirstRespSLA ? 50 : 0) + ($withinResSLA ? 50 : 0)
                ];
            }
            
            // Calcular estadísticas SLA globales
            $totalAgents = count($agentSLA);
            $meetsFirstRespSLA = count(array_filter($agentSLA, fn($a) => $a['first_response_sla_met']));
            $meetsResSLA = count(array_filter($agentSLA, fn($a) => $a['resolution_sla_met']));
            
            usort($agentSLA, fn($a, $b) => $b['sla_score'] <=> $a['sla_score']);
            
            echo json_encode([
                'success' => true,
                'sla_targets' => [
                    'first_response_seconds' => $slaTargets['first_response'],
                    'first_response_formatted' => formatTime($slaTargets['first_response']),
                    'resolution_seconds' => $slaTargets['resolution'],
                    'resolution_formatted' => formatTime($slaTargets['resolution'])
                ],
                'global_metrics' => [
                    'first_response' => [
                        'average' => round(count($allFirstResponse) > 0 ? array_sum($allFirstResponse) / count($allFirstResponse) : 0),
                        'p50' => round(percentile($allFirstResponse, 50)),
                        'p90' => round(percentile($allFirstResponse, 90)),
                        'p95' => round(percentile($allFirstResponse, 95)),
                        'compliance_rate' => $totalAgents > 0 ? round(($meetsFirstRespSLA / $totalAgents) * 100, 1) : 0
                    ],
                    'resolution' => [
                        'average' => round(count($allResolution) > 0 ? array_sum($allResolution) / count($allResolution) : 0),
                        'p50' => round(percentile($allResolution, 50)),
                        'p90' => round(percentile($allResolution, 90)),
                        'p95' => round(percentile($allResolution, 95)),
                        'compliance_rate' => $totalAgents > 0 ? round(($meetsResSLA / $totalAgents) * 100, 1) : 0
                    ]
                ],
                'agents' => $agentSLA,
                'summary' => [
                    'total_agents' => $totalAgents,
                    'agents_meeting_first_response_sla' => $meetsFirstRespSLA,
                    'agents_meeting_resolution_sla' => $meetsResSLA,
                    'overall_sla_compliance' => $totalAgents > 0 
                        ? round((($meetsFirstRespSLA + $meetsResSLA) / ($totalAgents * 2)) * 100, 1) : 0
                ],
                'period' => ['start' => $startDate, 'end' => $endDate],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        // ================================================================
        // COMPARACIÓN ENTRE PERÍODOS
        // ================================================================
        case 'comparison':
            // Período actual
            $currentStart = $startDate;
            $currentEnd = $endDate;
            
            // Período anterior (misma duración)
            $prevEnd = date('Y-m-d', strtotime($startDate . ' -1 day'));
            $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($daysDiff - 1) . ' days'));
            
            $currentDatesParam = 'dates%5B0%5D=' . $currentStart . '&dates%5B1%5D=' . $currentEnd;
            $prevDatesParam = 'dates%5B0%5D=' . $prevStart . '&dates%5B1%5D=' . $prevEnd;
            
            $endpoints = [
                'current_conv' => 'dashboard/metrics/consolidated-conversations?' . $currentDatesParam,
                'prev_conv' => 'dashboard/metrics/consolidated-conversations?' . $prevDatesParam,
                'current_workflow' => 'reports/volume-of-workflow?start_date=' . $currentStart . '&end_date=' . $currentEnd,
                'prev_workflow' => 'reports/volume-of-workflow?start_date=' . $prevStart . '&end_date=' . $prevEnd,
                'current_perf' => 'reports/performance-by-agent?start_date=' . $currentStart . '&end_date=' . $currentEnd,
                'prev_perf' => 'reports/performance-by-agent?start_date=' . $prevStart . '&end_date=' . $prevEnd
            ];
            
            $results = wasapiMultiRequest($endpoints);
            
            // Procesar período actual
            $currentConv = ['open' => 0, 'closed' => 0];
            foreach ($results['current_conv']['conversations']['data'] ?? [] as $c) {
                $status = $c['status'] ?? '';
                $count = intval($c['cant'] ?? 0);
                if ($status === 'open') $currentConv['open'] += $count;
                if ($status === 'closed') $currentConv['closed'] += $count;
            }
            
            // Procesar período anterior
            $prevConv = ['open' => 0, 'closed' => 0];
            foreach ($results['prev_conv']['conversations']['data'] ?? [] as $c) {
                $status = $c['status'] ?? '';
                $count = intval($c['cant'] ?? 0);
                if ($status === 'open') $prevConv['open'] += $count;
                if ($status === 'closed') $prevConv['closed'] += $count;
            }
            
            // Calcular métricas de workflow
            $currentWf = ['opened' => 0, 'closed' => 0, 'first_resp_time' => 0, 'first_resp_count' => 0, 'res_time' => 0, 'res_count' => 0];
            foreach ($results['current_workflow']['data'] ?? [] as $wf) {
                $currentWf['opened'] += intval($wf['total_open_conversations'] ?? 0);
                $currentWf['closed'] += intval($wf['total_close_conversations'] ?? 0);
                $firstRespTime = floatval($wf['total_first_response_time'] ?? 0);
                $firstRespCount = intval($wf['total_first_response_count'] ?? 0);
                if ($firstRespTime > 0 && $firstRespCount > 0) {
                    $currentWf['first_resp_time'] += $firstRespTime;
                    $currentWf['first_resp_count'] += $firstRespCount;
                }
                $resTime = floatval($wf['total_resolution_time'] ?? 0);
                $closeCount = intval($wf['total_close_conversations'] ?? 0);
                if ($resTime > 0 && $closeCount > 0) {
                    $currentWf['res_time'] += $resTime;
                    $currentWf['res_count'] += $closeCount;
                }
            }
            
            $prevWf = ['opened' => 0, 'closed' => 0, 'first_resp_time' => 0, 'first_resp_count' => 0, 'res_time' => 0, 'res_count' => 0];
            foreach ($results['prev_workflow']['data'] ?? [] as $wf) {
                $prevWf['opened'] += intval($wf['total_open_conversations'] ?? 0);
                $prevWf['closed'] += intval($wf['total_close_conversations'] ?? 0);
                $firstRespTime = floatval($wf['total_first_response_time'] ?? 0);
                $firstRespCount = intval($wf['total_first_response_count'] ?? 0);
                if ($firstRespTime > 0 && $firstRespCount > 0) {
                    $prevWf['first_resp_time'] += $firstRespTime;
                    $prevWf['first_resp_count'] += $firstRespCount;
                }
                $resTime = floatval($wf['total_resolution_time'] ?? 0);
                $closeCount = intval($wf['total_close_conversations'] ?? 0);
                if ($resTime > 0 && $closeCount > 0) {
                    $prevWf['res_time'] += $resTime;
                    $prevWf['res_count'] += $closeCount;
                }
            }
            
            // Calcular cambios porcentuales
            $calcChange = function($current, $previous) {
                if ($previous == 0) return $current > 0 ? 100 : 0;
                return round((($current - $previous) / $previous) * 100, 1);
            };
            
            $currentAvgFirstResp = $currentWf['first_resp_count'] > 0 ? $currentWf['first_resp_time'] / $currentWf['first_resp_count'] : 0;
            $prevAvgFirstResp = $prevWf['first_resp_count'] > 0 ? $prevWf['first_resp_time'] / $prevWf['first_resp_count'] : 0;
            
            $currentAvgRes = $currentWf['res_count'] > 0 ? $currentWf['res_time'] / $currentWf['res_count'] : 0;
            $prevAvgRes = $prevWf['res_count'] > 0 ? $prevWf['res_time'] / $prevWf['res_count'] : 0;
            
            echo json_encode([
                'success' => true,
                'comparison' => [
                    'conversations' => [
                        'metric' => 'Conversaciones Totales',
                        'current' => $currentConv['open'] + $currentConv['closed'],
                        'previous' => $prevConv['open'] + $prevConv['closed'],
                        'change_percent' => $calcChange($currentConv['open'] + $currentConv['closed'], $prevConv['open'] + $prevConv['closed']),
                        'trend' => ($currentConv['open'] + $currentConv['closed']) >= ($prevConv['open'] + $prevConv['closed']) ? 'up' : 'down'
                    ],
                    'closed' => [
                        'metric' => 'Conversaciones Cerradas',
                        'current' => $currentWf['closed'],
                        'previous' => $prevWf['closed'],
                        'change_percent' => $calcChange($currentWf['closed'], $prevWf['closed']),
                        'trend' => $currentWf['closed'] >= $prevWf['closed'] ? 'up' : 'down'
                    ],
                    'resolution_rate' => [
                        'metric' => 'Tasa de Resolución',
                        'current' => $currentWf['opened'] > 0 ? round(($currentWf['closed'] / $currentWf['opened']) * 100, 1) : 0,
                        'previous' => $prevWf['opened'] > 0 ? round(($prevWf['closed'] / $prevWf['opened']) * 100, 1) : 0,
                        'change_percent' => $calcChange(
                            $currentWf['opened'] > 0 ? ($currentWf['closed'] / $currentWf['opened']) * 100 : 0,
                            $prevWf['opened'] > 0 ? ($prevWf['closed'] / $prevWf['opened']) * 100 : 0
                        ),
                        'trend' => ($currentWf['opened'] > 0 ? $currentWf['closed'] / $currentWf['opened'] : 0) >= ($prevWf['opened'] > 0 ? $prevWf['closed'] / $prevWf['opened'] : 0) ? 'up' : 'down'
                    ],
                    'avg_first_response' => [
                        'metric' => 'Tiempo Primera Respuesta (seg)',
                        'current' => round($currentAvgFirstResp),
                        'previous' => round($prevAvgFirstResp),
                        'change_percent' => $calcChange($currentAvgFirstResp, $prevAvgFirstResp),
                        'trend' => $currentAvgFirstResp <= $prevAvgFirstResp ? 'up' : 'down', // menor es mejor
                        'is_lower_better' => true
                    ],
                    'avg_resolution' => [
                        'metric' => 'Tiempo Resolución (seg)',
                        'current' => round($currentAvgRes),
                        'previous' => round($prevAvgRes),
                        'change_percent' => $calcChange($currentAvgRes, $prevAvgRes),
                        'trend' => $currentAvgRes <= $prevAvgRes ? 'up' : 'down', // menor es mejor
                        'is_lower_better' => true
                    ]
                ],
                'periods' => [
                    'current' => ['start' => $currentStart, 'end' => $currentEnd],
                    'previous' => ['start' => $prevStart, 'end' => $prevEnd],
                    'days_per_period' => $daysDiff
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        // ================================================================
        // EXPORTAR DATOS
        // ================================================================
        case 'export_data':
            $format = $_GET['format'] ?? 'json';
            
            $endpoints = [
                'conversations' => 'dashboard/metrics/consolidated-conversations?' . $datesParam,
                'agent_conversations' => 'dashboard/metrics/agent-conversations?' . $datesParam,
                'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate,
                'workflow' => 'reports/volume-of-workflow?start_date=' . $startDate . '&end_date=' . $endDate,
                'users' => 'users'
            ];
            
            $results = wasapiMultiRequest($endpoints);
            
            // Preparar datos para exportación
            $exportData = [
                'generated_at' => date('Y-m-d H:i:s'),
                'period' => ['start' => $startDate, 'end' => $endDate],
                'conversations_summary' => $results['conversations']['conversations']['data'] ?? [],
                'agent_conversations' => $results['agent_conversations']['conversations'] ?? [],
                'agent_performance' => $results['performance']['data'] ?? [],
                'daily_workflow' => $results['workflow']['data'] ?? [],
                'users' => array_map(fn($u) => [
                    'id' => $u['id'],
                    'name' => $u['name'],
                    'email' => $u['email']
                ], $results['users']['users'] ?? [])
            ];
            
            echo json_encode([
                'success' => true,
                'data' => $exportData,
                'row_counts' => [
                    'conversations' => count($exportData['conversations_summary']),
                    'agent_conversations' => count($exportData['agent_conversations']),
                    'agent_performance' => count($exportData['agent_performance']),
                    'daily_workflow' => count($exportData['daily_workflow']),
                    'users' => count($exportData['users'])
                ]
            ]);
            break;
            
        // ================================================================
        // ANÁLISIS DE STAFFING Y DEMANDA
        // ================================================================
        case 'staffing':
            $endpoints = [
                'workflow' => 'reports/volume-of-workflow?start_date=' . $startDate . '&end_date=' . $endDate,
                'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate,
                'online_agents' => 'dashboard/metrics/online-agents'
            ];
            
            $results = wasapiMultiRequest($endpoints);
            
            // Agrupar por día de la semana (0=Domingo, 1=Lunes, etc.)
            $weekdayData = [];
            $dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
            $dayNamesShort = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
            
            // Inicializar todos los días
            for ($i = 0; $i < 7; $i++) {
                $weekdayData[$i] = [
                    'day_number' => $i,
                    'day_name' => $dayNames[$i],
                    'day_short' => $dayNamesShort[$i],
                    'total_conversations' => 0,
                    'total_closed' => 0,
                    'total_resolution_time' => 0,
                    'total_first_response_time' => 0,
                    'total_first_response_count' => 0,
                    'occurrences' => 0,
                    'agents_active' => []
                ];
            }
            
            // Procesar datos de workflow por día
            foreach ($results['workflow']['data'] ?? [] as $wf) {
                $date = substr($wf['date'] ?? '', 0, 10);
                if (empty($date)) continue;
                
                $dayOfWeek = intval(date('w', strtotime($date))); // 0=Domingo
                $agentId = $wf['agent_id'] ?? 0;
                
                $opened = intval($wf['total_open_conversations'] ?? 0);
                $closed = intval($wf['total_close_conversations'] ?? 0);
                
                $weekdayData[$dayOfWeek]['total_conversations'] += $opened + $closed;
                $weekdayData[$dayOfWeek]['total_closed'] += $closed;
                $weekdayData[$dayOfWeek]['total_resolution_time'] += floatval($wf['total_resolution_time'] ?? 0);
                $weekdayData[$dayOfWeek]['total_first_response_time'] += floatval($wf['total_first_response_time'] ?? 0);
                $weekdayData[$dayOfWeek]['total_first_response_count'] += intval($wf['total_first_response_count'] ?? 0);
                
                if ($agentId > 0 && !in_array($agentId, $weekdayData[$dayOfWeek]['agents_active'])) {
                    $weekdayData[$dayOfWeek]['agents_active'][] = $agentId;
                }
                
                // Contar ocurrencias únicas de fechas
                if (!isset($weekdayData[$dayOfWeek]['dates'])) {
                    $weekdayData[$dayOfWeek]['dates'] = [];
                }
                if (!in_array($date, $weekdayData[$dayOfWeek]['dates'])) {
                    $weekdayData[$dayOfWeek]['dates'][] = $date;
                    $weekdayData[$dayOfWeek]['occurrences']++;
                }
            }
            
            // Calcular promedios y métricas por productividad
            $agentProductivity = [];
            foreach ($results['performance']['data'] ?? [] as $p) {
                $agentId = $p['agent_id'] ?? 0;
                if (!isset($agentProductivity[$agentId])) {
                    $agentProductivity[$agentId] = 0;
                }
                $agentProductivity[$agentId] += intval($p['total_close_conversations'] ?? 0);
            }
            
            // Calcular productividad promedio por agente
            $totalAgents = count($agentProductivity);
            $totalClosed = array_sum($agentProductivity);
            $avgProductivityPerAgent = $totalAgents > 0 ? round($totalClosed / $totalAgents / max($daysDiff, 1), 1) : 0;
            
            // Capacidad estimada por agente (basada en datos reales)
            $capacityPerAgent = max(5, $avgProductivityPerAgent); // Mínimo 5 por día
            
            // Procesar datos diarios
            $dailyAnalysis = [];
            $maxConv = 0;
            $minConv = PHP_INT_MAX;
            $peakDayIdx = 0;
            $lowDayIdx = 0;
            
            // Reordenar para empezar en Lunes (1)
            $orderedDays = [1, 2, 3, 4, 5, 6, 0]; // Lunes a Domingo
            
            foreach ($orderedDays as $idx => $dayNum) {
                $data = $weekdayData[$dayNum];
                $occurrences = max($data['occurrences'], 1);
                
                $avgConv = round($data['total_conversations'] / $occurrences);
                $avgClosed = round($data['total_closed'] / $occurrences);
                $avgResTime = $data['total_closed'] > 0 
                    ? $data['total_resolution_time'] / $data['total_closed'] : 0;
                
                // Tasa de resolución
                $resolutionRate = $data['total_conversations'] > 0 
                    ? round(($data['total_closed'] / $data['total_conversations']) * 100, 1) : 0;
                
                // Agentes sugeridos basado en carga
                $suggestedAgents = $capacityPerAgent > 0 
                    ? max(1, ceil($avgConv / $capacityPerAgent)) : 1;
                
                // Determinar día pico/bajo
                if ($avgConv > $maxConv) {
                    $maxConv = $avgConv;
                    $peakDayIdx = $dayNum;
                }
                if ($avgConv < $minConv && $avgConv > 0) {
                    $minConv = $avgConv;
                    $lowDayIdx = $dayNum;
                }
                
                $dailyAnalysis[] = [
                    'day_number' => $dayNum,
                    'day_name' => $data['day_name'],
                    'day_short' => $data['day_short'],
                    'avg_conversations' => $avgConv,
                    'total_conversations' => $data['total_conversations'],
                    'total_closed' => $data['total_closed'],
                    'resolution_rate' => $resolutionRate,
                    'avg_resolution_time' => round($avgResTime),
                    'suggested_agents' => $suggestedAgents,
                    'agents_count' => count($data['agents_active']),
                    'occurrences' => $occurrences,
                    'is_peak' => false,
                    'is_low' => false,
                    'demand_level' => 0,
                    'status' => 'normal'
                ];
            }
            
            // Marcar pico y bajo, calcular niveles de demanda
            foreach ($dailyAnalysis as &$day) {
                $day['is_peak'] = $day['day_number'] === $peakDayIdx;
                $day['is_low'] = $day['day_number'] === $lowDayIdx;
                
                // Nivel de demanda (0-100%)
                $day['demand_level'] = $maxConv > 0 
                    ? round(($day['avg_conversations'] / $maxConv) * 100) : 0;
                
                // Estado basado en nivel de demanda
                if ($day['demand_level'] >= 90) {
                    $day['status'] = 'critical';
                } elseif ($day['demand_level'] >= 70) {
                    $day['status'] = 'high';
                } elseif ($day['demand_level'] >= 40) {
                    $day['status'] = 'normal';
                } else {
                    $day['status'] = 'low';
                }
            }
            unset($day);
            
            // Variación porcentual
            $variation = $minConv > 0 ? round((($maxConv - $minConv) / $minConv) * 100, 1) : 0;
            
            // Agentes online actuales
            $onlineAgents = 0;
            $totalRegisteredAgents = 0;
            foreach ($results['online_agents']['users'] ?? [] as $u) {
                $totalRegisteredAgents++;
                if (isset($u['online']) && $u['online'] == 1) $onlineAgents++;
            }
            
            // Calcular recomendaciones
            $allSuggested = array_column($dailyAnalysis, 'suggested_agents');
            $minAgents = min($allSuggested);
            $maxAgents = max($allSuggested);
            $avgAgents = round(array_sum($allSuggested) / count($allSuggested));
            
            // Distribución semanal óptima
            $weeklyDistribution = [];
            foreach ($dailyAnalysis as $day) {
                $weeklyDistribution[$day['day_short']] = $day['suggested_agents'];
            }
            
            // Generar alertas
            $alerts = [];
            
            // Alerta de día pico
            $peakDay = $dailyAnalysis[array_search($peakDayIdx, array_column($dailyAnalysis, 'day_number'))];
            if ($peakDay && $maxAgents > $onlineAgents) {
                $alerts[] = [
                    'id' => 1,
                    'type' => 'danger',
                    'title' => 'Personal insuficiente para días pico',
                    'message' => "El {$peakDay['day_name']} necesitas {$maxAgents} agentes, pero solo tienes {$onlineAgents} registrados online."
                ];
            }
            
            // Alerta de variación alta
            if ($variation > 50) {
                $alerts[] = [
                    'id' => 2,
                    'type' => 'warning',
                    'title' => 'Alta variación de demanda',
                    'message' => "La demanda varía un {$variation}% entre días. Considera ajustar turnos."
                ];
            }
            
            // Alerta de eficiencia
            $avgResRate = count($dailyAnalysis) > 0 
                ? round(array_sum(array_column($dailyAnalysis, 'resolution_rate')) / count($dailyAnalysis), 1) : 0;
            if ($avgResRate < 70) {
                $alerts[] = [
                    'id' => 3,
                    'type' => 'warning',
                    'title' => 'Tasa de resolución baja',
                    'message' => "La tasa promedio de resolución es {$avgResRate}%. Considera capacitar al equipo."
                ];
            }
            
            // Calcular métricas adicionales
            $totalConvAll = array_sum(array_column($dailyAnalysis, 'total_conversations'));
            $avgLoad = $totalAgents > 0 ? round($totalConvAll / $totalAgents / max($daysDiff, 1), 1) : 0;
            $coverageRate = $totalRegisteredAgents >= $avgAgents ? 100 : round(($totalRegisteredAgents / $avgAgents) * 100);
            
            // Tendencia de demanda (basada en últimos días vs primeros)
            $midpoint = floor(count($dailyAnalysis) / 2);
            $firstHalf = array_slice($dailyAnalysis, 0, $midpoint);
            $secondHalf = array_slice($dailyAnalysis, $midpoint);
            $avgFirst = count($firstHalf) > 0 ? array_sum(array_column($firstHalf, 'avg_conversations')) / count($firstHalf) : 0;
            $avgSecond = count($secondHalf) > 0 ? array_sum(array_column($secondHalf, 'avg_conversations')) / count($secondHalf) : 0;
            
            $demandTrend = 'stable';
            if ($avgSecond > $avgFirst * 1.1) $demandTrend = 'increasing';
            elseif ($avgSecond < $avgFirst * 0.9) $demandTrend = 'decreasing';
            
            echo json_encode([
                'success' => true,
                'daily_analysis' => $dailyAnalysis,
                'peak_day' => [
                    'name' => $weekdayData[$peakDayIdx]['day_name'],
                    'avg_conversations' => $maxConv
                ],
                'low_day' => [
                    'name' => $weekdayData[$lowDayIdx]['day_name'],
                    'avg_conversations' => $minConv
                ],
                'variation_percent' => $variation,
                'productivity_per_agent' => $avgProductivityPerAgent,
                'suggested_agents' => [
                    'peak' => $maxAgents,
                    'low' => $minAgents,
                    'average' => $avgAgents
                ],
                'recommendations' => [
                    'min_agents' => $minAgents,
                    'max_agents' => $maxAgents,
                    'avg_agents' => $avgAgents,
                    'capacity_per_agent' => round($capacityPerAgent, 1)
                ],
                'weekly_distribution' => $weeklyDistribution,
                'alerts' => $alerts,
                'efficiency_score' => $avgResRate,
                'coverage_rate' => $coverageRate,
                'avg_wait_time' => formatTime(0), // No disponible directamente
                'avg_load' => $avgLoad,
                'demand_trend' => $demandTrend,
                'current_agents' => [
                    'online' => $onlineAgents,
                    'total' => $totalRegisteredAgents
                ],
                'period' => ['start' => $startDate, 'end' => $endDate, 'days' => $daysDiff],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            throw new Exception('Acción no válida: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// Helper function para ordenar
function collect_sorted($array, $key, $desc = true) {
    usort($array, fn($a, $b) => $desc 
        ? ($b[$key] ?? 0) <=> ($a[$key] ?? 0) 
        : ($a[$key] ?? 0) <=> ($b[$key] ?? 0)
    );
    return $array;
}
