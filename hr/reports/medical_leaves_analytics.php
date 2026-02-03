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

// MEDICAL LEAVES OVERVIEW
$leavesOverview = $pdo->prepare("
    SELECT 
        COUNT(*) as total_leaves,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN total_days ELSE 0 END) as total_days,
        AVG(CASE WHEN status = 'approved' THEN total_days ELSE 0 END) as avg_days_per_leave
    FROM medical_leaves
    WHERE start_date BETWEEN ? AND ?
");
$leavesOverview->execute([$startDate, $endDate]);
$overview = $leavesOverview->fetch(PDO::FETCH_ASSOC);

// LEAVES BY TYPE
$leavesByType = $pdo->prepare("
    SELECT 
        leave_type,
        COUNT(*) as count,
        SUM(CASE WHEN status = 'approved' THEN total_days ELSE 0 END) as total_days
    FROM medical_leaves
    WHERE start_date BETWEEN ? AND ?
    GROUP BY leave_type
    ORDER BY count DESC
");
$leavesByType->execute([$startDate, $endDate]);
$byType = $leavesByType->fetchAll(PDO::FETCH_ASSOC);

// LEAVES BY DEPARTMENT
$leavesByDept = $pdo->prepare("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(ml.id) as count,
        SUM(CASE WHEN ml.status = 'approved' THEN ml.total_days ELSE 0 END) as total_days
    FROM medical_leaves ml
    JOIN employees e ON ml.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE ml.start_date BETWEEN ? AND ?
    GROUP BY d.name
    ORDER BY count DESC
");
$leavesByDept->execute([$startDate, $endDate]);
$byDept = $leavesByDept->fetchAll(PDO::FETCH_ASSOC);

// MONTHLY TREND
$monthlyTrend = $pdo->prepare("
    SELECT 
        DATE_FORMAT(start_date, '%Y-%m') as month,
        DATE_FORMAT(start_date, '%b %Y') as month_label,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'approved' THEN total_days ELSE 0 END) as days
    FROM medical_leaves
    WHERE start_date >= DATE_SUB(?, INTERVAL 12 MONTH)
    GROUP BY month, month_label
    ORDER BY month ASC
");
$monthlyTrend->execute([$endDate]);
$trend = $monthlyTrend->fetchAll(PDO::FETCH_ASSOC);

// EMPLOYEES WITH MULTIPLE LEAVES
$frequentLeaves = $pdo->prepare("
    SELECT 
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        COUNT(ml.id) as leave_count,
        SUM(CASE WHEN ml.status = 'approved' THEN ml.total_days ELSE 0 END) as total_days
    FROM medical_leaves ml
    JOIN employees e ON ml.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE ml.start_date BETWEEN ? AND ?
    GROUP BY e.id, e.first_name, e.last_name, e.position, d.name
    HAVING leave_count > 1
    ORDER BY leave_count DESC, total_days DESC
    LIMIT 20
");
$frequentLeaves->execute([$startDate, $endDate]);
$frequent = $frequentLeaves->fetchAll(PDO::FETCH_ASSOC);

// RECENT MEDICAL LEAVES
$recentLeaves = $pdo->prepare("
    SELECT 
        ml.id,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        ml.leave_type,
        ml.start_date,
        ml.end_date,
        ml.total_days,
        ml.status
    FROM medical_leaves ml
    JOIN employees e ON ml.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE ml.start_date BETWEEN ? AND ?
    ORDER BY ml.created_at DESC
    LIMIT 50
");
$recentLeaves->execute([$startDate, $endDate]);
$leaves = $recentLeaves->fetchAll(PDO::FETCH_ASSOC);

$approvalRate = $overview['total_leaves'] > 0 ? ($overview['approved'] / $overview['total_leaves']) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análisis de Licencias Médicas - HR Reports</title>
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
                    <i class="fas fa-hospital text-red-400 mr-3"></i>Análisis de Licencias Médicas
                </h1>
                <p class="text-slate-400">Seguimiento de ausencias por motivos de salud</p>
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

        <?php if ($overview['total_leaves'] == 0): ?>
            <div class="report-card text-center py-20">
                <i class="fas fa-check-circle text-green-400 text-6xl mb-6"></i>
                <h2 class="text-2xl font-bold text-white mb-3">¡Excelente!</h2>
                <p class="text-slate-400 text-lg mb-2">No hay licencias médicas registradas en este período</p>
                <p class="text-slate-500">Esto indica una buena salud general del equipo</p>
            </div>
        <?php else: ?>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-clipboard-list text-3xl text-red-400"></i><span class="text-3xl">🏥</span></div>
                <p class="text-slate-400 text-sm">Total Licencias</p>
                <h3 class="text-3xl font-bold text-white"><?= $overview['total_leaves'] ?></h3>
            </div>
            <div class="stat-box bg-green-500/10 border-green-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-calendar-check text-3xl text-green-400"></i><span class="text-3xl">📅</span></div>
                <p class="text-slate-400 text-sm">Días Totales</p>
                <h3 class="text-3xl font-bold text-white"><?= number_format($overview['total_days'] ?? 0) ?></h3>
            </div>
            <div class="stat-box bg-blue-500/10 border-blue-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-chart-line text-3xl text-blue-400"></i><span class="text-3xl">📊</span></div>
                <p class="text-slate-400 text-sm">Promedio por Licencia</p>
                <h3 class="text-3xl font-bold text-white"><?= number_format($overview['avg_days_per_leave'] ?? 0, 1) ?> días</h3>
            </div>
            <div class="stat-box bg-purple-500/10 border-purple-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-percentage text-3xl text-purple-400"></i><span class="text-3xl">✅</span></div>
                <p class="text-slate-400 text-sm">Tasa de Aprobación</p>
                <h3 class="text-3xl font-bold text-white"><?= number_format($approvalRate, 1) ?>%</h3>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-tags text-blue-400 mr-2"></i>Licencias por Tipo</h3>
                <?php if (empty($byType)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="typeChart"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-sitemap text-purple-400 mr-2"></i>Licencias por Departamento</h3>
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

        <?php if (!empty($frequent)): ?>
        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-exclamation-triangle text-yellow-400 mr-2"></i>Empleados con Múltiples Licencias</h3>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Cantidad</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Total Días</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($frequent as $emp): ?>
                            <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($emp['employee_name']) ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($emp['position'] ?? 'N/A') ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($emp['department'] ?? 'N/A') ?></td>
                                <td class="py-3 px-4 text-center">
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $emp['leave_count'] >= 4 ? 'bg-red-500/20 text-red-300' : 'bg-yellow-500/20 text-yellow-300' ?>">
                                        <?= $emp['leave_count'] ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $emp['total_days'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="report-card">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-list text-orange-400 mr-2"></i>Licencias Recientes</h3>
            <?php if (empty($leaves)): ?>
                <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay licencias registradas</p></div>
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
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Días</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaves as $leave): ?>
                                <?php 
                                $statusColors = ['approved' => 'bg-green-500/20 text-green-300', 'pending' => 'bg-yellow-500/20 text-yellow-300', 'rejected' => 'bg-red-500/20 text-red-300'];
                                $statusClass = $statusColors[$leave['status']] ?? 'bg-gray-500/20 text-gray-300';
                                ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($leave['employee_name']) ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($leave['department'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($leave['leave_type'] ?? 'General') ?></td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($leave['start_date'])) ?></td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($leave['end_date'])) ?></td>
                                    <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $leave['total_days'] ?></td>
                                    <td class="py-3 px-4 text-center"><span class="px-3 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>"><?= ucfirst($leave['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>

    <?php include '../../footer.php'; ?>
    <script>
        <?php if (!empty($byType)): ?>
        new Chart(document.getElementById('typeChart'), {
            type: 'doughnut',
            data: { labels: <?= json_encode(array_column($byType, 'leave_type')) ?>, datasets: [{ data: <?= json_encode(array_column($byType, 'count')) ?>, backgroundColor: ['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } } }
        });
        <?php endif; ?>
        <?php if (!empty($byDept)): ?>
        new Chart(document.getElementById('deptChart'), {
            type: 'bar',
            data: { labels: <?= json_encode(array_column($byDept, 'department')) ?>, datasets: [{ label: 'Licencias', data: <?= json_encode(array_column($byDept, 'count')) ?>, backgroundColor: '#ef4444' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8', stepSize: 1 }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
        <?php if (!empty($trend)): ?>
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: { labels: <?= json_encode(array_column($trend, 'month_label')) ?>, datasets: [{ label: 'Licencias', data: <?= json_encode(array_column($trend, 'total')) ?>, borderColor: '#ef4444', backgroundColor: 'rgba(239, 68, 68, 0.1)', fill: true }, { label: 'Días', data: <?= json_encode(array_column($trend, 'days')) ?>, borderColor: '#f59e0b', backgroundColor: 'rgba(245, 158, 11, 0.1)', fill: true }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
    </script>
</body>
</html>

