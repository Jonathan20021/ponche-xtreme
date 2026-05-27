<?php
require_once __DIR__ . '/db.php';

// Show vacancy 26 before
$stmt = $pdo->query("SELECT id, title, status, posted_date, closing_date, CURDATE() AS db_today FROM job_postings WHERE id = 26");
echo "BEFORE:\n";
print_r($stmt->fetch(PDO::FETCH_ASSOC));

// Extend closing_date by 60 days from today
$stmt = $pdo->prepare("UPDATE job_postings SET closing_date = DATE_ADD(CURDATE(), INTERVAL 60 DAY) WHERE id = 26 AND status = 'active'");
$stmt->execute();
echo "Rows affected: " . $stmt->rowCount() . "\n";

$stmt = $pdo->query("SELECT id, title, status, posted_date, closing_date, CURDATE() AS db_today FROM job_postings WHERE id = 26");
echo "AFTER:\n";
print_r($stmt->fetch(PDO::FETCH_ASSOC));

// Confirm the public query now finds it
$stmt = $pdo->query("SELECT id, title FROM job_postings WHERE status = 'active' AND (closing_date IS NULL OR closing_date >= CURDATE())");
echo "PUBLIC VISIBLE NOW:\n";
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    print_r($row);
}
