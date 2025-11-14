<?php
require_once 'db.php';

echo "=== DEBUG: Paso a paso del primer período ===\n\n";

$userStmt = $pdo->query("SELECT id FROM users WHERE username = 'joelc.evallish'");
$userId = $userStmt->fetchColumn();

$punchesStmt = $pdo->prepare("
    SELECT a.timestamp, a.type, at.is_paid
    FROM attendance a
    LEFT JOIN attendance_types at ON CAST(at.slug AS CHAR) = CAST(a.type AS CHAR)
    WHERE a.user_id = ? AND DATE(a.timestamp) = '2025-11-13'
    ORDER BY a.timestamp
    LIMIT 4
");
$punchesStmt->execute([$userId]);
$punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);

echo "Primeros 4 punches:\n";
foreach ($punches as $i => $p) {
    echo "  [$i] " . substr($p['timestamp'], 11) . " - {$p['type']} (is_paid={$p['is_paid']})\n";
}

$paidTypesStmt = $pdo->query("SELECT slug FROM attendance_types WHERE is_paid = 1");
$paidTypes = $paidTypesStmt->fetchAll(PDO::FETCH_COLUMN);
$paidTypesUpper = array_map('strtoupper', $paidTypes);

echo "\nTipos pagados: " . implode(', ', $paidTypesUpper) . "\n\n";

echo "Ejecución paso a paso:\n";
$inPaidState = false;
$paidStartTime = null;
$totalSeconds = 0;
$periodStart = null;

foreach ($punches as $i => $punch) {
    $punchTime = strtotime($punch['timestamp']);
    $punchType = strtoupper($punch['type']);
    $isPaid = in_array($punchType, $paidTypesUpper);
    
    echo "\n[$i] " . substr($punch['timestamp'], 11) . " {$punch['type']} (isPaid=" . ($isPaid ? 'true' : 'false') . ")\n";
    echo "  Estado antes: inPaidState=" . ($inPaidState ? 'true' : 'false');
    if ($paidStartTime) {
        echo ", paidStartTime=" . date('H:i:s', $paidStartTime);
    }
    echo "\n";
    
    if ($isPaid && !$inPaidState) {
        echo "  → Entra en estado PAGADO\n";
        $paidStartTime = $punchTime;
        $inPaidState = true;
        $periodStart = $punch['timestamp'];
        echo "  paidStartTime = " . date('H:i:s', $paidStartTime) . "\n";
        
    } elseif ($isPaid && $inPaidState) {
        echo "  → Continúa en estado PAGADO (ignora)\n";
        
    } elseif (!$isPaid && $inPaidState) {
        echo "  → Sale de estado PAGADO\n";
        if ($paidStartTime !== null && $i > 0) {
            $previousPunchTime = strtotime($punches[$i - 1]['timestamp']);
            echo "  previousPunchTime ($i-1) = " . date('H:i:s', $previousPunchTime) . "\n";
            echo "  paidStartTime = " . date('H:i:s', $paidStartTime) . "\n";
            
            if ($previousPunchTime > $paidStartTime) {
                $duration = $previousPunchTime - $paidStartTime;
                $hours = round($duration / 3600, 4);
                echo "  ✓ Suma duración: {$duration} segundos = {$hours} horas\n";
                $totalSeconds += $duration;
            } else {
                echo "  ✗ previousPunchTime <= paidStartTime, NO suma\n";
            }
        } else {
            echo "  ✗ Condiciones no cumplidas (paidStartTime null o i=0)\n";
        }
        $inPaidState = false;
        $paidStartTime = null;
    } else {
        echo "  → No pagado y ya estaba fuera (ignora)\n";
    }
}

echo "\n\nTotal acumulado: " . round($totalSeconds / 3600, 4) . " horas\n";
