<?php
require_once 'db.php';

echo "<h1>Estructura de Tablas</h1>";

try {
    // Verificar estructura de campaigns
    echo "<h2>Tabla: campaigns</h2>";
    $stmt = $pdo->query("DESCRIBE campaigns");
    echo "<table border='1'><tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $stmt->fetch()) {
        echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
    }
    echo "</table>";
    
    // Verificar estructura de payroll_periods
    echo "<h2>Tabla: payroll_periods</h2>";
    $stmt = $pdo->query("DESCRIBE payroll_periods");
    echo "<table border='1'><tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $stmt->fetch()) {
        echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
    }
    echo "</table>";
    
    // Verificar si existe payroll_entries
    echo "<h2>Verificar payroll_entries</h2>";
    try {
        $stmt = $pdo->query("DESCRIBE payroll_entries");
        echo "<table border='1'><tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $stmt->fetch()) {
            echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>La tabla payroll_entries no existe: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
