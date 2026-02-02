<?php
session_start();
include 'db.php';

ensurePermission('dashboard');

// Consulta para estadisticas generales
$total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$entries_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE type = 'Entry' AND DATE(timestamp) = CURDATE()")->fetchColumn();
$exits_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE type = 'Exit' AND DATE(timestamp) = CURDATE()")->fetchColumn();
$active_users = $pdo->query("
    SELECT COUNT(DISTINCT user_id) 
    FROM attendance 
    WHERE DATE(timestamp) = CURDATE()
")->fetchColumn();

$total_records_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(timestamp) = CURDATE()")->fetchColumn();
$entries_week = $pdo->query("SELECT COUNT(*) FROM attendance WHERE type = 'Entry' AND DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();
$entries_month = $pdo->query("SELECT COUNT(*) FROM attendance WHERE type = 'Entry' AND YEAR(timestamp) = YEAR(CURDATE()) AND MONTH(timestamp) = MONTH(CURDATE())")->fetchColumn();
$active_users_week = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();

$total_work_seconds_today = $pdo->query("
    SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, a.timestamp, (
        SELECT MIN(b.timestamp)
        FROM attendance b
        WHERE b.user_id = a.user_id
          AND b.type = 'Exit'
          AND b.timestamp > a.timestamp
    ))), 0)
    FROM attendance a
    WHERE a.type = 'Entry'
      AND DATE(a.timestamp) = CURDATE()
")->fetchColumn();

$avg_hours_today = 0;
if ($active_users > 0) {
    $avg_hours_today = round(($total_work_seconds_today / 3600) / $active_users, 2);
}

