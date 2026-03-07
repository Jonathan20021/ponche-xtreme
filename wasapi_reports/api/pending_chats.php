<?php
/**
 * API de Chats en Espera - Wasapi Reports
 * Monitoreo en tiempo real de conversaciones pendientes - TODO desde Wasapi API
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
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    
    return null;
}

try {
    $action = $_GET['action'] ?? 'status';
    
    // Accept date parameters or default to today
    $startDate = $_GET['start_date'] ?? date('Y-m-d');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    
    // Formato requerido por Wasapi API: dates[] array
    $datesParam = 'dates%5B0%5D=' . $startDate . '&dates%5B1%5D=' . $endDate;
    
    switch ($action) {
        case 'status':
            // Obtener todos los datos de Wasapi en paralelo
            $endpoints = [
                'conversations' => 'dashboard/metrics/consolidated-conversations?' . $datesParam,
                'agent_conversations' => 'dashboard/metrics/agent-conversations?' . $datesParam,
                'online_agents' => 'dashboard/metrics/online-agents'
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
            
            // Procesar conversaciones consolidadas (estructura: {conversations: {data: [{status,cant},...]}})
            $conv = $results['conversations'] ?? [];
            $conversationsArray = $conv['conversations']['data'] ?? $conv['conversations'] ?? [];
            
            $pendingChats = 0;
            $activeChats = 0;
            $unassignedChats = 0;
            $resolvedToday = 0;
            
            if (is_array($conversationsArray)) {
                foreach ($conversationsArray as $c) {
                    $status = $c['status'] ?? '';
                    $count = intval($c['cant'] ?? $c['count'] ?? 0);
                    
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
            
            // Procesar agentes online
            $onlineData = $results['online_agents'] ?? [];
            $users = $onlineData['users'] ?? $onlineData['data'] ?? $onlineData;
            $onlineAgents = 0;
            $agentsList = [];
            
            if (is_array($users)) {
                foreach ($users as $user) {
                    $isOnline = isset($user['online']) ? ($user['online'] == 1 || $user['online'] === true) : false;
                    if (!$isOnline && isset($user['status'])) {
                        $isOnline = $user['status'] === 'online' || $user['status'] === 'available';
                    }
                    
                    if ($isOnline) {
                        $onlineAgents++;
                        $agentsList[] = [
                            'id' => $user['id'] ?? $user['user_id'] ?? 0,
                            'name' => $user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'Agente',
                            'status' => 'online',
                            'active_chats' => $user['active_conversations'] ?? $user['conversations'] ?? $user['chats'] ?? 0
                        ];
                    }
                }
            }
            
            // Calcular métricas de carga
            $maxChatsPerAgent = 5;
            $totalCapacity = $onlineAgents * $maxChatsPerAgent;
            $capacityUsed = $totalCapacity > 0 ? round(($activeChats / $totalCapacity) * 100, 1) : 0;
            $avgLoad = $onlineAgents > 0 ? round($activeChats / $onlineAgents, 2) : 0;
            
            // Generar alertas basadas en datos reales
            $alerts = [];
            
            if ($pendingChats > 10) {
                $alerts[] = [
                    'type' => 'critical',
                    'message' => "Alto volumen: {$pendingChats} chats en espera",
                    'action' => 'Agregar más agentes'
                ];
            } elseif ($pendingChats > 5) {
                $alerts[] = [
                    'type' => 'warning',
                    'message' => "{$pendingChats} chats en espera",
                    'action' => 'Monitorear de cerca'
                ];
            }
            
            if ($unassignedChats > 5) {
                $alerts[] = [
                    'type' => 'critical',
                    'message' => "{$unassignedChats} chats sin asignar",
                    'action' => 'Asignar agentes'
                ];
            }
            
            if ($capacityUsed > 80) {
                $alerts[] = [
                    'type' => 'warning',
                    'message' => "Capacidad al {$capacityUsed}%",
                    'action' => 'Preparar refuerzos'
                ];
            }
            
            if ($onlineAgents === 0 && ($pendingChats > 0 || $activeChats > 0)) {
                $alerts[] = [
                    'type' => 'critical',
                    'message' => 'No hay agentes conectados',
                    'action' => 'Verificar disponibilidad'
                ];
            }
            
            echo json_encode([
                'success' => true,
                'status' => [
                    'pending_chats' => $pendingChats,
                    'active_chats' => $activeChats,
                    'unassigned_chats' => $unassignedChats,
                    'resolved_today' => $resolvedToday,
                    'online_agents' => $onlineAgents,
                    'avg_load_per_agent' => $avgLoad,
                    'capacity_used_percent' => min($capacityUsed, 100),
                    'max_capacity' => $totalCapacity
                ],
                'agents' => $agentsList,
                'alerts' => $alerts,
                'timestamp' => date('Y-m-d H:i:s'),
                'source' => 'wasapi_api',
                'debug' => [
                    'conversations_raw' => $conv,
                    'online_agents_raw' => $onlineData
                ]
            ]);
            break;
            
        case 'agent_load':
            // Carga por agente desde Wasapi
            $data = wasapiRequest('dashboard/metrics/agent-conversations');
            
            $agents = [];
            $agentData = $data['agents'] ?? $data['data'] ?? $data;
            
            if (is_array($agentData)) {
                foreach ($agentData as $agent) {
                    $activeConv = $agent['active'] ?? $agent['active_conversations'] ?? $agent['conversations'] ?? 0;
                    $maxChats = 5;
                    $loadPercent = round(($activeConv / $maxChats) * 100, 1);
                    
                    $agents[] = [
                        'id' => $agent['agent_id'] ?? $agent['id'] ?? $agent['user_id'] ?? 0,
                        'name' => $agent['agent_name'] ?? $agent['name'] ?? 'Agente',
                        'active_conversations' => $activeConv,
                        'max_capacity' => $maxChats,
                        'load_percent' => min($loadPercent, 100),
                        'status' => $loadPercent >= 100 ? 'full' : ($loadPercent >= 80 ? 'busy' : 'available'),
                        'resolved_today' => $agent['resolved'] ?? $agent['resolved_today'] ?? 0
                    ];
                }
            }
            
            // Ordenar por carga descendente
            usort($agents, fn($a, $b) => $b['load_percent'] <=> $a['load_percent']);
            
            echo json_encode([
                'success' => true,
                'agents' => $agents,
                'total_agents' => count($agents),
                'timestamp' => date('Y-m-d H:i:s'),
                'source' => 'wasapi_api'
            ]);
            break;
            
        case 'queue_history':
            // Historial de cola - último período
            $minutes = (int)($_GET['minutes'] ?? 60);
            
            // Wasapi no tiene endpoint específico para historial de cola,
            // así que retornamos snapshot actual con timestamp
            $data = wasapiRequest('dashboard/metrics/consolidated-conversations');
            
            echo json_encode([
                'success' => true,
                'history' => [[
                    'timestamp' => date('Y-m-d H:i:s'),
                    'pending' => $data['pending'] ?? $data['waiting'] ?? 0,
                    'active' => $data['active'] ?? $data['in_progress'] ?? 0,
                    'unassigned' => $data['unassigned'] ?? 0
                ]],
                'source' => 'wasapi_api'
            ]);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => 'Acción no válida'
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
