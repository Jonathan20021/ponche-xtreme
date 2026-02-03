<?php
/**
 * API para el Dashboard Ejecutivo
 * Retorna métricas completas de nómina, costos por campaña y monitor en tiempo real/histórico
 */
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once 'db.php';
require_once 'lib/authorization_functions.php';
require_once 'quality_db.php';

// Verificar permisos ejecutivos
if (!isset($_SESSION['user_id']) || !userHasPermission('executive_dashboard')) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

try {
    // Obtener parámetros de fecha
    $startDate = $_GET['start_date'] ?? date('Y-m-d');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    
    // Validar fechas
    if (!strtotime($startDate) || !strtotime($endDate)) {
        throw new Exception("Fechas inválidas");
    }
    
    // Asegurar que start <= end
    if ($startDate > $endDate) {
        $temp = $startDate;
        $startDate = $endDate;
        $endDate = $temp;
    }

    $isToday = ($startDate === date('Y-m-d') && $endDate === date('Y-m-d'));

    // Obtener tipos de punch pagados
    $paidTypes = getPaidAttendanceTypeSlugs($pdo);
    
    // Obtener todos los tipos de attendance con sus propiedades
    $attendanceTypes = getAttendanceTypes($pdo, true);
    $typesMap = [];
    foreach ($attendanceTypes as $type) {
        $slug = strtoupper($type['slug']);
        $typesMap[$slug] = [
            'slug' => $slug,
            'label' => $type['label'],
            'icon' => $type['icon_class'],
            'color_start' => $type['color_start'],
            'color_end' => $type['color_end'],
            'is_paid' => (int)$type['is_paid']
        ];
    }
    
    // 1. Obtener métricas diarias para gráficos (Trend)
    $dailyMetrics = getDailyMetrics($pdo, $startDate, $endDate, $paidTypes);
    
    // 2. Obtener empleados y sus totales en el rango
    // Si es hoy, queremos el estado actual. Si es histórico, queremos el resumen del periodo.
    $employeesData = getEmployeesData($pdo, $startDate, $endDate, $paidTypes, $isToday, $typesMap);
    
    // 3. Calcular resumen total
    $summary = [
        'total_employees' => count($employeesData['list']),
        'active_now' => $employeesData['active_count'], // Solo relevante si es hoy
        'total_hours_usd' => $employeesData['total_hours_usd'],
        'total_hours_dop' => $employeesData['total_hours_dop'],
        'total_earnings_usd' => $employeesData['total_earnings_usd'],
        'total_earnings_dop' => $employeesData['total_earnings_dop'],
        'total_earnings_usd_formatted' => formatMoney($employeesData['total_earnings_usd'], 'USD'),
        'total_earnings_dop_formatted' => formatMoney($employeesData['total_earnings_dop'], 'DOP'),
        'total_campaigns' => count($employeesData['campaigns']),
        'period_start' => $startDate,
        'period_end' => $endDate
    ];
    
    // 4. Obtener estadísticas de nómina (del mes de la fecha fin)
    $payrollStats = getPayrollStats($pdo, date('Y-m', strtotime($endDate)));

    // 5. Métricas operativas adicionales
    $workforceStats = getWorkforceSummary($pdo, $startDate, $endDate);
    $attendanceSummary = getAttendanceTypeSummary($pdo, $startDate, $endDate);
    $departmentSummary = getDepartmentSummary($pdo);

    // Top campañas por costo y horas
    $campaignsTop = buildTopCampaigns($employeesData['campaigns']);

    // 6. Métricas de calidad (base separada)
    $qualityPdo = getQualityDbConnection();
    $qualityMetrics = getQualityDashboardMetrics($qualityPdo, $startDate, $endDate);
    
    // Respuesta final
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'is_today' => $isToday,
        'employees' => $employeesData['list'],
        'campaigns' => array_values($employeesData['campaigns']),
        'charts' => [
            'daily_trend' => $dailyMetrics['trend'],
            'cost_distribution' => $dailyMetrics['cost_distribution']
        ],
        'summary' => $summary,
        'payroll' => $payrollStats,
        'workforce' => $workforceStats,
        'attendance' => $attendanceSummary,
        'departments' => $departmentSummary,
        'campaigns_top' => $campaignsTop,
        'quality' => $qualityMetrics
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error al obtener datos',
        'message' => $e->getMessage()
    ]);
}

