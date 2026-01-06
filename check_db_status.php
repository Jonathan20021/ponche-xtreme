<?php
// Suppress session errors for CLI
error_reporting(E_ALL & ~E_NOTICE);
define('CLI_MODE', true);

// Mock session for db.php if needed
if (session_status() === PHP_SESSION_NONE) {
    // session_start(); // Skip session start for CLI check to avoid header errors
}

require_once 'db.php';

try {
    // Check Max Connections
    $stmt = $pdo->query("SHOW VARIABLES LIKE 'max_connections'");
    $max = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check Current Connections
    $stmt = $pdo->query("SHOW STATUS LIKE 'Threads_connected'");
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check Connection Errors
    $stmt = $pdo->query("SHOW STATUS LIKE 'Connection_errors_%'");
    $errors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "=== MySQL Status ===\n";
    echo "Max Connections Limit: " . $max['Value'] . "\n";
    echo "Active Connections Now: " . $current['Value'] . "\n";
    echo "-------------------\n";
    echo "Connection Errors:\n";
    foreach ($errors as $error) {
        if ($error['Value'] > 0) {
            echo $error['Variable_name'] . ": " . $error['Value'] . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
