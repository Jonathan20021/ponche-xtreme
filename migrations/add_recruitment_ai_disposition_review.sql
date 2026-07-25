-- =====================================================
-- Revisión de la disposición sugerida por la IA (Reclutamiento)
-- =====================================================
-- Antes: al recibir una postulación, si el score de la IA superaba el mínimo,
-- el sistema movía al candidato a "Preseleccionado" solo, sin que Reclutamiento
-- viera por qué. Ahora la IA PROPONE y Reclutamiento aprueba o descarta, con la
-- evaluación y la justificación a la vista (notificación en la campana).
--
-- El servidor es MySQL 5.7: NO soporta "ADD COLUMN IF NOT EXISTS", así que cada
-- cambio va detrás de una consulta a information_schema. Se puede correr varias
-- veces sin romper nada.
-- =====================================================

-- ai_proposed_status
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_applications'
                  AND COLUMN_NAME = 'ai_proposed_status');
SET @sql := IF(@exists = 0,
  "ALTER TABLE `job_applications` ADD COLUMN `ai_proposed_status` VARCHAR(30) DEFAULT NULL COMMENT 'disposicion que sugiere la IA (shortlisted/reviewing/rejected)' AFTER `ai_recommendation`",
  'SELECT "ai_proposed_status ya existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ai_proposal_state
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_applications'
                  AND COLUMN_NAME = 'ai_proposal_state');
SET @sql := IF(@exists = 0,
  "ALTER TABLE `job_applications` ADD COLUMN `ai_proposal_state` ENUM('PENDING','APPROVED','REJECTED','AUTO_APPLIED') DEFAULT NULL COMMENT 'estado de la revision humana de esa sugerencia' AFTER `ai_proposed_status`",
  'SELECT "ai_proposal_state ya existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ai_proposal_reason
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_applications'
                  AND COLUMN_NAME = 'ai_proposal_reason');
SET @sql := IF(@exists = 0,
  "ALTER TABLE `job_applications` ADD COLUMN `ai_proposal_reason` TEXT DEFAULT NULL COMMENT 'justificacion de la IA para la disposicion sugerida' AFTER `ai_proposal_state`",
  'SELECT "ai_proposal_reason ya existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ai_proposed_at
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_applications'
                  AND COLUMN_NAME = 'ai_proposed_at');
SET @sql := IF(@exists = 0,
  "ALTER TABLE `job_applications` ADD COLUMN `ai_proposed_at` DATETIME DEFAULT NULL AFTER `ai_proposal_reason`",
  'SELECT "ai_proposed_at ya existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ai_proposal_decided_by
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_applications'
                  AND COLUMN_NAME = 'ai_proposal_decided_by');
SET @sql := IF(@exists = 0,
  "ALTER TABLE `job_applications` ADD COLUMN `ai_proposal_decided_by` INT UNSIGNED DEFAULT NULL AFTER `ai_proposed_at`",
  'SELECT "ai_proposal_decided_by ya existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ai_proposal_decided_at
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_applications'
                  AND COLUMN_NAME = 'ai_proposal_decided_at');
SET @sql := IF(@exists = 0,
  "ALTER TABLE `job_applications` ADD COLUMN `ai_proposal_decided_at` DATETIME DEFAULT NULL AFTER `ai_proposal_decided_by`",
  'SELECT "ai_proposal_decided_at ya existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Índice para listar rápido lo que está pendiente de revisión
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_applications'
                  AND INDEX_NAME = 'idx_job_applications_ai_proposal');
SET @sql := IF(@exists = 0,
  "ALTER TABLE `job_applications` ADD INDEX `idx_job_applications_ai_proposal` (`ai_proposal_state`)",
  'SELECT "idx_job_applications_ai_proposal ya existe" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
