<?php
// Script para instalar las configuraciones de autorización en el servidor remoto
include 'db.php';

echo "<h1>Instalación de Configuraciones de Autorización</h1>";
echo "<hr>";

try {
    // 1. Verificar si existe la tabla system_settings
    echo "<h3>1. Verificando tabla system_settings...</h3>";
    $checkTable = $pdo->query("SHOW TABLES LIKE 'system_settings'");
    $tableExists = $checkTable->rowCount() > 0;
    
    if (!$tableExists) {
        echo "<p style='color: orange;'>⚠️ Tabla system_settings no existe. Creándola...</p>";
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `system_settings` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `setting_key` varchar(100) NOT NULL,
              `setting_value` text,
              `setting_type` varchar(50) DEFAULT 'string',
              `description` text,
              `category` varchar(50) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `setting_key` (`setting_key`),
              KEY `idx_category` (`category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        echo "<p style='color: green;'>✅ Tabla system_settings creada exitosamente</p>";
    } else {
        echo "<p style='color: green;'>✅ Tabla system_settings ya existe</p>";
    }
    
    // 2. Verificar si existe la tabla authorization_codes
    echo "<h3>2. Verificando tabla authorization_codes...</h3>";
    $checkAuthTable = $pdo->query("SHOW TABLES LIKE 'authorization_codes'");
    $authTableExists = $checkAuthTable->rowCount() > 0;
    
    if (!$authTableExists) {
        echo "<p style='color: orange;'>⚠️ Tabla authorization_codes no existe. Creándola...</p>";
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `authorization_codes` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `code` varchar(50) NOT NULL,
              `code_name` varchar(100) NOT NULL,
              `role_type` varchar(50) NOT NULL,
              `usage_context` varchar(50) DEFAULT NULL,
              `created_by` int(10) UNSIGNED DEFAULT NULL,
              `is_active` tinyint(1) DEFAULT '1',
              `valid_from` datetime DEFAULT NULL,
              `valid_until` datetime DEFAULT NULL,
              `max_uses` int(11) DEFAULT NULL,
              `current_uses` int(11) DEFAULT '0',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `code` (`code`),
              KEY `idx_role_type` (`role_type`),
              KEY `idx_usage_context` (`usage_context`),
              KEY `idx_active` (`is_active`),
              KEY `fk_authorization_created_by` (`created_by`),
              CONSTRAINT `fk_authorization_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        echo "<p style='color: green;'>✅ Tabla authorization_codes creada exitosamente</p>";
    } else {
        echo "<p style='color: green;'>✅ Tabla authorization_codes ya existe</p>";
    }
    
    // 3. Verificar si existe la tabla authorization_code_logs
    echo "<h3>3. Verificando tabla authorization_code_logs...</h3>";
    $checkLogsTable = $pdo->query("SHOW TABLES LIKE 'authorization_code_logs'");
    $logsTableExists = $checkLogsTable->rowCount() > 0;
    
    if (!$logsTableExists) {
        echo "<p style='color: orange;'>⚠️ Tabla authorization_code_logs no existe. Creándola...</p>";
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `authorization_code_logs` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `authorization_code_id` int(10) UNSIGNED NOT NULL,
              `user_id` int(10) UNSIGNED NOT NULL,
              `usage_context` varchar(50) NOT NULL,
              `reference_id` int(11) DEFAULT NULL,
              `reference_table` varchar(100) DEFAULT NULL,
              `ip_address` varchar(45) DEFAULT NULL,
              `additional_data` text,
              `used_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_code_id` (`authorization_code_id`),
              KEY `idx_user_id` (`user_id`),
              KEY `idx_usage_context` (`usage_context`),
              KEY `idx_used_at` (`used_at`),
              CONSTRAINT `fk_log_authorization_code` FOREIGN KEY (`authorization_code_id`) REFERENCES `authorization_codes` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        echo "<p style='color: green;'>✅ Tabla authorization_code_logs creada exitosamente</p>";
    } else {
        echo "<p style='color: green;'>✅ Tabla authorization_code_logs ya existe</p>";
    }
    
    // 4. Insertar configuraciones
    echo "<h3>4. Insertando configuraciones...</h3>";
    
    $settings = [
        ['authorization_codes_enabled', '1', 'Habilitar sistema de códigos de autorización', 'authorization_codes'],
        ['authorization_require_for_overtime', '1', 'Requerir código de autorización para registrar horas extras', 'authorization_codes'],
        ['authorization_require_for_edit_records', '1', 'Requerir código de autorización para editar registros de asistencia', 'authorization_codes'],
        ['authorization_require_for_delete_records', '1', 'Requerir código de autorización para eliminar registros de asistencia', 'authorization_codes']
    ];
    
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, description, category)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            setting_value = VALUES(setting_value),
            description = VALUES(description),
            category = VALUES(category)
    ");
    
    foreach ($settings as $setting) {
        $stmt->execute($setting);
        echo "<p>✅ Configuración '{$setting[0]}' = {$setting[1]}</p>";
    }
    
    echo "<p style='color: green;'><strong>✅ Todas las configuraciones instaladas exitosamente</strong></p>";
    
    // 5. Mostrar resumen
    echo "<h3>5. Resumen de Configuraciones Actuales:</h3>";
    $summary = $pdo->query("SELECT setting_key, setting_value, description FROM system_settings WHERE setting_key LIKE 'authorization%' ORDER BY setting_key");
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>Setting Key</th><th>Valor</th><th>Descripción</th></tr>";
    foreach ($summary as $row) {
        $value = $row['setting_value'] == 1 ? '✅ Habilitado' : '❌ Deshabilitado';
        echo "<tr>";
        echo "<td><code>{$row['setting_key']}</code></td>";
        echo "<td>{$value}</td>";
        echo "<td>{$row['description']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<hr>";
    echo "<h2 style='color: green;'>✅ INSTALACIÓN COMPLETADA EXITOSAMENTE</h2>";
    echo "<p><a href='test_auth_config.php'>Ver Test de Configuración</a> | <a href='records.php'>Ir a Records</a> | <a href='settings.php'>Ir a Settings</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>❌ ERROR:</strong> " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
