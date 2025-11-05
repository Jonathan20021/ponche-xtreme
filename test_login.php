<?php
session_start();
require_once 'db.php';
require_once 'find_accessible_page.php';

echo "=== Simulating login for jsandoval ===\n\n";

// Get user
$username = 'jsandoval';
$password = 'Hacker2002';

$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "❌ User not found!\n";
    exit;
}

echo "✓ User found:\n";
echo "  ID: {$user['id']}\n";
echo "  Username: {$user['username']}\n";
echo "  Role: '{$user['role']}'\n";
echo "  Password matches: " . ($password === $user['password'] ? "YES" : "NO") . "\n";
echo "  Active: " . ($user['is_active'] ? "YES" : "NO") . "\n\n";

// Set session
$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = $user['role'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];

echo "=== Session set ===\n";
echo "  user_id: {$_SESSION['user_id']}\n";
echo "  role: {$_SESSION['role']}\n\n";

echo "=== Testing permissions ===\n\n";

$testSections = [
    'dashboard',
    'records', 
    'records_qa',
    'view_admin_hours',
    'hr_report',
    'adherence_report',
    'operations_dashboard',
    'register_attendance',
    'agent_dashboard',
    'login_logs',
    'settings'
];

foreach ($testSections as $section) {
    $hasPermission = userHasPermission($section);
    echo "$section: " . ($hasPermission ? "✓ YES" : "✗ NO") . "\n";
}

echo "\n=== Finding accessible page ===\n\n";

$accessiblePage = findAccessiblePage();

if ($accessiblePage === null) {
    echo "❌ NO ACCESSIBLE PAGE FOUND!\n";
    echo "This is why you get 'No tienes permisos para acceder al portal administrativo'\n";
} else {
    echo "✓ First accessible page: $accessiblePage\n";
    echo "You should be redirected to this page.\n";
}
?>
