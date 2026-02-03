<?php
session_start();
require_once '../../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../unauthorized.php');
    exit;
}

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// UPCOMING BIRTHDAYS (Next 30 days)
$upcomingBirthdays = $pdo->query("
    SELECT 
        e.id,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        e.birth_date,
        DAYOFMONTH(e.birth_date) as birth_day,
        MONTH(e.birth_date) as birth_month,
        YEAR(CURDATE()) - YEAR(e.birth_date) + 
            IF(DATE_FORMAT(CURDATE(), '%m-%d') > DATE_FORMAT(e.birth_date, '%m-%d'), 1, 0) as age_upcoming,
        DATEDIFF(
            DATE_ADD(
                DATE(CONCAT(YEAR(CURDATE()), '-', LPAD(MONTH(e.birth_date), 2, '0'), '-', LPAD(DAYOFMONTH(e.birth_date), 2, '0'))),
                INTERVAL IF(
                    DATE_FORMAT(CURDATE(), '%m-%d') > DATE_FORMAT(e.birth_date, '%m-%d'),
                    1,
                    0
                ) YEAR
            ),
            CURDATE()
        ) as days_until
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.birth_date IS NOT NULL
    AND e.employment_status IN ('ACTIVE', 'TRIAL')
    HAVING days_until >= 0 AND days_until <= 30
    ORDER BY days_until ASC, birth_month, birth_day
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// UPCOMING ANNIVERSARIES (Next 60 days)
$upcomingAnniversaries = $pdo->query("
    SELECT 
        e.id,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        e.hire_date,
        YEAR(CURDATE()) - YEAR(e.hire_date) + 
            IF(DATE_FORMAT(CURDATE(), '%m-%d') > DATE_FORMAT(e.hire_date, '%m-%d'), 1, 0) as years_upcoming,
        DATEDIFF(
            DATE_ADD(
                DATE(CONCAT(YEAR(CURDATE()), '-', LPAD(MONTH(e.hire_date), 2, '0'), '-', LPAD(DAYOFMONTH(e.hire_date), 2, '0'))),
                INTERVAL IF(
                    DATE_FORMAT(CURDATE(), '%m-%d') > DATE_FORMAT(e.hire_date, '%m-%d'),
                    1,
                    0
                ) YEAR
            ),
            CURDATE()
        ) as days_until
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.hire_date IS NOT NULL
    AND e.employment_status IN ('ACTIVE', 'TRIAL')
    HAVING days_until >= 0 AND days_until <= 60 AND years_upcoming > 0
    ORDER BY days_until ASC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// BIRTHDAYS BY MONTH (Current Year)
$birthdaysByMonth = $pdo->query("
    SELECT 
        MONTH(birth_date) as month_num,
        DATE_FORMAT(birth_date, '%b') as month_name,
        COUNT(*) as count
    FROM employees
    WHERE birth_date IS NOT NULL
    AND employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY month_num, month_name
    ORDER BY month_num
")->fetchAll(PDO::FETCH_ASSOC);

// ANNIVERSARIES BY YEAR
$anniversariesByYear = $pdo->query("
    SELECT 
        YEAR(CURDATE()) - YEAR(hire_date) as years,
        COUNT(*) as count
    FROM employees
    WHERE hire_date IS NOT NULL
    AND employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY years
    HAVING years > 0
    ORDER BY years ASC
")->fetchAll(PDO::FETCH_ASSOC);

// STATISTICS
$totalBirthdays = count($upcomingBirthdays);
$birthdaysThisWeek = count(array_filter($upcomingBirthdays, fn($b) => $b['days_until'] <= 7));
$totalAnniversaries = count($upcomingAnniversaries);
$anniversariesThisMonth = count(array_filter($upcomingAnniversaries, fn($a) => $a['days_until'] <= 30));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario de Eventos - HR Reports</title>
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
                    <i class="fas fa-calendar-alt text-pink-400 mr-3"></i>Calendario de Eventos
                </h1>
                <p class="text-slate-400">Cumpleaños y aniversarios laborales del equipo</p>
            </div>
            <a href="../index.php" class="px-6 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg"><i class="fas fa-arrow-left mr-2"></i>Volver</a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-box bg-pink-500/10 border-pink-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-birthday-cake text-3xl text-pink-400"></i><span class="text-3xl">🎂</span></div>
                <p class="text-slate-400 text-sm">Cumpleaños (30 días)</p>
                <h3 class="text-3xl font-bold text-white"><?= $totalBirthdays ?></h3>
            </div>
            <div class="stat-box bg-purple-500/10 border-purple-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-calendar-week text-3xl text-purple-400"></i><span class="text-3xl">📅</span></div>
                <p class="text-slate-400 text-sm">Esta Semana</p>
                <h3 class="text-3xl font-bold text-white"><?= $birthdaysThisWeek ?></h3>
            </div>
            <div class="stat-box bg-blue-500/10 border-blue-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-trophy text-3xl text-blue-400"></i><span class="text-3xl">🏆</span></div>
                <p class="text-slate-400 text-sm">Aniversarios (60 días)</p>
                <h3 class="text-3xl font-bold text-white"><?= $totalAnniversaries ?></h3>
            </div>
            <div class="stat-box bg-green-500/10 border-green-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-calendar-check text-3xl text-green-400"></i><span class="text-3xl">✨</span></div>
                <p class="text-slate-400 text-sm">Este Mes</p>
                <h3 class="text-3xl font-bold text-white"><?= $anniversariesThisMonth ?></h3>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-chart-bar text-pink-400 mr-2"></i>Cumpleaños por Mes</h3>
                <?php if (empty($birthdaysByMonth)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="birthdaysChart"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-chart-pie text-blue-400 mr-2"></i>Aniversarios por Antigüedad</h3>
                <?php if (empty($anniversariesByYear)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="anniversariesChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-birthday-cake text-pink-400 mr-2"></i>Próximos Cumpleaños (30 días)</h3>
            <?php if (empty($upcomingBirthdays)): ?>
                <div class="text-center py-12"><i class="fas fa-check-circle text-green-400 text-4xl mb-4"></i><p class="text-slate-400">No hay cumpleaños próximos</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Fecha</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Edad</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">En</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingBirthdays as $birthday): ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($birthday['employee_name']) ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($birthday['position'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($birthday['department'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d M', strtotime($birthday['birth_date'])) ?></td>
                                    <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $birthday['age_upcoming'] ?> años</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $birthday['days_until'] == 0 ? 'bg-pink-500/20 text-pink-300' : ($birthday['days_until'] <= 7 ? 'bg-purple-500/20 text-purple-300' : 'bg-blue-500/20 text-blue-300') ?>">
                                            <?= $birthday['days_until'] == 0 ? 'HOY' : $birthday['days_until'] . ' días' ?>
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
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-trophy text-blue-400 mr-2"></i>Próximos Aniversarios Laborales (60 días)</h3>
            <?php if (empty($upcomingAnniversaries)): ?>
                <div class="text-center py-12"><i class="fas fa-check-circle text-green-400 text-4xl mb-4"></i><p class="text-slate-400">No hay aniversarios próximos</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Fecha Ingreso</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Años</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">En</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingAnniversaries as $anniversary): ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($anniversary['employee_name']) ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($anniversary['position'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($anniversary['department'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($anniversary['hire_date'])) ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="font-bold <?= $anniversary['years_upcoming'] >= 5 ? 'text-yellow-400' : ($anniversary['years_upcoming'] >= 3 ? 'text-green-400' : 'text-blue-400') ?>">
                                            <?= $anniversary['years_upcoming'] ?> años
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $anniversary['days_until'] == 0 ? 'bg-blue-500/20 text-blue-300' : ($anniversary['days_until'] <= 7 ? 'bg-purple-500/20 text-purple-300' : 'bg-green-500/20 text-green-300') ?>">
                                            <?= $anniversary['days_until'] == 0 ? 'HOY' : $anniversary['days_until'] . ' días' ?>
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
        <?php if (!empty($birthdaysByMonth)): ?>
        new Chart(document.getElementById('birthdaysChart'), {
            type: 'bar',
            data: { labels: <?= json_encode(array_column($birthdaysByMonth, 'month_name')) ?>, datasets: [{ label: 'Cumpleaños', data: <?= json_encode(array_column($birthdaysByMonth, 'count')) ?>, backgroundColor: '#ec4899' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8', stepSize: 1 }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
        <?php if (!empty($anniversariesByYear)): ?>
        new Chart(document.getElementById('anniversariesChart'), {
            type: 'doughnut',
            data: { labels: <?= json_encode(array_map(fn($a) => $a['years'] . ' año' . ($a['years'] > 1 ? 's' : ''), $anniversariesByYear)) ?>, datasets: [{ data: <?= json_encode(array_column($anniversariesByYear, 'count')) ?>, backgroundColor: ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#ec4899'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } } }
        });
        <?php endif; ?>
    </script>
</body>
</html>
