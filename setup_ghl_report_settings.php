<?php
/**
 * Setup / Migration: Daily GHL (Voice AI) Executive Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_ghl_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_ghl_report_settings.php?key=ponche_xtreme_2025
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
Eres director de operaciones de un contact center que opera llamadas de voz con GoHighLevel (GHL) y su capa de Voice AI. Recibirás un JSON con la operación de llamadas del día anterior: KPIs, top disposiciones, top agentes con score de calidad, agentes con problemas detectados, distribución por canal y por duración, sentimiento y alertas detectadas.

Genera un análisis ejecutivo en español, dirigido al gerente de operaciones, con esta estructura (sin markdown, solo texto plano con secciones y viñetas con "-"):

VEREDICTO (1-2 líneas)
Estado general de la operación de voz del día.

KPIs CLAVE
- Llamadas totales, in/out, duración promedio, cobertura grabación/transcripción/resumen.
- Disposiciones únicas, llamadas sin disposición y su % del total.

LO QUE FUNCIONÓ
2-3 puntos: agentes destacados (alto volumen + alta calidad), disposiciones positivas predominantes, buena cobertura de grabación, etc.

LO QUE PREOCUPA
2-4 puntos: agentes con score de calidad bajo umbral (especificar nombres y score), llamadas sin disposición, baja cobertura de transcripción/grabación, distribución de duración con muchas llamadas <30s (indicador de cuelgues), sentimiento negativo predominante.

ACCIONES SUGERIDAS PARA HOY
3-5 acciones priorizadas y específicas (a quién, qué, plazo). Ejemplos:
- "Coaching a [Agente X] en cierre de disposiciones (score Y%)"
- "Validar grabación en línea Z (cobertura X% vs umbral Y%)"
- "Refuerzo en script para reducir llamadas <30s (X% del volumen)"

Sé factual y conciso. No inventes datos. Si un dato falta, dilo. Máximo 25 líneas en total.
PROMPT;

$settings = [
    ['ghl_report_enabled',                  '0',                 'boolean', 'reports', 'Habilitar envío automático del reporte ejecutivo de GHL Voice AI'],
    ['ghl_report_time',                     '08:45',             'string',  'reports', 'Hora de envío (HH:MM, GMT-4)'],
    ['ghl_report_recipients',               '',                  'string',  'reports', 'Correos destinatarios (separados por coma)'],
    ['ghl_report_days_back',                '1',                 'number',  'reports', 'Días hacia atrás a reportar (1 = ayer)'],
    ['ghl_report_max_pages',                '10',                'number',  'reports', 'Máximo de páginas a traer de la API (1-50). Más páginas = más datos pero más lento'],
    ['ghl_report_page_size',                '50',                'number',  'reports', 'Tamaño de página (10-50)'],
    ['ghl_report_top_agents_limit',         '10',                'number',  'reports', 'Cantidad de agentes en el Top del reporte'],
    ['ghl_report_top_dispositions_limit',   '10',                'number',  'reports', 'Cantidad de disposiciones en el Top del reporte'],
    ['ghl_report_quality_alert_threshold',  '70',                'number',  'reports', 'Umbral de score de calidad por agente (0-100). Por debajo de esto, el agente se reporta como problema'],
    ['ghl_report_recording_alert_threshold','90',                'number',  'reports', 'Umbral mínimo de cobertura de grabación (%). Si baja, se dispara alerta'],
    ['ghl_report_no_disposition_alert_pct', '10',                'number',  'reports', 'Si % de llamadas sin disposición supera este valor, se dispara alerta'],
    ['ghl_report_exclude_weekends',         '0',                 'boolean', 'reports', 'No enviar los días sábado/domingo'],
    ['ghl_report_integration_id',           '',                  'string',  'reports', 'ID de integración GHL específica a reportar (vacío = default)'],
    ['ghl_report_claude_enabled',           '0',                 'boolean', 'reports', 'Usar Claude AI para generar análisis ejecutivo'],
    ['ghl_report_claude_model',             'claude-sonnet-4-6', 'string',  'reports', 'Modelo de Claude (override; vacío = usa el global)'],
    ['ghl_report_claude_max_tokens',        '1400',              'number',  'reports', 'Máximo de tokens de salida del análisis'],
    ['ghl_report_claude_prompt',            $defaultPrompt,      'string',  'reports', 'System prompt para Claude'],
];

$inserted = 0;
$updated  = 0;

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
    echo "  1. Make sure GHL/Voice AI is configured (ghl_integrations table — vía ghl_voice_ai_dashboard.php).\n";
    echo "  2. Go to settings.php → section 'Reporte Ejecutivo GHL Voice AI'.\n";
    echo "  3. Configure recipients, enable the report.\n";
    echo "  4. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
