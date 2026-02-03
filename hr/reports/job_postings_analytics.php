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

// JOB POSTINGS OVERVIEW
$postingsOverview = $pdo->query("
    SELECT 
        COUNT(*) as total_postings,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
        COUNT(CASE WHEN status = 'closed' THEN 1 END) as closed,
        COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft
    FROM job_postings
")->fetch(PDO::FETCH_ASSOC);

// POSTINGS WITH APPLICATIONS
$postingsWithApps = $pdo->prepare("
    SELECT 
        jp.id,
        jp.title,
        jp.department,
        jp.employment_type as position_type,
        jp.status,
        jp.created_at,
        COUNT(ja.id) as total_applications,
        COUNT(CASE WHEN ja.status = 'hired' THEN 1 END) as hired,
        COUNT(CASE WHEN ja.status = 'interview' THEN 1 END) as in_interview,
        COUNT(CASE WHEN ja.status = 'reviewing' THEN 1 END) as in_review,
        COUNT(CASE WHEN ja.status = 'pending' THEN 1 END) as pending
    FROM job_postings jp
    LEFT JOIN job_applications ja ON jp.id = ja.job_posting_id AND ja.applied_date BETWEEN ? AND ?
    GROUP BY jp.id, jp.title, jp.department, jp.employment_type, jp.status, jp.created_at
    ORDER BY total_applications DESC
");
$postingsWithApps->execute([$startDate, $endDate]);
$postings = $postingsWithApps->fetchAll(PDO::FETCH_ASSOC);

// TOP PERFORMING POSTINGS
$topPostings = $pdo->prepare("
    SELECT 
        jp.title,
        jp.department,
        COUNT(ja.id) as applications,
        COUNT(CASE WHEN ja.status = 'hired' THEN 1 END) as hired,
        (COUNT(CASE WHEN ja.status = 'hired' THEN 1 END) / COUNT(ja.id) * 100) as conversion_rate
    FROM job_postings jp
    LEFT JOIN job_applications ja ON jp.id = ja.job_posting_id AND ja.applied_date BETWEEN ? AND ?
    GROUP BY jp.id, jp.title, jp.department
    HAVING applications > 0
    ORDER BY conversion_rate DESC, applications DESC
    LIMIT 10
");
$topPostings->execute([$startDate, $endDate]);
$topPerformers = $topPostings->fetchAll(PDO::FETCH_ASSOC);

// POSTINGS BY DEPARTMENT
$postingsByDept = $pdo->query("
    SELECT 
        department,
        COUNT(*) as count
    FROM job_postings
    WHERE status IN ('active', 'closed')
    GROUP BY department
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// APPLICATIONS OVER TIME
$applicationsOverTime = $pdo->prepare("
    SELECT 
        DATE_FORMAT(ja.applied_date, '%Y-%m') as month,
        DATE_FORMAT(ja.applied_date, '%b %Y') as month_label,
        COUNT(*) as applications
    FROM job_applications ja
    WHERE ja.applied_date >= DATE_SUB(?, INTERVAL 12 MONTH)
    GROUP BY month, month_label
    ORDER BY month ASC
");
$applicationsOverTime->execute([$endDate]);
$appsOverTime = $applicationsOverTime->fetchAll(PDO::FETCH_ASSOC);

// TOTAL APPLICATIONS IN PERIOD
$totalAppsInPeriod = $pdo->prepare("
    SELECT COUNT(*) as total FROM job_applications WHERE applied_date BETWEEN ? AND ?
");
$totalAppsInPeriod->execute([$startDate, $endDate]);
$totalApps = $totalAppsInPeriod->fetch(PDO::FETCH_ASSOC)['total'];

// AVG APPLICATIONS PER POSTING
$avgAppsPerPosting = $postingsOverview['total_postings'] > 0 ? $totalApps / $postingsOverview['total_postings'] : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análisis de Vacantes - HR Reports</title>
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
                    <i class="fas fa-briefcase text-indigo-400 mr-3"></i>Análisis de Vacantes
                </h1>
                <p class="text-slate-400">Efectividad de publicaciones de empleo y reclutamiento</p>
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
                <div class="flex items-center justify-between mb-2"><i class="fas fa-briefcase text-3xl text-indigo-400"></i><span class="text-3xl">💼</span></div>
                <p class="text-slate-400 text-sm">Total Vacantes</p>
                <h3 class="text-3xl font-bold text-white"><?= $postingsOverview['total_postings'] ?></h3>
            </div>
            <div class="stat-box bg-green-500/10 border-green-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-check-circle text-3xl text-green-400"></i><span class="text-3xl">✅</span></div>
                <p class="text-slate-400 text-sm">Activas</p>
                <h3 class="text-3xl font-bold text-white"><?= $postingsOverview['active'] ?></h3>
            </div>
            <div class="stat-box bg-blue-500/10 border-blue-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-users text-3xl text-blue-400"></i><span class="text-3xl">📊</span></div>
                <p class="text-slate-400 text-sm">Total Aplicaciones</p>
                <h3 class="text-3xl font-bold text-white"><?= $totalApps ?></h3>
            </div>
            <div class="stat-box bg-purple-500/10 border-purple-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-chart-bar text-3xl text-purple-400"></i><span class="text-3xl">📈</span></div>
                <p class="text-slate-400 text-sm">Promedio por Vacante</p>
                <h3 class="text-3xl font-bold text-white"><?= number_format($avgAppsPerPosting, 1) ?></h3>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-sitemap text-purple-400 mr-2"></i>Vacantes por Departamento</h3>
                <?php if (empty($postingsByDept)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="deptChart"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-chart-line text-green-400 mr-2"></i>Aplicaciones por Mes</h3>
                <?php if (empty($appsOverTime)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="timeChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($topPerformers)): ?>
        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-trophy text-yellow-400 mr-2"></i>Vacantes Más Exitosas</h3>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Aplicaciones</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Contratados</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Tasa de Conversión</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topPerformers as $posting): ?>
                            <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($posting['title']) ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($posting['department']) ?></td>
                                <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $posting['applications'] ?></td>
                                <td class="py-3 px-4 text-center text-green-400 font-bold"><?= $posting['hired'] ?></td>
                                <td class="py-3 px-4 text-center">
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $posting['conversion_rate'] >= 10 ? 'bg-green-500/20 text-green-300' : ($posting['conversion_rate'] >= 5 ? 'bg-yellow-500/20 text-yellow-300' : 'bg-red-500/20 text-red-300') ?>">
                                        <?= number_format($posting['conversion_rate'], 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="report-card">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-list text-indigo-400 mr-2"></i>Todas las Vacantes</h3>
            <?php if (empty($postings)): ?>
                <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay vacantes registradas</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Título</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Tipo</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Aplicaciones</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">En Revisión</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Entrevista</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Contratados</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($postings as $posting): ?>
                                <?php 
                                $statusColors = ['active' => 'bg-green-500/20 text-green-300', 'closed' => 'bg-red-500/20 text-red-300', 'draft' => 'bg-gray-500/20 text-gray-300'];
                                $statusClass = $statusColors[$posting['status']] ?? 'bg-gray-500/20 text-gray-300';
                                ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($posting['title']) ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($posting['department']) ?></td>
                                    <td class="py-3 px-4 text-center text-slate-300 text-sm"><?= htmlspecialchars($posting['position_type']) ?></td>
                                    <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $posting['total_applications'] ?></td>
                                    <td class="py-3 px-4 text-center text-yellow-400"><?= $posting['in_review'] ?></td>
                                    <td class="py-3 px-4 text-center text-purple-400"><?= $posting['in_interview'] ?></td>
                                    <td class="py-3 px-4 text-center text-green-400 font-bold"><?= $posting['hired'] ?></td>
                                    <td class="py-3 px-4 text-center"><span class="px-3 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>"><?= ucfirst($posting['status']) ?></span></td>
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
        <?php if (!empty($postingsByDept)): ?>
        new Chart(document.getElementById('deptChart'), {
            type: 'doughnut',
            data: { labels: <?= json_encode(array_column($postingsByDept, 'department')) ?>, datasets: [{ data: <?= json_encode(array_column($postingsByDept, 'count')) ?>, backgroundColor: ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#06b6d4', '#3b82f6'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } } }
        });
        <?php endif; ?>
        <?php if (!empty($appsOverTime)): ?>
        new Chart(document.getElementById('timeChart'), {
            type: 'line',
            data: { labels: <?= json_encode(array_column($appsOverTime, 'month_label')) ?>, datasets: [{ label: 'Aplicaciones', data: <?= json_encode(array_column($appsOverTime, 'applications')) ?>, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true, tension: 0.4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
    </script>
</body>
</html>

