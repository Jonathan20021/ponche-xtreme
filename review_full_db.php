<?php
require_once 'db.php';

echo "<h1>Revisión Completa de la Base de Datos</h1>";

try {
    // Obtener todas las tablas
    echo "<h2>1. Todas las tablas en la base de datos</h2>";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p>Total de tablas: " . count($tables) . "</p>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
    
    // Revisar tablas relacionadas con nómina
    echo "<h2>2. Tablas relacionadas con nómina</h2>";
    $payrollTables = array_filter($tables, function($table) {
        return strpos($table, 'payroll') !== false;
    });
    
    foreach ($payrollTables as $table) {
        echo "<h3>Tabla: $table</h3>";
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
        $count = $stmt->fetch()['count'];
        echo "<p>Registros: $count</p>";
        
        if ($count > 0) {
            $stmt = $pdo->query("SELECT * FROM $table LIMIT 3");
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($records)) {
                echo "<table border='1' style='border-collapse: collapse;'>";
                echo "<tr>";
                foreach (array_keys($records[0]) as $column) {
                    echo "<th>$column</th>";
                }
                echo "</tr>";
                foreach ($records as $record) {
                    echo "<tr>";
                    foreach ($record as $value) {
                        echo "<td>" . htmlspecialchars($value) . "</td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
            }
        }
    }
    
    // Revisar estructura de employees completa
    echo "<h2>3. Estructura completa de employees</h2>";
    $stmt = $pdo->query("DESCRIBE employees");
    echo "<table border='1'><tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $stmt->fetch()) {
        echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
    }
    echo "</table>";
    
    // Revisar estructura de users completa
    echo "<h2>4. Estructura completa de users</h2>";
    $stmt = $pdo->query("DESCRIBE users");
    echo "<table border='1'><tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $stmt->fetch()) {
        echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
    }
    echo "</table>";
    
    // Revisar datos de campaigns
    echo "<h2>5. Datos de campaigns</h2>";
    $stmt = $pdo->query("SELECT * FROM campaigns");
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($campaigns)) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr>";
        foreach (array_keys($campaigns[0]) as $column) {
            echo "<th>$column</th>";
        }
        echo "</tr>";
        foreach ($campaigns as $campaign) {
            echo "<tr>";
            foreach ($campaign as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Verificar funciones disponibles
    echo "<h2>6. Funciones disponibles en db.php</h2>";
    $functions = get_defined_functions()['user'];
    $dbFunctions = array_filter($functions, function($func) {
        return strpos($func, 'get') === 0 || strpos($func, 'calculate') === 0;
    });
    
    echo "<ul>";
    foreach ($dbFunctions as $func) {
        echo "<li>$func</li>";
    }
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
