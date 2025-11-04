<?php
// SLA Monitoring Script
// This script should be run via cron job every 15-30 minutes
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/helpdesk_functions.php';

echo "Starting SLA Monitoring...\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Call the stored procedure to check and update SLA breaches
$conn->query("CALL check_sla_breaches()");

// Get tickets approaching SLA deadlines (within 2 hours)
$query = "SELECT t.*, u.full_name, u.email, c.name as category_name
          FROM helpdesk_tickets t
          JOIN users u ON t.user_id = u.id
          JOIN helpdesk_categories c ON t.category_id = c.id
          WHERE t.status IN ('open', 'in_progress', 'pending')
          AND (
              (t.sla_response_deadline BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 2 HOUR) 
               AND t.first_response_at IS NULL 
               AND t.sla_response_breached = 0)
              OR
              (t.sla_resolution_deadline BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 2 HOUR) 
               AND t.resolved_at IS NULL 
               AND t.sla_resolution_breached = 0)
          )";

$result = $conn->query($query);
$warningsSent = 0;

while ($row = $result->fetch_assoc()) {
    // Check if we already sent a warning in the last 2 hours
    $checkQuery = "SELECT id FROM helpdesk_notifications 
                   WHERE ticket_id = ? 
                   AND notification_type = 'sla_warning'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("i", $row['id']);
    $stmt->execute();
    $checkResult = $stmt->get_result();
    
    if ($checkResult->num_rows == 0) {
        // Send warning notification to assigned agent
        if ($row['assigned_to']) {
            $title = "SLA Warning: Ticket #{$row['ticket_number']}";
            $message = "This ticket is approaching its SLA deadline. Please take action immediately.";
            
            $insertQuery = "INSERT INTO helpdesk_notifications 
                           (ticket_id, user_id, notification_type, title, message) 
                           VALUES (?, ?, 'sla_warning', ?, ?)";
            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param("iiss", $row['id'], $row['assigned_to'], $title, $message);
            $stmt->execute();
            $warningsSent++;
        }
        
        // Also notify admins
        $adminQuery = "SELECT id FROM users WHERE role IN ('admin', 'hr')";
        $adminResult = $conn->query($adminQuery);
        
        while ($admin = $adminResult->fetch_assoc()) {
            if ($admin['id'] != $row['assigned_to']) {
                $title = "SLA Warning: Ticket #{$row['ticket_number']}";
                $message = "Ticket is approaching SLA deadline. Category: {$row['category_name']}, Priority: {$row['priority']}";
                
                $insertQuery = "INSERT INTO helpdesk_notifications 
                               (ticket_id, user_id, notification_type, title, message) 
                               VALUES (?, ?, 'sla_warning', ?, ?)";
                $stmt = $conn->prepare($insertQuery);
                $stmt->bind_param("iiss", $row['id'], $admin['id'], $title, $message);
                $stmt->execute();
            }
        }
    }
}

echo "SLA Warnings sent: $warningsSent\n";

// Get current SLA statistics
$statsQuery = "SELECT 
                COUNT(*) as total_active,
                SUM(CASE WHEN sla_response_breached = 1 THEN 1 ELSE 0 END) as response_breaches,
                SUM(CASE WHEN sla_resolution_breached = 1 THEN 1 ELSE 0 END) as resolution_breaches
               FROM helpdesk_tickets
               WHERE status IN ('open', 'in_progress', 'pending')";

$result = $conn->query($statsQuery);
$stats = $result->fetch_assoc();

echo "\nCurrent SLA Status:\n";
echo "Active Tickets: {$stats['total_active']}\n";
echo "Response SLA Breaches: {$stats['response_breaches']}\n";
echo "Resolution SLA Breaches: {$stats['resolution_breaches']}\n";

// Check for unassigned high/critical priority tickets
$unassignedQuery = "SELECT COUNT(*) as count 
                    FROM helpdesk_tickets 
                    WHERE assigned_to IS NULL 
                    AND priority IN ('high', 'critical')
                    AND status = 'open'";
$result = $conn->query($unassignedQuery);
$unassigned = $result->fetch_assoc();

echo "Unassigned High/Critical Tickets: {$unassigned['count']}\n";

if ($unassigned['count'] > 0) {
    echo "⚠️  WARNING: There are unassigned high/critical priority tickets!\n";
}

echo "\nSLA Monitoring completed successfully.\n";
