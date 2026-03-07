<?php
// Test API without session
define('WASAPI_TOKEN', '338529|NeQrFHvdJ3lX6O2Hs26QPjc0IyrgzKFxQGwVcvCM0575a229');
define('WASAPI_BASE_URL', 'https://api.wasapi.io/prod/api/v1/');

$agentId = 26508;
$startDate = '2026-03-06';
$endDate = '2026-03-06';

$url = WASAPI_BASE_URL . "reports/performance-by-agent?start_date={$startDate}&end_date={$endDate}";

echo "Testing: $url\n\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . WASAPI_TOKEN,
    'Accept: application/json',
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Error: $error\n\n";

$data = json_decode($response, true);
echo "Agent 26508 data:\n";

$allAgents = $data['data'] ?? [];
foreach ($allAgents as $record) {
    $recordAgentId = $record['agent_id'] ?? ($record['agent']['id'] ?? null);
    if ($recordAgentId == $agentId) {
        print_r($record);
    }
}
?>
