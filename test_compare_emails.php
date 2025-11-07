<?php
/**
 * Send BOTH emails side by side and compare headers
 */

require_once 'vendor/autoload.php';
require_once 'db.php';
require_once 'lib/email_functions.php';
require_once 'lib/daily_absence_report.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$testEmail = 'jonathansandovalferreira@gmail.com';
$config = require 'config/email_config.php';

echo "=== COMPARING EMAIL HEADERS ===\n\n";

// TEST 1: Password Reset (WORKS)
echo "1. PASSWORD RESET EMAIL (Este SÍ llega)\n";
echo "==========================================\n";

$mail1 = new PHPMailer(true);
$mail1->SMTPDebug = 0;
$mail1->isSMTP();
$mail1->Host = $config['smtp_host'];
$mail1->SMTPAuth = true;
$mail1->Username = $config['smtp_username'];
$mail1->Password = $config['smtp_password'];
$mail1->SMTPSecure = $config['smtp_secure'];
$mail1->Port = $config['smtp_port'];
$mail1->CharSet = $config['charset'];

$mail1->setFrom($config['from_email'], $config['from_name']);
$mail1->addAddress($testEmail);

$mail1->isHTML(true);
$mail1->Subject = 'Recuperación de Contraseña - Test';
$mail1->Body = '<h1>Test Password Reset</h1><p>This email WORKS</p>';
$mail1->AltBody = 'Test Password Reset';

$mail1->preSend();
echo "Subject: " . $mail1->Subject . "\n";
echo "From: " . $config['from_email'] . "\n";
echo "To: " . $testEmail . "\n";
echo "Content-Type: " . ($mail1->ContentType ?? 'text/html') . "\n";
echo "CharSet: " . $mail1->CharSet . "\n";
echo "Encoding: " . $mail1->Encoding . "\n\n";

// TEST 2: Absence Report (NO LLEGA)
echo "2. ABSENCE REPORT EMAIL (Este NO llega)\n";
echo "==========================================\n";

$reportData = generateDailyAbsenceReport($pdo);
$html = generateReportHTML($reportData);

$mail2 = new PHPMailer(true);
$mail2->SMTPDebug = 0;
$mail2->isSMTP();
$mail2->Host = $config['smtp_host'];
$mail2->SMTPAuth = true;
$mail2->Username = $config['smtp_username'];
$mail2->Password = $config['smtp_password'];
$mail2->SMTPSecure = $config['smtp_secure'];
$mail2->Port = $config['smtp_port'];
$mail2->CharSet = $config['charset'];

$mail2->setFrom($config['from_email'], $config['from_name']);
$mail2->addAddress($testEmail);

$dateFormatted = date('l, F j, Y');
$mail2->isHTML(true);
$mail2->Subject = "📊 Reporte Diario de Ausencias - $dateFormatted";
$mail2->Body = $html;
$mail2->AltBody = "Reporte de ausencias";

$mail2->preSend();
echo "Subject: " . $mail2->Subject . "\n";
echo "From: " . $config['from_email'] . "\n";
echo "To: " . $testEmail . "\n";
echo "Content-Type: " . ($mail2->ContentType ?? 'text/html') . "\n";
echo "CharSet: " . $mail2->CharSet . "\n";
echo "Encoding: " . $mail2->Encoding . "\n";
echo "Body Length: " . strlen($html) . " bytes\n";
echo "Has Emoji in Subject: " . (strpos($mail2->Subject, '📊') !== false ? 'YES' : 'NO') . "\n\n";

echo "==========================================\n";
echo "DIFFERENCES FOUND:\n";
echo "==========================================\n";

$differences = [];

if ($mail1->Subject !== $mail2->Subject) {
    echo "✓ Subjects are different\n";
    if (strpos($mail2->Subject, '📊') !== false) {
        echo "  ⚠️ Absence report has EMOJI in subject (puede causar problemas)\n";
        $differences[] = 'emoji_in_subject';
    }
}

if (strlen($html) > 10000) {
    echo "✓ Absence report HTML is LARGE (" . strlen($html) . " bytes)\n";
    echo "  ⚠️ Gmail puede rechazar emails muy grandes\n";
    $differences[] = 'large_body';
}

// Check for problematic content
if (preg_match('/style=/i', $html)) {
    echo "✓ Absence report has inline STYLES\n";
    echo "  ⚠️ Algunos filtros bloquean inline styles\n";
    $differences[] = 'inline_styles';
}

if (empty($differences)) {
    echo "No obvious differences found\n";
}

echo "\n==========================================\n";
echo "SOLUTION: Testing without problematic elements\n";
echo "==========================================\n\n";

// Send simplified version
echo "Sending SIMPLIFIED absence report (without emoji, minimal HTML)...\n";

$mail3 = new PHPMailer(true);
$mail3->SMTPDebug = 0;
$mail3->isSMTP();
$mail3->Host = $config['smtp_host'];
$mail3->SMTPAuth = true;
$mail3->Username = $config['smtp_username'];
$mail3->Password = $config['smtp_password'];
$mail3->SMTPSecure = $config['smtp_secure'];
$mail3->Port = $config['smtp_port'];
$mail3->CharSet = $config['charset'];

$mail3->setFrom($config['from_email'], $config['from_name']);
$mail3->addAddress($testEmail);

$mail3->isHTML(true);
$mail3->Subject = "Reporte Diario de Ausencias - $dateFormatted"; // SIN EMOJI
$mail3->Body = "<h1>Reporte Diario de Ausencias</h1><p>Test simplificado</p><p>Fecha: $dateFormatted</p><p>Total Empleados: {$reportData['total_employees']}</p><p>Ausencias: {$reportData['total_absences']}</p>";
$mail3->AltBody = "Reporte de Ausencias - Test";

try {
    $mail3->send();
    echo "✅ Simplified email sent\n";
    echo "\nCheck your Gmail for:\n";
    echo "  1. Password Reset email (should arrive)\n";
    echo "  2. Simplified Absence Report (testing if this arrives)\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
