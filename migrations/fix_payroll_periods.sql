-- Script para corregir la tabla payroll_periods

-- Eliminar la tabla si existe
DROP TABLE IF EXISTS payroll_records;
DROP TABLE IF EXISTS payroll_periods;

-- Recrear la tabla payroll_periods con la estructura correcta
CREATE TABLE payroll_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    period_type ENUM('WEEKLY', 'BIWEEKLY', 'MONTHLY', 'CUSTOM') NOT NULL DEFAULT 'BIWEEKLY',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    payment_date DATE NOT NULL,
    status ENUM('DRAFT', 'CALCULATED', 'APPROVED', 'PAID', 'CLOSED') NOT NULL DEFAULT 'DRAFT',
    total_gross DECIMAL(12,2) DEFAULT 0.00,
    total_deductions DECIMAL(12,2) DEFAULT 0.00,
    total_net DECIMAL(12,2) DEFAULT 0.00,
    notes TEXT,
    created_by INT,
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created_by (created_by),
    INDEX idx_approved_by (approved_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recrear la tabla payroll_records
CREATE TABLE payroll_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id INT NOT NULL,
    employee_id INT NOT NULL,
    
    -- Ingresos
    base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    overtime_hours DECIMAL(6,2) DEFAULT 0.00,
    overtime_amount DECIMAL(10,2) DEFAULT 0.00,
    bonuses DECIMAL(10,2) DEFAULT 0.00,
    commissions DECIMAL(10,2) DEFAULT 0.00,
    other_income DECIMAL(10,2) DEFAULT 0.00,
    gross_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    
    -- Descuentos empleado
    afp_employee DECIMAL(10,2) DEFAULT 0.00,
    sfs_employee DECIMAL(10,2) DEFAULT 0.00,
    isr DECIMAL(10,2) DEFAULT 0.00,
    other_deductions DECIMAL(10,2) DEFAULT 0.00,
    total_deductions DECIMAL(10,2) DEFAULT 0.00,
    
    -- Aportes empleador
    afp_employer DECIMAL(10,2) DEFAULT 0.00,
    sfs_employer DECIMAL(10,2) DEFAULT 0.00,
    srl_employer DECIMAL(10,2) DEFAULT 0.00,
    infotep_employer DECIMAL(10,2) DEFAULT 0.00,
    total_employer_contributions DECIMAL(10,2) DEFAULT 0.00,
    
    -- Neto
    net_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    
    -- Horas trabajadas
    regular_hours DECIMAL(6,2) DEFAULT 0.00,
    total_hours DECIMAL(6,2) DEFAULT 0.00,
    
    -- Metadata
    notes TEXT,
    is_paid TINYINT(1) DEFAULT 0,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_payroll_period_id (payroll_period_id),
    INDEX idx_employee_id (employee_id),
    UNIQUE KEY unique_employee_period (payroll_period_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Crear índices adicionales
CREATE INDEX IF NOT EXISTS idx_payroll_period_dates ON payroll_periods(start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_payroll_period_status ON payroll_periods(status);
