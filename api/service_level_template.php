<?php
/**
 * Service Level Calculator - Excel Template
 * Permite descargar una plantilla Excel y procesar intervalos cargados
 */

session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Permission check
if (!userHasPermission('wfm_planning')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para usar esta herramienta']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// =====================================================================
// Erlang C implementation (mirror of api/service_level_calculator.php)
// =====================================================================
function erlangC(float $a, int $n): float
{
    if ($a <= 0 || $n <= 0) return 0.0;
    if ($n <= $a) return 1.0;

    $sum = 1.0;
    $term = 1.0;
    for ($k = 1; $k <= $n - 1; $k++) {
        $term *= $a / $k;
        $sum += $term;
    }
    $term *= $a / $n;
    $numer = $term * ($n / ($n - $a));
    $denom = $sum + $numer;
    if ($denom == 0.0) return 1.0;
    return $numer / $denom;
}

function calculateStaffing(array $params): array
{
    $intervalMinutes = max(1, (int) ($params['intervalMinutes'] ?? 30));
    $intervalSeconds = $intervalMinutes * 60;

    $calls = max(0, (int) ($params['calls'] ?? 0));
    $ahtSeconds = max(1, (int) ($params['ahtSeconds'] ?? 180));

    $targetSl = (float) ($params['targetSl'] ?? 80);
    if ($targetSl > 1) $targetSl = $targetSl / 100;

    $targetAns = max(1, (int) ($params['targetAns'] ?? 20));

    $occupancyTarget = (float) ($params['occupancyTarget'] ?? 85);
    if ($occupancyTarget > 1) $occupancyTarget = $occupancyTarget / 100;

    $shrinkage = (float) ($params['shrinkage'] ?? 30);
    if ($shrinkage > 1) $shrinkage = $shrinkage / 100;

    if ($calls <= 0) throw new Exception('Llamadas debe ser mayor que 0');
    if ($ahtSeconds <= 0) throw new Exception('AHT debe ser mayor que 0');
    if ($targetSl <= 0 || $targetSl > 1) throw new Exception('Service Level debe estar entre 1% y 100%');
    if ($occupancyTarget <= 0 || $occupancyTarget > 0.95) throw new Exception('Ocupación objetivo debe estar entre 1% y 95%');
    if ($shrinkage < 0 || $shrinkage >= 1) throw new Exception('Shrinkage debe estar entre 0% y 99%');

    $workload = 0.0;
    if ($intervalSeconds > 0 && $ahtSeconds > 0 && $calls > 0) {
        $workload = ($calls * $ahtSeconds) / $intervalSeconds;
    }

    $requiredAgents = 0;
    $serviceLevel = 1.0;
    $occupancy = 0.0;

    if ($workload > 0 && $ahtSeconds > 0) {
        $n = (int) ceil($workload);
        if ($n <= $workload) $n = (int) floor($workload) + 1;

        if ($occupancyTarget > 0) {
            $minOccupancy = (int) ceil($workload / $occupancyTarget);
            if ($minOccupancy > $n) $n = $minOccupancy;
        }

        $maxIterations = 200;
        $serviceLevel = 0.0;
        for ($i = 0; $i <= $maxIterations; $i++) {
            if ($n <= $workload) { $n++; continue; }
            $erlC = erlangC($workload, $n);
            $exponent = -($n - $workload) * ($targetAns / $ahtSeconds);
            $serviceLevel = 1 - ($erlC * exp($exponent));
            if ($serviceLevel >= $targetSl) break;
            $n++;
        }

        $requiredAgents = $n;
        $occupancy = $requiredAgents > 0 ? ($workload / $requiredAgents) : 0.0;
    }

    $requiredStaff = $requiredAgents;
    if ($shrinkage > 0 && $shrinkage < 1 && $requiredAgents > 0) {
        $requiredStaff = (int) ceil($requiredAgents / (1 - $shrinkage));
    }

    return [
        'required_agents' => $requiredAgents,
        'required_staff' => $requiredStaff,
        'service_level' => round($serviceLevel, 4),
        'occupancy' => round($occupancy, 4),
        'workload' => round($workload, 4),
        'interval_seconds' => $intervalSeconds,
    ];
}

// =====================================================================
// Action: download template
// =====================================================================
if ($action === 'download') {
    try {
        $spreadsheet = new Spreadsheet();

        // ---------- Sheet 1: Instrucciones ----------
        $instructions = $spreadsheet->getActiveSheet();
        $instructions->setTitle('Instrucciones');

        $instructions->setCellValue('A1', 'PLANTILLA DE CÁLCULO DE NIVEL DE SERVICIO - INTERVALOS');
        $instructions->mergeCells('A1:D1');
        $instructions->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0891B2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $instructions->getRowDimension(1)->setRowHeight(32);

        $instructions->setCellValue('A3', '¿QUÉ ES ESTA PLANTILLA?');
        $instructions->getStyle('A3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0E7490']],
        ]);
        $instructions->setCellValue('A4', 'Esta plantilla te permite cargar múltiples intervalos a la vez para que la calculadora analice cada uno');
        $instructions->setCellValue('A5', 'usando la fórmula de Erlang C y devuelva el dimensionamiento requerido para cada intervalo.');

        $instructions->setCellValue('A7', 'PASOS DE USO:');
        $instructions->getStyle('A7')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0E7490']],
        ]);
        $steps = [
            '1. Abre la hoja "Intervalos" (pestaña inferior).',
            '2. Llena una fila por cada intervalo que quieras analizar. No modifiques los encabezados.',
            '3. Puedes agregar tantas filas como necesites (recomendado: máximo 500).',
            '4. Todos los campos son obligatorios. Los porcentajes se ingresan como número entero (ej: 80 para 80%).',
            '5. Guarda el archivo en formato Excel (.xlsx) o CSV.',
            '6. Vuelve a la Calculadora de Nivel de Servicio, presiona "Subir plantilla" y selecciona el archivo.',
            '7. Los resultados aparecerán en la sección de Análisis por Intervalos.',
        ];
        $row = 8;
        foreach ($steps as $step) {
            $instructions->setCellValue('A' . $row, $step);
            $row++;
        }

        $instructions->setCellValue('A' . ($row + 1), 'DESCRIPCIÓN DE COLUMNAS:');
        $instructions->getStyle('A' . ($row + 1))->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0E7490']],
        ]);

        $columnDocs = [
            ['Intervalo', 'Etiqueta del intervalo (ej: "08:00-08:30", "Lunes 09:00"). Solo texto identificador.'],
            ['Llamadas', 'Número de llamadas esperadas en el intervalo. Entero mayor a 0.'],
            ['AHT (seg)', 'Average Handling Time: tiempo promedio de atención en segundos. Entero mayor a 0.'],
            ['Duración Intervalo (min)', 'Duración del intervalo en minutos. Valores típicos: 15, 30 o 60.'],
            ['SL Objetivo (%)', 'Porcentaje de llamadas a contestar dentro del tiempo objetivo. Valor 1 a 100 (ej: 80).'],
            ['Tiempo Respuesta (seg)', 'Segundos máximos para contestar y cumplir el SL objetivo (ej: 20).'],
            ['Ocupación Objetivo (%)', 'Ocupación máxima deseada de agentes. Valor típico 70 a 90 (ej: 85).'],
            ['Shrinkage (%)', 'Porcentaje de tiempo no productivo (breaks, reuniones). Valor típico 20 a 35 (ej: 30).'],
        ];

        $docRow = $row + 3;
        $instructions->setCellValue('A' . $docRow, 'Columna');
        $instructions->setCellValue('B' . $docRow, 'Descripción');
        $instructions->getStyle('A' . $docRow . ':B' . $docRow)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '164E63']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $docRow++;
        foreach ($columnDocs as $doc) {
            $instructions->setCellValue('A' . $docRow, $doc[0]);
            $instructions->setCellValue('B' . $docRow, $doc[1]);
            $instructions->getStyle('A' . $docRow)->getFont()->setBold(true);
            $instructions->getStyle('A' . $docRow . ':B' . $docRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $docRow++;
        }

        $instructions->getColumnDimension('A')->setWidth(30);
        $instructions->getColumnDimension('B')->setWidth(90);
        $instructions->getStyle('A4:A' . ($row + 1))->getAlignment()->setWrapText(true);

        // ---------- Sheet 2: Intervalos ----------
        $dataSheet = $spreadsheet->createSheet();
        $dataSheet->setTitle('Intervalos');

        $headers = [
            'A1' => 'Intervalo',
            'B1' => 'Llamadas',
            'C1' => 'AHT (seg)',
            'D1' => 'Duración Intervalo (min)',
            'E1' => 'SL Objetivo (%)',
            'F1' => 'Tiempo Respuesta (seg)',
            'G1' => 'Ocupación Objetivo (%)',
            'H1' => 'Shrinkage (%)',
        ];
        foreach ($headers as $cell => $value) {
            $dataSheet->setCellValue($cell, $value);
        }

        $dataSheet->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0891B2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '164E63']]],
        ]);
        $dataSheet->getRowDimension(1)->setRowHeight(36);

        $widths = ['A' => 22, 'B' => 12, 'C' => 12, 'D' => 22, 'E' => 16, 'F' => 20, 'G' => 20, 'H' => 16];
        foreach ($widths as $col => $w) {
            $dataSheet->getColumnDimension($col)->setWidth($w);
        }

        // Example rows
        $examples = [
            ['08:00-08:30', 120, 180, 30, 80, 20, 85, 30],
            ['08:30-09:00', 150, 180, 30, 80, 20, 85, 30],
            ['09:00-09:30', 200, 195, 30, 80, 20, 85, 30],
        ];
        $rowNum = 2;
        foreach ($examples as $ex) {
            $col = 'A';
            foreach ($ex as $val) {
                $dataSheet->setCellValue($col . $rowNum, $val);
                $col++;
            }
            $rowNum++;
        }

        $dataSheet->getStyle('A2:H4')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0F2FE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BAE6FD']]],
        ]);
        $dataSheet->setCellValue('J2', '← Filas de ejemplo. Puedes borrarlas y escribir tus datos.');
        $dataSheet->getStyle('J2')->getFont()->setItalic(true)->getColor()->setRGB('64748B');

        // Freeze header row
        $dataSheet->freezePane('A2');

        $spreadsheet->setActiveSheetIndex(0);

        // Output
        $filename = 'plantilla_service_level_calculator.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Error generando plantilla: ' . $e->getMessage()]);
        exit;
    }
}

