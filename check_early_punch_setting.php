<?php
require_once 'db.php';

// Check if setting exists
$stmt = $pdo->query("SELECT * FROM system_settings WHERE setting_key = 'authorization_require_for_early_punch'");
$setting = $stmt->fetch(PDO::FETCH_ASSOC);

if ($setting) {
    echo "Setting exists:\n";
    print_r($setting);
} else {
    echo "Setting does not exist. Creating it...\n";
    
    // Insert the setting
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, description) 
        VALUES ('authorization_require_for_early_punch', '1', 'Requerir código de autorización para punches antes de la hora de entrada programada')
    ");
    
    if ($stmt->execute()) {
        echo "Setting created successfully!\n";
    } else {
        echo "Error creating setting.\n";
    }
}
