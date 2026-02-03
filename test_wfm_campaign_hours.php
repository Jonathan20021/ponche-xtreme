<?php
// Test script to verify WFM report changes
require_once 'db.php';

echo "=== WFM Report Campaign Hours Test ===\n\n";

// Test 1: Verify users query includes campaign data
echo "Test 1: Checking users with campaign assignments...\n";
$usersQuery = "SELECT u.id, u.full_name, u.username, u.employee_code, u.department_id, u.role, u.hourly_rate, u.hourly_rate_dop, 
    e.campaign_id, c.name as campaign_name, c.color as campaign_color 
    FROM users u 
    LEFT JOIN employees e ON e.user_id = u.id 
    LEFT JOIN campaigns c ON c.id = e.campaign_id
    LIMIT 5";
$stmt = $pdo->query($usersQuery);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $user) {
    echo "  - {$user['full_name']} (ID: {$user['id']})\n";
    echo "    Campaign: " . ($user['campaign_name'] ?? 'Sin asignar') . "\n";
    echo "    Campaign ID: " . ($user['campaign_id'] ?? 'NULL') . "\n\n";
}

// Test 2: Verify employee schedules with days_of_week
echo "\nTest 2: Checking employee schedules with days_of_week...\n";
$schedQuery = "SELECT es.*, u.full_name, u.username 
    FROM employee_schedules es 
    LEFT JOIN users u ON u.id = es.user_id 
    WHERE es.is_active = 1 
    LIMIT 5";
$stmt = $pdo->query($schedQuery);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($schedules as $sched) {
    echo "  - {$sched['full_name']} - {$sched['schedule_name']}\n";
    echo "    Days: " . ($sched['days_of_week'] ?? 'All days') . "\n";
    echo "    Hours: {$sched['scheduled_hours']}h\n";
    echo "    Effective: {$sched['effective_date']} to " . ($sched['end_date'] ?? 'indefinite') . "\n\n";
}

// Test 3: Test resolveScheduleHours function logic
echo "\nTest 3: Testing day of week resolution...\n";
$testDate = '2026-02-03'; // Monday
$dayOfWeek = date('w', strtotime($testDate));
$dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$currentDay = $dayNames[$dayOfWeek];

echo "  Date: $testDate\n";
echo "  Day of week: $dayOfWeek\n";
echo "  Day name: $currentDay\n\n";

// Test schedule matching
$testSchedule = "Lunes,Martes,Miércoles,Jueves";
$matches = (strpos($testSchedule, $currentDay) !== false);
echo "  Test schedule: '$testSchedule'\n";
echo "  Matches: " . ($matches ? 'YES' : 'NO') . "\n\n";

// Test 4: Check campaigns with employee counts
echo "\nTest 4: Checking campaigns with employee counts...\n";
$campaignQuery = "SELECT c.id, c.name, c.code, c.color, 
    COUNT(e.id) as employee_count
    FROM campaigns c
    LEFT JOIN employees e ON e.campaign_id = c.id
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY employee_count DESC";
$stmt = $pdo->query($campaignQuery);
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($campaigns as $campaign) {
    echo "  - {$campaign['name']} ({$campaign['code']})\n";
    echo "    Employees: {$campaign['employee_count']}\n";
    echo "    Color: {$campaign['color']}\n\n";
}

echo "=== Test Complete ===\n";
