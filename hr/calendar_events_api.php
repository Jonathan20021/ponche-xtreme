<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_calendar');

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            createEvent();
            break;
            
        case 'update':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            updateEvent();
            break;
            
        case 'delete':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            deleteEvent();
            break;
            
        case 'get':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed');
            }
            getEvent();
            break;
            
        case 'list':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed');
            }
            listEvents();
            break;
            
        case 'add_attendee':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            addAttendee();
            break;
            
        case 'remove_attendee':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            removeAttendee();
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function createEvent() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (empty($data['title']) || empty($data['event_date'])) {
        throw new Exception('Title and event date are required');
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO calendar_events 
        (title, description, event_date, start_time, end_time, event_type, color, location, is_all_day, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['title'],
        $data['description'] ?? null,
        $data['event_date'],
        $data['start_time'] ?? null,
        $data['end_time'] ?? null,
        $data['event_type'] ?? 'OTHER',
        $data['color'] ?? '#6366f1',
        $data['location'] ?? null,
        isset($data['is_all_day']) ? (int)$data['is_all_day'] : 0,
        $_SESSION['user_id']
    ]);
    
    $eventId = $pdo->lastInsertId();
    
    // Add attendees if provided
    if (!empty($data['attendees']) && is_array($data['attendees'])) {
        $attendeeStmt = $pdo->prepare("
            INSERT INTO calendar_event_attendees (event_id, employee_id, status)
            VALUES (?, ?, 'PENDING')
        ");
        
        foreach ($data['attendees'] as $employeeId) {
            $attendeeStmt->execute([$eventId, $employeeId]);
        }
    }
    
    // Add reminders if provided
    if (!empty($data['reminders']) && is_array($data['reminders'])) {
        $reminderStmt = $pdo->prepare("
            INSERT INTO calendar_event_reminders (event_id, reminder_minutes)
            VALUES (?, ?)
        ");
        
        foreach ($data['reminders'] as $minutes) {
            $reminderStmt->execute([$eventId, $minutes]);
        }
    }
    
    echo json_encode([
        'success' => true,
        'event_id' => $eventId,
        'message' => 'Evento creado exitosamente'
    ]);
}

function updateEvent() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id'])) {
        throw new Exception('Event ID is required');
    }
    
    // Check if user has permission to edit this event
    $checkStmt = $pdo->prepare("SELECT created_by FROM calendar_events WHERE id = ?");
    $checkStmt->execute([$data['id']]);
    $event = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception('Event not found');
    }
    
    // Allow edit if user is creator or has admin permissions
    if ($event['created_by'] != $_SESSION['user_id'] && !hasPermission('admin')) {
        throw new Exception('No tienes permiso para editar este evento');
    }
    
    $stmt = $pdo->prepare("
        UPDATE calendar_events 
        SET title = ?, description = ?, event_date = ?, start_time = ?, end_time = ?, 
            event_type = ?, color = ?, location = ?, is_all_day = ?
        WHERE id = ?
    ");
    
    $stmt->execute([
        $data['title'],
        $data['description'] ?? null,
        $data['event_date'],
        $data['start_time'] ?? null,
        $data['end_time'] ?? null,
        $data['event_type'] ?? 'OTHER',
        $data['color'] ?? '#6366f1',
        $data['location'] ?? null,
        isset($data['is_all_day']) ? (int)$data['is_all_day'] : 0,
        $data['id']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Evento actualizado exitosamente'
    ]);
}

function deleteEvent() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id'])) {
        throw new Exception('Event ID is required');
    }
    
    // Check if user has permission to delete this event
    $checkStmt = $pdo->prepare("SELECT created_by FROM calendar_events WHERE id = ?");
    $checkStmt->execute([$data['id']]);
    $event = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception('Event not found');
    }
    
    if ($event['created_by'] != $_SESSION['user_id'] && !hasPermission('admin')) {
        throw new Exception('No tienes permiso para eliminar este evento');
    }
    
    $stmt = $pdo->prepare("DELETE FROM calendar_events WHERE id = ?");
    $stmt->execute([$data['id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Evento eliminado exitosamente'
    ]);
}

function getEvent() {
    global $pdo;
    
    $id = $_GET['id'] ?? null;
    
    if (empty($id)) {
        throw new Exception('Event ID is required');
    }
    
    $stmt = $pdo->prepare("
        SELECT ce.*, u.username as creator_name
        FROM calendar_events ce
        JOIN users u ON u.id = ce.created_by
        WHERE ce.id = ?
    ");
    $stmt->execute([$id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception('Event not found');
    }
    
    // Get attendees
    $attendeesStmt = $pdo->prepare("
        SELECT cea.*, e.first_name, e.last_name, e.employee_code
        FROM calendar_event_attendees cea
        JOIN employees e ON e.id = cea.employee_id
        WHERE cea.event_id = ?
    ");
    $attendeesStmt->execute([$id]);
    $event['attendees'] = $attendeesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get reminders
    $remindersStmt = $pdo->prepare("
        SELECT * FROM calendar_event_reminders WHERE event_id = ?
    ");
    $remindersStmt->execute([$id]);
    $event['reminders'] = $remindersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'event' => $event
    ]);
}

function listEvents() {
    global $pdo;
    
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    
    if (empty($startDate) || empty($endDate)) {
        throw new Exception('Start date and end date are required');
    }
    
    $stmt = $pdo->prepare("
        SELECT ce.*, u.username as creator_name,
               (SELECT COUNT(*) FROM calendar_event_attendees WHERE event_id = ce.id) as attendee_count
        FROM calendar_events ce
        JOIN users u ON u.id = ce.created_by
        WHERE ce.event_date BETWEEN ? AND ?
        ORDER BY ce.event_date, ce.start_time
    ");
    $stmt->execute([$startDate, $endDate]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'events' => $events
    ]);
}

function addAttendee() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['event_id']) || empty($data['employee_id'])) {
        throw new Exception('Event ID and Employee ID are required');
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO calendar_event_attendees (event_id, employee_id, status)
        VALUES (?, ?, 'PENDING')
        ON DUPLICATE KEY UPDATE status = 'PENDING'
    ");
    $stmt->execute([$data['event_id'], $data['employee_id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Asistente agregado exitosamente'
    ]);
}

function removeAttendee() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['event_id']) || empty($data['employee_id'])) {
        throw new Exception('Event ID and Employee ID are required');
    }
    
    $stmt = $pdo->prepare("
        DELETE FROM calendar_event_attendees 
        WHERE event_id = ? AND employee_id = ?
    ");
    $stmt->execute([$data['event_id'], $data['employee_id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Asistente eliminado exitosamente'
    ]);
}
