<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure the user is authenticated
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Define the Wasapi API token
define('WASAPI_TOKEN', '338529|NeQrFHvdJ3lX6O2Hs26QPjc0IyrgzKFxQGwVcvCM0575a229');
define('WASAPI_BASE_URL', 'https://api.wasapi.io/prod/api/v1/');

// Get the requested endpoint
$endpoint = $_GET['endpoint'] ?? '';

// Basic validation to prevent arbitrary requests
$allowed_endpoints = [
    'dashboard/metrics/online-agents',
    'dashboard/metrics/total-campaigns',
    'dashboard/metrics/consolidated-conversations',
    'dashboard/metrics/agent-conversations',
    'dashboard/metrics/contacts',
    'dashboard/metrics/messages',
    'dashboard/metrics/messages-bot',
    'metrics',
    'reports/performance-by-agent',
    'reports/volume-of-workflow'
];

$is_allowed = false;
foreach ($allowed_endpoints as $allowed) {
    if (strpos($endpoint, $allowed) === 0) {
        $is_allowed = true;
        break;
    }
}

if (!$is_allowed) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or unauthorized endpoint']);
    exit;
}

// Build the target URL (append any extra query parameters from the frontend)
$queryParams = $_GET;
unset($queryParams['endpoint']); // Remove the routing parameter
$queryString = http_build_query($queryParams);

$targetUrl = WASAPI_BASE_URL . ltrim($endpoint, '/');
if (!empty($queryString)) {
    $targetUrl .= '?' . $queryString;
}

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . WASAPI_TOKEN,
    'Accept: application/json',
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local dev environments if needed

// Execute the request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Set response headers and return data
header('Content-Type: application/json');

if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL error: ' . $error]);
} else {
    http_response_code($httpCode);
    echo $response;
}
