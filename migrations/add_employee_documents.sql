-- Migration: Add employee documents table for digital HR records
-- This allows uploading and managing all employee documentation

CREATE TABLE IF NOT EXISTS employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    file_extension VARCHAR(20) NOT NULL,
    description TEXT,
    uploaded_by INT NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_employee_id (employee_id),
    INDEX idx_document_type (document_type),
    INDEX idx_uploaded_at (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add permission for document management to section_permissions
INSERT INTO section_permissions (section_key, role) 
VALUES ('hr_employee_documents', 'HR')
ON DUPLICATE KEY UPDATE role = 'HR';

INSERT INTO section_permissions (section_key, role) 
VALUES ('hr_employee_documents', 'Admin')
ON DUPLICATE KEY UPDATE role = 'Admin';
