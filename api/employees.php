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

// Check manage_campaigns permission for quick assign
if (!userHasPermission('manage_campaigns')) {
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
            $campaignId = !empty($_POST['campaign_id']) ? intval($_POST['campaign_id']) : null;
            $supervisorId = !empty($_POST['supervisor_id']) ? intval($_POST['supervisor_id']) : null;
            
            if (!$employeeId) {
                throw new Exception('ID de empleado inválido');
            }
            
            // Verify employee exists
            $checkQuery = $conn->prepare("SELECT id FROM employees WHERE id = ?");
            $checkQuery->bind_param("i", $employeeId);
            $checkQuery->execute();
            if ($checkQuery->get_result()->num_rows === 0) {
                throw new Exception('Empleado no encontrado');
            }
            
            // Update employee
            $stmt = $conn->prepare("UPDATE employees SET campaign_id = ?, supervisor_id = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("iii", $campaignId, $supervisorId, $employeeId);
            
            if (!$stmt->execute()) {
                throw new Exception('Error al actualizar empleado: ' . $conn->error);
            }
            
            // Log the action
            $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, 'employee_assign', ?, NOW())");
            $description = "Asignación rápida para empleado ID: $employeeId";
            $logStmt->bind_param("is", $userId, $description);
            $logStmt->execute();
            
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
