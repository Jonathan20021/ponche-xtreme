-- Agregar columnas faltantes a chat_conversations para soporte de grupos

-- Verificar si las columnas existen antes de agregarlas
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'chat_conversations' 
               AND COLUMN_NAME = 'is_group');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE chat_conversations ADD COLUMN is_group TINYINT(1) DEFAULT 0 AFTER id',
    'SELECT "Column is_group already exists" AS message');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'chat_conversations' 
               AND COLUMN_NAME = 'group_name');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE chat_conversations ADD COLUMN group_name VARCHAR(255) DEFAULT NULL AFTER is_group',
    'SELECT "Column group_name already exists" AS message');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Mostrar la estructura actualizada
DESCRIBE chat_conversations;
