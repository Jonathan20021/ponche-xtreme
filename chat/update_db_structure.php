<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar que tenga permisos de administrador
if (!isset($_SESSION['user_id'])) {
    die("Debes iniciar sesión");
}

echo "<h1>Actualizar Estructura de Base de Datos - Chat Groups</h1>";

try {
    // Agregar columna is_group
    $stmt = $pdo->query("SHOW COLUMNS FROM chat_conversations LIKE 'is_group'");
    if ($stmt->rowCount() === 0) {
        echo "Agregando columna <strong>is_group</strong>...<br>";
        $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN is_group TINYINT(1) DEFAULT 0 AFTER id");
        echo "✅ Columna <strong>is_group</strong> agregada exitosamente<br><br>";
    } else {
        echo "✅ Columna <strong>is_group</strong> ya existe<br><br>";
    }
    
    // Agregar columna group_name
    $stmt = $pdo->query("SHOW COLUMNS FROM chat_conversations LIKE 'group_name'");
    if ($stmt->rowCount() === 0) {
        echo "Agregando columna <strong>group_name</strong>...<br>";
        $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN group_name VARCHAR(255) DEFAULT NULL AFTER is_group");
        echo "✅ Columna <strong>group_name</strong> agregada exitosamente<br><br>";
    } else {
        echo "✅ Columna <strong>group_name</strong> ya existe<br><br>";
    }
    
    // Mostrar estructura actualizada
    echo "<h2>Estructura actualizada de chat_conversations:</h2>";
    $stmt = $pdo->query("DESCRIBE chat_conversations");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($col['Field']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($col['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<br><br>";
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px;'>";
    echo "<strong style='color: #155724;'>✅ Base de datos actualizada correctamente</strong><br>";
    echo "Ahora puedes regresar a <a href='admin.php'>Administración de Chat</a> y el monitoreo debería funcionar.";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px;'>";
    echo "<strong style='color: #721c24;'>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
