<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('hr_medical_leaves');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle medical leave creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_leave'])) {
    $employeeId = (int)$_POST['employee_id'];
    $leaveType = $_POST['leave_type'];
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $reason = trim($_POST['reason']);
    $isPaid = isset($_POST['is_paid']) ? 1 : 0;
    $paymentPercentage = (float)($_POST['payment_percentage'] ?? 100);
    $doctorName = trim($_POST['doctor_name'] ?? '');
    $medicalCenter = trim($_POST['medical_center'] ?? '');
    $certificateNumber = trim($_POST['medical_certificate_number'] ?? '');
    $isWorkRelated = isset($_POST['is_work_related']) ? 1 : 0;
    
    // Calculate total days
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $totalDays = $start->diff($end)->days + 1;
    
    // Get user_id from employee_id
    $empStmt = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
    $empStmt->execute([$employeeId]);
    $userId = $empStmt->fetchColumn();
    
    if ($userId) {
        $stmt = $pdo->prepare("
            INSERT INTO medical_leaves 
            (employee_id, user_id, leave_type, diagnosis, start_date, end_date, total_days, 
             is_paid, payment_percentage, doctor_name, medical_center, medical_certificate_number, 
             reason, is_work_related, expected_return_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL 1 DAY))
        ");
        $stmt->execute([
            $employeeId, $userId, $leaveType, $diagnosis, $startDate, $endDate, $totalDays,
            $isPaid, $paymentPercentage, $doctorName, $medicalCenter, $certificateNumber,
            $reason, $isWorkRelated, $endDate
        ]);
        
        // Update employee health stats
        $year = date('Y', strtotime($startDate));
        $statsStmt = $pdo->prepare("
            INSERT INTO employee_health_stats (employee_id, year, total_medical_leaves, total_days_on_leave, last_medical_leave_date)
            VALUES (?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE 
                total_medical_leaves = total_medical_leaves + 1,
                total_days_on_leave = total_days_on_leave + ?,
                last_medical_leave_date = ?,
                total_work_related_incidents = total_work_related_incidents + ?
        ");
        $statsStmt->execute([$employeeId, $year, $totalDays, $startDate, $totalDays, $startDate, $isWorkRelated ? 1 : 0]);
        
        // Get employee name for logging
        $empNameStmt = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE id = ?");
        $empNameStmt->execute([$employeeId]);
        $empName = $empNameStmt->fetch();
        $employeeName = $empName['first_name'] . ' ' . $empName['last_name'];
        
        // Log medical leave creation
        $leaveId = $pdo->lastInsertId();
        $details = [
            'leave_type' => $leaveType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_days' => $totalDays,
            'is_paid' => $isPaid
        ];
        log_medical_leave_action($pdo, $_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], 'create', $leaveId, $employeeName, $details);
        
        $successMsg = "Licencia médica creada correctamente.";
    } else {
        $errorMsg = "No se encontró el empleado especificado.";
    }
}

// Handle medical leave review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_leave'])) {
    $leaveId = (int)$_POST['leave_id'];
    $newStatus = $_POST['new_status'];
    $reviewNotes = trim($_POST['review_notes'] ?? '');
    
    // Get leave details for logging
    $leaveStmt = $pdo->prepare("SELECT ml.*, e.first_name, e.last_name FROM medical_leaves ml JOIN employees e ON ml.employee_id = e.id WHERE ml.id = ?");
    $leaveStmt->execute([$leaveId]);
    $leave = $leaveStmt->fetch();
    
    $stmt = $pdo->prepare("
        UPDATE medical_leaves 
        SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
        WHERE id = ?
    ");
    $stmt->execute([$newStatus, $_SESSION['user_id'], $reviewNotes, $leaveId]);
    
    // Log the review action
    $employeeName = $leave['first_name'] . ' ' . $leave['last_name'];
    $action = $newStatus === 'APPROVED' ? 'approve' : 'reject';
    $details = ['status' => $newStatus, 'review_notes' => $reviewNotes];
    log_medical_leave_action($pdo, $_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], $action, $leaveId, $employeeName, $details);
    
    $successMsg = "Licencia médica revisada correctamente.";
}

