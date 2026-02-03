<?php
session_start();
require_once '../../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../unauthorized.php');
    exit;
}

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Date range filter
$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// ============ TRIAL PERIOD OVERVIEW ============
$trialOverview = $pdo->query("
    SELECT 
        COUNT(*) as total_in_trial,
        COUNT(CASE WHEN DATEDIFF(DATE_ADD(hire_date, INTERVAL 90 DAY), CURDATE()) <= 15 THEN 1 END) as ending_soon,
        COUNT(CASE WHEN DATEDIFF(DATE_ADD(hire_date, INTERVAL 90 DAY), CURDATE()) < 0 THEN 1 END) as overdue,
        COUNT(CASE WHEN DATEDIFF(DATE_ADD(hire_date, INTERVAL 90 DAY), CURDATE()) > 15 THEN 1 END) as ongoing
    FROM employees
    WHERE employment_status = 'TRIAL'
")->fetch(PDO::FETCH_ASSOC);

// ============ TRIAL PERIOD BY DEPARTMENT ============
$trialByDept = $pdo->query("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(e.id) as count,
        AVG(DATEDIFF(CURDATE(), e.hire_date)) as avg_days_in_trial
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.employment_status = 'TRIAL'
    GROUP BY d.name
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ============ UPCOMING TRIAL ENDINGS ============
$upcomingEndings = $pdo->query("
    SELECT 
        e.id,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        e.hire_date,
        DATE_ADD(e.hire_date, INTERVAL 90 DAY) as trial_end_date,
        DATEDIFF(DATE_ADD(e.hire_date, INTERVAL 90 DAY), CURDATE()) as days_remaining,
        DATEDIFF(CURDATE(), e.hire_date) as days_in_trial
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.employment_status = 'TRIAL'
    ORDER BY trial_end_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ============ TRIAL TO ACTIVE CONVERSION (LAST 12 MONTHS) ============
$conversionData = $pdo->query("
    SELECT 
        DATE_FORMAT(hire_date, '%Y-%m') as month,
        DATE_FORMAT(hire_date, '%b %Y') as month_label,
        COUNT(*) as hired_in_trial,
        COUNT(CASE WHEN employment_status = 'ACTIVE' AND DATEDIFF(CURDATE(), hire_date) >= 90 THEN 1 END) as converted_to_active
    FROM employees
    WHERE hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    AND hire_date <= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY month, month_label
    ORDER BY month ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Calculate conversion rate
$totalHired = array_sum(array_column($conversionData, 'hired_in_trial'));
$totalConverted = array_sum(array_column($conversionData, 'converted_to_active'));
$conversionRate = $totalHired > 0 ? ($totalConverted / $totalHired) * 100 : 0;

// ============ TRIAL PERIOD DISTRIBUTION ============
$trialDistribution = $pdo->query("
    SELECT 
        CASE 
            WHEN DATEDIFF(CURDATE(), hire_date) <= 30 THEN '0-30 días'
            WHEN DATEDIFF(CURDATE(), hire_date) BETWEEN 31 AND 60 THEN '31-60 días'
            WHEN DATEDIFF(CURDATE(), hire_date) BETWEEN 61 AND 90 THEN '61-90 días'
            ELSE 'Más de 90 días'
        END as period_range,
        COUNT(*) as count
    FROM employees
    WHERE employment_status = 'TRIAL'
    GROUP BY period_range
    ORDER BY 
        CASE period_range
            WHEN '0-30 días' THEN 1
            WHEN '31-60 días' THEN 2
            WHEN '61-90 días' THEN 3
            ELSE 4
        END
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análisis de Período de Prueba - HR Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link href="../../assets/css/theme.css" rel="stylesheet">
    <style>
        .report-card {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            padding: 1.5rem;
        }
        .theme-light .report-card {
            background: #ffffff;
            border-color: rgba(148, 163, 184, 0.2);
        }
        .stat-box {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(99, 102, 241, 0.1) 100%);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 12px;
            padding: 1.25rem;
        }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-user-clock text-blue-400 mr-3"></i>
                    Análisis de Período de Prueba
                </h1>
                <p class="text-slate-400">Seguimiento y evaluación de empleados en período de prueba</p>
            </div>
            <a href="../index.php" class="px-6 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>Volver
            </a>
        </div>

        <!-- Key Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-users text-3xl text-blue-400"></i>
                    <span class="text-3xl">👥</span>
                </div>
                <p class="text-slate-400 text-sm">Total en Período de Prueba</p>
                <h3 class="text-3xl font-bold text-white"><?= $trialOverview['total_in_trial'] ?></h3>
            </div>
            <div class="stat-box bg-yellow-500/10 border-yellow-500/30">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-clock text-3xl text-yellow-400"></i>
                    <span class="text-3xl">⏰</span>
                </div>
                <p class="text-slate-400 text-sm">Finalizan Pronto (≤15 días)</p>
                <h3 class="text-3xl font-bold text-white"><?= $trialOverview['ending_soon'] ?></h3>
            </div>
            <div class="stat-box bg-red-500/10 border-red-500/30">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-exclamation-triangle text-3xl text-red-400"></i>
                    <span class="text-3xl">⚠️</span>
                </div>
                <p class="text-slate-400 text-sm">Vencidos (>90 días)</p>
                <h3 class="text-3xl font-bold text-white"><?= $trialOverview['overdue'] ?></h3>
            </div>
            <div class="stat-box bg-green-500/10 border-green-500/30">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-percentage text-3xl text-green-400"></i>
                    <span class="text-3xl">📊</span>
                </div>
                <p class="text-slate-400 text-sm">Tasa de Conversión (12m)</p>
                <h3 class="text-3xl font-bold text-white"><?= number_format($conversionRate, 1) ?>%</h3>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Trial by Department -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-sitemap text-purple-400 mr-2"></i>
                    Distribución por Departamento
                </h3>
                <?php if (empty($trialByDept)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400">No hay empleados en período de prueba</p>
                    </div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;">
                        <canvas id="deptChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Trial Period Distribution -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-chart-pie text-indigo-400 mr-2"></i>
                    Distribución por Tiempo en Prueba
                </h3>
                <?php if (empty($trialDistribution)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400">No hay datos disponibles</p>
                    </div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;">
                        <canvas id="distributionChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Conversion Trend -->
        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-chart-line text-green-400 mr-2"></i>
                Tasa de Conversión a Activo (Últimos 12 Meses)
            </h3>
            <?php if (empty($conversionData)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-chart-line text-slate-600 text-4xl mb-4"></i>
                    <p class="text-slate-400">No hay datos históricos disponibles</p>
                </div>
            <?php else: ?>
                <div style="height: 350px; position: relative;">
                    <canvas id="conversionChart"></canvas>
                </div>
            <?php endif; ?>
        </div>

        <!-- Upcoming Trial Endings Table -->
        <div class="report-card">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-calendar-alt text-orange-400 mr-2"></i>
                Empleados en Período de Prueba
            </h3>
            <?php if (empty($upcomingEndings)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-check-circle text-green-400 text-4xl mb-4"></i>
                    <p class="text-slate-400">No hay empleados en período de prueba actualmente</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Fecha Ingreso</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Días en Prueba</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Finaliza</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingEndings as $emp): ?>
                                <?php 
                                $daysRemaining = $emp['days_remaining'];
                                $statusClass = 'bg-green-500/20 text-green-300';
                                $statusIcon = 'fa-check-circle';
                                $statusText = 'En curso';
                                
                                if ($daysRemaining < 0) {
                                    $statusClass = 'bg-red-500/20 text-red-300';
                                    $statusIcon = 'fa-exclamation-circle';
                                    $statusText = 'Vencido';
                                } elseif ($daysRemaining <= 15) {
                                    $statusClass = 'bg-yellow-500/20 text-yellow-300';
                                    $statusIcon = 'fa-clock';
                                    $statusText = 'Por vencer';
                                }
                                ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($emp['employee_name']) ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($emp['position'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($emp['department'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($emp['hire_date'])) ?></td>
                                    <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $emp['days_in_trial'] ?> días</td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($emp['trial_end_date'])) ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>">
                                            <i class="fas <?= $statusIcon ?> mr-1"></i>
                                            <?= $statusText ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../../footer.php'; ?>

    <script>
        <?php if (!empty($trialByDept)): ?>
        // Department Chart
        new Chart(document.getElementById('deptChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($trialByDept, 'department')) ?>,
                datasets: [{
                    label: 'Empleados',
                    data: <?= json_encode(array_column($trialByDept, 'count')) ?>,
                    backgroundColor: '#8b5cf6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#cbd5e1' } } },
                scales: {
                    y: { 
                        beginAtZero: true,
                        ticks: { color: '#94a3b8', stepSize: 1 }, 
                        grid: { color: 'rgba(148, 163, 184, 0.1)' } 
                    },
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }
                }
            }
        });
        <?php endif; ?>

        <?php if (!empty($trialDistribution)): ?>
        // Distribution Chart
        new Chart(document.getElementById('distributionChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($trialDistribution, 'period_range')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($trialDistribution, 'count')) ?>,
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#cbd5e1' } } }
            }
        });
        <?php endif; ?>

        <?php if (!empty($conversionData)): ?>
        // Conversion Trend Chart
        new Chart(document.getElementById('conversionChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($conversionData, 'month_label')) ?>,
                datasets: [
                    {
                        label: 'Contratados en Prueba',
                        data: <?= json_encode(array_column($conversionData, 'hired_in_trial')) ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        fill: true
                    },
                    {
                        label: 'Convertidos a Activo',
                        data: <?= json_encode(array_column($conversionData, 'converted_to_active')) ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#cbd5e1' } } },
                scales: {
                    y: { 
                        beginAtZero: true,
                        ticks: { color: '#94a3b8', stepSize: 1 }, 
                        grid: { color: 'rgba(148, 163, 184, 0.1)' } 
                    },
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
