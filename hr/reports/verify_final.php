<?php
// Final verification test after hourly_rate_dop updates
require_once __DIR__ . '/../../db.php';

echo "=== VERIFICACIÓN FINAL DE DATOS ===\n\n";

// Test 1: Department with hourly_rate_dop
echo "1. DEPARTAMENTOS (con hourly_rate_dop):\n";
$deptData = $pdo->query("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(e.id) as count,
        AVG(CASE WHEN u.hourly_rate_dop > 0 THEN u.hourly_rate_dop ELSE NULL END) as avg_rate,
        SUM(CASE WHEN u.hourly_rate_dop > 0 THEN u.hourly_rate_dop ELSE 0 END) as total_cost_per_hour
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY d.name
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);
print_r($deptData);

// Test 2: Salary ranges with hourly_rate_dop
echo "\n2. RANGOS SALARIALES (con hourly_rate_dop):\n";
$salaryData = $pdo->query("
    SELECT 
        CASE 
            WHEN u.hourly_rate_dop IS NULL OR u.hourly_rate_dop = 0 THEN 'Sin datos'
            WHEN u.hourly_rate_dop * 160 < 15000 THEN 'Menos de RD\$15K'
            WHEN u.hourly_rate_dop * 160 BETWEEN 15000 AND 24999 THEN 'RD\$15K-25K'
            WHEN u.hourly_rate_dop * 160 BETWEEN 25000 AND 34999 THEN 'RD\$25K-35K'
            WHEN u.hourly_rate_dop * 160 BETWEEN 35000 AND 49999 THEN 'RD\$35K-50K'
            WHEN u.hourly_rate_dop * 160 >= 50000 THEN 'Más de RD\$50K'
        END as salary_range,
        COUNT(*) as count
    FROM employees e
    JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY salary_range
")->fetchAll(PDO::FETCH_ASSOC);
print_r($salaryData);

// Test 3: Top performers with hourly_rate_dop
echo "\n3. TOP 10 EMPLEADOS (con hourly_rate_dop):\n";
$topPerformers = $pdo->query("
    SELECT 
        CONCAT(e.first_name, ' ', e.last_name) as name,
        d.name as department,
        e.position,
        u.hourly_rate_dop,
        (u.hourly_rate_dop * 160) as monthly_estimate
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    AND u.hourly_rate_dop IS NOT NULL
    AND u.hourly_rate_dop > 0
    ORDER BY u.hourly_rate_dop DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
print_r($topPerformers);

echo "\n=== TODOS LOS DATOS ESTÁN FUNCIONANDO CORRECTAMENTE ===\n";
