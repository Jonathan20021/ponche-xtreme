-- =====================================================
-- SISTEMA DE CHAT EN TIEMPO REAL
-- Instalación de base de datos
-- =====================================================

-- Tabla de conversaciones
CREATE TABLE IF NOT EXISTS chat_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NULL,
    type ENUM('direct', 'group', 'channel') DEFAULT 'direct',
    created_by INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_message_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_last_message (last_message_at),
    INDEX idx_created_by (created_by),
    INDEX idx_created_by_user (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de participantes en conversaciones
CREATE TABLE IF NOT EXISTS chat_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('admin', 'member') DEFAULT 'member',
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_read_at DATETIME NULL,
    is_active TINYINT(1) DEFAULT 1,
    is_muted TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_participant (conversation_id, user_id),
    INDEX idx_user (user_id),
    INDEX idx_conversation (conversation_id),
    INDEX idx_last_read (last_read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de mensajes
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    parent_message_id INT NULL,
    message_text TEXT NULL,
    message_type ENUM('text', 'file', 'image', 'video', 'audio', 'document', 'system') DEFAULT 'text',
    is_edited TINYINT(1) DEFAULT 0,
    is_deleted TINYINT(1) DEFAULT 0,
    edited_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_conversation (conversation_id),
    INDEX idx_user (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_parent (parent_message_id),
    FULLTEXT idx_message_text (message_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de archivos adjuntos
CREATE TABLE IF NOT EXISTS chat_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_original_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    file_size BIGINT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    thumbnail_path VARCHAR(500) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message (message_id),
    INDEX idx_file_type (file_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de reacciones a mensajes
CREATE TABLE IF NOT EXISTS chat_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    reaction VARCHAR(50) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reaction (message_id, user_id, reaction),
    INDEX idx_message (message_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de estado de lectura de mensajes
CREATE TABLE IF NOT EXISTS chat_read_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_read (message_id, user_id),
    INDEX idx_message (message_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de notificaciones de chat
CREATE TABLE IF NOT EXISTS chat_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    conversation_id INT NOT NULL,
    message_id INT NULL,
    notification_type ENUM('new_message', 'mention', 'reply', 'new_conversation') NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    INDEX idx_user (user_id),
    INDEX idx_conversation (conversation_id),
    INDEX idx_message (message_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de permisos de chat
CREATE TABLE IF NOT EXISTS chat_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    can_use_chat TINYINT(1) DEFAULT 1,
    can_create_groups TINYINT(1) DEFAULT 1,
    can_upload_files TINYINT(1) DEFAULT 1,
    max_file_size_mb INT DEFAULT 50,
    can_send_videos TINYINT(1) DEFAULT 1,
    can_send_documents TINYINT(1) DEFAULT 1,
    is_restricted TINYINT(1) DEFAULT 0,
    restriction_reason TEXT NULL,
    restricted_until DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    INDEX idx_can_use_chat (can_use_chat),
    INDEX idx_is_restricted (is_restricted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de estado de usuarios (online/offline/away)
CREATE TABLE IF NOT EXISTS chat_user_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    status ENUM('online', 'offline', 'away', 'busy') DEFAULT 'offline',
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    status_message VARCHAR(255) NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    INDEX idx_status (status),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de mensajes programados
CREATE TABLE IF NOT EXISTS chat_scheduled_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    message_text TEXT NOT NULL,
    scheduled_for DATETIME NOT NULL,
    is_sent TINYINT(1) DEFAULT 0,
    sent_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conversation (conversation_id),
    INDEX idx_user (user_id),
    INDEX idx_scheduled_for (scheduled_for),
    INDEX idx_is_sent (is_sent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar permiso de chat en section_permissions
INSERT IGNORE INTO section_permissions (section_key, role) 
VALUES 
    ('chat', 'Admin'),
    ('chat', 'Supervisor'),
    ('chat', 'AGENT'),
    ('chat', 'HR'),
    ('chat', 'Desarrollador'),
    ('chat', 'OperationsManager'),
    ('chat', 'IT'),
    ('chat', 'GeneralManager'),
    ('chat', 'QA'),
    ('chat_admin', 'Admin'),
    ('chat_admin', 'Supervisor'),
    ('chat_admin', 'Desarrollador');

-- Crear permisos de chat por defecto para todos los usuarios existentes
INSERT INTO chat_permissions (user_id, can_use_chat, can_create_groups, can_upload_files, max_file_size_mb, can_send_videos, can_send_documents)
SELECT 
    id,
    1,
    CASE WHEN role IN ('Admin', 'Supervisor', 'HR', 'Desarrollador', 'OperationsManager', 'GeneralManager') THEN 1 ELSE 0 END,
    1,
    CASE WHEN role IN ('Admin', 'Supervisor', 'Desarrollador', 'OperationsManager', 'GeneralManager') THEN 100 ELSE 50 END,
    1,
    1
FROM users
WHERE id NOT IN (SELECT user_id FROM chat_permissions);

-- Crear índices adicionales para optimizar consultas frecuentes
CREATE INDEX idx_conversation_last_message ON chat_conversations(id, last_message_at);
CREATE INDEX idx_message_conversation_created ON chat_messages(conversation_id, created_at DESC);
CREATE INDEX idx_participant_user_active ON chat_participants(user_id, is_active);

-- =====================================================
-- TRIGGERS PARA ACTUALIZAR LAST_MESSAGE_AT
-- =====================================================

DELIMITER $$

CREATE TRIGGER update_conversation_last_message_insert
AFTER INSERT ON chat_messages
FOR EACH ROW
BEGIN
    UPDATE chat_conversations
    SET last_message_at = NEW.created_at
    WHERE id = NEW.conversation_id;
END$$

CREATE TRIGGER update_conversation_last_message_update
AFTER UPDATE ON chat_messages
FOR EACH ROW
BEGIN
    IF NEW.is_deleted = 0 THEN
        UPDATE chat_conversations
        SET last_message_at = NEW.created_at
        WHERE id = NEW.conversation_id;
    END IF;
END$$

DELIMITER ;

-- =====================================================
-- FIN DE LA INSTALACIÓN
-- =====================================================
