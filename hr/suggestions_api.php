<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/logging_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'employee';
$isAdmin = ($userRole === 'admin' || $userRole === 'hr');

switch ($action) {
    case 'create_suggestion':
        $department = trim($_POST['department']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $suggestionType = $_POST['suggestion_type'] ?? 'improvement';
        $isAnonymous = isset($_POST['is_anonymous']) ? intval($_POST['is_anonymous']) : 0;
        
        if (empty($title) || empty($description) || empty($department)) {
            echo json_encode(['success' => false, 'error' => 'All fields are required']);
            exit;
        }
        
        $query = "INSERT INTO helpdesk_suggestions 
                  (user_id, department, title, description, suggestion_type, is_anonymous) 
                  VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issssi", $userId, $department, $title, $description, $suggestionType, $isAnonymous);
        
        if ($stmt->execute()) {
            $suggestionId = $conn->insert_id;
            logActivity($userId, 'suggestion_created', "Created suggestion: $title");
            echo json_encode(['success' => true, 'suggestion_id' => $suggestionId]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        break;
        
    case 'get_suggestions':
        $query = "SELECT s.*, u.full_name as user_name, u.email as user_email,
                  r.full_name as reviewed_by_name
                  FROM helpdesk_suggestions s
                  LEFT JOIN users u ON s.user_id = u.id
                  LEFT JOIN users r ON s.reviewed_by = r.id
                  WHERE 1=1";
        
        $params = [];
        $types = "";
        
        // Regular users can only see their own suggestions
        if (!$isAdmin) {
            $query .= " AND s.user_id = ?";
            $params[] = $userId;
            $types .= "i";
        }
        
        if (!empty($_GET['department'])) {
            $query .= " AND s.department = ?";
            $params[] = $_GET['department'];
            $types .= "s";
        }
        
        if (!empty($_GET['status'])) {
            $query .= " AND s.status = ?";
            $params[] = $_GET['status'];
            $types .= "s";
        }
        
        if (!empty($_GET['suggestion_type'])) {
            $query .= " AND s.suggestion_type = ?";
            $params[] = $_GET['suggestion_type'];
            $types .= "s";
        }
        
        $query .= " ORDER BY s.created_at DESC";
        
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
        
        $suggestions = [];
        while ($row = $result->fetch_assoc()) {
            // Hide user info if anonymous
            if ($row['is_anonymous'] == 1 && !$isAdmin) {
                $row['user_name'] = 'Anonymous';
                $row['user_email'] = '';
            }
            $suggestions[] = $row;
        }
        
        echo json_encode(['success' => true, 'suggestions' => $suggestions]);
        break;
        
    case 'get_suggestion':
        $suggestionId = intval($_GET['suggestion_id']);
        
        $query = "SELECT s.*, u.full_name as user_name, u.email as user_email,
                  r.full_name as reviewed_by_name
                  FROM helpdesk_suggestions s
                  LEFT JOIN users u ON s.user_id = u.id
                  LEFT JOIN users r ON s.reviewed_by = r.id
                  WHERE s.id = ?";
        
        if (!$isAdmin) {
            $query .= " AND s.user_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ii", $suggestionId, $userId);
        } else {
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $suggestionId);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $suggestion = $result->fetch_assoc();
            
            // Hide user info if anonymous and not admin
            if ($suggestion['is_anonymous'] == 1 && !$isAdmin) {
                $suggestion['user_name'] = 'Anonymous';
                $suggestion['user_email'] = '';
            }
            
            echo json_encode(['success' => true, 'suggestion' => $suggestion]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Suggestion not found']);
        }
        break;
        
    case 'update_suggestion_status':
        if (!$isAdmin) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        $suggestionId = intval($_POST['suggestion_id']);
        $status = $_POST['status'];
        $reviewNotes = $_POST['review_notes'] ?? '';
        
        $query = "UPDATE helpdesk_suggestions 
                  SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
                  WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sisi", $status, $userId, $reviewNotes, $suggestionId);
        
        if ($stmt->execute()) {
            logActivity($userId, 'suggestion_reviewed', "Updated suggestion #$suggestionId status to $status");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        break;
        
    case 'vote_suggestion':
        $suggestionId = intval($_POST['suggestion_id']);
        $voteType = $_POST['vote_type'] ?? 'up';
        
        // Check if user already voted
        $query = "SELECT id FROM helpdesk_suggestion_votes 
                  WHERE suggestion_id = ? AND user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $suggestionId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing vote
            $query = "UPDATE helpdesk_suggestion_votes 
                      SET vote_type = ? 
                      WHERE suggestion_id = ? AND user_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sii", $voteType, $suggestionId, $userId);
        } else {
            // Insert new vote
            $query = "INSERT INTO helpdesk_suggestion_votes 
                      (suggestion_id, user_id, vote_type) 
                      VALUES (?, ?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("iis", $suggestionId, $userId, $voteType);
        }
        
        if ($stmt->execute()) {
            // Update vote count
            $query = "UPDATE helpdesk_suggestions 
                      SET votes_count = (
                          SELECT COUNT(*) FROM helpdesk_suggestion_votes 
                          WHERE suggestion_id = ? AND vote_type = 'up'
                      ) - (
                          SELECT COUNT(*) FROM helpdesk_suggestion_votes 
                          WHERE suggestion_id = ? AND vote_type = 'down'
                      )
                      WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("iii", $suggestionId, $suggestionId, $suggestionId);
            $stmt->execute();
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        break;
        
    case 'get_statistics':
        if (!$isAdmin) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        // Total suggestions
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'implemented' THEN 1 ELSE 0 END) as implemented,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                  FROM helpdesk_suggestions";
        $result = $conn->query($query);
        $stats = $result->fetch_assoc();
        
        // By department
        $query = "SELECT department, COUNT(*) as count
                  FROM helpdesk_suggestions
                  GROUP BY department
                  ORDER BY count DESC";
        $result = $conn->query($query);
        
        $byDepartment = [];
        while ($row = $result->fetch_assoc()) {
            $byDepartment[] = $row;
        }
        
        $stats['by_department'] = $byDepartment;
        
        // By type
        $query = "SELECT suggestion_type, COUNT(*) as count
                  FROM helpdesk_suggestions
                  GROUP BY suggestion_type
                  ORDER BY count DESC";
        $result = $conn->query($query);
        
        $byType = [];
        while ($row = $result->fetch_assoc()) {
            $byType[] = $row;
        }
        
        $stats['by_type'] = $byType;
        
        echo json_encode(['success' => true, 'statistics' => $stats]);
        break;
        
    case 'get_departments':
        $query = "SELECT DISTINCT department FROM helpdesk_suggestions ORDER BY department";
        $result = $conn->query($query);
        
        $departments = ['IT', 'HR', 'Payroll', 'Operations', 'Facilities', 'Training', 'General'];
        
        echo json_encode(['success' => true, 'departments' => $departments]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
