<?php
/**
 * Test PHPMailer Direct Connection
 * This will show detailed SMTP debug information
 */

require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$config = require 'config/email_config.php';

$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->SMTPDebug = 2; // Enable verbose debug output
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
    $mail->addAddress('jonathansandovalferreira@gmail.com', 'Jonathan'); // Test recipient
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = '🧪 Test Email - ' . date('Y-m-d H:i:s');
    $mail->Body = '<h1>Test Email</h1><p>This is a test email from Ponche Xtreme system.</p><p><strong>If you receive this, SMTP is working correctly!</strong></p>';
    $mail->AltBody = 'Test Email - This is a test email from Ponche Xtreme system. If you receive this, SMTP is working correctly!';
    
    echo "<pre>\n";
    echo "=== PHPMailer Configuration ===\n";
    echo "SMTP Host: " . $config['smtp_host'] . "\n";
    echo "SMTP Port: " . $config['smtp_port'] . "\n";
    echo "SMTP Secure: " . $config['smtp_secure'] . "\n";
    echo "SMTP Username: " . $config['smtp_username'] . "\n";
    echo "From Email: " . $config['from_email'] . "\n";
    echo "\n=== Sending Email ===\n\n";
    
    $mail->send();
    
    echo "\n\n=== RESULT ===\n";
    echo "✅ SUCCESS! Email sent successfully!\n";
    echo "Check jonathansandovalferreira@gmail.com inbox (and spam folder)\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "\n\n=== RESULT ===\n";
    echo "<pre>";
    echo "❌ ERROR: Email could not be sent.\n";
    echo "Mailer Error: {$mail->ErrorInfo}\n";
    echo "Exception: {$e->getMessage()}\n";
    echo "</pre>";
}
