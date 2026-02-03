<?php
require_once __DIR__ . '/../../db.php';

echo "=== TABLAS DISPONIBLES PARA REPORTES ===\n\n";

$tables = [
    'employees',
    'users', 
    'departments',
    'campaigns',
    'payroll_periods',
    'payroll_records',
    'permission_requests',
    'vacation_requests',
    'medical_leaves',
    'job_applications',
    'job_postings',
    'employment_contracts',
    'attendance'
];

foreach ($tables as $table) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "✅ $table: $count registros\n";
    } catch (Exception $e) {
        echo "❌ $table: NO EXISTE\n";
    }
}
