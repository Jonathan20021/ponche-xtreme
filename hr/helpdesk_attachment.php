<?php
/**
 * Sirve el binario de un adjunto de ticket (imagen/PDF/txt) con control de acceso:
 * solo el dueño del ticket, su asignado o el equipo de soporte pueden verlo.
 */
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/helpdesk_support.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Sesión requerida.');
}
$conn = getMysqli();
$uid = (int) $_SESSION['user_id'];
$isSupport = isHelpdeskSupport($_SESSION['role'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Falta id.');
}

$stmt = $conn->prepare("
    SELECT a.file_name, a.file_path, a.file_blob, a.file_type, t.user_id, t.assigned_to
    FROM helpdesk_attachments a
    JOIN helpdesk_tickets t ON t.id = a.ticket_id
    WHERE a.id = ? LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    http_response_code(404);
    exit('No encontrado.');
}
$canView = $isSupport || (int) $row['user_id'] === $uid || (int) $row['assigned_to'] === $uid;
if (!$canView) {
    http_response_code(403);
    exit('Sin acceso.');
}

$type = $row['file_type'] ?: 'application/octet-stream';
$disposition = isset($_GET['download']) ? 'attachment' : 'inline';
header('Content-Type: ' . $type);
header('Content-Disposition: ' . $disposition . '; filename="' . basename((string) $row['file_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

// El binario vive en la DB compartida (funciona entre servidores).
if ($row['file_blob'] !== null && $row['file_blob'] !== '') {
    header('Content-Length: ' . strlen($row['file_blob']));
    echo $row['file_blob'];
    exit;
}

// Compat: adjuntos antiguos guardados en disco (uploads/helpdesk).
$path = realpath(__DIR__ . '/../' . ltrim((string) $row['file_path'], '/'));
$baseDir = realpath(__DIR__ . '/../uploads/helpdesk');
if ($path === false || $baseDir === false || strpos($path, $baseDir) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('Archivo no disponible.');
}
header('Content-Length: ' . filesize($path));
readfile($path);
