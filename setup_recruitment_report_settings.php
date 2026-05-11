<?php
/**
 * Setup / Migration: Daily Recruitment Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_recruitment_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_recruitment_report_settings.php?key=ponche_xtreme_2025
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
Eres un analista senior de reclutamiento de una empresa de centro de llamadas (BPO). Recibirás un JSON con la foto diaria del módulo: posiciones activas, embudo por estado, top candidatos calificados por IA, cuellos de botella, próximas entrevistas, contrataciones recientes, fuentes y métricas de conversión.

Genera un RESUMEN EJECUTIVO de máximo 8 líneas en español, dirigido a RH, supervisores y gerencia. Incluye:
1. Salud general del pipeline (cuántos en proceso, posiciones cubiertas vs abiertas, conversión y tiempo promedio al contratar).
2. Top 2-3 candidatos por score de IA que requieren acción inmediata (menciona nombres y puesto).
3. Cuellos de botella críticos: candidatos estancados >7 días en una etapa (menciona top 2 por nombre).
4. Entrevistas próximas a destacar (hoy o mañana, especialmente con candidatos de alto score).
5. Tendencias de fuentes y departamentos: de dónde están viniendo los mejores candidatos.
6. Acciones recomendadas concretas (revisar shortlist, agendar entrevista a X, contactar a Y, abrir más fuentes para puesto Z).

Sé conciso, factual, accionable. No inventes datos. Sin markdown, solo texto plano con viñetas "-".
PROMPT;

$settings = [
    ['recruitment_report_enabled',            '0',                 'boolean', 'reports', 'Habilitar envío automático del reporte diario de reclutamiento'],
    ['recruitment_report_time',               '08:30',             'string',  'reports', 'Hora de envío (HH:MM, GMT-4). Default 08:30'],
    ['recruitment_report_recipients',         '',                  'string',  'reports', 'Correos destinatarios (RH + supervisores + gerencia)'],
    ['recruitment_report_exclude_weekends',   '1',                 'boolean', 'reports', 'Excluir fines de semana del envío automático'],
    ['recruitment_report_only_with_activity', '0',                 'boolean', 'reports', 'Solo enviar si hubo actividad reciente (aplicaciones, cambios, entrevistas)'],
    ['recruitment_report_period_days',        '7',                 'number',  'reports', 'Ventana de días para totales del período (recibidas, contratadas, rechazadas)'],
    ['recruitment_report_upcoming_days',      '3',                 'number',  'reports', 'Días hacia adelante para incluir entrevistas próximas'],
    ['recruitment_report_bottleneck_days',    '7',                 'number',  'reports', 'Días sin movimiento para considerar un candidato como cuello de botella'],
    ['recruitment_report_min_ai_score',       '70',                'number',  'reports', 'Score mínimo de IA para incluir un candidato en el top destacado (0-100)'],
    ['recruitment_report_claude_enabled',     '0',                 'boolean', 'reports', 'Usar Claude AI para generar un resumen ejecutivo'],
    ['recruitment_report_claude_model',       'claude-sonnet-4-6', 'string',  'reports', 'Modelo de Claude (override; vacío = usa el global)'],
    ['recruitment_report_claude_max_tokens',  '900',               'number',  'reports', 'Máximo de tokens de salida del resumen'],
    ['recruitment_report_claude_prompt',      $defaultPrompt,      'string',  'reports', 'System prompt para Claude'],
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
    echo "  1. Go to settings.php → section 'Reporte Diario de Reclutamiento'.\n";
    echo "  2. Configure recipients, enable the report, optionally enable Claude AI.\n";
    echo "  3. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
