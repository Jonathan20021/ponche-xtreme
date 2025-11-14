<?php
/**
 * Test script to verify HR requests tables are properly configured
 */

require_once 'db.php';

echo "<h1>Test de Tablas de Solicitudes HR</h1>";

// Test permission_requests table
echo "<h2>1. Verificando tabla permission_requests</h2>";
try {
    $stmt = $pdo->query("DESCRIBE permission_requests");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>Columnas encontradas:\n";
    foreach ($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
    echo "</pre>";
    echo "<p style='color: green;'>✓ Tabla permission_requests existe correctamente</p>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

// Test vacation_requests table
echo "<h2>2. Verificando tabla vacation_requests</h2>";
try {
    $stmt = $pdo->query("DESCRIBE vacation_requests");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>Columnas encontradas:\n";
    foreach ($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
    echo "</pre>";
    echo "<p style='color: green;'>✓ Tabla vacation_requests existe correctamente</p>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

// Test employees table
echo "<h2>3. Verificando relación con tabla employees</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM employees");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Total de empleados en la base de datos: <strong>{$result['total']}</strong></p>";
    
    if ($result['total'] > 0) {
        echo "<p style='color: green;'>✓ Tabla employees tiene datos</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Tabla employees existe pero no tiene datos</p>";
    }
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

// Test if current session user has employee record
session_start();
if (isset($_SESSION['user_id'])) {
    echo "<h2>4. Verificando usuario actual</h2>";
    $userId = $_SESSION['user_id'];
    echo "<p>User ID de la sesión: <strong>$userId</strong></p>";
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE user_id = ?");
        $stmt->execute([$userId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($employee) {
            echo "<pre>";
            print_r($employee);
            echo "</pre>";
            echo "<p style='color: green;'>✓ Usuario tiene registro de empleado (ID: {$employee['id']})</p>";
        } else {
            echo "<p style='color: orange;'>⚠ Usuario no tiene registro en tabla employees. Necesitas crear uno primero.</p>";
            echo "<p>Para crear un registro de empleado, usa el módulo de HR o ejecuta:</p>";
            echo "<pre>INSERT INTO employees (user_id, first_name, last_name, email, hire_date) 
VALUES ({$userId}, 'TU_NOMBRE', 'TU_APELLIDO', 'tu@email.com', CURDATE());</pre>";
        }
    } catch (PDOException $e) {
        echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<h2>4. Usuario actual</h2>";
    echo "<p style='color: orange;'>⚠ No hay sesión activa. Por favor inicia sesión primero.</p>";
}

echo "<hr>";
echo "<p><a href='agent_dashboard.php'>← Volver al Dashboard de Agente</a></p>";
?>
