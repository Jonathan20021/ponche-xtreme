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
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para cargar reportes']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'M\u00e9todo no permitido']);
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

function normalizeDate($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt && $dt->format($format) === $value) {
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

$campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
if ($campaignId <= 0) {
    jsonError('Campania inv\u00e1lida');
}

if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
    jsonError('Archivo no v\u00e1lido');
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
    jsonError('Encabezado inv\u00e1lido en el CSV');
}

$firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
$delimiters = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
arsort($delimiters);
$delimiter = array_key_first($delimiters);
if ($delimiter === null || $delimiters[$delimiter] === 0) {
    $delimiter = ',';
}

$headerRow = str_getcsv(trim($firstLine), $delimiter);
if (!$headerRow || count($headerRow) < 2) {
    fclose($handle);
    jsonError('Encabezado inv\u00e1lido en el CSV');
}

$aliases = [
    'report_date' => ['report_date', 'fecha', 'fecha_reporte', 'date'],
    'sales_amount' => ['ventas', 'sales', 'sale', 'producido', 'produced'],
    'revenue_amount' => ['ingresos', 'revenue', 'income'],
    'volume' => ['volumen', 'volume', 'qty', 'cantidad'],
    'currency' => ['currency', 'moneda']
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

$dateIdx = findHeaderIndex($headerMap, $aliases['report_date']);
$salesIdx = findHeaderIndex($headerMap, $aliases['sales_amount']);
$revenueIdx = findHeaderIndex($headerMap, $aliases['revenue_amount']);
$volumeIdx = findHeaderIndex($headerMap, $aliases['volume']);
$currencyIdx = findHeaderIndex($headerMap, $aliases['currency']);

if ($dateIdx === null) {
    fclose($handle);
    jsonError('El CSV debe incluir la columna report_date o fecha');
}

$stmt = $pdo->prepare("
    INSERT INTO campaign_sales_reports
        (campaign_id, report_date, sales_amount, revenue_amount, volume, currency, source_filename, uploaded_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        sales_amount = VALUES(sales_amount),
        revenue_amount = VALUES(revenue_amount),
        volume = VALUES(volume),
        currency = VALUES(currency),
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

    $dateValue = $row[$dateIdx] ?? '';
    $reportDate = normalizeDate($dateValue);
    if ($reportDate === null) {
        $skipped++;
        continue;
    }

    $sales = $salesIdx !== null ? parseNumber($row[$salesIdx] ?? 0) : 0;
    $revenue = $revenueIdx !== null ? parseNumber($row[$revenueIdx] ?? 0) : 0;
    $volume = $volumeIdx !== null ? parseIntValue($row[$volumeIdx] ?? 0) : 0;
    $currency = $currencyIdx !== null ? strtoupper(trim((string)($row[$currencyIdx] ?? 'USD'))) : 'USD';
    if ($currency === '') {
        $currency = 'USD';
    }

    $stmt->execute([
        $campaignId,
        $reportDate,
        $sales,
        $revenue,
        $volume,
        $currency,
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
