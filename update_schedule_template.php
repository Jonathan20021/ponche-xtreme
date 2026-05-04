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
    $entryTime = normalizeScheduleTimeValue($_POST['entry_time'] ?? null, '10:00:00');
    $exitTime = normalizeScheduleTimeValue($_POST['exit_time'] ?? null, '19:00:00');
    $lunchTime = normalizeScheduleTimeValue($_POST['lunch_time'] ?? null, '14:00:00');
    $breakTime = normalizeScheduleTimeValue($_POST['break_time'] ?? null, null);
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

    $templateStmt = $pdo->prepare("SELECT * FROM schedule_templates WHERE id = ? LIMIT 1");
    $templateStmt->execute([$id]);
    $oldTemplate = $templateStmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldTemplate) {
        echo json_encode(['success' => false, 'error' => 'Turno no encontrado']);
        exit;
    }
    
    // Check if template name already exists (excluding current)
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schedule_templates WHERE name = ? AND id != ?");
    $checkStmt->execute([$name, $id]);
    if ($checkStmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'Ya existe otro turno con ese nombre']);
        exit;
    }

    $pdo->beginTransaction();
    
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

    $assignmentStmt = $pdo->prepare("
        UPDATE employee_schedules SET
            schedule_name = ?,
            entry_time = ?,
            exit_time = ?,
            lunch_time = ?,
            break_time = ?,
            lunch_minutes = ?,
            break_minutes = ?,
            scheduled_hours = ?,
            updated_at = NOW()
        WHERE schedule_name = ?
          AND is_active = 1
          AND (end_date IS NULL OR end_date >= CURDATE())
    ");
    $assignmentStmt->execute([
        $name,
        $entryTime,
        $exitTime,
        $lunchTime,
        $breakTime,
        $lunchMinutes,
        $breakMinutes,
        $scheduledHours,
        $oldTemplate['name']
    ]);
    $updatedAssignments = $assignmentStmt->rowCount();
    
    // Get the updated template
    $getStmt = $pdo->prepare("SELECT * FROM schedule_templates WHERE id = ?");
    $getStmt->execute([$id]);
    $template = $getStmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Turno actualizado exitosamente',
        'template' => $template,
        'updated_assignments' => $updatedAssignments
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Error al actualizar el turno: ' . $e->getMessage()
    ]);
}
