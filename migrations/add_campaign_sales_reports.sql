-- -----------------------------------------------------
-- Campaign Sales Reports (Campaign Ops)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `campaign_sales_reports` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT(10) UNSIGNED NOT NULL,
  `report_date` DATE NOT NULL,
  `sales_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `revenue_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `volume` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
  `source_filename` VARCHAR(255) DEFAULT NULL,
  `uploaded_by` INT(10) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `campaign_date` (`campaign_id`, `report_date`),
  KEY `campaign_id` (`campaign_id`),
  KEY `report_date` (`report_date`),
  CONSTRAINT `fk_campaign_sales_reports_campaign`
    FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_campaign_sales_reports_user`
    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
