<?php
// Test the FIXED logic
require_once __DIR__ . '/db.php';

$userId = 71;
$startDate = '2026-02-01';
$endDate = '2026-02-28';

echo "=== TESTING FIXED LOGIC ===\n";
echo "User ID: $userId (Elvis Joel Rojas Núñez)\n";
echo "Date Range: $startDate to $endDate\n\n";

// Fetch global config
$globalScheduleConfig = getScheduleConfig($pdo);

// Fetch user schedules
$schedQuery = "
    SELECT * FROM employee_schedules 
    WHERE user_id = ? 
    AND is_active = 1 
    ORDER BY effective_date DESC
";
$schedStmt = $pdo->prepare($schedQuery);
$schedStmt->execute([$userId]);
$allSchedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

$userSchedulesMap = [];
foreach ($allSchedules as $sch) {
    $userSchedulesMap[$userId][] = $sch;
}

// FIXED resolveScheduleHours function
function resolveScheduleHoursFixed($map, $defaultConfig, $userId, $dateStr)
{
    $dayOfWeek = date('w', strtotime($dateStr));
    $dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $currentDay = $dayNames[$dayOfWeek];

    if (isset($map[$userId])) {
        foreach ($map[$userId] as $sch) {
            $effDate = $sch['effective_date'] ?? '0000-00-00';
            $endDate = $sch['end_date'];

            if ($effDate <= $dateStr && ($endDate === null || $endDate >= $dateStr)) {
                $daysOfWeek = $sch['days_of_week'] ?? '';

                if (empty($daysOfWeek)) {
                    return (float) ($sch['scheduled_hours'] ?? 0);
                } else {
                    $matchesNumeric = strpos($daysOfWeek, (string) $dayOfWeek) !== false;
                    $matchesText = strpos($daysOfWeek, $currentDay) !== false;

                    if ($matchesNumeric || $matchesText) {
                        return (float) ($sch['scheduled_hours'] ?? 0);
                    }
                }
            }
        }
    }

    // NEW LOGIC: If employee has custom schedules but none matched, return 0
    if (isset($map[$userId]) && !empty($map[$userId])) {
        return 0.0;
    }

    // Fallback to global schedule only if employee has NO custom schedules at all
    return (float) ($defaultConfig['scheduled_hours'] ?? 8.0);
}

// Calculate
$dateRangeIter = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($endDate))->modify('+1 day')
);
$datesArray = iterator_to_array($dateRangeIter);

echo "=== CALCULATING WITH FIXED LOGIC ===\n";
$scheduledSeconds = 0;
$dayCount = 0;
foreach ($datesArray as $dt) {
    $dateStr = $dt->format('Y-m-d');
    $hours = resolveScheduleHoursFixed($userSchedulesMap, $globalScheduleConfig, $userId, $dateStr);
    $scheduledSeconds += ($hours * 3600);
    if ($hours > 0) {
        $dayCount++;
        echo "$dateStr: $hours hours\n";
    }
}

$scheduledHours = $scheduledSeconds / 3600;
echo "\n=== RESULT ===\n";
echo "Days with hours: $dayCount\n";
echo "Total Scheduled Hours: $scheduledHours\n";
echo "Formatted: " . sprintf('%d:%02d:%02d', floor($scheduledHours), 0, 0) . "\n";
echo "\nExpected: 72 hours (18 weekdays from Feb 4-28 × 4 hours)\n";
