<?php
// Simulate EXACT logic from wfm_report.php for Elvis
require_once __DIR__ . '/db.php';

$userId = 71;
$startDate = '2026-02-01';
$endDate = '2026-02-28';

echo "=== SIMULATING WFM_REPORT.PHP LOGIC ===\n";
echo "User ID: $userId\n";
echo "Date Range: $startDate to $endDate\n\n";

// Fetch global config
$globalScheduleConfig = getScheduleConfig($pdo);
echo "Global Schedule Config:\n";
print_r($globalScheduleConfig);
echo "\n";

// Fetch user schedules (sorted by effective_date DESC like in the report)
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

echo "User Schedules:\n";
print_r($userSchedulesMap);
echo "\n";

// Copy the resolveScheduleHours function from wfm_report.php
function resolveScheduleHours($map, $defaultConfig, $userId, $dateStr)
{
    // Get day of week from date (0=Sunday, 1=Monday, etc.)
    $dayOfWeek = date('w', strtotime($dateStr));
    // Convert to Spanish days used in system (Lunes, Martes, etc.)
    $dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $currentDay = $dayNames[$dayOfWeek];

    // Schedules are already sorted by effective_date DESC
    // We need to find the FIRST (most recent) schedule that applies to this date
    if (isset($map[$userId])) {
        foreach ($map[$userId] as $sch) {
            // Check dates: effective_date <= dateStr AND (end_date IS NULL OR end_date >= dateStr)
            $effDate = $sch['effective_date'] ?? '0000-00-00';
            $endDate = $sch['end_date'];

            if ($effDate <= $dateStr && ($endDate === null || $endDate >= $dateStr)) {
                // Check if this schedule applies to this day of week
                $daysOfWeek = $sch['days_of_week'] ?? '';

                // If days_of_week is empty or null, assume it applies to all days
                if (empty($daysOfWeek)) {
                    return (float) ($sch['scheduled_hours'] ?? 0);
                } else {
                    // Check both formats: numeric (1,2,3) and text (Lunes,Martes,Miércoles)
                    $matchesNumeric = strpos($daysOfWeek, (string) $dayOfWeek) !== false;
                    $matchesText = strpos($daysOfWeek, $currentDay) !== false;

                    if ($matchesNumeric || $matchesText) {
                        return (float) ($sch['scheduled_hours'] ?? 0);
                    }
                }
            }
        }
    }

    // Fallback global
    return (float) ($defaultConfig['scheduled_hours'] ?? 8.0);
}

// Create array of dates (like in the fixed code)
$dateRangeIter = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($endDate))->modify('+1 day')
);
$datesArray = iterator_to_array($dateRangeIter);

echo "=== CALCULATING SCHEDULED HOURS ===\n";
$scheduledSeconds = 0;
foreach ($datesArray as $dt) {
    $dateStr = $dt->format('Y-m-d');
    $hours = resolveScheduleHours($userSchedulesMap, $globalScheduleConfig, $userId, $dateStr);
    $scheduledSeconds += ($hours * 3600);
    if ($hours > 0) {
        echo "$dateStr: $hours hours\n";
    }
}

$scheduledHours = $scheduledSeconds / 3600;
echo "\n=== RESULT ===\n";
echo "Total Scheduled Seconds: $scheduledSeconds\n";
echo "Total Scheduled Hours: $scheduledHours\n";
echo "Formatted: " . sprintf('%d:%02d:%02d', floor($scheduledHours), ($scheduledHours * 60) % 60, 0) . "\n";
