<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_employees');

header('Content-Type: application/json');

$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

if ($employeeId <= 0) {
    echo json_encode(['error' => 'Invalid employee ID']);
    exit;
}

try {
    $schedule = getEmployeeSchedule($pdo, $employeeId);
    
    if ($schedule) {
        // Format times for display
        $schedule['entry_time'] = date('g:i A', strtotime($schedule['entry_time']));
        $schedule['exit_time'] = date('g:i A', strtotime($schedule['exit_time']));
        if ($schedule['lunch_time']) {
            $schedule['lunch_time'] = date('g:i A', strtotime($schedule['lunch_time']));
        }
        if ($schedule['break_time']) {
            $schedule['break_time'] = date('g:i A', strtotime($schedule['break_time']));
        }
    }
    
    echo json_encode([
        'success' => true,
        'schedule' => $schedule
    ]);
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Error loading schedule: ' . $e->getMessage()
    ]);
}
