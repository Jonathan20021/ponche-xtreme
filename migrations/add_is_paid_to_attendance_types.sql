-- Migration: Add is_paid column to attendance_types table
-- This allows marking punch types as paid or unpaid for payroll calculations

-- Add is_paid column to attendance_types
ALTER TABLE `attendance_types` 
ADD COLUMN `is_paid` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Indica si este tipo de punch cuenta para pago de nómina' 
AFTER `is_active`;

-- Update existing punch types based on requirements
-- Paid types: DISPONIBLE, WASAPI, DIGITACION
UPDATE `attendance_types` SET `is_paid` = 1 WHERE `slug` IN ('DISPONIBLE', 'WASAPI', 'DIGITACION');

-- Unpaid types: ENTRY, BA_NO (Baño), PAUSA, LUNCH, BREAK, EXIT
UPDATE `attendance_types` SET `is_paid` = 0 WHERE `slug` IN ('ENTRY', 'BA_NO', 'PAUSA', 'LUNCH', 'BREAK', 'EXIT');

-- Note: If DISPONIBLE doesn't exist yet, you may need to create it
-- INSERT INTO `attendance_types` (`slug`, `label`, `icon_class`, `shortcut_key`, `color_start`, `color_end`, `sort_order`, `is_unique_daily`, `is_active`, `is_paid`) 
-- VALUES ('DISPONIBLE', 'Disponible', 'fas fa-check-circle', 'D', '#10B981', '#059669', 15, 0, 1, 1);
