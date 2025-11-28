<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

header('Content-Type: application/json');

// Verificar que el usuario tenga permiso para mensajes masivos
if (!isset($_SESSION['user_id']) || !userHasPermission('chat_mass_message')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    // Obtener estadísticas de usuarios
    $activeUsersStmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE id != ? AND is_active = 1
    ");
    $activeUsersStmt->execute([$userId]);
    $activeUsers = $activeUsersStmt->fetchColumn();

    $totalUsersStmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE id != ?
    ");
    $totalUsersStmt->execute([$userId]);
    $totalUsers = $totalUsersStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'active_users' => (int)$activeUsers,
        'total_users' => (int)$totalUsers,
        'recipients' => (int)$activeUsers // Los destinatarios son los usuarios activos
    ]);

} catch (Exception $e) {
    error_log("Mass message stats error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor']);
}
?>
