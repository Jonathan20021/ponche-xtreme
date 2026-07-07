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
    function sanitizeHexColorValue(?string $color, string $fallback = '#6366F1'): string
    {
        $value = strtoupper(trim((string) $color));
        return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : strtoupper($fallback);
    }
}

// Formato "Xh Ym" seguro para totales de MÁS de 24h (gmdate() haría wrap a 0).
if (!function_exists('agFmtHoursHM')) {
    function agFmtHoursHM(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return intdiv($seconds, 3600) . 'h ' . str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm';
    }
}

$user_id = (int) $_SESSION['user_id'];
$username = $_SESSION['username'] ?? null;
$full_name = $_SESSION['full_name'] ?? null;

// Fuente de nómina del agente: 'manual' (marca desde el portal / ponche) o
// 'vicidial'. Define qué ve el dashboard: los agentes manuales marcan aquí y
// ven sus horas del ponche; los de Vicidial ven su actividad de Vicidial.
$payrollSource = 'manual';
try {
    $psSt = $pdo->prepare("SELECT COALESCE(payroll_source, 'manual') FROM users WHERE id = ?");
    $psSt->execute([$user_id]);
    $payrollSource = $psSt->fetchColumn() ?: 'manual';
} catch (Throwable $e) {
    $payrollSource = 'manual';
}
$isManualPunch = ($payrollSource === 'manual');

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
    
    // Check early punch authorization (solo aplica a ENTRADA / inicio de trabajo pagado;
    // breaks, lunch, pausas y salida quedan exentos vía isEarlyPunchAttempt)
    if ($authSystemEnabled && $authRequiredForEarlyPunch) {
        $isEarly = isEarlyPunchAttempt($pdo, $user_id, $typeSlug);

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
    $colorStart = sanitizeHexColorValue($meta['color_start'] ?? '#38BDF8', '#38BDF8');
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
        'color_start' => '#38BDF8',
        'color_end' => '#2563EB',
    ],
];

