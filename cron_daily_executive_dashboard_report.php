<?php
/**
 * Cron Job: Executive Dashboard Daily Closing Report ("Cierre del día")
 *
 * Runs each evening (default 19:00 GMT-4 / 7 PM) with the snapshot of the
 * Executive Dashboard for the current day: asistencia, horas pagadas,
 * costo USD/DOP, campañas activas, fuerza laboral y departamentos.
 *
 * Suggested cron (19:00 GMT-4):
 *   0 19 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_executive_dashboard_report.php >> /home2/hhempeos/logs/executive_dashboard_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_executive_dashboard_report.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON EXECUTIVE DASHBOARD REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getExecutiveDashboardReportSettings($pdo);

    if (($settings['executive_dashboard_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    if (($settings['executive_dashboard_report_exclude_weekends'] ?? '0') === '1') {
        $dow = (int) date('N');
        if ($dow >= 6) {
            echo $logPrefix . "Weekend (dow=$dow) and exclude_weekends enabled. Exiting.\n";
            exit(0);
        }
    }

    $configuredTime = $settings['executive_dashboard_report_time'] ?? '19:00';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 19)) * 60 + (int) ($cfgParts[1] ?? 0);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    $recipients = getExecutiveDashboardReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    $targetDate = date('Y-m-d');
    echo $logPrefix . "Building executive closing for $targetDate...\n";

    $reportData = generateDailyExecutiveDashboardReport($pdo, $targetDate);

    $t = $reportData['totals'] ?? [];
    echo $logPrefix . "  Asistencia:        " . (int) ($t['worked_today']  ?? 0) . " / " . (int) ($t['eligible'] ?? 0)
        . " (" . (float) ($t['attendance_rate_pct'] ?? 0) . "%)\n";
    echo $logPrefix . "  Horas pagadas:     " . number_format((float) ($t['hours_total']     ?? 0), 2) . " h\n";
    echo $logPrefix . "  Costo USD:         $" . number_format((float) ($t['earnings_usd']    ?? 0), 2) . "\n";
    echo $logPrefix . "  Costo DOP:         RD$" . number_format((float) ($t['earnings_dop']  ?? 0), 2) . "\n";
    echo $logPrefix . "  Costo USD equiv.:  $" . number_format((float) ($t['earnings_combined_usd'] ?? 0), 2) . "\n";
    echo $logPrefix . "  Campañas activas:  " . count($reportData['campaigns'] ?? []) . "\n";

    $aiSummary = '';
    if (($settings['executive_dashboard_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAIExecutiveDashboardSummary($pdo, $reportData);
        echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendExecutiveDashboardReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                    'Cierre ejecutivo del día enviado automáticamente',
                    'executive_dashboard_report',
                    null,
                    [
                        'recipients_count' => count($recipients),
                        'date'             => $reportData['date'],
                        'totals'           => $reportData['totals'] ?? [],
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
    error_log('[CRON EXECUTIVE DASHBOARD REPORT] ' . $e->getMessage());
    exit(1);
}
