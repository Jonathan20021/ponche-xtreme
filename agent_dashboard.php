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

$user_id = (int) $_SESSION['user_id'];
$username = $_SESSION['username'] ?? null;
$full_name = $_SESSION['full_name'] ?? null;

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

$date_filter = $_GET['dates'] ?? date('Y-m-d');

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

$chartLabels = [];
$chartData = [];
$chartColors = [];
$chartTotal = 0;

if ($workSeconds > 0) {
    $chartLabels[] = 'Productivo';
    $chartData[] = $workSeconds;
    $chartColors[] = '#38BDF8';
    $chartTotal += $workSeconds;
}

foreach ($durationTypes as $typeMeta) {
    $slug = $typeMeta['slug'];
    $value = $durations[$slug] ?? 0;
    if ($value <= 0) {
        continue;
    }
    $meta = $attendanceTypeMap[$slug] ?? null;
    $colorStart = sanitizeHexColorValue($meta['color_start'] ?? '#6366F1', '#6366F1');
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

    <section class="metric-grid">
        <?php foreach ($insightCards as $card): ?>
            <article class="metric-card" style="--metric-start: <?= htmlspecialchars($card['color_start'], ENT_QUOTES, 'UTF-8') ?>; --metric-end: <?= htmlspecialchars($card['color_end'], ENT_QUOTES, 'UTF-8') ?>;">
                <div class="metric-icon"><i class="<?= htmlspecialchars($card['icon']) ?>"></i></div>
                <p class="metric-label"><?= htmlspecialchars($card['label']) ?></p>
                <p class="metric-value"><?= htmlspecialchars($card['value']) ?></p>
                <p class="metric-sub"><?= htmlspecialchars($card['description']) ?></p>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="insight-grid">
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
<?php include 'footer.php'; ?>
