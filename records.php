<?php
session_start();
include 'db.php';

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
$defaultExitTime = trim((string) ($scheduleConfig['exit_time'] ?? ''));
if ($defaultExitTime !== '' && strlen($defaultExitTime) === 5) {
    $defaultExitTime .= ':00';
}
$exitSlug = sanitizeAttendanceTypeSlug('EXIT');
$entryThreshold = date('H:i:s', strtotime($scheduleConfig['entry_time'] . ' +5 minutes'));
$lunchThreshold = $scheduleConfig['lunch_time'];
$breakThreshold = $scheduleConfig['break_time'];

$attendanceTypes = getAttendanceTypes($pdo, false);
$attendanceTypeMap = [];
foreach ($attendanceTypes as $typeRow) {
    $slug = sanitizeAttendanceTypeSlug($typeRow['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $typeRow['slug'] = $slug;
    $attendanceTypeMap[$slug] = $typeRow;
}

$durationTypes = array_values(array_filter($attendanceTypes, function (array $typeRow): bool {
    return ((int) ($typeRow['is_active'] ?? 0) === 1) && ((int) ($typeRow['is_unique_daily'] ?? 0) === 0);
}));

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

$finalizeSummaryGroup = function (?array &$group) use (&$work_summary, $summaryColumns, $nonWorkSlugs, $hourly_rates, $userExitTimes, $defaultExitTime, $exitSlug): void {
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

    $totalSeconds = array_sum($durationsAll);
    $pauseSeconds = 0;
    foreach ($nonWorkSlugs as $pauseSlug) {
        $pauseSeconds += $durationsAll[$pauseSlug] ?? 0;
    }

    $workSeconds = max(0, $totalSeconds - $pauseSeconds);

    $recordDate = $group['record_date'] ?? null;
    $username = $group['username'] ?? null;
    $overtimeSeconds = 0;

    if ($recordDate !== null) {
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
                    }
                }
            }
        }
    }

    $hourlyRate = isset($hourly_rates[$group['username']]) ? (float) $hourly_rates[$group['username']] : 0.0;

    $work_summary[] = [
        'full_name' => $group['full_name'],
        'username' => $group['username'],
        'record_date' => $group['record_date'],
        'durations' => $durationMap,
        'work_seconds' => $workSeconds,
        'overtime_seconds' => $overtimeSeconds,
        'total_payment' => round(($workSeconds / 3600) * $hourlyRate, 2),
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
                                    <a href="edit_record.php?id=<?= $record['id'] ?>" class="text-cyan-300 hover:text-cyan-100 transition-colors">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" action="delete_record.php" class="inline">
                                        <input type="hidden" name="id" value="<?= $record['id'] ?>">
                                        <button type="submit" class="text-rose-300 hover:text-rose-100 transition-colors" onclick="return confirm('&#191;Seguro que deseas eliminar este registro?');">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
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
            <span class="chip"><i class="fas fa-users-cog"></i> <?= number_format($workSummaryTotal) ?> registros</span>
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
                        <th>Horas extra</th>
                        <th>Pago (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($work_summary as $summary): ?>
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
                            <td>$<?= number_format($summary['total_payment'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($work_summary)): ?>
            <div class="data-table-empty">No hay datos de productividad para el periodo seleccionado.</div>
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
    ['#recordsTable', '#summaryTable'].forEach(function (selector) {
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
});
</script>

<?php include 'footer.php'; ?>

