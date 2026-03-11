<?php
require_once __DIR__ . '/db.php';

echo "=== FOREIGN KEYS: vicidial_inbound_hourly ===\n\n";

try {
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = 'hhempeos_ponche'
          AND TABLE_NAME = 'vicidial_inbound_hourly'
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($fks)) {
        echo "No foreign keys found.\n";
    } else {
        foreach ($fks as $fk) {
            echo "FK: {$fk['CONSTRAINT_NAME']}\n";
            echo "   Column: {$fk['COLUMN_NAME']}\n";
            echo "   References: {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}\n\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== FOREIGN KEYS: campaign_staffing_forecast ===\n\n";

try {
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = 'hhempeos_ponche'
          AND TABLE_NAME = 'campaign_staffing_forecast'
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($fks)) {
        echo "No foreign keys found.\n";
    } else {
        foreach ($fks as $fk) {
            echo "FK: {$fk['CONSTRAINT_NAME']}\n";
            echo "   Column: {$fk['COLUMN_NAME']}\n";
            echo "   References: {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}\n\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
