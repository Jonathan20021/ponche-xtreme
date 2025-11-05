-- =====================================================
-- TABLA DE PERMISOS DEL CHAT
-- Ejecuta este SQL adicional en phpMyAdmin
-- =====================================================

-- Tabla: chat_permissions
-- Almacena los permisos específicos del chat para cada usuario
CREATE TABLE IF NOT EXISTS `chat_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `can_use_chat` tinyint(1) DEFAULT 1,
  `can_create_groups` tinyint(1) DEFAULT 1,
  `is_restricted` tinyint(1) DEFAULT 0,
  `restricted_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `can_use_chat` (`can_use_chat`),
  KEY `is_restricted` (`is_restricted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar permisos por defecto para el usuario actual (admin)
INSERT IGNORE INTO `chat_permissions` 
(`user_id`, `can_use_chat`, `can_create_groups`, `is_restricted`)
VALUES 
(1, 1, 1, 0);

-- =====================================================
-- ✓ Tabla de permisos creada con permisos completos
-- =====================================================
