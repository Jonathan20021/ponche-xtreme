<?php
/**
 * API para obtener detalles completos de un agente específico
 * Incluye historial de punches del día y estadísticas
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

// IMPORTANTE: Establecer zona horaria de Santo Domingo
date_default_timezone_set('America/Santo_Domingo');

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once 'db.php';
require_once __DIR__ . '/lib/monitor_history.php';

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

$requestedDate = isset($_GET['date']) ? trim($_GET['date']) : '';
if ($requestedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
    http_response_code(400);
    echo json_encode(['error' => 'Formato de fecha inválido. Use YYYY-MM-DD']);
    exit;
}
$targetDate = $requestedDate !== '' ? $requestedDate : date('Y-m-d');
$isToday = $targetDate === date('Y-m-d');

try {
    // Obtener tipos de punch con colores - usar UPPER en slug para match consistente
    $typesStmt = $pdo->query("
        SELECT 
            UPPER(slug) as slug,
            label,
            icon_class,
            color_start,
            color_end,
            is_paid,
            is_unique_daily
        FROM attendance_types
        WHERE is_active = 1
    ");
    $attendanceTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $typesMap = [];
    foreach ($attendanceTypes as $type) {
        $slug = $type['slug'];
        $typesMap[$slug] = [
            'slug' => $slug,
            'label' => $type['label'],
            'icon' => $type['icon_class'] ?? 'fas fa-circle',
            'color_start' => $type['color_start'] ?? '#6366f1',
            'color_end' => $type['color_end'] ?? '#4338ca',
            'is_paid' => isset($type['is_paid']) ? (int)$type['is_paid'] : 1,
            'is_unique_daily' => isset($type['is_unique_daily']) ? (int)$type['is_unique_daily'] : 0
        ];
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
    
    // Historial de punches de la fecha solicitada (ordenado del más reciente al más antiguo para mostrar)
    $punchesStmt = $pdo->prepare("
        SELECT
            id,
            type,
            timestamp,
            TIMESTAMPDIFF(SECOND, timestamp, NOW()) as seconds_ago
        FROM attendance
        WHERE user_id = ?
        AND DATE(timestamp) = ?
        ORDER BY timestamp DESC
    ");
    $punchesStmt->execute([$userId, $targetDate]);
    $punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar punches con información de tipo
    $punchesFormatted = [];
    foreach ($punches as $punch) {
        $typeSlug = strtoupper($punch['type']);
        
        if (!isset($typesMap[$typeSlug])) {
            error_log("Tipo $typeSlug no encontrado en typesMap al formatear punches");
            continue;
        }
        
        $typeInfo = $typesMap[$typeSlug];
        
        $punchesFormatted[] = [
            'id' => (int)$punch['id'],
            'type' => $typeSlug,
            'type_label' => $typeInfo['label'],
            'icon' => $typeInfo['icon'],
            'color_start' => $typeInfo['color_start'],
            'color_end' => $typeInfo['color_end'],
            'is_paid' => (int)$typeInfo['is_paid'],
            'timestamp' => $punch['timestamp'],
            'time' => date('h:i A', strtotime($punch['timestamp'])),
            'seconds_ago' => (int)$punch['seconds_ago']
        ];
    }
    
    // ---------------------------------------------------------------------
    // Tiempo por estado + linea de tiempo de disposiciones.
    //
    // Todo sale de monitorHistoryBuild() (lib/monitor_history.php), que aplica
    // las MISMAS reglas que la nomina. Antes este endpoint tenia su propio
    // calculo "delta hasta el siguiente punch" con un tope de 12h: atribuia las
    // sub-pausas al slug equivocado, contaba punches fantasma de ediciones de
    // supervisor y descartaba en silencio los tramos largos, asi que el modal
    // podia no cuadrar con lo que se paga.
    // ---------------------------------------------------------------------
    $history = monitorHistoryBuild($pdo, $userId, $targetDate);

    // Cuantas veces marco cada tipo (eso si sale de los punches, no de los tramos)
    $punchCountByType = [];
    foreach ($punchesFormatted as $p) {
        $punchCountByType[$p['type']] = ($punchCountByType[$p['type']] ?? 0) + 1;
    }

    $stats = [
        'total_punches'    => count($punches),
        'paid_punches'     => 0,
        'unpaid_punches'   => 0,
        'total_paid_time'  => (int) $history['totals']['paid_seconds'],
        'total_unpaid_time'=> (int) $history['totals']['unpaid_seconds'],
        'by_type'          => []
    ];

    foreach ($history['by_state'] as $state) {
        $slug = $state['slug'];
        $stats['by_type'][$slug] = [
            'label'              => $state['label'],
            'count'              => (int) ($punchCountByType[$slug] ?? $state['episodes']),
            'total_seconds'      => (int) $state['seconds'],
            'is_paid'            => $state['is_paid'] ? 1 : 0,
            'total_time_formatted' => $state['duration_formatted'],
            'percentage'         => (float) $state['pct'],
            'episodes'           => (int) $state['episodes'],
        ];
    }

    // Tipos marcados que no generaron tiempo (p. ej. el EXIT que cierra el dia):
    // siguen apareciendo en el desglose con su conteo, en 0s.
    foreach ($punchCountByType as $slug => $count) {
        if (isset($stats['by_type'][$slug])) {
            continue;
        }
        $typeInfo = $typesMap[$slug] ?? null;
        $stats['by_type'][$slug] = [
            'label'              => $typeInfo['label'] ?? $slug,
            'count'              => (int) $count,
            'total_seconds'      => 0,
            'is_paid'            => (int) ($typeInfo['is_paid'] ?? 0),
            'total_time_formatted' => '0s',
            'percentage'         => 0.0,
            'episodes'           => 0,
        ];
    }

    foreach ($stats['by_type'] as $data) {
        if ((int) $data['is_paid'] === 1) {
            $stats['paid_punches'] += $data['count'];
        } else {
            $stats['unpaid_punches'] += $data['count'];
        }
    }

    $stats['total_paid_time_formatted']   = formatDuration($stats['total_paid_time']);
    $stats['total_unpaid_time_formatted'] = formatDuration($stats['total_unpaid_time']);
    $stats['total_time']                  = $stats['total_paid_time'] + $stats['total_unpaid_time'];
    $stats['total_time_formatted']        = formatDuration($stats['total_time']);

    // Datos para la grafica de distribucion (minutos por estado)
    $chartData = [
        'labels'  => [],
        'data'    => [],
        'colors'  => [],
        'isPaid'  => []
    ];
    foreach ($history['by_state'] as $state) {
        if ((int) $state['seconds'] <= 0) {
            continue;
        }
        $chartData['labels'][] = $state['label'];
        $chartData['data'][]   = round($state['seconds'] / 60, 1);
        $chartData['colors'][] = $state['color'];
        $chartData['isPaid'][] = $state['is_paid'] ? 1 : 0;
    }

    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'date' => $targetDate,
        'is_today' => $isToday,
        'user' => $user,
        'punches' => $punchesFormatted,
        'stats' => $stats,
        'chart_data' => $chartData,
        // Histórico de disposiciones: en qué estado estuvo, desde cuándo,
        // hasta cuándo y cuánto permaneció en cada uno.
        'state_timeline' => $history['timeline'],
        'state_totals'   => $history['by_state'],
        'day_totals'     => $history['totals'],
        'attendance_types' => array_values($typesMap)
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
