<?php
require_once __DIR__ . '/../../db.php';

echo "=== ESTRUCTURA DE job_applications ===\n\n";
$result = $pdo->query("DESCRIBE job_applications");
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    echo "  - {$row['Field']} ({$row['Type']})\n";
}
