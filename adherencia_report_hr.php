<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Verificar rol de usuario
if (!in_array($_SESSION['role'], ['IT', 'HR'])) {
    header('Location: unauthorized.php');
    exit;
}

include 'db.php';

// Definir las metas para los horarios
$goals = [
    'sch_in' => '10:00:00', // Hora de entrada
    'sch_out' => '19:00:00', // Hora de salida
    'lunch' => 45 * 60, // 45 minutos (en segundos)
    'break' => 15 * 60, // 15 minutos (en segundos)
    'meeting_coaching' => 45 * 60 // 45 minutos (en segundos)
];

// Horas programadas por día (en segundos)
$scheduled_hours_per_day = 8 * 3600; // 8 horas

// Tarifas por hora para los empleados
$hourly_rates = [
    'ematos' => 200.00,
    'Jcoronado' => 200.00,
    'Jmirabel' => 200.00,
    'Gbonilla' => 110.00,
    'Ecapellan' => 110.00,
    'Rmota' => 110.00,
    'abatista' => 200.00,
    'ydominguez' => 110.00,
    'elara' => 200.00,
    'omorel' => 110.00,
    'rbueno' => 200.00,
    'xalfonso' => 200.00,
    'jalmonte' => 110.00
];

$date_filter = $_GET['month'] ?? date('Y-m');
$start_date = $date_filter . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

// Configuración de paginación
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Consulta optimizada para obtener datos de tendencias
$trend_query = "
    WITH daily_work AS (
        SELECT 
            DATE(a.timestamp) as work_date,
            a.user_id,
            MIN(CASE WHEN a.type = 'Entry' THEN a.timestamp END) as first_entry,
            MAX(CASE WHEN a.type = 'Exit' THEN a.timestamp END) as last_exit
        FROM attendance a
        WHERE a.timestamp >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE(a.timestamp), a.user_id
    )
    SELECT 
        DATE_FORMAT(work_date, '%Y-%m') as month,
        COUNT(DISTINCT user_id) as total_employees,
        AVG(TIMESTAMPDIFF(SECOND, first_entry, COALESCE(last_exit, CONCAT(work_date, ' 19:00:00')))) as avg_work_time,
        COUNT(CASE WHEN first_entry > CONCAT(work_date, ' 10:00:00') THEN 1 END) as late_count
    FROM daily_work
    GROUP BY DATE_FORMAT(work_date, '%Y-%m')
    ORDER BY month DESC
";

$trend_data = $pdo->query($trend_query)->fetchAll(PDO::FETCH_ASSOC);

// Consulta optimizada para el reporte diario
$query_daily = "
    WITH daily_attendance AS (
        SELECT 
            DATE(a.timestamp) as work_date,
            a.user_id,
            MIN(CASE WHEN a.type = 'Entry' THEN a.timestamp END) as first_entry,
            MAX(CASE WHEN a.type = 'Exit' THEN a.timestamp END) as last_exit,
            SUM(CASE WHEN a.type = 'Lunch' THEN 1 ELSE 0 END) as lunch_count,
            SUM(CASE WHEN a.type = 'Break' THEN 1 ELSE 0 END) as break_count,
            SUM(CASE WHEN a.type IN ('Meeting', 'Coaching') THEN 1 ELSE 0 END) as meeting_count
        FROM attendance a
        WHERE a.timestamp BETWEEN ? AND ?
        GROUP BY DATE(a.timestamp), a.user_id
    )
    SELECT 
        u.full_name AS employee,
        u.username,
        da.work_date,
        da.first_entry,
        COALESCE(da.last_exit, CONCAT(da.work_date, ' 19:00:00')) as last_exit,
        (da.lunch_count * 45 * 60) as total_lunch,
        (da.break_count * 15 * 60) as total_break,
        (da.meeting_count * 45 * 60) as total_meeting_coaching,
        GREATEST(
            TIMESTAMPDIFF(SECOND, 
                da.first_entry, 
                COALESCE(da.last_exit, CONCAT(da.work_date, ' 19:00:00'))
            ) - 
            (da.lunch_count * 45 * 60) - 
            (da.break_count * 15 * 60),
            0
        ) as total_work_time
    FROM daily_attendance da
    JOIN users u ON da.user_id = u.id
    ORDER BY u.full_name, da.work_date
    LIMIT $offset, $records_per_page
";

