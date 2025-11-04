<?php
// Helpdesk System Functions Library
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/gemini_api.php';
require_once __DIR__ . '/email_functions.php';
require_once __DIR__ . '/logging_functions.php';

// Generate unique ticket number
function generateTicketNumber() {
    global $conn;
    $prefix = 'TKT';
    $year = date('Y');
    
    $query = "SELECT ticket_number FROM helpdesk_tickets 
              WHERE ticket_number LIKE ? 
              ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $pattern = $prefix . '-' . $year . '-%';
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastNumber = intval(substr($row['ticket_number'], -5));
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }
    
    return $prefix . '-' . $year . '-' . str_pad($newNumber, 5, '0', STR_PAD_LEFT);
}

// AI Analysis of ticket using Gemini
function analyzeTicketWithAI($subject, $description, $ticketId = null) {
    $prompt = "Analyze this support ticket and provide:
1. Suggested category (Technical Support, HR Support, Payroll Issues, Access Request, Equipment Request, Facilities, Training, General Inquiry)
2. Suggested priority (low, medium, high, critical)
3. Brief analysis of the issue
4. Suggested initial response or solution

Ticket Subject: $subject
Ticket Description: $description

Respond in JSON format with keys: category, priority, analysis, suggested_response";

    $response = callGeminiAPI($prompt);
    
    if ($ticketId) {
        logAIInteraction($ticketId, 'analysis', $prompt, $response);
    }
    
    return json_decode($response, true);
}

// Log AI interaction
function logAIInteraction($ticketId, $type, $prompt, $response, $model = 'gemini-pro') {
    global $conn;
    
    $query = "INSERT INTO helpdesk_ai_interactions 
              (ticket_id, interaction_type, prompt, response, model_used) 
              VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("issss", $ticketId, $type, $prompt, $response, $model);
    $stmt->execute();
}

// Create new ticket
function createTicket($userId, $categoryId, $subject, $description, $priority = 'medium', $createdByType = 'employee') {
    global $conn;
    
    $ticketNumber = generateTicketNumber();
    
    // Get category SLA settings
    $query = "SELECT sla_response_hours, sla_resolution_hours FROM helpdesk_categories WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $result = $stmt->get_result();
    $category = $result->fetch_assoc();
    
    $responseDeadline = date('Y-m-d H:i:s', strtotime("+{$category['sla_response_hours']} hours"));
    $resolutionDeadline = date('Y-m-d H:i:s', strtotime("+{$category['sla_resolution_hours']} hours"));
    
    // AI Analysis
    $aiAnalysis = analyzeTicketWithAI($subject, $description);
    $aiAnalysisJson = json_encode($aiAnalysis);
    
    $query = "INSERT INTO helpdesk_tickets 
              (ticket_number, user_id, category_id, subject, description, priority, 
               created_by_type, sla_response_deadline, sla_resolution_deadline, ai_analysis) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("siisssssss", $ticketNumber, $userId, $categoryId, $subject, 
                      $description, $priority, $createdByType, $responseDeadline, 
                      $resolutionDeadline, $aiAnalysisJson);
    
    if ($stmt->execute()) {
        $ticketId = $conn->insert_id;
        
        // Log AI interaction
        logAIInteraction($ticketId, 'analysis', 
            "Analyze ticket: $subject", 
            json_encode($aiAnalysis));
        
        // Create notification for admins/HR
        notifyTicketCreated($ticketId, $userId);
        
        // Send email notification
        sendTicketCreatedEmail($ticketId);
        
        logActivity($userId, 'ticket_created', "Created ticket #$ticketNumber");
        
        return ['success' => true, 'ticket_id' => $ticketId, 'ticket_number' => $ticketNumber];
    }
    
    return ['success' => false, 'error' => $conn->error];
}

