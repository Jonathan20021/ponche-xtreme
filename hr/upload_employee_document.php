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

$employeeId = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
$documentType = isset($_POST['document_type']) ? trim($_POST['document_type']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';

if (!$employeeId || !$documentType) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

// Verify employee exists
$stmt = $pdo->prepare("SELECT employee_code, first_name, last_name FROM employees WHERE id = ?");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    echo json_encode(['success' => false, 'error' => 'Empleado no encontrado']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'Error al subir el archivo';
    if (isset($_FILES['document_file']['error'])) {
        switch ($_FILES['document_file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = 'El archivo es demasiado grande';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = 'No se seleccionó ningún archivo';
                break;
        }
    }
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

// Validate file
$file = $_FILES['document_file'];
$fileSize = $file['size'];
$originalName = $file['name'];
$fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

// Allowed file types (comprehensive list for HR documents)
$allowedExtensions = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'bmp',
    'txt', 'rtf', 'odt', 'ods', 'ppt', 'pptx', 'zip', 'rar', '7z'
];

if (!in_array($fileExtension, $allowedExtensions)) {
    echo json_encode(['success' => false, 'error' => 'Tipo de archivo no permitido']);
    exit;
}

// Max file size: 10MB
$maxFileSize = 10 * 1024 * 1024;
if ($fileSize > $maxFileSize) {
    echo json_encode(['success' => false, 'error' => 'El archivo no puede superar 10MB']);
    exit;
}

// Create upload directory
$uploadDir = '../uploads/employee_documents/' . $employee['employee_code'] . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$fileName = 'doc_' . uniqid() . '_' . time() . '.' . $fileExtension;
$targetPath = $uploadDir . $fileName;
$relativePath = 'uploads/employee_documents/' . $employee['employee_code'] . '/' . $fileName;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['success' => false, 'error' => 'Error al guardar el archivo']);
    exit;
}

// Save to database
try {
    // Check if columns exist
    $checkColumns = $pdo->query("SHOW COLUMNS FROM employee_documents LIKE 'uploaded_at'");
    $hasUploadedAt = $checkColumns->rowCount() > 0;
    
    $checkExtension = $pdo->query("SHOW COLUMNS FROM employee_documents LIKE 'file_extension'");
    $hasFileExtension = $checkExtension->rowCount() > 0;
    
    // Build query based on available columns
    if ($hasUploadedAt && $hasFileExtension) {
        $stmt = $pdo->prepare("
            INSERT INTO employee_documents 
            (employee_id, document_type, document_name, file_path, file_size, file_extension, mime_type, description, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $employeeId,
            $documentType,
            $originalName,
            $relativePath,
            $fileSize,
            $fileExtension,
            $fileExtension,
            $description,
            $_SESSION['user_id']
        ]);
    } else {
        // Use old column names
        $stmt = $pdo->prepare("
            INSERT INTO employee_documents 
            (employee_id, document_type, document_name, file_path, file_size, mime_type, description, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $employeeId,
            $documentType,
            $originalName,
            $relativePath,
            $fileSize,
            $fileExtension,
            $description,
            $_SESSION['user_id']
        ]);
    }
    
    $documentId = $pdo->lastInsertId();
    
    // Log the action
    log_activity(
        $pdo,
        $_SESSION['user_id'],
        $_SESSION['full_name'],
        $_SESSION['role'],
        'document_uploaded',
        'employee_documents',
        $documentId,
        "Documento subido para {$employee['first_name']} {$employee['last_name']}: {$documentType} - {$originalName}"
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Documento subido exitosamente',
        'document' => [
            'id' => $documentId,
            'document_type' => $documentType,
            'document_name' => $originalName,
            'file_size' => $fileSize,
            'file_extension' => $fileExtension,
            'description' => $description,
            'uploaded_at' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    // Delete uploaded file if database insert fails
    if (file_exists($targetPath)) {
        unlink($targetPath);
    }
    echo json_encode(['success' => false, 'error' => 'Error al guardar en la base de datos: ' . $e->getMessage()]);
}
