<?php
session_start();
require_once __DIR__ . '/../db.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'No autenticado'
    ]);
    exit;
}

try {
    $userId = $_SESSION['user_id'];
    
    // Delete all chat history for this user
    $stmt = $pdo->prepare("
        DELETE FROM hr_assistant_chat_history
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Historial eliminado correctamente'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al limpiar el historial: ' . $e->getMessage()
    ]);
}
