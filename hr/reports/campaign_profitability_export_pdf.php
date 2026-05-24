<?php
session_start();
require_once '../../db.php';
require_once '../../quality_db.php';
require_once '../payroll_functions.php';
require_once __DIR__ . '/campaign_profitability_data.php';
require_once '../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

ensurePermission('hr_payroll', '../../unauthorized.php');

// Filtros
$today = date('Y-m-d');
$defaultStart = date('Y-m-01');
$startDate = $_GET['start_date'] ?? $defaultStart;
$endDate   = $_GET['end_date']   ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate = $today;
if ($endDate < $startDate) { $tmp = $endDate; $endDate = $startDate; $startDate = $tmp; }

$campaignFilter = (isset($_GET['campaign_id']) && $_GET['campaign_id'] !== '')
    ? (int)$_GET['campaign_id']
    : null;

$data = loadCampaignProfitabilityData($pdo, $startDate, $endDate, $campaignFilter);
$rows = $data['rows'];
$totals = $data['totals'];
$qaAvailable = $data['qaAvailable'];

// Si se filtró por una campaña, dejamos solo esa fila en el resumen.
$filteredCampaignName = null;
if ($campaignFilter !== null) {
    $filteredRows = array_values(array_filter($rows, fn($r) => (int)$r['campaign_id'] === $campaignFilter));
    if (!empty($filteredRows)) {
        $rows = $filteredRows;
        $filteredCampaignName = $filteredRows[0]['campaign_name'];
        // Recalcular totales filtrados
        $r = $filteredRows[0];
        $totals = [
            'revenue' => $r['revenue'] ?? 0.0,
            'cost' => $r['total_cost'],
            'profit' => $r['profit'] ?? -$r['total_cost'],
            'margin' => $r['margin'],
            'campaigns_with_revenue' => $r['revenue'] !== null ? 1 : 0,
            'campaigns_profitable' => ($r['profit'] ?? -1) > 0 ? 1 : 0,
            'sales' => $r['sales'] ?? 0,
            'volume' => $r['volume'],
        ];
    }
}

