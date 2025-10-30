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

// Check permissions
ensurePermission('hr_payroll');

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

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Nómina');

// Header
$sheet->setCellValue('A1', 'REPORTE DE NÓMINA - REPÚBLICA DOMINICANA');
$sheet->mergeCells('A1:N1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
$sheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');

// Period info
$sheet->setCellValue('A2', 'Período: ' . $period['name']);
$sheet->mergeCells('A2:D2');
$sheet->setCellValue('E2', 'Fechas: ' . date('d/m/Y', strtotime($period['start_date'])) . ' - ' . date('d/m/Y', strtotime($period['end_date'])));
$sheet->mergeCells('E2:H2');
$sheet->setCellValue('I2', 'Pago: ' . date('d/m/Y', strtotime($period['payment_date'])));
$sheet->mergeCells('I2:N2');

// Column headers
$row = 4;
$headers = [
    'A' => 'Código',
    'B' => 'Empleado',
    'C' => 'Cédula',
    'D' => 'Departamento',
    'E' => 'Horas',
    'F' => 'Salario Bruto',
    'G' => 'AFP (' . number_format($deductionRates['AFP']['employee_percentage'], 2) . '%)',
    'H' => 'SFS (' . number_format($deductionRates['SFS']['employee_percentage'], 2) . '%)',
    'I' => 'ISR',
    'J' => 'Otros Desc.',
    'K' => 'Total Desc.',
    'L' => 'Salario Neto',
    'M' => 'AFP Patronal (' . number_format($deductionRates['AFP']['employer_percentage'], 2) . '%)',
    'N' => 'SFS Patronal (' . number_format($deductionRates['SFS']['employer_percentage'], 2) . '%)'
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
$sheet->getStyle('A' . $row . ':N' . $row)->applyFromArray($headerStyle);

// Data rows
$row++;
$totals = ['gross' => 0, 'afp_emp' => 0, 'sfs_emp' => 0, 'isr' => 0, 'other' => 0, 'deductions' => 0, 'net' => 0, 'afp_pat' => 0, 'sfs_pat' => 0];

foreach ($records as $record) {
    $sheet->setCellValue('A' . $row, $record['employee_code']);
    $sheet->setCellValue('B' . $row, $record['first_name'] . ' ' . $record['last_name']);
    $sheet->setCellValue('C' . $row, $record['identification_number'] ?: 'N/A');
    $sheet->setCellValue('D' . $row, $record['department_name'] ?: 'N/A');
    $sheet->setCellValue('E' . $row, number_format($record['total_hours'], 2));
    $sheet->setCellValue('F' . $row, $record['gross_salary']);
    $sheet->setCellValue('G' . $row, $record['afp_employee']);
    $sheet->setCellValue('H' . $row, $record['sfs_employee']);
    $sheet->setCellValue('I' . $row, $record['isr']);
    $sheet->setCellValue('J' . $row, $record['other_deductions']);
    $sheet->setCellValue('K' . $row, $record['total_deductions']);
    $sheet->setCellValue('L' . $row, $record['net_salary']);
    $sheet->setCellValue('M' . $row, $record['afp_employer']);
    $sheet->setCellValue('N' . $row, $record['sfs_employer']);
    
    // Format currency
    foreach (['F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'] as $col) {
        $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
    }
    
    $totals['gross'] += $record['gross_salary'];
    $totals['afp_emp'] += $record['afp_employee'];
    $totals['sfs_emp'] += $record['sfs_employee'];
    $totals['isr'] += $record['isr'];
    $totals['other'] += $record['other_deductions'];
    $totals['deductions'] += $record['total_deductions'];
    $totals['net'] += $record['net_salary'];
    $totals['afp_pat'] += $record['afp_employer'];
    $totals['sfs_pat'] += $record['sfs_employer'];
    
    $row++;
}

// Totals row
$sheet->setCellValue('A' . $row, 'TOTALES');
$sheet->mergeCells('A' . $row . ':E' . $row);
$sheet->setCellValue('F' . $row, $totals['gross']);
$sheet->setCellValue('G' . $row, $totals['afp_emp']);
$sheet->setCellValue('H' . $row, $totals['sfs_emp']);
$sheet->setCellValue('I' . $row, $totals['isr']);
$sheet->setCellValue('J' . $row, $totals['other']);
$sheet->setCellValue('K' . $row, $totals['deductions']);
$sheet->setCellValue('L' . $row, $totals['net']);
$sheet->setCellValue('M' . $row, $totals['afp_pat']);
$sheet->setCellValue('N' . $row, $totals['sfs_pat']);

foreach (['F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'] as $col) {
    $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
}

$totalsStyle = [
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle('A' . $row . ':N' . $row)->applyFromArray($totalsStyle);

// Auto-size columns
foreach (range('A', 'N') as $col) {
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
