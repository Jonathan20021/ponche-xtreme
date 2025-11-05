<?php
require_once 'db.php';

echo "=== Checking section_permissions table ===\n\n";

$stmt = $pdo->query("SELECT * FROM section_permissions ORDER BY section_key, role");
$permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($permissions)) {
    echo "❌ NO PERMISSIONS FOUND! The section_permissions table is empty.\n";
} else {
    echo "Found " . count($permissions) . " permission entries:\n\n";
    
    $grouped = [];
    foreach ($permissions as $perm) {
        $section = $perm['section_key'];
        $role = $perm['role'];
        if (!isset($grouped[$section])) {
            $grouped[$section] = [];
        }
        $grouped[$section][] = $role;
    }
    
    foreach ($grouped as $section => $roles) {
        echo "📄 $section:\n";
        foreach ($roles as $role) {
            echo "   ✓ $role\n";
        }
        echo "\n";
    }
}

echo "\n=== Checking roles table ===\n\n";
$stmt = $pdo->query("SELECT name FROM roles ORDER BY name");
$roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($roles)) {
    echo "❌ NO ROLES FOUND!\n";
} else {
    echo "Available roles:\n";
    foreach ($roles as $role) {
        echo "   • $role\n";
    }
}

echo "\n=== Checking your user ===\n\n";
$stmt = $pdo->query("SELECT id, username, role FROM users WHERE role = 'developer'");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($users)) {
    echo "❌ No users with role 'developer' found.\n";
} else {
    foreach ($users as $user) {
        echo "User: {$user['username']} (ID: {$user['id']}) - Role: {$user['role']}\n";
    }
}
?>
