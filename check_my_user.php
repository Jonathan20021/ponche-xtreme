<?php
require_once 'db.php';

echo "=== Checking all users ===\n\n";

$stmt = $pdo->query("SELECT id, username, full_name, role, password, COALESCE(is_active, 1) as is_active FROM users ORDER BY id");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $user) {
    echo "ID: {$user['id']}\n";
    echo "Username: {$user['username']}\n";
    echo "Full Name: {$user['full_name']}\n";
    echo "Role: '{$user['role']}'\n";
    echo "Password: {$user['password']}\n";
    echo "Active: " . ($user['is_active'] ? 'YES' : 'NO') . "\n";
    
    // Check if this role has permissions
    $permStmt = $pdo->prepare("SELECT COUNT(*) FROM section_permissions WHERE role = ?");
    $permStmt->execute([$user['role']]);
    $permCount = $permStmt->fetchColumn();
    echo "Permissions: $permCount sections\n";
    
    echo "---\n\n";
}

echo "=== Testing 'Desarrollador' permissions ===\n\n";

$sections = ['dashboard', 'records', 'records_qa', 'view_admin_hours', 'hr_report', 
             'adherencia_report_hr', 'operations_dashboard', 'settings'];

foreach ($sections as $section) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM section_permissions WHERE section_key = ? AND role = 'Desarrollador'");
    $stmt->execute([$section]);
    $hasAccess = $stmt->fetchColumn() > 0;
    echo "$section: " . ($hasAccess ? "✓ YES" : "✗ NO") . "\n";
}
?>
