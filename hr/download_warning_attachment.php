<?php
/**
 * hr/download_warning_attachment.php
 *
 * Sirve el documento adjunto de una amonestación.
 *
 * El archivo se guarda en uploads/warnings/ y se entrega SIEMPRE por aquí (no por
 * enlace directo) para que pase por el permiso de RRHH: un expediente
 * disciplinario no puede quedar accesible a quien adivine la URL.
 *
 *   ?id=123        -> lo abre en el navegador (PDF/imagen)
 *   ?id=123&dl=1   -> lo descarga
 */

session_start();
require_once '../db.php';

ensurePermission('hr_employees', '../unauthorized.php');

$warningId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$forceDownload = !empty($_GET['dl']);

if ($warningId <= 0) {
    http_response_code(400);
    die('Amonestación no válida.');
}

$stmt = $pdo->prepare("
    SELECT w.id, w.subject, w.attachment, w.incident_date,
           e.employee_code, e.first_name, e.last_name
    FROM employee_warnings w
    JOIN employees e ON e.id = w.employee_id
    WHERE w.id = ?
");
$stmt->execute([$warningId]);
$warning = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$warning) {
    http_response_code(404);
    die('Amonestación no encontrada.');
}
if (empty($warning['attachment'])) {
    http_response_code(404);
    die('Esta amonestación no tiene documento adjunto.');
}

// La ruta viene de la BD, pero se valida igual: solo se sirve algo que de verdad
// esté dentro de uploads/, nunca una ruta que se haya escapado del directorio.
$baseDir = realpath(__DIR__ . '/../uploads');
$fullPath = realpath(__DIR__ . '/../' . ltrim((string) $warning['attachment'], '/\\'));

if ($baseDir === false || $fullPath === false || strpos($fullPath, $baseDir) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    die('El archivo ya no está en el servidor.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeByExt = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mime = $mimeByExt[$ext] ?? 'application/octet-stream';

// Nombre legible: "Amonestacion_EMP001_2026-08-05.pdf" en vez del hash interno.
$safeName = 'Amonestacion_'
    . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $warning['employee_code'])
    . '_' . date('Y-m-d', strtotime((string) $warning['incident_date']))
    . '.' . $ext;

$disposition = ($forceDownload || $mime === 'application/octet-stream') ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($fullPath);
exit;
