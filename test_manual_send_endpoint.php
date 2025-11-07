<?php
/**
 * Test Manual Send Endpoint
 * Simula el envío manual desde la interfaz web
 */

// Simulate session
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['full_name'] = 'Test Admin';
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'administrator';

echo "=== Test Manual Send Endpoint ===\n\n";
echo "Session User: " . $_SESSION['full_name'] . " (" . $_SESSION['role'] . ")\n\n";

// Capture output
ob_start();
require 'send_absence_report.php';
$output = ob_get_clean();

echo "Response:\n";
echo $output;
echo "\n\n";

// Try to decode JSON
$result = json_decode($output, true);
if ($result) {
    echo "Parsed Response:\n";
    print_r($result);
} else {
    echo "Failed to parse JSON. Raw output above.\n";
}
