<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Include database connection
if (!file_exists(__DIR__ . '/db.php')) {
    die("Error: db.php no encontrado");
}

require_once __DIR__ . '/db.php';

if (!isset($conn)) {
    die("Error: Variable \$conn no está definida en db.php");
}

// Simular sesión de admin
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'Admin';

echo "<h2>Test API Helpdesk</h2>";

// Test 1: Get Categories
echo "<h3>1. Test get_categories</h3>";
$query = "SELECT * FROM helpdesk_categories ORDER BY name";
$result = $conn->query($query);

if ($result) {
    echo "✅ Query ejecutado correctamente<br>";
    echo "Categorías encontradas: " . $result->num_rows . "<br><br>";
    
    if ($result->num_rows > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Nombre</th><th>Descripción</th><th>Departamento</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . $row['name'] . "</td>";
            echo "<td>" . $row['description'] . "</td>";
            echo "<td>" . $row['department'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} else {
    echo "❌ Error: " . $conn->error;
}

// Test 2: API endpoint
echo "<h3>2. Test API endpoint</h3>";
echo "<a href='hr/helpdesk_api.php?action=get_categories' target='_blank'>Abrir API: get_categories</a><br><br>";

// Test 3: Check users table
echo "<h3>3. Test users table</h3>";
$query = "SELECT id, username, full_name FROM users LIMIT 5";
$result = $conn->query($query);

if ($result) {
    echo "✅ Usuarios encontrados: " . $result->num_rows . "<br>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Username</th><th>Nombre</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['username'] . "</td>";
        echo "<td>" . $row['full_name'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Test 4: Check if helpdesk_tickets table exists
echo "<h3>4. Test helpdesk_tickets table</h3>";
$query = "SHOW TABLES LIKE 'helpdesk_%'";
$result = $conn->query($query);

if ($result) {
    echo "✅ Tablas helpdesk encontradas: " . $result->num_rows . "<br>";
    while ($row = $result->fetch_array()) {
        echo "- " . $row[0] . "<br>";
    }
}
?>
