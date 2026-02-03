<?php
session_start();
require_once '../../db.php';

echo "<h2>Fix de Sesión - Establecer Role</h2>";
echo "<style>body { font-family: monospace; padding: 20px; background: #1e293b; color: #fff; } pre { background: #0f172a; padding: 15px; border-radius: 8px; } h3 { color: #3b82f6; } .success { background: #10b981; color: white; padding: 15px; border-radius: 8px; margin: 10px 0; } .alert { background: #ef4444; color: white; padding: 15px; border-radius: 8px; margin: 10px 0; }</style>";

echo "<h3>Sesión ANTES del fix:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Si tiene user_id pero no tiene role, obtenerlo de la BD
if (isset($_SESSION['user_id']) && empty($_SESSION['role'])) {
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT role, username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $user['username'];
        echo "<div class='success'>✅ ROLE ESTABLECIDO: {$user['role']}</div>";
        echo "<div class='success'>✅ USERNAME ESTABLECIDO: {$user['username']}</div>";
    } else {
        echo "<div class='alert'>❌ No se encontró el usuario con ID: $userId</div>";
    }
} elseif (!isset($_SESSION['user_id'])) {
    echo "<div class='alert'>❌ No hay user_id en la sesión. Debes iniciar sesión primero.</div>";
} else {
    echo "<div class='success'>✅ Ya tienes role establecido: {$_SESSION['role']}</div>";
}

echo "<h3>Sesión DESPUÉS del fix:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Test de Permiso hr_dashboard:</h3>";
echo "<pre>";
$hasPermission = userHasPermission('hr_dashboard');
echo $hasPermission ? "✅ TIENE PERMISO para hr_dashboard" : "❌ NO TIENE PERMISO para hr_dashboard";
echo "</pre>";

echo "<hr>";
echo "<h3>Acciones:</h3>";
echo "<a href='../../hr/index.php' style='display: inline-block; background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; margin: 5px;'>Ir a HR Dashboard</a>";
echo "<a href='debug_permissions.php' style='display: inline-block; background: #8b5cf6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; margin: 5px;'>Ver Debug Completo</a>";
?>
