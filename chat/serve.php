<?php
/**
 * Servidor de Archivos del Chat
 * Sirve archivos de forma segura verificando permisos
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('No autorizado');
}

$userId = (int)$_SESSION['user_id'];
$file = $_GET['file'] ?? '';

if (empty($file)) {
    http_response_code(400);
    die('Archivo no especificado');
}

// Sanitizar el nombre del archivo para prevenir directory traversal
$file = basename($file);

// Buscar el archivo en el directorio base y subdirectorios
$possiblePaths = [
    CHAT_UPLOAD_DIR . $file,
    CHAT_UPLOAD_DIR . 'documents/' . $file,
    CHAT_UPLOAD_DIR . 'images/' . $file,
    CHAT_UPLOAD_DIR . 'videos/' . $file,
    CHAT_UPLOAD_DIR . 'audio/' . $file,
    CHAT_UPLOAD_DIR . 'thumbnails/' . $file,
];

$filePath = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $filePath = $path;
        break;
    }
}

if (!$filePath) {
    http_response_code(404);
    die('Archivo no encontrado');
}

// Verificar que el usuario tenga acceso al archivo
// (debe ser participante de la conversación donde se compartió el archivo)
$stmt = $pdo->prepare("
    SELECT ca.id, ca.mime_type, ca.file_original_name
    FROM chat_attachments ca
    JOIN chat_messages cm ON cm.id = ca.message_id
    JOIN chat_participants cp ON cp.conversation_id = cm.conversation_id
    WHERE ca.file_name = ? AND cp.user_id = ?
");
$stmt->execute([$file, $userId]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(403);
    die('No tienes permiso para acceder a este archivo');
}

// Establecer headers apropiados
header('Content-Type: ' . $attachment['mime_type']);
header('Content-Disposition: inline; filename="' . $attachment['file_original_name'] . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');

// Enviar el archivo
readfile($filePath);
exit;
