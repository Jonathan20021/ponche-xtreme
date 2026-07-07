-- Respuestas guardadas (macros) para el equipo de soporte.
CREATE TABLE IF NOT EXISTS `helpdesk_canned_responses` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(150) NOT NULL,
  `body`        TEXT NOT NULL,
  `category_id` INT DEFAULT NULL,
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `is_active`   TINYINT(1) DEFAULT 1,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
