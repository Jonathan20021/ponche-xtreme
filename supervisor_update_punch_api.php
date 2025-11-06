<?php
session_start();

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/logging_functions.php';

if (!isset($_SESSION['user_id']) || !userHasPermission('supervisor_dashboard')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Debug: registrar lo que se recibió
error_log("Raw input: " . $rawInput);
error_log("Decoded input: " . print_r($input, true));
error_log("JSON decode error: " . json_last_error_msg());

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

$punchId = isset($input['punch_id']) ? (int) $input['punch_id'] : 0;
$targetUserId = isset($input['user_id']) ? (int) $input['user_id'] : 0;
$newTypeSlug = sanitizeAttendanceTypeSlug($input['new_type'] ?? '');
$newTime = isset($input['new_time']) ? trim($input['new_time']) : null;

error_log("Parsed values - punchId: $punchId, targetUserId: $targetUserId, newTypeSlug: $newTypeSlug, newTime: $newTime");

if ($punchId <= 0 || $targetUserId <= 0 || $newTypeSlug === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Datos incompletos para actualizar el punch.',
        'debug' => [
            'punch_id' => $punchId,
            'user_id' => $targetUserId,
            'new_type' => $newTypeSlug,
            'new_time' => $newTime,
            'input' => $input
        ]
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $punchStmt = $pdo->prepare("
        SELECT id, user_id, type, timestamp
        FROM attendance
        WHERE id = ?
        FOR UPDATE
    ");
    $punchStmt->execute([$punchId]);
    $punch = $punchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$punch) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Registro de punch no encontrado.']);
        exit;
    }

    if ((int) $punch['user_id'] !== $targetUserId) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No puedes modificar punch de otro usuario.']);
        exit;
    }

    $typeStmt = $pdo->prepare("
        SELECT slug, is_unique_daily
        FROM attendance_types
        WHERE UPPER(slug) = ?
          AND is_active = 1
        LIMIT 1
    ");
    $typeStmt->execute([$newTypeSlug]);
    $typeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$typeRow) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tipo de punch inv\u00e1lido o inactivo.']);
        exit;
    }

    $isUniqueDaily = (int)($typeRow['is_unique_daily'] ?? 0) === 1;

    if ($isUniqueDaily) {
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM attendance
            WHERE user_id = ?
              AND type = ?
              AND DATE(timestamp) = DATE(?)
              AND id <> ?
        ");
        $checkStmt->execute([$targetUserId, $newTypeSlug, $punch['timestamp'], $punchId]);
        $alreadyExists = (int)$checkStmt->fetchColumn() > 0;

        if ($alreadyExists) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Ya existe un punch de este tipo para la fecha seleccionada.']);
            exit;
        }
    }

    // Construir la query de actualización dinámicamente
    $updates = ['type = ?'];
    $params = [$newTypeSlug];
    
    // Si se proporcionó una nueva hora, actualizar el timestamp
    if ($newTime !== null && $newTime !== '') {
        // Validar formato de hora HH:MM
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $newTime)) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Formato de hora inválido. Use HH:MM']);
            exit;
        }
        
        // Mantener la fecha original pero cambiar la hora
        $updates[] = 'timestamp = CONCAT(DATE(timestamp), " ", ?)';
        $params[] = $newTime . ':00';
    }
    
    $params[] = $punchId;
    
    $updateQuery = "UPDATE attendance SET " . implode(', ', $updates) . " WHERE id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute($params);

    $pdo->commit();

    $supervisorId = (int) $_SESSION['user_id'];
    $supervisorName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Supervisor';
    $supervisorRole = $_SESSION['role'] ?? 'Supervisor';

    $logMessage = "Cambio de punch desde supervisor dashboard";
    $logDetails = [
        'previous_type' => $punch['type'],
        'new_type' => $newTypeSlug,
        'previous_timestamp' => $punch['timestamp']
    ];
    
    if ($newTime !== null && $newTime !== '') {
        $logMessage .= " (tipo y hora)";
        $logDetails['new_time'] = $newTime;
    } else {
        $logMessage .= " (solo tipo)";
    }

    log_custom_action(
        $pdo,
        $supervisorId,
        $supervisorName,
        $supervisorRole,
        'attendance',
        'update',
        $logMessage,
        'attendance_record',
        $punchId,
        $logDetails
    );

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al actualizar el punch.',
        'message' => $e->getMessage()
    ]);
}
