<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>Debug Generate</title></head><body>";
echo "<h1>Generate Contract Debug</h1>";

echo "<h2>1. Checking REQUEST_METHOD</h2>";
echo "<p>Method: <strong>" . $_SERVER['REQUEST_METHOD'] . "</strong></p>";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<p style='color: red;'>ERROR: No es POST, redirigiendo...</p>";
    echo "<p>Por esto te redirige a contracts.php</p>";
    echo "</body></html>";
    exit;
}

echo "<p style='color: green;'>✓ Es POST</p>";

echo "<h2>2. Starting Session</h2>";
session_start();
echo "<p style='color: green;'>✓ Session started</p>";
echo "<p>User ID: " . ($_SESSION['user_id'] ?? 'NO SESSION') . "</p>";

echo "<h2>3. Loading DB</h2>";
require_once '../db.php';
echo "<p style='color: green;'>✓ DB loaded</p>";

echo "<h2>4. Checking Permission</h2>";
if (!isset($_SESSION['user_id'])) {
    echo "<p style='color: red;'>✗ No user_id in session</p>";
    echo "</body></html>";
    exit;
}

$hasPermission = userHasPermission('hr_employees');
if (!$hasPermission) {
    echo "<p style='color: red;'>✗ No tiene permiso hr_employees</p>";
    echo "</body></html>";
    exit;
}
echo "<p style='color: green;'>✓ Tiene permiso hr_employees</p>";

echo "<h2>5. POST Data Received</h2>";
echo "<pre style='background: #f0f0f0; padding: 10px;'>";
print_r($_POST);
echo "</pre>";

echo "<h2>6. Validating Required Fields</h2>";
$requiredFields = ['employee_name', 'id_card', 'province', 'position', 'salary', 'work_schedule', 'contract_date', 'city'];
$missingFields = [];
foreach ($requiredFields as $field) {
    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
        $missingFields[] = $field;
        echo "<p style='color: red;'>✗ Falta: $field</p>";
    } else {
        echo "<p style='color: green;'>✓ $field = " . htmlspecialchars($_POST[$field]) . "</p>";
    }
}

if (!empty($missingFields)) {
    echo "<h3 style='color: red;'>ERROR: Faltan campos requeridos</h3>";
    echo "<p>Por esto no se genera el contrato y te redirige</p>";
    echo "</body></html>";
    exit;
}

echo "<h2>7. Loading Dompdf</h2>";
require_once '../vendor/autoload.php';
echo "<p style='color: green;'>✓ Autoload cargado</p>";

use Dompdf\Dompdf;
use Dompdf\Options;

echo "<p style='color: green;'>✓ Clases importadas</p>";

echo "<h2>8. Processing Data</h2>";
$employeeName = trim($_POST['employee_name']);
$idCard = trim($_POST['id_card']);
$province = trim($_POST['province']);
$position = trim($_POST['position']);
$salary = (float)$_POST['salary'];
$workSchedule = trim($_POST['work_schedule']);
$contractDate = $_POST['contract_date'];
$city = trim($_POST['city']);
$action = $_POST['action'] ?? 'employment';

echo "<p style='color: green;'>✓ Datos procesados</p>";

echo "<h2>9. Saving to Database</h2>";
try {
    $contractType = 'TRABAJO';
    $insertStmt = $pdo->prepare("
        INSERT INTO employment_contracts 
        (employee_id, employee_name, id_card, province, contract_date, salary, work_schedule, city, contract_type, created_by, created_at)
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $insertStmt->execute([
        $employeeName,
        $idCard,
        $province,
        $contractDate,
        $salary,
        $workSchedule,
        $city,
        $contractType,
        $_SESSION['user_id']
    ]);
    
    $contractId = $pdo->lastInsertId();
    echo "<p style='color: green;'>✓ Guardado en BD con ID: $contractId</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error BD: " . $e->getMessage() . "</p>";
    echo "</body></html>";
    exit;
}

echo "<h2>10. Generating PDF</h2>";
echo "<p>Si todo va bien, ahora debería generar el PDF...</p>";

echo "<p style='color: blue; font-weight: bold;'>TODO OK! El problema NO está aquí. Presiona F12 y revisa la consola del navegador cuando envías el formulario desde contracts.php</p>";

echo "</body></html>";
?>
