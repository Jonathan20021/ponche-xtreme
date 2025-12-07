<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Check permissions
ensurePermission('hr_payroll', '../unauthorized.php');

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

// Get deduction rates
$ratesStmt = $pdo->query("SELECT code, employee_percentage, employer_percentage FROM payroll_deduction_config");
$deductionRates = [];
while ($row = $ratesStmt->fetch(PDO::FETCH_ASSOC)) {
    $deductionRates[$row['code']] = $row;
}

// Get payroll records
$recordsStmt = $pdo->prepare("
    SELECT pr.*, 
           e.first_name, e.last_name, e.employee_code, e.identification_number, e.position,
           d.name as department_name
    FROM payroll_records pr
    JOIN employees e ON e.id = pr.employee_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE pr.payroll_period_id = ?
    ORDER BY e.last_name, e.first_name
");
$recordsStmt->execute([$periodId]);
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'gross' => 0,
    'afp_employee' => 0,
    'sfs_employee' => 0,
    'isr' => 0,
    'other_deductions' => 0,
    'total_deductions' => 0,
    'afp_employer' => 0,
    'sfs_employer' => 0,
    'srl_employer' => 0,
    'infotep_employer' => 0,
    'total_employer' => 0,
    'net' => 0
];

foreach ($records as $record) {
    $totals['gross'] += $record['gross_salary'];
    $totals['afp_employee'] += $record['afp_employee'];
    $totals['sfs_employee'] += $record['sfs_employee'];
    $totals['isr'] += $record['isr'];
    $totals['other_deductions'] += $record['other_deductions'];
    $totals['total_deductions'] += $record['total_deductions'];
    $totals['afp_employer'] += $record['afp_employer'];
    $totals['sfs_employer'] += $record['sfs_employer'];
    $totals['srl_employer'] += $record['srl_employer'];
    $totals['infotep_employer'] += $record['infotep_employer'];
    $totals['total_employer'] += $record['total_employer_contributions'];
    $totals['net'] += $record['net_salary'];
}

