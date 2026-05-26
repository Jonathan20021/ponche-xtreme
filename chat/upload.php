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

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => getUploadErrorMessage((int)$file['error'])]);
    exit;
}

// Detectar tipo de archivo
$permissions = getChatUploadPermissions($pdo, $userId);
$mimeType = detectUploadedFileMimeType($file['tmp_name']);
$fileType = 'document';
$subdir = 'documents';

if (in_array($mimeType, CHAT_ALLOWED_IMAGE_TYPES)) {
    if (!$permissions['can_upload_files'] && !$permissions['can_upload_images']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para subir imagenes']);
        exit;
    }
    $fileType = 'image';
    $subdir = 'images';
} elseif (in_array($mimeType, CHAT_ALLOWED_VIDEO_TYPES)) {
    if (!$permissions['can_upload_files']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para subir archivos']);
        exit;
    }
    if (!$permissions['can_send_videos']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para enviar videos']);
        exit;
    }
    $fileType = 'video';
    $subdir = 'videos';
} elseif (in_array($mimeType, CHAT_ALLOWED_DOCUMENT_TYPES)) {
    if (!$permissions['can_upload_files']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para subir archivos']);
        exit;
    }
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

// Validar tamano despues de resolver permisos/defaults por rol.
$maxSizeMb = max(1, (int)$permissions['max_file_size_mb']);
$maxSizeBytes = min($maxSizeMb * 1024 * 1024, CHAT_UPLOAD_MAX_SIZE);
if ($file['size'] > $maxSizeBytes) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => "El archivo excede el tamano maximo de {$maxSizeMb}MB"
    ]);
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
    $extension = strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]/', '', $extension);
    if ($extension === '') {
        $extension = extensionForMime($mimeType);
    }
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
        basename((string)$file['name']),
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
 * Devuelve permisos efectivos de subida. Mantiene compatibilidad con usuarios
 * sin fila en chat_permissions, que era el caso de varios QA/SUPERVISOR.
 */
function getChatUploadPermissions(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT
            u.role,
            cp.can_upload_files,
            cp.max_file_size_mb,
            cp.can_send_videos,
            cp.can_send_documents,
            cp.is_restricted,
            cp.restricted_until
        FROM users u
        LEFT JOIN chat_permissions cp ON cp.user_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'can_upload_files' => false,
            'can_upload_images' => false,
            'max_file_size_mb' => 1,
            'can_send_videos' => false,
            'can_send_documents' => false,
        ];
    }

    $role = strtoupper(trim((string)($row['role'] ?? '')));
    $privilegedImageRoles = ['ADMIN', 'SUPERVISOR', 'QA'];
    $largeUploadRoles = ['ADMIN', 'SUPERVISOR', 'DESARROLLADOR', 'OPERATIONSMANAGER', 'GENERALMANAGER', 'IT'];

    $canUploadFiles = chatBoolOrDefault($row['can_upload_files'] ?? null, true);
    $canSendVideos = chatBoolOrDefault($row['can_send_videos'] ?? null, true);
    $canSendDocuments = chatBoolOrDefault($row['can_send_documents'] ?? null, true);

    $maxFileSizeMb = (int)($row['max_file_size_mb'] ?? 0);
    if ($maxFileSizeMb <= 0) {
        $maxFileSizeMb = in_array($role, $largeUploadRoles, true) ? 100 : 50;
    }

    $hasActiveRestriction = !empty($row['is_restricted']) && !empty($row['restricted_until']) && strtotime((string)$row['restricted_until']) > time();
    if ($hasActiveRestriction) {
        $canUploadFiles = false;
        $canSendVideos = false;
        $canSendDocuments = false;
    }

    return [
        'can_upload_files' => $canUploadFiles,
        'can_upload_images' => !$hasActiveRestriction && ($canUploadFiles || in_array($role, $privilegedImageRoles, true)),
        'max_file_size_mb' => $maxFileSizeMb,
        'can_send_videos' => $canSendVideos,
        'can_send_documents' => $canSendDocuments,
    ];
}

function chatBoolOrDefault($value, bool $default): bool {
    if ($value === null) {
        return $default;
    }

    return (bool)$value;
}

function detectUploadedFileMimeType(string $path): string {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }
    }

    $mimeType = function_exists('mime_content_type') ? @mime_content_type($path) : null;
    return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
}

function extensionForMime(string $mimeType): string {
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/mpeg' => 'mpeg',
        'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',
        'video/webm' => 'webm',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
        'application/x-rar-compressed' => 'rar',
    ];

    return $map[$mimeType] ?? 'bin';
}

function getUploadErrorMessage(int $errorCode): string {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo excede el tamano permitido por el servidor';
        case UPLOAD_ERR_PARTIAL:
            return 'El archivo se subio parcialmente. Intenta de nuevo';
        case UPLOAD_ERR_NO_FILE:
            return 'No se proporciono ningun archivo';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'No existe carpeta temporal para subir archivos';
        case UPLOAD_ERR_CANT_WRITE:
            return 'No se pudo guardar el archivo en el servidor';
        case UPLOAD_ERR_EXTENSION:
            return 'Una extension del servidor bloqueo la subida';
        default:
            return 'No se pudo procesar el archivo';
    }
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
