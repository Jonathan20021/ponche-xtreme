<?php
require_once __DIR__ . '/db.php';

echo "=== ESTRUCTURA: vicidial_inbound_hourly ===\n\n";

try {
    $stmt = $pdo->query("DESCRIBE vicidial_inbound_hourly");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $col) {
        if ($col['Field'] === 'campaign_id') {
            echo "✨ campaign_id:\n";
            echo "   Type: " . $col['Type'] . "\n";
            echo "   Null: " . $col['Null'] . "\n";
            echo "   Key: " . $col['Key'] . "\n";
            echo "   Default: " . ($col['Default'] ?? 'NULL') . "\n";
            echo "   Extra: " . $col['Extra'] . "\n\n";
        }
    }

    echo "\n=== ÍNDICES ===\n\n";

    $stmt = $pdo->query("SHOW INDEX FROM vicidial_inbound_hourly");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($indexes as $idx) {
        $grouped[$idx['Key_name']][] = $idx;
    }

    foreach ($grouped as $keyName => $cols) {
        echo "🔑 $keyName:\n";
        echo "   Unique: " . ($cols[0]['Non_unique'] == 0 ? 'YES' : 'NO') . "\n";
        echo "   Columns: " . implode(', ', array_column($cols, 'Column_name')) . "\n\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== ESTRUCTURA: campaign_staffing_forecast ===\n\n";

try {
    $stmt = $pdo->query("DESCRIBE campaign_staffing_forecast");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $col) {
        if ($col['Field'] === 'campaign_id') {
            echo "✨ campaign_id:\n";
            echo "   Type: " . $col['Type'] . "\n";
            echo "   Null: " . $col['Null'] . "\n";
            echo "   Key: " . $col['Key'] . "\n";
            echo "   Default: " . ($col['Default'] ?? 'NULL') . "\n";
            echo "   Extra: " . $col['Extra'] . "\n\n";
        }
    }

    echo "\n=== ÍNDICES ===\n\n";

    $stmt = $pdo->query("SHOW INDEX FROM campaign_staffing_forecast");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($indexes as $idx) {
        $grouped[$idx['Key_name']][] = $idx;
    }

    foreach ($grouped as $keyName => $cols) {
        echo "🔑 $keyName:\n";
        echo "   Unique: " . ($cols[0]['Non_unique'] == 0 ? 'YES' : 'NO') . "\n";
        echo "   Columns: " . implode(', ', array_column($cols, 'Column_name')) . "\n\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
