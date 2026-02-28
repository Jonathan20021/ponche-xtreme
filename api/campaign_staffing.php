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
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para cargar pronosticos']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
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
    $header = strtolower(trim((string) $header));
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
    $value = str_replace(['$', ',', ' ', '%'], ['', '', '', ''], $value);
    return is_numeric($value) ? (float) $value : 0;
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

function timeStrToSeconds($str)
{
    $str = trim(str_replace('"', '', (string) $str));
    if ($str === '') {
        return 0;
    }
    $parts = explode(':', $str);
    if (count($parts) === 3) {
        return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
    }
    if (count($parts) === 2) {
        return ((int) $parts[0] * 60) + (int) $parts[1];
    }
    return 0;
}

function parseCallingHourToDateTime($value)
{
    $value = trim((string) $value);
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2})(am|pm)$/i', $value, $m)) {
        return null;
    }

    $datePart = $m[1];
    $hour = (int) $m[2];
    $ampm = strtolower($m[3]);

    if ($hour < 1 || $hour > 12) {
        return null;
    }

    if ($ampm === 'am') {
        $hour24 = ($hour === 12) ? 0 : $hour;
    } else {
        $hour24 = ($hour === 12) ? 12 : ($hour + 12);
    }

    return sprintf('%s %02d:00:00', $datePart, $hour24);
}

function parseInboundDailyRow(array $row)
{
    $rangeStr = trim((string) ($row[0] ?? ''));
    $parts = explode(' - ', $rangeStr);
    if (count($parts) !== 2) {
        return null;
    }

    $intervalStart = normalizeDateTime(trim($parts[0]));
    if ($intervalStart === null) {
        return null;
    }

    $offered = (int) round(parseNumber($row[1] ?? 0));
    $answered = (int) round(parseNumber($row[2] ?? 0));
    $agentsAnswered = (int) round(parseNumber($row[3] ?? 0));
    $abandoned = (int) round(parseNumber($row[4] ?? 0));
    $abandonPercent = parseNumber($row[5] ?? '0');

    $avgAbandon = timeStrToSeconds($row[6] ?? '00:00');
    $avgAnswer = timeStrToSeconds($row[7] ?? '00:00');
    $avgTalk = timeStrToSeconds($row[8] ?? '00:00');
    $totalTalk = timeStrToSeconds($row[9] ?? '0:00:00');
    $totalWrap = timeStrToSeconds($row[10] ?? '0:00:00');
    $totalCall = timeStrToSeconds($row[11] ?? '0:00:00');

    return [
        'interval_start' => $intervalStart,
        'offered_calls' => max(0, $offered),
        'answered_calls' => max(0, $answered),
        'agents_answered' => max(0, $agentsAnswered),
        'abandoned_calls' => max(0, $abandoned),
        'abandon_percent' => max(0, $abandonPercent),
        'avg_abandon_time_sec' => max(0, $avgAbandon),
        'avg_answer_speed_sec' => max(0, $avgAnswer),
        'avg_talk_time_sec' => max(0, $avgTalk),
        'total_talk_sec' => max(0, $totalTalk),
        'total_wrap_sec' => max(0, $totalWrap),
        'total_call_sec' => max(0, $totalCall),
    ];
}

function parseAstErlangRow(array $row)
{
    $intervalStart = parseCallingHourToDateTime($row[0] ?? '');
    if ($intervalStart === null) {
        return null;
    }

    $calls = (int) round(parseNumber($row[1] ?? 0));
    $totalTimeSec = timeStrToSeconds($row[3] ?? '0:00:00');
    $avgTimeSec = timeStrToSeconds($row[4] ?? '00:00');
    $droppedTimeSec = timeStrToSeconds($row[5] ?? '00:00');
    $blocking = parseNumber($row[6] ?? 0);

    if ($blocking > 1) {
        $blocking = $blocking / 100;
    }
    if ($blocking < 0) {
        $blocking = 0;
    }

    $abandoned = (int) round($calls * $blocking);
    $answered = max(0, $calls - $abandoned);
    $agentsAnswered = (int) round(parseNumber($row[9] ?? 0));
    if ($agentsAnswered <= 0) {
        $agentsAnswered = (int) round(parseNumber($row[8] ?? 0));
    }

    $avgAbandonSec = $abandoned > 0 ? (int) round($droppedTimeSec / $abandoned) : 0;

    return [
        'interval_start' => $intervalStart,
        'offered_calls' => max(0, $calls),
        'answered_calls' => max(0, $answered),
        'agents_answered' => max(0, $agentsAnswered),
        'abandoned_calls' => max(0, $abandoned),
        'abandon_percent' => round($blocking * 100, 2),
        'avg_abandon_time_sec' => max(0, $avgAbandonSec),
        'avg_answer_speed_sec' => 0,
        'avg_talk_time_sec' => max(0, $avgTimeSec),
        'total_talk_sec' => max(0, $totalTimeSec),
        'total_wrap_sec' => 0,
        'total_call_sec' => max(0, $totalTimeSec),
    ];
}