// Get tickets with filters
function getTickets($filters = []) {
    global $conn;
    
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
    
    if (!empty($filters['user_id'])) {
        $query .= " AND t.user_id = ?";
        $params[] = $filters['user_id'];
        $types .= "i";
    }
    
    if (!empty($filters['assigned_to'])) {
        $query .= " AND t.assigned_to = ?";
        $params[] = $filters['assigned_to'];
        $types .= "i";
    }
    
    if (!empty($filters['status'])) {
        $query .= " AND t.status = ?";
        $params[] = $filters['status'];
        $types .= "s";
    }
    
    if (!empty($filters['priority'])) {
        $query .= " AND t.priority = ?";
        $params[] = $filters['priority'];
        $types .= "s";
    }
    
    if (!empty($filters['category_id'])) {
        $query .= " AND t.category_id = ?";
        $params[] = $filters['category_id'];
        $types .= "i";
    }
    
    $query .= " ORDER BY t.created_at DESC";
    
    if (!empty($filters['limit'])) {
        $query .= " LIMIT ?";
        $params[] = $filters['limit'];
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
    
    return $tickets;
}

// Assign ticket
function assignTicket($ticketId, $assignedTo, $assignedBy, $notes = '') {
    global $conn;
    
    // Get current assignment
    $query = "SELECT assigned_to FROM helpdesk_tickets WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    $assignedFrom = $ticket['assigned_to'];
    
    // Update ticket
    $query = "UPDATE helpdesk_tickets SET assigned_to = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $assignedTo, $ticketId);
    $stmt->execute();
    
    // Log assignment
    $query = "INSERT INTO helpdesk_assignments 
              (ticket_id, assigned_from, assigned_to, assigned_by, notes) 
              VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiiis", $ticketId, $assignedFrom, $assignedTo, $assignedBy, $notes);
    $stmt->execute();
    
    // Create notification
    notifyTicketAssigned($ticketId, $assignedTo, $assignedBy);
    
    // Send email
    sendTicketAssignedEmail($ticketId, $assignedTo);
    
    logActivity($assignedBy, 'ticket_assigned', "Assigned ticket #$ticketId to user #$assignedTo");
    
    return true;
}

// Update ticket status
function updateTicketStatus($ticketId, $newStatus, $changedBy, $notes = '') {
    global $conn;
    
    // Get current status
    $query = "SELECT status, ticket_number FROM helpdesk_tickets WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    $oldStatus = $ticket['status'];
    
    // Update ticket
    $updateFields = "status = ?";
    $params = [$newStatus];
    $types = "s";
    
    if ($newStatus === 'resolved' || $newStatus === 'closed') {
        $updateFields .= ", resolved_at = NOW()";
    }
    if ($newStatus === 'closed') {
        $updateFields .= ", closed_at = NOW()";
    }
    if ($oldStatus === 'open' && $newStatus !== 'open') {
        $updateFields .= ", first_response_at = NOW()";
    }
    
    $query = "UPDATE helpdesk_tickets SET $updateFields WHERE id = ?";
    $params[] = $ticketId;
    $types .= "i";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    // Log status change
    $query = "INSERT INTO helpdesk_status_history 
              (ticket_id, old_status, new_status, changed_by, notes) 
              VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("issis", $ticketId, $oldStatus, $newStatus, $changedBy, $notes);
    $stmt->execute();
    
    // Create notification
    notifyStatusChanged($ticketId, $oldStatus, $newStatus);
    
    logActivity($changedBy, 'ticket_status_changed', 
                "Changed ticket #{$ticket['ticket_number']} status from $oldStatus to $newStatus");
    
    return true;
}

// Add comment to ticket
function addTicketComment($ticketId, $userId, $comment, $isInternal = false) {
    global $conn;
    
    $query = "INSERT INTO helpdesk_comments (ticket_id, user_id, comment, is_internal) 
              VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iisi", $ticketId, $userId, $comment, $isInternal);
    
    if ($stmt->execute()) {
        $commentId = $conn->insert_id;
        
        // Update first response time if this is the first response
        $query = "UPDATE helpdesk_tickets 
                  SET first_response_at = NOW() 
                  WHERE id = ? AND first_response_at IS NULL";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        
        // Notify ticket creator
        notifyCommentAdded($ticketId, $userId, $commentId);
        
        logActivity($userId, 'ticket_comment_added', "Added comment to ticket #$ticketId");
        
        return ['success' => true, 'comment_id' => $commentId];
    }
    
    return ['success' => false, 'error' => $conn->error];
}

// Notification functions
function notifyTicketCreated($ticketId, $userId) {
    global $conn;
    
    $query = "SELECT ticket_number, subject FROM helpdesk_tickets WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    
    // Notify all admins and HR
    $query = "SELECT id FROM users WHERE role IN ('admin', 'hr') AND id != ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        createNotification($ticketId, $row['id'], 'ticket_created',
            "New Ticket: {$ticket['ticket_number']}",
            "A new ticket has been created: {$ticket['subject']}");
    }
}

function notifyTicketAssigned($ticketId, $assignedTo, $assignedBy) {
    global $conn;
    
    $query = "SELECT ticket_number, subject FROM helpdesk_tickets WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    
    createNotification($ticketId, $assignedTo, 'ticket_assigned',
        "Ticket Assigned: {$ticket['ticket_number']}",
        "You have been assigned to ticket: {$ticket['subject']}");
}

function notifyStatusChanged($ticketId, $oldStatus, $newStatus) {
    global $conn;
    
    $query = "SELECT ticket_number, subject, user_id, assigned_to FROM helpdesk_tickets WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    
    // Notify ticket creator
    createNotification($ticketId, $ticket['user_id'], 'status_changed',
        "Ticket Status Updated: {$ticket['ticket_number']}",
        "Your ticket status changed from $oldStatus to $newStatus");
    
    // Notify assigned agent if different from creator
    if ($ticket['assigned_to'] && $ticket['assigned_to'] != $ticket['user_id']) {
        createNotification($ticketId, $ticket['assigned_to'], 'status_changed',
            "Ticket Status Updated: {$ticket['ticket_number']}",
            "Ticket status changed from $oldStatus to $newStatus");
    }
}

