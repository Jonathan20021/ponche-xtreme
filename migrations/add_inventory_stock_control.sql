-- =====================================================
-- Inventory Module v2 - Stock Control + AI
-- =====================================================
-- Extends the existing inventory module to support:
--  * Stock quantities (current_stock, min/max, reorder)
--  * Movement ledger (entries, exits, adjustments, assignments)
--  * Suppliers, lots, expiration dates
--  * AI-related settings
-- Safe to run multiple times.
-- =====================================================

USE `ponche`;

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- Suppliers
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_suppliers_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Extend inventory_categories with icon and description
-- -----------------------------------------------------
ALTER TABLE `inventory_categories`
  ADD COLUMN IF NOT EXISTS `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'fa-box' AFTER `name`,
  ADD COLUMN IF NOT EXISTS `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `icon`,
  ADD COLUMN IF NOT EXISTS `color` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'slate' AFTER `description`;

-- -----------------------------------------------------
-- Extend inventory_item_types with stock control fields
-- -----------------------------------------------------
ALTER TABLE `inventory_item_types`
  ADD COLUMN IF NOT EXISTS `sku` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `description`,
  ADD COLUMN IF NOT EXISTS `unit` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unidad' AFTER `sku`,
  ADD COLUMN IF NOT EXISTS `is_consumable` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=consumible (papel, medicina); 0=asignable por unidad (laptop)' AFTER `unit`,
  ADD COLUMN IF NOT EXISTS `track_lots` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=rastrea lotes y vencimientos (botiquin, cocina)' AFTER `is_consumable`,
  ADD COLUMN IF NOT EXISTS `current_stock` decimal(12,2) NOT NULL DEFAULT '0.00' AFTER `track_lots`,
  ADD COLUMN IF NOT EXISTS `min_stock` decimal(12,2) NOT NULL DEFAULT '0.00' AFTER `current_stock`,
  ADD COLUMN IF NOT EXISTS `max_stock` decimal(12,2) DEFAULT NULL AFTER `min_stock`,
  ADD COLUMN IF NOT EXISTS `reorder_qty` decimal(12,2) DEFAULT NULL AFTER `max_stock`,
  ADD COLUMN IF NOT EXISTS `unit_cost` decimal(12,2) DEFAULT NULL AFTER `reorder_qty`,
  ADD COLUMN IF NOT EXISTS `supplier_id` int(11) DEFAULT NULL AFTER `unit_cost`,
  ADD COLUMN IF NOT EXISTS `location` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `supplier_id`,
  ADD COLUMN IF NOT EXISTS `is_active` tinyint(1) NOT NULL DEFAULT '1' AFTER `location`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

ALTER TABLE `inventory_item_types`
  ADD INDEX IF NOT EXISTS `idx_item_types_sku` (`sku`),
  ADD INDEX IF NOT EXISTS `idx_item_types_low_stock` (`current_stock`, `min_stock`),
  ADD INDEX IF NOT EXISTS `idx_item_types_supplier` (`supplier_id`);

