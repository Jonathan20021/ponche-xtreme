<?php
// Helpdesk System Functions Library
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/gemini_api.php';
require_once __DIR__ . '/email_functions.php';
require_once __DIR__ . '/logging_functions.php';
require_once __DIR__ . '/helpdesk_support.php'; // SLA por prioridad configurable

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
    
    // SLA: primero por prioridad (configurable desde el reporte). Si no hubiera
    // política de prioridad, cae al SLA de la categoría y luego a un default.
    $slaByPrio = helpdeskGetSlaPriorities();
    if (isset($slaByPrio[$priority])) {
        $respHours  = $slaByPrio[$priority]['response'];
        $resolHours = $slaByPrio[$priority]['resolution'];
    } else {
        $stmt = $conn->prepare("SELECT sla_response_hours, sla_resolution_hours FROM helpdesk_categories WHERE id = ?");
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();
        $category = $stmt->get_result()->fetch_assoc() ?: [];
        $respHours  = (int) ($category['sla_response_hours'] ?? 24);
        $resolHours = (int) ($category['sla_resolution_hours'] ?? 72);
    }

    $responseDeadline = date('Y-m-d H:i:s', strtotime("+{$respHours} hours"));
    $resolutionDeadline = date('Y-m-d H:i:s', strtotime("+{$resolHours} hours"));

    // Análisis con IA (best-effort: una falla de la API no debe impedir crear el ticket)
    $aiAnalysis = null;
    try { $aiAnalysis = analyzeTicketWithAI($subject, $description); } catch (Throwable $e) { $aiAnalysis = null; }
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

        // Efectos secundarios best-effort: ninguna falla (IA, notificación, correo,
        // log) debe romper la creación del ticket ni devolver error al agente.
        try { logAIInteraction($ticketId, 'analysis', "Analyze ticket: $subject", json_encode($aiAnalysis)); } catch (Throwable $e) {}
        try { notifyTicketCreated($ticketId, $userId); } catch (Throwable $e) {}
        try { sendTicketCreatedEmail($ticketId); } catch (Throwable $e) {}          // confirmación al solicitante
        try { sendTicketCreatedSupportEmails($ticketId); } catch (Throwable $e) {}  // aviso al equipo de soporte + devs
        try { logActivity($userId, 'ticket_created', "Created ticket #$ticketNumber"); } catch (Throwable $e) {}

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

