<?php
session_start();
include 'db.php';
date_default_timezone_set('America/Santo_Domingo');

// Verificar permisos usando el mismo control que records.php
ensurePermission('records');

function getSupervisorAccessClause(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['role'] ?? '';

    if ($role !== 'Supervisor' || $userId <= 0) {
        $cache = ['', []];
        return $cache;
    }

    $campaignStmt = $pdo->prepare("SELECT campaign_id FROM supervisor_campaigns WHERE supervisor_id = ?");
    $campaignStmt->execute([$userId]);
    $campaigns = array_map('intval', $campaignStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $conditions = [
        'users.id = ?',
        'e.supervisor_id = ?'
    ];
    $params = [$userId, $userId];

    if (!empty($campaigns)) {
        $placeholders = implode(',', array_fill(0, count($campaigns), '?'));
        $conditions[] = "e.campaign_id IN ($placeholders)";
        $params = array_merge($params, $campaigns);
    }

    $cache = [' AND (' . implode(' OR ', $conditions) . ')', $params];
    return $cache;
}

// Obtener filtros desde la URL (pasados desde records.php)
$date_filter = $_GET['dates'] ?? date('Y-m-d');
$user_filter = trim($_GET['user'] ?? '');

// Procesar fechas - el daterangepicker envía formato "YYYY-MM-DD - YYYY-MM-DD" o "YYYY-MM-DD"
$dateValues = [];
if ($date_filter) {
    // Verificar si es un rango con " - " (espacio guión espacio)
    if (strpos($date_filter, ' - ') !== false) {
        // Es un rango de fechas del daterangepicker
        $parts = explode(' - ', $date_filter);
        if (count($parts) === 2) {
            $startDate = trim($parts[0]);
            $endDate = trim($parts[1]);
            
            // Generar todas las fechas del rango
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $end = $end->modify('+1 day'); // Incluir el último día
            
            $interval = new DateInterval('P1D');
            $daterange = new DatePeriod($start, $interval, $end);
            
            foreach ($daterange as $date) {
                $dateValues[] = $date->format('Y-m-d');
            }
        }
    } else {
        // Es una fecha única o lista separada por comas
        $dateValues = array_values(array_filter(array_map('trim', explode(',', $date_filter))));
    }
}

// Si no hay fechas, usar hoy
if (empty($dateValues)) {
    $dateValues = [date('Y-m-d')];
}

$datePlaceholders = implode(',', array_fill(0, count($dateValues), '?'));

// Obtener tipos de punch pagados
$paidTypeSlugs = getPaidAttendanceTypeSlugs($pdo);

// Obtener todos los tipos de asistencia para el reporte
$attendanceTypes = getAttendanceTypes($pdo, false);
$attendanceTypeMap = [];
$durationTypes = [];

foreach ($attendanceTypes as $typeRow) {
    $slug = sanitizeAttendanceTypeSlug($typeRow['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $typeRow['slug'] = $slug;
    $attendanceTypeMap[$slug] = $typeRow;
    
    // Tipos de duración (no únicos diarios)
    if ((int) ($typeRow['is_active'] ?? 0) === 1 && (int) ($typeRow['is_unique_daily'] ?? 0) === 0) {
        $durationTypes[] = $typeRow;
    }
}

// Construir consulta principal
$summary_query = "
    SELECT 
        attendance.user_id,
        users.full_name,
        users.username,
        users.preferred_currency,
        DATE(attendance.timestamp) AS record_date,
        attendance.type AS type_slug,
        attendance.timestamp
    FROM attendance 
    JOIN users ON attendance.user_id = users.id 
    LEFT JOIN employees e ON e.user_id = users.id
    WHERE DATE(attendance.timestamp) IN ($datePlaceholders)
";

$summary_params = $dateValues;

if ($user_filter !== '') {
    $summary_query .= " AND users.username = ?";
    $summary_params[] = $user_filter;
}

[$supervisorClause, $supervisorParams] = getSupervisorAccessClause($pdo);
if ($supervisorClause !== '') {
    $summary_query .= $supervisorClause;
    $summary_params = array_merge($summary_params, $supervisorParams);
}

$summary_query .= " ORDER BY users.username, record_date, attendance.timestamp";

$summary_stmt = $pdo->prepare($summary_query);
$summary_stmt->execute($summary_params);
$raw_summary_rows = $summary_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Debug: Si no hay datos, generar un reporte de debug
if (empty($raw_summary_rows)) {
    // Crear un archivo de debug simple en lugar del Excel
    $debug_info = [
        'Date Filter Received' => $_GET['dates'] ?? 'No filter',
        'User Filter' => $user_filter ?: 'All users',
        'Parsed Dates' => implode(', ', $dateValues),
        'Date Placeholders' => $datePlaceholders,
        'Query Params Count' => count($summary_params),
        'Query Params' => implode(', ', $summary_params),
        'Paid Type Slugs' => implode(', ', $paidTypeSlugs),
        'Duration Types Count' => count($durationTypes),
    ];
    
    // Verificar si hay registros en la base de datos para estas fechas
    $check_query = "
        SELECT COUNT(*) as total 
        FROM attendance 
        JOIN users ON attendance.user_id = users.id
        LEFT JOIN employees e ON e.user_id = users.id
        WHERE DATE(attendance.timestamp) IN ($datePlaceholders)
    ";
    $check_stmt = $pdo->prepare($check_query . $supervisorClause);
    $check_stmt->execute(array_merge($dateValues, $supervisorParams));
    $total_records = $check_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $debug_info['Total Records in DB for dates'] = $total_records;
    
    // Si hay registros pero no se están obteniendo, hay un problema con el JOIN o filtros
    if ($total_records > 0) {
        $check_query2 = "
            SELECT COUNT(*) as total 
            FROM attendance 
            JOIN users ON attendance.user_id = users.id 
            LEFT JOIN employees e ON e.user_id = users.id
            WHERE DATE(attendance.timestamp) IN ($datePlaceholders)
        ";
        $check_stmt2 = $pdo->prepare($check_query2 . $supervisorClause);
        $check_stmt2->execute(array_merge($dateValues, $supervisorParams));
        $total_with_join = $check_stmt2->fetch(PDO::FETCH_ASSOC)['total'];
        $debug_info['Total with JOIN'] = $total_with_join;
    }
    
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body style="font-family: Arial; padding: 20px;">';
    echo '<h2 style="color: #e74c3c;">⚠️ No se encontraron registros de asistencia</h2>';
    echo '<div style="background: #f8f9fa; padding: 15px; border-left: 4px solid #3498db;">';
    echo '<h3>Información de Debug:</h3>';
    echo '<table border="1" cellpadding="8" style="border-collapse: collapse;">';
    foreach ($debug_info as $key => $value) {
        echo '<tr><td style="background: #ecf0f1;"><strong>' . htmlspecialchars($key) . '</strong></td>';
        echo '<td>' . htmlspecialchars($value) . '</td></tr>';
    }
    echo '</table>';
    echo '</div>';
    echo '<br><a href="records.php" style="display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;">← Volver a Records</a>';
    echo '</body></html>';
    exit;
}

// Obtener configuración de horarios
$scheduleConfig = getScheduleConfig($pdo);
$hourly_rates = getUserHourlyRates($pdo);
$userExitTimes = function_exists('getUserExitTimes') ? getUserExitTimes($pdo) : [];
$userOvertimeMultipliers = function_exists('getUserOvertimeMultipliers') ? getUserOvertimeMultipliers($pdo) : [];
$defaultExitTime = trim((string) ($scheduleConfig['exit_time'] ?? ''));
if ($defaultExitTime !== '' && strlen($defaultExitTime) === 5) {
    $defaultExitTime .= ':00';
}
$overtimeEnabled = (int) ($scheduleConfig['overtime_enabled'] ?? 1) === 1;
$defaultOvertimeMultiplier = (float) ($scheduleConfig['overtime_multiplier'] ?? 1.50);
$overtimeStartMinutes = (int) ($scheduleConfig['overtime_start_minutes'] ?? 0);
$exitSlug = sanitizeAttendanceTypeSlug('EXIT');

$scheduleCache = [];
$getScheduleForUserDate = function (int $userId, ?string $recordDate = null) use ($pdo, &$scheduleCache): array {
    $dateKey = $recordDate ?? date('Y-m-d');
    $cacheKey = $userId . '|' . $dateKey;
    if (!isset($scheduleCache[$cacheKey])) {
        $scheduleCache[$cacheKey] = getScheduleConfigForUser($pdo, $userId, $dateKey);
    }
    return $scheduleCache[$cacheKey];
};

// Procesar datos para el resumen
$work_summary = [];
$currentGroup = null;
$currentKey = null;

$finalizeSummaryGroup = function (?array &$group) use (
    &$work_summary, 
    $durationTypes, 
    $paidTypeSlugs, 
    $hourly_rates, 
    $userExitTimes, 
    $defaultExitTime, 
    $exitSlug, 
    $overtimeEnabled, 
    $defaultOvertimeMultiplier, 
    $overtimeStartMinutes, 
    $userOvertimeMultipliers, 
    $getScheduleForUserDate,
    $pdo
): void {
    if ($group === null) {
        return;
    }

    $events = $group['events'];
    $eventCount = count($events);
    $durationsAll = [];

    if ($eventCount >= 2) {
        for ($i = 0; $i < $eventCount - 1; $i++) {
            $start = $events[$i]['timestamp'];
            $end = $events[$i + 1]['timestamp'];
            $delta = max(0, $end - $start);

            if ($delta <= 0) {
                continue;
            }

            $slug = $events[$i]['slug'];
            $durationsAll[$slug] = ($durationsAll[$slug] ?? 0) + $delta;
        }
    }

    $durationMap = [];
    foreach ($durationTypes as $typeRow) {
        $columnSlug = $typeRow['slug'];
        $durationMap[$columnSlug] = $durationsAll[$columnSlug] ?? 0;
    }

    // Calcular tiempo de trabajo solo desde tipos pagados
    $workSeconds = 0;
    foreach ($paidTypeSlugs as $paidSlug) {
        if (isset($durationsAll[$paidSlug])) {
            $workSeconds += $durationsAll[$paidSlug];
        }
    }
    $workSeconds = max(0, $workSeconds);

    // Calcular tiempo de pausa (tipos NO pagados)
    $breakSeconds = 0;
    foreach ($durationsAll as $slug => $seconds) {
        if (!in_array($slug, $paidTypeSlugs)) {
            $breakSeconds += $seconds;
        }
    }
    $breakSeconds = max(0, $breakSeconds);

    $recordDate = $group['record_date'] ?? null;
    $username = $group['username'] ?? null;
    $userId = $group['user_id'] ?? null;
    $preferredCurrency = $group['preferred_currency'] ?? 'USD';
    $overtimeSeconds = 0;
    $overtimePayment = 0.0;

    // Obtener tarifa por hora para la fecha específica
    $hourlyRate = 0.0;
    if ($userId !== null && $recordDate !== null) {
        $hourlyRate = getUserHourlyRateForDate($pdo, $userId, $recordDate, $preferredCurrency);
    } else if (isset($hourly_rates[$username])) {
        $hourlyRate = (float) $hourly_rates[$username];
    }

    // Calcular horas extra
    if ($recordDate !== null && $overtimeEnabled) {
        $configuredExit = $defaultExitTime;
        if ($userId !== null) {
            $userSchedule = $getScheduleForUserDate((int) $userId, $recordDate);
            if (!empty($userSchedule['exit_time'])) {
                $configuredExit = $userSchedule['exit_time'];
            }
        }
        if ($username !== null && isset($userExitTimes[$username]) && $userExitTimes[$username] !== '') {
            $configuredExit = $userExitTimes[$username];
        }

        $configuredExit = trim((string) $configuredExit);
        if ($configuredExit !== '') {
            if (strlen($configuredExit) === 5) {
                $configuredExit .= ':00';
            }
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $configuredExit) !== 1) {
                $parsedExit = strtotime($configuredExit);
                if ($parsedExit !== false) {
                    $configuredExit = date('H:i:s', $parsedExit);
                } else {
                    $configuredExit = '';
                }
            }

            if ($configuredExit !== '') {
                $scheduledExitTs = strtotime($recordDate . ' ' . $configuredExit);
                if ($scheduledExitTs !== false && $eventCount > 0) {
                    $scheduledExitTs += ($overtimeStartMinutes * 60);
                    
                    $actualExitTs = null;
                    for ($idx = $eventCount - 1; $idx >= 0; $idx--) {
                        if ($events[$idx]['slug'] === $exitSlug) {
                            $actualExitTs = $events[$idx]['timestamp'];
                            break;
                        }
                    }
                    if ($actualExitTs === null) {
                        $actualExitTs = $events[$eventCount - 1]['timestamp'];
                    }
                    if ($actualExitTs !== null && $actualExitTs > $scheduledExitTs) {
                        $overtimeSeconds = $actualExitTs - $scheduledExitTs;
                        
                        $overtimeMultiplier = $defaultOvertimeMultiplier;
                        if ($username !== null && isset($userOvertimeMultipliers[$username]) && $userOvertimeMultipliers[$username] !== null) {
                            $overtimeMultiplier = $userOvertimeMultipliers[$username];
                        }
                        $overtimePayment = round(($overtimeSeconds / 3600) * $hourlyRate * $overtimeMultiplier, 2);
                    }
                }
            }
        }
    }

    $regularPayment = round(($workSeconds / 3600) * $hourlyRate, 2);

    $work_summary[] = [
        'user_id' => $userId,
        'full_name' => $group['full_name'],
        'username' => $group['username'],
        'record_date' => $group['record_date'],
        'preferred_currency' => $preferredCurrency,
        'durations' => $durationMap,
        'work_seconds' => $workSeconds,
        'break_seconds' => $breakSeconds,
        'overtime_seconds' => $overtimeSeconds,
        'hourly_rate' => $hourlyRate,
        'regular_payment' => $regularPayment,
        'overtime_payment' => $overtimePayment,
        'total_payment' => $regularPayment + $overtimePayment,
    ];

    $group = null;
};

foreach ($raw_summary_rows as $row) {
    $slug = sanitizeAttendanceTypeSlug($row['type_slug'] ?? '');
    if ($slug === '') {
        continue;
    }

    $timestamp = strtotime($row['timestamp']);
    if ($timestamp === false) {
        continue;
    }

    $recordDate = $row['record_date'] ?? date('Y-m-d', $timestamp);
    $key = $row['user_id'] . '|' . $recordDate;

    if ($currentKey !== $key) {
        $finalizeSummaryGroup($currentGroup);
        $currentKey = $key;
        $currentGroup = [
            'user_id' => $row['user_id'],
            'full_name' => $row['full_name'],
            'username' => $row['username'],
            'preferred_currency' => $row['preferred_currency'] ?? 'USD',
            'record_date' => $recordDate,
            'events' => [],
        ];
    }

    $currentGroup['events'][] = [
        'slug' => $slug,
        'timestamp' => $timestamp,
    ];
}

$finalizeSummaryGroup($currentGroup);

// Debug: Verificar si work_summary tiene datos
if (empty($work_summary)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body style="font-family: Arial; padding: 20px;">';
    echo '<h2 style="color: #e74c3c;">⚠️ Error al procesar registros</h2>';
    echo '<div style="background: #f8f9fa; padding: 15px; border-left: 4px solid #e74c3c;">';
    echo '<p>Se encontraron ' . count($raw_summary_rows) . ' registros en la base de datos, pero no se pudieron procesar.</p>';
    echo '<h3>Información de Debug:</h3>';
    echo '<ul>';
    echo '<li>Duration Types: ' . count($durationTypes) . '</li>';
    echo '<li>Paid Type Slugs: ' . implode(', ', $paidTypeSlugs) . '</li>';
    echo '<li>Raw Rows: ' . count($raw_summary_rows) . '</li>';
    echo '</ul>';
    echo '<h3>Primeros 5 registros sin procesar:</h3>';
    echo '<pre>';
    print_r(array_slice($raw_summary_rows, 0, 5));
    echo '</pre>';
    echo '</div>';
    echo '<br><a href="records.php" style="display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;">← Volver a Records</a>';
    echo '</body></html>';
    exit;
}

// Preparar archivo Excel
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Asistencia Diaria');

// Calcular el número total de columnas
$totalColumns = 10 + count($durationTypes); // 3 básicas + duración types + 7 adicionales
$lastCol = chr(65 + $totalColumns - 1);

// Agregar logo
$logoPath = __DIR__ . '/assets/logo.png';
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

// Título del reporte
$dateRange = count($dateValues) === 1 ? $dateValues[0] : $dateValues[0] . ' - ' . end($dateValues);
$sheet->setCellValue('B1', 'REPORTE DE ASISTENCIA DIARIA');
$sheet->setCellValue('A2', 'Periodo: ' . $dateRange);
$sheet->setCellValue('A3', 'Generado: ' . date('Y-m-d H:i:s'));
if ($user_filter !== '') {
    $sheet->setCellValue('A4', 'Usuario filtrado: ' . $user_filter);
    $sheet->mergeCells('A4:' . $lastCol . '4');
    $sheet->getStyle('A4')->applyFromArray($subtitleStyle);
}

$sheet->mergeCells('B1:' . $lastCol . '1');
$sheet->mergeCells('A2:' . $lastCol . '2');
$sheet->mergeCells('A3:' . $lastCol . '3');

// Estilo del título
$titleStyle = [
    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
];
$sheet->getStyle('B1:' . $lastCol . '1')->applyFromArray($titleStyle);
$sheet->getStyle('A1')->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
]);

