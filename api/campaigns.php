<?php
/**
 * API para Gestión de Campañas
 * Endpoints para crear, listar, editar y eliminar campañas
 */

// Deshabilitar visualización de errores en producción
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Limpiar cualquier salida previa
ob_start();

session_start();
require_once '../db.php';

// Limpiar buffer y descartar cualquier salida generada
ob_end_clean();

// Iniciar nuevo buffer para capturar solo JSON
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Verificar que el usuario tenga permisos
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    ob_end_flush();
    exit;
}

// Verificar permiso manage_campaigns
if (!userHasPermission('manage_campaigns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para gestionar campañas']);
    ob_end_flush();
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($pdo, $action);
            break;
        case 'POST':
            handlePost($pdo, $action);
            break;
        case 'PUT':
            handlePut($pdo);
            break;
        case 'DELETE':
            handleDelete($pdo);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    }
    ob_end_flush();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    ob_end_flush();
}

function handleGet($pdo, $action) {
    switch ($action) {
        case 'list':
            // Listar todas las campañas
            $stmt = $pdo->query("
                SELECT c.*, 
                    u.full_name as created_by_name,
                    (SELECT COUNT(*) FROM employees WHERE campaign_id = c.id) as agent_count,
                    (SELECT COUNT(*) FROM supervisor_campaigns WHERE campaign_id = c.id) as supervisor_count
                FROM campaigns c
                LEFT JOIN users u ON c.created_by = u.id
                ORDER BY c.is_active DESC, c.name ASC
            ");
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'campaigns' => $campaigns
            ]);
            break;
            
        case 'active':
            // Listar solo campañas activas
            $stmt = $pdo->query("
                SELECT c.*, 
                    (SELECT COUNT(*) FROM employees WHERE campaign_id = c.id) as agent_count
                FROM campaigns c
                WHERE c.is_active = 1
                ORDER BY c.name ASC
            ");
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'campaigns' => $campaigns
            ]);
            break;
            
        case 'get':
            // Obtener una campaña específica
            $id = $_GET['id'] ?? 0;
            $stmt = $pdo->prepare("
                SELECT c.*, 
                    u.full_name as created_by_name,
                    (SELECT COUNT(*) FROM employees WHERE campaign_id = c.id) as agent_count,
                    (SELECT COUNT(*) FROM supervisor_campaigns WHERE campaign_id = c.id) as supervisor_count
                FROM campaigns c
                LEFT JOIN users u ON c.created_by = u.id
                WHERE c.id = ?
            ");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Campaña no encontrada']);
                return;
            }
            
            // Obtener supervisores asignados
            $stmt = $pdo->prepare("
                SELECT sc.*, u.full_name, u.username
                FROM supervisor_campaigns sc
                JOIN users u ON sc.supervisor_id = u.id
                WHERE sc.campaign_id = ?
            ");
            $stmt->execute([$id]);
            $campaign['supervisors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Obtener agentes asignados
            $stmt = $pdo->prepare("
                SELECT e.id, e.user_id, e.first_name, e.last_name, e.employee_code,
                    u.username, u.role
                FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE e.campaign_id = ?
                ORDER BY e.first_name, e.last_name
            ");
            $stmt->execute([$id]);
            $campaign['agents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'campaign' => $campaign
            ]);
            break;
            
        case 'supervisors':
            // Listar supervisores disponibles para asignar
            $stmt = $pdo->query("
                SELECT u.id, u.username, u.full_name, u.employee_code,
                    (SELECT COUNT(*) FROM supervisor_campaigns WHERE supervisor_id = u.id) as campaign_count
                FROM users u
                WHERE u.role IN ('Supervisor', 'Admin', 'HR') AND u.is_active = 1
                ORDER BY u.full_name ASC
            ");
            $supervisors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'supervisors' => $supervisors
            ]);
            break;
            
        case 'my_campaigns':
            // Obtener campañas asignadas al supervisor actual
            $userId = $_SESSION['user_id'];
            $stmt = $pdo->prepare("
                SELECT c.*,
                    (SELECT COUNT(*) FROM employees WHERE campaign_id = c.id) as agent_count
                FROM campaigns c
                INNER JOIN supervisor_campaigns sc ON c.id = sc.campaign_id
                WHERE sc.supervisor_id = ? AND c.is_active = 1
                ORDER BY c.name ASC
            ");
            $stmt->execute([$userId]);
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'campaigns' => $campaigns
            ]);
            break;
            
        case 'get_employees':
            // Obtener empleados de una campaña específica
            $campaignId = $_GET['campaign_id'] ?? 0;
            
            // Obtener información de la campaña
            $stmt = $pdo->prepare("SELECT id, name, code, color FROM campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Campaña no encontrada']);
                return;
            }
            
            // Obtener empleados asignados a esta campaña
            $stmt = $pdo->prepare("
                SELECT e.id, e.user_id, e.employee_code, e.position,
                    CONCAT(e.first_name, ' ', e.last_name) as full_name,
                    u.username, u.role
                FROM employees e
                LEFT JOIN users u ON e.user_id = u.id
                WHERE e.campaign_id = ?
                ORDER BY e.first_name, e.last_name
            ");
            $stmt->execute([$campaignId]);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'campaign' => $campaign,
                'employees' => $employees
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
}

