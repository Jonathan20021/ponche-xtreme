<?php
session_start();
require_once '../../db.php';
require_once '../../quality_db.php';
require_once '../payroll_functions.php';
require_once __DIR__ . '/campaign_profitability_data.php';
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

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

$filteredCampaignName = null;
if ($campaignFilter !== null) {
    $filteredRows = array_values(array_filter($rows, fn($r) => (int)$r['campaign_id'] === $campaignFilter));
    if (!empty($filteredRows)) {
        $rows = $filteredRows;
        $filteredCampaignName = $filteredRows[0]['campaign_name'];
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

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Rentabilidad');

// Estilos comunes
$headerFill = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '047857']];
$headerFont = ['bold' => true, 'color' => ['rgb' => 'FFFFFF']];
$borderAll = ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]];

$lastCol = $qaAvailable ? 'J' : 'I';

// --- Título ---
$title = 'REPORTE DE RENTABILIDAD POR CAMPAÑA';
if ($filteredCampaignName !== null) {
    $title .= ' — ' . $filteredCampaignName;
}
$sheet->setCellValue("A1", $title);
$sheet->mergeCells("A1:{$lastCol}1");
$sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => $headerFill,
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(28);

// --- Subtítulo período ---
$sheet->setCellValue("A2", "Período: " . date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate)));
$sheet->mergeCells("A2:{$lastCol}2");
$sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
    'font' => ['italic' => true, 'color' => ['rgb' => '4B5563']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// --- KPIs en fila 3 ---
$sheet->setCellValue('A3', 'Ingresos:');
$sheet->setCellValue('B3', $totals['revenue']);
$sheet->setCellValue('C3', 'Costo:');
$sheet->setCellValue('D3', $totals['cost']);
$sheet->setCellValue('E3', 'Ganancia:');
$sheet->setCellValue('F3', $totals['profit']);
$sheet->setCellValue('G3', 'Margen:');
$sheet->setCellValue('H3', $totals['margin'] !== null ? ($totals['margin'] / 100) : null);

$sheet->getStyle('A3:H3')->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0FDF4']],
]);
$sheet->getStyle('B3')->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
$sheet->getStyle('D3')->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
$sheet->getStyle('F3')->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
$sheet->getStyle('H3')->getNumberFormat()->setFormatCode('0.0%');

// --- Cabecera de tabla en fila 5 ---
$row = 5;
$headers = [
    'A' => '#',
    'B' => 'Campaña',
    'C' => 'Supervisor(es)',
    'D' => 'Agentes',
    'E' => 'Sup.',
    'F' => 'Otros',
    'G' => 'Horas',
    'H' => 'Ingreso (RD$)',
    'I' => 'Costo (RD$)',
];
if ($qaAvailable) {
    $headers['J'] = 'QA Avg %';
}

foreach ($headers as $col => $label) {
    $sheet->setCellValue($col . $row, $label);
}
$sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
    'font' => $headerFont,
    'fill' => $headerFill,
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => $borderAll,
]);

// --- Filas de datos ---
$row++;
$rank = 1;
foreach ($rows as $r) {
    $sheet->setCellValue("A{$row}", $rank++);
    $sheet->setCellValue("B{$row}", $r['campaign_name']);
    $sheet->setCellValue("C{$row}", !empty($r['supervisors']) ? implode(', ', $r['supervisors']) : '—');
    $sheet->setCellValue("D{$row}", (int)$r['roles']['AGENT']);
    $sheet->setCellValue("E{$row}", (int)$r['roles']['SUPERVISOR']);
    $sheet->setCellValue("F{$row}", (int)$r['roles']['OTROS']);
    $sheet->setCellValue("G{$row}", round($r['total_hours'], 2));
    if ($r['revenue'] !== null) {
        $sheet->setCellValue("H{$row}", round($r['revenue'], 2));
    } else {
        $sheet->setCellValue("H{$row}", 'Sin datos');
        $sheet->getStyle("H{$row}")->getFont()->setItalic(true)->getColor()->setRGB('9CA3AF');
    }
    $sheet->setCellValue("I{$row}", round($r['total_cost'], 2));

    if ($qaAvailable) {
        if ($r['qa'] !== null) {
            $sheet->setCellValue("J{$row}", round($r['qa']['avg_score'], 2));
        } else {
            $sheet->setCellValue("J{$row}", '—');
        }
    }

    // Color de fila según rentabilidad
    if ($r['profit'] !== null) {
        $bgColor = $r['profit'] >= 0 ? 'ECFDF5' : 'FEF2F2';
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
              ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bgColor);
    }

    $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray(['borders' => $borderAll]);
    $row++;
}

