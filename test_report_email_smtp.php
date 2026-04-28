<?php
/**
 * SMTP Diagnostic for Wasapi & GHL Reports
 *
 * Sends a tiny test email with full SMTP debug to identify why emails may
 * not be arriving. Use:
 *
 *   /test_report_email_smtp.php?to=tu-correo@dominio.com
 *
 * Requires admin/IT role or 'settings' permission.
 */

session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: text/plain; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "ERROR: No autenticado. Inicia sesión primero.\n";
    exit;
}

$role = strtoupper(trim((string) ($_SESSION['role'] ?? '')));
$allowedRoles = ['ADMIN', 'ADMINISTRATOR', 'DESARROLLADOR', 'IT'];
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    echo "ERROR: Requiere rol ADMIN/IT. Tu rol: $role\n";
    exit;
}

$to = trim((string) ($_GET['to'] ?? ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "Uso: ?to=correo@dominio.com\n";
    echo "Ejemplo: /test_report_email_smtp.php?to=jonathansandoval@kyrosrd.com\n";
    exit;
}

$config = require __DIR__ . '/config/email_config.php';

echo "=== Configuración SMTP ===\n";
echo "Host:      {$config['smtp_host']}\n";
echo "Port:      {$config['smtp_port']}\n";
echo "Secure:    {$config['smtp_secure']}\n";
echo "Username:  {$config['smtp_username']}\n";
echo "From:      {$config['from_email']}\n";
echo "Reply-To:  " . ($config['reply_to_email'] ?? '(sin definir)') . "\n";
echo "To:        $to\n\n";

echo "=== Probando envío con SMTP debug nivel 2 ===\n\n";

try {
    $mail = new PHPMailer(true);
    $mail->SMTPDebug = 2;                                  // verbose SMTP conversation
    $mail->Debugoutput = function ($str, $level) {
        echo trim($str) . "\n";
    };
    $mail->isSMTP();
    $mail->Host       = $config['smtp_host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['smtp_username'];
    $mail->Password   = $config['smtp_password'];
    $mail->SMTPSecure = $config['smtp_secure'];
    $mail->Port       = $config['smtp_port'];
    $mail->CharSet    = $config['charset'] ?? 'UTF-8';

    $mail->setFrom($config['from_email'], $config['from_name']);
    if (!empty($config['reply_to_email'])) {
        $mail->addReplyTo($config['reply_to_email'], $config['reply_to_name'] ?? '');
    }
    $mail->addAddress($to);

    $mail->isHTML(true);
    $mail->Subject = 'Test de diagnóstico - Reportes Wasapi/GHL - ' . date('Y-m-d H:i:s');
    $mail->Body    = '<h2>Prueba de envío SMTP</h2>'
        . '<p>Este es un correo de diagnóstico de Ponche Xtreme.</p>'
        . '<p>Si recibes este correo, el envío funciona correctamente. '
        . 'Si los reportes Wasapi/GHL no llegan: revisa carpeta de spam.</p>'
        . '<p>Hora: ' . date('Y-m-d H:i:s') . '</p>';
    $mail->AltBody = "Prueba de envío SMTP - " . date('Y-m-d H:i:s');

    $sent = $mail->send();

    echo "\n=== RESULTADO ===\n";
    echo $sent ? "✅ Correo enviado al SMTP exitosamente.\n" : "❌ El SMTP rechazó el envío.\n";
    echo "Revisa la bandeja de entrada y la carpeta de spam de: $to\n";

} catch (Exception $e) {
    echo "\n=== ERROR ===\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Esto indica un problema real con el SMTP (autenticación, conexión, etc).\n";
}
