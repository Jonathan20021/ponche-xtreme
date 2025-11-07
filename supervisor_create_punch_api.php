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

error_log("CREATE - Parsed values - targetUserId: $targetUserId, typeSlug: $typeSlug");

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
              AND DATE(timestamp) = CURDATE()
        ");
        $checkStmt->execute([$targetUserId, $typeSlug]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Ya existe un punch de este tipo registrado hoy.']);
            exit;
        }
    }

    // Validar secuencia ENTRY/EXIT
    $sequenceValidation = validateEntryExitSequence($pdo, $targetUserId, $typeSlug);
    if (!$sequenceValidation['valid']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $sequenceValidation['message']]);
        exit;
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if ($ipAddress === '::1') {
        $ipAddress = '127.0.0.1';
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO attendance (user_id, type, ip_address, timestamp)
        VALUES (?, ?, ?, NOW())
    ");
    $insertStmt->execute([$targetUserId, $typeSlug, $ipAddress]);

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
            'ip_address' => $ipAddress
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
