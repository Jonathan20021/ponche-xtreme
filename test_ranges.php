<?php
// Check what date range might give 152 hours
require 'db.php';

$userId = 71;

echo "=== TESTING DIFFERENT DATE RANGES ===\n\n";

$testRanges = [
    ['2026-02-01', '2026-02-28', 'Febrero completo'],
    ['2026-01-01', '2026-02-28', 'Enero + Febrero'],
    ['2026-02-04', '2026-02-28', 'Desde fecha efectiva'],
    ['2026-01-01', '2026-01-31', 'Solo Enero'],
];

$schedQuery = "
    SELECT * FROM employee_schedules 
    WHERE user_id = ? 
    AND is_active = 1 
    ORDER BY effective_date DESC
";
$schedStmt = $pdo->prepare($schedQuery);
$schedStmt->execute([$userId]);
$schedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

$dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

foreach ($testRanges as [$startDate, $endDate, $label]) {
    $totalHours = 0;
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end->modify('+1 day'));

    foreach ($period as $date) {
        $dateStr = $date->format('Y-m-d');
        $dayOfWeek = (int) $date->format('w');
        $currentDay = $dayNames[$dayOfWeek];

        foreach ($schedules as $sch) {
            $effDate = $sch['effective_date'] ?? '0000-00-00';
            $endDateSch = $sch['end_date'];

            if ($effDate <= $dateStr && ($endDateSch === null || $endDateSch >= $dateStr)) {
                $daysOfWeek = $sch['days_of_week'] ?? '';

                if (empty($daysOfWeek)) {
                    $totalHours += (float) ($sch['scheduled_hours'] ?? 0);
                    break;
                } else {
                    $matchesNumeric = strpos($daysOfWeek, (string) $dayOfWeek) !== false;
                    $matchesText = strpos($daysOfWeek, $currentDay) !== false;

                    if ($matchesNumeric || $matchesText) {
                        $totalHours += (float) ($sch['scheduled_hours'] ?? 0);
                        break;
                    }
                }
            }
        }
    }

    echo "$label ($startDate to $endDate): $totalHours hours\n";
}

echo "\n=== LOOKING FOR 152 HOURS ===\n";
echo "152 hours / 4 hours per day = 38 weekdays\n";
echo "This would be approximately 7.6 weeks or ~54 calendar days\n";
