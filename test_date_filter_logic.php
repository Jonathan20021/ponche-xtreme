<?php

function test_logic($date_filter) {
    echo "Testing input: '$date_filter'\n";
    
    // Original Logic Simulation
    $dateValues = [];
    $datePlaceholders = '';
    
    if ($date_filter) {
        $dateValues = array_values(array_filter(array_map('trim', explode(',', $date_filter))));
        if (!empty($dateValues)) {
            $datePlaceholders = implode(',', array_fill(0, count($dateValues), '?'));
        }
    }
    
    echo "Original Logic Result:\n";
    echo "  Values: " . json_encode($dateValues) . "\n";
    echo "  Placeholders: '$datePlaceholders'\n";
    
    // Proposed Fix Logic Simulation
    $isRange = false;
    $rangeStart = null;
    $rangeEnd = null;
    $dateValuesNew = [];
    $datePlaceholdersNew = '';
    
    if ($date_filter) {
        if (strpos($date_filter, ' - ') !== false) {
            $parts = explode(' - ', $date_filter);
            if (count($parts) === 2) {
                $isRange = true;
                $rangeStart = trim($parts[0]);
                $rangeEnd = trim($parts[1]);
            }
        }
        
        if (!$isRange) {
             $dateValuesNew = array_values(array_filter(array_map('trim', explode(',', $date_filter))));
            if (!empty($dateValuesNew)) {
                $datePlaceholdersNew = implode(',', array_fill(0, count($dateValuesNew), '?'));
            }
        }
    }
    
    echo "Proposed Logic Result:\n";
    if ($isRange) {
        echo "  Range Detected: Yes\n";
        echo "  Start: $rangeStart\n";
        echo "  End: $rangeEnd\n";
    } else {
        echo "  Range Detected: No\n";
        echo "  Values: " . json_encode($dateValuesNew) . "\n";
        echo "  Placeholders: '$datePlaceholdersNew'\n";
    }
    echo "--------------------------------------------------\n";
}

test_logic("2025-12-14 - 2025-12-14");
test_logic("2025-12-14,2025-12-15");
test_logic("2025-12-14");
