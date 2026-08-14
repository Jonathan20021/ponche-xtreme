<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

// Check permissions
ensurePermission('hr_payroll', '../unauthorized.php');
ensurePayrollManualIncentivesTable($pdo);

$periodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;

if (!$periodId) {
    die('Período no especificado');
}

// Filtros opcionales por campaña y/o departamento. Sin parámetros, comportamiento
// idéntico al original.
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
// Filtros opcionales por campaña y/o departamento — solo añaden WHERE extra,
// no tocan cálculos.
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
// que acordó la auditoría: los incentivos ya vienen dentro del registro de
// nómina (comisiones ← incentivo de ventas, otros ingresos ← nocturno), así que
// no hace falta traer las tarifas del usuario ni los incentivos manuales.
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

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Nómina');

// Add logo
$logoPath = dirname(__DIR__) . '/assets/logo.png';
if (file_exists($logoPath)) {
    $drawing = new Drawing();
    $drawing->setName('Logo');
    $drawing->setDescription('Evallish BPO Logo');
    $drawing->setPath($logoPath);
    $drawing->setHeight(40);
    $drawing->setCoordinates('A1');
    $drawing->setOffsetX(10);
    $drawing->setOffsetY(5);
    $drawing->setWorksheet($sheet);
    $sheet->getRowDimension(1)->setRowHeight(50);
}

// Header
$headerTitle = 'REPORTE DE NÓMINA - REPÚBLICA DOMINICANA';
if ($groupHeaderLabel !== null) {
    $headerTitle .= ' — ' . $groupHeaderLabel;
}
$sheet->setCellValue('B1', $headerTitle);
$sheet->mergeCells('B1:Z1');
$sheet->getStyle('B1:Z1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('B1:Z1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A1:Z1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
$sheet->getStyle('B1:Z1')->getFont()->getColor()->setRGB('FFFFFF');

// Period info
$periodLabel = 'Período: ' . $period['name'];
if ($groupHeaderLabel !== null) {
    $periodLabel .= ' · ' . $groupHeaderLabel;
}
$sheet->setCellValue('A2', $periodLabel);
$sheet->mergeCells('A2:F2');
$sheet->setCellValue('G2', 'Fechas: ' . date('d/m/Y', strtotime($period['start_date'])) . ' - ' . date('d/m/Y', strtotime($period['end_date'])));
$sheet->mergeCells('G2:L2');
$sheet->setCellValue('M2', 'Pago: ' . date('d/m/Y', strtotime($period['payment_date'])));
$sheet->mergeCells('M2:Z2');

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

// Column headers — mismas columnas, mismo orden y mismos rótulos que el reporte
// de nómina de Finanzas (Nómina → Detalle de nómina): ese es el formato único
// que acordó la auditoría, para que el documento sea el mismo salga de donde salga.
$row = 4;
$headers = [
    'A' => 'Código',
    'B' => 'Empleado',
    'C' => 'Departamento',
    'D' => 'Posición',
    'E' => 'Salario Base',
    'F' => 'Horas Reg.',
    'G' => 'Horas Extra',
    'H' => 'Horas Tot.',
    'I' => 'Monto Extra',
    'J' => 'Bonos',
    'K' => 'Comisiones',
    'L' => 'Otros Ingresos',
    'M' => 'Bruto',
    'N' => 'AFP',
    'O' => 'SFS',
    'P' => 'ISR',
    'Q' => 'Otros Desc.',
    'R' => 'Total Desc.',
    'S' => 'AFP Patronal',
    'T' => 'SFS Patronal',
    'U' => 'SRL (Riesgo)',
    'V' => 'INFOTEP',
    'W' => 'Total Patronal',
    'X' => 'Neto',
    'Y' => 'Pagado',
    'Z' => 'Novedades de personal'
];

foreach ($headers as $col => $header) {
    $sheet->setCellValue($col . $row, $header);
}

$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle('A' . $row . ':Z' . $row)->applyFromArray($headerStyle);

// La fila de encabezados queda fija al hacer scroll: con la nómina dividida en
// bloques por departamento el listado es largo y se perdía la referencia.
$sheet->freezePane('A' . ($row + 1));

// Data rows
$row++;

$emptyTotals = static fn(): array => [
    'base' => 0, 'reg_hours' => 0, 'ot_hours' => 0, 'total_hours' => 0, 'ot_pay' => 0,
    'bonuses' => 0, 'commissions' => 0, 'other_income' => 0, 'gross' => 0,
    'afp' => 0, 'sfs' => 0, 'isr' => 0, 'other' => 0, 'deductions' => 0,
    'afp_pat' => 0, 'sfs_pat' => 0, 'srl_pat' => 0, 'infotep_pat' => 0, 'employer' => 0,
    'net' => 0,
];
$totals = $emptyTotals();

// Agrupar por departamento. El departamento es la división que pidió la
// auditoría: cada bloque lleva su encabezado y su subtotal, y al final va el
// total general. "Sin Departamento" siempre de último.
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

$currencyCols = ['E', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X'];
$hourCols = ['F', 'G', 'H'];

// Escribe una fila de totales (subtotal de departamento o total general).
$writeTotalsRow = function (int $row, string $label, array $t, string $fillRgb) use ($sheet, $currencyCols, $hourCols): void {
    $sheet->setCellValue('A' . $row, $label);
    $sheet->mergeCells('A' . $row . ':D' . $row);
    $sheet->setCellValue('E' . $row, $t['base']);
    $sheet->setCellValue('F' . $row, round($t['reg_hours'], 4));
    $sheet->setCellValue('G' . $row, round($t['ot_hours'], 4));
    $sheet->setCellValue('H' . $row, round($t['total_hours'], 4));
    $sheet->setCellValue('I' . $row, $t['ot_pay']);
    $sheet->setCellValue('J' . $row, $t['bonuses']);
    $sheet->setCellValue('K' . $row, $t['commissions']);
    $sheet->setCellValue('L' . $row, $t['other_income']);
    $sheet->setCellValue('M' . $row, $t['gross']);
    $sheet->setCellValue('N' . $row, $t['afp']);
    $sheet->setCellValue('O' . $row, $t['sfs']);
    $sheet->setCellValue('P' . $row, $t['isr']);
    $sheet->setCellValue('Q' . $row, $t['other']);
    $sheet->setCellValue('R' . $row, $t['deductions']);
    $sheet->setCellValue('S' . $row, $t['afp_pat']);
    $sheet->setCellValue('T' . $row, $t['sfs_pat']);
    $sheet->setCellValue('U' . $row, $t['srl_pat']);
    $sheet->setCellValue('V' . $row, $t['infotep_pat']);
    $sheet->setCellValue('W' . $row, $t['employer']);
    $sheet->setCellValue('X' . $row, $t['net']);

    foreach ($currencyCols as $col) {
        $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
    }
    foreach ($hourCols as $col) {
        $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.0000');
    }

    $sheet->getStyle('A' . $row . ':Z' . $row)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillRgb]],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
};

