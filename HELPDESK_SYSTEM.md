# Helpdesk & Ticket Management System

## Overview

Complete helpdesk and ticket management system with AI-powered assistance, SLA tracking, email notifications, and suggestion box functionality. This module enables efficient support ticket management, department suggestions, and automated workflows.

## Features

### 🎫 Ticket Management
- **Create, Read, Update, Delete (CRUD)** operations for tickets
- **Ticket Assignment** to support agents
- **Status Tracking**: Open, In Progress, Pending, Resolved, Closed, Cancelled
- **Priority Levels**: Low, Medium, High, Critical
- **Categories**: Technical Support, HR Support, Payroll, Access Request, Equipment, Facilities, Training, General
- **Ticket History**: Complete audit trail of all changes
- **Comments System**: Internal and public comments
- **File Attachments**: Support for document uploads

### 🤖 AI Integration (Gemini)
- **Automatic Ticket Analysis**: AI analyzes ticket content on creation
- **Category Suggestions**: AI suggests appropriate category based on content
- **Priority Assessment**: AI recommends priority level
- **Response Suggestions**: AI generates suggested responses for agents
- **Smart Categorization**: Improves ticket routing efficiency

### ⏱️ SLA Management
- **Configurable SLA**: Response and resolution time limits per category
- **Automatic Tracking**: System monitors all SLA deadlines
- **Breach Detection**: Automatic detection and logging of SLA violations
- **Warning Alerts**: Notifications sent before SLA breach
- **Performance Metrics**: Track SLA compliance rates

### 📧 Email Notifications
- **Ticket Created**: Confirmation emails to users
- **Ticket Assigned**: Notification to assigned agent
- **Status Changed**: Updates on ticket progress
- **New Comments**: Alerts when comments are added
- **SLA Warnings**: Alerts for approaching deadlines
- **SLA Breaches**: Critical alerts for violated SLAs

### 💡 Suggestion Box
- **Department Suggestions**: Submit ideas for any department
- **Suggestion Types**: Improvement, New Feature, Complaint, Compliment, Other
- **Anonymous Submissions**: Option to submit anonymously
- **Voting System**: Users can vote on suggestions
- **Status Tracking**: Pending, Under Review, Approved, Implemented, Rejected
- **Admin Review**: HR/Admin can review and respond to suggestions

### 📊 Reporting & Analytics
- **Ticket Statistics**: Total, open, resolved, closed tickets
- **SLA Performance**: Response and resolution breach rates
- **Category Analysis**: Tickets by category
- **Priority Distribution**: Tickets by priority level
- **Agent Performance**: Individual agent metrics
- **Average Resolution Time**: Track efficiency

## Database Structure

### Main Tables

#### `helpdesk_categories`
Stores ticket categories and SLA configurations
- `id`, `name`, `description`, `department`
- `sla_response_hours`, `sla_resolution_hours`
- `color`, `is_active`

#### `helpdesk_tickets`
Main tickets table
- `id`, `ticket_number`, `user_id`, `category_id`
- `subject`, `description`, `priority`, `status`
- `assigned_to`, `created_by_type`
- `sla_response_deadline`, `sla_resolution_deadline`
- `first_response_at`, `resolved_at`, `closed_at`
- `sla_response_breached`, `sla_resolution_breached`
- `ai_analysis`, `ai_suggested_category`, `ai_suggested_priority`

#### `helpdesk_comments`
Ticket comments and replies
- `id`, `ticket_id`, `user_id`, `comment`
- `is_internal`, `is_ai_generated`, `attachments`

#### `helpdesk_assignments`
Ticket assignment history
- `id`, `ticket_id`, `assigned_from`, `assigned_to`, `assigned_by`, `notes`

#### `helpdesk_status_history`
Status change tracking
- `id`, `ticket_id`, `old_status`, `new_status`, `changed_by`, `notes`

#### `helpdesk_notifications`
Notification queue
- `id`, `ticket_id`, `user_id`, `notification_type`
- `title`, `message`, `is_read`, `email_sent`

#### `helpdesk_suggestions`
Suggestion box entries
- `id`, `user_id`, `department`, `title`, `description`
- `suggestion_type`, `status`, `priority`
- `reviewed_by`, `reviewed_at`, `review_notes`
- `is_anonymous`, `votes_count`

#### `helpdesk_ai_interactions`
AI interaction logs
- `id`, `ticket_id`, `interaction_type`, `prompt`, `response`
- `model_used`, `tokens_used`, `processing_time_ms`

#### `helpdesk_sla_breaches`
SLA breach tracking
- `id`, `ticket_id`, `breach_type`, `deadline`, `breached_at`
- `delay_hours`, `assigned_to`, `category_id`

## Installation

### 1. Run Database Migration

