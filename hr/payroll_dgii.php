<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';

// Check permissions
ensurePermission('hr_payroll');

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

// Get DGII report data
$dgiiData = generateDGIIReport($pdo, $periodId);

// Calculate totals
$totals = [
    'gross' => 0,
    'isr' => 0,
    'net' => 0,
    'employees_with_isr' => count($dgiiData)
];

foreach ($dgiiData as $record) {
    $totals['gross'] += $record['gross_salary'];
    $totals['isr'] += $record['isr'];
    $totals['net'] += $record['net_salary'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte DGII - <?= htmlspecialchars($period['name']) ?></title>
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
                    <i class="fas fa-landmark text-red-400 mr-3"></i>
                    Reporte DGII (Dirección General de Impuestos Internos)
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
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Empleados con ISR</p>
                        <h3 class="text-2xl font-bold"><?= $totals['employees_with_isr'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-red-500">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total Salarios Gravados</p>
                        <h3 class="text-2xl font-bold text-blue-400"><?= formatDOP($totals['gross']) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-blue-500">
                        <i class="fas fa-money-bill-wave text-white text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total ISR Retenido</p>
                        <h3 class="text-2xl font-bold text-red-400"><?= formatDOP($totals['isr']) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-red-500">
                        <i class="fas fa-file-invoice-dollar text-white text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- ISR Scale Info -->
        <div class="glass-card mb-8">
            <h3 class="text-lg font-semibold mb-4">
                <i class="fas fa-info-circle text-blue-400 mr-2"></i>
                Escala de ISR 2025 (Anual)
            </h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-2 px-4">Rango de Ingresos</th>
                            <th class="text-left py-2 px-4">Base</th>
                            <th class="text-left py-2 px-4">% sobre Excedente</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-800">
                            <td class="py-2 px-4">Hasta RD$416,220.00</td>
                            <td class="py-2 px-4 text-green-400">Exento</td>
                            <td class="py-2 px-4">0%</td>
                        </tr>
                        <tr class="border-b border-slate-800">
                            <td class="py-2 px-4">RD$416,220.01 - RD$624,329.00</td>
                            <td class="py-2 px-4">RD$0.00</td>
                            <td class="py-2 px-4 text-orange-400">15%</td>
                        </tr>
                        <tr class="border-b border-slate-800">
                            <td class="py-2 px-4">RD$624,329.01 - RD$867,123.00</td>
                            <td class="py-2 px-4">RD$31,216.00</td>
                            <td class="py-2 px-4 text-orange-400">20%</td>
                        </tr>
                        <tr class="border-b border-slate-800">
                            <td class="py-2 px-4">Más de RD$867,123.01</td>
                            <td class="py-2 px-4">RD$79,775.00</td>
                            <td class="py-2 px-4 text-red-400">25%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Detailed Table -->
        <div class="glass-card">
            <h2 class="text-xl font-semibold mb-4">
                <i class="fas fa-table text-indigo-400 mr-2"></i>
                Detalle de Retenciones ISR por Empleado
            </h2>
            
            <?php if (empty($dgiiData)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-check-circle text-6xl text-green-400 mb-4"></i>
                    <h3 class="text-xl font-semibold mb-2">No hay retenciones de ISR</h3>
                    <p class="text-slate-400">Ningún empleado alcanza el umbral mínimo para retención de ISR en este período.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-2">Código</th>
                                <th class="text-left py-3 px-2">Empleado</th>
                                <th class="text-left py-3 px-2">Cédula/RNC</th>
                                <th class="text-right py-3 px-2">Salario Mensual</th>
                                <th class="text-right py-3 px-2">Salario Anual Proyectado</th>
                                <th class="text-right py-3 px-2">ISR Mensual Retenido</th>
                                <th class="text-right py-3 px-2">ISR Anual Proyectado</th>
                                <th class="text-right py-3 px-2">Salario Neto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dgiiData as $record): 
                                $annualSalary = $record['gross_salary'] * 12;
                                $annualISR = $record['isr'] * 12;
                            ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                    <td class="py-2 px-2"><?= htmlspecialchars($record['employee_code']) ?></td>
                                    <td class="py-2 px-2">
                                        <div class="font-medium"><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></div>
                                    </td>
                                    <td class="py-2 px-2"><?= htmlspecialchars($record['identification_number'] ?: 'N/A') ?></td>
                                    <td class="py-2 px-2 text-right"><?= formatDOP($record['gross_salary']) ?></td>
                                    <td class="py-2 px-2 text-right text-blue-400"><?= formatDOP($annualSalary) ?></td>
                                    <td class="py-2 px-2 text-right text-red-400 font-semibold"><?= formatDOP($record['isr']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-500"><?= formatDOP($annualISR) ?></td>
                                    <td class="py-2 px-2 text-right text-green-400 font-semibold"><?= formatDOP($record['net_salary']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="bg-slate-800/70 font-bold">
                                <td colspan="3" class="py-3 px-2">TOTALES</td>
                                <td class="py-3 px-2 text-right"><?= formatDOP($totals['gross']) ?></td>
                                <td class="py-3 px-2 text-right text-blue-400"><?= formatDOP($totals['gross'] * 12) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['isr']) ?></td>
                                <td class="py-3 px-2 text-right text-red-500"><?= formatDOP($totals['isr'] * 12) ?></td>
                                <td class="py-3 px-2 text-right text-green-400"><?= formatDOP($totals['net']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payment Summary -->
        <?php if (!empty($dgiiData)): ?>
            <div class="glass-card mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <i class="fas fa-calculator text-green-400 mr-2"></i>
                    Resumen de Pago DGII
                </h3>
                <div class="bg-slate-800/50 p-6 rounded-lg">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-slate-400 text-sm mb-2">Total ISR a Declarar (Mensual)</p>
                            <p class="text-3xl font-bold text-red-400"><?= formatDOP($totals['isr']) ?></p>
                        </div>
                        <div>
                            <p class="text-slate-400 text-sm mb-2">Total ISR Proyectado (Anual)</p>
                            <p class="text-3xl font-bold text-red-500"><?= formatDOP($totals['isr'] * 12) ?></p>
                        </div>
                    </div>
                    <div class="mt-6 p-4 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                        <p class="text-sm text-blue-300">
                            <i class="fas fa-info-circle mr-2"></i>
                            <strong>Nota:</strong> Este reporte debe ser presentado mensualmente a la DGII junto con el formulario IR-3. 
                            Las retenciones deben ser depositadas antes del día 10 del mes siguiente.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>
