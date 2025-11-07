<?php
/**
 * Test Script for Daily Absence Report
 * Generates a test report without sending emails
 */

require_once 'db.php';
require_once 'lib/daily_absence_report.php';

echo "=== Testing Daily Absence Report System ===\n\n";

try {
    echo "1. Generating report data...\n";
    $reportData = generateDailyAbsenceReport($pdo);
    
    echo "✓ Report generated successfully!\n\n";
    
    echo "--- Report Summary ---\n";
    echo "Date: {$reportData['date_formatted']}\n";
    echo "Total Employees: {$reportData['total_employees']}\n";
    echo "Total Absences: {$reportData['total_absences']}\n";
    echo "Without Justification: " . count($reportData['absences_without_justification']) . "\n";
    echo "With Justification: " . count($reportData['absences_with_justification']) . "\n";
    echo "\n";
    
    // Show absences without justification
    if (!empty($reportData['absences_without_justification'])) {
        echo "--- Absences WITHOUT Justification ---\n";
        foreach ($reportData['absences_without_justification'] as $emp) {
            echo "  • {$emp['full_name']} ({$emp['employee_code']}) - {$emp['position']} - {$emp['department']}\n";
        }
        echo "\n";
    }
    
    // Show absences with justification
    if (!empty($reportData['absences_with_justification'])) {
        echo "--- Absences WITH Justification ---\n";
        foreach ($reportData['absences_with_justification'] as $emp) {
            echo "  • {$emp['full_name']} ({$emp['employee_code']}) - {$emp['position']}\n";
            
            if (!empty($emp['permissions'])) {
                foreach ($emp['permissions'] as $perm) {
                    echo "    - Permission: {$perm['request_type']} ({$perm['start_date']} to {$perm['end_date']})\n";
                }
            }
            
            if (!empty($emp['vacations'])) {
                foreach ($emp['vacations'] as $vac) {
                    $type = $vac['vacation_type'] ?? 'regular';
                    echo "    - Vacation: $type ({$vac['start_date']} to {$vac['end_date']})\n";
                }
            }
            
            if (!empty($emp['medical_leaves'])) {
                foreach ($emp['medical_leaves'] as $leave) {
                    echo "    - Medical Leave: {$leave['leave_type']} ({$leave['start_date']} to {$leave['end_date']})\n";
                    if (!empty($leave['diagnosis'])) {
                        echo "      Diagnosis: {$leave['diagnosis']}\n";
                    }
                }
            }
        }
        echo "\n";
    }
    
    // Generate HTML
    echo "2. Generating HTML email...\n";
    $html = generateReportHTML($reportData);
    echo "✓ HTML generated (" . strlen($html) . " bytes)\n\n";
    
    // Save HTML to file for preview
    $filename = 'test_absence_report_' . date('Y-m-d_His') . '.html';
    file_put_contents($filename, $html);
    echo "✓ HTML saved to: $filename\n";
    echo "  Open this file in a browser to preview the email\n\n";
    
    // Check recipients configuration
    echo "3. Checking email configuration...\n";
    $recipients = getReportRecipients($pdo);
    
    if (empty($recipients)) {
        echo "⚠ WARNING: No recipients configured!\n";
        echo "  Configure recipients in Settings > Reporte de Ausencias\n";
    } else {
        echo "✓ Recipients configured: " . count($recipients) . "\n";
        foreach ($recipients as $email) {
            echo "  - $email\n";
        }
    }
    echo "\n";
    
    // Check if enabled
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'absence_report_enabled'");
    $enabled = $stmt->fetchColumn();
    
    echo "4. Checking system status...\n";
    echo "  Report System: " . ($enabled === '1' ? '✓ Enabled' : '⚠ Disabled') . "\n";
    
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'absence_report_time'");
    $time = $stmt->fetchColumn();
    echo "  Scheduled Time: $time GMT-4\n\n";
    
    echo "=== Test Completed Successfully! ===\n";
    echo "\nNext steps:\n";
    echo "1. Review the generated HTML file\n";
    echo "2. Configure recipients in Settings if not done yet\n";
    echo "3. Test manual send from Settings page\n";
    echo "4. Set up cron job: 0 8 * * * /usr/bin/php " . __DIR__ . "/cron_daily_absence_report.php\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
