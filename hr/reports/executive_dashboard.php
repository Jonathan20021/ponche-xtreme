<?php
session_start();
require_once '../../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../unauthorized.php');
    exit;
}

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Date range filter (default: current month)
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

// ============ KEY METRICS ============
// Total Employees
$totalEmployees = $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status IN ('ACTIVE', 'TRIAL')")->fetchColumn();

// New Hires in period
$newHires = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE hire_date BETWEEN ? AND ? AND employment_status IN ('ACTIVE', 'TRIAL')");
$newHires->execute([$startDate, $endDate]);
$newHiresCount = $newHires->fetchColumn();

// Terminations in period
$terminations = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE termination_date BETWEEN ? AND ?");
$terminations->execute([$startDate, $endDate]);
$terminationsCount = $terminations->fetchColumn();

// Turnover Rate
$turnoverRate = $totalEmployees > 0 ? ($terminationsCount / $totalEmployees) * 100 : 0;

// Active Campaigns
$activeCampaigns = $pdo->query("SELECT COUNT(*) FROM campaigns WHERE is_active = 1")->fetchColumn();

// Pending Approvals (permissions + vacations)
$pendingApprovals = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM permission_requests WHERE status = 'PENDING') +
        (SELECT COUNT(*) FROM vacation_requests WHERE status = 'PENDING')
")->fetchColumn();

// ============ PAYROLL METRICS ============
$payrollData = $pdo->prepare("
    SELECT 
        SUM(pr.gross_salary) as total_gross,
        SUM(pr.net_salary) as total_net,
        COUNT(*) as employee_count,
        AVG(pr.gross_salary) as avg_salary
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.payroll_period_id = pp.id
    WHERE pp.start_date >= ? AND pp.end_date <= ?
");
$payrollData->execute([$startDate, $endDate]);
$payroll = $payrollData->fetch(PDO::FETCH_ASSOC);

// ============ TRIAL PERIOD METRICS ============
$trialMetrics = $pdo->query("
    SELECT 
        COUNT(*) as total_in_trial,
        COUNT(CASE WHEN DATEDIFF(DATE_ADD(hire_date, INTERVAL 90 DAY), CURDATE()) <= 15 THEN 1 END) as ending_soon,
        COUNT(CASE WHEN DATEDIFF(DATE_ADD(hire_date, INTERVAL 90 DAY), CURDATE()) < 0 THEN 1 END) as overdue
    FROM employees
    WHERE employment_status = 'TRIAL'
")->fetch(PDO::FETCH_ASSOC);

// ============ ABSENCES & LEAVE ============
$absenceMetrics = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM permission_requests WHERE start_date BETWEEN ? AND ?) as permissions,
        (SELECT COUNT(*) FROM vacation_requests WHERE start_date BETWEEN ? AND ?) as vacations,
        (SELECT COUNT(*) FROM medical_leaves WHERE start_date BETWEEN ? AND ?) as medical_leaves
");
$absenceMetrics->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
$absences = $absenceMetrics->fetch(PDO::FETCH_ASSOC);

// ============ RECRUITMENT METRICS ============
$recruitmentMetrics = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT ja.id) as total_applications,
        COUNT(DISTINCT CASE WHEN ja.status = 'hired' THEN ja.id END) as hired,
        COUNT(DISTINCT jp.id) as active_postings,
        AVG(DATEDIFF(ja.last_updated, ja.applied_date)) as avg_time_to_hire
    FROM job_applications ja
    LEFT JOIN job_postings jp ON jp.id = ja.job_posting_id
    WHERE ja.applied_date BETWEEN ? AND ?
");
$recruitmentMetrics->execute([$startDate, $endDate]);
$recruitment = $recruitmentMetrics->fetch(PDO::FETCH_ASSOC);

