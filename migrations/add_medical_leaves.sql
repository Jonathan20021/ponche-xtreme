-- =====================================================
-- MEDICAL LEAVES MODULE MIGRATION
-- Sistema de Licencias Médicas
-- =====================================================

-- Tabla principal de licencias médicas
CREATE TABLE IF NOT EXISTS `medical_leaves` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `leave_type` varchar(50) NOT NULL DEFAULT 'MEDICAL' COMMENT 'MEDICAL, MATERNITY, PATERNITY, ACCIDENT, SURGERY, CHRONIC',
  `diagnosis` varchar(255) DEFAULT NULL COMMENT 'Diagnóstico médico',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `expected_return_date` date DEFAULT NULL,
  `actual_return_date` date DEFAULT NULL,
  `total_days` decimal(5,2) NOT NULL DEFAULT 1.00,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Si la licencia es pagada',
  `payment_percentage` decimal(5,2) DEFAULT 100.00 COMMENT 'Porcentaje de pago (ej: 100%, 60%)',
  `doctor_name` varchar(150) DEFAULT NULL,
  `medical_center` varchar(200) DEFAULT NULL,
  `medical_certificate_number` varchar(100) DEFAULT NULL,
  `medical_certificate_file` varchar(255) DEFAULT NULL COMMENT 'Ruta al archivo del certificado médico',
  `prescription_file` varchar(255) DEFAULT NULL COMMENT 'Ruta a recetas médicas',
  `reason` text DEFAULT NULL COMMENT 'Razón detallada de la licencia',
  `notes` text DEFAULT NULL COMMENT 'Notas adicionales',
  `status` varchar(50) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, APPROVED, REJECTED, CANCELLED, EXTENDED, COMPLETED',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `requires_followup` tinyint(1) DEFAULT 0 COMMENT 'Si requiere seguimiento médico',
  `followup_date` date DEFAULT NULL,
  `followup_notes` text DEFAULT NULL,
  `is_work_related` tinyint(1) DEFAULT 0 COMMENT 'Si es relacionado con el trabajo (accidente laboral)',
  `ars_claim_number` varchar(100) DEFAULT NULL COMMENT 'Número de reclamación ARS',
  `ars_status` varchar(50) DEFAULT NULL COMMENT 'Estado de reclamación ARS',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_medical_leaves_employee` (`employee_id`),
  KEY `idx_medical_leaves_user` (`user_id`),
  KEY `idx_medical_leaves_dates` (`start_date`, `end_date`),
  KEY `idx_medical_leaves_status` (`status`),
  KEY `idx_medical_leaves_type` (`leave_type`),
  KEY `idx_medical_leaves_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_medical_leaves_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_medical_leaves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_medical_leaves_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de licencias médicas y relacionadas';

-- Tabla de extensiones de licencias médicas
CREATE TABLE IF NOT EXISTS `medical_leave_extensions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `medical_leave_id` bigint(20) UNSIGNED NOT NULL,
  `previous_end_date` date NOT NULL,
  `new_end_date` date NOT NULL,
  `extension_days` int(11) NOT NULL,
  `reason` text NOT NULL,
  `medical_certificate_file` varchar(255) DEFAULT NULL,
  `requested_by` int(10) UNSIGNED NOT NULL,
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, APPROVED, REJECTED',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_extensions_leave` (`medical_leave_id`),
  KEY `idx_extensions_requested_by` (`requested_by`),
  KEY `idx_extensions_approved_by` (`approved_by`),
  CONSTRAINT `fk_extensions_leave` FOREIGN KEY (`medical_leave_id`) REFERENCES `medical_leaves` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_extensions_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_extensions_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Extensiones de licencias médicas';

