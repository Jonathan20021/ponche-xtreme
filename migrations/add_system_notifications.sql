-- =====================================================
-- Centro de Notificaciones del sistema (campana del header)
-- =====================================================
-- Notificaciones dentro del sistema de ponche, dirigidas a un usuario
-- concreto, a uno o varios roles, o a quien tenga permiso de una sección.
-- Usos actuales:
--   * RECRUITMENT_AI_DISPOSITION -> avisa a Reclutamiento la evaluación de la IA
--     y su justificación ANTES de aplicar la disposición al candidato.
--   * INVENTORY_LOW_STOCK / INVENTORY_OUT_OF_STOCK -> stock bajo o agotado.
-- Se puede correr varias veces sin romper nada.
-- =====================================================

CREATE TABLE IF NOT EXISTS `system_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notif_type` VARCHAR(60) NOT NULL COMMENT 'RECRUITMENT_AI_DISPOSITION, INVENTORY_LOW_STOCK, ...',
  `severity` ENUM('LOW','NORMAL','HIGH','CRITICAL') NOT NULL DEFAULT 'NORMAL',
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `url` VARCHAR(255) DEFAULT NULL COMMENT 'enlace relativo para actuar sobre la notificación',
  `payload_json` LONGTEXT DEFAULT NULL COMMENT 'datos estructurados (evaluación de IA, item de inventario...)',
  `target_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'usuario específico; NULL = por rol/permiso',
  `target_roles` VARCHAR(255) DEFAULT NULL COMMENT 'CSV de roles (FIND_IN_SET); NULL = cualquiera',
  `target_permission` VARCHAR(100) DEFAULT NULL COMMENT 'section_key: lo ven los roles con ese permiso',
  `dedupe_key` VARCHAR(190) DEFAULT NULL COMMENT 'evita duplicados del mismo evento',
  `requires_action` TINYINT(1) NOT NULL DEFAULT 0,
  `resolved_at` DATETIME DEFAULT NULL,
  `resolved_by` INT UNSIGNED DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_system_notifications_dedupe` (`dedupe_key`),
  KEY `idx_system_notifications_created` (`created_at`),
  KEY `idx_system_notifications_type` (`notif_type`),
  KEY `idx_system_notifications_user` (`target_user_id`),
  KEY `idx_system_notifications_pending` (`resolved_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lecturas por usuario: una misma notificación puede apuntar a varias personas
-- (por rol), así que el estado leído/no leído NO puede vivir en la fila anterior.
CREATE TABLE IF NOT EXISTS `system_notification_reads` (
  `notification_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`, `user_id`),
  KEY `idx_notification_reads_user` (`user_id`),
  CONSTRAINT `fk_notification_reads_notif`
    FOREIGN KEY (`notification_id`) REFERENCES `system_notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Ajustes configurables desde settings.php (política del proyecto:
-- nada de destinatarios ni umbrales hardcodeados)
-- -----------------------------------------------------
INSERT INTO `system_settings` (setting_key, setting_value, setting_type, category)
SELECT v.k, v.val, 'string', v.cat
FROM (
  -- Campana / centro de notificaciones
  SELECT 'notifications_enabled'                      AS k, '1'   AS val, 'notifications' AS cat UNION ALL
  SELECT 'notifications_poll_seconds',                     '90',        'notifications'   UNION ALL
  SELECT 'notifications_retention_days',                   '45',        'notifications'   UNION ALL

  -- Reclutamiento: revisión de la disposición sugerida por la IA
  SELECT 'recruitment_ai_require_approval',                '1',         'recruitment_ai'  UNION ALL
  SELECT 'recruitment_ai_notify_roles',                    'HR,Admin',  'recruitment_ai'  UNION ALL
  SELECT 'recruitment_ai_notify_user_ids',                 '',          'recruitment_ai'  UNION ALL
  SELECT 'recruitment_ai_notify_email',                    '0',         'recruitment_ai'  UNION ALL
  SELECT 'recruitment_ai_notify_email_recipients',         '',          'recruitment_ai'  UNION ALL

  -- Inventario: alertas de stock bajo / próximo a agotarse
  SELECT 'inventory_stock_alerts_enabled',                 '1',         'inventory'       UNION ALL
  SELECT 'inventory_stock_alert_roles',                    'HR,Admin,IT','inventory'      UNION ALL
  SELECT 'inventory_stock_alert_user_ids',                 '',          'inventory'       UNION ALL
  SELECT 'inventory_stock_alert_near_pct',                 '20',        'inventory'       UNION ALL
  SELECT 'inventory_stock_alert_cooldown_hours',           '24',        'inventory'       UNION ALL
  SELECT 'inventory_stock_alert_digest',                    '1',         'inventory'       UNION ALL
  SELECT 'inventory_stock_alert_email',                    '0',         'inventory'       UNION ALL
  SELECT 'inventory_stock_alert_email_recipients',         '',          'inventory'
) v
WHERE NOT EXISTS (
  SELECT 1 FROM `system_settings` s WHERE s.setting_key = v.k
);
