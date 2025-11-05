-- Agregar campos de tipo de compensación y salario diario a la tabla users
-- Fecha: 2025-11-05

-- Agregar campo compensation_type
ALTER TABLE `users` 
ADD COLUMN `compensation_type` VARCHAR(20) NOT NULL DEFAULT 'hourly' 
COMMENT 'Tipo de compensación: hourly, fixed, daily' 
AFTER `preferred_currency`;

-- Agregar campos de salario diario
ALTER TABLE `users` 
ADD COLUMN `daily_salary_usd` DECIMAL(10,2) NOT NULL DEFAULT '0.00' 
COMMENT 'Salario diario en USD' 
AFTER `monthly_salary_dop`;

ALTER TABLE `users` 
ADD COLUMN `daily_salary_dop` DECIMAL(12,2) NOT NULL DEFAULT '0.00' 
COMMENT 'Salario diario en DOP' 
AFTER `daily_salary_usd`;

-- Actualizar registros existentes basándose en los valores actuales
-- Si tiene hourly_rate > 0, es hourly
-- Si tiene monthly_salary > 0, es fixed
UPDATE `users` 
SET `compensation_type` = CASE 
    WHEN `hourly_rate` > 0 OR `hourly_rate_dop` > 0 THEN 'hourly'
    WHEN `monthly_salary` > 0 OR `monthly_salary_dop` > 0 THEN 'fixed'
    ELSE 'hourly'
END;
