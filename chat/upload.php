<?php
/**
 * API para subida de archivos del chat
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Verificar permisos de subida
$stmt = $pdo->prepare("
    SELECT can_upload_files, max_file_size_mb, can_send_videos, can_send_documents
    FROM chat_permissions
    WHERE user_id = ?
");
$stmt->execute([$userId]);
$permissions = $stmt->fetch();

if (!$permissions || !$permissions['can_upload_files']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tienes permiso para subir archivos']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$conversationId = (int)($_POST['conversation_id'] ?? 0);
$messageText = trim($_POST['message_text'] ?? '');

if ($conversationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de conversación inválido']);
    exit;
}

// Verificar que es participante
$stmt = $pdo->prepare("
    SELECT id FROM chat_participants 
    WHERE conversation_id = ? AND user_id = ? AND is_active = 1
");
$stmt->execute([$conversationId, $userId]);

if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se proporcionó ningún archivo']);
    exit;
}

$file = $_FILES['file'];

// Validar tamaño
$maxSizeBytes = $permissions['max_file_size_mb'] * 1024 * 1024;
if ($file['size'] > $maxSizeBytes) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => "El archivo excede el tamaño máximo de {$permissions['max_file_size_mb']}MB"
    ]);
    exit;
}

// Detectar tipo de archivo
$mimeType = mime_content_type($file['tmp_name']);
$fileType = 'document';
$subdir = 'documents';

if (in_array($mimeType, CHAT_ALLOWED_IMAGE_TYPES)) {
    $fileType = 'image';
    $subdir = 'images';
} elseif (in_array($mimeType, CHAT_ALLOWED_VIDEO_TYPES)) {
    if (!$permissions['can_send_videos']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para enviar videos']);
        exit;
    }
    $fileType = 'video';
    $subdir = 'videos';
} elseif (in_array($mimeType, CHAT_ALLOWED_DOCUMENT_TYPES)) {
    if (!$permissions['can_send_documents']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para enviar documentos']);
        exit;
    }
    $fileType = 'document';
    $subdir = 'documents';
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Tipo de archivo no permitido']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Crear mensaje
    $stmt = $pdo->prepare("
        INSERT INTO chat_messages (conversation_id, user_id, message_text, message_type)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$conversationId, $userId, $messageText ?: '', $fileType]);
    $messageId = $pdo->lastInsertId();
    
    // Generar nombre único para el archivo
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = uniqid('chat_', true) . '.' . $extension;
    $filePath = "{$subdir}/{$fileName}";
    $fullPath = CHAT_UPLOAD_DIR . $filePath;
    
    // Mover archivo
    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new Exception('Error al guardar el archivo');
    }
    
    // Crear thumbnail para imágenes
    $thumbnailPath = null;
    if ($fileType === 'image') {
        $thumbnailPath = createThumbnail($fullPath, $fileName);
    }
    
    // Guardar información del archivo
    $stmt = $pdo->prepare("
        INSERT INTO chat_attachments 
        (message_id, file_name, file_original_name, file_path, file_type, file_size, mime_type, thumbnail_path)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $messageId,
        $fileName,
        $file['name'],
        $filePath,
        $fileType,
        $file['size'],
        $mimeType,
        $thumbnailPath
    ]);
    
    // Crear notificaciones
    $stmt = $pdo->prepare("
        INSERT INTO chat_notifications (user_id, conversation_id, message_id, notification_type)
        SELECT user_id, ?, ?, 'new_message'
        FROM chat_participants
        WHERE conversation_id = ? AND user_id != ? AND is_active = 1
    ");
    $stmt->execute([$conversationId, $messageId, $conversationId, $userId]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message_id' => $messageId,
        'file_name' => $fileName,
        'file_path' => $filePath,
        'file_type' => $fileType,
        'thumbnail_path' => $thumbnailPath
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    
    // Eliminar archivo si se subió
    if (isset($fullPath) && file_exists($fullPath)) {
        @unlink($fullPath);
    }
    
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Crea un thumbnail para una imagen
 */
function createThumbnail(string $sourcePath, string $fileName): ?string {
    try {
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return null;
        }
        
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $type = $imageInfo[2];
        
        // Cargar imagen según tipo
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($sourcePath);
                break;
            default:
                return null;
        }
        
        if (!$source) {
            return null;
        }
        
        // Calcular dimensiones del thumbnail (300px max)
        $maxSize = 300;
        if ($width > $height) {
            $thumbWidth = $maxSize;
            $thumbHeight = (int)(($height / $width) * $maxSize);
        } else {
            $thumbHeight = $maxSize;
            $thumbWidth = (int)(($width / $height) * $maxSize);
        }
        
        // Crear thumbnail
        $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
        
        // Preservar transparencia para PNG y GIF
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }
        
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
        
        // Guardar thumbnail
        $thumbFileName = 'thumb_' . $fileName;
        $thumbPath = CHAT_UPLOAD_DIR . 'thumbnails/' . $thumbFileName;
        
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($thumb, $thumbPath, 85);
                break;
            case IMAGETYPE_PNG:
                imagepng($thumb, $thumbPath, 8);
                break;
            case IMAGETYPE_GIF:
                imagegif($thumb, $thumbPath);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($thumb, $thumbPath, 85);
                break;
        }
        
        imagedestroy($source);
        imagedestroy($thumb);
        
        return 'thumbnails/' . $thumbFileName;
        
    } catch (Exception $e) {
        return null;
    }
}
