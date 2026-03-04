<?php
require 'db.php';
$stmt = $pdo->query('SELECT user_name, current_user_group, COUNT(*) as row_count, SUM(calls) as total_calls FROM vicidial_login_stats GROUP BY user_name, current_user_group ORDER BY total_calls DESC');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