// Generate HTML
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Nómina - <?= htmlspecialchars($period['name']) ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            color: #1e40af;
            font-size: 18px;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .info-box {
            background: #f3f4f6;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
        }
        .info-box table {
            width: 100%;
        }
        .info-box td {
            padding: 3px 5px;
        }
        .info-box strong {
            color: #1f2937;
        }
        table.payroll {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table.payroll th {
            background: #2563eb;
            color: white;
            padding: 8px 4px;
            text-align: left;
            font-size: 9px;
            border: 1px solid #1e40af;
        }
        table.payroll td {
            padding: 6px 4px;
            border: 1px solid #e5e7eb;
            font-size: 9px;
        }
        table.payroll tr:nth-child(even) {
            background: #f9fafb;
        }
        table.payroll .text-right {
            text-align: right;
        }
        table.payroll .text-center {
            text-align: center;
        }
        .totals-row {
            background: #dbeafe !important;
            font-weight: bold;
        }
        .section-title {
            background: #1e40af;
            color: white;
            padding: 5px 10px;
            margin-top: 15px;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 8px;
            color: #666;
        }
        .signature-box {
            margin-top: 40px;
            display: inline-block;
            width: 45%;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 50px;
            padding-top: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>REPORTE DE NÓMINA</h1>
        <p><strong><?= htmlspecialchars($period['name']) ?></strong></p>
        <p>Período: <?= date('d/m/Y', strtotime($period['start_date'])) ?> - <?= date('d/m/Y', strtotime($period['end_date'])) ?></p>
        <p>Fecha de Pago: <?= date('d/m/Y', strtotime($period['payment_date'])) ?></p>
    </div>

    <div class="info-box">
        <table>
            <tr>
                <td><strong>Total Empleados:</strong> <?= count($records) ?></td>
                <td><strong>Salario Bruto Total:</strong> <?= formatDOP($totals['gross']) ?></td>
                <td><strong>Total Descuentos:</strong> <?= formatDOP($totals['total_deductions']) ?></td>
                <td><strong>Salario Neto Total:</strong> <?= formatDOP($totals['net']) ?></td>
            </tr>
        </table>
    </div>

    <div class="section-title">DETALLE DE NÓMINA POR EMPLEADO</div>

    <table class="payroll">
        <thead>
            <tr>
                <th>Código</th>
                <th>Empleado</th>
                <th>Cédula</th>
                <th class="text-right">Salario Bruto</th>
                <th class="text-right">AFP (<?= number_format($deductionRates['AFP']['employee_percentage'], 2) ?>%)</th>
                <th class="text-right">SFS (<?= number_format($deductionRates['SFS']['employee_percentage'], 2) ?>%)</th>
                <th class="text-right">ISR</th>
                <th class="text-right">Otros</th>
                <th class="text-right">Total Desc.</th>
                <th class="text-right">Salario Neto</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($records as $record): ?>
            <tr>
                <td><?= htmlspecialchars($record['employee_code']) ?></td>
                <td><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></td>
                <td><?= htmlspecialchars($record['identification_number'] ?: 'N/A') ?></td>
                <td class="text-right"><?= formatDOP($record['gross_salary']) ?></td>
                <td class="text-right"><?= formatDOP($record['afp_employee']) ?></td>
                <td class="text-right"><?= formatDOP($record['sfs_employee']) ?></td>
                <td class="text-right"><?= formatDOP($record['isr']) ?></td>
                <td class="text-right"><?= formatDOP($record['other_deductions']) ?></td>
                <td class="text-right"><?= formatDOP($record['total_deductions']) ?></td>
                <td class="text-right"><strong><?= formatDOP($record['net_salary']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <tr class="totals-row">
                <td colspan="3"><strong>TOTALES</strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['gross']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['afp_employee']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['sfs_employee']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['isr']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['other_deductions']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['total_deductions']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['net']) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">RESUMEN DE APORTES PATRONALES</div>

    <table class="payroll">
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="text-right">Porcentaje</th>
                <th class="text-right">Monto Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>AFP Patronal</td>
                <td class="text-right"><?= number_format($deductionRates['AFP']['employer_percentage'], 2) ?>%</td>
                <td class="text-right"><?= formatDOP($totals['afp_employer']) ?></td>
            </tr>
            <tr>
                <td>SFS Patronal</td>
                <td class="text-right"><?= number_format($deductionRates['SFS']['employer_percentage'], 2) ?>%</td>
                <td class="text-right"><?= formatDOP($totals['sfs_employer']) ?></td>
            </tr>
            <tr>
                <td>Seguro de Riesgos Laborales (SRL)</td>
                <td class="text-right"><?= number_format($deductionRates['SRL']['employer_percentage'], 2) ?>%</td>
                <td class="text-right"><?= formatDOP($totals['srl_employer']) ?></td>
            </tr>
            <tr>
                <td>INFOTEP</td>
                <td class="text-right"><?= number_format($deductionRates['INFOTEP']['employer_percentage'], 2) ?>%</td>
                <td class="text-right"><?= formatDOP($totals['infotep_employer']) ?></td>
            </tr>
            <tr class="totals-row">
                <td colspan="2"><strong>TOTAL APORTES PATRONALES</strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['total_employer']) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top: 50px;">
        <div class="signature-box">
            <div class="signature-line">
                Elaborado por
            </div>
        </div>
        <div class="signature-box" style="float: right;">
            <div class="signature-line">
                Aprobado por
            </div>
        </div>
    </div>

    <div class="footer">
        <p>Documento generado el <?= date('d/m/Y H:i:s') ?></p>
        <p>Sistema de Nómina - República Dominicana | Cumple con normativas TSS y DGII</p>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

// Configure Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('Letter', 'landscape');
$dompdf->render();

// Output PDF
$filename = 'Nomina_' . str_replace(' ', '_', $period['name']) . '_' . date('Ymd') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
?>
