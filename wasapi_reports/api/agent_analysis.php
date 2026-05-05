<?php
/**
 * API de Análisis Individual de Agentes - Wasapi Reports
 * Análisis detallado del rendimiento de cada agente
 * TODOS LOS DATOS PROVIENEN EXCLUSIVAMENTE DE WASAPI
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../../db.php';

define('WASAPI_TOKEN', '338529|NeQrFHvdJ3lX6O2Hs26QPjc0IyrgzKFxQGwVcvCM0575a229');
define('WASAPI_BASE_URL', 'https://api.wasapi.io/prod/api/v1/');

/**
 * Build the exclusion set (Wasapi user IDs and/or emails) configured in settings.
 */
function getWasapiExcludedUsers(PDO $pdo): array {
    $raw = (string) getSystemSetting($pdo, 'wasapi_excluded_user_ids', '');
    $ids = [];
    $emails = [];
    if ($raw === '') return ['ids' => $ids, 'emails' => $emails];
    foreach (preg_split('/[,;\s]+/', $raw) as $token) {
        $token = trim($token);
        if ($token === '') continue;
        if (filter_var($token, FILTER_VALIDATE_EMAIL)) {
            $emails[strtolower($token)] = true;
        } elseif (ctype_digit($token)) {
            $ids[(int) $token] = true;
        }
    }
    return ['ids' => $ids, 'emails' => $emails];
}

function isUserExcluded(array $user, array $excluded): bool {
    $uid = (int) ($user['id'] ?? 0);
    if ($uid > 0 && !empty($excluded['ids'][$uid])) return true;
    $email = strtolower((string) ($user['email'] ?? ''));
    if ($email !== '' && !empty($excluded['emails'][$email])) return true;
    return false;
}

