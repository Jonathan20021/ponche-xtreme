-- Grabaciones de llamadas de Vicidial por agente (metadato; el audio se transmite
-- bajo demanda vía proxy, no se almacena localmente).
CREATE TABLE IF NOT EXISTS `vicidial_recordings` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recording_id`   INT UNSIGNED NULL,                 -- recording_log.recording_id de Vicidial
  `user_id`        INT UNSIGNED NULL,                 -- users.id nuestro (mapeado vía vicidial_user_map)
  `vicidial_user`  VARCHAR(60) NOT NULL,              -- usuario del agente en Vicidial
  `lead_id`        INT UNSIGNED NULL,
  `call_datetime`  DATETIME NULL,
  `call_date`      DATE NULL,                         -- para indexar/filtrar por día
  `length_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `filename`       VARCHAR(255) NOT NULL,             -- ej. 20260707-094314_7878220258-all.mp3
  `customer_phone` VARCHAR(40) NULL,                  -- extraído del filename
  `imported_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_filename` (`filename`),              -- idempotencia (el filename es único por grabación)
  KEY `idx_user_date` (`user_id`, `call_date`),
  KEY `idx_vuser_date` (`vicidial_user`, `call_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
