<?php
/**
 * Cron Job: Daily Activity Logs Audit Report
 *
 * Runs each morning (default 08:15 GMT-4), produces an audit of all
 * administrative activity from activity_logs for the previous day,
 * highlights sensitive actions and deletions, optionally adds a Claude
 * summary, and emails the report.
 *
 * Suggested cron (08:15 GMT-4):
 *   15 8 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_activity_logs_report.php >> /home2/hhempeos/logs/activity_logs_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_activity_logs_report.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON ACTIVITY LOGS REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getActivityLogsReportSettings($pdo);

    if (($settings['activity_logs_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    $configuredTime = $settings['activity_logs_report_time'] ?? '08:15';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 8)) * 60 + (int) ($cfgParts[1] ?? 15);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    $recipients = getActivityLogsReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    $targetDate = date('Y-m-d', strtotime('yesterday'));
    echo $logPrefix . "Building activity audit for $targetDate...\n";

    $reportData = generateDailyActivityLogsReport($pdo, $targetDate);

    echo $logPrefix . "  Total actions:     {$reportData['totals']['total_actions']}\n";
    echo $logPrefix . "  Modules touched:   {$reportData['totals']['modules_touched']}\n";
    echo $logPrefix . "  Unique users:      {$reportData['totals']['unique_users']}\n";
    echo $logPrefix . "  Sensitive actions: {$reportData['totals']['sensitive']}\n";
    echo $logPrefix . "  Deletes:           {$reportData['totals']['deletes']}\n";

    $aiSummary = '';
    if (($settings['activity_logs_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAIActivityLogsSummary($pdo, $reportData);
        echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendActivityLogsReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                    'Reporte diario de auditoría de actividad enviado automáticamente',
                    'activity_logs_report',
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
    error_log('[CRON ACTIVITY LOGS REPORT] ' . $e->getMessage());
    exit(1);
}
