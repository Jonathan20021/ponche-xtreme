<?php
/**
 * Setup / Migration: Executive Dashboard Daily Summary Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_executive_dashboard_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_executive_dashboard_report_settings.php?key=ponche_xtreme_2025
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
Eres el Chief of Staff de un call center / BPO. Recibirás un JSON con el cierre ejecutivo del día desde el Dashboard Ejecutivo: asistencia, horas pagadas, costo USD/DOP, campañas activas, departamentos y top empleados.

Genera un RESUMEN EJECUTIVO DE CIERRE DEL DÍA de máximo 10 líneas en español, dirigido al CEO/COO. Incluye:
1. Cómo cerró el día: % de asistencia, horas pagadas, costo total del día (en USD equivalente).
2. Campañas que lideraron en costo y horas. Destaca si alguna tuvo baja asistencia.
3. Departamento más activo del día y departamento con más ausentismo.
4. Alertas: costo inusualmente alto o bajo, asistencia menor a la esperada, empleados en prueba ausentes.
5. Highlights de la fuerza laboral (mes en curso): altas, bajas, estado general del headcount.
6. Una línea de recomendación accionable para mañana si detectas un patrón relevante.

Sé conciso, factual y orientado a decisión ejecutiva. No inventes datos. Sin markdown, solo texto plano con viñetas "-".
PROMPT;

$settings = [
    ['executive_dashboard_report_enabled',             '0',                 'boolean', 'reports', 'Habilitar envío automático del cierre ejecutivo del día'],
    ['executive_dashboard_report_time',                '19:00',             'string',  'reports', 'Hora de envío (HH:MM, GMT-4)'],
    ['executive_dashboard_report_recipients',          '',                  'string',  'reports', 'Correos destinatarios (CEO, COO, Directores, separados por coma)'],
    ['executive_dashboard_report_top_employees_count', '10',                'number',  'reports', 'Cantidad de empleados en el Top por horas del día'],
    ['executive_dashboard_report_exclude_weekends',    '0',                 'boolean', 'reports', 'No enviar los fines de semana'],
    ['executive_dashboard_report_claude_enabled',      '0',                 'boolean', 'reports', 'Usar Claude AI para generar un resumen ejecutivo del cierre'],
    ['executive_dashboard_report_claude_model',        'claude-sonnet-4-6', 'string',  'reports', 'Modelo de Claude (override; vacío = usa el global)'],
    ['executive_dashboard_report_claude_max_tokens',   '900',               'number',  'reports', 'Máximo de tokens de salida del resumen'],
    ['executive_dashboard_report_claude_prompt',       $defaultPrompt,      'string',  'reports', 'System prompt para Claude'],
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
    echo "  1. Go to settings.php → section 'Cierre Ejecutivo del Día'.\n";
    echo "  2. Configure recipients, enable the report.\n";
    echo "  3. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
