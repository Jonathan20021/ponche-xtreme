<?php
/**
 * Migración: verificación de empleo vía webhook (GHL AI Voice Agent / bancos).
 * Crea employment_verification_log y siembra los system_settings por defecto.
 */

require_once __DIR__ . '/db.php';

echo "=== Ejecutando migración: add_employment_verification ===\n\n";

$statements = [
    'Crear tabla employment_verification_log' => "
        CREATE TABLE IF NOT EXISTS `employment_verification_log` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `queried_id_number` VARCHAR(50) NOT NULL,
          `employee_id` INT UNSIGNED NULL,
          `found` TINYINT(1) NOT NULL DEFAULT 0,
          `is_active` TINYINT(1) NULL,
          `caller_ip` VARCHAR(64) NULL,
          `source` VARCHAR(50) NOT NULL DEFAULT 'ghl_voice_ai',
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_employment_verification_log_employee` (`employee_id`),
          KEY `idx_employment_verification_log_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'Sembrar system_settings por defecto' => "
        INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `category`) VALUES
          ('employment_verification_enabled', '0', 'boolean', 'integrations'),
          ('employment_verification_api_key', '', 'text', 'integrations'),
          ('employment_verification_include_position', '1', 'boolean', 'integrations')
    ",
];

$success = 0;
$errors = 0;

foreach ($statements as $label => $statement) {
    echo "▶️  $label\n";
    try {
        $pdo->exec($statement);
        echo "   ✅ Completado\n\n";
        $success++;
    } catch (PDOException $e) {
        echo "   ❌ Error: " . $e->getMessage() . "\n\n";
        $errors++;
    }
}

echo "=== Resumen ===\n";
echo "✅ Completados: $success\n";
echo "❌ Errores: $errors\n";