$isFirstDepartment = true;
foreach ($byDepartment as $deptName => $deptRecords) {
    if (!$isFirstDepartment) {
        $row++; // fila en blanco entre bloques
    }
    $isFirstDepartment = false;

    // Encabezado del bloque
    $sheet->setCellValue('A' . $row, 'DEPARTAMENTO: ' . mb_strtoupper($deptName, 'UTF-8') . ' (' . count($deptRecords) . ' ' . (count($deptRecords) === 1 ? 'empleado' : 'empleados') . ')');
    $sheet->mergeCells('A' . $row . ':Z' . $row);
    $sheet->getStyle('A' . $row . ':Z' . $row)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '1E3A8A']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
    $row++;

    $deptTotals = $emptyTotals();

    foreach ($deptRecords as $record) {
        $sheet->setCellValue('A' . $row, $record['employee_code']);
        $sheet->setCellValue('B' . $row, $record['first_name'] . ' ' . $record['last_name']);
        $sheet->setCellValue('C' . $row, $record['department_name'] ?: 'Sin departamento');
        $sheet->setCellValue('D' . $row, $record['position'] ?: '');
        $sheet->setCellValue('E' . $row, $record['base_salary']);
        // Horas con 4 decimales: el bruto se calcula con las horas exactas, así que
        // mostrarlas redondeadas a 2 hacía que "tarifa × horas" no cuadrara por centavos.
        $sheet->setCellValue('F' . $row, round((float)$record['regular_hours'], 4));
        $sheet->setCellValue('G' . $row, round((float)$record['overtime_hours'], 4));
        $sheet->setCellValue('H' . $row, round((float)$record['total_hours'], 4));
        $sheet->setCellValue('I' . $row, $record['overtime_amount']);
        $sheet->setCellValue('J' . $row, $record['bonuses']);
        $sheet->setCellValue('K' . $row, $record['commissions']);
        $sheet->setCellValue('L' . $row, $record['other_income']);
        $sheet->setCellValue('M' . $row, $record['gross_salary']);
        $sheet->setCellValue('N' . $row, $record['afp_employee']);
        $sheet->setCellValue('O' . $row, $record['sfs_employee']);
        $sheet->setCellValue('P' . $row, $record['isr']);
        $sheet->setCellValue('Q' . $row, $record['other_deductions']);
        $sheet->setCellValue('R' . $row, $record['total_deductions']);
        $sheet->setCellValue('S' . $row, $record['afp_employer']);
        $sheet->setCellValue('T' . $row, $record['sfs_employer']);
        $sheet->setCellValue('U' . $row, $record['srl_employer']);
        $sheet->setCellValue('V' . $row, $record['infotep_employer']);
        $sheet->setCellValue('W' . $row, $record['total_employer_contributions']);
        $sheet->setCellValue('X' . $row, $record['net_salary']);
        $sheet->setCellValue('Y' . $row, ((int) $record['is_paid']) ? 'Sí' : 'No');
        // Novedades del período; vacía si el empleado no tuvo ninguna, para que
        // RRHH pueda anotar a mano lo que no está en el sistema.
        $sheet->setCellValueExplicit(
            'Z' . $row,
            (string) ($noveltiesByEmployee[(int)$record['employee_id']] ?? ''),
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );

        // Format currency (montos; las horas F/G/H quedan como número simple)
        foreach ($currencyCols as $col) {
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
        }
        foreach ($hourCols as $col) {
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.0000');
        }

        foreach ([
            'base' => (float)$record['base_salary'],
            'reg_hours' => (float)$record['regular_hours'],
            'ot_hours' => (float)$record['overtime_hours'],
            'total_hours' => (float)$record['total_hours'],
            'ot_pay' => (float)$record['overtime_amount'],
            'bonuses' => (float)$record['bonuses'],
            'commissions' => (float)$record['commissions'],
            'other_income' => (float)$record['other_income'],
            'gross' => (float)$record['gross_salary'],
            'afp' => (float)$record['afp_employee'],
            'sfs' => (float)$record['sfs_employee'],
            'isr' => (float)$record['isr'],
            'other' => (float)$record['other_deductions'],
            'deductions' => (float)$record['total_deductions'],
            'afp_pat' => (float)$record['afp_employer'],
            'sfs_pat' => (float)$record['sfs_employer'],
            'srl_pat' => (float)$record['srl_employer'],
            'infotep_pat' => (float)$record['infotep_employer'],
            'employer' => (float)$record['total_employer_contributions'],
            'net' => (float)$record['net_salary'],
        ] as $key => $value) {
            $deptTotals[$key] += $value;
            $totals[$key] += $value;
        }

        $row++;
    }

    $writeTotalsRow($row, 'SUBTOTAL ' . mb_strtoupper($deptName, 'UTF-8'), $deptTotals, 'F1F5F9');
    $row++;
}

// Total general
$row++;
$writeTotalsRow($row, 'TOTALES GENERALES', $totals, 'DBEAFE');

// Auto-size columns
foreach (range('A', 'Z') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
// La columna de novedades es texto libre: ancho fijo y ajuste de línea, porque
// el auto-size la estiraría hasta romper la impresión.
$sheet->getColumnDimension('Z')->setAutoSize(false);
$sheet->getColumnDimension('Z')->setWidth(45);
$sheet->getStyle('Z5:Z' . $row)->getAlignment()->setWrapText(true);

// Output
$slugParts = [];
if ($campaignLabel !== null)   $slugParts[] = 'C-' . $campaignLabel;
if ($departmentLabel !== null) $slugParts[] = 'D-' . $departmentLabel;
$groupSlug = !empty($slugParts)
    ? '_' . preg_replace('/[^A-Za-z0-9]+/', '-', implode('_', $slugParts))
    : '';
$filename = 'Nomina_' . str_replace(' ', '_', $period['name']) . $groupSlug . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
