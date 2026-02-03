<?php
require __DIR__ . '/../db.php';

$employeeCode = $argv[1] ?? '';
if ($employeeCode === '') {
    echo "Usage: php check_employee_schedules.php EMP-YYYY-XXXX\n";
    exit(1);
}

$stmt = $pdo->prepare("SELECT id, user_id, employee_code, first_name, last_name FROM employees WHERE employee_code = ? LIMIT 1");
$stmt->execute([$employeeCode]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    echo "Employee not found for code {$employeeCode}\n";
    exit(1);
}

$employeeId = (int) $employee['id'];
$userId = (int) $employee['user_id'];

echo "Employee ID: {$employeeId}, User ID: {$userId}, Name: {$employee['first_name']} {$employee['last_name']}\n";

$schedStmt = $pdo->prepare("SELECT id, schedule_name, entry_time, exit_time, effective_date, end_date, days_of_week, is_active FROM employee_schedules WHERE employee_id = ? ORDER BY effective_date DESC, entry_time ASC");
$schedStmt->execute([$employeeId]);
$rows = $schedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo "Schedules: " . count($rows) . "\n";
foreach ($rows as $row) {
    echo sprintf(
        "#%d %s %s-%s effective=%s end=%s days=%s active=%s\n",
        $row['id'],
        $row['schedule_name'] ?? 'Horario',
        $row['entry_time'],
        $row['exit_time'],
        $row['effective_date'] ?? 'NULL',
        $row['end_date'] ?? 'NULL',
        $row['days_of_week'] ?? 'NULL',
        $row['is_active']
    );
}