/**
 * Obtiene datos de empleados y métricas agregadas
 */
function getEmployeesData($pdo, $startDate, $endDate, $paidTypes, $isToday, $typesMap) {
    // Consulta base para empleados activos o que tuvieron actividad
    $query = "
        SELECT 
            e.id as employee_id,
            e.first_name,
            e.last_name,
            e.photo_path,
            e.position,
            e.hire_date,
            e.employment_status,
            u.id as user_id,
            u.username,
            u.full_name,
            u.hourly_rate,
            u.hourly_rate_dop,
            u.preferred_currency,
            d.name as department_name,
            c.id as campaign_id,
            c.name as campaign_name,
            c.code as campaign_code,
            c.color as campaign_color,
            -- Último punch (solo relevante para estado actual)
            (SELECT a.type 
             FROM attendance a 
             WHERE a.user_id = u.id 
             ORDER BY a.timestamp DESC 
             LIMIT 1) as last_punch_type,
            (SELECT a.timestamp 
             FROM attendance a 
             WHERE a.user_id = u.id 
             ORDER BY a.timestamp DESC 
             LIMIT 1) as last_punch_time
        FROM employees e
        INNER JOIN users u ON u.id = e.user_id
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN campaigns c ON e.campaign_id = c.id
        WHERE (e.employment_status IN ('ACTIVE', 'TRIAL') OR 
               EXISTS (SELECT 1 FROM attendance a3 WHERE a3.user_id = u.id AND DATE(a3.timestamp) BETWEEN ? AND ?))
        AND u.is_active = 1
        ORDER BY c.name ASC, e.first_name ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$startDate, $endDate]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    $campaignStats = [];
    $totalHoursUSD = 0;
    $totalHoursDOP = 0;
    $totalEarningsUSD = 0;
    $totalEarningsDOP = 0;
    $activeCount = 0;
    
    foreach ($employees as $emp) {
        $userId = (int)$emp['user_id'];
        $currency = $emp['preferred_currency'] ?? 'USD';
        $hourlyRate = $currency === 'DOP' ? (float)$emp['hourly_rate_dop'] : (float)$emp['hourly_rate'];
        
        // Calcular horas trabajadas en el rango
        $hoursWorked = calculateRangePaidHours($pdo, $userId, $startDate, $endDate, $paidTypes);
        
        // Si es rango histórico y no tiene horas, y no está activo, saltar (opcional, pero limpia la vista)
        // Pero si es "Hoy", queremos ver a todos los activos aunque lleven 0 horas.
        if (!$isToday && $hoursWorked == 0 && $emp['employment_status'] !== 'ACTIVE') {
            continue;
        }

        // Calcular ingresos
        $earnings = $hoursWorked * $hourlyRate;
        
        // Acumular totales
        if ($currency === 'DOP') {
            $totalHoursDOP += $hoursWorked;
            $totalEarningsDOP += $earnings;
        } else {
            $totalHoursUSD += $hoursWorked;
            $totalEarningsUSD += $earnings;
        }
        
        // Determinar estado (solo si es hoy o fecha fin es hoy)
        $status = 'offline';
        $statusLabel = 'Desconectado';
        $punchType = strtoupper($emp['last_punch_type'] ?? 'NONE');
        $lastPunchTime = $emp['last_punch_time'];
        
        if ($isToday) {
            $isPunchToday = $lastPunchTime && date('Y-m-d', strtotime($lastPunchTime)) === date('Y-m-d');
            
            if (!$lastPunchTime) {
                $status = 'not_today';
                $statusLabel = 'Sin registros';
            } elseif (!$isPunchToday) {
                $status = 'not_today';
                $statusLabel = 'No ha marcado hoy';
            } elseif ($punchType === 'EXIT') {
                $status = 'completed';
                $statusLabel = 'Jornada Finalizada';
            } else {
                $status = 'active';
                $statusLabel = 'Activo';
                $activeCount++;
            }
        } else {
            // En histórico, el estado "en tiempo real" es menos relevante, mostramos resumen
            $status = 'historical';
            $statusLabel = 'Histórico';
        }
        
        // Información del tipo de punch actual
        $typeInfo = $typesMap[$punchType] ?? [
            'slug' => $punchType,
            'label' => $punchType,
            'icon' => 'fa-circle',
            'color_start' => '#6b7280',
            'color_end' => '#6b7280',
            'is_paid' => 0
        ];
        
        // Calcular duración en estado actual (solo si es hoy)
        $durationFormatted = '-';
        if ($isToday && $status === 'active' && $lastPunchTime) {
            $secondsInState = time() - strtotime($lastPunchTime);
            $durationFormatted = formatDuration($secondsInState);
        }
        
        // Información de campaña
        $campaignId = $emp['campaign_id'] ?? 0;
        $campaignName = $emp['campaign_name'] ?? 'Sin Campaña';
        $campaignCode = $emp['campaign_code'] ?? 'N/A';
        $campaignColor = $emp['campaign_color'] ?? '#6b7280';
        
        // Estadísticas por campaña
        if (!isset($campaignStats[$campaignId])) {
            $campaignStats[$campaignId] = [
                'id' => $campaignId,
                'name' => $campaignName,
                'code' => $campaignCode,
                'color' => $campaignColor,
                'employees' => 0,
                'active_employees' => 0,
                'total_hours' => 0,
                'total_cost_usd' => 0,
                'total_cost_dop' => 0,
                'avg_hourly_rate' => 0,
                'employee_list' => []
            ];
        }
        
        $campaignStats[$campaignId]['employees']++;
        $campaignStats[$campaignId]['total_hours'] += $hoursWorked;
        
        if ($status === 'active') {
            $campaignStats[$campaignId]['active_employees']++;
        }
        
        if ($currency === 'DOP') {
            $campaignStats[$campaignId]['total_cost_dop'] += $earnings;
        } else {
            $campaignStats[$campaignId]['total_cost_usd'] += $earnings;
        }
        
        $employeeData = [
            'employee_id' => (int)$emp['employee_id'],
            'user_id' => $userId,
            'username' => $emp['username'],
            'full_name' => $emp['full_name'] ?: ($emp['first_name'] . ' ' . $emp['last_name']),
            'first_name' => $emp['first_name'],
            'last_name' => $emp['last_name'],
            'position' => $emp['position'],
            'hire_date' => $emp['hire_date'],
            'employment_status' => $emp['employment_status'],
            'photo_path' => $emp['photo_path'],
            'department' => $emp['department_name'],
            'campaign' => [
                'id' => $campaignId,
                'name' => $campaignName,
                'code' => $campaignCode,
                'color' => $campaignColor
            ],
            'last_activity' => $lastPunchTime,
            'last_punch_type' => $punchType,
            'duration_in_state' => $durationFormatted,
            'punch_type_info' => $typeInfo,
            'status' => $status,
            'status_label' => $statusLabel,
            'hours_worked' => round($hoursWorked, 2),
            'hours_formatted' => formatHours($hoursWorked),
            'hourly_rate' => $hourlyRate,
            'currency' => $currency,
            'earnings' => round($earnings, 2),
            'earnings_formatted' => formatMoney($earnings, $currency)
        ];
        
        $campaignStats[$campaignId]['employee_list'][] = $employeeData;
        $result[] = $employeeData;
    }
    
    // Calcular promedios para campañas
    foreach ($campaignStats as &$campaign) {
        if ($campaign['employees'] > 0) {
            $totalRates = 0;
            foreach ($campaign['employee_list'] as $emp) {
                $totalRates += $emp['hourly_rate']; // Nota: esto mezcla monedas si hay mix en campaña, idealmente separar
            }
            $campaign['avg_hourly_rate'] = round($totalRates / $campaign['employees'], 2);
        }
    }
    
    return [
        'list' => $result,
        'campaigns' => $campaignStats,
        'active_count' => $activeCount,
        'total_hours_usd' => round($totalHoursUSD, 2),
        'total_hours_dop' => round($totalHoursDOP, 2),
        'total_earnings_usd' => round($totalEarningsUSD, 2),
        'total_earnings_dop' => round($totalEarningsDOP, 2)
    ];
}

