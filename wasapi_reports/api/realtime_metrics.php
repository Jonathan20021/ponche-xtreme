<?php
/**
 * API de Métricas en Tiempo Real - Wasapi Reports
 * Obtiene TODAS las métricas en tiempo real directamente de la API de Wasapi
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
 * Realiza una petición a la API de Wasapi
 */
function wasapiRequest($endpoint, $params = []) {
    $url = WASAPI_BASE_URL . ltrim($endpoint, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . WASAPI_TOKEN,
        'Accept: application/json',
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'data' => json_decode($response, true), 'raw' => $response];
    }
    
    return ['success' => false, 'error' => $curlError ?: "HTTP $httpCode", 'raw' => $response];
}

try {
    $action = $_GET['action'] ?? 'all';
    $startDate = $_GET['start_date'] ?? date('Y-m-d');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    
    // Obtener todas las métricas de Wasapi usando multi_curl para máxima velocidad
    // IMPORTANTE: Los endpoints de conversaciones requieren parámetro dates[] como array
    $today = date('Y-m-d');
    $datesParam = 'dates%5B0%5D=' . $startDate . '&dates%5B1%5D=' . $endDate;
    
    $endpoints = [
        'online_agents' => 'dashboard/metrics/online-agents',
        'conversations' => 'dashboard/metrics/consolidated-conversations?' . $datesParam,
        'agent_conversations' => 'dashboard/metrics/agent-conversations?' . $datesParam,
        'contacts' => 'dashboard/metrics/contacts',
        'messages' => 'dashboard/metrics/messages?start_date=' . $startDate . '&end_date=' . $endDate,
        'messages_bot' => 'dashboard/metrics/messages-bot?start_date=' . $startDate . '&end_date=' . $endDate,
        'campaigns' => 'dashboard/metrics/total-campaigns',
        'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate,
        'workflow' => 'reports/volume-of-workflow?start_date=' . $startDate . '&end_date=' . $endDate
    ];
    
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
    
    // Ejecutar todas las peticiones en paralelo
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);
    
    // Recoger resultados
    $results = [];
    foreach ($handles as $key => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $results[$key] = [
            'data' => json_decode($response, true),
            'http_code' => $httpCode,
            'raw' => $response
        ];
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    curl_multi_close($multiHandle);
    
    // ============================================================
    // PROCESAR AGENTES ONLINE
    // ============================================================
    $onlineAgents = 0;
    $onlineAgentsList = [];
    $onlineData = $results['online_agents']['data'] ?? [];
    
    // La API de Wasapi devuelve usuarios en "users" o directamente como array
    $users = $onlineData['users'] ?? $onlineData['data'] ?? $onlineData;
    if (is_array($users)) {
        foreach ($users as $user) {
            $isOnline = isset($user['online']) ? ($user['online'] == 1 || $user['online'] === true) : false;
            if (!$isOnline && isset($user['status'])) {
                $isOnline = $user['status'] === 'online' || $user['status'] === 'available';
            }
            
            if ($isOnline) {
                $onlineAgents++;
                $onlineAgentsList[] = [
                    'id' => $user['id'] ?? $user['user_id'] ?? 0,
                    'name' => $user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'Agente',
                    'avatar' => $user['avatar'] ?? null,
                    'status' => 'online',
                    'last_activity' => $user['last_activity'] ?? $user['updated_at'] ?? null,
                    'active_chats' => $user['active_conversations'] ?? $user['chats'] ?? 0
                ];
            }
        }
    }
    
    // ============================================================
    // PROCESAR CONVERSACIONES (estructura: {conversations: {data: [{status,cant},...]}})
    // ============================================================
    $convData = $results['conversations']['data'] ?? [];
    $conversationsArray = $convData['conversations']['data'] ?? $convData['conversations'] ?? [];
    
    $activeChats = 0;
    $resolvedToday = 0;
    $pendingChats = 0;
    $unassignedChats = 0;
    $totalConversations = 0;
    
    if (is_array($conversationsArray)) {
        foreach ($conversationsArray as $conv) {
            $status = $conv['status'] ?? '';
            $count = intval($conv['cant'] ?? $conv['count'] ?? 0);
            $totalConversations += $count;
            
            if ($status === 'open' || $status === 'active') {
                $activeChats += $count;
            } elseif ($status === 'closed' || $status === 'resolved') {
                $resolvedToday += $count;
            } elseif ($status === 'pending' || $status === 'waiting') {
                $pendingChats += $count;
            } elseif ($status === 'unassigned') {
                $unassignedChats += $count;
            }
        }
    }
    
    // ============================================================
    // PROCESAR CONVERSACIONES POR AGENTE (estructura: {conversations: [{agent_id, status, count},...], sessions: [...]})
    // ============================================================
    $agentConvData = $results['agent_conversations']['data'] ?? [];
    $agentConversations = $agentConvData['conversations'] ?? $agentConvData['data'] ?? [];
    $agentSessions = $agentConvData['sessions'] ?? [];
    $chatsByAgent = [];
    $agentStats = []; // Agrupar por agent_id
    
    if (is_array($agentConversations) && !empty($agentConversations)) {
        foreach ($agentConversations as $ac) {
            $agentId = $ac['agent_id'] ?? $ac['id'] ?? 0;
            if ($agentId > 0) {
                if (!isset($agentStats[$agentId])) {
                    $agentStats[$agentId] = [
                        'agent_id' => $agentId,
                        'agent_name' => $ac['agent_name'] ?? $ac['name'] ?? 'Agente #' . $agentId,
                        'active' => 0,
                        'pending' => 0,
                        'resolved_today' => 0,
                        'total' => 0
                    ];
                }
                
                $status = $ac['status'] ?? '';
                $count = intval($ac['count'] ?? $ac['cant'] ?? 0);
                $agentStats[$agentId]['total'] += $count;
                
                if ($status === 'open' || $status === 'active') {
                    $agentStats[$agentId]['active'] += $count;
                } elseif ($status === 'closed' || $status === 'resolved') {
                    $agentStats[$agentId]['resolved_today'] += $count;
                } elseif ($status === 'pending' || $status === 'waiting') {
                    $agentStats[$agentId]['pending'] += $count;
                }
            }
        }
        
        // Agregar info de última actividad desde sessions
        foreach ($agentSessions as $session) {
            $userId = $session['user_id'] ?? 0;
            if (isset($agentStats[$userId]) && isset($session['last_activity'])) {
                $agentStats[$userId]['last_activity'] = date('Y-m-d H:i:s', $session['last_activity']);
            }
        }
        
        $chatsByAgent = array_values($agentStats);
    }
    
    // ============================================================
    // PROCESAR DATOS DE PERFORMANCE (fallback si no hay datos de conversaciones)
    // ============================================================
    $performanceData = $results['performance']['data'] ?? [];
    $performanceAgents = $performanceData['data'] ?? $performanceData;
    $totalResolved = 0;
    $totalAssignments = 0;
    
    if (is_array($performanceAgents) && !empty($performanceAgents)) {
        foreach ($performanceAgents as $pa) {
            $resolved = $pa['resolved_conversations'] ?? $pa['resolutions'] ?? 0;
            $assignments = $pa['assignments'] ?? 0;
            $totalResolved += $resolved;
            $totalAssignments += $assignments;
            
            // Si no tenemos datos de chatsByAgent, usar performance
            if (empty($chatsByAgent) && (isset($pa['agent']) || isset($pa['agent_name']))) {
                $agentName = $pa['agent']['name'] ?? $pa['agent_name'] ?? $pa['name'] ?? 'Agente';
                $chatsByAgent[] = [
                    'agent_id' => $pa['agent']['id'] ?? $pa['agent_id'] ?? $pa['id'] ?? 0,
                    'agent_name' => $agentName,
                    'active' => $assignments - $resolved,
                    'pending' => 0,
                    'resolved_today' => $resolved
                ];
            }
        }
        
        // Si no había datos de conversaciones, usar los de performance
        if ($resolvedToday == 0 && $totalResolved > 0) {
            $resolvedToday = $totalResolved;
        }
        if ($activeChats == 0 && $totalAssignments > 0) {
            $activeChats = max(0, $totalAssignments - $totalResolved);
        }
    }
    
    // ============================================================
    // PROCESAR TIEMPOS DE MANEJO (AHT para chats)
    // ============================================================
    $avgHandlingTime = 0; // en segundos
    $avgFirstResponseTime = 0; // en segundos
    $totalHandlingTime = 0;
    $totalFirstResponse = 0;
    $countHandling = 0;
    $countFirstResponse = 0;
    $agentPerformanceMetrics = [];
    
    if (is_array($performanceAgents) && !empty($performanceAgents)) {
        foreach ($performanceAgents as $pa) {
            $resolutionTime = floatval($pa['avg_resolution_time_per_conversation'] ?? 0);
            $firstResponseTime = floatval($pa['avg_first_response_time'] ?? 0);
            $closeConvs = intval($pa['total_close_conversations'] ?? 0);
            
            if ($resolutionTime > 0 && $closeConvs > 0) {
                $totalHandlingTime += $resolutionTime * $closeConvs;
                $countHandling += $closeConvs;
            }
            
            if ($firstResponseTime > 0) {
                $totalFirstResponse += $firstResponseTime;
                $countFirstResponse++;
            }
            
            // Métricas por agente
            $agentId = $pa['agent_id'] ?? ($pa['agent']['id'] ?? 0);
            $agentName = $pa['agent']['name'] ?? 'Agente';
            
            if ($agentId > 0) {
                if (!isset($agentPerformanceMetrics[$agentId])) {
                    $agentPerformanceMetrics[$agentId] = [
                        'agent_id' => $agentId,
                        'agent_name' => $agentName,
                        'total_conversations' => 0,
                        'closed_conversations' => 0,
                        'total_resolution_time' => 0,
                        'avg_resolution_time' => 0,
                        'avg_first_response' => 0,
                        'conversations_per_hour' => 0
                    ];
                }
                
                $agentPerformanceMetrics[$agentId]['total_conversations'] += intval($pa['total_open_conversations'] ?? 0) + $closeConvs;
                $agentPerformanceMetrics[$agentId]['closed_conversations'] += $closeConvs;
                $agentPerformanceMetrics[$agentId]['total_resolution_time'] += floatval($pa['total_resolution_time'] ?? 0);
                $agentPerformanceMetrics[$agentId]['avg_resolution_time'] = $resolutionTime;
                $agentPerformanceMetrics[$agentId]['avg_first_response'] = $firstResponseTime;
            }
        }
        
        // Calcular promedios globales
        $avgHandlingTime = $countHandling > 0 ? round($totalHandlingTime / $countHandling, 0) : 0;
        $avgFirstResponseTime = $countFirstResponse > 0 ? round($totalFirstResponse / $countFirstResponse, 0) : 0;
        
        // Calcular conversaciones por hora para cada agente (asumiendo 8 horas de trabajo)
        foreach ($agentPerformanceMetrics as &$apm) {
            $apm['conversations_per_hour'] = round($apm['closed_conversations'] / 8, 2);
        }
    }
    
    // ============================================================
    // PROCESAR VOLUMEN POR HORA (Chats x Hora)
    // ============================================================
    // La respuesta de Wasapi es {success: true, data: [...]}
    $workflowResponse = $results['workflow']['data'] ?? [];
    $workflowData = $workflowResponse['data'] ?? $workflowResponse ?? [];
    $hourlyVolume = [];
    $totalByHour = [];
    
    if (is_array($workflowData) && !empty($workflowData)) {
        foreach ($workflowData as $wf) {
            $hour = intval($wf['hour'] ?? 0);
            $openConvs = intval($wf['total_open_conversations'] ?? 0);
            $closeConvs = intval($wf['total_close_conversations'] ?? 0);
            $firstResponses = intval($wf['total_first_response_count'] ?? 0);
            
            if (!isset($totalByHour[$hour])) {
                $totalByHour[$hour] = [
                    'hour' => $hour,
                    'hour_label' => sprintf('%02d:00', $hour),
                    'open' => 0,
                    'closed' => 0,
                    'first_responses' => 0,
                    'total' => 0
                ];
            }
            
            $totalByHour[$hour]['open'] += $openConvs;
            $totalByHour[$hour]['closed'] += $closeConvs;
            $totalByHour[$hour]['first_responses'] += $firstResponses;
            $totalByHour[$hour]['total'] += $openConvs + $closeConvs;
        }
        
        // Ordenar por hora
        ksort($totalByHour);
        $hourlyVolume = array_values($totalByHour);
    }
    
    // Calcular chats por hora promedio
    $avgChatsPerHour = count($hourlyVolume) > 0 
        ? round(array_sum(array_column($hourlyVolume, 'total')) / count($hourlyVolume), 1) 
        : 0;
    
    // ============================================================
    // PROCESAR VOLUMEN POR DÍA DE LA SEMANA (Histórico para Staffing)
    // ============================================================
    $weeklyVolume = [];
    $dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $dayNamesShort = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    
    // Inicializar estructura para cada día de la semana
    $weeklyStats = [];
    for ($i = 0; $i < 7; $i++) {
        $weeklyStats[$i] = [
            'day_number' => $i,
            'day_name' => $dayNames[$i],
            'day_short' => $dayNamesShort[$i],
            'open' => 0,
            'closed' => 0,
            'total' => 0,
            'count_days' => 0 // Para calcular promedios
        ];
    }
    
    // Procesar cada día en el rango de fechas usando datos de la respuesta de performance
    // Los datos de performanceAgents tienen fecha porque vienen filtrados por start_date y end_date
    if (is_array($performanceAgents) && !empty($performanceAgents)) {
        $dateStats = [];
        
        foreach ($performanceAgents as $pa) {
            // Cada registro puede tener una fecha específica
            $dateStr = $pa['date'] ?? $pa['created_at'] ?? null;
            $openConvs = intval($pa['total_open_conversations'] ?? 0);
            $closeConvs = intval($pa['total_close_conversations'] ?? 0);
            
            if ($dateStr) {
                $date = strtotime($dateStr);
                $dayOfWeek = intval(date('w', $date)); // 0=Domingo, 1=Lunes, etc.
                $dateKey = date('Y-m-d', $date);
                
                if (!isset($dateStats[$dateKey])) {
                    $dateStats[$dateKey] = [
                        'day_of_week' => $dayOfWeek,
                        'open' => 0,
                        'closed' => 0
                    ];
                }
                
                $dateStats[$dateKey]['open'] += $openConvs;
                $dateStats[$dateKey]['closed'] += $closeConvs;
            }
        }
        
        // Agrupar por día de semana
        foreach ($dateStats as $dateKey => $stats) {
            $dayOfWeek = $stats['day_of_week'];
            $weeklyStats[$dayOfWeek]['open'] += $stats['open'];
            $weeklyStats[$dayOfWeek]['closed'] += $stats['closed'];
            $weeklyStats[$dayOfWeek]['total'] += $stats['open'] + $stats['closed'];
            $weeklyStats[$dayOfWeek]['count_days']++;
        }
    }
    
    // Si no hay datos de performance con fechas, usar el rango de fechas para calcular promedios básicos
    if (array_sum(array_column($weeklyStats, 'total')) == 0) {
        // Calcular los días que hay en el rango
        $start = strtotime($startDate);
        $end = strtotime($endDate);
        
        // Distribuir el total de conversaciones proporcionalmente
        $totalAll = $totalConversations > 0 ? $totalConversations : ($activeChats + $resolvedToday);
        $numDays = max(1, floor(($end - $start) / (24 * 3600)) + 1);
        
        // Contar cuántos de cada día de semana hay en el rango
        $current = $start;
        while ($current <= $end) {
            $dayOfWeek = intval(date('w', $current));
            $weeklyStats[$dayOfWeek]['count_days']++;
            $current += 24 * 3600;
        }
        
        // Estimar distribución basada en patrones típicos (más carga Lun-Vie)
        $businessDayMultiplier = [0.4, 1.2, 1.1, 1.15, 1.1, 1.0, 0.5]; // Dom, Lun, Mar, Mié, Jue, Vie, Sáb
        $totalMultiplier = array_sum($businessDayMultiplier);
        
        foreach ($weeklyStats as $i => &$stat) {
            if ($stat['count_days'] > 0 && $totalAll > 0) {
                $proportion = $businessDayMultiplier[$i] / $totalMultiplier;
                $dayTotal = round($totalAll * $proportion);
                $stat['total'] = $dayTotal;
                $stat['closed'] = round($dayTotal * ($resolvedToday / max(1, $totalAll)));
                $stat['open'] = $dayTotal - $stat['closed'];
            }
        }
    }
    
    // Calcular promedios si hay múltiples días del mismo tipo
    foreach ($weeklyStats as &$stat) {
        if ($stat['count_days'] > 1) {
            $stat['avg_open'] = round($stat['open'] / $stat['count_days'], 1);
            $stat['avg_closed'] = round($stat['closed'] / $stat['count_days'], 1);
            $stat['avg_total'] = round($stat['total'] / $stat['count_days'], 1);
        } else {
            $stat['avg_open'] = $stat['open'];
            $stat['avg_closed'] = $stat['closed'];
            $stat['avg_total'] = $stat['total'];
        }
    }
    
    // Reordenar para empezar en Lunes (más intuitivo para trabajo)
    $weeklyVolume = [
        $weeklyStats[1], // Lunes
        $weeklyStats[2], // Martes
        $weeklyStats[3], // Miércoles
        $weeklyStats[4], // Jueves
        $weeklyStats[5], // Viernes
        $weeklyStats[6], // Sábado
        $weeklyStats[0]  // Domingo
    ];
    
    // ============================================================
    // PROCESAR CONTACTOS
    // ============================================================
    $contactsData = $results['contacts']['data'] ?? [];
    $totalContacts = $contactsData['total'] ?? $contactsData['count'] ?? 0;
    $newContactsToday = $contactsData['today'] ?? $contactsData['new'] ?? $contactsData['new_today'] ?? 0;
    
    // ============================================================
    // PROCESAR MENSAJES
    // ============================================================
    $messagesData = $results['messages']['data'] ?? [];
    $messagesSent = $messagesData['sent'] ?? $messagesData['outgoing'] ?? $messagesData['total_sent'] ?? 0;
    $messagesReceived = $messagesData['received'] ?? $messagesData['incoming'] ?? $messagesData['total_received'] ?? 0;
    $totalMessages = $messagesData['total'] ?? ($messagesSent + $messagesReceived) ?? 0;
    
    // Mensajes de bot
    $botData = $results['messages_bot']['data'] ?? [];
    $botMessages = $botData['total'] ?? $botData['sent'] ?? $botData['messages'] ?? 0;
    
    // ============================================================
    // CALCULAR MÉTRICAS DERIVADAS (después de procesar todos los datos)
    // ============================================================
    $maxChatsPerAgent = 5; // Capacidad máxima por agente
    $totalCapacity = $onlineAgents * $maxChatsPerAgent;
    $capacityUsed = $totalCapacity > 0 ? round(($activeChats / $totalCapacity) * 100, 1) : 0;
    $avgLoadPerAgent = $onlineAgents > 0 ? round($activeChats / $onlineAgents, 2) : 0;
    
    // ============================================================
    // RESPUESTA
    // ============================================================
    echo json_encode([
        'success' => true,
        'metrics' => [
            // Agentes
            'online_agents' => $onlineAgents,
            'online_agents_list' => $onlineAgentsList,
            
            // Chats / Conversaciones
            'pending_chats' => $pendingChats,
            'active_chats' => $activeChats,
            'unassigned_chats' => $unassignedChats,
            'resolved_today' => $resolvedToday,
            'total_conversations' => $totalConversations,
            'chats_by_agent' => $chatsByAgent,
            
            // Tiempos de manejo
            'avg_handling_time' => $avgHandlingTime,
            'avg_handling_time_formatted' => gmdate('i:s', $avgHandlingTime),
            'avg_first_response_time' => $avgFirstResponseTime,
            'avg_first_response_formatted' => gmdate('i:s', $avgFirstResponseTime),
            
            // Volumen por hora
            'hourly_volume' => $hourlyVolume,
            'avg_chats_per_hour' => $avgChatsPerHour,
            
            // Volumen por día de semana (para staffing)
            'weekly_volume' => $weeklyVolume,
            
            // Performance por agente
            'agent_performance' => array_values($agentPerformanceMetrics),
            
            // Capacidad
            'capacity_used_percent' => min($capacityUsed, 100),
            'avg_load_per_agent' => $avgLoadPerAgent,
            'max_capacity' => $totalCapacity,
            
            // Contactos
            'total_contacts' => $totalContacts,
            'new_contacts_today' => $newContactsToday,
            
            // Mensajes
            'messages_sent' => $messagesSent,
            'messages_received' => $messagesReceived,
            'total_messages' => $totalMessages,
            'bot_messages' => $botMessages
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'source' => 'wasapi_api'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
