-- =====================================================
-- Migration: Hourly Rate History System
-- Description: Adds support for tracking hourly rate changes by effective date
-- =====================================================

-- Create hourly_rate_history table
CREATE TABLE IF NOT EXISTS `hourly_rate_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `hourly_rate_usd` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `hourly_rate_dop` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `effective_date` DATE NOT NULL COMMENT 'Fecha desde la cual esta tarifa es valida',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED DEFAULT NULL COMMENT 'Usuario que registro el cambio',
  `notes` TEXT DEFAULT NULL COMMENT 'Notas sobre el cambio de tarifa',
  PRIMARY KEY (`id`),
  KEY `idx_user_effective_date` (`user_id`, `effective_date` DESC),
  KEY `idx_effective_date` (`effective_date`),
  CONSTRAINT `fk_rate_history_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Historial de cambios de tarifas por hora con fechas efectivas';

-- Populate initial rates from existing users
INSERT INTO `hourly_rate_history` (`user_id`, `hourly_rate_usd`, `hourly_rate_dop`, `effective_date`, `notes`)
SELECT 
    id,
    hourly_rate,
    hourly_rate_dop,
    DATE(created_at) as effective_date,
    'Tarifa inicial del sistema'
FROM users
WHERE NOT EXISTS (
    SELECT 1 FROM hourly_rate_history WHERE user_id = users.id
);

-- Create index for faster lookups
CREATE INDEX idx_user_date_lookup ON hourly_rate_history(user_id, effective_date DESC, id DESC);
