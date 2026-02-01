<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

if (!userHasPermission('manage_campaigns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para cargar pronÃ³sticos']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'MÃ©todo no permitido']);
    exit;
}

function jsonError($message, $status = 400) {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function normalizeHeader($header) {
    $header = strtolower(trim($header));
    $header = str_replace([' ', '-', '.'], '_', $header);
    $header = preg_replace('/[^a-z0-9_]/', '', $header);
    return $header;
}

function parseNumber($value) {
    if ($value === null) {
        return 0;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    $value = str_replace([',', ' '], ['', ''], $value);
    return is_numeric($value) ? (float)$value : 0;
}

function parseIntValue($value) {
    if ($value === null) {
        return 0;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    $value = str_replace([',', ' '], ['', ''], $value);
    return is_numeric($value) ? (int)$value : 0;
}

function normalizeDateTime($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i', 'd/m/Y H:i:s', 'm/d/Y H:i', 'm/d/Y H:i:s'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt && $dt->format($format) === $value) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $ts = strtotime($value);
    if ($ts !== false) {
        return date('Y-m-d H:i:s', $ts);
    }

    return null;
}

$campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
if ($campaignId <= 0) {
    jsonError('CampaÃ±a invÃ¡lida');
}

if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
    jsonError('Archivo no vÃ¡lido');
}

$fileTmp = $_FILES['report_file']['tmp_name'];
$originalName = $_FILES['report_file']['name'] ?? 'upload.csv';

if (!is_uploaded_file($fileTmp)) {
    jsonError('Carga no permitida');
}

$handle = fopen($fileTmp, 'r');
if (!$handle) {
    jsonError('No se pudo leer el archivo');
}

$firstLine = fgets($handle);
if ($firstLine === false) {
    fclose($handle);
    jsonError('Encabezado invÃ¡lido en el CSV');
}

$firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
$delimiters = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
arsort($delimiters);
$delimiter = array_key_first($delimiters);
if ($delimiter === null || $delimiters[$delimiter] === 0) {
    $delimiter = ',';
}

$headerRow = str_getcsv(trim($firstLine), $delimiter);
if (!$headerRow || count($headerRow) < 3) {
    fclose($handle);
    jsonError('Encabezado invÃ¡lido en el CSV');
}

$aliases = [
    'interval_start' => ['interval_start', 'inicio_intervalo', 'fecha_hora', 'datetime'],
    'interval_minutes' => ['interval_minutes', 'intervalo_min', 'intervalo', 'minutes'],
    'offered_volume' => ['offered_volume', 'volumen', 'ofrecido', 'calls', 'contactos'],
    'aht_seconds' => ['aht_seconds', 'aht', 'tmo', 'aht_segundos'],
    'target_sl' => ['target_sl', 'sl', 'nivel_servicio'],
    'target_answer_seconds' => ['target_answer_seconds', 'asa_objetivo', 'answer_seconds', 'tasa_respuesta'],
    'occupancy_target' => ['occupancy_target', 'ocupacion', 'occupancy'],
    'shrinkage' => ['shrinkage', 'shrink', 'ausentismo'],
    'channel' => ['channel', 'canal']
];

$headerMap = [];
foreach ($headerRow as $idx => $h) {
    $normalized = normalizeHeader($h);
    $headerMap[$normalized] = $idx;
}

function findHeaderIndex($headerMap, $keys) {
    foreach ($keys as $key) {
        $norm = normalizeHeader($key);
        if (array_key_exists($norm, $headerMap)) {
            return $headerMap[$norm];
        }
    }
    return null;
}

$intervalIdx = findHeaderIndex($headerMap, $aliases['interval_start']);
$intervalMinutesIdx = findHeaderIndex($headerMap, $aliases['interval_minutes']);
$volumeIdx = findHeaderIndex($headerMap, $aliases['offered_volume']);
$ahtIdx = findHeaderIndex($headerMap, $aliases['aht_seconds']);
$targetSlIdx = findHeaderIndex($headerMap, $aliases['target_sl']);
$targetAnsIdx = findHeaderIndex($headerMap, $aliases['target_answer_seconds']);
$occupancyIdx = findHeaderIndex($headerMap, $aliases['occupancy_target']);
$shrinkageIdx = findHeaderIndex($headerMap, $aliases['shrinkage']);
$channelIdx = findHeaderIndex($headerMap, $aliases['channel']);

if ($intervalIdx === null || $volumeIdx === null || $ahtIdx === null) {
    fclose($handle);
    jsonError('El CSV debe incluir interval_start, offered_volume y aht_seconds');
}

$stmt = $pdo->prepare("
    INSERT INTO campaign_staffing_forecast
        (campaign_id, interval_start, interval_minutes, offered_volume, aht_seconds, target_sl,
         target_answer_seconds, occupancy_target, shrinkage, channel, source_filename, uploaded_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        interval_minutes = VALUES(interval_minutes),
        offered_volume = VALUES(offered_volume),
        aht_seconds = VALUES(aht_seconds),
        target_sl = VALUES(target_sl),
        target_answer_seconds = VALUES(target_answer_seconds),
        occupancy_target = VALUES(occupancy_target),
        shrinkage = VALUES(shrinkage),
        channel = VALUES(channel),
        source_filename = VALUES(source_filename),
        uploaded_by = VALUES(uploaded_by)
");

$inserted = 0;
$updated = 0;
$skipped = 0;

while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    if (!array_filter($row, fn($v) => trim((string)$v) !== '')) {
        continue;
    }

    $intervalStart = normalizeDateTime($row[$intervalIdx] ?? '');
    if ($intervalStart === null) {
        $skipped++;
        continue;
    }

    $intervalMinutes = $intervalMinutesIdx !== null ? max(1, parseIntValue($row[$intervalMinutesIdx] ?? 30)) : 30;
    $volume = $volumeIdx !== null ? max(0, parseIntValue($row[$volumeIdx] ?? 0)) : 0;
    $aht = $ahtIdx !== null ? max(0, parseIntValue($row[$ahtIdx] ?? 0)) : 0;
    $targetSl = $targetSlIdx !== null ? parseNumber($row[$targetSlIdx] ?? 0.8) : 0.8;
    $targetAns = $targetAnsIdx !== null ? max(0, parseIntValue($row[$targetAnsIdx] ?? 20)) : 20;
    $occupancy = $occupancyIdx !== null ? parseNumber($row[$occupancyIdx] ?? 0.85) : 0.85;
    $shrinkage = $shrinkageIdx !== null ? parseNumber($row[$shrinkageIdx] ?? 0.3) : 0.3;
    $channel = $channelIdx !== null ? trim((string)($row[$channelIdx] ?? '')) : null;

    if ($targetSl > 1) {
        $targetSl = $targetSl / 100;
    }
    if ($occupancy > 1) {
        $occupancy = $occupancy / 100;
    }
    if ($shrinkage > 1) {
        $shrinkage = $shrinkage / 100;
    }

    $stmt->execute([
        $campaignId,
        $intervalStart,
        $intervalMinutes,
        $volume,
        $aht,
        $targetSl,
        $targetAns,
        $occupancy,
        $shrinkage,
        $channel,
        $originalName,
        $_SESSION['user_id'] ?? null
    ]);

    $affected = $stmt->rowCount();
    if ($affected === 1) {
        $inserted++;
    } elseif ($affected === 2) {
        $updated++;
    } else {
        $skipped++;
    }
}

fclose($handle);

echo json_encode([
    'success' => true,
    'inserted' => $inserted,
    'updated' => $updated,
    'skipped' => $skipped
]);
