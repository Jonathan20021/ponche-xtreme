<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['AGENT', 'IT', 'Supervisor'], true)) {
    header('Location: login_agent.php');
    exit;
}

include 'db.php';

if (!function_exists('sanitizeHexColorValue')) {
    function sanitizeHexColorValue(?string $color, string $fallback = '#6366F1'): string
    {
        $value = strtoupper(trim((string) $color));
        return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : strtoupper($fallback);
    }
}

function summarizeEvents(array $events, array $nonWorkSlugs): array
{
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
    return [
        'durations' => $durations,
        'total_seconds' => $totalSeconds,
        'work_seconds' => max(0, $totalSeconds - $pauseSeconds),
    ];
}

$user_id = (int) $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'Agente';

$date_filter = $_GET['dates'] ?? date('Y-m-d');
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$attendanceTypes = getAttendanceTypes($pdo, false);
$attendanceTypeMap = [];
$durationTypes = [];
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
}

$nonWorkSlugs = [
    sanitizeAttendanceTypeSlug('BREAK'),
    sanitizeAttendanceTypeSlug('LUNCH'),
];

$dailyStmt = $pdo->prepare('SELECT type, timestamp, TIME(timestamp) AS record_time
    FROM attendance
    WHERE user_id = ? AND DATE(timestamp) = ?
    ORDER BY timestamp ASC');
$dailyStmt->execute([$user_id, $date_filter]);
$dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$dailyEvents = [];
$dailyRecords = [];
foreach ($dailyRows as $row) {
    $slug = sanitizeAttendanceTypeSlug($row['type'] ?? '');
    if ($slug === '') {
        continue;
    }
    $meta = $attendanceTypeMap[$slug] ?? null;
    $label = $meta['label'] ?? ($row['type'] ?? $slug);
    $colorStart = sanitizeHexColorValue($meta['color_start'] ?? '#38BDF8', '#38BDF8');
    $timestamp = strtotime($row['timestamp'] ?? '');
    if ($timestamp === false) {
        continue;
    }
    $dailyRecords[] = [
        'label' => $label,
        'time' => $row['record_time'],
        'color' => $colorStart,
    ];
    $dailyEvents[] = ['slug' => $slug, 'timestamp' => $timestamp];
}

$dailySummary = summarizeEvents($dailyEvents, $nonWorkSlugs);
$dailyDurations = [];
foreach ($durationTypes as $typeMeta) {
    $dailyDurations[$typeMeta['slug']] = $dailySummary['durations'][$typeMeta['slug']] ?? 0;
}
$dailyProductivity = $dailySummary['total_seconds'] > 0
    ? round(($dailySummary['work_seconds'] / $dailySummary['total_seconds']) * 100, 1)
    : 0;

$hourly_rates = getUserHourlyRates($pdo);
$hourly_rate = $hourly_rates[$username] ?? 0;
$dailyPayment = round(($dailySummary['work_seconds'] / 3600) * $hourly_rate, 2);

$tardinessStmt = $pdo->prepare("SELECT 
        COUNT(CASE WHEN UPPER(type) = 'ENTRY' AND TIME(timestamp) > '10:05:00' THEN 1 END) AS late_entries,
        COUNT(CASE WHEN UPPER(type) = 'LUNCH' AND TIME(timestamp) > '14:00:00' THEN 1 END) AS late_lunches,
        COUNT(CASE WHEN UPPER(type) = 'BREAK' AND TIME(timestamp) > '17:00:00' THEN 1 END) AS late_breaks,
        COUNT(*) AS total_entries
    FROM attendance
    WHERE user_id = ?");
$tardinessStmt->execute([$user_id]);
$tardinessData = $tardinessStmt->fetch(PDO::FETCH_ASSOC) ?: ['late_entries' => 0, 'late_lunches' => 0, 'late_breaks' => 0, 'total_entries' => 0];
$totalTardiness = (($tardinessData['late_entries'] ?? 0) + ($tardinessData['late_lunches'] ?? 0) + ($tardinessData['late_breaks'] ?? 0));
$totalTardiness = ($tardinessData['total_entries'] ?? 0) > 0
    ? round(($totalTardiness / $tardinessData['total_entries']) * 100, 2)
    : 0;

$rangeStmt = $pdo->prepare('SELECT type, timestamp, DATE(timestamp) AS work_date
    FROM attendance
    WHERE user_id = ? AND DATE(timestamp) BETWEEN ? AND ?
    ORDER BY timestamp ASC');
$rangeStmt->execute([$user_id, $start_date, $end_date]);
$rangeRows = $rangeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$grouped = [];
foreach ($rangeRows as $row) {
    $slug = sanitizeAttendanceTypeSlug($row['type'] ?? '');
    if ($slug === '') {
        continue;
    }
    $timestamp = strtotime($row['timestamp'] ?? '');
    if ($timestamp === false) {
        continue;
    }
    $grouped[$row['work_date']][] = ['slug' => $slug, 'timestamp' => $timestamp];
}

$accumulated_total = 0;
$rangeSummary = [];
foreach ($grouped as $workDate => $events) {
    $summary = summarizeEvents($events, $nonWorkSlugs);
    $hours = $summary['work_seconds'] / 3600;
    $payment = $hours * $hourly_rate;
    $accumulated_total += $payment;
    $rangeSummary[] = [
        'date' => $workDate,
        'hours' => $hours,
        'payment' => $payment,
    ];
}

$heroMetrics = [
    [
        'label' => 'Tardanza promedio',
        'value' => $totalTardiness . '%',
        'note' => ($tardinessData['total_entries'] ?? 0) > 0 ? ($tardinessData['total_entries'] . ' eventos evaluados') : 'Sin incidencias registradas',
    ],
    [
        'label' => 'Pago estimado (hoy)',
        'value' => '$' . number_format($dailyPayment, 2),
        'note' => number_format($dailySummary['work_seconds'] / 3600, 2) . ' hrs productivas',
    ],
    [
        'label' => 'Eventos del día',
        'value' => (string) count($dailyRecords),
        'note' => 'Marcaciones de asistencia',
    ],
];

$metricCards = [
    [
        'label' => 'Horas productivas',
        'value' => gmdate('H:i:s', $dailySummary['work_seconds']),
        'description' => 'Tiempo registrado para pago',
        'color_start' => '#34D399',
        'color_end' => '#10B981',
    ],
    [
        'label' => 'Productividad',
        'value' => $dailyProductivity . '%',
        'description' => 'Relación entre tiempos productivos y totales',
        'color_start' => '#A855F7',
        'color_end' => '#7C3AED',
    ],
];

foreach ($durationTypes as $typeMeta) {
    $slug = $typeMeta['slug'];
    $meta = $attendanceTypeMap[$slug] ?? null;
    $colorStart = sanitizeHexColorValue($meta['color_start'] ?? '#6366F1', '#6366F1');
    $colorEnd = sanitizeHexColorValue($meta['color_end'] ?? $colorStart, $colorStart);
    $metricCards[] = [
        'label' => $typeMeta['label'],
        'value' => gmdate('H:i:s', max(0, $dailyDurations[$slug] ?? 0)),
        'description' => 'Duración registrada',
        'color_start' => $colorStart,
        'color_end' => $colorEnd,
    ];
}

?>

<?php include 'header_agent.php'; ?>
<div class="agent-dashboard agent-records">
    <section class="dashboard-hero glass-card">
        <div class="hero-main">
            <div class="space-y-2">
                <span class="badge badge--info">Control personal</span>
                <h1 class="text-2xl font-semibold text-primary">Hola, <?= htmlspecialchars($full_name) ?></h1>
                <p class="text-muted text-sm">Gestiona tus marcaciones y revisa tu progreso</p>
            </div>
            <div class="hero-progress">
                <span class="text-sm text-muted uppercase tracking-[0.18em]">Tardanza</span>
                <div class="progress-circle" style="--progress: <?= min($totalTardiness, 100) ?>%;">
                    <span><?= $totalTardiness ?>%</span>
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
                <span>Detalle</span>
                <input type="date" name="dates" id="dates" value="<?= htmlspecialchars($date_filter) ?>" class="input-control">
            </label>
            <label>
                <span>Inicio</span>
                <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>" class="input-control">
            </label>
            <label>
                <span>Fin</span>
                <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>" class="input-control">
            </label>
            <button type="submit" class="btn-primary">Actualizar</button>
        </form>
    </section>

    <section class="metric-grid">
        <?php foreach ($metricCards as $card): ?>
            <article class="metric-card" style="--metric-start: <?= htmlspecialchars($card['color_start'], ENT_QUOTES, 'UTF-8') ?>; --metric-end: <?= htmlspecialchars($card['color_end'], ENT_QUOTES, 'UTF-8') ?>;">
                <p class="metric-label"><?= htmlspecialchars($card['label']) ?></p>
                <p class="metric-value"><?= htmlspecialchars($card['value']) ?></p>
                <p class="metric-sub"><?= htmlspecialchars($card['description']) ?></p>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="insight-grid">
        <article class="glass-card timeline-card">
            <header>
                <h2 class="text-lg font-semibold text-primary">Eventos del día</h2>
                <p class="text-sm text-muted">Registro cronológico de tus marcaciones.</p>
            </header>
            <?php if (!empty($dailyRecords)): ?>
                <ol class="timeline">
                    <?php foreach ($dailyRecords as $record): ?>
                        <li class="timeline-item">
                            <span class="timeline-dot" style="--dot-color: <?= htmlspecialchars($record['color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                            <div class="timeline-content">
                                <div class="timeline-title"><?= htmlspecialchars($record['label']) ?></div>
                                <div class="timeline-time"><?= htmlspecialchars($record['time']) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <p class="timeline-empty">Aún no has registrado eventos en la fecha seleccionada.</p>
            <?php endif; ?>
        </article>

        <article class="glass-card table-card">
            <header>
                <h2>Pagos acumulados</h2>
                <span>Total en el rango: $<?= number_format($accumulated_total, 2) ?></span>
            </header>
            <div class="responsive-scroll">
                <table class="data-table" data-skip-responsive="true">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Horas trabajadas</th>
                            <th>Pago estimado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($rangeSummary)): ?>
                            <?php foreach ($rangeSummary as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['date']) ?></td>
                                    <td><?= number_format($row['hours'], 2) ?> hrs</td>
                                    <td>$<?= number_format($row['payment'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="data-table-empty">Sin registros en el rango seleccionado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </div>
</div>

<script>
['dates','start_date','end_date'].forEach(function(id){
    const input = document.getElementById(id);
    input?.addEventListener('change', function(){ this.form.submit(); });
});
</script>
<?php include 'footer.php'; ?>
