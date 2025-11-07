<?php
/**
 * Test the actual AJAX endpoint
 * Simulates browser request to send_absence_report.php
 */

// Simulate authenticated session
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['full_name'] = 'Test User';
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'administrator';

echo "=== TEST AJAX ENDPOINT (send_absence_report.php) ===\n\n";
echo "Session Info:\n";
echo "  User ID: {$_SESSION['user_id']}\n";
echo "  Username: {$_SESSION['username']}\n";
echo "  Role: {$_SESSION['role']}\n\n";

// Set up environment like AJAX request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';

echo "Making request to send_absence_report.php...\n";
echo "==========================================\n\n";

// Capture the output
ob_start();
include 'send_absence_report.php';
$response = ob_get_clean();

echo "Response received:\n";
echo $response . "\n\n";

// Parse JSON
$json = json_decode($response, true);

if ($json) {
    echo "==========================================\n";
    echo "PARSED RESPONSE:\n";
    echo "==========================================\n";
    echo "Success: " . ($json['success'] ? 'YES' : 'NO') . "\n";
    
    if ($json['success']) {
        echo "Message: {$json['message']}\n";
        if (isset($json['data'])) {
            echo "\nData:\n";
            echo "  Recipients: {$json['data']['recipients_count']}\n";
            echo "  Total Employees: {$json['data']['total_employees']}\n";
            echo "  Total Absences: {$json['data']['total_absences']}\n";
            echo "  Without Justification: {$json['data']['absences_without_justification']}\n";
            echo "  With Justification: {$json['data']['absences_with_justification']}\n";
        }
        
        echo "\n✅ EMAIL SENT SUCCESSFULLY FROM ENDPOINT!\n";
        echo "\nThis is exactly what the UI calls when you click 'Enviar Reporte Ahora'\n";
        echo "\nCheck your email: jonathansandovalferreira@gmail.com\n";
        echo "(Also check spam folder)\n";
    } else {
        echo "Error: {$json['error']}\n";
        echo "\n❌ ENDPOINT RETURNED ERROR\n";
    }
} else {
    echo "❌ Failed to parse JSON response\n";
    echo "Raw output:\n$response\n";
}

echo "\n=== TEST COMPLETED ===\n";