function notifyCommentAdded($ticketId, $commentBy, $commentId) {
    global $conn;
    
    $query = "SELECT ticket_number, subject, user_id, assigned_to FROM helpdesk_tickets WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    
    // Notify ticket creator if comment is not by them
    if ($ticket['user_id'] != $commentBy) {
        createNotification($ticketId, $ticket['user_id'], 'comment_added',
            "New Comment: {$ticket['ticket_number']}",
            "A new comment has been added to your ticket");
    }
    
    // Notify assigned agent if different and not the commenter
    if ($ticket['assigned_to'] && $ticket['assigned_to'] != $commentBy && $ticket['assigned_to'] != $ticket['user_id']) {
        createNotification($ticketId, $ticket['assigned_to'], 'comment_added',
            "New Comment: {$ticket['ticket_number']}",
            "A new comment has been added to the ticket");
    }
}

function createNotification($ticketId, $userId, $type, $title, $message) {
    global $conn;
    
    $query = "INSERT INTO helpdesk_notifications 
              (ticket_id, user_id, notification_type, title, message) 
              VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iisss", $ticketId, $userId, $type, $title, $message);
    $stmt->execute();
}

// Email notification functions
function sendTicketCreatedEmail($ticketId) {
    global $conn;
    
    $query = "SELECT t.*, u.email, u.full_name, c.name as category_name
              FROM helpdesk_tickets t
              JOIN users u ON t.user_id = u.id
              JOIN helpdesk_categories c ON t.category_id = c.id
              WHERE t.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    
    $subject = "Ticket Created: {$ticket['ticket_number']}";
    $body = "
        <h2>Your Support Ticket Has Been Created</h2>
        <p>Dear {$ticket['full_name']},</p>
        <p>Your support ticket has been successfully created and our team will respond shortly.</p>
        <h3>Ticket Details:</h3>
        <ul>
            <li><strong>Ticket Number:</strong> {$ticket['ticket_number']}</li>
            <li><strong>Subject:</strong> {$ticket['subject']}</li>
            <li><strong>Category:</strong> {$ticket['category_name']}</li>
            <li><strong>Priority:</strong> {$ticket['priority']}</li>
            <li><strong>Status:</strong> {$ticket['status']}</li>
        </ul>
        <p><strong>Description:</strong><br>{$ticket['description']}</p>
        <p>You can track your ticket status in the helpdesk portal.</p>
    ";
    
    return sendEmail($ticket['email'], $subject, $body);
}

function sendTicketAssignedEmail($ticketId, $assignedTo) {
    global $conn;
    
    $query = "SELECT t.*, u.email, u.full_name, c.name as category_name
              FROM helpdesk_tickets t
              JOIN users u ON u.id = ?
              JOIN helpdesk_categories c ON t.category_id = c.id
              WHERE t.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $assignedTo, $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    
    $subject = "Ticket Assigned: {$ticket['ticket_number']}";
    $body = "
        <h2>New Ticket Assignment</h2>
        <p>Dear {$ticket['full_name']},</p>
        <p>A support ticket has been assigned to you.</p>
        <h3>Ticket Details:</h3>
        <ul>
            <li><strong>Ticket Number:</strong> {$ticket['ticket_number']}</li>
            <li><strong>Subject:</strong> {$ticket['subject']}</li>
            <li><strong>Category:</strong> {$ticket['category_name']}</li>
            <li><strong>Priority:</strong> {$ticket['priority']}</li>
            <li><strong>SLA Response Deadline:</strong> {$ticket['sla_response_deadline']}</li>
            <li><strong>SLA Resolution Deadline:</strong> {$ticket['sla_resolution_deadline']}</li>
        </ul>
        <p>Please review and respond to this ticket as soon as possible.</p>
    ";
    
    return sendEmail($ticket['email'], $subject, $body);
}

// Get ticket statistics
function getTicketStatistics($filters = []) {
    global $conn;
    
    $query = "SELECT 
                COUNT(*) as total_tickets,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_tickets,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tickets,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_tickets,
                SUM(CASE WHEN sla_response_breached = 1 THEN 1 ELSE 0 END) as response_breaches,
                SUM(CASE WHEN sla_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breaches,
                AVG(CASE WHEN resolved_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) END) as avg_resolution_hours
              FROM helpdesk_tickets WHERE 1=1";
    
    $params = [];
    $types = "";
    
    if (!empty($filters['date_from'])) {
        $query .= " AND created_at >= ?";
        $params[] = $filters['date_from'];
        $types .= "s";
    }
    
    if (!empty($filters['date_to'])) {
        $query .= " AND created_at <= ?";
        $params[] = $filters['date_to'];
        $types .= "s";
    }
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}
