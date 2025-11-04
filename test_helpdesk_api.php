<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test Helpdesk API</h2>";

// Test 1: Get Categories
echo "<h3>1. Test get_categories endpoint</h3>";
$url = 'http://localhost/ponche-xtreme/hr/helpdesk_api.php?action=get_categories';
$response = @file_get_contents($url);

if ($response === false) {
    echo "❌ Error: No se pudo conectar al endpoint<br>";
} else {
    echo "<strong>Respuesta cruda:</strong><br>";
    echo "<pre>" . htmlspecialchars($response) . "</pre><br>";
    
    $data = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "✅ JSON válido<br>";
        echo "<strong>Datos decodificados:</strong><br>";
        echo "<pre>" . print_r($data, true) . "</pre>";
    } else {
        echo "❌ Error al decodificar JSON: " . json_last_error_msg() . "<br>";
    }
}

// Test 2: Check if helpdesk_functions.php exists
echo "<h3>2. Verificar archivos requeridos</h3>";
$files = [
    'db.php' => __DIR__ . '/db.php',
    'helpdesk_functions.php' => __DIR__ . '/lib/helpdesk_functions.php',
    'helpdesk_api.php' => __DIR__ . '/hr/helpdesk_api.php'
];

foreach ($files as $name => $path) {
    if (file_exists($path)) {
        echo "✅ $name existe<br>";
    } else {
        echo "❌ $name NO existe en: $path<br>";
    }
}

// Test 3: Direct API call
echo "<h3>3. Llamada directa a la API</h3>";
try {
    $_GET['action'] = 'get_categories';
    ob_start();
    include __DIR__ . '/hr/helpdesk_api.php';
    $output = ob_get_clean();
    
    echo "<strong>Output:</strong><br>";
    echo "<pre>" . htmlspecialchars($output) . "</pre>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