$subtitleStyle = [
    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6366F1']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
];
$sheet->getStyle('A2:A3')->applyFromArray($subtitleStyle);

// Encabezados de columnas
$row = $user_filter !== '' ? 6 : 5;
$col = 'A';

$headers = [
    'Nombre Completo',
    'Usuario',
    'Fecha',
];

// Agregar columnas de duración por tipo de punch
foreach ($durationTypes as $typeRow) {
    $headers[] = $typeRow['label'] ?? $typeRow['slug'];
}

$headers = array_merge($headers, [
    'Total Tiempo Pago',
    'Total Tiempo Pausa',
    'Horas Extra',
    'Tarifa/Hora',
    'Pago Regular',
    'Pago HE',
    'Pago Total',
]);

foreach ($headers as $header) {
    $sheet->setCellValue($col . $row, $header);
    $col++;
}

// Estilo de encabezados
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '818CF8']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
];
$lastHeaderCol = chr(65 + count($headers) - 1);
$sheet->getStyle('A' . $row . ':' . $lastHeaderCol . $row)->applyFromArray($headerStyle);
$sheet->getRowDimension($row)->setRowHeight(25);

// Datos
$row++;
$totals = [
    'work_seconds' => 0,
    'break_seconds' => 0,
    'overtime_seconds' => 0,
    'regular_payment_usd' => 0,
    'regular_payment_dop' => 0,
    'overtime_payment_usd' => 0,
    'overtime_payment_dop' => 0,
    'total_payment_usd' => 0,
    'total_payment_dop' => 0,
];

