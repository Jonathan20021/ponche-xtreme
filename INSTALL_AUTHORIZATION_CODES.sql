-- =====================================================
-- Sistema de Códigos de Autorización
-- Ponche Xtreme - Authorization Codes System
-- =====================================================

-- Tabla para almacenar códigos de autorización configurables por rol
CREATE TABLE IF NOT EXISTS `authorization_codes` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code_name` VARCHAR(100) NOT NULL COMMENT 'Nombre descriptivo del código (ej: Supervisor Turno A)',
  `code` VARCHAR(50) NOT NULL COMMENT 'Código de autorización',
  `role_type` VARCHAR(50) NOT NULL COMMENT 'Tipo de rol (supervisor, it, manager, hr, director, etc)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Estado del código (activo/inactivo)',
  `usage_context` VARCHAR(100) DEFAULT NULL COMMENT 'Contexto de uso (overtime, special_punch, edit_records, etc)',
  `valid_from` DATETIME DEFAULT NULL COMMENT 'Fecha desde cuando el código es válido',
  `valid_until` DATETIME DEFAULT NULL COMMENT 'Fecha hasta cuando el código es válido',
  `max_uses` INT DEFAULT NULL COMMENT 'Número máximo de usos permitidos (NULL = ilimitado)',
  `current_uses` INT NOT NULL DEFAULT 0 COMMENT 'Número de veces que se ha usado',
  `created_by` INT(10) UNSIGNED DEFAULT NULL COMMENT 'ID del usuario que creó el código',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_code` (`code`),
  INDEX `idx_role_type` (`role_type`),
  INDEX `idx_is_active` (`is_active`),
  INDEX `idx_usage_context` (`usage_context`),
  CONSTRAINT `fk_authorization_codes_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Códigos de autorización configurables para diversos contextos';

-- Tabla para registrar el uso de códigos de autorización
CREATE TABLE IF NOT EXISTS `authorization_code_logs` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `authorization_code_id` INT(10) UNSIGNED NOT NULL COMMENT 'ID del código utilizado',
  `user_id` INT(10) UNSIGNED NOT NULL COMMENT 'ID del usuario que usó el código',
  `usage_context` VARCHAR(100) NOT NULL COMMENT 'Contexto donde se usó el código',
  `reference_id` INT DEFAULT NULL COMMENT 'ID de referencia (ej: ID del punch, ID del registro editado)',
  `reference_table` VARCHAR(50) DEFAULT NULL COMMENT 'Tabla de referencia',
  `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'Dirección IP desde donde se usó',
  `user_agent` TEXT DEFAULT NULL COMMENT 'User agent del navegador',
  `additional_data` JSON DEFAULT NULL COMMENT 'Datos adicionales en formato JSON',
  `used_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_authorization_code_id` (`authorization_code_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_usage_context` (`usage_context`),
  INDEX `idx_used_at` (`used_at`),
  CONSTRAINT `fk_auth_logs_code` FOREIGN KEY (`authorization_code_id`) REFERENCES `authorization_codes`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_auth_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de uso de códigos de autorización';

-- Agregar campo a la tabla attendance para registrar si se usó código de autorización
-- Solo agregar si no existe
SET @dbname = DATABASE();
SET @tablename = 'attendance';
SET @columnname = 'authorization_code_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT ''Column authorization_code_id already exists in attendance table'' AS message;',
  'ALTER TABLE `attendance` 
   ADD COLUMN `authorization_code_id` INT(10) UNSIGNED DEFAULT NULL COMMENT ''ID del código de autorización usado (si aplica)'',
   ADD INDEX `idx_authorization_code` (`authorization_code_id`),
   ADD CONSTRAINT `fk_attendance_auth_code` FOREIGN KEY (`authorization_code_id`) REFERENCES `authorization_codes`(`id`) ON DELETE SET NULL;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Insertar códigos de ejemplo (puedes eliminarlos o modificarlos desde settings)
INSERT INTO `authorization_codes` (`code_name`, `code`, `role_type`, `is_active`, `usage_context`) VALUES
('Supervisor Principal', 'SUP2025', 'supervisor', 1, 'overtime'),
('IT Administrator', 'IT2025', 'it', 1, 'overtime'),
('Gerente General', 'MGR2025', 'manager', 1, 'overtime'),
('Director de Operaciones', 'DIR2025', 'director', 1, 'overtime'),
('Recursos Humanos', 'HR2025', 'hr', 1, 'overtime'),
('Código Universal', 'UNIVERSAL2025', 'universal', 1, 'overtime');

-- =====================================================
-- Configuración del sistema para habilitar/deshabilitar códigos
-- =====================================================

-- Verificar si existe la tabla system_settings
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `setting_type` VARCHAR(50) DEFAULT 'string' COMMENT 'string, int, boolean, json',
  `description` TEXT,
  `category` VARCHAR(50) DEFAULT 'general',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agregar columna category si no existe (compatible con tablas existentes)
SET @dbname = DATABASE();
SET @tablename = 'system_settings';
SET @columnname = 'category';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1;',
  'ALTER TABLE `system_settings` ADD COLUMN `category` VARCHAR(50) DEFAULT ''general'' AFTER `description`;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Insertar configuraciones del sistema de códigos de autorización
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `category`) VALUES
('authorization_codes_enabled', '1', 'boolean', 'Habilitar sistema de códigos de autorización', 'authorization'),
('authorization_require_for_overtime', '1', 'boolean', 'Requerir código de autorización para registrar horas extras', 'authorization'),
('authorization_require_for_special_punch', '0', 'boolean', 'Requerir código de autorización para tipos de punch especiales', 'authorization'),
('authorization_log_retention_days', '365', 'int', 'Días de retención de logs de códigos de autorización', 'authorization')
ON DUPLICATE KEY UPDATE 
  `setting_value` = VALUES(`setting_value`),
  `description` = VALUES(`description`);

