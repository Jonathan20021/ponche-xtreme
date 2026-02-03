<?php
require_once 'db.php';

echo "=== Checking Global Schedule Config ===\n\n";

$globalScheduleConfig = getScheduleConfig($pdo);
echo "Global Schedule Configuration:\n";
print_r($globalScheduleConfig);

echo "\n\n=== Checking Users Without Specific Schedules ===\n\n";

$query = "SELECT u.id, u.full_name, u.username, 
    COUNT(es.id) as schedule_count
    FROM users u
    LEFT JOIN employee_schedules es ON es.user_id = u.id AND es.is_active = 1
    WHERE u.is_active = 1
    GROUP BY u.id
    HAVING schedule_count = 0
    LIMIT 10";

$stmt = $pdo->query($query);
$usersWithoutSchedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Users WITHOUT specific schedules (using global):\n";
foreach ($usersWithoutSchedule as $user) {
    echo "  - {$user['full_name']} (ID: {$user['id']})\n";
}

echo "\n\n=== Date Range Calculation ===\n";
$startDate = '2026-02-01';
$endDate = '2026-02-28';

$dateRangeIter = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($endDate))->modify('+1 day')
);

$days = 0;
foreach ($dateRangeIter as $dt) {
    $days++;
}

$globalHours = (float)($globalScheduleConfig['scheduled_hours'] ?? 8.0);
$totalHours = $days * $globalHours;

echo "Start: $startDate\n";
echo "End: $endDate\n";
echo "Days: $days\n";
echo "Global hours per day: $globalHours\n";
echo "Total hours: $totalHours\n";
echo "Formatted: " . floor($totalHours) . ":" . str_pad((($totalHours - floor($totalHours)) * 60), 2, '0', STR_PAD_LEFT) . ":00\n";
