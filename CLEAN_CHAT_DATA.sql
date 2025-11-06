-- =====================================================
-- LIMPIAR TODOS LOS DATOS DEL CHAT
-- Este script elimina todos los datos del chat pero mantiene las tablas
-- Para empezar el chat desde cero sin perder la estructura
-- =====================================================

-- IMPORTANTE: Desactivar temporalmente las verificaciones de foreign keys
SET FOREIGN_KEY_CHECKS = 0;

-- Limpiar todas las tablas del chat (en orden para evitar conflictos)
-- Primero las tablas dependientes, luego las principales

-- 1. Tablas de estado y notificaciones
TRUNCATE TABLE chat_typing;
TRUNCATE TABLE chat_online_status;
TRUNCATE TABLE chat_notifications;
TRUNCATE TABLE chat_read_receipts;
TRUNCATE TABLE chat_reactions;

-- 2. Tablas de contenido
TRUNCATE TABLE chat_attachments;
TRUNCATE TABLE chat_scheduled_messages;

-- 3. Mensajes (dependen de conversaciones)
TRUNCATE TABLE chat_messages;

-- 4. Participantes (dependen de conversaciones)
TRUNCATE TABLE chat_participants;

-- 5. Conversaciones (tabla principal)
TRUNCATE TABLE chat_conversations;

-- 6. Estado de usuarios (mantiene la estructura pero limpia datos)
TRUNCATE TABLE chat_user_status;

-- NOTA: NO se limpian estas tablas porque contienen configuración:
-- - chat_permissions (permisos de usuarios)
-- - chat_settings (preferencias de usuarios)
-- Si quieres limpiarlas también, descomenta las siguientes líneas:

-- TRUNCATE TABLE chat_permissions;
-- TRUNCATE TABLE chat_settings;

-- Reactivar las verificaciones de foreign keys
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- VERIFICAR QUE TODO ESTÁ LIMPIO
-- =====================================================

-- Ejecuta estas consultas para verificar que todo está en 0:
SELECT 'chat_conversations' as tabla, COUNT(*) as registros FROM chat_conversations
UNION ALL
SELECT 'chat_participants', COUNT(*) FROM chat_participants
UNION ALL
SELECT 'chat_messages', COUNT(*) FROM chat_messages
UNION ALL
SELECT 'chat_attachments', COUNT(*) FROM chat_attachments
UNION ALL
SELECT 'chat_reactions', COUNT(*) FROM chat_reactions
UNION ALL
SELECT 'chat_read_receipts', COUNT(*) FROM chat_read_receipts
UNION ALL
SELECT 'chat_notifications', COUNT(*) FROM chat_notifications
UNION ALL
SELECT 'chat_typing', COUNT(*) FROM chat_typing
UNION ALL
SELECT 'chat_online_status', COUNT(*) FROM chat_online_status
UNION ALL
SELECT 'chat_scheduled_messages', COUNT(*) FROM chat_scheduled_messages
UNION ALL
SELECT 'chat_user_status', COUNT(*) FROM chat_user_status;

-- =====================================================
-- IMPORTANTE: LIMPIAR ARCHIVOS FÍSICOS
-- =====================================================

-- Después de ejecutar este SQL, también debes eliminar los archivos físicos
-- de la carpeta chat/uploads/
-- Puedes hacerlo manualmente o ejecutar este comando en PowerShell:
-- 
-- cd c:\xampp\htdocs\ponche-xtreme\chat\uploads
-- Remove-Item -Path .\documents\* -Force
-- Remove-Item -Path .\images\* -Force
-- Remove-Item -Path .\videos\* -Force
-- Remove-Item -Path .\audio\* -Force
-- Remove-Item -Path .\thumbnails\* -Force
--
-- O simplemente elimina el contenido de cada carpeta desde el explorador de Windows

-- =====================================================
-- ✓ Chat limpio y listo para usar desde cero
-- =====================================================
