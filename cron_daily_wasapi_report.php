<?php
/**
 * Cron Job: Daily Wasapi Executive Report
 *
 * Suggested cron (08:30 GMT-4):
 *   30 8 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_wasapi_report.php >> /home2/hhempeos/logs/wasapi_report_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_wasapi_report.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON WASAPI REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getWasapiReportSettings($pdo);

    if (($settings['wasapi_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    if (($settings['wasapi_report_exclude_weekends'] ?? '0') === '1') {
        $dow = (int) date('w');
        if ($dow === 0 || $dow === 6) {
            echo $logPrefix . "Weekend skip enabled and today is " . date('l') . ". Exiting.\n";
            exit(0);
        }
    }

    $configuredTime = $settings['wasapi_report_time'] ?? '08:30';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 8)) * 60 + (int) ($cfgParts[1] ?? 30);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    $recipients = getWasapiReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    $daysBack   = max(1, (int) ($settings['wasapi_report_days_back'] ?? 1));
    $targetDate = date('Y-m-d', strtotime("-{$daysBack} day"));
    echo $logPrefix . "Building Wasapi executive report for $targetDate...\n";

    $reportData = generateDailyWasapiReport($pdo, $targetDate);

    if (empty($reportData['available'])) {
        echo $logPrefix . "❌ Data unavailable: " . ($reportData['error'] ?? 'unknown') . "\n";
        exit(1);
    }

    $totals = $reportData['totals'];
    echo $logPrefix . "  Conversaciones:    {$totals['total_conversations']}\n";
    echo $logPrefix . "  Cerradas:          " . (int) $totals['conversations_by_status']['closed'] . "\n";
    echo $logPrefix . "  Pendientes+hold:   " . ((int) $totals['conversations_by_status']['pending'] + (int) $totals['conversations_by_status']['hold']) . "\n";
    echo $logPrefix . "  Tasa resolución:   {$totals['team_resolution_rate']}%\n";
    echo $logPrefix . "  Agentes online:    {$totals['agents_online']} / {$totals['agents_total']}\n";

    $aiSummary = '';
    if (($settings['wasapi_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAIWasapiSummary($pdo, $reportData);
        echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendWasapiReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                    'Reporte ejecutivo Wasapi enviado automáticamente',
                    'wasapi_report',
                    null,
                    [
                        'recipients_count' => count($recipients),
                        'date'             => $reportData['date'],
                        'totals'           => $totals,
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
    error_log('[CRON WASAPI REPORT] ' . $e->getMessage());
    exit(1);
}
