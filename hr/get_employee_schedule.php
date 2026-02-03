<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_employees', '../unauthorized.php');

header('Content-Type: application/json');

$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$userIdParam = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$employeeCode = isset($_GET['employee_code']) ? trim($_GET['employee_code']) : '';
$includeAll = !empty($_GET['include_all']);

if ($employeeId <= 0) {
    echo json_encode(['error' => 'Invalid employee ID']);
    exit;
}

try {
    $date = date('Y-m-d');
    $userId = 0;
    if ($employeeId > 0) {
        $userStmt = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
        $userStmt->execute([$employeeId]);
        $userId = (int) $userStmt->fetchColumn();
    }
    if ($userId <= 0 && $userIdParam > 0) {
        $userId = $userIdParam;
    }
    if ($employeeId <= 0 && $employeeCode !== '') {
        $empStmt = $pdo->prepare("SELECT id, user_id FROM employees WHERE employee_code = ? LIMIT 1");
        $empStmt->execute([$employeeCode]);
        $empRow = $empStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $employeeId = (int) ($empRow['id'] ?? 0);
        if ($userId <= 0) {
            $userId = (int) ($empRow['user_id'] ?? 0);
        }
    }
    if ($employeeId <= 0 && $userId > 0) {
        $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = ? LIMIT 1");
        $empStmt->execute([$userId]);
        $employeeId = (int) $empStmt->fetchColumn();
    }

    if ($includeAll) {
        if ($employeeId > 0) {
            $stmt = $pdo->prepare("
                SELECT * FROM employee_schedules
                WHERE employee_id = ?
                AND is_active = 1
                AND (effective_date IS NULL OR effective_date <= ?)
                AND (end_date IS NULL OR end_date >= ?)
                ORDER BY effective_date DESC, entry_time ASC
            ");
            $stmt->execute([$employeeId, $date, $date]);
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } elseif ($userId > 0) {
            $stmt = $pdo->prepare("
                SELECT * FROM employee_schedules
                WHERE user_id = ?
                AND is_active = 1
                AND (effective_date IS NULL OR effective_date <= ?)
                AND (end_date IS NULL OR end_date >= ?)
                ORDER BY effective_date DESC, entry_time ASC
            ");
            $stmt->execute([$userId, $date, $date]);
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $schedules = [];
        }
    } else {
        if ($employeeId > 0) {
            $schedules = getEmployeeSchedules($pdo, $employeeId, $date);
        } elseif ($userId > 0) {
            $schedules = getEmployeeSchedulesByUserId($pdo, $userId, $date);
        } else {
            $schedules = [];
        }
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

    if (!$summary && !empty($formattedSchedules)) {
        $first = $formattedSchedules[0];
        $summary = [
            'entry_time' => $first['entry_time_display'] ?? null,
            'exit_time' => $first['exit_time_display'] ?? null,
            'lunch_time' => $first['lunch_time_display'] ?? null,
            'break_time' => $first['break_time_display'] ?? null,
            'lunch_minutes' => $first['lunch_minutes'] ?? 0,
            'break_minutes' => $first['break_minutes'] ?? 0,
            'scheduled_hours' => $first['scheduled_hours'] ?? 0,
            'schedule_name' => $first['schedule_name'] ?? 'Horario Personalizado',
            'schedule_count' => count($formattedSchedules),
            'schedule_segments' => $formattedSchedules,
            'is_custom' => true
        ];
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
