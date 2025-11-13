-- =====================================================
-- Sistema de Campañas para Supervisores y Agentes
-- =====================================================
-- Fecha: 2025-11-12
-- Descripción: Permite asignar agentes a campañas específicas
--             y que supervisores solo vean agentes de sus campañas
-- =====================================================

-- Tabla de Campañas
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Código único de la campaña',
  `description` TEXT COLLATE utf8mb4_unicode_ci,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=activa, 0=inactiva',
  `color` VARCHAR(7) COLLATE utf8mb4_unicode_ci DEFAULT '#6366f1' COMMENT 'Color hex para identificación visual',
  `created_by` INT(10) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `is_active` (`is_active`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Campañas para organizar agentes y supervisores';

-- Tabla pivot: Supervisores asignados a Campañas
CREATE TABLE IF NOT EXISTS `supervisor_campaigns` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `supervisor_id` INT(10) UNSIGNED NOT NULL COMMENT 'ID del usuario supervisor',
  `campaign_id` INT(10) UNSIGNED NOT NULL COMMENT 'ID de la campaña',
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` INT(10) UNSIGNED DEFAULT NULL COMMENT 'Usuario que hizo la asignación',
  PRIMARY KEY (`id`),
  UNIQUE KEY `supervisor_campaign` (`supervisor_id`, `campaign_id`),
  KEY `supervisor_id` (`supervisor_id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `assigned_by` (`assigned_by`),
  CONSTRAINT `fk_supervisor_campaigns_user` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supervisor_campaigns_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Asignación de supervisores a campañas';

-- Agregar campo campaign_id a la tabla employees (solo si no existe)
-- Verificar primero si la columna existe
SET @dbname = DATABASE();
SET @tablename = 'employees';
SET @columnname = 'campaign_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `employees` 
   ADD COLUMN `campaign_id` INT(10) UNSIGNED DEFAULT NULL COMMENT "Campaña a la que pertenece el agente" AFTER `supervisor_id`,
   ADD KEY `campaign_id` (`campaign_id`),
   ADD CONSTRAINT `fk_employees_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Insertar algunas campañas de ejemplo (solo si no existen)
INSERT IGNORE INTO `campaigns` (`name`, `code`, `description`, `color`, `is_active`) VALUES
('Soporte Técnico', 'TECH-SUPPORT', 'Campaña de soporte técnico general', '#3b82f6', 1),
('Ventas', 'SALES', 'Campaña de ventas y atención al cliente', '#10b981', 1),
('Atención al Cliente', 'CUSTOMER-SERVICE', 'Servicio de atención al cliente', '#f59e0b', 1),
('Retención', 'RETENTION', 'Campaña de retención de clientes', '#8b5cf6', 1);

-- Crear permiso para gestión de campañas en section_permissions
INSERT INTO `section_permissions` (`section_key`, `role`)
VALUES ('manage_campaigns', 'Admin')
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`);

INSERT INTO `section_permissions` (`section_key`, `role`)
VALUES ('manage_campaigns', 'HR')
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`);

-- =====================================================
-- Nota: Después de ejecutar este script:
-- 1. Los empleados pueden ser asignados a campañas
-- 2. Los supervisores pueden ver solo agentes de sus campañas
-- 3. Un supervisor puede tener múltiples campañas
-- 4. Un agente solo puede estar en una campaña a la vez
-- =====================================================
