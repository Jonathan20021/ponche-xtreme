-- Migration: Add employee_code field to users table
-- This migration adds an automatic employee code generation system
-- Employee codes follow the format: EMP-YYYY-XXXX (e.g., EMP-2025-0001)

-- Add employee_code column to users table
ALTER TABLE `users` 
ADD COLUMN `employee_code` VARCHAR(20) NULL UNIQUE AFTER `username`,
ADD INDEX `idx_users_employee_code` (`employee_code`);

-- Update existing users with employee codes based on their ID
-- This ensures all existing users get a code
UPDATE `users` 
SET `employee_code` = CONCAT('EMP-', YEAR(CURDATE()), '-', LPAD(id, 4, '0'))
WHERE `employee_code` IS NULL;

-- Note: The application logic will handle generating codes for new users
-- Format: EMP-YYYY-NNNN where YYYY is the current year and NNNN is a sequential number
