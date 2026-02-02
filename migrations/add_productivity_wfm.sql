-- -----------------------------------------------------
-- Productivity (KPIs, Goals, Coaching, Gamification)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `kpi_goals` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope_type` ENUM('campaign', 'team', 'user') NOT NULL,
  `campaign_id` INT(10) UNSIGNED DEFAULT NULL,
  `supervisor_id` INT(10) UNSIGNED DEFAULT NULL,
  `user_id` INT(10) UNSIGNED DEFAULT NULL,
  `kpi_key` VARCHAR(60) NOT NULL,
  `target_value` DECIMAL(12,4) NOT NULL,
  `target_direction` ENUM('min', 'max', 'target') NOT NULL DEFAULT 'target',
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(10) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `kpi_scope` (`scope_type`, `campaign_id`, `supervisor_id`, `user_id`),
  KEY `kpi_date` (`start_date`, `end_date`),
  CONSTRAINT `fk_kpi_goal_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_kpi_goal_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_kpi_goal_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_kpi_goal_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coaching_sessions` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `supervisor_id` INT(10) UNSIGNED DEFAULT NULL,
  `campaign_id` INT(10) UNSIGNED DEFAULT NULL,
  `session_date` DATE NOT NULL,
  `topic` VARCHAR(160) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `action_items` TEXT DEFAULT NULL,
  `score` DECIMAL(5,2) DEFAULT NULL,
  `next_review_date` DATE DEFAULT NULL,
  `status` ENUM('open', 'closed') NOT NULL DEFAULT 'open',
  `created_by` INT(10) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `coaching_user` (`user_id`),
  KEY `coaching_supervisor` (`supervisor_id`),
  KEY `coaching_campaign` (`campaign_id`),
  CONSTRAINT `fk_coaching_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coaching_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coaching_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coaching_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gamification_points` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `campaign_id` INT(10) UNSIGNED DEFAULT NULL,
  `points` INT(11) NOT NULL,
  `reason` VARCHAR(200) DEFAULT NULL,
  `awarded_by` INT(10) UNSIGNED DEFAULT NULL,
  `awarded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `gamification_user` (`user_id`),
  KEY `gamification_campaign` (`campaign_id`),
  KEY `gamification_awarded_at` (`awarded_at`),
  CONSTRAINT `fk_gamification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gamification_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gamification_awarded_by` FOREIGN KEY (`awarded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- WFM Adherence Alerts
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `wfm_adherence_alerts` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `campaign_id` INT(10) UNSIGNED DEFAULT NULL,
  `alert_date` DATE NOT NULL,
  `alert_type` ENUM('late', 'absent', 'early_exit') NOT NULL,
  `expected_time` TIME DEFAULT NULL,
  `actual_time` TIME DEFAULT NULL,
  `severity` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `message` VARCHAR(255) NOT NULL,
  `status` ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME DEFAULT NULL,
  `resolved_by` INT(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_alert` (`user_id`, `alert_date`, `alert_type`),
  KEY `alert_campaign` (`campaign_id`),
  KEY `alert_date` (`alert_date`),
  CONSTRAINT `fk_alert_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alert_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_alert_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
