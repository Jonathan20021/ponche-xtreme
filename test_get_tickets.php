<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Simular sesión de admin
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'Admin';

echo "<h2>Test get_tickets endpoint</h2>";

// Llamar directamente al endpoint
$_GET['action'] = 'get_tickets';

ob_start();
try {
    include __DIR__ . '/hr/helpdesk_api.php';
    $output = ob_get_clean();
    
    echo "<h3>Respuesta del servidor:</h3>";
    echo "<pre>" . htmlspecialchars($output) . "</pre>";
    
    $data = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "<h3>✅ JSON válido</h3>";
        echo "<pre>" . print_r($data, true) . "</pre>";
    } else {
        echo "<h3>❌ Error JSON: " . json_last_error_msg() . "</h3>";
    }
} catch (Exception $e) {
    ob_end_clean();
    echo "<h3>❌ Exception: " . $e->getMessage() . "</h3>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
