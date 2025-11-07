<?php
require_once 'db.php';

echo "=== employees table structure ===\n";
$stmt = $pdo->query('DESCRIBE employees');
while ($row = $stmt->fetch()) {
    echo $row['Field'] . ' (' . $row['Type'] . ')' . PHP_EOL;
}
