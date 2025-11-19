<?php
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

// Obtener el ID del registro a editar
if (!isset($_GET['id'])) {
    header('Location: records.php');
    exit;
}

$record_id = (int) $_GET['id'];
if ($record_id <= 0) {
    header('Location: records.php');
    exit;
}

$message = '';
$authCodeValidated = false;
$validatedAuthCodeId = null;

// Check if authorization code was provided via URL (from modal)
if (isset($_GET['auth_code']) && !empty($_GET['auth_code'])) {
    $authCodeFromUrl = trim($_GET['auth_code']);
    
    // Check if authorization is required
    if (isAuthorizationRequiredForContext($pdo, 'edit_records')) {
        $validation = validateAuthorizationCode(
            $pdo,
            $authCodeFromUrl,
            'edit_records',
            $_SESSION['user_id']
        );
        
        if ($validation['valid']) {
            $authCodeValidated = true;
            $validatedAuthCodeId = $validation['code_id'];
        } else {
            $_SESSION['error'] = "Código de autorización inválido: " . $validation['error'];
            header('Location: records.php');
            exit;
        }
    }
} else {
    // If auth is required but no code provided, redirect back
    if (isAuthorizationRequiredForContext($pdo, 'edit_records')) {
        $_SESSION['error'] = "Se requiere un código de autorización para editar registros.";
        header('Location: records.php');
        exit;
    }
}

// Obtener los datos del registro para prellenar el formulario, validando acceso del supervisor
$record = fetchAttendanceRecord($pdo, $record_id);

if (!$record) {
    header('Location: records.php');
    exit;
}

// Get all attendance types
$attendanceTypes = getAttendanceTypes($pdo, true);

// Procesar la edición del registro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = strtoupper(trim($_POST['type'] ?? ''));
    $timestamp = $_POST['timestamp'] ?? '';
    $ip_address = trim($_POST['ip_address'] ?? '');

    if ($type && $timestamp) {
        $authCodeId = $validatedAuthCodeId; // Use the pre-validated code ID
        $shouldUpdate = true;
        
        if (isset($shouldUpdate) && $shouldUpdate) {
            // Snapshot values before updating for logging
            $oldValues = [
                'type' => $record['type'],
                'timestamp' => $record['timestamp'],
                'ip_address' => $record['ip_address']
            ];
            
            // Update record
            $update_query = "UPDATE attendance SET type = ?, timestamp = ?, ip_address = ? WHERE id = ?";
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([$type, $timestamp, $ip_address, $record_id]);

            // Log the authorization code usage
            if ($authCodeId) {
                logAuthorizationCodeUsage(
                    $pdo,
                    $authCodeId,
                    $_SESSION['user_id'],
                    'edit_records',
                    $record_id,
                    'attendance',
                    [
                        'old_values' => $oldValues,
                        'new_values' => [
                            'type' => $type,
                            'timestamp' => $timestamp,
                            'ip_address' => $ip_address
                        ],
                        'edited_by' => $_SESSION['full_name']
                    ]
                );
            }

            // Log the modification
            $newValues = [
                'type' => $type,
                'timestamp' => $timestamp,
                'ip_address' => $ip_address
            ];
            
            log_attendance_modified(
                $pdo,
                $_SESSION['user_id'],
                $_SESSION['full_name'],
                $_SESSION['role'],
                $record_id,
                $record['full_name'],
                $oldValues,
                $newValues
            );

            $message = "Registro actualizado exitosamente.";
            header('Location: records.php');
            exit;
        }
    } else {
        $message = "Por favor completa todos los campos requeridos.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <title>Editar Registro de Asistencia</title>
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="container mx-auto mt-10">
        <h2 class="text-2xl font-bold mb-4">Editar Registro de Asistencia</h2>
        <?php if ($message): ?>
            <div class="bg-green-100 text-green-800 p-2 mb-4 rounded">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Mostrar Full Name y Username -->
        <div class="bg-white p-6 rounded shadow-md mb-4">
            <h3 class="text-lg font-semibold">Detalles del Empleado</h3>
            <p><strong>Nombre Completo:</strong> <?= htmlspecialchars($record['full_name']) ?></p>
            <p><strong>Usuario:</strong> <?= htmlspecialchars($record['username']) ?></p>
        </div>

        <!-- Formulario de edición -->
        <form method="POST" class="bg-white p-6 rounded shadow-md">
            <div class="mb-4">
                <label for="type" class="block text-sm font-bold mb-2">Tipo de Asistencia</label>
                <select name="type" id="type" class="p-2 border rounded w-full" required>
                    <option value="">Seleccionar tipo...</option>
                    <?php foreach ($attendanceTypes as $typeRow): ?>
                        <?php 
                        $slug = strtoupper($typeRow['slug']);
                        $label = $typeRow['label'] ?? $slug;
                        $isSelected = strtoupper($record['type']) === $slug ? 'selected' : '';
                        ?>
                        <option value="<?= htmlspecialchars($slug) ?>" <?= $isSelected ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label for="timestamp" class="block text-sm font-bold mb-2">Fecha y Hora</label>
                <input type="datetime-local" name="timestamp" id="timestamp" value="<?= date('Y-m-d\TH:i', strtotime($record['timestamp'])) ?>" class="p-2 border rounded w-full" required>
            </div>
            <div class="mb-4">
                <label for="ip_address" class="block text-sm font-bold mb-2">Dirección IP</label>
                <input type="text" name="ip_address" id="ip_address" value="<?= htmlspecialchars($record['ip_address']) ?>" class="p-2 border rounded w-full">
                <p class="text-xs text-gray-500 mt-1">Opcional - Dirección IP desde donde se registró</p>
            </div>
            
            <div class="flex gap-3">
                <button type="submit" class="bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-700">
                    <i class="fas fa-save"></i> Guardar Cambios
                </button>
                <a href="records.php" class="bg-gray-500 text-white py-2 px-4 rounded hover:bg-gray-700 inline-block">
                    <i class="fas fa-times"></i> Cancelar
                </a>
            </div>
        </form>
    </div>
</body>
</html>