-- =====================================================
-- Vista para facilitar consultas de códigos activos
-- =====================================================

CREATE OR REPLACE VIEW `v_active_authorization_codes` AS
SELECT 
  ac.id,
  ac.code_name,
  ac.code,
  ac.role_type,
  ac.usage_context,
  ac.valid_from,
  ac.valid_until,
  ac.max_uses,
  ac.current_uses,
  CASE 
    WHEN ac.max_uses IS NOT NULL AND ac.current_uses >= ac.max_uses THEN 0
    WHEN ac.valid_until IS NOT NULL AND ac.valid_until < NOW() THEN 0
    WHEN ac.valid_from IS NOT NULL AND ac.valid_from > NOW() THEN 0
    ELSE 1
  END AS is_currently_valid,
  u.full_name AS created_by_name,
  ac.created_at,
  ac.updated_at
FROM `authorization_codes` ac
LEFT JOIN `users` u ON ac.created_by = u.id
WHERE ac.is_active = 1;

-- =====================================================
-- Procedimiento almacenado para validar código
-- =====================================================

-- Eliminar el procedimiento si existe
DROP PROCEDURE IF EXISTS `sp_validate_authorization_code`;

DELIMITER //

CREATE PROCEDURE `sp_validate_authorization_code`(
  IN p_code VARCHAR(50),
  IN p_usage_context VARCHAR(100),
  OUT p_is_valid TINYINT(1),
  OUT p_code_id INT,
  OUT p_message VARCHAR(255)
)
BEGIN
  DECLARE v_is_active TINYINT(1);
  DECLARE v_valid_from DATETIME;
  DECLARE v_valid_until DATETIME;
  DECLARE v_max_uses INT;
  DECLARE v_current_uses INT;
  DECLARE v_context VARCHAR(100);
  
  -- Inicializar valores de salida
  SET p_is_valid = 0;
  SET p_code_id = NULL;
  SET p_message = 'Código inválido';
  
  -- Buscar el código
  SELECT 
    id, is_active, valid_from, valid_until, max_uses, current_uses, usage_context
  INTO 
    p_code_id, v_is_active, v_valid_from, v_valid_until, v_max_uses, v_current_uses, v_context
  FROM `authorization_codes`
  WHERE `code` = p_code
  LIMIT 1;
  
  -- Verificar si el código existe
  IF p_code_id IS NULL THEN
    SET p_message = 'Código no encontrado';
  -- Verificar si está activo
  ELSEIF v_is_active = 0 THEN
    SET p_message = 'Código inactivo';
  -- Verificar contexto de uso (si está especificado)
  ELSEIF v_context IS NOT NULL AND v_context != p_usage_context THEN
    SET p_message = CONCAT('Código no válido para este contexto. Requerido: ', v_context);
  -- Verificar fecha de inicio
  ELSEIF v_valid_from IS NOT NULL AND NOW() < v_valid_from THEN
    SET p_message = 'Código aún no es válido';
  -- Verificar fecha de expiración
  ELSEIF v_valid_until IS NOT NULL AND NOW() > v_valid_until THEN
    SET p_message = 'Código expirado';
  -- Verificar límite de usos
  ELSEIF v_max_uses IS NOT NULL AND v_current_uses >= v_max_uses THEN
    SET p_message = 'Código ha alcanzado el límite de usos';
  ELSE
    -- Código válido
    SET p_is_valid = 1;
    SET p_message = 'Código válido';
  END IF;
