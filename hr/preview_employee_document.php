<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_employee_documents', '../unauthorized.php');

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

// Get file extension
$extension = strtolower($document['file_extension'] ?? $document['mime_type'] ?? '');

// Set appropriate content type
$contentTypes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'bmp' => 'image/bmp',
    'webp' => 'image/webp',
    'txt' => 'text/plain',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

$contentType = $contentTypes[$extension] ?? 'application/octet-stream';

// Set headers for inline display
header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . $document['document_name'] . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: public, max-age=3600');
header('Pragma: public');

// Output file
readfile($filePath);
exit;
