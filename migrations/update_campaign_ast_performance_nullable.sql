-- =====================================================
-- Hacer campaign_id NULLABLE en campaign_ast_performance
-- =====================================================
-- Los reportes AST Team Performance ya traen sus propios
-- equipos/campañas. No necesitamos FK a campaigns.
-- =====================================================

-- Primero eliminar la constraint de FK si existe
ALTER TABLE `campaign_ast_performance` 
DROP FOREIGN KEY IF EXISTS `fk_ast_perf_campaign`;

-- Modificar campaign_id para que sea NULL
ALTER TABLE `campaign_ast_performance` 
MODIFY COLUMN `campaign_id` INT(10) UNSIGNED NULL DEFAULT NULL;

-- Actualizar unique key para usar team_id en lugar de campaign_id
ALTER TABLE `campaign_ast_performance`
DROP INDEX IF EXISTS `uq_ast_agent`;

ALTER TABLE `campaign_ast_performance`
ADD UNIQUE KEY `uq_ast_agent` (`team_id`, `report_date`, `agent_id`);

-- Agregar índices para búsquedas por team
ALTER TABLE `campaign_ast_performance`
ADD INDEX `idx_team_date` (`team_id`, `report_date`);

ALTER TABLE `campaign_ast_performance`
ADD INDEX `idx_team_name` (`team_name`);