foreach ($durationTypes as $typeRow) {
    $totals['duration_' . $typeRow['slug']] = 0;
}

// Si no hay datos, agregar una fila indicándolo
if (empty($work_summary)) {
    $sheet->setCellValue('A' . $row, 'No se encontraron registros para el periodo seleccionado');
    $sheet->mergeCells('A' . $row . ':' . $lastHeaderCol . $row);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A' . $row)->getFont()->setItalic(true);
    $row++;
}

foreach ($work_summary as $summary) {
    $col = 'A';
    $currency = $summary['preferred_currency'] ?? 'USD';
    $currencySymbol = $currency === 'DOP' ? 'RD$' : '$';
    
    // Datos básicos
    $sheet->setCellValue($col++ . $row, $summary['full_name']);
    $sheet->setCellValue($col++ . $row, $summary['username']);
    $sheet->setCellValue($col++ . $row, $summary['record_date']);
    
    // Duraciones por tipo
    foreach ($durationTypes as $typeRow) {
        $seconds = (int) ($summary['durations'][$typeRow['slug']] ?? 0);
        $totals['duration_' . $typeRow['slug']] += $seconds;
        $sheet->setCellValue($col++ . $row, gmdate('H:i:s', max(0, $seconds)));
    }
    
    // Tiempos totales
    $workSeconds = (int) $summary['work_seconds'];
    $breakSeconds = (int) $summary['break_seconds'];
    $overtimeSeconds = (int) $summary['overtime_seconds'];
    
    $sheet->setCellValue($col++ . $row, gmdate('H:i:s', $workSeconds));
    $sheet->setCellValue($col++ . $row, gmdate('H:i:s', $breakSeconds));
    $sheet->setCellValue($col++ . $row, gmdate('H:i:s', $overtimeSeconds));
    
    // Pagos
    $sheet->setCellValue($col++ . $row, $currencySymbol . number_format($summary['hourly_rate'], 2) . ' ' . $currency);
    $sheet->setCellValue($col++ . $row, $currencySymbol . number_format($summary['regular_payment'], 2) . ' ' . $currency);
    $sheet->setCellValue($col++ . $row, $currencySymbol . number_format($summary['overtime_payment'], 2) . ' ' . $currency);
    $sheet->setCellValue($col++ . $row, $currencySymbol . number_format($summary['total_payment'], 2) . ' ' . $currency);
    
    // Acumular totales
    $totals['work_seconds'] += $workSeconds;
    $totals['break_seconds'] += $breakSeconds;
    $totals['overtime_seconds'] += $overtimeSeconds;
    
    if ($currency === 'USD') {
        $totals['regular_payment_usd'] += $summary['regular_payment'];
        $totals['overtime_payment_usd'] += $summary['overtime_payment'];
        $totals['total_payment_usd'] += $summary['total_payment'];
    } else {
        $totals['regular_payment_dop'] += $summary['regular_payment'];
        $totals['overtime_payment_dop'] += $summary['overtime_payment'];
        $totals['total_payment_dop'] += $summary['total_payment'];
    }
    
    // Estilo de fila alternada
    $rowStyle = [
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
    ];
    if ($row % 2 === 0) {
        $rowStyle['fill'] = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8F9FA']];
    }
    $sheet->getStyle('A' . $row . ':' . $lastHeaderCol . $row)->applyFromArray($rowStyle);
    $row++;
}

