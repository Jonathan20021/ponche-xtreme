<?php
/**
 * Cron: control diario de horas.
 *
 * Hace dos cosas cada mañana:
 *   1. Barre el día anterior levantando excepciones (jornadas sin salida,
 *      jornadas largas, gente sin tarifa, días vencidos sin cerrar).
 *   2. Envía el resumen de impacto económico: cuánto se generó, qué se modificó,
 *      qué está pendiente de cierre y qué excepciones requieren revisión.
 *
 * Lo dispara cron_daily_reports.php (clave "timesheet"), que controla la hora y
 * el "una vez al día". No necesita tarea de Windows propia.
 *
 * Uso manual:
 *   php cron_daily_timesheet_report.php
 *   php cron_daily_timesheet_report.php --date=2026-08-12
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    if (($_GET['cron_key'] ?? '') !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/timesheet_control.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON TIMESHEET] ';

$targetDate = null;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--date=') === 0) {
        $candidate = substr($arg, 7);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            $targetDate = $candidate;
        }
    }
}
if ($targetDate === null && !empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $targetDate = $_GET['date'];
}

try {
    echo $logPrefix . 'Inicio ' . date('Y-m-d H:i:s') . "\n";

    if (!timesheetControlEnabled($pdo)) {
        echo $logPrefix . "Control de horas desactivado o sin instalar. Saliendo.\n";
        exit(0);
    }

    if (timesheetSetting($pdo, 'timesheet_report_enabled', '1') !== '1') {
        echo $logPrefix . "Reporte deshabilitado en Ajustes. Saliendo.\n";
        exit(0);
    }

    $date = $targetDate ?: date('Y-m-d', strtotime('-1 day'));

    // 1. Barrido de excepciones del día
    $abiertas = timesheetDetectExceptions($pdo, $date);
    echo $logPrefix . "Excepciones abiertas en $date: $abiertas\n";

    // Los días anteriores que quedaron sin cerrar también se revisan: es donde
    // se acumula el atraso que después bloquea la nómina.
    for ($i = 2; $i <= 7; $i++) {
        $prev = date('Y-m-d', strtotime("-$i day"));
        timesheetDetectExceptions($pdo, $prev);
    }

    // 2. Resumen económico
    $impacto = timesheetDailyImpact($pdo, $date);
    $filas   = timesheetDayRows($pdo, $date);

    $pendientes = array_values(array_filter($filas, static function (array $r): bool {
        return !in_array($r['status'], ['CLOSED', 'LOCKED'], true);
    }));

    $excepciones = timesheetOpenExceptions($pdo, date('Y-m-d', strtotime('-14 days')), $date);

    $recipients = trim(timesheetSetting($pdo, 'timesheet_report_recipients', ''));
    if ($recipients === '') {
        echo $logPrefix . "Sin destinatarios configurados. Solo se corrió la detección.\n";
        exit(0);
    }

    $dop = static fn(float $n): string => 'RD$ ' . number_format($n, 2);
    $hm  = static fn(int $s): string => sprintf('%d:%02d', intdiv(max(0, $s), 3600), intdiv(max(0, $s) % 3600, 60));

    $fechaLarga = date('d/m/Y', strtotime($date));

    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1f2937;max-width:720px">';
    $html .= '<h2 style="margin:0 0 4px">Control de horas &mdash; ' . $fechaLarga . '</h2>';
    $html .= '<p style="margin:0 0 18px;color:#6b7280;font-size:13px">'
        . 'Cuánto se generó, qué se modificó, qué está pendiente y qué requiere revisión.</p>';

    $html .= '<table cellpadding="10" cellspacing="0" style="width:100%;border-collapse:collapse;margin-bottom:18px">';
    $html .= '<tr>'
        . '<td style="border:1px solid #e5e7eb"><div style="font-size:11px;color:#6b7280;text-transform:uppercase">Generado</div>'
        . '<div style="font-size:20px;font-weight:700;color:#047857">' . $dop((float) $impacto['generated_amount']) . '</div>'
        . '<div style="font-size:12px;color:#6b7280">' . $hm((int) $impacto['generated_seconds']) . ' h · '
        . (int) $impacto['people'] . ' colaboradores</div></td>'
        . '<td style="border:1px solid #e5e7eb"><div style="font-size:11px;color:#6b7280;text-transform:uppercase">Modificado</div>'
        . '<div style="font-size:20px;font-weight:700;color:#b45309">'
        . ($impacto['modified_amount'] >= 0 ? '+' : '-') . $dop(abs((float) $impacto['modified_amount'])) . '</div>'
        . '<div style="font-size:12px;color:#6b7280">' . (int) $impacto['adjustments'] . ' ajuste(s)</div></td>'
        . '</tr><tr>'
        . '<td style="border:1px solid #e5e7eb"><div style="font-size:11px;color:#6b7280;text-transform:uppercase">Pendiente de cierre</div>'
        . '<div style="font-size:20px;font-weight:700">' . count($pendientes) . '</div>'
        . '<div style="font-size:12px;color:#6b7280">' . (int) $impacto['closed_days'] . ' ya cerrado(s)</div></td>'
        . '<td style="border:1px solid #e5e7eb"><div style="font-size:11px;color:#6b7280;text-transform:uppercase">Excepciones abiertas</div>'
        . '<div style="font-size:20px;font-weight:700;color:' . (count($excepciones) > 0 ? '#b91c1c' : '#111827') . '">'
        . count($excepciones) . '</div>'
        . '<div style="font-size:12px;color:#6b7280">últimos 14 días</div></td>'
        . '</tr></table>';

    if (!empty($pendientes)) {
        $html .= '<h3 style="margin:0 0 6px;font-size:15px">Días sin cerrar (' . count($pendientes) . ')</h3>';
        $html .= '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:18px">';
        $html .= '<tr style="background:#f3f4f6"><th align="left">Colaborador</th><th align="right">Horas</th>'
            . '<th align="right">Valor</th><th align="center">Etapa</th></tr>';
        foreach (array_slice($pendientes, 0, 40) as $r) {
            $html .= '<tr style="border-bottom:1px solid #e5e7eb">'
                . '<td>' . htmlspecialchars($r['full_name']) . '</td>'
                . '<td align="right">' . $hm((int) $r['work_seconds']) . '</td>'
                . '<td align="right">' . ($r['rate'] > 0 ? $dop((float) $r['amount']) : 'sin tarifa') . '</td>'
                . '<td align="center">' . htmlspecialchars($r['status']) . '</td>'
                . '</tr>';
        }
        if (count($pendientes) > 40) {
            $html .= '<tr><td colspan="4" style="color:#6b7280">… y ' . (count($pendientes) - 40) . ' más.</td></tr>';
        }
        $html .= '</table>';
    }

    if (!empty($excepciones)) {
        $html .= '<h3 style="margin:0 0 6px;font-size:15px">Excepciones que requieren revisión</h3>';
        $html .= '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px">';
        $html .= '<tr style="background:#f3f4f6"><th align="left">Severidad</th><th align="left">Colaborador</th>'
            . '<th align="left">Fecha</th><th align="left">Qué pasa</th><th align="right">En juego</th></tr>';
        foreach (array_slice($excepciones, 0, 40) as $e) {
            $color = $e['severity'] === 'CRITICAL' ? '#b91c1c' : ($e['severity'] === 'HIGH' ? '#c2410c' : '#6b7280');
            $html .= '<tr style="border-bottom:1px solid #e5e7eb">'
                . '<td style="color:' . $color . ';font-weight:600">' . htmlspecialchars($e['severity']) . '</td>'
                . '<td>' . htmlspecialchars((string) ($e['full_name'] ?: ('#' . $e['user_id']))) . '</td>'
                . '<td>' . date('d/m/Y', strtotime($e['work_date'])) . '</td>'
                . '<td>' . htmlspecialchars($e['title']) . '</td>'
                . '<td align="right">' . ($e['amount_dop'] !== null ? $dop((float) $e['amount_dop']) : '—') . '</td>'
                . '</tr>';
        }
        if (count($excepciones) > 40) {
            $html .= '<tr><td colspan="5" style="color:#6b7280">… y ' . (count($excepciones) - 40) . ' más.</td></tr>';
        }
        $html .= '</table>';
    }

    if (empty($pendientes) && empty($excepciones)) {
        $html .= '<p style="padding:14px;background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46">'
            . 'Todo el día está cerrado y sin excepciones abiertas.</p>';
    }

    $html .= '<p style="margin-top:20px;font-size:12px;color:#6b7280">'
        . 'Ponche Xtreme &mdash; Control de Horas. Los montos son estimaciones a la tarifa '
        . 'vigente del colaborador; el cálculo que paga sigue siendo el de Nómina.</p></div>';

    require_once __DIR__ . '/lib/email_functions.php';

    $asunto = 'Control de horas ' . $fechaLarga
        . ' — ' . $dop((float) $impacto['generated_amount']) . ' generados'
        . (count($excepciones) > 0 ? ' · ' . count($excepciones) . ' excepción(es)' : '');

    $enviados = 0;
    foreach (preg_split('/[,;]+/', $recipients) as $to) {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if (function_exists('sendEmail') && sendEmail($to, $asunto, $html)) {
            $enviados++;
            echo $logPrefix . "Enviado a $to\n";
        } else {
            echo $logPrefix . "FALLO al enviar a $to\n";
        }
    }

    echo $logPrefix . "Listo. Enviados: $enviados\n";
    exit(0);

} catch (Throwable $e) {
    echo $logPrefix . 'ERROR: ' . $e->getMessage() . "\n";
    error_log('cron_daily_timesheet_report: ' . $e->getMessage());
    exit(1);
}
