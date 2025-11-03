<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_job_postings');

$job_id = $_GET['id'] ?? 0;
$new_status = $_GET['status'] ?? 'inactive';

try {
    $stmt = $pdo->prepare("UPDATE job_postings SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $job_id]);
    
    $_SESSION['success_message'] = "Estado de la vacante actualizado";
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al actualizar el estado";
    error_log($e->getMessage());
}

header("Location: job_postings.php");
exit;
