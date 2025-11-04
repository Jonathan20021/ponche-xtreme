<?php
/**
 * HR Assistant Helper Functions
 * Provides data retrieval functions for the HR virtual assistant
 */

require_once __DIR__ . '/../db.php';

/**
 * Get employee vacation balance
 */
function getEmployeeVacationBalance($pdo, $userId) {
    try {
        // Get employee_id from user_id
        $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = ?");
        $empStmt->execute([$userId]);
        $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$employee) {
            return null;
        }
        
        $employeeId = $employee['id'];
        $currentYear = date('Y');
        
        // Get vacation balance for current year
        $stmt = $pdo->prepare("
            SELECT 
                u.username,
                u.full_name,
                COALESCE(vb.total_days, 14.00) as total_days,
                COALESCE(vb.used_days, 0.00) as used_days,
                COALESCE(vb.remaining_days, 14.00) as remaining_days,
                (
                    SELECT COUNT(*) 
                    FROM vacation_requests vr 
                    WHERE vr.employee_id = ? 
                    AND vr.status = 'PENDING'
                    AND YEAR(vr.start_date) = ?
                ) as pending_requests
            FROM users u
            LEFT JOIN vacation_balances vb ON vb.employee_id = ? AND vb.year = ?
            WHERE u.id = ?
        ");
        $stmt->execute([$employeeId, $currentYear, $employeeId, $currentYear, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Get employee upcoming evaluations (based on trial period or hire date)
 */
function getEmployeeEvaluations($pdo, $userId) {
    try {
        // Get employee info
        $stmt = $pdo->prepare("
            SELECT 
                e.hire_date,
                e.employment_status,
                DATEDIFF(CURDATE(), e.hire_date) as days_employed,
                DATE_ADD(e.hire_date, INTERVAL 90 DAY) as trial_end_date,
                DATE_ADD(e.hire_date, INTERVAL 6 MONTH) as six_month_review,
                DATE_ADD(e.hire_date, INTERVAL 1 YEAR) as annual_review
            FROM employees e
            WHERE e.user_id = ?
        ");
        $stmt->execute([$userId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$employee) {
            return [];
        }
        
        $evaluations = [];
        $today = new DateTime();
        
        // Trial period evaluation (90 days)
        if ($employee['employment_status'] === 'TRIAL') {
            $trialEnd = new DateTime($employee['trial_end_date']);
            if ($trialEnd >= $today) {
                $evaluations[] = [
                    'evaluation_date' => $employee['trial_end_date'],
                    'evaluation_type' => 'Fin de Período de Prueba',
                    'status' => 'Pendiente',
                    'notes' => 'Evaluación de finalización del período de prueba (90 días)'
                ];
            }
        }
        
        // 6-month review
        $sixMonthReview = new DateTime($employee['six_month_review']);
        if ($sixMonthReview >= $today) {
            $evaluations[] = [
                'evaluation_date' => $employee['six_month_review'],
                'evaluation_type' => 'Evaluación Semestral',
                'status' => 'Pendiente',
                'notes' => 'Evaluación de desempeño a los 6 meses'
            ];
        }
        
        // Annual review
        $annualReview = new DateTime($employee['annual_review']);
        if ($annualReview >= $today) {
            $evaluations[] = [
                'evaluation_date' => $employee['annual_review'],
                'evaluation_type' => 'Evaluación Anual',
                'status' => 'Pendiente',
                'notes' => 'Evaluación de desempeño anual'
            ];
        }
        
        return $evaluations;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get employee basic information
 */
function getEmployeeInfo($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.username,
                u.full_name,
                u.employee_code,
                u.role,
                u.hourly_rate,
                u.monthly_salary,
                u.hourly_rate_dop,
                u.monthly_salary_dop,
                u.preferred_currency,
                e.email,
                e.phone,
                e.mobile,
                e.birth_date,
                e.hire_date,
                e.position,
                e.employment_status,
                e.employment_type,
                d.name as department_name,
                DATEDIFF(CURDATE(), e.hire_date) as days_employed,
                TIMESTAMPDIFF(MONTH, e.hire_date, CURDATE()) as months_employed
            FROM users u
            LEFT JOIN employees e ON e.user_id = u.id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Get employee permissions/requests
 */
function getEmployeePermissions($pdo, $userId, $limit = 10) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                pr.id,
                pr.request_type,
                pr.start_date,
                pr.end_date,
                pr.start_time,
                pr.end_time,
                pr.total_days,
                pr.total_hours,
                pr.reason,
                pr.status,
                pr.reviewed_at,
                pr.review_notes,
                pr.created_at,
                reviewer.full_name as reviewed_by_name
            FROM permission_requests pr
            LEFT JOIN users reviewer ON reviewer.id = pr.reviewed_by
            WHERE pr.user_id = ?
            ORDER BY pr.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get employee schedule
 */
function getEmployeeScheduleInfo($pdo, $userId) {
    try {
        $schedule = getScheduleConfigForUser($pdo, $userId);
        return $schedule;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get employee attendance summary
 */
function getEmployeeAttendanceSummary($pdo, $userId, $days = 30) {
    try {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT DATE(timestamp)) as days_with_records,
                SUM(CASE WHEN type = 'ENTRY' THEN 1 ELSE 0 END) as entry_count,
                SUM(CASE WHEN type = 'EXIT' THEN 1 ELSE 0 END) as exit_count,
                SUM(CASE WHEN type = 'BREAK' THEN 1 ELSE 0 END) as break_count,
                SUM(CASE WHEN type = 'PAUSA' THEN 1 ELSE 0 END) as lunch_count,
                MIN(CASE WHEN type = 'ENTRY' THEN timestamp END) as first_entry,
                MAX(CASE WHEN type = 'EXIT' THEN timestamp END) as last_exit
            FROM attendance
            WHERE user_id = ?
            AND DATE(timestamp) >= ?
        ");
        $stmt->execute([$userId, $startDate]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Get company policies information
 */
function getCompanyPolicies($pdo) {
    $policies = [
        'vacation_policy' => 'Los empleados tienen derecho a 14 días de vacaciones por año trabajado.',
        'permission_request' => 'Las solicitudes de permiso deben hacerse con al menos 48 horas de anticipación a través del sistema.',
        'sick_leave' => 'Para ausencias por enfermedad, debe notificar a RH y presentar certificado médico si supera 2 días.',
        'work_schedule' => 'El horario estándar es de 10:00 AM a 7:00 PM con 45 minutos de almuerzo.',
        'evaluation_process' => 'Las evaluaciones de desempeño se realizan cada 6 meses.'
    ];
    return $policies;
}

/**
 * Get employee benefits (based on employment data)
 */
function getEmployeeBenefits($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                e.employment_status,
                e.employment_type,
                u.hourly_rate,
                u.monthly_salary,
                u.preferred_currency,
                e.bank_account_number,
                b.name as bank_name
            FROM employees e
            JOIN users u ON u.id = e.user_id
            LEFT JOIN banks b ON b.id = e.bank_id
            WHERE e.user_id = ?
        ");
        $stmt->execute([$userId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$employee) {
            return [];
        }
        
        $benefits = [];
        
        // Standard benefits based on employment type
        if ($employee['employment_type'] === 'FULL_TIME') {
            $benefits[] = [
                'benefit_type' => 'Vacaciones',
                'benefit_value' => '14 días anuales',
                'description' => 'Días de vacaciones pagadas por año'
            ];
            $benefits[] = [
                'benefit_type' => 'Seguro de Salud (SFS)',
                'benefit_value' => 'Cobertura completa',
                'description' => 'Seguro Familiar de Salud según ley dominicana'
            ];
            $benefits[] = [
                'benefit_type' => 'Pensiones (AFP)',
                'benefit_value' => 'Aporte obligatorio',
                'description' => 'Fondo de pensiones según ley dominicana'
            ];
        }
        
        return $benefits;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get upcoming company events
 */
function getUpcomingEvents($pdo, $limit = 5) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                ce.title,
                ce.description,
                ce.event_date,
                ce.start_time,
                ce.end_time,
                ce.event_type,
                ce.location,
                ce.is_all_day,
                u.full_name as created_by_name
            FROM calendar_events ce
            LEFT JOIN users u ON u.id = ce.created_by
            WHERE ce.event_date >= CURDATE()
            ORDER BY ce.event_date ASC, ce.start_time ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get employee documents
 */
function getEmployeeDocuments($pdo, $userId) {
    try {
        // Get employee_id from user_id
        $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = ?");
        $empStmt->execute([$userId]);
        $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$employee) {
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                ed.document_type,
                ed.document_name,
                ed.file_size,
                ed.file_extension,
                ed.uploaded_at,
                ed.description,
                uploader.full_name as uploaded_by_name
            FROM employee_documents ed
            LEFT JOIN users uploader ON uploader.id = ed.uploaded_by
            WHERE ed.employee_id = ?
            ORDER BY ed.uploaded_at DESC
        ");
        $stmt->execute([$employee['id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Search HR knowledge base
 */
function searchHRKnowledge($pdo, $query) {
    try {
        $searchTerm = "%{$query}%";
        $stmt = $pdo->prepare("
            SELECT 
                question,
                answer,
                category
            FROM hr_knowledge_base
            WHERE question LIKE ? OR answer LIKE ? OR keywords LIKE ?
            ORDER BY priority DESC
            LIMIT 5
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get vacation requests for employee
 */
function getEmployeeVacationRequests($pdo, $userId, $limit = 5) {
    try {
        $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = ?");
        $empStmt->execute([$userId]);
        $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$employee) {
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                vr.start_date,
                vr.end_date,
                vr.total_days,
                vr.vacation_type,
                vr.status,
                vr.reviewed_at,
                vr.created_at,
                reviewer.full_name as reviewed_by_name
            FROM vacation_requests vr
            LEFT JOIN users reviewer ON reviewer.id = vr.reviewed_by
            WHERE vr.employee_id = ?
            ORDER BY vr.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$employee['id'], $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get medical leaves for employee
 */
function getEmployeeMedicalLeaves($pdo, $userId, $limit = 5) {
    try {
        $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = ?");
        $empStmt->execute([$userId]);
        $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$employee) {
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                ml.leave_type,
                ml.diagnosis,
                ml.start_date,
                ml.end_date,
                ml.total_days,
                ml.status,
                ml.medical_center,
                ml.doctor_name
            FROM medical_leaves ml
            WHERE ml.employee_id = ?
            ORDER BY ml.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$employee['id'], $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get context for AI assistant
 */
function getEmployeeContext($pdo, $userId) {
    $context = [
        'employee_info' => getEmployeeInfo($pdo, $userId),
        'vacation_balance' => getEmployeeVacationBalance($pdo, $userId),
        'vacation_requests' => getEmployeeVacationRequests($pdo, $userId, 5),
        'schedule' => getEmployeeScheduleInfo($pdo, $userId),
        'recent_permissions' => getEmployeePermissions($pdo, $userId, 5),
        'medical_leaves' => getEmployeeMedicalLeaves($pdo, $userId, 3),
        'attendance_summary' => getEmployeeAttendanceSummary($pdo, $userId, 30),
        'benefits' => getEmployeeBenefits($pdo, $userId),
        'upcoming_events' => getUpcomingEvents($pdo, 3),
        'evaluations' => getEmployeeEvaluations($pdo, $userId),
        'documents' => getEmployeeDocuments($pdo, $userId),
        'policies' => getCompanyPolicies($pdo)
    ];
    
    return $context;
}
