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

// Get payroll records (include user salary fields so we can show base hourly/fixed rates)
$recordsStmt = $pdo->prepare("
    SELECT pr.*,
           e.first_name, e.last_name, e.employee_code, e.identification_number, e.position,
           d.name as department_name,
           u.hourly_rate, u.monthly_salary, u.hourly_rate_dop, u.monthly_salary_dop,
           u.daily_salary_usd, u.daily_salary_dop, u.preferred_currency, u.compensation_type, u.role,
           COALESCE(pmi.sales_incentive, 0) as sales_incentive,
           COALESCE(pmi.night_incentive, 0) as night_incentive,
           COALESCE(pmi.cooperative_deduction, 0) as cooperative_deduction,
           COALESCE(pmi.additional_deduction, 0) as additional_deduction
    FROM payroll_records pr
    JOIN employees e ON e.id = pr.employee_id
    LEFT JOIN users u ON u.id = e.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN payroll_manual_incentives pmi
        ON pmi.payroll_period_id = pr.payroll_period_id
       AND pmi.employee_id = pr.employee_id
    WHERE pr.payroll_period_id = ?
    ORDER BY e.last_name, e.first_name
");
$recordsStmt->execute([$periodId]);
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

// Resolve effective compensation type and base rate (in DOP) for an employee row.
// Mirrors the rate-selection logic from calculateEmployeePayroll() so the report
// shows what the employee is actually paid by.
$resolveBaseRate = function (array $r) use ($pdo) {
    $hourlyRateUsd = (float)($r['hourly_rate'] ?? 0);
    $hourlyRateDop = (float)($r['hourly_rate_dop'] ?? 0);
    $monthlySalaryUsd = (float)($r['monthly_salary'] ?? 0);
    $monthlySalaryDop = (float)($r['monthly_salary_dop'] ?? 0);
    $dailySalaryUsd = (float)($r['daily_salary_usd'] ?? 0);
    $dailySalaryDop = (float)($r['daily_salary_dop'] ?? 0);

    $hourlyDop = $hourlyRateDop > 0
        ? $hourlyRateDop
        : ($hourlyRateUsd > 0 ? convertCurrency($pdo, $hourlyRateUsd, 'USD', 'DOP') : 0.0);
    $monthlyDop = $monthlySalaryDop > 0
        ? $monthlySalaryDop
        : ($monthlySalaryUsd > 0 ? convertCurrency($pdo, $monthlySalaryUsd, 'USD', 'DOP') : 0.0);
    $dailyDop = $dailySalaryDop > 0
        ? $dailySalaryDop
        : ($dailySalaryUsd > 0 ? convertCurrency($pdo, $dailySalaryUsd, 'USD', 'DOP') : 0.0);

    $compType = strtolower(trim($r['compensation_type'] ?? 'hourly'));
    $role = strtoupper(trim($r['role'] ?? ''));
    if ($compType === '' || $compType === 'hourly') {
        if ($role !== 'AGENT' && $monthlyDop > 0) {
            $compType = 'fixed';
        }
    }

    if ($compType === 'fixed') {
        return ['label' => 'Fijo (mensual)', 'rate' => $monthlyDop];
    }
    if ($compType === 'daily') {
        return ['label' => 'Diario', 'rate' => $dailyDop];
    }
    return ['label' => 'Por Hora', 'rate' => $hourlyDop];
};

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
$sheet->setCellValue('B1', 'REPORTE DE NÓMINA - REPÚBLICA DOMINICANA');
$sheet->mergeCells('B1:T1');
$sheet->getStyle('B1:T1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('B1:T1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A1:T1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
$sheet->getStyle('B1:T1')->getFont()->getColor()->setRGB('FFFFFF');

// Period info
$sheet->setCellValue('A2', 'Período: ' . $period['name']);
$sheet->mergeCells('A2:F2');
$sheet->setCellValue('G2', 'Fechas: ' . date('d/m/Y', strtotime($period['start_date'])) . ' - ' . date('d/m/Y', strtotime($period['end_date'])));
$sheet->mergeCells('G2:L2');
$sheet->setCellValue('M2', 'Pago: ' . date('d/m/Y', strtotime($period['payment_date'])));
$sheet->mergeCells('M2:T2');

// Column headers
$row = 4;
$headers = [
    'A' => 'Código',
    'B' => 'Empleado',
    'C' => 'Cédula',
    'D' => 'Departamento',
    'E' => 'Tipo Comp.',
    'F' => 'Salario Base',
    'G' => 'Horas',
    'H' => 'Inc. Ventas',
    'I' => 'Inc. Nocturno',
    'J' => 'Salario Bruto',
    'K' => 'AFP (' . number_format($deductionRates['AFP']['employee_percentage'], 2) . '%)',
    'L' => 'SFS (' . number_format($deductionRates['SFS']['employee_percentage'], 2) . '%)',
    'M' => 'ISR',
    'N' => 'Cooperativa',
    'O' => 'Descuento',
    'P' => 'Otros Desc.',
    'Q' => 'Total Desc.',
    'R' => 'Salario Neto',
    'S' => 'AFP Patronal (' . number_format($deductionRates['AFP']['employer_percentage'], 2) . '%)',
    'T' => 'SFS Patronal (' . number_format($deductionRates['SFS']['employer_percentage'], 2) . '%)'
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
$sheet->getStyle('A' . $row . ':T' . $row)->applyFromArray($headerStyle);

// Data rows
$row++;
$totals = ['sales' => 0, 'night' => 0, 'gross' => 0, 'afp_emp' => 0, 'sfs_emp' => 0, 'isr' => 0, 'coop' => 0, 'add' => 0, 'other' => 0, 'deductions' => 0, 'net' => 0, 'afp_pat' => 0, 'sfs_pat' => 0];

foreach ($records as $record) {
    $base = $resolveBaseRate($record);

    // other_deductions includes cooperativa + descuento + custom; split for the report.
    $coopAmt = (float)$record['cooperative_deduction'];
    $addAmt = (float)$record['additional_deduction'];
    $othersOnly = max(0, (float)$record['other_deductions'] - $coopAmt - $addAmt);

    $sheet->setCellValue('A' . $row, $record['employee_code']);
    $sheet->setCellValue('B' . $row, $record['first_name'] . ' ' . $record['last_name']);
    $sheet->setCellValue('C' . $row, $record['identification_number'] ?: 'N/A');
    $sheet->setCellValue('D' . $row, $record['department_name'] ?: 'N/A');
    $sheet->setCellValue('E' . $row, $base['label']);
    $sheet->setCellValue('F' . $row, $base['rate']);
    $sheet->setCellValue('G' . $row, number_format($record['total_hours'], 2));
    $sheet->setCellValue('H' . $row, $record['sales_incentive']);
    $sheet->setCellValue('I' . $row, $record['night_incentive']);
    $sheet->setCellValue('J' . $row, $record['gross_salary']);
    $sheet->setCellValue('K' . $row, $record['afp_employee']);
    $sheet->setCellValue('L' . $row, $record['sfs_employee']);
    $sheet->setCellValue('M' . $row, $record['isr']);
    $sheet->setCellValue('N' . $row, $coopAmt);
    $sheet->setCellValue('O' . $row, $addAmt);
    $sheet->setCellValue('P' . $row, $othersOnly);
    $sheet->setCellValue('Q' . $row, $record['total_deductions']);
    $sheet->setCellValue('R' . $row, $record['net_salary']);
    $sheet->setCellValue('S' . $row, $record['afp_employer']);
    $sheet->setCellValue('T' . $row, $record['sfs_employer']);

    // Format currency
    foreach (['F', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T'] as $col) {
        $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
    }

    $totals['sales'] += $record['sales_incentive'];
    $totals['night'] += $record['night_incentive'];
    $totals['gross'] += $record['gross_salary'];
    $totals['afp_emp'] += $record['afp_employee'];
    $totals['sfs_emp'] += $record['sfs_employee'];
    $totals['isr'] += $record['isr'];
    $totals['coop'] += $coopAmt;
    $totals['add'] += $addAmt;
    $totals['other'] += $othersOnly;
    $totals['deductions'] += $record['total_deductions'];
    $totals['net'] += $record['net_salary'];
    $totals['afp_pat'] += $record['afp_employer'];
    $totals['sfs_pat'] += $record['sfs_employer'];

    $row++;
}

// Totals row (Salario Base column F left blank — base rates are per-employee and not summable across hourly/fixed/daily)
$sheet->setCellValue('A' . $row, 'TOTALES');
$sheet->mergeCells('A' . $row . ':G' . $row);
$sheet->setCellValue('H' . $row, $totals['sales']);
$sheet->setCellValue('I' . $row, $totals['night']);
$sheet->setCellValue('J' . $row, $totals['gross']);
$sheet->setCellValue('K' . $row, $totals['afp_emp']);
$sheet->setCellValue('L' . $row, $totals['sfs_emp']);
$sheet->setCellValue('M' . $row, $totals['isr']);
$sheet->setCellValue('N' . $row, $totals['coop']);
$sheet->setCellValue('O' . $row, $totals['add']);
$sheet->setCellValue('P' . $row, $totals['other']);
$sheet->setCellValue('Q' . $row, $totals['deductions']);
$sheet->setCellValue('R' . $row, $totals['net']);
$sheet->setCellValue('S' . $row, $totals['afp_pat']);
$sheet->setCellValue('T' . $row, $totals['sfs_pat']);

foreach (['H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T'] as $col) {
    $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
}

$totalsStyle = [
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle('A' . $row . ':T' . $row)->applyFromArray($totalsStyle);

// Auto-size columns
foreach (range('A', 'T') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Output
$filename = 'Nomina_' . str_replace(' ', '_', $period['name']) . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