-- FK supplier (best-effort, only adds if not already there)
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'inventory_item_types'
                     AND CONSTRAINT_NAME = 'fk_item_types_supplier');
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE `inventory_item_types` ADD CONSTRAINT `fk_item_types_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `inventory_suppliers`(`id`) ON DELETE SET NULL',
  'SELECT "supplier FK already exists" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------
-- Inventory Lots (only used when item_types.track_lots = 1)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_lots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_type_id` int(11) NOT NULL,
  `lot_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity_received` decimal(12,2) NOT NULL DEFAULT '0.00',
  `quantity_remaining` decimal(12,2) NOT NULL DEFAULT '0.00',
  `received_date` date NOT NULL,
  `expiration_date` date DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `unit_cost` decimal(12,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lots_item` (`item_type_id`),
  KEY `idx_lots_expiration` (`expiration_date`),
  KEY `idx_lots_remaining` (`quantity_remaining`),
  CONSTRAINT `fk_lots_item` FOREIGN KEY (`item_type_id`) REFERENCES `inventory_item_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lots_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `inventory_suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Movements ledger (source of truth)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_type_id` int(11) NOT NULL,
  `lot_id` int(11) DEFAULT NULL,
  `movement_type` enum('ENTRY','EXIT','ADJUSTMENT','ASSIGN','RETURN','LOSS','DAMAGE','TRANSFER') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(12,2) NOT NULL COMMENT 'positive for entries, negative for exits',
  `unit_cost` decimal(12,2) DEFAULT NULL,
  `reason` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'PO number, invoice, etc.',
  `employee_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'employee receiving (for ASSIGN/EXIT)',
  `assignment_id` int(11) DEFAULT NULL COMMENT 'link to employee_inventory if applicable',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `performed_by` int(10) UNSIGNED DEFAULT NULL,
  `performed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mov_item` (`item_type_id`),
  KEY `idx_mov_type` (`movement_type`),
  KEY `idx_mov_date` (`performed_at`),
  KEY `idx_mov_employee` (`employee_id`),
  KEY `idx_mov_lot` (`lot_id`),
  CONSTRAINT `fk_mov_item` FOREIGN KEY (`item_type_id`) REFERENCES `inventory_item_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mov_lot` FOREIGN KEY (`lot_id`) REFERENCES `inventory_lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mov_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mov_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `employee_inventory` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mov_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Link employee_inventory to its source movement (best-effort)
-- -----------------------------------------------------
ALTER TABLE `employee_inventory`
  ADD COLUMN IF NOT EXISTS `quantity` decimal(12,2) NOT NULL DEFAULT '1.00' AFTER `uuid`,
  ADD COLUMN IF NOT EXISTS `lot_id` int(11) DEFAULT NULL AFTER `quantity`;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'employee_inventory'
                     AND CONSTRAINT_NAME = 'fk_ei_lot');
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE `employee_inventory` ADD CONSTRAINT `fk_ei_lot` FOREIGN KEY (`lot_id`) REFERENCES `inventory_lots`(`id`) ON DELETE SET NULL',
  'SELECT "ei lot FK already exists" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------
-- AI conversation log (chat assistant history)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_ai_chats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `role` enum('user','assistant','system') COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokens_used` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_chat_user` (`user_id`),
  KEY `idx_ai_chat_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- AI predictions/anomalies cache
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_ai_insights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_type_id` int(11) DEFAULT NULL,
  `insight_type` enum('PREDICTION','ANOMALY','REORDER','SUGGESTION') COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('LOW','MEDIUM','HIGH','CRITICAL') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'LOW',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `payload_json` longtext COLLATE utf8mb4_unicode_ci,
  `acknowledged` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_insight_item` (`item_type_id`),
  KEY `idx_ai_insight_type` (`insight_type`),
  KEY `idx_ai_insight_ack` (`acknowledged`),
  CONSTRAINT `fk_ai_insight_item` FOREIGN KEY (`item_type_id`) REFERENCES `inventory_item_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------
-- Seed new categories from ticket TK-03-00261 (idempotent)
-- -----------------------------------------------------
INSERT INTO `inventory_categories` (`name`, `icon`, `description`, `color`)
SELECT v.name, v.icon, v.description, v.color
FROM (
  SELECT 'Papel desechable' AS name, 'fa-toilet-paper' AS icon, 'Papel toalla, servilletas, papel higienico' AS description, 'amber' AS color UNION ALL
  SELECT 'Materiales de oficina', 'fa-paperclip', 'Boligrafos, grapas, carpetas, papel bond', 'blue' UNION ALL
  SELECT 'Materiales gastables', 'fa-recycle', 'Suministros generales de consumo rapido', 'orange' UNION ALL
  SELECT 'Insumos de cocina', 'fa-utensils', 'Cafe, azucar, vasos, agua', 'yellow' UNION ALL
  SELECT 'Materiales de limpieza', 'fa-spray-can', 'Detergentes, escobas, paños, desinfectantes', 'emerald' UNION ALL
  SELECT 'Botiquin', 'fa-kit-medical', 'Medicamentos, vendas, alcohol, insumos primeros auxilios', 'red'
) v
WHERE NOT EXISTS (
  SELECT 1 FROM `inventory_categories` c WHERE c.name = v.name
);

