<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php'); // Redirect to login page if no active session
    exit;
}

include 'db.php';

// Consulta para estadisticas generales
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$entries_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE type = 'Entry' AND DATE(timestamp) = CURDATE()")->fetchColumn();
$exits_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE type = 'Exit' AND DATE(timestamp) = CURDATE()")->fetchColumn();
$active_users = $pdo->query("
    SELECT COUNT(DISTINCT user_id) 
    FROM attendance 
    WHERE DATE(timestamp) = CURDATE()
")->fetchColumn();

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evallish BPO Dashboard</title>
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
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Evallish BPO Dashboard</h1>
                <p class="text-gray-600">Last updated: <span id="lastUpdate"></span></p>
            </div>
            <button id="refreshData" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-sync-alt mr-2"></i> Refresh Data
            </button>
        </div>

        <!-- Main Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Total Users</p>
                        <h3 class="text-4xl font-bold text-gray-800"><?= $total_users ?></h3>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-users text-blue-500 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center">
                        <span class="text-green-500"><i class="fas fa-arrow-up"></i> 12%</span>
                        <span class="text-gray-400 text-sm ml-2">vs last month</span>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Entries Today</p>
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
                        <p class="text-gray-500 text-sm">Active Users</p>
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
                    <p class="text-sm text-gray-500 mt-2"><?= round(($active_users/$total_users)*100) ?>% of total users</p>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Tardiness Rate</p>
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

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Attendance Distribution -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Attendance Distribution</h3>
                <div style="height: 400px; position: relative;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <!-- Work Time Analysis -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Work Time Analysis</h3>
                <div style="height: 400px; position: relative;">
                    <canvas id="workTimeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Tardiness Breakdown -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-gray-800">Tardiness Breakdown</h3>
                <div class="flex space-x-2">
                    <button class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300 text-sm">Daily</button>
                    <button class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300 text-sm">Weekly</button>
                    <button class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300 text-sm">Monthly</button>
                </div>
            </div>
            <div style="height: 300px; position: relative;">
                <canvas id="tardinessChart"></canvas>
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
        const tardinessLabels = ['Late Entries', 'Late Lunches', 'Late Breaks'];
        const tardinessData = [<?= $late_entries_percent ?>, <?= $late_lunches_percent ?>, <?= $late_breaks_percent ?>];

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
                    label: 'Work Hours',
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
                    label: 'Tardiness %',
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

