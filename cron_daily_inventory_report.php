<?php
/**
 * Cron Job: Daily Inventory Report
 *
 * Captures a snapshot of inventory state — totals, stock alerts, expiring lots,
 * top consumed items, today's movements and active assignments — and emails
 * it to the configured recipients. Optionally enriches the email with a
 * Claude-generated executive summary.
 *
 * Suggested cron (08:00 GMT-4):
 *   0 8 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_inventory_report.php >> /home2/hhempeos/logs/inventory_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_inventory_report.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON INVENTORY REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getInventoryReportSettings($pdo);

    if (($settings['inventory_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    // Skip weekends if configured
    if (($settings['inventory_report_exclude_weekends'] ?? '1') === '1') {
        $dayOfWeek = (int) date('N'); // 1=Mon, 7=Sun
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            echo $logPrefix . "Today is weekend (day $dayOfWeek) and exclude_weekends is ON. Exiting.\n";
            exit(0);
        }
    }

    // Enforce configured time with ±5 min tolerance when run via CLI
    $configuredTime = $settings['inventory_report_time'] ?? '08:00';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 8)) * 60 + (int) ($cfgParts[1] ?? 0);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    $recipients = getInventoryReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    $targetDate = date('Y-m-d');
    echo $logPrefix . "Building inventory snapshot for $targetDate...\n";

    $reportData = generateDailyInventoryReport($pdo, $targetDate);

    echo $logPrefix . "  Items activos:      {$reportData['totals']['total_items']}\n";
    echo $logPrefix . "  Unidades totales:   {$reportData['totals']['total_units']}\n";
    echo $logPrefix . "  Stock bajo:         {$reportData['totals']['low_stock']}\n";
    echo $logPrefix . "  Sin stock:          {$reportData['totals']['out_of_stock']}\n";
    echo $logPrefix . "  Lotes por vencer:   {$reportData['totals']['expiring_soon']}\n";
    echo $logPrefix . "  Alertas (total):    {$reportData['totals']['alerts_count']}\n";

    // If configured to only send when there are alerts, skip clean days
    $onlyWithAlerts = ($settings['inventory_report_only_with_alerts'] ?? '0') === '1';
    if ($onlyWithAlerts && (int) $reportData['totals']['alerts_count'] === 0) {
        echo $logPrefix . "No alerts today and 'only_with_alerts' is ON. Skipping send.\n";
        exit(0);
    }

    $aiSummary = '';
    if (($settings['inventory_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAIInventorySummary($pdo, $reportData);
        echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendInventoryReportByEmail($pdo, $reportData, $recipients, $aiSummary);

    if ($sent) {
        echo $logPrefix . "✅ Sent successfully.\n";

        try {
            require_once __DIR__ . '/lib/logging_functions.php';
            if (function_exists('log_custom_action')) {
                log_custom_action(
                    $pdo,
                    0,
                    'CRON System',
                    'system',
                    'reports',
                    'send',
                    'Reporte diario de inventario enviado automáticamente',
                    'inventory_report',
                    null,
                    [
                        'recipients_count' => count($recipients),
                        'date'             => $reportData['date'],
                        'totals'           => $reportData['totals'],
                        'ai_generated'     => $aiSummary !== '',
                        'automated'        => true,
                    ]
                );
            }
        } catch (Exception $e) {
            echo $logPrefix . 'Warning: could not log action: ' . $e->getMessage() . "\n";
        }

        exit(0);
    } else {
        echo $logPrefix . "❌ Failed to send. Check error_log for details.\n";
        exit(1);
    }

} catch (Exception $e) {
    echo $logPrefix . '❌ ERROR: ' . $e->getMessage() . "\n";
    echo $logPrefix . 'Trace: ' . $e->getTraceAsString() . "\n";
    error_log('[CRON INVENTORY REPORT] ' . $e->getMessage());
    exit(1);
}