// Assign ticket (assignedTo <= 0 => desasignar / NULL)
function assignTicket($ticketId, $assignedTo, $assignedBy, $notes = '') {
    global $conn;
    $ticketId = (int) $ticketId;
    $assignedTo = (int) $assignedTo;

    // Asignación actual (para el log)
    $stmt = $conn->prepare("SELECT assigned_to FROM helpdesk_tickets WHERE id = ?");
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $assignedFrom = $ticket['assigned_to'] ?? null;

    // DESASIGNAR: assigned_to = NULL (poner 0 rompía la llave foránea y dejaba
    // el ticket asignado al anterior). No hay a quién notificar.
    if ($assignedTo <= 0) {
        $stmt = $conn->prepare("UPDATE helpdesk_tickets SET assigned_to = NULL WHERE id = ?");
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        try { logActivity($assignedBy, 'ticket_unassigned', "Unassigned ticket #$ticketId"); } catch (Throwable $e) {}
        return true;
    }

    $stmt = $conn->prepare("UPDATE helpdesk_tickets SET assigned_to = ? WHERE id = ?");
    $stmt->bind_param("ii", $assignedTo, $ticketId);
    $stmt->execute();

    // Log de asignación
    $stmt = $conn->prepare("INSERT INTO helpdesk_assignments (ticket_id, assigned_from, assigned_to, assigned_by, notes) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiis", $ticketId, $assignedFrom, $assignedTo, $assignedBy, $notes);
    $stmt->execute();

    // Efectos secundarios best-effort (no deben romper la asignación).
    try { notifyTicketAssigned($ticketId, $assignedTo, $assignedBy); } catch (Throwable $e) {}
    try { sendTicketAssignedEmail($ticketId, $assignedTo); } catch (Throwable $e) {}
    try { logActivity($assignedBy, 'ticket_assigned', "Assigned ticket #$ticketId to user #$assignedTo"); } catch (Throwable $e) {}

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
        
        // Efectos secundarios best-effort (no deben romper el comentario)
        try { notifyCommentAdded($ticketId, $userId, $commentId); } catch (Throwable $e) {}
        try { logActivity($userId, 'ticket_comment_added', "Added comment to ticket #$ticketId"); } catch (Throwable $e) {}

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
    
    // Notificar al equipo de soporte (IT, Desarrollador, Admin, HR). Antes comparaba
    // 'admin'/'hr' en minúscula y NO coincidía con los roles reales -> nadie se enteraba.
    $query = "SELECT id FROM users WHERE UPPER(role) IN ('ADMIN','HR','IT','DESARROLLADOR') AND id != ?";
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
    if (!helpdeskEmailsEnabled()) { return false; }

    // Confirmación al solicitante. El email vive en employees (users no lo tiene).
    $stmt = $conn->prepare("SELECT t.ticket_number, t.subject, t.description, t.priority,
                                   e.email, u.full_name, c.name AS category_name
                            FROM helpdesk_tickets t
                            JOIN users u ON t.user_id = u.id
                            LEFT JOIN employees e ON e.user_id = u.id
                            LEFT JOIN helpdesk_categories c ON c.id = t.category_id
                            WHERE t.id = ?");
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    if (empty($ticket['email'])) { return false; } // sin email no hay a quién avisar

    $appUrl = '';
    try { $cfg = require __DIR__ . '/../config/email_config.php'; $appUrl = rtrim($cfg['app_url'] ?? '', '/'); } catch (Throwable $e) {}
    $link = $appUrl ? ($appUrl . '/agents/helpdesk_tickets.php') : '';
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $inner = "<p style='color:#161f35; font-size:14px; margin:0 0 14px;'>Hola " . $esc($ticket['full_name']) . ", recibimos tu ticket y nuestro equipo lo atenderá pronto.</p>
      <table style='width:100%; border-collapse:collapse; margin:0 0 16px;'>"
        . helpdeskEmailRow('Ticket', $esc($ticket['ticket_number']))
        . helpdeskEmailRow('Asunto', $esc($ticket['subject']))
        . helpdeskEmailRow('Categoría', $esc($ticket['category_name'] ?? '—'))
        . helpdeskEmailRow('Prioridad', $esc(ucfirst($ticket['priority'])))
      . "</table>"
      . ($link ? "<div style='text-align:center; margin:6px 0 2px;'><a href='{$link}' style='background:#244886; color:#fff; text-decoration:none; padding:12px 28px; border-radius:10px; font-weight:700; font-size:14px; display:inline-block;'>Ver mis tickets</a></div>" : '');
    $body = helpdeskEmailShell('Ticket recibido: ' . $ticket['ticket_number'], $inner);

    return sendEmail($ticket['email'], "Ticket recibido: {$ticket['ticket_number']}", $body, $ticket['full_name']);
}

function sendTicketAssignedEmail($ticketId, $assignedTo) {
    global $conn;
    if (!helpdeskEmailsEnabled()) { return false; }

    // Aviso al agente de soporte asignado. El email vive en employees.
    $stmt = $conn->prepare("SELECT t.ticket_number, t.subject, t.description, t.priority,
                                   e.email, u.full_name, c.name AS category_name
                            FROM helpdesk_tickets t
                            JOIN users u ON u.id = ?
                            LEFT JOIN employees e ON e.user_id = u.id
                            LEFT JOIN helpdesk_categories c ON c.id = t.category_id
                            WHERE t.id = ?");
    $stmt->bind_param("ii", $assignedTo, $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    if (empty($ticket['email'])) { return false; } // sin email no hay a quién avisar

    $appUrl = '';
    try { $cfg = require __DIR__ . '/../config/email_config.php'; $appUrl = rtrim($cfg['app_url'] ?? '', '/'); } catch (Throwable $e) {}
    $link = $appUrl ? ($appUrl . '/helpdesk/console.php?ticket=' . (int) $ticketId) : '';
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $inner = "<p style='color:#161f35; font-size:14px; margin:0 0 14px;'>Hola " . $esc($ticket['full_name']) . ", se te asignó un ticket de soporte. Revísalo y da la primera respuesta lo antes posible.</p>
      <table style='width:100%; border-collapse:collapse; margin:0 0 16px;'>"
        . helpdeskEmailRow('Ticket', $esc($ticket['ticket_number']))
        . helpdeskEmailRow('Asunto', $esc($ticket['subject']))
        . helpdeskEmailRow('Categoría', $esc($ticket['category_name'] ?? '—'))
        . helpdeskEmailRow('Prioridad', $esc(ucfirst($ticket['priority'])))
      . "</table>"
      . ($link ? "<div style='text-align:center; margin:6px 0 2px;'><a href='{$link}' style='background:#244886; color:#fff; text-decoration:none; padding:12px 28px; border-radius:10px; font-weight:700; font-size:14px; display:inline-block;'>Abrir en la Consola</a></div>" : '');
    $body = helpdeskEmailShell('Ticket asignado: ' . $ticket['ticket_number'], $inner);

    return sendEmail($ticket['email'], "Ticket asignado: {$ticket['ticket_number']}", $body, $ticket['full_name']);
}

// ¿Enviar correos del helpdesk? Interruptor configurable (system_settings).
function helpdeskEmailsEnabled() {
    global $pdo;
    try { return getSystemSetting($pdo, 'helpdesk_notify_emails', '1') === '1'; }
    catch (Throwable $e) { return true; }
}

// Correos extra a notificar en la creación (además del equipo de soporte).
function helpdeskExtraRecipients() {
    global $pdo;
    try { $raw = getSystemSetting($pdo, 'helpdesk_notify_extra_emails', ''); }
    catch (Throwable $e) { $raw = ''; }
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string) $raw) as $addr) {
        $addr = trim($addr);
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) { $out[] = $addr; }
    }
    return $out;
}

