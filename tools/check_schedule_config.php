<?php
require __DIR__ . '/../db.php';

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    echo "Usage: php check_schedule_config.php USER_ID\n";
    exit(1);
}

$date = $argv[2] ?? date('Y-m-d');
$schedule = getScheduleConfigForUser($pdo, $userId, $date);

echo "User ID: {$userId}, Date: {$date}\n";
print_r($schedule);
