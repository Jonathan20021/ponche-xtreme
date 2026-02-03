<?php
session_start();
require_once '../../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../unauthorized.php');
    exit;
}

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Date range - por defecto todo el año actual
$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Total employees by status
$employeesByStatus = $pdo->query("
    SELECT 
        employment_status,
        COUNT(*) as count
    FROM employees
    GROUP BY employment_status
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Gender distribution
$genderDistribution = $pdo->query("
    SELECT 
        COALESCE(gender, 'No especificado') as gender,
        COUNT(*) as count
    FROM employees
    WHERE employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY gender
")->fetchAll(PDO::FETCH_ASSOC);

// Age distribution
$ageDistribution = $pdo->query("
    SELECT 
        CASE 
            WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) < 25 THEN '18-24'
            WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 25 AND 34 THEN '25-34'
            WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 35 AND 44 THEN '35-44'
            WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 45 AND 54 THEN '45-54'
            WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) >= 55 THEN '55+'
            ELSE 'No especificado'
        END as age_range,
        COUNT(*) as count
    FROM employees
    WHERE employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY age_range
    ORDER BY age_range
")->fetchAll(PDO::FETCH_ASSOC);

// Department distribution (calculating avg monthly cost per employee)
$departmentData = $pdo->query("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(e.id) as count,
        AVG(
            CASE 
                WHEN u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0 THEN u.hourly_rate_dop * 160
                WHEN u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0 THEN u.monthly_salary_dop
                ELSE NULL
            END
        ) as avg_rate,
        SUM(
            CASE 
                WHEN u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0 THEN u.hourly_rate_dop * 160
                WHEN u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0 THEN u.monthly_salary_dop
                ELSE 0
            END
        ) as total_cost_per_hour
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY d.name
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Position distribution (top 10, calculating monthly equivalent)
$positionData = $pdo->query("
    SELECT 
        COALESCE(e.position, 'Sin Posición') as position,
        COUNT(e.id) as count,
        AVG(
            CASE 
                WHEN u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0 THEN u.hourly_rate_dop * 160
                WHEN u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0 THEN u.monthly_salary_dop
                ELSE NULL
            END
        ) as avg_rate
    FROM employees e
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY e.position
    ORDER BY count DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Tenure distribution
$tenureDistribution = $pdo->query("
    SELECT 
        CASE 
            WHEN TIMESTAMPDIFF(MONTH, hire_date, CURDATE()) < 6 THEN '0-6 meses'
            WHEN TIMESTAMPDIFF(MONTH, hire_date, CURDATE()) BETWEEN 6 AND 11 THEN '6-12 meses'
            WHEN TIMESTAMPDIFF(YEAR, hire_date, CURDATE()) BETWEEN 1 AND 2 THEN '1-2 años'
            WHEN TIMESTAMPDIFF(YEAR, hire_date, CURDATE()) BETWEEN 2 AND 5 THEN '2-5 años'
            WHEN TIMESTAMPDIFF(YEAR, hire_date, CURDATE()) > 5 THEN '5+ años'
        END as tenure_range,
        COUNT(*) as count
    FROM employees
    WHERE employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY tenure_range
    ORDER BY 
        CASE tenure_range
            WHEN '0-6 meses' THEN 1
            WHEN '6-12 meses' THEN 2
            WHEN '1-2 años' THEN 3
            WHEN '2-5 años' THEN 4
            WHEN '5+ años' THEN 5
        END
")->fetchAll(PDO::FETCH_ASSOC);

// Salary range distribution (hourly employees use hourly_rate_dop * 160, fixed use monthly_salary_dop)
$salaryRanges = $pdo->query("
    SELECT 
        CASE 
            WHEN u.compensation_type = 'hourly' AND (u.hourly_rate_dop IS NULL OR u.hourly_rate_dop = 0) THEN 'Sin datos'
            WHEN u.compensation_type = 'fixed' AND (u.monthly_salary_dop IS NULL OR u.monthly_salary_dop = 0) THEN 'Sin datos'
            WHEN (u.compensation_type = 'hourly' AND u.hourly_rate_dop * 160 < 15000) OR (u.compensation_type = 'fixed' AND u.monthly_salary_dop < 15000) THEN 'Menos de RD\$15K'
            WHEN (u.compensation_type = 'hourly' AND u.hourly_rate_dop * 160 BETWEEN 15000 AND 24999) OR (u.compensation_type = 'fixed' AND u.monthly_salary_dop BETWEEN 15000 AND 24999) THEN 'RD\$15K-25K'
            WHEN (u.compensation_type = 'hourly' AND u.hourly_rate_dop * 160 BETWEEN 25000 AND 34999) OR (u.compensation_type = 'fixed' AND u.monthly_salary_dop BETWEEN 25000 AND 34999) THEN 'RD\$25K-35K'
            WHEN (u.compensation_type = 'hourly' AND u.hourly_rate_dop * 160 BETWEEN 35000 AND 49999) OR (u.compensation_type = 'fixed' AND u.monthly_salary_dop BETWEEN 35000 AND 49999) THEN 'RD\$35K-50K'
            WHEN (u.compensation_type = 'hourly' AND u.hourly_rate_dop * 160 >= 50000) OR (u.compensation_type = 'fixed' AND u.monthly_salary_dop >= 50000) THEN 'Más de RD\$50K'
            ELSE 'Sin datos'
        END as salary_range,
        COUNT(*) as count
    FROM employees e
    JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY salary_range
    ORDER BY 
        CASE salary_range
            WHEN 'Sin datos' THEN 6
            WHEN 'Menos de RD\$15K' THEN 1
            WHEN 'RD\$15K-25K' THEN 2
            WHEN 'RD\$25K-35K' THEN 3
            WHEN 'RD\$35K-50K' THEN 4
            WHEN 'Más de RD\$50K' THEN 5
        END
")->fetchAll(PDO::FETCH_ASSOC);

// Monthly hiring trend (last 12 months)
$hiringTrend = $pdo->query("
    SELECT 
        DATE_FORMAT(hire_date, '%Y-%m') as month,
        DATE_FORMAT(hire_date, '%b %Y') as month_label,
        COUNT(*) as hires
    FROM employees
    WHERE hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month, month_label
    ORDER BY month
")->fetchAll(PDO::FETCH_ASSOC);

// Turnover analysis
$turnoverData = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN e.termination_date BETWEEN ? AND ? THEN 1 END) as terminations,
        COUNT(CASE WHEN e.hire_date BETWEEN ? AND ? THEN 1 END) as new_hires,
        (SELECT COUNT(*) FROM employees WHERE employment_status IN ('ACTIVE', 'TRIAL')) as current_total
    FROM employees e
");
$turnoverData->execute([$startDate, $endDate, $startDate, $endDate]);
$turnover = $turnoverData->fetch(PDO::FETCH_ASSOC);
$turnoverRate = $turnover['current_total'] > 0 ? ($turnover['terminations'] / $turnover['current_total']) * 100 : 0;

// Top performers (by salary - considering compensation_type)
$topPerformers = $pdo->prepare("
    SELECT 
        e.id,
        CONCAT(e.first_name, ' ', e.last_name) as name,
        e.position,
        d.name as department,
        u.compensation_type,
        CASE 
            WHEN u.compensation_type = 'hourly' THEN u.hourly_rate_dop
            ELSE 0
        END as hourly_rate,
        CASE 
            WHEN u.compensation_type = 'hourly' THEN u.hourly_rate_dop * 160
            WHEN u.compensation_type = 'fixed' THEN u.monthly_salary_dop
            ELSE 0
        END as estimated_monthly
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    AND (
        (u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0) OR
        (u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0)
    )
    ORDER BY estimated_monthly DESC
    LIMIT 10
");
$topPerformers->execute();
$performers = $topPerformers->fetchAll(PDO::FETCH_ASSOC);

$totalActive = array_sum(array_column($employeesByStatus, 'count'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análisis de Empleados - HR Reports</title>
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
                    <i class="fas fa-users-line text-blue-400 mr-3"></i>
                    Análisis de Empleados
                </h1>
                <p class="text-slate-400">Métricas demográficas, distribución y análisis de headcount</p>
            </div>
            <div class="flex gap-3">
                <form method="GET" class="flex gap-2">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="input-field text-sm">
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="input-field text-sm">
                    <button type="submit" class="btn-primary text-sm">
                        <i class="fas fa-filter mr-1"></i>Filtrar
                    </button>
                </form>
                <a href="../index.php" class="btn-secondary text-sm">
                    <i class="fas fa-arrow-left mr-1"></i>Volver
                </a>
            </div>
        </div>

        <!-- Key Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-users text-3xl text-blue-400"></i>
                    <span class="text-3xl">👥</span>
                </div>
                <p class="text-slate-400 text-sm">Total Empleados</p>
                <h3 class="text-3xl font-bold text-white"><?= $totalActive ?></h3>
            </div>
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-user-plus text-3xl text-green-400"></i>
                    <span class="text-3xl">➕</span>
                </div>
                <p class="text-slate-400 text-sm">Nuevos Ingresos</p>
                <h3 class="text-3xl font-bold text-white"><?= $turnover['new_hires'] ?></h3>
                <?php if ($turnover['new_hires'] == 0): ?>
                    <p class="text-xs text-slate-500 mt-1">En período seleccionado</p>
                <?php endif; ?>
            </div>
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-user-minus text-3xl text-red-400"></i>
                    <span class="text-3xl">➖</span>
                </div>
                <p class="text-slate-400 text-sm">Salidas</p>
                <h3 class="text-3xl font-bold text-white"><?= $turnover['terminations'] ?></h3>
                <?php if ($turnover['terminations'] == 0): ?>
                    <p class="text-xs text-slate-500 mt-1">En período seleccionado</p>
                <?php endif; ?>
            </div>
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-sync-alt text-3xl text-orange-400"></i>
                    <span class="text-3xl">📊</span>
                </div>
                <p class="text-slate-400 text-sm">Tasa Rotación</p>
                <h3 class="text-3xl font-bold text-white"><?= number_format($turnoverRate, 1) ?>%</h3>
            </div>
        </div>

        <?php if ($turnover['new_hires'] == 0 && $turnover['terminations'] == 0): ?>
            <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-4 mb-6">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-blue-400 text-xl mr-3 mt-1"></i>
                    <div>
                        <h4 class="text-blue-300 font-semibold mb-1">Información del Período</h4>
                        <p class="text-slate-300 text-sm">
                            No se registraron nuevos ingresos ni salidas en el período del <strong><?= date('d/m/Y', strtotime($startDate)) ?></strong> al <strong><?= date('d/m/Y', strtotime($endDate)) ?></strong>. 
                            Puedes ajustar el rango de fechas arriba para ver datos históricos.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Charts Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Employee Status -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-circle-check text-green-400 mr-2"></i>
                    Empleados por Estado
                </h3>
                <div style="height: 300px; position: relative;">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>

            <!-- Gender Distribution -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-venus-mars text-pink-400 mr-2"></i>
                    Distribución por Género
                </h3>
                <div style="height: 300px; position: relative;">
                    <canvas id="genderChart"></canvas>
                </div>
            </div>

            <!-- Age Distribution -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-birthday-cake text-purple-400 mr-2"></i>
                    Distribución por Edad
                </h3>
                <div style="height: 300px; position: relative;">
                    <canvas id="ageChart"></canvas>
                </div>
            </div>

            <!-- Tenure Distribution -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-clock text-blue-400 mr-2"></i>
                    Antigüedad en la Empresa
                </h3>
                <div style="height: 300px; position: relative;">
                    <canvas id="tenureChart"></canvas>
                </div>
            </div>

            <!-- Department Distribution -->
            <div class="report-card lg:col-span-2">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-building text-cyan-400 mr-2"></i>
                    Empleados por Departamento
                </h3>
                <?php if (empty($departmentData) || array_sum(array_column($departmentData, 'count')) == 0): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-building text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400">No hay datos de departamentos disponibles</p>
                    </div>
                <?php else: ?>
                    <div style="height: 250px; position: relative;">
                        <canvas id="departmentChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Salary Range Distribution -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-dollar-sign text-green-400 mr-2"></i>
                    Distribución Salarial
                </h3>
                <?php if (empty($salaryRanges) || array_sum(array_column($salaryRanges, 'count')) == 0): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-dollar-sign text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400">No hay datos salariales disponibles</p>
                        <p class="text-slate-500 text-sm mt-2">Verifica que los usuarios tengan hourly_rate configurado</p>
                    </div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;">
                        <canvas id="salaryChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Hiring Trend -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-chart-line text-indigo-400 mr-2"></i>
                    Tendencia de Contratación (12 meses)
                </h3>
                <?php if (empty($hiringTrend) || array_sum(array_column($hiringTrend, 'hires')) == 0): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-chart-line text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400">No hay contrataciones en los últimos 12 meses</p>
                    </div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;">
                        <canvas id="hiringTrendChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Performers Table -->
        <div class="report-card">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-trophy text-yellow-400 mr-2"></i>
                Top 10 Empleados por Compensación
            </h3>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">#</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Tipo</th>
                            <th class="text-right py-3 px-4 text-slate-400 font-medium">Salario Mensual</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($performers as $index => $performer): ?>
                            <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                <td class="py-3 px-4">
                                    <span class="text-white font-bold"><?= $index + 1 ?></span>
                                </td>
                                <td class="py-3 px-4 text-white"><?= htmlspecialchars($performer['name']) ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($performer['position'] ?? 'N/A') ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($performer['department'] ?? 'N/A') ?></td>
                                <td class="py-3 px-4 text-center">
                                    <?php if ($performer['compensation_type'] === 'hourly'): ?>
                                        <span class="px-2 py-1 bg-blue-500/20 text-blue-300 text-xs rounded">Por Hora</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 bg-green-500/20 text-green-300 text-xs rounded">Fijo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <span class="text-green-400 font-bold">RD$<?= number_format($performer['estimated_monthly'], 2) ?></span>
                                    <?php if ($performer['compensation_type'] === 'hourly' && $performer['hourly_rate'] > 0): ?>
                                        <span class="text-slate-500 text-xs block">RD$<?= number_format($performer['hourly_rate'], 2) ?>/hora</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Department Details Table -->
        <div class="report-card mt-6">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-sitemap text-purple-400 mr-2"></i>
                Análisis Detallado por Departamento
            </h3>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Empleados</th>
                            <th class="text-right py-3 px-4 text-slate-400 font-medium">Salario Promedio/Mes</th>
                            <th class="text-right py-3 px-4 text-slate-400 font-medium">Costo Mensual Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departmentData as $dept): ?>
                            <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($dept['department']) ?></td>
                                <td class="py-3 px-4 text-center text-slate-300"><?= $dept['count'] ?></td>
                                <td class="py-3 px-4 text-right text-blue-400">
                                    <?php if ($dept['avg_rate']): ?>
                                        RD$<?= number_format($dept['avg_rate'], 2) ?>
                                    <?php else: ?>
                                        <span class="text-slate-500">Sin datos</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-right text-green-400 font-bold">RD$<?= number_format($dept['total_cost_per_hour'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php include '../../footer.php'; ?>

    <script>
        // Status Chart
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($employeesByStatus, 'employment_status')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($employeesByStatus, 'count')) ?>,
                    backgroundColor: ['#10b981', '#f59e0b', '#94a3b8', '#ef4444']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#cbd5e1' } } }
            }
        });

        // Gender Chart
        new Chart(document.getElementById('genderChart'), {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_column($genderDistribution, 'gender')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($genderDistribution, 'count')) ?>,
                    backgroundColor: ['#3b82f6', '#ec4899', '#8b5cf6']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#cbd5e1' } } }
            }
        });

        // Age Distribution Chart
        new Chart(document.getElementById('ageChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($ageDistribution, 'age_range')) ?>,
                datasets: [{
                    label: 'Empleados',
                    data: <?= json_encode(array_column($ageDistribution, 'count')) ?>,
                    backgroundColor: '#8b5cf6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#cbd5e1' } } },
                scales: {
                    y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } },
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }
                }
            }
        });

        // Tenure Chart
        new Chart(document.getElementById('tenureChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($tenureDistribution, 'tenure_range')) ?>,
                datasets: [{
                    label: 'Empleados',
                    data: <?= json_encode(array_column($tenureDistribution, 'count')) ?>,
                    backgroundColor: '#3b82f6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#cbd5e1' } } },
                scales: {
                    y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } },
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }
                }
            }
        });

        // Department Chart
        const departmentCtx = document.getElementById('departmentChart');
        if (departmentCtx) {
            const departmentData = <?= json_encode(array_column($departmentData, 'count')) ?>;
            if (departmentData.length > 0 && departmentData.some(v => v > 0)) {
                new Chart(departmentCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode(array_column($departmentData, 'department')) ?>,
                        datasets: [{
                            label: 'Empleados',
                            data: departmentData,
                            backgroundColor: '#06b6d4'
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { labels: { color: '#cbd5e1' } } },
                        scales: {
                            y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } },
                            x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }
                        }
                    }
                });
            }
        }

        // Salary Range Chart
        const salaryCtx = document.getElementById('salaryChart');
        if (salaryCtx) {
            const salaryData = <?= json_encode(array_column($salaryRanges, 'count')) ?>;
            if (salaryData.length > 0 && salaryData.some(v => v > 0)) {
                new Chart(salaryCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode(array_column($salaryRanges, 'salary_range')) ?>,
                        datasets: [{
                            label: 'Empleados',
                            data: salaryData,
                            backgroundColor: '#10b981'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { labels: { color: '#cbd5e1' } } },
                        scales: {
                            y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } },
                            x: { ticks: { color: '#94a3b8', maxRotation: 45, minRotation: 45 }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }
                        }
                    }
                });
            }
        }

        // Hiring Trend Chart
        const hiringCtx = document.getElementById('hiringTrendChart');
        if (hiringCtx) {
            const hiringData = <?= json_encode(array_column($hiringTrend, 'hires')) ?>;
            if (hiringData.length > 0 && hiringData.some(v => v > 0)) {
                new Chart(hiringCtx, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode(array_column($hiringTrend, 'month_label')) ?>,
                        datasets: [{
                            label: 'Nuevos Ingresos',
                            data: hiringData,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99, 102, 241, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4
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
            }
        }
    </script>
</body>
</html>
