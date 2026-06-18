<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';

// READ-ONLY view: agrupa los payroll_records ya calculados por
// (a) la campaña ACTUAL del empleado (employees.campaign_id) o
// (b) el departamento ACTUAL del empleado (employees.department_id).
// No ejecuta ningún cálculo, no escribe a payroll_records ni a payroll_periods.

ensurePermission('hr_payroll', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Modo de agrupación: campaign (default) o department.
$groupBy = (isset($_GET['group_by']) && $_GET['group_by'] === 'department') ? 'department' : 'campaign';

$selectedPeriodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;

// Filtro de drill-down: campaign_id ó department_id según modo.
$drilldownKey = $groupBy === 'department' ? 'department_id' : 'campaign_id';
$selectedGroupFilter = null;
if (isset($_GET[$drilldownKey]) && $_GET[$drilldownKey] !== '') {
    $selectedGroupFilter = (int)$_GET[$drilldownKey];
}

// Etiquetas según modo.
if ($groupBy === 'department') {
    $modeLabels = [
        'singular'    => 'Departamento',
        'plural'      => 'Departamentos',
        'titleHeader' => 'Nómina por Departamento',
        'subtitle'    => 'Agrupación de la nómina ya calculada por departamento actual del empleado',
        'icon'        => 'fa-building',
        'iconColor'   => 'text-purple-400',
        'sinGroup'    => 'Sin Departamento',
        'noteHistory' => 'Si un empleado cambió de departamento después del período, se mostrará bajo su departamento actual.',
    ];
} else {
    $modeLabels = [
        'singular'    => 'Campaña',
        'plural'      => 'Campañas',
        'titleHeader' => 'Nómina por Campaña',
        'subtitle'    => 'Agrupación de la nómina ya calculada por campaña actual del empleado',
        'icon'        => 'fa-bullhorn',
        'iconColor'   => 'text-blue-400',
        'sinGroup'    => 'Sin Campaña',
        'noteHistory' => 'Si un empleado cambió de campaña después del período, se mostrará bajo su campaña actual.',
    ];
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
$groupSummary = [];          // resumen agrupado
$drilldownRecords = [];       // detalle de empleados del grupo seleccionado
$drilldownGroup = null;       // info del grupo en drill-down
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
    // Configuración SQL según el modo de agrupación.
    if ($groupBy === 'department') {
        // departments no tiene columna color; usamos un slate por defecto.
        $summarySql = "
            SELECT
                COALESCE(d.id, 0) AS group_id,
                COALESCE(d.name, 'Sin Departamento') AS group_name,
                '#5e7cba' AS group_color,
                COUNT(DISTINCT pr.employee_id) AS employee_count,
                SUM(pr.total_hours) AS total_hours,
                SUM(pr.overtime_hours) AS overtime_hours,
                SUM(pr.gross_salary) AS total_gross,
                SUM(pr.total_deductions) AS total_deductions,
                SUM(pr.net_salary) AS total_net
            FROM payroll_records pr
            JOIN employees e ON e.id = pr.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE pr.payroll_period_id = ?
            GROUP BY COALESCE(d.id, 0), COALESCE(d.name, 'Sin Departamento')
            ORDER BY total_gross DESC
        ";
        $foreignKey = 'department_id';
    } else {
        $summarySql = "
            SELECT
                COALESCE(c.id, 0) AS group_id,
                COALESCE(c.name, 'Sin Campaña') AS group_name,
                COALESCE(c.color, '#64748b') AS group_color,
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
        ";
        $foreignKey = 'campaign_id';
    }

    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute([$selectedPeriodId]);
    $groupSummary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

    // Drill-down: detalle del grupo seleccionado.
    if ($selectedGroupFilter !== null) {
        if ($selectedGroupFilter > 0) {
            if ($groupBy === 'department') {
                $infoStmt = $pdo->prepare("SELECT id, name, description, '#5e7cba' AS color FROM departments WHERE id = ?");
            } else {
                $infoStmt = $pdo->prepare("SELECT id, name, color, description FROM campaigns WHERE id = ?");
            }
            $infoStmt->execute([$selectedGroupFilter]);
            $drilldownGroup = $infoStmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($selectedGroupFilter === 0) {
            $drilldownGroup = [
                'id' => 0,
                'name' => $modeLabels['sinGroup'],
                'color' => '#64748b',
                'description' => 'Empleados sin ' . strtolower($modeLabels['singular']) . ' asignada',
            ];
        }

        if ($drilldownGroup) {
            $filter = $selectedGroupFilter > 0
                ? "AND e.$foreignKey = ?"
                : "AND e.$foreignKey IS NULL";

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
            if ($selectedGroupFilter > 0) {
                $recStmt->execute([$selectedPeriodId, $selectedGroupFilter]);
            } else {
                $recStmt->execute([$selectedPeriodId]);
            }
            $drilldownRecords = $recStmt->fetchAll(PDO::FETCH_ASSOC);

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

// Total global del período (para mostrar % de cada grupo sobre el total)
$periodGrandTotalGross = 0.0;
$periodGrandTotalNet = 0.0;
foreach ($groupSummary as $row) {
    $periodGrandTotalGross += (float)$row['total_gross'];
    $periodGrandTotalNet += (float)$row['total_net'];
}

// Helper para construir URLs preservando period_id y modo.
$buildUrl = function (array $params) use ($selectedPeriodId, $groupBy) {
    $base = ['group_by' => $groupBy];
    if ($selectedPeriodId) $base['period_id'] = $selectedPeriodId;
    return '?' . http_build_query(array_merge($base, $params));
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($modeLabels['titleHeader']) ?> - HR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .group-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.25rem;
            transition: transform .12s ease, border-color .12s ease;
        }
        .group-card:hover {
            transform: translateY(-2px);
            border-color: rgba(58, 93, 160, 0.5);
        }
        .theme-light .group-card {
            background: #ffffff;
            border-color: rgba(148, 163, 184, 0.25);
        }
        .group-dot {
            width: 14px; height: 14px; border-radius: 4px; display: inline-block;
        }
        .progress-bar {
            height: 6px; background: rgba(148, 163, 184, 0.18); border-radius: 999px; overflow: hidden;
        }
        .progress-bar > span {
            display: block; height: 100%; border-radius: 999px;
        }
        .mode-pill {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.5rem 1rem; border-radius: 999px;
            background: var(--surface);
            border: 1px solid var(--border);
            color: #cbd5e1;
            font-weight: 500;
            transition: all .15s ease;
        }
        .mode-pill:hover { border-color: rgba(58, 93, 160, 0.5); color: #fff; }
        .mode-pill.active {
            background: linear-gradient(135deg, #152849, #3a5da0);
            border-color: #3a5da0;
            color: #fff;
        }
        .theme-light .mode-pill { color: var(--text-muted); }
        .theme-light .mode-pill:hover { color: var(--text); }
        .theme-light .mode-pill.active { color: #fff; }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6 flex-wrap gap-3">
            <div>
                <h1 class="text-3xl font-bold mb-2">
                    <i class="fas <?= htmlspecialchars($modeLabels['icon']) ?> <?= htmlspecialchars($modeLabels['iconColor']) ?> mr-3"></i>
                    <?= htmlspecialchars($modeLabels['titleHeader']) ?>
                </h1>
                <p class="text-slate-400"><?= htmlspecialchars($modeLabels['subtitle']) ?></p>
            </div>
            <div class="flex gap-3 flex-wrap">
                <a href="payroll.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver a Nómina
                </a>
            </div>
        </div>

        <!-- Toggle de modo -->
        <div class="flex flex-wrap gap-3 mb-6">
            <?php
            $campaignUrl = '?group_by=campaign' . ($selectedPeriodId ? '&period_id=' . $selectedPeriodId : '');
            $deptUrl = '?group_by=department' . ($selectedPeriodId ? '&period_id=' . $selectedPeriodId : '');
            ?>
            <a href="<?= $campaignUrl ?>" class="mode-pill <?= $groupBy === 'campaign' ? 'active' : '' ?>">
                <i class="fas fa-bullhorn"></i> Por Campaña
            </a>
            <a href="<?= $deptUrl ?>" class="mode-pill <?= $groupBy === 'department' ? 'active' : '' ?>">
                <i class="fas fa-building"></i> Por Departamento
            </a>
        </div>

        <!-- Aviso de limitación histórica -->
        <div class="status-banner mb-6" style="background: rgba(38, 75, 139, 0.08); border: 1px solid rgba(38, 75, 139, 0.25); color: var(--text-muted); padding: 0.75rem 1rem; border-radius: 10px;">
            <i class="fas fa-info-circle mr-2"></i>
            La agrupación usa <strong><?= strtolower($modeLabels['singular']) === 'campaña' ? 'la campaña' : 'el departamento' ?> actual</strong> del empleado.
            <?= htmlspecialchars($modeLabels['noteHistory']) ?>
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
                    <input type="hidden" name="group_by" value="<?= htmlspecialchars($groupBy) ?>">
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

        <?php if ($selectedPeriod && empty($groupSummary)): ?>
            <div class="glass-card text-center py-10">
                <i class="fas fa-inbox text-slate-500 text-4xl mb-3"></i>
                <p class="text-slate-300 font-medium">Este período no tiene nómina calculada todavía.</p>
                <p class="text-slate-400 text-sm mt-1">Ve a <a href="payroll.php" class="text-blue-400 hover:underline">Nómina</a> y presiona "Calcular" sobre el período.</p>
            </div>
        <?php endif; ?>

        <?php if ($selectedPeriod && !empty($groupSummary)): ?>
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
                    <p class="text-slate-400 text-sm"><?= htmlspecialchars($modeLabels['plural']) ?> con nómina</p>
                    <p class="text-3xl font-bold text-blue-400"><?= count($groupSummary) ?></p>
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

            <!-- Tarjetas por grupo -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
                <?php foreach ($groupSummary as $row):
                    $gid = (int)$row['group_id'];
                    $pct = $periodGrandTotalGross > 0 ? ((float)$row['total_gross'] / $periodGrandTotalGross) * 100 : 0;
                    $isActive = ($selectedGroupFilter !== null && (int)$selectedGroupFilter === $gid);
                    $cardUrl = $buildUrl([$drilldownKey => $gid]) . '#drilldown';
                ?>
                    <a href="<?= htmlspecialchars($cardUrl) ?>"
                       class="group-card block <?= $isActive ? 'ring-2 ring-indigo-400' : '' ?>">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <span class="group-dot" style="background: <?= htmlspecialchars($row['group_color']) ?>;"></span>
                                <h3 class="font-semibold text-slate-100"><?= htmlspecialchars($row['group_name']) ?></h3>
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
                                <span style="width: <?= min(100, $pct) ?>%; background: <?= htmlspecialchars($row['group_color']) ?>;"></span>
                            </div>
                        </div>

                        <div class="mt-3 text-right text-xs text-indigo-300">
                            Ver empleados <i class="fas fa-arrow-right ml-1"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Tabla resumen -->
            <div class="glass-card mb-6">
                <h2 class="text-lg font-semibold mb-4">
                    <i class="fas fa-table text-orange-400 mr-2"></i>
                    Resumen por <?= htmlspecialchars($modeLabels['singular']) ?>
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-2 px-2"><?= htmlspecialchars($modeLabels['singular']) ?></th>
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
                            <?php foreach ($groupSummary as $row):
                                $gid = (int)$row['group_id'];
                                $viewUrl = $buildUrl([$drilldownKey => $gid]) . '#drilldown';
                                $pdfUrl = 'payroll_export_pdf.php?period_id=' . $selectedPeriodId . '&' . $drilldownKey . '=' . $gid;
                                $xlsUrl = 'payroll_export_excel.php?period_id=' . $selectedPeriodId . '&' . $drilldownKey . '=' . $gid;
                            ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/40">
                                    <td class="py-2 px-2">
                                        <div class="flex items-center gap-2">
                                            <span class="group-dot" style="background: <?= htmlspecialchars($row['group_color']) ?>;"></span>
                                            <span class="font-medium"><?= htmlspecialchars($row['group_name']) ?></span>
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
                                            <a href="<?= htmlspecialchars($viewUrl) ?>"
                                               class="px-2 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs" title="Ver empleados">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank"
                                               class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs"
                                               title="PDF de <?= htmlspecialchars(strtolower($modeLabels['singular'])) ?>">
                                                <i class="fas fa-file-pdf"></i>
                                            </a>
                                            <a href="<?= htmlspecialchars($xlsUrl) ?>"
                                               class="px-2 py-1 rounded bg-green-600 hover:bg-green-700 text-white text-xs"
                                               title="Excel de <?= htmlspecialchars(strtolower($modeLabels['singular'])) ?>">
                                                <i class="fas fa-file-excel"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="bg-slate-800/70 font-bold">
                                <td class="py-2 px-2">TOTAL</td>
                                <td class="py-2 px-2 text-center">
                                    <?= array_sum(array_map(static fn($r) => (int)$r['employee_count'], $groupSummary)) ?>
                                </td>
                                <td class="py-2 px-2 text-right">
                                    <?= number_format(array_sum(array_map(static fn($r) => (float)$r['total_hours'], $groupSummary)), 1) ?>
                                </td>
                                <td class="py-2 px-2 text-right">
                                    <?= number_format(array_sum(array_map(static fn($r) => (float)$r['overtime_hours'], $groupSummary)), 1) ?>
                                </td>
                                <td class="py-2 px-2 text-right"><?= formatDOP($periodGrandTotalGross) ?></td>
                                <td class="py-2 px-2 text-right text-red-400">
                                    <?= formatDOP(array_sum(array_map(static fn($r) => (float)$r['total_deductions'], $groupSummary))) ?>
                                </td>
                                <td class="py-2 px-2 text-right text-green-400"><?= formatDOP($periodGrandTotalNet) ?></td>
                                <td class="py-2 px-2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($selectedPeriod && $drilldownGroup): ?>
            <!-- DRILL-DOWN: empleados de un grupo -->
            <?php
                $pdfUrlD = 'payroll_export_pdf.php?period_id=' . $selectedPeriodId . '&' . $drilldownKey . '=' . (int)$drilldownGroup['id'];
                $xlsUrlD = 'payroll_export_excel.php?period_id=' . $selectedPeriodId . '&' . $drilldownKey . '=' . (int)$drilldownGroup['id'];
                $closeUrl = $buildUrl([]);
            ?>
            <div id="drilldown" class="glass-card mb-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-xl font-semibold">
                            <span class="group-dot align-middle mr-2" style="background: <?= htmlspecialchars($drilldownGroup['color']) ?>;"></span>
                            Empleados de <?= htmlspecialchars($drilldownGroup['name']) ?>
                        </h2>
                        <?php if (!empty($drilldownGroup['description'])): ?>
                            <p class="text-sm text-slate-400 mt-1"><?= htmlspecialchars($drilldownGroup['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= htmlspecialchars($pdfUrlD) ?>" target="_blank" class="btn-danger text-sm">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                        <a href="<?= htmlspecialchars($xlsUrlD) ?>" class="btn-secondary text-sm">
                            <i class="fas fa-file-excel"></i> Excel
                        </a>
                        <a href="<?= htmlspecialchars($closeUrl) ?>" class="btn-secondary text-sm">
                            <i class="fas fa-times"></i> Cerrar
                        </a>
                    </div>
                </div>

                <?php if (empty($drilldownRecords)): ?>
                    <p class="text-slate-400 text-center py-6">No hay empleados con nómina en <?= htmlspecialchars(strtolower($modeLabels['singular'])) ?> para el período.</p>
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
                                    <td class="py-3 px-2" colspan="2">TOTAL <?= htmlspecialchars(strtoupper($modeLabels['singular'])) ?></td>
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
