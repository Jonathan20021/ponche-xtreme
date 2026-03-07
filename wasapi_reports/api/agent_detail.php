<?php
/**
 * API de Detalle de Agente - Wasapi Reports
 * Obtiene métricas detalladas de un agente específico desde Wasapi API
 */

// Suppress PHP warnings/notices from appearing in JSON output
error_reporting(0);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

if (!defined('WASAPI_TOKEN')) {
    define('WASAPI_TOKEN', '338529|NeQrFHvdJ3lX6O2Hs26QPjc0IyrgzKFxQGwVcvCM0575a229');
}
if (!defined('WASAPI_BASE_URL')) {
    define('WASAPI_BASE_URL', 'https://api.wasapi.io/prod/api/v1/');
}

/**
 * Realiza una petición a la API de Wasapi
 */
function wasapiRequest($endpoint) {
    $url = WASAPI_BASE_URL . ltrim($endpoint, '/');
    
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

/**
 * Formatea segundos a texto legible
 */
function formatSeconds($seconds) {
    if (!$seconds || $seconds == 0) return '0s';
    $seconds = floatval($seconds);
    if ($seconds < 60) return round($seconds) . 's';
    if ($seconds < 3600) {
        $mins = floor($seconds / 60);
        $secs = round($seconds % 60);
        return $mins . 'm ' . $secs . 's';
    }
    $hours = floor($seconds / 3600);
    $mins = round(($seconds % 3600) / 60);
    return $hours . 'h ' . $mins . 'm';
}

try {
    $agentId = $_GET['agent_id'] ?? null;
    $startDate = $_GET['start_date'] ?? date('Y-m-d');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    
    if (!$agentId) {
        throw new Exception('Agent ID requerido');
    }
    
    // Obtener datos de performance por agente de Wasapi
    // Este endpoint devuelve datos diarios por agente
    $performanceData = wasapiRequest("reports/performance-by-agent?start_date={$startDate}&end_date={$endDate}");
    
    // Obtener lista de agentes online
    $onlineAgentsData = wasapiRequest("dashboard/metrics/online-agents");
    
    // Buscar datos del agente específico
    $agentData = null;
    $dailyData = [];
    $agentInfo = null;
    $isOnline = false;
    
    // Procesar datos de online
    $onlineAgents = $onlineAgentsData['data'] ?? $onlineAgentsData['users'] ?? $onlineAgentsData ?? [];
    if (is_array($onlineAgents)) {
        foreach ($onlineAgents as $agent) {
            $id = $agent['id'] ?? $agent['user_id'] ?? null;
            if ($id == $agentId) {
                $online = $agent['online'] ?? false;
                $isOnline = ($online == 1 || $online === true || $agent['status'] === 'online');
                break;
            }
        }
    }
    
    // Procesar datos de performance
    $allAgentData = $performanceData['data'] ?? $performanceData ?? [];
    
    // Agrupar datos por fecha para el agente específico
    $totalConversations = 0;
    $totalClosed = 0;
    $totalResolutionTime = 0;
    $totalFirstResponseTime = 0;
    $totalScaled = 0;
    $totalAutoAssign = 0;
    $totalManualAssign = 0;
    $totalBotAssign = 0;
    $totalMakeAssign = 0;
    $countResolution = 0;
    $countFirstResponse = 0;
    
    $dailyByDate = [];
    
    foreach ($allAgentData as $record) {
        $recordAgentId = $record['agent_id'] ?? ($record['agent']['id'] ?? null);
        
        if ($recordAgentId != $agentId) {
            continue;
        }
        
        // Guardar info del agente
        if (!$agentInfo && isset($record['agent'])) {
            $agentInfo = $record['agent'];
        }
        
        // Extraer fecha
        $dateStr = $record['date'] ?? date('Y-m-d');
        if (strpos($dateStr, 'T') !== false) {
            $dateStr = substr($dateStr, 0, 10);
        }
        
        // Métricas
        $open = intval($record['total_open_conversations'] ?? 0);
        $closed = intval($record['total_close_conversations'] ?? 0);
        $resolutionTime = floatval($record['avg_resolution_time_per_conversation'] ?? 0);
        $firstResponseTime = floatval($record['avg_first_response_time'] ?? 0);
        $scaled = intval($record['total_scaled_to_agents'] ?? 0);
        $autoAssign = intval($record['total_asigned_by_automatic'] ?? 0);
        $manualAssign = intval($record['total_asigned_by_manual'] ?? 0);
        $botAssign = intval($record['total_asigned_by_bot'] ?? 0);
        $makeAssign = intval($record['total_asigned_by_make'] ?? 0);
        
        // Acumular totales
        $totalConversations += $open;
        $totalClosed += $closed;
        $totalScaled += $scaled;
        $totalAutoAssign += $autoAssign;
        $totalManualAssign += $manualAssign;
        $totalBotAssign += $botAssign;
        $totalMakeAssign += $makeAssign;
        
        if ($resolutionTime > 0 && $closed > 0) {
            $totalResolutionTime += $resolutionTime * $closed;
            $countResolution += $closed;
        }
        if ($firstResponseTime > 0) {
            $totalFirstResponseTime += $firstResponseTime;
            $countFirstResponse++;
        }
        
        // Agrupar por fecha
        if (!isset($dailyByDate[$dateStr])) {
            $dailyByDate[$dateStr] = [
                'date' => $dateStr,
                'open' => 0,
                'closed' => 0,
                'total_resolution' => 0,
                'total_first_response' => 0,
                'count_resolution' => 0,
                'count_first_response' => 0,
                'scaled' => 0
            ];
        }
        
        $dailyByDate[$dateStr]['open'] += $open;
        $dailyByDate[$dateStr]['closed'] += $closed;
        $dailyByDate[$dateStr]['scaled'] += $scaled;
        
        if ($resolutionTime > 0) {
            $dailyByDate[$dateStr]['total_resolution'] += $resolutionTime * $closed;
            $dailyByDate[$dateStr]['count_resolution'] += $closed;
        }
        if ($firstResponseTime > 0) {
            $dailyByDate[$dateStr]['total_first_response'] += $firstResponseTime;
            $dailyByDate[$dateStr]['count_first_response']++;
        }
    }
    
    // Calcular promedios y eficiencia por día
    ksort($dailyByDate);
    foreach ($dailyByDate as $date => &$day) {
        $day['avg_resolution'] = $day['count_resolution'] > 0 
            ? round($day['total_resolution'] / $day['count_resolution'], 1) 
            : 0;
        $day['avg_first_response'] = $day['count_first_response'] > 0 
            ? round($day['total_first_response'] / $day['count_first_response'], 1) 
            : 0;
        $day['efficiency'] = $day['open'] > 0 
            ? round(($day['closed'] / $day['open']) * 100, 1) 
            : 0;
        if ($day['efficiency'] > 100) $day['efficiency'] = 100;
        
        // Limpiar campos temporales
        unset($day['total_resolution'], $day['total_first_response'], $day['count_resolution'], $day['count_first_response']);
    }
    
    // Calcular métricas globales
    $avgResolution = $countResolution > 0 ? round($totalResolutionTime / $countResolution, 1) : 0;
    $avgFirstResponse = $countFirstResponse > 0 ? round($totalFirstResponseTime / $countFirstResponse, 1) : 0;
    $efficiencyRate = $totalConversations > 0 ? round(($totalClosed / $totalConversations) * 100, 1) : 0;
    if ($efficiencyRate > 100) $efficiencyRate = 100;
    
    // Preparar respuesta
    echo json_encode([
        'success' => true,
        'agent' => [
            'id' => $agentId,
            'name' => $agentInfo['name'] ?? 'Agente ' . $agentId,
            'email' => $agentInfo['email'] ?? '',
            'online' => $isOnline
        ],
        'metrics' => [
            'total_conversations' => $totalConversations,
            'closed_conversations' => $totalClosed,
            'avg_resolution' => $avgResolution,
            'avg_resolution_formatted' => formatSeconds($avgResolution),
            'avg_first_response' => $avgFirstResponse,
            'avg_first_response_formatted' => formatSeconds($avgFirstResponse),
            'total_scaled' => $totalScaled,
            'efficiency_rate' => $efficiencyRate,
            'automatic_assignments' => $totalAutoAssign,
            'manual_assignments' => $totalManualAssign,
            'bot_assignments' => $totalBotAssign,
            'make_assignments' => $totalMakeAssign
        ],
        'daily' => array_values($dailyByDate),
        'period' => [
            'start_date' => $startDate,
            'end_date' => $endDate
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
