<?php
/**
 * Proxy de audio: transmite una grabación de Vicidial al navegador del agente.
 *
 * - Requiere sesión y que la grabación PERTENEZCA al agente logueado (o que sea
 *   supervisor/admin con permiso). El agente nunca ve la URL real ni las claves.
 * - Descarga desde el host de la API con Basic Auth + bundle SSL (server-side).
 * - Soporta Range (seek/buffering del <audio>).
 */
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/vicidial_recordings.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Sesión requerida.');
}
$uid = (int) $_SESSION['user_id'];
$role = strtoupper((string) ($_SESSION['role'] ?? ''));
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Falta id.');
}

try {
    $st = $pdo->prepare("SELECT filename, user_id FROM vicidial_recordings WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $rec = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Error.');
}

// Sólo el dueño de la grabación (supervisores/QA/admin pueden oír cualquiera).
$privileged = in_array($role, ['ADMIN', 'SUPERVISOR', 'QA', 'IT', 'HR', 'GERENTEDEOPERACIONES', 'OPERATIONSMANAGER', 'GENERALMANAGER', 'TEAMLEAD', 'DIRECTOR'], true);
if (!$rec || (!$privileged && (int) $rec['user_id'] !== $uid)) {
    http_response_code(404);
    exit('Grabación no encontrada.');
}

$cfg = getVicidialSyncConfig($pdo);
$url = vicidialBuildRecordingUrl($cfg, (string) $rec['filename']);
$sslVerify = ($cfg['vicidial_ssl_verify'] ?? '1') !== '0';
$caBundle = __DIR__ . '/lib/cacert_vicidial.pem';

$range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));

// Cabeceras base (Content-Type propio: el upstream manda application/forcedownload).
header('Content-Type: audio/mpeg');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . basename((string) $rec['filename']) . '"');

$ch = curl_init($url);
$opts = [
    CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
    CURLOPT_USERPWD        => $cfg['vicidial_api_user'] . ':' . $cfg['vicidial_api_pass'],
    CURLOPT_SSL_VERIFYPEER => $sslVerify,
    CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_BUFFERSIZE     => 65536,
    CURLOPT_USERAGENT      => 'ponche-xtreme-recording-proxy/1.0',
    // Reenvía SOLO cabeceras seguras y fija el status (200/206) según el upstream.
    CURLOPT_HEADERFUNCTION => function ($ch, $header) {
        if (preg_match('~^HTTP/\d(?:\.\d)?\s+(\d{3})~', $header, $m)) {
            $code = (int) $m[1];
            if ($code >= 400) {
                http_response_code($code === 404 ? 404 : 502);
            } elseif ($code === 206) {
                http_response_code(206);
            }
        } elseif (preg_match('~^(Content-Length|Content-Range|Accept-Ranges):~i', $header)) {
            header(trim($header));
        }
        return strlen($header);
    },
    // Transmite el cuerpo directamente al navegador.
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
        echo $data;
        return strlen($data);
    },
];
if ($sslVerify && is_readable($caBundle)) {
    $opts[CURLOPT_CAINFO] = $caBundle;
}
if ($range !== '') {
    $opts[CURLOPT_RANGE] = str_ireplace('bytes=', '', $range);
}
curl_setopt_array($ch, $opts);

// Vacía cualquier buffer para transmitir de a poco.
while (ob_get_level() > 0) { ob_end_flush(); }

$ok = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($ok === false && $httpCode === 0) {
    // No se pudo conectar; si aún no salió cuerpo, avisar.
    if (!headers_sent()) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo obtener la grabación.';
    }
}
