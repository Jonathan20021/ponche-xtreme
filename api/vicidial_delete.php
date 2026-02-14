<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

header('Content-Type: application/json');

// Check permission
if (!isset($_SESSION['user_id']) || !userHasPermission('vicidial_reports')) {
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para realizar esta acción']);
    exit;
}

// Check if upload ID was provided
if (!isset($_POST['upload_id']) || empty($_POST['upload_id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de subida no especificado']);
    exit;
}

$uploadId = (int) $_POST['upload_id'];

try {
    $pdo->beginTransaction();

    // Get upload information
    $uploadStmt = $pdo->prepare("
        SELECT upload_date, uploaded_by 
        FROM vicidial_uploads 
        WHERE id = ?
    ");
    $uploadStmt->execute([$uploadId]);
    $upload = $uploadStmt->fetch(PDO::FETCH_ASSOC);

    if (!$upload) {
        throw new Exception('Registro de subida no encontrado');
    }

    // Delete associated login stats records
    $deleteStatsStmt = $pdo->prepare("
        DELETE FROM vicidial_login_stats 
        WHERE upload_date = ? AND uploaded_by = ?
    ");
    $deleteStatsStmt->execute([$upload['upload_date'], $upload['uploaded_by']]);
    $deletedRecords = $deleteStatsStmt->rowCount();

    // Delete upload record
    $deleteUploadStmt = $pdo->prepare("
        DELETE FROM vicidial_uploads 
        WHERE id = ?
    ");
    $deleteUploadStmt->execute([$uploadId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Subida eliminada exitosamente',
        'deleted_records' => $deletedRecords
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar la subida: ' . $e->getMessage()
    ]);
}
?>