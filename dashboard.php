<?php
session_start();
include 'db.php';

ensurePermission('dashboard');

/* ============================================================
   DATA QUERIES  (validated, division-by-zero guarded)
   ============================================================ */
$total_users         = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$entries_today       = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE type = 'Entry' AND DATE(timestamp) = CURDATE()")->fetchColumn();
$exits_today         = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE type = 'Exit' AND DATE(timestamp) = CURDATE()")->fetchColumn();
$active_users        = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE DATE(timestamp) = CURDATE()")->fetchColumn();
$total_records_today = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(timestamp) = CURDATE()")->fetchColumn();
$entries_week        = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE type = 'Entry' AND DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();
$entries_month       = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE type = 'Entry' AND YEAR(timestamp) = YEAR(CURDATE()) AND MONTH(timestamp) = MONTH(CURDATE())")->fetchColumn();
$active_users_week   = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();

$total_work_seconds_today = (int) $pdo->query("
    SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, a.timestamp, (
        SELECT MIN(b.timestamp) FROM attendance b
        WHERE b.user_id = a.user_id AND b.type = 'Exit' AND b.timestamp > a.timestamp
    ))), 0)
    FROM attendance a
    WHERE a.type = 'Entry' AND DATE(a.timestamp) = CURDATE()
")->fetchColumn();
$avg_hours_today = $active_users > 0 ? round(($total_work_seconds_today / 3600) / $active_users, 2) : 0;

