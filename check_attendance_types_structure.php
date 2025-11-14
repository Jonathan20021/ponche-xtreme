<?php
require_once 'db.php';

$stmt = $pdo->query('DESCRIBE attendance_types');
echo "Estructura de attendance_types:\n";
while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $r['Field'] . ' - ' . $r['Type'] . "\n";
}
