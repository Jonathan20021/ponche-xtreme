-- Migration: Add GoHighLevel communications reporting permission and config keys

INSERT INTO section_permissions (section_key, role) VALUES
('voice_ai_reports', 'Admin'),
('voice_ai_reports', 'IT')
ON DUPLICATE KEY UPDATE
    section_key = VALUES(section_key),
    role = VALUES(role);

INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('voice_ai_api_key', '', 'string', 'Private Integration Token para la integracion de GoHighLevel Communications y Voice AI'),
('voice_ai_location_id', '', 'string', 'Location ID requerido por los endpoints publicos de GoHighLevel'),
('voice_ai_timezone', 'America/La_Paz', 'string', 'Zona horaria IANA para filtrar llamadas e interacciones'),
('voice_ai_page_size', '50', 'number', 'Tamano de pagina usado al consultar call logs de Voice AI'),
('voice_ai_max_pages', '10', 'number', 'Cantidad maxima de paginas que se consultan por reporte de Voice AI'),
('voice_ai_interaction_page_size', '100', 'number', 'Tamano de pagina usado al exportar interacciones de Conversations'),
('voice_ai_interaction_max_pages', '200', 'number', 'Cantidad maxima de paginas consultadas para interacciones y llamadas inbox')
ON DUPLICATE KEY UPDATE
    description = VALUES(description);
