<?php
/**
 * Setup / Migration: Daily Login Hours Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_login_hours_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_login_hours_report_settings.php?key=ponche_xtreme_2025
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
Eres un analista de operaciones para un call center / BPO. Recibirás un JSON con el resumen de horas de login del día anterior por empleado (hora de entrada, hora de salida, horas netas trabajadas, breaks tomados, tiempo en cada estado como disponible, wasapi, digitación, baño, coaching).

Genera un RESUMEN EJECUTIVO de máximo 6 líneas en español, dirigido al supervisor. Incluye:
1. Total de empleados que registraron entrada ayer.
2. Quiénes llegaron tarde (más de 10 min después del horario programado) — menciona nombres.
3. Quiénes excedieron el tiempo de breaks habitual (más de 45 min total de BREAK + PAUSA + BA_NO).
4. Quiénes no registraron salida.
5. Anomalías relevantes (horas netas muy bajas, tiempo en estado productivo inusual).

Sé conciso, específico y factual. No inventes datos que no estén en el JSON. No uses markdown, solo texto plano con viñetas simples usando "-".
PROMPT;

$settings = [
    ['login_hours_report_enabled',               '0',                    'boolean', 'reports', 'Habilitar envío automático del reporte diario de horas de login'],
    ['login_hours_report_time',                  '07:00',                'string',  'reports', 'Hora de envío del reporte de horas de login (HH:MM, GMT-4)'],
    ['login_hours_report_recipients',            '',                     'string',  'reports', 'Correos destinatarios del reporte de horas de login (separados por coma)'],
    ['login_hours_report_late_threshold_minutes','10',                   'number',  'reports', 'Minutos de tolerancia antes de marcar una entrada como tardía'],
    ['login_hours_report_break_threshold_minutes','45',                  'number',  'reports', 'Minutos totales de break (BREAK + PAUSA + BA_NO) antes de considerarlo exceso'],
    ['login_hours_report_claude_enabled',        '0',                    'boolean', 'reports', 'Usar Claude API para generar un resumen ejecutivo narrativo encima de la tabla'],
    ['login_hours_report_claude_api_key',        '',                     'string',  'reports', 'API Key de Anthropic (sk-ant-...). Si está vacía, se usará la variable de entorno ANTHROPIC_API_KEY'],
    ['login_hours_report_claude_model',          'claude-sonnet-4-6',    'string',  'reports', 'Modelo de Claude a utilizar para el resumen'],
    ['login_hours_report_claude_max_tokens',     '800',                  'number',  'reports', 'Máximo de tokens de salida para el resumen de Claude'],
    ['login_hours_report_claude_prompt',         $defaultPrompt,         'string',  'reports', 'Prompt del sistema para Claude al generar el resumen ejecutivo'],
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
        // Only set setting_value if the key doesn't yet exist (to avoid overwriting user config)
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
    echo "  1. Go to settings.php → section 'Reporte Diario de Horas de Login'.\n";
    echo "  2. Configure recipients, enable the report, optionally enable Claude AI summary.\n";
    echo "  3. Install the cron job (example shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