$peak_entry_hour = $pdo->query("
    SELECT HOUR(timestamp) as hour
    FROM attendance
    WHERE type = 'Entry'
      AND DATE(timestamp) = CURDATE()
    GROUP BY HOUR(timestamp)
    ORDER BY COUNT(*) DESC
    LIMIT 1
")->fetchColumn();

$late_entries_today = $pdo->query("
    SELECT COUNT(*)
    FROM attendance
    WHERE type = 'Entry'
      AND DATE(timestamp) = CURDATE()
      AND TIME(timestamp) > '10:05:00'
")->fetchColumn();

$late_rate_today = 0;
if ($entries_today > 0) {
    $late_rate_today = round(($late_entries_today / $entries_today) * 100, 2);
}

// Datos para grÃƒÆ’Ã†a€™Ãƒa€šÃ‚a¡ficos
$category_data = $pdo->query("
    SELECT type, COUNT(*) as count 
    FROM attendance 
    GROUP BY type
")->fetchAll(PDO::FETCH_ASSOC);

$work_time_data = $pdo->query("
    SELECT users.username, SUM(TIMESTAMPDIFF(SECOND, attendance.timestamp, (
        SELECT MIN(a.timestamp) 
        FROM attendance a 
        WHERE a.user_id = attendance.user_id 
        AND a.timestamp > attendance.timestamp 
        AND a.type = 'Exit'
    ))) AS total_time
    FROM attendance
    JOIN users ON attendance.user_id = users.id
    WHERE attendance.type = 'Entry'
    GROUP BY users.username
")->fetchAll(PDO::FETCH_ASSOC);

$trend_rows = $pdo->query("
        SELECT DATE(timestamp) AS day,
                     SUM(type = 'Entry') AS entries,
                     SUM(type = 'Exit') AS exits
        FROM attendance
        WHERE DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY day
        ORDER BY day
")->fetchAll(PDO::FETCH_ASSOC);

$hour_rows = $pdo->query("
        SELECT HOUR(timestamp) AS hour, COUNT(*) AS count
        FROM attendance
        WHERE type = 'Entry'
            AND DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY hour
        ORDER BY hour
")->fetchAll(PDO::FETCH_ASSOC);

$top_tardy_users = $pdo->query("
        SELECT users.username, COUNT(*) AS late_entries
        FROM attendance
        JOIN users ON attendance.user_id = users.id
        WHERE attendance.type = 'Entry'
            AND attendance.timestamp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND TIME(attendance.timestamp) > '10:05:00'
        GROUP BY users.username
        ORDER BY late_entries DESC
        LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$top_punctual_users = $pdo->query("
        SELECT users.username, SUM(CASE WHEN TIME(timestamp) <= '10:05:00' THEN 1 ELSE 0 END) AS on_time
        FROM attendance
        JOIN users ON attendance.user_id = users.id
        WHERE attendance.type = 'Entry'
            AND attendance.timestamp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY users.username
        ORDER BY on_time DESC
        LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// CÃƒÆ’Ã†a€™Ãƒa€šÃ‚a¡lculo del porcentaje total de tardanzas generales
$tardiness_data = $pdo->query("
    SELECT 
        COUNT(CASE WHEN type = 'Entry' AND TIME(timestamp) > '10:05:00' THEN 1 END) AS late_entries,
        COUNT(CASE WHEN type = 'Lunch' AND TIME(timestamp) > '14:00:00' THEN 1 END) AS late_lunches,
        COUNT(CASE WHEN type = 'Break' AND TIME(timestamp) > '17:00:00' THEN 1 END) AS late_breaks,
        COUNT(*) AS total_entries
    FROM attendance
")->fetch(PDO::FETCH_ASSOC);

// Porcentaje total de tardanzas generales
$total_tardiness = 0;
$late_entries_percent = 0;
$late_lunches_percent = 0;
$late_breaks_percent = 0;

if ($tardiness_data['total_entries'] > 0) {
    $total_tardiness = round(
        (($tardiness_data['late_entries'] + $tardiness_data['late_lunches'] + $tardiness_data['late_breaks']) 
        / $tardiness_data['total_entries']) * 100, 2
    );

    $late_entries_percent = round(($tardiness_data['late_entries'] / $tardiness_data['total_entries']) * 100, 2);
    $late_lunches_percent = round(($tardiness_data['late_lunches'] / $tardiness_data['total_entries']) * 100, 2);
    $late_breaks_percent = round(($tardiness_data['late_breaks'] / $tardiness_data['total_entries']) * 100, 2);
}

// CÃƒÆ’Ã†a€™Ãƒa€šÃ‚a¡lculo del porcentaje de tardanzas solo para Entry
$tardiness_entry_data = $pdo->query("
    SELECT 
        COUNT(CASE WHEN TIME(timestamp) > '10:05:00' THEN 1 END) AS late_entries,
        COUNT(*) AS total_entries
    FROM attendance
    WHERE type = 'Entry'
")->fetch(PDO::FETCH_ASSOC);

$overall_tardiness_entry = 0;

if ($tardiness_entry_data['total_entries'] > 0) {
    $overall_tardiness_entry = round(($tardiness_entry_data['late_entries'] / $tardiness_entry_data['total_entries']) * 100, 2);
}

$trend_map = [];
foreach ($trend_rows as $row) {
    $trend_map[$row['day']] = [
        'entries' => (int)$row['entries'],
        'exits' => (int)$row['exits']
    ];
}

$trend_labels = [];
$trend_entries = [];
$trend_exits = [];
$start = new DateTime('today -13 days');
for ($i = 0; $i < 14; $i++) {
    $day = $start->format('Y-m-d');
    $trend_labels[] = $start->format('M d');
    $trend_entries[] = $trend_map[$day]['entries'] ?? 0;
    $trend_exits[] = $trend_map[$day]['exits'] ?? 0;
    $start->modify('+1 day');
}

$hour_map = [];
foreach ($hour_rows as $row) {
    $hour_map[(int)$row['hour']] = (int)$row['count'];
}

$hour_labels = [];
$hour_counts = [];
for ($h = 0; $h < 24; $h++) {
    $hour_labels[] = str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00';
    $hour_counts[] = $hour_map[$h] ?? 0;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control Evallish BPO</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
</head>
<body class="bg-gray-100">
    <?php include 'header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Dashboard Header -->
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Panel de Control Evallish BPO</h1>
                <p class="text-gray-600">Última actualización: <span id="lastUpdate"></span></p>
            </div>
            <button id="refreshData" class="w-full sm:w-auto bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center justify-center gap-2">
                <i class="fas fa-sync-alt mr-2"></i> Actualizar Datos
            </button>
        </div>

        <!-- Main Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Total de Usuarios</p>
                        <h3 class="text-4xl font-bold text-gray-800"><?= $total_users ?></h3>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-users text-blue-500 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center">
                        <span class="text-green-500"><i class="fas fa-arrow-up"></i> 12%</span>
                        <span class="text-gray-400 text-sm ml-2">vs mes anterior</span>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Entradas Hoy</p>
                        <h3 class="text-4xl font-bold text-gray-800"><?= $entries_today ?></h3>
                    </div>
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-door-open text-green-500 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div id="entriesChart"></div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Usuarios Activos</p>
                        <h3 class="text-4xl font-bold text-gray-800"><?= $active_users ?></h3>
                    </div>
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-user-clock text-purple-500 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-purple-500 rounded-full h-2" style="width: <?= ($active_users/$total_users)*100 ?>%"></div>
                    </div>
                    <p class="text-sm text-gray-500 mt-2"><?= round(($active_users/$total_users)*100) ?>% del total de usuarios</p>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Tasa de Tardanza</p>
                        <h3 class="text-4xl font-bold <?= $overall_tardiness_entry > 50 ? 'text-red-500' : ($overall_tardiness_entry > 25 ? 'text-yellow-500' : 'text-green-500') ?>">
                            <?= $overall_tardiness_entry ?>%
                        </h3>
                    </div>
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-clock text-red-500 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div id="tardinessGauge"></div>
                </div>
            </div>
        </div>

        <!-- Additional KPI Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Registros Hoy</p>
                        <h3 class="text-3xl font-bold text-gray-800"><?= $total_records_today ?></h3>
                    </div>
                    <div class="bg-indigo-100 rounded-full p-3">
                        <i class="fas fa-clipboard-list text-indigo-500 text-lg"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Entradas + Salidas + Breaks</p>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Entradas (7 días)</p>
                        <h3 class="text-3xl font-bold text-gray-800"><?= $entries_week ?></h3>
                    </div>
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-calendar-week text-green-500 text-lg"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Últimos 7 días</p>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Entradas (Mes)</p>
                        <h3 class="text-3xl font-bold text-gray-800"><?= $entries_month ?></h3>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-calendar-alt text-blue-500 text-lg"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Mes actual</p>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Activos (7 días)</p>
                        <h3 class="text-3xl font-bold text-gray-800"><?= $active_users_week ?></h3>
                    </div>
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-user-friends text-purple-500 text-lg"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Usuarios únicos</p>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Prom. Horas Hoy</p>
                        <h3 class="text-3xl font-bold text-gray-800"><?= $avg_hours_today ?></h3>
                    </div>
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-hourglass-half text-yellow-500 text-lg"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Por usuario activo</p>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Tardanza Hoy</p>
                        <h3 class="text-3xl font-bold <?= $late_rate_today > 50 ? 'text-red-500' : ($late_rate_today > 25 ? 'text-yellow-500' : 'text-green-500') ?>"><?= $late_rate_today ?>%</h3>
                    </div>
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-exclamation-triangle text-red-500 text-lg"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Entradas tardías hoy</p>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Attendance Distribution -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Distribución de Asistencia</h3>
                <div style="height: 400px; position: relative;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <!-- Work Time Analysis -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Análisis de Tiempo de Trabajo</h3>
                <div style="height: 400px; position: relative;">
                    <canvas id="workTimeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Advanced Analytics -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">Tendencia 14 Días</h3>
                    <div class="text-xs text-gray-400">Entradas vs Salidas</div>
                </div>
                <div style="height: 360px; position: relative;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">Distribución de Entradas por Hora</h3>
                    <div class="text-xs text-gray-400">Últimos 7 días</div>
                </div>
                <div style="height: 360px; position: relative;">
                    <canvas id="entryHourChart"></canvas>
                </div>
                <div class="mt-3 text-sm text-gray-500">
                    Pico de entrada: <span class="font-semibold text-gray-700"><?= $peak_entry_hour !== false && $peak_entry_hour !== null ? str_pad($peak_entry_hour, 2, '0', STR_PAD_LEFT) . ':00' : 'N/A' ?></span>
                </div>
            </div>
        </div>

        <!-- Tardiness Breakdown -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-4">
                <h3 class="text-xl font-semibold text-gray-800">Desglose de Tardanzas</h3>
                <div class="flex flex-wrap gap-2">
                    <button class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300 text-sm">Diario</button>
                    <button class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300 text-sm">Semanal</button>
                    <button class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300 text-sm">Mensual</button>
                </div>
            </div>
            <div style="height: 300px; position: relative;">
                <canvas id="tardinessChart"></canvas>
            </div>
        </div>

        <!-- Rankings -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Top Tardanzas (30 días)</h3>
                <div class="space-y-3">
                    <?php if (count($top_tardy_users) > 0): ?>
                        <?php foreach ($top_tardy_users as $row): ?>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-2.5 h-2.5 rounded-full bg-red-500"></div>
                                    <span class="text-gray-700"><?= htmlspecialchars($row['username']) ?></span>
                                </div>
                                <span class="text-sm text-gray-500"><?= (int)$row['late_entries'] ?> tardanzas</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-gray-500 text-sm">Sin datos recientes.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Top Puntuales (30 días)</h3>
                <div class="space-y-3">
                    <?php if (count($top_punctual_users) > 0): ?>
                        <?php foreach ($top_punctual_users as $row): ?>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-2.5 h-2.5 rounded-full bg-green-500"></div>
                                    <span class="text-gray-700"><?= htmlspecialchars($row['username']) ?></span>
                                </div>
                                <span class="text-sm text-gray-500"><?= (int)$row['on_time'] ?> a tiempo</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-gray-500 text-sm">Sin datos recientes.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <style>
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .animate-spin {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        canvas {
            max-width: 100%;
            max-height: 100%;
        }

        #categoryChart, #workTimeChart, #tardinessChart {
            width: 100% !important;
            height: 100% !important;
        }
    </style>

    <script>
        // Update timestamp
        function updateTimestamp() {
            document.getElementById('lastUpdate').textContent = moment().format('MMMM D, YYYY HH:mm:ss');
        }
        updateTimestamp();
        setInterval(updateTimestamp, 1000);

        // Existing chart data
        const categoryLabels = <?= json_encode(array_column($category_data, 'type')) ?>;
        const categoryCounts = <?= json_encode(array_column($category_data, 'count')) ?>;
        const workTimeLabels = <?= json_encode(array_column($work_time_data, 'username')) ?>;
        const workTimeData = <?= json_encode(array_map(fn($row) => round($row['total_time'] / 3600, 2), $work_time_data)) ?>;
        const tardinessLabels = ['Entradas Tardías', 'Almuerzos Tardíos', 'Descansos Tardíos'];
        const tardinessData = [<?= $late_entries_percent ?>, <?= $late_lunches_percent ?>, <?= $late_breaks_percent ?>];
        const trendLabels = <?= json_encode($trend_labels) ?>;
        const trendEntries = <?= json_encode($trend_entries) ?>;
        const trendExits = <?= json_encode($trend_exits) ?>;
        const entryHourLabels = <?= json_encode($hour_labels) ?>;
        const entryHourCounts = <?= json_encode($hour_counts) ?>;

        // ConfiguraciÃƒÆ’Ã†a€™Ãƒa€šÃ‚a³n comÃƒÆ’Ã†a€™Ãƒa€šÃ‚aºn para todos los grÃƒÆ’Ã†a€™Ãƒa€šÃ‚a¡ficos
        Chart.defaults.font.size = 12;
        Chart.defaults.responsive = true;
        Chart.defaults.maintainAspectRatio = false;

        // Enhanced Category Chart
        new Chart(document.getElementById('categoryChart').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: categoryLabels,
                datasets: [{
                    data: categoryCounts,
                    backgroundColor: ['#4CAF50', '#2196F3', '#FFC107', '#FF5722', '#9C27B0'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 12
                            }
                        }
                    }
                },
                layout: {
                    padding: {
                        left: 10,
                        right: 10,
                        top: 0,
                        bottom: 0
                    }
                }
            }
        });

        // Enhanced Work Time Chart
        new Chart(document.getElementById('workTimeChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: workTimeLabels,
                datasets: [{
                    label: 'Horas de Trabajo',
                    data: workTimeData,
                    backgroundColor: '#4CAF50',
                    borderRadius: 6,
                    maxBarThickness: 50
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                layout: {
                    padding: {
                        left: 10,
                        right: 10,
                        top: 10,
                        bottom: 10
                    }
                }
            }
        });

        // Enhanced Tardiness Chart
        new Chart(document.getElementById('tardinessChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: tardinessLabels,
                datasets: [{
                    label: 'Tardanza %',
                    data: tardinessData,
                    borderColor: '#FF5722',
                    backgroundColor: 'rgba(255, 87, 34, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: value => value + '%'
                        }
                    }
                },
                layout: {
                    padding: {
                        left: 10,
                        right: 10,
                        top: 10,
                        bottom: 10
                    }
                }
            }
        });

        // Trend Chart (14 days)
        new Chart(document.getElementById('trendChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    {
                        label: 'Entradas',
                        data: trendEntries,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34, 197, 94, 0.15)',
                        fill: true,
                        tension: 0.35
                    },
                    {
                        label: 'Salidas',
                        data: trendExits,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.12)',
                        fill: true,
                        tension: 0.35
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Entry Hour Distribution
        new Chart(document.getElementById('entryHourChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: entryHourLabels,
                datasets: [{
                    label: 'Entradas',
                    data: entryHourCounts,
                    backgroundColor: '#6366f1',
                    borderRadius: 4,
                    maxBarThickness: 18
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false
                        }
                    },
                    x: {
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 12
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Entries mini chart
        const entriesOptions = {
            series: [{
                data: [25, 30, 28, 32, 29, 30, <?= $entries_today ?>]
            }],
            chart: {
                type: 'area',
                height: 60,
                sparkline: {
                    enabled: true
                }
            },
            stroke: {
                curve: 'smooth'
            },
            fill: {
                opacity: 0.3
            },
            colors: ['#4CAF50']
        };
        new ApexCharts(document.getElementById('entriesChart'), entriesOptions).render();

        // Tardiness gauge
        const gaugeOptions = {
            series: [<?= $overall_tardiness_entry ?>],
            chart: {
                type: 'radialBar',
                height: 100,
                sparkline: {
                    enabled: true
                }
            },
            colors: ['<?= $overall_tardiness_entry > 50 ? "#ef4444" : ($overall_tardiness_entry > 25 ? "#f59e0b" : "#22c55e") ?>'],
            plotOptions: {
                radialBar: {
                    hollow: {
                        size: '60%'
                    },
                    track: {
                        background: '#e2e8f0'
                    },
                    dataLabels: {
                        show: false
                    }
                }
            }
        };
        new ApexCharts(document.getElementById('tardinessGauge'), gaugeOptions).render();

        // Refresh data button functionality
        document.getElementById('refreshData').addEventListener('click', function() {
            this.classList.add('animate-spin');
            setTimeout(() => {
                location.reload();
            }, 1000);
        });
    </script>
</body>
</html>