foreach ($durationTypes as $typeMeta) {
    $slug = $typeMeta['slug'];
    $meta = $attendanceTypeMap[$slug] ?? null;
    $colorStart = sanitizeHexColorValue($meta['color_start'] ?? '#6366F1', '#6366F1');
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
    'color_start' => '#C084FC',
    'color_end' => '#7C3AED',
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

// ---- Horas del ponche (día seleccionado) + horas acumuladas de la quincena ----
// Alineadas EXACTO con la nómina (misma librería lib/agent_hours.php). Para
// agentes manuales alimentan el panel de ponche; para TODOS, la tarjeta
// "Horas de la quincena".
require_once __DIR__ . '/lib/agent_hours.php';
$paidTypeSlugsDash = array_values(array_filter(array_map('sanitizeAttendanceTypeSlug', getPaidAttendanceTypeSlugs($pdo))));

$dayPonche = calculateDailyWorkSecondsFromPunchRows($eventRows, $paidTypeSlugsDash);
$poncheTodaySeconds = (int) ($dayPonche[$date_filter] ?? array_sum($dayPonche));

$agentPeriods  = getAgentVisiblePeriods($pdo);
$currentPeriod = pickCurrentAgentPeriod($agentPeriods, date('Y-m-d'));
$periodHours   = null;
if ($currentPeriod) {
    $ucSt = $pdo->prepare("SELECT compensation_type, role, monthly_salary, monthly_salary_dop FROM users WHERE id = ?");
    $ucSt->execute([$user_id]);
    $uc = $ucSt->fetch(PDO::FETCH_ASSOC) ?: [];
    $qualHol = shouldApplyHolidayDoublePay(
        $uc['compensation_type'] ?? null,
        $uc['role'] ?? null,
        max((float) ($uc['monthly_salary'] ?? 0), (float) ($uc['monthly_salary_dop'] ?? 0))
    );
    $periodHours = computePeriodHoursForUser(
        $pdo, $user_id, $currentPeriod['start_date'], $currentPeriod['end_date'],
        $paidTypeSlugsDash, 44.0, $qualHol, $payrollSource
    );
}

// ---- Datos de HOY desde Vicidial (para agentes de Vicidial) ----
require_once __DIR__ . '/lib/vicidial_api_client.php';

$vicidialToday = null;
try {
    $vtStmt = $pdo->prepare("
        SELECT report_date, user_group, first_login, last_activity,
               total_logged_seconds, nonpause_seconds, pause_breakdown,
               calls, talk_seconds
        FROM vicidial_agent_timesheet
        WHERE user_id = ? AND report_date = ?
        ORDER BY imported_at DESC LIMIT 1
    ");
    $vtStmt->execute([$user_id, $date_filter]);
    $vicidialToday = $vtStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $vicidialToday = null;
}

$vicidialHasData = false;
$vicidialPaidSeconds = 0;
$vicidialProductivity = 0;
$vicidialDist = [];   // [label => segundos] para el donut de distribución

if ($vicidialToday) {
    $vicidialHasData = true;
    $pauseCodes = $vicidialToday['pause_breakdown'] ? json_decode($vicidialToday['pause_breakdown'], true) : [];
    if (!is_array($pauseCodes)) { $pauseCodes = []; }
    $paidCodesList = vicidialGetPaidPauseCodes($pdo);
    $capSec = (int) round((float) getSystemSetting($pdo, 'vicidial_payroll_daily_cap_hours', 14) * 3600);
    $calcPaid = vicidialComputePaidSeconds((int) $vicidialToday['nonpause_seconds'], $pauseCodes, $paidCodesList, $capSec);
    $vicidialPaidSeconds = $calcPaid['paid_seconds'];

    $vNonpause = max(0, (int) $vicidialToday['nonpause_seconds']);
    $vTotalLogged = max(0, (int) $vicidialToday['total_logged_seconds']);
    $vSumPauses = 0;
    foreach ($pauseCodes as $sec) { $vSumPauses += max(0, (int) $sec); }
    $vDenom = $vTotalLogged > 0 ? $vTotalLogged : ($vNonpause + $vSumPauses);
    $vicidialProductivity = $vDenom > 0 ? round($vNonpause / $vDenom * 100, 1) : 0;

    $vicidialDist['Productivo'] = $vNonpause;
    foreach ($pauseCodes as $code => $sec) {
        $sec = max(0, (int) $sec);
        if ($sec > 0) { $vicidialDist[(string) $code] = $sec; }
    }
}

// Donut "Distribución del día" alimentado desde Vicidial (reusa el script del pie)
$distColors = [
    'Productivo' => '#244886', 'Break' => '#F79009', 'Bao' => '#EF4444',
    'Coachi' => '#16C8C7', 'Digita' => '#5347CE', 'ITRes' => '#4896FE',
    'LAGGED' => '#887CFD', 'LOGIN' => '#12B76A', 'wasapi' => '#0EA5E9', 'NXDIAL' => '#94A3B8',
];
$fallbackColors = ['#4896FE', '#5347CE', '#16C8C7', '#F79009', '#887CFD', '#EF4444', '#0EA5E9'];
$chartLabels = [];
$chartData = [];
$chartColors = [];
$chartTotal = 0;
$fi = 0;
foreach ($vicidialDist as $label => $sec) {
    if ($sec <= 0) { continue; }
    $chartLabels[] = $label;
    $chartData[] = $sec;
    $chartColors[] = $distColors[$label] ?? $fallbackColors[$fi++ % count($fallbackColors)];
    $chartTotal += $sec;
}
$chartLabelsJson = json_encode($chartLabels, JSON_UNESCAPED_UNICODE);
$chartDataJson = json_encode($chartData);
$chartColorsJson = json_encode($chartColors);
?>

<?php include 'header_agent.php'; ?>
<div class="agent-dashboard">

    <!-- Page head -->
    <div class="ag-pagehead">
        <div>
            <h1>Hola, <?= htmlspecialchars(strtok($full_name, ' ')) ?> 👋</h1>
            <p>Tu resumen de <?= htmlspecialchars(date('d \d\e M \d\e Y', strtotime($date_filter))) ?>.</p>
        </div>
        <div class="ag-head-actions">
            <?php if ($isManualPunch): ?>
                <span class="ag-chip"><i class="fas fa-fingerprint" style="color:var(--ag-brand)"></i> Marcación por ponche</span>
            <?php else: ?>
                <span class="ag-chip"><i class="fas fa-shield-halved" style="color:var(--ag-green)"></i> Conectado a Vicidial</span>
            <?php endif; ?>
            <form method="get" style="margin:0;">
                <input type="date" name="dates" id="dates" value="<?= htmlspecialchars($date_filter) ?>" class="ag-input" style="padding:9px 12px;">
            </form>
        </div>
    </div>

    <!-- KPIs -->
    <div class="ag-grid ag-kpis">
        <?php if ($isManualPunch): ?>
        <div class="ag-card ag-kpi">
            <div class="top"><div class="ico" style="background:var(--ag-brand-tint);color:var(--ag-brand)"><i class="fas fa-clock"></i></div></div>
            <div class="val"><?= gmdate('H:i:s', max(0, (int) $poncheTodaySeconds)) ?></div>
            <div class="lbl">Horas de hoy (ponche)</div>
        </div>
        <div class="ag-card ag-kpi">
            <div class="top"><div class="ico" style="background:#EDEBFB;color:var(--ag-purple)"><i class="fas fa-business-time"></i></div></div>
            <div class="val"><?= $periodHours ? number_format($periodHours['total_seconds'] / 3600, 1) : '0.0' ?></div>
            <div class="lbl">Horas de la quincena</div>
        </div>
        <?php else: ?>
        <div class="ag-card ag-kpi">
            <div class="top"><div class="ico" style="background:var(--ag-brand-tint);color:var(--ag-brand)"><i class="fas fa-briefcase"></i></div></div>
            <div class="val"><?= gmdate('H:i:s', max(0, (int) $vicidialPaidSeconds)) ?></div>
            <div class="lbl">Horas productivas hoy</div>
        </div>
        <div class="ag-card ag-kpi">
            <div class="top"><div class="ico" style="background:#EDEBFB;color:var(--ag-purple)"><i class="fas fa-bolt"></i></div></div>
            <div class="val"><?= $vicidialProductivity ?>%</div>
            <div class="lbl">Productividad</div>
        </div>
        <?php endif; ?>
        <div class="ag-card ag-kpi">
            <div class="top"><div class="ico" style="background:#E4F6F6;color:#0FA8A7"><i class="fas fa-star"></i></div></div>
            <div class="val"><?= number_format((float) $qualityMetrics['avg_percentage'], 1) ?>%</div>
            <div class="lbl">Calidad promedio</div>
        </div>
        <div class="ag-card ag-kpi">
            <div class="top"><div class="ico" style="background:#E8F1FE;color:var(--ag-blue)"><i class="fas fa-headphones"></i></div></div>
            <div class="val"><?= (int) $qualityMetrics['audited_calls'] ?></div>
            <div class="lbl">Llamadas auditadas</div>
        </div>
    </div>

    <!-- Horas de la quincena (TODOS los agentes) — acumulado alineado con la nómina -->
    <?php if ($periodHours && $currentPeriod): ?>
    <div class="ag-card ag-sec ag-mt">
        <div class="ag-sec-head">
            <div>
                <div class="ttl"><i class="fas fa-business-time"></i> Horas de la quincena</div>
                <div class="sub"><?= htmlspecialchars($currentPeriod['name']) ?> · <?= htmlspecialchars(date('d/m', strtotime($currentPeriod['start_date']))) ?>–<?= htmlspecialchars(date('d/m', strtotime($currentPeriod['end_date']))) ?> · iguales a tu nómina</div>
            </div>
            <a href="agents/mis_horas.php" class="ag-chip" style="text-decoration:none;">Ver detalle <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="ag-grid ag-kpis" style="gap:12px;">
            <div class="ag-statcard">
                <div class="sc-top"><div class="sc-ico" style="background:var(--ag-brand);"><i class="fas fa-clock"></i></div><div class="sc-lbl">Horas totales</div></div>
                <div class="sc-val"><?= number_format($periodHours['total_seconds'] / 3600, 2) ?></div>
                <div class="sc-sub"><?= agFmtHoursHM((int) $periodHours['total_seconds']) ?></div>
            </div>
            <div class="ag-statcard">
                <div class="sc-top"><div class="sc-ico" style="background:var(--ag-blue);"><i class="fas fa-briefcase"></i></div><div class="sc-lbl">Regulares</div></div>
                <div class="sc-val"><?= number_format($periodHours['regular_seconds'] / 3600, 2) ?></div>
                <div class="sc-sub"><?= agFmtHoursHM((int) $periodHours['regular_seconds']) ?></div>
            </div>
            <div class="ag-statcard">
                <div class="sc-top"><div class="sc-ico" style="background:var(--ag-amber);"><i class="fas fa-bolt"></i></div><div class="sc-lbl">Extra</div></div>
                <div class="sc-val"><?= number_format($periodHours['overtime_seconds'] / 3600, 2) ?></div>
                <div class="sc-sub"><?= agFmtHoursHM((int) $periodHours['overtime_seconds']) ?></div>
            </div>
            <div class="ag-statcard">
                <div class="sc-top"><div class="sc-ico" style="background:var(--ag-purple);"><i class="fas fa-calendar-check"></i></div><div class="sc-lbl">Días trabajados</div></div>
                <div class="sc-val"><?= (int) $periodHours['days_worked'] ?></div>
                <div class="sc-sub">fuente: <?= ['vicidial' => 'Vicidial', 'mixta' => 'Vicidial + ponche', 'manual' => 'ponche'][$periodHours['source_used']] ?? 'ponche' ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isManualPunch): ?>
    <!-- Row: Jornada de hoy (Vicidial) + Distribución del día -->
    <div class="ag-grid ag-mt" style="grid-template-columns:1.15fr 1fr;">
        <div class="ag-card ag-sec">
            <div class="ag-sec-head">
                <div>
                    <div class="ttl"><i class="fas fa-headset"></i> Jornada de hoy</div>
                    <div class="sub">Actividad en tiempo real desde Vicidial</div>
                </div>
                <span class="ag-chip"><i class="fas fa-tower-broadcast" style="color:var(--ag-teal)"></i> <?= $vicidialToday && $vicidialToday['user_group'] ? htmlspecialchars($vicidialToday['user_group']) : 'Vicidial' ?></span>
            </div>
            <!-- Estado EN VIVO del agente (se puebla vía agent_live_status.php cada ~25s; oculto si no hay sesión de Vicidial) -->
            <div class="ag-live" id="agLive" style="display:none;">
                <span class="ag-live-dot" id="agLiveDot"></span>
                <div class="ag-live-txt">
                    <span class="ag-live-label" id="agLiveLabel">—</span>
                    <span class="ag-live-meta" id="agLiveMeta"></span>
                </div>
                <span class="ag-live-time" id="agLiveTime"></span>
            </div>
            <?php if ($vicidialHasData): ?>
                <div class="ag-sched" style="grid-template-columns:repeat(3,1fr);">
                    <div class="b"><div class="k"><i class="fas fa-right-to-bracket"></i> Primer login</div><div class="v"><?= $vicidialToday['first_login'] ? htmlspecialchars(date('g:i A', strtotime($vicidialToday['first_login']))) : '—' ?></div></div>
                    <div class="b"><div class="k"><i class="fas fa-wave-square"></i> Última actividad</div><div class="v"><?= $vicidialToday['last_activity'] ? htmlspecialchars(date('g:i A', strtotime($vicidialToday['last_activity']))) : '—' ?></div></div>
                    <div class="b"><div class="k"><i class="fas fa-clock"></i> Total logueado</div><div class="v"><?= gmdate('G:i', max(0,(int)$vicidialToday['total_logged_seconds'])) ?> h</div></div>
                    <div class="b"><div class="k"><i class="fas fa-phone-volume"></i> Llamadas</div><div class="v"><?= (int) $vicidialToday['calls'] ?></div></div>
                    <div class="b"><div class="k"><i class="fas fa-headphones-simple"></i> En llamada</div><div class="v"><?= gmdate('G:i', max(0,(int)$vicidialToday['talk_seconds'])) ?> h</div></div>
                    <div class="b"><div class="k"><i class="fas fa-bolt"></i> Productivo</div><div class="v"><?= gmdate('G:i', max(0,(int)$vicidialToday['nonpause_seconds'])) ?> h</div></div>
                </div>
            <?php else: ?>
                <div class="ag-empty-state" style="min-height:120px;">
                    <i class="fas fa-satellite-dish"></i>
                    <p>Sin datos de Vicidial para esta fecha. Se sincronizan automáticamente cada noche.</p>
                </div>
            <?php endif; ?>
            <div class="ag-seg" style="margin-top:14px;">
                <div>
                    <div class="nm"><i class="fas fa-calendar-day" style="color:var(--ag-muted);margin-right:6px;"></i> Horario asignado · <?= htmlspecialchars($scheduleName) ?></div>
                    <div class="mt">Entrada <?= htmlspecialchars($scheduleEntry ?? '—') ?> · Salida <?= htmlspecialchars($scheduleExit ?? '—') ?></div>
                </div>
                <span class="hh"><?= htmlspecialchars($scheduleHours) ?> h</span>
            </div>
        </div>

        <div class="ag-card ag-sec">
            <div class="ag-sec-head">
                <div class="ttl"><i class="fas fa-chart-pie"></i> Distribución del día</div>
                <span class="ag-chip"><?= htmlspecialchars(date('d/m', strtotime($date_filter))) ?></span>
            </div>
            <?php if ($chartTotal > 0): ?>
            <div class="ag-donut-wrap">
                <div class="ag-donut-box">
                    <canvas id="timeBreakdownChart" aria-label="Distribución de tiempo" role="img"></canvas>
                    <div class="ag-donut-center">
                        <span class="dc-v"><?= gmdate('G:i', (int) $chartTotal) ?></span>
                        <span class="dc-l">horas totales</span>
                    </div>
                </div>
                <div class="ag-donut-legend">
                    <?php for ($di = 0, $dn = count($chartLabels); $di < $dn; $di++): ?>
                        <?php $lsec = (int) $chartData[$di]; $lpct = $chartTotal > 0 ? round($lsec / $chartTotal * 100) : 0; ?>
                        <div class="lg">
                            <span class="l"><span class="dt" style="background:<?= htmlspecialchars($chartColors[$di]) ?>"></span><?= htmlspecialchars($chartLabels[$di]) ?></span>
                            <span class="vv"><?= gmdate('G:i', $lsec) ?><span class="pc"><?= $lpct ?>%</span></span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="ag-empty-state" data-chart-empty>
                <i class="fas fa-chart-pie"></i>
                <p>Sin datos de distribución para esta fecha. Se sincronizan desde Vicidial cada noche.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <!-- Row (MANUAL): Marcar asistencia + Marcaciones de hoy -->
    <div class="ag-grid ag-mt" style="grid-template-columns:1.15fr 1fr;">
        <div class="ag-card ag-sec">
            <div class="ag-sec-head">
                <div>
                    <div class="ttl"><i class="fas fa-fingerprint"></i> Marcar asistencia</div>
                    <div class="sub">Registra tu entrada, pausas y salida</div>
                </div>
                <span class="ag-chip"><i class="fas fa-clock" style="color:var(--ag-brand)"></i> Hoy <?= gmdate('H:i:s', max(0, (int) $poncheTodaySeconds)) ?></span>
            </div>
            <div id="agentPunchStatus" class="ag-punch-status" style="display:none;"></div>
            <form id="agentPunchForm" method="POST" class="ag-punch-form">
                <div class="ag-punch-grid">
                    <?php foreach ($activeAttendanceTypes as $t): ?>
                        <?php
                            $pslug = sanitizeAttendanceTypeSlug($t['slug'] ?? '');
                            if ($pslug === '') { continue; }
                            $plabel = $t['label'] ?? $pslug;
                            $pico = $t['icon_class'] ?? 'fas fa-circle';
                            $pcolor = sanitizeHexColorValue($t['color_start'] ?? '#244886', '#244886');
                        ?>
                        <button type="submit" name="punch_type" value="<?= htmlspecialchars($pslug) ?>" class="ag-punch punch-btn">
                            <span class="pico" style="background:<?= htmlspecialchars($pcolor) ?>;"><i class="<?= htmlspecialchars($pico) ?>"></i></span>
                            <span class="pl"><?= htmlspecialchars($plabel) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <label class="ag-punch-auth" for="authorization_code">
                    <i class="fas fa-key"></i>
                    <input type="text" id="authorization_code" name="authorization_code" placeholder="Código de autorización (solo si se te solicita)" autocomplete="off" inputmode="numeric">
                </label>
            </form>
        </div>

        <div class="ag-card ag-sec">
            <div class="ag-sec-head">
                <div class="ttl"><i class="fas fa-list-check"></i> Marcaciones de hoy</div>
                <span class="ag-chip"><?= htmlspecialchars(date('d/m', strtotime($date_filter))) ?></span>
            </div>
            <?php if (!empty($records)): ?>
                <div class="ag-punch-timeline">
                    <?php foreach ($records as $rec): ?>
                        <div class="ag-pt-item">
                            <span class="ag-pt-ico" style="background:<?= htmlspecialchars($rec['color']) ?>1a;color:<?= htmlspecialchars($rec['color']) ?>;"><i class="<?= htmlspecialchars($rec['icon']) ?>"></i></span>
                            <div class="ag-pt-body">
                                <span class="ag-pt-label"><?= htmlspecialchars($rec['label']) ?></span>
                                <span class="ag-pt-time"><?= htmlspecialchars(date('g:i A', strtotime($rec['time']))) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="ag-empty-state" style="min-height:120px;">
                    <i class="fas fa-clock"></i>
                    <p>Aún no tienes marcaciones hoy. Usa los botones para registrar tu jornada.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Calidad del agente -->
    <div class="ag-card ag-sec ag-mt">
        <div class="ag-sec-head">
            <div>
                <div class="ttl"><i class="fas fa-award"></i> Calidad del agente</div>
                <div class="sub">Métricas y auditorías del sistema de calidad</div>
            </div>
            <a href="agent_quality.php" class="ag-chip">Ver todo <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if ($qualityError): ?>
            <div class="ag-alert warn"><i class="fas fa-exclamation-triangle"></i><span><?= htmlspecialchars($qualityError) ?></span></div>
        <?php else: ?>
            <div class="ag-qgrid">
                <div class="ag-qc"><div class="qh"><i style="background:var(--ag-blue)"><i class="fas fa-clipboard-check"></i></i> Evaluaciones</div><div class="qv"><?= (int) $qualityMetrics['total_evaluations'] ?></div></div>
                <div class="ag-qc"><div class="qh"><i style="background:var(--ag-green)"><i class="fas fa-chart-line"></i></i> Promedio</div><div class="qv"><?= number_format((float) $qualityMetrics['avg_percentage'], 1) ?>%</div></div>
                <div class="ag-qc"><div class="qh"><i style="background:var(--ag-amber)"><i class="fas fa-headphones"></i></i> Auditadas</div><div class="qv"><?= (int) $qualityMetrics['audited_calls'] ?></div></div>
                <div class="ag-qc"><div class="qh"><i style="background:var(--ag-purple)"><i class="fas fa-star"></i></i> Mejor / Peor</div><div class="qv"><?= number_format((float) $qualityMetrics['max_percentage'], 0) ?> / <?= number_format((float) $qualityMetrics['min_percentage'], 0) ?></div></div>
                <div class="ag-qc"><div class="qh"><i style="background:var(--ag-teal)"><i class="fas fa-robot"></i></i> Score IA</div><div class="qv"><?= number_format((float) $qualityMetrics['avg_ai_score'], 1) ?></div></div>
                <div class="ag-qc"><div class="qh"><i style="background:#64748B"><i class="fas fa-calendar"></i></i> Última</div><div class="qv" style="font-size:15px;padding-top:5px;"><?= $qualityMetrics['last_eval_date'] ? htmlspecialchars(date('d/m/Y', strtotime($qualityMetrics['last_eval_date']))) : 'N/A' ?></div></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Row: Llamadas auditadas (+ Desglose Vicidial solo para agentes de Vicidial) -->
    <div class="ag-grid ag-mt" style="grid-template-columns:<?= $isManualPunch ? '1fr' : '1.55fr .9fr' ?>;">
        <div class="ag-card ag-sec">
            <div class="ag-sec-head">
                <div class="ttl"><i class="fas fa-phone-volume"></i> Llamadas auditadas</div>
                <a href="agent_quality.php" class="ag-chip" style="text-decoration:none;">Ver todo en Calidad <i class="fas fa-arrow-right"></i></a>
            </div>
            <div style="overflow-x:auto;">
                <table class="ag-table" id="auditTable">
                    <thead><tr><th>Fecha</th><th>Campaña</th><th>Score</th><th>QA IA</th><th>Resumen</th><th>Audio</th></tr></thead>
                    <tbody>
                        <?php if (!empty($qualityAudits)): ?>
                            <?php foreach ($qualityAudits as $audit): ?>
                                <?php
                                    $audioUrl = resolveQualityRecordingUrl($audit['recording_path'] ?? null);
                                    $pct = $audit['percentage'] !== null ? (float) $audit['percentage'] : null;
                                    $scoreValue = $pct !== null ? number_format($pct, 1) . '%' : 'N/A';
                                    $scoreClass = $pct === null ? '' : ($pct >= 85 ? 'good' : ($pct >= 70 ? 'mid' : 'bad'));
                                    $aiScoreValue = $audit['ai_score'] !== null ? number_format((float) $audit['ai_score'], 1) : 'N/A';
                                    $summaryText = $audit['ai_summary'] ?: ($audit['general_comments'] ?? '');
                                    $campName = $audit['campaign_name'] ?? 'Sin campaña';
                                    $campInitials = strtoupper(mb_substr($campName, 0, 2));
                                    $campColors = ['#244886','#16C8C7','#5347CE','#4896FE','#F79009'];
                                    $campColor = $campColors[abs(crc32($campName)) % count($campColors)];
                                ?>
                                <tr data-audit-row>
                                    <td><b><?= htmlspecialchars(date('d/m', strtotime($audit['call_date'] ?: $audit['created_at']))) ?></b><div class="ag-tsub"><?= htmlspecialchars(date('H:i', strtotime($audit['call_datetime'] ?: $audit['created_at']))) ?></div></td>
                                    <td>
                                        <div class="ag-tg">
                                            <span class="ci" style="background:<?= $campColor ?>"><?= htmlspecialchars($campInitials) ?></span>
                                            <div><b><?= htmlspecialchars($campName) ?></b><?php if (!empty($audit['call_type'])): ?><div class="ag-tsub"><?= htmlspecialchars($audit['call_type']) ?></div><?php endif; ?></div>
                                        </div>
                                    </td>
                                    <td><span class="ag-score <?= $scoreClass ?>"><?= $scoreValue ?></span></td>
                                    <td><span class="ag-score"><?= htmlspecialchars($aiScoreValue) ?></span></td>
                                    <td style="color:var(--ag-muted);max-width:230px;"><?= htmlspecialchars(mb_strimwidth((string) $summaryText, 0, 120, '…')) ?></td>
                                    <td>
                                        <?php if ($audioUrl): ?>
                                            <audio controls preload="none" style="height:34px;max-width:200px;"><source src="<?= htmlspecialchars($audioUrl) ?>" type="audio/mpeg"></audio>
                                        <?php else: ?>
                                            <span class="ag-tsub">Sin audio</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="ag-empty">No hay llamadas auditadas disponibles.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="ag-pager" id="auditPager" style="display:none;">
                <button type="button" class="ag-pager-btn" data-page="prev" aria-label="Página anterior"><i class="fas fa-chevron-left"></i></button>
                <span class="ag-pager-info">Página <b id="auditPageNow">1</b> de <span id="auditPageTot">1</span></span>
                <button type="button" class="ag-pager-btn" data-page="next" aria-label="Página siguiente"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>

        <?php if (!$isManualPunch): ?>
        <div class="ag-card ag-sec">
            <div class="ag-sec-head">
                <div class="ttl"><i class="fas fa-list-check"></i> Desglose de hoy</div>
                <span class="ag-chip">Vicidial</span>
            </div>
            <?php if (!empty($vicidialDist) && $chartTotal > 0): ?>
                <?php foreach ($vicidialDist as $label => $sec): ?>
                    <?php if ($sec <= 0) continue; $pctBar = $chartTotal > 0 ? round($sec / $chartTotal * 100) : 0; $barColor = $distColors[$label] ?? '#4896FE'; ?>
                    <div style="margin-bottom:14px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                            <span style="display:flex;align-items:center;gap:8px;font-weight:600;color:var(--ag-text);">
                                <span style="width:9px;height:9px;border-radius:3px;background:<?= $barColor ?>;"></span><?= htmlspecialchars($label) ?>
                            </span>
                            <span style="font-weight:700;color:var(--ag-text);"><?= gmdate('G:i', (int) $sec) ?> h</span>
                        </div>
                        <div style="height:7px;border-radius:6px;background:#EDF1F7;overflow:hidden;">
                            <span style="display:block;height:100%;width:<?= $pctBar ?>%;background:<?= $barColor ?>;border-radius:6px;"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="ag-empty-state">
                    <i class="fas fa-chart-simple"></i>
                    <p>Aún no hay actividad de Vicidial para esta fecha.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

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
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#16213E',
                        padding: 10,
                        cornerRadius: 8,
                        titleFont: { size: 12, weight: '700' },
                        bodyFont: { size: 12 },
                        callbacks: {
                            label: function (c) {
                                var s = c.parsed || 0;
                                var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
                                var hhmm = (h > 0 ? h + 'h ' : '') + m + 'm';
                                var pct = total > 0 ? Math.round(s / total * 100) : 0;
                                return ' ' + hhmm + ' · ' + pct + '%';
                            }
                        }
                    }
                }
            }
        });
    }
})();