// Fila de totales
$row++;
$col = 'A';
$sheet->setCellValue($col++ . $row, 'TOTALES');
$sheet->mergeCells('A' . $row . ':B' . $row);
$col = 'C';
$sheet->setCellValue($col++ . $row, count($work_summary) . ' registros');

// Totales de duraciones por tipo
foreach ($durationTypes as $typeRow) {
    $sheet->setCellValue($col++ . $row, gmdate('H:i:s', max(0, $totals['duration_' . $typeRow['slug']])));
}

// Totales generales
$sheet->setCellValue($col++ . $row, gmdate('H:i:s', $totals['work_seconds']));
$sheet->setCellValue($col++ . $row, gmdate('H:i:s', $totals['break_seconds']));
$sheet->setCellValue($col++ . $row, gmdate('H:i:s', $totals['overtime_seconds']));
$sheet->setCellValue($col++ . $row, '-');

// Pagos totales
$regularPaymentText = '';
if ($totals['regular_payment_usd'] > 0) {
    $regularPaymentText .= '$' . number_format($totals['regular_payment_usd'], 2) . ' USD';
}
if ($totals['regular_payment_dop'] > 0) {
    if ($regularPaymentText) $regularPaymentText .= "\n";
    $regularPaymentText .= 'RD$' . number_format($totals['regular_payment_dop'], 2) . ' DOP';
}
$sheet->setCellValue($col++ . $row, $regularPaymentText);

