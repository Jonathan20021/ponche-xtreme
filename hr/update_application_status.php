<?php
session_start();
require_once '../db.php';

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
    // Get current status
    $stmt = $pdo->prepare("SELECT status FROM job_applications WHERE id = ?");
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
    
    $_SESSION['success_message'] = "Estado actualizado exitosamente";
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al actualizar el estado";
    error_log($e->getMessage());
}

header("Location: view_application.php?id=" . $application_id);
exit;
