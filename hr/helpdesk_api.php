<?php
error_reporting(0);
ini_set('display_errors', 0);

// Capturar cualquier salida no deseada
ob_start();

try {
    session_start();
    require_once __DIR__ . '/../db.php';

    // helpdesk_functions.php usa `global $conn` (mysqli). db.php provee PDO ($pdo);
    // getMysqli() da la conexión mysqli con las mismas credenciales. Sin esto,
    // $conn era null -> fatal "prepare() on null" -> HTTP 500 vacío.
    $conn = getMysqli();

    // Limpiar cualquier salida previa
    ob_clean();
    
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // Public endpoints that don't require authentication
    $publicEndpoints = ['get_categories'];
    
    if (!in_array($action, $publicEndpoints)) {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
    }
    
    $userId = $_SESSION['user_id'] ?? null;
    $userRole = $_SESSION['role'] ?? 'employee';

    // Identidad del equipo de soporte (gestiona TODOS los tickets). Reemplaza los
    // chequeos rotos que comparaban 'Admin'/'HR' (y a veces en minúscula) y dejaban
    // fuera a IT/Desarrollador.
    require_once __DIR__ . '/../lib/helpdesk_support.php';
    $isSupport = isHelpdeskSupport($userRole);

    // Cargar funciones solo si es necesario
    if ($action !== 'get_categories') {
        require_once __DIR__ . '/../lib/helpdesk_functions.php';
        ensureHelpdeskSupportTables($conn);
    }
    
} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}

