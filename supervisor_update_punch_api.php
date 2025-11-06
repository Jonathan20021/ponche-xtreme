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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Solicitud inv\u00e1lida.']);
    exit;
}

$punchId = isset($input['punch_id']) ? (int) $input['punch_id'] : 0;
$targetUserId = isset($input['user_id']) ? (int) $input['user_id'] : 0;
$newTypeSlug = sanitizeAttendanceTypeSlug($input['new_type'] ?? '');

if ($punchId <= 0 || $targetUserId <= 0 || $newTypeSlug === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Datos incompletos para actualizar el punch.']);
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

    if (strtoupper($punch['type']) === $newTypeSlug) {
        $pdo->rollBack();
        echo json_encode(['success' => true, 'message' => 'El punch ya tiene el tipo seleccionado.']);
        exit;
    }

    $updateStmt = $pdo->prepare("
        UPDATE attendance
        SET type = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$newTypeSlug, $punchId]);

    $pdo->commit();

    $supervisorId = (int) $_SESSION['user_id'];
    $supervisorName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Supervisor';
    $supervisorRole = $_SESSION['role'] ?? 'Supervisor';

    log_custom_action(
        $pdo,
        $supervisorId,
        $supervisorName,
        $supervisorRole,
        'attendance',
        'update',
        "Cambio de punch desde supervisor dashboard: {$punch['type']} -> {$newTypeSlug}",
        'attendance_record',
        $punchId,
        [
            'previous_type' => $punch['type'],
            'new_type' => $newTypeSlug,
            'timestamp' => $punch['timestamp']
        ]
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
