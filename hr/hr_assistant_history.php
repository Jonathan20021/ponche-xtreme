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
    
    // Get chat history for this user (last 50 messages)
    $stmt = $pdo->prepare("
        SELECT 
            message,
            response,
            created_at
        FROM hr_assistant_chat_history
        WHERE user_id = ?
        ORDER BY created_at ASC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'history' => $history
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al cargar el historial: ' . $e->getMessage()
    ]);
}
