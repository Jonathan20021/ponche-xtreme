-- Migration: Add employee schedules/shifts system
-- This allows each employee to have their own work schedule

-- Create employee_schedules table
CREATE TABLE IF NOT EXISTS `employee_schedules` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `schedule_name` varchar(100) DEFAULT NULL COMMENT 'Nombre del turno (ej: Turno Mañana, Turno Tarde)',
  `entry_time` time NOT NULL DEFAULT '10:00:00' COMMENT 'Hora de entrada',
  `exit_time` time NOT NULL DEFAULT '19:00:00' COMMENT 'Hora de salida',
  `lunch_time` time DEFAULT '14:00:00' COMMENT 'Hora de almuerzo',
  `break_time` time DEFAULT '17:00:00' COMMENT 'Hora de descanso',
  `lunch_minutes` int(11) NOT NULL DEFAULT 45 COMMENT 'Minutos de almuerzo',
  `break_minutes` int(11) NOT NULL DEFAULT 15 COMMENT 'Minutos de descanso',
  `scheduled_hours` decimal(5,2) NOT NULL DEFAULT 8.00 COMMENT 'Horas programadas por día',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Si el horario está activo',
  `effective_date` date DEFAULT NULL COMMENT 'Fecha desde cuando aplica este horario',
  `end_date` date DEFAULT NULL COMMENT 'Fecha hasta cuando aplica (NULL = indefinido)',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `fk_employee_schedules_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_employee_schedules_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index for faster lookups by date range
CREATE INDEX idx_effective_dates ON employee_schedules(effective_date, end_date);

-- Create predefined schedule templates table
CREATE TABLE IF NOT EXISTS `schedule_templates` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Nombre del template (ej: Turno Mañana, Turno Tarde)',
  `description` text DEFAULT NULL,
  `entry_time` time NOT NULL DEFAULT '10:00:00',
  `exit_time` time NOT NULL DEFAULT '19:00:00',
  `lunch_time` time DEFAULT '14:00:00',
  `break_time` time DEFAULT '17:00:00',
  `lunch_minutes` int(11) NOT NULL DEFAULT 45,
  `break_minutes` int(11) NOT NULL DEFAULT 15,
  `scheduled_hours` decimal(5,2) NOT NULL DEFAULT 8.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_template_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default schedule templates
INSERT INTO `schedule_templates` (`name`, `description`, `entry_time`, `exit_time`, `lunch_time`, `break_time`, `lunch_minutes`, `break_minutes`, `scheduled_hours`) VALUES
('Turno Regular (10am-7pm)', 'Horario estándar de 10:00 AM a 7:00 PM', '10:00:00', '19:00:00', '14:00:00', '17:00:00', 45, 15, 8.00),
('Turno Mañana (7am-4pm)', 'Turno matutino de 7:00 AM a 4:00 PM', '07:00:00', '16:00:00', '12:00:00', '15:00:00', 45, 15, 8.00),
('Turno Tarde (2pm-11pm)', 'Turno vespertino de 2:00 PM a 11:00 PM', '14:00:00', '23:00:00', '18:00:00', '21:00:00', 45, 15, 8.00),
('Turno Noche (10pm-7am)', 'Turno nocturno de 10:00 PM a 7:00 AM', '22:00:00', '07:00:00', '02:00:00', '05:00:00', 45, 15, 8.00),
('Medio Tiempo Mañana (8am-12pm)', 'Medio tiempo matutino', '08:00:00', '12:00:00', NULL, NULL, 0, 0, 4.00),
('Medio Tiempo Tarde (1pm-5pm)', 'Medio tiempo vespertino', '13:00:00', '17:00:00', NULL, NULL, 0, 0, 4.00);

-- Add schedule_id to users table for quick reference
ALTER TABLE `users` 
ADD COLUMN `schedule_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Horario actual del empleado' AFTER `exit_time`,
ADD KEY `idx_schedule_id` (`schedule_id`);
