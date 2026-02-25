<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

header('Content-Type: application/json');

// Check permission
if (!isset($_SESSION['user_id']) || !userHasPermission('vicidial_reports')) {
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para realizar esta acción']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Error al subir el archivo']);
    exit;
}

// Check if report date was provided
if (!isset($_POST['report_date']) || empty($_POST['report_date'])) {
    echo json_encode(['success' => false, 'message' => 'Debe especificar la fecha del reporte']);
    exit;
}

$reportDate = $_POST['report_date'];
$uploadedBy = $_SESSION['user_id'];
$filename = $_FILES['csv_file']['name'];
$tmpPath = $_FILES['csv_file']['tmp_name'];

// Validate file extension
$fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if ($fileExt !== 'csv') {
    echo json_encode(['success' => false, 'message' => 'Solo se permiten archivos CSV']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    echo json_encode(['success' => false, 'message' => 'Formato de fecha inválido']);
    exit;
}

try {
    // Open CSV file
    $file = fopen($tmpPath, 'r');
    if (!$file) {
        throw new Exception('No se pudo abrir el archivo CSV');
    }

    // Skip header rows and find the column headers
    // Vicidial format has metadata rows at the top, then the actual headers
    $header = null;
    $lineNumber = 0;

    while (($line = fgetcsv($file, 0, ",")) !== false) {
        $lineNumber++;

        // Skip empty lines
        if (empty(array_filter($line))) {
            continue;
        }

        // Clean quotes from values
        $cleanLine = array_map(function ($col) {
            return trim($col, '"');
        }, $line);

        // Look for the header row (contains "USER NAME")
        if (in_array('USER NAME', $cleanLine)) {
            $header = $cleanLine;
            break;
        }
    }

    if (!$header) {
        fclose($file);
        throw new Exception('No se encontró la fila de encabezados en el archivo CSV');
    }

    // Expected columns (from the sample data provided)
    $expectedColumns = [
        'USER NAME',
        'ID',
        'CURRENT USER GROUP',
        'MOST RECENT USER GROUP',
        'CALL DATE ',
        'CALLS',
        'TIME',
        'PAUSE',
        'PAUSAVG',
        'WAIT',
        'WAITAVG',
        'TALK',
        'TALKAVG',
        'DISPO',
        'DISPAVG',
        'DEAD',
        'DEADAVG',
        'CUSTOMER',
        'CUSTAVG',
        'A',
        'ACTIVE',
        'B',
        'CALLBK',
        'COLGO',
        'CORTAD',
        'DAIR',
        'DC',
        'DEC',
        'DEPOSI',
        'DNC',
        'DUPLIC',
        'N',
        'NI',
        'NOCAL',
        'NOCON',
        'NOTIE',
        'NP',
        'NUMEQ',
        'ORDEN',
        'OTROD',
        'PEDIDO',
        'PREGUN',
        'PROMO',
        'PTRANS',
        'PU',
        'QUEJAS',
        'RESERV',
        'SALE',
        'SEGUIM',
        'SILENC',
        'WASAPI',
        'XFER'
    ];

    // Validate header
    $missingColumns = array_diff($expectedColumns, $header);
    if (!empty($missingColumns)) {
        fclose($file);
        throw new Exception('Faltan columnas en el CSV: ' . implode(', ', $missingColumns));
    }

    // Helper function to convert HH:MM:SS to seconds
    function timeToSeconds($timeStr)
    {
        $timeStr = trim($timeStr);
        if (empty($timeStr) || $timeStr === '0' || $timeStr === '00:00:00') {
            return 0;
        }

        $parts = explode(':', $timeStr);
        if (count($parts) === 3) {
            return (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (int) $parts[2];
        }
        return 0;
    }

    // Prepare insert statement
    $insertStmt = $pdo->prepare("
        INSERT INTO vicidial_login_stats (
            user_name, user_id, current_user_group, most_recent_user_group,
            calls, time_total, pause_time, pause_avg, wait_time, wait_avg,
            talk_time, talk_avg, dispo_time, dispo_avg, dead_time, dead_avg,
            customer_time, customer_avg, a, active, b, callbk, colgo, cortad, dair, dc, `dec`,
            deposi, dnc, duplic, n, ni, nocal, nocon, notie, np, numeq, orden, otrod, pedido, pregun, promo, ptrans,
            pu, quejas, reserv, sale, seguim, silenc, wasapi, xfer, upload_date, uploaded_by
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ");

    $recordCount = 0;
    $currentAgent = null;
    $pdo->beginTransaction();

    // Process each row
    while (($row = fgetcsv($file, 0, ",")) !== false) {
        // Skip empty rows
        if (empty(array_filter($row))) {
            continue;
        }

        // Clean quotes from all values
        $row = array_map(function ($val) {
            return trim($val, '"');
        }, $row);

        // Skip if this row has wrong number of columns
        if (count($row) !== count($header)) {
            continue;
        }

        // Map row to associative array
        $data = array_combine($header, $row);

        // Skip TOTALS row
        if (isset($data['CURRENT USER GROUP']) && $data['CURRENT USER GROUP'] === 'TOTALS') {
            continue;
        }

        // Extract agent info
        $userName = trim($data['USER NAME'] ?? '');
        $userId = trim($data['ID'] ?? '');

        // If USER NAME and ID are present, this is a summary row (start of a new agent section)
        if (!empty($userName) && !empty($userId)) {
            $currentAgent = [
                'user_name' => $userName,
                'user_id' => $userId,
                'current_user_group' => trim($data['CURRENT USER GROUP'] ?? ''),
                'most_recent_user_group' => trim($data['MOST RECENT USER GROUP'] ?? '')
            ];

            // If there's no CALL DATE column OR if CALL DATE is empty, 
            // check if we should process this row directly (non-hierarchical CSV)
            if (!isset($header_has_call_date)) {
                $header_has_call_date = in_array('CALL DATE ', $header);
            }

            if (!$header_has_call_date) {
                // Old format: process summary row as the data row
                $process_row = true;
                $rowDate = $reportDate;
            } else {
                // New format: wait for daily rows
                $process_row = false;
            }
        } else {
            // This might be a daily row
            $rowDate = trim($data['CALL DATE '] ?? '');
            if (!empty($rowDate) && $currentAgent) {
                $process_row = true;
            } else {
                $process_row = false;
            }
        }

        if (!$process_row) {
            continue;
        }

        // Extract and convert data
        $userName = $currentAgent['user_name'];
        $userId = $currentAgent['user_id'];
        $currentUserGroup = $currentAgent['current_user_group'];
        $mostRecentUserGroup = $currentAgent['most_recent_user_group'];

        // Convert time fields to seconds
        $calls = (int) ($data['CALLS'] ?? 0);
        $timeTotal = timeToSeconds($data['TIME'] ?? '0');
        $pauseTime = timeToSeconds($data['PAUSE'] ?? '0');
        $pauseAvg = timeToSeconds($data['PAUSAVG'] ?? '0');
        $waitTime = timeToSeconds($data['WAIT'] ?? '0');
        $waitAvg = timeToSeconds($data['WAITAVG'] ?? '0');
        $talkTime = timeToSeconds($data['TALK'] ?? '0');
        $talkAvg = timeToSeconds($data['TALKAVG'] ?? '0');
        $dispoTime = timeToSeconds($data['DISPO'] ?? '0');
        $dispoAvg = timeToSeconds($data['DISPAVG'] ?? '0');
        $deadTime = timeToSeconds($data['DEAD'] ?? '0');
        $deadAvg = timeToSeconds($data['DEADAVG'] ?? '0');
        $customerTime = timeToSeconds($data['CUSTOMER'] ?? '0');
        $customerAvg = timeToSeconds($data['CUSTAVG'] ?? '0');

        // Status columns
        $a = (int) ($data['A'] ?? 0);
        $active = (int) ($data['ACTIVE'] ?? 0);
        $b = (int) ($data['B'] ?? 0);
        $callbk = (int) ($data['CALLBK'] ?? 0);
        $colgo = (int) ($data['COLGO'] ?? 0);
        $cortad = (int) ($data['CORTAD'] ?? 0);
        $dair = (int) ($data['DAIR'] ?? 0);
        $dc = (int) ($data['DC'] ?? 0);
        $dec = (int) ($data['DEC'] ?? 0);
        $deposi = (int) ($data['DEPOSI'] ?? 0);
        $dnc = (int) ($data['DNC'] ?? 0);
        $duplic = (int) ($data['DUPLIC'] ?? 0);
        $n = (int) ($data['N'] ?? 0);
        $ni = (int) ($data['NI'] ?? 0);
        $nocal = (int) ($data['NOCAL'] ?? 0);
        $nocon = (int) ($data['NOCON'] ?? 0);
        $notie = (int) ($data['NOTIE'] ?? 0);
        $np = (int) ($data['NP'] ?? 0);
        $numeq = (int) ($data['NUMEQ'] ?? 0);
        $orden = (int) ($data['ORDEN'] ?? 0);
        $otrod = (int) ($data['OTROD'] ?? 0);
        $pedido = (int) ($data['PEDIDO'] ?? 0);
        $pregun = (int) ($data['PREGUN'] ?? 0);
        $promo = (int) ($data['PROMO'] ?? 0);
        $ptrans = (int) ($data['PTRANS'] ?? 0);
        $pu = (int) ($data['PU'] ?? 0);
        $quejas = (int) ($data['QUEJAS'] ?? 0);
        $reserv = (int) ($data['RESERV'] ?? 0);
        $sale = (int) ($data['SALE'] ?? 0);
        $seguim = (int) ($data['SEGUIM'] ?? 0);
        $silenc = (int) ($data['SILENC'] ?? 0);
        $wasapi = (int) ($data['WASAPI'] ?? 0);
        $xfer = (int) ($data['XFER'] ?? 0);

        // Insert record
        $insertStmt->execute([
            $userName,
            $userId,
            $currentUserGroup,
            $mostRecentUserGroup,
            $calls,
            $timeTotal,
            $pauseTime,
            $pauseAvg,
            $waitTime,
            $waitAvg,
            $talkTime,
            $talkAvg,
            $dispoTime,
            $dispoAvg,
            $deadTime,
            $deadAvg,
            $customerTime,
            $customerAvg,
            $a,
            $active,
            $b,
            $callbk,
            $colgo,
            $cortad,
            $dair,
            $dc,
            $dec,
            $deposi,
            $dnc,
            $duplic,
            $n,
            $ni,
            $nocal,
            $nocon,
            $notie,
            $np,
            $numeq,
            $orden,
            $otrod,
            $pedido,
            $pregun,
            $promo,
            $ptrans,
            $pu,
            $quejas,
            $reserv,
            $sale,
            $seguim,
            $silenc,
            $wasapi,
            $xfer,
            $rowDate,
            $uploadedBy
        ]);

        $recordCount++;
    }

    fclose($file);

    // Create upload history record
    $historyStmt = $pdo->prepare("
        INSERT INTO vicidial_uploads (report_type, filename, upload_date, uploaded_by, record_count)
        VALUES (?, ?, ?, ?, ?)
    ");
    $historyStmt->execute(['login_stats', $filename, $reportDate, $uploadedBy, $recordCount]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Reporte subido exitosamente',
        'record_count' => $recordCount
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => 'Error al procesar el archivo: ' . $e->getMessage()
    ]);
}
?>