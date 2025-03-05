<?php
session_start();
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];

    // Validar ID
    if (is_numeric($id)) {
        $query = "DELETE FROM attendance WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);

        $_SESSION['message'] = "Record with ID $id has been deleted successfully.";
    } else {
        $_SESSION['message'] = "Invalid record ID.";
    }
    header('Location: records.php'); // Cambia esto por la página de la tabla
    exit;
}
?>
