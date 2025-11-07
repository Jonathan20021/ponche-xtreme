<?php
session_start();
include '../db.php';
require_once '../lib/authorization_functions.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Helper function to send JSON response
function jsonResponse($success, $message, $data = null, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Get method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Log request for debugging
error_log("Authorization API - Method: $method, Action: $action, User: " . ($_SESSION['user_id'] ?? 'none'));

// Handle code_details action
if ($action === 'code_details') {
    // Check authentication
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(false, 'No autenticado', null, 401);
    }

    if ($method !== 'GET') {
        jsonResponse(false, 'Método no permitido', null, 405);
    }

    // Verify permissions
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['admin', 'developer', 'hr_manager', 'supervisor'])) {
        jsonResponse(false, 'Sin permisos. Tu rol es: ' . $role, null, 403);
    }

    $codeId = (int)($_GET['id'] ?? 0);
    if ($codeId <= 0) {
        jsonResponse(false, 'ID de código inválido: ' . ($_GET['id'] ?? 'no proporcionado'), null, 400);
    }

    try {
        // Get code details
        $codeStmt = $pdo->prepare("
            SELECT 
                id, code, code_name, role_type, usage_context, 
                is_active, valid_from, valid_until, max_uses, 
                current_uses, created_by, created_at, updated_at
            FROM authorization_codes 
            WHERE id = ?
        ");
        $codeStmt->execute([$codeId]);
        $code = $codeStmt->fetch(PDO::FETCH_ASSOC);

        if (!$code) {
            jsonResponse(false, 'Código no encontrado con ID: ' . $codeId, null, 404);
        }

        // Get usage history
        $historyStmt = $pdo->prepare("
            SELECT 
                acl.id,
                acl.used_at,
                acl.usage_context,
                acl.reference_id,
                acl.reference_table,
                acl.ip_address,
                u.full_name as user_name,
                u.username
            FROM authorization_code_logs acl
            JOIN users u ON acl.user_id = u.id
            WHERE acl.authorization_code_id = ?
            ORDER BY acl.used_at DESC
            LIMIT 50
        ");
        $historyStmt->execute([$codeId]);
        $usageHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Format usage history with reference info
        foreach ($usageHistory as &$usage) {
            $refInfo = '';
            if ($usage['reference_table'] && $usage['reference_id']) {
                $refInfo = ucfirst($usage['reference_table']) . ' #' . $usage['reference_id'];
            }
            $usage['reference_info'] = $refInfo;
        }

        jsonResponse(true, 'Detalles obtenidos exitosamente', [
            'code' => $code,
            'usage_history' => $usageHistory
        ]);

    } catch (PDOException $e) {
        error_log("Database error in code_details: " . $e->getMessage());
        jsonResponse(false, 'Error de base de datos: ' . $e->getMessage(), null, 500);
    }
}

// If we got here, action not found
jsonResponse(false, 'Acción no válida: ' . $action, null, 400);
?>