```bash
# Navigate to your MySQL client or phpMyAdmin
# Execute the migration file:
mysql -u username -p database_name < migrations/add_helpdesk_system.sql
```

Or via phpMyAdmin:
1. Open phpMyAdmin
2. Select your database
3. Go to "Import" tab
4. Choose `migrations/add_helpdesk_system.sql`
5. Click "Go"

### 2. Configure Email Settings

Ensure your email configuration is set up in `config/email_config.php`:

```php
define('SMTP_HOST', 'your-smtp-host');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@domain.com');
define('SMTP_PASSWORD', 'your-password');
define('SMTP_FROM_EMAIL', 'noreply@yourdomain.com');
define('SMTP_FROM_NAME', 'Helpdesk System');
```

### 3. Configure Gemini AI

The system uses your existing Gemini API configuration in `lib/gemini_api.php`.

### 4. Set Up Cron Jobs

Add these cron jobs for automated processing:

```bash
# Process email notifications every 5 minutes
*/5 * * * * php /path/to/hr/process_helpdesk_notifications.php

# Monitor SLA every 15 minutes
*/15 * * * * php /path/to/hr/monitor_sla.php
```

Windows Task Scheduler:
```
Action: Start a program
Program: C:\xampp\php\php.exe
Arguments: C:\xampp\htdocs\ponche-xtreme\hr\process_helpdesk_notifications.php
Trigger: Every 5 minutes
```

### 5. Grant Permissions

The helpdesk permission is automatically granted to admin and hr roles. To grant to other roles:

```sql
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r, permissions p
WHERE r.name = 'your_role_name' AND p.name = 'helpdesk';
```

## Usage

### For Employees/Agents

#### Create a Ticket
1. Navigate to **Agents Dashboard** → **My Support Tickets**
2. Click **"Create Ticket"**
3. Select category, enter subject and description
4. Choose priority level
5. Submit ticket
6. Receive confirmation email with ticket number

#### Track Tickets
- View all your tickets in the dashboard
- Filter by status, priority, or category
- Click on any ticket to view details
- Add comments to provide additional information
- Receive email updates on ticket progress

#### Submit Suggestions
1. Navigate to **Suggestion Box**
2. Click **"Submit Suggestion"**
3. Select department and type
4. Enter title and detailed description
5. Optionally submit anonymously
6. Vote on other suggestions

### For Admins/HR

#### Manage Tickets
1. Navigate to **HR Dashboard** → **Helpdesk Dashboard**
2. View all tickets with filters
3. Click on ticket to view details
4. **Assign** tickets to agents
5. **Update status** as work progresses
6. Add **internal notes** (not visible to users)
7. Use **AI suggestions** for responses

#### AI-Powered Features
- View AI analysis on ticket creation
- Get AI-suggested category and priority
- Click **"AI Suggest Response"** for automated response drafts
- Review AI interaction history

#### Monitor SLA
- Dashboard shows SLA breach statistics
- Tickets approaching deadline highlighted in yellow
- Breached tickets highlighted in red
- Automatic email alerts for warnings and breaches

#### Review Suggestions
1. Navigate to suggestion management
2. Filter by department, status, or type
3. Review suggestion details
4. Update status (Under Review, Approved, Implemented, Rejected)
5. Add review notes
6. Users receive notifications on status changes

## API Endpoints

### Ticket API (`hr/helpdesk_api.php`)

#### Create Ticket
```
POST /hr/helpdesk_api.php
action=create_ticket
category_id=1
subject=Issue subject
description=Detailed description
priority=medium
```

#### Get Tickets
```
GET /hr/helpdesk_api.php?action=get_tickets
&status=open
&priority=high
&category_id=1
&assigned_to=5
```

#### Get Ticket Details
```
GET /hr/helpdesk_api.php?action=get_ticket&ticket_id=123
```

#### Assign Ticket
```
POST /hr/helpdesk_api.php
action=assign_ticket
ticket_id=123
assigned_to=5
notes=Assignment notes
```

#### Update Status
```
POST /hr/helpdesk_api.php
action=update_status
ticket_id=123
status=in_progress
notes=Status change notes
```

#### Add Comment
```
POST /hr/helpdesk_api.php
action=add_comment
ticket_id=123
comment=Comment text
is_internal=0
```

#### AI Suggest Response
```
POST /hr/helpdesk_api.php
action=ai_suggest_response
ticket_id=123
```

#### Get Statistics
```
GET /hr/helpdesk_api.php?action=get_statistics
&date_from=2025-01-01
&date_to=2025-12-31
```

### Suggestions API (`hr/suggestions_api.php`)

#### Create Suggestion
```
POST /hr/suggestions_api.php
action=create_suggestion
department=IT
title=Suggestion title
description=Detailed description
suggestion_type=improvement
is_anonymous=0
```

