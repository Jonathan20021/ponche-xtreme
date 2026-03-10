<?php
// Start session first
session_start();

// Simulate authenticated user
$_SESSION['user_id'] = 1;
$_GET['action'] = 'inbound_metrics';
$_GET['start_date'] = '2026-02-01';
$_GET['end_date'] = '2026-02-24';

// Capture output
ob_start();
require __DIR__ . '/api/wfm_planning.php';
$output = ob_get_clean();

echo "=== API RESPONSE ===\n";
echo $output;
echo "\n=== END ===\n";

// Try to parse as JSON
$json = json_decode($output, true);
if ($json) {
    echo "\n=== PARSED JSON ===\n";
    echo "Success: " . ($json['success'] ? 'true' : 'false') . "\n";
    if (isset($json['totals'])) {
        echo "Totals count: " . count($json['totals']) . "\n";
    }
    if (isset($json['daily'])) {
        echo "Daily count: " . count($json['daily']) . "\n";
    }
    if (isset($json['intraday'])) {
        echo "Intraday campaigns: " . count($json['intraday']) . "\n";
    }
}