-- Backfill icons for legacy categories
UPDATE `inventory_categories` SET `icon` = 'fa-id-badge', `color` = 'slate' WHERE `name` = 'Administrativo' AND (`icon` IS NULL OR `icon` = 'fa-box');
UPDATE `inventory_categories` SET `icon` = 'fa-laptop',   `color` = 'cyan'  WHERE `name` = 'Tecnología'     AND (`icon` IS NULL OR `icon` = 'fa-box');
UPDATE `inventory_categories` SET `icon` = 'fa-shirt',    `color` = 'purple' WHERE `name` = 'Uniforme'      AND (`icon` IS NULL OR `icon` = 'fa-box');

-- Tag medical/cocina/limpieza items as track_lots by default
UPDATE `inventory_item_types` it
  JOIN `inventory_categories` c ON c.id = it.category_id
  SET it.track_lots = 1
  WHERE c.name IN ('Botiquin', 'Insumos de cocina') AND it.track_lots = 0;

-- Tag legacy hardware (laptop, headset, etc) as non-consumable (assignable)
UPDATE `inventory_item_types` it
  JOIN `inventory_categories` c ON c.id = it.category_id
  SET it.is_consumable = 0
  WHERE c.name IN ('Tecnología', 'Uniforme', 'Administrativo')
    AND it.is_consumable = 1;

-- -----------------------------------------------------
-- Seed AI settings (idempotent)
-- -----------------------------------------------------
INSERT INTO `system_settings` (setting_key, setting_value, setting_type, category)
SELECT v.k, v.val, 'string', 'inventory_ai'
FROM (
  SELECT 'inventory_ai_enabled' AS k, '1' AS val UNION ALL
  SELECT 'inventory_ai_model', '' UNION ALL
  SELECT 'inventory_ai_chat_system_prompt',
    'Eres un asistente experto en gestion de inventario para una empresa de centro de llamadas (Punch). Respondes en espanol, conciso y profesional. Tienes acceso al estado actual del inventario y al historial de movimientos a traves del contexto que se te da en cada turno. Solo respondes en base a esos datos; si no tienes informacion suficiente, di que no lo sabes. Usa tablas markdown cuando enumeres varios items.' UNION ALL
  SELECT 'inventory_ai_categorize_prompt',
    'Eres un experto categorizador de inventario. Recibiras el nombre de un articulo nuevo y una lista de categorias disponibles con sus descripciones. Responde SOLO con un JSON valido sin texto adicional: {"category_id": <id>, "description": "<descripcion clara del articulo>", "unit": "<unidad sugerida (unidad, caja, litro, kg, paquete)>", "is_consumable": <1 o 0>, "track_lots": <1 o 0>, "min_stock": <numero sugerido>}. is_consumable=1 si se gasta al usarse (papel, medicina). track_lots=1 si tiene vencimiento (medicinas, alimentos).' UNION ALL
  SELECT 'inventory_ai_predict_prompt',
    'Eres un analista de consumo de inventario. Recibiras el historial de salidas de un articulo de los ultimos 90 dias. Tu trabajo es estimar cuando se acabara el stock actual y cuanto reordenar. Responde SOLO con JSON valido: {"days_until_stockout": <int>, "monthly_consumption_avg": <float>, "recommended_reorder_qty": <float>, "confidence": "<low|medium|high>", "reasoning": "<explicacion breve en espanol>"}' UNION ALL
  SELECT 'inventory_ai_anomaly_prompt',
    'Eres un detector de anomalias de consumo. Recibiras el historial de salidas semanales de un articulo. Identifica si el consumo reciente es anomalo (>2x el promedio, picos sospechosos, agotamiento repentino). Responde SOLO con JSON valido: {"anomaly_detected": <true|false>, "severity": "<low|medium|high|critical>", "explanation": "<en espanol>", "suggested_action": "<accion en espanol>"}' UNION ALL
  SELECT 'inventory_ai_low_stock_threshold_pct', '20' UNION ALL
  SELECT 'inventory_ai_max_chat_history', '20' UNION ALL
  SELECT 'inventory_ai_predict_days', '90' UNION ALL
  SELECT 'inventory_ai_auto_categorize_on_create', '1'
) v
WHERE NOT EXISTS (
  SELECT 1 FROM `system_settings` s WHERE s.setting_key = v.k
);
