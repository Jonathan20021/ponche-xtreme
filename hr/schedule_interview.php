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
$interview_type = $_POST['interview_type'];
$interview_date = $_POST['interview_date'];
$duration_minutes = $_POST['duration_minutes'] ?? 60;
$location = $_POST['location'] ?? '';
$notes = $_POST['notes'] ?? '';

try {
    $stmt = $pdo->prepare("
        INSERT INTO recruitment_interviews 
        (application_id, interview_type, interview_date, duration_minutes, location, notes, created_by, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')
    ");
    $stmt->execute([
        $application_id, 
        $interview_type, 
        $interview_date, 
        $duration_minutes, 
        $location, 
        $notes, 
        $_SESSION['user_id']
    ]);
    
    // Update application status to interview_scheduled if not already
    $stmt = $pdo->prepare("
        UPDATE job_applications 
        SET status = 'interview_scheduled', last_updated = NOW() 
        WHERE id = ? AND status NOT IN ('interviewed', 'offer_extended', 'hired')
    ");
    $stmt->execute([$application_id]);
    
    $_SESSION['success_message'] = "Entrevista agendada exitosamente";
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al agendar la entrevista";
    error_log($e->getMessage());
}

header("Location: view_application.php?id=" . $application_id);
exit;
