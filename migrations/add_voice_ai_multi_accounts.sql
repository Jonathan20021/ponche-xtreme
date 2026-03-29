-- Migration: Enable multi-account GoHighLevel communications integrations

CREATE TABLE IF NOT EXISTS voice_ai_integrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    integration_name VARCHAR(191) NOT NULL,
    api_key VARCHAR(255) NOT NULL DEFAULT '',
    location_id VARCHAR(100) NOT NULL DEFAULT '',
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/La_Paz',
    page_size INT NOT NULL DEFAULT 50,
    max_pages INT NOT NULL DEFAULT 10,
    interaction_page_size INT NOT NULL DEFAULT 100,
    interaction_max_pages INT NOT NULL DEFAULT 200,
    display_order INT NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL DEFAULT NULL,
    updated_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_voice_ai_integrations_enabled (is_enabled, display_order, integration_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('voice_ai_default_integration_id', '', 'number', 'Integracion GHL seleccionada por defecto para reporteria multicuenta')
ON DUPLICATE KEY UPDATE
    description = VALUES(description);
