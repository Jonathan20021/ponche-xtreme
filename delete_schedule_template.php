<?php
session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID de turno inválido']);
        exit;
    }
    
    // Check if template is being used by any employee
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) FROM employee_schedules es
        JOIN schedule_templates st ON st.name = es.schedule_name
        WHERE st.id = ? AND es.is_active = 1
    ");
    $checkStmt->execute([$id]);
    $inUse = $checkStmt->fetchColumn();
    
    if ($inUse > 0) {
        echo json_encode([
            'success' => false,
            'error' => 'No se puede eliminar este turno porque está siendo usado por ' . $inUse . ' empleado(s)'
        ]);
        exit;
    }
    
    // Delete template
    $stmt = $pdo->prepare("DELETE FROM schedule_templates WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Turno eliminado exitosamente'
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al eliminar el turno: ' . $e->getMessage()
    ]);
}