switch ($action) {
    case 'test':
        echo json_encode(['success' => true, 'message' => 'API funcionando correctamente', 'user_role' => $userRole]);
        break;
        
    case 'create_ticket':
        try {
            if (!function_exists('createTicket')) {
                require_once __DIR__ . '/../lib/helpdesk_functions.php';
            }
            
            $categoryId = intval($_POST['category_id']);
            $subject = trim($_POST['subject']);
            $description = trim($_POST['description']);
            $priority = $_POST['priority'] ?? 'medium';
            $createdByType = ($userRole === 'Admin' || $userRole === 'HR') ? 'admin' : 
                            (($userRole === 'agent') ? 'agent' : 'employee');
            
            if (empty($subject) || empty($description)) {
                echo json_encode(['success' => false, 'error' => 'Asunto y descripción son requeridos']);
                exit;
            }
            
            $result = createTicket($userId, $categoryId, $subject, $description, $priority, $createdByType);
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error al crear ticket: ' . $e->getMessage()]);
        }
        break;
        
    case 'get_tickets':
        try {
            // Limpiar buffer antes de empezar
            if (ob_get_length()) ob_clean();
            
            $query = "SELECT t.*, c.name as category_name, c.color as category_color,
                      u.full_name as user_name, u.username as user_email,
                      a.full_name as assigned_to_name
                      FROM helpdesk_tickets t
                      LEFT JOIN helpdesk_categories c ON t.category_id = c.id
                      LEFT JOIN users u ON t.user_id = u.id
                      LEFT JOIN users a ON t.assigned_to = a.id
                      WHERE 1=1";
            
            $params = [];
            $types = "";

            // Quien NO es soporte solo ve sus propios tickets.
            if (!$isSupport) {
                $query .= " AND t.user_id = ?";
                $params[] = $userId;
                $types .= "i";
            }

            if (!empty($_GET['status'])) {
                $query .= " AND t.status = ?";
                $params[] = $_GET['status'];
                $types .= "s";
            }
            if (!empty($_GET['priority'])) {
                $query .= " AND t.priority = ?";
                $params[] = $_GET['priority'];
                $types .= "s";
            }
            if (!empty($_GET['category_id'])) {
                $query .= " AND t.category_id = ?";
                $params[] = intval($_GET['category_id']);
                $types .= "i";
            }
            if (!empty($_GET['assigned_to'])) {
                $query .= " AND t.assigned_to = ?";
                $params[] = intval($_GET['assigned_to']);
                $types .= "i";
            }
            // Vistas rápidas (solo soporte)
            if ($isSupport && !empty($_GET['view'])) {
                if ($_GET['view'] === 'unassigned') {
                    $query .= " AND t.assigned_to IS NULL AND t.status NOT IN ('closed','cancelled')";
                } elseif ($_GET['view'] === 'mine') {
                    $query .= " AND t.assigned_to = ?";
                    $params[] = $userId;
                    $types .= "i";
                } elseif ($_GET['view'] === 'unresolved') {
                    $query .= " AND t.status NOT IN ('resolved','closed','cancelled')";
                }
            }
            // Búsqueda libre: número, asunto, descripción o solicitante.
            if (isset($_GET['search']) && trim($_GET['search']) !== '') {
                $s = '%' . trim($_GET['search']) . '%';
                $query .= " AND (t.ticket_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ? OR u.full_name LIKE ?)";
                array_push($params, $s, $s, $s, $s);
                $types .= "ssss";
            }

            // Total (para paginación) antes de aplicar LIMIT.
            $countSql = preg_replace('/^\s*SELECT.*?\sFROM\s/s', 'SELECT COUNT(*) AS c FROM ', $query, 1);
            $cstmt = $conn->prepare($countSql);
            if (!empty($params)) { $cstmt->bind_param($types, ...$params); }
            $cstmt->execute();
            $total = (int) (($cstmt->get_result()->fetch_assoc()['c']) ?? 0);

            // Orden: primero por prioridad, luego lo más nuevo.
            $query .= " ORDER BY FIELD(t.priority,'critical','high','medium','low'), t.created_at DESC";

            $limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 25;
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $offset = ($page - 1) * $limit;
            $query .= " LIMIT ? OFFSET ?";
            $params[] = $limit; $types .= "i";
            $params[] = $offset; $types .= "i";

            $stmt = $conn->prepare($query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            $tickets = [];
            while ($row = $result->fetch_assoc()) {
                $tickets[] = $row;
            }

            echo json_encode(['success' => true, 'tickets' => $tickets, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error loading tickets: ' . $e->getMessage()]);
        }
        break;
        
    case 'get_ticket':
        $ticketId = intval($_GET['ticket_id']);
        
        $query = "SELECT t.*, c.name as category_name, c.color as category_color,
                  u.full_name as user_name, u.username as user_email,
                  a.full_name as assigned_to_name, a.username as assigned_to_email
                  FROM helpdesk_tickets t
                  LEFT JOIN helpdesk_categories c ON t.category_id = c.id
                  LEFT JOIN users u ON t.user_id = u.id
                  LEFT JOIN users a ON t.assigned_to = a.id
                  WHERE t.id = ?";
        
        // Regular users can only see their own tickets
        if (!$isSupport) {
            $query .= " AND t.user_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ii", $ticketId, $userId);
        } else {
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $ticketId);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $ticket = $result->fetch_assoc();
            
            // Get comments
            $query = "SELECT c.*, u.full_name as user_name, '' as user_email
                      FROM helpdesk_comments c
                      JOIN users u ON c.user_id = u.id
                      WHERE c.ticket_id = ?";
            
            // Hide internal comments from regular users
            if (!$isSupport) {
                $query .= " AND c.is_internal = 0";
            }
            
            $query .= " ORDER BY c.created_at ASC";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $ticketId);
            $stmt->execute();
            $commentsResult = $stmt->get_result();
            
            $comments = [];
            while ($row = $commentsResult->fetch_assoc()) {
                $comments[] = $row;
            }
            
            $ticket['comments'] = $comments;
            
            // Get status history
            $query = "SELECT h.*, u.full_name as changed_by_name
                      FROM helpdesk_status_history h
                      JOIN users u ON h.changed_by = u.id
                      WHERE h.ticket_id = ?
                      ORDER BY h.created_at DESC";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $ticketId);
            $stmt->execute();
            $historyResult = $stmt->get_result();
            
            $history = [];
            while ($row = $historyResult->fetch_assoc()) {
                $history[] = $row;
            }
            
            $ticket['status_history'] = $history;
            $ticket['attachments'] = helpdeskGetTicketAttachments($conn, $ticketId);

            // Perfil completo del solicitante (campaña, proyecto/depto, supervisor,
            // posición, contacto, ingreso) para dar contexto total al soporte.
            $rq = null;
            $rqStmt = $conn->prepare("
                SELECT u.id, u.full_name, u.username, u.role,
                       e.employee_code, e.position, e.email, e.phone, e.mobile,
                       e.hire_date, e.employment_status, e.employment_type,
                       e.city, e.state, e.country,
                       c.name AS campaign_name, c.color AS campaign_color, c.code AS campaign_code,
                       d.name AS department_name,
                       s.full_name AS supervisor_name
                FROM users u
                LEFT JOIN employees e ON e.user_id = u.id
                LEFT JOIN campaigns c ON c.id = e.campaign_id
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN users s ON s.id = e.supervisor_id
                WHERE u.id = ? LIMIT 1
            ");
            $rqStmt->bind_param("i", $ticket['user_id']);
            $rqStmt->execute();
            $rq = $rqStmt->get_result()->fetch_assoc() ?: null;
            $ticket['requester'] = $rq;

            echo json_encode(['success' => true, 'ticket' => $ticket]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        }
        break;
        
    case 'assign_ticket':
        if (!$isSupport) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        $ticketId = intval($_POST['ticket_id']);
        $assignedTo = intval($_POST['assigned_to']);
        $notes = $_POST['notes'] ?? '';
        
        $result = assignTicket($ticketId, $assignedTo, $userId, $notes);
        echo json_encode(['success' => $result]);
        break;
        
    case 'update_status':
        $ticketId = intval($_POST['ticket_id']);
        $newStatus = $_POST['status'];
        $notes = $_POST['notes'] ?? '';
        
        // Check if user has permission to update this ticket
        $query = "SELECT user_id, assigned_to FROM helpdesk_tickets WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ticket = $result->fetch_assoc();
        
        $canUpdate = ($isSupport || 
                     $ticket['user_id'] == $userId || 
                     $ticket['assigned_to'] == $userId);
        
        if (!$canUpdate) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        $result = updateTicketStatus($ticketId, $newStatus, $userId, $notes);
        echo json_encode(['success' => $result]);
        break;
        
    case 'add_comment':
        $ticketId = intval($_POST['ticket_id']);
        $comment = trim($_POST['comment']);
        $isInternal = isset($_POST['is_internal']) ? intval($_POST['is_internal']) : 0;
        
        if (empty($comment)) {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']);
            exit;
        }
        
        // Check if user has access to this ticket
        $query = "SELECT user_id, assigned_to FROM helpdesk_tickets WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ticket = $result->fetch_assoc();
        
        $canComment = ($isSupport || 
                      $ticket['user_id'] == $userId || 
                      $ticket['assigned_to'] == $userId);
        
        if (!$canComment) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        // Only admins/hr can add internal comments
        if ($isInternal && !$isSupport) {
            $isInternal = 0;
        }
        
        $result = addTicketComment($ticketId, $userId, $comment, $isInternal);
        echo json_encode($result);
        break;
        
    case 'get_categories':
        $query = "SELECT * FROM helpdesk_categories ORDER BY name";
        $result = $conn->query($query);
        
        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
        
        echo json_encode(['success' => true, 'categories' => $categories]);
        break;
        
    case 'get_my_statistics':
        // Get statistics for current user's tickets
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved
                  FROM helpdesk_tickets
                  WHERE user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        
        echo json_encode(['success' => true, 'statistics' => $stats]);
        break;
        
    case 'get_my_tickets':
        // Get tickets for current user
        $query = "SELECT t.*, c.name as category_name, u.full_name as assigned_name
                  FROM helpdesk_tickets t
                  JOIN helpdesk_categories c ON t.category_id = c.id
                  LEFT JOIN users u ON t.assigned_to = u.id
                  WHERE t.user_id = ?";
        
        $params = [$userId];
        $types = "i";
        
        if (!empty($_GET['status'])) {
            $query .= " AND t.status = ?";
            $params[] = $_GET['status'];
            $types .= "s";
        }
        if (!empty($_GET['priority'])) {
            $query .= " AND t.priority = ?";
            $params[] = $_GET['priority'];
            $types .= "s";
        }
        if (!empty($_GET['category_id'])) {
            $query .= " AND t.category_id = ?";
            $params[] = intval($_GET['category_id']);
            $types .= "i";
        }
        
        $query .= " ORDER BY t.created_at DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            $tickets[] = $row;
        }
        
        echo json_encode(['success' => true, 'tickets' => $tickets]);
        break;
        
    case 'get_statistics':
        if (!$isSupport) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        $filters = [];
        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = $_GET['date_from'];
        }
        if (!empty($_GET['date_to'])) {
            $filters['date_to'] = $_GET['date_to'];
        }
        
        $stats = getTicketStatistics($filters);
        
        // Get tickets by category
        $query = "SELECT c.name, c.color, COUNT(t.id) as count
                  FROM helpdesk_categories c
                  LEFT JOIN helpdesk_tickets t ON c.id = t.category_id
                  GROUP BY c.id, c.name, c.color
                  ORDER BY count DESC";
        $result = $conn->query($query);
        
        $byCategory = [];
        while ($row = $result->fetch_assoc()) {
            $byCategory[] = $row;
        }
        
        $stats['by_category'] = $byCategory;
        
        // Get tickets by priority
        $query = "SELECT priority, COUNT(*) as count
                  FROM helpdesk_tickets
                  GROUP BY priority";
        $result = $conn->query($query);
        
        $byPriority = [];
        while ($row = $result->fetch_assoc()) {
            $byPriority[] = $row;
        }
        
        $stats['by_priority'] = $byPriority;
        
        echo json_encode(['success' => true, 'statistics' => $stats]);
        break;
        
    case 'get_my_notifications':
        $query = "SELECT n.*, t.ticket_number, t.subject
                  FROM helpdesk_notifications n
                  JOIN helpdesk_tickets t ON n.ticket_id = t.id
                  WHERE n.user_id = ?
                  ORDER BY n.created_at DESC
                  LIMIT 50";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
        
        echo json_encode(['success' => true, 'notifications' => $notifications]);
        break;
        
    case 'mark_notification_read':
        $notificationId = intval($_POST['notification_id']);
        
        $query = "UPDATE helpdesk_notifications 
                  SET is_read = 1 
                  WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $notificationId, $userId);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        break;
        
    case 'ai_suggest_response':
        if (!$isSupport) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        $ticketId = intval($_POST['ticket_id']);
        
        // Get ticket details
        $query = "SELECT subject, description FROM helpdesk_tickets WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ticket = $result->fetch_assoc();
        
        // Get all comments
        $query = "SELECT comment FROM helpdesk_comments WHERE ticket_id = ? ORDER BY created_at ASC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        $commentsResult = $stmt->get_result();
        
        $conversation = "Subject: {$ticket['subject']}\nDescription: {$ticket['description']}\n\n";
        while ($row = $commentsResult->fetch_assoc()) {
            $conversation .= "Comment: {$row['comment']}\n";
        }
        
        $prompt = "Based on this support ticket conversation, suggest a helpful response:\n\n$conversation\n\nProvide a professional and helpful response.";
        
        $response = callGeminiAPI($prompt);
        
        logAIInteraction($ticketId, 'suggestion', $prompt, $response);
        
        echo json_encode(['success' => true, 'suggestion' => $response]);
        break;
        
    case 'get_category':
        $categoryId = intval($_GET['id']);
        
        $query = "SELECT * FROM helpdesk_categories WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();
        $category = $result->fetch_assoc();
        
        if ($category) {
            echo json_encode(['success' => true, 'category' => $category]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Category not found']);
        }
        break;
        
    case 'create_category':
        if (!$isSupport) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $department = trim($_POST['department']);
        $color = $_POST['color'] ?? '#6366f1';
        $slaResponse = intval($_POST['sla_response_hours']);
        $slaResolution = intval($_POST['sla_resolution_hours']);
        
        $query = "INSERT INTO helpdesk_categories (name, description, department, color, sla_response_hours, sla_resolution_hours) 
                  VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssssii", $name, $description, $department, $color, $slaResponse, $slaResolution);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'category_id' => $conn->insert_id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to create category']);
        }
        break;
        
    case 'update_category':
        if (!$isSupport) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        $categoryId = intval($_POST['category_id']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $department = trim($_POST['department']);
        $color = $_POST['color'] ?? '#6366f1';
        $slaResponse = intval($_POST['sla_response_hours']);
        $slaResolution = intval($_POST['sla_resolution_hours']);
        
        $query = "UPDATE helpdesk_categories 
                  SET name = ?, description = ?, department = ?, color = ?, 
                      sla_response_hours = ?, sla_resolution_hours = ?
                  WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssssiii", $name, $description, $department, $color, $slaResponse, $slaResolution, $categoryId);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update category']);
        }
        break;
        
    case 'delete_category':
        if (!$isSupport) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        $categoryId = intval($_POST['category_id']);
        
        // Check if category has tickets
        $checkQuery = "SELECT COUNT(*) as count FROM helpdesk_tickets WHERE category_id = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['count'] > 0) {
            echo json_encode(['success' => false, 'error' => 'No se puede eliminar. Esta categoría tiene tickets asociados.']);
            exit;
        }
        
        $query = "DELETE FROM helpdesk_categories WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $categoryId);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to delete category']);
        }
        break;
        
    case 'get_agents':
        // Pool de asignables (equipo de soporte: IT, Desarrollador, Admin, HR).
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $roles = helpdeskSupportRoles();
        $in = "'" . implode("','", array_map(function ($r) use ($conn) { return $conn->real_escape_string($r); }, $roles)) . "'";
        $res = $conn->query("SELECT id, full_name, role FROM users WHERE is_active = 1 AND UPPER(role) IN ($in) ORDER BY full_name");
        $agents = [];
        while ($row = $res->fetch_assoc()) { $agents[] = $row; }
        echo json_encode(['success' => true, 'agents' => $agents]);
        break;

    case 'update_priority':
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $ticketId = intval($_POST['ticket_id']);
        $priority = $_POST['priority'] ?? '';
        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            echo json_encode(['success' => false, 'error' => 'Prioridad inválida']); break;
        }
        $stmt = $conn->prepare("UPDATE helpdesk_tickets SET priority = ? WHERE id = ?");
        $stmt->bind_param('si', $priority, $ticketId);
        echo json_encode(['success' => $stmt->execute()]);
        break;

    case 'update_ticket_category':
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $ticketId = intval($_POST['ticket_id']);
        $categoryId = intval($_POST['category_id']);
        $stmt = $conn->prepare("UPDATE helpdesk_tickets SET category_id = ? WHERE id = ?");
        $stmt->bind_param('ii', $categoryId, $ticketId);
        echo json_encode(['success' => $stmt->execute()]);
        break;

    case 'get_requester_tickets':
        // Otros tickets del mismo solicitante (panel de contexto). Solo soporte.
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $ticketId = intval($_GET['ticket_id']);
        $stmt = $conn->prepare("SELECT user_id FROM helpdesk_tickets WHERE id = ?");
        $stmt->bind_param('i', $ticketId); $stmt->execute();
        $owner = $stmt->get_result()->fetch_assoc();
        $rows = [];
        if ($owner) {
            $stmt = $conn->prepare("SELECT id, ticket_number, subject, status, priority, created_at FROM helpdesk_tickets WHERE user_id = ? AND id <> ? ORDER BY created_at DESC LIMIT 10");
            $stmt->bind_param('ii', $owner['user_id'], $ticketId); $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) { $rows[] = $row; }
        }
        echo json_encode(['success' => true, 'tickets' => $rows]);
        break;

    case 'upload_attachment':
        // Adjuntar archivo (captura del error). Permitido al dueño del ticket o a soporte.
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $commentId = !empty($_POST['comment_id']) ? intval($_POST['comment_id']) : null;
        $stmt = $conn->prepare("SELECT user_id, assigned_to FROM helpdesk_tickets WHERE id = ?");
        $stmt->bind_param('i', $ticketId); $stmt->execute();
        $tk = $stmt->get_result()->fetch_assoc();
        if (!$tk) { echo json_encode(['success' => false, 'error' => 'Ticket no encontrado']); break; }
        $canUp = $isSupport || $tk['user_id'] == $userId || $tk['assigned_to'] == $userId;
        if (!$canUp) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        if (empty($_FILES['file'])) { echo json_encode(['success' => false, 'error' => 'No se recibió archivo']); break; }
        $r = helpdeskSaveUploadedFile($conn, $ticketId, (int) $userId, $_FILES['file'], $commentId);
        echo json_encode($r['ok'] ? ['success' => true, 'id' => $r['id'], 'file_name' => $r['file_name']] : ['success' => false, 'error' => $r['error']]);
        break;

    case 'get_canned':
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $res = $conn->query("SELECT id, title, body, category_id FROM helpdesk_canned_responses WHERE is_active = 1 ORDER BY title");
        $canned = [];
        while ($row = $res->fetch_assoc()) { $canned[] = $row; }
        echo json_encode(['success' => true, 'canned' => $canned]);
        break;

    case 'save_canned':
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $id = intval($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        if ($title === '' || $body === '') { echo json_encode(['success' => false, 'error' => 'Título y contenido requeridos']); break; }
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE helpdesk_canned_responses SET title = ?, body = ? WHERE id = ?");
            $stmt->bind_param('ssi', $title, $body, $id);
            echo json_encode(['success' => $stmt->execute()]);
        } else {
            $stmt = $conn->prepare("INSERT INTO helpdesk_canned_responses (title, body, created_by) VALUES (?, ?, ?)");
            $stmt->bind_param('ssi', $title, $body, $userId);
            echo json_encode(['success' => $stmt->execute(), 'id' => $conn->insert_id]);
        }
        break;

    case 'delete_canned':
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $id = intval($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE helpdesk_canned_responses SET is_active = 0 WHERE id = ?");
        $stmt->bind_param('i', $id);
        echo json_encode(['success' => $stmt->execute()]);
        break;

    // ===== Bóveda de credenciales de acceso remoto (AnyDesk/RustDesk...) =====
    case 'get_remote':
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $where = "1=1"; $params = []; $types = "";
        if (!empty($_GET['user_id'])) { $where .= " AND r.user_id = ?"; $params[] = intval($_GET['user_id']); $types .= "i"; }
        if (isset($_GET['search']) && trim($_GET['search']) !== '') {
            $s = '%' . trim($_GET['search']) . '%';
            $where .= " AND (r.label LIKE ? OR r.remote_id LIKE ? OR u.full_name LIKE ?)";
            array_push($params, $s, $s, $s); $types .= "sss";
        }
        $sql = "SELECT r.id, r.user_id, r.label, r.tool, r.remote_id, r.ip_hostname, r.updated_at,
                       (r.password_enc IS NOT NULL AND r.password_enc <> '') AS has_password,
                       u.full_name AS user_name
                FROM helpdesk_remote_access r LEFT JOIN users u ON u.id = r.user_id
                WHERE $where ORDER BY u.full_name, r.label";
        $stmt = $conn->prepare($sql);
        if (!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute(); $res = $stmt->get_result();
        $items = []; while ($row = $res->fetch_assoc()) { $items[] = $row; }
        echo json_encode(['success' => true, 'items' => $items]);
        break;

    case 'get_remote_for_user':
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $ruid = intval($_GET['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT id, label, tool, remote_id, ip_hostname, (password_enc IS NOT NULL AND password_enc <> '') AS has_password FROM helpdesk_remote_access WHERE user_id = ? ORDER BY label");
        $stmt->bind_param("i", $ruid); $stmt->execute(); $res = $stmt->get_result();
        $items = []; while ($row = $res->fetch_assoc()) { $items[] = $row; }
        echo json_encode(['success' => true, 'items' => $items]);
        break;

    case 'reveal_remote':
        // Devuelve la contraseña descifrada + AUDITA quién la reveló.
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $rid = intval($_POST['id'] ?? $_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT r.password_enc, r.notes_enc, r.label, u.full_name FROM helpdesk_remote_access r LEFT JOIN users u ON u.id = r.user_id WHERE r.id = ?");
        $stmt->bind_param("i", $rid); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc();
        if (!$row) { echo json_encode(['success' => false, 'error' => 'No encontrado']); break; }
        try { logActivity($userId, 'remote_password_revealed', "Reveló credencial remota #$rid (" . ($row['full_name'] ?: $row['label']) . ")"); } catch (Throwable $e) {}
        echo json_encode(['success' => true, 'password' => vaultDecrypt($row['password_enc']), 'notes' => vaultDecrypt($row['notes_enc'])]);
        break;

    case 'save_remote':
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $rid = intval($_POST['id'] ?? 0);
        $ruser = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
        $label = trim($_POST['label'] ?? '');
        $tool = trim($_POST['tool'] ?? 'anydesk');
        $remoteId = trim($_POST['remote_id'] ?? '');
        $ip = trim($_POST['ip_hostname'] ?? '');
        $pwd = (string) ($_POST['password'] ?? '');
        $notes = (string) ($_POST['notes'] ?? '');
        if ($label === '') { echo json_encode(['success' => false, 'error' => 'La etiqueta es requerida']); break; }
        if ($rid > 0) {
            // Editar: solo re-cifrar password si vino algo (no borrar al editar sin tocarla).
            $set = "user_id=?, label=?, tool=?, remote_id=?, ip_hostname=?, updated_by=?";
            $params = [$ruser, $label, $tool, $remoteId, $ip, $userId]; $types = "issssi";
            if ($pwd !== '') { $set .= ", password_enc=?"; $params[] = vaultEncrypt($pwd); $types .= "s"; }
            if (isset($_POST['notes'])) { $set .= ", notes_enc=?"; $params[] = vaultEncrypt($notes); $types .= "s"; }
            $params[] = $rid; $types .= "i";
            $stmt = $conn->prepare("UPDATE helpdesk_remote_access SET $set WHERE id=?");
            $stmt->bind_param($types, ...$params);
            echo json_encode(['success' => $stmt->execute()]);
        } else {
            $pe = vaultEncrypt($pwd); $ne = vaultEncrypt($notes);
            $stmt = $conn->prepare("INSERT INTO helpdesk_remote_access (user_id, label, tool, remote_id, password_enc, notes_enc, ip_hostname, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssssii", $ruser, $label, $tool, $remoteId, $pe, $ne, $ip, $userId, $userId);
            echo json_encode(['success' => $stmt->execute(), 'id' => $conn->insert_id]);
        }
        break;

    case 'delete_remote':
        if (!$isSupport) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); break; }
        $rid = intval($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM helpdesk_remote_access WHERE id=?");
        $stmt->bind_param("i", $rid);
        echo json_encode(['success' => $stmt->execute()]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
