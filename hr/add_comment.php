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
$comment = trim((string) ($_POST['comment'] ?? ''));
$is_internal = isset($_POST['is_internal']) ? 1 : 0;

try {
    if ($application_id <= 0 || $comment === '') {
        throw new InvalidArgumentException('Comentario o solicitud invalida.');
    }

    $exists = $pdo->prepare("SELECT id FROM job_applications WHERE id = ?");
    $exists->execute([$application_id]);
    if (!$exists->fetchColumn()) {
        throw new InvalidArgumentException('Solicitud no encontrada.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO application_comments (application_id, user_id, comment, is_internal)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$application_id, $_SESSION['user_id'], $comment, $is_internal]);
    
    $_SESSION['success_message'] = "Comentario agregado exitosamente";
} catch (InvalidArgumentException $e) {
    $_SESSION['error_message'] = $e->getMessage();
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al agregar el comentario";
    error_log($e->getMessage());
}

header("Location: " . ($application_id > 0 ? "view_application.php?id=" . $application_id : "recruitment.php"));
exit;
