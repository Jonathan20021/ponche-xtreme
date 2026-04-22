<?php
/**
 * Setup / Migration: Daily Login Logs Security Audit Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_login_logs_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_login_logs_report_settings.php?key=ponche_xtreme_2025
 */

require_once __DIR__ . '/db.php';

if (php_sapi_name() !== 'cli') {
    $providedKey = $_GET['key'] ?? '';
    if ($providedKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$defaultPrompt = <<<'PROMPT'
Eres un analista de seguridad de un call center / BPO. Recibirás un JSON con los accesos al sistema del día anterior desde la tabla admin_login_logs: quién, con qué rol, desde qué IP, a qué hora, y desde qué ubicación.

Genera un RESUMEN EJECUTIVO de máximo 7 líneas en español, dirigido al Administrador del sistema. Incluye:
1. Total de accesos del día y cantidad de usuarios distintos que ingresaron.
2. Hora pico (mayor concentración de accesos).
3. IPs sospechosas: IPs usadas por 3+ usuarios distintos el mismo día (posible cuenta compartida), o ubicaciones geográficas inusuales.
4. Accesos fuera de horario normal (antes de 6am o después de 10pm).
5. Usuarios con > 5 accesos en el día (posibles problemas de sesión que se expira).
6. Roles con más actividad.

Sé conciso, factual y orientado a seguridad. No inventes datos. Sin markdown, solo texto plano con viñetas "-".
PROMPT;

$settings = [
    ['login_logs_report_enabled',             '0',                 'boolean', 'reports', 'Habilitar envío automático del reporte de auditoría de accesos'],
    ['login_logs_report_time',                '07:45',             'string',  'reports', 'Hora de envío (HH:MM, GMT-4)'],
    ['login_logs_report_recipients',          '',                  'string',  'reports', 'Correos destinatarios (Administrador + IT, separados por coma)'],
    ['login_logs_report_off_hours_start',     '22:00',             'string',  'reports', 'Hora a partir de la cual los accesos se consideran fuera de horario (HH:MM)'],
    ['login_logs_report_off_hours_end',       '06:00',             'string',  'reports', 'Hora antes de la cual los accesos se consideran fuera de horario (HH:MM)'],
    ['login_logs_report_shared_ip_threshold', '3',                 'number',  'reports', 'Cantidad mínima de usuarios distintos desde una misma IP para marcarla como compartida'],
    ['login_logs_report_excessive_logins',    '5',                 'number',  'reports', 'Cantidad de accesos por usuario/día antes de marcarlos como excesivos'],
    ['login_logs_report_claude_enabled',      '0',                 'boolean', 'reports', 'Usar Claude AI para generar un resumen ejecutivo'],
    ['login_logs_report_claude_model',        'claude-sonnet-4-6', 'string',  'reports', 'Modelo de Claude (override; vacío = usa el global)'],
    ['login_logs_report_claude_max_tokens',   '800',               'number',  'reports', 'Máximo de tokens de salida del resumen'],
    ['login_logs_report_claude_prompt',       $defaultPrompt,      'string',  'reports', 'System prompt para Claude'],
];

$inserted = 0;
$updated = 0;

try {
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            setting_type = VALUES(setting_type),
            category     = VALUES(category),
            description  = VALUES(description)
    ");

    foreach ($settings as $setting) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $check->execute([$setting[0]]);
        $exists = (int) $check->fetchColumn() > 0;

        $stmt->execute($setting);

        if ($exists) {
            $updated++;
            echo "✓ Updated meta (value preserved): {$setting[0]}\n";
        } else {
            $inserted++;
            echo "✓ Inserted with default value: {$setting[0]}\n";
        }
    }

    echo "\n✅ Done. Inserted: {$inserted}, metadata-updated: {$updated}.\n";
    echo "Next steps:\n";
    echo "  1. Go to settings.php → section 'Auditoría de Accesos (Login Logs)'.\n";
    echo "  2. Configure recipients, enable the report.\n";
    echo "  3. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