try {
    $action = $_GET['action'] ?? 'list';
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $agentId = $_GET['agent_id'] ?? null;
    
    // Obtener todos los datos de Wasapi en paralelo
    $multiHandle = curl_multi_init();
    $handles = [];
    
    $datesParam = 'dates%5B0%5D=' . $startDate . '&dates%5B1%5D=' . $endDate;
    $endpoints = [
        'users' => 'users',
        'online_agents' => 'dashboard/metrics/online-agents',
        'agent_conversations' => 'dashboard/metrics/agent-conversations?' . $datesParam,
        'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate
    ];
    
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
    
    // Procesar usuarios de Wasapi (filtrando administrativos configurados en settings)
    $excluded = getWasapiExcludedUsers($pdo);
    $excludedUserIds = [];
    $allUsers = $results['users']['users'] ?? [];
    $users = [];
    foreach ($allUsers as $u) {
        if (isUserExcluded($u, $excluded)) {
            $excludedUserIds[(int) ($u['id'] ?? 0)] = true;
            continue;
        }
        $users[] = $u;
    }
    $onlineUsers = array_values(array_filter(
        $results['online_agents']['users'] ?? [],
        fn($u) => !isUserExcluded($u, $excluded)
    ));
    $agentConversations = array_values(array_filter(
        $results['agent_conversations']['conversations'] ?? [],
        fn($c) => empty($excludedUserIds[(int) ($c['agent_id'] ?? 0)])
    ));
    $performanceData = array_values(array_filter(
        $results['performance']['data'] ?? [],
        fn($p) => empty($excludedUserIds[(int) ($p['agent_id'] ?? ($p['agent']['id'] ?? 0))])
    ));
    
    // Crear map de usuarios online
    $onlineMap = [];
    foreach ($onlineUsers as $u) {
        $onlineMap[$u['id']] = $u['online'] == 1;
    }
    
    // Crear map de conversaciones por agente
    $conversationMap = [];
    foreach ($agentConversations as $ac) {
        $agentId_conv = $ac['agent_id'] ?? 0;
        if (!isset($conversationMap[$agentId_conv])) {
            $conversationMap[$agentId_conv] = ['open' => 0, 'closed' => 0, 'total' => 0];
        }
        $count = intval($ac['count'] ?? 0);
        $conversationMap[$agentId_conv]['total'] += $count;
        if (($ac['status'] ?? '') === 'open') {
            $conversationMap[$agentId_conv]['open'] += $count;
        } else {
            $conversationMap[$agentId_conv]['closed'] += $count;
        }
    }
    
    // Crear map de performance por agente
    $performanceMap = [];
    foreach ($performanceData as $perf) {
        $agentId_perf = $perf['agent_id'] ?? 0;
        if (!isset($performanceMap[$agentId_perf])) {
            $performanceMap[$agentId_perf] = [
                'total_scaled' => 0,
                'total_open' => 0,
                'total_closed' => 0,
                'total_first_response' => 0,
                'avg_first_response_time' => 0,
                'name' => $perf['agent']['name'] ?? '',
                'email' => $perf['agent']['email'] ?? ''
            ];
        }
        $performanceMap[$agentId_perf]['total_scaled'] += intval($perf['total_scaled_to_agents'] ?? 0);
        $performanceMap[$agentId_perf]['total_open'] += intval($perf['total_open_conversations'] ?? 0);
        $performanceMap[$agentId_perf]['total_closed'] += intval($perf['total_close_conversations'] ?? 0);
        $performanceMap[$agentId_perf]['total_first_response'] += intval($perf['total_first_response_count'] ?? 0);
    }
    
    switch ($action) {
        case 'list':
            // Lista de todos los agentes con métricas resumidas
            $agents = [];
            
            foreach ($users as $user) {
                $userId = $user['id'];
                $isOnline = $onlineMap[$userId] ?? false;
                $convs = $conversationMap[$userId] ?? ['open' => 0, 'closed' => 0, 'total' => 0];
                $perf = $performanceMap[$userId] ?? [];
                
                // Calcular productividad basada en conversaciones
                $totalConvs = $convs['total'];
                $closedConvs = $convs['closed'];
                $productivity = $totalConvs > 0 ? round(($closedConvs / $totalConvs) * 100, 1) : 0;
                
                // Clasificar rendimiento
                if ($productivity >= 70) {
                    $performanceLevel = 'high';
                    $performanceLabel = 'Alto';
                } elseif ($productivity >= 50) {
                    $performanceLevel = 'medium';
                    $performanceLabel = 'Medio';
                } else {
                    $performanceLevel = 'low';
                    $performanceLabel = 'Bajo';
                }
                
                $agents[] = [
                    'id' => $userId,
                    'full_name' => $user['name'] ?? 'Agente',
                    'email' => $user['email'] ?? '',
                    'is_active' => $isOnline ? 1 : 0,
                    'is_online' => $isOnline,
                    'last_login' => $user['last_login_at'] ?? null,
                    'total_calls_handled' => $closedConvs,
                    'total_conversations' => $totalConvs,
                    'active_conversations' => $convs['open'],
                    'productivity_percent' => $productivity,
                    'performance_level' => $performanceLevel,
                    'performance_label' => $performanceLabel,
                    'total_scaled' => $perf['total_scaled'] ?? 0,
                    'campaign_name' => 'Wasapi',
                    'campaign_color' => '#25D366'
                ];
            }
            
            // Ordenar por conversaciones cerradas
            usort($agents, fn($a, $b) => $b['total_calls_handled'] - $a['total_calls_handled']);
            
            // Calcular estadísticas generales
            $totalAgents = count($agents);
            $activeAgents = count(array_filter($agents, fn($a) => $a['is_online']));
            $highPerformers = count(array_filter($agents, fn($a) => $a['performance_level'] === 'high'));
            $lowPerformers = count(array_filter($agents, fn($a) => $a['performance_level'] === 'low'));
            $avgProductivity = $totalAgents > 0 
                ? round(array_sum(array_column($agents, 'productivity_percent')) / $totalAgents, 1) 
                : 0;
            
            echo json_encode([
                'success' => true,
                'agents' => $agents,
                'stats' => [
                    'total_agents' => $totalAgents,
                    'active_agents' => $activeAgents,
                    'high_performers' => $highPerformers,
                    'low_performers' => $lowPerformers,
                    'avg_productivity' => $avgProductivity
                ],
                'period' => ['start_date' => $startDate, 'end_date' => $endDate],
                'source' => 'wasapi'
            ]);
            break;
            
        case 'detail':
            // Detalles de un agente específico
            if (!$agentId) {
                throw new Exception('agent_id requerido');
            }
            
            // Buscar el agente
            $agent = null;
            foreach ($users as $user) {
                if ($user['id'] == $agentId) {
                    $agent = $user;
                    break;
                }
            }
            
            if (!$agent) {
                throw new Exception('Agente no encontrado');
            }
            
            $userId = $agent['id'];
            $isOnline = $onlineMap[$userId] ?? false;
            $convs = $conversationMap[$userId] ?? ['open' => 0, 'closed' => 0, 'total' => 0];
            $perf = $performanceMap[$userId] ?? [];
            
            $productivity = $convs['total'] > 0 ? round(($convs['closed'] / $convs['total']) * 100, 1) : 0;
            
            echo json_encode([
                'success' => true,
                'agent' => [
                    'id' => $agent['id'],
                    'first_name' => explode(' ', $agent['name'])[0] ?? '',
                    'last_name' => implode(' ', array_slice(explode(' ', $agent['name']), 1)) ?? '',
                    'email' => $agent['email'],
                    'is_online' => $isOnline,
                    'last_login' => $agent['last_login_at'],
                    'created_at' => $agent['created_at']
                ],
                'summary' => [
                    'total_calls' => $convs['closed'],
                    'total_conversations' => $convs['total'],
                    'active_conversations' => $convs['open'],
                    'avg_productivity' => $productivity,
                    'total_scaled' => $perf['total_scaled'] ?? 0,
                    'performance_level' => $productivity >= 70 ? 'high' : ($productivity >= 50 ? 'medium' : 'low')
                ],
                'wasapi_data' => $perf,
                'period' => ['start_date' => $startDate, 'end_date' => $endDate],
                'source' => 'wasapi'
            ]);
            break;
            
        case 'ranking':
            // Ranking de agentes por conversaciones cerradas
            $agents = [];
            
            foreach ($users as $user) {
                $userId = $user['id'];
                $convs = $conversationMap[$userId] ?? ['open' => 0, 'closed' => 0, 'total' => 0];
                
                if ($convs['total'] > 0) {
                    $productivity = round(($convs['closed'] / $convs['total']) * 100, 1);
                    
                    $agents[] = [
                        'id' => $userId,
                        'name' => $user['name'] ?? 'Agente',
                        'email' => $user['email'] ?? '',
                        'total_calls' => $convs['closed'],
                        'total_conversations' => $convs['total'],
                        'productivity' => $productivity,
                        'is_online' => $onlineMap[$userId] ?? false
                    ];
                }
            }
            
            usort($agents, fn($a, $b) => $b['total_calls'] - $a['total_calls']);
            
            // Añadir posición
            foreach ($agents as $idx => &$agent) {
                $agent['rank'] = $idx + 1;
            }
            
            echo json_encode([
                'success' => true,
                'ranking' => array_slice($agents, 0, 20),
                'period' => ['start_date' => $startDate, 'end_date' => $endDate],
                'source' => 'wasapi'
            ]);
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

