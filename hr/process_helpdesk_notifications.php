<?php
// Process Helpdesk Email Notifications
// This script should be run via cron job every 5 minutes
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/email_functions.php';

// Get pending notifications
$query = "SELECT n.*, t.ticket_number, t.subject, t.priority, t.status,
          u.email, u.full_name, u.username
          FROM helpdesk_notifications n
          JOIN helpdesk_tickets t ON n.ticket_id = t.id
          JOIN users u ON n.user_id = u.id
          WHERE n.email_sent = 0
          ORDER BY n.created_at ASC
          LIMIT 50";

$result = $conn->query($query);

$emailsSent = 0;
$emailsFailed = 0;

while ($row = $result->fetch_assoc()) {
    $subject = '';
    $body = '';
    
    switch ($row['notification_type']) {
        case 'ticket_created':
            $subject = "New Ticket Created: {$row['ticket_number']}";
            $body = generateTicketCreatedEmail($row);
            break;
            
        case 'ticket_assigned':
            $subject = "Ticket Assigned to You: {$row['ticket_number']}";
            $body = generateTicketAssignedEmail($row);
            break;
            
        case 'ticket_updated':
            $subject = "Ticket Updated: {$row['ticket_number']}";
            $body = generateTicketUpdatedEmail($row);
            break;
            
        case 'comment_added':
            $subject = "New Comment on Ticket: {$row['ticket_number']}";
            $body = generateCommentAddedEmail($row);
            break;
            
        case 'status_changed':
            $subject = "Ticket Status Changed: {$row['ticket_number']}";
            $body = generateStatusChangedEmail($row);
            break;
            
        case 'sla_warning':
            $subject = "⚠️ SLA Warning: {$row['ticket_number']}";
            $body = generateSLAWarningEmail($row);
            break;
            
        case 'sla_breached':
            $subject = "🚨 SLA BREACHED: {$row['ticket_number']}";
            $body = generateSLABreachedEmail($row);
            break;
    }
    
    if (sendEmail($row['email'], $subject, $body)) {
        // Mark as sent
        $updateQuery = "UPDATE helpdesk_notifications 
                       SET email_sent = 1, email_sent_at = NOW() 
                       WHERE id = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("i", $row['id']);
        $stmt->execute();
        $emailsSent++;
    } else {
        $emailsFailed++;
    }
}

echo "Helpdesk Email Notifications Processed\n";
echo "Emails sent: $emailsSent\n";
echo "Emails failed: $emailsFailed\n";

