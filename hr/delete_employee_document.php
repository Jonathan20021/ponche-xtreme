<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('hr_employee_documents', '../unauthorized.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$documentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$documentId) {
    echo json_encode(['success' => false, 'error' => 'ID de documento requerido']);
    exit;
}

try {
    // Get document info before deletion
    $stmt = $pdo->prepare("
        SELECT ed.*, e.first_name, e.last_name 
        FROM employee_documents ed
        JOIN employees e ON e.id = ed.employee_id
        WHERE ed.id = ?
    ");
    $stmt->execute([$documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        echo json_encode(['success' => false, 'error' => 'Documento no encontrado']);
        exit;
    }
    
    // Delete file from filesystem
    $filePath = '../' . $document['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    // Delete from database
    $deleteStmt = $pdo->prepare("DELETE FROM employee_documents WHERE id = ?");
    $deleteStmt->execute([$documentId]);
    
    // Log the action
    log_activity(
        $pdo,
        $_SESSION['user_id'],
        $_SESSION['full_name'],
        $_SESSION['role'],
        'document_deleted',
        'employee_documents',
        $documentId,
        "Documento eliminado de {$document['first_name']} {$document['last_name']}: {$document['document_type']} - {$document['document_name']}"
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Documento eliminado exitosamente'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error al eliminar: ' . $e->getMessage()]);
}
