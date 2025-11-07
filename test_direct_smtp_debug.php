<?php
/**
 * Direct Email Send Test with SMTP Debug Output
 */

require_once 'vendor/autoload.php';
require_once 'db.php';
require_once 'lib/daily_absence_report.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

echo "=== DIRECT EMAIL SEND WITH SMTP DEBUG ===\n\n";

// Get config
$config = require 'config/email_config.php';

// Get recipients
$recipients = getReportRecipients($pdo);
echo "Recipients: " . implode(', ', $recipients) . "\n\n";

if (empty($recipients)) {
    die("No recipients!\n");
}

// Generate report
$reportData = generateDailyAbsenceReport($pdo);
$html = generateReportHTML($reportData);

echo "Sending email to: " . $recipients[0] . "\n";
echo "SMTP Server: {$config['smtp_host']}:{$config['smtp_port']}\n";
echo "From: {$config['from_email']}\n\n";

try {
    $mail = new PHPMailer(true);
    
    // Enable verbose debug output
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function($str, $level) {
        echo "DEBUG [$level]: $str\n";
    };
    
    // Server settings
    $mail->isSMTP();
    $mail->Host = $config['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['smtp_username'];
    $mail->Password = $config['smtp_password'];
    $mail->SMTPSecure = $config['smtp_secure'];
    $mail->Port = $config['smtp_port'];
    $mail->CharSet = $config['charset'];
    
    // Recipients
    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($recipients[0]);
    
    // Content
    $mail->isHTML(true);
    $dateFormatted = $reportData['date_formatted'] ?? date('l, F j, Y');
    $mail->Subject = "📊 Reporte Diario de Ausencias - $dateFormatted";
    $mail->Body = $html;
    $mail->AltBody = "Reporte de ausencias - Ver versión HTML";
    
    echo "\nAttempting to send...\n";
    echo "==================\n\n";
    
    $result = $mail->send();
    
    echo "\n==================\n";
    if ($result) {
        echo "✅ EMAIL SENT SUCCESSFULLY!\n";
        echo "\nCheck your inbox: {$recipients[0]}\n";
        echo "(Also check spam folder)\n";
    } else {
        echo "❌ EMAIL FAILED\n";
    }
    
} catch (Exception $e) {
    echo "\n❌ EXCEPTION CAUGHT:\n";
    echo "Message: {$mail->ErrorInfo}\n";
    echo "Exception: {$e->getMessage()}\n";
}

echo "\n=== TEST COMPLETED ===\n";
