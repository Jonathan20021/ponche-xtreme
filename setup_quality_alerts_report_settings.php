<?php
/**
 * Setup / Migration: Daily Quality Alerts Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_quality_alerts_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_quality_alerts_report_settings.php?key=ponche_xtreme_2025
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
Eres un analista de calidad de un call center / BPO. Recibirás un JSON con las evaluaciones del día anterior que cayeron por debajo del umbral de calidad. Cada evaluación incluye: agente, campaña, score obtenido, áreas de mejora identificadas, comentarios generales, resumen del análisis AI y fecha de la llamada.

Genera un RESUMEN EJECUTIVO de máximo 8 líneas en español, dirigido al supervisor. Incluye:
1. Total de alertas críticas del día y cuántos agentes únicos están involucrados.
2. Patrones recurrentes entre las áreas de mejora (ej: "cierre de venta", "tono inadecuado", "saltar script").
3. Agentes con 2 o más alertas en el mismo día — nómbralos.
4. Campañas más afectadas (cuáles concentran más fallas).
5. Una recomendación de acción priorizada (coaching, feedback, revisión de script).

Sé específico, factual y accionable. No inventes datos fuera del JSON. Sin markdown, solo texto plano con viñetas usando "-".
PROMPT;

$settings = [
    ['quality_alerts_report_enabled',             '0',                  'boolean', 'reports', 'Habilitar envío automático del reporte diario de alertas críticas de calidad'],
    ['quality_alerts_report_time',                '07:30',              'string',  'reports', 'Hora de envío del reporte de alertas de calidad (HH:MM, GMT-4)'],
    ['quality_alerts_report_recipients',          '',                   'string',  'reports', 'Correos destinatarios del reporte de alertas críticas (separados por coma)'],
    ['quality_alerts_report_threshold',           '80',                 'number',  'reports', 'Umbral de calidad (%). Evaluaciones con percentage < umbral se consideran alertas críticas'],
    ['quality_alerts_report_only_with_evals',     '1',                  'boolean', 'reports', 'Solo enviar si hay al menos una alerta crítica (no enviar emails vacíos)'],
    ['quality_alerts_report_claude_enabled',      '0',                  'boolean', 'reports', 'Usar Claude API para generar un resumen ejecutivo con patrones y recomendaciones'],
    ['quality_alerts_report_claude_api_key',      '',                   'string',  'reports', 'API Key de Anthropic (sk-ant-...). Si está vacía, usa ANTHROPIC_API_KEY del entorno'],
    ['quality_alerts_report_claude_model',        'claude-sonnet-4-6',  'string',  'reports', 'Modelo de Claude para el resumen'],
    ['quality_alerts_report_claude_max_tokens',   '900',                'number',  'reports', 'Máximo de tokens de salida del resumen'],
    ['quality_alerts_report_claude_prompt',       $defaultPrompt,       'string',  'reports', 'System prompt para Claude al generar el resumen ejecutivo'],
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
    echo "  1. Go to settings.php → section 'Reporte Diario de Alertas Críticas de Calidad'.\n";
    echo "  2. Configure threshold, recipients, enable the report.\n";
    echo "  3. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
