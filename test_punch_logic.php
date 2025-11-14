<?php
require_once 'db.php';

echo "=== CASO REAL: joelc.evallish 2025-11-13 ===\n\n";

$stmt = $pdo->query("
    SELECT 
        a.timestamp,
        a.type,
        at.is_paid
    FROM attendance a
    LEFT JOIN attendance_types at ON CAST(at.slug AS CHAR) = CAST(a.type AS CHAR)
    WHERE a.user_id = (SELECT id FROM users WHERE username = 'joelc.evallish')
    AND DATE(a.timestamp) = '2025-11-13'
    ORDER BY a.timestamp
");

$punches = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Punches del día:\n";
foreach ($punches as $p) {
    $paid = $p['is_paid'] == 1 ? 'PAGADO' : 'NO PAGADO';
    echo "  " . substr($p['timestamp'], 11) . " - {$p['type']} ({$paid})\n";
}

echo "\n--- LÓGICA ACTUAL (suma cuando punch actual es pagado) ---\n";
$total1 = 0;
for ($i = 1; $i < count($punches); $i++) {
    $prev = strtotime($punches[$i-1]['timestamp']);
    $curr = strtotime($punches[$i]['timestamp']);
    $duration = ($curr - $prev) / 3600;
    
    if ($punches[$i]['is_paid'] == 1) {
        echo sprintf("  %s → %s (%s): %.2f horas ✓\n", 
            substr($punches[$i-1]['timestamp'], 11),
            substr($punches[$i]['timestamp'], 11),
            $punches[$i]['type'],
            $duration
        );
        $total1 += $duration;
    } else {
        echo sprintf("  %s → %s (%s): NO suma (no pagado)\n", 
            substr($punches[$i-1]['timestamp'], 11),
            substr($punches[$i]['timestamp'], 11),
            $punches[$i]['type']
        );
    }
}
echo "TOTAL: " . round($total1, 2) . " horas\n";

echo "\n--- LÓGICA ALTERNATIVA 1: Solo suma si el punch ANTERIOR era NO pagado ---\n";
$total2 = 0;
for ($i = 1; $i < count($punches); $i++) {
    $prev = strtotime($punches[$i-1]['timestamp']);
    $curr = strtotime($punches[$i]['timestamp']);
    $duration = ($curr - $prev) / 3600;
    
    if ($punches[$i]['is_paid'] == 1 && $punches[$i-1]['is_paid'] == 0) {
        echo sprintf("  %s → %s: %.2f horas ✓ (transición NO PAGADO → PAGADO)\n", 
            substr($punches[$i-1]['timestamp'], 11),
            substr($punches[$i]['timestamp'], 11),
            $duration
        );
        $total2 += $duration;
    } else {
        echo sprintf("  %s → %s: NO suma\n", 
            substr($punches[$i-1]['timestamp'], 11),
            substr($punches[$i]['timestamp'], 11)
        );
    }
}
echo "TOTAL: " . round($total2, 2) . " horas\n";

echo "\n--- LÓGICA ALTERNATIVA 2: Intervalos de tiempo en estados pagados ---\n";
echo "(Busca el inicio y fin de cada periodo 'pagado')\n";
$total3 = 0;
$inPaidState = false;
$paidStart = null;

foreach ($punches as $i => $punch) {
    $isPaid = $punch['is_paid'] == 1;
    $time = substr($punch['timestamp'], 11);
    
    if ($isPaid && !$inPaidState) {
        // Entrando a estado pagado
        $paidStart = strtotime($punch['timestamp']);
        $inPaidState = true;
        echo "  {$time} - Inicia periodo PAGADO ({$punch['type']})\n";
    } elseif ($isPaid && $inPaidState) {
        // Sigue en estado pagado (mismo tipo repetido)
        echo "  {$time} - Continúa PAGADO ({$punch['type']}) - actualiza marcador\n";
        // NO sumamos, solo actualizamos el marcador de tiempo
    } elseif (!$isPaid && $inPaidState) {
        // Saliendo de estado pagado
        $paidEnd = strtotime($punches[$i-1]['timestamp']);
        $duration = ($paidEnd - $paidStart) / 3600;
        echo sprintf("  {$time} - Fin periodo PAGADO: %.2f horas ✓\n", $duration);
        $total3 += $duration;
        $inPaidState = false;
    }
}

// Si termina el día en estado pagado
if ($inPaidState) {
    $lastPaidTime = strtotime($punches[count($punches)-1]['timestamp']);
    $duration = ($lastPaidTime - $paidStart) / 3600;
    echo sprintf("  Fin del día en estado PAGADO: %.2f horas ✓\n", $duration);
    $total3 += $duration;
}

echo "TOTAL: " . round($total3, 2) . " horas\n";

echo "\n=== COMPARACIÓN ===\n";
echo "Lógica actual (suma cada punch pagado): " . round($total1, 2) . " horas\n";
echo "Alternativa 1 (transiciones): " . round($total2, 2) . " horas\n";
echo "Alternativa 2 (intervalos): " . round($total3, 2) . " horas\n";
