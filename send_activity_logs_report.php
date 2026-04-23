<?php
/**
 * Manual Activity Logs Audit Sender
 *
 * Modes:
 *   - send          : full send to configured recipients
 *   - send_preview  : send to a single email provided in request
 */

session_start();

ob_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_activity_logs_report.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$hasAccess = false;
if (function_exists('userHasPermission')) {
    try {
        $hasAccess = userHasPermission('settings') || userHasPermission('activity_logs');
    } catch (Throwable $e) {
        $hasAccess = false;
    }
}
if (!$hasAccess) {
    $allowedRoles = ['ADMIN', 'ADMINISTRATOR', 'DESARROLLADOR', 'IT'];
    $userRole = strtoupper(trim((string) ($_SESSION['role'] ?? '')));
    if (in_array($userRole, $allowedRoles, true)) {
        $hasAccess = true;
    }
}
if (!$hasAccess) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'No tienes permisos para esta acción (requiere rol de Administrador o IT)',
        'debug'   => ['role_in_session' => $_SESSION['role'] ?? null],
    ]);
    exit;
}

$mode = $_POST['mode'] ?? ($_GET['mode'] ?? 'send');

try {
    $previewEmail = trim((string) ($_POST['preview_email'] ?? ''));
    $targetDate   = trim((string) ($_POST['date'] ?? '')) ?: date('Y-m-d', strtotime('yesterday'));

    if ($mode === 'send_preview') {
        if ($previewEmail === '' || !filter_var($previewEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Correo de prueba inválido']);
            exit;
        }
        $recipients = [$previewEmail];
    } else {
        $recipients = getActivityLogsReportRecipients($pdo);
        if (empty($recipients)) {
            echo json_encode(['success' => false, 'error' => 'No hay destinatarios configurados.']);
            exit;
        }
    }

    $reportData = generateDailyActivityLogsReport($pdo, $targetDate);

    $aiSummary = '';
    $settings = getActivityLogsReportSettings($pdo);
    if (($settings['activity_logs_report_claude_enabled'] ?? '0') === '1') {
        $aiSummary = generateAIActivityLogsSummary($pdo, $reportData);
    }

    $sent = sendActivityLogsReportByEmail($pdo, $reportData, $recipients, $aiSummary);

    if (!$sent) {
        echo json_encode(['success' => false, 'error' => 'Error al enviar el reporte. Revisa los logs.']);
        exit;
    }

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
                'Reporte de auditoría de actividad enviado manualmente',
                'activity_logs_report',
                null,
                [
                    'mode'             => $mode,
                    'recipients_count' => count($recipients),
                    'date'             => $reportData['date'],
                    'totals'           => $reportData['totals'],
                    'ai_generated'     => $aiSummary !== '',
                ]
            );
        }
    } catch (Exception $e) {
        error_log('send_activity_logs_report log error: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Reporte enviado a ' . count($recipients) . ' destinatario(s).',
        'data'    => [
            'recipients_count' => count($recipients),
            'date'             => $reportData['date'],
            'totals'           => $reportData['totals'],
            'ai_generated'     => $aiSummary !== '',
        ],
    ]);

} catch (Exception $e) {
    error_log('send_activity_logs_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
}
