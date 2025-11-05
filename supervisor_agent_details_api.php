<?php
/**
 * API para obtener detalles completos de un agente específico
 * Incluye historial de punches del día y estadísticas
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de usuario inválido']);
    exit;
}

try {
    // Obtener tipos de punch con colores
    $attendanceTypes = getAttendanceTypes($pdo, true);
    $typesMap = [];
    foreach ($attendanceTypes as $type) {
        // Usar tanto minúsculas como mayúsculas como keys para asegurar match
        $slugUpper = strtoupper($type['slug']);
        $typeData = [
            'label' => $type['label'],
            'icon' => $type['icon_class'] ?? 'fas fa-circle',
            'color_start' => $type['color_start'] ?? '#6366f1',
            'color_end' => $type['color_end'] ?? '#4338ca',
            'is_paid' => isset($type['is_paid']) ? (int)$type['is_paid'] : 1
        ];
        
        $typesMap[$slugUpper] = $typeData;
        $typesMap[strtolower($type['slug'])] = $typeData;
        $typesMap[$type['slug']] = $typeData;
    }
    
    // Información básica del usuario
    $userStmt = $pdo->prepare("
        SELECT 
            u.id,
            u.username,
            u.full_name,
            u.role,
            u.hourly_rate,
            u.monthly_salary,
            d.name as department_name
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.id = ?
    ");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado']);
        exit;
    }
    
    // Historial de punches de hoy (ordenado del más reciente al más antiguo para mostrar)
    $punchesStmt = $pdo->prepare("
        SELECT 
            id,
            type,
            timestamp,
            TIMESTAMPDIFF(SECOND, timestamp, NOW()) as seconds_ago
        FROM attendance
        WHERE user_id = ?
        AND DATE(timestamp) = CURDATE()
        ORDER BY timestamp DESC
    ");
    $punchesStmt->execute([$userId]);
    $punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar punches con información de tipo
    $punchesFormatted = [];
    foreach ($punches as $punch) {
        $typeSlug = strtoupper($punch['type']);
        $typeInfo = $typesMap[$typeSlug] ?? [
            'label' => $punch['type'],
            'icon' => 'fas fa-circle',
            'color_start' => '#6B7280',
            'color_end' => '#4B5563',
            'is_paid' => 0
        ];
        
        $punchesFormatted[] = [
            'id' => (int)$punch['id'],
            'type' => $typeSlug,
            'type_label' => $typeInfo['label'],
            'icon' => $typeInfo['icon'],
            'color_start' => $typeInfo['color_start'],
            'color_end' => $typeInfo['color_end'],
            'is_paid' => $typeInfo['is_paid'],
            'timestamp' => $punch['timestamp'],
            'time' => date('h:i A', strtotime($punch['timestamp'])),
            'seconds_ago' => (int)$punch['seconds_ago']
        ];
    }
    
    // Calcular estadísticas del día
    $stats = [
        'total_punches' => count($punches),
        'paid_punches' => 0,
        'unpaid_punches' => 0,
        'total_paid_time' => 0,
        'total_unpaid_time' => 0,
        'by_type' => []
    ];
    
    // Obtener todos los punches ordenados cronológicamente para calcular duraciones
    $timeCalcStmt = $pdo->prepare("
        SELECT 
            type,
            timestamp
        FROM attendance
        WHERE user_id = ?
        AND DATE(timestamp) = CURDATE()
        ORDER BY timestamp ASC
    ");
    $timeCalcStmt->execute([$userId]);
    $timeData = $timeCalcStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular duraciones entre punches consecutivos
    for ($i = 0; $i < count($timeData); $i++) {
        $currentPunch = $timeData[$i];
        $typeSlug = strtoupper($currentPunch['type']);
        $typeInfo = $typesMap[$typeSlug] ?? ['is_paid' => 0, 'label' => $currentPunch['type']];
        
        // Inicializar el tipo si no existe
        if (!isset($stats['by_type'][$typeSlug])) {
            $stats['by_type'][$typeSlug] = [
                'label' => $typeInfo['label'],
                'count' => 0,
                'total_seconds' => 0,
                'is_paid' => (int)$typeInfo['is_paid']
            ];
        }
        
        // Incrementar contador
        $stats['by_type'][$typeSlug]['count']++;
        
        // Calcular duración
        if ($i < count($timeData) - 1) {
            // Hay un siguiente punch
            $nextPunch = $timeData[$i + 1];
            $duration = strtotime($nextPunch['timestamp']) - strtotime($currentPunch['timestamp']);
            
            if ($duration > 0 && $duration < 43200) {
                $stats['by_type'][$typeSlug]['total_seconds'] += $duration;
                
                if ((int)$typeInfo['is_paid'] === 1) {
                    $stats['total_paid_time'] += $duration;
                } else {
                    $stats['total_unpaid_time'] += $duration;
                }
            }
        } else {
            // Último punch - solo calcular si es pagado
            if ((int)$typeInfo['is_paid'] === 1) {
                $duration = time() - strtotime($currentPunch['timestamp']);
                
                if ($duration > 0 && $duration < 43200) {
                    $stats['by_type'][$typeSlug]['total_seconds'] += $duration;
                    $stats['total_paid_time'] += $duration;
                }
            }
        }
    }
    
    // Contar punches pagados y no pagados
    foreach ($stats['by_type'] as $type => $data) {
        if ((int)$data['is_paid'] === 1) {
            $stats['paid_punches'] += $data['count'];
        } else {
            $stats['unpaid_punches'] += $data['count'];
        }
    }
    
    // Formatear tiempos
    $stats['total_paid_time_formatted'] = formatDuration($stats['total_paid_time']);
    $stats['total_unpaid_time_formatted'] = formatDuration($stats['total_unpaid_time']);
    $stats['total_time'] = $stats['total_paid_time'] + $stats['total_unpaid_time'];
    $stats['total_time_formatted'] = formatDuration($stats['total_time']);
    
    // Formatear by_type
    foreach ($stats['by_type'] as $type => &$data) {
        $data['total_time_formatted'] = formatDuration($data['total_seconds']);
        $data['percentage'] = $stats['total_time'] > 0 
            ? round(($data['total_seconds'] / $stats['total_time']) * 100, 1) 
            : 0;
    }
    
    // Preparar datos para gráfica
    $chartData = [
        'labels' => [],
        'data' => [],
        'colors' => [],
        'isPaid' => []
    ];
    
    foreach ($stats['by_type'] as $type => $data) {
        $typeInfo = $typesMap[$type] ?? ['label' => $type, 'color_start' => '#6B7280'];
        $chartData['labels'][] = $typeInfo['label'];
        $chartData['data'][] = round($data['total_seconds'] / 60, 1); // Convertir a minutos
        $chartData['colors'][] = $typeInfo['color_start'];
        $chartData['isPaid'][] = $data['is_paid'];
    }
    
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'user' => $user,
        'punches' => $punchesFormatted,
        'stats' => $stats,
        'chart_data' => $chartData
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener datos',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error fatal',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
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
