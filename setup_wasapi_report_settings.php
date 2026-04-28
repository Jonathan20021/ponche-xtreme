<?php
/**
 * Setup / Migration: Daily Wasapi Executive Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_wasapi_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_wasapi_report_settings.php?key=ponche_xtreme_2025
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
Eres director de operaciones de un contact center que opera el canal WhatsApp con la plataforma Wasapi. Recibirás un JSON con la operación del día anterior y la tendencia de los últimos 14 días: KPIs, agentes top y bottom, percentiles SLA (P50/P90/P95), tendencia diaria, distribución por día de la semana, campañas y alertas detectadas.

Genera un análisis ejecutivo en español, dirigido al gerente de operaciones, con esta estructura (sin markdown, solo texto plano con secciones y viñetas con "-"):

VEREDICTO (1-2 líneas)
Estado general de la operación del día y comparación con la tendencia 14d.

KPIs CLAVE
- Conversaciones, tasa de resolución, escaladas, agentes activos.
- Cambio % vs día anterior cuando esté disponible.

LO QUE FUNCIONÓ
2-3 puntos concretos: agentes destacados, mejoras en SLA, reducción de pendientes, etc.

LO QUE PREOCUPA
2-4 puntos: agentes con baja resolución, P90 elevado, pendientes/hold acumulados, anomalías de volumen vs día de la semana esperado.

ACCIONES SUGERIDAS PARA HOY
3-5 acciones priorizadas y específicas (a quién, qué, plazo). Ej: "Coaching a [Agente X] en cierre de conversaciones", "Refuerzo de cobertura entre 14:00-17:00 si pendientes >X".

Sé factual y conciso. No inventes datos. Si un dato falta, dilo. Máximo 25 líneas en total.
PROMPT;

$settings = [
    ['wasapi_report_enabled',                 '0',                 'boolean', 'reports', 'Habilitar envío automático del reporte ejecutivo de Wasapi'],
    ['wasapi_report_time',                    '08:30',             'string',  'reports', 'Hora de envío (HH:MM, GMT-4)'],
    ['wasapi_report_recipients',              '',                  'string',  'reports', 'Correos destinatarios (separados por coma)'],
    ['wasapi_report_days_back',               '1',                 'number',  'reports', 'Días hacia atrás a reportar (1 = ayer)'],
    ['wasapi_report_top_agents_limit',        '10',                'number',  'reports', 'Cantidad de agentes en el Top del reporte'],
    ['wasapi_report_pending_alert_threshold', '15',                'number',  'reports', 'Umbral para disparar alerta cuando hay X conversaciones pendientes/hold al cierre'],
    ['wasapi_report_exclude_weekends',        '0',                 'boolean', 'reports', 'No enviar los días sábado/domingo'],
    ['wasapi_report_claude_enabled',          '0',                 'boolean', 'reports', 'Usar Claude AI para generar análisis ejecutivo'],
    ['wasapi_report_claude_model',            'claude-sonnet-4-6', 'string',  'reports', 'Modelo de Claude (override; vacío = usa el global)'],
    ['wasapi_report_claude_max_tokens',       '1200',              'number',  'reports', 'Máximo de tokens de salida del análisis'],
    ['wasapi_report_claude_prompt',           $defaultPrompt,      'string',  'reports', 'System prompt para Claude'],
    ['wasapi_api_token',                      '338529|NeQrFHvdJ3lX6O2Hs26QPjc0IyrgzKFxQGwVcvCM0575a229', 'string', 'integrations', 'Token Bearer de la API de Wasapi (https://api.wasapi.io)'],
    ['wasapi_base_url',                       'https://api.wasapi.io/prod/api/v1/', 'string', 'integrations', 'URL base de la API de Wasapi'],
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
    echo "  1. Go to settings.php → section 'Reporte Ejecutivo Wasapi (WhatsApp)'.\n";
    echo "  2. Configure recipients, enable the report.\n";
    echo "  3. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
