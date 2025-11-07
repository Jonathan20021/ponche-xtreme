<?php
session_start();
include 'db.php';
require_once 'lib/authorization_functions.php';

echo "<h1>Test de Configuración de Autorización</h1>";

// Check if system is enabled
$systemEnabled = isAuthorizationSystemEnabled($pdo);
echo "<h3>Sistema de Autorización: " . ($systemEnabled ? '✅ HABILITADO' : '❌ DESHABILITADO') . "</h3>";

// Check specific contexts
$contexts = [
    'overtime_punch' => 'Hora Extra',
    'edit_record' => 'Editar Registros',
    'delete_record' => 'Eliminar Registros'
];

echo "<h3>Contextos que requieren autorización:</h3>";
echo "<ul>";
foreach ($contexts as $key => $label) {
    $required = isAuthorizationRequiredForContext($pdo, $key);
    $status = $required ? '✅ REQUERIDO' : '❌ NO REQUERIDO';
    echo "<li><strong>$label</strong> ($key): $status</li>";
}
echo "</ul>";

// Show all authorization settings
echo "<h3>Todas las configuraciones de autorización:</h3>";
$stmt = $pdo->query("SELECT setting_key, setting_value, description FROM system_settings WHERE setting_key LIKE 'authorization%' ORDER BY setting_key");
$settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($settings)) {
    echo "<p style='color: red;'><strong>⚠️ NO HAY CONFIGURACIONES EN LA BASE DE DATOS</strong></p>";
    echo "<p>Ejecuta el archivo INSTALL_AUTHORIZATION_CODES.sql</p>";
} else {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>Setting Key</th><th>Valor</th><th>Descripción</th></tr>";
    foreach ($settings as $setting) {
        $value = $setting['setting_value'] == 1 ? '✅ Sí (1)' : '❌ No (0)';
        echo "<tr>";
        echo "<td><code>{$setting['setting_key']}</code></td>";
        echo "<td>{$value}</td>";
        echo "<td>{$setting['description']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Show available authorization codes
echo "<h3>Códigos de Autorización Disponibles:</h3>";
$codesStmt = $pdo->query("
    SELECT 
        code, 
        code_name, 
        role_type, 
        usage_context,
        is_active,
        valid_from,
        valid_until,
        max_uses,
        current_uses
    FROM authorization_codes 
    WHERE is_active = 1
    ORDER BY code_name
");
$codes = $codesStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($codes)) {
    echo "<p style='color: orange;'><strong>⚠️ NO HAY CÓDIGOS ACTIVOS</strong></p>";
    echo "<p>Crea códigos desde Settings > Códigos de Autorización</p>";
} else {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>Código</th><th>Nombre</th><th>Rol</th><th>Contexto</th><th>Válido</th><th>Usos</th></tr>";
    foreach ($codes as $code) {
        $context = $code['usage_context'] ?: '🌐 Todos';
        $validity = "Desde: " . ($code['valid_from'] ?: 'Inicio') . "<br>Hasta: " . ($code['valid_until'] ?: 'Siempre');
        $uses = $code['max_uses'] ? "{$code['current_uses']}/{$code['max_uses']}" : "{$code['current_uses']}/∞";
        echo "<tr>";
        echo "<td><strong>{$code['code']}</strong></td>";
        echo "<td>{$code['code_name']}</td>";
        echo "<td>{$code['role_type']}</td>";
        echo "<td>{$context}</td>";
        echo "<td><small>{$validity}</small></td>";
        echo "<td>{$uses}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";
echo "<p><a href='records.php'>← Volver a Records</a> | <a href='settings.php'>Ir a Settings</a></p>";
?>
