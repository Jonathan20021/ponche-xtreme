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
        'payroll' => $payrollStats
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
