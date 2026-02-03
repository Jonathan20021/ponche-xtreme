<?php
require_once __DIR__ . '/../../db.php';

echo "=== ESTRUCTURA DE employment_contracts ===\n\n";
$result = $pdo->query("DESCRIBE employment_contracts");
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    echo "  - {$row['Field']} ({$row['Type']})\n";
}
