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
    // Get candidate info
    $stmt = $pdo->prepare("
        SELECT a.first_name, a.last_name, a.email, a.application_code, j.title as job_title
        FROM job_applications a
        LEFT JOIN job_postings j ON a.job_posting_id = j.id
        WHERE a.id = ?
    ");
    $stmt->execute([$application_id]);
    $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
    
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
    
    // Send email notification to candidate
    require_once '../lib/email_functions.php';
    $emailData = [
        'email' => $candidate['email'],
        'first_name' => $candidate['first_name'],
        'last_name' => $candidate['last_name'],
        'application_code' => $candidate['application_code'],
        'job_title' => $candidate['job_title'],
        'interview_type' => $interview_type,
        'interview_date' => $interview_date,
        'duration_minutes' => $duration_minutes,
        'location' => $location,
        'notes' => $notes
    ];
    
    $emailResult = sendInterviewNotificationEmail($emailData);
    if (!$emailResult['success']) {
        error_log("Failed to send interview notification email: " . $emailResult['message']);
    }
    
    $_SESSION['success_message'] = "Entrevista agendada exitosamente";
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al agendar la entrevista";
    error_log($e->getMessage());
}

header("Location: view_application.php?id=" . $application_id);
exit;
