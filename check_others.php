<?php
require_once 'db.php';
$stmt = $pdo->query("
    SELECT u.id, u.full_name, u.hourly_rate_dop, u.monthly_salary_dop, u.compensation_type 
    FROM users u 
    JOIN employees e ON e.user_id = u.id 
    WHERE u.monthly_salary_dop > 0 AND u.hourly_rate_dop >= u.monthly_salary_dop
");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
