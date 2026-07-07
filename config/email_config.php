<?php
/**
 * Configuración de correo (SMTP cPanel).
 *
 * SEGURIDAD: las credenciales SENSIBLES (usuario y contraseña SMTP) ya NO viven
 * en este archivo — se leen de la BASE DE DATOS COMPARTIDA (system_settings), así
 * NO quedan en git ni se exponen en el repo. Como la oficina y HostGator comparten
 * la misma base, ambos servidores usan las mismas credenciales sin configurar nada.
 *
 * Para rotar la contraseña: cámbiala en cPanel y actualiza el valor en
 * Reportes de Soporte → "Correo saliente (SMTP)" (o en system_settings.smtp_password).
 *
 * Los valores NO sensibles (host, puerto, remitente, URL) quedan como predeterminados
 * y pueden sobreescribirse también desde system_settings si algún día hiciera falta.
 */

$__smtpUser = '';
$__smtpPass = '';
try {
    require_once __DIR__ . '/../db.php';
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        $__smtpUser = getSystemSetting($pdo, 'smtp_username', '');
        $__smtpPass = getSystemSetting($pdo, 'smtp_password', '');
    }
} catch (Throwable $e) {
    // Sin DB: se queda vacío (el envío simplemente no autenticará).
}

return [
    // SMTP Server Settings
    'smtp_host'   => 'mail.evallishbpo.com',
    'smtp_port'   => 465,       // 465 SSL, 587 TLS
    'smtp_secure' => 'ssl',     // 'ssl' o 'tls'

    // Credenciales (desde system_settings; NO se commitean)
    'smtp_username' => $__smtpUser !== '' ? $__smtpUser : 'notificaciones@evallishbpo.com',
    'smtp_password' => $__smtpPass,

    // Remitente
    'from_email'  => 'notificaciones@evallishbpo.com',
    'from_name'   => 'Evallish BPO Control - Sistema de RH',

    // Reply-To
    'reply_to_email' => 'notificaciones@evallishbpo.com',
    'reply_to_name'  => 'Recursos Humanos - Evallish BPO',

    // Ajustes
    'charset'     => 'UTF-8',
    'debug_mode'  => false,

    // Aplicación
    'app_name'    => 'Evallish BPO Control',
    'app_url'     => 'https://punch.evallishbpo.com', // URL base sin slash final
    'support_email' => 'notificaciones@evallishbpo.com',
];
