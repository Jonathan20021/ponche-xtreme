<?php
/**
 * Script de prueba para verificar la recuperación de contraseña
 */
require_once 'db.php';

echo "<h1>Test de Recuperación de Contraseña</h1>";
echo "<hr>";

// Datos de prueba - CAMBIAR ESTOS VALORES
$testUsername = 'jsandoval'; // Cambiar por un usuario real
$testIdCard = '402-3417388-4'; // Cambiar por una cédula real

echo "<h2>Datos de Prueba:</h2>";
echo "Usuario: <strong>$testUsername</strong><br>";
echo "Cédula: <strong>$testIdCard</strong><br>";
echo "<hr>";

// Test 1: Verificar si el usuario existe
echo "<h2>1. Verificando si el usuario existe en la tabla 'users':</h2>";
$userStmt = $pdo->prepare("SELECT id, username, full_name, role FROM users WHERE username = ?");
$userStmt->execute([$testUsername]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);

if ($userData) {
    echo "✅ Usuario encontrado:<br>";
    echo "- ID: {$userData['id']}<br>";
    echo "- Username: {$userData['username']}<br>";
    echo "- Nombre: {$userData['full_name']}<br>";
    echo "- Rol: {$userData['role']}<br>";
    $userId = $userData['id'];
} else {
    echo "❌ Usuario NO encontrado en la tabla 'users'<br>";
    echo "<p style='color: red;'>El usuario '$testUsername' no existe. Verifica el nombre de usuario.</p>";
    exit;
}

echo "<hr>";

// Test 2: Verificar si existe registro en employees
echo "<h2>2. Verificando registro en la tabla 'employees':</h2>";
$empStmt = $pdo->prepare("SELECT id, user_id, employee_code, first_name, last_name, email, identification_number FROM employees WHERE user_id = ?");
$empStmt->execute([$userId]);
$empData = $empStmt->fetch(PDO::FETCH_ASSOC);

if ($empData) {
    echo "✅ Registro de empleado encontrado:<br>";
    echo "- Employee ID: {$empData['id']}<br>";
    echo "- User ID: {$empData['user_id']}<br>";
    echo "- Código: {$empData['employee_code']}<br>";
    echo "- Nombre: {$empData['first_name']} {$empData['last_name']}<br>";
    echo "- Email: " . ($empData['email'] ?: 'No registrado') . "<br>";
    echo "- Cédula registrada: <strong>" . ($empData['identification_number'] ?: 'No registrada') . "</strong><br>";
    
    if ($empData['identification_number']) {
        echo "<br>";
        if ($empData['identification_number'] === $testIdCard) {
            echo "✅ <span style='color: green;'>La cédula COINCIDE con la registrada</span><br>";
        } else {
            echo "❌ <span style='color: red;'>La cédula NO COINCIDE</span><br>";
            echo "Cédula ingresada: <strong>$testIdCard</strong><br>";
            echo "Cédula registrada: <strong>{$empData['identification_number']}</strong><br>";
        }
    } else {
        echo "<br>⚠️ <span style='color: orange;'>Este usuario NO tiene cédula registrada</span><br>";
        echo "Necesitas agregar la cédula en el módulo de Recursos Humanos.<br>";
    }
} else {
    echo "❌ NO existe registro de empleado para este usuario<br>";
    echo "<p style='color: orange;'>El usuario existe pero no tiene registro en la tabla 'employees'.</p>";
    echo "<p>Esto puede pasar si el usuario fue creado antes de implementar el sistema de empleados.</p>";
}

echo "<hr>";

// Test 3: Probar la consulta actual
echo "<h2>3. Probando la consulta SQL actual:</h2>";
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.full_name, u.role
    FROM users u
    LEFT JOIN employees e ON e.user_id = u.id
    WHERE u.username = ? 
    AND (e.identification_number = ? OR e.identification_number IS NULL)
");
$stmt->execute([$testUsername, $testIdCard]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result) {
    echo "✅ Consulta SQL devuelve resultado<br>";
    echo "<pre>";
    print_r($result);
    echo "</pre>";
} else {
    echo "❌ Consulta SQL NO devuelve resultado<br>";
    echo "<p style='color: red;'>La consulta no encuentra coincidencias.</p>";
}

echo "<hr>";

// Test 4: Listar todos los usuarios con sus cédulas
echo "<h2>4. Lista de usuarios con cédulas registradas:</h2>";
$allUsers = $pdo->query("
    SELECT u.username, u.full_name, u.role, e.identification_number 
    FROM users u 
    LEFT JOIN employees e ON e.user_id = u.id 
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Cédula</th></tr>";
foreach ($allUsers as $u) {
    $cedula = $u['identification_number'] ?: '<em style="color: gray;">No registrada</em>';
    echo "<tr>";
    echo "<td>{$u['username']}</td>";
    echo "<td>{$u['full_name']}</td>";
    echo "<td>{$u['role']}</td>";
    echo "<td>$cedula</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";
echo "<h2>Conclusión:</h2>";
echo "<p>Para que la recuperación de contraseña funcione, el usuario debe:</p>";
echo "<ol>";
echo "<li>Existir en la tabla <strong>users</strong></li>";
echo "<li>Tener un registro en la tabla <strong>employees</strong></li>";
echo "<li>Tener su <strong>cédula (identification_number)</strong> registrada en employees</li>";
echo "<li>La cédula ingresada debe coincidir exactamente con la registrada</li>";
echo "</ol>";
?>
