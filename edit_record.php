<?php
session_start();
include 'db.php';
require_once 'lib/logging_functions.php';
require_once 'lib/authorization_functions.php';

date_default_timezone_set('America/Santo_Domingo');

// Check permission
ensurePermission('records');

function getSupervisorAccessClause(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['role'] ?? '';

    if ($role !== 'Supervisor' || $userId <= 0) {
        $cache = ['', []];
        return $cache;
    }

    $campaignStmt = $pdo->prepare("SELECT campaign_id FROM supervisor_campaigns WHERE supervisor_id = ?");
    $campaignStmt->execute([$userId]);
    $campaigns = array_map('intval', $campaignStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $conditions = [
        'users.id = ?',
        'e.supervisor_id = ?'
    ];
    $params = [$userId, $userId];

    if (!empty($campaigns)) {
        $placeholders = implode(',', array_fill(0, count($campaigns), '?'));
        $conditions[] = "e.campaign_id IN ($placeholders)";
        $params = array_merge($params, $campaigns);
    }

    $cache = [' AND (' . implode(' OR ', $conditions) . ')', $params];
    return $cache;
}

function fetchAttendanceRecord(PDO $pdo, int $recordId): ?array
{
    [$clause, $params] = getSupervisorAccessClause($pdo);
    $sql = "
        SELECT
            attendance.*,
            users.full_name,
            users.username
        FROM attendance
        JOIN users ON attendance.user_id = users.id
        LEFT JOIN employees e ON e.user_id = users.id
        WHERE attendance.id = ?
    ";

    $stmt = $pdo->prepare($sql . $clause);
    $stmt->execute(array_merge([$recordId], $params));
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    return $record ?: null;
}

if (!isset($_GET['id'])) {
    header('Location: records.php');
    exit;
}

$record_id = (int) $_GET['id'];
if ($record_id <= 0) {
    header('Location: records.php');
    exit;
}

$message = '';
$messageType = '';
$authCodeValidated = false;
$validatedAuthCodeId = null;

// Check if authorization code was provided via URL (from modal)
if (isset($_GET['auth_code']) && !empty($_GET['auth_code'])) {
    $authCodeFromUrl = trim($_GET['auth_code']);

    if (isAuthorizationRequiredForContext($pdo, 'edit_records')) {
        $validation = validateAuthorizationCode(
            $pdo,
            $authCodeFromUrl,
            'edit_records',
            $_SESSION['user_id']
        );

        if ($validation['valid']) {
            $authCodeValidated = true;
            $validatedAuthCodeId = $validation['code_id'];
        } else {
            $_SESSION['punch_error'] = 'Código de autorización inválido: ' . ($validation['error'] ?? ($validation['message'] ?? ''));
            header('Location: records.php');
            exit;
        }
    }
} else {
    if (isAuthorizationRequiredForContext($pdo, 'edit_records')) {
        $_SESSION['punch_error'] = 'Se requiere un código de autorización para editar registros.';
        header('Location: records.php');
        exit;
    }
}

$record = fetchAttendanceRecord($pdo, $record_id);

if (!$record) {
    $_SESSION['punch_error'] = 'Registro no encontrado o sin permisos para editarlo.';
    header('Location: records.php');
    exit;
}

$attendanceTypes = getAttendanceTypes($pdo, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = sanitizeAttendanceTypeSlug($_POST['type'] ?? '');
    $timestampInput = trim($_POST['timestamp'] ?? '');
    $ip_address = trim($_POST['ip_address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Normalize datetime-local value (YYYY-MM-DDTHH:MM) to MySQL format
    $timestamp = $timestampInput;
    if ($timestamp !== '' && strpos($timestamp, 'T') !== false) {
        $timestamp = str_replace('T', ' ', $timestamp);
    }
    if ($timestamp !== '' && strlen($timestamp) === 16) {
        $timestamp .= ':00';
    }

    if ($type === '' || $timestamp === '') {
        $message = 'Por favor completa los campos obligatorios (tipo y fecha/hora).';
        $messageType = 'error';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp)) {
        $message = 'Formato de fecha y hora inválido.';
        $messageType = 'error';
    } else {
        // Validate the new type exists and is active
        $typeStmt = $pdo->prepare("SELECT slug, is_active FROM attendance_types WHERE UPPER(slug) = ? LIMIT 1");
        $typeStmt->execute([$type]);
        $typeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$typeRow || (int) ($typeRow['is_active'] ?? 0) !== 1) {
            $message = 'Tipo de evento inválido o inactivo.';
            $messageType = 'error';
        } else {
            $oldValues = [
                'type' => $record['type'],
                'timestamp' => $record['timestamp'],
                'ip_address' => $record['ip_address']
            ];

            $update_query = "UPDATE attendance SET type = ?, timestamp = ?, ip_address = ? WHERE id = ?";
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([$type, $timestamp, $ip_address, $record_id]);

            if ($validatedAuthCodeId) {
                logAuthorizationCodeUsage(
                    $pdo,
                    $validatedAuthCodeId,
                    $_SESSION['user_id'],
                    'edit_records',
                    $record_id,
                    'attendance',
                    [
                        'old_values' => $oldValues,
                        'new_values' => [
                            'type' => $type,
                            'timestamp' => $timestamp,
                            'ip_address' => $ip_address
                        ],
                        'notes' => $notes,
                        'edited_by' => $_SESSION['full_name'] ?? ''
                    ]
                );
            }

            $newValues = [
                'type' => $type,
                'timestamp' => $timestamp,
                'ip_address' => $ip_address,
                'notes' => $notes
            ];

            log_attendance_modified(
                $pdo,
                $_SESSION['user_id'],
                $_SESSION['full_name'] ?? '',
                $_SESSION['role'] ?? '',
                $record_id,
                $record['full_name'],
                $oldValues,
                $newValues
            );

            $_SESSION['punch_success'] = 'Registro actualizado exitosamente.';
            header('Location: records.php');
            exit;
        }
    }

    // Reload record with the attempted values so the form shows what the user typed.
    $record['type'] = $type !== '' ? $type : $record['type'];
    $record['timestamp'] = $timestamp !== '' ? $timestamp : $record['timestamp'];
    $record['ip_address'] = $ip_address;
}

$recordDate = substr((string) $record['timestamp'], 0, 10);
$isPast = $recordDate !== '' && $recordDate < date('Y-m-d');
?>
<?php include 'header.php'; ?>

<section class="space-y-10">
    <div class="max-w-4xl mx-auto px-6 py-10 space-y-8">

        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="space-y-2">
                <p class="text-muted text-xs uppercase tracking-[0.35em]">Edición de asistencia</p>
                <h1 class="text-primary text-3xl md:text-4xl font-bold">Editar registro #<?= (int) $record_id ?></h1>
                <p class="text-muted max-w-2xl">
                    Corrige el tipo, la fecha/hora o la IP de un registro de asistencia para mantener la nómina cuadrada.
                    Todos los cambios se guardan en el historial de auditoría.
                </p>
            </div>
            <a href="records.php" class="btn-secondary w-full sm:w-auto" style="align-self:flex-start;">
                <i class="fas fa-arrow-left"></i> Volver a registros
            </a>
        </div>

        <?php if ($message): ?>
            <div class="status-banner <?= $messageType === 'error' ? 'error' : 'success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($isPast): ?>
            <div class="status-banner" style="background: linear-gradient(135deg, #f59e0b15 0%, #d9770615 100%); border-left: 4px solid #f59e0b; color: #fbbf24;">
                <i class="fas fa-history"></i>
                Estás editando un registro de una fecha pasada (<?= htmlspecialchars($recordDate) ?>). Usa el campo de notas para justificar el ajuste.
            </div>
        <?php endif; ?>

        <div class="glass-card space-y-6">
            <div class="panel-heading">
                <div>
                    <h2>Detalles del colaborador</h2>
                    <p class="text-muted text-sm">Verifica que este es el empleado correcto antes de guardar.</p>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-muted text-xs uppercase tracking-widest">Nombre completo</p>
                    <p class="text-primary font-semibold"><?= htmlspecialchars($record['full_name']) ?></p>
                </div>
                <div>
                    <p class="text-muted text-xs uppercase tracking-widest">Usuario</p>
                    <p class="text-primary font-semibold"><?= htmlspecialchars($record['username']) ?></p>
                </div>
            </div>
        </div>

        <form method="POST" class="glass-card space-y-6">
            <div class="panel-heading">
                <div>
                    <h2>Datos del registro</h2>
                    <p class="text-muted text-sm">Los cambios se aplican inmediatamente al cerrar y afectan los cálculos de nómina.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="form-group">
                    <label class="form-label" for="type">Tipo de evento *</label>
                    <select name="type" id="type" class="select-control" required>
                        <option value="">Seleccionar tipo...</option>
                        <?php foreach ($attendanceTypes as $typeRow): ?>
                            <?php
                                $slug = strtoupper($typeRow['slug']);
                                $label = $typeRow['label'] ?? $slug;
                                $isSelected = strtoupper((string) $record['type']) === $slug ? 'selected' : '';
                            ?>
                            <option value="<?= htmlspecialchars($slug) ?>" <?= $isSelected ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="timestamp">Fecha y hora *</label>
                    <input
                        type="datetime-local"
                        name="timestamp"
                        id="timestamp"
                        value="<?= date('Y-m-d\TH:i', strtotime((string) $record['timestamp'])) ?>"
                        max="<?= htmlspecialchars(date('Y-m-d\TH:i')) ?>"
                        class="input-control"
                        required
                    >
                    <p class="text-muted text-xs mt-1">Puedes editar cualquier fecha pasada. Fechas futuras no están permitidas.</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="ip_address">Dirección IP</label>
                    <input
                        type="text"
                        name="ip_address"
                        id="ip_address"
                        value="<?= htmlspecialchars((string) ($record['ip_address'] ?? '')) ?>"
                        class="input-control"
                        maxlength="45"
                    >
                    <p class="text-muted text-xs mt-1">Opcional - Dirección IP desde donde se registró.</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="notes">Motivo / Notas del ajuste</label>
                    <input
                        type="text"
                        name="notes"
                        id="notes"
                        class="input-control"
                        maxlength="255"
                        placeholder="Ej: Olvidó marcar salida, corrección por cuadre de nómina…"
                    >
                    <p class="text-muted text-xs mt-1">Se guarda en el historial de auditoría junto al cambio.</p>
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                <a href="records.php" class="btn-secondary w-full sm:w-auto">
                    <i class="fas fa-times"></i> Cancelar
                </a>
                <button type="submit" class="btn-primary w-full sm:w-auto">
                    <i class="fas fa-save"></i> Guardar cambios
                </button>
            </div>
        </form>
    </div>
</section>

<?php include 'footer.php'; ?>
