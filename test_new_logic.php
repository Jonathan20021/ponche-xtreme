<?php
require_once 'db.php';

echo "=== NUEVA LÓGICA: Rastrear último punch pagado ===\n\n";

$userStmt = $pdo->query("SELECT id FROM users WHERE username = 'joelc.evallish'");
$userId = $userStmt->fetchColumn();

$punchesStmt = $pdo->prepare("
    SELECT a.timestamp, a.type, at.is_paid
    FROM attendance a
    LEFT JOIN attendance_types at ON CAST(at.slug AS CHAR) = CAST(a.type AS CHAR)
    WHERE a.user_id = ? AND DATE(a.timestamp) = '2025-11-13'
    ORDER BY a.timestamp
");
$punchesStmt->execute([$userId]);
$punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);

$paidTypesStmt = $pdo->query("SELECT slug FROM attendance_types WHERE is_paid = 1");
$paidTypes = $paidTypesStmt->fetchAll(PDO::FETCH_COLUMN);
$paidTypesUpper = array_map('strtoupper', $paidTypes);

echo "LÓGICA CORREGIDA:\n";
echo "- Rastrear el último punch del período\n";
echo "- Calcular duración usando lastPaidPunchTime en lugar de previousPunchTime\n\n";

$inPaidState = false;
$paidStartTime = null;
$lastPaidPunchTime = null; // ← NUEVO: rastrear último punch pagado
$totalSeconds = 0;
$periods = [];

foreach ($punches as $i => $punch) {
    $punchTime = strtotime($punch['timestamp']);
    $punchType = strtoupper($punch['type']);
    $isPaid = in_array($punchType, $paidTypesUpper);
    
    if ($isPaid) {
        $lastPaidPunchTime = $punchTime; // Actualizar siempre que sea pagado
        
        if (!$inPaidState) {
            // Inicio de período pagado
            $paidStartTime = $punchTime;
            $inPaidState = true;
            $periodStartStr = $punch['timestamp'];
        }
        // Si ya estaba en paid state, solo actualiza lastPaidPunchTime
        
    } elseif (!$isPaid && $inPaidState) {
        // Fin de período pagado
        if ($paidStartTime !== null && $lastPaidPunchTime !== null) {
            $duration = $lastPaidPunchTime - $paidStartTime;
            $hours = round($duration / 3600, 2);
            
            $endPunch = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if (strtotime($punches[$j]['timestamp']) == $lastPaidPunchTime) {
                    $endPunch = $punches[$j]['timestamp'];
                    break;
                }
            }
            
            $periods[] = [
                'start' => $periodStartStr,
                'end' => $endPunch,
                'hours' => $hours
            ];
            $totalSeconds += $duration;
        }
        $inPaidState = false;
        $paidStartTime = null;
        $lastPaidPunchTime = null;
    }
}

// Si termina en estado pagado
if ($inPaidState && $paidStartTime !== null && $lastPaidPunchTime !== null) {
    $duration = $lastPaidPunchTime - $paidStartTime;
    $hours = round($duration / 3600, 2);
    
    $endPunch = null;
    for ($j = count($punches) - 1; $j >= 0; $j--) {
        if (strtotime($punches[$j]['timestamp']) == $lastPaidPunchTime) {
            $endPunch = $punches[$j]['timestamp'];
            break;
        }
    }
    
    $periods[] = [
        'start' => $periodStartStr,
        'end' => $endPunch,
        'hours' => $hours
    ];
    $totalSeconds += $duration;
}

echo "Períodos detectados:\n";
foreach ($periods as $p) {
    echo "  {$p['start']} → {$p['end']} = {$p['hours']} horas\n";
}

$totalHours = round($totalSeconds / 3600, 2);
echo "\nTOTAL: {$totalHours} horas\n\n";

echo "Comparación con el método de test_punch_logic.php (lógica alternativa 2): 7.29 horas\n";
if (abs($totalHours - 7.29) < 0.01) {
    echo "✅ CORRECTO\n";
} else {
    echo "❌ DIFERENCIA DETECTADA\n";
}
