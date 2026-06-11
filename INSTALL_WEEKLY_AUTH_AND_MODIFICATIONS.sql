-- =====================================================
-- Restricción de Modificaciones en Registros
-- + Código de Autorización Semanal Automático
-- Ponche Xtreme
-- =====================================================
-- Valores por defecto (INSERT IGNORE: no sobreescribe si ya existen).
-- Todo es editable desde Configuración > Códigos de Autorización.

-- Acceso a Modificaciones (editar/eliminar) en Registros
-- Por defecto: restringido a Marcela Rosario (ID 85) y Hugo Hidalgo (ID 16)
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `category`) VALUES
('records_modifications_restricted', '1', 'boolean', 'Restringir editar/eliminar registros solo a usuarios autorizados', 'authorization'),
('records_modifications_allowed_users', '85,16', 'text', 'IDs de usuarios con acceso a Modificaciones en Registros (separados por coma)', 'authorization');

-- Código de Autorización Semanal Automático
-- Por defecto: rotación los lunes 7:00 AM, enviado al correo de Marcela Rosario
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `category`) VALUES
('weekly_auth_rotation_enabled', '1', 'boolean', 'Habilitar rotación semanal automática de código de autorización', 'authorization'),
('weekly_auth_rotation_day', '1', 'text', 'Día de rotación del código semanal (1=Lunes ... 7=Domingo)', 'authorization'),
('weekly_auth_rotation_time', '07:00', 'text', 'Hora de rotación del código semanal (HH:MM)', 'authorization'),
('weekly_auth_rotation_recipients', 'mrosario@evallishbpo.com', 'text', 'Correos destinatarios del código semanal (separados por coma)', 'authorization'),
('weekly_auth_code_length', '8', 'text', 'Largo del código semanal generado (6-20 caracteres)', 'authorization'),
('weekly_auth_current_code_id', '0', 'number', 'ID interno del código semanal vigente (no editar manualmente)', 'authorization');

-- =====================================================
-- Cron requerido (cPanel > Cron Jobs) - Lunes 7:00 AM:
-- 0 7 * * 1 /usr/local/bin/php /home/USUARIO/public_html/cron_weekly_auth_code_rotation.php
-- O vía wget:
-- 0 7 * * 1 wget -q -O - "https://punch.evallishbpo.com/cron_weekly_auth_code_rotation.php?cron_key=ponche_xtreme_2025"
-- =====================================================
