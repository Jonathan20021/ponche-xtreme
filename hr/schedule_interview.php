<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_recruitment', '../unauthorized.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: recruitment.php");
    exit;
}

$application_id = (int) ($_POST['application_id'] ?? 0);
$interview_type = (string) ($_POST['interview_type'] ?? '');
$interview_date_raw = trim((string) ($_POST['interview_date'] ?? ''));
$duration_minutes = (int) ($_POST['duration_minutes'] ?? 60);
$location = trim((string) ($_POST['location'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$allowed_types = ['phone_screening', 'technical', 'hr', 'manager', 'final', 'other'];

try {
    if ($application_id <= 0 || !in_array($interview_type, $allowed_types, true)) {
        throw new InvalidArgumentException('Parametros invalidos para agendar la entrevista.');
    }

    $timestamp = strtotime($interview_date_raw);
    if ($timestamp === false) {
        throw new InvalidArgumentException('Fecha de entrevista invalida.');
    }
    $interview_date = date('Y-m-d H:i:s', $timestamp);

    if ($duration_minutes < 15 || $duration_minutes > 480) {
        throw new InvalidArgumentException('La duracion debe estar entre 15 y 480 minutos.');
    }

    // Get candidate info
    $stmt = $pdo->prepare("
        SELECT a.first_name, a.last_name, a.email, a.application_code, a.status, j.title as job_title
        FROM job_applications a
        LEFT JOIN job_postings j ON a.job_posting_id = j.id
        WHERE a.id = ?
    ");
    $stmt->execute([$application_id]);
    $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$candidate) {
        throw new InvalidArgumentException('Solicitud no encontrada.');
    }

    $pdo->beginTransaction();
    
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
    $oldStatus = $candidate['status'];
    $stmt = $pdo->prepare("
        UPDATE job_applications 
        SET status = 'interview_scheduled', last_updated = NOW() 
        WHERE id = ? AND status NOT IN ('interviewed', 'offer_extended', 'hired')
    ");
    $stmt->execute([$application_id]);

    if ($stmt->rowCount() > 0 && $oldStatus !== 'interview_scheduled') {
        $history = $pdo->prepare("
            INSERT INTO application_status_history (application_id, old_status, new_status, changed_by, notes)
            VALUES (?, ?, 'interview_scheduled', ?, ?)
        ");
        $history->execute([$application_id, $oldStatus, $_SESSION['user_id'] ?? null, 'Entrevista agendada']);
    }

    $pdo->commit();
    
    // Send email notification to candidate
    try {
        require_once '../lib/email_functions.php';
        if (function_exists('sendInterviewNotificationEmail') && filter_var($candidate['email'], FILTER_VALIDATE_EMAIL) && $candidate['email'] !== 'sin-correo@evallish.local') {
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
        }
    } catch (Throwable $emailError) {
        error_log("Failed to send interview notification email: " . $emailError->getMessage());
    }
    
    $_SESSION['success_message'] = "Entrevista agendada exitosamente";
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = $e->getMessage();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = "Error al agendar la entrevista";
    error_log($e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = "Error al agendar la entrevista";
    error_log($e->getMessage());
}

header("Location: " . ($application_id > 0 ? "view_application.php?id=" . $application_id : "recruitment.php"));
exit;