// ============ CAMPAIGN PERFORMANCE ============
$campaignPerformance = $pdo->query("
    SELECT 
        c.id,
        c.name,
        c.color,
        COUNT(DISTINCT e.id) as employee_count,
        AVG(
            CASE 
                WHEN u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0 THEN u.hourly_rate_dop * 160
                WHEN u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0 THEN u.monthly_salary_dop
                ELSE NULL
            END
        ) as avg_monthly_cost
    FROM campaigns c
    LEFT JOIN employees e ON c.id = e.campaign_id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE c.is_active = 1
    GROUP BY c.id, c.name, c.color
    ORDER BY employee_count DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ============ EMPLOYEE DISTRIBUTION BY DEPARTMENT ============
$departmentDistribution = $pdo->query("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(e.id) as count
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY d.name
    ORDER BY count DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ============ UPCOMING CRITICAL DATES ============
$criticalDates = $pdo->query("
    SELECT 
        'trial_ending' as type,
        CONCAT(e.first_name, ' ', e.last_name) as description,
        DATE_ADD(e.hire_date, INTERVAL 90 DAY) as event_date,
        DATEDIFF(DATE_ADD(e.hire_date, INTERVAL 90 DAY), CURDATE()) as days_until
    FROM employees e
    WHERE e.employment_status = 'TRIAL'
    HAVING days_until BETWEEN 0 AND 15
    ORDER BY days_until ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ============ MONTHLY TREND DATA (Last 6 months) ============
$monthlyTrends = $pdo->query("
    SELECT 
        DATE_FORMAT(month_date, '%Y-%m') as month,
        DATE_FORMAT(month_date, '%b %Y') as month_label,
        
        (SELECT COUNT(*) FROM employees 
         WHERE hire_date <= LAST_DAY(month_date) 
         AND (termination_date IS NULL OR termination_date > LAST_DAY(month_date))
         AND employment_status IN ('ACTIVE', 'TRIAL')) as headcount,
        
        (SELECT COUNT(*) FROM employees 
         WHERE DATE_FORMAT(hire_date, '%Y-%m') = DATE_FORMAT(month_date, '%Y-%m')) as new_hires,
        
        (SELECT COUNT(*) FROM employees 
         WHERE DATE_FORMAT(termination_date, '%Y-%m') = DATE_FORMAT(month_date, '%Y-%m')) as terminations
    
    FROM (
        SELECT DATE_SUB(CURDATE(), INTERVAL 5 MONTH) as month_date
        UNION SELECT DATE_SUB(CURDATE(), INTERVAL 4 MONTH)
        UNION SELECT DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
        UNION SELECT DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
        UNION SELECT DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
        UNION SELECT CURDATE()
    ) months
    ORDER BY month
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Ejecutivo - HR</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link href="../../assets/css/theme.css" rel="stylesheet">
    <style>
        .metric-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.9) 0%, rgba(15, 23, 42, 0.9) 100%);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--card-color, #3b82f6) 0%, var(--card-color-end, #2563eb) 100%);
        }
        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            border-color: rgba(59, 130, 246, 0.4);
        }
        .theme-light .metric-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-color: rgba(148, 163, 184, 0.3);
        }
        .chart-container {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            padding: 1.5rem;
        }
        .theme-light .chart-container {
            background: #ffffff;
            border-color: rgba(148, 163, 184, 0.2);
        }
        .alert-badge {
            animation: pulse-alert 2s infinite;
        }
        @keyframes pulse-alert {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .trend-up { color: #10b981; }
        .trend-down { color: #ef4444; }
        .trend-neutral { color: #94a3b8; }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2 flex items-center">
                    <i class="fas fa-chart-mixed text-blue-400 mr-3"></i>
                    Dashboard Ejecutivo de HR
                    <span class="ml-3 text-xs px-3 py-1 rounded-full bg-blue-500/30 text-blue-300 border border-blue-500/50">
                        <i class="fas fa-crown mr-1"></i>Premium
                    </span>
                </h1>
                <p class="text-slate-400">Vista estratégica integral para toma de decisiones gerenciales</p>
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

        <!-- Key Performance Indicators -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Total Employees -->
            <div class="metric-card" style="--card-color: #3b82f6; --card-color-end: #2563eb;">
                <div class="flex items-center justify-between mb-2">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                    <span class="text-2xl">👥</span>
                </div>
                <p class="text-slate-400 text-sm mb-1">Total Empleados</p>
                <h3 class="text-3xl font-bold text-white mb-2"><?= number_format($totalEmployees) ?></h3>
                <div class="flex items-center text-sm">
                    <span class="trend-up mr-2">
                        <i class="fas fa-arrow-up mr-1"></i><?= $newHiresCount ?>
                    </span>
                    <span class="text-slate-400">nuevos</span>
                    <?php if ($terminationsCount > 0): ?>
                        <span class="trend-down ml-3 mr-2">
                            <i class="fas fa-arrow-down mr-1"></i><?= $terminationsCount ?>
                        </span>
                        <span class="text-slate-400">salidas</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Turnover Rate -->
            <div class="metric-card" style="--card-color: #f59e0b; --card-color-end: #d97706;">
                <div class="flex items-center justify-between mb-2">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                        <i class="fas fa-sync-alt text-white text-xl"></i>
                    </div>
                    <span class="text-2xl">📊</span>
                </div>
                <p class="text-slate-400 text-sm mb-1">Tasa de Rotación</p>
                <h3 class="text-3xl font-bold text-white mb-2"><?= number_format($turnoverRate, 1) ?>%</h3>
                <div class="text-sm">
                    <span class="<?= $turnoverRate <= 5 ? 'trend-up' : ($turnoverRate <= 10 ? 'trend-neutral' : 'trend-down') ?>">
                        <i class="fas fa-circle mr-1 text-xs"></i>
                        <?= $turnoverRate <= 5 ? 'Excelente' : ($turnoverRate <= 10 ? 'Normal' : 'Requiere atención') ?>
                    </span>
                </div>
            </div>

            <!-- Payroll Cost -->
            <div class="metric-card" style="--card-color: #10b981; --card-color-end: #059669;">
                <div class="flex items-center justify-between mb-2">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                        <i class="fas fa-dollar-sign text-white text-xl"></i>
                    </div>
                    <span class="text-2xl">💰</span>
                </div>
                <p class="text-slate-400 text-sm mb-1">Costo de Nómina</p>
                <h3 class="text-3xl font-bold text-white mb-2">RD$<?= number_format($payroll['total_gross'] ?? 0, 0) ?></h3>
                <div class="text-sm text-slate-400">
                    Promedio: RD$<?= number_format($payroll['avg_salary'] ?? 0, 0) ?>
                </div>
            </div>

            <!-- Pending Approvals -->
            <div class="metric-card" style="--card-color: #8b5cf6; --card-color-end: #7c3aed;">
                <div class="flex items-center justify-between mb-2">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                        <i class="fas fa-clipboard-check text-white text-xl"></i>
                    </div>
                    <?php if ($pendingApprovals > 10): ?>
                        <span class="alert-badge text-2xl">⚠️</span>
                    <?php else: ?>
                        <span class="text-2xl">✅</span>
                    <?php endif; ?>
                </div>
                <p class="text-slate-400 text-sm mb-1">Aprobaciones Pendientes</p>
                <h3 class="text-3xl font-bold text-white mb-2"><?= $pendingApprovals ?></h3>
                <div class="text-sm">
                    <span class="<?= $pendingApprovals > 10 ? 'trend-down' : 'trend-up' ?>">
                        <i class="fas fa-circle mr-1 text-xs"></i>
                        <?= $pendingApprovals > 10 ? 'Requiere atención' : 'Bajo control' ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Secondary Metrics Row -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <!-- Trial Period -->
            <div class="metric-card" style="--card-color: #ec4899; --card-color-end: #db2777;">
                <div class="flex items-center justify-between mb-3">
                    <i class="fas fa-user-clock text-3xl" style="color: #ec4899;"></i>
                    <?php if ($trialMetrics['overdue'] > 0): ?>
                        <span class="alert-badge text-lg">⏰</span>
                    <?php endif; ?>
                </div>
                <p class="text-slate-400 text-xs mb-1">En Período de Prueba</p>
                <h4 class="text-2xl font-bold text-white mb-2"><?= $trialMetrics['total_in_trial'] ?></h4>
                <div class="text-xs text-slate-400">
                    <?= $trialMetrics['ending_soon'] ?> finalizan pronto
                </div>
            </div>

            <!-- Active Campaigns -->
            <div class="metric-card" style="--card-color: #a855f7; --card-color-end: #9333ea;">
                <div class="flex items-center justify-between mb-3">
                    <i class="fas fa-bullhorn text-3xl" style="color: #a855f7;"></i>
                </div>
                <p class="text-slate-400 text-xs mb-1">Campañas Activas</p>
                <h4 class="text-2xl font-bold text-white mb-2"><?= $activeCampaigns ?></h4>
                <div class="text-xs text-slate-400">
                    Operaciones en curso
                </div>
            </div>

            <!-- Recruitment -->
            <div class="metric-card" style="--card-color: #06b6d4; --card-color-end: #0891b2;">
                <div class="flex items-center justify-between mb-3">
                    <i class="fas fa-user-plus text-3xl" style="color: #06b6d4;"></i>
                </div>
                <p class="text-slate-400 text-xs mb-1">Aplicaciones Activas</p>
                <h4 class="text-2xl font-bold text-white mb-2"><?= $recruitment['total_applications'] ?? 0 ?></h4>
                <div class="text-xs text-slate-400">
                    <?= $recruitment['hired'] ?? 0 ?> contratados
                </div>
            </div>

            <!-- Absences -->
            <div class="metric-card" style="--card-color: #ef4444; --card-color-end: #dc2626;">
                <div class="flex items-center justify-between mb-3">
                    <i class="fas fa-calendar-xmark text-3xl" style="color: #ef4444;"></i>
                </div>
                <p class="text-slate-400 text-xs mb-1">Ausencias Totales</p>
                <h4 class="text-2xl font-bold text-white mb-2"><?= ($absences['permissions'] ?? 0) + ($absences['vacations'] ?? 0) + ($absences['medical_leaves'] ?? 0) ?></h4>
                <div class="text-xs text-slate-400">
                    En período seleccionado
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Headcount Trend -->
            <div class="chart-container">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-chart-line text-blue-400 mr-2"></i>
                    Tendencia de Headcount (6 meses)
                </h3>
                <div style="height: 300px; position: relative;">
                    <canvas id="headcountChart"></canvas>
                </div>
            </div>

            <!-- Department Distribution -->
            <div class="chart-container">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-chart-pie text-purple-400 mr-2"></i>
                    Distribución por Departamento
                </h3>
                <div style="height: 300px; position: relative;">
                    <canvas id="departmentChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Campaign Performance & Critical Dates -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Campaign Performance -->
            <div class="chart-container">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-bullhorn text-purple-400 mr-2"></i>
                    Top 5 Campañas por Personal
                </h3>
                <?php if (empty($campaignPerformance)): ?>
                    <p class="text-slate-400 text-center py-8">No hay datos de campañas disponibles</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($campaignPerformance as $campaign): ?>
                            <div class="p-4 bg-slate-800/50 rounded-lg border border-slate-700/50 hover:border-slate-600 transition-colors">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center">
                                        <div class="w-4 h-4 rounded mr-3" style="background: <?= htmlspecialchars($campaign['color']) ?>;"></div>
                                        <span class="text-white font-medium"><?= htmlspecialchars($campaign['name']) ?></span>
                                    </div>
                                    <span class="text-slate-300 font-bold"><?= $campaign['employee_count'] ?> empleados</span>
                                </div>
                                <div class="text-sm">
                                    <span class="text-slate-400">Costo promedio mensual:</span>
                                    <span class="text-blue-400 ml-2">RD$<?= number_format($campaign['avg_monthly_cost'], 2) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Critical Dates & Alerts -->
            <div class="chart-container">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-bell text-yellow-400 mr-2"></i>
                    Fechas Críticas y Alertas
                </h3>
                <?php if (empty($criticalDates)): ?>
                    <p class="text-slate-400 text-center py-8">No hay fechas críticas próximas</p>
                <?php else: ?>
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        <?php foreach ($criticalDates as $date): 
                            $urgency = $date['days_until'] <= 7 ? 'high' : ($date['days_until'] <= 15 ? 'medium' : 'low');
                            $iconClass = $date['type'] === 'trial_ending' ? 'fa-user-clock' : 'fa-file-contract';
                            $colorClass = $urgency === 'high' ? 'border-red-500/50 bg-red-500/10' : ($urgency === 'medium' ? 'border-yellow-500/50 bg-yellow-500/10' : 'border-blue-500/50 bg-blue-500/10');
                        ?>
                            <div class="p-3 rounded-lg border <?= $colorClass ?> transition-all hover:scale-102">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-start flex-1">
                                        <i class="fas <?= $iconClass ?> text-lg mt-1 mr-3 <?= $urgency === 'high' ? 'text-red-400' : ($urgency === 'medium' ? 'text-yellow-400' : 'text-blue-400') ?>"></i>
                                        <div>
                                            <p class="text-white font-medium"><?= htmlspecialchars($date['description']) ?></p>
                                            <p class="text-slate-400 text-sm"><?= date('d/m/Y', strtotime($date['event_date'])) ?></p>
                                        </div>
                                    </div>
                                    <span class="text-xs px-2 py-1 rounded-full <?= $urgency === 'high' ? 'bg-red-500/30 text-red-300' : ($urgency === 'medium' ? 'bg-yellow-500/30 text-yellow-300' : 'bg-blue-500/30 text-blue-300') ?> whitespace-nowrap ml-2">
                                        <?= $date['days_until'] == 0 ? 'Hoy' : ($date['days_until'] == 1 ? 'Mañana' : $date['days_until'] . ' días') ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="chart-container">
            <h3 class="text-xl font-semibold text-white mb-4 flex items-center">
                <i class="fas fa-bolt text-yellow-400 mr-2"></i>
                Acciones Rápidas
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="../employees.php" class="p-4 bg-blue-500/20 hover:bg-blue-500/30 border border-blue-500/30 rounded-lg text-center transition-all">
                    <i class="fas fa-users text-blue-400 text-2xl mb-2"></i>
                    <p class="text-white text-sm font-medium">Ver Empleados</p>
                </a>
                <a href="../trial_period.php" class="p-4 bg-orange-500/20 hover:bg-orange-500/30 border border-orange-500/30 rounded-lg text-center transition-all">
                    <i class="fas fa-hourglass-half text-orange-400 text-2xl mb-2"></i>
                    <p class="text-white text-sm font-medium">Período Prueba</p>
                </a>
                <a href="../permissions.php" class="p-4 bg-purple-500/20 hover:bg-purple-500/30 border border-purple-500/30 rounded-lg text-center transition-all">
                    <i class="fas fa-clipboard-list text-purple-400 text-2xl mb-2"></i>
                    <p class="text-white text-sm font-medium">Aprobar Permisos</p>
                </a>
                <a href="../payroll.php" class="p-4 bg-green-500/20 hover:bg-green-500/30 border border-green-500/30 rounded-lg text-center transition-all">
                    <i class="fas fa-money-bill-wave text-green-400 text-2xl mb-2"></i>
                    <p class="text-white text-sm font-medium">Gestionar Nómina</p>
                </a>
            </div>
        </div>
    </div>

    <?php include '../../footer.php'; ?>

    <script>
        // Headcount Trend Chart
        const headcountCtx = document.getElementById('headcountChart');
        if (headcountCtx) {
            new Chart(headcountCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_column($monthlyTrends, 'month_label')) ?>,
                    datasets: [{
                        label: 'Headcount Total',
                        data: <?= json_encode(array_column($monthlyTrends, 'headcount')) ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Nuevos Ingresos',
                        data: <?= json_encode(array_column($monthlyTrends, 'new_hires')) ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Salidas',
                        data: <?= json_encode(array_column($monthlyTrends, 'terminations')) ?>,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: { color: '#cbd5e1', font: { size: 12 } }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#94a3b8' },
                            grid: { color: 'rgba(148, 163, 184, 0.1)' }
                        },
                        x: {
                            ticks: { color: '#94a3b8' },
                            grid: { color: 'rgba(148, 163, 184, 0.1)' }
                        }
                    }
                }
            });
        }

        // Department Distribution Chart
        const departmentCtx = document.getElementById('departmentChart');
        if (departmentCtx) {
            new Chart(departmentCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_column($departmentDistribution, 'department')) ?>,
                    datasets: [{
                        data: <?= json_encode(array_column($departmentDistribution, 'count')) ?>,
                        backgroundColor: [
                            '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', 
                            '#10b981', '#06b6d4', '#ef4444', '#a855f7'
                        ],
                        borderWidth: 2,
                        borderColor: 'rgba(30, 41, 59, 0.5)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { 
                                color: '#cbd5e1', 
                                font: { size: 11 },
                                padding: 15
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
