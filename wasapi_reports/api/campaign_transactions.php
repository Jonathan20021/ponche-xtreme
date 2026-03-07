<?php
/**
 * API de Transacciones por Campaña - Wasapi Reports
 * Obtiene datos de canales/teléfonos de Wasapi como "campañas"
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

define('WASAPI_TOKEN', '338529|NeQrFHvdJ3lX6O2Hs26QPjc0IyrgzKFxQGwVcvCM0575a229');
define('WASAPI_BASE_URL', 'https://api.wasapi.io/prod/api/v1/');

try {
    $action = $_GET['action'] ?? 'summary';
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    
    switch ($action) {
        case 'summary':
            // Obtener todos los datos de Wasapi en paralelo
            $multiHandle = curl_multi_init();
            $handles = [];
            
            $datesParam = 'dates%5B0%5D=' . $startDate . '&dates%5B1%5D=' . $endDate;
            $endpoints = [
                'users' => 'users',
                'conversations' => 'dashboard/metrics/consolidated-conversations?' . $datesParam,
                'agent_conversations' => 'dashboard/metrics/agent-conversations?' . $datesParam,
                'online_agents' => 'dashboard/metrics/online-agents',
                'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate,
                'workflow' => 'reports/volume-of-workflow?start_date=' . $startDate . '&end_date=' . $endDate
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
            
            // Extraer teléfonos/canales únicos de usuarios (estos son las "campañas")
            $campaigns = [];
            $campaignIds = [];
            $users = $results['users']['users'] ?? [];
            
            foreach ($users as $user) {
                $phones = $user['phones_asing'] ?? [];
                foreach ($phones as $phone) {
                    if (is_string($phone)) {
                        // Parsear string con formato "@{key=value; key2=value2; ...}"
                        preg_match('/display_name=([^;]+)/', $phone, $nameMatch);
                        preg_match('/phone_number=([^;]+)/', $phone, $phoneMatch);
                        preg_match('/id=(\d+)/', $phone, $idMatch);
                        preg_match('/color=([^;]+)/', $phone, $colorMatch);
                        
                        $phoneId = trim($idMatch[1] ?? '0');
                        if (!isset($campaignIds[$phoneId]) && !empty($nameMatch[1])) {
                            $campaignIds[$phoneId] = true;
                            $campaigns[] = [
                                'id' => $phoneId,
                                'name' => trim($nameMatch[1] ?? 'Canal'),
                                'phone' => trim($phoneMatch[1] ?? ''),
                                'color' => trim($colorMatch[1] ?? '#3B82F6')
                            ];
                        }
                    } elseif (is_array($phone)) {
                        $phoneId = $phone['id'] ?? 0;
                        if (!isset($campaignIds[$phoneId]) && !empty($phone['display_name'])) {
                            $campaignIds[$phoneId] = true;
                            $campaigns[] = [
                                'id' => $phoneId,
                                'name' => $phone['display_name'] ?? 'Canal',
                                'phone' => $phone['phone_number'] ?? '',
                                'color' => $phone['color'] ?? '#3B82F6'
                            ];
                        }
                    }
                }
            }
            
            // Calcular métricas de conversaciones
            $convData = $results['conversations']['conversations']['data'] ?? [];
            $totalOpen = 0;
            $totalClosed = 0;
            
            foreach ($convData as $conv) {
                $status = $conv['status'] ?? '';
                $count = intval($conv['cant'] ?? 0);
                if ($status === 'open') {
                    $totalOpen += $count;
                } elseif ($status === 'closed') {
                    $totalClosed += $count;
                }
            }
            
            // Workflow data para calcular transacciones
            $workflowData = $results['workflow']['data'] ?? [];
            $totalTransactions = 0;
            $totalFirstResponses = 0;
            
            foreach ($workflowData as $wf) {
                $totalTransactions += intval($wf['total_open_conversations'] ?? 0) + intval($wf['total_close_conversations'] ?? 0);
                $totalFirstResponses += intval($wf['total_first_response_count'] ?? 0);
            }
            
            // Performance data
            $performanceData = $results['performance']['data'] ?? [];
            $totalScaled = 0;
            
            foreach ($performanceData as $perf) {
                $totalScaled += intval($perf['total_scaled_to_agents'] ?? 0);
            }
            
            // Agentes online
            $onlineUsers = $results['online_agents']['users'] ?? [];
            $onlineCount = 0;
            foreach ($onlineUsers as $user) {
                if (isset($user['online']) && $user['online'] == 1) {
                    $onlineCount++;
                }
            }
            
            // Distribuir métricas entre campañas
            $campaignCount = max(count($campaigns), 1);
            foreach ($campaigns as &$campaign) {
                $campaign['transactions'] = round($totalTransactions / $campaignCount);
                $campaign['sales'] = round($totalClosed / $campaignCount);
                $campaign['calls_handled'] = round($totalFirstResponses / $campaignCount);
                $campaign['total_agents'] = ceil(count($users) / $campaignCount);
                $campaign['active_agents'] = ceil($onlineCount / $campaignCount);
            }
            
            // Totales
            $totals = [
                'campaigns_count' => count($campaigns),
                'total_transactions' => $totalTransactions,
                'total_sales' => $totalClosed,
                'total_calls_handled' => $totalFirstResponses,
                'total_open' => $totalOpen,
                'total_closed' => $totalClosed,
                'total_agents' => count($users),
                'online_agents' => $onlineCount
            ];
            
            echo json_encode([
                'success' => true,
                'campaigns' => $campaigns,
                'totals' => $totals,
                'period' => ['start_date' => $startDate, 'end_date' => $endDate],
                'source' => 'wasapi',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'detail':
            $campaignId = $_GET['campaign_id'] ?? null;
            if (!$campaignId) {
                throw new Exception('ID de campaña requerido');
            }
            
            // Obtener datos de performance de Wasapi
            $datesParam = 'dates%5B0%5D=' . $startDate . '&dates%5B1%5D=' . $endDate;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, WASAPI_BASE_URL . 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . WASAPI_TOKEN,
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $performance = json_decode($response, true) ?? [];
            
            echo json_encode([
                'success' => true,
                'campaign_id' => $campaignId,
                'performance' => $performance['data'] ?? [],
                'source' => 'wasapi'
            ]);
            break;
            
        case 'trends':
            // Obtener workflow data para tendencias
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, WASAPI_BASE_URL . 'reports/volume-of-workflow?start_date=' . $startDate . '&end_date=' . $endDate);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . WASAPI_TOKEN,
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $workflow = json_decode($response, true) ?? [];
            $trends = [];
            
            // Agrupar por fecha
            foreach ($workflow['data'] ?? [] as $wf) {
                $date = substr($wf['date'] ?? '', 0, 10);
                if (!isset($trends[$date])) {
                    $trends[$date] = [
                        'date' => $date,
                        'calls' => 0,
                        'transactions' => 0
                    ];
                }
                $trends[$date]['calls'] += intval($wf['total_first_response_count'] ?? 0);
                $trends[$date]['transactions'] += intval($wf['total_open_conversations'] ?? 0) + intval($wf['total_close_conversations'] ?? 0);
            }
            
            echo json_encode([
                'success' => true,
                'trends' => array_values($trends),
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

