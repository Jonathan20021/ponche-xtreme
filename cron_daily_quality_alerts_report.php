<?php
/**
 * Cron Job: Daily Quality Alerts Report
 *
 * Generates a list of agents whose quality evaluations fell below the
 * configured threshold on the previous day, optionally adds a Claude-
 * generated executive narrative, and emails the report to supervisors.
 *
 * Suggested cron (every morning at 07:30 GMT-4):
 *   30 7 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_quality_alerts_report.php >> /home2/hhempeos/logs/quality_alerts_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_quality_alerts_report.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON QUALITY ALERTS REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getQualityAlertsReportSettings($pdo);

    if (($settings['quality_alerts_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    // Enforce configured time with ±5 min tolerance (only when run via CLI)
    $configuredTime = $settings['quality_alerts_report_time'] ?? '07:30';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 7)) * 60 + (int) ($cfgParts[1] ?? 30);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    $recipients = getQualityAlertsReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    $targetDate = date('Y-m-d', strtotime('yesterday'));
    echo $logPrefix . "Building report for $targetDate...\n";

    $reportData = generateDailyQualityAlertsReport($pdo, $targetDate);

    if (!$reportData['available']) {
        echo $logPrefix . '⚠️  Quality DB issue: ' . ($reportData['error'] ?? 'unknown') . "\n";
    }

    echo $logPrefix . "  Threshold:           {$reportData['threshold']}%\n";
    echo $logPrefix . "  Total alerts:        {$reportData['totals']['total_alerts']}\n";
    echo $logPrefix . "  Agents affected:     {$reportData['totals']['agents_affected']}\n";
    echo $logPrefix . "  Campaigns affected:  {$reportData['totals']['campaigns_affected']}\n";

    // If configured to only send when there are alerts, skip empty days
    $onlyWithEvals = ($settings['quality_alerts_report_only_with_evals'] ?? '1') === '1';
    if ($onlyWithEvals && $reportData['totals']['total_alerts'] === 0 && !empty($reportData['available'])) {
        echo $logPrefix . "No critical alerts today and 'only_with_evals' is ON. Skipping send.\n";
        exit(0);
    }

    // Optional AI summary
    $aiSummary = '';
    if (($settings['quality_alerts_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAIQualityAlertsSummary($pdo, $reportData);
        echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendQualityAlertsReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                    'Reporte diario de alertas críticas de calidad enviado automáticamente',
                    'quality_alerts_report',
                    null,
                    [
                        'recipients_count' => count($recipients),
                        'date'             => $reportData['date'],
                        'threshold'        => $reportData['threshold'],
                        'totals'           => $reportData['totals'],
                        'ai_enabled'       => ($settings['quality_alerts_report_claude_enabled'] ?? '0') === '1',
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
    error_log('[CRON QUALITY ALERTS REPORT] ' . $e->getMessage());
    exit(1);
}
