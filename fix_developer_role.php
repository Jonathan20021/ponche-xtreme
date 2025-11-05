<?php
require_once 'db.php';

echo "=== Finding users with incorrect 'developer' role ===\n\n";

$stmt = $pdo->query("SELECT id, username, role FROM users WHERE role = 'developer'");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($users)) {
    echo "No users found with role 'developer'\n";
    echo "\nLet's check all users:\n";
    $stmt = $pdo->query("SELECT id, username, role FROM users ORDER BY id");
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allUsers as $user) {
        echo "  • {$user['username']} - Role: {$user['role']}\n";
    }
} else {
    echo "Found " . count($users) . " user(s) with role 'developer':\n\n";
    
    foreach ($users as $user) {
        echo "User: {$user['username']} (ID: {$user['id']})\n";
        echo "Current role: {$user['role']}\n";
        echo "Fixing to: Desarrollador\n";
        
        $updateStmt = $pdo->prepare("UPDATE users SET role = 'Desarrollador' WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        echo "✓ Fixed!\n\n";
    }
    
    echo "All users have been updated. You can now log in with the correct permissions.\n";
}
?>
