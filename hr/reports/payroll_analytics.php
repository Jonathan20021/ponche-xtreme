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
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

// ============ PAYROLL OVERVIEW ============
$payrollOverview = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT pp.id) as total_periods,
        COUNT(DISTINCT pr.id) as total_records,
        SUM(pr.gross_salary) as total_gross,
        SUM(pr.total_deductions) as total_deductions,
        SUM(pr.net_salary) as total_net,
        AVG(pr.net_salary) as avg_net_salary
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pp.id = pr.payroll_period_id
    WHERE pp.start_date >= ? AND pp.end_date <= ?
");
$payrollOverview->execute([$startDate, $endDate]);
$overview = $payrollOverview->fetch(PDO::FETCH_ASSOC);

// ============ PAYROLL BY PERIOD ============
$payrollByPeriod = $pdo->prepare("
    SELECT 
        pp.id,
        pp.name,
        pp.period_type,
        pp.start_date,
        pp.end_date,
        pp.payment_date,
        pp.status,
        pp.total_gross,
        pp.total_deductions,
        pp.total_net,
        COUNT(pr.id) as employee_count
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pp.id = pr.payroll_period_id
    WHERE pp.start_date >= ? AND pp.end_date <= ?
    GROUP BY pp.id, pp.name, pp.period_type, pp.start_date, pp.end_date, pp.payment_date, pp.status, pp.total_gross, pp.total_deductions, pp.total_net
    ORDER BY pp.start_date DESC
");
$payrollByPeriod->execute([$startDate, $endDate]);
$periods = $payrollByPeriod->fetchAll(PDO::FETCH_ASSOC);

// ============ PAYROLL TREND (LAST 6 MONTHS) ============
$payrollTrend = $pdo->query("
    SELECT 
        DATE_FORMAT(pp.start_date, '%Y-%m') as month,
        DATE_FORMAT(pp.start_date, '%b %Y') as month_label,
        SUM(pp.total_gross) as total_gross,
        SUM(pp.total_net) as total_net,
        COUNT(DISTINCT pr.employee_id) as employee_count
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pp.id = pr.payroll_period_id
    WHERE pp.start_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month, month_label
    ORDER BY month ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ============ DEDUCTION BREAKDOWN ============
$deductionBreakdown = $pdo->prepare("
    SELECT 
        SUM(pr.afp_employee) as total_afp,
        SUM(pr.sfs_employee) as total_sfs,
        SUM(pr.isr) as total_isr,
        SUM(pr.other_deductions) as total_other
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.payroll_period_id = pp.id
    WHERE pp.start_date >= ? AND pp.end_date <= ?
");
$deductionBreakdown->execute([$startDate, $endDate]);
$deductions = $deductionBreakdown->fetch(PDO::FETCH_ASSOC);

// ============ TOP EARNERS ============
$topEarners = $pdo->prepare("
    SELECT 
        e.id,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        AVG(pr.gross_salary) as avg_gross,
        AVG(pr.net_salary) as avg_net,
        COUNT(pr.id) as payroll_count
    FROM payroll_records pr
    JOIN employees e ON pr.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    JOIN payroll_periods pp ON pr.payroll_period_id = pp.id
    WHERE pp.start_date >= ? AND pp.end_date <= ?
    GROUP BY e.id, e.first_name, e.last_name, e.position, d.name
    ORDER BY avg_gross DESC
    LIMIT 10
");
$topEarners->execute([$startDate, $endDate]);
$earners = $topEarners->fetchAll(PDO::FETCH_ASSOC);

// ============ PAYROLL BY DEPARTMENT ============
$payrollByDept = $pdo->prepare("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(DISTINCT pr.employee_id) as employee_count,
        SUM(pr.gross_salary) as total_gross,
        SUM(pr.net_salary) as total_net,
        AVG(pr.net_salary) as avg_net
    FROM payroll_records pr
    JOIN employees e ON pr.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    JOIN payroll_periods pp ON pr.payroll_period_id = pp.id
    WHERE pp.start_date >= ? AND pp.end_date <= ?
    GROUP BY d.name
    ORDER BY total_gross DESC
");
$payrollByDept->execute([$startDate, $endDate]);
$deptPayroll = $payrollByDept->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análisis de Nómina - HR Reports</title>
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
                    <i class="fas fa-money-check-alt text-green-400 mr-3"></i>
                    Análisis de Nómina
                </h1>
                <p class="text-slate-400">Resumen de costos de nómina y tendencias salariales</p>
            </div>
            <div class="flex gap-3">
                <form method="GET" class="flex gap-2">
                    <input type="month" name="start_date" value="<?= date('Y-m', strtotime($startDate)) ?>" 
                           class="px-3 py-2 bg-slate-800 text-white rounded-lg border border-slate-700">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                        <i class="fas fa-filter mr-2"></i>Filtrar
                    </button>
                </form>
                <a href="../index.php" class="px-6 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                    <i class="fas fa-arrow-left mr-2"></i>Volver
                </a>
            </div>
        </div>

        <!-- Key Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-calendar-check text-3xl text-blue-400"></i>
                    <span class="text-3xl">📅</span>
                </div>
                <p class="text-slate-400 text-sm">Períodos Procesados</p>
                <h3 class="text-3xl font-bold text-white"><?= $overview['total_periods'] ?></h3>
            </div>
            <div class="stat-box bg-green-500/10 border-green-500/30">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-dollar-sign text-3xl text-green-400"></i>
                    <span class="text-3xl">💵</span>
                </div>
                <p class="text-slate-400 text-sm">Total Bruto</p>
                <h3 class="text-2xl font-bold text-white">RD$<?= number_format($overview['total_gross'], 0) ?></h3>
            </div>
            <div class="stat-box bg-red-500/10 border-red-500/30">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-minus-circle text-3xl text-red-400"></i>
                    <span class="text-3xl">➖</span>
                </div>
                <p class="text-slate-400 text-sm">Total Descuentos</p>
                <h3 class="text-2xl font-bold text-white">RD$<?= number_format($overview['total_deductions'], 0) ?></h3>
            </div>
            <div class="stat-box bg-blue-500/10 border-blue-500/30">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-hand-holding-usd text-3xl text-blue-400"></i>
                    <span class="text-3xl">💰</span>
                </div>
                <p class="text-slate-400 text-sm">Total Neto</p>
                <h3 class="text-2xl font-bold text-white">RD$<?= number_format($overview['total_net'], 0) ?></h3>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Payroll Trend -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-chart-line text-green-400 mr-2"></i>
                    Tendencia de Nómina (6 meses)
                </h3>
                <?php if (empty($payrollTrend)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400">No hay datos históricos</p>
                    </div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;">
                        <canvas id="trendChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Deduction Breakdown -->
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-chart-pie text-red-400 mr-2"></i>
                    Desglose de Descuentos
                </h3>
                <?php if ($deductions['total_afp'] + $deductions['total_sfs'] + $deductions['total_isr'] + $deductions['total_other'] == 0): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400">No hay descuentos registrados</p>
                    </div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;">
                        <canvas id="deductionChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payroll by Department -->
        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-sitemap text-purple-400 mr-2"></i>
                Nómina por Departamento
            </h3>
            <?php if (empty($deptPayroll)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                    <p class="text-slate-400">No hay datos de nómina por departamento</p>
                </div>
            <?php else: ?>
                <div style="height: 350px; position: relative;">
                    <canvas id="deptChart"></canvas>
                </div>
            <?php endif; ?>
        </div>

        <!-- Top Earners Table -->
        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-trophy text-yellow-400 mr-2"></i>
                Top 10 Mayores Compensaciones
            </h3>
            <?php if (empty($earners)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                    <p class="text-slate-400">No hay datos disponibles</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">#</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                                <th class="text-right py-3 px-4 text-slate-400 font-medium">Salario Bruto Promedio</th>
                                <th class="text-right py-3 px-4 text-slate-400 font-medium">Salario Neto Promedio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($earners as $index => $earner): ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4">
                                        <span class="text-white font-bold"><?= $index + 1 ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($earner['employee_name']) ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($earner['position'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($earner['department'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-right text-blue-400 font-bold">RD$<?= number_format($earner['avg_gross'], 2) ?></td>
                                    <td class="py-3 px-4 text-right text-green-400 font-bold">RD$<?= number_format($earner['avg_net'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payroll Periods Table -->
        <div class="report-card">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-list text-blue-400 mr-2"></i>
                Períodos de Nómina
            </h3>
            <?php if (empty($periods)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                    <p class="text-slate-400">No hay períodos de nómina en este rango</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Período</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Tipo</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Fecha Pago</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Empleados</th>
                                <th class="text-right py-3 px-4 text-slate-400 font-medium">Total Bruto</th>
                                <th class="text-right py-3 px-4 text-slate-400 font-medium">Total Neto</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periods as $period): ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($period['name']) ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-2 py-1 bg-blue-500/20 text-blue-300 text-xs rounded">
                                            <?= htmlspecialchars($period['period_type']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($period['payment_date'])) ?></td>
                                    <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $period['employee_count'] ?></td>
                                    <td class="py-3 px-4 text-right text-blue-400">RD$<?= number_format($period['total_gross'], 2) ?></td>
                                    <td class="py-3 px-4 text-right text-green-400 font-bold">RD$<?= number_format($period['total_net'], 2) ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <?php 
                                        $statusColors = [
                                            'DRAFT' => 'bg-gray-500/20 text-gray-300',
                                            'CALCULATED' => 'bg-blue-500/20 text-blue-300',
                                            'APPROVED' => 'bg-green-500/20 text-green-300',
                                            'PAID' => 'bg-green-500/30 text-green-200'
                                        ];
                                        $statusClass = $statusColors[$period['status']] ?? 'bg-gray-500/20 text-gray-300';
                                        ?>
                                        <span class="px-2 py-1 <?= $statusClass ?> text-xs rounded-full">
                                            <?= htmlspecialchars($period['status']) ?>
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
        <?php if (!empty($payrollTrend)): ?>
        // Trend Chart
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($payrollTrend, 'month_label')) ?>,
                datasets: [
                    {
                        label: 'Bruto',
                        data: <?= json_encode(array_column($payrollTrend, 'total_gross')) ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        fill: true
                    },
                    {
                        label: 'Neto',
                        data: <?= json_encode(array_column($payrollTrend, 'total_net')) ?>,
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
                    y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } },
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }
                }
            }
        });
        <?php endif; ?>

        <?php if ($deductions['total_afp'] + $deductions['total_sfs'] + $deductions['total_isr'] + $deductions['total_other'] > 0): ?>
        // Deduction Chart
        new Chart(document.getElementById('deductionChart'), {
            type: 'doughnut',
            data: {
                labels: ['AFP', 'SFS', 'ISR', 'Otros'],
                datasets: [{
                    data: [
                        <?= $deductions['total_afp'] ?>,
                        <?= $deductions['total_sfs'] ?>,
                        <?= $deductions['total_isr'] ?>,
                        <?= $deductions['total_other'] ?>
                    ],
                    backgroundColor: ['#ef4444', '#f59e0b', '#8b5cf6', '#64748b']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#cbd5e1' } } }
            }
        });
        <?php endif; ?>

        <?php if (!empty($deptPayroll)): ?>
        // Department Chart
        new Chart(document.getElementById('deptChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($deptPayroll, 'department')) ?>,
                datasets: [{
                    label: 'Total Neto',
                    data: <?= json_encode(array_column($deptPayroll, 'total_net')) ?>,
                    backgroundColor: '#8b5cf6'
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
        <?php endif; ?>
    </script>
</body>
</html>
