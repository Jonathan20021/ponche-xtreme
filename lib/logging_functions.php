<?php
/**
 * Activity Logging Functions
 * Comprehensive logging system for tracking all actions across the application
 */

/**
 * Log an activity to the database
 * 
 * @param PDO $pdo Database connection
 * @param int $user_id ID of the user performing the action
 * @param string $user_name Name of the user
 * @param string $user_role Role of the user (admin, hr, agent, etc)
 * @param string $module Module name (employees, schedules, payroll, recruitment, etc)
 * @param string $action Action performed (create, update, delete, generate, etc)
 * @param string $description Human-readable description of the action
 * @param string|null $entity_type Type of entity affected (employee, schedule, payroll_period, etc)
 * @param int|null $entity_id ID of the entity affected
 * @param array|null $old_values Old values before change (for updates)
 * @param array|null $new_values New values after change (for creates/updates)
 * @return bool Success status
 */
function log_activity($pdo, $user_id, $user_name, $user_role, $module, $action, $description, $entity_type = null, $entity_id = null, $old_values = null, $new_values = null) {
    try {
        $sql = "INSERT INTO activity_logs 
                (user_id, user_name, user_role, module, action, description, entity_type, entity_id, old_values, new_values, ip_address, user_agent) 
                VALUES 
                (:user_id, :user_name, :user_role, :module, :action, :description, :entity_type, :entity_id, :old_values, :new_values, :ip_address, :user_agent)";
        
        $stmt = $pdo->prepare($sql);
        
        $ip_address = get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':user_name' => $user_name,
            ':user_role' => $user_role,
            ':module' => $module,
            ':action' => $action,
            ':description' => $description,
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id,
            ':old_values' => $old_values ? json_encode($old_values, JSON_UNESCAPED_UNICODE) : null,
            ':new_values' => $new_values ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null,
            ':ip_address' => $ip_address,
            ':user_agent' => $user_agent
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Get client IP address
 */
function get_client_ip() {
    $ip = 'Unknown';
    
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    return $ip;
}

/**
 * Log employee creation
 */
function log_employee_created($pdo, $user_id, $user_name, $user_role, $employee_id, $employee_data) {
    $description = "Nuevo empleado creado: {$employee_data['name']} (ID: {$employee_id})";
    return log_activity($pdo, $user_id, $user_name, $user_role, 'employees', 'create', $description, 'employee', $employee_id, null, $employee_data);
}

/**
 * Log employee update
 */
function log_employee_updated($pdo, $user_id, $user_name, $user_role, $employee_id, $old_data, $new_data) {
    $changes = array_diff_assoc($new_data, $old_data);
    $change_list = [];
    foreach ($changes as $field => $value) {
        $change_list[] = "{$field}: '{$old_data[$field]}' → '{$value}'";
    }
    $description = "Empleado actualizado (ID: {$employee_id}): " . implode(', ', $change_list);
    return log_activity($pdo, $user_id, $user_name, $user_role, 'employees', 'update', $description, 'employee', $employee_id, $old_data, $new_data);
}

/**
 * Log employee deletion
 */
function log_employee_deleted($pdo, $user_id, $user_name, $user_role, $employee_id, $employee_data) {
    $description = "Empleado eliminado: {$employee_data['name']} (ID: {$employee_id})";
    return log_activity($pdo, $user_id, $user_name, $user_role, 'employees', 'delete', $description, 'employee', $employee_id, $employee_data, null);
}

/**
 * Log schedule change
 */
function log_schedule_changed($pdo, $user_id, $user_name, $user_role, $employee_id, $employee_name, $old_schedule, $new_schedule) {
    $description = "Horario modificado para {$employee_name} (ID: {$employee_id})";
    return log_activity($pdo, $user_id, $user_name, $user_role, 'schedules', 'update', $description, 'schedule', $employee_id, $old_schedule, $new_schedule);
}

/**
 * Log payroll generation
 */
function log_payroll_generated($pdo, $user_id, $user_name, $user_role, $period_start, $period_end, $employee_count) {
    $description = "Nómina generada para el período {$period_start} - {$period_end} ({$employee_count} empleados)";
    return log_activity($pdo, $user_id, $user_name, $user_role, 'payroll', 'generate', $description, 'payroll_period', null, null, ['period_start' => $period_start, 'period_end' => $period_end, 'employee_count' => $employee_count]);
}

/**
 * Log contract/recruitment action
 */
function log_recruitment_action($pdo, $user_id, $user_name, $user_role, $action, $candidate_id, $candidate_name, $details = []) {
    $action_labels = [
        'application_received' => 'Solicitud recibida',
        'status_changed' => 'Estado cambiado',
        'interview_scheduled' => 'Entrevista programada',
        'hired' => 'Contratado',
        'rejected' => 'Rechazado'
    ];
    
    $action_label = $action_labels[$action] ?? $action;
    $description = "{$action_label}: {$candidate_name} (ID: {$candidate_id})";
    
    return log_activity($pdo, $user_id, $user_name, $user_role, 'recruitment', $action, $description, 'candidate', $candidate_id, null, $details);
}

/**
 * Log medical leave action
 */
function log_medical_leave_action($pdo, $user_id, $user_name, $user_role, $action, $leave_id, $employee_name, $details = []) {
    $action_labels = [
        'create' => 'Permiso médico creado',
        'update' => 'Permiso médico actualizado',
        'approve' => 'Permiso médico aprobado',
        'reject' => 'Permiso médico rechazado',
        'delete' => 'Permiso médico eliminado'
    ];
    
    $action_label = $action_labels[$action] ?? $action;
    $description = "{$action_label} para {$employee_name} (ID: {$leave_id})";
    
    return log_activity($pdo, $user_id, $user_name, $user_role, 'medical_leaves', $action, $description, 'medical_leave', $leave_id, null, $details);
}

/**
 * Log overtime action
 */
function log_overtime_action($pdo, $user_id, $user_name, $user_role, $action, $overtime_id, $employee_name, $details = []) {
    $action_labels = [
        'create' => 'Tiempo extra registrado',
        'update' => 'Tiempo extra actualizado',
        'approve' => 'Tiempo extra aprobado',
        'reject' => 'Tiempo extra rechazado',
        'delete' => 'Tiempo extra eliminado'
    ];
    
    $action_label = $action_labels[$action] ?? $action;
    $description = "{$action_label} para {$employee_name} (ID: {$overtime_id})";
    
    return log_activity($pdo, $user_id, $user_name, $user_role, 'overtime', $action, $description, 'overtime', $overtime_id, null, $details);
}

/**
 * Log user activation/deactivation
 */
function log_user_activation($pdo, $user_id, $user_name, $user_role, $target_user_id, $target_user_name, $is_active) {
    $action = $is_active ? 'Usuario activado' : 'Usuario desactivado';
    $description = "{$action}: {$target_user_name} (ID: {$target_user_id})";
    
    return log_activity($pdo, $user_id, $user_name, $user_role, 'users', $is_active ? 'activate' : 'deactivate', $description, 'user', $target_user_id, null, ['is_active' => $is_active]);
}

/**
 * Log permission change
 */
function log_permission_changed($pdo, $user_id, $user_name, $user_role, $target_user_id, $target_user_name, $old_permissions, $new_permissions) {
    $description = "Permisos modificados para {$target_user_name} (ID: {$target_user_id})";
    
    return log_activity($pdo, $user_id, $user_name, $user_role, 'permissions', 'update', $description, 'user', $target_user_id, $old_permissions, $new_permissions);
}

/**
 * Log attendance record modification
 */
function log_attendance_modified($pdo, $user_id, $user_name, $user_role, $record_id, $employee_name, $old_data, $new_data) {
    $description = "Registro de asistencia modificado para {$employee_name} (ID: {$record_id})";
    
    return log_activity($pdo, $user_id, $user_name, $user_role, 'attendance', 'update', $description, 'attendance_record', $record_id, $old_data, $new_data);
}

/**
 * Log calendar event action
 */
function log_calendar_event($pdo, $user_id, $user_name, $user_role, $action, $event_id, $event_title, $details = []) {
    $action_labels = [
        'create' => 'Evento creado',
        'update' => 'Evento actualizado',
        'delete' => 'Evento eliminado'
    ];
    
    $action_label = $action_labels[$action] ?? $action;
    $description = "{$action_label}: {$event_title} (ID: {$event_id})";
    
    return log_activity($pdo, $user_id, $user_name, $user_role, 'calendar', $action, $description, 'calendar_event', $event_id, null, $details);
}

/**
 * Log bank information change
 */
function log_bank_info_changed($pdo, $user_id, $user_name, $user_role, $employee_id, $employee_name, $old_data, $new_data) {
    $description = "Información bancaria actualizada para {$employee_name} (ID: {$employee_id})";
    
    return log_activity($pdo, $user_id, $user_name, $user_role, 'banking', 'update', $description, 'employee', $employee_id, $old_data, $new_data);
}

/**
 * Log rate history change
 */
function log_rate_changed($pdo, $user_id, $user_name, $user_role, $employee_id, $employee_name, $old_rate, $new_rate, $effective_date) {
    $description = "Tarifa modificada para {$employee_name} (ID: {$employee_id}): \${$old_rate} → \${$new_rate} (Efectivo: {$effective_date})";
    
    return log_activity($pdo, $user_id, $user_name, $user_role, 'rates', 'update', $description, 'employee', $employee_id, ['rate' => $old_rate], ['rate' => $new_rate, 'effective_date' => $effective_date]);
}

/**
 * Generic log function for custom actions
 */
function log_custom_action($pdo, $user_id, $user_name, $user_role, $module, $action, $description, $entity_type = null, $entity_id = null, $details = []) {
    return log_activity($pdo, $user_id, $user_name, $user_role, $module, $action, $description, $entity_type, $entity_id, null, $details);
}
