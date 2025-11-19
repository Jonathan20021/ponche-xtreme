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

    // Validar ID
    if ($id <= 0) {
        $_SESSION['error'] = "ID de registro inválido.";
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

    // Delete record
    $query = "DELETE FROM attendance WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);

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
