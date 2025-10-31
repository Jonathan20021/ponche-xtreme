-- Migration: Add is_active field to users table
-- Purpose: Enable/disable user accounts and prevent inactive users from logging in
-- Date: 2025-10-30

-- Add is_active column to users table
ALTER TABLE `users` 
ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Estado del usuario: 1 = activo, 0 = inactivo' 
AFTER `overtime_multiplier`;

-- Set all existing users as active by default
UPDATE `users` SET `is_active` = 1 WHERE `is_active` IS NULL;

-- Add index for better query performance
ALTER TABLE `users` ADD INDEX `idx_is_active` (`is_active`);
