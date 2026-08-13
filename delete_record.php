<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';
require_once 'lib/logging_functions.php';
require_once 'lib/authorization_functions.php';

// Check permission
ensurePermission('records');

// Acceso a Modificaciones restringido a los usuarios configurados en settings
if (!canUserModifyRecords($pdo)) {
    $_SESSION['error'] = 'No tienes autorización para eliminar registros de asistencia.';
    header('Location: records.php');
    exit;
}

function getSupervisorAccessClause(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['role'] ?? '';

    if ($role !== 'Supervisor' || $userId <= 0) {
        $cache = ['', []];
        return $cache;
    }

    $campaignStmt = $pdo->prepare("SELECT campaign_id FROM supervisor_campaigns WHERE supervisor_id = ?");
    $campaignStmt->execute([$userId]);
    $campaigns = array_map('intval', $campaignStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $conditions = [
        'users.id = ?',
        'e.supervisor_id = ?'
    ];
    $params = [$userId, $userId];

    if (!empty($campaigns)) {
        $placeholders = implode(',', array_fill(0, count($campaigns), '?'));
        $conditions[] = "e.campaign_id IN ($placeholders)";
        $params = array_merge($params, $campaigns);
    }

    $cache = [' AND (' . implode(' OR ', $conditions) . ')', $params];
    return $cache;
}

function fetchAttendanceRecord(PDO $pdo, int $recordId): ?array
{
    [$clause, $params] = getSupervisorAccessClause($pdo);
    $sql = "
        SELECT 
            attendance.*,
            users.full_name,
            users.username
        FROM attendance
        JOIN users ON attendance.user_id = users.id
        LEFT JOIN employees e ON e.user_id = users.id
        WHERE attendance.id = ?
    ";

    $stmt = $pdo->prepare($sql . $clause);
    $stmt->execute(array_merge([$recordId], $params));
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    return $record ?: null;
}

// This file should only be accessed via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Acceso no válido. Este archivo solo puede ser llamado mediante POST.";
    header('Location: records.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $authorizationCode = $_POST['authorization_code'] ?? '';
    $deleteReason = trim((string) ($_POST['notes'] ?? $_POST['reason'] ?? ''));

    // Validar ID
    if ($id <= 0) {
        $_SESSION['error'] = "ID de registro inválido.";
        header('Location: records.php');
        exit;
    }

    // El motivo es obligatorio: borrar un punch cambia las horas pagadas y tiene
    // que quedar justificado en la bitácora, igual que el ajuste de Vicidial.
    if ($deleteReason === '') {
        $_SESSION['error'] = 'El motivo es obligatorio para eliminar un registro: queda en la bitácora de auditoría del ponche.';
        header('Location: records.php');
        exit;
    }

    // Check if authorization is required for delete
    if (isAuthorizationRequiredForContext($pdo, 'delete_records')) {
        // Validate authorization code
        if (empty($authorizationCode)) {
            $_SESSION['error'] = "Se requiere un código de autorización para eliminar registros.";
            header('Location: records.php');
            exit;
        }

        $validation = validateAuthorizationCode(
            $pdo,
            $authorizationCode,
            'delete_records',
            $_SESSION['user_id']
        );

        if (!$validation['valid']) {
            $_SESSION['error'] = "Código de autorización inválido: " . $validation['error'];
            header('Location: records.php');
            exit;
        }

        $authCodeId = $validation['code_id'];
    } else {
        $authCodeId = null;
    }

    // Get record data before deleting for logging and validate supervisor scope
    $recordData = fetchAttendanceRecord($pdo, $id);
    
    if (!$recordData) {
        $_SESSION['error'] = "Registro no encontrado.";
        header('Location: records.php');
        exit;
    }

    // Historial de modificaciones del ponche: borrar un punch cambia las horas
    // del día, así que se guarda cómo estaban antes.
    require_once __DIR__ . '/lib/attendance_audit.php';
    require_once __DIR__ . '/lib/timesheet_control.php';
    $auditUserId   = (int) ($recordData['user_id'] ?? 0);
    $auditWorkDate = !empty($recordData['timestamp']) ? date('Y-m-d', strtotime($recordData['timestamp'])) : date('Y-m-d');

    // Candado del procedimiento: día cerrado, período bloqueado o ventana vencida.
    $guard = timesheetGuard($pdo, $auditUserId, $auditWorkDate, [
        'context'   => 'delete_records',
        'auth_code' => $authorizationCode,
        'reason'    => $deleteReason,
    ]);
    if (!$guard['allowed']) {
        $_SESSION['error'] = $guard['message'];
        header('Location: records.php');
        exit;
    }

    $auditBefore   = attendanceAuditSnapshot($pdo, $auditUserId, $auditWorkDate);

    // El punch eliminado se archiva ANTES de borrarlo: el ponche original no se
    // destruye nunca, solo deja de contar para las horas.
    try {
        $pdo->prepare("
            INSERT INTO attendance_voided
                (attendance_id, user_id, work_date, type, timestamp, ip_address,
                 row_json, reason, source, voided_by, authorization_code_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'delete_record', ?, ?)
        ")->execute([
            $id,
            $auditUserId,
            $auditWorkDate,
            $recordData['type'] ?? '',
            $recordData['timestamp'] ?? null,
            $recordData['ip_address'] ?? null,
            json_encode($recordData, JSON_UNESCAPED_UNICODE),
            mb_substr($deleteReason, 0, 255),
            $_SESSION['user_id'] ?? null,
            $authCodeId ?: ($guard['code_id'] ?? null),
        ]);
    } catch (Throwable $e) {
        // Sin archivo no se borra: perder la evidencia es peor que no borrar.
        error_log('delete_record archivo: ' . $e->getMessage());
        $_SESSION['error'] = 'No se pudo archivar el registro antes de eliminarlo. '
            . 'El punch NO fue eliminado (la evidencia no se puede perder).';
        header('Location: records.php');
        exit;
    }

    // Delete record
    $query = "DELETE FROM attendance WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);

    attendanceAuditRecord($pdo, [
        'attendance_id' => null, // ya no existe
        'user_id'       => $auditUserId,
        'work_date'     => $auditWorkDate,
        'action'        => 'DELETE',
        'old_type'      => $recordData['type'] ?? null,
        'new_type'      => null,
        'old_timestamp' => $recordData['timestamp'] ?? null,
        'new_timestamp' => null,
        'reason'        => $deleteReason,
        'source'        => 'delete_record',
        'performed_by'  => $_SESSION['user_id'] ?? null,
        'stage_at_change'       => $guard['stage'] ?? null,
        'authorization_code_id' => $authCodeId ?: ($guard['code_id'] ?? null),
        'was_outside_window'    => $guard['outside_window'] ?? false,
        'was_after_close'       => $guard['after_close'] ?? false,
    ], $auditBefore);

    timesheetAfterChange($pdo, $auditUserId, $auditWorkDate, $guard, [
        'reason'      => $deleteReason,
        'source'      => 'delete_record',
        'old_seconds' => (int) ($auditBefore['work_seconds'] ?? 0),
        'new_seconds' => timesheetDayWorkSeconds($pdo, $auditUserId, $auditWorkDate),
    ]);

    // Log the authorization code usage
    if ($authCodeId) {
        logAuthorizationCodeUsage(
            $pdo,
            $authCodeId,
            $_SESSION['user_id'],
            'delete_records',
            $id,
            'attendance',
            [
                'record_data' => $recordData,
                'deleted_by' => $_SESSION['full_name']
            ]
        );
    }

    // Log the deletion
    log_custom_action(
        $pdo,
        $_SESSION['user_id'],
        $_SESSION['full_name'],
        $_SESSION['role'],
        'attendance',
        'delete',
        "Registro de asistencia eliminado: {$recordData['full_name']} - {$recordData['type']} - {$recordData['timestamp']}",
        'attendance_record',
        $id,
        $recordData
    );

    $_SESSION['message'] = "Registro con ID $id ha sido eliminado exitosamente.";
    header('Location: records.php');
    exit;
}
?>
