-- Service Level Calculator - Database Schema
-- Tabla opcional para guardar histórico de cálculos

CREATE TABLE IF NOT EXISTS service_level_calculations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    
    -- Parámetros de entrada
    interval_minutes INT NOT NULL COMMENT 'Duración del intervalo (15, 30, 60 min)',
    offered_calls INT NOT NULL COMMENT 'Número de llamadas esperadas',
    aht_seconds INT NOT NULL COMMENT 'Average Handling Time en segundos',
    target_sl DECIMAL(5,2) NOT NULL COMMENT 'Service Level objetivo en decimal (ej: 0.80)',
    target_answer_seconds INT NOT NULL COMMENT 'Tiempo objetivo de respuesta en segundos',
    occupancy_target DECIMAL(5,2) DEFAULT 0.85 COMMENT 'Ocupación objetivo (ej: 0.85)',
    shrinkage DECIMAL(5,2) DEFAULT 0.30 COMMENT 'Shrinkage en decimal (ej: 0.30)',
    
    -- Resultados del cálculo
    required_agents INT NOT NULL COMMENT 'Agentes requeridos para cumplir SL',
    required_staff INT NOT NULL COMMENT 'Staff total incluyendo shrinkage',
    calculated_sl DECIMAL(6,4) NOT NULL COMMENT 'Service Level calculado',
    calculated_occupancy DECIMAL(6,4) NOT NULL COMMENT 'Ocupación calculada',
    workload_erlangs DECIMAL(10,4) NOT NULL COMMENT 'Carga de trabajo en Erlangs',
    
    -- Metadata
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT COMMENT 'Notas opcionales del usuario',
    
    INDEX idx_user_date (user_id, created_at),
    INDEX idx_created (created_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Histórico de cálculos de dimensionamiento de agentes';

-- Ejemplos de consultas útiles:

-- Ver últimos 10 cálculos de un usuario
-- SELECT * FROM service_level_calculations 
-- WHERE user_id = ? 
-- ORDER BY created_at DESC 
-- LIMIT 10;

-- Calcular promedios por usuario
-- SELECT 
--     user_id,
--     COUNT(*) as total_calculations,
--     AVG(required_agents) as avg_agents,
--     AVG(calculated_sl) as avg_sl,
--     AVG(calculated_occupancy) as avg_occupancy
-- FROM service_level_calculations
-- GROUP BY user_id;

-- Análisis de tendencias
-- SELECT 
--     DATE(created_at) as date,
--     AVG(required_agents) as avg_agents,
--     AVG(workload_erlangs) as avg_erlangs,
--     COUNT(*) as calculations_count
-- FROM service_level_calculations
-- WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
-- GROUP BY DATE(created_at)
-- ORDER BY date DESC;