$peak_entry_hour = $pdo->query("
    SELECT HOUR(timestamp) AS hour FROM attendance
    WHERE type = 'Entry' AND DATE(timestamp) = CURDATE()
    GROUP BY HOUR(timestamp) ORDER BY COUNT(*) DESC LIMIT 1
")->fetchColumn();

$late_entries_today = (int) $pdo->query("
    SELECT COUNT(*) FROM attendance
    WHERE type = 'Entry' AND DATE(timestamp) = CURDATE() AND TIME(timestamp) > '10:05:00'
")->fetchColumn();
$late_rate_today = $entries_today > 0 ? round(($late_entries_today / $entries_today) * 100, 1) : 0;
$ontime_today    = $entries_today > 0 ? round((($entries_today - $late_entries_today) / $entries_today) * 100, 1) : 0;

// Attendance / adherence today (% of active staff that registered)
$present_rate = $total_users > 0 ? min(100, round(($active_users / $total_users) * 100)) : 0;

// Real month-over-month for entries (replaces previously hardcoded "+12%")
$entries_last_month = (int) $pdo->query("
    SELECT COUNT(*) FROM attendance
    WHERE type = 'Entry'
      AND timestamp >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
      AND timestamp <  DATE_FORMAT(CURDATE(), '%Y-%m-01')
")->fetchColumn();
$entries_mom_pct = $entries_last_month > 0 ? round((($entries_month - $entries_last_month) / $entries_last_month) * 100, 1) : null;

// All-time entry tardiness rate (late entries / total entries)
$tardiness_entry_data = $pdo->query("
    SELECT COUNT(CASE WHEN TIME(timestamp) > '10:05:00' THEN 1 END) AS late_entries, COUNT(*) AS total_entries
    FROM attendance WHERE type = 'Entry'
")->fetch(PDO::FETCH_ASSOC);
$overall_tardiness_entry = ($tardiness_entry_data['total_entries'] ?? 0) > 0
    ? round(($tardiness_entry_data['late_entries'] / $tardiness_entry_data['total_entries']) * 100, 1) : 0;

/* ---- Chart datasets ---- */
$category_data = $pdo->query("SELECT type, COUNT(*) AS count FROM attendance GROUP BY type ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);

$work_time_data = $pdo->query("
    SELECT users.username, SUM(TIMESTAMPDIFF(SECOND, attendance.timestamp, (
        SELECT MIN(a.timestamp) FROM attendance a
        WHERE a.user_id = attendance.user_id AND a.timestamp > attendance.timestamp AND a.type = 'Exit'
    ))) AS total_time
    FROM attendance JOIN users ON attendance.user_id = users.id
    WHERE attendance.type = 'Entry'
    GROUP BY users.username
")->fetchAll(PDO::FETCH_ASSOC);
usort($work_time_data, fn($a, $b) => (int) ($b['total_time'] ?? 0) <=> (int) ($a['total_time'] ?? 0));
$wt_top    = array_slice($work_time_data, 0, 8);
$wt_labels = array_map(fn($r) => $r['username'], $wt_top);
$wt_hours  = array_map(fn($r) => round((int) ($r['total_time'] ?? 0) / 3600, 1), $wt_top);

$trend_rows = $pdo->query("
    SELECT DATE(timestamp) AS day, SUM(type = 'Entry') AS entries, SUM(type = 'Exit') AS exits
    FROM attendance WHERE DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY day ORDER BY day
")->fetchAll(PDO::FETCH_ASSOC);

$hour_rows = $pdo->query("
    SELECT HOUR(timestamp) AS hour, COUNT(*) AS count FROM attendance
    WHERE type = 'Entry' AND DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY hour ORDER BY hour
")->fetchAll(PDO::FETCH_ASSOC);

$top_tardy_users = $pdo->query("
    SELECT users.username, COUNT(*) AS late_entries
    FROM attendance JOIN users ON attendance.user_id = users.id
    WHERE attendance.type = 'Entry' AND attendance.timestamp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      AND TIME(attendance.timestamp) > '10:05:00'
    GROUP BY users.username ORDER BY late_entries DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$top_punctual_users = $pdo->query("
    SELECT users.username, SUM(CASE WHEN TIME(timestamp) <= '10:05:00' THEN 1 ELSE 0 END) AS on_time
    FROM attendance JOIN users ON attendance.user_id = users.id
    WHERE attendance.type = 'Entry' AND attendance.timestamp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY users.username ORDER BY on_time DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// 14-day trend series (fill gaps)
$trend_map = [];
foreach ($trend_rows as $row) {
    $trend_map[$row['day']] = ['entries' => (int) $row['entries'], 'exits' => (int) $row['exits']];
}
$trend_labels = $trend_entries = $trend_exits = [];
$cursor = new DateTime('today -13 days');
for ($i = 0; $i < 14; $i++) {
    $day = $cursor->format('Y-m-d');
    $trend_labels[]  = $cursor->format('d M');
    $trend_entries[] = $trend_map[$day]['entries'] ?? 0;
    $trend_exits[]   = $trend_map[$day]['exits'] ?? 0;
    $cursor->modify('+1 day');
}

// 24h entry distribution
$hour_map = [];
foreach ($hour_rows as $row) {
    $hour_map[(int) $row['hour']] = (int) $row['count'];
}
$hour_labels = $hour_counts = [];
for ($h = 6; $h <= 22; $h++) { // business-hours window for a cleaner chart
    $hour_labels[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00';
    $hour_counts[] = $hour_map[$h] ?? 0;
}
$peak_hour_idx = $hour_counts ? array_keys($hour_counts, max($hour_counts))[0] : -1;

/* ---- Tardiness breakdown by period (VALIDATED: late-of-type / total-of-type) ---- */
if (!function_exists('ev_tardiness_breakdown')) {
    function ev_tardiness_breakdown(PDO $pdo, string $dateCond): array
    {
        $stmt = $pdo->query("
            SELECT
                SUM(type = 'Entry')                                          AS tot_entry,
                SUM(type = 'Entry' AND TIME(timestamp) > '10:05:00')         AS le,
                SUM(type = 'Lunch')                                          AS tot_lunch,
                SUM(type = 'Lunch' AND TIME(timestamp) > '14:00:00')         AS ll,
                SUM(type = 'Break')                                          AS tot_break,
                SUM(type = 'Break' AND TIME(timestamp) > '17:00:00')         AS lb
            FROM attendance WHERE 1=1 " . $dateCond . "
        ");
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $pct = function ($num, $den) {
            $den = (int) $den;
            return $den > 0 ? round(((int) $num / $den) * 100, 1) : 0;
        };
        return [
            $pct($r['le'] ?? 0, $r['tot_entry'] ?? 0),
            $pct($r['ll'] ?? 0, $r['tot_lunch'] ?? 0),
            $pct($r['lb'] ?? 0, $r['tot_break'] ?? 0),
        ];
    }
}
$tardinessByPeriod = [
    'dia'    => ev_tardiness_breakdown($pdo, "AND DATE(timestamp) = CURDATE()"),
    'semana' => ev_tardiness_breakdown($pdo, "AND DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"),
    'mes'    => ev_tardiness_breakdown($pdo, "AND DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)"),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control · Evallish BPO</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 1.1rem; }
        .kpi-mini-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; }
        .charts-grid { display: grid; gap: 1.25rem; }
        @media (min-width: 1100px) {
            .charts-2col { grid-template-columns: 1.6fr 1fr; }
            .charts-2col-even { grid-template-columns: 1fr 1fr; }
        }
        .kpi {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm); padding: 1.35rem 1.5rem;
            transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
        }
        .kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--border-strong); }
        .kpi-icon { width: 46px; height: 46px; border-radius: 13px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .kpi-label { color: var(--text-muted); font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
        .kpi-value { color: var(--heading); font-size: 2.1rem; font-weight: 800; letter-spacing: -.02em; line-height: 1.1; font-variant-numeric: tabular-nums; font-family: var(--font-display); }
        .kpi-sub { color: var(--text-subtle); font-size: .8rem; margin-top: .25rem; }
        .kpi-mini { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-xs); padding: 1rem 1.1rem; transition: transform .16s ease, box-shadow .16s ease; }
        .kpi-mini:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }
        .kpi-mini .v { font-size: 1.55rem; font-weight: 800; color: var(--heading); font-variant-numeric: tabular-nums; font-family: var(--font-display); }
        .chart-box { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); padding: 1.4rem 1.5rem; }
        .chart-box h3 { font-size: 1.05rem; font-weight: 700; color: var(--heading); margin: 0; }
        .chart-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.1rem; flex-wrap: wrap; }
        .rank-row { display: flex; align-items: center; justify-content: space-between; padding: .6rem 0; border-bottom: 1px solid var(--border); }
        .rank-row:last-child { border-bottom: none; }
        .rank-badge { width: 26px; height: 26px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: .78rem; font-weight: 700; }
        .trend-pill { display: inline-flex; align-items: center; gap: .3rem; font-size: .8rem; font-weight: 700; padding: .15rem .5rem; border-radius: 999px; }
        .animate-spin { animation: ev-spin 1s linear infinite; }
        @keyframes ev-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body class="theme-light bg-gray-100">
    <?php include 'header.php'; ?>

    <div class="container mx-auto px-4 py-6" style="max-width: 1500px;">

        <!-- HERO -->
        <section class="page-hero" style="margin-bottom: 1.5rem;">
            <div class="page-hero-content" style="flex-direction: row; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1.25rem;">
                <div>
                    <span class="tag-pill" style="background: rgba(255,255,255,.16); color: #fff; border-color: rgba(255,255,255,.28);">
                        <span class="live-dot" style="width:8px;height:8px;border-radius:50%;background:#5fe0a8;display:inline-block;box-shadow:0 0 0 0 rgba(95,224,168,.7);animation:ev-pulse 2s infinite;"></span>
                        Panel en vivo
                    </span>
                    <h1 style="font-size: clamp(1.7rem,3vw,2.2rem); font-weight: 800; color: #fff; letter-spacing: -.02em; margin: .55rem 0 .3rem;">Panel de Control · Evallish BPO</h1>
                    <p class="page-meta">Operación de fuerza laboral en tiempo real · <span id="lastUpdate"></span></p>
                </div>
                <div style="display:flex; flex-direction:column; gap:.75rem; align-items:flex-end;">
                    <button id="refreshData" type="button" style="background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.32); color: #fff; backdrop-filter: blur(6px); padding: .7rem 1.3rem; border-radius: var(--radius-sm); font-weight: 600; display: inline-flex; align-items: center; gap: .55rem; cursor: pointer;">
                        <i class="fas fa-sync-alt"></i> Actualizar Datos
                    </button>
                    <div class="hero-metric-grid" style="margin: 0; grid-template-columns: repeat(3, minmax(96px,1fr));">
                        <div class="hero-metric" style="padding: .7rem .9rem;"><strong style="font-size:1.35rem;"><?= $active_users ?></strong><span>En operación</span></div>
                        <div class="hero-metric" style="padding: .7rem .9rem;"><strong style="font-size:1.35rem;"><?= $total_records_today ?></strong><span>Marcajes hoy</span></div>
                        <div class="hero-metric" style="padding: .7rem .9rem;"><strong style="font-size:1.35rem;"><?= $ontime_today ?>%</strong><span>Puntualidad</span></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- PRIMARY KPIs -->
        <div class="kpi-grid" style="margin-bottom: 1.25rem;">
            <div class="kpi">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="kpi-label">Agentes Activos Hoy</p>
                        <h3 class="kpi-value"><?= $active_users ?></h3>
                    </div>
                    <span class="kpi-icon" style="background: var(--navy-100); color: var(--brand);"><i class="fas fa-user-check"></i></span>
                </div>
                <div class="hero-progress" style="background: var(--surface-3); margin-top: .9rem;"><span style="width: <?= $present_rate ?>%; background: linear-gradient(90deg, var(--brand-bright), var(--brand));"></span></div>
                <p class="kpi-sub"><?= $present_rate ?>% de <?= $total_users ?> usuarios activos</p>
            </div>

            <div class="kpi">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="kpi-label">Marcajes Hoy</p>
                        <h3 class="kpi-value"><?= number_format($total_records_today) ?></h3>
                    </div>
                    <span class="kpi-icon" style="background: var(--navy-100); color: var(--brand);"><i class="fas fa-fingerprint"></i></span>
                </div>
                <p class="kpi-sub"><i class="fas fa-door-open text-green-500"></i> <?= $entries_today ?> entradas · <i class="fas fa-door-closed" style="color:var(--text-subtle);"></i> <?= $exits_today ?> salidas</p>
            </div>

            <div class="kpi">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="kpi-label">Promedio Horas Hoy</p>
                        <h3 class="kpi-value"><?= $avg_hours_today ?><span style="font-size:1.1rem;color:var(--text-muted);font-weight:700;">h</span></h3>
                    </div>
                    <span class="kpi-icon" style="background: var(--navy-100); color: var(--brand);"><i class="fas fa-business-time"></i></span>
                </div>
                <p class="kpi-sub">Por agente activo en el día</p>
            </div>

            <div class="kpi">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="kpi-label">Puntualidad Hoy</p>
                        <h3 class="kpi-value" style="color: <?= $ontime_today >= 80 ? 'var(--success)' : ($ontime_today >= 60 ? 'var(--warning)' : 'var(--danger)') ?>;"><?= $ontime_today ?>%</h3>
                    </div>
                    <div style="width:64px;height:64px;flex-shrink:0;"><div id="ontimeGauge"></div></div>
                </div>
                <p class="kpi-sub"><?= $late_entries_today ?> tardías de <?= $entries_today ?> entradas</p>
            </div>
        </div>

        <!-- SECONDARY KPIs -->
        <div class="kpi-mini-grid" style="margin-bottom: 1.5rem;">
            <div class="kpi-mini"><p class="kpi-label">Total Usuarios</p><div class="v"><?= number_format($total_users) ?></div><p class="kpi-sub">Cuentas activas</p></div>
            <div class="kpi-mini"><p class="kpi-label">Entradas (7 días)</p><div class="v"><?= number_format($entries_week) ?></div><p class="kpi-sub">Últimos 7 días</p></div>
            <div class="kpi-mini">
                <p class="kpi-label">Entradas (Mes)</p><div class="v"><?= number_format($entries_month) ?></div>
                <p class="kpi-sub">
                    <?php if ($entries_mom_pct === null): ?>
                        Mes actual
                    <?php else: ?>
                        <span class="trend-pill" style="background: <?= $entries_mom_pct >= 0 ? 'var(--success-bg)' : 'var(--danger-bg)' ?>; color: <?= $entries_mom_pct >= 0 ? 'var(--success-strong)' : 'var(--danger-strong)' ?>;">
                            <i class="fas fa-arrow-<?= $entries_mom_pct >= 0 ? 'up' : 'down' ?>"></i> <?= abs($entries_mom_pct) ?>%
                        </span> vs mes anterior
                    <?php endif; ?>
                </p>
            </div>
            <div class="kpi-mini"><p class="kpi-label">Activos (7 días)</p><div class="v"><?= number_format($active_users_week) ?></div><p class="kpi-sub">Usuarios únicos</p></div>
            <div class="kpi-mini"><p class="kpi-label">Pico de Entrada</p><div class="v"><?= ($peak_entry_hour !== false && $peak_entry_hour !== null) ? str_pad((string)$peak_entry_hour, 2, '0', STR_PAD_LEFT) . ':00' : '—' ?></div><p class="kpi-sub">Hora más concurrida</p></div>
            <div class="kpi-mini"><p class="kpi-label">Tardanza (histórico)</p><div class="v" style="color: <?= $overall_tardiness_entry > 40 ? 'var(--danger)' : ($overall_tardiness_entry > 20 ? 'var(--warning)' : 'var(--success)') ?>;"><?= $overall_tardiness_entry ?>%</div><p class="kpi-sub">Entradas tardías / total</p></div>
        </div>

        <!-- CHARTS: trend + distribution -->
        <div class="charts-grid charts-2col" style="margin-bottom: 1.25rem;">
            <div class="chart-box">
                <div class="chart-head">
                    <h3><i class="fas fa-chart-area" style="color:var(--brand);margin-right:.5rem;"></i>Tendencia de Asistencia · 14 días</h3>
                    <div class="pill-switch" data-trend-toggle>
                        <button type="button" class="pill-option is-active" data-fill="area">Área</button>
                        <button type="button" class="pill-option" data-fill="line">Línea</button>
                    </div>
                </div>
                <div style="height: 320px; position: relative;"><canvas id="trendChart"></canvas></div>
            </div>
            <div class="chart-box">
                <div class="chart-head"><h3><i class="fas fa-chart-pie" style="color:var(--brand);margin-right:.5rem;"></i>Distribución por Tipo</h3></div>
                <div style="height: 320px; position: relative;"><canvas id="categoryChart"></canvas></div>
            </div>
        </div>

        <!-- CHARTS: hourly + tardiness -->
        <div class="charts-grid charts-2col" style="margin-bottom: 1.25rem;">
            <div class="chart-box">
                <div class="chart-head">
                    <h3><i class="fas fa-clock" style="color:var(--brand);margin-right:.5rem;"></i>Entradas por Hora</h3>
                    <span class="chip"><i class="fas fa-bolt"></i> Pico: <?= ($peak_entry_hour !== false && $peak_entry_hour !== null) ? str_pad((string)$peak_entry_hour, 2, '0', STR_PAD_LEFT) . ':00' : 'N/A' ?></span>
                </div>
                <div style="height: 300px; position: relative;"><canvas id="hourChart"></canvas></div>
            </div>
            <div class="chart-box">
                <div class="chart-head">
                    <h3><i class="fas fa-triangle-exclamation" style="color:var(--brand);margin-right:.5rem;"></i>Tardanzas por Categoría</h3>
                    <div class="pill-switch" data-tardiness-period>
                        <button type="button" class="pill-option is-active" data-period="dia">Diario</button>
                        <button type="button" class="pill-option" data-period="semana">Semanal</button>
                        <button type="button" class="pill-option" data-period="mes">Mensual</button>
                    </div>
                </div>
                <div style="height: 300px; position: relative;"><canvas id="tardinessChart"></canvas></div>
            </div>
        </div>

        <!-- CHARTS: work-time + rankings -->
        <div class="charts-grid charts-2col" style="margin-bottom: 1.25rem;">
            <div class="chart-box">
                <div class="chart-head"><h3><i class="fas fa-ranking-star" style="color:var(--brand);margin-right:.5rem;"></i>Horas Trabajadas · Top Agentes</h3></div>
                <div style="height: 340px; position: relative;"><canvas id="workTimeChart"></canvas></div>
            </div>
            <div class="chart-box">
                <div class="chart-head"><h3><i class="fas fa-award" style="color:var(--brand);margin-right:.5rem;"></i>Rankings · 30 días</h3></div>
                <p class="kpi-label" style="margin-bottom:.3rem;"><i class="fas fa-circle text-red-500" style="font-size:.5rem;vertical-align:middle;"></i> Más tardanzas</p>
                <div style="margin-bottom: 1.1rem;">
                    <?php if ($top_tardy_users): foreach ($top_tardy_users as $i => $row): ?>
                        <div class="rank-row">
                            <span class="flex items-center gap-2"><span class="rank-badge" style="background:var(--danger-bg);color:var(--danger-strong);"><?= $i + 1 ?></span><span style="color:var(--text);"><?= htmlspecialchars($row['username']) ?></span></span>
                            <span class="badge badge--danger"><?= (int) $row['late_entries'] ?> tardanzas</span>
                        </div>
                    <?php endforeach; else: ?><p class="kpi-sub">Sin datos recientes.</p><?php endif; ?>
                </div>
                <p class="kpi-label" style="margin-bottom:.3rem;"><i class="fas fa-circle text-green-500" style="font-size:.5rem;vertical-align:middle;"></i> Más puntuales</p>
                <div>
                    <?php if ($top_punctual_users): foreach ($top_punctual_users as $i => $row): ?>
                        <div class="rank-row">
                            <span class="flex items-center gap-2"><span class="rank-badge" style="background:var(--success-bg);color:var(--success-strong);"><?= $i + 1 ?></span><span style="color:var(--text);"><?= htmlspecialchars($row['username']) ?></span></span>
                            <span class="badge badge--success"><?= (int) $row['on_time'] ?> a tiempo</span>
                        </div>
                    <?php endforeach; else: ?><p class="kpi-sub">Sin datos recientes.</p><?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <style>@keyframes ev-pulse { 0% { box-shadow: 0 0 0 0 rgba(95,224,168,.7); } 70% { box-shadow: 0 0 0 8px rgba(95,224,168,0); } 100% { box-shadow: 0 0 0 0 rgba(95,224,168,0); } }</style>

    <script>
        // ---- Live timestamp (native, no moment.js) ----
        function evUpdateClock() {
            var el = document.getElementById('lastUpdate');
            if (el) el.textContent = new Date().toLocaleString('es-DO', { dateStyle: 'medium', timeStyle: 'medium' });
        }
        evUpdateClock();
        setInterval(evUpdateClock, 1000);

        document.getElementById('refreshData').addEventListener('click', function () {
            this.classList.add('animate-spin');
            setTimeout(function () { location.reload(); }, 700);
        });

        // ---- Data from PHP ----
        const E = window.EvallishCharts || { palette: ['#264b8b', '#3a5da0', '#6f8bbd', '#9db1d2', '#c3d0e6'], brand: '#264b8b', gradient: null, fade: function (h, a) { return h; } };
        const categoryLabels = <?= json_encode(array_column($category_data, 'type')) ?>;
        const categoryCounts = <?= json_encode(array_map('intval', array_column($category_data, 'count'))) ?>;
        const trendLabels  = <?= json_encode($trend_labels) ?>;
        const trendEntries = <?= json_encode($trend_entries) ?>;
        const trendExits   = <?= json_encode($trend_exits) ?>;
        const hourLabels   = <?= json_encode($hour_labels) ?>;
        const hourCounts   = <?= json_encode($hour_counts) ?>;
        const peakHourIdx  = <?= (int) $peak_hour_idx ?>;
        const wtLabels     = <?= json_encode($wt_labels) ?>;
        const wtHours      = <?= json_encode(array_map('floatval', $wt_hours)) ?>;
        const tardinessByPeriod = <?= json_encode($tardinessByPeriod) ?>;
        const tardinessLabels = ['Entradas', 'Almuerzos', 'Descansos'];

        function grad(ctx, h, c1, c2) { var g = ctx.createLinearGradient(0, 0, 0, h); g.addColorStop(0, c1); g.addColorStop(1, c2); return g; }

        // ---- Trend (gradient area, entries vs exits) ----
        const trCtx = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(trCtx, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    { label: 'Entradas', data: trendEntries, borderColor: '#16895c', backgroundColor: grad(trCtx, 320, 'rgba(22,137,92,.30)', 'rgba(22,137,92,.01)'), fill: true, tension: .4, pointBackgroundColor: '#16895c' },
                    { label: 'Salidas', data: trendExits, borderColor: '#264b8b', backgroundColor: grad(trCtx, 320, 'rgba(38,75,139,.30)', 'rgba(38,75,139,.01)'), fill: true, tension: .4, pointBackgroundColor: '#264b8b' }
                ]
            },
            options: { plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true } } }
        });
        document.querySelectorAll('[data-trend-toggle] .pill-option').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-trend-toggle] .pill-option').forEach(b => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                var fill = btn.getAttribute('data-fill') === 'area';
                trendChart.data.datasets.forEach(d => d.fill = fill);
                trendChart.update();
            });
        });

        // ---- Distribution doughnut ----
        new Chart(document.getElementById('categoryChart').getContext('2d'), {
            type: 'doughnut',
            data: { labels: categoryLabels, datasets: [{ data: categoryCounts, backgroundColor: E.palette, borderWidth: 2, borderColor: '#ffffff' }] },
            options: { cutout: '62%', plugins: { legend: { position: 'right' } } }
        });

        // ---- Entradas por hora (peak highlighted) ----
        const hCtx = document.getElementById('hourChart').getContext('2d');
        new Chart(hCtx, {
            type: 'bar',
            data: { labels: hourLabels, datasets: [{ label: 'Entradas', data: hourCounts, backgroundColor: hourCounts.map((_, i) => i === peakHourIdx ? '#264b8b' : 'rgba(58,93,160,.45)'), hoverBackgroundColor: '#1f3f76', borderRadius: 6, maxBarThickness: 26 }] },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } }
        });

        // ---- Tardiness by period (interactive, validated %) ----
        const tCtx = document.getElementById('tardinessChart').getContext('2d');
        const tardinessChart = new Chart(tCtx, {
            type: 'bar',
            data: { labels: tardinessLabels, datasets: [{ label: '% tardías', data: tardinessByPeriod['dia'], backgroundColor: grad(tCtx, 300, 'rgba(58,93,160,.95)', 'rgba(38,75,139,.45)'), hoverBackgroundColor: '#1f3f76', borderRadius: 10, maxBarThickness: 80 }] },
            options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + c.parsed.y + '% tardías' } } }, scales: { y: { beginAtZero: true, suggestedMax: 100, ticks: { callback: v => v + '%' } }, x: { grid: { display: false } } } }
        });
        document.querySelectorAll('[data-tardiness-period] .pill-option').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-tardiness-period] .pill-option').forEach(b => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                var p = btn.getAttribute('data-period');
                if (tardinessByPeriod[p]) { tardinessChart.data.datasets[0].data = tardinessByPeriod[p]; tardinessChart.update(); }
            });
        });

        // ---- Horas trabajadas top agentes (horizontal bar) ----
        if (wtLabels.length) {
            const wCtx = document.getElementById('workTimeChart').getContext('2d');
            new Chart(wCtx, {
                type: 'bar',
                data: { labels: wtLabels, datasets: [{ label: 'Horas', data: wtHours, backgroundColor: (function () { var w = wCtx.canvas.width || 600; var g = wCtx.createLinearGradient(0, 0, w, 0); g.addColorStop(0, '#264b8b'); g.addColorStop(1, '#6f8bbd'); return g; })(), hoverBackgroundColor: '#1f3f76', borderRadius: 6, maxBarThickness: 22 }] },
                options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + c.parsed.x + ' h' } } }, scales: { x: { beginAtZero: true }, y: { grid: { display: false } } } }
            });
        }

        // ---- Puntualidad gauge (ApexCharts radial) ----
        if (window.ApexCharts) {
            var gaugeColor = <?= json_encode($ontime_today >= 80 ? '#16895c' : ($ontime_today >= 60 ? '#b07614' : '#cf3a35')) ?>;
            new ApexCharts(document.getElementById('ontimeGauge'), {
                series: [<?= $ontime_today ?>], chart: { type: 'radialBar', height: 64, width: 64, sparkline: { enabled: true } },
                colors: [gaugeColor],
                plotOptions: { radialBar: { hollow: { size: '52%' }, track: { background: 'rgba(38,75,139,.12)' }, dataLabels: { show: false } } },
                stroke: { lineCap: 'round' }
            }).render();
        }
    </script>
</body>
</html>
