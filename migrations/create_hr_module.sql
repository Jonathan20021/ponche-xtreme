-- =====================================================
-- HR MODULE - Complete Database Schema
-- =====================================================

USE `ponche`;

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- Table: employees
-- Extended employee information linked to users
-- =====================================================
CREATE TABLE IF NOT EXISTS `employees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `employee_code` VARCHAR(20) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `mobile` VARCHAR(20) DEFAULT NULL,
  `birth_date` DATE DEFAULT NULL,
  `hire_date` DATE NOT NULL,
  `termination_date` DATE DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `postal_code` VARCHAR(20) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT 'República Dominicana',
  `emergency_contact_name` VARCHAR(150) DEFAULT NULL,
  `emergency_contact_phone` VARCHAR(20) DEFAULT NULL,
  `emergency_contact_relationship` VARCHAR(50) DEFAULT NULL,
  `identification_type` VARCHAR(50) DEFAULT NULL COMMENT 'Cédula, Pasaporte, etc.',
  `identification_number` VARCHAR(50) DEFAULT NULL,
  `blood_type` VARCHAR(5) DEFAULT NULL,
  `marital_status` VARCHAR(20) DEFAULT NULL,
  `gender` VARCHAR(20) DEFAULT NULL,
  `position` VARCHAR(150) DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `supervisor_id` INT UNSIGNED DEFAULT NULL,
  `employment_status` VARCHAR(50) NOT NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE, TRIAL, SUSPENDED, TERMINATED',
  `employment_type` VARCHAR(50) DEFAULT 'FULL_TIME' COMMENT 'FULL_TIME, PART_TIME, CONTRACT, INTERN',
  `notes` TEXT DEFAULT NULL,
  `profile_photo` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employees_user_id_unique` (`user_id`),
  UNIQUE KEY `employees_employee_code_unique` (`employee_code`),
  UNIQUE KEY `employees_email_unique` (`email`),
  KEY `idx_employees_hire_date` (`hire_date`),
  KEY `idx_employees_birth_date` (`birth_date`),
  KEY `idx_employees_status` (`employment_status`),
  KEY `idx_employees_department` (`department_id`),
  KEY `idx_employees_supervisor` (`supervisor_id`),
  CONSTRAINT `fk_employees_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_employees_department`
    FOREIGN KEY (`department_id`)
    REFERENCES `departments` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_employees_supervisor`
    FOREIGN KEY (`supervisor_id`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: payroll_periods
-- Payroll period definitions
-- =====================================================
CREATE TABLE IF NOT EXISTS `payroll_periods` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_name` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `payment_date` DATE DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'OPEN' COMMENT 'OPEN, PROCESSING, PAID, CLOSED',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_periods_dates` (`start_date`, `end_date`),
  KEY `idx_payroll_periods_status` (`status`),
  CONSTRAINT `fk_payroll_periods_creator`
    FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: payroll_records
-- Individual payroll records per employee per period
-- =====================================================
CREATE TABLE IF NOT EXISTS `payroll_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payroll_period_id` INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `regular_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `hourly_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `overtime_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `regular_pay` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `overtime_pay` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `bonuses` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `deductions` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `gross_pay` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_pay` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
  `notes` TEXT DEFAULT NULL,
  `calculated_at` TIMESTAMP NULL DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_records_period_employee_unique` (`payroll_period_id`, `employee_id`),
  KEY `idx_payroll_records_employee` (`employee_id`),
  KEY `idx_payroll_records_user` (`user_id`),
  CONSTRAINT `fk_payroll_records_period`
    FOREIGN KEY (`payroll_period_id`)
    REFERENCES `payroll_periods` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_payroll_records_employee`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_payroll_records_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_payroll_records_approver`
    FOREIGN KEY (`approved_by`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: permission_requests
-- Employee permission/time-off requests
-- =====================================================
CREATE TABLE IF NOT EXISTS `permission_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `request_type` VARCHAR(50) NOT NULL COMMENT 'PERMISSION, SICK_LEAVE, PERSONAL, MEDICAL, OTHER',
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `start_time` TIME DEFAULT NULL,
  `end_time` TIME DEFAULT NULL,
  `total_days` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  `total_hours` DECIMAL(5,2) DEFAULT NULL,
  `reason` TEXT NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, APPROVED, REJECTED, CANCELLED',
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `review_notes` TEXT DEFAULT NULL,
  `attachment` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_permission_requests_employee` (`employee_id`),
  KEY `idx_permission_requests_dates` (`start_date`, `end_date`),
  KEY `idx_permission_requests_status` (`status`),
  CONSTRAINT `fk_permission_requests_employee`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_permission_requests_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_permission_requests_reviewer`
    FOREIGN KEY (`reviewed_by`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: vacation_requests
-- Employee vacation requests
-- =====================================================
CREATE TABLE IF NOT EXISTS `vacation_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `total_days` DECIMAL(5,2) NOT NULL,
  `vacation_type` VARCHAR(50) DEFAULT 'ANNUAL' COMMENT 'ANNUAL, UNPAID, COMPENSATORY',
  `reason` TEXT DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, APPROVED, REJECTED, CANCELLED',
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `review_notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vacation_requests_employee` (`employee_id`),
  KEY `idx_vacation_requests_dates` (`start_date`, `end_date`),
  KEY `idx_vacation_requests_status` (`status`),
  CONSTRAINT `fk_vacation_requests_employee`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_vacation_requests_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_vacation_requests_reviewer`
    FOREIGN KEY (`reviewed_by`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: vacation_balances
-- Track vacation days balance per employee
-- =====================================================
CREATE TABLE IF NOT EXISTS `vacation_balances` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `year` INT NOT NULL,
  `total_days` DECIMAL(5,2) NOT NULL DEFAULT 14.00,
  `used_days` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `remaining_days` DECIMAL(5,2) NOT NULL DEFAULT 14.00,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vacation_balances_employee_year_unique` (`employee_id`, `year`),
  CONSTRAINT `fk_vacation_balances_employee`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: employee_documents
-- Store employee documents and files
-- =====================================================
CREATE TABLE IF NOT EXISTS `employee_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `document_type` VARCHAR(100) NOT NULL COMMENT 'CONTRACT, ID, CERTIFICATE, RESUME, etc.',
  `document_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_size` INT DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_employee_documents_employee` (`employee_id`),
  KEY `idx_employee_documents_type` (`document_type`),
  CONSTRAINT `fk_employee_documents_employee`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_employee_documents_uploader`
    FOREIGN KEY (`uploaded_by`)
    REFERENCES `users` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Table: hr_notifications
-- HR-specific notifications and reminders
-- =====================================================
CREATE TABLE IF NOT EXISTS `hr_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_type` VARCHAR(50) NOT NULL COMMENT 'BIRTHDAY, TRIAL_END, VACATION, PERMISSION, DOCUMENT_EXPIRY',
  `employee_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `notification_date` DATE NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `priority` VARCHAR(20) DEFAULT 'NORMAL' COMMENT 'LOW, NORMAL, HIGH, URGENT',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr_notifications_date` (`notification_date`),
  KEY `idx_hr_notifications_type` (`notification_type`),
  KEY `idx_hr_notifications_employee` (`employee_id`),
  CONSTRAINT `fk_hr_notifications_employee`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- Add HR permissions to section_permissions
-- =====================================================
INSERT INTO `section_permissions` (`section_key`, `role`) VALUES
  ('hr_dashboard', 'Admin'),
  ('hr_dashboard', 'HR'),
  ('hr_dashboard', 'IT'),
  ('hr_employees', 'Admin'),
  ('hr_employees', 'HR'),
  ('hr_employees', 'IT'),
  ('hr_trial_period', 'Admin'),
  ('hr_trial_period', 'HR'),
  ('hr_trial_period', 'IT'),
  ('hr_payroll', 'Admin'),
  ('hr_payroll', 'HR'),
  ('hr_payroll', 'IT'),
  ('hr_birthdays', 'Admin'),
  ('hr_birthdays', 'HR'),
  ('hr_birthdays', 'IT'),
  ('hr_permissions', 'Admin'),
  ('hr_permissions', 'HR'),
  ('hr_permissions', 'IT'),
  ('hr_vacations', 'Admin'),
  ('hr_vacations', 'HR'),
  ('hr_vacations', 'IT'),
  ('hr_calendar', 'Admin'),
  ('hr_calendar', 'HR'),
  ('hr_calendar', 'IT'),
  ('hr_employee_profile', 'Admin'),
  ('hr_employee_profile', 'HR'),
  ('hr_employee_profile', 'IT')
ON DUPLICATE KEY UPDATE section_key = section_key;
