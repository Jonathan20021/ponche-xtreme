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
                        <p class="text-slate-400 text-sm mb-1">Vacaciones Pendientes</p>
                        <h3 class="text-3xl font-bold text-white"><?= $pendingVacations ?></h3>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                        <i class="fas fa-umbrella-beach text-white"></i>
                    </div>
                </div>
            </div>
        </div>

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
</body>
</html>
