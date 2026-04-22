<?php
/**
 * Cron Job: Daily Tardiness Alert Report
 *
 * Runs mid-morning (default 11:00 GMT-4), lists employees who arrived late
 * today beyond the configured tolerance, compares today's rate with the
 * month-to-date rate, optionally adds a Claude summary, and emails HR + supervisors.
 *
 * Suggested cron (11:00 GMT-4):
 *   0 11 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_tardiness_report.php >> /home2/hhempeos/logs/tardiness_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_tardiness_report.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON TARDINESS REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getTardinessReportSettings($pdo);

    if (($settings['tardiness_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    // Skip weekends if configured
    if (($settings['tardiness_report_exclude_weekends'] ?? '1') === '1') {
        $dayOfWeek = (int) date('N'); // 1=Mon, 7=Sun
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            echo $logPrefix . "Today is weekend (day $dayOfWeek) and exclude_weekends is ON. Exiting.\n";
            exit(0);
        }
    }

    // Enforce configured time with ±5 min tolerance when run via CLI
    $configuredTime = $settings['tardiness_report_time'] ?? '11:00';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 11)) * 60 + (int) ($cfgParts[1] ?? 0);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    $recipients = getTardinessReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    $targetDate = date('Y-m-d'); // TODAY — this is an alert
    echo $logPrefix . "Building tardiness report for $targetDate...\n";

    $reportData = generateDailyTardinessReport($pdo, $targetDate);

    echo $logPrefix . "  Tolerance:         {$reportData['tolerance_minutes']} min\n";
    echo $logPrefix . "  Total entries:     {$reportData['totals']['total_entries_today']}\n";
    echo $logPrefix . "  Tardies today:     {$reportData['totals']['tardies_today']}\n";
    echo $logPrefix . "  Today rate:        {$reportData['totals']['today_rate_pct']}%\n";
    echo $logPrefix . "  Month rate:        {$reportData['totals']['month_rate_pct']}%\n";

    // If configured to only send when there are tardies, skip clean days
    $onlyWithTardies = ($settings['tardiness_report_only_with_tardies'] ?? '0') === '1';
    if ($onlyWithTardies && $reportData['totals']['tardies_today'] === 0) {
        echo $logPrefix . "No tardies today and 'only_with_tardies' is ON. Skipping send.\n";
        exit(0);
    }

    $aiSummary = '';
    if (($settings['tardiness_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAITardinessSummary($pdo, $reportData);
        echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendTardinessReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                    'Reporte diario de tardanzas enviado automáticamente',
                    'tardiness_report',
                    null,
                    [
                        'recipients_count' => count($recipients),
                        'date'             => $reportData['date'],
                        'tolerance'        => $reportData['tolerance_minutes'],
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
    error_log('[CRON TARDINESS REPORT] ' . $e->getMessage());
    exit(1);
}
