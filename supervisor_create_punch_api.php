<?php
session_start();

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/logging_functions.php';
require_once __DIR__ . '/lib/authorization_functions.php';

if (!isset($_SESSION['user_id']) || !userHasPermission('supervisor_dashboard')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Debug: registrar lo que se recibió
error_log("CREATE - Raw input: " . $rawInput);
error_log("CREATE - Decoded input: " . print_r($input, true));
error_log("CREATE - JSON decode error: " . json_last_error_msg());

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Solicitud inválida.',
        'debug' => [
            'raw_input' => $rawInput,
            'json_error' => json_last_error_msg()
        ]
    ]);
    exit;
}

$targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$typeSlug = sanitizeAttendanceTypeSlug($input['punch_type'] ?? '');
$newDate = isset($input['new_date']) ? trim($input['new_date']) : '';
$newTime = isset($input['new_time']) ? trim($input['new_time']) : '';

error_log("CREATE - Parsed values - targetUserId: $targetUserId, typeSlug: $typeSlug, newDate: $newDate, newTime: $newTime");

if ($targetUserId <= 0 || $typeSlug === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Datos incompletos para registrar el punch.',
        'debug' => [
            'user_id' => $targetUserId,
            'punch_type' => $typeSlug,
            'input' => $input
        ]
    ]);
    exit;
}

if ($newDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato de fecha inválido. Use YYYY-MM-DD']);
    exit;
}

if ($newTime !== '' && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $newTime)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato de hora inválido. Use HH:MM']);
    exit;
}

// Si se especifica fecha u hora, ambas son requeridas para construir un timestamp consistente
if (($newDate !== '') !== ($newTime !== '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debe proporcionar fecha y hora juntas para registrar un punch manual.']);
    exit;
}

$hasCustomTimestamp = ($newDate !== '' && $newTime !== '');
$effectiveDate = $hasCustomTimestamp ? $newDate : date('Y-m-d');
$isTodayCorrection = $effectiveDate === date('Y-m-d');

try {
    $typeStmt = $pdo->prepare("
        SELECT slug, label, is_unique_daily
        FROM attendance_types
        WHERE UPPER(slug) = ?
          AND is_active = 1
        LIMIT 1
    ");
    $typeStmt->execute([$typeSlug]);
    $typeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$typeRow) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tipo de punch inválido o inactivo.']);
        exit;
    }

    $isUniqueDaily = (int)($typeRow['is_unique_daily'] ?? 0) === 1;

    if ($isUniqueDaily) {
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM attendance
            WHERE user_id = ?
              AND type = ?
              AND DATE(timestamp) = ?
        ");
        $checkStmt->execute([$targetUserId, $typeSlug, $effectiveDate]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            http_response_code(409);
            $msg = $hasCustomTimestamp
                ? "Ya existe un punch de este tipo registrado el {$effectiveDate}."
                : 'Ya existe un punch de este tipo registrado hoy.';
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        }
    }

    // Validar secuencia ENTRY/EXIT solo cuando se registra en tiempo real (día de hoy sin hora personalizada).
    // En correcciones manuales de supervisor la secuencia se maneja de forma explícita.
    if (!$hasCustomTimestamp) {
        $sequenceValidation = validateEntryExitSequence($pdo, $targetUserId, $typeSlug);
        if (!$sequenceValidation['valid']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $sequenceValidation['message']]);
            exit;
        }
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if ($ipAddress === '::1') {
        $ipAddress = '127.0.0.1';
    }

    if ($hasCustomTimestamp) {
        $customTimestamp = $newDate . ' ' . $newTime . ':00';
        $insertStmt = $pdo->prepare("
            INSERT INTO attendance (user_id, type, ip_address, timestamp)
            VALUES (?, ?, ?, ?)
        ");
        $insertStmt->execute([$targetUserId, $typeSlug, $ipAddress, $customTimestamp]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO attendance (user_id, type, ip_address, timestamp)
            VALUES (?, ?, ?, NOW())
        ");
        $insertStmt->execute([$targetUserId, $typeSlug, $ipAddress]);
    }

    $recordId = (int)$pdo->lastInsertId();

    $supervisorId = (int)$_SESSION['user_id'];
    $supervisorName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Supervisor';
    $supervisorRole = $_SESSION['role'] ?? 'Supervisor';

    log_custom_action(
        $pdo,
        $supervisorId,
        $supervisorName,
        $supervisorRole,
        'attendance',
        'create',
        "Registro manual de punch desde supervisor dashboard: {$typeSlug}",
        'attendance_record',
        $recordId,
        [
            'type' => $typeSlug,
            'is_unique_daily' => $isUniqueDaily,
            'ip_address' => $ipAddress,
            'custom_timestamp' => $hasCustomTimestamp ? ($newDate . ' ' . $newTime) : null,
            'target_date' => $effectiveDate
        ]
    );

    echo json_encode(['success' => true, 'record_id' => $recordId]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al registrar el punch.',
        'message' => $e->getMessage()
    ]);
}
