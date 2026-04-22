<?php
/**
 * Setup / Migration: Daily Workforce (Active vs. Absent) Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_workforce_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_workforce_report_settings.php?key=ponche_xtreme_2025
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
Eres un analista de operaciones de un call center / BPO. Recibirás un JSON con la foto de fuerza laboral del día: total de empleados Activos, En Prueba y Ausentes (sin marcaje hoy), con listas detalladas por departamento y rol.

Genera un RESUMEN EJECUTIVO de máximo 6 líneas en español, dirigido a RH y supervisores. Incluye:
1. Cuántos empleados hay en total y qué porcentaje está presente hoy.
2. Cuántos ausentes y qué % representa del total.
3. Departamento más afectado por ausencias.
4. Roles más impactados (ej: AGENT vs SUPERVISOR vs administrativo).
5. Si hay empleados en período de prueba entre los ausentes (punto crítico — candidatos a seguimiento inmediato).

Sé conciso, factual, y directo. No inventes datos. Sin markdown, solo texto plano con viñetas "-".
PROMPT;

$settings = [
    ['workforce_report_enabled',              '0',                 'boolean', 'reports', 'Habilitar envío automático del reporte de fuerza laboral (activos vs ausentes)'],
    ['workforce_report_time',                 '09:00',             'string',  'reports', 'Hora de envío (HH:MM, GMT-4). Default 09:00 — foto al inicio del turno'],
    ['workforce_report_recipients',           '',                  'string',  'reports', 'Correos destinatarios (RH + supervisores + gerencia)'],
    ['workforce_report_exclude_weekends',     '1',                 'boolean', 'reports', 'Excluir fines de semana del envío automático'],
    ['workforce_report_only_with_absences',   '0',                 'boolean', 'reports', 'Solo enviar si hay al menos un empleado ausente'],
    ['workforce_report_claude_enabled',       '0',                 'boolean', 'reports', 'Usar Claude AI para generar un resumen ejecutivo'],
    ['workforce_report_claude_model',         'claude-sonnet-4-6', 'string',  'reports', 'Modelo de Claude (override; vacío = usa el global)'],
    ['workforce_report_claude_max_tokens',    '700',               'number',  'reports', 'Máximo de tokens de salida del resumen'],
    ['workforce_report_claude_prompt',        $defaultPrompt,      'string',  'reports', 'System prompt para Claude'],
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
    echo "  1. Go to settings.php → section 'Reporte de Fuerza Laboral (Activos vs Ausentes)'.\n";
    echo "  2. Configure recipients, enable the report.\n";
    echo "  3. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
