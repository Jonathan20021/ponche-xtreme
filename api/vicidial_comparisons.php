<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';
require_once __DIR__ . '/../lib/vicidial_report_adapter.php';

header('Content-Type: application/json');

// Check permission
if (!isset($_SESSION['user_id']) || !userHasPermission('vicidial_reports')) {
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para realizar esta acción']);
    exit;
}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$campaign = $_GET['campaign'] ?? '';

// Daily date override
$dailyDate = $_GET['daily_date'] ?? '';
if ($dailyDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyDate)) {
    $startDate = $dailyDate;
    $endDate = $dailyDate;
}

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
    // Fuente activa (sync automático o CSV) vía el adaptador.
    $rows = vicidialReportsAgentStats($pdo, $startDate, $endDate, $campaign);

    $totalCalls = $totalTime = $totalTalk = $totalDispo = $totalDead = $totalConversions = $totalNoContact = 0;
    foreach ($rows as $r) {
        $totalCalls       += (int) ($r['total_calls'] ?? 0);
        $totalTime        += (int) ($r['time_total'] ?? 0);
        $totalTalk        += (int) ($r['talk_time'] ?? 0);
        $totalDispo       += (int) ($r['dispo_time'] ?? 0);
        $totalDead        += (int) ($r['dead_time'] ?? 0);
        $totalConversions += (int) ($r['sale'] ?? 0) + (int) ($r['pedido'] ?? 0) + (int) ($r['orden'] ?? 0);
        $totalNoContact   += (int) ($r['no_contact'] ?? 0);
    }
    $data = ['total_agents' => count($rows)];

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