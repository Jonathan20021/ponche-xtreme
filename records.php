<?php
session_start();
include 'db.php';
date_default_timezone_set('America/Santo_Domingo');

ensurePermission('records');

if (!function_exists('sanitizeHexColorValue')) {
    function sanitizeHexColorValue(?string $color, string $fallback = '#6366F1'): string
    {
        $value = strtoupper(trim((string) $color));
        return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : strtoupper($fallback);
    }
}

$scheduleConfig = getScheduleConfig($pdo);
$hourly_rates = getUserHourlyRates($pdo);
$userExitTimes = function_exists('getUserExitTimes') ? getUserExitTimes($pdo) : [];
$userOvertimeMultipliers = function_exists('getUserOvertimeMultipliers') ? getUserOvertimeMultipliers($pdo) : [];
$defaultExitTime = trim((string) ($scheduleConfig['exit_time'] ?? ''));
if ($defaultExitTime !== '' && strlen($defaultExitTime) === 5) {
    $defaultExitTime .= ':00';
}
$overtimeEnabled = (int) ($scheduleConfig['overtime_enabled'] ?? 1) === 1;
$defaultOvertimeMultiplier = (float) ($scheduleConfig['overtime_multiplier'] ?? 1.50);
$overtimeStartMinutes = (int) ($scheduleConfig['overtime_start_minutes'] ?? 0);
$exitSlug = sanitizeAttendanceTypeSlug('EXIT');
$entryThreshold = date('H:i:s', strtotime($scheduleConfig['entry_time'] . ' +5 minutes'));
$lunchThreshold = $scheduleConfig['lunch_time'];
$breakThreshold = $scheduleConfig['break_time'];

// Handle punch submission
$punch_error = null;
$punch_success = null;

