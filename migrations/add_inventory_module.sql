-- =====================================================
-- Inventory Module - Tables and Seed Data
-- =====================================================

USE `ponche`;

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- Table: inventory_categories
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_categories_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: inventory_item_types
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_item_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_item_types_name_unique` (`name`, `category_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `fk_inventory_category` FOREIGN KEY (`category_id`) REFERENCES `inventory_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: employee_inventory
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `employee_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `item_type_id` int(11) NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci COMMENT 'Serial number, size, specific details',
  `uuid` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Asset Tag or Unique Identifier',
  `status` enum('ASSIGNED','RETURNED','LOST','DAMAGED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ASSIGNED',
  `assigned_date` date NOT NULL,
  `returned_date` date DEFAULT NULL,
  `assigned_by` int(10) UNSIGNED DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `item_type_id` (`item_type_id`),
  KEY `idx_employee_inventory_status` (`status`),
  KEY `idx_employee_inventory_assigned_date` (`assigned_date`),
  KEY `idx_employee_inventory_uuid` (`uuid`),
  CONSTRAINT `fk_inventory_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventory_item_type` FOREIGN KEY (`item_type_id`) REFERENCES `inventory_item_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventory_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------
-- Seed Categories (idempotent)
-- -----------------------------------------------------
INSERT INTO `inventory_categories` (`name`)
SELECT v.name
FROM (
  SELECT 'Administrativo' AS name UNION ALL
  SELECT 'Tecnología' UNION ALL
  SELECT 'Uniforme'
) v
WHERE NOT EXISTS (
  SELECT 1 FROM `inventory_categories` c WHERE c.name = v.name
);

-- -----------------------------------------------------
-- Seed Item Types (idempotent)
-- -----------------------------------------------------
INSERT INTO `inventory_item_types` (`category_id`, `name`, `description`)
SELECT c.id, v.name, v.description
FROM (
  SELECT 'Uniforme' AS category, 'Camiseta' AS name, 'Camiseta oficial de la empresa' AS description UNION ALL
  SELECT 'Administrativo', 'Carnet', 'Carnet de identificación' UNION ALL
  SELECT 'Administrativo', 'Llaves de acceso', 'Llaves de la oficina/puerta principal' UNION ALL
  SELECT 'Tecnología', 'Computadora', 'Laptop o Desktop asignada' UNION ALL
  SELECT 'Tecnología', 'Mouse', 'Mouse USB o Inalámbrico' UNION ALL
  SELECT 'Tecnología', 'Teclado', 'Teclado USB o Inalámbrico' UNION ALL
  SELECT 'Tecnología', 'Headset', 'Auriculares con micrófono' UNION ALL
  SELECT 'Tecnología', 'UPS', 'Unidad de respaldo de energía'
) v
JOIN `inventory_categories` c ON c.name = v.category
WHERE NOT EXISTS (
  SELECT 1 FROM `inventory_item_types` it
  WHERE it.name = v.name AND it.category_id = c.id
);
