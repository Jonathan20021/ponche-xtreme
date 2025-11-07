<?php
/**
 * Manual Absence Report Sender
 * Permite enviar manualmente el reporte de ausencias desde settings.php
 */

session_start();
require_once 'db.php';
require_once 'lib/daily_absence_report.php';

// Check authentication and permissions
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Check if user has permission (only admin, IT, HR roles)
$allowedRoles = ['administrator', 'desarrollador', 'it', 'hr', 'recursos humanos'];
$userRole = strtolower($_SESSION['role'] ?? '');

if (!in_array($userRole, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tienes permisos para enviar reportes']);
    exit;
}

try {
    // Get recipients
    $recipients = getReportRecipients($pdo);
    
    if (empty($recipients)) {
        echo json_encode([
            'success' => false, 
            'error' => 'No hay destinatarios configurados. Configure al menos un correo electrónico en la configuración.'
        ]);
        exit;
    }
    
    // Generate report
    $reportData = generateDailyAbsenceReport($pdo);
    
    // Send email
    $sent = sendReportByEmail($pdo, $reportData, $recipients);
    
    if ($sent) {
        // Log the action
        require_once 'lib/logging_functions.php';
        log_custom_action(
            $pdo,
            $_SESSION['user_id'],
            $_SESSION['full_name'] ?? $_SESSION['username'],
            $_SESSION['role'],
            'reports',
            'send',
            "Reporte de ausencias enviado manualmente",
            'absence_report',
            null,
            [
                'recipients_count' => count($recipients),
                'total_absences' => $reportData['total_absences'],
                'date' => $reportData['date']
            ]
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Reporte enviado exitosamente a ' . count($recipients) . ' destinatario(s)',
            'data' => [
                'recipients_count' => count($recipients),
                'total_employees' => $reportData['total_employees'],
                'total_absences' => $reportData['total_absences'],
                'absences_without_justification' => count($reportData['absences_without_justification']),
                'absences_with_justification' => count($reportData['absences_with_justification'])
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Error al enviar el reporte. Revise los logs para más detalles.'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error sending manual absence report: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al generar el reporte: ' . $e->getMessage()
    ]);
}
