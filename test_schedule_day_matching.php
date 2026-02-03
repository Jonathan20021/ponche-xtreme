<?php
// Test resolveScheduleHours logic with different day formats
echo "=== Testing resolveScheduleHours Day Matching ===\n\n";

// Day names mapping
$dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

// Test dates (one for each day of the week)
$testDates = [
    '2026-02-01' => 'Domingo',  // Sunday
    '2026-02-02' => 'Lunes',    // Monday
    '2026-02-03' => 'Martes',   // Tuesday
    '2026-02-04' => 'Miércoles', // Wednesday
    '2026-02-05' => 'Jueves',   // Thursday
    '2026-02-06' => 'Viernes',  // Friday
    '2026-02-07' => 'Sábado'    // Saturday
];

// Test schedules in different formats
$testSchedules = [
    ['name' => 'Weekdays (numeric)', 'days' => '1,2,3,4,5', 'hours' => 8],
    ['name' => 'Weekdays (text)', 'days' => 'Lunes,Martes,Miércoles,Jueves,Viernes', 'hours' => 8],
    ['name' => 'Weekend (numeric)', 'days' => '0,6', 'hours' => 4],
    ['name' => 'Weekend (text)', 'days' => 'Sábado,Domingo', 'hours' => 4],
    ['name' => 'All days (empty)', 'days' => '', 'hours' => 6],
    ['name' => 'Monday only (numeric)', 'days' => '1', 'hours' => 9],
    ['name' => 'Tuesday only (text)', 'days' => 'Martes', 'hours' => 7]
];

foreach ($testSchedules as $schedule) {
    echo "Schedule: {$schedule['name']}\n";
    echo "Days config: '{$schedule['days']}'\n";
    echo "Hours: {$schedule['hours']}\n";
    echo "Matches:\n";
    
    foreach ($testDates as $date => $expectedDay) {
        $dayOfWeek = date('w', strtotime($date));
        $currentDay = $dayNames[$dayOfWeek];
        
        $daysOfWeek = $schedule['days'];
        $matches = false;
        
        if (empty($daysOfWeek)) {
            $matches = true;
        } else {
            $matchesNumeric = strpos($daysOfWeek, (string)$dayOfWeek) !== false;
            $matchesText = strpos($daysOfWeek, $currentDay) !== false;
            $matches = $matchesNumeric || $matchesText;
        }
        
        $result = $matches ? '✓' : '✗';
        $hours = $matches ? $schedule['hours'] : 0;
        echo "  $result $date ($currentDay) => {$hours}h\n";
    }
    echo "\n";
}

echo "=== Test Complete ===\n";
