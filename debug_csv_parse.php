<?php
require_once __DIR__ . '/db.php';

function normalizeHeader($header)
{
    $header = strtolower(trim((string) $header));
    $header = str_replace([' ', '-', '.'], '_', $header);
    $header = preg_replace('/[^a-z0-9_]/', '', $header);
    return $header;
}

$file = __DIR__ . '/Inbound_Daily_Report_20260225-213638 (1).csv';
$handle = fopen($file, 'r');

if (!$handle) {
    die("No se pudo abrir el archivo\n");
}

echo "=== LEYENDO ARCHIVO CSV ===\n\n";

$lineNumber = 0;
$headerLine1 = null;
$headerLine2 = null;
$headerLine3 = null;
$columnMap = [];

while (($row = fgetcsv($handle, 0, ',')) !== false && $lineNumber < 15) {
    $lineNumber++;
    $firstCell = trim((string) ($row[0] ?? ''));
    $firstNorm = normalizeHeader($firstCell);
    
    echo "Línea $lineNumber: ";
    echo "cols=" . count($row);
    
    // Detect header rows
    if ($firstCell === '' && count($row) >= 10 && !$headerLine1) {
        echo " --> HEADER LINE 1\n";
        $headerLine1 = $row;
        continue;
    }
    
    if ($firstCell === '' && count($row) >= 10 && $headerLine1 && !$headerLine2) {
        echo " --> HEADER LINE 2\n";
        $headerLine2 = $row;
        continue;
    }
    
    if (($firstNorm === 'shift_date_time_range' || $firstNorm === 'shift_datetime_range') && $headerLine1 && $headerLine2) {
        echo " --> HEADER LINE 3 *** FORMAT DETECTED ***\n";
        $headerLine3 = $row;
        
        echo "\n=== COMBINED HEADERS ===\n";
        for ($idx = 0; $idx < count($headerLine3); $idx++) {
            $h1 = normalizeHeader($headerLine1[$idx] ?? '');
            $h2 = normalizeHeader($headerLine2[$idx] ?? '');
            $h3 = normalizeHeader($headerLine3[$idx] ?? '');
            
            $parts = array_filter([$h1, $h2, $h3], function($p) { return $p !== ''; });
            $combinedHeader = implode('_', $parts);
            
            echo "  [$idx] $combinedHeader\n";
        }
        
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
        
        for ($idx = 0; $idx < count($headerLine3); $idx++) {
            $h1 = normalizeHeader($headerLine1[$idx] ?? '');
            $h2 = normalizeHeader($headerLine2[$idx] ?? '');
            $h3 = normalizeHeader($headerLine3[$idx] ?? '');
            
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
        
        echo "\n=== COLUMN MAP ===\n";
        foreach ($columnMap as $key => $idx) {
            echo "  $key => column $idx\n";
        }
        echo "\n";
        continue;
    }
    
    if ($lineNumber === 2 && stripos($firstCell, 'Selected in-groups') !== false) {
        $campaignStr = trim(str_replace(['Selected in-groups:', 'Selected in-groups'], '', $firstCell));
        $parts = explode(' - ', $campaignStr);
        $campaignName = trim($parts[0]);
        echo " --> CAMPAIGN: '$campaignName'\n";
        continue;
    }
    
    echo "\n";
}

fclose($handle);

echo "=== FIN ===\n";

