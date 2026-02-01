-- -----------------------------------------------------
-- Campaign Staffing Forecast (Erlang C inputs)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `campaign_staffing_forecast` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT(10) UNSIGNED NOT NULL,
  `interval_start` DATETIME NOT NULL,
  `interval_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `offered_volume` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `aht_seconds` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `target_sl` DECIMAL(5,4) NOT NULL DEFAULT 0.8000,
  `target_answer_seconds` INT(10) UNSIGNED NOT NULL DEFAULT 20,
  `occupancy_target` DECIMAL(5,4) NOT NULL DEFAULT 0.8500,
  `shrinkage` DECIMAL(5,4) NOT NULL DEFAULT 0.3000,
  `channel` VARCHAR(50) DEFAULT NULL,
  `source_filename` VARCHAR(255) DEFAULT NULL,
  `uploaded_by` INT(10) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `campaign_interval` (`campaign_id`, `interval_start`),
  KEY `campaign_id` (`campaign_id`),
  KEY `interval_start` (`interval_start`),
  CONSTRAINT `fk_staffing_forecast_campaign`
    FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_staffing_forecast_user`
    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
