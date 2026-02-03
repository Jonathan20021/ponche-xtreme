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

// PERMISSIONS OVERVIEW
$permissionsOverview = $pdo->prepare("
    SELECT 
        COUNT(*) as total_requests,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
        SUM(CASE WHEN status = 'approved' THEN total_hours ELSE 0 END) as total_hours_approved
    FROM permission_requests
    WHERE start_date BETWEEN ? AND ?
");
$permissionsOverview->execute([$startDate, $endDate]);
$overview = $permissionsOverview->fetch(PDO::FETCH_ASSOC);

// PERMISSIONS BY TYPE
$permissionsByType = $pdo->prepare("
    SELECT 
        request_type,
        COUNT(*) as count,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count,
        SUM(CASE WHEN status = 'approved' THEN total_hours ELSE 0 END) as total_hours
    FROM permission_requests
    WHERE start_date BETWEEN ? AND ?
    GROUP BY request_type
    ORDER BY count DESC
");
$permissionsByType->execute([$startDate, $endDate]);
$byType = $permissionsByType->fetchAll(PDO::FETCH_ASSOC);

// PERMISSIONS BY DEPARTMENT
$permissionsByDept = $pdo->prepare("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(pr.id) as count,
        COUNT(CASE WHEN pr.status = 'approved' THEN 1 END) as approved
    FROM permission_requests pr
    JOIN employees e ON pr.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE pr.start_date BETWEEN ? AND ?
    GROUP BY d.name
    ORDER BY count DESC
");
$permissionsByDept->execute([$startDate, $endDate]);
$byDept = $permissionsByDept->fetchAll(PDO::FETCH_ASSOC);

// MONTHLY TREND
$monthlyTrend = $pdo->prepare("
    SELECT 
        DATE_FORMAT(start_date, '%Y-%m') as month,
        DATE_FORMAT(start_date, '%b %Y') as month_label,
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved
    FROM permission_requests
    WHERE start_date >= DATE_SUB(?, INTERVAL 12 MONTH)
    GROUP BY month, month_label
    ORDER BY month ASC
");
$monthlyTrend->execute([$endDate]);
$trend = $monthlyTrend->fetchAll(PDO::FETCH_ASSOC);

// RECENT REQUESTS
$recentRequests = $pdo->prepare("
    SELECT 
        pr.id,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        pr.request_type,
        pr.start_date,
        pr.end_date,
        pr.status,
        pr.total_hours as hours
    FROM permission_requests pr
    JOIN employees e ON pr.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE pr.start_date BETWEEN ? AND ?
    ORDER BY pr.created_at DESC
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
    <title>Análisis de Permisos - HR Reports</title>
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
                    <i class="fas fa-user-clock text-orange-400 mr-3"></i>Análisis de Permisos
                </h1>
                <p class="text-slate-400">Seguimiento de solicitudes de permisos y ausencias</p>
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
                <div class="flex items-center justify-between mb-2"><i class="fas fa-clipboard-list text-3xl text-blue-400"></i><span class="text-3xl">📋</span></div>
                <p class="text-slate-400 text-sm">Total Solicitudes</p>
                <h3 class="text-3xl font-bold text-white"><?= $overview['total_requests'] ?></h3>
            </div>
            <div class="stat-box bg-green-500/10 border-green-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-check-circle text-3xl text-green-400"></i><span class="text-3xl">✅</span></div>
                <p class="text-slate-400 text-sm">Aprobados</p>
                <h3 class="text-3xl font-bold text-white"><?= $overview['approved'] ?></h3>
            </div>
            <div class="stat-box bg-yellow-500/10 border-yellow-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-clock text-3xl text-yellow-400"></i><span class="text-3xl">⏳</span></div>
                <p class="text-slate-400 text-sm">Pendientes</p>
                <h3 class="text-3xl font-bold text-white"><?= $overview['pending'] ?></h3>
            </div>
            <div class="stat-box bg-purple-500/10 border-purple-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-percentage text-3xl text-purple-400"></i><span class="text-3xl">📊</span></div>
                <p class="text-slate-400 text-sm">Tasa de Aprobación</p>
                <h3 class="text-3xl font-bold text-white"><?= number_format($approvalRate, 1) ?>%</h3>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-tags text-blue-400 mr-2"></i>Permisos por Tipo</h3>
                <?php if (empty($byType)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="typeChart"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-sitemap text-purple-400 mr-2"></i>Permisos por Departamento</h3>
                <?php if (empty($byDept)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="deptChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-chart-line text-green-400 mr-2"></i>Tendencia Mensual (12 meses)</h3>
            <?php if (empty($trend)): ?>
                <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos históricos</p></div>
            <?php else: ?>
                <div style="height: 350px; position: relative;"><canvas id="trendChart"></canvas></div>
            <?php endif; ?>
        </div>

        <div class="report-card">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-list text-orange-400 mr-2"></i>Solicitudes Recientes</h3>
            <?php if (empty($requests)): ?>
                <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay solicitudes</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Tipo</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Inicio</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Fin</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Horas</th>
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
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($req['request_type']) ?></td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y H:i', strtotime($req['start_date'])) ?></td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y H:i', strtotime($req['end_date'])) ?></td>
                                    <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $req['hours'] ?>h</td>
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
        <?php if (!empty($byType)): ?>
        new Chart(document.getElementById('typeChart'), {
            type: 'doughnut',
            data: { labels: <?= json_encode(array_column($byType, 'request_type')) ?>, datasets: [{ data: <?= json_encode(array_column($byType, 'count')) ?>, backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } } }
        });
        <?php endif; ?>
        <?php if (!empty($byDept)): ?>
        new Chart(document.getElementById('deptChart'), {
            type: 'bar',
            data: { labels: <?= json_encode(array_column($byDept, 'department')) ?>, datasets: [{ label: 'Total', data: <?= json_encode(array_column($byDept, 'count')) ?>, backgroundColor: '#8b5cf6' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8', stepSize: 1 }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
        <?php if (!empty($trend)): ?>
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: { labels: <?= json_encode(array_column($trend, 'month_label')) ?>, datasets: [{ label: 'Total', data: <?= json_encode(array_column($trend, 'total')) ?>, borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.1)', fill: true }, { label: 'Aprobados', data: <?= json_encode(array_column($trend, 'approved')) ?>, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8', stepSize: 1 }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
    </script>
</body>
</html>