// Check for flash messages from session
if (isset($_SESSION['punch_success'])) {
    $punch_success = $_SESSION['punch_success'];
    unset($_SESSION['punch_success']);
}
if (isset($_SESSION['punch_error'])) {
    $punch_error = $_SESSION['punch_error'];
    unset($_SESSION['punch_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['punch_type'])) {
    $typeSlug = sanitizeAttendanceTypeSlug($_POST['punch_type'] ?? '');
    $user_id = (int) $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? '';
    $full_name = $_SESSION['full_name'] ?? '';
    
    if ($typeSlug === '') {
        $_SESSION['punch_error'] = "Tipo de asistencia no válido.";
        header('Location: records.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
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
        $_SESSION['punch_error'] = "Tipo de asistencia no válido.";
        header('Location: records.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
    
    $selectedTypeMeta = $attendanceTypeMapForPunch[$typeSlug];
    $typeLabel = $selectedTypeMeta['label'] ?? $selectedTypeMeta['slug'];
    
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
            $_SESSION['punch_error'] = "Solo puedes registrar '{$typeLabel}' una vez por día.";
            header('Location: records.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
            exit;
        }
    }
    
    // Validar secuencia ENTRY/EXIT
    require_once 'lib/authorization_functions.php';
    $sequenceValidation = validateEntryExitSequence($pdo, $user_id, $typeSlug);
    if (!$sequenceValidation['valid']) {
        $_SESSION['punch_error'] = $sequenceValidation['message'];
        header('Location: records.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
    
    // Check authorization requirements
    $authSystemEnabled = isAuthorizationSystemEnabled($pdo);
    $authRequiredForOvertime = isAuthorizationRequiredForContext($pdo, 'overtime');
    $authRequiredForEarlyPunch = isAuthorizationRequiredForContext($pdo, 'early_punch');
    $authorizationCodeId = null;
    
    // Check overtime authorization
    if ($authSystemEnabled && $authRequiredForOvertime) {
        $isOvertime = isOvertimeAttempt($pdo, $user_id, $typeSlug);
        
        if ($isOvertime) {
            $_SESSION['punch_error'] = "Se requiere código de autorización para registrar hora extra. Use el formulario de punch principal.";
            header('Location: records.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
            exit;
        }
    }
    
    // Check early punch authorization
    if ($authSystemEnabled && $authRequiredForEarlyPunch) {
        $isEarly = isEarlyPunchAttempt($pdo, $user_id);
        
        if ($isEarly) {
            $_SESSION['punch_error'] = "Se requiere código de autorización para marcar entrada antes de su horario. Use el formulario de punch principal.";
            header('Location: records.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
            exit;
        }
    }
    
    // Register the punch
    $ip_address = $_SERVER['REMOTE_ADDR'] === '::1' ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];
    $insert_stmt = $pdo->prepare("
        INSERT INTO attendance (user_id, type, ip_address, timestamp) 
        VALUES (?, ?, ?, NOW())
    ");
    $insert_stmt->execute([$user_id, $typeSlug, $ip_address]);
    
    // Log attendance registration
    require_once 'lib/logging_functions.php';
    $recordId = $pdo->lastInsertId();
    log_custom_action(
        $pdo,
        $user_id,
        $full_name,
        $_SESSION['role'],
        'attendance',
        'create',
        "Registro de asistencia desde records: {$typeSlug}",
        'attendance_record',
        $recordId,
        ['type' => $typeSlug, 'ip_address' => $ip_address]
    );
    
    // Send Slack notification
    $slack_webhook_url = 'https://hooks.slack.com/services/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';
    $current_timestamp = date('Y-m-d H:i:s');
    $color = sanitizeHexColorValue($selectedTypeMeta['color_start'] ?? null, '#6366F1');
    
    $message = [
        "text" => "New Punch Recorded",
        "attachments" => [
            [
                "color" => $color,
                "fields" => [
                    ["title" => "Full Name", "value" => $full_name, "short" => true],
                    ["title" => "Username", "value" => $username, "short" => true],
                    ["title" => "Type", "value" => "{$typeLabel} ({$typeSlug})", "short" => true],
                    ["title" => "IP Address", "value" => $ip_address, "short" => true],
                    ["title" => "Timestamp", "value" => $current_timestamp, "short" => true],
                ]
            ]
        ]
    ];
    
    $ch = curl_init($slack_webhook_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
    
    // Set success message and redirect
    $_SESSION['punch_success'] = "¡Asistencia registrada exitosamente como {$typeLabel}!";
    header('Location: records.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

$attendanceTypes = getAttendanceTypes($pdo, false);
$attendanceTypeMap = [];
$activeAttendanceTypes = [];
foreach ($attendanceTypes as $typeRow) {
    $slug = sanitizeAttendanceTypeSlug($typeRow['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $typeRow['slug'] = $slug;
    $attendanceTypeMap[$slug] = $typeRow;
    // Build active attendance types for punch buttons
    if ((int) ($typeRow['is_active'] ?? 0) === 1) {
        $activeAttendanceTypes[] = $typeRow;
    }
}

$durationTypes = array_values(array_filter($attendanceTypes, function (array $typeRow): bool {
    return ((int) ($typeRow['is_active'] ?? 0) === 1) && ((int) ($typeRow['is_unique_daily'] ?? 0) === 0);
}));

// Get paid attendance type slugs for payment calculations
$paidTypeSlugs = getPaidAttendanceTypeSlugs($pdo);

$summaryColumns = array_map(function (array $typeRow): array {
    return [
        'slug' => $typeRow['slug'],
        'label' => $typeRow['label'] ?? $typeRow['slug'],
        'icon_class' => $typeRow['icon_class'] ?? 'fas fa-circle',
    ];
}, $durationTypes);

$nonWorkSlugs = array_values(array_filter(array_unique([
    sanitizeAttendanceTypeSlug('BREAK'),
    sanitizeAttendanceTypeSlug('LUNCH'),
])));

$search = trim($_GET['search'] ?? '');
$user_filter = trim($_GET['user'] ?? '');
$date_filter = $_GET['dates'] ?? '';
$type_filter_input = $_GET['type'] ?? '';
$type_filter = $type_filter_input !== '' ? sanitizeAttendanceTypeSlug($type_filter_input) : '';
$dateValues = [];
$datePlaceholders = '';

if ($type_filter !== '' && !isset($attendanceTypeMap[$type_filter])) {
    $type_filter = '';
}

if ($date_filter) {
    $dateValues = array_values(array_filter(array_map('trim', explode(',', $date_filter))));
    if (!empty($dateValues)) {
        $datePlaceholders = implode(',', array_fill(0, count($dateValues), '?'));
    }
}

// Consulta para registros

$query = "
    SELECT 
        attendance.id,
        users.full_name,
        users.username,
        attendance.type AS type_slug,
        COALESCE(at.label, attendance.type) AS type_label,
        COALESCE(at.icon_class, 'fas fa-circle') AS type_icon_class,
        at.color_start AS type_color_start,
        at.color_end AS type_color_end,
        DATE(attendance.timestamp) AS record_date,
        TIME(attendance.timestamp) AS record_time,
        attendance.ip_address
    FROM attendance
    JOIN users ON attendance.user_id = users.id
    LEFT JOIN attendance_types at 
        ON at.slug COLLATE utf8mb4_unicode_ci = UPPER(attendance.type COLLATE utf8mb4_unicode_ci)
    WHERE 1=1
";

$params = [];
$collation = 'utf8mb4_unicode_ci';

if ($search !== '') {
    $like = "%$search%";
    $query .= " AND (
        users.full_name COLLATE {$collation} LIKE ?
        OR users.username COLLATE {$collation} LIKE ?
        OR attendance.type COLLATE {$collation} LIKE ?
        OR COALESCE(at.label, attendance.type) COLLATE {$collation} LIKE ?
        OR attendance.ip_address LIKE ?
    )";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($user_filter !== '') {
    $query .= " AND users.username = ?";
    $params[] = $user_filter;
}
if (!empty($dateValues)) {
    $query .= " AND DATE(attendance.timestamp) IN ($datePlaceholders)";
    $params = array_merge($params, $dateValues);
}
if ($type_filter !== '') {
    $query .= " AND UPPER(attendance.type COLLATE {$collation}) = ?";
    $params[] = $type_filter;
}

$query .= " ORDER BY attendance.timestamp DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($records as &$record) {
    $record['type_slug'] = sanitizeAttendanceTypeSlug($record['type_slug'] ?? $record['type_label'] ?? '');
    $slug = $record['type_slug'];
    $meta = ($slug !== '' && isset($attendanceTypeMap[$slug])) ? $attendanceTypeMap[$slug] : null;

    $record['type_label'] = $meta['label'] ?? ($record['type_label'] ?? $slug);
    $record['type_icon_class'] = $meta['icon_class'] ?? ($record['type_icon_class'] ?? 'fas fa-circle');

    $colorStartCandidate = $meta['color_start'] ?? ($record['type_color_start'] ?? null);
    $record['type_color_start'] = sanitizeHexColorValue($colorStartCandidate, '#6366F1');

    $colorEndCandidate = $meta['color_end'] ?? ($record['type_color_end'] ?? $record['type_color_start']);
    $record['type_color_end'] = sanitizeHexColorValue($colorEndCandidate, $record['type_color_start']);
}
unset($record);

// Usuarios unicos para filtro
$users = $pdo->query("SELECT DISTINCT username FROM users ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);

$summary_query = "
    SELECT 
        attendance.user_id,
        users.full_name,
        users.username,
        users.preferred_currency,
        DATE(attendance.timestamp) AS record_date,
        attendance.type AS type_slug,
        attendance.timestamp,
        attendance.ip_address
    FROM attendance 
    JOIN users ON attendance.user_id = users.id 
    WHERE 1=1
";

$summary_params = [];
if ($search !== '') {
    $like = "%$search%";
    $summary_query .= " AND (
        users.full_name COLLATE {$collation} LIKE ?
        OR users.username COLLATE {$collation} LIKE ?
        OR attendance.type COLLATE {$collation} LIKE ?
        OR attendance.ip_address LIKE ?
    )";
    array_push($summary_params, $like, $like, $like, $like);
}
if ($user_filter !== '') {
    $summary_query .= " AND users.username = ?";
    $summary_params[] = $user_filter;
}
if (!empty($dateValues)) {
    $summary_query .= " AND DATE(attendance.timestamp) IN ($datePlaceholders)";
    $summary_params = array_merge($summary_params, $dateValues);
}
if ($type_filter !== '') {
    $summary_query .= " AND UPPER(attendance.type COLLATE {$collation}) = ?";
    $summary_params[] = $type_filter;
}

$summary_query .= " ORDER BY users.username COLLATE {$collation}, record_date, attendance.timestamp";
$summary_stmt = $pdo->prepare($summary_query);
$summary_stmt->execute($summary_params);
$raw_summary_rows = $summary_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$work_summary = [];
$currentGroup = null;
$currentKey = null;

$finalizeSummaryGroup = function (?array &$group) use (&$work_summary, $summaryColumns, $nonWorkSlugs, $paidTypeSlugs, $hourly_rates, $userExitTimes, $defaultExitTime, $exitSlug, $overtimeEnabled, $defaultOvertimeMultiplier, $overtimeStartMinutes, $userOvertimeMultipliers, $pdo): void {
    if ($group === null) {
        return;
    }

    $events = $group['events'];
    $eventCount = count($events);
    $durationsAll = [];

    if ($eventCount >= 2) {
        for ($i = 0; $i < $eventCount - 1; $i++) {
            $start = $events[$i]['timestamp'];
            $end = $events[$i + 1]['timestamp'];
            $delta = max(0, $end - $start);

            if ($delta <= 0) {
                continue;
            }

            $slug = $events[$i]['slug'];
            $durationsAll[$slug] = ($durationsAll[$slug] ?? 0) + $delta;
        }
    }

    $durationMap = [];
    foreach ($summaryColumns as $column) {
        $columnSlug = $column['slug'];
        $durationMap[$columnSlug] = $durationsAll[$columnSlug] ?? 0;
    }

    // Calculate work seconds only from PAID punch types
    $workSeconds = 0;
    foreach ($paidTypeSlugs as $paidSlug) {
        if (isset($durationsAll[$paidSlug])) {
            $workSeconds += $durationsAll[$paidSlug];
        }
    }
    $workSeconds = max(0, $workSeconds);

    $recordDate = $group['record_date'] ?? null;
    $username = $group['username'] ?? null;
    $userId = $group['user_id'] ?? null;
    $preferredCurrency = $group['preferred_currency'] ?? 'USD';
    $overtimeSeconds = 0;
    $overtimePayment = 0.0;

    // Get hourly rate for the specific date (uses rate history)
    $hourlyRate = 0.0;
    if ($userId !== null && $recordDate !== null) {
        $hourlyRate = getUserHourlyRateForDate($pdo, $userId, $recordDate, $preferredCurrency);
    } else if (isset($hourly_rates[$username])) {
        $hourlyRate = (float) $hourly_rates[$username];
    }

    if ($recordDate !== null && $overtimeEnabled) {
        $configuredExit = $defaultExitTime;
        if ($username !== null && isset($userExitTimes[$username]) && $userExitTimes[$username] !== '') {
            $configuredExit = $userExitTimes[$username];
        }

        $configuredExit = trim((string) $configuredExit);
        if ($configuredExit !== '') {
            if (strlen($configuredExit) === 5) {
                $configuredExit .= ':00';
            }
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $configuredExit) !== 1) {
                $parsedExit = strtotime($configuredExit);
                if ($parsedExit !== false) {
                    $configuredExit = date('H:i:s', $parsedExit);
                } else {
                    $configuredExit = '';
                }
            }

            if ($configuredExit !== '') {
                $scheduledExitTs = strtotime($recordDate . ' ' . $configuredExit);
                if ($scheduledExitTs !== false && $eventCount > 0) {
                    // Apply overtime start offset
                    $scheduledExitTs += ($overtimeStartMinutes * 60);
                    
                    $actualExitTs = null;
                    for ($idx = $eventCount - 1; $idx >= 0; $idx--) {
                        if ($events[$idx]['slug'] === $exitSlug) {
                            $actualExitTs = $events[$idx]['timestamp'];
                            break;
                        }
                    }
                    if ($actualExitTs === null) {
                        $actualExitTs = $events[$eventCount - 1]['timestamp'];
                    }
                    if ($actualExitTs !== null && $actualExitTs > $scheduledExitTs) {
                        $overtimeSeconds = $actualExitTs - $scheduledExitTs;
                        
                        // Calculate overtime payment with multiplier using historical rate
                        $overtimeMultiplier = $defaultOvertimeMultiplier;
                        if ($username !== null && isset($userOvertimeMultipliers[$username]) && $userOvertimeMultipliers[$username] !== null) {
                            $overtimeMultiplier = $userOvertimeMultipliers[$username];
                        }
                        $overtimePayment = round(($overtimeSeconds / 3600) * $hourlyRate * $overtimeMultiplier, 2);
                    }
                }
            }
        }
    }

    $regularPayment = round(($workSeconds / 3600) * $hourlyRate, 2);

    $work_summary[] = [
        'full_name' => $group['full_name'],
        'username' => $group['username'],
        'record_date' => $group['record_date'],
        'preferred_currency' => $preferredCurrency,
        'durations' => $durationMap,
        'work_seconds' => $workSeconds,
        'overtime_seconds' => $overtimeSeconds,
        'overtime_payment' => $overtimePayment,
        'total_payment' => $regularPayment + $overtimePayment,
    ];

    $group = null;
};

foreach ($raw_summary_rows as $row) {
    $slug = sanitizeAttendanceTypeSlug($row['type_slug'] ?? '');
    if ($slug === '') {
        continue;
    }

    $timestamp = strtotime($row['timestamp']);
    if ($timestamp === false) {
        continue;
    }

    $recordDate = $row['record_date'] ?? date('Y-m-d', $timestamp);
    $key = $row['user_id'] . '|' . $recordDate;

    if ($currentKey !== $key) {
        $finalizeSummaryGroup($currentGroup);
        $currentKey = $key;
        $currentGroup = [
            'user_id' => $row['user_id'],
            'full_name' => $row['full_name'],
            'username' => $row['username'],
            'preferred_currency' => $row['preferred_currency'] ?? 'USD',
            'record_date' => $recordDate,
            'events' => [],
        ];
    }

    $currentGroup['events'][] = [
        'slug' => $slug,
        'timestamp' => $timestamp,
    ];
}

$finalizeSummaryGroup($currentGroup);

// Calculate agent payment summary (grouped by agent and date)
$agent_payments = [];
foreach ($work_summary as $summary) {
    $key = $summary['username'] . '|' . $summary['record_date'];
    if (!isset($agent_payments[$key])) {
        $agent_payments[$key] = [
            'user_id' => null,
            'full_name' => $summary['full_name'],
            'username' => $summary['username'],
            'record_date' => $summary['record_date'],
            'preferred_currency' => $summary['preferred_currency'] ?? 'USD',
            'work_seconds' => 0,
            'overtime_seconds' => 0,
            'overtime_payment' => 0.0,
            'total_payment' => 0.0,
        ];
    }
    $agent_payments[$key]['work_seconds'] += $summary['work_seconds'];
    $agent_payments[$key]['overtime_seconds'] += $summary['overtime_seconds'] ?? 0;
    $agent_payments[$key]['overtime_payment'] += $summary['overtime_payment'] ?? 0;
    $agent_payments[$key]['total_payment'] += $summary['total_payment'];
}
$agent_payments = array_values($agent_payments);
$agentPaymentsTotal = count($agent_payments);

// Calculate totals by currency
$paymentTotals = [
    'USD' => ['work_seconds' => 0, 'overtime_seconds' => 0, 'overtime_payment' => 0.0, 'total_payment' => 0.0, 'count' => 0],
    'DOP' => ['work_seconds' => 0, 'overtime_seconds' => 0, 'overtime_payment' => 0.0, 'total_payment' => 0.0, 'count' => 0],
];
foreach ($agent_payments as $payment) {
    $currency = $payment['preferred_currency'] ?? 'USD';
    if (isset($paymentTotals[$currency])) {
        $paymentTotals[$currency]['work_seconds'] += $payment['work_seconds'];
        $paymentTotals[$currency]['overtime_seconds'] += $payment['overtime_seconds'];
        $paymentTotals[$currency]['overtime_payment'] += $payment['overtime_payment'];
        $paymentTotals[$currency]['total_payment'] += $payment['total_payment'];
        $paymentTotals[$currency]['count']++;
    }
}

// Colculo de Porcentaje de Tardanza Diario
$tardiness_query = "
    SELECT 
        users.full_name,
        users.username, 
        DATE(attendance.timestamp) AS record_date,
        COUNT(CASE WHEN UPPER(attendance.type) = 'ENTRY' AND TIME(attendance.timestamp) > ? THEN 1 END) AS late_entries,
        COUNT(CASE WHEN UPPER(attendance.type) = 'LUNCH' AND TIME(attendance.timestamp) > ? THEN 1 END) AS late_lunches,
        COUNT(CASE WHEN UPPER(attendance.type) = 'BREAK' AND TIME(attendance.timestamp) > ? THEN 1 END) AS late_breaks,
        COUNT(*) AS total_entries
    FROM attendance 
    JOIN users ON attendance.user_id = users.id 
    WHERE 1=1
";

$tardiness_params = [$entryThreshold, $lunchThreshold, $breakThreshold];
if ($user_filter) {
    $tardiness_query .= " AND users.username = ?";
    $tardiness_params[] = $user_filter;
}
if (!empty($dateValues)) {
    $tardiness_query .= " AND DATE(attendance.timestamp) IN ($datePlaceholders)";
    $tardiness_params = array_merge($tardiness_params, $dateValues);
}

$tardiness_query .= " GROUP BY users.full_name, users.username, record_date ORDER BY record_date DESC";
$tardiness_stmt = $pdo->prepare($tardiness_query);
$tardiness_stmt->execute($tardiness_params);
$tardiness_data = $tardiness_stmt->fetchAll(PDO::FETCH_ASSOC);

$referenceDate = !empty($dateValues) ? $dateValues[0] : date('Y-m-d');

$missing_entry_query = "
    SELECT 
        users.full_name AS agent_name,
        users.username,
        MIN(attendance.timestamp) AS first_time,
        attendance.type AS first_type
    FROM attendance
    JOIN users ON attendance.user_id = users.id
    WHERE DATE(attendance.timestamp) = ? 
    AND attendance.user_id NOT IN (
        SELECT DISTINCT user_id 
        FROM attendance 
        WHERE DATE(timestamp) = ? AND UPPER(type) = 'ENTRY'
    )
    GROUP BY users.id
    ORDER BY first_time ASC
";

$stmt = $pdo->prepare($missing_entry_query);
$stmt->execute([$referenceDate, $referenceDate]);
$missing_entry_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$missing_entry_data = array_map(function (array $row) use ($attendanceTypeMap): array {
    $slug = sanitizeAttendanceTypeSlug($row['first_type'] ?? '');
    $row['first_type_label'] = ($slug !== '' && isset($attendanceTypeMap[$slug]))
        ? ($attendanceTypeMap[$slug]['label'] ?? $slug)
        : ($row['first_type'] ?? '');
    return $row;
}, $missing_entry_data);

// Query para empleados que no tienen "Exit" en la fecha seleccionada
$missing_exit_query = "
    SELECT 
        users.full_name AS agent_name,
        users.username,
        MIN(attendance.timestamp) AS first_time,
        attendance.type AS first_type
    FROM attendance
    JOIN users ON attendance.user_id = users.id
    WHERE DATE(attendance.timestamp) = ? 
    AND attendance.user_id NOT IN (
        SELECT DISTINCT user_id 
        FROM attendance 
        WHERE DATE(timestamp) = ? AND UPPER(type) = 'EXIT'
    )
    GROUP BY users.id
    ORDER BY first_time ASC
";

// Ejecutar la consulta con el filtro de fecha
$stmt_missing_exit = $pdo->prepare($missing_exit_query);
$stmt_missing_exit->execute([$referenceDate, $referenceDate]);
$missing_exit_data = $stmt_missing_exit->fetchAll(PDO::FETCH_ASSOC) ?: [];
$missing_exit_data = array_map(function (array $row) use ($attendanceTypeMap): array {
    $slug = sanitizeAttendanceTypeSlug($row['first_type'] ?? '');
    $row['first_type_label'] = ($slug !== '' && isset($attendanceTypeMap[$slug]))
        ? ($attendanceTypeMap[$slug]['label'] ?? $slug)
        : ($row['first_type'] ?? '');
    return $row;
}, $missing_exit_data);

// Query para empleados que SÍ tienen "Exit" registrado
$with_exit_query = "
    SELECT 
        users.full_name AS agent_name,
        users.username,
        DATE(attendance.timestamp) AS exit_date,
        TIME(attendance.timestamp) AS exit_time,
        attendance.timestamp AS exit_timestamp
    FROM attendance
    JOIN users ON attendance.user_id = users.id
    WHERE UPPER(attendance.type) = 'EXIT'
";

$with_exit_params = [];
if ($user_filter !== '') {
    $with_exit_query .= " AND users.username = ?";
    $with_exit_params[] = $user_filter;
}
if (!empty($dateValues)) {
    $with_exit_query .= " AND DATE(attendance.timestamp) IN ($datePlaceholders)";
    $with_exit_params = array_merge($with_exit_params, $dateValues);
} else {
    // If no date filter, use reference date
    $with_exit_query .= " AND DATE(attendance.timestamp) = ?";
    $with_exit_params[] = $referenceDate;
}

$with_exit_query .= " ORDER BY attendance.timestamp DESC";

$stmt_with_exit = $pdo->prepare($with_exit_query);
$stmt_with_exit->execute($with_exit_params);
$with_exit_data = $stmt_with_exit->fetchAll(PDO::FETCH_ASSOC) ?: [];
$withExitCount = count($with_exit_data);

$recordsTotal = count($records);
$latestRecordTimestamp = $recordsTotal > 0 ? strtotime($records[0]['record_date'] . ' ' . $records[0]['record_time']) : null;
$workSummaryTotal = count($work_summary);
$missingEntryCount = count($missing_entry_data);
$missingExitCount = count($missing_exit_data);
$tardinessTotal = count($tardiness_data);

?>
<?php include 'header.php'; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">

<section class="space-y-12 fade-in">
    <div class="glass-card">
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
        
        <?php if ($punch_error): ?>
            <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-red-400"></i>
                    <p class="text-red-300 text-sm"><?= htmlspecialchars($punch_error) ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-3">
            <?php foreach ($activeAttendanceTypes as $type): ?>
                <?php
                    $buttonSlug = htmlspecialchars($type['slug'], ENT_QUOTES, 'UTF-8');
                    $buttonLabel = htmlspecialchars($type['label'], ENT_QUOTES, 'UTF-8');
                    $iconClass = htmlspecialchars($type['icon_class'] ?? 'fas fa-circle', ENT_QUOTES, 'UTF-8');
                    $colorStart = htmlspecialchars($type['color_start'] ?? '#6366F1', ENT_QUOTES, 'UTF-8');
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
    </div>
    <div class="page-hero">
        <div class="page-hero-content">
            <div>
                <h1>Registro de asistencias</h1>
                <p>Monitorea entradas, descansos y salidas en tiempo real y genera reportes listos para compartir con operaciones y RRHH.</p>
            </div>
            <div class="page-meta">
                <span class="chip"><i class="fas fa-database"></i> <?= number_format($recordsTotal) ?> registros</span>
                <span class="chip"><i class="fas fa-users"></i> <?= number_format(count($users)) ?> usuarios</span>
                <?php if ($latestRecordTimestamp): ?>
                    <span class="chip"><i class="fas fa-clock"></i> Actualizado <?= date('d/m/Y H:i', $latestRecordTimestamp) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h2>Filtros inteligentes</h2>
                <p class="text-muted text-sm">Refina la búsqueda por usuario, rango de fechas y tipo de evento.</p>
            </div>
            <button type="button" id="reload-button" class="btn-secondary w-full sm:w-auto">
                <i class="fas fa-sync-alt"></i>
                Recargar datos
            </button>
        </div>
        <form method="GET" class="space-y-6" id="filterForm">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
                <div class="form-group">
                    <label class="form-label" for="search">Buscar</label>
                    <div class="input-icon">
                        <input id="search" type="text" name="search" class="input-control" placeholder="Nombre, usuario o IP" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                        <span class="input-icon__element"><i class="fas fa-search"></i></span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="user-filter">Usuario</label>
                    <select id="user-filter" name="user" class="select-control">
                        <option value="">Todos los usuarios</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user) ?>" <?= $user_filter === $user ? 'selected' : '' ?>><?= htmlspecialchars($user) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="daterange">Rango de fechas</label>
                    <input type="text" id="daterange" name="dates" class="input-control" value="<?= htmlspecialchars($date_filter) ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="type">Tipo de evento</label>
                    <select id="type" name="type" class="select-control">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($attendanceTypeMap as $slug => $meta): ?>
                            <option value="<?= htmlspecialchars($slug) ?>" <?= $type_filter === $slug ? 'selected' : '' ?>>
                                <?= htmlspecialchars($meta['label'] ?? $slug) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                <a href="records.php" class="btn-secondary w-full sm:w-auto">
                    <i class="fas fa-eraser"></i>
                    Limpiar filtros
                </a>
                <button type="submit" class="btn-primary w-full sm:w-auto">
                    <i class="fas fa-filter"></i>
                    Aplicar filtros
                </button>
            </div>
        </form>
    </div>

    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h2>Detalle de registros</h2>
                <p class="text-muted text-sm">Historial completo con clasificación por tipo de evento y acciones rápidas.</p>
            </div>
            <div class="table-actions w-full xl:w-auto">
                <button id="exportCsv" class="btn-secondary w-full sm:w-auto"><i class="fas fa-file-csv"></i> Exportar CSV</button>
                <button id="exportExcel" class="btn-primary w-full sm:w-auto"><i class="fas fa-file-excel"></i> Exportar Excel</button>
                <button id="exportPDF" class="btn-secondary w-full sm:w-auto"><i class="fas fa-file-pdf"></i> Exportar PDF</button>
            </div>
        </div>
        <div class="responsive-scroll">
            <table id="recordsTable" class="data-table js-datatable" data-export-name="attendance-records">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Usuario</th>
                        <th>Tipo</th>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>IP</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <?php
                            $typeSlug = $record['type_slug'] ?? '';
                            $typeLabel = $record['type_label'] ?? $typeSlug;
                            $iconClass = htmlspecialchars($record['type_icon_class'] ?? 'fas fa-circle');
                            $colorStart = sanitizeHexColorValue($record['type_color_start'] ?? null, '#6366F1');
                            $colorEnd = sanitizeHexColorValue($record['type_color_end'] ?? null, $colorStart);
                            $badgeStyle = sprintf(
                                'background: linear-gradient(135deg, %1$s 0%%, %2$s 100%%); color: #ffffff; border: none; display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.35rem 0.75rem; border-radius: 9999px; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.25); text-transform: none; font-weight: 600; letter-spacing: 0;',
                                $colorStart,
                                $colorEnd
                            );
                        ?>
                        <tr>
                            <td><?= $record['id'] ?></td>
                            <td><?= htmlspecialchars($record['full_name']) ?></td>
                            <td><?= htmlspecialchars($record['username']) ?></td>
                            <td>
                                <span class="badge" style="<?= htmlspecialchars($badgeStyle, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($typeSlug) ?>">
                                    <i class="<?= $iconClass ?>"></i>
                                    <?= htmlspecialchars($typeLabel) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($record['record_date']) ?></td>
                            <td><?= htmlspecialchars($record['record_time']) ?></td>
                            <td><?= htmlspecialchars($record['ip_address']) ?></td>
                            <td class="text-center">
                                <div class="flex items-center justify-center gap-3 text-sm">
                                    <button type="button" onclick="openEditModal(<?= $record['id'] ?>)" class="text-cyan-300 hover:text-cyan-100 transition-colors" title="Editar registro">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" onclick="openDeleteModal(<?= $record['id'] ?>)" class="text-rose-300 hover:text-rose-100 transition-colors" title="Eliminar registro">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($records)): ?>
            <div class="data-table-empty">No se encontraron registros con los filtros seleccionados.</div>
        <?php endif; ?>
    </div>

    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h2>Resumen de tiempo trabajado</h2>
                <p class="text-muted text-sm">Horas acumuladas, descansos y pagos generados por usuario.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <span class="chip"><i class="fas fa-users-cog"></i> <?= number_format($workSummaryTotal) ?> registros</span>
                <button id="downloadDailyReport" class="btn-primary" title="Descargar reporte de asistencia diaria en Excel">
                    <i class="fas fa-download"></i>
                    Reporte de Asistencia Diaria
                </button>
            </div>
        </div>
        <div class="responsive-scroll">
            <table id="summaryTable" class="data-table js-datatable" data-export-name="worktime-summary" data-length="25">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Usuario</th>
                        <th>Fecha</th>
                        <?php foreach ($summaryColumns as $column): ?>
                            <th><?= htmlspecialchars($column['label']) ?></th>
                        <?php endforeach; ?>
                        <th>Horas trabajadas</th>
                        <th title="Horas extras calculadas despues de la hora de salida configurada">
                            Horas extra
                            <i class="fas fa-info-circle text-xs text-muted ml-1" title="Las horas extras se calculan automaticamente despues de la hora de salida de cada empleado. El multiplicador se aplica al pago."></i>
                        </th>
                        <th title="Pago por horas extras con multiplicador aplicado">Pago HE</th>
                        <th>Pago Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($work_summary as $summary): ?>
                        <?php 
                            $currency = $summary['preferred_currency'] ?? 'USD';
                            $currencySymbol = $currency === 'DOP' ? 'RD$' : '$';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($summary['full_name']) ?></td>
                            <td><?= htmlspecialchars($summary['username']) ?></td>
                            <td><?= htmlspecialchars($summary['record_date']) ?></td>
                            <?php foreach ($summaryColumns as $column): ?>
                                <?php $seconds = (int) ($summary['durations'][$column['slug']] ?? 0); ?>
                                <td><?= gmdate('H:i:s', max(0, $seconds)) ?></td>
                            <?php endforeach; ?>
                            <td><?= gmdate('H:i:s', max(0, (int) $summary['work_seconds'])) ?></td>
                            <td><?= gmdate('H:i:s', max(0, (int) ($summary['overtime_seconds'] ?? 0))) ?></td>
                            <td><?= $currencySymbol ?><?= number_format($summary['overtime_payment'] ?? 0, 2) ?> <?= $currency ?></td>
                            <td><strong><?= $currencySymbol ?><?= number_format($summary['total_payment'], 2) ?> <?= $currency ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($work_summary)): ?>
            <div class="data-table-empty">No hay datos de productividad para el periodo seleccionado.</div>
        <?php endif; ?>
    </div>

    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h2>Pagos por Agente</h2>
                <p class="text-muted text-sm">Resumen consolidado de pagos por empleado y fecha, calculado solo con tipos de punch pagados.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <span class="chip"><i class="fas fa-users"></i> <?= number_format($agentPaymentsTotal) ?> registros</span>
                <?php if ($paymentTotals['USD']['count'] > 0): ?>
                    <span class="chip" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
                        <i class="fas fa-dollar-sign"></i> $<?= number_format($paymentTotals['USD']['total_payment'], 2) ?> USD
                    </span>
                <?php endif; ?>
                <?php if ($paymentTotals['DOP']['count'] > 0): ?>
                    <span class="chip" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white;">
                        <i class="fas fa-coins"></i> RD$<?= number_format($paymentTotals['DOP']['total_payment'], 2) ?> DOP
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="responsive-scroll">
            <table id="agentPaymentsTable" class="data-table js-datatable" data-export-name="agent-payments" data-length="25">
                <thead>
                    <tr>
                        <th>Nombre Completo</th>
                        <th>Usuario</th>
                        <th>Fecha</th>
                        <th>Horas Trabajadas</th>
                        <th title="Horas extras acumuladas">Horas Extra</th>
                        <th title="Pago por horas extras">Pago HE</th>
                        <th title="Pago total del día">Pago Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agent_payments as $payment): ?>
                        <?php 
                            $currency = $payment['preferred_currency'] ?? 'USD';
                            $currencySymbol = $currency === 'DOP' ? 'RD$' : '$';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($payment['full_name']) ?></td>
                            <td><?= htmlspecialchars($payment['username']) ?></td>
                            <td><?= htmlspecialchars($payment['record_date']) ?></td>
                            <td><?= gmdate('H:i:s', max(0, (int) $payment['work_seconds'])) ?></td>
                            <td><?= gmdate('H:i:s', max(0, (int) $payment['overtime_seconds'])) ?></td>
                            <td><?= $currencySymbol ?><?= number_format($payment['overtime_payment'], 2) ?> <?= $currency ?></td>
                            <td><strong><?= $currencySymbol ?><?= number_format($payment['total_payment'], 2) ?> <?= $currency ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: rgba(99, 102, 241, 0.1); font-weight: 600;">
                        <td colspan="3" class="text-right"><strong>Totales:</strong></td>
                        <td><?= gmdate('H:i:s', max(0, (int) ($paymentTotals['USD']['work_seconds'] + $paymentTotals['DOP']['work_seconds']))) ?></td>
                        <td><?= gmdate('H:i:s', max(0, (int) ($paymentTotals['USD']['overtime_seconds'] + $paymentTotals['DOP']['overtime_seconds']))) ?></td>
                        <td>
                            <?php if ($paymentTotals['USD']['count'] > 0): ?>
                                $<?= number_format($paymentTotals['USD']['overtime_payment'], 2) ?> USD
                            <?php endif; ?>
                            <?php if ($paymentTotals['USD']['count'] > 0 && $paymentTotals['DOP']['count'] > 0): ?>
                                <br>
                            <?php endif; ?>
                            <?php if ($paymentTotals['DOP']['count'] > 0): ?>
                                RD$<?= number_format($paymentTotals['DOP']['overtime_payment'], 2) ?> DOP
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($paymentTotals['USD']['count'] > 0): ?>
                                <strong>$<?= number_format($paymentTotals['USD']['total_payment'], 2) ?> USD</strong>
                            <?php endif; ?>
                            <?php if ($paymentTotals['USD']['count'] > 0 && $paymentTotals['DOP']['count'] > 0): ?>
                                <br>
                            <?php endif; ?>
                            <?php if ($paymentTotals['DOP']['count'] > 0): ?>
                                <strong>RD$<?= number_format($paymentTotals['DOP']['total_payment'], 2) ?> DOP</strong>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php if (empty($agent_payments)): ?>
            <div class="data-table-empty">No hay datos de pagos para el periodo seleccionado.</div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="glass-card space-y-4">
            <div class="panel-heading">
                <div>
                    <h2>Sin entrada registrada hoy</h2>
                    <p class="text-muted text-sm">Colaboradores que aún no marcan inicio de jornada.</p>
                </div>
                <span class="chip"><i class="fas fa-user-times"></i> <?= number_format($missingEntryCount) ?></span>
            </div>
            <div class="responsive-scroll">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Último evento</th>
                            <th>Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($missing_entry_data as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['agent_name']) ?></td>
                                <td><?= htmlspecialchars($row['username']) ?></td>
                                <td><?= htmlspecialchars($row['first_type_label'] ?? $row['first_type']) ?></td>
                                <td><?= htmlspecialchars(date('H:i:s', strtotime($row['first_time']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($missing_entry_data)): ?>
                            <tr><td colspan="4" class="data-table-empty">Todos los colaboradores registraron su entrada hoy.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="glass-card space-y-4">
            <div class="panel-heading">
                <div>
                    <h2>Sin salida en la fecha seleccionada</h2>
                    <p class="text-muted text-sm">Registros pendientes de cierre para el rango elegido.</p>
                </div>
                <span class="chip"><i class="fas fa-sign-out-alt"></i> <?= number_format($missingExitCount) ?></span>
            </div>
            <div class="responsive-scroll">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Último evento</th>
                            <th>Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($missing_exit_data as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['agent_name']) ?></td>
                                <td><?= htmlspecialchars($row['username']) ?></td>
                                <td><?= htmlspecialchars($row['first_type_label'] ?? $row['first_type']) ?></td>
                                <td><?= htmlspecialchars(date('H:i:s', strtotime($row['first_time']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($missing_exit_data)): ?>
                            <tr><td colspan="4" class="data-table-empty">No hay pendientes de salida para la fecha seleccionada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h2>Salidas Registradas (EXIT)</h2>
                <p class="text-muted text-sm">Empleados que han marcado su salida en el periodo seleccionado.</p>
            </div>
            <span class="chip"><i class="fas fa-door-open"></i> <?= number_format($withExitCount) ?> registros</span>
        </div>
        <div class="responsive-scroll">
            <table id="withExitTable" class="data-table js-datatable" data-export-name="exits-registered" data-length="25">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Usuario</th>
                        <th>Fecha</th>
                        <th>Hora de Salida</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($with_exit_data as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['agent_name']) ?></td>
                            <td><?= htmlspecialchars($row['username']) ?></td>
                            <td><?= htmlspecialchars($row['exit_date']) ?></td>
                            <td>
                                <span class="badge" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 9999px;">
                                    <i class="fas fa-sign-out-alt"></i>
                                    <?= htmlspecialchars($row['exit_time']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($with_exit_data)): ?>
            <div class="data-table-empty">No hay salidas registradas para el periodo seleccionado.</div>
        <?php endif; ?>
    </div>

    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h2>Porcentaje de tardanza</h2>
                <p class="text-muted text-sm">Seguimiento de llegadas tarde en las jornadas registradas.</p>
            </div>
            <span class="chip"><i class="fas fa-percent"></i> <?= number_format($tardinessTotal) ?> registros</span>
        </div>
        <div class="responsive-scroll">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Usuario</th>
                        <th>Fecha</th>
                        <th>Entradas tarde</th>
                        <th>Almuerzo tarde</th>
                        <th>Break tarde</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tardiness_data as $data): ?>
                        <?php
                            $totalEntries = (int) ($data['total_entries'] ?? 0);
                            $entryPercent = $totalEntries > 0 ? ($data['late_entries'] / $totalEntries) * 100 : 0;
                            $lunchPercent = $totalEntries > 0 ? ($data['late_lunches'] / $totalEntries) * 100 : 0;
                            $breakPercent = $totalEntries > 0 ? ($data['late_breaks'] / $totalEntries) * 100 : 0;
                            $entryBadge = $entryPercent >= 50 ? 'badge badge--danger' : 'badge badge--success';
                            $lunchBadge = $lunchPercent >= 50 ? 'badge badge--danger' : 'badge badge--success';
                            $breakBadge = $breakPercent >= 50 ? 'badge badge--danger' : 'badge badge--success';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($data['full_name']) ?></td>
                            <td><?= htmlspecialchars($data['username']) ?></td>
                            <td><?= htmlspecialchars($data['record_date']) ?></td>
                            <td><span class="<?= $entryBadge ?>"><?= number_format($entryPercent, 2) ?>%</span></td>
                            <td><span class="<?= $lunchBadge ?>"><?= number_format($lunchPercent, 2) ?>%</span></td>
                            <td><span class="<?= $breakBadge ?>"><?= number_format($breakPercent, 2) ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tardiness_data)): ?>
                        <tr><td colspan="6" class="data-table-empty">Sin incidencias registradas en el periodo seleccionado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.jsdelivr.net/npm/moment@2.29.4/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script>
// Existing JavaScript with added animations
$(document).ready(function() {
    // Initialize DataTables with custom styling
    const tableConfig = {
        dom: '<"flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4"Bf>rtip',
        buttons: [
            {
                extend: 'csvHtml5',
                className: 'dt-button-hidden'
            },
            {
                extend: 'excelHtml5',
                className: 'dt-button-hidden'
            },
            {
                extend: 'pdfHtml5',
                className: 'dt-button-hidden',
                orientation: 'landscape',
                pageSize: 'LEGAL'
            }
        ],
        pageLength: 10,
        responsive: true,
        order: [[0, 'desc']],
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json',
            search: "",
            searchPlaceholder: "Buscar..."
        },
        drawCallback: function() {
            const rows = this.api().rows({ page: 'current' }).nodes();
            $(rows).addClass('fade-in');
        }
    };

    const tableInstances = {};
    ['#recordsTable', '#summaryTable', '#agentPaymentsTable', '#withExitTable'].forEach(function (selector) {
        const $table = $(selector);
        if ($table.length) {
            const customConfig = $.extend(true, {}, tableConfig);
            const lengthAttr = parseInt($table.data('length') || customConfig.pageLength, 10);
            customConfig.pageLength = Number.isNaN(lengthAttr) ? customConfig.pageLength : lengthAttr;
            tableInstances[selector] = $table.DataTable(customConfig);
        }
    });

    const mainTable = tableInstances['#recordsTable'];

    $('#exportCsv').on('click', function() {
        if (mainTable) {
            mainTable.button('.buttons-csv').trigger();
        }
    });

    $('#exportExcel').on('click', function() {
        if (mainTable) {
            mainTable.button('.buttons-excel').trigger();
        }
    });

    $('#exportPDF').on('click', function() {
        if (mainTable) {
            mainTable.button('.buttons-pdf').trigger();
        }
    });

    // Recarga rápida
    $('#reload-button').click(function() {
        const button = $(this);
        button.prop('disabled', true)
              .html('<i class="fas fa-spinner fa-spin"></i> Actualizando...');

        setTimeout(() => {
            location.reload();
        }, 500);
    });

    // DateRangePicker en español
    $('#daterange').daterangepicker({
        opens: 'left',
        locale: {
            format: 'YYYY-MM-DD',
            applyLabel: 'Aplicar',
            cancelLabel: 'Cancelar',
            daysOfWeek: ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa'],
            monthNames: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
        },
        ranges: {
           'Hoy': [moment(), moment()],
           'Ayer': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
           'Últimos 7 días': [moment().subtract(6, 'days'), moment()],
           'Últimos 30 días': [moment().subtract(29, 'days'), moment()],
           'Este mes': [moment().startOf('month'), moment().endOf('month')],
           'Mes anterior': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
        }
    });

    // Descargar reporte de asistencia diaria
    $('#downloadDailyReport').on('click', function() {
        const button = $(this);
        const originalHtml = button.html();
        
        button.prop('disabled', true)
              .html('<i class="fas fa-spinner fa-spin"></i> Generando reporte...');
        
        // Construir URL con parámetros actuales
        const params = new URLSearchParams();
        
        // Obtener el rango de fechas - si está vacío, usar la fecha actual
        let dateRange = $('#daterange').val();
        if (!dateRange || dateRange.trim() === '') {
            // Si no hay filtro, usar la fecha actual
            const today = moment().format('YYYY-MM-DD');
            dateRange = today + ' - ' + today;
        }
        params.append('dates', dateRange);
        
        const userFilter = $('#user-filter').val();
        if (userFilter) {
            params.append('user', userFilter);
        }
        
        // Redirigir para descargar
        window.location.href = 'download_daily_attendance_report.php?' + params.toString();
        
        // Restaurar botón después de un breve delay
        setTimeout(() => {
            button.prop('disabled', false).html(originalHtml);
        }, 2000);
    });
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

/* Daily Report Button Styling */
#downloadDailyReport {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);
}

#downloadDailyReport:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.4);
}

#downloadDailyReport:active {
    transform: translateY(0);
}

#downloadDailyReport:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

@media (max-width: 640px) {
    #downloadDailyReport {
        width: 100%;
        justify-content: center;
    }
}

/* DataTables Pagination Styling */
.dataTables_wrapper .dataTables_paginate {
    padding-top: 1.5rem !important;
    padding-bottom: 1.5rem !important;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-width: 2.5rem !important;
    height: 2.5rem !important;
    padding: 0 0.75rem !important;
    margin: 0 0.125rem !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 0.5rem !important;
    background: #ffffff !important;
    color: #64748b !important;
    font-size: 0.875rem !important;
    font-weight: 500 !important;
    text-decoration: none !important;
    transition: all 0.2s ease !important;
    box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05) !important;
}

.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background: #f8fafc !important;
    border-color: #cbd5e1 !important;
    color: #1e293b !important;
    box-shadow: 0 2px 4px 0 rgba(0, 0, 0, 0.1) !important;
    transform: translateY(-1px) !important;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
    border-color: #2563eb !important;
    color: #ffffff !important;
    font-weight: 600 !important;
    box-shadow: 0 2px 4px 0 rgba(37, 99, 235, 0.3) !important;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
    border-color: #1d4ed8 !important;
    color: #ffffff !important;
    transform: translateY(-1px) !important;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
    background: #f1f5f9 !important;
    border-color: #e2e8f0 !important;
    color: #cbd5e1 !important;
    cursor: not-allowed !important;
    box-shadow: none !important;
    opacity: 0.6 !important;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
    background: #f1f5f9 !important;
    border-color: #e2e8f0 !important;
    color: #cbd5e1 !important;
    transform: none !important;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.previous,
.dataTables_wrapper .dataTables_paginate .paginate_button.next {
    padding: 0.625rem 1rem !important;
    font-weight: 600 !important;
}

.dataTables_wrapper .dataTables_info {
    color: #64748b !important;
    font-size: 0.875rem !important;
    font-weight: 500 !important;
    padding-top: 1.5rem !important;
}

.dataTables_wrapper .dataTables_length select {
    border: 1px solid #e2e8f0 !important;
    border-radius: 0.5rem !important;
    padding: 0.5rem 2rem 0.5rem 0.75rem !important;
    font-size: 0.875rem !important;
    color: #1e293b !important;
    background-color: #ffffff !important;
    transition: all 0.2s ease !important;
}

.dataTables_wrapper .dataTables_length select:focus {
    border-color: #3b82f6 !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}

.dataTables_wrapper .dataTables_filter input {
    border: 1px solid #e2e8f0 !important;
    border-radius: 0.5rem !important;
    padding: 0.5rem 0.75rem !important;
    font-size: 0.875rem !important;
    color: #1e293b !important;
    transition: all 0.2s ease !important;
}

.dataTables_wrapper .dataTables_filter input:focus {
    border-color: #3b82f6 !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}

@media (max-width: 768px) {
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        min-width: 2rem !important;
        height: 2rem !important;
        padding: 0 0.5rem !important;
        font-size: 0.8125rem !important;
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button.previous,
    .dataTables_wrapper .dataTables_paginate .paginate_button.next {
        padding: 0.5rem 0.75rem !important;
    }
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
}

.modal-content {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    margin: 10% auto;
    padding: 2rem;
    border-radius: 1rem;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.2);
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-50px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(226, 232, 240, 0.1);
}

.modal-header h3 {
    color: #f1f5f9;
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}

.modal-close {
    color: #94a3b8;
    font-size: 1.75rem;
    font-weight: bold;
    cursor: pointer;
    transition: color 0.2s;
    background: none;
    border: none;
    line-height: 1;
}

.modal-close:hover,
.modal-close:focus {
    color: #f1f5f9;
}

.modal-body {
    margin-bottom: 1.5rem;
}

.modal-input-group {
    margin-bottom: 1rem;
}

.modal-input-group label {
    display: block;
    color: #cbd5e1;
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.modal-input-group input {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid rgba(226, 232, 240, 0.2);
    border-radius: 0.5rem;
    background: rgba(248, 250, 252, 0.05);
    color: #f1f5f9;
    font-size: 0.875rem;
    transition: all 0.2s;
}

.modal-input-group input:focus {
    outline: none;
    border-color: #3b82f6;
    background: rgba(248, 250, 252, 0.1);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.modal-input-group input::placeholder {
    color: #64748b;
}

.modal-info {
    background: rgba(251, 191, 36, 0.1);
    border: 1px solid rgba(251, 191, 36, 0.3);
    border-radius: 0.5rem;
    padding: 0.75rem;
    margin-bottom: 1rem;
}

.modal-info p {
    color: #fbbf24;
    font-size: 0.875rem;
    margin: 0;
}

.modal-footer {
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
}

.modal-btn {
    padding: 0.75rem 1.5rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
}

.modal-btn-danger {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

.modal-btn-danger:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.3);
}

.modal-btn-secondary {
    background: rgba(148, 163, 184, 0.2);
    color: #cbd5e1;
    border: 1px solid rgba(148, 163, 184, 0.3);
}

.modal-btn-secondary:hover {
    background: rgba(148, 163, 184, 0.3);
    color: #f1f5f9;
}

.modal-btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}

.modal-btn-primary:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.3);
}
</style>

<?php
// Define authorization requirements before modals
require_once 'lib/authorization_functions.php';
$editAuthRequired = isAuthorizationRequiredForContext($pdo, 'edit_records');
$deleteAuthRequired = isAuthorizationRequiredForContext($pdo, 'delete_records');
?>

<!-- Modal para editar registro con código de autorización -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Editar Registro</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            
            <div class="modal-info">
                <p><i class="fas fa-info-circle"></i> Vas a editar un registro de asistencia</p>
            </div>
            
            <?php if ($editAuthRequired): ?>
            <div class="modal-input-group">
                <label for="edit_auth_code">
                    <i class="fas fa-lock"></i> Código de Autorización *
                </label>
                <input 
                    type="text" 
                    id="edit_auth_code" 
                    name="authorization_code"
                    placeholder="Ingresa el código de autorización"
                    required
                >
            </div>
            <?php endif; ?>
            
            <input type="hidden" id="edit_record_id">
        </div>
        <div class="modal-footer">
            <button type="button" class="modal-btn modal-btn-secondary" onclick="closeEditModal()">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="button" class="modal-btn modal-btn-primary" onclick="submitEdit()">
                <i class="fas fa-edit"></i> Continuar
            </button>
        </div>
    </div>
</div>

<!-- Modal para eliminar registro con código de autorización -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-trash-alt"></i> Eliminar Registro</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="modal-info">
                <p><i class="fas fa-exclamation-triangle"></i> ¿Estás seguro de que deseas eliminar este registro?</p>
            </div>
            
            <?php if ($deleteAuthRequired): ?>
            <div class="modal-input-group">
                <label for="delete_auth_code">
                    <i class="fas fa-lock"></i> Código de Autorización *
                </label>
                <input 
                    type="text" 
                    id="delete_auth_code" 
                    name="authorization_code"
                    placeholder="Ingresa el código de autorización"
                    required
                >
            </div>
            <?php endif; ?>
            
            <form id="deleteForm" method="POST" action="delete_record.php">
                <input type="hidden" name="id" id="delete_record_id">
                <input type="hidden" name="authorization_code" id="delete_auth_code_hidden">
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="modal-btn modal-btn-secondary" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="button" class="modal-btn modal-btn-danger" onclick="submitDelete()">
                <i class="fas fa-trash-alt"></i> Eliminar
            </button>
        </div>
    </div>
</div>

<script>
// Edit modal functions
function openEditModal(recordId) {
    document.getElementById('edit_record_id').value = recordId;
    document.getElementById('editModal').style.display = 'block';
    <?php if ($editAuthRequired): ?>
    document.getElementById('edit_auth_code').value = '';
    document.getElementById('edit_auth_code').focus();
    <?php endif; ?>
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function submitEdit() {
    const recordId = document.getElementById('edit_record_id').value;
    <?php if ($editAuthRequired): ?>
    const authCode = document.getElementById('edit_auth_code').value.trim();
    if (!authCode) {
        alert('Por favor ingresa el código de autorización');
        return;
    }
    // Redirect to edit page with auth code in URL
    window.location.href = `edit_record.php?id=${recordId}&auth_code=${encodeURIComponent(authCode)}`;
    <?php else: ?>
    window.location.href = `edit_record.php?id=${recordId}`;
    <?php endif; ?>
}

// Delete modal functions
function openDeleteModal(recordId) {
    document.getElementById('delete_record_id').value = recordId;
    document.getElementById('deleteModal').style.display = 'block';
    <?php if ($deleteAuthRequired): ?>
    document.getElementById('delete_auth_code').value = '';
    document.getElementById('delete_auth_code').focus();
    <?php endif; ?>
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

function submitDelete() {
    <?php if ($deleteAuthRequired): ?>
    const authCode = document.getElementById('delete_auth_code').value.trim();
    if (!authCode) {
        alert('Por favor ingresa el código de autorización');
        return;
    }
    document.getElementById('delete_auth_code_hidden').value = authCode;
    <?php endif; ?>
    
    document.getElementById('deleteForm').submit();
}

// Close modals when clicking outside
window.onclick = function(event) {
    const editModal = document.getElementById('editModal');
    const deleteModal = document.getElementById('deleteModal');
    if (event.target === editModal) {
        closeEditModal();
    }
    if (event.target === deleteModal) {
        closeDeleteModal();
    }
}

// Close modals with ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEditModal();
        closeDeleteModal();
    }
});
</script>

<?php include 'footer.php'; ?>
