<?php
/**
 * Manual Login Hours Report Sender
 *
 * Modes:
 *   - send           : full send to configured recipients
 *   - send_preview   : send to a single email provided in request (test)
 *   - test_claude    : just tests the Anthropic API credentials and returns the answer
 *
 * Invoked from settings.php via fetch().
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_login_hours_report.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Permission gate: the user must have access to the 'settings' section.
// Anyone who can open settings.php to configure this report can also trigger it.
// Falls back to a role list if the permission system is unavailable.
$hasAccess = false;
if (function_exists('userHasPermission')) {
    try {
        $hasAccess = userHasPermission('settings');
    } catch (Throwable $e) {
        $hasAccess = false;
    }
}
if (!$hasAccess) {
    // Defensive fallback by role (case-insensitive)
    $allowedRoles = ['ADMIN', 'ADMINISTRATOR', 'DESARROLLADOR', 'IT', 'HR', 'RECURSOS HUMANOS', 'DIRECTOR', 'GENERALMANAGER', 'OPERATIONSMANAGER', 'GERENTEDEOPERACIONES', 'ENCARGADODEGESTIONHUMANA'];
    $userRole = strtoupper(trim((string) ($_SESSION['role'] ?? '')));
    if (in_array($userRole, $allowedRoles, true)) {
        $hasAccess = true;
    }
}
if (!$hasAccess) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'No tienes permisos para esta acción',
        'debug'   => ['role_in_session' => $_SESSION['role'] ?? null],
    ]);
    exit;
}

$mode = $_POST['mode'] ?? ($_GET['mode'] ?? 'send');

try {
    // ---- test_claude ----
    if ($mode === 'test_claude') {
        $settings = getLoginHoursReportSettings($pdo);
        $apiKey = (string) ($_POST['api_key'] ?? $settings['login_hours_report_claude_api_key'] ?? '');
        $model  = (string) ($_POST['model']   ?? $settings['login_hours_report_claude_model']   ?? 'claude-sonnet-4-6');

        $result = testClaudeAPIConnection($apiKey, $model);

        echo json_encode([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Conexión con Claude API correcta. Respuesta: ' . $result['content']
                : ('Error: ' . ($result['error'] ?? 'desconocido')),
            'data' => [
                'http_code' => $result['http_code'],
                'usage'     => $result['usage'],
            ],
        ]);
        exit;
    }

    // ---- send / send_preview ----
    $previewEmail = trim((string) ($_POST['preview_email'] ?? ''));
    $targetDate   = trim((string) ($_POST['date'] ?? '')) ?: date('Y-m-d', strtotime('yesterday'));

    if ($mode === 'send_preview') {
        if ($previewEmail === '' || !filter_var($previewEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Correo de prueba inválido']);
            exit;
        }
        $recipients = [$previewEmail];
    } else {
        $recipients = getLoginHoursReportRecipients($pdo);
        if (empty($recipients)) {
            echo json_encode([
                'success' => false,
                'error'   => 'No hay destinatarios configurados. Agrega al menos un correo en la configuración.',
            ]);
            exit;
        }
    }

    $reportData = generateDailyLoginHoursReport($pdo, $targetDate);

    $aiSummary = '';
    $settings = getLoginHoursReportSettings($pdo);
    if (($settings['login_hours_report_claude_enabled'] ?? '0') === '1') {
        $aiSummary = generateAILoginHoursSummary($pdo, $reportData);
    }

    $sent = sendLoginHoursReportByEmail($pdo, $reportData, $recipients, $aiSummary);

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
                'Reporte diario de horas de login enviado manualmente',
                'login_hours_report',
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
        error_log('send_login_hours_report log error: ' . $e->getMessage());
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
    error_log('send_login_hours_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
}
