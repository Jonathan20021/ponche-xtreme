<?php
session_start();
require_once '../../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../unauthorized.php');
    exit;
}

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// VACATION OVERVIEW
$vacationOverview = $pdo->prepare("
    SELECT 
        COUNT(*) as total_requests,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN total_days ELSE 0 END) as total_days_approved
    FROM vacation_requests
    WHERE start_date BETWEEN ? AND ?
");
$vacationOverview->execute([$startDate, $endDate]);
$overview = $vacationOverview->fetch(PDO::FETCH_ASSOC);

// VACATION BALANCE BY EMPLOYEE
$vacationBalance = $pdo->query("
    SELECT 
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        COALESCE(SUM(CASE WHEN vr.status = 'approved' THEN vr.total_days ELSE 0 END), 0) as days_used,
        (14 - COALESCE(SUM(CASE WHEN vr.status = 'approved' THEN vr.total_days ELSE 0 END), 0)) as days_remaining
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN vacation_requests vr ON e.id = vr.employee_id AND YEAR(vr.start_date) = YEAR(CURDATE())
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY e.id, e.first_name, e.last_name, e.position, d.name
    HAVING days_used > 0 OR days_remaining < 14
    ORDER BY days_remaining ASC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// VACATION BY DEPARTMENT
$vacationByDept = $pdo->prepare("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(vr.id) as requests,
        SUM(CASE WHEN vr.status = 'approved' THEN vr.total_days ELSE 0 END) as days_approved
    FROM vacation_requests vr
    JOIN employees e ON vr.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE vr.start_date BETWEEN ? AND ?
    GROUP BY d.name
    ORDER BY days_approved DESC
");
$vacationByDept->execute([$startDate, $endDate]);
$byDept = $vacationByDept->fetchAll(PDO::FETCH_ASSOC);

// MONTHLY TREND
$monthlyTrend = $pdo->prepare("
    SELECT 
        DATE_FORMAT(start_date, '%Y-%m') as month,
        DATE_FORMAT(start_date, '%b %Y') as month_label,
        COUNT(*) as requests,
        SUM(CASE WHEN status = 'approved' THEN total_days ELSE 0 END) as days_approved
    FROM vacation_requests
    WHERE start_date >= DATE_SUB(?, INTERVAL 12 MONTH)
    GROUP BY month, month_label
    ORDER BY month ASC
");
$monthlyTrend->execute([$endDate]);
$trend = $monthlyTrend->fetchAll(PDO::FETCH_ASSOC);

// RECENT REQUESTS
$recentRequests = $pdo->prepare("
    SELECT 
        vr.id,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        vr.start_date,
        vr.end_date,
        vr.total_days,
        vr.status
    FROM vacation_requests vr
    JOIN employees e ON vr.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE vr.start_date BETWEEN ? AND ?
    ORDER BY vr.created_at DESC
    LIMIT 50
");
$recentRequests->execute([$startDate, $endDate]);
$requests = $recentRequests->fetchAll(PDO::FETCH_ASSOC);

$approvalRate = $overview['total_requests'] > 0 ? ($overview['approved'] / $overview['total_requests']) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análisis de Vacaciones - HR Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link href="../../assets/css/theme.css" rel="stylesheet">
    <style>
        .report-card { background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(148, 163, 184, 0.1); border-radius: 16px; padding: 1.5rem; }
        .theme-light .report-card { background: #ffffff; border-color: rgba(148, 163, 184, 0.2); }
        .stat-box { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(99, 102, 241, 0.1) 100%); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 12px; padding: 1.25rem; }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-umbrella-beach text-cyan-400 mr-3"></i>Análisis de Vacaciones
                </h1>
                <p class="text-slate-400">Seguimiento de solicitudes y balance de vacaciones</p>
            </div>
            <div class="flex gap-3">
                <form method="GET" class="flex gap-2">
                    <input type="date" name="start_date" value="<?= $startDate ?>" class="px-3 py-2 bg-slate-800 text-white rounded-lg border border-slate-700">
                    <input type="date" name="end_date" value="<?= $endDate ?>" class="px-3 py-2 bg-slate-800 text-white rounded-lg border border-slate-700">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg"><i class="fas fa-filter mr-2"></i>Filtrar</button>
                </form>
                <a href="../index.php" class="px-6 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg"><i class="fas fa-arrow-left mr-2"></i>Volver</a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-clipboard-list text-3xl text-cyan-400"></i><span class="text-3xl">🏖️</span></div>
                <p class="text-slate-400 text-sm">Total Solicitudes</p>
                <h3 class="text-3xl font-bold text-white"><?= $overview['total_requests'] ?></h3>
            </div>
            <div class="stat-box bg-green-500/10 border-green-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-check-circle text-3xl text-green-400"></i><span class="text-3xl">✅</span></div>
                <p class="text-slate-400 text-sm">Aprobadas</p>
                <h3 class="text-3xl font-bold text-white"><?= $overview['approved'] ?></h3>
            </div>
            <div class="stat-box bg-blue-500/10 border-blue-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-calendar-day text-3xl text-blue-400"></i><span class="text-3xl">📅</span></div>
                <p class="text-slate-400 text-sm">Días Aprobados</p>
                <h3 class="text-3xl font-bold text-white"><?= $overview['total_days_approved'] ?></h3>
            </div>
            <div class="stat-box bg-purple-500/10 border-purple-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-percentage text-3xl text-purple-400"></i><span class="text-3xl">📊</span></div>
                <p class="text-slate-400 text-sm">Tasa de Aprobación</p>
                <h3 class="text-3xl font-bold text-white"><?= number_format($approvalRate, 1) ?>%</h3>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-sitemap text-purple-400 mr-2"></i>Vacaciones por Departamento</h3>
                <?php if (empty($byDept)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="deptChart"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-chart-line text-green-400 mr-2"></i>Tendencia Mensual</h3>
                <?php if (empty($trend)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos históricos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="trendChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-balance-scale text-orange-400 mr-2"></i>Balance de Vacaciones por Empleado</h3>
            <?php if (empty($vacationBalance)): ?>
                <div class="text-center py-12"><i class="fas fa-check-circle text-green-400 text-4xl mb-4"></i><p class="text-slate-400">Todos los empleados tienen su balance completo</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Días Usados</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Días Restantes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vacationBalance as $balance): ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($balance['employee_name']) ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($balance['position'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($balance['department'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $balance['days_used'] ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="font-bold <?= $balance['days_remaining'] <= 3 ? 'text-red-400' : ($balance['days_remaining'] <= 7 ? 'text-yellow-400' : 'text-green-400') ?>">
                                            <?= $balance['days_remaining'] ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="report-card">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-list text-cyan-400 mr-2"></i>Solicitudes Recientes</h3>
            <?php if (empty($requests)): ?>
                <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay solicitudes</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Inicio</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Fin</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Días</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                                <?php 
                                $statusColors = ['approved' => 'bg-green-500/20 text-green-300', 'pending' => 'bg-yellow-500/20 text-yellow-300', 'rejected' => 'bg-red-500/20 text-red-300'];
                                $statusClass = $statusColors[$req['status']] ?? 'bg-gray-500/20 text-gray-300';
                                ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($req['employee_name']) ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($req['department'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($req['start_date'])) ?></td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($req['end_date'])) ?></td>
                                    <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $req['total_days'] ?></td>
                                    <td class="py-3 px-4 text-center"><span class="px-3 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>"><?= ucfirst($req['status']) ?></span></td>
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
        <?php if (!empty($byDept)): ?>
        new Chart(document.getElementById('deptChart'), {
            type: 'bar',
            data: { labels: <?= json_encode(array_column($byDept, 'department')) ?>, datasets: [{ label: 'Días', data: <?= json_encode(array_column($byDept, 'days_approved')) ?>, backgroundColor: '#06b6d4' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
        <?php if (!empty($trend)): ?>
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: { labels: <?= json_encode(array_column($trend, 'month_label')) ?>, datasets: [{ label: 'Días Aprobados', data: <?= json_encode(array_column($trend, 'days_approved')) ?>, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true, tension: 0.4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
    </script>
</body>
</html>

