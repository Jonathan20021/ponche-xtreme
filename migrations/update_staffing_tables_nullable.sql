-- =====================================================
-- Hacer campaign_id NULLABLE en tablas de staffing
-- =====================================================
-- Los reportes Erlang/Inbound Daily son solo para análisis WFM.
-- No deben crear campañas automáticamente.
-- =====================================================

-- ========== vicidial_inbound_hourly ==========

-- Agregar columna para guardar nombre de campaña del archivo
ALTER TABLE `vicidial_inbound_hourly`
ADD COLUMN `campaign_name` VARCHAR(100) NULL AFTER `campaign_id`;

-- Hacer campaign_id nullable
ALTER TABLE `vicidial_inbound_hourly`
MODIFY COLUMN `campaign_id` INT(10) UNSIGNED NULL DEFAULT NULL;

-- Copiar nombres de campañas existentes
UPDATE vicidial_inbound_hourly v
INNER JOIN campaigns c ON c.id = v.campaign_id
SET v.campaign_name = c.name
WHERE v.campaign_name IS NULL;

-- Cambiar unique key para usar campaign_name
ALTER TABLE `vicidial_inbound_hourly`
DROP INDEX `idx_campaign_interval`;

ALTER TABLE `vicidial_inbound_hourly`
ADD UNIQUE KEY `idx_campaign_interval` (`campaign_name`, `interval_start`);

-- Agregar índice por fecha
ALTER TABLE `vicidial_inbound_hourly`
ADD INDEX `idx_interval_start` (`interval_start`);


-- ========== campaign_staffing_forecast ==========

-- Agregar columna para guardar nombre de campaña del archivo
ALTER TABLE `campaign_staffing_forecast`
ADD COLUMN `campaign_name` VARCHAR(100) NULL AFTER `campaign_id`;

-- Hacer campaign_id nullable
ALTER TABLE `campaign_staffing_forecast`
MODIFY COLUMN `campaign_id` INT(10) UNSIGNED NULL DEFAULT NULL;

-- Copiar nombres de campañas existentes
UPDATE campaign_staffing_forecast f
INNER JOIN campaigns c ON c.id = f.campaign_id
SET f.campaign_name = c.name
WHERE f.campaign_name IS NULL;

-- Cambiar unique key para usar campaign_name
ALTER TABLE `campaign_staffing_forecast`
DROP INDEX `campaign_interval`;

ALTER TABLE `campaign_staffing_forecast`
ADD UNIQUE KEY `campaign_interval` (`campaign_name`, `interval_start`);

-- Agregar índice por fecha
ALTER TABLE `campaign_staffing_forecast`
ADD INDEX `idx_interval_start_forecast` (`interval_start`);