// --- Fila adicional: Ganancia y Margen calculados por fórmula ---
// Para mantener Excel "vivo" agrego columnas K y L con ganancia y margen (fórmula)
$gPLcolStart = $qaAvailable ? 'K' : 'J';
$gPLcolEnd   = $qaAvailable ? 'L' : 'K';

$sheet->setCellValue("{$gPLcolStart}5", 'Ganancia');
$sheet->setCellValue("{$gPLcolEnd}5", 'Margen %');
$sheet->getStyle("{$gPLcolStart}5:{$gPLcolEnd}5")->applyFromArray([
    'font' => $headerFont, 'fill' => $headerFill,
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => $borderAll,
]);

$startDataRow = 6;
$lastDataRow = $row - 1;
for ($r = $startDataRow; $r <= $lastDataRow; $r++) {
    // Ganancia: H - I (si H es numérico)
    $sheet->setCellValue("{$gPLcolStart}{$r}", "=IFERROR(H{$r}-I{$r},\"—\")");
    $sheet->setCellValue("{$gPLcolEnd}{$r}",   "=IFERROR((H{$r}-I{$r})/H{$r},\"—\")");
    $sheet->getStyle("{$gPLcolStart}{$r}")->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
    $sheet->getStyle("{$gPLcolEnd}{$r}")->getNumberFormat()->setFormatCode('0.0%');
    $sheet->getStyle("{$gPLcolStart}{$r}:{$gPLcolEnd}{$r}")->applyFromArray(['borders' => $borderAll]);
}

// --- Totales ---
$sheet->setCellValue("A{$row}", 'TOTAL');
$sheet->mergeCells("A{$row}:C{$row}");
$sheet->setCellValue("G{$row}", "=SUM(G{$startDataRow}:G{$lastDataRow})");
$sheet->setCellValue("H{$row}", $totals['revenue']);
$sheet->setCellValue("I{$row}", $totals['cost']);
$sheet->setCellValue("{$gPLcolStart}{$row}", $totals['profit']);
$sheet->setCellValue("{$gPLcolEnd}{$row}", $totals['margin'] !== null ? ($totals['margin']/100) : null);

$sheet->getStyle("A{$row}:{$gPLcolEnd}{$row}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1FAE5']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
]);
$sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
$sheet->getStyle("I{$row}")->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
$sheet->getStyle("{$gPLcolStart}{$row}")->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
$sheet->getStyle("{$gPLcolEnd}{$row}")->getNumberFormat()->setFormatCode('0.0%');

// Aplicar formato moneda a H/I de datos
$sheet->getStyle("H{$startDataRow}:I{$lastDataRow}")->getNumberFormat()->setFormatCode('"RD$"#,##0.00');

