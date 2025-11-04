<?php
$host = '192.185.46.27';
$dbname = 'hhempeos_ponche';
$username = 'hhempeos_ponche';
$password = 'Hugo##2025#';

$conn = new mysqli($host, $username, $password, $dbname);

echo "<h2>Estructura de la tabla users</h2>";

$result = $conn->query("DESCRIBE users");

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['Field'] . "</td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "</tr>";
}
echo "</table>";
?>
