<?php
require_once 'db.php';

echo "=== PRUEBA FINAL: MÚLTIPLES CASOS COMPLEJOS ===\n\n";

// Test con varios usuarios que tienen patrones diferentes
$testUsers = ['joelc.evallish', 'hugo', 'admin'];
$testDate = '2025-11-13';

foreach ($testUsers as $username) {
    $userStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE username = ?");
    $userStmt->execute([$username]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "Usuario {$username} no encontrado\n\n";
        continue;
    }
    
    echo str_repeat('=', 80) . "\n";
    echo "Usuario: {$user['full_name']} ({$username})\n";
    echo str_repeat('=', 80) . "\n";
    
    // Get punches
    $punchesStmt = $pdo->prepare("
        SELECT a.timestamp, a.type, at.is_paid
        FROM attendance a
        LEFT JOIN attendance_types at ON CAST(at.slug AS CHAR) = CAST(a.type AS CHAR)
        WHERE a.user_id = ? AND DATE(a.timestamp) = ?
        ORDER BY a.timestamp
    ");
    $punchesStmt->execute([$user['id'], $testDate]);
    $punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($punches)) {
        echo "No hay punches para esta fecha\n\n";
        continue;
    }
    
    echo "Punches:\n";
    foreach ($punches as $p) {
        $paid = $p['is_paid'] == 1 ? 'PAGADO' : 'NO PAGADO';
        echo "  " . $p['timestamp'] . " - {$p['type']} ({$paid})\n";
    }
    
    // Get paid types for this check
    $paidTypesStmt = $pdo->query("SELECT slug FROM attendance_types WHERE is_paid = 1");
    $paidTypes = $paidTypesStmt->fetchAll(PDO::FETCH_COLUMN);
    $paidTypesUpper = array_map('strtoupper', $paidTypes);
    
    // Calculate with interval logic
    $inPaidState = false;
    $paidStartTime = null;
    $totalSeconds = 0;
    $periods = [];
    
    foreach ($punches as $i => $punch) {
        $punchTime = strtotime($punch['timestamp']);
        $punchType = strtoupper($punch['type']);
        $isPaid = in_array($punchType, $paidTypesUpper);
        
        if ($isPaid && !$inPaidState) {
            $paidStartTime = $punchTime;
            $inPaidState = true;
            $periodStart = $punch['timestamp'];
        } elseif (!$isPaid && $inPaidState) {
            if ($paidStartTime !== null && $i > 0) {
                $previousPunchTime = strtotime($punches[$i - 1]['timestamp']);
                if ($previousPunchTime > $paidStartTime) {
                    $duration = $previousPunchTime - $paidStartTime;
                    $totalSeconds += $duration;
                    $periods[] = [
                        'start' => $periodStart,
                        'end' => $punches[$i - 1]['timestamp'],
                        'hours' => round($duration / 3600, 2)
                    ];
                }
            }
            $inPaidState = false;
            $paidStartTime = null;
        }
    }
    
    if ($inPaidState && $paidStartTime !== null) {
        $lastPunch = end($punches);
        $lastPunchTime = strtotime($lastPunch['timestamp']);
        if ($lastPunchTime > $paidStartTime) {
            $duration = $lastPunchTime - $paidStartTime;
            $totalSeconds += $duration;
            $periods[] = [
                'start' => $periodStart,
                'end' => $lastPunch['timestamp'],
                'hours' => round($duration / 3600, 2)
            ];
        }
    }
    
    echo "\nPeríodos pagados identificados:\n";
    if (empty($periods)) {
        echo "  (ninguno)\n";
    } else {
        foreach ($periods as $period) {
            echo "  {$period['start']} → {$period['end']} = {$period['hours']} horas\n";
        }
    }
    
    $totalHours = round($totalSeconds / 3600, 2);
    echo "\n✅ TOTAL HORAS PAGADAS: {$totalHours} horas\n\n";
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "PRUEBA COMPLETADA\n";
echo str_repeat('=', 80) . "\n";