/**
 * Obtiene métricas diarias para gráficos
 */
function getDailyMetrics($pdo, $startDate, $endDate, $paidTypes) {
    // Generar array de fechas
    $period = new DatePeriod(
        new DateTime($startDate),
        new DateInterval('P1D'),
        (new DateTime($endDate))->modify('+1 day')
    );
    
    $trend = [];
    $costDist = ['USD' => 0, 'DOP' => 0];
    
    foreach ($period as $date) {
        $dateStr = $date->format('Y-m-d');
        
        // Obtener todos los punches del día para todos los usuarios
        // Esta consulta puede ser pesada si hay muchos datos, optimizar si es necesario
        // Para optimizar: hacer una sola query agrupada por día y usuario
        
        // Query optimizada para obtener horas por usuario por día
        // Nota: calcular horas exactas desde SQL es complejo por la lógica de pares. 
        // Por ahora iteraremos usuarios activos ese día.
        
        // 1. Identificar usuarios con actividad este día
        $stmt = $pdo->prepare("
            SELECT DISTINCT user_id 
            FROM attendance 
            WHERE DATE(timestamp) = ?
        ");
        $stmt->execute([$dateStr]);
        $activeUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $dayHoursUSD = 0;
        $dayHoursDOP = 0;
        $dayCostUSD = 0;
        $dayCostDOP = 0;
        $activeCount = count($activeUsers);
        
        foreach ($activeUsers as $uid) {
            // Obtener info de usuario (tarifa y moneda)
            // Cachear esto sería ideal
            $uStmt = $pdo->prepare("SELECT hourly_rate, hourly_rate_dop, preferred_currency FROM users WHERE id = ?");
            $uStmt->execute([$uid]);
            $uData = $uStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($uData) {
                $hours = calculateDailyPaidHours($pdo, $uid, $dateStr, $paidTypes);
                $currency = $uData['preferred_currency'] ?? 'USD';
                $rate = $currency === 'DOP' ? $uData['hourly_rate_dop'] : $uData['hourly_rate'];
                $cost = $hours * $rate;
                
                if ($currency === 'DOP') {
                    $dayHoursDOP += $hours;
                    $dayCostDOP += $cost;
                } else {
                    $dayHoursUSD += $hours;
                    $dayCostUSD += $cost;
                }
            }
        }
        
        $trend[] = [
            'date' => $dateStr,
            'active_employees' => $activeCount,
            'hours_usd' => round($dayHoursUSD, 2),
            'hours_dop' => round($dayHoursDOP, 2),
            'cost_usd' => round($dayCostUSD, 2),
            'cost_dop' => round($dayCostDOP, 2)
        ];
        
        $costDist['USD'] += $dayCostUSD;
        $costDist['DOP'] += $dayCostDOP;
    }
    
    return [
        'trend' => $trend,
        'cost_distribution' => $costDist
    ];
}

/**
 * Calcula horas en un rango de fechas
 */
function calculateRangePaidHours($pdo, $userId, $startDate, $endDate, $paidTypes) {
    $totalHours = 0;
    $period = new DatePeriod(
        new DateTime($startDate),
        new DateInterval('P1D'),
        (new DateTime($endDate))->modify('+1 day')
    );
    
    foreach ($period as $date) {
        $totalHours += calculateDailyPaidHours($pdo, $userId, $date->format('Y-m-d'), $paidTypes);
    }
    
    return $totalHours;
}

/**
 * Calcula las horas trabajadas en un día específico, solo contando punches pagados
 */
function calculateDailyPaidHours($pdo, $userId, $date, $paidTypes) {
    // Obtener todos los punches del día
    $stmt = $pdo->prepare("
        SELECT type, timestamp 
        FROM attendance 
        WHERE user_id = ? 
        AND DATE(timestamp) = ?
        ORDER BY timestamp ASC
    ");
    $stmt->execute([$userId, $date]);
    $punches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($punches)) {
        return 0;
    }
    
    $totalSeconds = 0;
    $lastPaidPunch = null;
    
    foreach ($punches as $punch) {
        $type = strtoupper($punch['type']);
        $timestamp = strtotime($punch['timestamp']);
        
        // Si es un tipo pagado
        if (in_array($type, $paidTypes)) {
            // Si ya había un punch pagado anterior, calcular el intervalo
            if ($lastPaidPunch !== null) {
                $interval = $timestamp - $lastPaidPunch;
                $totalSeconds += $interval;
            }
            $lastPaidPunch = $timestamp;
        } else {
            // Tipo no pagado (pausa, break, etc.)
            // Si había un punch pagado activo, cerrarlo
            if ($lastPaidPunch !== null) {
                $interval = $timestamp - $lastPaidPunch;
                $totalSeconds += $interval;
                $lastPaidPunch = null;
            }
        }
    }
    
    // Si el último punch fue pagado y no hay EXIT, contar hasta ahora (solo si es hoy)
    $lastPunch = end($punches);
    if ($lastPaidPunch !== null && strtoupper($lastPunch['type']) !== 'EXIT') {
        if ($date === date('Y-m-d')) {
            $now = time();
            $interval = $now - $lastPaidPunch;
            $totalSeconds += $interval;
        } else {
            // Si es un día pasado y quedó abierto, cerramos al final del día (23:59:59) o ignoramos?
            // Por seguridad, si quedó abierto en el pasado, mejor no sumar infinito. 
            // Asumimos que se cerró a las 23:59:59 o simplemente no sumamos más.
            // Opción conservadora: no sumar más. Opción corrección: sumar hasta fin del día.
            // Vamos a sumar hasta el final del día para no perder esas horas si olvidaron hacer checkout.
            $endOfDay = strtotime($date . ' 23:59:59');
            $interval = $endOfDay - $lastPaidPunch;
            $totalSeconds += $interval;
        }
    }
    
    return $totalSeconds / 3600; // Convertir a horas
}

/**
 * Obtiene estadísticas de nómina para un mes específico
 */
function getPayrollStats($pdo, $month) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                pp.id,
                pp.name,
                pp.status,
                pp.period_start,
                pp.period_end,
                pp.total_gross,
                pp.total_deductions,
                pp.total_net
            FROM payroll_periods pp
            WHERE DATE_FORMAT(pp.period_start, '%Y-%m') = ?
            ORDER BY pp.period_start DESC
            LIMIT 1
        ");
        $stmt->execute([$month]);
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payroll) {
            return [
                'has_payroll' => false,
                'message' => 'No hay nómina para este mes'
            ];
        }
        
        // Calcular empleados activos para este período
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as active_employees
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
            AND u.is_active = 1
        ");
        $stmt->execute();
        $activeEmployees = $stmt->fetch()['active_employees'];
        
        // Calcular promedio de tarifa por hora de empleados activos
        $stmt = $pdo->prepare("
            SELECT AVG(u.hourly_rate) as avg_hourly_rate
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
            AND u.is_active = 1
            AND u.hourly_rate > 0
        ");
        $stmt->execute();
        $avgRate = $stmt->fetch()['avg_hourly_rate'] ?? 0;
        
        return [
            'has_payroll' => true,
            'employees_in_payroll' => (int)$activeEmployees,
            'total_hours' => 0, // No disponible sin payroll_entries
            'total_gross_pay' => round((float)$payroll['total_gross'], 2),
            'total_net_pay' => round((float)$payroll['total_net'], 2),
            'avg_hourly_rate' => round((float)$avgRate, 2),
            'status' => $payroll['status'],
            'period_start' => $payroll['period_start'],
            'period_end' => $payroll['period_end'],
            'total_gross_formatted' => formatMoney((float)$payroll['total_gross'], 'USD'),
            'total_net_formatted' => formatMoney((float)$payroll['total_net'], 'USD')
        ];
    } catch (Exception $e) {
        return [
            'has_payroll' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Obtiene métricas de fuerza laboral en el periodo.
 */
function getWorkforceSummary($pdo, $startDate, $endDate) {
    $defaults = [
        'active_employees' => 0,
        'trial_employees' => 0,
        'suspended_employees' => 0,
        'terminated_employees' => 0,
        'new_hires' => 0,
        'terminations' => 0,
        'absent_employees' => 0,
        'attendance_records' => 0,
        'attendance_users' => 0
    ];

    try {
        $statusStmt = $pdo->query("
            SELECT e.employment_status, COUNT(*) as total
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE u.is_active = 1
            GROUP BY e.employment_status
        ");
        $statusRows = $statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($statusRows as $row) {
            $status = strtoupper(trim($row['employment_status'] ?? ''));
            $count = (int)($row['total'] ?? 0);
            if ($status === 'ACTIVE') {
                $defaults['active_employees'] = $count;
            } elseif ($status === 'TRIAL') {
                $defaults['trial_employees'] = $count;
            } elseif ($status === 'SUSPENDED') {
                $defaults['suspended_employees'] = $count;
            } elseif ($status === 'TERMINATED') {
                $defaults['terminated_employees'] = $count;
            }
        }

        $newHiresStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE u.is_active = 1
            AND DATE(e.hire_date) BETWEEN ? AND ?
        ");
        $newHiresStmt->execute([$startDate, $endDate]);
        $defaults['new_hires'] = (int)$newHiresStmt->fetchColumn();

        $terminationsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE u.is_active = 1
            AND e.termination_date IS NOT NULL
            AND DATE(e.termination_date) BETWEEN ? AND ?
        ");
        $terminationsStmt->execute([$startDate, $endDate]);
        $defaults['terminations'] = (int)$terminationsStmt->fetchColumn();

        $attendanceCountStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM attendance
            WHERE DATE(timestamp) BETWEEN ? AND ?
        ");
        $attendanceCountStmt->execute([$startDate, $endDate]);
        $defaults['attendance_records'] = (int)$attendanceCountStmt->fetchColumn();

        $attendanceUsersStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT user_id)
            FROM attendance
            WHERE DATE(timestamp) BETWEEN ? AND ?
        ");
        $attendanceUsersStmt->execute([$startDate, $endDate]);
        $defaults['attendance_users'] = (int)$attendanceUsersStmt->fetchColumn();

        $absentStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE u.is_active = 1
            AND e.employment_status IN ('ACTIVE', 'TRIAL')
            AND NOT EXISTS (
                SELECT 1
                FROM attendance a
                WHERE a.user_id = u.id
                AND DATE(a.timestamp) BETWEEN ? AND ?
            )
        ");
        $absentStmt->execute([$startDate, $endDate]);
        $defaults['absent_employees'] = (int)$absentStmt->fetchColumn();
    } catch (Exception $e) {
        return $defaults;
    }

    return $defaults;
}

/**
 * Obtiene distribución de punches por tipo en el periodo.
 */
function getAttendanceTypeSummary($pdo, $startDate, $endDate) {
    $summary = [
        'total_punches' => 0,
        'paid_punches' => 0,
        'unpaid_punches' => 0,
        'by_type' => []
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT
                UPPER(a.type) as slug,
                COUNT(*) as total,
                t.label,
                t.color_start,
                t.color_end,
                t.is_paid
            FROM attendance a
            LEFT JOIN attendance_types t ON UPPER(t.slug) = UPPER(a.type)
            WHERE DATE(a.timestamp) BETWEEN ? AND ?
            GROUP BY UPPER(a.type), t.label, t.color_start, t.color_end, t.is_paid
            ORDER BY total DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $count = (int)($row['total'] ?? 0);
            $isPaid = (int)($row['is_paid'] ?? 0);
            $summary['total_punches'] += $count;
            if ($isPaid === 1) {
                $summary['paid_punches'] += $count;
            } else {
                $summary['unpaid_punches'] += $count;
            }

            $summary['by_type'][] = [
                'slug' => $row['slug'],
                'label' => $row['label'] ?: $row['slug'],
                'count' => $count,
                'is_paid' => $isPaid,
                'color_start' => $row['color_start'] ?: '#64748b',
                'color_end' => $row['color_end'] ?: '#475569'
            ];
        }
    } catch (Exception $e) {
        return $summary;
    }

    return $summary;
}

/**
 * Obtiene resumen por departamento.
 */
function getDepartmentSummary($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT
                d.id,
                d.name,
                COUNT(e.id) as employees,
                SUM(CASE WHEN e.employment_status IN ('ACTIVE', 'TRIAL') THEN 1 ELSE 0 END) as active_employees
            FROM departments d
            LEFT JOIN employees e ON e.department_id = d.id
            LEFT JOIN users u ON u.id = e.user_id AND u.is_active = 1
            GROUP BY d.id, d.name
            ORDER BY active_employees DESC, d.name ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $noDeptStmt = $pdo->query("
            SELECT COUNT(*) as employees,
                   SUM(CASE WHEN e.employment_status IN ('ACTIVE','TRIAL') THEN 1 ELSE 0 END) as active_employees
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id AND u.is_active = 1
            WHERE e.department_id IS NULL
        ");
        $noDept = $noDeptStmt->fetch(PDO::FETCH_ASSOC);
        if ($noDept && ((int)$noDept['employees'] > 0)) {
            $rows[] = [
                'id' => 0,
                'name' => 'Sin departamento',
                'employees' => (int)$noDept['employees'],
                'active_employees' => (int)$noDept['active_employees']
            ];
        }

        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Construye listas top de campañas.
 */
function buildTopCampaigns($campaigns) {
    $list = array_values($campaigns);

    usort($list, function ($a, $b) {
        $costA = ($a['total_cost_usd'] ?? 0) + ($a['total_cost_dop'] ?? 0);
        $costB = ($b['total_cost_usd'] ?? 0) + ($b['total_cost_dop'] ?? 0);
        return $costB <=> $costA;
    });

    $topByCost = array_slice($list, 0, 5);

    usort($list, function ($a, $b) {
        return ($b['total_hours'] ?? 0) <=> ($a['total_hours'] ?? 0);
    });

    $topByHours = array_slice($list, 0, 5);

    return [
        'by_cost' => $topByCost,
        'by_hours' => $topByHours
    ];
}

/**
 * Obtiene métricas agregadas de calidad.
 */
function getQualityDashboardMetrics($qualityPdo, $startDate, $endDate) {
    if (!$qualityPdo) {
        return [
            'available' => false,
            'error' => 'No se pudo conectar con la base de calidad.'
        ];
    }

    try {
        $metricsStmt = $qualityPdo->prepare("
            SELECT
                COUNT(*) AS total_evaluations,
                SUM(CASE WHEN call_id IS NOT NULL THEN 1 ELSE 0 END) AS audited_calls,
                ROUND(AVG(percentage), 2) AS avg_percentage,
                ROUND(MAX(percentage), 2) AS max_percentage,
                ROUND(MIN(percentage), 2) AS min_percentage,
                MAX(COALESCE(call_date, DATE(created_at))) AS last_eval_date,
                COUNT(DISTINCT agent_id) AS agents_evaluated,
                COUNT(DISTINCT campaign_id) AS campaigns_evaluated
            FROM evaluations
            WHERE DATE(created_at) BETWEEN ? AND ?
        ");
        $metricsStmt->execute([$startDate, $endDate]);
        $metricsRow = $metricsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $aiStmt = $qualityPdo->prepare("
            SELECT ROUND(AVG(ai.score), 2) AS avg_ai_score
            FROM call_ai_analytics ai
            INNER JOIN calls c ON c.id = ai.call_id
            INNER JOIN evaluations e ON e.call_id = c.id
            WHERE DATE(e.created_at) BETWEEN ? AND ?
        ");
        $aiStmt->execute([$startDate, $endDate]);
        $avgAiScore = (float)($aiStmt->fetchColumn() ?: 0);

        $callsStmt = $qualityPdo->prepare("
            SELECT COUNT(*)
            FROM calls
            WHERE DATE(call_datetime) BETWEEN ? AND ?
        ");
        $callsStmt->execute([$startDate, $endDate]);
        $totalCalls = (int)$callsStmt->fetchColumn();

        $trendStmt = $qualityPdo->prepare("
            SELECT DATE(created_at) as date,
                   COUNT(*) as evaluations,
                   ROUND(AVG(percentage), 2) as avg_score
            FROM evaluations
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY DATE(created_at) ASC
        ");
        $trendStmt->execute([$startDate, $endDate]);
        $trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $trendMap = [];
        foreach ($trendRows as $row) {
            $trendMap[$row['date']] = [
                'date' => $row['date'],
                'evaluations' => (int)$row['evaluations'],
                'avg_score' => (float)$row['avg_score']
            ];
        }

        $trend = [];
        $period = new DatePeriod(
            new DateTime($startDate),
            new DateInterval('P1D'),
            (new DateTime($endDate))->modify('+1 day')
        );

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $trend[] = $trendMap[$dateStr] ?? [
                'date' => $dateStr,
                'evaluations' => 0,
                'avg_score' => 0
            ];
        }

        $campaignStmt = $qualityPdo->prepare("
            SELECT
                c.name as campaign_name,
                COUNT(*) as evaluations,
                ROUND(AVG(e.percentage), 2) as avg_score,
                SUM(CASE WHEN e.call_id IS NOT NULL THEN 1 ELSE 0 END) AS audited_calls
            FROM evaluations e
            LEFT JOIN campaigns c ON c.id = e.campaign_id
            WHERE DATE(e.created_at) BETWEEN ? AND ?
            GROUP BY e.campaign_id, c.name
            ORDER BY evaluations DESC
            LIMIT 8
        ");
        $campaignStmt->execute([$startDate, $endDate]);
        $campaigns = $campaignStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $topAgentsStmt = $qualityPdo->prepare("
            SELECT
                u.full_name,
                u.username,
                COUNT(*) as evaluations,
                ROUND(AVG(e.percentage), 2) as avg_score
            FROM evaluations e
            INNER JOIN users u ON u.id = e.agent_id
            WHERE DATE(e.created_at) BETWEEN ? AND ?
            GROUP BY e.agent_id
            ORDER BY avg_score DESC, evaluations DESC
            LIMIT 5
        ");
        $topAgentsStmt->execute([$startDate, $endDate]);
        $topAgents = $topAgentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $bottomAgentsStmt = $qualityPdo->prepare("
            SELECT
                u.full_name,
                u.username,
                COUNT(*) as evaluations,
                ROUND(AVG(e.percentage), 2) as avg_score
            FROM evaluations e
            INNER JOIN users u ON u.id = e.agent_id
            WHERE DATE(e.created_at) BETWEEN ? AND ?
            GROUP BY e.agent_id
            ORDER BY avg_score ASC, evaluations DESC
            LIMIT 5
        ");
        $bottomAgentsStmt->execute([$startDate, $endDate]);
        $bottomAgents = $bottomAgentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'available' => true,
            'summary' => [
                'total_evaluations' => (int)($metricsRow['total_evaluations'] ?? 0),
                'audited_calls' => (int)($metricsRow['audited_calls'] ?? 0),
                'avg_percentage' => (float)($metricsRow['avg_percentage'] ?? 0),
                'max_percentage' => (float)($metricsRow['max_percentage'] ?? 0),
                'min_percentage' => (float)($metricsRow['min_percentage'] ?? 0),
                'last_eval_date' => $metricsRow['last_eval_date'] ?? null,
                'agents_evaluated' => (int)($metricsRow['agents_evaluated'] ?? 0),
                'campaigns_evaluated' => (int)($metricsRow['campaigns_evaluated'] ?? 0),
                'avg_ai_score' => $avgAiScore,
                'total_calls' => $totalCalls
            ],
            'trend' => $trend,
            'by_campaign' => $campaigns,
            'top_agents' => $topAgents,
            'bottom_agents' => $bottomAgents
        ];
    } catch (Exception $e) {
        return [
            'available' => false,
            'error' => $e->getMessage()
        ];
    }
}

function formatDuration($seconds) {
    if ($seconds < 60) {
        return $seconds . 's';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        return $minutes . 'm ' . $secs . 's';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}

function formatHours($hours) {
    if ($hours == 0) {
        return '0h';
    }
    $h = floor($hours);
    $m = round(($hours - $h) * 60);
    if ($m == 0) {
        return $h . 'h';
    }
    return $h . 'h ' . $m . 'm';
}

function formatMoney($amount, $currency) {
    if ($currency === 'DOP') {
        return 'RD$' . number_format($amount, 2);
    }
    return '$' . number_format($amount, 2);
}
?>
