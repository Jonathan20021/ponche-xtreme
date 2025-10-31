-- Migration: Add banking information and photo fields to employees
-- Date: 2025-10-31
-- Description: Adds ID card number, bank account information, and photo upload capability

-- Create banks table for dynamic bank management
CREATE TABLE IF NOT EXISTS `banks` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `code` varchar(20) DEFAULT NULL COMMENT 'Bank code or abbreviation',
  `swift_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'República Dominicana',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bank_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert common Dominican Republic banks
INSERT INTO `banks` (`name`, `code`, `country`, `is_active`) VALUES
('Banco Popular Dominicano', 'BPD', 'República Dominicana', 1),
('Banco de Reservas', 'BANRESERVAS', 'República Dominicana', 1),
('Banco BHD León', 'BHD', 'República Dominicana', 1),
('Banco Santa Cruz', 'BSC', 'República Dominicana', 1),
('Banco López de Haro', 'BLH', 'República Dominicana', 1),
('Scotiabank', 'SCOTIA', 'República Dominicana', 1),
('Citibank', 'CITI', 'República Dominicana', 1),
('Banco Promerica', 'PROMERICA', 'República Dominicana', 1),
('Banco Caribe', 'CARIBE', 'República Dominicana', 1),
('Banco Múltiple Ademi', 'ADEMI', 'República Dominicana', 1);

-- Add new fields to employees table
ALTER TABLE `employees`
ADD COLUMN `id_card_number` varchar(50) DEFAULT NULL COMMENT 'Número de cédula de identidad' AFTER `identification_number`,
ADD COLUMN `bank_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Bank where employee has account' AFTER `id_card_number`,
ADD COLUMN `bank_account_number` varchar(50) DEFAULT NULL COMMENT 'Employee bank account number' AFTER `bank_id`,
ADD COLUMN `photo_path` varchar(255) DEFAULT NULL COMMENT 'Path to employee photo' AFTER `profile_photo`;

-- Add foreign key constraint for bank_id
ALTER TABLE `employees`
ADD CONSTRAINT `fk_employees_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- Create index for better performance
CREATE INDEX `idx_employees_bank_id` ON `employees` (`bank_id`);
CREATE INDEX `idx_employees_id_card` ON `employees` (`id_card_number`);

-- Note: Make sure to create the uploads/employee_photos directory with proper permissions
