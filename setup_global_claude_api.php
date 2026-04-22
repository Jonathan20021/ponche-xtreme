<?php
/**
 * Setup / Migration: Global Claude (Anthropic) API configuration
 *
 * Creates two global settings shared across all AI-powered automations:
 *   - anthropic_api_key        (the single API key)
 *   - anthropic_default_model  (default model used when a report does not override it)
 *
 * If `anthropic_api_key` is empty, seeds it from the first non-empty
 * per-report key (login_hours_report_claude_api_key, then
 * quality_alerts_report_claude_api_key) so the migration is non-destructive.
 *
 * Idempotent. Safe to run multiple times.
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

try {
    // Read existing values (so we can seed)
    $currentGlobal = (function (PDO $pdo) {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'anthropic_api_key'");
        $s->execute();
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['setting_value'] : null;
    })($pdo);

    $seedSources = [
        'login_hours_report_claude_api_key',
        'quality_alerts_report_claude_api_key',
    ];
    $seedKey = '';
    foreach ($seedSources as $src) {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $s->execute([$src]);
        $val = (string) ($s->fetchColumn() ?: '');
        if ($val !== '') {
            $seedKey = $val;
            echo "↪ Will seed anthropic_api_key from '$src' (preserving user config).\n";
            break;
        }
    }

    $toInsert = [
        ['anthropic_api_key',       $seedKey,              'string', 'ai', 'API Key global de Anthropic Claude. Usada por todas las automatizaciones con IA (reportes diarios, análisis, etc).'],
        ['anthropic_default_model', 'claude-sonnet-4-6',   'string', 'ai', 'Modelo por defecto de Claude. Cada automatización puede sobrescribirlo en su propia sección.'],
    ];

    $upsert = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            setting_type = VALUES(setting_type),
            category     = VALUES(category),
            description  = VALUES(description)
    ");

    foreach ($toInsert as $row) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $check->execute([$row[0]]);
        $exists = (int) $check->fetchColumn() > 0;

        $upsert->execute($row);

        if ($exists) {
            echo "✓ Updated meta (value preserved): {$row[0]}\n";
        } else {
            echo "✓ Inserted with default value:    {$row[0]}\n";
        }
    }

    // Clean up per-report API keys (not deleted, just blanked) so they no longer shadow the global.
    // Users who had them set will now see an "uses global key" notice in the UI.
    $perReportKeys = [
        'login_hours_report_claude_api_key',
        'quality_alerts_report_claude_api_key',
    ];
    foreach ($perReportKeys as $k) {
        $blank = $pdo->prepare("UPDATE system_settings SET setting_value = '' WHERE setting_key = ? AND setting_value <> ''");
        $blank->execute([$k]);
        if ($blank->rowCount() > 0) {
            echo "↪ Cleared legacy per-report key: $k (now uses global)\n";
        }
    }

    echo "\n✅ Done.\n";
    echo "Next steps:\n";
    echo "  1. Go to settings.php → new section 'Integración con Claude AI (global)'.\n";
    echo "  2. Verify the API key. If empty, paste a valid one.\n";
    echo "  3. Click 'Probar conexión' — both reports will share this key automatically.\n";

} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
