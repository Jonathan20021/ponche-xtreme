<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_job_postings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: job_postings.php");
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO job_postings 
        (title, department, location, employment_type, description, requirements, 
         responsibilities, salary_range, closing_date, posted_date, created_by, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'active')
    ");
    
    $stmt->execute([
        $_POST['title'],
        $_POST['department'],
        $_POST['location'],
        $_POST['employment_type'],
        $_POST['description'],
        $_POST['requirements'] ?? null,
        $_POST['responsibilities'] ?? null,
        $_POST['salary_range'] ?? null,
        !empty($_POST['closing_date']) ? $_POST['closing_date'] : null,
        $_SESSION['user_id']
    ]);
    
    $_SESSION['success_message'] = "Vacante publicada exitosamente";
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al publicar la vacante";
    error_log($e->getMessage());
}

header("Location: job_postings.php");
exit;
