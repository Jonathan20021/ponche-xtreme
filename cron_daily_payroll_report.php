<?php
/**
 * Cron Job: Daily Payroll Month-to-Date Report
 *
 * Runs each morning, computes the accumulated payroll for administrative users
 * from the 1st of the current month up to yesterday, generates an Excel
 * attachment matching admin_daily_excel.php format, and emails it to the
 * configured HR recipients — optionally with a Claude-generated executive
 * summary.
 *
 * Suggested cron (08:00 GMT-4):
 *   0 8 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_payroll_report.php >> /home2/hhempeos/logs/payroll_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_payroll_report.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON PAYROLL REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getPayrollReportSettings($pdo);

    if (($settings['payroll_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    // Enforce configured time with ±5 min tolerance when run via CLI
    $configuredTime = $settings['payroll_report_time'] ?? '08:00';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 8)) * 60 + (int) ($cfgParts[1] ?? 0);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    $recipients = getPayrollReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    // Resolve date range from period mode
    $periodMode = $settings['payroll_report_period_mode'] ?? 'month_to_yesterday';
    [$startDate, $endDate] = resolvePayrollPeriod($periodMode);
    echo $logPrefix . "Period mode: $periodMode → $startDate to $endDate\n";

    $reportData = generateDailyPayrollReport($pdo, $startDate, $endDate);

    echo $logPrefix . "  Rows:             {$reportData['totals']['rows']}\n";
    echo $logPrefix . "  Collab. w/ data:  {$reportData['totals']['users_with_rows']}\n";
    echo $logPrefix . "  Hours:            " . number_format($reportData['totals']['hours'], 2) . "\n";
    echo $logPrefix . "  Pay USD:          $" . number_format($reportData['totals']['pay_usd'], 2) . "\n";
    echo $logPrefix . "  Pay DOP:          RD$" . number_format($reportData['totals']['pay_dop'], 2) . "\n";

    $aiSummary = '';
    if (($settings['payroll_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAIPayrollSummary($pdo, $reportData);
        echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendPayrollReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                    'Reporte diario de nómina acumulada enviado automáticamente',
                    'payroll_report',
                    null,
                    [
                        'recipients_count' => count($recipients),
                        'start_date'       => $reportData['start_date'],
                        'end_date'         => $reportData['end_date'],
                        'totals'           => $reportData['totals'],
                        'projection'       => $reportData['projection'],
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
    error_log('[CRON PAYROLL REPORT] ' . $e->getMessage());
    exit(1);
}
