<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

header('Content-Type: application/json');

// Check permission
if (!isset($_SESSION['user_id']) || !userHasPermission('vicidial_reports')) {
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para realizar esta acción']);
    exit;
}

$action = $_GET['action'] ?? '';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$campaign = $_GET['campaign'] ?? '';

// Keep original range dates for available_dates query
$rangeStartDate = $startDate;
$rangeEndDate = $endDate;

// Daily date override: when a specific day is selected, restrict range to that single day
$dailyDate = $_GET['daily_date'] ?? '';
if ($dailyDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyDate)) {
    $startDate = $dailyDate;
    $endDate = $dailyDate;
}

try {
    switch ($action) {
        case 'kpis':
            echo json_encode(getKPIs($pdo, $startDate, $endDate, $campaign));
            break;

        case 'top_agents':
            echo json_encode(getTopAgents($pdo, $startDate, $endDate, $campaign));
            break;

        case 'time_distribution':
            echo json_encode(getTimeDistribution($pdo, $startDate, $endDate, $campaign));
            break;

        case 'disposition_breakdown':
            echo json_encode(getDispositionBreakdown($pdo, $startDate, $endDate, $campaign));
            break;

        case 'trends':
            echo json_encode(getTrends($pdo, $startDate, $endDate, $campaign));
            break;

        case 'alerts':
            echo json_encode(getAlerts($pdo, $startDate, $endDate, $campaign));
            break;

        case 'campaigns':
            echo json_encode(getCampaigns($pdo, $startDate, $endDate));
            break;

        case 'available_dates':
            echo json_encode(getAvailableDates($pdo, $rangeStartDate, $rangeEndDate));
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Get Executive KPIs
 */
function getKPIs($pdo, $startDate, $endDate, $campaign = '')
{
    $campaignFilter = $campaign ? "AND current_user_group = :campaign" : "";

    $stmt = $pdo->prepare("
        SELECT 
            SUM(calls) as total_calls,
            SUM(time_total) as total_time,
            SUM(talk_time) as total_talk,
            SUM(dispo_time) as total_dispo,
            SUM(dead_time) as total_dead,
            SUM(pause_time) as total_pause,
            SUM(wait_time) as total_wait,
            SUM(sale + pedido + orden) as total_conversions,
            SUM(nocal + silenc) as total_no_contact,
            COUNT(DISTINCT user_id) as total_agents
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        $campaignFilter
    ");

    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    if ($campaign) {
        $params['campaign'] = $campaign;
    }

    $stmt->execute($params);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    // Calculate KPIs
    $totalCalls = (int) $data['total_calls'];
    $totalTime = (int) $data['total_time'];
    $totalTalk = (int) $data['total_talk'];
    $totalDispo = (int) $data['total_dispo'];
    $totalDead = (int) $data['total_dead'];
    $totalConversions = (int) $data['total_conversions'];
    $totalNoContact = (int) $data['total_no_contact'];

    $conversionRate = $totalCalls > 0 ? round(($totalConversions / $totalCalls) * 100, 2) : 0;
    $aht = $totalCalls > 0 ? round(($totalTalk + $totalDispo) / $totalCalls, 0) : 0;
    $occupancy = $totalTime > 0 ? round((($totalTalk + $totalDispo + $totalDead) / $totalTime) * 100, 2) : 0;
    $productivity = $totalTime > 0 ? round($totalCalls / ($totalTime / 3600), 2) : 0;
    $contactRate = $totalCalls > 0 ? round((($totalCalls - $totalNoContact) / $totalCalls) * 100, 2) : 0;
    $efficiency = $totalTime > 0 ? round(($totalTalk / $totalTime) * 100, 2) : 0;

    return [
        'success' => true,
        'kpis' => [
            'conversion_rate' => [
                'value' => $conversionRate,
                'label' => 'Tasa de Conversión',
                'unit' => '%',
                'icon' => 'fa-chart-line',
                'color' => 'cyan'
            ],
            'aht' => [
                'value' => gmdate('i:s', $aht),
                'label' => 'AHT Promedio',
                'unit' => 'min',
                'icon' => 'fa-clock',
                'color' => 'blue'
            ],
            'occupancy' => [
                'value' => $occupancy,
                'label' => 'Ocupación',
                'unit' => '%',
                'icon' => 'fa-percentage',
                'color' => 'green'
            ],
            'productivity' => [
                'value' => $productivity,
                'label' => 'Llamadas/Hora',
                'unit' => 'llamadas',
                'icon' => 'fa-phone',
                'color' => 'purple'
            ],
            'contact_rate' => [
                'value' => $contactRate,
                'label' => 'Tasa de Contacto',
                'unit' => '%',
                'icon' => 'fa-user-check',
                'color' => 'indigo'
            ],
            'efficiency' => [
                'value' => $efficiency,
                'label' => 'Eficiencia',
                'unit' => '%',
                'icon' => 'fa-tachometer-alt',
                'color' => 'pink'
            ]
        ],
        'totals' => [
            'calls' => $totalCalls,
            'conversions' => $totalConversions,
            'agents' => (int) $data['total_agents']
        ]
    ];
}

/**
 * Get Top Agents by Conversions
 */
function getTopAgents($pdo, $startDate, $endDate, $campaign = '', $limit = 10)
{
    $campaignFilter = $campaign ? "AND current_user_group = :campaign" : "";

    $stmt = $pdo->prepare("
        SELECT 
            user_name,
            SUM(calls) as total_calls,
            SUM(sale + pedido + orden) as conversions,
            ROUND((SUM(sale + pedido + orden) / SUM(calls)) * 100, 2) as conversion_rate
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        $campaignFilter
        GROUP BY user_name
        HAVING total_calls > 0
        ORDER BY conversions DESC
        LIMIT :limit
    ");

    $params = ['start_date' => $startDate, 'end_date' => $endDate, 'limit' => $limit];
    if ($campaign) {
        $params['campaign'] = $campaign;
    }

    $stmt->execute($params);
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'success' => true,
        'agents' => array_map(function ($agent) {
            return [
                'name' => $agent['user_name'],
                'calls' => (int) $agent['total_calls'],
                'conversions' => (int) $agent['conversions'],
                'conversion_rate' => (float) $agent['conversion_rate']
            ];
        }, $agents)
    ];
}

/**
 * Get Time Distribution
 */
function getTimeDistribution($pdo, $startDate, $endDate, $campaign = '')
{
    $campaignFilter = $campaign ? "AND current_user_group = :campaign" : "";

    $stmt = $pdo->prepare("
        SELECT 
            SUM(talk_time) as talk,
            SUM(pause_time) as pause,
            SUM(wait_time) as wait,
            SUM(dispo_time) as dispo,
            SUM(dead_time) as dead
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        $campaignFilter
    ");

    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    if ($campaign) {
        $params['campaign'] = $campaign;
    }

    $stmt->execute($params);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'success' => true,
        'distribution' => [
            'Talk' => (int) $data['talk'],
            'Pause' => (int) $data['pause'],
            'Wait' => (int) $data['wait'],
            'Dispo' => (int) $data['dispo'],
            'Dead' => (int) $data['dead']
        ]
    ];
}

/**
 * Get Disposition Breakdown
 */
function getDispositionBreakdown($pdo, $startDate, $endDate, $campaign = '')
{
    $campaignFilter = $campaign ? "AND current_user_group = :campaign" : "";

    $stmt = $pdo->prepare("
        SELECT 
            SUM(sale) as SALE,
            SUM(pedido) as PEDIDO,
            SUM(orden) as ORDEN,
            SUM(callbk) as CALLBACK,
            SUM(n) as NO_INTERESADO,
            SUM(nocal) as NO_CONTACTO,
            SUM(colgo) as COLGO,
            SUM(silenc) as SILENCIO
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        $campaignFilter
    ");

    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    if ($campaign) {
        $params['campaign'] = $campaign;
    }

    $stmt->execute($params);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'success' => true,
        'dispositions' => array_map('intval', $data)
    ];
}

/**
 * Get Trends by Date
 */
function getTrends($pdo, $startDate, $endDate, $campaign = '')
{
    $campaignFilter = $campaign ? "AND current_user_group = :campaign" : "";

    $stmt = $pdo->prepare("
        SELECT 
            upload_date,
            SUM(calls) as calls,
            SUM(sale + pedido + orden) as conversions
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        $campaignFilter
        GROUP BY upload_date
        ORDER BY upload_date ASC
    ");

    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    if ($campaign) {
        $params['campaign'] = $campaign;
    }

    $stmt->execute($params);
    $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'success' => true,
        'trends' => array_map(function ($trend) {
            return [
                'date' => $trend['upload_date'],
                'calls' => (int) $trend['calls'],
                'conversions' => (int) $trend['conversions']
            ];
        }, $trends)
    ];
}

/**
 * Get Alerts and Recommendations
 */
function getAlerts($pdo, $startDate, $endDate, $campaign = '')
{
    $campaignFilter = $campaign ? "AND current_user_group = :campaign" : "";
    $alerts = [];

    // Low conversion rate agents
    $stmt = $pdo->prepare("
        SELECT 
            user_name,
            SUM(calls) as total_calls,
            SUM(sale + pedido + orden) as conversions,
            ROUND((SUM(sale + pedido + orden) / SUM(calls)) * 100, 2) as conversion_rate
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        $campaignFilter
        GROUP BY user_name
        HAVING total_calls >= 50 AND conversion_rate < 5
        ORDER BY conversion_rate ASC
        LIMIT 5
    ");

    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    if ($campaign) {
        $params['campaign'] = $campaign;
    }

    $stmt->execute($params);
    $lowConversion = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($lowConversion) > 0) {
        $alerts[] = [
            'type' => 'warning',
            'icon' => 'fa-exclamation-triangle',
            'title' => 'Baja Tasa de Conversión',
            'message' => count($lowConversion) . ' agentes con conversión menor a 5%',
            'agents' => array_column($lowConversion, 'user_name')
        ];
    }

    // Excessive pause time
    $stmt = $pdo->prepare("
        SELECT 
            user_name,
            SUM(pause_time) as total_pause,
            SUM(time_total) as total_time,
            ROUND((SUM(pause_time) / SUM(time_total)) * 100, 2) as pause_percentage
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        $campaignFilter
        GROUP BY user_name
        HAVING pause_percentage > 30
        ORDER BY pause_percentage DESC
        LIMIT 5
    ");

    $stmt->execute($params);
    $highPause = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($highPause) > 0) {
        $alerts[] = [
            'type' => 'info',
            'icon' => 'fa-pause-circle',
            'title' => 'Tiempo de Pausa Elevado',
            'message' => count($highPause) . ' agentes con más de 30% en pausa',
            'agents' => array_column($highPause, 'user_name')
        ];
    }

    // Low call volume
    $stmt = $pdo->prepare("
        SELECT 
            user_name,
            SUM(calls) as total_calls,
            SUM(time_total) / 3600 as hours_worked
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        $campaignFilter
        GROUP BY user_name
        HAVING hours_worked >= 8 AND total_calls < 50
        ORDER BY total_calls ASC
        LIMIT 5
    ");

    $stmt->execute($params);
    $lowVolume = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($lowVolume) > 0) {
        $alerts[] = [
            'type' => 'danger',
            'icon' => 'fa-phone-slash',
            'title' => 'Bajo Volumen de Llamadas',
            'message' => count($lowVolume) . ' agentes con menos de 50 llamadas en 8+ horas',
            'agents' => array_column($lowVolume, 'user_name')
        ];
    }

    return [
        'success' => true,
        'alerts' => $alerts
    ];
}

/**
 * Get Available Campaigns
 */
function getCampaigns($pdo, $startDate, $endDate)
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT current_user_group as campaign
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        ORDER BY campaign
    ");

    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return [
        'success' => true,
        'campaigns' => $campaigns
    ];
}

/**
 * Get Available Dates (all distinct upload dates in the database)
 */
function getAvailableDates($pdo, $startDate, $endDate)
{
    $stmt = $pdo->query("
        SELECT DISTINCT upload_date
        FROM vicidial_login_stats
        ORDER BY upload_date DESC
    ");

    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return [
        'success' => true,
        'dates' => $dates
    ];
}
?>