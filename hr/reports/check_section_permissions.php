<?php
require_once '../../db.php';

echo "<h2>Verificación de Section Permissions</h2>";
echo "<style>body { font-family: monospace; padding: 20px; background: #1e293b; color: #fff; } pre { background: #0f172a; padding: 15px; border-radius: 8px; } h3 { color: #3b82f6; } .alert { background: #ef4444; color: white; padding: 15px; border-radius: 8px; margin: 10px 0; }</style>";

// Ver tu sesión actual
session_start();
echo "<h3>Tu Sesión Actual:</h3>";
echo "<pre>";
echo "User ID: " . ($_SESSION['user_id'] ?? 'NO SET') . "\n";
echo "Role: " . ($_SESSION['role'] ?? 'NO SET') . "\n";
echo "Username: " . ($_SESSION['username'] ?? 'NO SET') . "\n";
echo "</pre>";

// Ver section_permissions para hr_dashboard
echo "<h3>¿Existe 'hr_dashboard' en section_permissions?</h3>";
$hrPerms = $pdo->query("SELECT * FROM section_permissions WHERE section_key = 'hr_dashboard'")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
if (empty($hrPerms)) {
    echo "<div class='alert'>⚠️ NO EXISTE 'hr_dashboard' en section_permissions - Por eso no tienes acceso!</div>";
} else {
    echo "SÍ EXISTE:\n";
    print_r($hrPerms);
}
echo "</pre>";

// Ver TODAS las section_permissions
echo "<h3>TODAS las Section Permissions:</h3>";
$allSections = $pdo->query("SELECT section_key, role, description FROM section_permissions ORDER BY section_key")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
foreach ($allSections as $sec) {
    echo "Section: {$sec['section_key']} | Role: {$sec['role']} | Desc: {$sec['description']}\n";
}
echo "</pre>";

// Verificar qué permisos HR existen
echo "<h3>Section Permissions que contienen 'hr':</h3>";
$hrSections = $pdo->query("SELECT * FROM section_permissions WHERE section_key LIKE '%hr%' OR section_key LIKE '%HR%'")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
if (empty($hrSections)) {
    echo "NO HAY NINGUNO\n";
} else {
    print_r($hrSections);
}
echo "</pre>";

// Test de la función
echo "<h3>Test userHasPermission('hr_dashboard'):</h3>";
echo "<pre>";
$hasPermission = userHasPermission('hr_dashboard');
echo $hasPermission ? "✅ TIENE PERMISO" : "❌ NO TIENE PERMISO";
echo "</pre>";
?>
