<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';

// READ-ONLY view: agrupa los payroll_records ya calculados por la campaña
// actual del empleado (employees.campaign_id). No ejecuta ningún cálculo,
// no escribe a payroll_records ni a payroll_periods.
//
// Nota: como employees.campaign_id no tiene historial, la agrupación usa
// la campaña ACTUAL del empleado. Si un empleado cambió de campaña después
// del período, aparecerá bajo su campaña actual, no la que tuvo entonces.

ensurePermission('hr_payroll', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$selectedPeriodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;
$selectedCampaignFilter = null;
if (isset($_GET['campaign_id']) && $_GET['campaign_id'] !== '') {
    // 0 representa "Sin Campaña"; cualquier id > 0 es campaña específica
    $selectedCampaignFilter = (int)$_GET['campaign_id'];
}

// Períodos con records (los DRAFT sin calcular se omiten porque no tienen datos)
$periods = $pdo->query("
    SELECT pp.id, pp.name, pp.start_date, pp.end_date, pp.payment_date, pp.status,
           COUNT(pr.id) AS record_count
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pr.payroll_period_id = pp.id
    GROUP BY pp.id, pp.name, pp.start_date, pp.end_date, pp.payment_date, pp.status
    ORDER BY pp.start_date DESC, pp.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$selectedPeriod = null;
$campaignSummary = [];      // resumen agrupado por campaña
$drilldownRecords = [];     // detalle de empleados de la campaña seleccionada
$drilldownCampaign = null;  // info de la campaña en drill-down
$drilldownTotals = [
    'hours' => 0, 'overtime_hours' => 0, 'gross' => 0,
    'afp' => 0, 'sfs' => 0, 'isr' => 0,
    'cooperative' => 0, 'additional' => 0, 'loans' => 0,
    'other' => 0, 'deductions' => 0, 'net' => 0,
];
$loanDeductionsByEmployee = [];

if ($selectedPeriodId) {
    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$selectedPeriodId]);
    $selectedPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($selectedPeriod) {
    // Resumen por campaña: una sola query con GROUP BY.
    // COALESCE(c.id, 0) → empleados sin campaña caen al grupo 0 ("Sin Campaña").
    $summaryStmt = $pdo->prepare("
        SELECT
            COALESCE(c.id, 0) AS campaign_id,
            COALESCE(c.name, 'Sin Campaña') AS campaign_name,
            COALESCE(c.color, '#64748b') AS campaign_color,
            COUNT(DISTINCT pr.employee_id) AS employee_count,
            SUM(pr.total_hours) AS total_hours,
            SUM(pr.overtime_hours) AS overtime_hours,
            SUM(pr.gross_salary) AS total_gross,
            SUM(pr.total_deductions) AS total_deductions,
            SUM(pr.net_salary) AS total_net
        FROM payroll_records pr
        JOIN employees e ON e.id = pr.employee_id
        LEFT JOIN campaigns c ON c.id = e.campaign_id
        WHERE pr.payroll_period_id = ?
        GROUP BY COALESCE(c.id, 0), COALESCE(c.name, 'Sin Campaña'), COALESCE(c.color, '#64748b')
        ORDER BY total_gross DESC
    ");
    $summaryStmt->execute([$selectedPeriodId]);
    $campaignSummary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

    // Si hay drill-down, levantar el detalle de empleados de esa campaña.
    if ($selectedCampaignFilter !== null) {
        if ($selectedCampaignFilter > 0) {
            $campStmt = $pdo->prepare("SELECT id, name, color, description FROM campaigns WHERE id = ?");
            $campStmt->execute([$selectedCampaignFilter]);
            $drilldownCampaign = $campStmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($selectedCampaignFilter === 0) {
            $drilldownCampaign = ['id' => 0, 'name' => 'Sin Campaña', 'color' => '#64748b', 'description' => 'Empleados sin campaña asignada'];
        }

        if ($drilldownCampaign) {
            // Misma query que /hr/payroll.php para los records, filtrada por campaña.
            $filter = $selectedCampaignFilter > 0
                ? "AND e.campaign_id = ?"
                : "AND e.campaign_id IS NULL";

            $sql = "
                SELECT pr.*, e.first_name, e.last_name, e.employee_code, e.identification_number,
                       d.name AS department_name,
                       COALESCE(pmi.sales_incentive, 0) AS sales_incentive,
                       COALESCE(pmi.night_incentive, 0) AS night_incentive,
                       COALESCE(pmi.cooperative_deduction, 0) AS cooperative_deduction,
                       COALESCE(pmi.additional_deduction, 0) AS additional_deduction
                FROM payroll_records pr
                JOIN employees e ON e.id = pr.employee_id
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN payroll_manual_incentives pmi
                    ON pmi.payroll_period_id = pr.payroll_period_id
                   AND pmi.employee_id = pr.employee_id
                WHERE pr.payroll_period_id = ?
                  $filter
                ORDER BY e.last_name, e.first_name
            ";

            $recStmt = $pdo->prepare($sql);
            if ($selectedCampaignFilter > 0) {
                $recStmt->execute([$selectedPeriodId, $selectedCampaignFilter]);
            } else {
                $recStmt->execute([$selectedPeriodId]);
            }
            $drilldownRecords = $recStmt->fetchAll(PDO::FETCH_ASSOC);

            // Cuotas de préstamos para los empleados visibles (batched).
            if (!empty($drilldownRecords)) {
                $empIds = array_map(static fn($r) => (int)$r['employee_id'], $drilldownRecords);
                $loanDeductionsByEmployee = getLoanDeductionsForEmployees(
                    $pdo,
                    $empIds,
                    $selectedPeriod['start_date'],
                    $selectedPeriod['end_date']
                );
            }

            foreach ($drilldownRecords as $r) {
                $coopAmt = (float)$r['cooperative_deduction'];
                $addAmt = (float)$r['additional_deduction'];
                $loanAmt = (float)($loanDeductionsByEmployee[(int)$r['employee_id']] ?? 0);
                $othersOnly = max(0, (float)$r['other_deductions'] - $coopAmt - $addAmt - $loanAmt);

                $drilldownTotals['hours'] += (float)$r['total_hours'];
                $drilldownTotals['overtime_hours'] += (float)$r['overtime_hours'];
                $drilldownTotals['gross'] += (float)$r['gross_salary'];
                $drilldownTotals['afp'] += (float)$r['afp_employee'];
                $drilldownTotals['sfs'] += (float)$r['sfs_employee'];
                $drilldownTotals['isr'] += (float)$r['isr'];
                $drilldownTotals['cooperative'] += $coopAmt;
                $drilldownTotals['additional'] += $addAmt;
                $drilldownTotals['loans'] += $loanAmt;
                $drilldownTotals['other'] += $othersOnly;
                $drilldownTotals['deductions'] += (float)$r['total_deductions'];
                $drilldownTotals['net'] += (float)$r['net_salary'];
            }
        }
    }
}

// Total global del período (para mostrar % de cada campaña sobre el total)
$periodGrandTotalGross = 0.0;
$periodGrandTotalNet = 0.0;
foreach ($campaignSummary as $row) {
    $periodGrandTotalGross += (float)$row['total_gross'];
    $periodGrandTotalNet += (float)$row['total_net'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nómina por Campaña - HR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .campaign-card {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.15);
            border-radius: 14px;
            padding: 1.25rem;
            transition: transform .12s ease, border-color .12s ease;
        }
        .campaign-card:hover {
            transform: translateY(-2px);
            border-color: rgba(99, 102, 241, 0.5);
        }
        .theme-light .campaign-card {
            background: #ffffff;
            border-color: rgba(148, 163, 184, 0.25);
        }
        .campaign-dot {
            width: 14px; height: 14px; border-radius: 4px; display: inline-block;
        }
        .progress-bar {
            height: 6px; background: rgba(148, 163, 184, 0.18); border-radius: 999px; overflow: hidden;
        }
        .progress-bar > span {
            display: block; height: 100%; border-radius: 999px;
        }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8 flex-wrap gap-3">
            <div>
                <h1 class="text-3xl font-bold mb-2">
                    <i class="fas fa-bullhorn text-blue-400 mr-3"></i>
                    Nómina por Campaña
                </h1>
                <p class="text-slate-400">Agrupación de la nómina ya calculada por campaña actual del empleado</p>
            </div>
            <div class="flex gap-3 flex-wrap">
                <a href="payroll.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver a Nómina
                </a>
            </div>
        </div>

        <!-- Aviso de limitación histórica -->
        <div class="status-banner mb-6" style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); color: #93c5fd; padding: 0.75rem 1rem; border-radius: 10px;">
            <i class="fas fa-info-circle mr-2"></i>
            La agrupación usa la campaña <strong>actual</strong> del empleado. Si un empleado cambió de campaña después del período, se mostrará bajo su campaña actual.
        </div>

        <!-- Selector de período -->
        <div class="glass-card mb-6">
            <h2 class="text-xl font-semibold mb-4">
                <i class="fas fa-calendar-alt text-indigo-400 mr-2"></i>
                Selecciona un Período
            </h2>
            <?php if (empty($periods)): ?>
                <p class="text-slate-400 text-center py-6">No hay períodos disponibles.</p>
            <?php else: ?>
                <form method="GET" class="flex flex-wrap items-center gap-3">
                    <select name="period_id" class="rounded border border-slate-700 bg-slate-900 text-slate-100 px-3 py-2 min-w-[280px]" onchange="this.form.submit()">
                        <option value="">— Selecciona un período —</option>
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === $selectedPeriodId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                                · <?= date('d/m/Y', strtotime($p['start_date'])) ?> – <?= date('d/m/Y', strtotime($p['end_date'])) ?>
                                · <?= htmlspecialchars($p['status']) ?>
                                <?= (int)$p['record_count'] === 0 ? ' (sin cálculo)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn-primary">Cargar</button></noscript>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($selectedPeriod && empty($campaignSummary)): ?>
            <div class="glass-card text-center py-10">
                <i class="fas fa-inbox text-slate-500 text-4xl mb-3"></i>
                <p class="text-slate-300 font-medium">Este período no tiene nómina calculada todavía.</p>
                <p class="text-slate-400 text-sm mt-1">Ve a <a href="payroll.php" class="text-blue-400 hover:underline">Nómina</a> y presiona "Calcular" sobre el período.</p>
            </div>
        <?php endif; ?>

        <?php if ($selectedPeriod && !empty($campaignSummary)): ?>
            <!-- Métricas globales del período -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="glass-card">
                    <p class="text-slate-400 text-sm">Período</p>
                    <p class="font-semibold text-slate-100"><?= htmlspecialchars($selectedPeriod['name']) ?></p>
                    <p class="text-xs text-slate-400 mt-1">
                        <?= date('d/m/Y', strtotime($selectedPeriod['start_date'])) ?> –
                        <?= date('d/m/Y', strtotime($selectedPeriod['end_date'])) ?>
                    </p>
                </div>
                <div class="glass-card">
                    <p class="text-slate-400 text-sm">Campañas con nómina</p>
                    <p class="text-3xl font-bold text-blue-400"><?= count($campaignSummary) ?></p>
                </div>
                <div class="glass-card">
                    <p class="text-slate-400 text-sm">Total Bruto</p>
                    <p class="text-2xl font-bold text-slate-100"><?= formatDOP($periodGrandTotalGross) ?></p>
                </div>
                <div class="glass-card">
                    <p class="text-slate-400 text-sm">Total Neto</p>
                    <p class="text-2xl font-bold text-green-400"><?= formatDOP($periodGrandTotalNet) ?></p>
                </div>
            </div>

            <!-- Tarjetas por campaña -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
                <?php foreach ($campaignSummary as $row):
                    $cid = (int)$row['campaign_id'];
                    $pct = $periodGrandTotalGross > 0 ? ((float)$row['total_gross'] / $periodGrandTotalGross) * 100 : 0;
                    $isActive = ($selectedCampaignFilter !== null && (int)$selectedCampaignFilter === $cid);
                ?>
                    <a href="?period_id=<?= $selectedPeriodId ?>&campaign_id=<?= $cid ?>#drilldown"
                       class="campaign-card block <?= $isActive ? 'ring-2 ring-indigo-400' : '' ?>">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <span class="campaign-dot" style="background: <?= htmlspecialchars($row['campaign_color']) ?>;"></span>
                                <h3 class="font-semibold text-slate-100"><?= htmlspecialchars($row['campaign_name']) ?></h3>
                            </div>
                            <span class="text-xs bg-slate-700 text-slate-200 rounded-full px-2 py-0.5">
                                <i class="fas fa-users mr-1"></i><?= (int)$row['employee_count'] ?>
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <p class="text-slate-400 text-xs">Bruto</p>
                                <p class="font-semibold text-slate-100"><?= formatDOP($row['total_gross']) ?></p>
                            </div>
                            <div>
                                <p class="text-slate-400 text-xs">Neto</p>
                                <p class="font-semibold text-green-400"><?= formatDOP($row['total_net']) ?></p>
                            </div>
                            <div>
                                <p class="text-slate-400 text-xs">Descuentos</p>
                                <p class="text-red-400"><?= formatDOP($row['total_deductions']) ?></p>
                            </div>
                            <div>
                                <p class="text-slate-400 text-xs">Horas</p>
                                <p class="text-slate-200"><?= number_format((float)$row['total_hours'], 1) ?> <span class="text-xs text-slate-500">(<?= number_format((float)$row['overtime_hours'], 1) ?> extra)</span></p>
                            </div>
                        </div>

                        <div class="mt-3">
                            <div class="flex justify-between text-xs text-slate-400 mb-1">
                                <span>% del total bruto</span>
                                <span><?= number_format($pct, 1) ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <span style="width: <?= min(100, $pct) ?>%; background: <?= htmlspecialchars($row['campaign_color']) ?>;"></span>
                            </div>
                        </div>

                        <div class="mt-3 text-right text-xs text-indigo-300">
                            Ver empleados <i class="fas fa-arrow-right ml-1"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Tabla resumen (alternativa compacta) -->
            <div class="glass-card mb-6">
                <h2 class="text-lg font-semibold mb-4">
                    <i class="fas fa-table text-orange-400 mr-2"></i>
                    Resumen por Campaña
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-2 px-2">Campaña</th>
                                <th class="text-center py-2 px-2">Empleados</th>
                                <th class="text-right py-2 px-2">Horas</th>
                                <th class="text-right py-2 px-2">Horas Extra</th>
                                <th class="text-right py-2 px-2">Bruto</th>
                                <th class="text-right py-2 px-2">Descuentos</th>
                                <th class="text-right py-2 px-2">Neto</th>
                                <th class="text-center py-2 px-2">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaignSummary as $row):
                                $cid = (int)$row['campaign_id'];
                            ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/40">
                                    <td class="py-2 px-2">
                                        <div class="flex items-center gap-2">
                                            <span class="campaign-dot" style="background: <?= htmlspecialchars($row['campaign_color']) ?>;"></span>
                                            <span class="font-medium"><?= htmlspecialchars($row['campaign_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="py-2 px-2 text-center"><?= (int)$row['employee_count'] ?></td>
                                    <td class="py-2 px-2 text-right"><?= number_format((float)$row['total_hours'], 1) ?></td>
                                    <td class="py-2 px-2 text-right"><?= number_format((float)$row['overtime_hours'], 1) ?></td>
                                    <td class="py-2 px-2 text-right font-semibold"><?= formatDOP($row['total_gross']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($row['total_deductions']) ?></td>
                                    <td class="py-2 px-2 text-right text-green-400 font-semibold"><?= formatDOP($row['total_net']) ?></td>
                                    <td class="py-2 px-2 text-center">
                                        <div class="flex justify-center gap-1">
                                            <a href="?period_id=<?= $selectedPeriodId ?>&campaign_id=<?= $cid ?>#drilldown"
                                               class="px-2 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs" title="Ver empleados">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="payroll_export_pdf.php?period_id=<?= $selectedPeriodId ?>&campaign_id=<?= $cid ?>"
                                               target="_blank"
                                               class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs" title="PDF de esta campaña">
                                                <i class="fas fa-file-pdf"></i>
                                            </a>
                                            <a href="payroll_export_excel.php?period_id=<?= $selectedPeriodId ?>&campaign_id=<?= $cid ?>"
                                               class="px-2 py-1 rounded bg-green-600 hover:bg-green-700 text-white text-xs" title="Excel de esta campaña">
                                                <i class="fas fa-file-excel"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="bg-slate-800/70 font-bold">
                                <td class="py-2 px-2">TOTAL</td>
                                <td class="py-2 px-2 text-center">
                                    <?= array_sum(array_map(static fn($r) => (int)$r['employee_count'], $campaignSummary)) ?>
                                </td>
                                <td class="py-2 px-2 text-right">
                                    <?= number_format(array_sum(array_map(static fn($r) => (float)$r['total_hours'], $campaignSummary)), 1) ?>
                                </td>
                                <td class="py-2 px-2 text-right">
                                    <?= number_format(array_sum(array_map(static fn($r) => (float)$r['overtime_hours'], $campaignSummary)), 1) ?>
                                </td>
                                <td class="py-2 px-2 text-right"><?= formatDOP($periodGrandTotalGross) ?></td>
                                <td class="py-2 px-2 text-right text-red-400">
                                    <?= formatDOP(array_sum(array_map(static fn($r) => (float)$r['total_deductions'], $campaignSummary))) ?>
                                </td>
                                <td class="py-2 px-2 text-right text-green-400"><?= formatDOP($periodGrandTotalNet) ?></td>
                                <td class="py-2 px-2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($selectedPeriod && $drilldownCampaign): ?>
            <!-- DRILL-DOWN: empleados de una campaña -->
            <div id="drilldown" class="glass-card mb-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-xl font-semibold">
                            <span class="campaign-dot align-middle mr-2" style="background: <?= htmlspecialchars($drilldownCampaign['color']) ?>;"></span>
                            Empleados de <?= htmlspecialchars($drilldownCampaign['name']) ?>
                        </h2>
                        <?php if (!empty($drilldownCampaign['description'])): ?>
                            <p class="text-sm text-slate-400 mt-1"><?= htmlspecialchars($drilldownCampaign['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="payroll_export_pdf.php?period_id=<?= $selectedPeriodId ?>&campaign_id=<?= (int)$drilldownCampaign['id'] ?>"
                           target="_blank" class="btn-danger text-sm">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                        <a href="payroll_export_excel.php?period_id=<?= $selectedPeriodId ?>&campaign_id=<?= (int)$drilldownCampaign['id'] ?>"
                           class="btn-secondary text-sm">
                            <i class="fas fa-file-excel"></i> Excel
                        </a>
                        <a href="?period_id=<?= $selectedPeriodId ?>" class="btn-secondary text-sm">
                            <i class="fas fa-times"></i> Cerrar
                        </a>
                    </div>
                </div>

                <?php if (empty($drilldownRecords)): ?>
                    <p class="text-slate-400 text-center py-6">No hay empleados con nómina en esta campaña para el período.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-700">
                                    <th class="text-left py-2 px-2">Empleado</th>
                                    <th class="text-left py-2 px-2">Departamento</th>
                                    <th class="text-center py-2 px-2">Horas</th>
                                    <th class="text-center py-2 px-2">Horas Extra</th>
                                    <th class="text-right py-2 px-2">Inc. Ventas</th>
                                    <th class="text-right py-2 px-2">Inc. Nocturno</th>
                                    <th class="text-right py-2 px-2">Bruto</th>
                                    <th class="text-right py-2 px-2">AFP</th>
                                    <th class="text-right py-2 px-2">SFS</th>
                                    <th class="text-right py-2 px-2">ISR</th>
                                    <th class="text-right py-2 px-2">Cooperativa</th>
                                    <th class="text-right py-2 px-2">Descuento</th>
                                    <th class="text-right py-2 px-2" title="Cuotas de préstamos">Préstamos</th>
                                    <th class="text-right py-2 px-2">Otros Desc.</th>
                                    <th class="text-right py-2 px-2">Total Desc.</th>
                                    <th class="text-right py-2 px-2">Neto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($drilldownRecords as $r):
                                    $coopAmt = (float)$r['cooperative_deduction'];
                                    $addAmt = (float)$r['additional_deduction'];
                                    $loanAmt = (float)($loanDeductionsByEmployee[(int)$r['employee_id']] ?? 0);
                                    $othersOnly = max(0, (float)$r['other_deductions'] - $coopAmt - $addAmt - $loanAmt);
                                ?>
                                    <tr class="border-b border-slate-800 hover:bg-slate-800/40">
                                        <td class="py-2 px-2">
                                            <div class="font-medium"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                            <div class="text-xs text-slate-400"><?= htmlspecialchars($r['employee_code']) ?></div>
                                        </td>
                                        <td class="py-2 px-2 text-slate-300"><?= htmlspecialchars($r['department_name'] ?? '-') ?></td>
                                        <td class="py-2 px-2 text-center"><?= number_format((float)$r['total_hours'], 1) ?></td>
                                        <td class="py-2 px-2 text-center"><?= number_format((float)$r['overtime_hours'], 1) ?></td>
                                        <td class="py-2 px-2 text-right text-emerald-300"><?= formatDOP($r['sales_incentive']) ?></td>
                                        <td class="py-2 px-2 text-right text-amber-300"><?= formatDOP($r['night_incentive']) ?></td>
                                        <td class="py-2 px-2 text-right font-semibold"><?= formatDOP($r['gross_salary']) ?></td>
                                        <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($r['afp_employee']) ?></td>
                                        <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($r['sfs_employee']) ?></td>
                                        <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($r['isr']) ?></td>
                                        <td class="py-2 px-2 text-right text-red-400"><?= $coopAmt > 0 ? formatDOP($coopAmt) : '-' ?></td>
                                        <td class="py-2 px-2 text-right text-red-400"><?= $addAmt > 0 ? formatDOP($addAmt) : '-' ?></td>
                                        <td class="py-2 px-2 text-right text-amber-400 font-semibold"><?= $loanAmt > 0 ? formatDOP($loanAmt) : '-' ?></td>
                                        <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($othersOnly) ?></td>
                                        <td class="py-2 px-2 text-right text-red-500 font-semibold"><?= formatDOP($r['total_deductions']) ?></td>
                                        <td class="py-2 px-2 text-right text-green-400 font-bold"><?= formatDOP($r['net_salary']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="bg-slate-800/70 font-bold">
                                    <td class="py-3 px-2" colspan="2">TOTAL CAMPAÑA</td>
                                    <td class="py-3 px-2 text-center"><?= number_format($drilldownTotals['hours'], 1) ?></td>
                                    <td class="py-3 px-2 text-center"><?= number_format($drilldownTotals['overtime_hours'], 1) ?></td>
                                    <td class="py-3 px-2" colspan="2"></td>
                                    <td class="py-3 px-2 text-right"><?= formatDOP($drilldownTotals['gross']) ?></td>
                                    <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($drilldownTotals['afp']) ?></td>
                                    <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($drilldownTotals['sfs']) ?></td>
                                    <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($drilldownTotals['isr']) ?></td>
                                    <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($drilldownTotals['cooperative']) ?></td>
                                    <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($drilldownTotals['additional']) ?></td>
                                    <td class="py-3 px-2 text-right text-amber-300"><?= formatDOP($drilldownTotals['loans']) ?></td>
                                    <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($drilldownTotals['other']) ?></td>
                                    <td class="py-3 px-2 text-right text-red-500"><?= formatDOP($drilldownTotals['deductions']) ?></td>
                                    <td class="py-3 px-2 text-right text-green-400"><?= formatDOP($drilldownTotals['net']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>
