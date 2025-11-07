<?php
/**
 * Compare Password Reset vs Absence Report
 * Send both emails and compare
 */

require_once 'vendor/autoload.php';
require_once 'db.php';
require_once 'lib/email_functions.php';
require_once 'lib/daily_absence_report.php';

echo "=== COMPARISON TEST: Password Reset vs Absence Report ===\n\n";

$testEmail = 'jonathansandovalferreira@gmail.com';

// TEST 1: Send Password Reset Email (THIS WORKS)
echo "TEST 1: Sending Password Reset Email\n";
echo "=====================================\n";

$passwordResetData = [
    'email' => $testEmail,
    'full_name' => 'Jonathan Sandoval',
    'reset_token' => 'test_token_' . time()
];

$result1 = sendPasswordResetEmail($passwordResetData);

if ($result1['success']) {
    echo "✅ Password Reset Email: SUCCESS\n";
    echo "   Message: {$result1['message']}\n";
} else {
    echo "❌ Password Reset Email: FAILED\n";
    echo "   Error: {$result1['message']}\n";
}

echo "\n";
sleep(2); // Wait between emails

// TEST 2: Send Absence Report (TESTING)
echo "TEST 2: Sending Absence Report Email\n";
echo "=====================================\n";

$reportData = generateDailyAbsenceReport($pdo);
$html = generateReportHTML($reportData);

$result2 = sendDailyAbsenceReport($html, [$testEmail], $reportData);

if ($result2['success']) {
    echo "✅ Absence Report Email: SUCCESS\n";
    echo "   Message: {$result2['message']}\n";
} else {
    echo "❌ Absence Report Email: FAILED\n";
    echo "   Error: {$result2['message']}\n";
}

echo "\n";
echo "===========================================\n";
echo "RESULTS:\n";
echo "===========================================\n";
echo "Password Reset: " . ($result1['success'] ? '✅ SENT' : '❌ FAILED') . "\n";
echo "Absence Report: " . ($result2['success'] ? '✅ SENT' : '❌ FAILED') . "\n";
echo "\n";

if ($result1['success'] && $result2['success']) {
    echo "✅ BOTH emails sent successfully!\n";
    echo "\nCheck your email inbox: $testEmail\n";
    echo "You should receive 2 emails:\n";
    echo "  1. Password Reset\n";
    echo "  2. Absence Report\n";
    echo "\n(Also check spam folder)\n";
} else {
    echo "⚠️ At least one email failed\n";
}

echo "\n=== TEST COMPLETED ===\n";
