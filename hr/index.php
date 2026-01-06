<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_dashboard', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Get statistics
$totalEmployees = $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status IN ('ACTIVE', 'TRIAL')")->fetchColumn();
$trialEmployees = $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'TRIAL'")->fetchColumn();
$pendingPermissions = $pdo->query("SELECT COUNT(*) FROM permission_requests WHERE status = 'PENDING'")->fetchColumn();
$pendingVacations = $pdo->query("SELECT COUNT(*) FROM vacation_requests WHERE status = 'PENDING'")->fetchColumn();
$activeCampaigns = $pdo->query("SELECT COUNT(*) FROM campaigns WHERE is_active = 1")->fetchColumn();

// Get upcoming birthdays (next 30 days)
$upcomingBirthdays = $pdo->query("
    SELECT e.first_name, e.last_name, e.birth_date, u.username,
           DATEDIFF(
               DATE_ADD(e.birth_date, INTERVAL YEAR(CURDATE()) - YEAR(e.birth_date) + IF(DAYOFYEAR(e.birth_date) < DAYOFYEAR(CURDATE()), 1, 0) YEAR),
               CURDATE()
           ) as days_until
    FROM employees e
    JOIN users u ON u.id = e.user_id
    WHERE e.birth_date IS NOT NULL
    AND e.employment_status IN ('ACTIVE', 'TRIAL')
    HAVING days_until BETWEEN 0 AND 30
    ORDER BY days_until ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Get employees ending trial period soon (next 15 days)
$endingTrial = $pdo->query("
    SELECT e.first_name, e.last_name, e.hire_date, u.username,
           DATEDIFF(DATE_ADD(e.hire_date, INTERVAL 90 DAY), CURDATE()) as days_remaining,
           DATE_ADD(e.hire_date, INTERVAL 90 DAY) as trial_end_date
    FROM employees e
    JOIN users u ON u.id = e.user_id
    WHERE e.employment_status = 'TRIAL'
    HAVING days_remaining BETWEEN 0 AND 15
    ORDER BY days_remaining ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Get recent permission requests
$recentPermissions = $pdo->query("
    SELECT pr.*, e.first_name, e.last_name, u.username
    FROM permission_requests pr
    JOIN employees e ON e.id = pr.employee_id
    JOIN users u ON u.id = pr.user_id
    ORDER BY pr.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Get recent vacation requests
$recentVacations = $pdo->query("
    SELECT vr.*, e.first_name, e.last_name, u.username
    FROM vacation_requests vr
    JOIN employees e ON e.id = vr.employee_id
    JOIN users u ON u.id = vr.user_id
    ORDER BY vr.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Recursos Humanos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .stat-card {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(99, 102, 241, 0.2);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .module-card {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .module-card:hover {
            background: rgba(30, 41, 59, 0.8);
            border-color: rgba(99, 102, 241, 0.5);
            transform: translateY(-2px);
        }
        .theme-light .module-card {
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        .theme-light .module-card:hover {
            background: rgba(255, 255, 255, 1);
        }
        .monitor-summary-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            padding: 1.25rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        .monitor-summary-card:hover {
            background: rgba(30, 41, 59, 0.9);
            border-color: rgba(99, 102, 241, 0.3);
            transform: translateY(-2px);
        }
        .summary-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .employee-monitor-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            padding: 1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .employee-monitor-card:hover {
            background: rgba(30, 41, 59, 0.8);
            border-color: rgba(99, 102, 241, 0.4);
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }
        .employee-monitor-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--campaign-color, #6b7280);
            opacity: 0.8;
        }
        .live-dot {
            width: 8px;
            height: 8px;
            background-color: #4ade80;
            border-radius: 50%;
            display: inline-block;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(74, 222, 128, 0); }
            100% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); }
        }
        .status-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-active { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); }
        .status-pause { background: rgba(234, 179, 8, 0.2); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.3); }
        .status-offline { background: rgba(148, 163, 184, 0.2); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3); }
        .status-completed { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        
        .theme-light .monitor-summary-card, 
        .theme-light .employee-monitor-card {
            background: rgba(255, 255, 255, 0.8);
            border-color: rgba(148, 163, 184, 0.2);
        }
        .theme-light .monitor-summary-card:hover,
        .theme-light .employee-monitor-card:hover {
            background: #ffffff;
        }
        .theme-light h2, .theme-light h3 { color: #1e293b; }
        .theme-light p { color: #64748b; }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-users-cog text-indigo-400 mr-3"></i>
                    Recursos Humanos
                </h1>
                <p class="text-slate-400">Sistema completo de gestión de personal</p>
            </div>
            <a href="../dashboard.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Volver al Dashboard
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total Empleados</p>
                        <h3 class="text-3xl font-bold text-white"><?= $totalEmployees ?></h3>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                        <i class="fas fa-users text-white"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">En Período de Prueba</p>
                        <h3 class="text-3xl font-bold text-white"><?= $trialEmployees ?></h3>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                        <i class="fas fa-user-clock text-white"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Permisos Pendientes</p>
                        <h3 class="text-3xl font-bold text-white"><?= $pendingPermissions ?></h3>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                        <i class="fas fa-file-alt text-white"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Campañas Activas</p>
                        <h3 class="text-3xl font-bold text-white"><?= $activeCampaigns ?></h3>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #a855f7 0%, #9333ea 100%);">
                        <i class="fas fa-bullhorn text-white"></i>
                    </div>
                </div>
            </div>
        </div>

        <?php /* ?>
        <!-- Real-Time Employee Monitor -->
        <div class="monitor-summary-card mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-white flex items-center">
                    <i class="fas fa-users-viewfinder mr-3 text-green-400"></i>
                    Monitor en Tiempo Real
                    <span class="ml-3 px-2 py-1 bg-green-500/20 text-green-400 text-xs rounded-full" id="live-indicator">
                        <i class="fas fa-circle animate-pulse mr-1"></i>
                        EN VIVO
                    </span>
                </h2>
                <div class="text-sm text-slate-400" id="last-update">
                    Actualizado: <span id="update-time">--:--:--</span>
                </div>
            </div>
            
            <!-- Search and Filter Controls -->
            <div class="mb-6">
                <div class="flex flex-col lg:flex-row gap-4 items-center justify-between">
                    <div class="flex flex-col md:flex-row gap-4 flex-1">
                        <!-- Search Input -->
                        <div class="flex-1 max-w-md">
                            <div class="relative">
                                <input type="text" 
                                       id="employee-search" 
                                       placeholder="Buscar empleados por nombre, posición..." 
                                       class="w-full bg-slate-800/50 border border-slate-700/50 rounded-lg px-4 py-2 pl-10 text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400"></i>
                            </div>
                        </div>
                        
                        <!-- Campaign Filter -->
                        <div class="min-w-[200px]">
                            <div class="relative">
                                <select id="campaign-filter" class="w-full bg-slate-800/50 border border-slate-700/50 rounded-lg px-4 py-2 pr-10 text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 appearance-none">
                                    <option value="all">Todas las Campañas</option>
                                    <!-- Campaign options will be populated dynamically -->
                                </select>
                                <i class="fas fa-chevron-down absolute right-3 top-1/2 transform -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>
                        
                        <!-- View Mode Toggle -->
                        <div class="flex items-center gap-2">
                            <button id="view-mode-list" class="px-3 py-2 bg-slate-800/50 border border-slate-700/50 rounded-lg text-white hover:bg-slate-700/50 transition-colors" title="Vista Lista">
                                <i class="fas fa-list"></i>
                            </button>
                            <button id="view-mode-campaign" class="px-3 py-2 bg-blue-600 border border-blue-500 rounded-lg text-white hover:bg-blue-700 transition-colors" title="Vista por Campaña">
                                <i class="fas fa-layer-group"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-4">
                        <div class="text-sm text-slate-400">
                            <span id="showing-count">0</span> de <span id="total-count">0</span> empleados
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-slate-400">Por página:</label>
                            <select id="items-per-page" class="bg-slate-800/50 border border-slate-700/50 rounded px-2 py-1 text-white text-sm focus:outline-none focus:border-blue-500">
                                <option value="12">12</option>
                                <option value="24" selected>24</option>
                                <option value="48">48</option>
                                <option value="all">Todos</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6" id="summary-stats">
                <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-400 text-sm">Empleados Activos</p>
                            <p class="text-2xl font-bold text-white" id="active-employees">--</p>
                        </div>
                        <div class="w-10 h-10 bg-blue-500/20 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-blue-400"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-400 text-sm">Horas Trabajadas Hoy</p>
                            <p class="text-2xl font-bold text-white" id="total-hours">--</p>
                            <p class="text-xs text-slate-500 mt-1" title="Suma de horas pagadas de todos los empleados activos hoy">Solo tiempo pagado</p>
                        </div>
                        <div class="w-10 h-10 bg-purple-500/20 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-purple-400"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-400 text-sm">Ingresos USD</p>
                            <p class="text-2xl font-bold text-white" id="earnings-usd">$0.00</p>
                        </div>
                        <div class="w-10 h-10 bg-green-500/20 rounded-lg flex items-center justify-center">
                            <i class="fas fa-dollar-sign text-green-400"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-400 text-sm">Ingresos DOP</p>
                            <p class="text-2xl font-bold text-white" id="earnings-dop">RD$0.00</p>
                        </div>
                        <div class="w-10 h-10 bg-orange-500/20 rounded-lg flex items-center justify-center">
                            <i class="fas fa-peso-sign text-orange-400"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Campaign Summary (only shown in campaign view) -->
            <div id="campaign-summary" class="mb-6 hidden">
                <div class="bg-slate-800/30 rounded-lg p-4 border border-slate-700/50">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-chart-pie mr-2 text-blue-400"></i>
                        Resumen de Costos por Campaña
                    </h3>
                    <div id="campaign-summary-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        <!-- Campaign summary cards will be populated here -->
                    </div>
                </div>
            </div>
            
            <div id="employees-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <!-- Employee cards will be populated here -->
            </div>
            
            <!-- Pagination -->
            <div id="pagination-container" class="mt-6 flex justify-center">
                <div class="flex items-center gap-2" id="pagination">
                    <!-- Pagination buttons will be populated here -->
                </div>
            </div>
        </div>
        <?php */ ?>

        <!-- Module Cards -->

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            <div class="module-card" onclick="window.location.href='employees.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-id-card text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Empleados</h3>
                </div>
                <p class="text-slate-400 text-sm">Gestión completa de empleados y perfiles</p>
            </div>

            <div class="module-card" onclick="window.location.href='trial_period.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-hourglass-half text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Período de Prueba</h3>
                </div>
                <p class="text-slate-400 text-sm">Seguimiento de empleados en prueba (90 días)</p>
            </div>

            <div class="module-card" onclick="window.location.href='campaigns.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-bullhorn text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Gestión de Campañas</h3>
                </div>
                <p class="text-slate-400 text-sm">Administrar campañas y asignar empleados</p>
            </div>

            <div class="module-card" onclick="window.location.href='payroll.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-money-bill-wave text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Nómina RD</h3>
                </div>
                <p class="text-slate-400 text-sm">Sistema completo con AFP, SFS, ISR, TSS y DGII</p>
            </div>

            <div class="module-card" onclick="window.location.href='birthdays.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #ec4899 0%, #db2777 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-birthday-cake text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Cumpleaños</h3>
                </div>
                <p class="text-slate-400 text-sm">Calendario de cumpleaños de empleados</p>
            </div>

            <div class="module-card" onclick="window.location.href='permissions.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-clipboard-list text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Permisos</h3>
                </div>
                <p class="text-slate-400 text-sm">Solicitudes y gestión de permisos</p>
            </div>

            <div class="module-card" onclick="window.location.href='vacations.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-plane-departure text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Vacaciones</h3>
                </div>
                <p class="text-slate-400 text-sm">Solicitudes y balance de vacaciones</p>
            </div>

            <div class="module-card" onclick="window.location.href='medical_leaves.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-notes-medical text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Licencias Médicas</h3>
                </div>
                <p class="text-slate-400 text-sm">Gestión de licencias médicas, maternidad y más</p>
            </div>

            <div class="module-card" onclick="window.location.href='calendar.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #a855f7 0%, #9333ea 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-calendar-alt text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Calendario HR</h3>
                </div>
                <p class="text-slate-400 text-sm">Vista unificada de eventos y fechas importantes</p>
            </div>

            <div class="module-card" onclick="window.location.href='contracts.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-file-contract text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Contratos</h3>
                </div>
                <p class="text-slate-400 text-sm">Generador automático de contratos laborales</p>
            </div>

            <div class="module-card" onclick="window.location.href='recruitment.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-user-plus text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Reclutamiento</h3>
                </div>
                <p class="text-slate-400 text-sm">Gestión de vacantes y solicitudes de empleo</p>
            </div>

            <div class="module-card" onclick="window.location.href='job_postings.php'">
                <div class="flex items-center mb-3">
                    <div class="stat-icon mr-3" style="background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="fas fa-briefcase text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Vacantes</h3>
                </div>
                <p class="text-slate-400 text-sm">Crear y administrar ofertas de empleo</p>
            </div>
        </div>

        <!-- Quick Info Sections -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Upcoming Birthdays -->
            <div class="glass-card">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-semibold text-white">
                        <i class="fas fa-birthday-cake text-pink-400 mr-2"></i>
                        Próximos Cumpleaños
                    </h3>
                    <a href="birthdays.php" class="text-indigo-400 hover:text-indigo-300 text-sm">Ver todos</a>
                </div>
                <?php if (empty($upcomingBirthdays)): ?>
                    <p class="text-slate-400 text-sm">No hay cumpleaños próximos en los siguientes 30 días.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($upcomingBirthdays as $birthday): ?>
                            <div class="flex items-center justify-between p-3 bg-slate-800/50 rounded-lg">
                                <div>
                                    <p class="text-white font-medium"><?= htmlspecialchars($birthday['first_name'] . ' ' . $birthday['last_name']) ?></p>
                                    <p class="text-slate-400 text-sm"><?= date('d/m', strtotime($birthday['birth_date'])) ?></p>
                                </div>
                                <span class="tag-pill" style="background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);">
                                    <?= $birthday['days_until'] == 0 ? '¡Hoy!' : 'En ' . $birthday['days_until'] . ' días' ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Trial Period Ending Soon -->
            <div class="glass-card">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-semibold text-white">
                        <i class="fas fa-hourglass-end text-orange-400 mr-2"></i>
                        Finalizando Período de Prueba
                    </h3>
                    <a href="trial_period.php" class="text-indigo-400 hover:text-indigo-300 text-sm">Ver todos</a>
                </div>
                <?php if (empty($endingTrial)): ?>
                    <p class="text-slate-400 text-sm">No hay empleados finalizando período de prueba próximamente.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($endingTrial as $employee): ?>
                            <div class="flex items-center justify-between p-3 bg-slate-800/50 rounded-lg">
                                <div>
                                    <p class="text-white font-medium"><?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></p>
                                    <p class="text-slate-400 text-sm">Finaliza: <?= date('d/m/Y', strtotime($employee['trial_end_date'])) ?></p>
                                </div>
                                <span class="tag-pill" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                                    <?= $employee['days_remaining'] ?> días
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>
    <?php /* ?>
    <script>
        let lastData = null;
        let allEmployees = [];
        let filteredEmployees = [];
        let allCampaigns = [];
        let currentPage = 1;
        let itemsPerPage = 24;
        let searchTerm = '';
        let selectedCampaign = 'all';
        let viewMode = 'campaign'; // 'list' or 'campaign'

        document.addEventListener('DOMContentLoaded', function() {
            // Initial load
            updateMonitor();
            
            // Auto-refresh every 30 seconds (reducido para evitar rate limiting)
            setInterval(updateMonitor, 30000);
            
            // Update durations every second locally to make it smooth
            setInterval(updateDurations, 1000);
            
            // Setup search functionality
            const searchInput = document.getElementById('employee-search');
            searchInput.addEventListener('input', function(e) {
                searchTerm = e.target.value.toLowerCase();
                currentPage = 1;
                filterAndPaginateEmployees();
            });
            
            // Setup campaign filter
            const campaignFilter = document.getElementById('campaign-filter');
            campaignFilter.addEventListener('change', function(e) {
                selectedCampaign = e.target.value;
                currentPage = 1;
                filterAndPaginateEmployees();
            });
            
            // Setup view mode toggles
            const viewModeList = document.getElementById('view-mode-list');
            const viewModeCampaign = document.getElementById('view-mode-campaign');
            
            viewModeList.addEventListener('click', function() {
                viewMode = 'list';
                updateViewModeButtons();
                currentPage = 1;
                filterAndPaginateEmployees();
            });
            
            viewModeCampaign.addEventListener('click', function() {
                viewMode = 'campaign';
                updateViewModeButtons();
                currentPage = 1;
                filterAndPaginateEmployees();
            });
            
            // Setup items per page selector
            const itemsPerPageSelect = document.getElementById('items-per-page');
            itemsPerPageSelect.addEventListener('change', function(e) {
                itemsPerPage = e.target.value === 'all' ? 999999 : parseInt(e.target.value);
                currentPage = 1;
                filterAndPaginateEmployees();
            });
            
            // Initialize view mode
            updateViewModeButtons();
        });

        function updateMonitor() {
            fetch('realtime_monitor_api.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        lastData = data;
                        allEmployees = data.employees;
                        
                        // Extract unique campaigns
                        extractCampaigns(data.employees);
                        
                        updateSummaryCards(data.summary);
                        filterAndPaginateEmployees();
                        
                        const now = new Date();
                        document.getElementById('update-time').textContent = now.toLocaleTimeString();
                    }
                })
                .catch(error => {
                    console.error('Error fetching monitor data:', error);
                    // Show error state
                    const grid = document.getElementById('employees-grid');
                    grid.innerHTML = `
                        <div class="col-span-full text-center py-12">
                            <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
                            <p class="text-slate-400">Error al cargar datos. Reintentando...</p>
                        </div>
                    `;
                });
        }

        function updateSummaryCards(summary) {
            document.getElementById('active-employees').textContent = summary.active_now + ' / ' + summary.total_employees;
            
            // Calculate total hours (USD + DOP)
            const totalHours = summary.total_hours_usd + summary.total_hours_dop;
            document.getElementById('total-hours').textContent = formatHours(totalHours);
            
            document.getElementById('earnings-usd').textContent = summary.total_earnings_usd_formatted;
            document.getElementById('earnings-dop').textContent = summary.total_earnings_dop_formatted;
        }

        function extractCampaigns(employees) {
            const campaignMap = new Map();
            
            employees.forEach(emp => {
                if (emp.campaign && emp.campaign.name) {
                    campaignMap.set(emp.campaign.name, {
                        name: emp.campaign.name,
                        color: emp.campaign.color,
                        code: emp.campaign.code
                    });
                }
            });
            
            allCampaigns = Array.from(campaignMap.values()).sort((a, b) => a.name.localeCompare(b.name));
            updateCampaignFilter();
        }
        
        function updateCampaignFilter() {
            const campaignFilter = document.getElementById('campaign-filter');
            const currentValue = campaignFilter.value;
            
            // Clear existing options except "All"
            campaignFilter.innerHTML = '<option value="all">Todas las Campañas</option>';
            
            // Add campaign options
            allCampaigns.forEach(campaign => {
                const option = document.createElement('option');
                option.value = campaign.name;
                option.textContent = campaign.name;
                campaignFilter.appendChild(option);
            });
            
            // Restore previous selection if it still exists
            if (currentValue && [...campaignFilter.options].some(opt => opt.value === currentValue)) {
                campaignFilter.value = currentValue;
            }
        }
        
        function updateViewModeButtons() {
            const listBtn = document.getElementById('view-mode-list');
            const campaignBtn = document.getElementById('view-mode-campaign');
            
            if (viewMode === 'list') {
                listBtn.className = 'px-3 py-2 bg-blue-600 border border-blue-500 rounded-lg text-white hover:bg-blue-700 transition-colors';
                campaignBtn.className = 'px-3 py-2 bg-slate-800/50 border border-slate-700/50 rounded-lg text-white hover:bg-slate-700/50 transition-colors';
            } else {
                listBtn.className = 'px-3 py-2 bg-slate-800/50 border border-slate-700/50 rounded-lg text-white hover:bg-slate-700/50 transition-colors';
                campaignBtn.className = 'px-3 py-2 bg-blue-600 border border-blue-500 rounded-lg text-white hover:bg-blue-700 transition-colors';
            }
        }
        
        function calculateCampaignStats(employees) {
            let totalHours = 0;
            let totalEarningsUSD = 0;
            let totalEarningsDOP = 0;
            
            employees.forEach(emp => {
                // Add hours worked today
                totalHours += emp.hours_worked_today || 0;
                
                // Add earnings based on currency
                if (emp.currency === 'DOP') {
                    totalEarningsDOP += emp.earnings_today || 0;
                } else {
                    totalEarningsUSD += emp.earnings_today || 0;
                }
            });
            
            return {
                totalHours: totalHours,
                totalHoursFormatted: formatHours(totalHours),
                totalEarningsUSD: totalEarningsUSD,
                totalEarningsUSDFormatted: formatMoney(totalEarningsUSD, 'USD'),
                totalEarningsDOP: totalEarningsDOP,
                totalEarningsDOPFormatted: formatMoney(totalEarningsDOP, 'DOP')
            };
        }
        
        function formatMoney(amount, currency) {
            if (currency === 'DOP') {
                return 'RD$' + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            return '$' + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        function updateCampaignSummary() {
            const summarySection = document.getElementById('campaign-summary');
            const summaryGrid = document.getElementById('campaign-summary-grid');
            
            // Show the summary section
            summarySection.classList.remove('hidden');
            
            // Group employees by campaign and calculate stats
            const campaignStats = {};
            filteredEmployees.forEach(emp => {
                const campaignName = emp.campaign?.name || 'Sin Campaña';
                if (!campaignStats[campaignName]) {
                    campaignStats[campaignName] = {
                        campaign: emp.campaign || { name: 'Sin Campaña', color: '#6b7280' },
                        employees: [],
                        totalHours: 0,
                        totalEarningsUSD: 0,
                        totalEarningsDOP: 0
                    };
                }
                
                campaignStats[campaignName].employees.push(emp);
                campaignStats[campaignName].totalHours += emp.hours_worked_today || 0;
                
                if (emp.currency === 'DOP') {
                    campaignStats[campaignName].totalEarningsDOP += emp.earnings_today || 0;
                } else {
                    campaignStats[campaignName].totalEarningsUSD += emp.earnings_today || 0;
                }
            });
            
            // Clear existing summary cards
            summaryGrid.innerHTML = '';
            
            // Create summary cards for each campaign
            Object.keys(campaignStats).sort().forEach(campaignName => {
                const stats = campaignStats[campaignName];
                const campaign = stats.campaign;
                const activeEmployees = stats.employees.filter(emp => emp.status === 'active').length;
                
                const summaryCard = document.createElement('div');
                summaryCard.className = 'bg-slate-800/50 rounded-lg p-4 border border-slate-700/50 hover:border-blue-500/50 transition-all';
                summaryCard.innerHTML = `
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-3 h-3 rounded-full" style="background-color: ${campaign.color}"></div>
                        <h4 class="font-semibold text-white truncate">${campaign.name}</h4>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <p class="text-slate-500 text-xs uppercase tracking-wider">Empleados</p>
                            <p class="text-white font-semibold">${stats.employees.length} <span class="text-green-400">(${activeEmployees} activos)</span></p>
                        </div>
                        <div>
                            <p class="text-slate-500 text-xs uppercase tracking-wider">Horas</p>
                            <p class="text-blue-400 font-semibold">${formatHours(stats.totalHours)}</p>
                        </div>
                        <div>
                            <p class="text-slate-500 text-xs uppercase tracking-wider">USD</p>
                            <p class="text-green-400 font-semibold">${formatMoney(stats.totalEarningsUSD, 'USD')}</p>
                        </div>
                        <div>
                            <p class="text-slate-500 text-xs uppercase tracking-wider">DOP</p>
                            <p class="text-orange-400 font-semibold">${formatMoney(stats.totalEarningsDOP, 'DOP')}</p>
                        </div>
                    </div>
                `;
                
                summaryGrid.appendChild(summaryCard);
            });
        }
        
        function hideCampaignSummary() {
            const summarySection = document.getElementById('campaign-summary');
            summarySection.classList.add('hidden');
        }

        function filterAndPaginateEmployees() {
            if (!allEmployees) return;
            
            // Filter employees based on search term and campaign
            filteredEmployees = allEmployees.filter(emp => {
                // Search term filter
                let matchesSearch = true;
                if (searchTerm) {
                    const searchableText = [
                        emp.first_name,
                        emp.last_name,
                        emp.full_name,
                        emp.position,
                        emp.status_label
                    ].join(' ').toLowerCase();
                    
                    matchesSearch = searchableText.includes(searchTerm);
                }
                
                // Campaign filter
                let matchesCampaign = true;
                if (selectedCampaign !== 'all') {
                    matchesCampaign = emp.campaign && emp.campaign.name === selectedCampaign;
                }
                
                return matchesSearch && matchesCampaign;
            });
            
            // Update counts
            document.getElementById('total-count').textContent = filteredEmployees.length;
            
            if (viewMode === 'campaign') {
                updateCampaignSummary();
                updateEmployeeGridByCampaign();
            } else {
                hideCampaignSummary();
                // Calculate pagination for list view
                const totalPages = Math.ceil(filteredEmployees.length / itemsPerPage);
                const startIndex = (currentPage - 1) * itemsPerPage;
                const endIndex = Math.min(startIndex + itemsPerPage, filteredEmployees.length);
                const currentPageEmployees = filteredEmployees.slice(startIndex, endIndex);
                
                // Update showing count
                document.getElementById('showing-count').textContent = currentPageEmployees.length;
                
                // Update employee grid
                updateEmployeeGrid(currentPageEmployees);
                
                // Update pagination
                updatePagination(totalPages);
            }
        }

        function updateEmployeeGridByCampaign() {
            const grid = document.getElementById('employees-grid');
            
            if (filteredEmployees.length === 0) {
                grid.innerHTML = `
                    <div class="col-span-full text-center py-12">
                        <i class="fas fa-search text-4xl text-slate-400 mb-4"></i>
                        <p class="text-slate-400">No se encontraron empleados${searchTerm || selectedCampaign !== 'all' ? ' con los filtros aplicados' : ''}.</p>
                    </div>
                `;
                document.getElementById('showing-count').textContent = '0';
                document.getElementById('pagination').innerHTML = '';
                return;
            }
            
            // Group employees by campaign
            const employeesByCampaign = {};
            filteredEmployees.forEach(emp => {
                const campaignName = emp.campaign?.name || 'Sin Campaña';
                if (!employeesByCampaign[campaignName]) {
                    employeesByCampaign[campaignName] = {
                        campaign: emp.campaign || { name: 'Sin Campaña', color: '#6b7280' },
                        employees: []
                    };
                }
                employeesByCampaign[campaignName].employees.push(emp);
            });
            
            // Sort campaigns by name
            const sortedCampaigns = Object.keys(employeesByCampaign).sort();
            
            grid.innerHTML = '';
            grid.className = 'space-y-6'; // Change grid layout for campaign view
            
            let totalShowing = 0;
            
            sortedCampaigns.forEach(campaignName => {
                const campaignData = employeesByCampaign[campaignName];
                const campaign = campaignData.campaign;
                const employees = campaignData.employees;
                
                // Create campaign section
                const campaignSection = document.createElement('div');
                campaignSection.className = 'bg-slate-800/30 rounded-lg p-4 border border-slate-700/50';
                
                // Calculate campaign earnings and hours
                const campaignStats = calculateCampaignStats(employees);
                
                // Campaign header
                const campaignHeader = document.createElement('div');
                campaignHeader.className = 'flex items-center justify-between mb-4 pb-3 border-b border-slate-700/50';
                campaignHeader.innerHTML = `
                    <div class="flex items-center gap-3">
                        <div class="w-4 h-4 rounded-full" style="background-color: ${campaign.color}"></div>
                        <h3 class="text-lg font-semibold text-white">${campaign.name}</h3>
                        <span class="px-2 py-1 bg-slate-700/50 rounded-full text-xs text-slate-300">
                            ${employees.length} empleado${employees.length !== 1 ? 's' : ''}
                        </span>
                    </div>
                    <div class="flex items-center gap-6">
                        <div class="text-center">
                            <p class="text-xs text-slate-500 uppercase tracking-wider">Activos</p>
                            <p class="text-sm font-semibold text-green-400">${employees.filter(emp => emp.status === 'active').length}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-xs text-slate-500 uppercase tracking-wider">Horas Hoy</p>
                            <p class="text-sm font-semibold text-blue-400">${campaignStats.totalHoursFormatted}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-xs text-slate-500 uppercase tracking-wider">Costo USD</p>
                            <p class="text-sm font-semibold text-green-400">${campaignStats.totalEarningsUSDFormatted}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-xs text-slate-500 uppercase tracking-wider">Costo DOP</p>
                            <p class="text-sm font-semibold text-orange-400">${campaignStats.totalEarningsDOPFormatted}</p>
                        </div>
                    </div>
                `;
                
                // Campaign employees grid
                const campaignGrid = document.createElement('div');
                campaignGrid.className = 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4';
                
                employees.forEach(emp => {
                    const card = createEmployeeCard(emp);
                    campaignGrid.appendChild(card);
                    totalShowing++;
                });
                
                campaignSection.appendChild(campaignHeader);
                campaignSection.appendChild(campaignGrid);
                grid.appendChild(campaignSection);
            });
            
            // Update showing count
            document.getElementById('showing-count').textContent = totalShowing;
            
            // Hide pagination in campaign view
            document.getElementById('pagination').innerHTML = '';
        }
        
        function createEmployeeCard(emp) {
            const card = document.createElement('div');
            card.className = 'employee-monitor-card';
            card.style.setProperty('--campaign-color', emp.campaign?.color || '#6b7280');
            
            // Determine status class
            let statusClass = 'status-offline';
            if (emp.status === 'active') statusClass = 'status-active';
            else if (emp.status === 'completed') statusClass = 'status-completed';
            else if (emp.current_punch.type === 'PAUSA' || emp.current_punch.type === 'BREAK' || emp.current_punch.type === 'LUNCH') statusClass = 'status-pause';
            
            // Handle photo display with fallback to initials (same as employees.php)
            let photoHTML = '';
            if (emp.photo_path && emp.photo_path.trim() !== '') {
                photoHTML = `
                    <img src="../${emp.photo_path}" 
                         alt="${emp.first_name}" 
                         class="w-10 h-10 rounded-full object-cover border-2 border-slate-700"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white border-2 border-slate-700" 
                         style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); display: none;">
                        ${emp.first_name.charAt(0).toUpperCase()}${emp.last_name.charAt(0).toUpperCase()}
                    </div>
                `;
            } else {
                photoHTML = `
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white border-2 border-slate-700" 
                         style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                        ${emp.first_name.charAt(0).toUpperCase()}${emp.last_name.charAt(0).toUpperCase()}
                    </div>
                `;
            }
            
            card.innerHTML = `
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center">
                        <div class="relative">
                            ${photoHTML}
                            <div class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-slate-800 ${emp.status === 'active' ? 'bg-green-500' : (emp.status === 'offline' ? 'bg-slate-500' : 'bg-yellow-500')}"></div>
                        </div>
                        <div class="ml-3">
                            <h4 class="text-white font-medium text-sm truncate w-32" title="${emp.full_name}">${emp.first_name} ${emp.last_name}</h4>
                            <p class="text-slate-400 text-xs truncate w-32">${emp.position || 'Sin posición'}</p>
                        </div>
                    </div>
                    <span class="status-badge ${statusClass}">
                        ${emp.status_label}
                    </span>
                </div>
                
                <div class="mb-3">
                    <div class="flex justify-between text-xs mb-1">
                        <span class="text-slate-400">Estado Actual</span>
                        <span class="text-white flex items-center">
                            <i class="${emp.current_punch.icon} mr-1" style="color: ${emp.current_punch.color_start}"></i>
                            ${emp.current_punch.label}
                        </span>
                    </div>
                    <div class="flex justify-between text-xs">
                        <span class="text-slate-400">Tiempo en Estado</span>
                        <span class="text-white font-mono duration-counter" data-timestamp="${emp.current_punch.timestamp}" data-active="${emp.status === 'active' || emp.status === 'pause' ? 'true' : 'false'}">
                            ${emp.current_punch.duration_formatted}
                        </span>
                    </div>
                </div>
                
                <div class="pt-3 border-t border-slate-700/50">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <p class="text-slate-500 text-[10px] uppercase tracking-wider">Horas Hoy</p>
                            <p class="text-white font-bold">${emp.hours_formatted}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-slate-500 text-[10px] uppercase tracking-wider">Generado</p>
                            <p class="text-green-400 font-bold">${emp.earnings_formatted}</p>
                        </div>
                    </div>
                </div>
            `;
            
            return card;
        }

        function updateEmployeeGrid(employees) {
            const grid = document.getElementById('employees-grid');
            
            if (employees.length === 0) {
                grid.innerHTML = `
                    <div class="col-span-full text-center py-12">
                        <i class="fas fa-search text-4xl text-slate-400 mb-4"></i>
                        <p class="text-slate-400">No se encontraron empleados${searchTerm ? ' que coincidan con "' + searchTerm + '"' : ''}.</p>
                    </div>
                `;
                return;
            }
            
            grid.innerHTML = '';
            grid.className = 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4'; // Reset grid layout for list view
            
            employees.forEach(emp => {
                const card = document.createElement('div');
                card.className = 'employee-monitor-card';
                card.style.setProperty('--campaign-color', emp.campaign.color);
                
                // Determine status class
                let statusClass = 'status-offline';
                if (emp.status === 'active') statusClass = 'status-active';
                else if (emp.status === 'completed') statusClass = 'status-completed';
                else if (emp.current_punch.type === 'PAUSA' || emp.current_punch.type === 'BREAK' || emp.current_punch.type === 'LUNCH') statusClass = 'status-pause';
                
                // Handle photo display with fallback to initials (same as employees.php)
                let photoHTML = '';
                if (emp.photo_path && emp.photo_path.trim() !== '') {
                    photoHTML = `
                        <img src="../${emp.photo_path}" 
                             alt="${emp.first_name}" 
                             class="w-10 h-10 rounded-full object-cover border-2 border-slate-700"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white border-2 border-slate-700" 
                             style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); display: none;">
                            ${emp.first_name.charAt(0).toUpperCase()}${emp.last_name.charAt(0).toUpperCase()}
                        </div>
                    `;
                } else {
                    photoHTML = `
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white border-2 border-slate-700" 
                             style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                            ${emp.first_name.charAt(0).toUpperCase()}${emp.last_name.charAt(0).toUpperCase()}
                        </div>
                    `;
                }
                
                card.innerHTML = `
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center">
                            <div class="relative">
                                ${photoHTML}
                                <div class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-slate-800 ${emp.status === 'active' ? 'bg-green-500' : (emp.status === 'offline' ? 'bg-slate-500' : 'bg-yellow-500')}"></div>
                            </div>
                            <div class="ml-3">
                                <h4 class="text-white font-medium text-sm truncate w-32" title="${emp.full_name}">${emp.first_name} ${emp.last_name}</h4>
                                <p class="text-slate-400 text-xs truncate w-32">${emp.position || 'Sin posición'}</p>
                            </div>
                        </div>
                        <span class="status-badge ${statusClass}">
                            ${emp.status_label}
                        </span>
                    </div>
                    
                    <div class="mb-3">
                        <div class="flex justify-between text-xs mb-1">
                            <span class="text-slate-400">Campaña</span>
                            <span class="text-white" style="color: ${emp.campaign.color}">${emp.campaign.name || 'Sin campaña'}</span>
                        </div>
                        <div class="flex justify-between text-xs mb-1">
                            <span class="text-slate-400">Estado Actual</span>
                            <span class="text-white flex items-center">
                                <i class="${emp.current_punch.icon} mr-1" style="color: ${emp.current_punch.color_start}"></i>
                                ${emp.current_punch.label}
                            </span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-400">Tiempo en Estado</span>
                            <span class="text-white font-mono duration-counter" data-timestamp="${emp.current_punch.timestamp}" data-active="${emp.status === 'active' || emp.status === 'pause' ? 'true' : 'false'}">
                                ${emp.current_punch.duration_formatted}
                            </span>
                        </div>
                    </div>
                    
                    <div class="pt-3 border-t border-slate-700/50">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <p class="text-slate-500 text-[10px] uppercase tracking-wider">Horas Hoy</p>
                                <p class="text-white font-bold">${emp.hours_formatted}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-slate-500 text-[10px] uppercase tracking-wider">Generado</p>
                                <p class="text-green-400 font-bold">${emp.earnings_formatted}</p>
                            </div>
                        </div>
                    </div>
                `;
                
                grid.appendChild(card);
            });
        }

        function updatePagination(totalPages) {
            const paginationContainer = document.getElementById('pagination');
            
            if (totalPages <= 1) {
                paginationContainer.innerHTML = '';
                return;
            }
            
            let paginationHTML = '';
            
            // Previous button
            if (currentPage > 1) {
                paginationHTML += `
                    <button onclick="changePage(${currentPage - 1})" class="px-3 py-2 bg-slate-800/50 border border-slate-700/50 rounded-lg text-white hover:bg-slate-700/50 transition-colors">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                `;
            }
            
            // Page numbers
            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPages, currentPage + 2);
            
            if (startPage > 1) {
                paginationHTML += `
                    <button onclick="changePage(1)" class="px-3 py-2 bg-slate-800/50 border border-slate-700/50 rounded-lg text-white hover:bg-slate-700/50 transition-colors">1</button>
                `;
                if (startPage > 2) {
                    paginationHTML += '<span class="px-2 text-slate-400">...</span>';
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const isActive = i === currentPage;
                paginationHTML += `
                    <button onclick="changePage(${i})" class="px-3 py-2 ${isActive ? 'bg-blue-600 border-blue-500' : 'bg-slate-800/50 border-slate-700/50'} border rounded-lg text-white hover:bg-${isActive ? 'blue-700' : 'slate-700/50'} transition-colors">
                        ${i}
                    </button>
                `;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    paginationHTML += '<span class="px-2 text-slate-400">...</span>';
                }
                paginationHTML += `
                    <button onclick="changePage(${totalPages})" class="px-3 py-2 bg-slate-800/50 border border-slate-700/50 rounded-lg text-white hover:bg-slate-700/50 transition-colors">${totalPages}</button>
                `;
            }
            
            // Next button
            if (currentPage < totalPages) {
                paginationHTML += `
                    <button onclick="changePage(${currentPage + 1})" class="px-3 py-2 bg-slate-800/50 border border-slate-700/50 rounded-lg text-white hover:bg-slate-700/50 transition-colors">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                `;
            }
            
            paginationContainer.innerHTML = paginationHTML;
        }

        function changePage(page) {
            currentPage = page;
            filterAndPaginateEmployees();
        }

        function updateDurations() {
            if (!lastData) return;
            
            document.querySelectorAll('.duration-counter').forEach(el => {
                if (el.dataset.active === 'true' && el.dataset.timestamp) {
                    const startTime = new Date(el.dataset.timestamp).getTime();
                    const now = new Date().getTime();
                    const diff = Math.floor((now - startTime) / 1000);
                    
                    if (diff >= 0) {
                        el.textContent = formatDuration(diff);
                    }
                }
            });
        }

        function formatDuration(seconds) {
            if (seconds < 60) return seconds + 's';
            if (seconds < 3600) {
                const m = Math.floor(seconds / 60);
                const s = seconds % 60;
                return `${m}m ${s}s`;
            }
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            return `${h}h ${m}m`;
        }

        function formatHours(hours) {
            if (hours === 0) return '0h';
            const h = Math.floor(hours);
            const m = Math.round((hours - h) * 60);
            if (m === 0) return h + 'h';
            return `${h}h ${m}m`;
        }
    </script>
    <?php */ ?>
</body>
</html>
