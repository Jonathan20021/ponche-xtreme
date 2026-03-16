-- Migration: Add AI Recruitment Analysis Permission
-- Description: Adds permission for AI-powered recruitment analysis module

-- Insert permission into section_permissions for Admin, HR, and IT roles
INSERT INTO section_permissions (section_key, role) VALUES
('hr_recruitment_ai', 'Admin'),
('hr_recruitment_ai', 'HR'),
('hr_recruitment_ai', 'IT')
ON DUPLICATE KEY UPDATE section_key = VALUES(section_key);

-- Confirm insertion
SELECT * FROM section_permissions WHERE section_key = 'hr_recruitment_ai';
