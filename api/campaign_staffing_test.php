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

if (false) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para cargar pronÃ³sticos']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'MÃ©todo no permitido']);
    exit;
}

function jsonError($message, $status = 400)
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function normalizeHeader($header)
{
    $header = strtolower(trim($header));
    $header = str_replace([' ', '-', '.'], '_', $header);
    $header = preg_replace('/[^a-z0-9_]/', '', $header);
    return $header;
}

function parseNumber($value)
{
    if ($value === null) {
        return 0;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $value = str_replace([',', ' '], ['', ''], $value);
    return is_numeric($value) ? (float) $value : 0;
}

function parseIntValue($value)
{
    if ($value === null) {
        return 0;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $value = str_replace([',', ' '], ['', ''], $value);
    return is_numeric($value) ? (int) $value : 0;
}

function normalizeDateTime($value)
{
    $value = trim((string) $value);
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

$campaignId = isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : 0;
if ($campaignId <= 0) {
    jsonError('Campaña inválida');
}

if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
    jsonError('Archivo no válido');
}

$fileTmp = $_FILES['report_file']['tmp_name'];
$originalName = $_FILES['report_file']['name'] ?? 'upload.csv';

if (!file_exists($fileTmp)) {
    jsonError('Carga no permitida');
}

$handle = fopen($fileTmp, 'r');
if (!$handle) {
    jsonError('No se pudo leer el archivo');
}

// Vicidial "Inbound Daily Report" format
// We skip lines until we find the actual data rows.
// Typical data row looks like: 
// "2026-02-01 11:00:00 - 2026-02-01 11:59:59","4","3","2","1","25.00%","03:26","00:00","02:42","0:08:07","0:00:45","0:08:52"
//
// Columns:
// 0: Shift Date-Time Range
// 1: Offered Calls
// 2: Answered Calls
// 3: Agents Answered
// 4: Abandoned Calls
// 5: Abandon Percent
// 6: Avg Abandon Time
// 7: Avg Answer Speed
// 8: Avg Talk Time
// 9: Total Talk Time
// 10: Total Wrap Time
// 11: Total Call Time

function timeStrToSeconds($str)
{
    $str = trim(str_replace('"', '', $str));
    if (empty($str))
        return 0;
    $parts = explode(':', $str);
    if (count($parts) === 3) {
        return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
    } elseif (count($parts) === 2) {
        return ((int) $parts[0] * 60) + (int) $parts[1];
    }
    return 0;
}

$stmt = $pdo->prepare("
    INSERT INTO vicidial_inbound_hourly
        (campaign_id, interval_start, offered_calls, answered_calls, agents_answered,
         abandoned_calls, abandon_percent, avg_abandon_time_sec, avg_answer_speed_sec,
         avg_talk_time_sec, total_talk_sec, total_wrap_sec, total_call_sec,
         source_filename, uploaded_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        offered_calls = VALUES(offered_calls),
        answered_calls = VALUES(answered_calls),
        agents_answered = VALUES(agents_answered),
        abandoned_calls = VALUES(abandoned_calls),
        abandon_percent = VALUES(abandon_percent),
        avg_abandon_time_sec = VALUES(avg_abandon_time_sec),
        avg_answer_speed_sec = VALUES(avg_answer_speed_sec),
        avg_talk_time_sec = VALUES(avg_talk_time_sec),
        total_talk_sec = VALUES(total_talk_sec),
        total_wrap_sec = VALUES(total_wrap_sec),
        total_call_sec = VALUES(total_call_sec),
        source_filename = VALUES(source_filename),
        uploaded_by = VALUES(uploaded_by)
");

$inserted = 0;
$updated = 0;
$skipped = 0;

$inDataSection = false;

while (($row = fgetcsv($handle, 0, ",")) !== false) {
    // If the row starts with "SHIFT DATE-TIME RANGE" we know next lines are data
    if (isset($row[0]) && strpos($row[0], 'SHIFT DATE-TIME RANGE') !== false) {
        $inDataSection = true;
        continue;
    }

    if (!$inDataSection) {
        continue;
    }

    // Stop parsing if we hit summary rows or empty rows
    if (empty($row[0]) || strpos($row[0], 'Week to date') !== false || strpos($row[0], 'WTD') !== false || strpos($row[0], 'TOTALS') !== false) {
        continue; // skip summaries but keep reading in case there are multiple days
    }

    // Parse interval
    $rangeStr = trim($row[0]);
    $parts = explode(' - ', $rangeStr);
    if (count($parts) !== 2) {
        $skipped++;
        continue;
    }
    $intervalStartStr = trim($parts[0]);
    $intervalStart = normalizeDateTime($intervalStartStr);

    if ($intervalStart === null) {
        $skipped++;
        continue;
    }

    $offered = parseNumber($row[1] ?? 0);
    $answered = parseNumber($row[2] ?? 0);
    $agentsAnswered = parseNumber($row[3] ?? 0);
    $abandoned = parseNumber($row[4] ?? 0);

    // Percentages might have '%' at the end
    $abandonPercent = parseNumber(str_replace('%', '', $row[5] ?? '0'));

    // Times
    $avgAbandon = timeStrToSeconds($row[6] ?? '00:00');
    $avgAnswer = timeStrToSeconds($row[7] ?? '00:00');
    $avgTalk = timeStrToSeconds($row[8] ?? '00:00');
    $totalTalk = timeStrToSeconds($row[9] ?? '0:00:00');
    $totalWrap = timeStrToSeconds($row[10] ?? '0:00:00');
    $totalCall = timeStrToSeconds($row[11] ?? '0:00:00');

    $stmt->execute([
        $campaignId,
        $intervalStart,
        $offered,
        $answered,
        $agentsAnswered,
        $abandoned,
        $abandonPercent,
        $avgAbandon,
        $avgAnswer,
        $avgTalk,
        $totalTalk,
        $totalWrap,
        $totalCall,
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
