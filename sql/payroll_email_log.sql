-- Create table for logging payroll email sends
CREATE TABLE IF NOT EXISTS payroll_email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id INT NOT NULL,
    employee_id INT NOT NULL,
    email_address VARCHAR(255) NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_payroll_email (payroll_period_id, employee_id),
    INDEX idx_payroll_period (payroll_period_id),
    INDEX idx_employee (employee_id),
    INDEX idx_sent_at (sent_at)
);
