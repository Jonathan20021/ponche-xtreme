<?php
// Test directo sin sesión
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test Directo API get_tickets</h2>";

// Conectar a la base de datos
$host = '192.185.46.27';
$dbname = 'hhempeos_ponche';
$username = 'hhempeos_ponche';
$password = 'Hugo##2025#';

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

echo "<h3>1. Conexión a BD: ✅</h3>";

// Probar la consulta directamente
try {
    $query = "SELECT t.*, c.name as category_name, c.color as category_color,
              u.full_name as user_name, u.email as user_email,
              a.full_name as assigned_to_name
              FROM helpdesk_tickets t
              LEFT JOIN helpdesk_categories c ON t.category_id = c.id
              LEFT JOIN users u ON t.user_id = u.id
              LEFT JOIN users a ON t.assigned_to = a.id
              WHERE 1=1
              ORDER BY t.created_at DESC";
    
    echo "<h3>2. Query:</h3>";
    echo "<pre>" . htmlspecialchars($query) . "</pre>";
    
    $result = $conn->query($query);
    
    if ($result === false) {
        echo "<h3>❌ Error en query:</h3>";
        echo "<pre>" . $conn->error . "</pre>";
    } else {
        echo "<h3>✅ Query ejecutado correctamente</h3>";
        echo "Tickets encontrados: " . $result->num_rows . "<br><br>";
        
        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            $tickets[] = $row;
        }
        
        echo "<h3>JSON Result:</h3>";
        $json = json_encode(['success' => true, 'tickets' => $tickets]);
        echo "<pre>" . htmlspecialchars($json) . "</pre>";
    }
} catch (Exception $e) {
    echo "<h3>❌ Exception:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}

$conn->close();
?>
