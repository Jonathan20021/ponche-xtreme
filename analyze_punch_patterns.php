<?php
require_once 'db.php';

echo "=== ANÁLISIS DE PATRONES DE PUNCHES ===\n\n";

// Obtener ejemplos de días con múltiples punches del mismo tipo
$stmt = $pdo->query("
    SELECT 
        u.username,
        u.full_name,
        DATE(a.timestamp) as date,
        a.timestamp,
        a.type,
        at.is_paid
    FROM attendance a
    JOIN users u ON u.id = a.user_id
    LEFT JOIN attendance_types at ON CAST(at.slug AS CHAR) = CAST(a.type AS CHAR)
    WHERE DATE(a.timestamp) = '2025-11-13'
    AND u.username IN ('hugo', 'admin')
    ORDER BY u.username, a.timestamp
");

$currentUser = null;
$currentDate = null;
$punches = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($currentUser !== $row['username'] && $currentUser !== null) {
        echo "\n" . str_repeat('-', 80) . "\n";
    }
    
    if ($currentUser !== $row['username'] || $currentDate !== $row['date']) {
        if ($currentUser !== null) {
            echo "\n";
        }
        echo "{$row['full_name']} ({$row['username']}) - {$row['date']}:\n";
        $currentUser = $row['username'];
        $currentDate = $row['date'];
    }
    
    $paid = $row['is_paid'] == 1 ? 'PAGADO' : 'NO PAGADO';
    echo "  " . substr($row['timestamp'], 11) . " - {$row['type']} ({$paid})\n";
    
    $punches[] = $row;
}

echo "\n\n=== ANÁLISIS DE LÓGICA ACTUAL ===\n";
echo "Si tenemos: 10:00 DISPONIBLE → 11:00 DISPONIBLE → 12:00 WASAPI\n";
echo "Lógica actual suma:\n";
echo "  - 11:00 DISPONIBLE es pagado: suma (11:00 - 10:00) = 1 hora ✓\n";
echo "  - 12:00 WASAPI es pagado: suma (12:00 - 11:00) = 1 hora ✓\n";
echo "  - Total: 2 horas ✓ CORRECTO\n\n";

echo "Si tenemos: 10:00 Entry → 11:00 DISPONIBLE → 12:00 Lunch → 13:00 DISPONIBLE\n";
echo "Lógica actual suma:\n";
echo "  - 11:00 DISPONIBLE es pagado: suma (11:00 - 10:00) = 1 hora ✓\n";
echo "  - 12:00 Lunch NO es pagado: NO suma\n";
echo "  - 13:00 DISPONIBLE es pagado: suma (13:00 - 12:00) = 1 hora ✓\n";
echo "  - Total: 2 horas ✓ CORRECTO\n\n";

echo "=== VERIFICACIÓN: ¿Hay casos de punches consecutivos iguales? ===\n";
$consecutiveStmt = $pdo->query("
    SELECT 
        u.username,
        DATE(a1.timestamp) as date,
        a1.timestamp as time1,
        a1.type as type1,
        a2.timestamp as time2,
        a2.type as type2,
        TIMESTAMPDIFF(MINUTE, a1.timestamp, a2.timestamp) as minutes_diff
    FROM attendance a1
    JOIN attendance a2 ON a2.user_id = a1.user_id 
        AND a2.id = (
            SELECT MIN(id) FROM attendance 
            WHERE user_id = a1.user_id 
            AND timestamp > a1.timestamp
            AND DATE(timestamp) = DATE(a1.timestamp)
        )
    JOIN users u ON u.id = a1.user_id
    WHERE DATE(a1.timestamp) BETWEEN '2025-11-01' AND '2025-11-14'
    AND a1.type = a2.type
    ORDER BY a1.timestamp DESC
    LIMIT 20
");

$consecutiveCount = 0;
while ($row = $consecutiveStmt->fetch(PDO::FETCH_ASSOC)) {
    if ($consecutiveCount === 0) {
        echo "\nEncontrados punches consecutivos del MISMO tipo:\n";
    }
    $consecutiveCount++;
    echo "  {$row['username']} - {$row['date']}: {$row['type1']} a las " . 
         substr($row['time1'], 11) . " → {$row['type2']} a las " . 
         substr($row['time2'], 11) . " ({$row['minutes_diff']} minutos)\n";
}

if ($consecutiveCount === 0) {
    echo "\n✓ NO se encontraron punches consecutivos del mismo tipo\n";
} else {
    echo "\n⚠ ALERTA: Se encontraron {$consecutiveCount} casos de punches consecutivos iguales\n";
    echo "Esto podría indicar:\n";
    echo "  1. Errores de registro (doble click)\n";
    echo "  2. Empleado salió y volvió al mismo estado\n";
    echo "  3. Sistema permite re-ponchar el mismo tipo\n";
}
