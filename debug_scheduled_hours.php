<?php
require_once 'db.php';

// Simulate WFM calculation for specific user
$userId = 10; // Sadelyn García Infante
$startDate = '2026-01-27'; // Week start
$endDate = '2026-02-02';   // Week end

echo "=== Debugging Scheduled Hours Calculation ===\n";
echo "User ID: $userId\n";
echo "Date Range: $startDate to $endDate\n\n";

// Get user schedules
$schedQuery = "SELECT * FROM employee_schedules WHERE user_id = ? AND is_active = 1";
$stmt = $pdo->prepare($schedQuery);
$stmt->execute([$userId]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Active Schedules for user:\n";
foreach ($schedules as $sch) {
    echo "  - {$sch['schedule_name']}\n";
    echo "    Hours: {$sch['scheduled_hours']}\n";
    echo "    Days: " . ($sch['days_of_week'] ?: 'All days') . "\n";
    echo "    Effective: {$sch['effective_date']} to " . ($sch['end_date'] ?: 'indefinite') . "\n\n";
}

// Simulate date iteration
echo "Daily calculation:\n";
$dateRangeIter = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($endDate))->modify('+1 day')
);

$totalScheduledSeconds = 0;
$dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

foreach ($dateRangeIter as $dt) {
    $dateStr = $dt->format('Y-m-d');
    $dayOfWeek = date('w', strtotime($dateStr));
    $currentDay = $dayNames[$dayOfWeek];
    
    $dayHours = 0;
    
    foreach ($schedules as $sch) {
        $effDate = $sch['effective_date'] ?? '0000-00-00';
        $endDate = $sch['end_date'];
        
        if ($effDate <= $dateStr && ($endDate === null || $endDate >= $dateStr)) {
            $daysOfWeek = $sch['days_of_week'] ?? '';
            
            if (empty($daysOfWeek)) {
                $dayHours += (float)$sch['scheduled_hours'];
                echo "  $dateStr ($currentDay): +{$sch['scheduled_hours']}h from '{$sch['schedule_name']}' (all days)\n";
            } else {
                $matchesNumeric = strpos($daysOfWeek, (string)$dayOfWeek) !== false;
                $matchesText = strpos($daysOfWeek, $currentDay) !== false;
                
                if ($matchesNumeric || $matchesText) {
                    $dayHours += (float)$sch['scheduled_hours'];
                    echo "  $dateStr ($currentDay): +{$sch['scheduled_hours']}h from '{$sch['schedule_name']}' (matches: $daysOfWeek)\n";
                } else {
                    echo "  $dateStr ($currentDay): No match for '{$sch['schedule_name']}' (days: $daysOfWeek)\n";
                }
            }
        }
    }
    
    $totalScheduledSeconds += ($dayHours * 3600);
    echo "  Total for $dateStr: {$dayHours}h\n\n";
}

$totalHours = $totalScheduledSeconds / 3600;
echo "Total Scheduled Seconds: $totalScheduledSeconds\n";
echo "Total Scheduled Hours: $totalHours\n";
echo "Formatted: " . floor($totalHours) . "h " . (($totalHours - floor($totalHours)) * 60) . "min\n";

// Now check how many days in the period
$days = iterator_count(new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($endDate))->modify('+1 day')
));
echo "\nDays in period: $days\n";
