<?php
/**
 * Email Configuration for cPanel SMTP
 * 
 * Configure your cPanel email settings here.
 * Update these values with your actual cPanel email credentials.
 */

$__sec = @include __DIR__ . '/secrets.php';
if (!is_array($__sec)) { $__sec = []; }

return [
    // SMTP Server Settings
    'smtp_host' => 'mail.evallishbpo.com', // Your cPanel mail server
    'smtp_port' => 465, // 465 for SSL, 587 for TLS
    'smtp_secure' => 'ssl', // 'ssl' or 'tls'
    
    // Email Account Credentials
    'smtp_username' => 'notificaciones@evallishbpo.com', // Your cPanel email address
    'smtp_password' => getenv('SMTP_PASS') ?: ($__sec['smtp']['pass'] ?? ''), // externalizado → config/secrets.php
    
    // Sender Information
    'from_email' => 'notificaciones@evallishbpo.com',
    'from_name' => 'Evallish BPO Control - Sistema de RH',
    
    // Reply-To (optional)
    'reply_to_email' => 'notificaciones@evallishbpo.com',
    'reply_to_name' => 'Recursos Humanos - Evallish BPO',
    
    // Email Settings
    'charset' => 'UTF-8',
    'debug_mode' => false, // Set to true for debugging (shows detailed SMTP logs)
    
    // Application Settings
    'app_name' => 'Evallish BPO Control',
    'app_url' => 'https://punch.evallishbpo.com', // URL base sin slash final
    'support_email' => 'notificaciones@evallishbpo.com',
];
