<?php
require_once '../db.php';

try {
    // Add position column if it doesn't exist
    $pdo->exec("ALTER TABLE employment_contracts ADD COLUMN position VARCHAR(255) DEFAULT 'Representante de Servicios' AFTER province");
    echo "✓ Column 'position' added successfully to employment_contracts table\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "✓ Column 'position' already exists\n";
    } else {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
}
