<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

if (!userHasPermission('manage_campaigns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para cargar reportes']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

// Handle DELETE requests
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $campaignId = isset($input['campaign_id']) ? (int) $input['campaign_id'] : 0;
    $reportDate = $input['report_date'] ?? '';

    if ($campaignId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'campaign_id requerido']);
        exit;
    }

    try {
        $deleted = 0;
        if ($reportDate !== '') {
            // Delete specific date
            $stmt = $pdo->prepare("DELETE FROM campaign_ast_performance WHERE campaign_id = ? AND report_date = ?");
            $stmt->execute([$campaignId, $reportDate]);
            $deleted += $stmt->rowCount();

            $stmt2 = $pdo->prepare("DELETE FROM campaign_sales_reports WHERE campaign_id = ? AND report_date = ?");
            $stmt2->execute([$campaignId, $reportDate]);
            $deleted += $stmt2->rowCount();
        } else {
            // Delete all data for this campaign
            $stmt = $pdo->prepare("DELETE FROM campaign_ast_performance WHERE campaign_id = ?");
            $stmt->execute([$campaignId]);
            $deleted += $stmt->rowCount();

            $stmt2 = $pdo->prepare("DELETE FROM campaign_sales_reports WHERE campaign_id = ?");
            $stmt2->execute([$campaignId]);
            $deleted += $stmt2->rowCount();
        }

        echo json_encode(['success' => true, 'deleted' => $deleted]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al eliminar: ' . $e->getMessage()]);
    }
    exit;
}

function jsonError($message, $status = 400)
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function parseNumber($value)
{
    if ($value === null)
        return 0;
    $value = trim((string) $value);
    if ($value === '')
        return 0;
    $value = str_replace([',', ' ', '%'], ['', '', ''], $value);
    return is_numeric($value) ? (float) $value : 0;
}

function parseIntVal($value)
{
    return (int) parseNumber($value);
}

/**
 * Converts a time string like "1:23:45", "0:05:30", "00:00", "0:00:00" to total seconds.
 */
function timeToSeconds($value)
{
    if ($value === null)
        return 0;
    $value = trim((string) $value);
    if ($value === '' || $value === '00:00')
        return 0;

    $parts = explode(':', $value);
    if (count($parts) === 3) {
        return (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (int) $parts[2];
    } elseif (count($parts) === 2) {
        return (int) $parts[0] * 60 + (int) $parts[1];
    }
    return 0;
}

// --- Validation ---
// No campaign_id needed - teams come directly from the file
// The file itself contains all campaign/team information

if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
    jsonError('Archivo no válido');
}

$fileTmp = $_FILES['report_file']['tmp_name'];
$originalName = $_FILES['report_file']['name'] ?? 'upload.csv';

if (!is_uploaded_file($fileTmp)) {
    jsonError('Carga no permitida');
}

// --- Extract date from filename ---
$reportDate = null;
if (preg_match('/_(\d{4})(\d{2})(\d{2})[-_]/', $originalName, $matches)) {
    $reportDate = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
} else {
    jsonError("No se pudo extraer la fecha del archivo '{$originalName}'. El nombre debe contener la fecha en formato YYYYMMDD (ej: AST_team_performance_detail_20260228-101813.csv).");
}

// --- Read entire CSV ---
$handle = fopen($fileTmp, 'r');
if (!$handle) {
    jsonError('No se pudo leer el archivo');
}

// Detect BOM and delimiter
$firstLine = fgets($handle);
rewind($handle);
$firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
$delimiter = ',';
if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
    $delimiter = ';';
}

// --- Parse all teams and agents ---
$rows = [];
$currentTeamName = '';
$currentTeamId = '';
$inHeader = false; // Next row is a header row
$headerIndexes = null; // Column name -> index map for current team

// Grand totals for campaign_sales_reports backward compatibility
$grandCalls = 0;
$grandSales = 0;

$inserted = 0;
$updated = 0;
$skipped = 0;

