-- Service Level Calculator System Migration
-- Adds table to store calculation history and user preferences
-- Date: 2026-03-13

-- Create service_level_calculations table if not exists
CREATE TABLE IF NOT EXISTS service_level_calculations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    
    -- Input parameters
    interval_minutes INT NOT NULL COMMENT 'Interval duration (15, 30, 60 min)',
    offered_calls INT NOT NULL COMMENT 'Expected number of calls',
    aht_seconds INT NOT NULL COMMENT 'Average Handling Time in seconds',
    target_sl DECIMAL(5,2) NOT NULL COMMENT 'Target Service Level in decimal (e.g. 0.80)',
    target_answer_seconds INT NOT NULL COMMENT 'Target answer time in seconds',
    occupancy_target DECIMAL(5,2) DEFAULT 0.85 COMMENT 'Target occupancy (e.g. 0.85)',
    shrinkage DECIMAL(5,2) DEFAULT 0.30 COMMENT 'Shrinkage in decimal (e.g. 0.30)',
    
    -- Calculated results
    required_agents INT NOT NULL COMMENT 'Required agents to meet SL',
    required_staff INT NOT NULL COMMENT 'Total staff including shrinkage',
    calculated_sl DECIMAL(6,4) NOT NULL COMMENT 'Calculated Service Level',
    calculated_occupancy DECIMAL(6,4) NOT NULL COMMENT 'Calculated occupancy',
    workload_erlangs DECIMAL(10,4) NOT NULL COMMENT 'Workload in Erlangs',
    
    -- Metadata
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT COMMENT 'Optional user notes',
    
    -- Indexes
    INDEX idx_user_date (user_id, created_at),
    INDEX idx_created (created_at),
    
    -- Foreign key
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Service Level Calculator - calculation history';

-- Check if table was created successfully
SELECT 'Service Level Calculator table created successfully' AS status;
