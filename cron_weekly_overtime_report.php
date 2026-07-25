<?php
/**
 * Cron: reporte SEMANAL de horas extra acumuladas por colaborador.
 *
 * Sale una vez por semana (por defecto los lunes) con la semana COMPLETA
 * anterior: reportar extras de una semana a medias no le sirve a nadie.
 *
 * Lo dispara cron_daily_reports.php, que ya controla la hora configurada y el
 * marcador de "una vez al día". Aquí solo se valida que sea el día de la semana
 * elegido en settings.php (overtime_report_weekday, 1 = lunes).
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    if (($_GET['cron_key'] ?? '') !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/overtime_reports.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON OVERTIME WEEKLY] ';
$force = in_array('--force', $argv ?? [], true);

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $cfg = overtimeReportSettings($pdo);

    if (($cfg['overtime_report_enabled'] ?? '1') !== '1') {
        echo $logPrefix . "Deshabilitado en settings. Saliendo.\n";
        exit(0);
    }

    // Día de la semana configurado (1 = lunes ... 7 = domingo)
    $wantDay = max(1, min(7, (int) ($cfg['overtime_report_weekday'] ?? 1)));
    $today   = (int) date('N');
    if (!$force && $today !== $wantDay) {
        $nombres = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
        echo $logPrefix . "Solo se manda los {$nombres[$wantDay]} (hoy es {$nombres[$today]}). Saliendo.\n";
        exit(0);
    }

    $recipients = overtimeReportRecipients($pdo, 'overtime_report_recipients');
    if (empty($recipients)) {
        echo $logPrefix . "Sin destinatarios validos configurados. Saliendo.\n";
        exit(0);
    }
    echo $logPrefix . 'Destinatarios: ' . implode(', ', $recipients) . "\n";

    $data = generateWeeklyOvertimeReport($pdo);

    echo $logPrefix . "Semana:            {$data['week_start']} al {$data['week_end']}\n";
    echo $logPrefix . "Con horas extra:   {$data['totals']['employees']}\n";
    echo $logPrefix . "Horas extra:       " . overtimeFormatHours($data['totals']['overtime_hours']) . "\n";
    echo $logPrefix . "Costo estimado:    RD$" . number_format($data['totals']['cost_dop'], 2) . "\n";
    if (!empty($data['no_rate'])) {
        echo $logPrefix . "Sin tarifa cargada: " . count($data['no_rate']) . " colaborador(es)\n";
    }

    $html    = generateWeeklyOvertimeReportHTML($data);
    $subject = 'Horas Extra de la Semana — ' . date('d/m', strtotime($data['week_start']))
             . ' al ' . date('d/m/Y', strtotime($data['week_end']));

    echo $logPrefix . "Enviando correo...\n";
    $sent = sendOvertimeReportEmail($pdo, $subject, $html, $recipients);

    if ($sent) {
        echo $logPrefix . "OK - enviado.\n";
        try {
            require_once __DIR__ . '/lib/logging_functions.php';
            if (function_exists('log_custom_action')) {
                log_custom_action($pdo, 0, 'CRON System', 'system', 'reports', 'send',
                    'Reporte semanal de horas extra enviado automáticamente', 'overtime_report', null, [
                        'week_start' => $data['week_start'],
                        'employees'  => $data['totals']['employees'],
                        'overtime'   => $data['totals']['overtime_hours'],
                        'automated'  => true,
                    ]);
            }
        } catch (Throwable $e) { /* el log no debe tumbar el cron */ }
        exit(0);
    }

    echo $logPrefix . "FALLO al enviar el correo.\n";
    exit(1);
} catch (Throwable $e) {
    echo $logPrefix . 'ERROR: ' . $e->getMessage() . "\n";
    error_log('cron_weekly_overtime_report: ' . $e->getMessage());
    exit(1);
}
