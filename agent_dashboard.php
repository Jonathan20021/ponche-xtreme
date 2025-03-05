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
    'elara@presta-max.com' => 200.00,
    'omorel' => 110.00,
    'rbueno' => 200.00
];
$hourly_rate = $hourly_rates[$username] ?? 0;
$total_work_hours = $time_summary['total_work'] / 3600;
$total_payment = round($total_work_hours * $hourly_rate, 2);
?>

<?php include 'header_agent.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <title>Agent Dashboard</title>
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="container mx-auto mt-8">
        <h1 class="text-3xl font-bold text-center mb-6">Welcome, <?= htmlspecialchars($full_name) ?></h1>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white p-6 rounded shadow">
                <h3 class="text-lg font-semibold">Total Break Time</h3>
                <p class="text-2xl"><?= gmdate("H:i:s", $time_summary['total_break']) ?></p>
            </div>
            <div class="bg-white p-6 rounded shadow">
                <h3 class="text-lg font-semibold">Total Lunch Time</h3>
                <p class="text-2xl"><?= gmdate("H:i:s", $time_summary['total_lunch']) ?></p>
            </div>
            <div class="bg-white p-6 rounded shadow">
                <h3 class="text-lg font-semibold">Total Follow Up Time</h3>
                <p class="text-2xl"><?= gmdate("H:i:s", $time_summary['total_follow_up']) ?></p>
            </div>
            <div class="bg-white p-6 rounded shadow">
                <h3 class="text-lg font-semibold">Total Ready Time</h3>
                <p class="text-2xl"><?= gmdate("H:i:s", $time_summary['total_ready']) ?></p>
            </div>
            <div class="bg-white p-6 rounded shadow">
                <h3 class="text-lg font-semibold">Total Work Time</h3>
                <p class="text-2xl"><?= gmdate("H:i:s", $time_summary['total_work']) ?></p>
            </div>
            <div class="bg-white p-6 rounded shadow">
                <h3 class="text-lg font-semibold">Total Payment</h3>
                <p class="text-2xl">$<?= number_format($total_payment, 2) ?></p>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Work Time Breakdown -->
            <div class="bg-white p-6 rounded shadow">
                <h3 class="text-lg font-semibold mb-4">Work Time Breakdown</h3>
                <canvas id="timeBreakdownChart"></canvas>
            </div>

            <!-- Attendance by Type -->
            <div class="bg-white p-6 rounded shadow">
                <h3 class="text-lg font-semibold mb-4">Attendance by Type</h3>
                <canvas id="attendanceTypeChart"></canvas>
            </div>
        </div>

        <!-- Table of Records -->
        <div class="bg-white p-6 rounded shadow">
            <h3 class="text-lg font-semibold mb-4">Attendance Records</h3>
            <table class="w-full border-collapse bg-white shadow-md rounded mt-4">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="p-2 border">Type</th>
                        <th class="p-2 border">Date</th>
                        <th class="p-2 border">Time</th>

                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($records)): ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td class="p-2 border"><?= htmlspecialchars($record['type']) ?></td>
                                <td class="p-2 border"><?= htmlspecialchars($record['record_date']) ?></td>
                                <td class="p-2 border"><?= htmlspecialchars($record['record_time']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center p-4">No records found for the selected date.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const timeBreakdownData = {
            labels: ['Break', 'Lunch', 'Follow Up', 'Ready', 'Work'],
            datasets: [{
                label: 'Time in Seconds',
                data: [<?= $time_summary['total_break'] ?>, <?= $time_summary['total_lunch'] ?>, <?= $time_summary['total_follow_up'] ?>, <?= $time_summary['total_ready'] ?>, <?= $time_summary['total_work'] ?>],
                backgroundColor: ['#ff6384', '#36a2eb', '#ffce56', '#4bc0c0', '#9966ff'],
            }]
        };

        const attendanceTypeData = {
            labels: <?= json_encode(array_column($records, 'type')) ?>,
            datasets: [{
                label: 'Attendance Count',
                data: <?= json_encode(array_count_values(array_column($records, 'type'))) ?>,
                backgroundColor: '#ffcc00',
            }]
        };

        new Chart(document.getElementById('timeBreakdownChart'), {
            type: 'doughnut',
            data: timeBreakdownData,
        });

        new Chart(document.getElementById('attendanceTypeChart'), {
            type: 'bar',
            data: attendanceTypeData,
        });
    </script>
</body>
</html>

