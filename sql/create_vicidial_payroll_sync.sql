-- =====================================================================
-- Vicidial Payroll Sync (Fase 1 - MODO SOMBRA)
-- Creado: 2026-07-03
--
-- Objetivo: importar automáticamente por la API de Vicidial los datos de
-- login/logout y actividad de cada agente para compararlos (conciliación)
-- contra la marcación manual de ponche (tabla `attendance`) SIN tocar la
-- nómina todavía. Si la comparación es sólida durante ~2 semanas, la Fase 2
-- reemplazará la marcación web de los agentes de call center.
--
-- No modifica ni reutiliza `vicidial_login_stats` (que se llena por carga
-- manual de CSV) para no interferir con los reportes existentes. Estas tablas
-- son independientes y se pueden borrar sin afectar nada si la Fase 1 se
-- descarta.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Mapeo Vicidial username  ->  usuario de ponche (users.id)
-- Los usernames NO coinciden 1:1 (ej. Vicidial "sadelyngi" vs ponche
-- "sadelyn.evallish"), por eso hace falta un mapeo explícito. El importador
-- lo auto-siembra por coincidencia de nombre completo y el admin lo corrige
-- desde la UI de conciliación.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vicidial_user_map` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `vicidial_user` VARCHAR(100) NOT NULL COMMENT 'ID/username del agente en Vicidial (columna ID del reporte)',
  `vicidial_name` VARCHAR(150) DEFAULT NULL COMMENT 'Nombre mostrado en Vicidial (USER NAME)',
  `user_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'users.id de ponche. NULL = sin mapear',
  `auto_matched` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = sembrado auto por coincidencia de nombre. 0 = confirmado a mano',
  `ignore_agent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = agente Vicidial que NO se concilia (bots, pruebas, IT)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vicidial_user` (`vicidial_user`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mapeo agente Vicidial -> usuario ponche (Fase 1 sync nómina)';

-- ---------------------------------------------------------------------
-- Hoja de tiempo diaria por agente (dato central de la nómina)
-- first_login / last_activity se guardan YA CONVERTIDOS a hora local de RD
-- (America/Santo_Domingo, -04:00). Vicidial reporta en su hora local (-05:00),
-- por eso el importador les suma `tz_offset_applied_minutes` (config, default 60)
-- para poder compararlos directamente con `attendance.timestamp`, que está en
-- hora local de RD. Se guarda el offset aplicado para auditoría.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vicidial_agent_timesheet` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `report_date` DATE NOT NULL COMMENT 'Día del dato (hora local RD)',
  `vicidial_user` VARCHAR(100) NOT NULL COMMENT 'ID del agente en Vicidial',
  `vicidial_name` VARCHAR(150) DEFAULT NULL,
  `user_group` VARCHAR(100) DEFAULT NULL COMMENT 'Grupo/campaña actual en Vicidial',
  `user_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'users.id resuelto vía vicidial_user_map (snapshot al importar)',

  -- Ventana de jornada (hora local RD, ya con offset aplicado)
  `first_login` DATETIME DEFAULT NULL,
  `last_activity` DATETIME DEFAULT NULL,
  `total_logged_seconds` INT(11) NOT NULL DEFAULT 0 COMMENT 'TOTAL LOGGED-IN TIME (suma de sesiones)',
  `nonpause_seconds` INT(11) NOT NULL DEFAULT 0 COMMENT 'NONPAUSE (tiempo trabajando) del pause code breakdown',
  `pause_breakdown` TEXT DEFAULT NULL COMMENT 'JSON codigo=>segundos por cada codigo de pausa (Bao, Break, Coachi, ...)',

  -- Desglose de actividad (segundos) del Agent Performance Detail
  `calls` INT(11) NOT NULL DEFAULT 0,
  `talk_seconds` INT(11) NOT NULL DEFAULT 0,
  `pause_seconds` INT(11) NOT NULL DEFAULT 0,
  `wait_seconds` INT(11) NOT NULL DEFAULT 0,
  `dispo_seconds` INT(11) NOT NULL DEFAULT 0,

  -- Auditoría
  `tz_offset_applied_minutes` INT(11) NOT NULL DEFAULT 0 COMMENT 'Minutos sumados a los timestamps de Vicidial para llevarlos a hora RD',
  `raw_first_login` DATETIME DEFAULT NULL COMMENT 'FIRST LOGIN crudo de Vicidial (su hora local -05:00), sin convertir',
  `raw_last_activity` DATETIME DEFAULT NULL,
  `source` VARCHAR(20) NOT NULL DEFAULT 'api' COMMENT 'api | manual',
  `imported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_date_user` (`report_date`, `vicidial_user`),
  KEY `idx_report_date` (`report_date`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_vicidial_user` (`vicidial_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hoja de tiempo diaria de agentes Vicidial (Fase 1 sync nómina)';

-- ---------------------------------------------------------------------
-- Bitácora de cada corrida del importador (para diagnóstico/monitoreo)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vicidial_sync_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `target_date` DATE NOT NULL COMMENT 'Día que se intentó importar',
  `status` VARCHAR(20) NOT NULL DEFAULT 'ok' COMMENT 'ok | partial | error | skipped',
  `agents_in_report` INT(11) NOT NULL DEFAULT 0,
  `timesheets_fetched` INT(11) NOT NULL DEFAULT 0,
  `rows_upserted` INT(11) NOT NULL DEFAULT 0,
  `new_mappings` INT(11) NOT NULL DEFAULT 0 COMMENT 'Mapeos nuevos auto-sembrados en esta corrida',
  `duration_ms` INT(11) NOT NULL DEFAULT 0,
  `triggered_by` VARCHAR(20) NOT NULL DEFAULT 'cron' COMMENT 'cron | manual | cli',
  `message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_target_date` (`target_date`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bitácora de corridas del importador Vicidial (Fase 1 sync nómina)';
