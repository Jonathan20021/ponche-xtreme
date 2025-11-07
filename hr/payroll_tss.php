<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';

// Check permissions
ensurePermission('hr_payroll', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$periodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;

if (!$periodId) {
    die('Período no especificado');
}

// Get period data
$periodStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
$periodStmt->execute([$periodId]);
$period = $periodStmt->fetch(PDO::FETCH_ASSOC);

if (!$period) {
    die('Período no encontrado');
}

// Get TSS report data
$tssData = generateTSSReport($pdo, $periodId);

// Calculate totals
$totals = [
    'gross' => 0,
    'afp_employee' => 0,
    'afp_employer' => 0,
    'sfs_employee' => 0,
    'sfs_employer' => 0,
    'srl_employer' => 0,
    'total_employee' => 0,
    'total_employer' => 0,
    'total_tss' => 0
];

foreach ($tssData as $record) {
    $totals['gross'] += $record['gross_salary'];
    $totals['afp_employee'] += $record['afp_employee'];
    $totals['afp_employer'] += $record['afp_employer'];
    $totals['sfs_employee'] += $record['sfs_employee'];
    $totals['sfs_employer'] += $record['sfs_employer'];
    $totals['srl_employer'] += $record['srl_employer'];
    $totals['total_employee'] += ($record['afp_employee'] + $record['sfs_employee']);
    $totals['total_employer'] += ($record['afp_employer'] + $record['sfs_employer'] + $record['srl_employer']);
}

$totals['total_tss'] = $totals['total_employee'] + $totals['total_employer'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte TSS - <?= htmlspecialchars($period['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold mb-2">
                    <i class="fas fa-shield-alt text-blue-400 mr-3"></i>
                    Reporte TSS (Tesorería de la Seguridad Social)
                </h1>
                <p class="text-slate-400"><?= htmlspecialchars($period['name']) ?></p>
                <p class="text-slate-400 text-sm">
                    Período: <?= date('d/m/Y', strtotime($period['start_date'])) ?> - <?= date('d/m/Y', strtotime($period['end_date'])) ?>
                </p>
            </div>
            <div class="flex gap-3">
                <button onclick="window.print()" class="btn-primary">
                    <i class="fas fa-print"></i>
                    Imprimir
                </button>
                <a href="payroll.php?period_id=<?= $periodId ?>" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver
                </a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total Empleados</p>
                        <h3 class="text-2xl font-bold"><?= count($tssData) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-blue-500">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Aportes Empleados</p>
                        <h3 class="text-2xl font-bold text-orange-400"><?= formatDOP($totals['total_employee']) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-orange-500">
                        <i class="fas fa-user-minus text-white text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Aportes Patronales</p>
                        <h3 class="text-2xl font-bold text-purple-400"><?= formatDOP($totals['total_employer']) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-purple-500">
                        <i class="fas fa-building text-white text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total a Pagar TSS</p>
                        <h3 class="text-2xl font-bold text-green-400"><?= formatDOP($totals['total_tss']) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-green-500">
                        <i class="fas fa-money-bill-wave text-white text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Table -->
        <div class="glass-card">
            <h2 class="text-xl font-semibold mb-4">
                <i class="fas fa-table text-indigo-400 mr-2"></i>
                Detalle de Aportes por Empleado
            </h2>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-2">Código</th>
                            <th class="text-left py-3 px-2">Empleado</th>
                            <th class="text-left py-3 px-2">Cédula</th>
                            <th class="text-right py-3 px-2">Salario Bruto</th>
                            <th class="text-right py-3 px-2">AFP Empleado<br><small>(2.87%)</small></th>
                            <th class="text-right py-3 px-2">AFP Patronal<br><small>(7.10%)</small></th>
                            <th class="text-right py-3 px-2">SFS Empleado<br><small>(3.04%)</small></th>
                            <th class="text-right py-3 px-2">SFS Patronal<br><small>(7.09%)</small></th>
                            <th class="text-right py-3 px-2">SRL Patronal<br><small>(1.20%)</small></th>
                            <th class="text-right py-3 px-2">Total Empleado</th>
                            <th class="text-right py-3 px-2">Total Patronal</th>
                            <th class="text-right py-3 px-2">Total TSS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tssData as $record): 
                            $totalEmp = $record['afp_employee'] + $record['sfs_employee'];
                            $totalPat = $record['afp_employer'] + $record['sfs_employer'] + $record['srl_employer'];
                            $totalTSS = $totalEmp + $totalPat;
                        ?>
                            <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                <td class="py-2 px-2"><?= htmlspecialchars($record['employee_code']) ?></td>
                                <td class="py-2 px-2"><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></td>
                                <td class="py-2 px-2"><?= htmlspecialchars($record['identification_number'] ?: 'N/A') ?></td>
                                <td class="py-2 px-2 text-right"><?= formatDOP($record['gross_salary']) ?></td>
                                <td class="py-2 px-2 text-right text-orange-400"><?= formatDOP($record['afp_employee']) ?></td>
                                <td class="py-2 px-2 text-right text-purple-400"><?= formatDOP($record['afp_employer']) ?></td>
                                <td class="py-2 px-2 text-right text-orange-400"><?= formatDOP($record['sfs_employee']) ?></td>
                                <td class="py-2 px-2 text-right text-purple-400"><?= formatDOP($record['sfs_employer']) ?></td>
                                <td class="py-2 px-2 text-right text-purple-400"><?= formatDOP($record['srl_employer']) ?></td>
                                <td class="py-2 px-2 text-right text-orange-500 font-semibold"><?= formatDOP($totalEmp) ?></td>
                                <td class="py-2 px-2 text-right text-purple-500 font-semibold"><?= formatDOP($totalPat) ?></td>
                                <td class="py-2 px-2 text-right text-green-400 font-bold"><?= formatDOP($totalTSS) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="bg-slate-800/70 font-bold">
                            <td colspan="4" class="py-3 px-2">TOTALES</td>
                            <td class="py-3 px-2 text-right text-orange-400"><?= formatDOP($totals['afp_employee']) ?></td>
                            <td class="py-3 px-2 text-right text-purple-400"><?= formatDOP($totals['afp_employer']) ?></td>
                            <td class="py-3 px-2 text-right text-orange-400"><?= formatDOP($totals['sfs_employee']) ?></td>
                            <td class="py-3 px-2 text-right text-purple-400"><?= formatDOP($totals['sfs_employer']) ?></td>
                            <td class="py-3 px-2 text-right text-purple-400"><?= formatDOP($totals['srl_employer']) ?></td>
                            <td class="py-3 px-2 text-right text-orange-500"><?= formatDOP($totals['total_employee']) ?></td>
                            <td class="py-3 px-2 text-right text-purple-500"><?= formatDOP($totals['total_employer']) ?></td>
                            <td class="py-3 px-2 text-right text-green-400"><?= formatDOP($totals['total_tss']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Summary Box -->
        <div class="glass-card mt-8">
            <h3 class="text-lg font-semibold mb-4">Resumen de Pago TSS</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-slate-800/50 p-4 rounded-lg">
                    <p class="text-slate-400 text-sm mb-2">AFP Total (Empleado + Patronal)</p>
                    <p class="text-2xl font-bold text-blue-400">
                        <?= formatDOP($totals['afp_employee'] + $totals['afp_employer']) ?>
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        Empleado: <?= formatDOP($totals['afp_employee']) ?> | 
                        Patronal: <?= formatDOP($totals['afp_employer']) ?>
                    </p>
                </div>
                
                <div class="bg-slate-800/50 p-4 rounded-lg">
                    <p class="text-slate-400 text-sm mb-2">SFS Total (Empleado + Patronal)</p>
                    <p class="text-2xl font-bold text-cyan-400">
                        <?= formatDOP($totals['sfs_employee'] + $totals['sfs_employer']) ?>
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        Empleado: <?= formatDOP($totals['sfs_employee']) ?> | 
                        Patronal: <?= formatDOP($totals['sfs_employer']) ?>
                    </p>
                </div>
                
                <div class="bg-slate-800/50 p-4 rounded-lg">
                    <p class="text-slate-400 text-sm mb-2">SRL (Solo Patronal)</p>
                    <p class="text-2xl font-bold text-purple-400">
                        <?= formatDOP($totals['srl_employer']) ?>
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        1.20% sobre salario bruto
                    </p>
                </div>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>
