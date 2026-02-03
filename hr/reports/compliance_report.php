<?php
session_start();
require_once '../../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../unauthorized.php');
    exit;
}

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// CONTRACT COMPLIANCE OVERVIEW
$contractsOverview = $pdo->query("
    SELECT 
        COUNT(*) as total_contracts,
        COUNT(CASE WHEN contract_type = 'INDEFINIDO' THEN 1 END) as indefinite,
        COUNT(CASE WHEN contract_type = 'FIJO' THEN 1 END) as fixed,
        COUNT(CASE WHEN contract_type = 'PRUEBA' THEN 1 END) as trial,
        COUNT(CASE WHEN contract_type = 'TEMPORAL' THEN 1 END) as temporary
    FROM employment_contracts
")->fetch(PDO::FETCH_ASSOC);

// ACTIVE EMPLOYEES STATUS
$employeeStatus = $pdo->query("
    SELECT 
        employment_status,
        COUNT(*) as count
    FROM employees
    GROUP BY employment_status
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// EMPLOYEES WITH CONTRACTS
$employeesWithContracts = $pdo->query("
    SELECT 
        COUNT(DISTINCT e.id) as employees_with_contracts,
        (SELECT COUNT(*) FROM employees WHERE employment_status IN ('ACTIVE', 'TRIAL')) as total_active_employees
    FROM employees e
    INNER JOIN employment_contracts ec ON e.id = ec.employee_id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
")->fetch(PDO::FETCH_ASSOC);

$contractComplianceRate = $employeesWithContracts['total_active_employees'] > 0 
    ? ($employeesWithContracts['employees_with_contracts'] / $employeesWithContracts['total_active_employees']) * 100 
    : 0;

// EMPLOYEES WITHOUT CONTRACTS
$employeesWithoutContracts = $pdo->query("
    SELECT 
        e.id,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        e.hire_date,
        e.employment_status,
        DATEDIFF(CURDATE(), e.hire_date) as days_without_contract
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN employment_contracts ec ON e.id = ec.employee_id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    AND ec.id IS NULL
    ORDER BY e.hire_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// CONTRACTS BY TYPE AND DEPARTMENT
$contractsByDept = $pdo->query("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(ec.id) as total_contracts,
        COUNT(CASE WHEN ec.contract_type = 'INDEFINIDO' THEN 1 END) as indefinite,
        COUNT(CASE WHEN ec.contract_type = 'FIJO' THEN 1 END) as fixed
    FROM employment_contracts ec
    JOIN employees e ON ec.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY d.name
    ORDER BY total_contracts DESC
")->fetchAll(PDO::FETCH_ASSOC);

// CONTRACTS BY TYPE
$contractsByType = $pdo->query("
    SELECT 
        contract_type,
        COUNT(*) as count
    FROM employment_contracts
    GROUP BY contract_type
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// RECENT CONTRACT SIGNINGS
$recentContracts = $pdo->query("
    SELECT 
        ec.id,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        ec.contract_type,
        ec.contract_date,
        ec.created_at as signed_date
    FROM employment_contracts ec
    JOIN employees e ON ec.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    ORDER BY ec.created_at DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// EMPLOYEES IN TRIAL PERIOD (Compliance Alert)
$trialEmployees = $pdo->query("
    SELECT 
        e.id,
        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
        e.position,
        d.name as department,
        e.hire_date,
        DATEDIFF(CURDATE(), e.hire_date) as days_in_trial,
        CASE 
            WHEN ec.id IS NOT NULL THEN 'CON CONTRATO'
            ELSE 'SIN CONTRATO'
        END as contract_status
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN employment_contracts ec ON e.id = ec.employee_id
    WHERE e.employment_status = 'TRIAL'
    ORDER BY e.hire_date ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Cumplimiento - HR Reports</title>
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
                    <i class="fas fa-shield-alt text-teal-400 mr-3"></i>Reporte de Cumplimiento
                </h1>
                <p class="text-slate-400">Contratos y cumplimiento normativo laboral</p>
            </div>
            <a href="../index.php" class="px-6 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg"><i class="fas fa-arrow-left mr-2"></i>Volver</a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-box">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-file-contract text-3xl text-teal-400"></i><span class="text-3xl">📄</span></div>
                <p class="text-slate-400 text-sm">Total Contratos</p>
                <h3 class="text-3xl font-bold text-white"><?= $contractsOverview['total_contracts'] ?></h3>
            </div>
            <div class="stat-box bg-green-500/10 border-green-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-check-circle text-3xl text-green-400"></i><span class="text-3xl">✅</span></div>
                <p class="text-slate-400 text-sm">Cumplimiento</p>
                <h3 class="text-3xl font-bold text-white"><?= number_format($contractComplianceRate, 1) ?>%</h3>
            </div>
            <div class="stat-box bg-red-500/10 border-red-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-exclamation-triangle text-3xl text-red-400"></i><span class="text-3xl">⚠️</span></div>
                <p class="text-slate-400 text-sm">Sin Contrato</p>
                <h3 class="text-3xl font-bold text-white"><?= count($employeesWithoutContracts) ?></h3>
            </div>
            <div class="stat-box bg-yellow-500/10 border-yellow-500/30">
                <div class="flex items-center justify-between mb-2"><i class="fas fa-user-clock text-3xl text-yellow-400"></i><span class="text-3xl">⏳</span></div>
                <p class="text-slate-400 text-sm">En Periodo de Prueba</p>
                <h3 class="text-3xl font-bold text-white"><?= count($trialEmployees) ?></h3>
            </div>
        </div>

        <?php if (!empty($employeesWithoutContracts)): ?>
        <div class="report-card mb-8 border-2 border-red-500/50">
            <div class="flex items-center gap-3 mb-4">
                <i class="fas fa-exclamation-circle text-red-400 text-2xl"></i>
                <h3 class="text-xl font-semibold text-white">ALERTA: Empleados sin Contrato</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Fecha Ingreso</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Estado</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Días sin Contrato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employeesWithoutContracts as $emp): ?>
                            <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($emp['employee_name']) ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($emp['position'] ?? 'N/A') ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($emp['department'] ?? 'N/A') ?></td>
                                <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($emp['hire_date'])) ?></td>
                                <td class="py-3 px-4 text-center">
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $emp['employment_status'] == 'TRIAL' ? 'bg-yellow-500/20 text-yellow-300' : 'bg-blue-500/20 text-blue-300' ?>">
                                        <?= $emp['employment_status'] ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="font-bold <?= $emp['days_without_contract'] > 90 ? 'text-red-400' : ($emp['days_without_contract'] > 30 ? 'text-yellow-400' : 'text-orange-400') ?>">
                                        <?= $emp['days_without_contract'] ?> días
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-chart-pie text-teal-400 mr-2"></i>Contratos por Tipo</h3>
                <?php if (empty($contractsByType)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="typeChart"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="report-card">
                <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-users text-purple-400 mr-2"></i>Estado de Empleados</h3>
                <?php if (empty($employeeStatus)): ?>
                    <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
                <?php else: ?>
                    <div style="height: 300px; position: relative;"><canvas id="statusChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($trialEmployees)): ?>
        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-user-clock text-yellow-400 mr-2"></i>Empleados en Periodo de Prueba</h3>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                            <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Fecha Ingreso</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Días en Prueba</th>
                            <th class="text-center py-3 px-4 text-slate-400 font-medium">Estado Contrato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trialEmployees as $trial): ?>
                            <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($trial['employee_name']) ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($trial['position'] ?? 'N/A') ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($trial['department'] ?? 'N/A') ?></td>
                                <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($trial['hire_date'])) ?></td>
                                <td class="py-3 px-4 text-center">
                                    <span class="font-bold <?= $trial['days_in_trial'] > 90 ? 'text-red-400' : ($trial['days_in_trial'] > 60 ? 'text-yellow-400' : 'text-green-400') ?>">
                                        <?= $trial['days_in_trial'] ?> días
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $trial['contract_status'] == 'CON CONTRATO' ? 'bg-green-500/20 text-green-300' : 'bg-red-500/20 text-red-300' ?>">
                                        <?= $trial['contract_status'] ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="report-card mb-8">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-sitemap text-blue-400 mr-2"></i>Contratos por Departamento</h3>
            <?php if (empty($contractsByDept)): ?>
                <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay datos</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Total Contratos</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Indefinidos</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Fijos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contractsByDept as $dept): ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($dept['department']) ?></td>
                                    <td class="py-3 px-4 text-center text-blue-400 font-bold"><?= $dept['total_contracts'] ?></td>
                                    <td class="py-3 px-4 text-center text-green-400"><?= $dept['indefinite'] ?></td>
                                    <td class="py-3 px-4 text-center text-yellow-400"><?= $dept['fixed'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="report-card">
            <h3 class="text-xl font-semibold text-white mb-4"><i class="fas fa-clock text-teal-400 mr-2"></i>Contratos Recientes</h3>
            <?php if (empty($recentContracts)): ?>
                <div class="text-center py-12"><i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i><p class="text-slate-400">No hay contratos registrados</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Posición</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Departamento</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Tipo</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Inicio</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Registro</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentContracts as $contract): ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white font-medium"><?= htmlspecialchars($contract['employee_name']) ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($contract['position'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($contract['department'] ?? 'N/A') ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-300">
                                            <?= htmlspecialchars($contract['contract_type']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-center text-slate-300"><?= date('d/m/Y', strtotime($contract['start_date'])) ?></td>
                                    <td class="py-3 px-4 text-center text-slate-400 text-sm"><?= date('d/m/Y', strtotime($contract['signed_date'])) ?></td>
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
        <?php if (!empty($contractsByType)): ?>
        new Chart(document.getElementById('typeChart'), {
            type: 'doughnut',
            data: { labels: <?= json_encode(array_column($contractsByType, 'contract_type')) ?>, datasets: [{ data: <?= json_encode(array_column($contractsByType, 'count')) ?>, backgroundColor: ['#14b8a6', '#3b82f6', '#f59e0b', '#8b5cf6', '#ef4444'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } } }
        });
        <?php endif; ?>
        <?php if (!empty($employeeStatus)): ?>
        new Chart(document.getElementById('statusChart'), {
            type: 'bar',
            data: { labels: <?= json_encode(array_column($employeeStatus, 'employment_status')) ?>, datasets: [{ label: 'Empleados', data: <?= json_encode(array_column($employeeStatus, 'count')) ?>, backgroundColor: '#8b5cf6' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { y: { ticks: { color: '#94a3b8', stepSize: 1 }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }, x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } } } }
        });
        <?php endif; ?>
    </script>
</body>
</html>