$overtimePaymentText = '';
if ($totals['overtime_payment_usd'] > 0) {
    $overtimePaymentText .= '$' . number_format($totals['overtime_payment_usd'], 2) . ' USD';
}
if ($totals['overtime_payment_dop'] > 0) {
    if ($overtimePaymentText) $overtimePaymentText .= "\n";
    $overtimePaymentText .= 'RD$' . number_format($totals['overtime_payment_dop'], 2) . ' DOP';
}
$sheet->setCellValue($col++ . $row, $overtimePaymentText);

$totalPaymentText = '';
if ($totals['total_payment_usd'] > 0) {
    $totalPaymentText .= '$' . number_format($totals['total_payment_usd'], 2) . ' USD';
}
if ($totals['total_payment_dop'] > 0) {
    if ($totalPaymentText) $totalPaymentText .= "\n";
    $totalPaymentText .= 'RD$' . number_format($totals['total_payment_dop'], 2) . ' DOP';
}
$sheet->setCellValue($col++ . $row, $totalPaymentText);

// Estilo de totales
$totalsStyle = [
    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '10B981']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '000000']]],
];
$sheet->getStyle('A' . $row . ':' . $lastHeaderCol . $row)->applyFromArray($totalsStyle);
$sheet->getRowDimension($row)->setRowHeight(30);

