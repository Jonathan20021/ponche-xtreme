<?php
require_once 'db.php';
require_once 'hr/payroll_functions.php';

$employeeCode = 'EMP-2025-0033';

try {
    // 1. Get employee and user data
    $stmt = $pdo->prepare("
        SELECT e.id as employee_id, e.first_name, e.last_name, e.employee_code,
               u.id as user_id, u.username, u.hourly_rate, u.monthly_salary, 
               u.hourly_rate_dop, u.monthly_salary_dop, u.daily_salary_usd, u.daily_salary_dop,
               u.preferred_currency, u.compensation_type, u.role
        FROM employees e
        JOIN users u ON u.id = e.user_id
        WHERE e.employee_code = ?
    ");
    $stmt->execute([$employeeCode]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "--- Employee Data ---\n";
    print_r($employee);

    if ($employee) {
        $employeeId = $employee['employee_id'];

        // 2. Get payroll records for this employee
        $stmt = $pdo->prepare("
            SELECT pr.*, pp.name as period_name, pp.start_date, pp.end_date
            FROM payroll_records pr
            JOIN payroll_periods pp ON pp.id = pr.payroll_period_id
            WHERE pr.employee_id = ?
            ORDER BY pr.id DESC
            LIMIT 5
        ");
        $stmt->execute([$employeeId]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "\n--- Payroll Records ---\n";
        print_r($records);

        // 3. Check for bonuses or commissions
        // I don't see a bonuses table yet, let's look for one
        echo "\n--- Checking for other income tables ---\n";
        $tables = $pdo->query("SHOW TABLES LIKE '%income%'")->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_merge($tables, $pdo->query("SHOW TABLES LIKE '%bonus%'")->fetchAll(PDO::FETCH_COLUMN));
        $tables = array_merge($tables, $pdo->query("SHOW TABLES LIKE '%commission%'")->fetchAll(PDO::FETCH_COLUMN));
        print_r($tables);

        foreach ($tables as $table) {
            echo "\nData from $table:\n";
            try {
                $stmt = $pdo->prepare("SELECT * FROM $table WHERE employee_id = ? OR user_id = ?");
                $stmt->execute([$employeeId, $employee['user_id']]);
                print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {
                echo "Error reading $table: " . $e->getMessage() . "\n";
            }
        }
    } else {
        echo "Employee not found.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
