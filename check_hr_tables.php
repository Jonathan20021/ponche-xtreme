<?php
require_once 'db.php';

// Check for tables with 'request', 'permission', 'leave', 'vacation'
$patterns = ['%request%', '%permission%', '%leave%', '%vacation%'];

foreach ($patterns as $pattern) {
    echo "=== Tables matching: $pattern ===\n";
    $stmt = $pdo->query("SHOW TABLES LIKE '$pattern'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "No tables found.\n\n";
    } else {
        foreach ($tables as $table) {
            echo "- $table\n";
            
            // Show structure
            $descStmt = $pdo->query("DESCRIBE $table");
            $columns = $descStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $col) {
                echo "  • {$col['Field']} ({$col['Type']})\n";
            }
            echo "\n";
        }
    }
}
