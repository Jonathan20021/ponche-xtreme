-- Cleanup script for helpdesk system
-- Run this BEFORE installing the helpdesk system if you need to start fresh

SET FOREIGN_KEY_CHECKS = 0;

-- Drop tables in reverse order of dependencies
DROP TABLE IF EXISTS helpdesk_sla_breaches;
DROP TABLE IF EXISTS helpdesk_ai_interactions;
DROP TABLE IF EXISTS helpdesk_suggestion_votes;
DROP TABLE IF EXISTS helpdesk_suggestions;
DROP TABLE IF EXISTS helpdesk_notifications;
DROP TABLE IF EXISTS helpdesk_status_history;
DROP TABLE IF EXISTS helpdesk_assignments;
DROP TABLE IF EXISTS helpdesk_attachments;
DROP TABLE IF EXISTS helpdesk_comments;
DROP TABLE IF EXISTS helpdesk_tickets;
DROP TABLE IF EXISTS helpdesk_categories;

-- Drop views
DROP VIEW IF EXISTS helpdesk_ticket_stats;
DROP VIEW IF EXISTS helpdesk_agent_performance;

-- Drop stored procedure
DROP PROCEDURE IF EXISTS check_sla_breaches;

-- Drop event
DROP EVENT IF EXISTS check_helpdesk_sla;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Helpdesk tables cleaned successfully' as status;
