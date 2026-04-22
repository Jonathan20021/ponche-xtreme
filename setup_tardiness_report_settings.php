<?php
/**
 * Setup / Migration: Daily Tardiness Alert Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_tardiness_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_tardiness_report_settings.php?key=ponche_xtreme_2025
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
Eres un analista de operaciones. Recibirás un JSON con el reporte de tardanzas del día en un call center / BPO: empleados que llegaron después de su hora programada + la tolerancia configurada, con sus minutos de retraso y departamento. También incluye la tasa mensual acumulada para contexto.

Genera un RESUMEN EJECUTIVO de máximo 6 líneas en español, dirigido a RH y supervisores. Incluye:
1. Cuántas tardanzas hubo hoy y cuál es la tasa de tardanza del día (% de empleados que entraron tarde).
2. Comparación: tasa del día vs. tasa acumulada del mes (¿peor o mejor que el promedio?).
3. Top 3 empleados con mayor retraso (nómbralos con sus minutos).
4. Departamento más afectado si hay concentración.
5. Empleados recurrentes con tardanzas este mes (si los hay en el JSON).

Sé conciso, factual y directo. No inventes datos. Sin markdown, solo texto plano con viñetas "-".
PROMPT;

$settings = [
    ['tardiness_report_enabled',               '0',                 'boolean', 'reports', 'Habilitar envío automático del reporte diario de tardanzas'],
    ['tardiness_report_time',                  '11:00',             'string',  'reports', 'Hora de envío del reporte (HH:MM, GMT-4). Default 11:00 permite que todos ya hayan llegado'],
    ['tardiness_report_recipients',            '',                  'string',  'reports', 'Correos destinatarios (RH + supervisores, separados por coma)'],
    ['tardiness_report_tolerance_minutes',     '10',                'number',  'reports', 'Minutos de tolerancia antes de considerar una entrada como tardía'],
    ['tardiness_report_exclude_weekends',      '1',                 'boolean', 'reports', 'Excluir fines de semana (sábado y domingo) del cálculo'],
    ['tardiness_report_only_with_tardies',     '0',                 'boolean', 'reports', 'Solo enviar si hubo al menos una tardanza hoy'],
    ['tardiness_report_claude_enabled',        '0',                 'boolean', 'reports', 'Usar Claude AI para generar un resumen ejecutivo'],
    ['tardiness_report_claude_model',          'claude-sonnet-4-6', 'string',  'reports', 'Modelo de Claude (override; vacío = usa el global)'],
    ['tardiness_report_claude_max_tokens',     '700',               'number',  'reports', 'Máximo de tokens de salida del resumen'],
    ['tardiness_report_claude_prompt',         $defaultPrompt,      'string',  'reports', 'System prompt para Claude'],
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
    echo "  1. Go to settings.php → section 'Reporte Diario de Tardanzas'.\n";
    echo "  2. Configure recipients, tolerance, enable the report.\n";
    echo "  3. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
