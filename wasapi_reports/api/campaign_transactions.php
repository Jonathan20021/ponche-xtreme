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

require_once __DIR__ . '/../../db.php';

define('WASAPI_TOKEN', '338529|NeQrFHvdJ3lX6O2Hs26QPjc0IyrgzKFxQGwVcvCM0575a229');
define('WASAPI_BASE_URL', 'https://api.wasapi.io/prod/api/v1/');

/**
 * Build the exclusion set (Wasapi user IDs and/or emails) configured in settings.
 * Returns ['ids' => [intMap], 'emails' => [lowerMap]].
 */
function getWasapiExcludedUsers(PDO $pdo): array {
    $raw = (string) getSystemSetting($pdo, 'wasapi_excluded_user_ids', '');
    $ids = [];
    $emails = [];
    if ($raw === '') {
        return ['ids' => $ids, 'emails' => $emails];
    }
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
            
            // Cargar lista de usuarios excluidos (administrativos) desde settings
            $excluded = getWasapiExcludedUsers($pdo);

            // Construir mapas de usuarios y de canales a partir de phones_asing
            $allUsers = $results['users']['users'] ?? [];
            $users = [];                  // usuarios elegibles (no excluidos)
            $excludedUserIds = [];        // set de IDs excluidos
            $userPhones = [];             // user_id => [phone_id, ...]
            $campaignsById = [];          // phone_id => campaign data

            $extractPhone = function ($phone) {
                if (is_string($phone)) {
                    preg_match('/display_name=([^;]+)/', $phone, $nameMatch);
                    preg_match('/phone_number=([^;]+)/', $phone, $phoneMatch);
                    preg_match('/id=(\d+)/', $phone, $idMatch);
                    preg_match('/color=([^;]+)/', $phone, $colorMatch);
                    return [
                        'id'    => trim($idMatch[1] ?? ''),
                        'name'  => trim($nameMatch[1] ?? ''),
                        'phone' => trim($phoneMatch[1] ?? ''),
                        'color' => trim($colorMatch[1] ?? '#3B82F6'),
                    ];
                }
                if (is_array($phone)) {
                    return [
                        'id'    => (string) ($phone['id'] ?? ''),
                        'name'  => (string) ($phone['display_name'] ?? ''),
                        'phone' => (string) ($phone['phone_number'] ?? ''),
                        'color' => (string) ($phone['color'] ?? '#3B82F6'),
                    ];
                }
                return null;
            };

            foreach ($allUsers as $user) {
                if (isUserExcluded($user, $excluded)) {
                    $excludedUserIds[(int) ($user['id'] ?? 0)] = true;
                    continue;
                }
                $users[] = $user;

                $userId = (int) ($user['id'] ?? 0);
                if ($userId <= 0) continue;

                $phoneIds = [];
                foreach ($user['phones_asing'] ?? [] as $phone) {
                    $p = $extractPhone($phone);
                    if (!$p || $p['id'] === '' || $p['name'] === '') continue;
                    $pid = $p['id'];

                    if (!isset($campaignsById[$pid])) {
                        $campaignsById[$pid] = [
                            'id'             => $pid,
                            'name'           => $p['name'],
                            'phone'          => $p['phone'],
                            'color'          => $p['color'] ?: '#3B82F6',
                            'transactions'   => 0.0,
                            'sales'          => 0.0,
                            'calls_handled'  => 0.0,
                            '_agent_ids'     => [],
                        ];
                    }
                    $campaignsById[$pid]['_agent_ids'][$userId] = true;
                    $phoneIds[] = $pid;
                }
                if (!empty($phoneIds)) {
                    $userPhones[$userId] = array_values(array_unique($phoneIds));
                }
            }

            // Conversaciones consolidadas (totales globales reportados por Wasapi)
            // Estos son los conteos "oficiales" de Wasapi, contados sin importar agente.
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

            // Agentes online (excluyendo administrativos)
            $onlineUsersRaw = $results['online_agents']['users'] ?? [];
            $onlineSet = [];
            $onlineCount = 0;
            foreach ($onlineUsersRaw as $u) {
                if (isUserExcluded($u, $excluded)) continue;
                if (!empty($u['online']) && $u['online'] == 1) {
                    $uid = (int) ($u['id'] ?? 0);
                    if ($uid > 0) $onlineSet[$uid] = true;
                    $onlineCount++;
                }
            }

            // Atribuir métricas de performance por agente a sus canales asignados.
            // Si un agente está en N canales, su aporte se reparte 1/N entre ellos
            // (Wasapi no expone phone_id por conversación, así que es la mejor
            // aproximación disponible).
            // Los agentes sin canal asignado (orfanos) no contribuyen al desglose
            // por campaña, pero sí se mantienen en `totals_global_*` para auditoría.
            $performanceData = $results['performance']['data'] ?? [];
            $globalTrans = 0;
            $globalSales = 0;
            $globalFirstResp = 0;
            $globalScaled = 0;
            $orphanTrans = 0;
            $orphanSales = 0;
            $orphanFirstResp = 0;

            foreach ($performanceData as $perf) {
                $agentId = (int) ($perf['agent_id'] ?? ($perf['agent']['id'] ?? 0));
                if ($agentId <= 0 || isset($excludedUserIds[$agentId])) continue;

                $closed    = intval($perf['total_close_conversations'] ?? 0);
                $open      = intval($perf['total_open_conversations'] ?? 0);
                $firstResp = intval($perf['total_first_response_count'] ?? 0);
                $scaled    = intval($perf['total_scaled_to_agents'] ?? 0);
                $trans     = $closed + $open;

                $globalTrans     += $trans;
                $globalSales     += $closed;
                $globalFirstResp += $firstResp;
                $globalScaled    += $scaled;

                if (!isset($userPhones[$agentId])) {
                    $orphanTrans     += $trans;
                    $orphanSales     += $closed;
                    $orphanFirstResp += $firstResp;
                    continue;
                }
                $assigned = $userPhones[$agentId];
                $share = 1 / max(count($assigned), 1);

                foreach ($assigned as $pid) {
                    $campaignsById[$pid]['transactions']  += $trans * $share;
                    $campaignsById[$pid]['sales']         += $closed * $share;
                    $campaignsById[$pid]['calls_handled'] += $firstResp * $share;
                }
            }

            // Finalizar campañas: contar agentes (totales y online) y redondear métricas
            $campaigns = [];
            foreach ($campaignsById as $pid => $camp) {
                $agentIds = array_keys($camp['_agent_ids']);
                $online = 0;
                foreach ($agentIds as $aid) {
                    if (!empty($onlineSet[$aid])) $online++;
                }
                unset($camp['_agent_ids']);
                $camp['transactions']  = (int) round($camp['transactions']);
                $camp['sales']         = (int) round($camp['sales']);
                $camp['calls_handled'] = (int) round($camp['calls_handled']);
                $camp['total_agents']  = count($agentIds);
                $camp['active_agents'] = $online;
                $campaigns[] = $camp;
            }

            // Ordenar por volumen descendente para que las activas aparezcan primero
            usort($campaigns, fn($a, $b) => $b['transactions'] - $a['transactions']);

            // Totales mostrados en las tarjetas superiores: se calculan como la SUMA
            // EXACTA de las tarjetas de campaña (incluyendo redondeo) para que el
            // dashboard sea internamente consistente — lo que se ve en los KPIs
            // siempre coincide con la suma del grid de abajo.
            $sumTransactions  = array_sum(array_column($campaigns, 'transactions'));
            $sumSales         = array_sum(array_column($campaigns, 'sales'));
            $sumCallsHandled  = array_sum(array_column($campaigns, 'calls_handled'));

            $totals = [
                'campaigns_count'     => count($campaigns),
                'total_transactions'  => $sumTransactions,
                'total_sales'         => $sumSales,
                'total_calls_handled' => $sumCallsHandled,
                'total_open'          => $totalOpen,
                'total_closed'        => $totalClosed,
                'total_agents'        => count($users),
                'online_agents'       => $onlineCount,
                'excluded_users'      => count($excludedUserIds),
                // Diagnóstico: suma de performance por agente sin atribuir a canal
                // (agentes con datos pero sin phones_asing). Si > 0, indica que hay
                // agentes activos que no están vinculados a ningún canal en Wasapi.
                'unassigned_agents_transactions' => $orphanTrans,
                'unassigned_agents_sales'        => $orphanSales,
                'unassigned_agents_responses'    => $orphanFirstResp,
                // Totales globales (no mostrados en KPI, útiles para depurar)
                'global_performance_transactions' => $globalTrans,
                'global_performance_sales'        => $globalSales,
                'global_performance_responses'    => $globalFirstResp,
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

