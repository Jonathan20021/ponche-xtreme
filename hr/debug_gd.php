<?php
$logFile = __DIR__ . '/debug_gd.log';
$status = "--- Debug GD Status ---\n";
$status .= "Date: " . date('Y-m-d H:i:s') . "\n";
$status .= "GD Loaded: " . (extension_loaded('gd') ? 'YES' : 'NO') . "\n";
$logoPath = dirname(__DIR__) . '/assets/logo.png';
$status .= "Logo Path: " . $logoPath . "\n";
$status .= "Logo Exists: " . (file_exists($logoPath) ? 'YES' : 'NO') . "\n";
if (file_exists($logoPath)) {
    $data = file_get_contents($logoPath);
    $status .= "Logo Size: " . strlen($data) . " bytes\n";
    $status .= "Base64 Length: " . strlen(base64_encode($data)) . "\n";
}
if (extension_loaded('gd')) {
    $info = gd_info();
    $status .= "GD Version: " . $info['GD Version'] . "\n";
    $status .= "PNG Support: " . ($info['PNG Support'] ? 'YES' : 'NO') . "\n";
}
file_put_contents($logFile, $status, FILE_APPEND);
echo "Debug info written to $logFile";
?>