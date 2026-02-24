<?php
// Deshabilitar visualización de errores en producción
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Limpiar cualquier salida previa
ob_start();

session_start();
require_once '../db.php';

// Limpiar buffer y descartar cualquier salida generada
ob_end_clean();

// Iniciar nuevo buffer para capturar solo JSON
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    ob_end_flush();
    exit;
}

// Check permissions for quick assign
if (!userHasPermission('hr_employees') && !userHasPermission('manage_campaigns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tiene permiso para asignar campañas']);
    ob_end_flush();
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'quick_assign':
            $userId = $_SESSION['user_id'];
            $employeeId = intval($_POST['employee_id'] ?? 0);
            $campaignId = !empty($_POST['campaign_id']) && (int) $_POST['campaign_id'] > 0 ? intval($_POST['campaign_id']) : null;
            $supervisorId = !empty($_POST['supervisor_id']) && (int) $_POST['supervisor_id'] > 0 ? intval($_POST['supervisor_id']) : null;

            if (!$employeeId) {
                throw new Exception('ID de empleado inválido');
            }

            // Verify employee exists
            $checkStmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
            $checkStmt->execute([$employeeId]);
            if (!$checkStmt->fetchColumn()) {
                throw new Exception('Empleado no encontrado');
            }

            $updateStmt = $pdo->prepare("
                UPDATE employees
                SET campaign_id = :campaign_id,
                    supervisor_id = :supervisor_id,
                    updated_at = NOW()
                WHERE id = :employee_id
            ");
            $updateStmt->bindValue(':campaign_id', $campaignId, $campaignId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $updateStmt->bindValue(':supervisor_id', $supervisorId, $supervisorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $updateStmt->bindValue(':employee_id', $employeeId, PDO::PARAM_INT);

            if (!$updateStmt->execute()) {
                throw new Exception('Error al actualizar empleado');
            }

            // Handle schedule assignment if provided
            $scheduleTemplateId = !empty($_POST['schedule_template_id']) ? (int) $_POST['schedule_template_id'] : null;
            if ($scheduleTemplateId) {
                // Get user_id for the employee
                $userStmt = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
                $userStmt->execute([$employeeId]);
                $userLocalId = $userStmt->fetchColumn();

                if ($userLocalId) {
                    // Deactivate old schedules
                    deactivateEmployeeSchedules($pdo, $employeeId);

                    // Create new schedule from template
                    createEmployeeScheduleFromTemplate($pdo, $employeeId, (int) $userLocalId, $scheduleTemplateId);
                }
            }

            // Log the action
            $description = "Asignación rápida para empleado ID: {$employeeId}";
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, user_name, user_role, module, action, description, created_at)
                VALUES (?, ?, ?, 'employees', 'employee_assign', ?, NOW())
            ");
            $logStmt->execute([
                $userId,
                $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Usuario'),
                $_SESSION['role'] ?? 'Desconocido',
                $description
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Asignación actualizada correctamente'
            ]);
            break;

        default:
            throw new Exception('Acción no válida');
    }
    ob_end_flush();
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    ob_end_flush();
}
