<?php
require_once 'db.php';
$stmt = $pdo->query("SELECT id, username, role, is_active FROM users WHERE role IN ('Supervisor', 'Admin', 'HR', 'Manager')");
while ($row = $stmt->fetch()) {
    echo json_encode($row) . "\n";
}
?>