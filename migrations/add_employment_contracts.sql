-- Migration: Add employment contracts table
-- Description: Creates table to store generated employment contracts

CREATE TABLE IF NOT EXISTS employment_contracts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NULL,
    employee_name VARCHAR(255) NOT NULL,
    id_card VARCHAR(50) NOT NULL,
    province VARCHAR(100) NOT NULL,
    contract_date DATE NOT NULL,
    salary DECIMAL(10, 2) NOT NULL,
    work_schedule VARCHAR(255) NOT NULL DEFAULT '44 horas semanales',
    city VARCHAR(100) NOT NULL DEFAULT 'Ciudad de Santiago',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_employee_id (employee_id),
    INDEX idx_contract_date (contract_date),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
