<?php
require_once __DIR__ . '/db.php';

// Test uploading the CSV file
$_SESSION['user_id'] = 1; // Simulate logged in user

$_FILES['report_file'] = [
    'tmp_name' => __DIR__ . '/Inbound_Daily_Report_20260225-213638 (1).csv',
    'name' => 'Inbound_Daily_Report_20260225-213638 (1).csv',
    'error' => UPLOAD_ERR_OK
];

$_POST['campaign_id'] = 0; // Not needed since auto-detected

echo "=== TESTING CSV UPLOAD ===\n\n";

// Include the campaign_staffing.php logic
// For testing, we'll extract and run the core logic

function normalizeHeader($header)
{
    $header = strtolower(trim((string) $header));
    $header = str_replace([' ', '-', '.'], '_', $header);
    $header = preg_replace('/[^a-z0-9_]/', '', $header);
    return $header;
}

function parseNumber($value)
{
    if ($value === null) {
        return 0;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $value = str_replace(['$', ',', ' ', '%'], ['', '', '', ''], $value);
    return is_numeric($value) ? (float) $value : 0;
}

function timeStrToSeconds($str)
{
    $str = trim(str_replace('"', '', (string) $str));
    if ($str === '') {
        return 0;
    }
    $parts = explode(':', $str);
    if (count($parts) === 3) {
        return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
    }
    if (count($parts) === 2) {
        return ((int) $parts[0] * 60) + (int) $parts[1];
    }
    return 0;
}

$file = __DIR__ . '/Inbound_Daily_Report_20260225-213638 (1).csv';
$handle = fopen($file, 'r');

$lineNumber = 0;
$headerLine1 = null;
$headerLine2 = null;
$format = null;
$campaignName = null;
$columnMap = [];
$inDataSection = false;
$dataCount = 0;

while (($row = fgetcsv($handle, 0, ',')) !== false) {
    $lineNumber++;
    $firstCell = trim((string) ($row[0] ?? ''));
    $firstNorm = normalizeHeader($firstCell);
    
    // Extract campaign
    if ($lineNumber === 2 && stripos($firstCell, 'Selected in-groups') !== false) {
        $campaignStr = trim(str_replace(['Selected in-groups:', 'Selected in-groups'], '', $firstCell));
        if (!empty($campaignStr)) {
            $parts = explode(' - ', $campaignStr);
            $campaignName = trim($parts[0]);
            echo "Campaña detectada: '$campaignName'\n";
        }
    }
    
    if ($format === null) {
        // Detect header rows
        if ($firstCell === '' && count($row) >= 10 && !$headerLine1) {
            $headerLine1 = $row;
            continue;
        }
        
        if ($firstCell === '' && count($row) >= 10 && $headerLine1 && !$headerLine2) {
            $headerLine2 = $row;
            continue;
        }
        
        if (($firstNorm === 'shift_date_time_range' || $firstNorm === 'shift_datetime_range') && $headerLine1 && $headerLine2) {
            $format = 'inbound_daily_report';
            echo "Formato detectado: '$format'\n";
            
            // Build column map
            $expectedColumns = [
                'offered' => ['total_calls_offered', 'calls_offered', 'offered'],
                'answered' => ['total_calls_answered', 'calls_answered'],
                'agents_answered' => ['total_agents_answered', 'agents_answered'],
                'abandoned' => ['total_calls_abandoned', 'calls_abandoned', 'abandoned'],
                'abandon_percent' => ['total_abandon_percent', 'abandon_percent'],
                'avg_abandon_time' => ['avg_abandon_time', 'abandon_time'],
                'avg_answer_speed' => ['avg_answer_speed', 'answer_speed'],
                'avg_talk_time' => ['avg_talk_time'],
                'total_talk_time' => ['total_talk_time'],
                'total_wrap_time' => ['total_wrap_time'],
                'total_call_time' => ['total_call_time']
            ];
            
            for ($idx = 0; $idx < count($row); $idx++) {
                $h1 = normalizeHeader($headerLine1[$idx] ?? '');
                $h2 = normalizeHeader($headerLine2[$idx] ?? '');
                $h3 = normalizeHeader($row[$idx] ?? '');
                
                $parts = array_filter([$h1, $h2, $h3], function($p) { return $p !== ''; });
                $combinedHeader = implode('_', $parts);
                
                foreach ($expectedColumns as $key => $patterns) {
                    foreach ($patterns as $pattern) {
                        if (stripos($combinedHeader, $pattern) !== false) {
                            $columnMap[$key] = $idx;
                            break 2;
                        }
                    }
                }
            }
            
            echo "Columnas mapeadas: " . count($columnMap) . "\n";
            print_r($columnMap);
            
            $inDataSection = true;
            continue;
        }
    }
    
    if (!$inDataSection || $firstCell === '') {
        continue;
    }
    
    // Try parsing data row
    if ($format === 'inbound_daily_report' && preg_match('/^\d{4}-\d{2}-\d{2}/', $firstCell)) {
        $dataCount++;
        
        if ($dataCount <= 3) {
            echo "\nFila de datos $dataCount:\n";
            echo "  Intervalo: $firstCell\n";
            echo "  Offered: " . (int) round(parseNumber($row[$columnMap['offered'] ?? 1] ?? 0)) . "\n";
            echo "  Answered: " . (int) round(parseNumber($row[$columnMap['answered'] ?? 2] ?? 0)) . "\n";
            echo "  Abandoned: " . (int) round(parseNumber($row[$columnMap['abandoned'] ?? 4] ?? 0)) . "\n";
        }
    }
    
    if ($lineNumber > 20) break;
}

fclose($handle);

echo "\n\nTotal filas de datos procesadas: $dataCount\n";
echo "\n=== TEST COMPLETADO ===\n";
