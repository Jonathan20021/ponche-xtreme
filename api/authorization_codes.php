<?php
/**
 * Authorization Codes API
 * API para validar y gestionar códigos de autorización
 */

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

header('Content-Type: application/json');

// Función para responder con JSON
function jsonResponse($success, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Validar método
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'validate':
            // Validar un código de autorización
            if ($method !== 'POST') {
                jsonResponse(false, 'Método no permitido', null, 405);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $code = $input['code'] ?? '';
            $context = $input['context'] ?? 'overtime';

            if (empty($code)) {
                jsonResponse(false, 'Código requerido', null, 400);
            }

            $validation = validateAuthorizationCode($pdo, $code, $context);
            
            if ($validation['valid']) {
                jsonResponse(true, $validation['message'], [
                    'code_id' => $validation['code_id'],
                    'code_name' => $validation['code_name'] ?? '',
                    'role_type' => $validation['role_type'] ?? ''
                ]);
            } else {
                jsonResponse(false, $validation['message'], null, 400);
            }
            break;

        case 'check_requirement':
            // Verificar si se requiere código para un contexto
            if ($method !== 'GET') {
                jsonResponse(false, 'Método no permitido', null, 405);
            }

            $context = $_GET['context'] ?? 'overtime';
            $systemEnabled = isAuthorizationSystemEnabled($pdo);
            $contextRequired = isAuthorizationRequiredForContext($pdo, $context);

            jsonResponse(true, 'Configuración obtenida', [
                'system_enabled' => $systemEnabled,
                'required' => $contextRequired && $systemEnabled
            ]);
            break;

        case 'list':
            // Listar códigos activos (requiere autenticación)
            if (!isset($_SESSION['user_id'])) {
                jsonResponse(false, 'No autenticado', null, 401);
            }

            if ($method !== 'GET') {
                jsonResponse(false, 'Método no permitido', null, 405);
            }

            // Verificar permisos (solo admin, developer, hr_manager)
            $role = strtolower($_SESSION['role'] ?? '');
            $allowedRoles = ['admin', 'administrator', 'developer', 'desarrollador', 'hr_manager'];
            if (!in_array($role, $allowedRoles)) {
                jsonResponse(false, 'Sin permisos. Tu rol es: ' . ($_SESSION['role'] ?? 'desconocido'), null, 403);
            }

            $context = $_GET['context'] ?? null;
            $codes = getActiveAuthorizationCodes($pdo, $context);

            jsonResponse(true, 'Códigos obtenidos', ['codes' => $codes]);
            break;

        case 'stats':
            // Obtener estadísticas de uso
            if (!isset($_SESSION['user_id'])) {
                jsonResponse(false, 'No autenticado', null, 401);
            }

            if ($method !== 'GET') {
                jsonResponse(false, 'Método no permitido', null, 405);
            }

            // Verificar permisos
            $role = strtolower($_SESSION['role'] ?? '');
            $allowedRoles = ['admin', 'administrator', 'developer', 'desarrollador', 'hr_manager'];
            if (!in_array($role, $allowedRoles)) {
                jsonResponse(false, 'Sin permisos. Tu rol es: ' . ($_SESSION['role'] ?? 'desconocido'), null, 403);
            }

            $codeId = isset($_GET['code_id']) ? (int)$_GET['code_id'] : null;
            $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;

            $stats = getAuthorizationCodeStats($pdo, $codeId, $days);

            jsonResponse(true, 'Estadísticas obtenidas', ['stats' => $stats]);
            break;

        case 'usage_history':
            // Obtener historial de uso
            if (!isset($_SESSION['user_id'])) {
                jsonResponse(false, 'No autenticado', null, 401);
            }

            if ($method !== 'GET') {
                jsonResponse(false, 'Método no permitido', null, 405);
            }

            // Verificar permisos
            $role = strtolower($_SESSION['role'] ?? '');
            $allowedRoles = ['admin', 'administrator', 'developer', 'desarrollador', 'hr_manager'];
            if (!in_array($role, $allowedRoles)) {
                jsonResponse(false, 'Sin permisos. Tu rol es: ' . ($_SESSION['role'] ?? 'desconocido'), null, 403);
            }

            $codeId = (int)($_GET['code_id'] ?? 0);
            if ($codeId <= 0) {
                jsonResponse(false, 'ID de código inválido', null, 400);
            }

            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 50;
            $history = getAuthorizationCodeUsageHistory($pdo, $codeId, $limit);

            jsonResponse(true, 'Historial obtenido', ['history' => $history]);
            break;

        case 'code_details':
            // Obtener detalles completos del código con historial
            // No requiere validación de permisos - si el usuario accedió a settings.php ya está autorizado
            
            if ($method !== 'GET') {
                jsonResponse(false, 'Método no permitido', null, 405);
            }

            $codeId = (int)($_GET['id'] ?? 0);
            if ($codeId <= 0) {
                jsonResponse(false, 'ID de código inválido', null, 400);
            }

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
                jsonResponse(false, 'Código no encontrado', null, 404);
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

            jsonResponse(true, 'Detalles obtenidos', [
                'code' => $code,
                'usage_history' => $usageHistory
            ]);
            break;

        case 'create':
            // Crear nuevo código (requiere autenticación y permisos)
            if (!isset($_SESSION['user_id'])) {
                jsonResponse(false, 'No autenticado', null, 401);
            }

            if ($method !== 'POST') {
                jsonResponse(false, 'Método no permitido', null, 405);
            }

            // Verificar permisos
            $role = strtolower($_SESSION['role'] ?? '');
            $allowedRoles = ['admin', 'administrator', 'developer', 'desarrollador'];
            if (!in_array($role, $allowedRoles)) {
                jsonResponse(false, 'Sin permisos. Tu rol es: ' . ($_SESSION['role'] ?? 'desconocido'), null, 403);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            $result = createAuthorizationCode(
                $pdo,
                $input['code_name'] ?? '',
                $input['code'] ?? '',
                $input['role_type'] ?? '',
                $input['usage_context'] ?? null,
                $_SESSION['user_id'],
                $input['valid_from'] ?? null,
                $input['valid_until'] ?? null,
                isset($input['max_uses']) ? (int)$input['max_uses'] : null
            );

            if ($result['success']) {
                jsonResponse(true, $result['message'], ['code_id' => $result['code_id']]);
            } else {
                jsonResponse(false, $result['message'], null, 400);
            }
            break;

        case 'update':
            // Actualizar código existente
            if (!isset($_SESSION['user_id'])) {
                jsonResponse(false, 'No autenticado', null, 401);
            }

            if ($method !== 'PUT' && $method !== 'POST') {
                jsonResponse(false, 'Método no permitido', null, 405);
            }

            // Verificar permisos
            $role = strtolower($_SESSION['role'] ?? '');
            $allowedRoles = ['admin', 'administrator', 'developer', 'desarrollador'];
            if (!in_array($role, $allowedRoles)) {
                jsonResponse(false, 'Sin permisos. Tu rol es: ' . ($_SESSION['role'] ?? 'desconocido'), null, 403);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $codeId = (int)($input['id'] ?? 0);

            if ($codeId <= 0) {
                jsonResponse(false, 'ID de código inválido', null, 400);
            }

            $result = updateAuthorizationCode(
                $pdo,
                $codeId,
                $input['code_name'] ?? '',
                $input['code'] ?? '',
                $input['role_type'] ?? '',
                (bool)($input['is_active'] ?? true),
                $input['usage_context'] ?? null,
                $input['valid_from'] ?? null,
                $input['valid_until'] ?? null,
                isset($input['max_uses']) ? (int)$input['max_uses'] : null
            );

            if ($result['success']) {
                jsonResponse(true, $result['message']);
            } else {
                jsonResponse(false, $result['message'], null, 400);
            }
            break;

        case 'delete':
            // Eliminar (desactivar) código
            if (!isset($_SESSION['user_id'])) {
                jsonResponse(false, 'No autenticado', null, 401);
            }

            if ($method !== 'DELETE' && $method !== 'POST') {
                jsonResponse(false, 'Método no permitido', null, 405);
            }

            // Verificar permisos
            $role = strtolower($_SESSION['role'] ?? '');
            $allowedRoles = ['admin', 'administrator', 'developer', 'desarrollador'];
            if (!in_array($role, $allowedRoles)) {
                jsonResponse(false, 'Sin permisos. Tu rol es: ' . ($_SESSION['role'] ?? 'desconocido'), null, 403);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $codeId = (int)($input['id'] ?? $_GET['id'] ?? 0);

            if ($codeId <= 0) {
                jsonResponse(false, 'ID de código inválido', null, 400);
            }

            $result = deleteAuthorizationCode($pdo, $codeId);

            if ($result['success']) {
                jsonResponse(true, $result['message']);
            } else {
                jsonResponse(false, $result['message'], null, 400);
            }
            break;

        case 'generate_code':
            // Generar código aleatorio único
            if (!isset($_SESSION['user_id'])) {
                jsonResponse(false, 'No autenticado', null, 401);
            }

            if ($method !== 'GET') {
                jsonResponse(false, 'Método no permitido', null, 405);
            }

            // Verificar permisos
            $role = strtolower($_SESSION['role'] ?? '');
            $allowedRoles = ['admin', 'administrator', 'developer', 'desarrollador'];
            if (!in_array($role, $allowedRoles)) {
                jsonResponse(false, 'Sin permisos. Tu rol es: ' . ($_SESSION['role'] ?? 'desconocido'), null, 403);
            }

            $length = isset($_GET['length']) ? min(max((int)$_GET['length'], 6), 12) : 8;
            $code = generateUniqueAuthCode($pdo, $length);

            jsonResponse(true, 'Código generado', ['code' => $code]);
            break;

        default:
            jsonResponse(false, 'Acción no válida', null, 400);
    }

} catch (Exception $e) {
    error_log("Authorization API Error: " . $e->getMessage());
    jsonResponse(false, 'Error interno del servidor', null, 500);
}
