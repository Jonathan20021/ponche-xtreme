-- Verificación de empleo vía webhook (GHL AI Voice Agent / bancos)
-- Tabla de auditoría: cada consulta que llega al webhook queda registrada
-- (quién preguntó, qué cédula, si se encontró, si estaba activo).

CREATE TABLE IF NOT EXISTS `employment_verification_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queried_id_number` VARCHAR(50) NOT NULL COMMENT 'Cédula/documento tal como llegó en la consulta',
  `employee_id` INT UNSIGNED NULL COMMENT 'FK lógica a employees.id, NULL si no se encontró',
  `found` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NULL COMMENT 'NULL si no se encontró al colaborador',
  `caller_ip` VARCHAR(64) NULL,
  `source` VARCHAR(50) NOT NULL DEFAULT 'ghl_voice_ai',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_employment_verification_log_employee` (`employee_id`),
  KEY `idx_employment_verification_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `category`) VALUES
  ('employment_verification_enabled', '0', 'boolean', 'integrations'),
  ('employment_verification_api_key', '', 'text', 'integrations'),
  ('employment_verification_include_position', '1', 'boolean', 'integrations');
