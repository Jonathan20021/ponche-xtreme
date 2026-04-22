<?php
/**
 * Manual Payroll Report Sender
 *
 * Modes:
 *   - send          : full send to configured recipients (with attachment)
 *   - send_preview  : send to a single email provided in request
 *   - download_xls  : returns the .xls file directly (for preview)
 *
 * Invoked from settings.php via fetch().
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_payroll_report.php';

$mode = $_POST['mode'] ?? ($_GET['mode'] ?? 'send');

// `download_xls` streams a file directly, so don't set JSON content type yet.
if ($mode !== 'download_xls') {
    header('Content-Type: application/json; charset=utf-8');
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    if ($mode !== 'download_xls') {
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
    } else {
        echo 'No autenticado';
    }
    exit;
}

// Permission gate — same pattern as the other automations
$hasAccess = false;
if (function_exists('userHasPermission')) {
    try {
        $hasAccess = userHasPermission('settings') || userHasPermission('hr_report');
    } catch (Throwable $e) {
        $hasAccess = false;
    }
}
if (!$hasAccess) {
    $allowedRoles = ['ADMIN', 'ADMINISTRATOR', 'DESARROLLADOR', 'IT', 'HR', 'RECURSOS HUMANOS', 'DIRECTOR', 'GENERALMANAGER', 'OPERATIONSMANAGER', 'GERENTEDEOPERACIONES', 'ENCARGADODEGESTIONHUMANA'];
    $userRole = strtoupper(trim((string) ($_SESSION['role'] ?? '')));
    if (in_array($userRole, $allowedRoles, true)) {
        $hasAccess = true;
    }
}
if (!$hasAccess) {
    http_response_code(403);
    if ($mode !== 'download_xls') {
        echo json_encode(['success' => false, 'error' => 'No tienes permisos para esta acción', 'debug' => ['role_in_session' => $_SESSION['role'] ?? null]]);
    } else {
        echo 'Permiso denegado';
    }
    exit;
}

try {
    // Resolve period
    $settings = getPayrollReportSettings($pdo);
    $overrideStart = trim((string) ($_POST['start_date'] ?? $_GET['start_date'] ?? ''));
    $overrideEnd   = trim((string) ($_POST['end_date']   ?? $_GET['end_date']   ?? ''));
    [$startDate, $endDate] = resolvePayrollPeriod(
        $settings['payroll_report_period_mode'] ?? 'month_to_yesterday',
        $overrideStart ?: null,
        $overrideEnd   ?: null
    );

    $reportData = generateDailyPayrollReport($pdo, $startDate, $endDate);

    // ---- download_xls ----
    if ($mode === 'download_xls') {
        $excelPath = writePayrollExcelFile($reportData);
        $fileName  = basename($excelPath);
        $ext       = strtolower(pathinfo($excelPath, PATHINFO_EXTENSION));
        $mime      = $ext === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/vnd.ms-excel';
        header('Content-Type: ' . $mime);
        header("Content-Disposition: attachment; filename=\"$fileName\"");
        header('Cache-Control: max-age=0');
        header('Content-Length: ' . filesize($excelPath));
        readfile($excelPath);
        @unlink($excelPath);
        exit;
    }

    // ---- send / send_preview ----
    $previewEmail = trim((string) ($_POST['preview_email'] ?? ''));
    if ($mode === 'send_preview') {
        if ($previewEmail === '' || !filter_var($previewEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Correo de prueba inválido']);
            exit;
        }
        $recipients = [$previewEmail];
    } else {
        $recipients = getPayrollReportRecipients($pdo);
        if (empty($recipients)) {
            echo json_encode(['success' => false, 'error' => 'No hay destinatarios configurados.']);
            exit;
        }
    }

    $aiSummary = '';
    if (($settings['payroll_report_claude_enabled'] ?? '0') === '1') {
        $aiSummary = generateAIPayrollSummary($pdo, $reportData);
    }

    $sent = sendPayrollReportByEmail($pdo, $reportData, $recipients, $aiSummary);

    if (!$sent) {
        echo json_encode(['success' => false, 'error' => 'Error al enviar el reporte. Revisa los logs.']);
        exit;
    }

    // Log
    try {
        require_once __DIR__ . '/lib/logging_functions.php';
        if (function_exists('log_custom_action')) {
            log_custom_action(
                $pdo,
                (int) $_SESSION['user_id'],
                $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Unknown'),
                $_SESSION['role'] ?? 'unknown',
                'reports',
                'send',
                'Corte diario de nómina enviado manualmente',
                'payroll_report',
                null,
                [
                    'mode'             => $mode,
                    'recipients_count' => count($recipients),
                    'start_date'       => $reportData['start_date'],
                    'end_date'         => $reportData['end_date'],
                    'totals'           => $reportData['totals'],
                    'ai_generated'     => $aiSummary !== '',
                ]
            );
        }
    } catch (Exception $e) {
        error_log('send_payroll_report log error: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Corte enviado a ' . count($recipients) . ' destinatario(s) con Excel adjunto.',
        'data'    => [
            'recipients_count' => count($recipients),
            'start_date'       => $reportData['start_date'],
            'end_date'         => $reportData['end_date'],
            'totals'           => $reportData['totals'],
            'projection'       => $reportData['projection'],
            'ai_generated'     => $aiSummary !== '',
        ],
    ]);

} catch (Exception $e) {
    error_log('send_payroll_report error: ' . $e->getMessage());
    http_response_code(500);
    if ($mode !== 'download_xls') {
        echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
    } else {
        echo 'Error: ' . $e->getMessage();
    }
}