// Ajustar anchos de columna
foreach (range('A', $lastHeaderCol) as $columnID) {
    $sheet->getColumnDimension($columnID)->setAutoSize(true);
}

// Aplicar alineación central a todas las celdas de datos
$dataStartRow = $user_filter !== '' ? 6 : 5;
$sheet->getStyle('A' . $dataStartRow . ':' . $lastHeaderCol . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $dataStartRow . ':' . $lastHeaderCol . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

// Agregar una hoja de resumen
$summarySheet = $spreadsheet->createSheet(0);
$summarySheet->setTitle('Resumen');

$summarySheet->setCellValue('A1', 'RESUMEN DEL REPORTE');
$summarySheet->mergeCells('A1:D1');
$summarySheet->getStyle('A1')->applyFromArray($titleStyle);
$summarySheet->getRowDimension(1)->setRowHeight(30);

$summaryRow = 3;
$summarySheet->setCellValue('A' . $summaryRow, 'Métrica');
$summarySheet->setCellValue('B' . $summaryRow, 'Valor');
$summarySheet->getStyle('A' . $summaryRow . ':B' . $summaryRow)->applyFromArray($headerStyle);
$summaryRow++;

// Agregar datos del resumen
$summaryData = [
    ['Periodo', $dateRange],
    ['Total de registros', count($work_summary)],
    ['Total de agentes', count(array_unique(array_column($work_summary, 'username')))],
    [''],
    ['Total Horas Trabajadas', gmdate('H:i:s', $totals['work_seconds'])],
    ['Total Horas de Pausa', gmdate('H:i:s', $totals['break_seconds'])],
    ['Total Horas Extra', gmdate('H:i:s', $totals['overtime_seconds'])],
    [''],
];

if ($totals['regular_payment_usd'] > 0) {
    $summaryData[] = ['Total Pago Regular (USD)', '$' . number_format($totals['regular_payment_usd'], 2)];
    $summaryData[] = ['Total Pago HE (USD)', '$' . number_format($totals['overtime_payment_usd'], 2)];
    $summaryData[] = ['Total General (USD)', '$' . number_format($totals['total_payment_usd'], 2)];
    $summaryData[] = [''];
}

if ($totals['regular_payment_dop'] > 0) {
    $summaryData[] = ['Total Pago Regular (DOP)', 'RD$' . number_format($totals['regular_payment_dop'], 2)];
    $summaryData[] = ['Total Pago HE (DOP)', 'RD$' . number_format($totals['overtime_payment_dop'], 2)];
    $summaryData[] = ['Total General (DOP)', 'RD$' . number_format($totals['total_payment_dop'], 2)];
}

foreach ($summaryData as $data) {
    if (empty($data[0])) {
        $summaryRow++;
        continue;
    }
    $summarySheet->setCellValue('A' . $summaryRow, $data[0]);
    $summarySheet->setCellValue('B' . $summaryRow, $data[1]);
    
    $rowStyle = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]]];
    if (strpos($data[0], 'Total General') !== false) {
        $rowStyle['font'] = ['bold' => true, 'size' => 12];
        $rowStyle['fill'] = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0F2FE']];
    }
    $summarySheet->getStyle('A' . $summaryRow . ':B' . $summaryRow)->applyFromArray($rowStyle);
    $summaryRow++;
}