// Email template functions
function generateTicketCreatedEmail($data) {
    return "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center;'>
            <h1 style='margin: 0;'>🎫 New Support Ticket</h1>
        </div>
        
        <div style='padding: 30px; background: #f8f9fa;'>
            <p>Dear {$data['full_name']},</p>
            
            <p>A new support ticket has been created and requires your attention.</p>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h2 style='margin-top: 0; color: #333;'>Ticket Details</h2>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'><strong>Ticket Number:</strong></td>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'>{$data['ticket_number']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'><strong>Subject:</strong></td>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'>{$data['subject']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'><strong>Priority:</strong></td>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'><span style='background: #ffc107; color: #333; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;'>{$data['priority']}</span></td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0;'><strong>Status:</strong></td>
                        <td style='padding: 10px 0;'><span style='background: #007bff; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;'>{$data['status']}</span></td>
                    </tr>
                </table>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . getBaseUrl() . "/hr/helpdesk_dashboard.php' style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>View Ticket</a>
            </div>
            
            <p style='color: #666; font-size: 13px; margin-top: 30px;'>
                This is an automated notification from the Helpdesk System. Please do not reply to this email.
            </p>
        </div>
    </div>
    ";
}

function generateTicketAssignedEmail($data) {
    return "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center;'>
            <h1 style='margin: 0;'>👤 Ticket Assigned to You</h1>
        </div>
        
        <div style='padding: 30px; background: #f8f9fa;'>
            <p>Dear {$data['full_name']},</p>
            
            <p>A support ticket has been assigned to you. Please review and respond as soon as possible.</p>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h2 style='margin-top: 0; color: #333;'>Ticket Details</h2>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'><strong>Ticket Number:</strong></td>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'>{$data['ticket_number']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'><strong>Subject:</strong></td>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'>{$data['subject']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'><strong>Priority:</strong></td>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e0e0e0;'><span style='background: #ffc107; color: #333; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;'>{$data['priority']}</span></td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0;'><strong>Status:</strong></td>
                        <td style='padding: 10px 0;'><span style='background: #007bff; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;'>{$data['status']}</span></td>
                    </tr>
                </table>
            </div>
            
            <div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;'>
                <strong>⏰ Action Required</strong><br>
                Please review this ticket and provide an initial response to meet SLA requirements.
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . getBaseUrl() . "/hr/helpdesk_dashboard.php' style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>View Ticket</a>
            </div>
            
            <p style='color: #666; font-size: 13px; margin-top: 30px;'>
                This is an automated notification from the Helpdesk System. Please do not reply to this email.
            </p>
        </div>
    </div>
    ";
}

function generateTicketUpdatedEmail($data) {
    return "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center;'>
            <h1 style='margin: 0;'>🔄 Ticket Updated</h1>
        </div>
        
        <div style='padding: 30px; background: #f8f9fa;'>
            <p>Dear {$data['full_name']},</p>
            
            <p>Your support ticket has been updated.</p>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h2 style='margin-top: 0; color: #333;'>Ticket #{$data['ticket_number']}</h2>
                <p><strong>Subject:</strong> {$data['subject']}</p>
                <p><strong>Current Status:</strong> <span style='background: #007bff; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;'>{$data['status']}</span></p>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . getBaseUrl() . "/hr/helpdesk_dashboard.php' style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>View Ticket</a>
            </div>
            
            <p style='color: #666; font-size: 13px; margin-top: 30px;'>
                This is an automated notification from the Helpdesk System. Please do not reply to this email.
            </p>
        </div>
    </div>
    ";
}

function generateCommentAddedEmail($data) {
    return "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center;'>
            <h1 style='margin: 0;'>💬 New Comment Added</h1>
        </div>
        
        <div style='padding: 30px; background: #f8f9fa;'>
            <p>Dear {$data['full_name']},</p>
            
            <p>A new comment has been added to your support ticket.</p>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h2 style='margin-top: 0; color: #333;'>Ticket #{$data['ticket_number']}</h2>
                <p><strong>Subject:</strong> {$data['subject']}</p>
                <p style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 15px;'>
                    {$data['message']}
                </p>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . getBaseUrl() . "/hr/helpdesk_dashboard.php' style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>View Ticket & Reply</a>
            </div>
            
            <p style='color: #666; font-size: 13px; margin-top: 30px;'>
                This is an automated notification from the Helpdesk System. Please do not reply to this email.
            </p>
        </div>
    </div>
    ";
}

function generateStatusChangedEmail($data) {
    return "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center;'>
            <h1 style='margin: 0;'>📊 Status Changed</h1>
        </div>
        
        <div style='padding: 30px; background: #f8f9fa;'>
            <p>Dear {$data['full_name']},</p>
            
            <p>The status of your support ticket has been changed.</p>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h2 style='margin-top: 0; color: #333;'>Ticket #{$data['ticket_number']}</h2>
                <p><strong>Subject:</strong> {$data['subject']}</p>
                <p><strong>New Status:</strong> <span style='background: #28a745; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;'>{$data['status']}</span></p>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . getBaseUrl() . "/hr/helpdesk_dashboard.php' style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>View Ticket</a>
            </div>
            
            <p style='color: #666; font-size: 13px; margin-top: 30px;'>
                This is an automated notification from the Helpdesk System. Please do not reply to this email.
            </p>
        </div>
    </div>
    ";
}

function generateSLAWarningEmail($data) {
    return "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%); color: white; padding: 30px; text-align: center;'>
            <h1 style='margin: 0;'>⚠️ SLA WARNING</h1>
        </div>
        
        <div style='padding: 30px; background: #f8f9fa;'>
            <p>Dear {$data['full_name']},</p>
            
            <div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0;'>
                <strong style='color: #856404;'>⚠️ ATTENTION REQUIRED</strong><br>
                <p style='color: #856404; margin: 10px 0 0 0;'>This ticket is approaching its SLA deadline. Immediate action is required.</p>
            </div>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h2 style='margin-top: 0; color: #333;'>Ticket #{$data['ticket_number']}</h2>
                <p><strong>Subject:</strong> {$data['subject']}</p>
                <p><strong>Priority:</strong> <span style='background: #dc3545; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;'>{$data['priority']}</span></p>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . getBaseUrl() . "/hr/helpdesk_dashboard.php' style='background: #dc3545; color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>TAKE ACTION NOW</a>
            </div>
            
            <p style='color: #666; font-size: 13px; margin-top: 30px;'>
                This is an automated notification from the Helpdesk System. Please do not reply to this email.
            </p>
        </div>
    </div>
    ";
}

function generateSLABreachedEmail($data) {
    return "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; padding: 30px; text-align: center;'>
            <h1 style='margin: 0;'>🚨 SLA BREACHED</h1>
        </div>
        
        <div style='padding: 30px; background: #f8f9fa;'>
            <p>Dear {$data['full_name']},</p>
            
            <div style='background: #f8d7da; border-left: 4px solid #dc3545; padding: 20px; margin: 20px 0;'>
                <strong style='color: #721c24;'>🚨 CRITICAL: SLA BREACH</strong><br>
                <p style='color: #721c24; margin: 10px 0 0 0;'>This ticket has breached its SLA deadline. Escalation procedures may be initiated.</p>
            </div>
            
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h2 style='margin-top: 0; color: #333;'>Ticket #{$data['ticket_number']}</h2>
                <p><strong>Subject:</strong> {$data['subject']}</p>
                <p><strong>Priority:</strong> <span style='background: #dc3545; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;'>CRITICAL</span></p>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . getBaseUrl() . "/hr/helpdesk_dashboard.php' style='background: #dc3545; color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>IMMEDIATE ACTION REQUIRED</a>
            </div>
            
            <p style='color: #666; font-size: 13px; margin-top: 30px;'>
                This is an automated notification from the Helpdesk System. Please do not reply to this email.
            </p>
        </div>
    </div>
    ";
}

function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host;
}