function buildForecastRow(array $parsedRow, string $format): array
{
    $intervalMinutes = 60;
    $ahtSeconds = (int) ($parsedRow['avg_talk_time_sec'] ?? 0);
    if ($ahtSeconds <= 0 && (int) ($parsedRow['answered_calls'] ?? 0) > 0) {
        $ahtSeconds = (int) round(((int) $parsedRow['total_talk_sec']) / (int) $parsedRow['answered_calls']);
    }
    if ($ahtSeconds <= 0) {
        $ahtSeconds = 20;
    }

    // Defaults operativos para que WFM Report pueda calcular staffing al subir AST/Inbound.
    $targetSl = 0.80;
    $targetAnswerSeconds = 20;
    $occupancyTarget = 0.85;
    $shrinkage = 0.30;
    $channel = 'Voice';

    if ($format === 'inbound_daily_report') {
        $channel = 'Inbound';
    }

    return [
        'interval_start' => $parsedRow['interval_start'],
        'interval_minutes' => $intervalMinutes,
        'offered_volume' => (int) ($parsedRow['offered_calls'] ?? 0),
        'aht_seconds' => $ahtSeconds,
        'target_sl' => $targetSl,
        'target_answer_seconds' => $targetAnswerSeconds,
        'occupancy_target' => $occupancyTarget,
        'shrinkage' => $shrinkage,
        'channel' => $channel,
    ];
}

$campaignId = isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : 0;
if ($campaignId <= 0) {
    jsonError('Campana invalida');
}

if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
    jsonError('Archivo no valido');
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

$forecastStmt = $pdo->prepare("
    INSERT INTO campaign_staffing_forecast
        (campaign_id, interval_start, interval_minutes, offered_volume, aht_seconds,
         target_sl, target_answer_seconds, occupancy_target, shrinkage, channel,
         source_filename, uploaded_by)
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
$parsed = 0;
$forecastInserted = 0;
$forecastUpdated = 0;

$format = null;
$inDataSection = false;

while (($row = fgetcsv($handle, 0, ',')) !== false) {
    $firstCell = trim((string) ($row[0] ?? ''));
    $firstNorm = normalizeHeader($firstCell);

    if ($format === null) {
        if ($firstNorm === 'shift_datetime_range') {
            $format = 'inbound_daily_report';
            $inDataSection = true;
            continue;
        }
        if ($firstNorm === 'calling_hour') {
            $format = 'ast_erlang';
            $inDataSection = true;
            continue;
        }
    }

    if (!$inDataSection || $firstCell === '') {
        continue;
    }

    if (
        stripos($firstCell, 'Week to date') !== false ||
        stripos($firstCell, 'WTD') !== false ||
        stripos($firstCell, 'TOTALS') !== false ||
        stripos($firstCell, 'Total calls:') !== false ||
        stripos($firstCell, 'Total drops:') !== false ||
        stripos($firstCell, 'Desired drop rate:') !== false ||
        stripos($firstCell, 'Total blocking/drop rate:') !== false ||
        stripos($firstCell, 'Desired sale rate:') !== false ||
        stripos($firstCell, 'Actual sale rate:') !== false ||
        stripos($firstCell, 'Average call duration:') !== false ||
        stripos($firstCell, 'Erlangs:') !== false ||
        stripos($firstCell, 'Estimated agents fielding calls:') !== false ||
        stripos($firstCell, 'Recommended agent count:') !== false
    ) {
        continue;
    }

    $parsedRow = null;
    if ($format === 'ast_erlang') {
        $parsedRow = parseAstErlangRow($row);
    } elseif ($format === 'inbound_daily_report') {
        $parsedRow = parseInboundDailyRow($row);
    }

    if ($parsedRow === null) {
        $skipped++;
        continue;
    }

    $stmt->execute([
        $campaignId,
        $parsedRow['interval_start'],
        $parsedRow['offered_calls'],
        $parsedRow['answered_calls'],
        $parsedRow['agents_answered'],
        $parsedRow['abandoned_calls'],
        $parsedRow['abandon_percent'],
        $parsedRow['avg_abandon_time_sec'],
        $parsedRow['avg_answer_speed_sec'],
        $parsedRow['avg_talk_time_sec'],
        $parsedRow['total_talk_sec'],
        $parsedRow['total_wrap_sec'],
        $parsedRow['total_call_sec'],
        $originalName,
        $_SESSION['user_id'] ?? null
    ]);

    $forecastRow = buildForecastRow($parsedRow, (string) $format);
    $forecastStmt->execute([
        $campaignId,
        $forecastRow['interval_start'],
        $forecastRow['interval_minutes'],
        $forecastRow['offered_volume'],
        $forecastRow['aht_seconds'],
        $forecastRow['target_sl'],
        $forecastRow['target_answer_seconds'],
        $forecastRow['occupancy_target'],
        $forecastRow['shrinkage'],
        $forecastRow['channel'],
        $originalName,
        $_SESSION['user_id'] ?? null
    ]);

    $parsed++;
    $affected = $stmt->rowCount();
    if ($affected === 1) {
        $inserted++;
    } elseif ($affected === 2) {
        $updated++;
    } else {
        $skipped++;
    }

    $forecastAffected = $forecastStmt->rowCount();
    if ($forecastAffected === 1) {
        $forecastInserted++;
    } elseif ($forecastAffected === 2) {
        $forecastUpdated++;
    }
}

fclose($handle);

if ($format === null || $parsed === 0) {
    jsonError('Formato CSV no reconocido o sin filas validas. Usa AST Erlang (CALLING HOUR) o Inbound Daily Report.');
}

echo json_encode([
    'success' => true,
    'format' => $format,
    'inserted' => $inserted,
    'updated' => $updated,
    'skipped' => $skipped,
    'parsed' => $parsed,
    'forecast_inserted' => $forecastInserted,
    'forecast_updated' => $forecastUpdated
]);
