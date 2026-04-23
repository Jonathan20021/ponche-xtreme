<?php
/**
 * Setup / Migration: Daily Activity Logs Audit Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_activity_logs_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_activity_logs_report_settings.php?key=ponche_xtreme_2025
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
Eres un analista de cumplimiento y auditoría de un call center / BPO. Recibirás un JSON con toda la actividad administrativa del día anterior desde la tabla activity_logs: quién hizo qué, en qué módulo, sobre qué entidad, y a qué hora.

Genera un RESUMEN EJECUTIVO de máximo 8 líneas en español, dirigido al Administrador y al equipo de cumplimiento. Incluye:
1. Total de acciones del día, cantidad de usuarios activos, módulos con más cambios.
2. Hora pico de actividad.
3. Acciones sensibles: cambios en permisos, tarifas, información bancaria, configuración del sistema, desactivación de usuarios. Menciona quién las hizo.
4. Eliminaciones: cualquier action=delete, destacando sobre qué entidad.
5. Usuarios con actividad atípica: alguien que tocó muchos módulos distintos o hizo muchas acciones.
6. Riesgos u omisiones detectables (p.ej. cambios de tarifas sin aprobación, eliminaciones en bloque).

Sé conciso, factual y orientado a auditoría. No inventes datos. Sin markdown, solo texto plano con viñetas "-".
PROMPT;

$settings = [
    ['activity_logs_report_enabled',           '0',                 'boolean', 'reports', 'Habilitar envío automático del reporte de auditoría de actividad'],
    ['activity_logs_report_time',              '08:15',             'string',  'reports', 'Hora de envío (HH:MM, GMT-4)'],
    ['activity_logs_report_recipients',        '',                  'string',  'reports', 'Correos destinatarios (Administrador + cumplimiento, separados por coma)'],
    ['activity_logs_report_exclude_modules',   'reports',           'string',  'reports', 'Módulos a excluir del reporte (CSV). Default excluye "reports" (ruido de automatización)'],
    ['activity_logs_report_sensitive_modules', 'permissions,rates,banking,system_settings,users', 'string', 'reports', 'Módulos tratados como sensibles (CSV). Aparecen en sección dedicada'],
    ['activity_logs_report_top_users_limit',   '15',                'number',  'reports', 'Cantidad de usuarios en el Top del reporte'],
    ['activity_logs_report_recent_tail',       '20',                'number',  'reports', 'Cantidad de acciones recientes a mostrar al final (cola cronológica)'],
    ['activity_logs_report_claude_enabled',    '0',                 'boolean', 'reports', 'Usar Claude AI para generar un resumen ejecutivo'],
    ['activity_logs_report_claude_model',      'claude-sonnet-4-6', 'string',  'reports', 'Modelo de Claude (override; vacío = usa el global)'],
    ['activity_logs_report_claude_max_tokens', '800',               'number',  'reports', 'Máximo de tokens de salida del resumen'],
    ['activity_logs_report_claude_prompt',     $defaultPrompt,      'string',  'reports', 'System prompt para Claude'],
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
    echo "  1. Go to settings.php → section 'Auditoría de Actividad (Activity Logs)'.\n";
    echo "  2. Configure recipients, enable the report.\n";
    echo "  3. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
