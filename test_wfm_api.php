<?php
require_once __DIR__ . '/db.php';

// Simular sesión
$_SESSION['user_id'] = 1;

// Simular permiso
function userHasPermission($perm) {
    return true;
}

// Test query
echo "=== TESTING WFM API QUERY ===\n\n";

$startDate = '2026-02-01';
$endDate = '2026-02-24';
$startBound = $startDate . ' 00:00:00';
$endBound = $endDate . ' 23:59:59';

echo "Rango de fechas: $startBound a $endBound\n\n";

// Get campaigns
$campaignsStmt = $pdo->query("SELECT id, name, code FROM campaigns ORDER BY name");
$campaigns = $campaignsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
echo "Campañas encontradas: " . count($campaigns) . "\n";
foreach ($campaigns as $c) {
    echo "  - ID: {$c['id']}, Nombre: {$c['name']}\n";
}
echo "\n";

// Main query
$stmt = $pdo->prepare("
    SELECT 
        campaign_id,
        DATE(interval_start) as date_string,
        SUM(offered_calls) as sum_offered,
        SUM(answered_calls) as sum_answered,
        SUM(abandoned_calls) as sum_abandoned,
        SUM(total_talk_sec) as sum_talk_sec,
        SUM(total_wrap_sec) as sum_wrap_sec,
        SUM(total_call_sec) as sum_call_sec,
        AVG(avg_answer_speed_sec) as g_avg_answer_speed,
        AVG(avg_abandon_time_sec) as g_avg_abandon_time
    FROM vicidial_inbound_hourly
    WHERE interval_start BETWEEN ? AND ?
    GROUP BY campaign_id, DATE(interval_start)
    ORDER BY campaign_id, DATE(interval_start)
");
$stmt->execute([$startBound, $endBound]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo "Filas de datos encontradas: " . count($rows) . "\n\n";

if (count($rows) > 0) {
    echo "Primeras 5 filas:\n";
    foreach (array_slice($rows, 0, 5) as $row) {
        echo "  Campaña: {$row['campaign_id']}, Fecha: {$row['date_string']}, ";
        echo "Ofrecidas: {$row['sum_offered']}, Atendidas: {$row['sum_answered']}\n";
    }
    
    echo "\nÚltimas 5 filas:\n";
    foreach (array_slice($rows, -5) as $row) {
        echo "  Campaña: {$row['campaign_id']}, Fecha: {$row['date_string']}, ";
        echo "Ofrecidas: {$row['sum_offered']}, Atendidas: {$row['sum_answered']}\n";
    }
} else {
    echo "⚠️ NO SE ENCONTRARON DATOS\n\n";
    
    // Check if there's any data at all
    $totalCheck = $pdo->query("SELECT COUNT(*) as total FROM vicidial_inbound_hourly")->fetch();
    echo "Total de registros en vicidial_inbound_hourly: {$totalCheck['total']}\n";
    
    if ($totalCheck['total'] > 0) {
        $sample = $pdo->query("SELECT campaign_id, interval_start, offered_calls, answered_calls FROM vicidial_inbound_hourly LIMIT 5")->fetchAll();
        echo "\nMuestra de datos:\n";
        foreach ($sample as $s) {
            echo "  Campaña: {$s['campaign_id']}, Intervalo: {$s['interval_start']}, ";
            echo "Ofrecidas: {$s['offered_calls']}, Atendidas: {$s['answered_calls']}\n";
        }
    }
}

echo "\n=== FIN TEST ===\n";