#### Get Suggestions
```
GET /hr/suggestions_api.php?action=get_suggestions
&department=IT
&status=pending
&suggestion_type=improvement
```

#### Update Suggestion Status
```
POST /hr/suggestions_api.php
action=update_suggestion_status
suggestion_id=123
status=approved
review_notes=Review notes
```

#### Vote on Suggestion
```
POST /hr/suggestions_api.php
action=vote_suggestion
suggestion_id=123
vote_type=up
```

## Ticket Workflow

### Standard Workflow
1. **User Creates Ticket** → Status: Open
2. **AI Analyzes Ticket** → Suggests category/priority
3. **Admin Assigns to Agent** → Notification sent
4. **Agent Reviews** → Status: In Progress
5. **Agent Works on Issue** → Adds comments
6. **Issue Resolved** → Status: Resolved
7. **User Confirms** → Status: Closed

### SLA Workflow
1. **Ticket Created** → SLA deadlines set
2. **2 Hours Before Deadline** → Warning notifications
3. **Deadline Passed** → Breach logged, critical alerts
4. **Escalation** → Management notified

## Default Categories & SLA

| Category | Department | Response SLA | Resolution SLA |
|----------|-----------|--------------|----------------|
| Technical Support | IT | 4 hours | 24 hours |
| HR Support | HR | 8 hours | 48 hours |
| Payroll Issues | Payroll | 4 hours | 24 hours |
| Access Request | IT | 2 hours | 8 hours |
| Equipment Request | IT | 24 hours | 72 hours |
| Facilities | Facilities | 12 hours | 48 hours |
| Training | HR | 48 hours | 120 hours |
| General Inquiry | General | 8 hours | 24 hours |

## Customization

### Add New Category
```sql
INSERT INTO helpdesk_categories 
(name, description, department, color, sla_response_hours, sla_resolution_hours) 
VALUES 
('New Category', 'Description', 'Department', '#007bff', 8, 48);
```

### Modify SLA Times
```sql
UPDATE helpdesk_categories 
SET sla_response_hours = 2, sla_resolution_hours = 12
WHERE name = 'Critical Issues';
```

### Add Custom Notification Types
Edit `lib/helpdesk_functions.php` and add new notification type in `createNotification()` function.

## Troubleshooting

### Emails Not Sending
1. Check email configuration in `config/email_config.php`
2. Verify SMTP credentials
3. Check `process_helpdesk_notifications.php` is running via cron
4. Review email logs in database

### SLA Not Tracking
1. Verify MySQL event scheduler is enabled: `SET GLOBAL event_scheduler = ON;`
2. Check `check_helpdesk_sla` event exists
3. Manually run `monitor_sla.php` to test
4. Review `helpdesk_sla_breaches` table

### AI Not Working
1. Verify Gemini API key is configured
2. Check `lib/gemini_api.php` configuration
3. Review `helpdesk_ai_interactions` table for errors
4. Ensure API key has sufficient quota

### Permissions Issues
1. Verify user role has helpdesk permission
2. Check `role_permissions` table
3. Clear session and re-login
4. Grant permission manually via SQL

## Security Considerations

- **Access Control**: Only authorized users can view/manage tickets
- **Internal Comments**: Hidden from regular users
- **Anonymous Suggestions**: User identity protected (except from admins)
- **SQL Injection**: All queries use prepared statements
- **XSS Protection**: All output is escaped
- **File Uploads**: Validate file types and sizes (if implemented)

## Performance Optimization

- **Indexes**: All foreign keys and frequently queried columns indexed
- **Views**: Pre-computed statistics for faster dashboard loading
- **Pagination**: Limit query results for large datasets
- **Caching**: Consider implementing Redis for notification queue
- **Archive**: Move old closed tickets to archive table periodically

## Future Enhancements

- [ ] Multi-language support
- [ ] Advanced search with full-text indexing
- [ ] Ticket templates for common issues
- [ ] Knowledge base integration
- [ ] Customer satisfaction surveys
- [ ] Live chat integration
- [ ] Mobile app
- [ ] Advanced reporting with charts
- [ ] Ticket merging and splitting
- [ ] Auto-assignment based on workload

## Support

For issues or questions about the helpdesk system:
1. Create a ticket using the system itself
2. Contact IT department
3. Review system logs in `helpdesk_ai_interactions` table
4. Check error logs in PHP error log

## Version History

### Version 1.0 (Current)
- Complete ticket management system
- AI-powered ticket analysis
- SLA tracking and monitoring
- Email notification system
- Suggestion box functionality
- Admin and agent dashboards
- Comprehensive reporting

---

**Last Updated**: November 2025
**Module**: Helpdesk & Ticket Management
**Status**: Production Ready
