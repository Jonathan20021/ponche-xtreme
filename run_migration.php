<?php
/**
 * Script temporal para ejecutar migración de campaign_ast_performance
 * Hace campaign_id NULLABLE y ajusta índices para usar team_id
 */

require_once __DIR__ . '/db.php';

echo "=== Ejecutando migración: update_staffing_tables_nullable ===\n\n";

// Definir los statements manualmente para mayor control
$statements = [
    "DROP FK fk_inbound_campaign" => "ALTER TABLE `vicidial_inbound_hourly` DROP FOREIGN KEY `fk_inbound_campaign`",
    "ADD campaign_name to vicidial_inbound_hourly" => "ALTER TABLE `vicidial_inbound_hourly` ADD COLUMN `campaign_name` VARCHAR(100) NULL AFTER `campaign_id`",
    "MODIFY campaign_id NULL in vicidial_inbound_hourly" => "ALTER TABLE `vicidial_inbound_hourly` MODIFY COLUMN `campaign_id` INT(10) UNSIGNED NULL DEFAULT NULL",
    "UPDATE campaign_name from campaigns (vicidial)" => "UPDATE vicidial_inbound_hourly v INNER JOIN campaigns c ON c.id = v.campaign_id SET v.campaign_name = c.name WHERE v.campaign_name IS NULL",
    "DROP INDEX idx_campaign_interval (vicidial)" => "ALTER TABLE `vicidial_inbound_hourly` DROP INDEX `idx_campaign_interval`",
    "ADD UNIQUE KEY with campaign_name (vicidial)" => "ALTER TABLE `vicidial_inbound_hourly` ADD UNIQUE KEY `idx_campaign_interval` (`campaign_name`, `interval_start`)",
    "ADD INDEX idx_interval_start (vicidial)" => "ALTER TABLE `vicidial_inbound_hourly` ADD INDEX `idx_interval_start` (`interval_start`)",
    
    "DROP FK fk_staffing_forecast_campaign" => "ALTER TABLE `campaign_staffing_forecast` DROP FOREIGN KEY `fk_staffing_forecast_campaign`",
    "ADD campaign_name to campaign_staffing_forecast" => "ALTER TABLE `campaign_staffing_forecast` ADD COLUMN `campaign_name` VARCHAR(100) NULL AFTER `campaign_id`",
    "MODIFY campaign_id NULL in campaign_staffing_forecast" => "ALTER TABLE `campaign_staffing_forecast` MODIFY COLUMN `campaign_id` INT(10) UNSIGNED NULL DEFAULT NULL",
    "UPDATE campaign_name from campaigns (forecast)" => "UPDATE campaign_staffing_forecast f INNER JOIN campaigns c ON c.id = f.campaign_id SET f.campaign_name = c.name WHERE f.campaign_name IS NULL",
    "DROP INDEX campaign_interval (forecast)" => "ALTER TABLE `campaign_staffing_forecast` DROP INDEX `campaign_interval`",
    "ADD UNIQUE KEY with campaign_name (forecast)" => "ALTER TABLE `campaign_staffing_forecast` ADD UNIQUE KEY `campaign_interval` (`campaign_name`, `interval_start`)",
    "ADD INDEX idx_interval_start_forecast" => "ALTER TABLE `campaign_staffing_forecast` ADD INDEX `idx_interval_start_forecast` (`interval_start`)"
];

echo "📋 Statements a ejecutar: " . count($statements) . "\n\n";

$success = 0;
$errors = 0;

foreach ($statements as $label => $statement) {
    echo "▶️  $label\n";
    echo "   " . substr($statement, 0, 80) . "...\n";
    
    try {
        $pdo->exec($statement);
        echo "   ✅ Completado\n\n";
        $success++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        echo "   ⚠️  " . $msg . "\n";
        
        // Errores que no son críticos (index/constraint ya existe o no existe)
        if (strpos($msg, 'Duplicate key name') !== false ||
            strpos($msg, "check that column/key exists") !== false ||
            strpos($msg, "Can't DROP") !== false) {
            echo "   ℹ️  Error ignorado (cambio ya aplicado o no necesario)\n\n";
            $success++; // Contar como éxito si ya está aplicado
        } else {
            echo "   ❌ Error crítico\n\n";
            $errors++;
        }
    }
}

echo "\n=== Resumen ===\n";
echo "✅ Completados: $success\n";
echo "⚠️  Errores: $errors\n";

if ($errors === 0) {
    echo "\n🎉 Migración completada exitosamente!\n";
} else {
    echo "\n⚠️  Migración completada con algunos errores. Revisa los mensajes arriba.\n";
}

echo "\n💡 Verifica la estructura con:\n";
echo "   DESCRIBE campaign_ast_performance;\n";
echo "   SHOW INDEX FROM campaign_ast_performance;\n";
