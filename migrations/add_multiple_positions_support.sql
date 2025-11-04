-- =====================================================
-- Migration: Add support for multiple job position applications with single CV
-- Date: 2025-11-03
-- Description: This migration documents the feature that allows users to apply to multiple
--              job positions using the same CV and personal information from a single form submission.
--              All applications share the same application_code for easy tracking.
-- =====================================================

-- =====================================================
-- NOTE: No database schema changes are required for this feature.
-- The existing job_applications table already supports this functionality by allowing
-- multiple records with the same application_code but different job_posting_id values.
-- =====================================================

-- =====================================================
-- FEATURE IMPLEMENTATION NOTES
-- =====================================================
-- 1. Users can select additional positions via checkboxes in the application form (careers.php)
-- 2. All selected positions share the same CV file (cv_filename, cv_path)
-- 3. All applications use the same application_code for unified tracking
-- 4. Each position creates a separate record in job_applications table
-- 5. Each application gets its own status tracking in application_status_history
-- 6. Users can track all their applications using a single application_code
-- 7. HR can view all related applications in view_application.php

-- =====================================================
-- BENEFITS
-- =====================================================
-- - Reduces duplicate CV uploads
-- - Improves user experience (one form for multiple positions)
-- - Maintains data integrity (separate records per position)
-- - Simplifies tracking (one code for all related applications)
-- - HR can easily see all positions a candidate applied to

-- =====================================================
-- USEFUL QUERIES
-- =====================================================

-- View all applications submitted together (same application_code)
SELECT 
    ja.id,
    ja.application_code,
    ja.first_name,
    ja.last_name,
    ja.email,
    jp.title as position_title,
    jp.department,
    jp.location,
    ja.status,
    ja.applied_date
FROM job_applications ja
LEFT JOIN job_postings jp ON ja.job_posting_id = jp.id
WHERE ja.application_code = 'APP-XXXXXXXX-2025'
ORDER BY ja.applied_date;

-- Count how many positions each applicant applied to
SELECT 
    application_code,
    CONCAT(first_name, ' ', last_name) as applicant_name,
    email,
    COUNT(*) as positions_applied,
    MIN(applied_date) as first_application_date,
    GROUP_CONCAT(DISTINCT status) as all_statuses
FROM job_applications
GROUP BY application_code, first_name, last_name, email
HAVING COUNT(*) > 1
ORDER BY first_application_date DESC;

-- Find candidates who applied to multiple positions and got hired for at least one
SELECT 
    ja.application_code,
    CONCAT(ja.first_name, ' ', ja.last_name) as applicant_name,
    ja.email,
    COUNT(*) as total_applications,
    SUM(CASE WHEN ja.status = 'hired' THEN 1 ELSE 0 END) as hired_count,
    GROUP_CONCAT(DISTINCT jp.title SEPARATOR ' | ') as positions
FROM job_applications ja
LEFT JOIN job_postings jp ON ja.job_posting_id = jp.id
GROUP BY ja.application_code, ja.first_name, ja.last_name, ja.email
HAVING COUNT(*) > 1 AND hired_count > 0
ORDER BY hired_count DESC;

-- =====================================================
-- FILES MODIFIED FOR THIS FEATURE
-- =====================================================
-- 1. careers.php - Added checklist of available positions in application form
-- 2. submit_application.php - Modified to handle multiple position submissions
-- 3. hr/view_application.php - Added section to display other positions applied to
-- 4. migrations/add_multiple_positions_support.sql - This documentation file
