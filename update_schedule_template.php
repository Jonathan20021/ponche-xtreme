<?php
session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $entryTime = trim($_POST['entry_time'] ?? '10:00');
    $exitTime = trim($_POST['exit_time'] ?? '19:00');
    $lunchTime = trim($_POST['lunch_time'] ?? '14:00');
    $breakTime = trim($_POST['break_time'] ?? '17:00');
    $lunchMinutes = (int)($_POST['lunch_minutes'] ?? 45);
    $breakMinutes = (int)($_POST['break_minutes'] ?? 15);
    $scheduledHours = (float)($_POST['scheduled_hours'] ?? 8.00);
    
    // Validations
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID de turno inválido']);
        exit;
    }
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'El nombre del turno es obligatorio']);
        exit;
    }
    
    // Add seconds if not present
    if (strlen($entryTime) === 5) $entryTime .= ':00';
    if (strlen($exitTime) === 5) $exitTime .= ':00';
    if (strlen($lunchTime) === 5) $lunchTime .= ':00';
    if (strlen($breakTime) === 5) $breakTime .= ':00';
    
    // Check if template name already exists (excluding current)
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schedule_templates WHERE name = ? AND id != ?");
    $checkStmt->execute([$name, $id]);
    if ($checkStmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'Ya existe otro turno con ese nombre']);
        exit;
    }
    
    // Update template
    $stmt = $pdo->prepare("
        UPDATE schedule_templates SET
            name = ?, description = ?, entry_time = ?, exit_time = ?, 
            lunch_time = ?, break_time = ?, lunch_minutes = ?, 
            break_minutes = ?, scheduled_hours = ?, updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([
        $name,
        $description ?: null,
        $entryTime,
        $exitTime,
        $lunchTime,
        $breakTime,
        $lunchMinutes,
        $breakMinutes,
        $scheduledHours,
        $id
    ]);
    
    // Get the updated template
    $getStmt = $pdo->prepare("SELECT * FROM schedule_templates WHERE id = ?");
    $getStmt->execute([$id]);
    $template = $getStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Turno actualizado exitosamente',
        'template' => $template
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al actualizar el turno: ' . $e->getMessage()
    ]);
}