// Plantilla HTML de marca (Evallish) para los correos del helpdesk.
function helpdeskEmailShell($heading, $innerHtml) {
    $year = date('Y');
    $heading = htmlspecialchars((string) $heading, ENT_QUOTES, 'UTF-8');
    return "
    <div style='font-family:Segoe UI,Roboto,Arial,sans-serif; max-width:600px; margin:0 auto; background:#f4f6fb; padding:0 0 24px;'>
      <div style='background:#244886; color:#fff; padding:26px 30px;'>
        <div style='font-size:12px; letter-spacing:.6px; opacity:.85; text-transform:uppercase;'>Evallish BPO &middot; Soporte</div>
        <h1 style='margin:6px 0 0; font-size:20px; font-weight:700;'>{$heading}</h1>
      </div>
      <div style='background:#fff; margin:0 20px; padding:24px 26px; border:1px solid #e6ebf3; border-top:none; border-radius:0 0 14px 14px;'>{$innerHtml}</div>
      <p style='color:#8a97ae; font-size:11.5px; text-align:center; margin:16px 20px 0;'>Notificación automática del Helpdesk de Evallish BPO &middot; {$year}. No respondas a este correo.</p>
    </div>";
}

function helpdeskEmailRow($k, $v) {
    return "<tr><td style='padding:8px 0; color:#5b6b88; font-size:13px; border-bottom:1px solid #eef1f6;'><strong>{$k}</strong></td>
                <td style='padding:8px 0; color:#161f35; font-size:13px; border-bottom:1px solid #eef1f6; text-align:right;'>{$v}</td></tr>";
}

// Correo a TODO el equipo de soporte (Admin/HR/IT/Desarrollador) + extras al crear.
function sendTicketCreatedSupportEmails($ticketId) {
    global $conn;
    if (!helpdeskEmailsEnabled()) { return; }

    $stmt = $conn->prepare("SELECT t.ticket_number, t.subject, t.description, t.priority,
                                   c.name AS category_name, ru.full_name AS requester_name
                            FROM helpdesk_tickets t
                            LEFT JOIN helpdesk_categories c ON c.id = t.category_id
                            JOIN users ru ON ru.id = t.user_id
                            WHERE t.id = ?");
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    if (!$t) { return; }

    $appUrl = '';
    try { $cfg = require __DIR__ . '/../config/email_config.php'; $appUrl = rtrim($cfg['app_url'] ?? '', '/'); } catch (Throwable $e) {}
    $link = $appUrl ? ($appUrl . '/helpdesk/console.php?ticket=' . (int) $ticketId) : '';
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $inner = "<p style='color:#161f35; font-size:14px; margin:0 0 14px;'>Se creó un nuevo ticket de soporte que requiere atención del equipo.</p>
      <table style='width:100%; border-collapse:collapse; margin:0 0 16px;'>"
        . helpdeskEmailRow('Ticket', $esc($t['ticket_number']))
        . helpdeskEmailRow('Asunto', $esc($t['subject']))
        . helpdeskEmailRow('Solicitante', $esc($t['requester_name']))
        . helpdeskEmailRow('Categoría', $esc($t['category_name'] ?? '—'))
        . helpdeskEmailRow('Prioridad', $esc(ucfirst($t['priority'])))
      . "</table>"
      . "<p style='color:#5b6b88; font-size:13px; background:#f7f9fc; border:1px solid #eef1f6; border-radius:10px; padding:12px 14px; margin:0 0 18px; white-space:pre-wrap;'>" . $esc(mb_strimwidth((string) $t['description'], 0, 400, '…')) . "</p>"
      . ($link ? "<div style='text-align:center; margin:6px 0 2px;'><a href='{$link}' style='background:#244886; color:#fff; text-decoration:none; padding:12px 28px; border-radius:10px; font-weight:700; font-size:14px; display:inline-block;'>Abrir en la Consola</a></div>" : '');
    $body    = helpdeskEmailShell('Nuevo ticket: ' . $t['ticket_number'], $inner);
    $subject = "Nuevo ticket de soporte: {$t['ticket_number']}";

    // Destinatarios: equipo de soporte con email en su ficha, activos.
    $sent = [];
    $res = $conn->query("SELECT DISTINCT e.email, u.full_name
                         FROM users u JOIN employees e ON e.user_id = u.id
                         WHERE UPPER(u.role) IN ('ADMIN','HR','IT','DESARROLLADOR')
                           AND e.email IS NOT NULL AND e.email <> '' AND u.is_active = 1");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $addr = strtolower(trim($r['email']));
            if ($addr === '' || isset($sent[$addr])) { continue; }
            $sent[$addr] = true;
            try { sendEmail($r['email'], $subject, $body, $r['full_name']); } catch (Throwable $e) {}
        }
    }
    // Destinatarios extra configurables.
    foreach (helpdeskExtraRecipients() as $addr) {
        if (isset($sent[strtolower($addr)])) { continue; }
        $sent[strtolower($addr)] = true;
        try { sendEmail($addr, $subject, $body, 'Equipo de soporte'); } catch (Throwable $e) {}
    }
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
