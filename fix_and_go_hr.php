<?php
session_start();
require_once __DIR__ . '/db.php';

// Si tiene user_id pero no tiene role, obtenerlo de la BD
if (isset($_SESSION['user_id']) && empty($_SESSION['role'])) {
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT role, username, first_name, last_name FROM users u LEFT JOIN employees e ON u.id = e.user_id WHERE u.id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $_SESSION['role'] = $user['role'];
        if (empty($_SESSION['username'])) {
            $_SESSION['username'] = $user['username'];
        }
    }
}

// Redirigir a HR
header('Location: hr/index.php');
exit;
?>