// Anchos de columna
$widths = [
    'A' => 5, 'B' => 28, 'C' => 30, 'D' => 8, 'E' => 7, 'F' => 7,
    'G' => 9, 'H' => 14, 'I' => 14, 'J' => 12, 'K' => 14, 'L' => 12,
];
foreach ($widths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// --- Hoja 2: Desglose por Departamento (si hay drill-down) ---
if ($campaignFilter !== null && !empty($data['drilldownByDept'])) {
    $deptSheet = $spreadsheet->createSheet();
    $deptSheet->setTitle('Por Departamento');

    $deptSheet->setCellValue('A1', 'DESGLOSE POR DEPARTAMENTO — ' . ($filteredCampaignName ?? 'Campaña'));
    $deptSheet->mergeCells('A1:F1');
    $deptSheet->getStyle('A1:F1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => $headerFill,
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $deptSheet->getRowDimension(1)->setRowHeight(24);

    $deptHeaders = ['A' => 'Departamento', 'B' => 'Empleados', 'C' => 'Horas', 'D' => 'Bruto', 'E' => 'Aportes', 'F' => 'Costo total'];
    foreach ($deptHeaders as $col => $h) $deptSheet->setCellValue($col . '3', $h);
    $deptSheet->getStyle('A3:F3')->applyFromArray([
        'font' => $headerFont, 'fill' => $headerFill,
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER], 'borders' => $borderAll,
    ]);

    $dr = 4;
    foreach ($data['drilldownByDept'] as $d) {
        $deptSheet->setCellValue("A{$dr}", $d['dept_name']);
        $deptSheet->setCellValue("B{$dr}", (int)$d['emp_count']);
        $deptSheet->setCellValue("C{$dr}", round((float)$d['hours'], 2));
        $deptSheet->setCellValue("D{$dr}", round((float)$d['gross'], 2));
        $deptSheet->setCellValue("E{$dr}", round((float)$d['employer'], 2));
        $deptSheet->setCellValue("F{$dr}", round((float)$d['total_cost'], 2));
        $deptSheet->getStyle("D{$dr}:F{$dr}")->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
        $deptSheet->getStyle("A{$dr}:F{$dr}")->applyFromArray(['borders' => $borderAll]);
        $dr++;
    }
    foreach (['A' => 30, 'B' => 12, 'C' => 10, 'D' => 14, 'E' => 14, 'F' => 14] as $col => $w) {
        $deptSheet->getColumnDimension($col)->setWidth($w);
    }
}

// --- Hoja 3: Empleados (si hay drill-down) ---
if ($campaignFilter !== null && !empty($data['drilldownEmployees'])) {
    $empSheet = $spreadsheet->createSheet();
    $empSheet->setTitle('Empleados');

    $empSheet->setCellValue('A1', 'EMPLEADOS — ' . ($filteredCampaignName ?? 'Campaña'));
    $empSheet->mergeCells('A1:I1');
    $empSheet->getStyle('A1:I1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => $headerFill,
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $empSheet->getRowDimension(1)->setRowHeight(24);

    $empHeaders = ['A' => 'Código', 'B' => 'Nombre', 'C' => 'Cargo', 'D' => 'Rol', 'E' => 'Depto',
                   'F' => 'Horas', 'G' => 'Bruto', 'H' => 'Aportes', 'I' => 'Costo total'];
    foreach ($empHeaders as $col => $h) $empSheet->setCellValue($col . '3', $h);
    $empSheet->getStyle('A3:I3')->applyFromArray([
        'font' => $headerFont, 'fill' => $headerFill,
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER], 'borders' => $borderAll,
    ]);

    $er = 4;
    foreach ($data['drilldownEmployees'] as $emp) {
        $empSheet->setCellValue("A{$er}", $emp['employee_code']);
        $empSheet->setCellValue("B{$er}", $emp['first_name'] . ' ' . $emp['last_name']);
        $empSheet->setCellValue("C{$er}", $emp['position'] ?: '—');
        $empSheet->setCellValue("D{$er}", $emp['role'] ?: '—');
        $empSheet->setCellValue("E{$er}", $emp['dept_name']);
        $empSheet->setCellValue("F{$er}", round((float)$emp['hours'], 2));
        $empSheet->setCellValue("G{$er}", round((float)$emp['gross'], 2));
        $empSheet->setCellValue("H{$er}", round((float)$emp['employer'], 2));
        $empSheet->setCellValue("I{$er}", round((float)$emp['total_cost'], 2));
        $empSheet->getStyle("G{$er}:I{$er}")->getNumberFormat()->setFormatCode('"RD$"#,##0.00');
        $empSheet->getStyle("A{$er}:I{$er}")->applyFromArray(['borders' => $borderAll]);
        $er++;
    }
    foreach (['A' => 12, 'B' => 25, 'C' => 18, 'D' => 14, 'E' => 18, 'F' => 9, 'G' => 13, 'H' => 13, 'I' => 14] as $col => $w) {
        $empSheet->getColumnDimension($col)->setWidth($w);
    }
}

$spreadsheet->setActiveSheetIndex(0);

$slug = $filteredCampaignName !== null
    ? '_' . preg_replace('/[^A-Za-z0-9]+/', '-', $filteredCampaignName)
    : '';
$filename = 'Rentabilidad_' . str_replace('-', '', $startDate) . '_' . str_replace('-', '', $endDate) . $slug . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