// Handle leave extension
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extend_leave'])) {
    $leaveId = (int)$_POST['leave_id'];
    $newEndDate = $_POST['new_end_date'];
    $extensionReason = trim($_POST['extension_reason']);
    
    // Get current end date
    $currentStmt = $pdo->prepare("SELECT end_date, total_days FROM medical_leaves WHERE id = ?");
    $currentStmt->execute([$leaveId]);
    $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($current) {
        $oldEndDate = $current['end_date'];
        $oldEnd = new DateTime($oldEndDate);
        $newEnd = new DateTime($newEndDate);
        $extensionDays = $oldEnd->diff($newEnd)->days;
        
        if ($extensionDays > 0) {
            // Create extension record
            $extStmt = $pdo->prepare("
                INSERT INTO medical_leave_extensions 
                (medical_leave_id, previous_end_date, new_end_date, extension_days, reason, requested_by, status)
                VALUES (?, ?, ?, ?, ?, ?, 'APPROVED')
            ");
            $extStmt->execute([$leaveId, $oldEndDate, $newEndDate, $extensionDays, $extensionReason, $_SESSION['user_id']]);
            
            // Update medical leave
            $newTotalDays = $current['total_days'] + $extensionDays;
            $updateStmt = $pdo->prepare("
                UPDATE medical_leaves 
                SET end_date = ?, total_days = ?, status = 'EXTENDED', expected_return_date = DATE_ADD(?, INTERVAL 1 DAY)
                WHERE id = ?
            ");
            $updateStmt->execute([$newEndDate, $newTotalDays, $newEndDate, $leaveId]);
            
            $successMsg = "Licencia médica extendida correctamente.";
        } else {
            $errorMsg = "La nueva fecha debe ser posterior a la fecha actual de finalización.";
        }
    }
}

// Handle followup creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_followup'])) {
    $leaveId = (int)$_POST['leave_id'];
    $followupDate = $_POST['followup_date'];
    $followupType = $_POST['followup_type'];
    $followupNotes = trim($_POST['followup_notes']);
    
    $stmt = $pdo->prepare("
        INSERT INTO medical_leave_followups 
        (medical_leave_id, followup_date, followup_type, notes, recorded_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$leaveId, $followupDate, $followupType, $followupNotes, $_SESSION['user_id']]);
    
    // Update medical leave to require followup
    $updateStmt = $pdo->prepare("
        UPDATE medical_leaves 
        SET requires_followup = 1, followup_date = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$followupDate, $leaveId]);
    
    $successMsg = "Seguimiento médico registrado correctamente.";
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$employeeFilter = $_GET['employee'] ?? '';
$yearFilter = $_GET['year'] ?? date('Y');

// Build query
$query = "
    SELECT ml.*, 
           e.first_name, e.last_name, e.employee_code, e.position,
           u.username,
           d.name as department_name,
           reviewer.username as reviewer_username,
           (SELECT COUNT(*) FROM medical_leave_extensions WHERE medical_leave_id = ml.id) as extension_count,
           (SELECT COUNT(*) FROM medical_leave_followups WHERE medical_leave_id = ml.id) as followup_count
    FROM medical_leaves ml
    JOIN employees e ON e.id = ml.employee_id
    JOIN users u ON u.id = ml.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN users reviewer ON reviewer.id = ml.reviewed_by
    WHERE YEAR(ml.start_date) = ?
";

$params = [$yearFilter];

if ($statusFilter !== 'all') {
    $query .= " AND ml.status = ?";
    $params[] = strtoupper($statusFilter);
}

if ($typeFilter !== 'all') {
    $query .= " AND ml.leave_type = ?";
    $params[] = strtoupper($typeFilter);
}

if ($employeeFilter !== '') {
    $query .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ?)";
    $searchParam = "%$employeeFilter%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$query .= " ORDER BY ml.start_date DESC, ml.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get employees for dropdown
$employeesStmt = $pdo->query("
    SELECT e.id, e.first_name, e.last_name, e.employee_code, d.name as department_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.employment_status = 'ACTIVE'
    ORDER BY e.last_name, e.first_name
");
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'total' => $pdo->prepare("SELECT COUNT(*) FROM medical_leaves WHERE YEAR(start_date) = ?"),
    'pending' => $pdo->prepare("SELECT COUNT(*) FROM medical_leaves WHERE status = 'PENDING' AND YEAR(start_date) = ?"),
    'approved' => $pdo->prepare("SELECT COUNT(*) FROM medical_leaves WHERE status = 'APPROVED' AND YEAR(start_date) = ?"),
    'active' => $pdo->prepare("SELECT COUNT(*) FROM medical_leaves WHERE status IN ('APPROVED', 'EXTENDED') AND end_date >= CURDATE() AND YEAR(start_date) = ?"),
    'total_days' => $pdo->prepare("SELECT COALESCE(SUM(total_days), 0) FROM medical_leaves WHERE status IN ('APPROVED', 'EXTENDED', 'COMPLETED') AND YEAR(start_date) = ?"),
];

foreach ($stats as $key => $stmt) {
    $stmt->execute([$yearFilter]);
    $stats[$key] = $stmt->fetchColumn();
}

require_once 'medical_leaves_view.php';
?>
