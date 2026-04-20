<?php
session_start();
include 'db.php';
require_once 'lib/logging_functions.php';
require_once 'lib/authorization_functions.php';

date_default_timezone_set('America/Santo_Domingo');

ensurePermission('records');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['punch_error'] = 'Acceso no válido.';
    header('Location: records.php');
    exit;
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentUserRole = $_SESSION['role'] ?? '';
$currentUserName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? '');

$targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
$typeSlug = sanitizeAttendanceTypeSlug($_POST['punch_type'] ?? '');
$punchDate = trim($_POST['punch_date'] ?? '');
$punchTime = trim($_POST['punch_time'] ?? '');
$ipAddress = trim($_POST['ip_address'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$authorizationCodeInput = trim($_POST['authorization_code'] ?? '');

$redirectUrl = 'records.php';

if ($targetUserId <= 0) {
    $_SESSION['punch_error'] = 'Selecciona un colaborador válido.';
    header('Location: ' . $redirectUrl);
    exit;
}

if ($typeSlug === '') {
    $_SESSION['punch_error'] = 'Selecciona un tipo de evento válido.';
    header('Location: ' . $redirectUrl);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $punchDate)) {
    $_SESSION['punch_error'] = 'Formato de fecha inválido. Usa YYYY-MM-DD.';
    header('Location: ' . $redirectUrl);
    exit;
}

if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $punchTime)) {
    $_SESSION['punch_error'] = 'Formato de hora inválido. Usa HH:MM.';
    header('Location: ' . $redirectUrl);
    exit;
}

if (strlen($punchTime) === 5) {
    $punchTime .= ':00';
}

$todayIso = date('Y-m-d');
if ($punchDate > $todayIso) {
    $_SESSION['punch_error'] = 'No puedes registrar un punch en una fecha futura.';
    header('Location: ' . $redirectUrl);
    exit;
}

$customTimestamp = $punchDate . ' ' . $punchTime;

if ($ipAddress === '') {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ipAddress = $remote === '::1' ? '127.0.0.1' : $remote;
}

if (strlen($ipAddress) > 45) {
    $ipAddress = substr($ipAddress, 0, 45);
}

