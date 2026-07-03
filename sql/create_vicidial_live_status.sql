-- =====================================================================
-- Vicidial Live Status (Monitor en tiempo real - Fase 2)
-- Creado: 2026-07-03
--
-- Caché del estado EN VIVO de los agentes de Vicidial (desde
-- AST_timeonVDADall.php). El monitor del supervisor LEE esta tabla (rápido,
-- a prueba de fallos); un lazy-refresh con lock compartido la actualiza desde
-- Vicidial a lo sumo una vez por ventana de TTL, sin importar cuántos
-- supervisores estén viendo (evita el throttle).
--
-- La tabla se REEMPLAZA completa en cada refresco exitoso (es un snapshot).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vicidial_live_status` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'users.id resuelto por nombre. NULL = no mapeado',
  `vicidial_name` VARCHAR(150) DEFAULT NULL COMMENT 'Nombre mostrado en la grilla en vivo',
  `station` VARCHAR(60) DEFAULT NULL COMMENT 'Extension SIP',
  `session_id` VARCHAR(40) DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'Normalizado: EN_LLAMADA, PAUSADO, DISPONIBLE, DISPO, LLAMADA_MUERTA, LOGIN',
  `pause_code` VARCHAR(60) DEFAULT NULL COMMENT 'Motivo de pausa si aplica (Break, Lunch, ...)',
  `raw_status` VARCHAR(40) DEFAULT NULL COMMENT 'Estado crudo de Vicidial',
  `seconds_in_status` INT(11) NOT NULL DEFAULT 0,
  `campaign` VARCHAR(60) DEFAULT NULL,
  `calls` INT(11) NOT NULL DEFAULT 0,
  `inbound_calls` INT(11) NOT NULL DEFAULT 0,
  `snapshot_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Snapshot en vivo de agentes Vicidial (monitor supervisor)';

-- Metadatos de una sola fila (id=1): control de TTL/lock y contadores de resumen.
CREATE TABLE IF NOT EXISTS `vicidial_live_meta` (
  `id` TINYINT(1) NOT NULL DEFAULT 1,
  `last_attempt_at` DATETIME DEFAULT NULL COMMENT 'Último intento de refresco (éxito o fallo)',
  `last_success_at` DATETIME DEFAULT NULL COMMENT 'Último refresco EXITOSO (freshness real)',
  `source_ok` TINYINT(1) NOT NULL DEFAULT 0,
  `logged_in` INT(11) NOT NULL DEFAULT 0,
  `in_call` INT(11) NOT NULL DEFAULT 0,
  `paused` INT(11) NOT NULL DEFAULT 0,
  `waiting` INT(11) NOT NULL DEFAULT 0,
  `dispo` INT(11) NOT NULL DEFAULT 0,
  `message` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Metadatos del caché en vivo de Vicidial';

INSERT INTO `vicidial_live_meta` (`id`) VALUES (1)
  ON DUPLICATE KEY UPDATE `id` = `id`;
