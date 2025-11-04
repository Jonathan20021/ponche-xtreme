-- Migration: Add system settings table for exchange rate and other configurations
-- Date: 2025-11-03

-- Create system_settings table
CREATE TABLE IF NOT EXISTS system_settings (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT(10) UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX idx_setting_key (setting_key),
    INDEX idx_updated_by (updated_by),
    CONSTRAINT fk_system_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default exchange rate (USD to DOP)
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) 
VALUES 
    ('exchange_rate_usd_to_dop', '58.50', 'number', 'Tasa de cambio de USD a DOP para cálculos de nómina y reportes'),
    ('exchange_rate_last_update', NOW(), 'string', 'Última actualización de la tasa de cambio')
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    description = VALUES(description);

-- Add permission for managing system settings
INSERT INTO section_permissions (section_key, role) 
VALUES 
    ('system_settings', 'Admin'),
    ('system_settings', 'HR')
ON DUPLICATE KEY UPDATE 
    section_key = VALUES(section_key),
    role = VALUES(role);
