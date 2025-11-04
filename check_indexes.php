<?php
require_once 'db.php';

echo "<h2>Índices de la tabla job_applications</h2>";

try {
    $stmt = $pdo->query("SHOW INDEX FROM job_applications");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Key_name</th><th>Column_name</th><th>Non_unique</th><th>Seq_in_index</th></tr>";
    
    foreach ($indexes as $index) {
        echo "<tr>";
        echo "<td>" . $index['Key_name'] . "</td>";
        echo "<td>" . $index['Column_name'] . "</td>";
        echo "<td>" . ($index['Non_unique'] ? 'No' : 'Sí') . "</td>";
        echo "<td>" . $index['Seq_in_index'] . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    echo "<hr>";
    echo "<h3>Comandos SQL para eliminar restricción UNIQUE:</h3>";
    
    foreach ($indexes as $index) {
        if ($index['Column_name'] === 'application_code' && $index['Non_unique'] == 0) {
            echo "<pre>ALTER TABLE job_applications DROP INDEX " . $index['Key_name'] . ";</pre>";
        }
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
