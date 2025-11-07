<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../db.php';

echo "<h1>Test de Generación de Contratos</h1>";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo "<p style='color: red;'>ERROR: No hay sesión activa</p>";
    exit;
}
echo "<p style='color: green;'>✓ Sesión activa: Usuario ID " . $_SESSION['user_id'] . "</p>";

// Check if vendor/autoload exists
if (file_exists('../vendor/autoload.php')) {
    echo "<p style='color: green;'>✓ vendor/autoload.php existe</p>";
    require_once '../vendor/autoload.php';
} else {
    echo "<p style='color: red;'>ERROR: vendor/autoload.php no encontrado</p>";
    exit;
}

// Check if Dompdf is available
if (class_exists('Dompdf\Dompdf')) {
    echo "<p style='color: green;'>✓ Dompdf está disponible</p>";
} else {
    echo "<p style='color: red;'>ERROR: Dompdf no está disponible</p>";
    exit;
}

// Check database connection
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM employment_contracts");
    $count = $stmt->fetchColumn();
    echo "<p style='color: green;'>✓ Conexión a BD exitosa. Contratos en BD: $count</p>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>ERROR BD: " . $e->getMessage() . "</p>";
}

// Check logo
$logoPath = dirname(__DIR__) . '/assets/logo.png';
if (file_exists($logoPath)) {
    echo "<p style='color: green;'>✓ Logo encontrado: $logoPath</p>";
} else {
    echo "<p style='color: orange;'>⚠ Logo no encontrado (opcional): $logoPath</p>";
}

echo "<hr>";
echo "<h2>Test Simple de PDF</h2>";

use Dompdf\Dompdf;
use Dompdf\Options;

try {
    
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    
    $dompdf = new Dompdf($options);
    $html = '<html><body><h1>Test PDF</h1><p>Este es un PDF de prueba.</p></body></html>';
    $dompdf->loadHtml($html);
    $dompdf->setPaper('Letter', 'portrait');
    $dompdf->render();
    
    echo "<p style='color: green;'>✓ Motor de PDF generado exitosamente</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>ERROR al generar PDF: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<hr>";
echo "<h2>Formulario de Prueba</h2>";
echo '<form action="generate_contract.php" method="POST" target="_blank" onsubmit="console.log(\'Enviando formulario...\'); return true;">';
echo '<table border="1" cellpadding="5" style="background: #f0f0f0; color: #000;">';
echo '<tr><td>Nombre:</td><td><input type="text" name="employee_name" value="Juan Pérez Test" required style="width: 300px;"></td></tr>';
echo '<tr><td>Cédula:</td><td><input type="text" name="id_card" value="001-1234567-8" required style="width: 300px;"></td></tr>';
echo '<tr><td>Provincia:</td><td><input type="text" name="province" value="Santiago" required style="width: 300px;"></td></tr>';
echo '<tr><td>Posición:</td><td><input type="text" name="position" value="Agente de Prueba" required style="width: 300px;"></td></tr>';
echo '<tr><td>Salario:</td><td><input type="number" name="salary" value="30000" required style="width: 300px;"></td></tr>';
echo '<tr><td>Horario:</td><td><input type="text" name="work_schedule" value="44 horas semanales" required style="width: 300px;"></td></tr>';
echo '<tr><td>Fecha:</td><td><input type="date" name="contract_date" value="' . date('Y-m-d') . '" required style="width: 300px;"></td></tr>';
echo '<tr><td>Ciudad:</td><td><input type="text" name="city" value="Santiago" required style="width: 300px;"></td></tr>';
echo '</table><br>';
echo '<button type="submit" name="action" value="employment" style="padding: 10px 20px; font-size: 16px; background: #4CAF50; color: white; border: none; cursor: pointer;">Generar Contrato de Prueba</button>';
echo '</form>';

echo "<hr>";
echo "<h2>Datos de Debug</h2>";
echo "<p>POST data si existe:</p>";
if (!empty($_POST)) {
    echo "<pre style='background: #000; color: #0f0; padding: 10px;'>";
    print_r($_POST);
    echo "</pre>";
} else {
    echo "<p>No hay datos POST (normal en la primera carga)</p>";
}
?>
