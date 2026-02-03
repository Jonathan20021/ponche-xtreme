<?php
require __DIR__ . '/../db.php';

$employeeId = (int) ($argv[1] ?? 0);
if ($employeeId <= 0) {
    echo "Usage: php check_employee_schedule_logic.php EMPLOYEE_ID [YYYY-MM-DD]\n";
    exit(1);
}

$date = $argv[2] ?? date('Y-m-d');
$userStmt = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
$userStmt->execute([$employeeId]);
$userId = (int) $userStmt->fetchColumn();

$schedules = getEmployeeSchedules($pdo, $employeeId, $date);
$summary = $userId > 0 ? getScheduleConfigForUser($pdo, $userId, $date) : null;

echo "Employee ID: {$employeeId}, User ID: {$userId}, Date: {$date}\n";

echo "Filtered schedules (getEmployeeSchedules): " . count($schedules) . "\n";
foreach ($schedules as $s) {
    echo "- {$s['schedule_name']} {$s['entry_time']}-{$s['exit_time']} days=" . ($s['days_of_week'] ?? 'NULL') . " active={$s['is_active']}\n";
}

echo "\nSummary (getScheduleConfigForUser):\n";
print_r($summary);
