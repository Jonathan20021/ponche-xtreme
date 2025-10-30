-- =====================================================
-- SISTEMA DE NÓMINA PARA REPÚBLICA DOMINICANA
-- Incluye: AFP, SFS, ISR, TSS, DGII
-- =====================================================

-- Tabla de configuración de descuentos legales
CREATE TABLE IF NOT EXISTS payroll_deduction_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE COMMENT 'AFP, SFS, ISR, etc',
    name VARCHAR(100) NOT NULL,
    description TEXT,
    type ENUM('PERCENTAGE', 'FIXED', 'PROGRESSIVE') NOT NULL DEFAULT 'PERCENTAGE',
    employee_percentage DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Porcentaje empleado',
    employer_percentage DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Porcentaje empleador',
    fixed_amount DECIMAL(10,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    is_mandatory TINYINT(1) DEFAULT 1 COMMENT 'Si es obligatorio por ley',
    applies_to_salary TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configuración de descuentos RD (2025)
INSERT INTO payroll_deduction_config (code, name, description, type, employee_percentage, employer_percentage, is_mandatory) VALUES
('AFP', 'Administradora de Fondos de Pensiones', 'Aporte obligatorio al sistema de pensiones', 'PERCENTAGE', 2.87, 7.10, 1),
('SFS', 'Seguro Familiar de Salud', 'Aporte obligatorio al seguro de salud', 'PERCENTAGE', 3.04, 7.09, 1),
('SRL', 'Seguro de Riesgos Laborales', 'Seguro de riesgos laborales (solo empleador)', 'PERCENTAGE', 0.00, 1.20, 1),
('INFOTEP', 'Instituto de Formación Técnico Profesional', 'Aporte para capacitación técnica', 'PERCENTAGE', 0.00, 1.00, 1),
('ISR', 'Impuesto Sobre la Renta', 'Retención de ISR según escala progresiva', 'PROGRESSIVE', 0.00, 0.00, 1)
ON DUPLICATE KEY UPDATE 
    name = VALUES(name),
    description = VALUES(description),
    employee_percentage = VALUES(employee_percentage),
    employer_percentage = VALUES(employer_percentage);

-- Tabla de escalas de ISR (Impuesto Sobre la Renta)
CREATE TABLE IF NOT EXISTS payroll_isr_scales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    min_amount DECIMAL(12,2) NOT NULL,
    max_amount DECIMAL(12,2) NULL COMMENT 'NULL = sin límite superior',
    base_tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    excess_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Porcentaje sobre excedente',
    year INT NOT NULL DEFAULT 2025,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Escala ISR República Dominicana 2025 (anual)
-- Primero eliminar escalas existentes del año 2025
DELETE FROM payroll_isr_scales WHERE year = 2025;

INSERT INTO payroll_isr_scales (min_amount, max_amount, base_tax, excess_rate, year) VALUES
(0.00, 416220.00, 0.00, 0.00, 2025),           -- Exento
(416220.01, 624329.00, 0.00, 15.00, 2025),     -- 15% sobre excedente
(624329.01, 867123.00, 31216.00, 20.00, 2025), -- 20% sobre excedente
(867123.01, NULL, 79775.00, 25.00, 2025);      -- 25% sobre excedente

-- Tabla de descuentos personalizados por empleado
CREATE TABLE IF NOT EXISTS employee_deductions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    deduction_config_id INT NULL COMMENT 'NULL si es descuento personalizado',
    name VARCHAR(100) NOT NULL,
    description TEXT,
    type ENUM('PERCENTAGE', 'FIXED') NOT NULL DEFAULT 'FIXED',
    amount DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    start_date DATE NULL,
    end_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee_id (employee_id),
    INDEX idx_deduction_config_id (deduction_config_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de períodos de nómina
CREATE TABLE IF NOT EXISTS payroll_periods (
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
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de nómina detallada por empleado
CREATE TABLE IF NOT EXISTS payroll_records (
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

-- Tabla de historial de cambios de salario
CREATE TABLE IF NOT EXISTS salary_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    old_salary DECIMAL(10,2) NOT NULL,
    new_salary DECIMAL(10,2) NOT NULL,
    change_type ENUM('INCREASE', 'DECREASE', 'ADJUSTMENT', 'PROMOTION') NOT NULL,
    effective_date DATE NOT NULL,
    reason TEXT,
    approved_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee_id (employee_id),
    INDEX idx_approved_by (approved_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Índices para optimización (solo si no existen)
CREATE INDEX IF NOT EXISTS idx_payroll_period_dates ON payroll_periods(start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_payroll_period_status ON payroll_periods(status);
CREATE INDEX IF NOT EXISTS idx_employee_deductions_active ON employee_deductions(is_active);
