<?php
/**
 * Comprehensive Email Send Test
 * Tests the complete flow with full error details
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';
require_once 'lib/daily_absence_report.php';

echo "=== COMPREHENSIVE EMAIL SEND TEST ===\n\n";

// Step 1: Get recipients
echo "1. Getting recipients from database...\n";
$recipients = getReportRecipients($pdo);
echo "   Recipients: " . implode(', ', $recipients) . "\n";
echo "   Count: " . count($recipients) . "\n\n";

if (empty($recipients)) {
    die("ERROR: No recipients configured!\n");
}

// Step 2: Generate report
echo "2. Generating absence report...\n";
$reportData = generateDailyAbsenceReport($pdo);
echo "   Total employees: " . $reportData['total_employees'] . "\n";
echo "   Total absences: " . $reportData['total_absences'] . "\n";
echo "   Without justification: " . count($reportData['absences_without_justification']) . "\n";
echo "   With justification: " . count($reportData['absences_with_justification']) . "\n\n";

// Step 3: Generate HTML
echo "3. Generating HTML email...\n";
$html = generateReportHTML($reportData);
echo "   HTML size: " . strlen($html) . " bytes\n\n";

// Step 4: Send email with detailed error capture
echo "4. Sending email via sendReportByEmail()...\n";
echo "   (This will show any errors from PHPMailer)\n\n";

// Capture all output including errors
ob_start();
$sent = sendReportByEmail($pdo, $reportData, $recipients);
$emailOutput = ob_get_clean();

if (!empty($emailOutput)) {
    echo "   Email function output:\n";
    echo "   " . str_replace("\n", "\n   ", $emailOutput) . "\n\n";
}

echo "5. Result: ";
if ($sent) {
    echo "✅ SUCCESS - Email sent\n";
    echo "   sendReportByEmail() returned TRUE\n";
} else {
    echo "❌ FAILED - Email not sent\n";
    echo "   sendReportByEmail() returned FALSE\n";
}

echo "\n";
echo "6. Checking PHP error log for PHPMailer errors...\n";
$errorLog = error_get_last();
if ($errorLog) {
    echo "   Last PHP error:\n";
    print_r($errorLog);
} else {
    echo "   No PHP errors found\n";
}

echo "\n=== TEST COMPLETED ===\n";
