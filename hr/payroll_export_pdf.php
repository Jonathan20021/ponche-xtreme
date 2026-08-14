<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Check permissions
ensurePermission('hr_payroll', '../unauthorized.php');
ensurePayrollManualIncentivesTable($pdo);

$periodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;

if (!$periodId) {
    die('Período no especificado');
}

// Filtros opcionales por campaña y/o departamento: si están presentes filtran
// los records por employees.campaign_id / employees.department_id (0 = "Sin ...").
// Sin parámetros, comportamiento idéntico al original — el flujo no se altera.
$campaignFilter = null;
if (isset($_GET['campaign_id']) && $_GET['campaign_id'] !== '') {
    $campaignFilter = (int)$_GET['campaign_id'];
}
$departmentFilter = null;
if (isset($_GET['department_id']) && $_GET['department_id'] !== '') {
    $departmentFilter = (int)$_GET['department_id'];
}

$campaignLabel = null;
if ($campaignFilter !== null) {
    if ($campaignFilter > 0) {
        $cStmt = $pdo->prepare("SELECT name FROM campaigns WHERE id = ?");
        $cStmt->execute([$campaignFilter]);
        $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        $campaignLabel = $cRow ? $cRow['name'] : 'Campaña #' . $campaignFilter;
    } else {
        $campaignLabel = 'Sin Campaña';
    }
}
$departmentLabel = null;
if ($departmentFilter !== null) {
    if ($departmentFilter > 0) {
        $dStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
        $dStmt->execute([$departmentFilter]);
        $dRow = $dStmt->fetch(PDO::FETCH_ASSOC);
        $departmentLabel = $dRow ? $dRow['name'] : 'Departamento #' . $departmentFilter;
    } else {
        $departmentLabel = 'Sin Departamento';
    }
}

