<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_employee_documents');

header('Content-Type: application/json');

$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

if (!$employeeId) {
    echo json_encode(['success' => false, 'error' => 'ID de empleado requerido']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            ed.*,
            COALESCE(ed.uploaded_at, ed.created_at) as uploaded_at,
            COALESCE(ed.file_extension, ed.mime_type) as file_extension,
            u.full_name as uploaded_by_name
        FROM employee_documents ed
        LEFT JOIN users u ON u.id = ed.uploaded_by
        WHERE ed.employee_id = ?
        ORDER BY COALESCE(ed.uploaded_at, ed.created_at) DESC
    ");
    
    $stmt->execute([$employeeId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group documents by type
    $groupedDocs = [];
    foreach ($documents as $doc) {
        $type = $doc['document_type'];
        if (!isset($groupedDocs[$type])) {
            $groupedDocs[$type] = [];
        }
        $groupedDocs[$type][] = $doc;
    }
    
    echo json_encode([
        'success' => true,
        'documents' => $documents,
        'grouped' => $groupedDocs,
        'total' => count($documents)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
