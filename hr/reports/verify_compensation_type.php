<?php
require_once __DIR__ . '/../../db.php';

echo "=== VERIFICACIÓN FINAL CON COMPENSATION_TYPE ===\n\n";

// Test 1: Salary ranges with compensation_type
echo "1. RANGOS SALARIALES (considerando compensation_type):\n";
$salaryData = $pdo->query("
    SELECT 
        CASE 
            WHEN u.compensation_type = 'hourly' AND (u.hourly_rate_dop IS NULL OR u.hourly_rate_dop = 0) THEN 'Sin datos'
            WHEN u.compensation_type = 'fixed' AND (u.monthly_salary_dop IS NULL OR u.monthly_salary_dop = 0) THEN 'Sin datos'
            WHEN (u.compensation_type = 'hourly' AND u.hourly_rate_dop * 160 < 15000) OR (u.compensation_type = 'fixed' AND u.monthly_salary_dop < 15000) THEN 'Menos de RD\$15K'
            WHEN (u.compensation_type = 'hourly' AND u.hourly_rate_dop * 160 BETWEEN 15000 AND 24999) OR (u.compensation_type = 'fixed' AND u.monthly_salary_dop BETWEEN 15000 AND 24999) THEN 'RD\$15K-25K'
            WHEN (u.compensation_type = 'hourly' AND u.hourly_rate_dop * 160 BETWEEN 25000 AND 34999) OR (u.compensation_type = 'fixed' AND u.monthly_salary_dop BETWEEN 25000 AND 34999) THEN 'RD\$25K-35K'
            WHEN (u.compensation_type = 'hourly' AND u.hourly_rate_dop * 160 BETWEEN 35000 AND 49999) OR (u.compensation_type = 'fixed' AND u.monthly_salary_dop BETWEEN 35000 AND 49999) THEN 'RD\$35K-50K'
            WHEN (u.compensation_type = 'hourly' AND u.hourly_rate_dop * 160 >= 50000) OR (u.compensation_type = 'fixed' AND u.monthly_salary_dop >= 50000) THEN 'Más de RD\$50K'
            ELSE 'Sin datos'
        END as salary_range,
        COUNT(*) as count
    FROM employees e
    JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY salary_range
    ORDER BY 
        CASE salary_range
            WHEN 'Sin datos' THEN 6
            WHEN 'Menos de RD\$15K' THEN 1
            WHEN 'RD\$15K-25K' THEN 2
            WHEN 'RD\$25K-35K' THEN 3
            WHEN 'RD\$35K-50K' THEN 4
            WHEN 'Más de RD\$50K' THEN 5
        END
")->fetchAll(PDO::FETCH_ASSOC);
print_r($salaryData);

// Test 2: Top performers considering compensation_type
echo "\n2. TOP 10 EMPLEADOS (por salario mensual):\n";
$topPerformers = $pdo->query("
    SELECT 
        CONCAT(e.first_name, ' ', e.last_name) as name,
        d.name as department,
        e.position,
        u.compensation_type,
        u.role,
        CASE 
            WHEN u.compensation_type = 'hourly' THEN u.hourly_rate_dop
            ELSE 0
        END as hourly_rate,
        CASE 
            WHEN u.compensation_type = 'hourly' THEN u.hourly_rate_dop * 160
            WHEN u.compensation_type = 'fixed' THEN u.monthly_salary_dop
            ELSE 0
        END as estimated_monthly
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    AND (
        (u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0) OR
        (u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0)
    )
    ORDER BY estimated_monthly DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
print_r($topPerformers);

// Test 3: Department analysis
echo "\n3. ANÁLISIS POR DEPARTAMENTO (salarios mensuales):\n";
$deptData = $pdo->query("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(e.id) as count,
        AVG(
            CASE 
                WHEN u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0 THEN u.hourly_rate_dop * 160
                WHEN u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0 THEN u.monthly_salary_dop
                ELSE NULL
            END
        ) as avg_monthly_salary,
        SUM(
            CASE 
                WHEN u.compensation_type = 'hourly' AND u.hourly_rate_dop > 0 THEN u.hourly_rate_dop * 160
                WHEN u.compensation_type = 'fixed' AND u.monthly_salary_dop > 0 THEN u.monthly_salary_dop
                ELSE 0
            END
        ) as total_monthly_cost
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY d.name
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);
print_r($deptData);

echo "\n=== VERIFICACIÓN COMPLETA ===\n";
