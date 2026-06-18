<?php
session_start();
require_once '../../db.php';
require_once '../../quality_db.php';
require_once '../payroll_functions.php';
require_once __DIR__ . '/campaign_profitability_data.php';

// ============================================================================
// REPORTE DE RENTABILIDAD POR CAMPAÑA — vista gerencial READ-ONLY.
//
// Fuentes (todas existentes — no se modifica ningún dato):
//   - Costos:    payroll_records (gross_salary + total_employer_contributions)
//                filtrados por payroll_periods.payment_date dentro del rango
//   - Ingresos:  campaign_sales_reports (revenue_amount, sales_amount)
//                filtrados por report_date dentro del rango
//   - Roles:     users.role agregado por campaign_id (snapshot actual)
//   - Sup.:      supervisor_campaigns JOIN users
//   - QA:        evaluations en BD calidad (opcional — si la conexión falla
//                el reporte sigue funcionando sin QA)
//
// NO ejecuta cálculos de nómina; solo lee lo ya calculado.
// ============================================================================

ensurePermission('hr_payroll', '../../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// ---- Filtros ----------------------------------------------------------------
$today = date('Y-m-d');
$defaultStart = date('Y-m-01');
$startDate = $_GET['start_date'] ?? $defaultStart;
$endDate   = $_GET['end_date']   ?? $today;

// Validar formato (defensa básica)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate = $today;
if ($endDate < $startDate) { $tmp = $endDate; $endDate = $startDate; $startDate = $tmp; }

$selectedCampaignId = isset($_GET['campaign_id']) && $_GET['campaign_id'] !== ''
    ? (int)$_GET['campaign_id']
    : null;

// ---- Helpers ----------------------------------------------------------------
function fmtMoney(float $n, string $currency = 'DOP'): string {
    return ($currency === 'USD' ? 'US$' : 'RD$') . number_format($n, 2);
}
function fmtMargin(?float $pct): string {
    if ($pct === null) return '—';
    $cls = $pct >= 25 ? 'text-emerald-400' : ($pct >= 10 ? 'text-amber-300' : 'text-red-400');
    return '<span class="' . $cls . '">' . number_format($pct, 1) . '%</span>';
}

// ---- Carga toda la data vía helper compartido ------------------------------
$data = loadCampaignProfitabilityData($pdo, $startDate, $endDate, $selectedCampaignId);
$rows = $data['rows'];
$globalRevenue = $data['totals']['revenue'];
$globalCost = $data['totals']['cost'];
$globalProfit = $data['totals']['profit'];
$globalMargin = $data['totals']['margin'];
$campaignsWithRevenue = $data['totals']['campaigns_with_revenue'];
$campaignsProfitable = $data['totals']['campaigns_profitable'];
$qaAvailable = $data['qaAvailable'];
$qaError = $data['qaError'];
$drilldownCampaign = $data['drilldownCampaign'];
$drilldownByDept = $data['drilldownByDept'];
$drilldownEmployees = $data['drilldownEmployees'];

// URL helper preservando filtros
$buildUrl = function (array $params) use ($startDate, $endDate) {
    return '?' . http_build_query(array_merge([
        'start_date' => $startDate,
        'end_date'   => $endDate,
    ], $params));
};
$exportParams = ['start_date' => $startDate, 'end_date' => $endDate];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rentabilidad por Campaña - HR Reports</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/theme.css" rel="stylesheet">
    <style>
        .kpi-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.25rem;
        }
        .theme-light .kpi-card {
            background: #ffffff;
            border-color: rgba(148, 163, 184, 0.25);
        }
        .group-dot { width: 12px; height: 12px; border-radius: 4px; display: inline-block; }
        .role-chip {
            display: inline-flex; align-items: center; gap: 0.25rem;
            padding: 0.125rem 0.5rem; border-radius: 999px;
            font-size: 0.7rem; font-weight: 600;
        }
        .role-chip.agent      { background: rgba(38, 75, 139, 0.18); color: #9db1d2; }
        .role-chip.supervisor { background: rgba(124, 58, 237, 0.18); color: #c4b5fd; }
        .role-chip.otros      { background: rgba(148, 163, 184, 0.18); color: #cbd5e1; }
        .theme-light .role-chip.agent      { color: var(--brand); }
        .theme-light .role-chip.supervisor { color: #6d28d9; }
        .theme-light .role-chip.otros      { color: var(--text-muted); }
        .no-data {
            color: #64748b; font-style: italic; font-size: 0.85rem;
        }
        tr.profitable td { box-shadow: inset 3px 0 0 #10b981; }
        tr.unprofitable td { box-shadow: inset 3px 0 0 #ef4444; }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6 flex-wrap gap-3">
            <div>
                <h1 class="text-3xl font-bold mb-2">
                    <i class="fas fa-chart-line text-emerald-400 mr-3"></i>
                    Rentabilidad por Campaña
                </h1>
                <p class="text-slate-400">Ingresos vs costo de nómina por proyecto · ranking por margen</p>
            </div>
            <div class="flex gap-2 flex-wrap">
                <a href="campaign_profitability_export_pdf.php?<?= http_build_query($exportParams) ?>" target="_blank" class="btn-danger text-sm">
                    <i class="fas fa-file-pdf"></i> PDF
                </a>
                <a href="campaign_profitability_export_excel.php?<?= http_build_query($exportParams) ?>" class="btn-secondary text-sm">
                    <i class="fas fa-file-excel"></i> Excel
                </a>
                <a href="../payroll.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver a Nómina
                </a>
            </div>
        </div>

        <!-- Filtros -->
        <div class="kpi-card mb-6">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <div class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1">Fecha inicio</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>"
                           class="rounded border border-slate-700 bg-slate-900 text-slate-100 px-3 py-2">
                </div>
                <div class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1">Fecha fin</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>"
                           class="rounded border border-slate-700 bg-slate-900 text-slate-100 px-3 py-2">
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-filter"></i> Aplicar
                </button>
                <a href="?" class="btn-secondary text-sm">
                    <i class="fas fa-undo"></i> Mes actual
                </a>
                <div class="text-xs text-slate-400 ml-auto self-center">
                    <i class="fas fa-info-circle mr-1"></i>
                    Costo = sueldo bruto + aportes patronales. Ingresos: tabla <code>campaign_sales_reports</code>.
                </div>
            </form>
        </div>

        <?php if ($qaError && !$qaAvailable): ?>
            <div class="status-banner mb-4" style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); color: #fcd34d; padding: 0.75rem 1rem; border-radius: 10px;">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                QA no disponible — el reporte continúa sin esa columna.
                <span class="text-xs opacity-80">(<?= htmlspecialchars($qaError) ?>)</span>
            </div>
        <?php endif; ?>

        <!-- KPIs globales -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <div class="kpi-card">
                <p class="text-slate-400 text-xs uppercase tracking-wide">Ingresos totales</p>
                <p class="text-2xl font-bold text-emerald-400 mt-1"><?= fmtMoney($globalRevenue) ?></p>
                <p class="text-xs text-slate-500 mt-1"><?= $campaignsWithRevenue ?> de <?= count($rows) ?> campañas con datos</p>
            </div>
            <div class="kpi-card">
                <p class="text-slate-400 text-xs uppercase tracking-wide">Costo total nómina</p>
                <p class="text-2xl font-bold text-red-400 mt-1"><?= fmtMoney($globalCost) ?></p>
                <p class="text-xs text-slate-500 mt-1">Bruto + aportes patronales</p>
            </div>
            <div class="kpi-card">
                <p class="text-slate-400 text-xs uppercase tracking-wide">Ganancia neta</p>
                <p class="text-2xl font-bold mt-1 <?= $globalProfit >= 0 ? 'text-emerald-400' : 'text-red-400' ?>">
                    <?= fmtMoney($globalProfit) ?>
                </p>
                <p class="text-xs text-slate-500 mt-1">Solo campañas con ingresos reportados</p>
            </div>
            <div class="kpi-card">
                <p class="text-slate-400 text-xs uppercase tracking-wide">Margen global</p>
                <p class="text-2xl font-bold mt-1"><?= $globalMargin === null ? '<span class="no-data">Sin datos</span>' : fmtMargin($globalMargin) ?></p>
                <p class="text-xs text-slate-500 mt-1"><?= $campaignsProfitable ?> campañas rentables</p>
            </div>
            <div class="kpi-card">
                <p class="text-slate-400 text-xs uppercase tracking-wide">Período</p>
                <p class="text-base font-semibold text-slate-100 mt-1">
                    <?= date('d/m/Y', strtotime($startDate)) ?>
                </p>
                <p class="text-xs text-slate-400">a <?= date('d/m/Y', strtotime($endDate)) ?></p>
            </div>
        </div>

        <!-- Tabla principal -->
        <div class="kpi-card mb-6">
            <h2 class="text-lg font-semibold mb-4">
                <i class="fas fa-trophy text-amber-400 mr-2"></i>
                Ranking por Margen
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-700 text-xs uppercase text-slate-400">
                            <th class="text-left py-2 px-2">#</th>
                            <th class="text-left py-2 px-2">Campaña</th>
                            <th class="text-center py-2 px-2">Equipo</th>
                            <th class="text-left py-2 px-2">Supervisor(es)</th>
                            <th class="text-right py-2 px-2">Horas</th>
                            <th class="text-right py-2 px-2">Ingreso</th>
                            <th class="text-right py-2 px-2">Costo</th>
                            <th class="text-right py-2 px-2">Ganancia</th>
                            <th class="text-right py-2 px-2">Margen %</th>
                            <?php if ($qaAvailable): ?>
                                <th class="text-right py-2 px-2">QA Score</th>
                            <?php endif; ?>
                            <th class="text-center py-2 px-2">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($rows as $r):
                            $rowClass = '';
                            if ($r['profit'] !== null) {
                                $rowClass = $r['profit'] >= 0 ? 'profitable' : 'unprofitable';
                            }
                            $drillUrl = $buildUrl(['campaign_id' => $r['campaign_id']]) . '#drilldown';
                            $pdfUrl = 'campaign_profitability_export_pdf.php?' . http_build_query(array_merge($exportParams, ['campaign_id' => $r['campaign_id']]));
                            $xlsUrl = 'campaign_profitability_export_excel.php?' . http_build_query(array_merge($exportParams, ['campaign_id' => $r['campaign_id']]));
                        ?>
                            <tr class="border-b border-slate-800 hover:bg-slate-800/40 <?= $rowClass ?>">
                                <td class="py-2 px-2 text-slate-400"><?= $rank++ ?></td>
                                <td class="py-2 px-2">
                                    <div class="flex items-center gap-2">
                                        <span class="group-dot" style="background: <?= htmlspecialchars($r['campaign_color']) ?>;"></span>
                                        <div>
                                            <div class="font-medium text-slate-100"><?= htmlspecialchars($r['campaign_name']) ?></div>
                                            <?php if ($r['report_days'] > 0): ?>
                                                <div class="text-xs text-slate-500"><?= $r['report_days'] ?> días con ventas reportadas</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-2 px-2 text-center">
                                    <div class="flex flex-wrap gap-1 justify-center">
                                        <?php if ($r['roles']['AGENT'] > 0): ?>
                                            <span class="role-chip agent" title="Agentes"><i class="fas fa-headset"></i><?= $r['roles']['AGENT'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($r['roles']['SUPERVISOR'] > 0): ?>
                                            <span class="role-chip supervisor" title="Supervisores"><i class="fas fa-user-tie"></i><?= $r['roles']['SUPERVISOR'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($r['roles']['OTROS'] > 0): ?>
                                            <span class="role-chip otros" title="Otros roles"><i class="fas fa-users"></i><?= $r['roles']['OTROS'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($r['roles']['TOTAL'] === 0): ?>
                                            <span class="no-data">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-2 px-2 text-xs text-slate-300">
                                    <?php if (!empty($r['supervisors'])): ?>
                                        <?= htmlspecialchars(implode(', ', $r['supervisors'])) ?>
                                    <?php else: ?>
                                        <span class="no-data">Sin asignar</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 px-2 text-right text-slate-200">
                                    <?= number_format($r['total_hours'], 1) ?>
                                    <?php if ($r['total_overtime'] > 0): ?>
                                        <div class="text-xs text-amber-400"><?= number_format($r['total_overtime'], 1) ?> extra</div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 px-2 text-right">
                                    <?php if ($r['revenue'] !== null): ?>
                                        <div class="font-semibold text-emerald-300"><?= fmtMoney($r['revenue']) ?></div>
                                        <?php if (!empty($r['currencies']) && strpos($r['currencies'], ',') !== false): ?>
                                            <div class="text-xs text-amber-400" title="Monedas mixtas: <?= htmlspecialchars($r['currencies']) ?>">⚠ mixto</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="no-data" title="No hay datos en campaign_sales_reports para este rango">Sin datos</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 px-2 text-right text-red-400">
                                    <?= $r['total_cost'] > 0 ? fmtMoney($r['total_cost']) : '<span class="no-data">—</span>' ?>
                                </td>
                                <td class="py-2 px-2 text-right font-semibold">
                                    <?php if ($r['profit'] !== null): ?>
                                        <span class="<?= $r['profit'] >= 0 ? 'text-emerald-400' : 'text-red-400' ?>">
                                            <?= fmtMoney($r['profit']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="no-data">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 px-2 text-right font-bold"><?= fmtMargin($r['margin']) ?></td>
                                <?php if ($qaAvailable): ?>
                                    <td class="py-2 px-2 text-right">
                                        <?php if ($r['qa'] !== null): ?>
                                            <div class="font-semibold text-slate-100"><?= number_format($r['qa']['avg_score'], 1) ?>%</div>
                                            <div class="text-xs text-slate-500"><?= $r['qa']['eval_count'] ?> evals</div>
                                        <?php else: ?>
                                            <span class="no-data">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="py-2 px-2 text-center">
                                    <div class="flex justify-center gap-1">
                                        <a href="<?= htmlspecialchars($drillUrl) ?>"
                                           class="px-2 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs" title="Ver desglose por departamento">
                                            <i class="fas fa-search-plus"></i>
                                        </a>
                                        <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank"
                                           class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs" title="PDF de esta campaña">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        <a href="<?= htmlspecialchars($xlsUrl) ?>"
                                           class="px-2 py-1 rounded bg-green-600 hover:bg-green-700 text-white text-xs" title="Excel de esta campaña">
                                            <i class="fas fa-file-excel"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="<?= $qaAvailable ? 11 : 10 ?>" class="text-center text-slate-400 py-6">No hay campañas con datos en este rango.</td></tr>
                        <?php endif; ?>
                        <tr class="bg-slate-800/70 font-bold border-t-2 border-slate-600">
                            <td class="py-3 px-2" colspan="5">TOTAL</td>
                            <td class="py-3 px-2 text-right text-emerald-300"><?= fmtMoney($globalRevenue) ?></td>
                            <td class="py-3 px-2 text-right text-red-400"><?= fmtMoney($globalCost) ?></td>
                            <td class="py-3 px-2 text-right <?= $globalProfit >= 0 ? 'text-emerald-400' : 'text-red-400' ?>"><?= fmtMoney($globalProfit) ?></td>
                            <td class="py-3 px-2 text-right"><?= fmtMargin($globalMargin) ?></td>
                            <?php if ($qaAvailable): ?><td></td><?php endif; ?>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($drilldownCampaign): ?>
            <!-- DRILL-DOWN: desglose por departamento + lista de empleados -->
            <div id="drilldown" class="kpi-card mb-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-xl font-semibold">
                            <span class="group-dot mr-2" style="background: <?= htmlspecialchars($drilldownCampaign['color']) ?>;"></span>
                            Desglose: <?= htmlspecialchars($drilldownCampaign['name']) ?>
                        </h2>
                        <p class="text-sm text-slate-400 mt-1">Costo por departamento dentro de la campaña, y detalle por empleado.</p>
                    </div>
                    <a href="<?= htmlspecialchars($buildUrl([])) ?>" class="btn-secondary text-sm">
                        <i class="fas fa-times"></i> Cerrar desglose
                    </a>
                </div>

                <?php if (empty($drilldownByDept)): ?>
                    <p class="text-slate-400 text-center py-6">Esta campaña no tiene nómina registrada en el rango.</p>
                <?php else: ?>
                    <!-- Departamentos -->
                    <h3 class="text-sm uppercase text-slate-400 mb-3">Por Departamento</h3>
                    <div class="overflow-x-auto mb-6">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-700 text-xs uppercase text-slate-400">
                                    <th class="text-left py-2 px-2">Departamento</th>
                                    <th class="text-center py-2 px-2">Empleados</th>
                                    <th class="text-right py-2 px-2">Horas</th>
                                    <th class="text-right py-2 px-2">Bruto</th>
                                    <th class="text-right py-2 px-2">Aportes patronales</th>
                                    <th class="text-right py-2 px-2">Costo total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($drilldownByDept as $d): ?>
                                    <tr class="border-b border-slate-800 hover:bg-slate-800/40">
                                        <td class="py-2 px-2 font-medium"><?= htmlspecialchars($d['dept_name']) ?></td>
                                        <td class="py-2 px-2 text-center"><?= (int)$d['emp_count'] ?></td>
                                        <td class="py-2 px-2 text-right"><?= number_format((float)$d['hours'], 1) ?></td>
                                        <td class="py-2 px-2 text-right"><?= fmtMoney((float)$d['gross']) ?></td>
                                        <td class="py-2 px-2 text-right text-slate-400"><?= fmtMoney((float)$d['employer']) ?></td>
                                        <td class="py-2 px-2 text-right font-semibold text-red-400"><?= fmtMoney((float)$d['total_cost']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Empleados -->
                    <h3 class="text-sm uppercase text-slate-400 mb-3">Empleados (<?= count($drilldownEmployees) ?>)</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-700 text-xs uppercase text-slate-400">
                                    <th class="text-left py-2 px-2">Empleado</th>
                                    <th class="text-left py-2 px-2">Cargo</th>
                                    <th class="text-left py-2 px-2">Rol</th>
                                    <th class="text-left py-2 px-2">Depto</th>
                                    <th class="text-right py-2 px-2">Horas</th>
                                    <th class="text-right py-2 px-2">Bruto</th>
                                    <th class="text-right py-2 px-2">Aportes</th>
                                    <th class="text-right py-2 px-2">Costo total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($drilldownEmployees as $emp):
                                    $roleNorm = strtoupper(trim($emp['role']));
                                    $chip = $roleNorm === 'AGENT' ? 'agent' : ($roleNorm === 'SUPERVISOR' ? 'supervisor' : 'otros');
                                ?>
                                    <tr class="border-b border-slate-800 hover:bg-slate-800/40">
                                        <td class="py-2 px-2">
                                            <div class="font-medium"><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></div>
                                            <div class="text-xs text-slate-500"><?= htmlspecialchars($emp['employee_code']) ?></div>
                                        </td>
                                        <td class="py-2 px-2 text-slate-300"><?= htmlspecialchars($emp['position'] ?: '—') ?></td>
                                        <td class="py-2 px-2"><span class="role-chip <?= $chip ?>"><?= htmlspecialchars($emp['role'] ?: '—') ?></span></td>
                                        <td class="py-2 px-2 text-slate-300"><?= htmlspecialchars($emp['dept_name']) ?></td>
                                        <td class="py-2 px-2 text-right"><?= number_format((float)$emp['hours'], 1) ?>
                                            <?php if ((float)$emp['overtime'] > 0): ?>
                                                <div class="text-xs text-amber-400"><?= number_format((float)$emp['overtime'], 1) ?> extra</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-2 text-right"><?= fmtMoney((float)$emp['gross']) ?></td>
                                        <td class="py-2 px-2 text-right text-slate-400"><?= fmtMoney((float)$emp['employer']) ?></td>
                                        <td class="py-2 px-2 text-right font-semibold text-red-400"><?= fmtMoney((float)$emp['total_cost']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php include '../../footer.php'; ?>
</body>
</html>
