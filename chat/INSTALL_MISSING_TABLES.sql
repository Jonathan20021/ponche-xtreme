-- =====================================================
-- TABLAS FALTANTES DEL SISTEMA DE CHAT
-- Ejecuta este SQL en phpMyAdmin de cPanel
-- VERSIÓN SIN FOREIGN KEYS (Compatible con cualquier estructura)
-- =====================================================

-- Tabla: chat_typing
-- Almacena el estado de "escribiendo..." de los usuarios
CREATE TABLE IF NOT EXISTS `chat_typing` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `is_typing` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversation_user` (`conversation_id`, `user_id`),
  KEY `conversation_id` (`conversation_id`),
  KEY `user_id` (`user_id`),
  KEY `is_typing` (`is_typing`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: chat_online_status
-- Almacena el estado de conexión (online/offline) de los usuarios
CREATE TABLE IF NOT EXISTS `chat_online_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `status` enum('online','away','offline') DEFAULT 'offline',
  `last_seen` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: chat_settings
-- Almacena las preferencias de chat de cada usuario
CREATE TABLE IF NOT EXISTS `chat_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `notifications_enabled` tinyint(1) DEFAULT 1,
  `sound_enabled` tinyint(1) DEFAULT 1,
  `show_online_status` tinyint(1) DEFAULT 1,
  `enter_to_send` tinyint(1) DEFAULT 1,
  `theme` enum('light','dark','auto') DEFAULT 'auto',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar configuración por defecto para el usuario actual (user_id = 1)
-- Si tienes más usuarios, ajusta los INSERT según sea necesario
INSERT IGNORE INTO `chat_settings` (`user_id`, `notifications_enabled`, `sound_enabled`, `show_online_status`, `enter_to_send`, `theme`)
VALUES (1, 1, 1, 1, 1, 'auto');

-- Insertar estado online/offline para el usuario actual
INSERT IGNORE INTO `chat_online_status` (`user_id`, `status`, `last_seen`)
VALUES (1, 'offline', CURRENT_TIMESTAMP);

-- =====================================================
-- ✓ Ahora todas las tablas del chat están completas
-- =====================================================