// Prepare statements
$stmtAst = $pdo->prepare("
    INSERT INTO campaign_ast_performance
        (campaign_id, report_date, team_name, team_id, agent_name, agent_id,
         calls, leads, contacts, contact_ratio,
         nonpause_time_sec, system_time_sec, talk_time_sec,
         sales, sales_per_working_hour, sales_to_leads_ratio,
         sales_to_contacts_ratio, sales_per_hour,
         incomplete_sales, cancelled_sales, callbacks,
         first_call_resolution, avg_sale_time_sec, avg_contact_time_sec,
         is_team_totals, source_filename, uploaded_by)
    VALUES (?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        calls = VALUES(calls),
        leads = VALUES(leads),
        contacts = VALUES(contacts),
        contact_ratio = VALUES(contact_ratio),
        nonpause_time_sec = VALUES(nonpause_time_sec),
        system_time_sec = VALUES(system_time_sec),
        talk_time_sec = VALUES(talk_time_sec),
        sales = VALUES(sales),
        sales_per_working_hour = VALUES(sales_per_working_hour),
        sales_to_leads_ratio = VALUES(sales_to_leads_ratio),
        sales_to_contacts_ratio = VALUES(sales_to_contacts_ratio),
        sales_per_hour = VALUES(sales_per_hour),
        incomplete_sales = VALUES(incomplete_sales),
        cancelled_sales = VALUES(cancelled_sales),
        callbacks = VALUES(callbacks),
        first_call_resolution = VALUES(first_call_resolution),
        avg_sale_time_sec = VALUES(avg_sale_time_sec),
        avg_contact_time_sec = VALUES(avg_contact_time_sec),
        is_team_totals = VALUES(is_team_totals),
        source_filename = VALUES(source_filename),
        uploaded_by = VALUES(uploaded_by)
");

// Delete existing data for this report_date to avoid duplicates
// We delete by report_date only since teams come from the file itself
$pdo->prepare("DELETE FROM campaign_ast_performance WHERE report_date = ?")
    ->execute([$reportDate]);

$lineNum = 0;
$inCallCenterTotal = false;
$expectedColumns = [
    'Agent Name', 'Agent ID', 'Calls', 'Leads', 'Contacts', 'Contact Ratio',
    'Nonpause Time', 'System Time', 'Talk Time', 'Sales', 'Sales per Working Hour',
    'Sales to Leads Ratio', 'Sales to Contacts Ratio', 'Sales Per Hour',
    'Incomplete Sales', 'Cancelled Sales', 'Callbacks', 'First Call Resolution',
    'Average Sale Time', 'Average Contact Time'
];
$foundColumns = [];
$missingColumns = [];
$teamsProcessed = []; // Track teams found in the file

while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    $lineNum++;
    if (empty($row) || (count($row) === 1 && ($row[0] === null || trim($row[0]) === ''))) {
        continue;
    }

    // Detect team header: "",  "TEAM: ADMIN - VICIDIAL ADMINISTRATORS"
    if (isset($row[1]) && preg_match('/^TEAM:\s*(\S+)\s*-\s*(.+)$/i', trim($row[1]), $teamMatch)) {
        $currentTeamId = trim($teamMatch[1]);
        $currentTeamName = trim($teamMatch[2]);
        $teamsProcessed[$currentTeamId] = $currentTeamName;
        $inHeader = true; // Next non-empty row will be the column headers
        $headerIndexes = null;
        continue;
    }

    // Detect "CALL CENTER TOTAL" section
    if (isset($row[1]) && trim($row[1]) === 'CALL CENTER TOTAL') {
        $inCallCenterTotal = true;
        $currentTeamName = 'CALL CENTER TOTAL';
        $currentTeamId = 'TOTAL';
        $teamsProcessed[$currentTeamId] = $currentTeamName;
        $inHeader = true;
        $headerIndexes = null;
        continue;
    }

    // Detect "NO AGENTS" message
    if (isset($row[1]) && strpos($row[1], 'NO AGENTS CURRENTLY') !== false) {
        $currentTeamName = '';
        $currentTeamId = '';
        continue;
    }

    // If we're expecting a header row, parse it
    if ($inHeader && isset($row[1]) && trim($row[1]) === 'Agent Name') {
        // This is the agent-level header row
        $headerIndexes = [];
        foreach ($row as $idx => $colName) {
            $colName = trim($colName);
            if ($colName !== '') {
                $headerIndexes[$colName] = $idx;
                $foundColumns[] = $colName;
            }
        }
        // Check for missing columns
        foreach ($expectedColumns as $expected) {
            if (!isset($headerIndexes[$expected])) {
                $missingColumns[] = $expected;
            }
        }
        $inHeader = false;
        continue;
    }

    // For CALL CENTER TOTAL section, header uses "Team Name" instead of "Agent Name"
    if ($inHeader && isset($row[1]) && trim($row[1]) === 'Team Name') {
        $headerIndexes = [];
        foreach ($row as $idx => $colName) {
            $colName = trim($colName);
            if ($colName !== '') {
                $headerIndexes[$colName] = $idx;
                if (!in_array($colName, $foundColumns)) {
                    $foundColumns[] = $colName;
                }
            }
        }
        // Check for missing columns (update list)
        $missingColumns = [];
        foreach ($expectedColumns as $expected) {
            if (!isset($headerIndexes[$expected]) && $expected !== 'Agent Name' && $expected !== 'Agent ID') {
                $missingColumns[] = $expected;
            }
        }
        $inHeader = false;
        continue;
    }

    // Skip rows if we don't have headers parsed yet
    if ($headerIndexes === null || $currentTeamName === '') {
        continue;
    }

    // Check if this is a data row (has valid columns)
    $col2 = isset($row[2]) ? trim($row[2]) : '';

    // TOTALS row for a team
    $isTotals = ($col2 === 'TOTALS:');

    // Regular agent row: first column is a number (row index)
    $isAgentRow = isset($row[0]) && is_numeric(trim($row[0]));

    if (!$isAgentRow && !$isTotals) {
        continue;
    }

    // Map columns by header
    $agentName = '';
    $agentId = '';

    if ($isTotals) {
        $agentName = 'TOTALS';
        $agentId = 'TOTALS_' . $currentTeamId;
    } else {
        // For agent rows
        if (isset($headerIndexes['Agent Name'])) {
            $agentName = trim($row[$headerIndexes['Agent Name']] ?? '');
        } elseif (isset($headerIndexes['Team Name'])) {
            $agentName = trim($row[$headerIndexes['Team Name']] ?? '');
        }
        if (isset($headerIndexes['Agent ID'])) {
            $agentId = trim($row[$headerIndexes['Agent ID']] ?? '');
        } elseif (isset($headerIndexes['Team ID'])) {
            $agentId = trim($row[$headerIndexes['Team ID']] ?? '');
        }
    }

    if ($agentId === '') {
        $skipped++;
        continue;
    }

    // Extract all metrics
    $calls = parseIntVal($row[$headerIndexes['Calls'] ?? 999] ?? 0);
    $leads = parseIntVal($row[$headerIndexes['Leads'] ?? 999] ?? 0);
    $contacts = parseIntVal($row[$headerIndexes['Contacts'] ?? 999] ?? 0);
    $contactRatio = parseNumber($row[$headerIndexes['Contact Ratio'] ?? 999] ?? 0);
    $nonpauseTimeSec = timeToSeconds($row[$headerIndexes['Nonpause Time'] ?? 999] ?? '');
    $systemTimeSec = timeToSeconds($row[$headerIndexes['System Time'] ?? 999] ?? '');
    $talkTimeSec = timeToSeconds($row[$headerIndexes['Talk Time'] ?? 999] ?? '');
    $sales = parseIntVal($row[$headerIndexes['Sales'] ?? 999] ?? 0);
    $salesPerWorkingHour = parseNumber($row[$headerIndexes['Sales per Working Hour'] ?? 999] ?? 0);
    $salesToLeadsRatio = parseNumber($row[$headerIndexes['Sales to Leads Ratio'] ?? 999] ?? 0);
    $salesToContactsRatio = parseNumber($row[$headerIndexes['Sales to Contacts Ratio'] ?? 999] ?? 0);
    $salesPerHour = parseNumber($row[$headerIndexes['Sales Per Hour'] ?? 999] ?? 0);
    $incompleteSales = parseIntVal($row[$headerIndexes['Incomplete Sales'] ?? 999] ?? 0);
    $cancelledSales = parseIntVal($row[$headerIndexes['Cancelled Sales'] ?? 999] ?? 0);
    $callbacks = parseIntVal($row[$headerIndexes['Callbacks'] ?? 999] ?? 0);
    $fcr = parseNumber($row[$headerIndexes['First Call Resolution'] ?? 999] ?? 0);
    $avgSaleTimeSec = timeToSeconds($row[$headerIndexes['Average Sale Time'] ?? 999] ?? '');
    $avgContactTimeSec = timeToSeconds($row[$headerIndexes['Average Contact Time'] ?? 999] ?? '');

    $isTeamTotals = $isTotals ? 1 : 0;

    // Track grand totals from CALL CENTER TOTAL section
    if ($inCallCenterTotal && $isTotals) {
        $grandCalls = $calls;
        $grandSales = $sales;
    }

    try {
        $stmtAst->execute([
            null, // campaign_id is NULL - we use team_id instead
            $reportDate,
            $currentTeamName,
            $currentTeamId,
            $agentName,
            $agentId,
            $calls,
            $leads,
            $contacts,
            $contactRatio,
            $nonpauseTimeSec,
            $systemTimeSec,
            $talkTimeSec,
            $sales,
            $salesPerWorkingHour,
            $salesToLeadsRatio,
            $salesToContactsRatio,
            $salesPerHour,
            $incompleteSales,
            $cancelledSales,
            $callbacks,
            $fcr,
            $avgSaleTimeSec,
            $avgContactTimeSec,
            $isTeamTotals,
            $originalName,
            $_SESSION['user_id'] ?? null
        ]);

        $affected = $stmtAst->rowCount();
        if ($affected === 1) {
            $inserted++;
        } elseif ($affected === 2) {
            $updated++;
        } else {
            $skipped++;
        }
    } catch (PDOException $e) {
        $skipped++;
    }
}

fclose($handle);

echo json_encode([
    'success' => true,
    'inserted' => $inserted,
    'updated' => $updated,
    'skipped' => $skipped,
    'report_date' => $reportDate,
    'grand_calls' => $grandCalls,
    'grand_sales' => $grandSales,
    'teams_found' => $teamsProcessed,
    'teams_count' => count($teamsProcessed),
    'columns_found' => array_unique($foundColumns),
    'columns_missing' => array_unique($missingColumns),
    'warning' => !empty($missingColumns) ? 'Algunas columnas no se encontraron en el archivo. Se usaron valores por defecto.' : null
]);
