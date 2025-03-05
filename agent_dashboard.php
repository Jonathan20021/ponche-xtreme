<?php
session_start();

// Verificar si el usuario está logueado y tiene el rol correcto
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['AGENT', 'IT', 'Supervisor'])) {
    header('Location: login_agent.php');
    exit;
}

include 'db.php';

// Obtener el ID del usuario logueado
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$full_name = $_SESSION['full_name'];

// Filtros
$date_filter = $_GET['dates'] ?? date('Y-m-d');

// Consulta de desglose de tiempo para el usuario actual
$query = "
    SELECT 
        attendance.type,
        DATE(attendance.timestamp) AS record_date,
        TIME(attendance.timestamp) AS record_time,
        attendance.ip_address
    FROM attendance
    WHERE attendance.user_id = ? AND DATE(attendance.timestamp) = ?
    ORDER BY attendance.timestamp ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_id, $date_filter]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular el desglose de tiempo
$time_summary_query = "
    SELECT 
        SUM(CASE WHEN type = 'Break' THEN TIMESTAMPDIFF(SECOND, timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS total_break,
        SUM(CASE WHEN type = 'Lunch' THEN TIMESTAMPDIFF(SECOND, timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS total_lunch,
        SUM(CASE WHEN type = 'Follow Up' THEN TIMESTAMPDIFF(SECOND, timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS total_follow_up,
        SUM(CASE WHEN type = 'Ready' THEN TIMESTAMPDIFF(SECOND, timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS total_ready,
        SUM(CASE WHEN type = 'Entry' THEN TIMESTAMPDIFF(SECOND, timestamp, (
            SELECT MAX(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
            AND a.type = 'Exit'
        )) ELSE 0 END) AS total_work
    FROM attendance
    WHERE user_id = ? AND DATE(timestamp) = ?
";

$time_summary_stmt = $pdo->prepare($time_summary_query);
$time_summary_stmt->execute([$user_id, $date_filter]);
$time_summary = $time_summary_stmt->fetch(PDO::FETCH_ASSOC);

// Calcular el pago
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
    'rbueno' => 200.00
];
$hourly_rate = $hourly_rates[$username] ?? 0;
$total_work_hours = $time_summary['total_work'] / 3600;
$total_payment = round($total_work_hours * $hourly_rate, 2);

// Calcular productividad
$total_time = $time_summary['total_work'] + $time_summary['total_break'] + $time_summary['total_lunch'] + $time_summary['total_follow_up'] + $time_summary['total_ready'];
$productivity_score = $total_time > 0 ? round(($time_summary['total_work'] / $total_time) * 100, 1) : 0;
?>

<?php include 'header_agent.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <title>Agent Dashboard</title>
</head>
<body class="bg-gray-50 text-gray-800">
    <div class="container mx-auto px-4 py-8">
        <!-- Header Section -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Welcome, <?= htmlspecialchars($full_name) ?></h1>
                <p class="text-gray-600">Here's your performance overview for today</p>
            </div>
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <input type="text" id="datePicker" class="bg-white border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?= $date_filter ?>">
                </div>
            </div>
        </div>

        <!-- Productivity Score -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Productivity Score</h3>
                    <p class="text-gray-600">Based on your work time vs total time</p>
                </div>
                <div class="relative">
                    <div class="w-24 h-24">
                        <canvas id="productivityChart"></canvas>
                    </div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span class="text-2xl font-bold"><?= $productivity_score ?>%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white p-6 rounded-lg shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Work Time</h3>
                    <i class="fas fa-briefcase text-blue-500 text-xl"></i>
                </div>
                <p class="text-3xl font-bold text-blue-600"><?= gmdate("H:i", $time_summary['total_work']) ?></p>
                <p class="text-sm text-gray-600 mt-2">Hours worked today</p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Break Time</h3>
                    <i class="fas fa-coffee text-orange-500 text-xl"></i>
                </div>
                <p class="text-3xl font-bold text-orange-600"><?= gmdate("H:i", $time_summary['total_break']) ?></p>
                <p class="text-sm text-gray-600 mt-2">Total breaks taken</p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Lunch Time</h3>
                    <i class="fas fa-utensils text-green-500 text-xl"></i>
                </div>
                <p class="text-3xl font-bold text-green-600"><?= gmdate("H:i", $time_summary['total_lunch']) ?></p>
                <p class="text-sm text-gray-600 mt-2">Lunch break duration</p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-lg hover:shadow-xl transition-shadow duration-300">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Daily Payment</h3>
                    <i class="fas fa-dollar-sign text-yellow-500 text-xl"></i>
                </div>
                <p class="text-3xl font-bold text-yellow-600">$<?= number_format($total_payment, 2) ?></p>
                <p class="text-sm text-gray-600 mt-2">Based on <?= number_format($total_work_hours, 1) ?> hours</p>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Time Distribution Chart -->
            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Time Distribution</h3>
                <canvas id="timeBreakdownChart" height="300"></canvas>
            </div>

            <!-- Activity Timeline -->
            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Activity Timeline</h3>
                <div class="space-y-4">
                    <?php foreach ($records as $record): ?>
                        <div class="flex items-center space-x-4">
                            <div class="w-2 h-2 rounded-full <?php
                                echo match($record['type']) {
                                    'Entry' => 'bg-green-500',
                                    'Exit' => 'bg-red-500',
                                    'Break' => 'bg-orange-500',
                                    'Lunch' => 'bg-yellow-500',
                                    'Ready' => 'bg-blue-500',
                                    'Follow Up' => 'bg-purple-500',
                                    default => 'bg-gray-500'
                                };
                            ?>"></div>
                            <div>
                                <p class="font-medium"><?= htmlspecialchars($record['type']) ?></p>
                                <p class="text-sm text-gray-600"><?= htmlspecialchars($record['record_time']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Detailed Records Table -->
        <div class="bg-white p-6 rounded-lg shadow-lg">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Detailed Records</h3>
                <div class="flex space-x-2">
                    <button class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                        <i class="fas fa-download mr-2"></i>Export
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (!empty($records)): ?>
                            <?php foreach ($records as $record): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php
                                            echo match($record['type']) {
                                                'Entry' => 'bg-green-100 text-green-800',
                                                'Exit' => 'bg-red-100 text-red-800',
                                                'Break' => 'bg-orange-100 text-orange-800',
                                                'Lunch' => 'bg-yellow-100 text-yellow-800',
                                                'Ready' => 'bg-blue-100 text-blue-800',
                                                'Follow Up' => 'bg-purple-100 text-purple-800',
                                                default => 'bg-gray-100 text-gray-800'
                                            };
                                        ?>">
                                            <?= htmlspecialchars($record['type']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($record['record_date']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($record['record_time']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($record['ip_address']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                                    No records found for the selected date.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Initialize date picker
        flatpickr("#datePicker", {
            dateFormat: "Y-m-d",
            maxDate: "today",
            onChange: function(selectedDates, dateStr) {
                window.location.href = `?dates=${dateStr}`;
            }
        });

        // Productivity Chart
        new Chart(document.getElementById('productivityChart'), {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [<?= $productivity_score ?>, <?= 100 - $productivity_score ?>],
                    backgroundColor: ['#3B82F6', '#E5E7EB'],
                    borderWidth: 0
                }]
            },
            options: {
                cutout: '80%',
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Time Breakdown Chart
        new Chart(document.getElementById('timeBreakdownChart'), {
            type: 'doughnut',
            data: {
                labels: ['Work', 'Break', 'Lunch', 'Follow Up', 'Ready'],
                datasets: [{
                    data: [
                        <?= $time_summary['total_work'] ?>,
                        <?= $time_summary['total_break'] ?>,
                        <?= $time_summary['total_lunch'] ?>,
                        <?= $time_summary['total_follow_up'] ?>,
                        <?= $time_summary['total_ready'] ?>
                    ],
                    backgroundColor: [
                        '#3B82F6',
                        '#F97316',
                        '#22C55E',
                        '#A855F7',
                        '#0EA5E9'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
    </script>
</body>
</html>

