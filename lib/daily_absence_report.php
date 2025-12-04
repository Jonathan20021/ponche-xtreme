<?php
/**
 * Daily Absence Report System
 * Genera reportes de empleados que no han ponchado hoy
 * Valida permisos, vacaciones y licencias mÃ©dicas
 */

require_once __DIR__ . '/../db.php';

/**
 * Obtiene todos los empleados activos que deberÃ­an trabajar hoy
 */
function getActiveEmployees(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT 
            e.id,
            e.employee_code,
            e.first_name,
            e.last_name,
            e.position,
            e.department_id,
            e.email,
            d.name as department_name,
            u.id as user_id,
            u.username
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.employment_status = 'active'
        ORDER BY e.last_name, e.first_name
    ");
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Verifica si un empleado tiene algÃºn punch hoy
 */
function hasEmployeePunchedToday(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM attendance 
        WHERE user_id = ? 
        AND DATE(timestamp) = CURDATE()
    ");
    $stmt->execute([$userId]);
    
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Obtiene permisos aprobados que cubren la fecha de hoy
 */
function getApprovedPermissionsForToday(PDO $pdo, int $employeeId): array {
    $stmt = $pdo->prepare("
        SELECT 
            request_type,
            start_date,
            end_date,
            start_time,
            end_time,
            total_days,
            total_hours,
            reason,
            reviewed_at
        FROM permission_requests
        WHERE employee_id = ?
        AND status = 'approved'
        AND CURDATE() BETWEEN start_date AND end_date
        ORDER BY start_date DESC
    ");
    $stmt->execute([$employeeId]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtiene vacaciones aprobadas que cubren la fecha de hoy
 */
function getApprovedVacationsForToday(PDO $pdo, int $employeeId): array {
    $stmt = $pdo->prepare("
        SELECT 
            vacation_type,
            start_date,
            end_date,
            total_days,
            reason,
            reviewed_at
        FROM vacation_requests
        WHERE employee_id = ?
        AND status = 'approved'
        AND CURDATE() BETWEEN start_date AND end_date
        ORDER BY start_date DESC
    ");
    $stmt->execute([$employeeId]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtiene licencias mÃ©dicas activas que cubren la fecha de hoy
 */
function getActiveMedicalLeavesForToday(PDO $pdo, int $employeeId): array {
    $stmt = $pdo->prepare("
        SELECT 
            leave_type,
            diagnosis,
            start_date,
            end_date,
            total_days,
            is_paid,
            payment_percentage,
            doctor_name,
            medical_center,
            status,
            reviewed_at
        FROM medical_leaves
        WHERE employee_id = ?
        AND status IN ('approved', 'active')
        AND CURDATE() BETWEEN start_date AND end_date
        ORDER BY start_date DESC
    ");
    $stmt->execute([$employeeId]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Genera el reporte completo de ausencias del dÃ­a
 */
function generateDailyAbsenceReport(PDO $pdo): array {
    $employees = getActiveEmployees($pdo);
    $absences = [];
    $withJustification = [];
    
    foreach ($employees as $employee) {
        $userId = $employee['user_id'];
        $employeeId = $employee['id'];
        
        // Si no tiene user_id, no puede ponchar
        if (!$userId) {
            continue;
        }
        
        // Verificar si ponchÃ³ hoy
        $hasPunched = hasEmployeePunchedToday($pdo, $userId);
        
        if (!$hasPunched) {
            // Verificar justificaciones
            $permissions = getApprovedPermissionsForToday($pdo, $employeeId);
            $vacations = getApprovedVacationsForToday($pdo, $employeeId);
            $medicalLeaves = getActiveMedicalLeavesForToday($pdo, $employeeId);
            
            $employeeData = [
                'employee_code' => $employee['employee_code'] ?? 'N/A',
                'full_name' => trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')),
                'position' => $employee['position'] ?? 'N/A',
                'department' => $employee['department_name'] ?? 'Sin departamento',
                'email' => $employee['email'] ?? null,
                'permissions' => $permissions,
                'vacations' => $vacations,
                'medical_leaves' => $medicalLeaves,
                'has_justification' => !empty($permissions) || !empty($vacations) || !empty($medicalLeaves)
            ];
            
            if ($employeeData['has_justification']) {
                $withJustification[] = $employeeData;
            } else {
                $absences[] = $employeeData;
            }
        }
    }
    
    return [
        'date' => date('Y-m-d'),
        'date_formatted' => date('l, F j, Y'),
        'total_employees' => count($employees),
        'total_absences' => count($absences) + count($withJustification),
        'absences_without_justification' => $absences,
        'absences_with_justification' => $withJustification,
        'generated_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Genera el HTML del reporte para envío por email
 * Diseño profesional con tablas y toda la información completa
 */
function generateReportHTML(array $reportData): string {
    $date = $reportData['date_formatted'];
    $totalEmployees = $reportData['total_employees'];
    $totalAbsences = $reportData['total_absences'];
    $absencesWithout = $reportData['absences_without_justification'];
    $absencesWith = $reportData['absences_with_justification'];
    
    // Professional HTML with tables
    $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px; }
        .header h1 { margin: 0; font-size: 28px; font-weight: 600; }
        .header p { margin: 10px 0 0 0; font-size: 16px; opacity: 0.95; }
        
        .stats-grid { display: table; width: 100%; margin: 20px 0; border-spacing: 10px; }
        .stat-card { display: table-cell; background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-card.primary { border-top: 4px solid #667eea; }
        .stat-card.danger { border-top: 4px solid #dc3545; }
        .stat-card.warning { border-top: 4px solid #ffc107; }
        .stat-card.success { border-top: 4px solid #28a745; }
        .stat-number { font-size: 32px; font-weight: bold; margin: 10px 0; }
        .stat-label { color: #666; font-size: 14px; text-transform: uppercase; }
        
        .section { background: white; margin: 20px 0; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .section-title { font-size: 20px; font-weight: 600; margin: 0 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid #667eea; }
        .section-title.danger { border-bottom-color: #dc3545; }
        .section-title.success { border-bottom-color: #28a745; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        thead { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        th { color: white; padding: 12px; text-align: left; font-weight: 600; font-size: 14px; }
        td { padding: 12px; border-bottom: 1px solid #e0e0e0; font-size: 13px; }
        tr:hover { background-color: #f8f9fa; }
        tbody tr:last-child td { border-bottom: none; }
        
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge-danger { background: #dc3545; color: white; }
        .badge-success { background: #28a745; color: white; }
        .badge-warning { background: #ffc107; color: #333; }
        .badge-info { background: #17a2b8; color: white; }
        
        .justification-detail { margin: 5px 0; padding: 8px; background: #f8f9fa; border-left: 3px solid #667eea; font-size: 12px; }
        .justification-detail strong { color: #667eea; }
        
        .no-data { text-align: center; padding: 30px; color: #666; font-size: 14px; }
        .no-data-success { background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; }
        
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; margin-top: 20px; }
        
        @media only screen and (max-width: 600px) {
            .stats-grid { display: block; }
            .stat-card { display: block; margin-bottom: 10px; }
            table { font-size: 11px; }
            th, td { padding: 8px; }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Reporte Diario de Ausencias</h1>
            <p>{$date}</p>
        </div>
        
        <div class='stats-grid'>
            <div class='stat-card primary'>
                <div class='stat-label'>Total Empleados</div>
                <div class='stat-number'>{$totalEmployees}</div>
            </div>
            <div class='stat-card danger'>
                <div class='stat-label'>Total Ausencias</div>
                <div class='stat-number'>{$totalAbsences}</div>
            </div>
            <div class='stat-card warning'>
                <div class='stat-label'>Sin Justificar</div>
                <div class='stat-number'>" . count($absencesWithout) . "</div>
            </div>
            <div class='stat-card success'>
                <div class='stat-label'>Justificadas</div>
                <div class='stat-number'>" . count($absencesWith) . "</div>
            </div>
        </div>";
    
    // Ausencias sin justificación
    if (!empty($absencesWithout)) {
        $html .= "
        <div class='section'>
            <h2 class='section-title danger'>Empleados sin Punch - Sin Justificación (" . count($absencesWithout) . ")</h2>
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre Completo</th>
                        <th>Puesto</th>
                        <th>Departamento</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>";
        
        foreach ($absencesWithout as $emp) {
            $statusBadge = "<span class='badge badge-danger'>Sin Justificar</span>";
            $html .= "
                    <tr>
                        <td><strong>{$emp['employee_code']}</strong></td>
                        <td>{$emp['full_name']}</td>
                        <td>{$emp['position']}</td>
                        <td>{$emp['department']}</td>
                        <td>{$statusBadge}</td>
                    </tr>";
        }
        
        $html .= "
                </tbody>
            </table>
        </div>";
    }
    
    // Ausencias con justificación
    if (!empty($absencesWith)) {
        $html .= "
        <div class='section'>
            <h2 class='section-title success'>Empleados sin Punch - Con Justificación (" . count($absencesWith) . ")</h2>
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre Completo</th>
                        <th>Puesto</th>
                        <th>Departamento</th>
                        <th>Justificación</th>
                    </tr>
                </thead>
                <tbody>";
        
        foreach ($absencesWith as $emp) {
            $justificationHTML = "";
            
            // Permisos
            if (!empty($emp['permissions'])) {
                foreach ($emp['permissions'] as $perm) {
                    $justificationHTML .= "
                        <div class='justification-detail'>
                            <span class='badge badge-warning'>Permiso</span>
                            <strong>{$perm['request_type']}</strong><br>
                            Periodo: {$perm['start_date']} al {$perm['end_date']}<br>
                            Estado: {$perm['status']}
                        </div>";
                }
            }
            
            // Vacaciones
            if (!empty($emp['vacations'])) {
                foreach ($emp['vacations'] as $vac) {
                    $type = $vac['vacation_type'] ?? 'Regular';
                    $justificationHTML .= "
                        <div class='justification-detail'>
                            <span class='badge badge-info'>Vacaciones</span>
                            <strong>{$type}</strong><br>
                            Periodo: {$vac['start_date']} al {$vac['end_date']}<br>
                            Estado: {$vac['status']}
                        </div>";
                }
            }
            
            // Licencias médicas
            if (!empty($emp['medical_leaves'])) {
                foreach ($emp['medical_leaves'] as $leave) {
                    $justificationHTML .= "
                        <div class='justification-detail'>
                            <span class='badge badge-success'>Licencia Médica</span>
                            <strong>{$leave['leave_type']}</strong><br>
                            Periodo: {$leave['start_date']} al {$leave['end_date']}<br>
                            Estado: {$leave['status']}
                        </div>";
                }
            }
            
            $html .= "
                    <tr>
                        <td><strong>{$emp['employee_code']}</strong></td>
                        <td>{$emp['full_name']}</td>
                        <td>{$emp['position']}</td>
                        <td>{$emp['department']}</td>
                        <td>{$justificationHTML}</td>
                    </tr>";
        }
        
        $html .= "
                </tbody>
            </table>
        </div>";
    }
    
    // No hay ausencias
    if (empty($absencesWithout) && empty($absencesWith)) {
        $html .= "
        <div class='section'>
            <div class='no-data no-data-success'>
                <h3 style='color: #28a745; margin-top: 0;'>¡Excelente!</h3>
                <p>No hay ausencias registradas para el día de hoy.</p>
                <p>Todos los empleados han realizado su registro de asistencia.</p>
            </div>
        </div>";
    }
    
    $html .= "
        <div class='footer'>
            <p><strong>Reporte generado automáticamente</strong></p>
            <p>" . date('Y-m-d H:i:s') . "</p>
            <p>Sistema de Control de Asistencia - Ponche Evallish BPO</p>
        </div>
    </div>
</body>
</html>";
    
    return $html;
}

/**
 * EnvÃ­a el reporte por correo electrÃ³nico
 */
function sendReportByEmail(PDO $pdo, array $reportData, array $recipients): bool {
    if (empty($recipients)) {
        error_log("No recipients configured for absence report");
        return false;
    }
    
    // Generate HTML content
    $html = generateReportHTML($reportData);
    
    // Use the email functions system
    require_once __DIR__ . '/email_functions.php';
    
    $result = sendDailyAbsenceReport($html, $recipients, $reportData);
    
    if ($result['success']) {
        error_log("Absence report sent successfully: " . $result['message']);
        return true;
    } else {
        error_log("Failed to send absence report: " . $result['message']);
        return false;
    }
}

/**
 * Obtiene los destinatarios configurados del sistema
 */
function getReportRecipients(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT setting_value 
        FROM system_settings 
        WHERE setting_key = 'absence_report_recipients'
    ");
    $stmt->execute();
    $value = $stmt->fetchColumn();
    
    if (empty($value)) {
        return [];
    }
    
    // Parsear emails separados por coma
    $emails = array_map('trim', explode(',', $value));
    return array_filter($emails, function($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    });
}
