<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/work_hours_calculator.php';

// Minimal self-contained assertions (no phpunit dependency)
function assertEqual($expected, $actual, $label) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: $label\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "PASS: $label\n";
}

// Paid slugs (already normalized by system)
$paid = ['DISPONIBLE', 'WASAPI', 'DIGITACION'];

// Case 1: Paid -> Break -> Paid (deltas attributed to current state)
$punches = [
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:00:00'],
    ['type' => 'BREAK',      'timestamp' => '2025-12-01 09:30:00'],
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:45:00'],
    ['type' => 'EXIT',       'timestamp' => '2025-12-01 10:15:00'],
];
$res = calculateWorkSecondsFromPunches($punches, $paid);
// Paid time: 09:00->09:30 (1800) + 09:45->10:15 (1800) = 3600
assertEqual(3600, $res['work_seconds'], 'paid with break in-between');

// Case 2: Only paid punches, ensure we count between paid and next paid
$punches2 = [
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:00:00'],
    ['type' => 'WASAPI',     'timestamp' => '2025-12-01 10:00:00'],
    ['type' => 'EXIT',       'timestamp' => '2025-12-01 11:00:00'],
];
$res2 = calculateWorkSecondsFromPunches($punches2, $paid);
// 09:00->10:00 attributed to DISPONIBLE (paid) = 3600
// 10:00->11:00 attributed to WASAPI (paid) = 3600
assertEqual(7200, $res2['work_seconds'], 'consecutive paid types');

echo "All tests passed.\n";
