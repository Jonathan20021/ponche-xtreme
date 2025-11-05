<?php
require_once 'db.php';

$stmt = $pdo->query("SELECT id, username, role, LENGTH(role) as role_length, HEX(role) as role_hex FROM users WHERE username = 'jsandoval'");
$user = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== Analyzing jsandoval role ===\n\n";
echo "Role value: '{$user['role']}'\n";
echo "Role length: {$user['role_length']} characters\n";
echo "Role HEX: {$user['role_hex']}\n\n";

// Expected
$expected = 'Desarrollador';
echo "Expected: '$expected'\n";
echo "Expected length: " . strlen($expected) . "\n";
echo "Expected HEX: " . bin2hex($expected) . "\n\n";

if ($user['role'] === $expected) {
    echo "✓ Roles match EXACTLY\n";
} else {
    echo "✗ Roles DO NOT match\n";
    echo "Difference: ";
    for ($i = 0; $i < max(strlen($user['role']), strlen($expected)); $i++) {
        $char1 = isset($user['role'][$i]) ? $user['role'][$i] : '∅';
        $char2 = isset($expected[$i]) ? $expected[$i] : '∅';
        if ($char1 !== $char2) {
            echo "Position $i: got '$char1' expected '$char2'\n";
        }
    }
}

echo "\n=== Checking for extra whitespace ===\n";
$trimmed = trim($user['role']);
if ($trimmed !== $user['role']) {
    echo "⚠ WARNING: Role has leading or trailing whitespace!\n";
    echo "Fixing...\n";
    $updateStmt = $pdo->prepare("UPDATE users SET role = TRIM(role) WHERE id = ?");
    $updateStmt->execute([$user['id']]);
    echo "✓ Fixed! Try logging in again.\n";
} else {
    echo "✓ No extra whitespace found\n";
}
?>