// =====================================================================
// Action: upload & process intervals
// =====================================================================
if ($action === 'upload') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No se recibió un archivo válido');
        }

        $tmpPath = $_FILES['file']['tmp_name'];
        $originalName = $_FILES['file']['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
            throw new Exception('Formato no soportado. Use .xlsx, .xls o .csv');
        }

        $reader = IOFactory::createReaderForFile($tmpPath);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($tmpPath);

        // Prefer a sheet named "Intervalos"; fallback to the last sheet with data
        $sheet = null;
        foreach ($spreadsheet->getAllSheets() as $candidate) {
            if (strcasecmp($candidate->getTitle(), 'Intervalos') === 0) {
                $sheet = $candidate;
                break;
            }
        }
        if ($sheet === null) {
            $sheet = $spreadsheet->getSheetCount() > 1
                ? $spreadsheet->getSheet($spreadsheet->getSheetCount() - 1)
                : $spreadsheet->getActiveSheet();
        }

        $rows = $sheet->toArray(null, true, true, false);
        if (count($rows) < 2) {
            throw new Exception('El archivo no contiene filas de datos');
        }

        // Skip header row and empty rows
        array_shift($rows);

        $results = [];
        $errors = [];
        $lineNumber = 2;
        $processed = 0;

        foreach ($rows as $row) {
            // Skip rows that are completely empty
            $nonEmpty = array_filter($row, function ($v) {
                return $v !== null && $v !== '';
            });
            if (empty($nonEmpty)) {
                $lineNumber++;
                continue;
            }

            $label = trim((string) ($row[0] ?? ''));
            $calls = $row[1] ?? null;
            $aht = $row[2] ?? null;
            $intervalMinutes = $row[3] ?? null;
            $targetSl = $row[4] ?? null;
            $targetAns = $row[5] ?? null;
            $occupancyTarget = $row[6] ?? null;
            $shrinkage = $row[7] ?? null;

            try {
                if ($label === '') $label = 'Fila ' . $lineNumber;

                $params = [
                    'calls' => (int) $calls,
                    'ahtSeconds' => (int) $aht,
                    'intervalMinutes' => (int) $intervalMinutes,
                    'targetSl' => (float) $targetSl,
                    'targetAns' => (int) $targetAns,
                    'occupancyTarget' => (float) $occupancyTarget,
                    'shrinkage' => (float) $shrinkage,
                ];

                $res = calculateStaffing($params);
                $res['label'] = $label;
                $res['params'] = $params;
                $results[] = $res;
                $processed++;
            } catch (Exception $ex) {
                $errors[] = [
                    'line' => $lineNumber,
                    'label' => $label,
                    'error' => $ex->getMessage(),
                ];
            }

            $lineNumber++;
        }

        // Summary aggregates
        $summary = [
            'processed' => $processed,
            'errors' => count($errors),
            'total_agents' => array_sum(array_column($results, 'required_agents')),
            'total_staff' => array_sum(array_column($results, 'required_staff')),
            'avg_sl' => $processed > 0 ? round(array_sum(array_column($results, 'service_level')) / $processed, 4) : 0,
            'avg_occupancy' => $processed > 0 ? round(array_sum(array_column($results, 'occupancy')) / $processed, 4) : 0,
            'total_workload' => round(array_sum(array_column($results, 'workload')), 4),
        ];

        echo json_encode([
            'success' => true,
            'summary' => $summary,
            'results' => $results,
            'errors' => $errors,
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'error' => 'Acción no válida']);