$summarySheet->getColumnDimension('A')->setWidth(30);
$summarySheet->getColumnDimension('B')->setWidth(25);

// Notas al final
$summaryRow += 2;
$summarySheet->setCellValue('A' . $summaryRow, 'NOTAS:');
$summarySheet->getStyle('A' . $summaryRow)->getFont()->setBold(true);
$summaryRow++;

$notes = [
    '• Los tipos de punch pagados se calculan según la configuración del sistema.',
    '• El tiempo de pausa incluye todos los tipos de punch no marcados como pagados.',
    '• Las horas extra se calculan automáticamente después de la hora de salida configurada.',
    '• Los pagos se calculan usando las tarifas históricas para cada fecha específica.',
    '• Este reporte refleja el filtro de fechas aplicado: ' . $dateRange,
];

foreach ($notes as $note) {
    $summarySheet->setCellValue('A' . $summaryRow, $note);
    $summarySheet->getStyle('A' . $summaryRow)->getAlignment()->setWrapText(true);
    $summaryRow++;
}

$summarySheet->mergeCells('A' . ($summaryRow - count($notes)) . ':D' . ($summaryRow - 1));

// Generar el archivo
$filename = 'Reporte_Asistencia_Diaria_' . str_replace(',', '_', $date_filter) . '_' . date('YmdHis') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
