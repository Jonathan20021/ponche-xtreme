-- Migration: Add Calendar Events System
-- Description: Adds support for custom calendar events in HR module
-- Date: 2025-11-03

-- Create calendar_events table
CREATE TABLE IF NOT EXISTS calendar_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    event_type ENUM('MEETING', 'REMINDER', 'DEADLINE', 'HOLIDAY', 'TRAINING', 'OTHER') DEFAULT 'OTHER',
    color VARCHAR(7) DEFAULT '#6366f1',
    location VARCHAR(255),
    is_all_day BOOLEAN DEFAULT FALSE,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_event_date (event_date),
    INDEX idx_event_type (event_type),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create calendar_event_attendees table for tracking who should attend events
CREATE TABLE IF NOT EXISTS calendar_event_attendees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    status ENUM('PENDING', 'ACCEPTED', 'DECLINED', 'TENTATIVE') DEFAULT 'PENDING',
    notified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_event_attendee (event_id, employee_id),
    INDEX idx_employee_id (employee_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create calendar_event_reminders table
CREATE TABLE IF NOT EXISTS calendar_event_reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    reminder_minutes INT NOT NULL COMMENT 'Minutes before event to remind',
    reminder_sent BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
    INDEX idx_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample event types for reference
INSERT INTO calendar_events (title, description, event_date, event_type, color, is_all_day, created_by) 
SELECT 
    'Día de la Independencia',
    'Feriado nacional',
    '2025-02-27',
    'HOLIDAY',
    '#10b981',
    TRUE,
    1
FROM users 
WHERE id = 1 
LIMIT 1
ON DUPLICATE KEY UPDATE title=VALUES(title);
