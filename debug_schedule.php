<?php
// Debug script to check what's happening with schedule resolution
require_once __DIR__ . '/db.php';

$userId = 71; // Elvis Joel Rojas Núñez
$startDate = '2026-02-01';
$endDate = '2026-02-28';

// Fetch user schedules
$schedQuery = "
    SELECT * FROM employee_schedules 
    WHERE user_id = ? 
    AND is_active = 1 
    ORDER BY effective_date DESC
";
$schedStmt = $pdo->prepare($schedQuery);
$schedStmt->execute([$userId]);
$schedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== SCHEDULES FOR USER $userId ===\n";
foreach ($schedules as $sch) {
    echo "ID: {$sch['id']}\n";
    echo "  Name: {$sch['schedule_name']}\n";
    echo "  Hours: {$sch['scheduled_hours']}\n";
    echo "  Days of Week: {$sch['days_of_week']}\n";
    echo "  Effective: {$sch['effective_date']} to " . ($sch['end_date'] ?? 'NULL') . "\n";
    echo "  Active: {$sch['is_active']}\n";
    echo "\n";
}

// Test schedule resolution for each day
$dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

echo "\n=== DAILY SCHEDULE RESOLUTION ===\n";
$totalHours = 0;
$start = new DateTime($startDate);
$end = new DateTime($endDate);
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, $end->modify('+1 day'));

foreach ($period as $date) {
    $dateStr = $date->format('Y-m-d');
    $dayOfWeek = (int) $date->format('w');
    $currentDay = $dayNames[$dayOfWeek];

    $hoursForDay = 0;

    foreach ($schedules as $sch) {
        $effDate = $sch['effective_date'] ?? '0000-00-00';
        $endDate = $sch['end_date'];

        if ($effDate <= $dateStr && ($endDate === null || $endDate >= $dateStr)) {
            $daysOfWeek = $sch['days_of_week'] ?? '';

            if (empty($daysOfWeek)) {
                $hoursForDay = (float) ($sch['scheduled_hours'] ?? 0);
                echo "$dateStr ($currentDay): {$sch['scheduled_hours']}h (no days_of_week filter)\n";
                break;
            } else {
                $matchesNumeric = strpos($daysOfWeek, (string) $dayOfWeek) !== false;
                $matchesText = strpos($daysOfWeek, $currentDay) !== false;

                if ($matchesNumeric || $matchesText) {
                    $hoursForDay = (float) ($sch['scheduled_hours'] ?? 0);
                    echo "$dateStr ($currentDay): {$sch['scheduled_hours']}h (matches: $daysOfWeek)\n";
                    break;
                }
            }
        }
    }

    $totalHours += $hoursForDay;
}

echo "\n=== TOTAL ===\n";
echo "Total Hours: $totalHours\n";
echo "Expected: 80 hours (20 weekdays × 4 hours)\n";
