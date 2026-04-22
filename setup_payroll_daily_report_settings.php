<?php
/**
 * Setup / Migration: Daily Payroll Monthly-to-Date Report Settings
 * Idempotent — safe to run multiple times.
 *
 * Usage (CLI):  php setup_payroll_daily_report_settings.php
 * Usage (web):  https://yourdomain.com/setup_payroll_daily_report_settings.php?key=ponche_xtreme_2025
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
Eres un analista financiero de un call center / BPO. Recibirás un JSON con el corte de nómina acumulado del mes en curso: colaboradores administrativos (no agentes) con sus horas productivas, pago en USD, pago en DOP, y totales por departamento.

Genera un RESUMEN EJECUTIVO de máximo 8 líneas en español, dirigido al área de RH / Gerencia. Incluye:
1. Monto total acumulado del mes en USD y DOP.
2. Departamento con mayor costo acumulado y su proporción del total.
3. Top 3 colaboradores con mayor pago acumulado (nómbralos).
4. Colaboradores con baja actividad (< 40 horas acumuladas) que podrían indicar ausencias o registros incompletos.
5. Días transcurridos del mes y proyección del cierre (extrapolación simple).

Sé conciso, factual y numérico. No inventes datos fuera del JSON. Sin markdown, solo texto plano con viñetas "-".
PROMPT;

$settings = [
    ['payroll_report_enabled',             '0',                  'boolean', 'reports', 'Habilitar envío automático del corte diario de nómina acumulada del mes'],
    ['payroll_report_time',                '08:00',              'string',  'reports', 'Hora de envío del corte diario (HH:MM, GMT-4)'],
    ['payroll_report_recipients',          '',                   'string',  'reports', 'Correos destinatarios del corte de nómina (separados por coma)'],
    ['payroll_report_period_mode',         'month_to_yesterday', 'string',  'reports', 'Modo del período: month_to_yesterday (1° del mes hasta ayer) o last_7_days'],
    ['payroll_report_claude_enabled',      '0',                  'boolean', 'reports', 'Usar Claude AI para agregar un resumen ejecutivo narrativo'],
    ['payroll_report_claude_model',        'claude-sonnet-4-6',  'string',  'reports', 'Modelo de Claude (override; vacío = usa el global)'],
    ['payroll_report_claude_max_tokens',   '1000',               'number',  'reports', 'Máximo de tokens de salida del resumen'],
    ['payroll_report_claude_prompt',       $defaultPrompt,       'string',  'reports', 'System prompt para Claude'],
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
    echo "  1. Go to settings.php → section 'Reporte Diario de Nómina Acumulada'.\n";
    echo "  2. Configure recipients, enable the report.\n";
    echo "  3. Install the cron job (line shown in the settings page).\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
