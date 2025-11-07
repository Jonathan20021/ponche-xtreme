<?php
session_start();
include 'db.php';

echo "<h1>Actualizar Códigos a Universales</h1>";
echo "<hr>";

try {
    // Actualizar todos los códigos para que sean universales (usage_context = NULL)
    $updateStmt = $pdo->prepare("
        UPDATE authorization_codes 
        SET usage_context = NULL 
        WHERE usage_context = 'overtime'
    ");
    $updateStmt->execute();
    $updated = $updateStmt->rowCount();
    
    echo "<p style='color: green; font-size: 20px;'><strong>✅ {$updated} códigos actualizados a UNIVERSALES</strong></p>";
    echo "<p>Ahora todos los códigos funcionarán para:</p>";
    echo "<ul>";
    echo "<li>✅ Hora Extra (overtime)</li>";
    echo "<li>✅ Editar Registros (edit_records)</li>";
    echo "<li>✅ Eliminar Registros (delete_records)</li>";
    echo "<li>✅ Cualquier otro contexto futuro</li>";
    echo "</ul>";
    
    // Mostrar códigos actualizados
    echo "<h3>Códigos Universales Disponibles:</h3>";
    $stmt = $pdo->query("
        SELECT DISTINCT code, code_name, role_type
        FROM authorization_codes 
        WHERE is_active = 1
        ORDER BY code
    ");
    $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>Código</th><th>Nombre</th><th>Rol</th><th>Contexto</th></tr>";
    foreach ($codes as $code) {
        echo "<tr>";
        echo "<td><strong>{$code['code']}</strong></td>";
        echo "<td>{$code['code_name']}</td>";
        echo "<td>{$code['role_type']}</td>";
        echo "<td><span style='color: green;'>🌐 TODOS (Universal)</span></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<hr>";
    echo "<h2 style='color: green;'>✅ ACTUALIZACIÓN COMPLETADA</h2>";
    echo "<p>Ahora puedes usar cualquiera de estos códigos para editar o eliminar registros:</p>";
    echo "<ul>";
    echo "<li><strong>SUP2025</strong> - Supervisor</li>";
    echo "<li><strong>IT2025</strong> - IT Administrator</li>";
    echo "<li><strong>MGR2025</strong> - Manager</li>";
    echo "<li><strong>DIR2025</strong> - Director</li>";
    echo "<li><strong>HR2025</strong> - HR</li>";
    echo "<li><strong>UNIVERSAL2025</strong> - Universal</li>";
    echo "</ul>";
    
    echo "<p><a href='debug_auth_code.php'>← Probar Validación de Códigos</a> | <a href='records.php'>Ir a Records</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>❌ ERROR:</strong> " . $e->getMessage() . "</p>";
}
?>
