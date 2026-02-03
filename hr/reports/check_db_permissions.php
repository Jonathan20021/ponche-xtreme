<?php
require_once '../../db.php';

echo "<h2>Verificación de Permisos en Base de Datos</h2>";
echo "<style>body { font-family: monospace; padding: 20px; background: #1e293b; color: #fff; } pre { background: #0f172a; padding: 15px; border-radius: 8px; } h3 { color: #3b82f6; }</style>";

// Buscar permisos que existen
echo "<h3>1. Permisos que contienen 'hr' en la base de datos:</h3>";
$perms = $pdo->query("SELECT * FROM permissions WHERE name LIKE '%hr%' OR name LIKE '%HR%'")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($perms);
echo "</pre>";

// Ver todos los permisos
echo "<h3>2. TODOS los permisos disponibles:</h3>";
$allPerms = $pdo->query("SELECT id, name, description FROM permissions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
foreach ($allPerms as $p) {
    echo "ID: {$p['id']} - Nombre: {$p['name']} - Descripción: {$p['description']}\n";
}
echo "</pre>";

// Ver si existe hr_dashboard
echo "<h3>3. ¿Existe el permiso 'hr_dashboard'?</h3>";
$hrDash = $pdo->query("SELECT * FROM permissions WHERE name = 'hr_dashboard'")->fetch(PDO::FETCH_ASSOC);
echo "<pre>";
if ($hrDash) {
    echo "SÍ EXISTE:\n";
    print_r($hrDash);
} else {
    echo "NO EXISTE el permiso 'hr_dashboard' en la base de datos\n";
}
echo "</pre>";

// Ver roles con permisos HR
echo "<h3>4. Roles con permisos de HR:</h3>";
$roles = $pdo->query("
    SELECT r.id, r.name as role_name, GROUP_CONCAT(p.name SEPARATOR ', ') as permissions
    FROM roles r
    LEFT JOIN role_permissions rp ON r.id = rp.role_id
    LEFT JOIN permissions p ON rp.permission_id = p.id
    WHERE r.name LIKE '%HR%' OR r.name LIKE '%Recursos%'
    GROUP BY r.id, r.name
")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($roles);
echo "</pre>";

// Ver TODOS los roles
echo "<h3>5. TODOS los roles:</h3>";
$allRoles = $pdo->query("
    SELECT r.id, r.name as role_name, GROUP_CONCAT(p.name SEPARATOR ', ') as permissions
    FROM roles r
    LEFT JOIN role_permissions rp ON r.id = rp.role_id
    LEFT JOIN permissions p ON rp.permission_id = p.id
    GROUP BY r.id, r.name
    ORDER BY r.name
")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
foreach ($allRoles as $role) {
    echo "\n========================================\n";
    echo "ROL: {$role['role_name']}\n";
    echo "PERMISOS: {$role['permissions']}\n";
}
echo "</pre>";
?>
