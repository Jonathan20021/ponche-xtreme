<?php
/**
 * Migration Runner: Add Recruitment AI Permission
 * Run this file once to add the necessary permissions
 */

session_start();
require_once __DIR__ . '/../db.php';

// Security: Only allow admin users to run migrations
if (!isset($_SESSION['user_id'])) {
    die('Error: Debes iniciar sesión para ejecutar migraciones');
}

// Check if user is admin
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'Admin') {
    die('Error: Solo los administradores pueden ejecutar migraciones');
}

echo "<h1>Migración: Agregar Permiso de Análisis de Reclutamiento con IA</h1>";
echo "<hr>";

try {
    // Check if permission already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM section_permissions WHERE section_key = 'hr_recruitment_ai'");
    $stmt->execute();
    $exists = $stmt->fetchColumn();
    
    if ($exists > 0) {
        echo "<p style='color: orange;'>⚠️ El permiso 'hr_recruitment_ai' ya existe en la base de datos</p>";
        
        // Show existing permissions
        $stmt = $pdo->prepare("SELECT * FROM section_permissions WHERE section_key = 'hr_recruitment_ai'");
        $stmt->execute();
        $perms = $stmt->fetchAll();
        
        echo "<h3>Permisos Existentes:</h3>";
        echo "<ul>";
        foreach ($perms as $perm) {
            echo "<li>Rol: {$perm['role']}</li>";
        }
        echo "</ul>";
    } else {
        // Insert permissions
        echo "<p>Insertando permisos...</p>";
        
        $roles = ['Admin', 'HR', 'IT'];
        $inserted = 0;
        
        foreach ($roles as $role) {
            $stmt = $pdo->prepare("INSERT INTO section_permissions (section_key, role) VALUES (?, ?)");
            if ($stmt->execute(['hr_recruitment_ai', $role])) {
                echo "<p style='color: green;'>✓ Permiso agregado para rol: $role</p>";
                $inserted++;
            }
        }
        
        echo "<hr>";
        echo "<h3 style='color: green;'>✓ Migración completada exitosamente</h3>";
        echo "<p>Se agregaron $inserted permisos</p>";
    }
    
    // Verify final state
    echo "<hr>";
    echo "<h3>Estado Final:</h3>";
    $stmt = $pdo->prepare("SELECT * FROM section_permissions WHERE section_key = 'hr_recruitment_ai' ORDER BY role_name");
    $stmt->execute();
    $finalPerms = $stmt->fetchAll();
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>Section Key</th><th>Role</th></tr>";
    foreach ($finalPerms as $perm) {
        echo "<tr><td>{$perm['section_key']}</td><td>{$perm['role']}</td></tr>";
    }
    echo "</table>";
    
    echo "<hr>";
    echo "<p><a href='../hr/recruitment_ai_analysis.php'>→ Ir al Análisis de Reclutamiento con IA</a></p>";
    echo "<p><a href='../hr/index.php'>← Volver al Dashboard de HR</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
