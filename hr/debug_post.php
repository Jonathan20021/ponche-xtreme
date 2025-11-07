<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug POST Handler</h1>";
echo "<h2>Request Method: " . $_SERVER['REQUEST_METHOD'] . "</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3 style='color: green;'>✓ Es un POST</h3>";
    echo "<pre style='background: #000; color: #0f0; padding: 20px;'>";
    print_r($_POST);
    echo "</pre>";
    
    echo "<h3>FILES:</h3>";
    echo "<pre style='background: #000; color: #0ff; padding: 20px;'>";
    print_r($_FILES);
    echo "</pre>";
} else {
    echo "<h3 style='color: red;'>✗ NO es un POST, es: " . $_SERVER['REQUEST_METHOD'] . "</h3>";
}

session_start();
echo "<h3>Sesión:</h3>";
echo "<pre style='background: #000; color: #ff0; padding: 20px;'>";
print_r($_SESSION);
echo "</pre>";

require_once '../db.php';

// Check permission
echo "<h3>Verificando Permiso 'hr_employees':</h3>";
if (isset($_SESSION['user_id'])) {
    echo "<p>User ID en sesión: " . $_SESSION['user_id'] . "</p>";
    $hasPermission = userHasPermission('hr_employees');
    if ($hasPermission) {
        echo "<p style='color: green;'>✓ Usuario TIENE permiso 'hr_employees'</p>";
    } else {
        echo "<p style='color: red;'>✗ Usuario NO TIENE permiso 'hr_employees'</p>";
        echo "<p>Permisos del usuario:</p>";
        $stmt = $pdo->prepare("
            SELECT sp.section_key 
            FROM section_permissions sp
            JOIN users u ON u.role = sp.role
            WHERE u.id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "<pre style='background: #000; color: #0f0; padding: 20px;'>";
        print_r($perms);
        echo "</pre>";
    }
} else {
    echo "<p style='color: red;'>✗ NO hay sesión activa</p>";
}
?>
<hr>
<h2>Formulario de Prueba</h2>
<form action="debug_post.php" method="POST">
    <input type="text" name="employee_name" value="Test Name" placeholder="Nombre"><br><br>
    <input type="text" name="test_field" value="Test Value"><br><br>
    <button type="submit">Enviar POST</button>
</form>
