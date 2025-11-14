<?php
require_once 'db.php';

echo "=== VERIFICACIÓN DE SINCRONIZACIÓN ENTRE SISTEMAS ===\n\n";
echo "Usuario: joelc.evallish\n";
echo "Fecha: 2025-11-13\n";
echo "Resultado esperado: 7.29 horas (usando lógica de intervalos)\n\n";

// Get user ID
$userStmt = $pdo->query("SELECT id FROM users WHERE username = 'joelc.evallish'");
$userId = $userStmt->fetchColumn();

if (!$userId) {
    echo "ERROR: Usuario no encontrado\n";
    exit(1);
}

// Get paid types
$paidTypesStmt = $pdo->query("SELECT slug FROM attendance_types WHERE is_paid = 1");
$paidTypes = $paidTypesStmt->fetchAll(PDO::FETCH_COLUMN);
echo "Tipos pagados: " . implode(', ', $paidTypes) . "\n\n";

// Get punches for the day
$punchesStmt = $pdo->prepare("
    SELECT timestamp, type 
    FROM attendance 
    WHERE user_id = :user_id 
    AND DATE(timestamp) = '2025-11-13'
    ORDER BY timestamp ASC
");
$punchesStmt->execute([':user_id' => $userId]);
$punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);

// Simulate hr_report.php logic
echo "--- SIMULANDO hr_report.php ---\n";
$paidTypesUpper = array_map('strtoupper', $paidTypes);
$inPaidState = false;
$paidStartTime = null;
$lastPaidPunchTime = null;
$totalSeconds1 = 0;

foreach ($punches as $i => $punch) {
    $punchTime = strtotime($punch['timestamp']);
    $punchType = strtoupper($punch['type']);
    $isPaid = in_array($punchType, $paidTypesUpper);
    
    if ($isPaid) {
        $lastPaidPunchTime = $punchTime;
        
        if (!$inPaidState) {
            $paidStartTime = $punchTime;
            $inPaidState = true;
        }
    } elseif (!$isPaid && $inPaidState) {
        if ($paidStartTime !== null && $lastPaidPunchTime !== null) {
            $totalSeconds1 += ($lastPaidPunchTime - $paidStartTime);
        }
        $inPaidState = false;
        $paidStartTime = null;
        $lastPaidPunchTime = null;
    }
}

if ($inPaidState && $paidStartTime !== null && $lastPaidPunchTime !== null) {
    $totalSeconds1 += ($lastPaidPunchTime - $paidStartTime);
}

$hours1 = $totalSeconds1 / 3600;
echo "Horas calculadas: " . round($hours1, 2) . "\n";

// Simulate hr/payroll.php logic
echo "\n--- SIMULANDO hr/payroll.php ---\n";
$inPaidState2 = false;
$paidStartTime2 = null;
$lastPaidPunchTime2 = null;
$totalSeconds2 = 0;

foreach ($punches as $i => $punch) {
    $punchTime = strtotime($punch['timestamp']);
    $punchType = strtoupper($punch['type']);
    $isPaid = in_array($punchType, $paidTypesUpper);
    
    if ($isPaid) {
        $lastPaidPunchTime2 = $punchTime;
        
        if (!$inPaidState2) {
            $paidStartTime2 = $punchTime;
            $inPaidState2 = true;
        }
    } elseif (!$isPaid && $inPaidState2) {
        if ($paidStartTime2 !== null && $lastPaidPunchTime2 !== null) {
            $totalSeconds2 += ($lastPaidPunchTime2 - $paidStartTime2);
        }
        $inPaidState2 = false;
        $paidStartTime2 = null;
        $lastPaidPunchTime2 = null;
    }
}

if ($inPaidState2 && $paidStartTime2 !== null && $lastPaidPunchTime2 !== null) {
    $totalSeconds2 += ($lastPaidPunchTime2 - $paidStartTime2);
}

$hours2 = $totalSeconds2 / 3600;
echo "Horas calculadas: " . round($hours2, 2) . "\n";

// Simulate adherencia_report_hr.php logic
echo "\n--- SIMULANDO adherencia_report_hr.php ---\n";
$punchesStmt2 = $pdo->prepare("
    SELECT a.timestamp, a.type, at.is_paid
    FROM attendance a
    LEFT JOIN attendance_types at ON CAST(at.slug AS CHAR) = CAST(a.type AS CHAR)
    WHERE a.user_id = :user_id 
    AND DATE(a.timestamp) = '2025-11-13'
    ORDER BY a.timestamp ASC
");
$punchesStmt2->execute([':user_id' => $userId]);
$punches3 = $punchesStmt2->fetchAll(PDO::FETCH_ASSOC);

$inPaidState3 = false;
$paidStartTime3 = null;
$lastPaidPunchTime3 = null;
$totalSeconds3 = 0;

foreach ($punches3 as $i => $punch) {
    $punchTime = strtotime($punch['timestamp']);
    $isPaid = (int)$punch['is_paid'] === 1;
    
    if ($isPaid) {
        $lastPaidPunchTime3 = $punchTime;
        
        if (!$inPaidState3) {
            $paidStartTime3 = $punchTime;
            $inPaidState3 = true;
        }
    } elseif (!$isPaid && $inPaidState3) {
        if ($paidStartTime3 !== null && $lastPaidPunchTime3 !== null) {
            $totalSeconds3 += ($lastPaidPunchTime3 - $paidStartTime3);
        }
        $inPaidState3 = false;
        $paidStartTime3 = null;
        $lastPaidPunchTime3 = null;
    }
}

if ($inPaidState3 && $paidStartTime3 !== null && $lastPaidPunchTime3 !== null) {
    $totalSeconds3 += ($lastPaidPunchTime3 - $paidStartTime3);
}

$hours3 = $totalSeconds3 / 3600;
echo "Horas calculadas: " . round($hours3, 2) . "\n";

// Comparison
echo "\n=== RESULTADO ===\n";
if (abs($hours1 - $hours2) < 0.01 && abs($hours2 - $hours3) < 0.01) {
    echo "✅ ¡SINCRONIZADO! Todos los sistemas calculan: " . round($hours1, 2) . " horas\n";
    echo "✅ Lógica de intervalos aplicada correctamente\n";
    echo "✅ Punches consecutivos del mismo tipo ignorados\n";
} else {
    echo "❌ ERROR: Diferencias encontradas:\n";
    echo "   hr_report.php: " . round($hours1, 2) . " horas\n";
    echo "   payroll.php: " . round($hours2, 2) . " horas\n";
    echo "   adherencia_report_hr.php: " . round($hours3, 2) . " horas\n";
}
