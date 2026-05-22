<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_job_postings', '../unauthorized.php');

$job_id = (int) ($_GET['id'] ?? 0);
$new_status = (string) ($_GET['status'] ?? 'inactive');
$allowed_statuses = ['active', 'inactive', 'closed'];

try {
    if ($job_id <= 0 || !in_array($new_status, $allowed_statuses, true)) {
        throw new InvalidArgumentException('Parametros invalidos para actualizar la vacante.');
    }

    $stmt = $pdo->prepare("UPDATE job_postings SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $job_id]);

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT id FROM job_postings WHERE id = ?");
        $check->execute([$job_id]);
        if (!$check->fetchColumn()) {
            throw new InvalidArgumentException('Vacante no encontrada.');
        }
    }

    $_SESSION['success_message'] = "Estado de la vacante actualizado";
} catch (InvalidArgumentException $e) {
    $_SESSION['error_message'] = $e->getMessage();
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al actualizar el estado";
    error_log($e->getMessage());
}

header("Location: job_postings.php");
exit;
