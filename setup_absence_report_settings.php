<?php
require_once 'db.php';

// Create settings for absence report
$settings = [
    ['absence_report_recipients', '', 'text', 'reports', 'Correos electrónicos para recibir el reporte diario de ausencias (separados por coma)'],
    ['absence_report_enabled', '1', 'boolean', 'reports', 'Habilitar envío automático del reporte diario de ausencias'],
    ['absence_report_time', '08:00', 'text', 'reports', 'Hora de envío del reporte diario (formato HH:MM, GMT-4)']
];

try {
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description) 
        VALUES (?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
            setting_type = VALUES(setting_type),
            category = VALUES(category),
            description = VALUES(description)
    ");
    
    foreach ($settings as $setting) {
        $stmt->execute($setting);
        echo "✓ Created/Updated: {$setting[0]}\n";
    }
    
    echo "\n✅ All absence report settings created successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
