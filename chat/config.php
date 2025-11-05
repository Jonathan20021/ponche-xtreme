<?php
/**
 * Configuración del Sistema de Chat en Tiempo Real
 */

// Configuración de archivos adjuntos
define('CHAT_UPLOAD_DIR', __DIR__ . '/uploads/');
define('CHAT_UPLOAD_MAX_SIZE', 100 * 1024 * 1024); // 100MB por defecto
define('CHAT_ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('CHAT_ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm']);
define('CHAT_ALLOWED_DOCUMENT_TYPES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain',
    'text/csv',
    'application/zip',
    'application/x-rar-compressed'
]);

// Configuración de tiempo real
define('CHAT_POLL_INTERVAL', 2000); // 2 segundos para long polling
define('CHAT_TYPING_TIMEOUT', 5000); // 5 segundos para indicador de escritura
define('CHAT_ONLINE_THRESHOLD', 300); // 5 minutos para considerar usuario online

// Configuración de mensajes
define('CHAT_MAX_MESSAGE_LENGTH', 10000);
define('CHAT_MESSAGES_PER_PAGE', 50);
define('CHAT_SHOW_READ_RECEIPTS', true);

// Crear directorio de uploads si no existe
if (!file_exists(CHAT_UPLOAD_DIR)) {
    mkdir(CHAT_UPLOAD_DIR, 0755, true);
}

// Subdirectorios para organizar archivos
$subdirs = ['images', 'videos', 'documents', 'audio', 'thumbnails'];
foreach ($subdirs as $dir) {
    $path = CHAT_UPLOAD_DIR . $dir;
    if (!file_exists($path)) {
        mkdir($path, 0755, true);
    }
}
