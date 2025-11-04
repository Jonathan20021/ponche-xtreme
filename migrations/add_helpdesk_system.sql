-- Helpdesk System Migration
-- This migration creates all necessary tables for a complete helpdesk/ticket system
-- with AI integration, SLA tracking, assignments, and notifications

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing tables if they exist (for clean reinstall)
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
DROP VIEW IF EXISTS helpdesk_ticket_stats;
DROP VIEW IF EXISTS helpdesk_agent_performance;
DROP PROCEDURE IF EXISTS check_sla_breaches;
DROP EVENT IF EXISTS check_helpdesk_sla;

SET FOREIGN_KEY_CHECKS = 1;

-- Table: helpdesk_categories
-- Stores ticket categories and their SLA configurations
CREATE TABLE helpdesk_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    department VARCHAR(100),
    color VARCHAR(7) DEFAULT '#007bff',
    sla_response_hours INT DEFAULT 24,
    sla_resolution_hours INT DEFAULT 72,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: helpdesk_tickets
-- Main tickets table with all ticket information
CREATE TABLE helpdesk_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(20) UNIQUE NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    category_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    status ENUM('open', 'in_progress', 'pending', 'resolved', 'closed', 'cancelled') DEFAULT 'open',
    assigned_to INT UNSIGNED DEFAULT NULL,
    created_by_type ENUM('employee', 'agent', 'admin') DEFAULT 'employee',
    sla_response_deadline DATETIME,
    sla_resolution_deadline DATETIME,
    first_response_at DATETIME DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    sla_response_breached TINYINT(1) DEFAULT 0,
    sla_resolution_breached TINYINT(1) DEFAULT 0,
    ai_analysis TEXT DEFAULT NULL,
    ai_suggested_category INT DEFAULT NULL,
    ai_suggested_priority VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ticket_number (ticket_number),
    INDEX idx_user_id (user_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_created_at (created_at),
    INDEX idx_sla_deadlines (sla_response_deadline, sla_resolution_deadline),
    INDEX idx_category_id (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign keys after table creation
ALTER TABLE helpdesk_tickets
ADD CONSTRAINT fk_helpdesk_tickets_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_tickets_category_id FOREIGN KEY (category_id) REFERENCES helpdesk_categories(id) ON DELETE RESTRICT,
ADD CONSTRAINT fk_helpdesk_tickets_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL;

-- Table: helpdesk_comments
-- Stores all comments/replies on tickets
CREATE TABLE helpdesk_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    is_internal TINYINT(1) DEFAULT 0,
    is_ai_generated TINYINT(1) DEFAULT 0,
    attachments JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE helpdesk_comments
ADD CONSTRAINT fk_helpdesk_comments_ticket_id FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_comments_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Table: helpdesk_attachments
-- Stores file attachments for tickets
CREATE TABLE helpdesk_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    comment_id INT DEFAULT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    file_type VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_comment_id (comment_id),
    INDEX idx_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE helpdesk_attachments
ADD CONSTRAINT fk_helpdesk_attachments_ticket_id FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_attachments_comment_id FOREIGN KEY (comment_id) REFERENCES helpdesk_comments(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_attachments_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE;

-- Table: helpdesk_assignments
-- Tracks ticket assignment history
CREATE TABLE helpdesk_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    assigned_from INT UNSIGNED DEFAULT NULL,
    assigned_to INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_assigned_from (assigned_from),
    INDEX idx_assigned_by (assigned_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE helpdesk_assignments
ADD CONSTRAINT fk_helpdesk_assignments_ticket_id FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_assignments_assigned_from FOREIGN KEY (assigned_from) REFERENCES users(id) ON DELETE SET NULL,
ADD CONSTRAINT fk_helpdesk_assignments_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_assignments_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE;

-- Table: helpdesk_status_history
-- Tracks all status changes for tickets
CREATE TABLE helpdesk_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    old_status VARCHAR(50),
    new_status VARCHAR(50) NOT NULL,
    changed_by INT UNSIGNED NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_changed_by (changed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE helpdesk_status_history
ADD CONSTRAINT fk_helpdesk_status_history_ticket_id FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_status_history_changed_by FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE;

-- Table: helpdesk_notifications
-- Stores notification queue for ticket events
CREATE TABLE helpdesk_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    notification_type ENUM('ticket_created', 'ticket_assigned', 'ticket_updated', 'comment_added', 'status_changed', 'sla_warning', 'sla_breached') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    email_sent TINYINT(1) DEFAULT 0,
    email_sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_email_sent (email_sent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE helpdesk_notifications
ADD CONSTRAINT fk_helpdesk_notifications_ticket_id FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_notifications_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Table: helpdesk_suggestions
-- Stores suggestion box entries for different departments
CREATE TABLE helpdesk_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    department VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    suggestion_type ENUM('improvement', 'new_feature', 'complaint', 'compliment', 'other') DEFAULT 'improvement',
    status ENUM('pending', 'under_review', 'approved', 'implemented', 'rejected') DEFAULT 'pending',
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_notes TEXT,
    is_anonymous TINYINT(1) DEFAULT 0,
    votes_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_reviewed_by (reviewed_by),
    INDEX idx_department (department),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE helpdesk_suggestions
ADD CONSTRAINT fk_helpdesk_suggestions_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_suggestions_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;

-- Table: helpdesk_suggestion_votes
-- Tracks votes on suggestions
CREATE TABLE helpdesk_suggestion_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    suggestion_id INT NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    vote_type ENUM('up', 'down') DEFAULT 'up',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_suggestion_id (suggestion_id),
    INDEX idx_user_id (user_id),
    UNIQUE KEY unique_user_vote (suggestion_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE helpdesk_suggestion_votes
ADD CONSTRAINT fk_helpdesk_suggestion_votes_suggestion_id FOREIGN KEY (suggestion_id) REFERENCES helpdesk_suggestions(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_suggestion_votes_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Table: helpdesk_ai_interactions
-- Logs all AI interactions for tickets
CREATE TABLE helpdesk_ai_interactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    interaction_type ENUM('analysis', 'suggestion', 'auto_response', 'categorization', 'priority_assessment') NOT NULL,
    prompt TEXT NOT NULL,
    response TEXT NOT NULL,
    model_used VARCHAR(100) DEFAULT 'gemini-pro',
    tokens_used INT DEFAULT 0,
    processing_time_ms INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_interaction_type (interaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE helpdesk_ai_interactions
ADD CONSTRAINT fk_helpdesk_ai_interactions_ticket_id FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE;

-- Table: helpdesk_sla_breaches
-- Tracks SLA breaches for reporting
CREATE TABLE helpdesk_sla_breaches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    breach_type ENUM('response', 'resolution') NOT NULL,
    deadline DATETIME NOT NULL,
    breached_at DATETIME NOT NULL,
    delay_hours DECIMAL(10,2) NOT NULL,
    assigned_to INT UNSIGNED DEFAULT NULL,
    category_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_category_id (category_id),
    INDEX idx_breach_type (breach_type),
    INDEX idx_breached_at (breached_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE helpdesk_sla_breaches
ADD CONSTRAINT fk_helpdesk_sla_breaches_ticket_id FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
ADD CONSTRAINT fk_helpdesk_sla_breaches_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
ADD CONSTRAINT fk_helpdesk_sla_breaches_category_id FOREIGN KEY (category_id) REFERENCES helpdesk_categories(id) ON DELETE RESTRICT;

-- Insert default categories
INSERT INTO helpdesk_categories (name, description, department, color, sla_response_hours, sla_resolution_hours) VALUES
('Technical Support', 'Technical issues with systems, software, or hardware', 'IT', '#007bff', 4, 24),
('HR Support', 'Human Resources related queries and issues', 'HR', '#28a745', 8, 48),
('Payroll Issues', 'Salary, benefits, and payroll related issues', 'Payroll', '#dc3545', 4, 24),
('Access Request', 'System access, permissions, and credentials', 'IT', '#17a2b8', 2, 8),
('Equipment Request', 'Hardware, software, and equipment requests', 'IT', '#ffc107', 24, 72),
('Facilities', 'Office facilities, maintenance, and infrastructure', 'Facilities', '#6c757d', 12, 48),
('Training', 'Training requests and learning resources', 'HR', '#e83e8c', 48, 120),
('General Inquiry', 'General questions and information requests', 'General', '#6610f2', 8, 24);

-- Insert helpdesk permissions for different roles
INSERT INTO section_permissions (section_key, role) VALUES
('helpdesk', 'Admin'),
('helpdesk', 'HR'),
('helpdesk_tickets', 'Admin'),
('helpdesk_tickets', 'HR'),
('helpdesk_tickets', 'AGENT'),
('helpdesk_suggestions', 'Admin'),
('helpdesk_suggestions', 'HR'),
('helpdesk_suggestions', 'AGENT'),
('helpdesk_suggestions', 'Supervisor')
ON DUPLICATE KEY UPDATE section_key = section_key;

-- Create view for ticket statistics
CREATE OR REPLACE VIEW helpdesk_ticket_stats AS
SELECT 
    DATE(created_at) as date,
    category_id,
    status,
    priority,
    COUNT(*) as ticket_count,
    AVG(TIMESTAMPDIFF(HOUR, created_at, COALESCE(resolved_at, NOW()))) as avg_resolution_hours,
    SUM(CASE WHEN sla_response_breached = 1 THEN 1 ELSE 0 END) as response_breaches,
    SUM(CASE WHEN sla_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breaches
FROM helpdesk_tickets
GROUP BY DATE(created_at), category_id, status, priority;

-- Create view for agent performance
CREATE OR REPLACE VIEW helpdesk_agent_performance AS
SELECT 
    u.id as agent_id,
    u.username,
    u.full_name,
    COUNT(DISTINCT t.id) as total_tickets,
    COUNT(DISTINCT CASE WHEN t.status = 'resolved' THEN t.id END) as resolved_tickets,
    COUNT(DISTINCT CASE WHEN t.status = 'closed' THEN t.id END) as closed_tickets,
    AVG(CASE WHEN t.resolved_at IS NOT NULL 
        THEN TIMESTAMPDIFF(HOUR, t.created_at, t.resolved_at) END) as avg_resolution_hours,
    SUM(CASE WHEN t.sla_response_breached = 1 THEN 1 ELSE 0 END) as response_breaches,
    SUM(CASE WHEN t.sla_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breaches
FROM users u
LEFT JOIN helpdesk_tickets t ON u.id = t.assigned_to
WHERE u.role IN ('admin', 'hr')
GROUP BY u.id, u.username, u.full_name;

-- Create stored procedure to check and update SLA breaches
DELIMITER //

CREATE PROCEDURE check_sla_breaches()
BEGIN
    -- Check response SLA breaches
    UPDATE helpdesk_tickets
    SET sla_response_breached = 1
    WHERE status IN ('open', 'in_progress', 'pending')
    AND first_response_at IS NULL
    AND sla_response_deadline < NOW()
    AND sla_response_breached = 0;
    
    -- Log response breaches
    INSERT INTO helpdesk_sla_breaches (ticket_id, breach_type, deadline, breached_at, delay_hours, assigned_to, category_id)
    SELECT 
        id,
        'response',
        sla_response_deadline,
        NOW(),
        TIMESTAMPDIFF(HOUR, sla_response_deadline, NOW()),
        assigned_to,
        category_id
    FROM helpdesk_tickets
    WHERE sla_response_breached = 1
    AND first_response_at IS NULL
    AND NOT EXISTS (
        SELECT 1 FROM helpdesk_sla_breaches 
        WHERE ticket_id = helpdesk_tickets.id 
        AND breach_type = 'response'
    );
    
    -- Check resolution SLA breaches
    UPDATE helpdesk_tickets
    SET sla_resolution_breached = 1
    WHERE status IN ('open', 'in_progress', 'pending')
    AND resolved_at IS NULL
    AND sla_resolution_deadline < NOW()
    AND sla_resolution_breached = 0;
    
    -- Log resolution breaches
    INSERT INTO helpdesk_sla_breaches (ticket_id, breach_type, deadline, breached_at, delay_hours, assigned_to, category_id)
    SELECT 
        id,
        'resolution',
        sla_resolution_deadline,
        NOW(),
        TIMESTAMPDIFF(HOUR, sla_resolution_deadline, NOW()),
        assigned_to,
        category_id
    FROM helpdesk_tickets
    WHERE sla_resolution_breached = 1
    AND resolved_at IS NULL
    AND NOT EXISTS (
        SELECT 1 FROM helpdesk_sla_breaches 
        WHERE ticket_id = helpdesk_tickets.id 
        AND breach_type = 'resolution'
    );
    
    -- Create notifications for new breaches
    INSERT INTO helpdesk_notifications (ticket_id, user_id, notification_type, title, message)
    SELECT 
        t.id,
        t.assigned_to,
        'sla_breached',
        CONCAT('SLA Breach: Ticket #', t.ticket_number),
        CONCAT('Ticket has breached its SLA deadline. Please take immediate action.')
    FROM helpdesk_tickets t
    WHERE (t.sla_response_breached = 1 OR t.sla_resolution_breached = 1)
    AND t.assigned_to IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM helpdesk_notifications n
        WHERE n.ticket_id = t.id 
        AND n.notification_type = 'sla_breached'
        AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    );
END //

DELIMITER ;

-- Create event to check SLA breaches every 30 minutes
CREATE EVENT IF NOT EXISTS check_helpdesk_sla
ON SCHEDULE EVERY 30 MINUTE
DO CALL check_sla_breaches();

-- Create indexes for better performance
CREATE INDEX idx_helpdesk_tickets_sla ON helpdesk_tickets(sla_response_breached, sla_resolution_breached, status);
CREATE INDEX idx_helpdesk_notifications_pending ON helpdesk_notifications(email_sent, created_at);
