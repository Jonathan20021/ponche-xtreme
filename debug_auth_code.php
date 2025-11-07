<?php
session_start();
include 'db.php';
require_once 'lib/authorization_functions.php';

echo "<h1>Debug de Validación de Código</h1>";
echo "<hr>";

// Mostrar códigos disponibles
echo "<h2>Códigos Activos en la Base de Datos:</h2>";
$stmt = $pdo->query("
    SELECT 
        id,
        code,
        code_name,
        role_type,
        usage_context,
        is_active,
        valid_from,
        valid_until,
        max_uses,
        current_uses
    FROM authorization_codes 
    WHERE is_active = 1
    ORDER BY code
");
$codes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Código</th><th>Nombre</th><th>Rol</th><th>Contexto</th><th>Válido Desde</th><th>Válido Hasta</th><th>Usos</th></tr>";
foreach ($codes as $code) {
    $context = $code['usage_context'] ?: '<span style="color: green;">TODOS (universal)</span>';
    $validFrom = $code['valid_from'] ?: '<span style="color: blue;">Sin límite</span>';
    $validUntil = $code['valid_until'] ?: '<span style="color: blue;">Sin límite</span>';
    $uses = $code['max_uses'] ? "{$code['current_uses']}/{$code['max_uses']}" : "{$code['current_uses']}/∞";
    
    echo "<tr>";
    echo "<td>{$code['id']}</td>";
    echo "<td><strong>{$code['code']}</strong></td>";
    echo "<td>{$code['code_name']}</td>";
    echo "<td>{$code['role_type']}</td>";
    echo "<td>{$context}</td>";
    echo "<td>{$validFrom}</td>";
    echo "<td>{$validUntil}</td>";
    echo "<td>{$uses}</td>";
    echo "</tr>";
}
echo "</table>";

// Probar validación de un código específico
echo "<hr>";
echo "<h2>Probar Validación de Código:</h2>";
echo "<form method='POST' style='margin: 20px 0;'>";
echo "<label>Código a probar: <input type='text' name='test_code' value='" . ($_POST['test_code'] ?? '') . "' required></label><br><br>";
echo "<label>Contexto: <select name='test_context'>";
echo "<option value='delete_records'" . (($_POST['test_context'] ?? '') == 'delete_records' ? ' selected' : '') . ">delete_records</option>";
echo "<option value='edit_records'" . (($_POST['test_context'] ?? '') == 'edit_records' ? ' selected' : '') . ">edit_records</option>";
echo "<option value='overtime'" . (($_POST['test_context'] ?? '') == 'overtime' ? ' selected' : '') . ">overtime</option>";
echo "</select></label><br><br>";
echo "<label>User ID: <input type='number' name='test_user_id' value='" . ($_POST['test_user_id'] ?? $_SESSION['user_id'] ?? 1) . "' required></label><br><br>";
echo "<button type='submit' name='test_submit'>Probar Validación</button>";
echo "</form>";

if (isset($_POST['test_submit'])) {
    $testCode = trim($_POST['test_code']);
    $testContext = $_POST['test_context'];
    $testUserId = (int)$_POST['test_user_id'];
    
    echo "<div style='background: #f0f0f0; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
    echo "<h3>Resultado de Validación:</h3>";
    echo "<p><strong>Código:</strong> {$testCode}</p>";
    echo "<p><strong>Contexto:</strong> {$testContext}</p>";
    echo "<p><strong>User ID:</strong> {$testUserId}</p>";
    
    $validation = validateAuthorizationCode($pdo, $testCode, $testContext, $testUserId);
    
    if ($validation['valid']) {
        echo "<p style='color: green; font-size: 20px;'><strong>✅ CÓDIGO VÁLIDO</strong></p>";
        echo "<p><strong>Code ID:</strong> {$validation['code_id']}</p>";
    } else {
        echo "<p style='color: red; font-size: 20px;'><strong>❌ CÓDIGO INVÁLIDO</strong></p>";
        echo "<p><strong>Error:</strong> {$validation['error']}</p>";
    }
    
    // Mostrar detalles del código
    if (isset($validation['code_id'])) {
        $codeDetails = $pdo->prepare("SELECT * FROM authorization_codes WHERE id = ?");
        $codeDetails->execute([$validation['code_id']]);
        $details = $codeDetails->fetch(PDO::FETCH_ASSOC);
        
        echo "<hr>";
        echo "<h4>Detalles del Código:</h4>";
        echo "<pre>" . print_r($details, true) . "</pre>";
    }
    echo "</div>";
}

// Verificar configuraciones
echo "<hr>";
echo "<h2>Estado de Configuraciones:</h2>";
$contexts = ['overtime', 'edit_records', 'delete_records'];
echo "<ul>";
foreach ($contexts as $ctx) {
    $required = isAuthorizationRequiredForContext($pdo, $ctx);
    $status = $required ? '✅ REQUERIDO' : '❌ NO REQUERIDO';
    echo "<li><strong>{$ctx}:</strong> {$status}</li>";
}
echo "</ul>";

echo "<hr>";
echo "<p><a href='records.php'>← Volver a Records</a> | <a href='settings.php'>Ir a Settings</a></p>";
?>
