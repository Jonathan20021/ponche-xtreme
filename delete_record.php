<?php
session_start();
include 'db.php';
require_once 'lib/logging_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];

    // Validar ID
    if (is_numeric($id)) {
        // Get record data before deleting for logging
        $getQuery = "SELECT a.*, u.full_name FROM attendance a JOIN users u ON a.user_id = u.id WHERE a.id = ?";
        $getStmt = $pdo->prepare($getQuery);
        $getStmt->execute([$id]);
        $recordData = $getStmt->fetch(PDO::FETCH_ASSOC);
        
        // Delete record
        $query = "DELETE FROM attendance WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);

        // Log the deletion
        if ($recordData) {
            log_custom_action(
                $pdo,
                $_SESSION['user_id'],
                $_SESSION['full_name'],
                $_SESSION['role'],
                'attendance',
                'delete',
                "Registro de asistencia eliminado: {$recordData['full_name']} - {$recordData['type']} - {$recordData['timestamp']}",
                'attendance_record',
                $id,
                $recordData
            );
        }

        $_SESSION['message'] = "Record with ID $id has been deleted successfully.";
    } else {
        $_SESSION['message'] = "Invalid record ID.";
    }
    header('Location: records.php');
    exit;
}
?>
