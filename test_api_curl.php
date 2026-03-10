<?php
require_once __DIR__ . '/db.php';

session_start();

echo "=== TESTING API DIRECT ===\n\n";

// Get or create a test user with admin permissions
$userStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "⚠️ No hay usuarios admin en el sistema\n";
    // Try to find any user
    $userStmt = $pdo->query("SELECT id, role FROM users LIMIT 1");
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        echo "Usando usuario ID: {$user['id']} (role: {$user['role']})\n";
    } else {
        echo "❌ No hay usuarios en el sistema\n";
        exit;
    }
} else {
    echo "✓ Usuario admin encontrado: ID {$user['id']}\n";
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = 'admin';

// Build API request URL
$url = 'http://localhost/ponche-xtreme/api/wfm_planning.php?action=inbound_metrics&start_date=2026-02-01&end_date=2026-02-24';

echo "URL: $url\n\n";

// Use curl to make the request with session
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response:\n";
echo $response;
echo "\n\n";

$json = json_decode($response, true);
if ($json) {
    echo "=== PARSED RESPONSE ===\n";
    echo "Success: " . ($json['success'] ? 'YES' : 'NO') . "\n";
    
    if ($json['success']) {
        echo "Totals: " . (isset($json['totals']) ? count($json['totals']) : 0) . " campaigns\n";
        echo "Daily: " . (isset($json['daily']) ? count($json['daily']) : 0) . " rows\n";
        echo "Intraday: " . (isset($json['intraday']) ? count($json['intraday']) : 0) . " campaigns\n";
        
        if (isset($json['totals']) && count($json['totals']) > 0) {
            echo "\nFirst campaign total:\n";
            print_r($json['totals'][0]);
        }
    } else {
        echo "Error: " . ($json['error'] ?? 'Unknown') . "\n";
    }
}

echo "\n=== FIN ===\n";
