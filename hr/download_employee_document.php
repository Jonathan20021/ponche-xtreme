<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_employee_documents');

$documentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$documentId) {
    die('ID de documento requerido');
}

// Get document info
$stmt = $pdo->prepare("SELECT * FROM employee_documents WHERE id = ?");
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    die('Documento no encontrado');
}

$filePath = '../' . $document['file_path'];

if (!file_exists($filePath)) {
    die('Archivo no encontrado en el servidor');
}

// Set headers for download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $document['document_name'] . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

// Output file
readfile($filePath);
exit;