// Supervisor scope: restrict which users a Supervisor can create punches for.
if ($currentUserRole === 'Supervisor' && $currentUserId > 0) {
    $campaignStmt = $pdo->prepare("SELECT campaign_id FROM supervisor_campaigns WHERE supervisor_id = ?");
    $campaignStmt->execute([$currentUserId]);
    $supervisorCampaigns = array_map('intval', $campaignStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $scopeConditions = ['users.id = ?', 'e.supervisor_id = ?'];
    $scopeParams = [$currentUserId, $currentUserId];

    if (!empty($supervisorCampaigns)) {
        $campaignPlaceholders = implode(',', array_fill(0, count($supervisorCampaigns), '?'));
        $scopeConditions[] = "e.campaign_id IN ($campaignPlaceholders)";
        $scopeParams = array_merge($scopeParams, $supervisorCampaigns);
    }

    $scopeSql = '
        SELECT users.id
        FROM users
        LEFT JOIN employees e ON e.user_id = users.id
        WHERE users.id = ?
          AND (' . implode(' OR ', $scopeConditions) . ')
        LIMIT 1
    ';
    $scopeStmt = $pdo->prepare($scopeSql);
    $scopeStmt->execute(array_merge([$targetUserId], $scopeParams));
    if (!$scopeStmt->fetchColumn()) {
        $_SESSION['punch_error'] = 'No tienes permisos para registrar asistencia para este colaborador.';
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Validate target user + get display info
$userStmt = $pdo->prepare('SELECT id, full_name, username FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$targetUserId]);
$targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$targetUser) {
    $_SESSION['punch_error'] = 'El colaborador seleccionado no existe.';
    header('Location: ' . $redirectUrl);
    exit;
}

// Validate attendance type
$typeStmt = $pdo->prepare("
    SELECT slug, label, is_unique_daily, is_active
    FROM attendance_types
    WHERE UPPER(slug) = ?
    LIMIT 1
");
$typeStmt->execute([$typeSlug]);
$typeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);
if (!$typeRow || (int) ($typeRow['is_active'] ?? 0) !== 1) {
    $_SESSION['punch_error'] = 'Tipo de evento inválido o inactivo.';
    header('Location: ' . $redirectUrl);
    exit;
}

$typeLabel = $typeRow['label'] ?? $typeSlug;
$isUniqueDaily = (int) ($typeRow['is_unique_daily'] ?? 0) === 1;

if ($isUniqueDaily) {
    $checkStmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM attendance
        WHERE user_id = ?
          AND type = ?
          AND DATE(timestamp) = ?
    ');
    $checkStmt->execute([$targetUserId, $typeSlug, $punchDate]);
    if ((int) $checkStmt->fetchColumn() > 0) {
        $_SESSION['punch_error'] = "Ya existe un registro de tipo '{$typeLabel}' para este colaborador el {$punchDate}.";
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Prevent exact duplicates (same user, same type, same timestamp)
$dupStmt = $pdo->prepare('
    SELECT COUNT(*)
    FROM attendance
    WHERE user_id = ?
      AND type = ?
      AND timestamp = ?
');
$dupStmt->execute([$targetUserId, $typeSlug, $customTimestamp]);
if ((int) $dupStmt->fetchColumn() > 0) {
    $_SESSION['punch_error'] = 'Ya existe un registro idéntico (mismo tipo, fecha y hora) para este colaborador.';
    header('Location: ' . $redirectUrl);
    exit;
}

// Authorization check — reuse the 'edit_records' context since manual creation
// of past punches is equivalent in impact to editing historical attendance.
$authRequired = isAuthorizationRequiredForContext($pdo, 'edit_records');
$authorizationCodeId = null;
if ($authRequired) {
    if ($authorizationCodeInput === '') {
        $_SESSION['punch_error'] = 'Se requiere un código de autorización para registrar asistencia manualmente.';
        header('Location: ' . $redirectUrl);
        exit;
    }
    $validation = validateAuthorizationCode($pdo, $authorizationCodeInput, 'edit_records', $currentUserId);
    if (empty($validation['valid'])) {
        $msg = $validation['message'] ?? ($validation['error'] ?? 'Código inválido.');
        $_SESSION['punch_error'] = 'Código de autorización inválido: ' . $msg;
        header('Location: ' . $redirectUrl);
        exit;
    }
    $authorizationCodeId = $validation['code_id'] ?? null;
}

try {
    $hasAuthColumn = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'authorization_code_id'");
        $hasAuthColumn = $colCheck && $colCheck->rowCount() > 0;
    } catch (Throwable $e) {
        $hasAuthColumn = false;
    }

    if ($hasAuthColumn) {
        $insertStmt = $pdo->prepare('
            INSERT INTO attendance (user_id, type, ip_address, timestamp, authorization_code_id)
            VALUES (?, ?, ?, ?, ?)
        ');
        $insertStmt->execute([$targetUserId, $typeSlug, $ipAddress, $customTimestamp, $authorizationCodeId]);
    } else {
        $insertStmt = $pdo->prepare('
            INSERT INTO attendance (user_id, type, ip_address, timestamp)
            VALUES (?, ?, ?, ?)
        ');
        $insertStmt->execute([$targetUserId, $typeSlug, $ipAddress, $customTimestamp]);
    }

    $recordId = (int) $pdo->lastInsertId();

    if ($authorizationCodeId) {
        logAuthorizationCodeUsage(
            $pdo,
            $authorizationCodeId,
            $currentUserId,
            'edit_records',
            $recordId,
            'attendance',
            [
                'action' => 'manual_create',
                'target_user_id' => $targetUserId,
                'target_user_name' => $targetUser['full_name'],
                'type' => $typeSlug,
                'timestamp' => $customTimestamp,
                'notes' => $notes,
                'created_by' => $currentUserName
            ]
        );
    }

    $description = sprintf(
        'Registro manual de asistencia creado para %s (%s): %s el %s',
        $targetUser['full_name'],
        $targetUser['username'],
        $typeSlug,
        $customTimestamp
    );

    log_custom_action(
        $pdo,
        $currentUserId,
        $currentUserName,
        $currentUserRole,
        'attendance',
        'create',
        $description,
        'attendance_record',
        $recordId,
        [
            'manual_creation' => true,
            'target_user_id' => $targetUserId,
            'target_user_name' => $targetUser['full_name'],
            'target_username' => $targetUser['username'],
            'type' => $typeSlug,
            'type_label' => $typeLabel,
            'timestamp' => $customTimestamp,
            'ip_address' => $ipAddress,
            'authorization_code_id' => $authorizationCodeId,
            'notes' => $notes,
            'is_backdated' => $punchDate < $todayIso
        ]
    );

    $_SESSION['punch_success'] = sprintf(
        'Registro manual creado: %s - %s el %s %s.',
        htmlspecialchars($targetUser['full_name']),
        htmlspecialchars($typeLabel),
        htmlspecialchars($punchDate),
        htmlspecialchars(substr($punchTime, 0, 5))
    );
} catch (PDOException $e) {
    $_SESSION['punch_error'] = 'Error al crear el registro: ' . $e->getMessage();
}

header('Location: ' . $redirectUrl);
exit;
