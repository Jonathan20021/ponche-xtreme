<?php
require 'db.php';

$stmt = $pdo->prepare('SELECT id, full_name, username FROM users WHERE full_name LIKE ? ORDER BY full_name');
$stmt->execute(['%Yuniely%']);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo "ID: {$u['id']} - {$u['full_name']} ({$u['username']})\n";
}