// Paginación del historial de llamadas (client-side; el detalle completo vive en Calidad)
(function () {
    var table = document.getElementById('auditTable');
    var pager = document.getElementById('auditPager');
    if (!table || !pager) { return; }
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-audit-row]'));
    var per = 6, page = 1, pages = Math.ceil(rows.length / per);
    if (pages <= 1) { return; } // no hace falta paginar
    var nowEl = document.getElementById('auditPageNow'),
        totEl = document.getElementById('auditPageTot'),
        prev = pager.querySelector('[data-page="prev"]'),
        next = pager.querySelector('[data-page="next"]');
    totEl.textContent = pages;
    pager.style.display = 'flex';
    function render() {
        rows.forEach(function (r, i) {
            var vis = (i >= (page - 1) * per && i < page * per);
            r.style.display = vis ? '' : 'none';
            if (!vis) { var a = r.querySelector('audio'); if (a && !a.paused) { a.pause(); } }
        });
        nowEl.textContent = page;
        prev.disabled = (page === 1);
        next.disabled = (page === pages);
    }
    prev.addEventListener('click', function () { if (page > 1) { page--; render(); } });
    next.addEventListener('click', function () { if (page < pages) { page++; render(); } });
    render();
})();

// Widget "En vivo": estado actual del agente en Vicidial (polling ~25s + contador local)
(function () {
    var box = document.getElementById('agLive');
    if (!box) { return; }
    var dot = document.getElementById('agLiveDot'),
        label = document.getElementById('agLiveLabel'),
        meta = document.getElementById('agLiveMeta'),
        timeEl = document.getElementById('agLiveTime');
    var P = window.PonchePolling || {};
    var interval = Math.max(15000, P.modal || 25000);
    var pauseHidden = P.pauseWhenHidden !== false;
    var secs = 0, tick = null;

    function fmt(s) { s = Math.max(0, s | 0); var m = Math.floor(s / 60), r = s % 60; return m + ':' + (r < 10 ? '0' : '') + r; }
    function stopTick() { if (tick) { clearInterval(tick); tick = null; } }
    function hide() { box.style.display = 'none'; box.classList.remove('on'); stopTick(); }

    function render(live) {
        if (!live) { hide(); return; } // sin sesión de Vicidial ahora -> ocultar
        box.style.display = 'flex';
        box.classList.add('on');
        dot.style.background = live.color || '#38bdf8';
        label.innerHTML = escapeHtml(live.label || live.status || '—') + '<span class="ag-live-live">en vivo</span>';
        var m = [];
        if (live.campaign) { m.push(live.campaign); }
        m.push((live.calls || 0) + ' llamadas hoy');
        meta.textContent = m.join(' · ');
        secs = live.seconds_in_status || 0;
        timeEl.textContent = fmt(secs);
        stopTick();
        tick = setInterval(function () { secs++; timeEl.textContent = fmt(secs); }, 1000);
    }
    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function poll() {
        if (pauseHidden && document.hidden) { return; }
        fetch('agent_live_status.php', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok || !d.enabled) { hide(); return; }
                render(d.live);
            })
            .catch(function () { /* silencio: nunca romper el dashboard */ });
    }

    poll();
    setInterval(poll, interval);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
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
            statusDiv.className = 'ag-punch-status ' + (ok ? 'ok' : 'err');
            statusDiv.innerHTML = (ok ? '<i class="fas fa-circle-check"></i> ' : '<i class="fas fa-triangle-exclamation"></i> ') + message;
            statusDiv.style.display = 'flex';
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

<?php include 'footer.php'; ?>
