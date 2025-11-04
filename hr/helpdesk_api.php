<?php
error_reporting(0);
ini_set('display_errors', 0);

// Capturar cualquier salida no deseada
ob_start();

try {
    session_start();
    require_once __DIR__ . '/../db.php';
    
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
    
    // Cargar funciones solo si es necesario
    if ($action !== 'get_categories') {
        require_once __DIR__ . '/../lib/helpdesk_functions.php';
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
            
            // Regular users can only see their own tickets
            if ($userRole !== 'Admin' && $userRole !== 'HR') {
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
            
            $query .= " ORDER BY t.created_at DESC";
            
            if (!empty($_GET['limit'])) {
                $query .= " LIMIT ?";
                $params[] = intval($_GET['limit']);
                $types .= "i";
            }
            
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
            
            echo json_encode(['success' => true, 'tickets' => $tickets]);
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
        if ($userRole !== 'Admin' && $userRole !== 'HR') {
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
            $query = "SELECT c.*, u.full_name as user_name, u.email as user_email
                      FROM helpdesk_comments c
                      JOIN users u ON c.user_id = u.id
                      WHERE c.ticket_id = ?";
            
            // Hide internal comments from regular users
            if ($userRole !== 'Admin' && $userRole !== 'HR') {
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
            
            echo json_encode(['success' => true, 'ticket' => $ticket]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        }
        break;
        
    case 'assign_ticket':
        if ($userRole !== 'Admin' && $userRole !== 'HR') {
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
        
        $canUpdate = ($userRole === 'admin' || $userRole === 'hr' || 
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
        
        $canComment = ($userRole === 'admin' || $userRole === 'hr' || 
                      $ticket['user_id'] == $userId || 
                      $ticket['assigned_to'] == $userId);
        
        if (!$canComment) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        // Only admins/hr can add internal comments
        if ($isInternal && $userRole !== 'Admin' && $userRole !== 'HR') {
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
        if ($userRole !== 'Admin' && $userRole !== 'HR') {
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
        if ($userRole !== 'Admin' && $userRole !== 'HR') {
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
        if ($userRole !== 'Admin' && $userRole !== 'HR') {
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
        if ($userRole !== 'Admin' && $userRole !== 'HR') {
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
        if ($userRole !== 'Admin' && $userRole !== 'HR') {
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
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
