<?php
session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID de turno inválido']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM schedule_templates WHERE id = ?");
    $stmt->execute([$id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        echo json_encode(['success' => false, 'error' => 'Turno no encontrado']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'template' => $template
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener el turno: ' . $e->getMessage()
    ]);
}
