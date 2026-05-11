<?php
/**
 * Cron Job: Daily Recruitment Report
 *
 * Captures a snapshot of the recruitment pipeline — postings, applications by status,
 * top AI-scored candidates, upcoming interviews, bottlenecks, recent hires and
 * rejections, conversion metrics and time-to-hire — and emails the report to
 * configured recipients. Optionally enriches with a Claude executive summary.
 *
 * Suggested cron (08:30 GMT-4):
 *   30 8 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_recruitment_report.php >> /home2/hhempeos/logs/recruitment_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_recruitment_report.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON RECRUITMENT REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getRecruitmentReportSettings($pdo);

    if (($settings['recruitment_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    // Skip weekends if configured
    if (($settings['recruitment_report_exclude_weekends'] ?? '1') === '1') {
        $dayOfWeek = (int) date('N');
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            echo $logPrefix . "Today is weekend (day $dayOfWeek) and exclude_weekends is ON. Exiting.\n";
            exit(0);
        }
    }

    // Enforce configured time with ±5 min tolerance when run via CLI
    $configuredTime = $settings['recruitment_report_time'] ?? '08:30';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 8)) * 60 + (int) ($cfgParts[1] ?? 30);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    $recipients = getRecruitmentReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    $targetDate = date('Y-m-d');
    echo $logPrefix . "Building recruitment snapshot for $targetDate...\n";

    $reportData = generateDailyRecruitmentReport($pdo, $targetDate);

    echo $logPrefix . "  Posiciones activas:   {$reportData['totals']['active_postings']}\n";
    echo $logPrefix . "  En pipeline:          {$reportData['totals']['pipeline_active']}\n";
    echo $logPrefix . "  Nuevas hoy:           {$reportData['totals']['today_new_apps']}\n";
    echo $logPrefix . "  Contratados periodo:  {$reportData['totals']['period_hired']}\n";
    echo $logPrefix . "  Cuellos de botella:   {$reportData['totals']['bottlenecks_count']}\n";
    echo $logPrefix . "  Top IA candidatos:    {$reportData['totals']['top_ai_candidates']}\n";
    echo $logPrefix . "  Próximas entrevistas: {$reportData['totals']['upcoming_interviews']}\n";

    // If configured to only send when there is activity, skip dead days
    $onlyWithActivity = ($settings['recruitment_report_only_with_activity'] ?? '0') === '1';
    if ($onlyWithActivity && empty($reportData['totals']['has_activity'])) {
        echo $logPrefix . "No activity in window and 'only_with_activity' is ON. Skipping send.\n";
        exit(0);
    }

    $aiSummary = '';
    if (($settings['recruitment_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAIRecruitmentSummary($pdo, $reportData);
        echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendRecruitmentReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                    'Reporte diario de reclutamiento enviado automáticamente',
                    'recruitment_report',
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
    error_log('[CRON RECRUITMENT REPORT] ' . $e->getMessage());
    exit(1);
}
