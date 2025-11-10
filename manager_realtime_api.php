<?php
/**
 * API para obtener el estado en tiempo real de todo el personal administrativo
 * Retorna JSON con el último punch de cada usuario administrativo activo (todos excepto AGENT)
 */
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once 'db.php';

// Verificar permisos
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

try {
    // Obtener todos los tipos de punch activos con sus colores
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
            'is_paid' => (int)$type['is_paid'],
            'is_unique_daily' => (int)($type['is_unique_daily'] ?? 0)
        ];
    }
    
    // Obtener el último punch de cada usuario administrativo (todos los roles excepto 'agent')
    $query = "
        SELECT 
            u.id as user_id,
            u.username,
            u.full_name,
            u.role,
            d.name as department_name,
            a.type as current_punch_type,
            a.timestamp as last_punch_time,
            TIMESTAMPDIFF(SECOND, a.timestamp, NOW()) as seconds_in_current_state,
            (SELECT COUNT(*) FROM attendance a2 
             WHERE a2.user_id = u.id 
             AND DATE(a2.timestamp) = CURDATE()) as punches_today
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN attendance a ON a.id = (
            SELECT a3.id 
            FROM attendance a3 
            WHERE a3.user_id = u.id 
            ORDER BY a3.timestamp DESC 
            LIMIT 1
        )
        WHERE u.is_active = 1
        AND u.role != 'agent'
        ORDER BY 
            CASE 
                WHEN a.timestamp IS NULL THEN 1
                WHEN DATE(a.timestamp) = CURDATE() THEN 0
                ELSE 2
            END,
            u.role ASC,
            d.name ASC,
            u.full_name ASC
    ";
    
    $stmt = $pdo->query($query);
    $administrativeStaff = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar datos
    $result = [];
    foreach ($administrativeStaff as $staff) {
        $punchType = strtoupper($staff['current_punch_type'] ?? 'SIN_PUNCH');
        $typeInfo = $typesMap[$punchType] ?? [
            'slug' => $punchType,
            'label' => 'Sin registro',
            'icon' => 'fas fa-question-circle',
            'color_start' => '#6B7280',
            'color_end' => '#4B5563',
            'is_paid' => 0,
            'is_unique_daily' => 0
        ];
        
        // Determinar estado
        $isToday = $staff['last_punch_time'] && date('Y-m-d', strtotime($staff['last_punch_time'])) === date('Y-m-d');
        $status = 'offline';
        if (!$staff['last_punch_time']) {
            $status = 'never_punched';
        } elseif (!$isToday) {
            $status = 'not_today';
        } elseif ($punchType === 'EXIT') {
            $status = 'completed';
        } else {
            $status = 'active';
        }

        $durationSeconds = (int)$staff['seconds_in_current_state'];
        $durationFormatted = formatDuration($durationSeconds);

        if ($punchType === 'EXIT' && $isToday) {
            $durationSeconds = 0;
            $durationFormatted = 'Jornada finalizada';
        }
        
        $result[] = [
            'user_id' => (int)$staff['user_id'],
            'username' => $staff['username'],
            'full_name' => $staff['full_name'],
            'role' => $staff['role'],
            'department' => $staff['department_name'] ?? 'Sin departamento',
            'current_punch' => [
                'slug' => $typeInfo['slug'],
                'type' => $punchType,
                'label' => $typeInfo['label'],
                'icon' => $typeInfo['icon'],
                'color_start' => $typeInfo['color_start'],
                'color_end' => $typeInfo['color_end'],
                'is_paid' => $typeInfo['is_paid'],
                'is_unique_daily' => $typeInfo['is_unique_daily'],
                'timestamp' => $staff['last_punch_time'],
                'duration_seconds' => $durationSeconds,
                'duration_formatted' => $durationFormatted
            ],
            'punches_today' => (int)$staff['punches_today'],
            'status' => $status
        ];
    }
    
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'administrative_staff' => $result,
        'total_administrative' => count($result),
        'types_available' => array_values($typesMap)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error al obtener datos',
        'message' => $e->getMessage()
    ]);
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
?>