$stmt = $pdo->prepare($query_daily);
$stmt->execute([$start_date, $end_date]);
$report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular el total de páginas
$total_records = $pdo->query("
    SELECT COUNT(DISTINCT u.full_name, DATE(a.timestamp)) AS total
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    WHERE a.timestamp BETWEEN '$start_date' AND '$end_date'
")->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Consulta optimizada para el reporte mensual
$query_monthly = "
    WITH monthly_attendance AS (
        SELECT 
            a.user_id,
            SUM(CASE WHEN a.type = 'Lunch' THEN 1 ELSE 0 END) as lunch_count,
            SUM(CASE WHEN a.type = 'Break' THEN 1 ELSE 0 END) as break_count,
            SUM(CASE WHEN a.type IN ('Meeting', 'Coaching') THEN 1 ELSE 0 END) as meeting_count,
            MIN(CASE WHEN a.type = 'Entry' THEN a.timestamp END) as first_entry,
            MAX(CASE WHEN a.type = 'Exit' THEN a.timestamp END) as last_exit
        FROM attendance a
        WHERE a.timestamp BETWEEN ? AND ?
        GROUP BY a.user_id
    )
    SELECT 
        u.full_name AS employee,
        u.username,
        (ma.lunch_count * 45 * 60) as total_lunch,
        (ma.break_count * 15 * 60) as total_break,
        (ma.meeting_count * 45 * 60) as total_meeting_coaching,
        TIMESTAMPDIFF(SECOND, ma.first_entry, COALESCE(ma.last_exit, CONCAT(DATE(ma.first_entry), ' 19:00:00'))) as total_work_time
    FROM monthly_attendance ma
    JOIN users u ON ma.user_id = u.id
    ORDER BY u.full_name
";

$stmt_monthly = $pdo->prepare($query_monthly);
$stmt_monthly->execute([$start_date, $end_date]);
$monthly_data = $stmt_monthly->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'header.php'; ?>

<!-- Modern Dashboard Layout -->
<div class="container mx-auto px-4 py-8">
    <!-- Header Section -->
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">HR Analytics Dashboard</h1>
            <p class="text-gray-600">Employee Performance & Attendance Report</p>
        </div>
        <div class="flex space-x-4">
            <form method="GET" class="flex items-center space-x-2">
                <input type="month" name="month" value="<?= htmlspecialchars($date_filter) ?>" 
                       class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600">
                    Filter
                </button>
            </form>
            <div class="flex space-x-2">
                <a href="download_excel.php?month=<?= htmlspecialchars($date_filter) ?>" 
                   class="bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600">
                    <i class="fas fa-file-excel mr-2"></i>Excel
                </a>
                <a href="download_pdf.php?month=<?= htmlspecialchars($date_filter) ?>" 
                   class="bg-red-500 text-white px-4 py-2 rounded-lg hover:bg-red-600">
                    <i class="fas fa-file-pdf mr-2"></i>PDF
                </a>
            </div>
        </div>
    </div>

    <!-- Key Metrics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <?php
        $total_employees = count($monthly_data);
        $avg_adh = array_sum(array_column($monthly_data, 'total_work_time')) / ($total_employees * $scheduled_hours_per_day) * 100;
        $late_count = count(array_filter($report_data, function($row) use ($goals) {
            return strtotime($row['first_entry']) > strtotime($row['work_date'] . ' ' . $goals['sch_in']);
        }));
        $total_earned = array_sum(array_map(function($row) use ($hourly_rates) {
            return ($row['total_work_time'] / 3600) * ($hourly_rates[$row['username']] ?? 0);
        }, $monthly_data));
        ?>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-500 text-sm font-medium">Total Employees</h3>
            <p class="text-3xl font-bold text-gray-900"><?= $total_employees ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-500 text-sm font-medium">Average Adherence</h3>
            <p class="text-3xl font-bold text-gray-900"><?= number_format($avg_adh, 1) ?>%</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-500 text-sm font-medium">Late Arrivals</h3>
            <p class="text-3xl font-bold text-gray-900"><?= $late_count ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-500 text-sm font-medium">Total Amount Earned</h3>
            <p class="text-3xl font-bold text-gray-900">$<?= number_format($total_earned, 2) ?></p>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold mb-4">Attendance Trends</h3>
            <canvas id="attendanceTrend"></canvas>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold mb-4">Employee Performance Distribution</h3>
            <canvas id="performanceDistribution"></canvas>
        </div>
    </div>

    <!-- Detailed Reports -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="p-6 border-b">
            <h2 class="text-xl font-semibold">Detailed Employee Report</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">First Entry</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Exit</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lunch Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Break Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Meeting/Coaching</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Work Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ABS (%)</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($report_data as $row): ?>
                        <?php
                        $work_time = $row['total_work_time'] ?: 0;
                        $abs_percent = ($work_time / $scheduled_hours_per_day) * 100;
                        $late = (strtotime($row['first_entry']) > strtotime($row['work_date'] . ' ' . $goals['sch_in']));
                        $status_class = $late ? 'text-red-600' : 'text-green-600';
                        $performance_class = $abs_percent >= 90 ? 'text-green-600' : ($abs_percent >= 80 ? 'text-yellow-600' : 'text-red-600');
                        $hourly_rate = $hourly_rates[$row['username']] ?? 0;
                        $earned = ($work_time / 3600) * $hourly_rate;
                        ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($row['employee']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($row['work_date']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($row['first_entry'] ?: 'N/A') ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($row['last_exit'] ?: 'N/A') ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= gmdate('H:i:s', $row['total_lunch'] ?: 0) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= gmdate('H:i:s', $row['total_break'] ?: 0) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= gmdate('H:i:s', $row['total_meeting_coaching'] ?: 0) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= gmdate('H:i:s', $work_time) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="<?= $status_class ?>">
                                    <?= $late ? 'Late' : 'On Time' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="<?= $performance_class ?>">
                                    <?= number_format($abs_percent, 2) ?>%
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">$<?= number_format($earned, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Monthly Consolidated Report -->
    <div class="bg-white rounded-lg shadow overflow-hidden mt-8">
        <div class="p-6 border-b">
            <h2 class="text-xl font-semibold">Monthly Consolidated Report</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Lunch Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Break Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Meeting/Coaching</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Work Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ADH (%)</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount Earned</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($monthly_data as $row): ?>
                        <?php
                        $work_time = $row['total_work_time'] ?: 0;
                        $adh_percent = ($work_time / ($scheduled_hours_per_day * count($report_data))) * 100;
                        $hourly_rate = $hourly_rates[$row['username']] ?? 0;
                        $earned = ($work_time / 3600) * $hourly_rate;
                        ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($row['employee']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= gmdate('H:i:s', $row['total_lunch'] ?: 0) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= gmdate('H:i:s', $row['total_break'] ?: 0) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= gmdate('H:i:s', $row['total_meeting_coaching'] ?: 0) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= gmdate('H:i:s', $work_time) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="<?= $adh_percent >= 90 ? 'text-green-600' : ($adh_percent >= 80 ? 'text-yellow-600' : 'text-red-600') ?>">
                                    <?= number_format($adh_percent, 2) ?>%
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">$<?= number_format($earned, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Attendance Trend Chart
const trendCtx = document.getElementById('attendanceTrend').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column(array_reverse($trend_data), 'month')) ?>,
        datasets: [{
            label: 'Average Work Time (hours)',
            data: <?= json_encode(array_map(function($item) {
                return $item['avg_work_time'] / 3600;
            }, array_reverse($trend_data))) ?>,
            borderColor: 'rgb(59, 130, 246)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Performance Distribution Chart
const performanceCtx = document.getElementById('performanceDistribution').getContext('2d');
const performanceData = <?= json_encode(array_map(function($row) use ($scheduled_hours_per_day) {
    return ($row['total_work_time'] / $scheduled_hours_per_day) * 100;
}, $monthly_data)) ?>;

new Chart(performanceCtx, {
    type: 'bar',
    data: {
        labels: ['< 80%', '80-90%', '90-100%', '> 100%'],
        datasets: [{
            label: 'Number of Employees',
            data: [
                performanceData.filter(x => x < 80).length,
                performanceData.filter(x => x >= 80 && x < 90).length,
                performanceData.filter(x => x >= 90 && x < 100).length,
                performanceData.filter(x => x >= 100).length
            ],
            backgroundColor: [
                'rgb(239, 68, 68)',
                'rgb(234, 179, 8)',
                'rgb(34, 197, 94)',
                'rgb(59, 130, 246)'
            ]
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>

<!-- Include Tailwind CSS -->
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<!-- Include Font Awesome -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">