<?php
// Debug Yuniely's schedules
require_once __DIR__ . '/db.php';

$userId = 17; // Yuniely Chamell Peralta Grullon
$startDate = '2026-02-01';
$endDate = '2026-02-28';

echo "=== YUNIELY CHAMELL PERALTA GRULLON ===\n";
echo "User ID: $userId\n";
echo "Date Range: $startDate to $endDate\n\n";

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

echo "=== CONFIGURED SCHEDULES ===\n";
foreach ($schedules as $idx => $sch) {
    echo "\nSchedule #" . ($idx + 1) . ":\n";
    echo "  ID: {$sch['id']}\n";
    echo "  Name: {$sch['schedule_name']}\n";
    echo "  Hours: {$sch['scheduled_hours']}\n";
    echo "  Days: {$sch['days_of_week']}\n";
    echo "  Effective: {$sch['effective_date']} to " . ($sch['end_date'] ?? 'NULL') . "\n";
    echo "  Time: {$sch['entry_time']} - {$sch['exit_time']}\n";
}

// Fetch global config
$globalScheduleConfig = getScheduleConfig($pdo);

// Build map
$userSchedulesMap = [];
foreach ($schedules as $sch) {
    $userSchedulesMap[$userId][] = $sch;
}

// Use the FIXED resolveScheduleHours function
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

    // If employee has custom schedules but none matched, return 0
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

echo "\n\n=== DAILY CALCULATION ===\n";
$scheduledSeconds = 0;
$daysBySchedule = [];

foreach ($datesArray as $dt) {
    $dateStr = $dt->format('Y-m-d');
    $dayOfWeek = (int) $dt->format('w');
    $dayNames = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $dayName = $dayNames[$dayOfWeek];

    $hours = resolveScheduleHoursFixed($userSchedulesMap, $globalScheduleConfig, $userId, $dateStr);
    $scheduledSeconds += ($hours * 3600);

    if ($hours > 0) {
        // Find which schedule matched
        $matchedSchedule = 'Unknown';
        foreach ($schedules as $sch) {
            $effDate = $sch['effective_date'] ?? '0000-00-00';
            $endDate = $sch['end_date'];
            if ($effDate <= $dateStr && ($endDate === null || $endDate >= $dateStr)) {
                $daysOfWeek = $sch['days_of_week'] ?? '';
                $matchesNumeric = strpos($daysOfWeek, (string) $dayOfWeek) !== false;
                $matchesText = strpos($daysOfWeek, $dayNames[$dayOfWeek]) !== false;
                if ($matchesNumeric || $matchesText) {
                    $matchedSchedule = $sch['schedule_name'];
                    if (!isset($daysBySchedule[$matchedSchedule])) {
                        $daysBySchedule[$matchedSchedule] = 0;
                    }
                    $daysBySchedule[$matchedSchedule]++;
                    break;
                }
            }
        }

        echo "$dateStr ($dayName): $hours h - $matchedSchedule\n";
    }
}

$scheduledHours = $scheduledSeconds / 3600;
$formattedTime = sprintf('%d:%02d:%02d', floor($scheduledHours), ($scheduledHours - floor($scheduledHours)) * 60, 0);

echo "\n=== SUMMARY ===\n";
foreach ($daysBySchedule as $scheduleName => $count) {
    echo "$scheduleName: $count días\n";
}

echo "\n=== RESULT ===\n";
echo "Total Scheduled Hours: $scheduledHours\n";
echo "Formatted: $formattedTime\n";
echo "\nExpected from screenshot: 53:15:00\n";
