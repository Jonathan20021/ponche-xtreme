<?php
require_once 'db.php';

echo "=== VOICE AI DIAGNOSTIC ===\n";

// Check if voice_ai configuration exists
$config = $pdo->query('SELECT * FROM system_settings WHERE setting_key LIKE "%voice_ai%"')->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== VOICE AI CONFIG ===\n";
foreach ($config as $item) {
    $val = $item['setting_value'];
    if (strlen($val) > 60) {
        $val = substr($val, 0, 60) . "...";
    }
    echo $item['setting_key'] . ': ' . $val . "\n";
}

// Check if GHL integration exists
$integrations = $pdo->query('SELECT * FROM ghl_integrations LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== GHL INTEGRATIONS ===\n";
echo 'Found ' . count($integrations) . " integration(s)\n";
foreach ($integrations as $int) {
    echo '- ' . $int['integration_name'] . ' (ID: ' . $int['location_id'] . ")\n";
    echo '  API Key: ' . (strlen($int['api_key']) > 50 ? 'SET' : 'EMPTY') . "\n";
    echo '  Status: ' . ($int['is_default'] ? 'DEFAULT' : 'SECONDARY') . "\n";
}

echo "\n";
?>
