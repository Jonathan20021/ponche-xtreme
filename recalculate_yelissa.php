<?php
require_once 'db.php';
require_once 'hr/payroll_functions.php';

$employeeId = 32; // Yelissa Mezon Minaya

try {
    // Get all records for this employee
    $stmt = $pdo->prepare("SELECT pr.*, pp.id as period_id FROM payroll_records pr JOIN payroll_periods pp ON pp.id = pr.payroll_period_id WHERE pr.employee_id = ?");
    $stmt->execute([$employeeId]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($records) . " records to recalculate.\n";

    foreach ($records as $record) {
        $periodId = $record['period_id'];
        $payrollRecordId = $record['id'];

        echo "Recalculating Record ID $payrollRecordId (Period $periodId)...\n";

        $hoursData = [
            'regular_hours' => $record['regular_hours'],
            'overtime_hours' => $record['overtime_hours'],
            'total_hours' => $record['total_hours'],
            'bonuses' => $record['bonuses'],
            'commissions' => $record['commissions'],
            'other_income' => $record['other_income']
        ];

        $payrollData = calculateEmployeePayroll($pdo, $employeeId, $periodId, $hoursData);

        if ($payrollData) {
            $updateStmt = $pdo->prepare("
                UPDATE payroll_records SET
                    base_salary = ?,
                    overtime_amount = ?,
                    gross_salary = ?,
                    afp_employee = ?,
                    sfs_employee = ?,
                    isr = ?,
                    total_deductions = ?,
                    afp_employer = ?,
                    sfs_employer = ?,
                    srl_employer = ?,
                    infotep_employer = ?,
                    total_employer_contributions = ?,
                    net_salary = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $updateStmt->execute([
                $payrollData['base_salary'],
                $payrollData['overtime_amount'],
                $payrollData['gross_salary'],
                $payrollData['afp_employee'],
                $payrollData['sfs_employee'],
                $payrollData['isr'],
                $payrollData['total_deductions'],
                $payrollData['afp_employer'],
                $payrollData['sfs_employer'],
                $payrollData['srl_employer'],
                $payrollData['infotep_employer'],
                $payrollData['total_employer_contributions'],
                $payrollData['net_salary'],
                $payrollRecordId
            ]);

            echo "Successfully updated Record ID $payrollRecordId. New Gross: " . $payrollData['gross_salary'] . "\n";
        } else {
            echo "Error: Could not calculate payroll for Record ID $payrollRecordId.\n";
        }
    }

    echo "\nAll records processed.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
