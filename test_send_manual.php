<?php
// Test send_absence_report.php directly
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'Administrator';
$_SESSION['full_name'] = 'Test Admin';
$_SESSION['username'] = 'admin';

// Simulate POST request
$_SERVER['REQUEST_METHOD'] = 'POST';

// Capture output
ob_start();
require 'send_absence_report.php';
$output = ob_get_clean();

echo "=== Output ===\n";
echo $output;
echo "\n\n";

// Decode JSON
$result = json_decode($output, true);
if ($result) {
    echo "=== Parsed Result ===\n";
    print_r($result);
} else {
    echo "=== JSON Parse Error ===\n";
    echo json_last_error_msg();
}
