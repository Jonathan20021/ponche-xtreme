<?php
/**
 * Cron Job: Daily Workforce (Active vs. Absent) Report
 *
 * Runs at 9 AM (or shift start), takes a snapshot of who is present vs. absent
 * today, counts active / trial / suspended / terminated employees, highlights
 * employees in TRIAL who are absent (critical for 90-day follow-up), and
 * emails the report to HR / supervisors / management.
 *
 * Suggested cron (09:00 GMT-4):
 *   0 9 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_workforce_report.php >> /home2/hhempeos/logs/workforce_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_workforce_report.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON WORKFORCE REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getWorkforceReportSettings($pdo);

    if (($settings['workforce_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    // Skip weekends if configured
    if (($settings['workforce_report_exclude_weekends'] ?? '1') === '1') {
        $dayOfWeek = (int) date('N'); // 1=Mon, 7=Sun
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            echo $logPrefix . "Today is weekend (day $dayOfWeek) and exclude_weekends is ON. Exiting.\n";
            exit(0);
        }
    }

    // Enforce configured time with ±5 min tolerance when run via CLI
    $configuredTime = $settings['workforce_report_time'] ?? '09:00';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 9)) * 60 + (int) ($cfgParts[1] ?? 0);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    $recipients = getWorkforceReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    $targetDate = date('Y-m-d');
    echo $logPrefix . "Building workforce snapshot for $targetDate...\n";

    $reportData = generateDailyWorkforceReport($pdo, $targetDate);

    echo $logPrefix . "  Total eligible:    {$reportData['totals']['total_eligible']}\n";
    echo $logPrefix . "  Present today:     {$reportData['totals']['present_today']} ({$reportData['totals']['present_rate_pct']}%)\n";
    echo $logPrefix . "  Absent today:      {$reportData['totals']['absent_today']} ({$reportData['totals']['absent_rate_pct']}%)\n";
    echo $logPrefix . "  Trial absent:      {$reportData['totals']['trial_absent']}\n";

    // If configured to only send when there are absences, skip clean days
    $onlyWithAbsences = ($settings['workforce_report_only_with_absences'] ?? '0') === '1';
    if ($onlyWithAbsences && $reportData['totals']['absent_today'] === 0) {
        echo $logPrefix . "No absences today and 'only_with_absences' is ON. Skipping send.\n";
        exit(0);
    }

    $aiSummary = '';
    if (($settings['workforce_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAIWorkforceSummary($pdo, $reportData);
        echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendWorkforceReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                    'Reporte diario de fuerza laboral enviado automáticamente',
                    'workforce_report',
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
    error_log('[CRON WORKFORCE REPORT] ' . $e->getMessage());
    exit(1);
}
