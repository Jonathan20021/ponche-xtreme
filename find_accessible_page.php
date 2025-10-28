<?php
/**
 * Finds the first accessible page for the current user based on their permissions.
 * Returns the URL of the first page they have access to, or null if none found.
 */
function findAccessiblePage(): ?string
{
    global $pdo;
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        return null;
    }
    
    // Define pages in priority order
    $pages = [
        'dashboard' => 'dashboard.php',
        'records' => 'records.php',
        'records_qa' => 'records_qa.php',
        'view_admin_hours' => 'view_admin_hours.php',
        'hr_report' => 'hr_report.php',
        'adherence_report' => 'adherencia_report_hr.php',
        'operations_dashboard' => 'operations_dashboard.php',
        'register_attendance' => 'register_attendance.php',
        'agent_dashboard' => 'agent_dashboard.php',
        'login_logs' => 'login_logs.php',
        'settings' => 'settings.php',
    ];
    
    foreach ($pages as $section => $url) {
        if (userHasPermission($section)) {
            return $url;
        }
    }
    
    return null;
}

/**
 * Clears the permission cache for the current session.
 * Call this after updating permissions to ensure changes take effect immediately.
 */
function clearPermissionCache(): void
{
    // Force a session variable update to invalidate any cached permission data
    $_SESSION['_permission_cache_version'] = time();
}
?>
