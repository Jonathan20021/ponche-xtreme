<?php
// Enable ALL error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Log to a file we can check
$logFile = __DIR__ . '/debug_log.txt';
file_put_contents($logFile, "=== NEW REQUEST ===\n", FILE_APPEND);
file_put_contents($logFile, date('Y-m-d H:i:s') . "\n", FILE_APPEND);
file_put_contents($logFile, "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n", FILE_APPEND);

session_start();
file_put_contents($logFile, "Session started\n", FILE_APPEND);
file_put_contents($logFile, "User ID: " . ($_SESSION['user_id'] ?? 'NONE') . "\n", FILE_APPEND);

require_once '../db.php';
file_put_contents($logFile, "DB loaded\n", FILE_APPEND);

// Check permission
if (!isset($_SESSION['user_id'])) {
    file_put_contents($logFile, "ERROR: No session user_id\n", FILE_APPEND);
    die("No session");
}

$hasPermission = userHasPermission('hr_employees');
file_put_contents($logFile, "Has permission: " . ($hasPermission ? 'YES' : 'NO') . "\n", FILE_APPEND);

if (!$hasPermission) {
    file_put_contents($logFile, "ERROR: No permission, redirecting\n", FILE_APPEND);
    header('Location: ../unauthorized.php?section=hr_employees');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_put_contents($logFile, "ERROR: Not POST, redirecting to contracts.php\n", FILE_APPEND);
    header('Location: contracts.php');
    exit;
}

file_put_contents($logFile, "POST Data:\n" . print_r($_POST, true) . "\n", FILE_APPEND);

// Validate required fields
$requiredFields = ['employee_name', 'id_card', 'province', 'position', 'salary', 'work_schedule', 'contract_date', 'city'];
foreach ($requiredFields as $field) {
    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
        file_put_contents($logFile, "ERROR: Missing field: $field\n", FILE_APPEND);
        die("Missing field: $field");
    }
}

file_put_contents($logFile, "All fields validated\n", FILE_APPEND);

// Get data
$employeeName = trim($_POST['employee_name']);
$idCard = trim($_POST['id_card']);
$province = trim($_POST['province']);
$position = trim($_POST['position']);
$salary = (float)$_POST['salary'];
$workSchedule = trim($_POST['work_schedule']);
$contractDate = $_POST['contract_date'];
$city = trim($_POST['city']);
$action = $_POST['action'] ?? 'employment';

file_put_contents($logFile, "Action: $action\n", FILE_APPEND);

// Check if action requires redirect
if ($action === 'confidentiality') {
    file_put_contents($logFile, "Action is confidentiality, would redirect\n", FILE_APPEND);
} elseif ($action === 'both') {
    file_put_contents($logFile, "Action is both, would redirect\n", FILE_APPEND);
}

file_put_contents($logFile, "Should generate PDF now...\n", FILE_APPEND);

// If we get here, PDF generation should happen
echo "<!DOCTYPE html><html><body>";
echo "<h1>Debug Complete</h1>";
echo "<p>Check the file: <code>" . $logFile . "</code></p>";
echo "<p>If you see this, the script ran without redirecting.</p>";
echo "<p>Action: " . htmlspecialchars($action) . "</p>";
echo "<p>Employee: " . htmlspecialchars($employeeName) . "</p>";
echo "</body></html>";

file_put_contents($logFile, "Script completed without errors\n\n", FILE_APPEND);
?>
