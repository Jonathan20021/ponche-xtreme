<?php
require __DIR__ . '/../db.php';

$employeeId = (int) ($argv[1] ?? 0);
if ($employeeId <= 0) {
    echo "Usage: php fetch_employee_schedule.php EMPLOYEE_ID\n";
    exit(1);
}

$date = $argv[2] ?? date('Y-m-d');

$stmt = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
$stmt->execute([$employeeId]);
$userId = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM employee_schedules WHERE employee_id = ? AND is_active = 1 AND (effective_date IS NULL OR effective_date <= ?) AND (end_date IS NULL OR end_date >= ?) ORDER BY effective_date DESC, entry_time ASC");
$stmt->execute([$employeeId, $date, $date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$formatted = [];
foreach ($rows as $schedule) {
    $schedule['entry_time_display'] = $schedule['entry_time'] ? date('g:i A', strtotime($schedule['entry_time'])) : null;
    $schedule['exit_time_display'] = $schedule['exit_time'] ? date('g:i A', strtotime($schedule['exit_time'])) : null;
    $schedule['lunch_time_display'] = $schedule['lunch_time'] ? date('g:i A', strtotime($schedule['lunch_time'])) : null;
    $schedule['break_time_display'] = $schedule['break_time'] ? date('g:i A', strtotime($schedule['break_time'])) : null;
    $formatted[] = $schedule;
}

echo "Schedules returned: " . count($formatted) . "\n";
foreach ($formatted as $row) {
    echo "- {$row['schedule_name']} {$row['entry_time_display']}-{$row['exit_time_display']} days=" . ($row['days_of_week'] ?? 'NULL') . "\n";
}

if ($userId) {
    $summary = getScheduleConfigForUser($pdo, $userId, $date);
    echo "\nSummary schedule_name: " . ($summary['schedule_name'] ?? 'NULL') . "\n";
}
