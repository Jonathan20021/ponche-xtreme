<?php
/**
 * Cron: reporte DIARIO de quienes excedieron las 8 horas el día anterior.
 *
 * El umbral es configurable (over8h_report_threshold_hours), por si algún día
 * la jornada cambia. Las horas salen de la misma función que paga la nómina, así
 * que respeta si el colaborador se mide por ponche o por Vicidial.
 *
 * Lo dispara cron_daily_reports.php, que controla la hora y el "una vez al día".
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

$logPrefix = '[CRON OVER 8H] ';

// Permite regenerar un día concreto: --date=2026-07-24
$targetDate = null;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--date=') === 0) {
        $candidate = substr($arg, 7);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            $targetDate = $candidate;
        }
    }
}

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $cfg = overtimeReportSettings($pdo);

    if (($cfg['over8h_report_enabled'] ?? '1') !== '1') {
        echo $logPrefix . "Deshabilitado en settings. Saliendo.\n";
        exit(0);
    }

    $date = $targetDate ?: date('Y-m-d', strtotime('-1 day'));

    // Si el día a revisar cae en fin de semana, se RETROCEDE al último día
    // laborable en vez de no mandar nada.
    //
    // Antes salía sin enviar, y eso dejaba al viernes sin reportar nunca: el
    // sábado el despachador no corre (fin de semana) y el lunes tocaba revisar
    // el domingo, que también se descartaba. El viernes — justo el día que más
    // interesa — se perdía todas las semanas.
    if ($targetDate === null && ($cfg['over8h_report_exclude_weekends'] ?? '1') === '1') {
        $saltos = 0;
        while ((int) date('N', strtotime($date)) >= 6 && $saltos < 7) {
            $date = date('Y-m-d', strtotime($date . ' -1 day'));
            $saltos++;
        }
        if ($saltos > 0) {
            echo $logPrefix . "El dia anterior era fin de semana; se revisa el ultimo dia laborable ($date).\n";
        }
    }

    $recipients = overtimeReportRecipients($pdo, 'over8h_report_recipients');
    if (empty($recipients)) {
        echo $logPrefix . "Sin destinatarios validos configurados. Saliendo.\n";
        exit(0);
    }
    echo $logPrefix . 'Destinatarios: ' . implode(', ', $recipients) . "\n";

    $data = generateDailyOver8hReport($pdo, $date);

    echo $logPrefix . "Dia revisado:   {$data['date']}\n";
    echo $logPrefix . "Umbral:         " . rtrim(rtrim(number_format($data['threshold'], 1), '0'), '.') . " h\n";
    echo $logPrefix . "Excedieron:     {$data['totals']['employees']}\n";
    echo $logPrefix . "Exceso total:   " . overtimeFormatHours($data['totals']['excess_hours']) . "\n";

    $html    = generateDailyOver8hReportHTML($data);
    $subject = 'Jornadas de más de ' . rtrim(rtrim(number_format($data['threshold'], 1), '0'), '.')
             . ' horas — ' . date('d/m/Y', strtotime($data['date']))
             . ' (' . $data['totals']['employees'] . ')';

    echo $logPrefix . "Enviando correo...\n";
    $sent = sendOvertimeReportEmail($pdo, $subject, $html, $recipients);

    if ($sent) {
        echo $logPrefix . "OK - enviado.\n";
        try {
            require_once __DIR__ . '/lib/logging_functions.php';
            if (function_exists('log_custom_action')) {
                log_custom_action($pdo, 0, 'CRON System', 'system', 'reports', 'send',
                    'Reporte diario de jornadas mayores a 8 horas enviado automáticamente',
                    'over8h_report', null, [
                        'date'      => $data['date'],
                        'employees' => $data['totals']['employees'],
                        'automated' => true,
                    ]);
            }
        } catch (Throwable $e) { /* el log no debe tumbar el cron */ }
        exit(0);
    }

    echo $logPrefix . "FALLO al enviar el correo.\n";
    exit(1);
} catch (Throwable $e) {
    echo $logPrefix . 'ERROR: ' . $e->getMessage() . "\n";
    error_log('cron_daily_over8h_report: ' . $e->getMessage());
    exit(1);
}
