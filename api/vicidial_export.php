<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

// Check permission
if (!isset($_SESSION['user_id']) || !userHasPermission('vicidial_reports')) {
    die('No tienes permiso para realizar esta acción');
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

// Fetch data
$campaignFilter = $campaign ? "AND current_user_group = :campaign" : "";
$stmt = $pdo->prepare("
    SELECT 
        user_name,
        user_id,
        current_user_group,
        SUM(calls) as total_calls,
        SUM(time_total) as time_total,
        SUM(pause_time) as pause_time,
        SUM(wait_time) as wait_time,
        SUM(talk_time) as talk_time,
        SUM(dispo_time) as dispo_time,
        SUM(dead_time) as dead_time,
        SUM(customer_time) as customer_time,
        SUM(sale) as sale,
        SUM(pedido) as pedido,
        SUM(orden) as orden,
        SUM(a) as a,
        SUM(b) as b,
        SUM(callbk) as callbk,
        SUM(colgo) as colgo,
        SUM(n) as n,
        SUM(nocal) as nocal,
        SUM(silenc) as silenc
    FROM vicidial_login_stats
    WHERE upload_date BETWEEN :start_date AND :end_date
    $campaignFilter
    GROUP BY user_name, user_id, current_user_group
    ORDER BY total_calls DESC
");

$params = ['start_date' => $startDate, 'end_date' => $endDate];
if ($campaign) {
    $params['campaign'] = $campaign;
}

$stmt->execute($params);
$stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to format seconds to HH:MM:SS
function formatTime($seconds)
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

// Set headers for Excel download
$filename = "vicidial_report_" . $startDate . "_to_" . $endDate . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 compatibility
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Write header row
fputcsv($output, [
    'Agente',
    'ID Usuario',
    'Campaña',
    'Llamadas',
    'Conversiones',
    'Tasa Conversión %',
    'AHT (MM:SS)',
    'Ocupación %',
    'Eficiencia %',
    'Tiempo Total',
    'Tiempo Talk',
    'Tiempo Pause',
    'Tiempo Wait',
    'Tiempo Dispo',
    'Tiempo Dead',
    'Ventas',
    'Pedidos',
    'Órdenes',
    'Callbacks',
    'No Contacto',
    'Silencio'
]);

// Write data rows
foreach ($stats as $row) {
    $conversions = $row['sale'] + $row['pedido'] + $row['orden'];
    $conversionRate = $row['total_calls'] > 0 ? round(($conversions / $row['total_calls']) * 100, 2) : 0;
    $aht = $row['total_calls'] > 0 ? round(($row['talk_time'] + $row['dispo_time']) / $row['total_calls'], 0) : 0;
    $occupancy = $row['time_total'] > 0 ? round((($row['talk_time'] + $row['dispo_time'] + $row['dead_time']) / $row['time_total']) * 100, 2) : 0;
    $efficiency = $row['time_total'] > 0 ? round(($row['talk_time'] / $row['time_total']) * 100, 2) : 0;

    fputcsv($output, [
        $row['user_name'],
        $row['user_id'],
        $row['current_user_group'],
        $row['total_calls'],
        $conversions,
        $conversionRate,
        gmdate('i:s', $aht),
        $occupancy,
        $efficiency,
        formatTime($row['time_total']),
        formatTime($row['talk_time']),
        formatTime($row['pause_time']),
        formatTime($row['wait_time']),
        formatTime($row['dispo_time']),
        formatTime($row['dead_time']),
        $row['sale'],
        $row['pedido'],
        $row['orden'],
        $row['callbk'],
        $row['nocal'],
        $row['silenc']
    ]);
}

fclose($output);
exit;
?>