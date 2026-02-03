<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_employees', '../unauthorized.php');

header('Content-Type: application/json');

$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$includeAll = !empty($_GET['include_all']);

if ($employeeId <= 0) {
    echo json_encode(['error' => 'Invalid employee ID']);
    exit;
}

try {
    $date = date('Y-m-d');
    $userStmt = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
    $userStmt->execute([$employeeId]);
    $userId = (int) $userStmt->fetchColumn();

    if ($includeAll) {
        $stmt = $pdo->prepare("\
            SELECT * FROM employee_schedules
            WHERE employee_id = ?
            AND is_active = 1
            AND (effective_date IS NULL OR effective_date <= ?)
            AND (end_date IS NULL OR end_date >= ?)
            ORDER BY effective_date DESC, entry_time ASC
        ");
        $stmt->execute([$employeeId, $date, $date]);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $schedules = getEmployeeSchedules($pdo, $employeeId, $date);
    }
    $summary = $userId > 0 ? getScheduleConfigForUser($pdo, $userId, $date) : null;

    $formattedSchedules = [];
    foreach ($schedules as $schedule) {
        $schedule['entry_time_display'] = $schedule['entry_time'] ? date('g:i A', strtotime($schedule['entry_time'])) : null;
        $schedule['exit_time_display'] = $schedule['exit_time'] ? date('g:i A', strtotime($schedule['exit_time'])) : null;
        $schedule['lunch_time_display'] = $schedule['lunch_time'] ? date('g:i A', strtotime($schedule['lunch_time'])) : null;
        $schedule['break_time_display'] = $schedule['break_time'] ? date('g:i A', strtotime($schedule['break_time'])) : null;
        $formattedSchedules[] = $schedule;
    }

    if ($summary && !empty($summary['entry_time'])) {
        $summary['entry_time'] = date('g:i A', strtotime($summary['entry_time']));
    }
    if ($summary && !empty($summary['exit_time'])) {
        $summary['exit_time'] = date('g:i A', strtotime($summary['exit_time']));
    }

    echo json_encode([
        'success' => true,
        'schedule' => $summary,
        'schedules' => $formattedSchedules
    ]);
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Error loading schedule: ' . $e->getMessage()
    ]);
}
