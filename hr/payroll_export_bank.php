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
ensurePermission('hr_payroll', '../unauthorized.php');

$periodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;

if (!$periodId) {
    die('Período no especificado');
}

// Optional filters
$campaignFilter = null;
if (isset($_GET['campaign_id']) && $_GET['campaign_id'] !== '') {
    $campaignFilter = (int)$_GET['campaign_id'];
}
$departmentFilter = null;
if (isset($_GET['department_id']) && $_GET['department_id'] !== '') {
    $departmentFilter = (int)$_GET['department_id'];
}

// Get period data
$periodStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
$periodStmt->execute([$periodId]);
$period = $periodStmt->fetch(PDO::FETCH_ASSOC);

if (!$period) {
    die('Período no encontrado');
}

// Bank comment (configurable from system_settings)
$commentStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
$commentStmt->execute(['payroll_bank_export_comment']);
$bankComment = $commentStmt->fetchColumn();
if ($bankComment === false || trim((string)$bankComment) === '') {
    $bankComment = 'Pago nómina BHD';
}

// Get payroll records joined with bank account info
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

$recordsStmt = $pdo->prepare("
    SELECT pr.net_salary,
           e.first_name, e.last_name,
           e.identification_number,
           e.bank_account_number,
           e.email
    FROM payroll_records pr
    JOIN employees e ON e.id = pr.employee_id
    WHERE pr.payroll_period_id = ?
    $campaignWhere
    $departmentWhere
    ORDER BY e.last_name, e.first_name
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

// Create spreadsheet matching the bank template exactly
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Archivos de pago');

// Headers (row 1) — exact column names required by the bank template
$headers = [
    'A' => 'NÚMERO DE CUENTA',
    'B' => 'NOMBRE COMPLETO',
    'C' => 'DOCUMENTO IDENTIDAD',
    'D' => 'MONTO A PAGAR',
    'E' => 'COMENTARIO',
    'F' => 'CORREO ELECTRÓNICO',
];
foreach ($headers as $col => $label) {
    $sheet->setCellValue($col . '1', $label);
}

$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
];
$sheet->getStyle('A1:F1')->applyFromArray($headerStyle);

// Force account number and ID to be treated as text so leading zeros are preserved
$sheet->getStyle('A:A')->getNumberFormat()->setFormatCode('@');
$sheet->getStyle('C:C')->getNumberFormat()->setFormatCode('@');

$row = 2;
foreach ($records as $r) {
    $netSalary = (float)$r['net_salary'];
    $accountRaw = trim((string)($r['bank_account_number'] ?? ''));
    $idRaw = trim((string)($r['identification_number'] ?? ''));
    $fullName = trim($r['first_name'] . ' ' . $r['last_name']);
    $email = trim((string)($r['email'] ?? ''));

    // Strip any non-digit characters from account and ID to match the bank file
    $account = preg_replace('/\D+/', '', $accountRaw);
    $idNumber = preg_replace('/\D+/', '', $idRaw);

    $sheet->setCellValueExplicit('A' . $row, $account, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('B' . $row, $fullName);
    $sheet->setCellValueExplicit('C' . $row, $idNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('D' . $row, $netSalary);
    $sheet->setCellValue('E' . $row, $bankComment);
    $sheet->setCellValue('F' . $row, $email);

    // Match the template's currency-style display (no symbol, 2 decimals, thousands separator)
    $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

    $row++;
}

// Auto-size columns
foreach (range('A', 'F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Build filename
$filename = 'Pago_Banco_' . str_replace(' ', '_', $period['name']) . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
