<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('hr_recruitment', '../unauthorized.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: recruitment.php");
    exit;
}

$application_id = (int) ($_POST['application_id'] ?? 0);
$new_status = (string) ($_POST['new_status'] ?? '');
$notes = trim((string) ($_POST['notes'] ?? ''));
$allowed_statuses = ['new', 'reviewing', 'shortlisted', 'interview_scheduled', 'interviewed', 'offer_extended', 'hired', 'rejected', 'withdrawn'];

try {
    if ($application_id <= 0 || !in_array($new_status, $allowed_statuses, true)) {
        throw new InvalidArgumentException('Parametros invalidos para actualizar la solicitud.');
    }

    // Get current status and candidate info
    $stmt = $pdo->prepare("
        SELECT a.status, a.first_name, a.last_name, a.email, a.application_code, j.title as job_title
        FROM job_applications a
        LEFT JOIN job_postings j ON a.job_posting_id = j.id
        WHERE a.id = ?
    ");
    $stmt->execute([$application_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        throw new InvalidArgumentException('Solicitud no encontrada.');
    }

    if ($current['status'] === $new_status) {
        $_SESSION['success_message'] = "La solicitud ya tenia ese estado";
        header("Location: view_application.php?id=" . $application_id);
        exit;
    }

    $pdo->beginTransaction();
    
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
    log_recruitment_action($pdo, (int) $_SESSION['user_id'], $_SESSION['full_name'] ?? '', $_SESSION['role'] ?? '', 'status_changed', $application_id, $candidateName, $details);

    $pdo->commit();
    
    // Send email notification to candidate
    try {
        require_once '../lib/email_functions.php';
        if (function_exists('sendStatusChangeEmail') && filter_var($current['email'], FILTER_VALIDATE_EMAIL) && $current['email'] !== 'sin-correo@evallish.local') {
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
        }
    } catch (Throwable $emailError) {
        error_log("Failed to send status change email: " . $emailError->getMessage());
    }
    
    $_SESSION['success_message'] = "Estado actualizado exitosamente";
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = $e->getMessage();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = "Error al actualizar el estado";
    error_log($e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = "Error al actualizar el estado";
    error_log($e->getMessage());
}

header("Location: " . ($application_id > 0 ? "view_application.php?id=" . $application_id : "recruitment.php"));
exit;
