-- Desglose de disposiciones/estados por agente-día para los Reportes Vicidial en
-- modo sync (conversiones). JSON: {"SALE":12,"PEDIDO":5,"NOCAL":30,"SILENC":8,...}.
-- Se llena desde el mismo reporte AST_agent_performance_detail.php (file_download=1)
-- que ya trae el sync. La app también la auto-crea en la próxima corrida del sync
-- vía ensureVicidialStatusBreakdownColumn(); esta migración es para despliegues
-- manuales / otras bases de datos.

ALTER TABLE `vicidial_agent_timesheet`
    ADD COLUMN `status_breakdown` TEXT NULL AFTER `pause_breakdown`;