function handlePost($pdo, $action) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($action === 'create') {
        // Crear nueva campaña
        $name = trim($data['name'] ?? '');
        $code = trim($data['code'] ?? '');
        $description = trim($data['description'] ?? '');
        $color = trim($data['color'] ?? '#6366f1');
        $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        
        if (empty($name) || empty($code)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nombre y código son obligatorios']);
            return;
        }
        
        // Verificar que el código sea único
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE code = ?");
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'El código de campaña ya existe']);
            return;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO campaigns (name, code, description, color, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $code, $description, $color, $is_active, $_SESSION['user_id']]);
        
        $campaignId = $pdo->lastInsertId();
        
        // Log activity
        require_once '../lib/logging_functions.php';
        log_custom_action(
            $pdo,
            $_SESSION['user_id'],
            $_SESSION['full_name'],
            $_SESSION['role'],
            'campaigns',
            'create',
            "Campaña creada: {$name}",
            'campaign',
            $campaignId,
            ['name' => $name, 'code' => $code]
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Campaña creada exitosamente',
            'campaign_id' => $campaignId
        ]);
        
    } elseif ($action === 'assign_supervisor') {
        // Asignar supervisor a campaña
        $supervisor_id = (int)($data['supervisor_id'] ?? 0);
        $campaign_id = (int)($data['campaign_id'] ?? 0);
        
        if (!$supervisor_id || !$campaign_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
            return;
        }
        
        // Verificar que el supervisor existe
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? AND role IN ('Supervisor', 'Admin', 'HR')");
        $stmt->execute([$supervisor_id]);
        $supervisor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supervisor) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Supervisor no encontrado']);
            return;
        }
        
        // Verificar que la campaña existe
        $stmt = $pdo->prepare("SELECT name FROM campaigns WHERE id = ?");
        $stmt->execute([$campaign_id]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$campaign) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Campaña no encontrada']);
            return;
        }
        
        // Insertar asignación (o ignorar si ya existe)
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO supervisor_campaigns (supervisor_id, campaign_id, assigned_by)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$supervisor_id, $campaign_id, $_SESSION['user_id']]);
        
        if ($stmt->rowCount() > 0) {
            require_once '../lib/logging_functions.php';
            log_custom_action(
                $pdo,
                $_SESSION['user_id'],
                $_SESSION['full_name'],
                $_SESSION['role'],
                'campaigns',
                'assign',
                "Supervisor {$supervisor['full_name']} asignado a campaña {$campaign['name']}",
                'supervisor_campaign',
                null,
                ['supervisor_id' => $supervisor_id, 'campaign_id' => $campaign_id]
            );
            
            echo json_encode([
                'success' => true,
                'message' => 'Supervisor asignado exitosamente'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'El supervisor ya estaba asignado a esta campaña'
            ]);
        }
        
    } elseif ($action === 'unassign_supervisor') {
        // Desasignar supervisor de campaña
        $supervisor_id = (int)($data['supervisor_id'] ?? 0);
        $campaign_id = (int)($data['campaign_id'] ?? 0);
        
        $stmt = $pdo->prepare("
            DELETE FROM supervisor_campaigns 
            WHERE supervisor_id = ? AND campaign_id = ?
        ");
        $stmt->execute([$supervisor_id, $campaign_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Supervisor desasignado exitosamente'
        ]);
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
}

function handlePut($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID de campaña requerido']);
        return;
    }
    
    $name = trim($data['name'] ?? '');
    $code = trim($data['code'] ?? '');
    $description = trim($data['description'] ?? '');
    $color = trim($data['color'] ?? '#6366f1');
    $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    
    if (empty($name) || empty($code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nombre y código son obligatorios']);
        return;
    }
    
    // Verificar que el código sea único (excluyendo la campaña actual)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE code = ? AND id != ?");
    $stmt->execute([$code, $id]);
    if ($stmt->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El código de campaña ya existe']);
        return;
    }
    
    $stmt = $pdo->prepare("
        UPDATE campaigns 
        SET name = ?, code = ?, description = ?, color = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([$name, $code, $description, $color, $is_active, $id]);
    
    // Log activity
    require_once '../lib/logging_functions.php';
    log_custom_action(
        $pdo,
        $_SESSION['user_id'],
        $_SESSION['full_name'],
        $_SESSION['role'],
        'campaigns',
        'update',
        "Campaña actualizada: {$name}",
        'campaign',
        $id,
        ['name' => $name, 'code' => $code, 'is_active' => $is_active]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Campaña actualizada exitosamente'
    ]);
}

function handleDelete($pdo) {
    $id = (int)($_GET['id'] ?? 0);
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID de campaña requerido']);
        return;
    }
    
    // Verificar si hay agentes asignados
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE campaign_id = ?");
    $stmt->execute([$id]);
    $agentCount = $stmt->fetchColumn();
    
    if ($agentCount > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => "No se puede eliminar la campaña porque tiene {$agentCount} agente(s) asignado(s). Primero reasigne los agentes a otra campaña."
        ]);
        return;
    }
    
    // Obtener nombre antes de eliminar para el log
    $stmt = $pdo->prepare("SELECT name FROM campaigns WHERE id = ?");
    $stmt->execute([$id]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$campaign) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Campaña no encontrada']);
        return;
    }
    
    // Eliminar campaña (las asignaciones de supervisores se eliminarán automáticamente por CASCADE)
    $stmt = $pdo->prepare("DELETE FROM campaigns WHERE id = ?");
    $stmt->execute([$id]);
    
    // Log activity
    require_once '../lib/logging_functions.php';
    log_custom_action(
        $pdo,
        $_SESSION['user_id'],
        $_SESSION['full_name'],
        $_SESSION['role'],
        'campaigns',
        'delete',
        "Campaña eliminada: {$campaign['name']}",
        'campaign',
        $id,
        ['name' => $campaign['name']]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Campaña eliminada exitosamente'
    ]);
}