END //

DELIMITER ;

-- =====================================================
-- Función para registrar uso de código
-- =====================================================

-- Eliminar el procedimiento si existe
DROP PROCEDURE IF EXISTS `sp_log_authorization_code_usage`;

DELIMITER //

CREATE PROCEDURE `sp_log_authorization_code_usage`(
  IN p_code_id INT,
  IN p_user_id INT,
  IN p_usage_context VARCHAR(100),
  IN p_reference_id INT,
  IN p_reference_table VARCHAR(50),
  IN p_ip_address VARCHAR(45),
  IN p_additional_data JSON
)
BEGIN
  -- Registrar el uso
  INSERT INTO `authorization_code_logs` (
    authorization_code_id,
    user_id,
    usage_context,
    reference_id,
    reference_table,
    ip_address,
    additional_data
  ) VALUES (
    p_code_id,
    p_user_id,
    p_usage_context,
    p_reference_id,
    p_reference_table,
    p_ip_address,
    p_additional_data
  );
  
  -- Incrementar contador de usos
  UPDATE `authorization_codes`
  SET current_uses = current_uses + 1
  WHERE id = p_code_id;
END //

DELIMITER ;

-- =====================================================
-- Índices adicionales para optimización
-- =====================================================

ALTER TABLE `authorization_codes`
ADD INDEX `idx_active_context` (`is_active`, `usage_context`),
ADD INDEX `idx_valid_dates` (`valid_from`, `valid_until`);

-- =====================================================
-- Permisos - Agregar nuevo permiso para gestionar códigos
-- =====================================================

-- Esto se debe configurar también en tu sistema de permisos PHP
-- Los roles con acceso: admin, developer, hr_manager

-- =====================================================
-- Configuraciones adicionales para edit/delete records
-- =====================================================

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`, `category`) VALUES
('authorization_require_for_edit_records', '1', 'Requerir código de autorización para editar registros de asistencia', 'authorization_codes')
ON DUPLICATE KEY UPDATE 
  setting_value = VALUES(setting_value),
  description = VALUES(description),
  category = VALUES(category);

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`, `category`) VALUES
('authorization_require_for_delete_records', '1', 'Requerir código de autorización para eliminar registros de asistencia', 'authorization_codes')
ON DUPLICATE KEY UPDATE 
  setting_value = VALUES(setting_value),
  description = VALUES(description),
  category = VALUES(category);

-- =====================================================
-- Script completado exitosamente
-- =====================================================

SELECT 'Sistema de Códigos de Autorización instalado correctamente' AS message;
SELECT COUNT(*) AS total_codes FROM authorization_codes;
SELECT COUNT(*) AS total_logs FROM authorization_code_logs;
