<?php
/**
 * Cron Job: Daily GHL Voice AI Executive Report
 *
 * Suggested cron (08:45 GMT-4):
 *   45 8 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_ghl_report.php >> /home2/hhempeos/logs/ghl_report_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_ghl_report.php';

date_default_timezone_set('America/Santo_Domingo');

@set_time_limit(300);
@ini_set('max_execution_time', '300');

$logPrefix = '[CRON GHL REPORT] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $settings = getGhlReportSettings($pdo);

    if (($settings['ghl_report_enabled'] ?? '0') !== '1') {
        echo $logPrefix . "Report disabled in system_settings. Exiting.\n";
        exit(0);
    }

    if (($settings['ghl_report_exclude_weekends'] ?? '0') === '1') {
        $dow = (int) date('w');
        if ($dow === 0 || $dow === 6) {
            echo $logPrefix . "Weekend skip enabled and today is " . date('l') . ". Exiting.\n";
            exit(0);
        }
    }

    $configuredTime = $settings['ghl_report_time'] ?? '08:45';
    $cfgParts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($cfgParts[0] ?? 8)) * 60 + (int) ($cfgParts[1] ?? 45);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    $diff = abs($nowMinutes - $cfgMinutes);

    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet (now=" . date('H:i')
            . ", configured=$configuredTime, diff={$diff}min). Exiting.\n";
        exit(0);
    }

    $recipients = getGhlReportRecipients($pdo);
    if (empty($recipients)) {
        echo $logPrefix . "No valid recipients configured. Exiting.\n";
        exit(0);
    }
    echo $logPrefix . 'Recipients: ' . implode(', ', $recipients) . "\n";

    $daysBack   = max(1, (int) ($settings['ghl_report_days_back'] ?? 1));
    $targetDate = date('Y-m-d', strtotime("-{$daysBack} day"));
    echo $logPrefix . "Building GHL Voice AI executive report for $targetDate...\n";

    $reportData = generateDailyGhlReport($pdo, $targetDate);

    if (empty($reportData['available'])) {
        echo $logPrefix . "❌ Data unavailable: " . ($reportData['error'] ?? 'unknown') . "\n";
        exit(1);
    }

    $totals = $reportData['totals'];
    echo $logPrefix . "  Integraciones:      " . (int) ($reportData['integrations_count'] ?? 1) . "\n";
    foreach ($reportData['integrations_summary'] ?? [] as $is) {
        if (empty($is['available'])) {
            echo $logPrefix . "    - {$is['integration']}: ERROR — " . ($is['error'] ?? 'sin datos') . "\n";
        } else {
            echo $logPrefix . sprintf("    - %s: %d llamadas, %d agentes, grab %.1f%%\n",
                $is['integration'], $is['total_calls'], $is['unique_agents'], $is['recording_coverage_pct']);
        }
    }
    echo $logPrefix . "  Llamadas (agreg.):  {$totals['total_calls']} (in {$totals['inbound_calls']} / out {$totals['outbound_calls']})\n";
    echo $logPrefix . "  Cobertura grab.:    {$totals['recording_coverage_pct']}%\n";
    echo $logPrefix . "  Cobertura trans.:   {$totals['transcript_coverage_pct']}%\n";
    echo $logPrefix . "  Agentes únicos:     {$totals['unique_agents']}\n";
    echo $logPrefix . "  Sin disposición:    {$totals['no_disposition']} ({$totals['no_disposition_pct']}%)\n";

    $aiSummary = '';
    if (($settings['ghl_report_claude_enabled'] ?? '0') === '1') {
        echo $logPrefix . "Generating AI summary with Claude...\n";
        $aiSummary = generateAIGhlSummary($pdo, $reportData);
        echo $logPrefix . '  AI summary length: ' . strlen($aiSummary) . " chars\n";
    }

    echo $logPrefix . "Sending email...\n";
    $sent = sendGhlReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                    'Reporte ejecutivo GHL Voice AI enviado automáticamente',
                    'ghl_report',
                    null,
                    [
                        'recipients_count' => count($recipients),
                        'date'             => $reportData['date'],
                        'integration'      => $reportData['integration'],
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
    error_log('[CRON GHL REPORT] ' . $e->getMessage());
    exit(1);
}