// Etiqueta combinada para header/filename
$groupHeaderLabel = null;
if ($campaignLabel && $departmentLabel) {
    $groupHeaderLabel = 'Campaña: ' . $campaignLabel . ' · Depto: ' . $departmentLabel;
} elseif ($campaignLabel) {
    $groupHeaderLabel = 'Campaña: ' . $campaignLabel;
} elseif ($departmentLabel) {
    $groupHeaderLabel = 'Departamento: ' . $departmentLabel;
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

// Get payroll records (include user salary fields so we can show base hourly/fixed rates).
// Si hay $campaignFilter y/o $departmentFilter, se añaden filtros por
// employees.campaign_id / employees.department_id sin tocar nada más.
$campaignWhere = '';
if ($campaignFilter !== null) {
    $campaignWhere = $campaignFilter > 0
        ? ' AND e.campaign_id = ?'
        : ' AND e.campaign_id IS NULL';
}
$departmentWhere = '';
if ($departmentFilter !== null) {
    $departmentWhere = $departmentFilter > 0
        ? ' AND e.department_id = ?'
        : ' AND e.department_id IS NULL';
}

// Las columnas son las del reporte de nómina de Finanzas, que es el formato
// que acordó la auditoría, así que basta con el registro de nómina y los datos
// del empleado.
$recordsStmt = $pdo->prepare("
    SELECT pr.*,
           e.first_name, e.last_name, e.employee_code, e.identification_number, e.position,
           d.name as department_name
    FROM payroll_records pr
    JOIN employees e ON e.id = pr.employee_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE pr.payroll_period_id = ?
    $campaignWhere
    $departmentWhere
    ORDER BY e.first_name, e.last_name
");
$bindings = [$periodId];
if ($campaignFilter !== null && $campaignFilter > 0) {
    $bindings[] = $campaignFilter;
}
if ($departmentFilter !== null && $departmentFilter > 0) {
    $bindings[] = $departmentFilter;
}
$recordsStmt->execute($bindings);
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

// Novedades de personal del período (ingresos, salidas, vacaciones, permisos, licencias)
$noveltiesByEmployee = [];
if (!empty($records)) {
    $noveltiesByEmployee = getPayrollNoveltiesForEmployees(
        $pdo,
        array_map(static fn($r) => (int) $r['employee_id'], $records),
        $period['start_date'],
        $period['end_date']
    );
}

// Calculate totals
$emptyTotals = static fn(): array => [
    'base' => 0,
    'total_hours' => 0,
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

$totals = $emptyTotals();

// Agrupar por departamento: la auditoría revisa la nómina dividida por área,
// cada bloque con su subtotal y al final el total general. "Sin Departamento"
// va de último.
$byDepartment = [];
foreach ($records as $record) {
    $deptName = trim((string) ($record['department_name'] ?? ''));
    $byDepartment[$deptName !== '' ? $deptName : 'Sin Departamento'][] = $record;
}
uksort($byDepartment, static function (string $a, string $b): int {
    if ($a === 'Sin Departamento') return 1;
    if ($b === 'Sin Departamento') return -1;
    return strcasecmp($a, $b);
});

$departmentTotals = [];
foreach ($byDepartment as $deptName => $deptRecords) {
    $deptTotals = $emptyTotals();
    foreach ($deptRecords as $record) {
        $amounts = [
            'base' => (float)$record['base_salary'],
            'total_hours' => (float)$record['total_hours'],
            'gross' => (float)$record['gross_salary'],
            'afp_employee' => (float)$record['afp_employee'],
            'sfs_employee' => (float)$record['sfs_employee'],
            'isr' => (float)$record['isr'],
            'other_deductions' => (float)$record['other_deductions'],
            'total_deductions' => (float)$record['total_deductions'],
            'afp_employer' => (float)$record['afp_employer'],
            'sfs_employer' => (float)$record['sfs_employer'],
            'srl_employer' => (float)$record['srl_employer'],
            'infotep_employer' => (float)$record['infotep_employer'],
            'total_employer' => (float)$record['total_employer_contributions'],
            'net' => (float)$record['net_salary'],
        ];
        foreach ($amounts as $key => $value) {
            $deptTotals[$key] += $value;
            $totals[$key] += $value;
        }
    }
    $departmentTotals[$deptName] = $deptTotals;
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
        /* Bloques por departamento: encabezado y subtotal del área. El !important
           es para ganarle al zebra de nth-child de la tabla. */
        .dept-row td {
            background: #e5e7eb !important;
            color: #1e3a8a;
            font-size: 10px;
        }
        .subtotal-row td {
            background: #f1f5f9 !important;
            font-weight: bold;
        }
        .novelties {
            font-size: 7px;
            color: #374151;
            width: 140px;
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
        <h1>REPORTE DE NÓMINA<?= $groupHeaderLabel !== null ? ' — ' . ($departmentLabel !== null && $campaignLabel === null ? 'DEPARTAMENTO' : 'CAMPAÑA') : '' ?></h1>
        <p><strong><?= htmlspecialchars($period['name']) ?></strong></p>
        <?php if ($groupHeaderLabel !== null): ?>
            <p><strong><?= htmlspecialchars($groupHeaderLabel) ?></strong></p>
        <?php endif; ?>
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
                <th class="text-right">Salario Base</th>
                <th class="text-right">H. Tot.</th>
                <th class="text-right">Bruto</th>
                <th class="text-right">AFP</th>
                <th class="text-right">SFS</th>
                <th class="text-right">ISR</th>
                <th class="text-right">Otros Desc.</th>
                <th class="text-right">Total Desc.</th>
                <th class="text-right">Aporte Patr.</th>
                <th class="text-right">Neto</th>
                <th class="text-center">Pag.</th>
                <th>Novedades de personal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($byDepartment as $deptName => $deptRecords): ?>
            <tr class="dept-row">
                <td colspan="14"><strong>DEPARTAMENTO: <?= htmlspecialchars(mb_strtoupper($deptName, 'UTF-8')) ?></strong> (<?= count($deptRecords) ?> <?= count($deptRecords) === 1 ? 'empleado' : 'empleados' ?>)</td>
            </tr>
            <?php foreach ($deptRecords as $record): ?>
            <tr>
                <td><?= htmlspecialchars($record['employee_code']) ?></td>
                <td><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></td>
                <td class="text-right"><?= formatDOP($record['base_salary']) ?></td>
                <td class="text-right"><?= number_format((float)$record['total_hours'], 2) ?></td>
                <td class="text-right"><?= formatDOP($record['gross_salary']) ?></td>
                <td class="text-right"><?= formatDOP($record['afp_employee']) ?></td>
                <td class="text-right"><?= formatDOP($record['sfs_employee']) ?></td>
                <td class="text-right"><?= formatDOP($record['isr']) ?></td>
                <td class="text-right"><?= formatDOP($record['other_deductions']) ?></td>
                <td class="text-right"><?= formatDOP($record['total_deductions']) ?></td>
                <td class="text-right"><?= formatDOP($record['total_employer_contributions']) ?></td>
                <td class="text-right"><strong><?= formatDOP($record['net_salary']) ?></strong></td>
                <td class="text-center"><?= ((int)$record['is_paid']) ? 'Sí' : 'No' ?></td>
                <td class="novelties"><?= htmlspecialchars($noveltiesByEmployee[(int)$record['employee_id']] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php $dt = $departmentTotals[$deptName]; ?>
            <tr class="subtotal-row">
                <td colspan="2"><strong>SUBTOTAL <?= htmlspecialchars(mb_strtoupper($deptName, 'UTF-8')) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($dt['base']) ?></strong></td>
                <td class="text-right"><strong><?= number_format($dt['total_hours'], 2) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($dt['gross']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($dt['afp_employee']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($dt['sfs_employee']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($dt['isr']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($dt['other_deductions']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($dt['total_deductions']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($dt['total_employer']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($dt['net']) ?></strong></td>
                <td colspan="2"></td>
            </tr>
            <?php endforeach; ?>
            <tr class="totals-row">
                <td colspan="2"><strong>TOTALES GENERALES</strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['base']) ?></strong></td>
                <td class="text-right"><strong><?= number_format($totals['total_hours'], 2) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['gross']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['afp_employee']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['sfs_employee']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['isr']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['other_deductions']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['total_deductions']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['total_employer']) ?></strong></td>
                <td class="text-right"><strong><?= formatDOP($totals['net']) ?></strong></td>
                <td colspan="2"></td>
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
$slugParts = [];
if ($campaignLabel !== null)   $slugParts[] = 'C-' . $campaignLabel;
if ($departmentLabel !== null) $slugParts[] = 'D-' . $departmentLabel;
$groupSlug = !empty($slugParts)
    ? '_' . preg_replace('/[^A-Za-z0-9]+/', '-', implode('_', $slugParts))
    : '';
$filename = 'Nomina_' . str_replace(' ', '_', $period['name']) . $groupSlug . '_' . date('Ymd') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
?>
