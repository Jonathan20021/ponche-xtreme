<?php
/**
 * Test Daily Absence Report Email Sending
 * This will show detailed debug information
 */

require_once 'db.php';
require_once 'lib/daily_absence_report.php';
require_once 'lib/email_functions.php';

echo "<pre>\n";
echo "=== TEST: Daily Absence Report Email ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Get test email from settings
$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'absence_report_recipients'");
$recipients = $stmt->fetchColumn();

echo "1. Recipients configured: $recipients\n";

if (empty($recipients)) {
    die("❌ ERROR: No recipients configured. Run configure_test_email.php first.\n");
}

$recipientsArray = array_map('trim', explode(',', $recipients));
echo "   Recipients array: " . print_r($recipientsArray, true) . "\n";

// Generate report
echo "\n2. Generating report...\n";
$reportData = generateDailyAbsenceReport($pdo);

echo "   Total employees: " . $reportData['total_employees'] . "\n";
echo "   Total absences: " . $reportData['total_absences'] . "\n";
echo "   Without justification: " . count($reportData['absences_without_justification']) . "\n";
echo "   With justification: " . count($reportData['absences_with_justification']) . "\n";

// Generate HTML
echo "\n3. Generating HTML...\n";
$html = generateReportHTML($reportData);
echo "   HTML size: " . strlen($html) . " bytes\n";

// Send email
echo "\n4. Sending email...\n";
echo "   Debug mode is enabled in config\n\n";
echo "--- SMTP DEBUG OUTPUT START ---\n\n";

ob_start();
$result = sendDailyAbsenceReport($html, $recipientsArray, $reportData);
$debugOutput = ob_get_clean();

echo $debugOutput;
echo "\n--- SMTP DEBUG OUTPUT END ---\n\n";

// Show result
echo "\n5. Result:\n";
if ($result['success']) {
    echo "   ✅ SUCCESS: " . $result['message'] . "\n";
    echo "   Check your email inbox: " . implode(', ', $recipientsArray) . "\n";
    echo "   (Also check spam folder)\n";
} else {
    echo "   ❌ ERROR: " . $result['message'] . "\n";
}

echo "\n=== TEST COMPLETED ===\n";
echo "</pre>";
