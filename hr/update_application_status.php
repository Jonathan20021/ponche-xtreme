<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('hr_recruitment');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: recruitment.php");
    exit;
}

$application_id = $_POST['application_id'];
$new_status = $_POST['new_status'];
$notes = $_POST['notes'] ?? '';

try {
    // Get current status and candidate info
    $stmt = $pdo->prepare("
        SELECT a.status, a.first_name, a.last_name, a.email, a.application_code, j.title as job_title
        FROM job_applications a
        LEFT JOIN job_postings j ON a.job_posting_id = j.id
        WHERE a.id = ?
    ");
    $stmt->execute([$application_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update application status
    $stmt = $pdo->prepare("UPDATE job_applications SET status = ?, last_updated = NOW() WHERE id = ?");
    $stmt->execute([$new_status, $application_id]);
    
    // Log status change
    $stmt = $pdo->prepare("
        INSERT INTO application_status_history (application_id, old_status, new_status, changed_by, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$application_id, $current['status'], $new_status, $_SESSION['user_id'], $notes]);
    
    // Log recruitment action
    $candidateName = $current['first_name'] . ' ' . $current['last_name'];
    $details = ['old_status' => $current['status'], 'new_status' => $new_status, 'notes' => $notes];
    log_recruitment_action($pdo, $_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], 'status_changed', $application_id, $candidateName, $details);
    
    // Send email notification to candidate
    require_once '../lib/email_functions.php';
    $emailData = [
        'email' => $current['email'],
        'first_name' => $current['first_name'],
        'last_name' => $current['last_name'],
        'application_code' => $current['application_code'],
        'job_title' => $current['job_title'],
        'new_status' => $new_status,
        'notes' => $notes
    ];
    
    $emailResult = sendStatusChangeEmail($emailData);
    if (!$emailResult['success']) {
        error_log("Failed to send status change email: " . $emailResult['message']);
    }
    
    $_SESSION['success_message'] = "Estado actualizado exitosamente";
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al actualizar el estado";
    error_log($e->getMessage());
}

header("Location: view_application.php?id=" . $application_id);
exit;
