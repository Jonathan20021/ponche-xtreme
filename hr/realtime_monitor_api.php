<?php
/**
 * API para el Monitor en Tiempo Real de Empleados - HR Dashboard
 * Retorna métricas en tiempo real de todos los empleados activos
 */
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Rate limiting para evitar sobrecarga
require_once __DIR__ . '/../lib/rate_limiter.php';
enforceRateLimit(120, 60); // 120 requests por minuto máximo

require_once '../db.php';

// Verificar permisos de HR
if (!isset($_SESSION['user_id']) || !userHasPermission('hr_dashboard')) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

try {
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
    
    // Consulta principal para obtener empleados activos con sus métricas
    $query = "
        SELECT 
            e.id as employee_id,
            e.first_name,
            e.last_name,
            e.photo_path,
            e.position,
            u.id as user_id,
            u.username,
            u.full_name,
            u.hourly_rate,
            u.hourly_rate_dop,
            u.preferred_currency,
            d.name as department_name,
            c.name as campaign_name,
            c.code as campaign_code,
            c.color as campaign_color,
            (SELECT a.type 
             FROM attendance a 
             WHERE a.user_id = u.id 
             ORDER BY a.timestamp DESC 
             LIMIT 1) as last_punch_type,
            (SELECT a.timestamp 
             FROM attendance a 
             WHERE a.user_id = u.id 
             ORDER BY a.timestamp DESC 
             LIMIT 1) as last_punch_time,
            (SELECT COUNT(*) 
             FROM attendance a2 
             WHERE a2.user_id = u.id 
             AND DATE(a2.timestamp) = CURDATE()) as punches_today
        FROM employees e
        INNER JOIN users u ON u.id = e.user_id
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN campaigns c ON e.campaign_id = c.id
        WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
        AND u.is_active = 1
        ORDER BY 
            CASE 
                WHEN last_punch_time IS NULL THEN 2
                WHEN DATE(last_punch_time) = CURDATE() THEN 0
                ELSE 1
            END,
            c.name ASC,
            e.first_name ASC
    ";
    
    $stmt = $pdo->query($query);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar cada empleado
    $result = [];
    $totalHoursUSD = 0;
    $totalHoursDOP = 0;
    $totalEarningsUSD = 0;
    $totalEarningsDOP = 0;
    $activeCount = 0;
    
    foreach ($employees as $emp) {
        $userId = (int)$emp['user_id'];
        $currency = $emp['preferred_currency'] ?? 'USD';
        $hourlyRate = $currency === 'DOP' ? (float)$emp['hourly_rate_dop'] : (float)$emp['hourly_rate'];
        
        // Calcular horas trabajadas hoy (solo punches pagados)
        $hoursWorked = calculateDailyPaidHours($pdo, $userId, date('Y-m-d'), $paidTypes);
        
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
        
        // Determinar estado
        $punchType = strtoupper($emp['last_punch_type'] ?? 'NONE');
        $lastPunchTime = $emp['last_punch_time'];
        $isToday = $lastPunchTime && date('Y-m-d', strtotime($lastPunchTime)) === date('Y-m-d');
        
        $status = 'offline';
        $statusLabel = 'Sin Actividad';
        if (!$lastPunchTime) {
            $status = 'never_punched';
            $statusLabel = 'Nunca ha marcado';
        } elseif (!$isToday) {
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
        
        // Información del tipo de punch actual
        $typeInfo = $typesMap[$punchType] ?? [
            'slug' => 'NONE',
            'label' => 'Sin registro',
            'icon' => 'fas fa-question-circle',
            'color_start' => '#6B7280',
            'color_end' => '#4B5563',
            'is_paid' => 0
        ];
        
        // Calcular tiempo en estado actual
        $secondsInState = 0;
        $durationFormatted = '-';
        if ($isToday && $lastPunchTime && $punchType !== 'EXIT') {
            $secondsInState = time() - strtotime($lastPunchTime);
            $durationFormatted = formatDuration($secondsInState);
        }
        
        $result[] = [
            'employee_id' => (int)$emp['employee_id'],
            'user_id' => $userId,
            'username' => $emp['username'],
            'full_name' => $emp['full_name'] ?: ($emp['first_name'] . ' ' . $emp['last_name']),
            'first_name' => $emp['first_name'],
            'last_name' => $emp['last_name'],
            'photo_path' => $emp['photo_path'],
            'position' => $emp['position'] ?? 'Sin cargo',
            'department' => $emp['department_name'] ?? 'Sin departamento',
            'campaign' => [
                'name' => $emp['campaign_name'] ?? 'Sin campaña',
                'code' => $emp['campaign_code'],
                'color' => $emp['campaign_color'] ?? '#6B7280'
            ],
            'current_punch' => [
                'type' => $punchType,
                'label' => $typeInfo['label'],
                'icon' => $typeInfo['icon'],
                'color_start' => $typeInfo['color_start'],
                'color_end' => $typeInfo['color_end'],
                'is_paid' => $typeInfo['is_paid'],
                'timestamp' => $lastPunchTime,
                'duration_seconds' => $secondsInState,
                'duration_formatted' => $durationFormatted
            ],
            'status' => $status,
            'status_label' => $statusLabel,
            'hours_worked_today' => round($hoursWorked, 2),
            'hours_formatted' => formatHours($hoursWorked),
            'hourly_rate' => $hourlyRate,
            'currency' => $currency,
            'earnings_today' => round($earnings, 2),
            'earnings_formatted' => formatMoney($earnings, $currency),
            'punches_today' => (int)$emp['punches_today']
        ];
    }
    
    // Respuesta final
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'employees' => $result,
        'summary' => [
            'total_employees' => count($result),
            'active_now' => $activeCount,
            'total_hours_usd' => round($totalHoursUSD, 2),
            'total_hours_dop' => round($totalHoursDOP, 2),
            'total_earnings_usd' => round($totalEarningsUSD, 2),
            'total_earnings_dop' => round($totalEarningsDOP, 2),
            'total_earnings_usd_formatted' => formatMoney($totalEarningsUSD, 'USD'),
            'total_earnings_dop_formatted' => formatMoney($totalEarningsDOP, 'DOP')
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error al obtener datos',
        'message' => $e->getMessage()
    ]);
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
    
    // Si el último punch fue pagado y no hay EXIT, contar hasta ahora
    $lastPunch = end($punches);
    if ($lastPaidPunch !== null && strtoupper($lastPunch['type']) !== 'EXIT') {
        $now = time();
        $interval = $now - $lastPaidPunch;
        $totalSeconds += $interval;
    }
    
    return $totalSeconds / 3600; // Convertir a horas
}

/**
 * Formatea una duración en segundos a formato legible
 */
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

/**
 * Formatea horas decimales a formato legible (ej: 5.5 -> "5h 30m")
 */
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

/**
 * Formatea dinero según la moneda
 */
function formatMoney($amount, $currency) {
    if ($currency === 'DOP') {
        return 'RD$' . number_format($amount, 2);
    }
    return '$' . number_format($amount, 2);
}
?>