function pdfMoney(?float $n): string {
    if ($n === null) return '—';
    return 'RD$' . number_format($n, 2);
}
function pdfMargin(?float $pct): string {
    if ($pct === null) return '—';
    return number_format($pct, 1) . '%';
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Rentabilidad por Campaña</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 9.5px; color: #1f2937; }
        .header { text-align: center; margin-bottom: 16px; border-bottom: 2px solid #059669; padding-bottom: 8px; }
        .header h1 { margin: 0; color: #047857; font-size: 18px; }
        .header p { margin: 3px 0; color: #555; }
        .info-box { background: #f0fdf4; padding: 8px; margin-bottom: 12px; border-radius: 4px; border-left: 3px solid #10b981; }
        .info-box table { width: 100%; }
        .info-box td { padding: 3px 6px; font-size: 10px; }
        table.report { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.report th { background: #047857; color: #fff; padding: 6px 4px; font-size: 9px; text-align: left; border: 1px solid #065f46; }
        table.report td { padding: 5px 4px; border: 1px solid #e5e7eb; font-size: 9px; }
        table.report tr:nth-child(even) { background: #f9fafb; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .totals-row { background: #d1fae5 !important; font-weight: bold; }
        .profit-pos { color: #059669; font-weight: bold; }
        .profit-neg { color: #dc2626; font-weight: bold; }
        .muted { color: #9ca3af; font-style: italic; }
        .section-title { background: #047857; color: #fff; padding: 5px 10px; margin: 14px 0 4px; font-weight: bold; font-size: 11px; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 4px; }
        .footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 8px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="header">
        <h1>REPORTE DE RENTABILIDAD POR CAMPAÑA</h1>
        <?php if ($filteredCampaignName !== null): ?>
            <p><strong>Campaña: <?= htmlspecialchars($filteredCampaignName) ?></strong></p>
        <?php endif; ?>
        <p>Período: <?= date('d/m/Y', strtotime($startDate)) ?> - <?= date('d/m/Y', strtotime($endDate)) ?></p>
    </div>

    <div class="info-box">
        <table>
            <tr>
                <td><strong>Ingresos:</strong> <?= pdfMoney($totals['revenue']) ?></td>
                <td><strong>Costo:</strong> <?= pdfMoney($totals['cost']) ?></td>
                <td><strong>Ganancia:</strong>
                    <span class="<?= $totals['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>">
                        <?= pdfMoney($totals['profit']) ?>
                    </span>
                </td>
                <td><strong>Margen:</strong>
                    <?= $totals['margin'] === null ? '<span class="muted">Sin datos</span>' : pdfMargin($totals['margin']) ?>
                </td>
                <td><strong>Rentables:</strong> <?= $totals['campaigns_profitable'] ?> de <?= $totals['campaigns_with_revenue'] ?></td>
            </tr>
        </table>
    </div>

    <div class="section-title">RANKING POR MARGEN</div>

    <table class="report">
        <thead>
            <tr>
                <th>#</th>
                <th>Campaña</th>
                <th>Supervisor(es)</th>
                <th class="text-center">Equipo</th>
                <th class="text-right">Horas</th>
                <th class="text-right">Ingreso</th>
                <th class="text-right">Costo</th>
                <th class="text-right">Ganancia</th>
                <th class="text-right">Margen</th>
                <?php if ($qaAvailable): ?><th class="text-right">QA</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php $rank = 1; foreach ($rows as $r):
                $supText = !empty($r['supervisors']) ? implode(', ', $r['supervisors']) : '—';
                $rolesText = '';
                if ($r['roles']['AGENT'] > 0)       $rolesText .= $r['roles']['AGENT'] . ' Ag. ';
                if ($r['roles']['SUPERVISOR'] > 0)  $rolesText .= $r['roles']['SUPERVISOR'] . ' Sup. ';
                if ($r['roles']['OTROS'] > 0)       $rolesText .= $r['roles']['OTROS'] . ' Otros';
                $rolesText = trim($rolesText) ?: '—';
            ?>
                <tr>
                    <td class="text-center"><?= $rank++ ?></td>
                    <td>
                        <span class="dot" style="background: <?= htmlspecialchars($r['campaign_color']) ?>;"></span>
                        <?= htmlspecialchars($r['campaign_name']) ?>
                    </td>
                    <td><?= htmlspecialchars($supText) ?></td>
                    <td class="text-center" style="font-size: 8px;"><?= htmlspecialchars($rolesText) ?></td>
                    <td class="text-right"><?= number_format($r['total_hours'], 1) ?></td>
                    <td class="text-right"><?= $r['revenue'] !== null ? pdfMoney($r['revenue']) : '<span class="muted">Sin datos</span>' ?></td>
                    <td class="text-right"><?= $r['total_cost'] > 0 ? pdfMoney($r['total_cost']) : '—' ?></td>
                    <td class="text-right">
                        <?php if ($r['profit'] !== null): ?>
                            <span class="<?= $r['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= pdfMoney($r['profit']) ?></span>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-right"><?= $r['margin'] === null ? '—' : pdfMargin($r['margin']) ?></td>
                    <?php if ($qaAvailable): ?>
                        <td class="text-right"><?= $r['qa'] !== null ? number_format($r['qa']['avg_score'], 1) . '%' : '<span class="muted">—</span>' ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <tr class="totals-row">
                <td colspan="5"><strong>TOTAL</strong></td>
                <td class="text-right"><strong><?= pdfMoney($totals['revenue']) ?></strong></td>
                <td class="text-right"><strong><?= pdfMoney($totals['cost']) ?></strong></td>
                <td class="text-right">
                    <span class="<?= $totals['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>">
                        <?= pdfMoney($totals['profit']) ?>
                    </span>
                </td>
                <td class="text-right"><strong><?= $totals['margin'] === null ? '—' : pdfMargin($totals['margin']) ?></strong></td>
                <?php if ($qaAvailable): ?><td></td><?php endif; ?>
            </tr>
        </tbody>
    </table>

    <?php if ($campaignFilter !== null && !empty($data['drilldownByDept'])): ?>
        <div class="section-title">DESGLOSE POR DEPARTAMENTO</div>
        <table class="report">
            <thead>
                <tr>
                    <th>Departamento</th>
                    <th class="text-center">Empleados</th>
                    <th class="text-right">Horas</th>
                    <th class="text-right">Bruto</th>
                    <th class="text-right">Aportes</th>
                    <th class="text-right">Costo total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['drilldownByDept'] as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['dept_name']) ?></td>
                        <td class="text-center"><?= (int)$d['emp_count'] ?></td>
                        <td class="text-right"><?= number_format((float)$d['hours'], 1) ?></td>
                        <td class="text-right"><?= pdfMoney((float)$d['gross']) ?></td>
                        <td class="text-right"><?= pdfMoney((float)$d['employer']) ?></td>
                        <td class="text-right"><strong><?= pdfMoney((float)$d['total_cost']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($campaignFilter !== null && !empty($data['drilldownEmployees'])): ?>
        <div class="section-title">EMPLEADOS DE LA CAMPAÑA</div>
        <table class="report">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Empleado</th>
                    <th>Cargo</th>
                    <th>Rol</th>
                    <th>Depto</th>
                    <th class="text-right">Horas</th>
                    <th class="text-right">Bruto</th>
                    <th class="text-right">Aportes</th>
                    <th class="text-right">Costo total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['drilldownEmployees'] as $emp): ?>
                    <tr>
                        <td><?= htmlspecialchars($emp['employee_code']) ?></td>
                        <td><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></td>
                        <td><?= htmlspecialchars($emp['position'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($emp['role'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($emp['dept_name']) ?></td>
                        <td class="text-right"><?= number_format((float)$emp['hours'], 1) ?></td>
                        <td class="text-right"><?= pdfMoney((float)$emp['gross']) ?></td>
                        <td class="text-right"><?= pdfMoney((float)$emp['employer']) ?></td>
                        <td class="text-right"><strong><?= pdfMoney((float)$emp['total_cost']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="footer">
        <p>Generado <?= date('d/m/Y H:i:s') ?> · Costo = sueldo bruto + aportes patronales · Ingresos: campaign_sales_reports</p>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('Letter', 'landscape');
$dompdf->render();

$slug = $filteredCampaignName !== null
    ? '_' . preg_replace('/[^A-Za-z0-9]+/', '-', $filteredCampaignName)
    : '';
$filename = 'Rentabilidad_' . str_replace('-', '', $startDate) . '_' . str_replace('-', '', $endDate) . $slug . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
