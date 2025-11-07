<?php
require_once 'db.php';

// Set a test email recipient
$testEmail = 'jonathansandovalferreira@gmail.com'; // Change this to your actual email for testing

try {
    $stmt = $pdo->prepare("
        UPDATE system_settings 
        SET setting_value = ? 
        WHERE setting_key = 'absence_report_recipients'
    ");
    $stmt->execute([$testEmail]);
    
    echo "✓ Test email configured: $testEmail\n";
    echo "\nNow you can:\n";
    echo "1. Go to Settings > Reporte de Ausencias\n";
    echo "2. Click 'Enviar Reporte Ahora'\n";
    echo "3. Check your email inbox\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
