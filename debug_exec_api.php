<?php
// debug_exec_api.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
// Simulate a logged-in user if needed, or just bypass permission check for debugging
// For this test, we'll assume we are running it from CLI or browser where we might need to mock session
// But since we are running via tool, we can just include db and run the logic.

require_once 'db.php';

echo "Testing DB Connection... ";
if ($pdo) {
    echo "OK\n";
} else {
    echo "FAILED\n";
    exit;
}

// Mock parameters
$startDate = date('Y-m-d');
$endDate = date('Y-m-d');

echo "Testing Date Range: $startDate to $endDate\n";

try {
    // 1. Check Paid Types
    echo "Fetching Paid Types...\n";
    $stmt = $pdo->query("SELECT slug FROM attendance_types WHERE is_paid = 1");
    $paidTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Paid Types: " . implode(', ', $paidTypes) . "\n";

    // 2. Check Employees Query
    echo "Executing Main Query...\n";
    $query = "
        SELECT 
            e.id as employee_id,
            u.username,
            e.employment_status,
            u.is_active
        FROM employees e
        INNER JOIN users u ON u.id = e.user_id
        WHERE (e.employment_status IN ('ACTIVE', 'TRIAL') OR 
               EXISTS (SELECT 1 FROM attendance a3 WHERE a3.user_id = u.id AND DATE(a3.timestamp) BETWEEN ? AND ?))
        AND u.is_active = 1
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$startDate, $endDate]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($employees) . " employees.\n";
    if (count($employees) > 0) {
        echo "First employee: " . print_r($employees[0], true) . "\n";
    }

    // 3. Test API Logic (Copy-paste relevant parts or include)
    // We'll just verify the query works for now.

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
?>
