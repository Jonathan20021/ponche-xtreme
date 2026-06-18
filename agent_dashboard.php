<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['AGENT', 'IT', 'Supervisor'], true)) {
    header('Location: login_agent.php');
    exit;
}

include 'db.php';
require_once __DIR__ . '/quality_db.php';
date_default_timezone_set('America/Santo_Domingo');

if (!function_exists('sanitizeHexColorValue')) {
    function sanitizeHexColorValue(?string $color, string $fallback = '#3a5da0'): string
    {
        $value = strtoupper(trim((string) $color));
        return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : strtoupper($fallback);
    }
}

$user_id = (int) $_SESSION['user_id'];
$username = $_SESSION['username'] ?? null;
$full_name = $_SESSION['full_name'] ?? null;

$qualityPdo = getQualityDbConnection();
$qualityUser = null;
$qualityMetrics = [
    'total_evaluations' => 0,
    'audited_calls' => 0,
    'avg_percentage' => 0.0,
    'max_percentage' => 0.0,
    'min_percentage' => 0.0,
    'last_eval_date' => null,
    'avg_ai_score' => 0.0,
];
$qualityAudits = [];
$qualityError = null;

if ($qualityPdo && $username) {
    $qualityUserStmt = $qualityPdo->prepare("SELECT id, full_name, username FROM users WHERE username = ? LIMIT 1");
    $qualityUserStmt->execute([$username]);
    $qualityUser = $qualityUserStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($qualityUser) {
        $qualityUserId = (int) $qualityUser['id'];

        $metricsStmt = $qualityPdo->prepare("
            SELECT
                COUNT(*) AS total_evaluations,
                SUM(CASE WHEN call_id IS NOT NULL THEN 1 ELSE 0 END) AS audited_calls,
                ROUND(AVG(percentage), 2) AS avg_percentage,
                ROUND(MAX(percentage), 2) AS max_percentage,
                ROUND(MIN(percentage), 2) AS min_percentage,
                MAX(COALESCE(call_date, DATE(created_at))) AS last_eval_date
            FROM evaluations
            WHERE agent_id = ?
        ");
        $metricsStmt->execute([$qualityUserId]);
        $metricsRow = $metricsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $qualityMetrics['total_evaluations'] = (int) ($metricsRow['total_evaluations'] ?? 0);
        $qualityMetrics['audited_calls'] = (int) ($metricsRow['audited_calls'] ?? 0);
        $qualityMetrics['avg_percentage'] = (float) ($metricsRow['avg_percentage'] ?? 0);
        $qualityMetrics['max_percentage'] = (float) ($metricsRow['max_percentage'] ?? 0);
        $qualityMetrics['min_percentage'] = (float) ($metricsRow['min_percentage'] ?? 0);
        $qualityMetrics['last_eval_date'] = $metricsRow['last_eval_date'] ?? null;

        $aiStmt = $qualityPdo->prepare("
            SELECT ROUND(AVG(ai.score), 2) AS avg_ai_score
            FROM call_ai_analytics ai
            INNER JOIN calls c ON c.id = ai.call_id
            INNER JOIN evaluations e ON e.call_id = c.id
            WHERE e.agent_id = ?
        ");
        $aiStmt->execute([$qualityUserId]);
        $qualityMetrics['avg_ai_score'] = (float) (($aiStmt->fetchColumn() ?: 0));

        $auditsStmt = $qualityPdo->prepare("
            SELECT
                e.id AS evaluation_id,
                e.call_id,
                e.percentage,
                e.total_score,
                e.max_possible_score,
                e.general_comments,
                e.call_date,
                e.created_at,
                c.call_datetime,
                c.duration_seconds,
                c.customer_phone,
                c.recording_path,
                c.call_type,
                camp.name AS campaign_name,
                ai.model AS ai_model,
                ai.score AS ai_score,
                ai.summary AS ai_summary,
                ai.metrics_json AS ai_metrics
            FROM evaluations e
            LEFT JOIN calls c ON c.id = e.call_id
            LEFT JOIN campaigns camp ON camp.id = e.campaign_id
            LEFT JOIN call_ai_analytics ai ON ai.call_id = c.id
            WHERE e.agent_id = ?
            ORDER BY e.created_at DESC
            LIMIT 20
        ");
        $auditsStmt->execute([$qualityUserId]);
        $qualityAudits = $auditsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $qualityError = 'No se encontró el usuario en el sistema de calidad.';
    }
} elseif (!$qualityPdo) {
    $qualityError = 'No se pudo conectar con la base de calidad.';
}

if (!function_exists('resolveQualityRecordingUrl')) {
    function resolveQualityRecordingUrl(?string $recordingPath): ?string
    {
        $recordingPath = trim((string) $recordingPath);
        if ($recordingPath === '') {
            return null;
        }
        if (preg_match('~^https?://~i', $recordingPath)) {
            return $recordingPath;
        }
        $baseUrl = defined('QUALITY_MEDIA_BASE_URL') ? QUALITY_MEDIA_BASE_URL : '';
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/' . ltrim($recordingPath, '/');
        }
        return $recordingPath;
    }
}

if ($username === null || $full_name === null) {
    $userStmt = $pdo->prepare('SELECT username, full_name FROM users WHERE id = ?');
    $userStmt->execute([$user_id]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($username === null) {
        $username = $userRow['username'] ?? '';
        $_SESSION['username'] = $username;
    }
    if ($full_name === null) {
        $full_name = $userRow['full_name'] ?? 'Agente';
        $_SESSION['full_name'] = $full_name;
    }
}

// Handle punch submission
$punch_error = null;
$punch_success = null;
$logout_error = null;
$permission_success = null;
$permission_error = null;
$vacation_success = null;
$vacation_error = null;

// Check for flash messages from session
if (isset($_SESSION['punch_success'])) {
    $punch_success = $_SESSION['punch_success'];
    unset($_SESSION['punch_success']);
}
if (isset($_SESSION['punch_error'])) {
    $punch_error = $_SESSION['punch_error'];
    unset($_SESSION['punch_error']);
}
if (isset($_SESSION['logout_error'])) {
    $logout_error = $_SESSION['logout_error'];
    unset($_SESSION['logout_error']);
}
if (isset($_SESSION['permission_success'])) {
    $permission_success = $_SESSION['permission_success'];
    unset($_SESSION['permission_success']);
}
if (isset($_SESSION['permission_error'])) {
    $permission_error = $_SESSION['permission_error'];
    unset($_SESSION['permission_error']);
}
if (isset($_SESSION['vacation_success'])) {
    $vacation_success = $_SESSION['vacation_success'];
    unset($_SESSION['vacation_success']);
}
if (isset($_SESSION['vacation_error'])) {
    $vacation_error = $_SESSION['vacation_error'];
    unset($_SESSION['vacation_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['punch_type'])) {
    $typeSlug = sanitizeAttendanceTypeSlug($_POST['punch_type'] ?? '');
    $date_filter_post = $_GET['dates'] ?? date('Y-m-d');

    // Detectar AJAX para responder JSON (confirmación de guardado + reintento del cliente).
    // Si no es AJAX, se mantiene el flujo clásico POST-Redirect-GET con mensajes en sesión.
    $isAjax = (isset($_POST['ajax']) && $_POST['ajax'] === '1')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $punchRespond = function (bool $ok, string $message) use ($isAjax, $date_filter_post) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION[$ok ? 'punch_success' : 'punch_error'] = $message;
        header('Location: agent_dashboard.php?dates=' . urlencode($date_filter_post));
        exit;
    };

    if ($typeSlug === '') {
        $punchRespond(false, "Tipo de asistencia no válido.");
    }
    
    $attendanceTypesForPunch = getAttendanceTypes($pdo, false);
    $attendanceTypeMapForPunch = [];
    foreach ($attendanceTypesForPunch as $row) {
        $slug = sanitizeAttendanceTypeSlug($row['slug'] ?? '');
        if ($slug !== '') {
            $attendanceTypeMapForPunch[$slug] = $row;
        }
    }
    
    if (!isset($attendanceTypeMapForPunch[$typeSlug])) {
        $punchRespond(false, "Tipo de asistencia no válido.");
    }
    
    $selectedTypeMeta = $attendanceTypeMapForPunch[$typeSlug];
    $typeLabel = $selectedTypeMeta['label'] ?? $selectedTypeMeta['slug'];

    // IDEMPOTENCIA: si llega una marcación idéntica (mismo usuario + tipo) dentro de la ventana
    // configurada, se trata como "ya registrada" en vez de duplicar. Evita los registros triples
    // por doble clic o reintentos ante 429/caída de red, y hace que reintentar sea seguro.
    $idempotencyWindow = (int) getSystemSetting($pdo, 'punch_idempotency_window_seconds', 8);
    if ($idempotencyWindow > 0) {
        $dupStmt = $pdo->prepare("
            SELECT id FROM attendance
            WHERE user_id = ? AND type = ?
              AND timestamp >= (NOW() - INTERVAL ? SECOND)
            ORDER BY timestamp DESC LIMIT 1
        ");
        $dupStmt->execute([$user_id, $typeSlug, $idempotencyWindow]);
        if ($dupStmt->fetch()) {
            $punchRespond(true, "Marcación ya registrada como {$typeLabel} (se evitó un duplicado).");
        }
    }

    // Validate unique per day constraint
    if ((int) ($selectedTypeMeta['is_unique_daily'] ?? 0) === 1) {
        $check_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM attendance 
            WHERE user_id = ? AND type = ? AND DATE(timestamp) = CURDATE()
        ");
        $check_stmt->execute([$user_id, $typeSlug]);
        $exists = (int) $check_stmt->fetchColumn();
        
        if ($exists > 0) {
            $punchRespond(false, "Solo puedes registrar '{$typeLabel}' una vez por día.");
        }
    }
    
    // Validar secuencia ENTRY/EXIT
    require_once 'lib/authorization_functions.php';
    $sequenceValidation = validateEntryExitSequence($pdo, $user_id, $typeSlug);
    if (!$sequenceValidation['valid']) {
        $punchRespond(false, $sequenceValidation['message']);
    }
    
    // Check authorization requirements
    $authSystemEnabled = isAuthorizationSystemEnabled($pdo);
    $authRequiredForOvertime = isAuthorizationRequiredForContext($pdo, 'overtime');
    $authRequiredForEarlyPunch = isAuthorizationRequiredForContext($pdo, 'early_punch');
    $authorizationCodeId = null;
    $authorizationContext = null;
    $authCode = trim($_POST['authorization_code'] ?? '');
    
    // Check overtime authorization
    if ($authSystemEnabled && $authRequiredForOvertime) {
        $isOvertime = isOvertimeAttempt($pdo, $user_id, $typeSlug);

        if ($isOvertime) {
            if ($authCode === '') {
                $punchRespond(false, "Se requiere código de autorización para registrar hora extra.");
            }

            $validation = validateAuthorizationCode($pdo, $authCode, 'overtime');
            if (!$validation['valid']) {
                $punchRespond(false, "Código de autorización inválido: " . $validation['message']);
            }

            $authorizationCodeId = $validation['code_id'];
            $authorizationContext = 'overtime';
        }
    }
    
    // Check early punch authorization
    if ($authSystemEnabled && $authRequiredForEarlyPunch) {
        $isEarly = isEarlyPunchAttempt($pdo, $user_id);

        if ($isEarly) {
            if ($authCode === '') {
                $punchRespond(false, "Se requiere código de autorización para marcar entrada antes de su horario.");
            }

            $validation = validateAuthorizationCode($pdo, $authCode, 'early_punch');
            if (!$validation['valid']) {
                $punchRespond(false, "Código de autorización inválido: " . $validation['message']);
            }

            if ($authorizationCodeId === null) {
                $authorizationCodeId = $validation['code_id'];
            }
            if ($authorizationContext === null) {
                $authorizationContext = 'early_punch';
            }
        }
    }
    
    // Register the punch
    $ip_address = $_SERVER['REMOTE_ADDR'] === '::1' ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];
    $insert_stmt = $pdo->prepare("
        INSERT INTO attendance (user_id, type, ip_address, timestamp, authorization_code_id) 
        VALUES (?, ?, ?, NOW(), ?)
    ");
    $insert_stmt->execute([$user_id, $typeSlug, $ip_address, $authorizationCodeId]);
    
    // Log attendance registration
    require_once 'lib/logging_functions.php';
    $recordId = $pdo->lastInsertId();
    if ($authorizationCodeId !== null) {
        logAuthorizationCodeUsage(
            $pdo,
            $authorizationCodeId,
            $user_id,
            $authorizationContext ?? 'overtime',
            $recordId,
            'attendance',
            ['type' => $typeSlug, 'username' => $username]
        );
    }
    log_custom_action(
        $pdo,
        $user_id,
        $_SESSION['full_name'],
        $_SESSION['role'],
        'attendance',
        'create',
        "Registro de asistencia desde dashboard: {$typeSlug}",
        'attendance_record',
        $recordId,
        ['type' => $typeSlug, 'ip_address' => $ip_address, 'authorization_code_id' => $authorizationCodeId]
    );
    
    // Set success message and redirect (o JSON si es AJAX)
    $punchRespond(true, "¡Asistencia registrada exitosamente como {$typeLabel}!");
}

$date_filter = $_GET['dates'] ?? date('Y-m-d');

if (!function_exists('formatScheduleTimeLabel')) {
    function formatScheduleTimeLabel(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        return date('g:i A', strtotime($value));
    }
}

if (!function_exists('formatScheduleDaysLabel')) {
    function formatScheduleDaysLabel(?string $daysValue): string
    {
        $daysValue = trim((string) $daysValue);
        if ($daysValue === '') {
            return 'Todos los días';
        }
        $map = [
            1 => 'Lun',
            2 => 'Mar',
            3 => 'Mié',
            4 => 'Jue',
            5 => 'Vie',
            6 => 'Sáb',
            7 => 'Dom'
        ];
        $parts = array_filter(array_map('intval', explode(',', $daysValue)));
        $labels = [];
        foreach ($parts as $day) {
            if (isset($map[$day])) {
                $labels[] = $map[$day];
            }
        }
        return $labels ? implode(', ', $labels) : 'Todos los días';
    }
}

$scheduleSummary = getScheduleConfigForUser($pdo, $user_id, $date_filter);
$scheduleSegments = $scheduleSummary['schedule_segments'] ?? [];
$scheduleName = $scheduleSummary['schedule_name'] ?? 'Horario Global';
$scheduleEntry = formatScheduleTimeLabel($scheduleSummary['entry_time'] ?? null);
$scheduleExit = formatScheduleTimeLabel($scheduleSummary['exit_time'] ?? null);
$scheduleHours = isset($scheduleSummary['scheduled_hours']) ? number_format((float) $scheduleSummary['scheduled_hours'], 2) : '0.00';

$attendanceTypes = getAttendanceTypes($pdo, false);
$attendanceTypeMap = [];
$durationTypes = [];
$activeAttendanceTypes = [];
foreach ($attendanceTypes as $typeRow) {
    $slug = sanitizeAttendanceTypeSlug($typeRow['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $typeRow['slug'] = $slug;
    $attendanceTypeMap[$slug] = $typeRow;
    if ((int) ($typeRow['is_active'] ?? 0) === 1 && (int) ($typeRow['is_unique_daily'] ?? 0) === 0) {
        $durationTypes[] = [
            'slug' => $slug,
            'label' => $typeRow['label'] ?? $slug,
        ];
    }
    // Build active attendance types for punch buttons
    if ((int) ($typeRow['is_active'] ?? 0) === 1) {
        $activeAttendanceTypes[] = $typeRow;
    }
}

$nonWorkSlugs = [
    sanitizeAttendanceTypeSlug('BREAK'),
    sanitizeAttendanceTypeSlug('LUNCH'),
];

$eventsStmt = $pdo->prepare('SELECT type, timestamp, TIME(timestamp) AS record_time, ip_address
    FROM attendance
    WHERE user_id = ? AND DATE(timestamp) = ?
    ORDER BY timestamp ASC');
$eventsStmt->execute([$user_id, $date_filter]);
$eventRows = $eventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$records = [];
$events = [];
foreach ($eventRows as $row) {
    $slug = sanitizeAttendanceTypeSlug($row['type'] ?? '');
    if ($slug === '') {
        continue;
    }
    $meta = $attendanceTypeMap[$slug] ?? null;
    $label = $meta['label'] ?? ($row['type'] ?? $slug);
    $icon = $meta['icon_class'] ?? 'fas fa-circle';
    $colorStart = sanitizeHexColorValue($meta['color_start'] ?? '#92a9da', '#92a9da');
    $timestamp = strtotime($row['timestamp'] ?? '');
    if ($timestamp === false) {
        continue;
    }

    $records[] = [
        'label' => $label,
        'time' => $row['record_time'],
        'ip' => $row['ip_address'] ?? 'N/A',
        'color' => $colorStart,
        'icon' => $icon,
    ];

    $events[] = [
        'slug' => $slug,
        'timestamp' => $timestamp,
    ];
}

$durations = [];
if (count($events) >= 2) {
    for ($i = 0; $i < count($events) - 1; $i++) {
        $delta = max(0, $events[$i + 1]['timestamp'] - $events[$i]['timestamp']);
        if ($delta <= 0) {
            continue;
        }
        $slug = $events[$i]['slug'];
        $durations[$slug] = ($durations[$slug] ?? 0) + $delta;
    }
}

$totalSeconds = array_sum($durations);
$pauseSeconds = 0;
foreach ($nonWorkSlugs as $pauseSlug) {
    $pauseSeconds += $durations[$pauseSlug] ?? 0;
}
$workSeconds = max(0, $totalSeconds - $pauseSeconds);

$hourly_rates = getUserHourlyRates($pdo);
$hourly_rate = $hourly_rates[$username] ?? 0;
$total_payment = round(($workSeconds / 3600) * $hourly_rate, 2);
$productivity_score = $totalSeconds > 0 ? round(($workSeconds / $totalSeconds) * 100, 1) : 0;

$heroMetrics = [
    [
        'label' => 'Horas trabajadas',
        'value' => gmdate('H:i', $workSeconds),
        'note' => 'Registro del día',
    ],
    [
        'label' => 'Eventos registrados',
        'value' => (string) count($records),
        'note' => 'Movimientos del día',
    ],
];

$insightCards = [
    [
        'label' => 'Horas productivas',
        'value' => gmdate('H:i:s', $workSeconds),
        'description' => 'Tiempo válido para pago',
        'icon' => 'fas fa-briefcase',
        'color_start' => '#92a9da',
        'color_end' => '#1f3f76',
    ],
];

foreach ($durationTypes as $typeMeta) {
    $slug = $typeMeta['slug'];
    $meta = $attendanceTypeMap[$slug] ?? null;
    $colorStart = sanitizeHexColorValue($meta['color_start'] ?? '#3a5da0', '#3a5da0');
    $colorEnd = sanitizeHexColorValue($meta['color_end'] ?? $colorStart, $colorStart);
    $insightCards[] = [
        'label' => $typeMeta['label'],
        'value' => gmdate('H:i:s', max(0, $durations[$slug] ?? 0)),
        'description' => 'Tiempo acumulado',
        'icon' => $meta['icon_class'] ?? 'fas fa-circle',
        'color_start' => $colorStart,
        'color_end' => $colorEnd,
    ];
}

$insightCards[] = [
    'label' => 'Productividad',
    'value' => $productivity_score . '%',
    'description' => 'Tiempo productivo vs total',
    'icon' => 'fas fa-bolt',
    'color_start' => '#92a9da',
    'color_end' => '#5e7cba',
];

// Get employee data for HR requests
$employeeStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = ?");
$employeeStmt->execute([$user_id]);
$employeeData = $employeeStmt->fetch(PDO::FETCH_ASSOC);
$employeeId = $employeeData['id'] ?? null;

// Handle permission request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_permission'])) {
    if ($employeeId) {
        try {
            $permissionType = $_POST['permission_type'];
            $startDate = $_POST['permission_start_date'];
            $endDate = $_POST['permission_end_date'];
            $reason = trim($_POST['permission_reason']);
            
            // Calculate total days
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $interval = $start->diff($end);
            $totalDays = $interval->days + 1;
            
            $insertStmt = $pdo->prepare("
                INSERT INTO permission_requests (employee_id, user_id, request_type, start_date, end_date, total_days, reason, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
            ");
            $insertStmt->execute([$employeeId, $user_id, $permissionType, $startDate, $endDate, $totalDays, $reason]);
            
            $_SESSION['permission_success'] = "Solicitud de permiso enviada correctamente.";
            header('Location: agent_dashboard.php?dates=' . urlencode($date_filter));
            exit;
        } catch (Exception $e) {
            $_SESSION['permission_error'] = "Error al enviar la solicitud: " . $e->getMessage();
            header('Location: agent_dashboard.php?dates=' . urlencode($date_filter));
            exit;
        }
    } else {
        $_SESSION['permission_error'] = "No se encontró información de empleado para este usuario.";
        header('Location: agent_dashboard.php?dates=' . urlencode($date_filter));
        exit;
    }
}

// Handle vacation request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vacation'])) {
    if ($employeeId) {
        try {
            $startDate = $_POST['vacation_start_date'];
            $endDate = $_POST['vacation_end_date'];
            $days = (int)$_POST['vacation_days'];
            $reason = trim($_POST['vacation_reason']);
            
            $insertStmt = $pdo->prepare("
                INSERT INTO vacation_requests (employee_id, user_id, start_date, end_date, total_days, reason, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'PENDING', NOW())
            ");
            $insertStmt->execute([$employeeId, $user_id, $startDate, $endDate, $days, $reason]);
            
            $_SESSION['vacation_success'] = "Solicitud de vacaciones enviada correctamente.";
            header('Location: agent_dashboard.php?dates=' . urlencode($date_filter));
            exit;
        } catch (Exception $e) {
            $_SESSION['vacation_error'] = "Error al enviar la solicitud: " . $e->getMessage();
            header('Location: agent_dashboard.php?dates=' . urlencode($date_filter));
            exit;
        }
    } else {
        $_SESSION['vacation_error'] = "No se encontró información de empleado para este usuario.";
        header('Location: agent_dashboard.php?dates=' . urlencode($date_filter));
        exit;
    }
}

// Get pending requests
$pendingPermissions = [];
$pendingVacations = [];
if ($employeeId) {
    $permStmt = $pdo->prepare("
        SELECT * FROM permission_requests 
        WHERE employee_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $permStmt->execute([$employeeId]);
    $pendingPermissions = $permStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $vacStmt = $pdo->prepare("
        SELECT * FROM vacation_requests 
        WHERE employee_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $vacStmt->execute([$employeeId]);
    $pendingVacations = $vacStmt->fetchAll(PDO::FETCH_ASSOC);
}

$chartLabels = [];
$chartData = [];
$chartColors = [];
$chartTotal = 0;

if ($workSeconds > 0) {
    $chartLabels[] = 'Productivo';
    $chartData[] = $workSeconds;
    $chartColors[] = '#92a9da';
    $chartTotal += $workSeconds;
}

foreach ($durationTypes as $typeMeta) {
    $slug = $typeMeta['slug'];
    $value = $durations[$slug] ?? 0;
    if ($value <= 0) {
        continue;
    }
    $meta = $attendanceTypeMap[$slug] ?? null;
    $colorStart = sanitizeHexColorValue($meta['color_start'] ?? '#3a5da0', '#3a5da0');
    $chartLabels[] = $typeMeta['label'];
    $chartData[] = $value;
    $chartColors[] = $colorStart;
    $chartTotal += $value;
}

$chartLabelsJson = json_encode($chartLabels, JSON_UNESCAPED_UNICODE);
$chartDataJson = json_encode($chartData);
$chartColorsJson = json_encode($chartColors);
?>

<?php include 'header_agent.php'; ?>
<div class="agent-dashboard">
    <!-- Quick Punch Section -->
    <section class="glass-card mb-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-xl font-semibold text-primary flex items-center gap-2">
                    <i class="fas fa-fingerprint text-emerald-400"></i>
                    Registro Rápido de Asistencia
                </h2>
                <p class="text-sm text-muted mt-1">Marca tu asistencia directamente desde aquí</p>
            </div>
        </div>
        
        <?php if ($punch_success): ?>
            <div class="bg-green-500/10 border border-green-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                <div class="flex items-center gap-2">
                    <i class="fas fa-check-circle text-green-400"></i>
                    <p class="text-green-300 text-sm"><?= htmlspecialchars($punch_success) ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($logout_error): ?>
            <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle text-red-400"></i>
                    <p class="text-red-300 text-sm"><?= htmlspecialchars($logout_error) ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($punch_error): ?>
            <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-red-400"></i>
                    <p class="text-red-300 text-sm"><?= htmlspecialchars($punch_error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div id="agentPunchStatus" class="hidden mb-4"></div>

        <form method="POST" id="agentPunchForm" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-3">
            <div class="col-span-2 sm:col-span-3 md:col-span-4 lg:col-span-7">
                <label for="authorization_code" class="block text-xs font-semibold text-slate-200 uppercase tracking-wide mb-2">
                    Código de autorización (si aplica)
                </label>
                <input
                    type="text"
                    id="authorization_code"
                    name="authorization_code"
                    placeholder="Ingresa el código para horas extras o entrada temprana"
                    class="input-control w-full"
                    autocomplete="off"
                >
                <p class="text-xs text-slate-400 mt-2">Solo es necesario cuando el sistema lo solicita (hora extra o entrada antes de horario).</p>
            </div>
            <?php foreach ($activeAttendanceTypes as $type): ?>
                <?php
                    $buttonSlug = htmlspecialchars($type['slug'], ENT_QUOTES, 'UTF-8');
                    $buttonLabel = htmlspecialchars($type['label'], ENT_QUOTES, 'UTF-8');
                    $iconClass = htmlspecialchars($type['icon_class'] ?? 'fas fa-circle', ENT_QUOTES, 'UTF-8');
                    $colorStart = htmlspecialchars($type['color_start'] ?? '#3a5da0', ENT_QUOTES, 'UTF-8');
                    $colorEnd = htmlspecialchars($type['color_end'] ?? $colorStart, ENT_QUOTES, 'UTF-8');
                ?>
                <button type="submit" 
                        name="punch_type" 
                        value="<?= $buttonSlug ?>" 
                        class="punch-btn group relative overflow-hidden rounded-xl p-4 transition-all duration-300 hover:scale-105 hover:shadow-lg"
                        style="background: linear-gradient(135deg, <?= $colorStart ?> 0%, <?= $colorEnd ?> 100%);">
                    <div class="absolute inset-0 bg-white/0 group-hover:bg-white/10 transition-colors duration-300"></div>
                    <div class="relative flex flex-col items-center gap-2">
                        <i class="<?= $iconClass ?> text-2xl text-white"></i>
                        <span class="text-xs font-semibold text-white text-center"><?= $buttonLabel ?></span>
                    </div>
                </button>
            <?php endforeach; ?>
        </form>
    </section>
    <section class="glass-card mb-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-xl font-semibold text-primary flex items-center gap-2">
                    <i class="fas fa-clock text-sky-400"></i>
                    Horario de Trabajo
                </h2>
                <p class="text-sm text-muted mt-1">Horario aplicado para <?= htmlspecialchars(date('d/m/Y', strtotime($date_filter))) ?></p>
            </div>
            <span class="badge badge--info"><?= htmlspecialchars($scheduleName) ?></span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
            <div class="bg-slate-800/60 rounded-lg p-3">
                <p class="text-xs text-slate-400 uppercase tracking-wide">Entrada</p>
                <p class="text-lg font-semibold text-white"><?= htmlspecialchars($scheduleEntry ?? 'No definida') ?></p>
            </div>
            <div class="bg-slate-800/60 rounded-lg p-3">
                <p class="text-xs text-slate-400 uppercase tracking-wide">Salida</p>
                <p class="text-lg font-semibold text-white"><?= htmlspecialchars($scheduleExit ?? 'No definida') ?></p>
            </div>
            <div class="bg-slate-800/60 rounded-lg p-3">
                <p class="text-xs text-slate-400 uppercase tracking-wide">Horas</p>
                <p class="text-lg font-semibold text-white"><?= htmlspecialchars($scheduleHours) ?> hrs</p>
            </div>
        </div>
        <?php if (!empty($scheduleSegments)): ?>
            <div class="space-y-2">
                <?php foreach ($scheduleSegments as $segment): ?>
                    <?php
                        $segmentEntry = formatScheduleTimeLabel($segment['entry_time'] ?? null) ?? 'No definida';
                        $segmentExit = formatScheduleTimeLabel($segment['exit_time'] ?? null) ?? 'No definida';
                        $segmentDays = formatScheduleDaysLabel($segment['days_of_week'] ?? null);
                        $segmentEffective = $segment['effective_date'] ?? null;
                        $segmentEnd = $segment['end_date'] ?? null;
                        $segmentRange = $segmentEffective
                            ? $segmentEffective . ($segmentEnd ? ' → ' . $segmentEnd : '')
                            : 'Sin fecha';
                        $segmentHours = number_format((float) ($segment['scheduled_hours'] ?? 0), 2);
                    ?>
                    <div class="flex items-center justify-between gap-3 bg-slate-800/60 rounded-lg p-3">
                        <div>
                            <p class="text-slate-200 font-medium"><?= htmlspecialchars($segment['schedule_name'] ?? 'Horario') ?></p>
                            <p class="text-xs text-slate-400">
                                <?= htmlspecialchars($segmentEntry) ?> - <?= htmlspecialchars($segmentExit) ?> · <?= htmlspecialchars($segmentRange) ?> · <?= htmlspecialchars($segmentDays) ?>
                            </p>
                        </div>
                        <span class="text-xs text-slate-300 font-semibold"><?= htmlspecialchars($segmentHours) ?> hrs</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-sm text-slate-400">Usando el horario global del sistema.</p>
        <?php endif; ?>
    </section>
    <section class="dashboard-hero glass-card">
        <div class="hero-main">
            <div class="space-y-2">
                <span class="badge badge--info">Sesión agente</span>
                <h1 class="text-2xl font-semibold text-primary">Hola, <?= htmlspecialchars($full_name) ?></h1>
                <p class="text-muted text-sm">Resumen de productividad para <?= htmlspecialchars(date('d \d\e M \d\e Y', strtotime($date_filter))) ?></p>
            </div>
            <div class="hero-progress">
                <span class="text-sm text-muted uppercase tracking-[0.18em]">Productividad</span>
                <div class="progress-circle" style="--progress: <?= min($productivity_score, 100) ?>%;">
                    <span><?= $productivity_score ?>%</span>
                </div>
            </div>
        </div>
        <div class="hero-metric-grid">
            <?php foreach ($heroMetrics as $metric): ?>
                <div class="hero-metric">
                    <p class="text-xs text-muted uppercase tracking-[0.18em]"><?= htmlspecialchars($metric['label']) ?></p>
                    <p class="text-xl font-semibold text-primary"><?= htmlspecialchars($metric['value']) ?></p>
                    <p class="text-xs text-muted"><?= htmlspecialchars($metric['note']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <form method="get" class="hero-range-filter">
            <label>
                <span>Detalle del día</span>
                <input type="date" name="dates" id="dates" value="<?= htmlspecialchars($date_filter) ?>" class="input-control">
            </label>
            <button type="submit" class="btn-primary">Actualizar</button>
        </form>
    </section>

    <div class="insight-grid agent-focus-grid">
        <article class="glass-card chart-card <?= $chartTotal <= 0 ? 'is-empty' : '' ?>">
            <header class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-primary">Distribución de tiempo</h2>
            </header>
            <canvas id="timeBreakdownChart" aria-label="Distribución de tiempo" role="img"></canvas>
            <p class="chart-empty <?= $chartTotal > 0 ? 'hidden' : '' ?>" data-chart-empty>Sin datos suficientes para graficar en esta fecha.</p>
        </article>

        <article class="glass-card timeline-card">
            <header>
                <h2 class="text-lg font-semibold text-primary">Timeline de actividades</h2>
                <p class="text-sm text-muted">Historial cronológico de tus marcaciones.</p>
            </header>
            <?php if (!empty($records)): ?>
                <ol class="timeline">
                    <?php foreach ($records as $record): ?>
                        <li class="timeline-item">
                            <span class="timeline-dot" style="--dot-color: <?= htmlspecialchars($record['color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    <i class="<?= htmlspecialchars($record['icon']) ?>"></i>
                                    <span><?= htmlspecialchars($record['label']) ?></span>
                                </div>
                                <div class="timeline-time"><?= htmlspecialchars($record['time']) ?> &ndash; <?= htmlspecialchars($record['ip']) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <p class="timeline-empty">Aún no hay eventos registrados para esta fecha.</p>
            <?php endif; ?>
        </article>
    </div>

    <section class="metric-section">
        <header class="metric-section__head">
            <h2>Desglose de tiempo por actividad</h2>
            <p>Tiempo acumulado del día por cada tipo de marcación.</p>
        </header>
        <div class="metric-grid">
        <?php foreach ($insightCards as $card): ?>
            <article class="metric-card" style="--metric-start: <?= htmlspecialchars($card['color_start'], ENT_QUOTES, 'UTF-8') ?>; --metric-end: <?= htmlspecialchars($card['color_end'], ENT_QUOTES, 'UTF-8') ?>;">
                <div class="metric-icon"><i class="<?= htmlspecialchars($card['icon']) ?>"></i></div>
                <p class="metric-label"><?= htmlspecialchars($card['label']) ?></p>
                <p class="metric-value"><?= htmlspecialchars($card['value']) ?></p>
                <p class="metric-sub"><?= htmlspecialchars($card['description']) ?></p>
            </article>
        <?php endforeach; ?>
        </div>
    </section>

    <section class="glass-card mb-6">
        <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-semibold text-primary">Calidad del agente</h2>
                <p class="text-sm text-muted">Métricas y auditorías desde el sistema de calidad.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge badge--info">Auditorías</span>
                <a href="agent_quality.php" class="btn-secondary">
                    <i class="fas fa-star"></i>
                    Ver todo
                </a>
            </div>
        </header>

        <?php if ($qualityError): ?>
            <div class="bg-amber-500/10 border border-amber-500/30 rounded-lg p-4">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle text-amber-400"></i>
                    <p class="text-amber-200 text-sm"><?= htmlspecialchars($qualityError) ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="metric-card" style="--metric-start: #264b8b; --metric-end: #1f3f76;">
                    <div class="metric-icon"><i class="fas fa-clipboard-check"></i></div>
                    <p class="metric-label">Evaluaciones</p>
                    <p class="metric-value"><?= (int) $qualityMetrics['total_evaluations'] ?></p>
                    <p class="metric-sub">Total registradas</p>
                </div>
                <div class="metric-card" style="--metric-start: #22c55e; --metric-end: #16a34a;">
                    <div class="metric-icon"><i class="fas fa-chart-line"></i></div>
                    <p class="metric-label">Promedio de calidad</p>
                    <p class="metric-value"><?= number_format((float) $qualityMetrics['avg_percentage'], 2) ?>%</p>
                    <p class="metric-sub">Score promedio</p>
                </div>
                <div class="metric-card" style="--metric-start: #f97316; --metric-end: #ea580c;">
                    <div class="metric-icon"><i class="fas fa-headphones"></i></div>
                    <p class="metric-label">Llamadas auditadas</p>
                    <p class="metric-value"><?= (int) $qualityMetrics['audited_calls'] ?></p>
                    <p class="metric-sub">Con evaluación</p>
                </div>
                <div class="metric-card" style="--metric-start: #92a9da; --metric-end: #5e7cba;">
                    <div class="metric-icon"><i class="fas fa-star"></i></div>
                    <p class="metric-label">Mejor / Peor</p>
                    <p class="metric-value">
                        <?= number_format((float) $qualityMetrics['max_percentage'], 2) ?>% / <?= number_format((float) $qualityMetrics['min_percentage'], 2) ?>%
                    </p>
                    <p class="metric-sub">Rango de desempeño</p>
                </div>
                <div class="metric-card" style="--metric-start: #1f3f76; --metric-end: #0f766e;">
                    <div class="metric-icon"><i class="fas fa-chart-bar"></i></div>
                    <p class="metric-label">Score Analítico</p>
                    <p class="metric-value"><?= number_format((float) $qualityMetrics['avg_ai_score'], 2) ?></p>
                    <p class="metric-sub">Promedio QA</p>
                </div>
                <div class="metric-card" style="--metric-start: #64748b; --metric-end: #334155;">
                    <div class="metric-icon"><i class="fas fa-calendar-alt"></i></div>
                    <p class="metric-label">Última evaluación</p>
                    <p class="metric-value">
                        <?= $qualityMetrics['last_eval_date'] ? htmlspecialchars(date('d/m/Y', strtotime($qualityMetrics['last_eval_date']))) : 'N/A' ?>
                    </p>
                    <p class="metric-sub">Fecha más reciente</p>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <article class="glass-card table-card">
        <header>
            <h2>Llamadas auditadas</h2>
            <span><?= count($qualityAudits) ?> registros</span>
        </header>
        <div class="responsive-scroll">
            <table class="data-table" data-skip-responsive="true">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Campaña</th>
                        <th>Score</th>
                        <th>Score QA</th>
                        <th>Resumen</th>
                        <th>Audio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($qualityAudits)): ?>
                        <?php foreach ($qualityAudits as $audit): ?>
                            <?php
                                $audioUrl = resolveQualityRecordingUrl($audit['recording_path'] ?? null);
                                $scoreValue = $audit['percentage'] !== null ? number_format((float) $audit['percentage'], 2) . '%' : 'N/A';
                                $aiScoreValue = $audit['ai_score'] !== null ? number_format((float) $audit['ai_score'], 2) : 'N/A';
                                $summaryText = $audit['ai_summary'] ?: ($audit['general_comments'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars(date('d/m/Y', strtotime($audit['call_date'] ?: $audit['created_at']))) ?><br>
                                    <span class="text-xs text-slate-400">
                                        <?= htmlspecialchars(date('H:i', strtotime($audit['call_datetime'] ?: $audit['created_at']))) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= htmlspecialchars($audit['campaign_name'] ?? 'Sin campaña') ?>
                                    <?php if (!empty($audit['call_type'])): ?>
                                        <div class="text-xs text-slate-400"><?= htmlspecialchars($audit['call_type']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $scoreValue ?></td>
                                <td><?= htmlspecialchars($aiScoreValue) ?></td>
                                <td class="text-sm">
                                    <?= htmlspecialchars(mb_strimwidth((string) $summaryText, 0, 140, '...')) ?>
                                </td>
                                <td>
                                    <?php if ($audioUrl): ?>
                                        <audio controls preload="none" style="min-width: 220px;">
                                            <source src="<?= htmlspecialchars($audioUrl) ?>" type="audio/mpeg">
                                        </audio>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">Sin audio</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="data-table-empty">No hay llamadas auditadas disponibles.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>


    <article class="glass-card table-card">
        <header>
            <h2>Detalle de eventos</h2>
            <span><?= count($records) ?> eventos</span>
        </header>
        <div class="responsive-scroll">
            <table class="data-table" data-skip-responsive="true">
                <thead>
                    <tr>
                        <th>Evento</th>
                        <th>Hora</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($records)): ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?= htmlspecialchars($record['label']) ?></td>
                                <td><?= htmlspecialchars($record['time']) ?></td>
                                <td><?= htmlspecialchars($record['ip']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="data-table-empty">No se encontraron registros.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <?php if ($employeeId): ?>
    <!-- Solicitudes a RH: tarjetas de acción que abren modales profesionales -->
    <section class="glass-card agent-requests-card">
        <header class="agent-requests-head">
            <h2 class="text-lg font-semibold text-primary flex items-center gap-2">
                <i class="fas fa-paper-plane text-blue-400"></i>
                Solicitudes a Recursos Humanos
            </h2>
            <p class="text-sm text-muted">Permisos, vacaciones y préstamos — envía y da seguimiento.</p>
        </header>
        <?php if ($permission_success || $vacation_success): ?>
            <div class="req-banner req-banner--ok animate-fade-in"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($permission_success ?: $vacation_success) ?></span></div>
        <?php endif; ?>
        <?php if ($permission_error || $vacation_error): ?>
            <div class="req-banner req-banner--err animate-fade-in"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($permission_error ?: $vacation_error) ?></span></div>
        <?php endif; ?>
        <div class="req-actions-grid">
            <button type="button" class="req-action" data-modal-open="modalPermiso">
                <span class="req-action__icon" style="--c1:#3b82f6;--c2:#2563eb;"><i class="fas fa-calendar-check"></i></span>
                <span class="req-action__body"><span class="req-action__title">Solicitar Permiso</span><span class="req-action__sub"><?= count($pendingPermissions ?? []) ?> en seguimiento</span></span>
                <i class="fas fa-chevron-right req-action__arrow"></i>
            </button>
            <button type="button" class="req-action" data-modal-open="modalVacaciones">
                <span class="req-action__icon" style="--c1:#a855f7;--c2:#7c3aed;"><i class="fas fa-umbrella-beach"></i></span>
                <span class="req-action__body"><span class="req-action__title">Solicitar Vacaciones</span><span class="req-action__sub"><?= count($pendingVacations ?? []) ?> en seguimiento</span></span>
                <i class="fas fa-chevron-right req-action__arrow"></i>
            </button>
            <a href="agents/request_loan.php" class="req-action">
                <span class="req-action__icon" style="--c1:#10b981;--c2:#059669;"><i class="fas fa-hand-holding-dollar"></i></span>
                <span class="req-action__body"><span class="req-action__title">Solicitar Préstamo</span><span class="req-action__sub">Ir al portal de préstamos</span></span>
                <i class="fas fa-chevron-right req-action__arrow"></i>
            </a>
        </div>
    </section>

    <!-- Modal: Solicitar Permiso -->
    <div class="ev-modal" id="modalPermiso" aria-hidden="true">
        <div class="ev-modal__overlay" data-modal-close></div>
        <div class="ev-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modalPermisoTitle">
            <div class="ev-modal__header">
                <h3 id="modalPermisoTitle"><i class="fas fa-calendar-check text-blue-400"></i> Solicitar Permiso</h3>
                <button type="button" class="ev-modal__close" data-modal-close aria-label="Cerrar"><i class="fas fa-times"></i></button>
            </div>
            <div class="ev-modal__body">
            
            <?php if ($permission_success): ?>
                <div class="bg-green-500/10 border border-green-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-check-circle text-green-400"></i>
                        <p class="text-green-300 text-sm"><?= htmlspecialchars($permission_success) ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($permission_error): ?>
                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-exclamation-circle text-red-400"></i>
                        <p class="text-red-300 text-sm"><?= htmlspecialchars($permission_error) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Tipo de Permiso</label>
                    <select name="permission_type" required class="input-control w-full">
                        <option value="MEDICAL">Médico</option>
                        <option value="PERSONAL">Personal</option>
                        <option value="STUDY">Estudio</option>
                        <option value="FAMILY">Familiar</option>
                        <option value="OTHER">Otro</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">Fecha Inicio</label>
                        <input type="date" name="permission_start_date" required class="input-control w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Fecha Fin</label>
                        <input type="date" name="permission_end_date" required class="input-control w-full">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Motivo</label>
                    <textarea name="permission_reason" rows="3" required class="input-control w-full" placeholder="Describe el motivo de tu solicitud..."></textarea>
                </div>
                <button type="submit" name="submit_permission" class="btn-primary w-full">
                    <i class="fas fa-paper-plane"></i>
                    Enviar Solicitud de Permiso
                </button>
            </form>

            <?php if (!empty($pendingPermissions)): ?>
                <div class="mt-6 pt-6 border-t border-slate-700">
                    <h3 class="text-sm font-semibold mb-3">Mis Solicitudes de Permisos</h3>
                    <div class="space-y-2">
                        <?php foreach ($pendingPermissions as $perm): ?>
                            <div class="bg-slate-800/50 rounded-lg p-3">
                                <div class="flex justify-between items-start mb-1">
                                    <span class="text-sm font-medium"><?= htmlspecialchars($perm['request_type']) ?></span>
                                    <span class="px-2 py-1 rounded text-xs <?= $perm['status'] === 'APPROVED' ? 'bg-green-500/20 text-green-300' : ($perm['status'] === 'REJECTED' ? 'bg-red-500/20 text-red-300' : 'bg-yellow-500/20 text-yellow-300') ?>">
                                        <?= htmlspecialchars($perm['status']) ?>
                                    </span>
                                </div>
                                <p class="text-xs text-muted"><?= htmlspecialchars($perm['start_date']) ?> - <?= htmlspecialchars($perm['end_date']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal: Solicitar Vacaciones -->
    <div class="ev-modal" id="modalVacaciones" aria-hidden="true">
        <div class="ev-modal__overlay" data-modal-close></div>
        <div class="ev-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modalVacacionesTitle">
            <div class="ev-modal__header">
                <h3 id="modalVacacionesTitle"><i class="fas fa-umbrella-beach text-purple-400"></i> Solicitar Vacaciones</h3>
                <button type="button" class="ev-modal__close" data-modal-close aria-label="Cerrar"><i class="fas fa-times"></i></button>
            </div>
            <div class="ev-modal__body">

            <?php if ($vacation_success): ?>
                <div class="bg-green-500/10 border border-green-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-check-circle text-green-400"></i>
                        <p class="text-green-300 text-sm"><?= htmlspecialchars($vacation_success) ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($vacation_error): ?>
                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-exclamation-circle text-red-400"></i>
                        <p class="text-red-300 text-sm"><?= htmlspecialchars($vacation_error) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">Fecha Inicio</label>
                        <input type="date" name="vacation_start_date" required class="input-control w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Fecha Fin</label>
                        <input type="date" name="vacation_end_date" required class="input-control w-full">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Días Solicitados</label>
                    <input type="number" name="vacation_days" min="1" required class="input-control w-full" placeholder="Número de días">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Motivo (Opcional)</label>
                    <textarea name="vacation_reason" rows="3" class="input-control w-full" placeholder="Describe el motivo de tus vacaciones..."></textarea>
                </div>
                <button type="submit" name="submit_vacation" class="btn-primary w-full">
                    <i class="fas fa-paper-plane"></i>
                    Enviar Solicitud de Vacaciones
                </button>
            </form>

            <?php if (!empty($pendingVacations)): ?>
                <div class="mt-6 pt-6 border-t border-slate-700">
                    <h3 class="text-sm font-semibold mb-3">Mis Solicitudes de Vacaciones</h3>
                    <div class="space-y-2">
                        <?php foreach ($pendingVacations as $vac): ?>
                            <div class="bg-slate-800/50 rounded-lg p-3">
                                <div class="flex justify-between items-start mb-1">
                                    <span class="text-sm font-medium"><?= htmlspecialchars($vac['total_days']) ?> días</span>
                                    <span class="px-2 py-1 rounded text-xs <?= $vac['status'] === 'APPROVED' ? 'bg-green-500/20 text-green-300' : ($vac['status'] === 'REJECTED' ? 'bg-red-500/20 text-red-300' : 'bg-yellow-500/20 text-yellow-300') ?>">
                                        <?= htmlspecialchars($vac['status']) ?>
                                    </span>
                                </div>
                                <p class="text-xs text-muted"><?= htmlspecialchars($vac['start_date']) ?> - <?= htmlspecialchars($vac['end_date']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const ctx = document.getElementById('timeBreakdownChart');
    const labels = <?= $chartLabelsJson ?>;
    const data = <?= $chartDataJson ?>;
    const colors = <?= $chartColorsJson ?>;
    const total = <?= (float) $chartTotal ?>;

    if (ctx && total > 0 && Array.isArray(data) && data.length) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors,
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                cutout: '70%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: '#E2E8F0',
                            font: { size: 12 }
                        }
                    }
                }
            }
        });
    }
})();

document.getElementById('dates')?.addEventListener('change', function () {
    this.form.submit();
});
</script>

<style>
.punch-btn {
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.punch-btn:hover {
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
}

.punch-btn:active {
    transform: scale(0.95) !important;
}

@keyframes fade-in {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-fade-in {
    animation: fade-in 0.3s ease-out;
}

@media (max-width: 640px) {
    .punch-btn {
        padding: 0.75rem;
    }
    
    .punch-btn i {
        font-size: 1.25rem;
    }
    
    .punch-btn span {
        font-size: 0.625rem;
    }
}

/* ============ Hero del agente: texto legible sobre el navy + aro de productividad ============ */
.dashboard-hero .text-primary, .dashboard-hero h1 { color: #fff !important; }
.dashboard-hero .text-muted { color: rgba(255,255,255,.72) !important; }
.dashboard-hero .hero-progress { height: auto; background: transparent; border-radius: 0; overflow: visible; display: flex; flex-direction: column; align-items: center; gap: .45rem; }
.dashboard-hero .hero-progress > span { background: transparent !important; color: rgba(255,255,255,.72) !important; font-size: .72rem; font-weight: 700; letter-spacing: .14em; }
.dashboard-hero .progress-circle { width: 96px; height: 96px; border-radius: 50%; display: grid; place-items: center; position: relative; background: conic-gradient(#ffffff var(--progress, 0%), rgba(255,255,255,.18) 0); }
.dashboard-hero .progress-circle::before { content: ''; position: absolute; inset: 9px; border-radius: 50%; background: #1f3f76; }
.dashboard-hero .progress-circle span { position: relative; font-weight: 800; font-size: 1.15rem; color: #fff; letter-spacing: -.02em; }

/* ============ Tarjetas de acción de solicitudes ============ */
.agent-requests-head { margin-bottom: 1.1rem; }
.req-actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: .9rem; }
.req-action { display: flex; align-items: center; gap: .9rem; padding: 1rem 1.1rem; border-radius: .9rem; background: var(--surface-2); border: 1px solid var(--border); cursor: pointer; text-decoration: none; text-align: left; transition: transform .18s, box-shadow .18s, border-color .18s, background .18s; width: 100%; font-family: inherit; }
.req-action:hover { border-color: var(--brand); transform: translateY(-2px); box-shadow: var(--shadow-md); background: var(--surface); }
.req-action__icon { width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 1.1rem; background: linear-gradient(135deg, var(--c1, var(--brand-bright)), var(--c2, var(--brand))); }
.req-action__body { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.req-action__title { font-weight: 700; color: var(--text); font-size: .95rem; }
.req-action__sub { font-size: .78rem; color: var(--text-muted); }
.req-action__arrow { color: var(--text-subtle, var(--text-muted)); font-size: .8rem; }
.req-banner { display: flex; align-items: center; gap: .6rem; padding: .8rem 1rem; border-radius: .7rem; margin-bottom: 1rem; font-size: .9rem; font-weight: 600; }
.req-banner--ok { background: rgba(16,185,129,.12); color: #047857; border: 1px solid rgba(16,185,129,.3); }
.req-banner--err { background: rgba(239,68,68,.1); color: #b91c1c; border: 1px solid rgba(239,68,68,.3); }

/* ============ Sistema de modales profesional ============ */
.ev-modal { position: fixed; inset: 0; z-index: 1200; display: none; }
.ev-modal.is-open { display: block; }
.ev-modal__overlay { position: absolute; inset: 0; background: rgba(10,18,35,.55); backdrop-filter: blur(4px); animation: evFade .2s ease; }
.ev-modal__dialog { position: relative; width: calc(100% - 2rem); max-width: 540px; margin: 6vh auto; background: var(--surface); border: 1px solid var(--border); border-radius: 1rem; box-shadow: 0 24px 60px -12px rgba(15,23,42,.45); max-height: 88vh; display: flex; flex-direction: column; animation: evPop .24s cubic-bezier(.2,.8,.25,1); }
.ev-modal__header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.1rem 1.4rem; border-bottom: 1px solid var(--border); }
.ev-modal__header h3 { font-size: 1.1rem; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: .55rem; margin: 0; }
.ev-modal__close { width: 36px; height: 36px; border-radius: 10px; background: var(--surface-2); color: var(--text-muted); border: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: background .15s, color .15s; }
.ev-modal__close:hover { background: var(--danger); color: #fff; }
.ev-modal__body { padding: 1.4rem; overflow-y: auto; }
.ev-modal__body label { color: var(--text); }
@keyframes evFade { from { opacity: 0 } to { opacity: 1 } }
@keyframes evPop { from { opacity: 0; transform: translateY(16px) scale(.98) } to { opacity: 1; transform: none } }
@media (max-width: 600px) {
    .ev-modal__dialog { width: 100%; max-width: 100%; min-height: 100vh; margin: 0; border-radius: 0; max-height: 100vh; }
}

/* Punch buttons del dashboard: más altos y táctiles (el sistema de diseño vive en agent-portal.css) */
.agent-shell .punch-btn { border-radius: .95rem; padding: 1.05rem .75rem; min-height: 88px; transition: transform .15s ease, box-shadow .2s ease, filter .2s ease; }
.agent-shell .punch-btn:hover { transform: translateY(-2px); filter: brightness(1.05); }
</style>

<script>
    // Marcación por AJAX en el dashboard del agente: confirma el guardado real y reintenta
    // ante 429 (límite por IP de HostGator) o caídas de red, para que ninguna salida/entrada
    // se pierda silenciosamente.
    (function () {
        const form = document.getElementById('agentPunchForm');
        if (!form) return;

        const PUNCH_MAX_RETRIES = <?= (int) getSystemSetting($pdo, 'punch_client_max_retries', 3) ?>;
        const statusDiv = document.getElementById('agentPunchStatus');
        const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

        // Backoff exponencial con jitter: de-sincroniza los reintentos de la oficina (IP NAT compartida).
        function backoff(attempt) {
            return Math.min(4000, 400 * Math.pow(2, attempt)) + Math.floor(Math.random() * 600);
        }

        function showStatus(ok, message) {
            statusDiv.className = (ok
                ? 'mb-4 rounded-lg p-4 bg-green-500/10 border border-green-500/30 text-green-300 font-semibold'
                : 'mb-4 rounded-lg p-4 bg-red-500/10 border border-red-500/40 text-red-300 font-bold');
            statusDiv.innerHTML = (ok ? '<i class="fas fa-check-circle mr-2"></i>' : '<i class="fas fa-exclamation-triangle mr-2"></i>') + message;
            statusDiv.classList.remove('hidden');
            statusDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function setButtonsDisabled(disabled) {
            form.querySelectorAll('.punch-btn').forEach(function (b) {
                b.disabled = disabled;
                b.style.opacity = disabled ? '0.6' : '';
                b.style.pointerEvents = disabled ? 'none' : '';
            });
        }

        async function safeJson(resp) {
            try { return await resp.json(); } catch (e) { return null; }
        }

        let lastType = null;
        form.querySelectorAll('.punch-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { lastType = btn.value; });
        });

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const type = (e.submitter && e.submitter.value) ? e.submitter.value : lastType;
            if (!type) { showStatus(false, 'Selecciona un tipo de marcación.'); return; }

            const authInput = document.getElementById('authorization_code');
            const fd = new FormData();
            fd.append('punch_type', type);
            fd.append('ajax', '1');
            if (authInput && authInput.value.trim()) fd.append('authorization_code', authInput.value.trim());

            setButtonsDisabled(true);
            showStatus(true, 'Registrando marcación…');

            const url = window.location.pathname + window.location.search;
            let attempt = 0;
            while (attempt <= PUNCH_MAX_RETRIES) {
                try {
                    const resp = await fetch(url, {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store'
                    });

                    if (resp.status === 429 || resp.status >= 500) {
                        attempt++;
                        if (attempt > PUNCH_MAX_RETRIES) break;
                        showStatus(true, `Servidor ocupado, reintentando (${attempt}/${PUNCH_MAX_RETRIES})…`);
                        await sleep(backoff(attempt));
                        continue;
                    }

                    const data = await safeJson(resp);
                    if (!resp.ok || !data) {
                        showStatus(false, (data && data.message) ? data.message : 'No se pudo registrar la marcación. Vuelve a intentar.');
                        setButtonsDisabled(false);
                        return;
                    }

                    if (data.success) {
                        showStatus(true, '✅ ' + (data.message || 'Marcación registrada.'));
                        await sleep(900);
                        window.location.reload();
                        return;
                    }

                    showStatus(false, data.message || 'No se pudo registrar la marcación.');
                    setButtonsDisabled(false);
                    return;

                } catch (err) {
                    attempt++;
                    if (attempt > PUNCH_MAX_RETRIES) break;
                    showStatus(true, `Sin conexión, reintentando (${attempt}/${PUNCH_MAX_RETRIES})…`);
                    await sleep(backoff(attempt));
                }
            }

            showStatus(false, '⚠️ Tu marcación NO se registró (conexión saturada). Por favor vuelve a marcar.');
            setButtonsDisabled(false);
        });
    })();
</script>

<script>
/* Modales profesionales del portal de agente (permiso / vacaciones) */
(function () {
    function openModal(id){ var m = document.getElementById(id); if(!m) return; m.classList.add('is-open'); m.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
    function closeModal(m){ if(!m) return; m.classList.remove('is-open'); m.setAttribute('aria-hidden','true'); document.body.style.overflow=''; }
    document.querySelectorAll('[data-modal-open]').forEach(function(b){ b.addEventListener('click', function(){ openModal(b.getAttribute('data-modal-open')); }); });
    document.querySelectorAll('[data-modal-close]').forEach(function(b){ b.addEventListener('click', function(){ closeModal(b.closest('.ev-modal')); }); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ document.querySelectorAll('.ev-modal.is-open').forEach(closeModal); } });
    // Reabrir el modal correspondiente si la última solicitud falló, para corregir y reintentar
    <?php if (!empty($permission_error)): ?>openModal('modalPermiso');<?php endif; ?>
    <?php if (!empty($vacation_error)): ?>openModal('modalVacaciones');<?php endif; ?>
})();
</script>

<?php include 'footer.php'; ?>
