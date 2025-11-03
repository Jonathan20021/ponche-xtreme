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
$comment = $_POST['comment'];
$is_internal = isset($_POST['is_internal']) ? 1 : 0;

try {
    $stmt = $pdo->prepare("
        INSERT INTO application_comments (application_id, user_id, comment, is_internal)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$application_id, $_SESSION['user_id'], $comment, $is_internal]);
    
    $_SESSION['success_message'] = "Comentario agregado exitosamente";
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al agregar el comentario";
    error_log($e->getMessage());
}

header("Location: view_application.php?id=" . $application_id);
exit;
