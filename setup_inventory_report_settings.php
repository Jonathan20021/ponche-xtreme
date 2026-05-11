<?php
/**
 * Setup / Migration: Daily Inventory Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_inventory_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_inventory_report_settings.php?key=ponche_xtreme_2025
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
Eres un analista de inventario senior de una empresa de centro de llamadas (BPO). Recibirás un JSON con la foto diaria del inventario: totales globales (items, unidades, valor), desglose por categoría, items con stock crítico, lotes próximos a vencer, top de items consumidos y movimientos del día.

Genera un RESUMEN EJECUTIVO de máximo 8 líneas en español, dirigido a gerencia, RH y supply chain. Incluye:
1. Salud general del inventario (items activos, valor total, # de alertas).
2. Items críticos: agotados y con stock bajo más urgentes a reordenar (menciona top 3 por nombre).
3. Riesgo de vencimiento: lotes que vencen pronto y costo aproximado en juego (si aplica).
4. Tendencia de consumo: qué se está consumiendo más rápido y cuántos días de cobertura quedan.
5. Acciones recomendadas concretas (reordenar X, redistribuir lote Y, revisar consumo anormal de Z).

Sé conciso, factual, accionable. No inventes datos. Sin markdown, solo texto plano con viñetas "-".
PROMPT;

$settings = [
    ['inventory_report_enabled',           '0',                 'boolean', 'reports', 'Habilitar envío automático del reporte diario de inventario'],
    ['inventory_report_time',              '08:00',             'string',  'reports', 'Hora de envío (HH:MM, GMT-4). Default 08:00 — al inicio del día'],
    ['inventory_report_recipients',        '',                  'string',  'reports', 'Correos destinatarios (gerencia + RH + supply chain)'],
    ['inventory_report_exclude_weekends',  '1',                 'boolean', 'reports', 'Excluir fines de semana del envío automático'],
    ['inventory_report_only_with_alerts',  '0',                 'boolean', 'reports', 'Solo enviar si hay al menos una alerta (stock bajo, agotado o próximo a vencer)'],
    ['inventory_report_expiring_days',     '30',                'number',  'reports', 'Ventana de días para mostrar lotes próximos a vencer'],
    ['inventory_report_consumption_days',  '30',                'number',  'reports', 'Días hacia atrás para el top de items consumidos'],
    ['inventory_report_movements_limit',   '20',                'number',  'reports', 'Cantidad de movimientos recientes a incluir en el reporte'],
    ['inventory_report_claude_enabled',    '0',                 'boolean', 'reports', 'Usar Claude AI para generar un resumen ejecutivo'],
    ['inventory_report_claude_model',      'claude-sonnet-4-6', 'string',  'reports', 'Modelo de Claude (override; vacío = usa el global)'],
    ['inventory_report_claude_max_tokens', '900',               'number',  'reports', 'Máximo de tokens de salida del resumen'],
    ['inventory_report_claude_prompt',     $defaultPrompt,      'string',  'reports', 'System prompt para Claude'],
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
    echo "  1. Go to settings.php → section 'Reporte Diario de Inventario'.\n";
    echo "  2. Configure recipients, enable the report, optionally enable Claude AI.\n";
    echo "  3. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
