<?php
// Test script for Real-Time Monitor API
session_start();

// Simulate HR user login
$_SESSION['user_id'] = 1; // Assuming ID 1 is admin/HR
$_SESSION['role'] = 'ADMIN'; // Admin usually has all permissions

require_once 'db.php';

// Mock permission check function if not included via db.php
if (!function_exists('userHasPermission')) {
    function userHasPermission($section, $role = null) {
        return true; // Bypass for testing
    }
}

// Include the API logic (we'll just include the file, but capture output)
ob_start();
include 'hr/realtime_monitor_api.php';
$output = ob_get_clean();

// Decode and print for inspection
$data = json_decode($output, true);

echo "API Response Status: " . ($data['success'] ? 'SUCCESS' : 'FAILURE') . "\n";
echo "Timestamp: " . $data['timestamp'] . "\n";
echo "Total Employees: " . $data['summary']['total_employees'] . "\n";
echo "Active Now: " . $data['summary']['active_now'] . "\n";
echo "Total Earnings USD: " . $data['summary']['total_earnings_usd_formatted'] . "\n";

echo "\n--- Employee Sample (First 2) ---\n";
if (!empty($data['employees'])) {
    foreach (array_slice($data['employees'], 0, 2) as $emp) {
        echo "Name: " . $emp['full_name'] . "\n";
        echo "Status: " . $emp['status'] . " (" . $emp['status_label'] . ")\n";
        echo "Current Punch: " . $emp['current_punch']['type'] . "\n";
        echo "Hours Today: " . $emp['hours_formatted'] . "\n";
        echo "Earnings: " . $emp['earnings_formatted'] . "\n";
        echo "------------------------\n";
    }
} else {
    echo "No employees found.\n";
}
?>
