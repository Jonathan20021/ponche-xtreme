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

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$campaign = $_GET['campaign'] ?? '';

try {
    // Calculate previous period dates
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $diff = $start->diff($end);
    $days = $diff->days + 1;

    $prevEnd = clone $start;
    $prevEnd->modify('-1 day');
    $prevStart = clone $prevEnd;
    $prevStart->modify('-' . ($days - 1) . ' days');

    $prevStartDate = $prevStart->format('Y-m-d');
    $prevEndDate = $prevEnd->format('Y-m-d');

    // Get current period data
    $currentData = getPeriodData($pdo, $startDate, $endDate, $campaign);

    // Get previous period data
    $previousData = getPeriodData($pdo, $prevStartDate, $prevEndDate, $campaign);

    // Calculate comparisons
    $comparisons = calculateComparisons($currentData, $previousData);

    echo json_encode([
        'success' => true,
        'current_period' => [
            'start' => $startDate,
            'end' => $endDate,
            'data' => $currentData
        ],
        'previous_period' => [
            'start' => $prevStartDate,
            'end' => $prevEndDate,
            'data' => $previousData
        ],
        'comparisons' => $comparisons
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Get aggregated data for a period
 */
function getPeriodData($pdo, $startDate, $endDate, $campaign = '')
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

    $totalCalls = (int) $data['total_calls'];
    $totalTime = (int) $data['total_time'];
    $totalTalk = (int) $data['total_talk'];
    $totalDispo = (int) $data['total_dispo'];
    $totalDead = (int) $data['total_dead'];
    $totalConversions = (int) $data['total_conversions'];
    $totalNoContact = (int) $data['total_no_contact'];

    return [
        'calls' => $totalCalls,
        'conversions' => $totalConversions,
        'conversion_rate' => $totalCalls > 0 ? round(($totalConversions / $totalCalls) * 100, 2) : 0,
        'aht' => $totalCalls > 0 ? round(($totalTalk + $totalDispo) / $totalCalls, 0) : 0,
        'occupancy' => $totalTime > 0 ? round((($totalTalk + $totalDispo + $totalDead) / $totalTime) * 100, 2) : 0,
        'productivity' => $totalTime > 0 ? round($totalCalls / ($totalTime / 3600), 2) : 0,
        'contact_rate' => $totalCalls > 0 ? round((($totalCalls - $totalNoContact) / $totalCalls) * 100, 2) : 0,
        'efficiency' => $totalTime > 0 ? round(($totalTalk / $totalTime) * 100, 2) : 0,
        'agents' => (int) $data['total_agents']
    ];
}

/**
 * Calculate comparisons and variances
 */
function calculateComparisons($current, $previous)
{
    $comparisons = [];

    $metrics = [
        'conversion_rate' => ['label' => 'Tasa de Conversión', 'unit' => '%', 'format' => 'percentage'],
        'aht' => ['label' => 'AHT Promedio', 'unit' => 's', 'format' => 'time'],
        'occupancy' => ['label' => 'Ocupación', 'unit' => '%', 'format' => 'percentage'],
        'productivity' => ['label' => 'Llamadas/Hora', 'unit' => 'llamadas', 'format' => 'number'],
        'contact_rate' => ['label' => 'Tasa de Contacto', 'unit' => '%', 'format' => 'percentage'],
        'efficiency' => ['label' => 'Eficiencia', 'unit' => '%', 'format' => 'percentage'],
        'calls' => ['label' => 'Total Llamadas', 'unit' => 'llamadas', 'format' => 'number'],
        'conversions' => ['label' => 'Conversiones', 'unit' => 'conversiones', 'format' => 'number']
    ];

    foreach ($metrics as $key => $info) {
        $currentValue = $current[$key] ?? 0;
        $previousValue = $previous[$key] ?? 0;

        // Calculate variance
        if ($previousValue > 0) {
            $variance = (($currentValue - $previousValue) / $previousValue) * 100;
        } else {
            $variance = $currentValue > 0 ? 100 : 0;
        }

        // Determine trend
        $trend = 'neutral';
        if ($variance > 2) {
            $trend = 'up';
        } elseif ($variance < -2) {
            $trend = 'down';
        }

        // For AHT, lower is better
        if ($key === 'aht') {
            $trend = $variance > 2 ? 'down' : ($variance < -2 ? 'up' : 'neutral');
        }

        $comparisons[$key] = [
            'label' => $info['label'],
            'current' => $currentValue,
            'previous' => $previousValue,
            'variance' => round($variance, 2),
            'trend' => $trend,
            'unit' => $info['unit'],
            'format' => $info['format']
        ];
    }

    return $comparisons;
}
?>