-- Tabla de seguimientos médicos
CREATE TABLE IF NOT EXISTS `medical_leave_followups` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `medical_leave_id` bigint(20) UNSIGNED NOT NULL,
  `followup_date` date NOT NULL,
  `followup_type` varchar(50) DEFAULT 'CHECKUP' COMMENT 'CHECKUP, TREATMENT, THERAPY, EXAM, OTHER',
  `medical_center` varchar(200) DEFAULT NULL,
  `doctor_name` varchar(150) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'SCHEDULED' COMMENT 'SCHEDULED, COMPLETED, CANCELLED, RESCHEDULED',
  `next_followup_date` date DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_followups_leave` (`medical_leave_id`),
  KEY `idx_followups_date` (`followup_date`),
  KEY `idx_followups_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_followups_leave` FOREIGN KEY (`medical_leave_id`) REFERENCES `medical_leaves` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_followups_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Seguimientos de licencias médicas';

-- Tabla de estadísticas de salud del empleado
CREATE TABLE IF NOT EXISTS `employee_health_stats` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `year` int(11) NOT NULL,
  `total_medical_leaves` int(11) DEFAULT 0,
  `total_days_on_leave` decimal(6,2) DEFAULT 0.00,
  `total_work_related_incidents` int(11) DEFAULT 0,
  `last_medical_leave_date` date DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_employee_year` (`employee_id`, `year`),
  KEY `idx_health_stats_employee` (`employee_id`),
  KEY `idx_health_stats_year` (`year`),
  CONSTRAINT `fk_health_stats_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Estadísticas de salud por empleado';

-- Insertar permisos para el módulo de licencias médicas
INSERT INTO `section_permissions` (`section_key`, `role`) VALUES
('hr_medical_leaves', 'Admin'),
('hr_medical_leaves', 'HR'),
('hr_medical_leaves', 'IT')
ON DUPLICATE KEY UPDATE section_key = section_key;

-- Agregar notificaciones para licencias médicas próximas a vencer
INSERT INTO `hr_notifications` (`notification_type`, `employee_id`, `title`, `message`, `notification_date`, `priority`)
SELECT 
    'MEDICAL_LEAVE_ENDING',
    ml.employee_id,
    CONCAT('Licencia médica próxima a finalizar - ', e.first_name, ' ', e.last_name),
    CONCAT('La licencia médica del empleado ', e.first_name, ' ', e.last_name, ' finaliza el ', DATE_FORMAT(ml.end_date, '%d/%m/%Y')),
    DATE_SUB(ml.end_date, INTERVAL 2 DAY),
    'NORMAL'
FROM medical_leaves ml
JOIN employees e ON e.id = ml.employee_id
WHERE ml.status = 'APPROVED' 
  AND ml.end_date > CURDATE()
  AND ml.end_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  AND NOT EXISTS (
      SELECT 1 FROM hr_notifications hn 
      WHERE hn.notification_type = 'MEDICAL_LEAVE_ENDING' 
        AND hn.employee_id = ml.employee_id
        AND DATE(hn.notification_date) = DATE_SUB(ml.end_date, INTERVAL 2 DAY)
  );

-- Crear vista para reportes de licencias médicas
CREATE OR REPLACE VIEW `vw_medical_leaves_report` AS
SELECT 
    ml.id,
    ml.employee_id,
    e.employee_code,
    CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
    e.position,
    d.name AS department_name,
    ml.leave_type,
    ml.diagnosis,
    ml.start_date,
    ml.end_date,
    ml.total_days,
    ml.is_paid,
    ml.payment_percentage,
    ml.status,
    ml.is_work_related,
    ml.medical_center,
    ml.doctor_name,
    CONCAT(reviewer.username) AS reviewed_by_username,
    ml.reviewed_at,
    ml.created_at,
    CASE 
        WHEN ml.actual_return_date IS NOT NULL THEN ml.actual_return_date
        WHEN ml.expected_return_date IS NOT NULL THEN ml.expected_return_date
        ELSE ml.end_date
    END AS return_date,
    DATEDIFF(ml.end_date, ml.start_date) + 1 AS calculated_days
FROM medical_leaves ml
JOIN employees e ON e.id = ml.employee_id
LEFT JOIN departments d ON d.id = e.department_id
LEFT JOIN users reviewer ON reviewer.id = ml.reviewed_by;

-- Crear índices adicionales para optimización
CREATE INDEX idx_medical_leaves_start_date ON medical_leaves (start_date);
CREATE INDEX idx_medical_leaves_active ON medical_leaves (status, end_date);

-- Comentarios finales
-- Este módulo permite gestionar:
-- 1. Licencias médicas de todo tipo (enfermedad, maternidad, paternidad, accidentes)
-- 2. Extensiones de licencias
-- 3. Seguimientos médicos
-- 4. Estadísticas de salud por empleado
-- 5. Integración con ARS (Administradora de Riesgos de Salud)
-- 6. Notificaciones automáticas

SELECT 'Medical Leaves Module Migration Completed Successfully!' AS Status;
