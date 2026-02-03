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

// ============ CAMPAIGN OVERVIEW ============
$campaignStats = $pdo->query("
    SELECT 
        COUNT(DISTINCT c.id) as total_campaigns,
        COUNT(DISTINCT CASE WHEN c.is_active = 1 THEN c.id END) as active_campaigns,
        COUNT(DISTINCT e.id) as total_employees,
        AVG(
            CASE 
                WHEN u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0 THEN u.hourly_rate_dop * 160
                WHEN u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0 THEN u.monthly_salary_dop
                ELSE NULL
            END
        ) as avg_monthly_cost
    FROM campaigns c
    LEFT JOIN employees e ON c.id = e.campaign_id AND e.employment_status IN ('ACTIVE', 'TRIAL')
    LEFT JOIN users u ON e.user_id = u.id
    WHERE c.is_active = 1
")->fetch(PDO::FETCH_ASSOC);

// ============ CAMPAIGN PERFORMANCE DETAILS ============
$campaignPerformance = $pdo->query("
    SELECT 
        c.id,
        c.name,
        c.description,
        c.color,
        c.is_active,
        COUNT(DISTINCT e.id) as employee_count,
        AVG(
            CASE 
                WHEN u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0 THEN u.hourly_rate_dop * 160
                WHEN u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0 THEN u.monthly_salary_dop
                ELSE NULL
            END
        ) as avg_monthly_cost,
        SUM(
            CASE 
                WHEN u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0 THEN u.hourly_rate_dop * 160
                WHEN u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0 THEN u.monthly_salary_dop
                ELSE 0
            END
        ) as total_monthly_cost
    FROM campaigns c
    LEFT JOIN employees e ON c.id = e.campaign_id AND e.employment_status IN ('ACTIVE', 'TRIAL')
    LEFT JOIN users u ON e.user_id = u.id
    WHERE c.is_active = 1
    GROUP BY c.id, c.name, c.description, c.color, c.is_active
    ORDER BY employee_count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ============ EMPLOYEE DISTRIBUTION BY CAMPAIGN ============
$employeeDistribution = [];
$colors = [];
foreach ($campaignPerformance as $camp) {
    if ($camp['employee_count'] > 0) {
        $employeeDistribution[] = [
            'name' => $camp['name'],
            'count' => $camp['employee_count']
        ];
        $colors[] = $camp['color'];
    }
}

// ============ CAMPAIGN GROWTH TREND (LAST 6 MONTHS) ============
$growthTrend = $pdo->query("
    SELECT 
        DATE_FORMAT(e.hire_date, '%Y-%m') as month,
        DATE_FORMAT(e.hire_date, '%b %Y') as month_label,
        c.name as campaign_name,
        c.color as campaign_color,
        COUNT(e.id) as new_employees
    FROM employees e
    JOIN campaigns c ON e.campaign_id = c.id
    WHERE e.hire_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    AND c.is_active = 1
    GROUP BY month, month_label, c.name, c.color
    ORDER BY month ASC, campaign_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Organize growth data by month
$monthlyGrowth = [];
foreach ($growthTrend as $row) {
    $month = $row['month_label'];
    if (!isset($monthlyGrowth[$month])) {
        $monthlyGrowth[$month] = [];
    }
    $monthlyGrowth[$month][$row['campaign_name']] = $row['new_employees'];
}

// ============ DEPARTMENT DISTRIBUTION WITHIN CAMPAIGNS ============
$deptDistribution = $pdo->query("
    SELECT 
        c.name as campaign_name,
        c.color as campaign_color,
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(e.id) as count
    FROM campaigns c
    LEFT JOIN employees e ON c.id = e.campaign_id AND e.employment_status IN ('ACTIVE', 'TRIAL')
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE c.is_active = 1
    GROUP BY c.name, c.color, d.name
    HAVING count > 0
    ORDER BY c.name, count DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análisis de Campañas - HR Reports</title>
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
                    <i class="fas fa-bullhorn text-blue-400 mr-3"></i>
                    Análisis de Campañas
                </h1>
                <p class="text-slate-400">Rendimiento y distribución de empleados por campaña</p>
            </div>
            <a href="../index.php" class="px-6 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>Volver
            </a>
        </div>

        <!-- Key Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-bullhorn text-3xl text-blue-400"></i>
                    <span class="text-3xl">📢</span>
                </div>
                <p class="text-slate-400 text-sm">Campañas Activas</p>
                <h3 class="text-3xl font-bold text-white"><?= $campaignStats['active_campaigns'] ?></h3>
            </div>
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-users text-3xl text-green-400"></i>
                    <span class="text-3xl">👥</span>
                </div>
                <p class="text-slate-400 text-sm">Empleados Asignados</p>
                <h3 class="text-3xl font-bold text-white"><?= $campaignStats['total_employees'] ?></h3>
            </div>
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-dollar-sign text-3xl text-yellow-400"></i>
                    <span class="text-3xl">💰</span>
                </div>
                <p class="text-slate-400 text-sm">Costo Promedio/Empleado</p>
                <h3 class="text-2xl font-bold text-white">RD$<?= number_format($campaignStats['avg_monthly_cost'], 0) ?></h3>
            </div>
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-chart-line text-3xl text-purple-400"></i>
                    <span class="text-3xl">📊</span>
                </div>
                <p class="text-slate-400 text-sm">Promedio por Campaña</p>
                <h3 class="text-3xl font-bold text-white">
                    <?= $campaignStats['active_campaigns'] > 0 ? number_format($campaignStats['total_employees'] / $campaignStats['active_campaigns'], 1) : '0' ?>
                </h3>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Employee Distribution -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-chart-pie text-indigo-400 mr-2"></i>
                    Distribución de Empleados por Campaña
                </h3>
                <?php if (empty($employeeDistribution)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400">No hay empleados asignados a campañas</p>
                    </div>
                <?php else: ?>
                    <div style="height: 350px; position: relative;">
                        <canvas id="distributionChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Campaign Performance Bars -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-chart-bar text-green-400 mr-2"></i>
                    Tamaño de Campañas
                </h3>
                <?php if (empty($campaignPerformance)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400">No hay datos disponibles</p>
                    </div>
                <?php else: ?>
                    <div style="height: 350px; position: relative;">
                        <canvas id="performanceChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Campaign Details Table -->
        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-table text-orange-400 mr-2"></i>
                Detalles de Campañas
            </h3>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Campaña</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Estado</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Empleados</th>
                            <th class="text-right py-3 px-4 text-slate-400 font-medium">Costo Promedio</th>
                            <th class="text-right py-3 px-4 text-slate-400 font-medium">Costo Total Mensual</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaignPerformance as $camp): ?>
                            <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                <td class="py-3 px-4">
                                    <div class="flex items-center">
                                        <div class="w-4 h-4 rounded mr-3" style="background: <?= htmlspecialchars($camp['color']) ?>;"></div>
                                        <div>
                                            <div class="text-white font-medium"><?= htmlspecialchars($camp['name']) ?></div>
                                            <?php if ($camp['description']): ?>
                                                <div class="text-slate-500 text-xs"><?= htmlspecialchars($camp['description']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="px-2 py-1 bg-green-500/20 text-green-300 text-xs rounded-full">
                                        <i class="fas fa-check-circle mr-1"></i>Activa
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center text-blue-400 font-bold text-lg"><?= $camp['employee_count'] ?></td>
                                <td class="py-3 px-4 text-right text-slate-300">
                                    <?php if ($camp['avg_monthly_cost']): ?>
                                        RD$<?= number_format($camp['avg_monthly_cost'], 2) ?>
                                    <?php else: ?>
                                        <span class="text-slate-500">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-right text-green-400 font-bold">
                                    RD$<?= number_format($camp['total_monthly_cost'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php include '../../footer.php'; ?>

    <script>
        <?php if (!empty($employeeDistribution)): ?>
        // Distribution Chart
        new Chart(document.getElementById('distributionChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($employeeDistribution, 'name')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($employeeDistribution, 'count')) ?>,
                    backgroundColor: <?= json_encode($colors) ?>
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { 
                        position: 'right',
                        labels: { color: '#cbd5e1' } 
                    } 
                }
            }
        });
        <?php endif; ?>

        <?php if (!empty($campaignPerformance)): ?>
        // Performance Chart
        new Chart(document.getElementById('performanceChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($campaignPerformance, 'name')) ?>,
                datasets: [{
                    label: 'Empleados',
                    data: <?= json_encode(array_column($campaignPerformance, 'employee_count')) ?>,
                    backgroundColor: <?= json_encode(array_column($campaignPerformance, 'color')) ?>
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
                    x: { 
                        ticks: { color: '#94a3b8', maxRotation: 45, minRotation: 45 }, 
                        grid: { color: 'rgba(148, 163, 184, 0.1)' } 
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
