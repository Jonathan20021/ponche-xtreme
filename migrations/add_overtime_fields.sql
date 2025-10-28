-- Migration: Add overtime fields to schedule_config and users tables
-- Run this script if your database already exists

-- Add overtime fields to schedule_config table
ALTER TABLE `schedule_config`
ADD COLUMN IF NOT EXISTS `overtime_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Activar calculo de horas extras' AFTER `scheduled_hours`,
ADD COLUMN IF NOT EXISTS `overtime_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.50 COMMENT 'Multiplicador para pago de horas extras (ej: 1.5 = tiempo y medio)' AFTER `overtime_enabled`,
ADD COLUMN IF NOT EXISTS `overtime_start_minutes` INT NOT NULL DEFAULT 0 COMMENT 'Minutos despues de la hora de salida para comenzar a contar horas extras' AFTER `overtime_multiplier`;

-- Add overtime fields to users table
ALTER TABLE `users`
ADD COLUMN IF NOT EXISTS `exit_time` TIME DEFAULT NULL COMMENT 'Hora de salida personalizada para este empleado' AFTER `department_id`,
ADD COLUMN IF NOT EXISTS `overtime_multiplier` DECIMAL(4,2) DEFAULT NULL COMMENT 'Multiplicador personalizado de horas extras (NULL = usar configuracion global)' AFTER `exit_time`;

-- Update existing schedule_config record with default overtime values
UPDATE `schedule_config` 
SET 
    `overtime_enabled` = 1,
    `overtime_multiplier` = 1.50,
    `overtime_start_minutes` = 0
WHERE `id` = 1 
AND (`overtime_enabled` IS NULL OR `overtime_multiplier` IS NULL OR `overtime_start_minutes` IS NULL);
