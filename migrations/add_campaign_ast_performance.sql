-- -----------------------------------------------------
-- Campaign AST Team Performance Detail
-- Stores agent-level metrics from Vicidial AST reports
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `campaign_ast_performance` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT(10) UNSIGNED NOT NULL,
  `report_date` DATE NOT NULL,
  `team_name` VARCHAR(100) NOT NULL DEFAULT '',
  `team_id` VARCHAR(50) NOT NULL DEFAULT '',
  `agent_name` VARCHAR(150) NOT NULL DEFAULT '',
  `agent_id` VARCHAR(100) NOT NULL DEFAULT '',
  `calls` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `leads` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `contacts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `contact_ratio` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `nonpause_time_sec` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `system_time_sec` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `talk_time_sec` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `sales` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `sales_per_working_hour` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `sales_to_leads_ratio` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `sales_to_contacts_ratio` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `sales_per_hour` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `incomplete_sales` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `cancelled_sales` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `callbacks` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `first_call_resolution` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `avg_sale_time_sec` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `avg_contact_time_sec` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `is_team_totals` TINYINT(1) NOT NULL DEFAULT 0,
  `source_filename` VARCHAR(255) DEFAULT NULL,
  `uploaded_by` INT(10) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ast_agent` (`campaign_id`, `report_date`, `team_name`, `agent_id`),
  KEY `idx_campaign_date` (`campaign_id`, `report_date`),
  KEY `idx_report_date` (`report_date`),
  CONSTRAINT `fk_ast_perf_campaign`
    FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ast_perf_user`
    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
