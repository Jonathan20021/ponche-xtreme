<?php
require_once 'db.php';

$userId = 34; // Yelissa Mezon Minaya
$monthlySalaryDop = 25000.00;
// Standard DR factor: 23.83 days/month, 8 hours/day
$correctHourlyRateDop = round($monthlySalaryDop / 23.83 / 8, 2);

echo "Correcting user_id $userId...\n";
echo "New hourly_rate_dop: $correctHourlyRateDop\n";

try {
    $stmt = $pdo->prepare("UPDATE users SET hourly_rate_dop = ? WHERE id = ?");
    $stmt->execute([$correctHourlyRateDop, $userId]);
    echo "Update successful.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
