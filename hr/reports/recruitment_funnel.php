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

// RECRUITMENT FUNNEL OVERVIEW
$funnelOverview = $pdo->prepare("
    SELECT 
        COUNT(*) as total_applications,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'reviewing' THEN 1 END) as reviewing,
        COUNT(CASE WHEN status = 'interview' THEN 1 END) as interview,
        COUNT(CASE WHEN status = 'hired' THEN 1 END) as hired,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected
    FROM job_applications
    WHERE applied_date BETWEEN ? AND ?
");
$funnelOverview->execute([$startDate, $endDate]);
$funnel = $funnelOverview->fetch(PDO::FETCH_ASSOC);

// CONVERSION RATES
$conversionRates = [];
if ($funnel['total_applications'] > 0) {
    $conversionRates['to_review'] = ($funnel['reviewing'] / $funnel['total_applications']) * 100;
    $conversionRates['to_interview'] = ($funnel['interview'] / $funnel['total_applications']) * 100;
    $conversionRates['to_hired'] = ($funnel['hired'] / $funnel['total_applications']) * 100;
}

// TIME TO HIRE CALCULATION
$timeToHire = $pdo->prepare("
    SELECT 
        AVG(DATEDIFF(last_updated, applied_date)) as avg_days
    FROM job_applications
    WHERE status = 'hired' 
    AND applied_date BETWEEN ? AND ?
    AND last_updated IS NOT NULL
");
$timeToHire->execute([$startDate, $endDate]);
$avgTimeToHire = $timeToHire->fetch(PDO::FETCH_ASSOC)['avg_days'] ?? 0;

// APPLICATIONS BY POSITION
$applicationsByPosition = $pdo->prepare("
    SELECT 
        jp.title as position,
        COUNT(ja.id) as applications,
        COUNT(CASE WHEN ja.status = 'hired' THEN 1 END) as hired
    FROM job_applications ja
    JOIN job_postings jp ON ja.job_posting_id = jp.id
    WHERE ja.applied_date BETWEEN ? AND ?
    GROUP BY jp.title
    ORDER BY applications DESC
    LIMIT 10
");
$applicationsByPosition->execute([$startDate, $endDate]);
$byPosition = $applicationsByPosition->fetchAll(PDO::FETCH_ASSOC);

// MONTHLY APPLICATION TREND
$monthlyTrend = $pdo->prepare("
    SELECT 
        DATE_FORMAT(applied_date, '%Y-%m') as month,
        DATE_FORMAT(applied_date, '%b %Y') as month_label,
        COUNT(*) as applications,
        COUNT(CASE WHEN status = 'hired' THEN 1 END) as hired
    FROM job_applications
    WHERE applied_date >= DATE_SUB(?, INTERVAL 12 MONTH)
    GROUP BY month, month_label
    ORDER BY month ASC
");
$monthlyTrend->execute([$endDate]);
$trend = $monthlyTrend->fetchAll(PDO::FETCH_ASSOC);

// TOP CANDIDATES
$topCandidates = $pdo->prepare("
    SELECT 
        ja.id,
        CONCAT(ja.first_name, ' ', ja.last_name) as applicant_name,
        ja.email as applicant_email,
        ja.phone as applicant_phone,
        jp.title as position,
        ja.applied_date,
        ja.status,
        DATEDIFF(CURDATE(), ja.applied_date) as days_in_process
    FROM job_applications ja
    JOIN job_postings jp ON ja.job_posting_id = jp.id
    WHERE ja.applied_date BETWEEN ? AND ?
    AND ja.status IN ('interview', 'reviewing')
    ORDER BY ja.applied_date ASC
    LIMIT 30
");
$topCandidates->execute([$startDate, $endDate]);
$candidates = $topCandidates->fetchAll(PDO::FETCH_ASSOC);

// RECENT HIRES
$recentHires = $pdo->prepare("
    SELECT 
        CONCAT(ja.first_name, ' ', ja.last_name) as applicant_name,
        jp.title as position,
        ja.applied_date,
        ja.last_updated as hired_date,
        DATEDIFF(ja.last_updated, ja.applied_date) as days_to_hire
    FROM job_applications ja
    JOIN job_postings jp ON ja.job_posting_id = jp.id
    WHERE ja.status = 'hired'
    AND ja.applied_date BETWEEN ? AND ?
    ORDER BY ja.last_updated DESC
    LIMIT 20
");
$recentHires->execute([$startDate, $endDate]);
$hires = $recentHires->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Embudo de Reclutamiento - HR Reports</title>
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
                    <i class="fas fa-funnel-dollar text-emerald-400 mr-3"></i>Embudo de Reclutamiento
                </h1>
                <p class="text-slate-400">Análisis del proceso de selección y contratación</p>
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
                <div class="flex items-center justify-between mb-2"><i class="fas fa-users text-3xl text-blue-400"></i><span class="text-3xl">📝</span></div>
                <p class="text-slate-400 text-sm">Total Aplicaciones</p>
                <h3 class="text-3xl font-bold text-white"><?= $funnel['total_applications'] ?></h3>
            </div>
            <div class="stat-box bg-green-500/10 border-green-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-user-check text-3xl text-green-400"></i><span class="text-3xl">✅</span></div>
                <p class="text-slate-400 text-sm">Contratados</p>
                <h3 class="text-3xl font-bold text-white"><?= $funnel['hired'] ?></h3>
            </div>
            <div class="stat-box bg-purple-500/10 border-purple-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-percentage text-3xl text-purple-400"></i><span class="text-3xl">📊</span></div>
                <p class="text-slate-400 text-sm">Tasa de Conversión</p>
                <h3 class="text-3xl font-bold text-white"><?= number_format($conversionRates['to_hired'] ?? 0, 1) ?>%</h3>
            </div>
            <div class="stat-box bg-orange-500/10 border-orange-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-clock text-3xl text-orange-400"></i><span class="text-3xl">⏱️</span></div>
                <p class="text-slate-400 text-sm">Tiempo Promedio</p>
                <h3 class="text-3xl font-bold text-white"><?= round($avgTimeToHire) ?> días</h3>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-filter text-blue-400 mr-2"></i>Embudo de Conversión</h3>
                <?php if ($funnel['total_applications'] == 0): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 350px; position: relative;"><canvas id="funnelChart"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-briefcase text-purple-400 mr-2"></i>Aplicaciones por Posición</h3>
                <?php if (empty($byPosition)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 350px; position: relative;"><canvas id="positionChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-chart-line text-green-400 mr-2"></i>Tendencia de Aplicaciones (12 meses)</h3>
            <?php if (empty($trend)): ?>
                <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos históricos</p></div>
            <?php else: ?>
                <div style="height: 350px; position: relative;"><canvas id="trendChart"></canvas></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($candidates)): ?>
        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-user-tie text-yellow-400 mr-2"></i>Candidatos en Proceso</h3>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Candidato</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Email</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Fecha Aplicación</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Días en Proceso</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $candidate): ?>
                            <?php 
                            $statusColors = ['interview' => 'bg-blue-500/20 text-blue-300', 'reviewing' => 'bg-yellow-500/20 text-yellow-300'];
                            $statusClass = $statusColors[$candidate['status']] ?? 'bg-gray-500/20 text-gray-300';
                            ?>
                            <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($candidate['applicant_name']) ?></td>
                                <td class="py-3 px-4 text-slate-300 text-sm"><?= htmlspecialchars($candidate['applicant_email']) ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($candidate['position']) ?></td>
                                <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($candidate['applied_date'])) ?></td>
                                <td class="py-3 px-4 text-center">
                                    <span class="font-bold <?= $candidate['days_in_process'] > 30 ? 'text-red-400' : ($candidate['days_in_process'] > 15 ? 'text-yellow-400' : 'text-green-400') ?>">
                                        <?= $candidate['days_in_process'] ?> días
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center"><span class="px-3 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>"><?= ucfirst($candidate['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($hires)): ?>
        <div class="report-card">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-user-plus text-green-400 mr-2"></i>Contrataciones Recientes</h3>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Nombre</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Fecha Aplicación</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Fecha Contratación</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Tiempo de Proceso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hires as $hire): ?>
                            <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($hire['applicant_name']) ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($hire['position']) ?></td>
                                <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($hire['applied_date'])) ?></td>
                                <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($hire['hired_date'])) ?></td>
                                <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $hire['days_to_hire'] ?> días</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php include '../../footer.php'; ?>
    <script>
        <?php if ($funnel['total_applications'] > 0): ?>
        new Chart(document.getElementById('funnelChart'), {
            type: 'bar',
            data: { 
                labels: ['Aplicaciones', 'En Revisión', 'Entrevista', 'Contratados', 'Rechazados'],
                datasets: [{ 
                    label: 'Candidatos', 
                    data: [<?= $funnel['total_applications'] ?>, <?= $funnel['reviewing'] ?>, <?= $funnel['interview'] ?>, <?= $funnel['hired'] ?>, <?= $funnel['rejected'] ?>], 
                    backgroundColor: ['#3b82f6', '#f59e0b', '#8b5cf6', '#10b981', '#ef4444'] 
                }] 
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
        <?php if (!empty($byPosition)): ?>
        new Chart(document.getElementById('positionChart'), {
            type: 'bar',
            data: { labels: <?= json_encode(array_column($byPosition, 'position')) ?>, datasets: [{ label: 'Aplicaciones', data: <?= json_encode(array_column($byPosition, 'applications')) ?>, backgroundColor: '#8b5cf6' }, { label: 'Contratados', data: <?= json_encode(array_column($byPosition, 'hired')) ?>, backgroundColor: '#10b981' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
        <?php if (!empty($trend)): ?>
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: { labels: <?= json_encode(array_column($trend, 'month_label')) ?>, datasets: [{ label: 'Aplicaciones', data: <?= json_encode(array_column($trend, 'applications')) ?>, borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.1)', fill: true }, { label: 'Contratados', data: <?= json_encode(array_column($trend, 'hired')) ?>, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
    </script>
</body>
</html>

