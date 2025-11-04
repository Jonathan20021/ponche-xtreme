-- Migration: Add Activity Logs Permission
-- Description: Creates permission for viewing activity logs system
-- Date: 2025-11-03

-- Add the activity_logs permission for Admin role
INSERT INTO section_permissions (section_key, role) 
VALUES ('activity_logs', 'Admin')
ON DUPLICATE KEY UPDATE role = 'Admin';

-- Optionally grant to HR role as well
INSERT INTO section_permissions (section_key, role) 
VALUES ('activity_logs', 'HR')
ON DUPLICATE KEY UPDATE role = 'HR';
