-- Migration: Add permissions for employee documents system
-- The table employee_documents already exists with all necessary columns

-- Add permission for document management to section_permissions
INSERT INTO section_permissions (section_key, role) 
VALUES ('hr_employee_documents', 'HR')
ON DUPLICATE KEY UPDATE role = 'HR';

INSERT INTO section_permissions (section_key, role) 
VALUES ('hr_employee_documents', 'Admin')
ON DUPLICATE KEY UPDATE role = 'Admin';

-- Add indexes (ignore errors if they already exist)
-- Check and add index for employee_id
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = DATABASE() AND table_name = 'employee_documents' AND index_name = 'idx_employee_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE employee_documents ADD INDEX idx_employee_id (employee_id)', 'SELECT "Index idx_employee_id already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add index for document_type
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = DATABASE() AND table_name = 'employee_documents' AND index_name = 'idx_document_type');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE employee_documents ADD INDEX idx_document_type (document_type)', 'SELECT "Index idx_document_type already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add index for uploaded_at
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = DATABASE() AND table_name = 'employee_documents' AND index_name = 'idx_uploaded_at');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE employee_documents ADD INDEX idx_uploaded_at (uploaded_at)', 'SELECT "Index idx_uploaded_at already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
