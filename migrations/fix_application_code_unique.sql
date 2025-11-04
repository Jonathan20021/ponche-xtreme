-- =====================================================
-- Migration: Fix application_code UNIQUE constraint
-- Date: 2025-11-03
-- Description: Remove UNIQUE constraint from application_code to allow
--              multiple applications (different positions) to share the same code
-- =====================================================

-- Drop the UNIQUE constraint on application_code
ALTER TABLE job_applications 
DROP INDEX application_code;

-- Keep the index for performance but without UNIQUE constraint
ALTER TABLE job_applications 
ADD INDEX idx_application_code (application_code);

-- Verify the change
SHOW INDEX FROM job_applications WHERE Key_name LIKE '%application_code%';
