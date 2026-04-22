<?php
/**
 * Cron Job: Daily Login Hours Report
 *
 * Generates the Entry/Exit/Break/Coaching/Disponible/... summary for the
 * previous day, optionally adds a Claude-generated executive narrative,
 * and emails the report to configured supervisors.
 *
 * Suggested cron (every morning at 07:00 GMT-4):
 *   0 7 * * * /usr/bin/php /path/to/ponche-xtreme/cron_daily_login_hours_report.php
 *
 * Or via web (with key):
 *   0 7 * * * wget -q -O - 'https://yourdomain.com/cron_daily_login_hours_report.php?cron_key=ponche_xtreme_2025'
 */

// Allow CLI or authenticated web trigger
if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_login_hours_report.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON LOGIN HOURS REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getLoginHoursReportSettings($pdo);

    if (($settings['login_hours_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    // Enforce configured time with +/- 5 minute tolerance (only when running via cron)
    $configuredTime = $settings['login_hours_report_time'] ?? '07:00';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 7)) * 60 + (int) ($cfgParts[1] ?? 0);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    // Recipients
    $recipients = getLoginHoursReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    // Build yesterday's report
    $targetDate = date('Y-m-d', strtotime('yesterday'));
    echo $logPrefix . "Building report for $targetDate...\n";

    $reportData = generateDailyLoginHoursReport($pdo, $targetDate);

    echo $logPrefix . "  Employees with activity: {$reportData['totals']['employees_with_activity']}\n";
    echo $logPrefix . "  Late:                    {$reportData['totals']['late_count']}\n";
    echo $logPrefix . "  No-exit:                 {$reportData['totals']['no_exit_count']}\n";
    echo $logPrefix . "  Break excess:            {$reportData['totals']['break_excess_count']}\n";

    // Optional AI summary
    $aiSummary = '';
    if (($settings['login_hours_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAILoginHoursSummary($pdo, $reportData);
        if ($aiSummary !== '') {
            echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
        } else {
            echo $logPrefix . "  AI summary unavailable (see error_log).\n";
        }
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendLoginHoursReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                    'Reporte diario de horas de login enviado automáticamente',
                    'login_hours_report',
                    null,
                    [
                        'recipients_count' => count($recipients),
                        'date'             => $reportData['date'],
                        'totals'           => $reportData['totals'],
                        'ai_enabled'       => ($settings['login_hours_report_claude_enabled'] ?? '0') === '1',
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
    error_log('[CRON LOGIN HOURS REPORT] ' . $e->getMessage());
    exit(1);
}
