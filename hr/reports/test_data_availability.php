<?php
// Test data availability for employees_analytics.php
require_once __DIR__ . '/../../db.php';

echo "=== DIAGNÓSTICO DE DATOS DISPONIBLES ===\n\n";

// Test 1: Employees by Department
echo "1. EMPLEADOS POR DEPARTAMENTO:\n";
$deptData = $pdo->query("
    SELECT 
        COALESCE(d.name, 'Sin Departamento') as department,
        COUNT(e.id) as count
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    GROUP BY d.name
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);
print_r($deptData);

// Test 2: Salary Ranges
echo "\n2. DISTRIBUCIÓN SALARIAL:\n";
$salaryData = $pdo->query("
    SELECT 
        CASE 
            WHEN u.hourly_rate IS NULL THEN 'Sin datos'
            WHEN u.hourly_rate * 160 < 15000 THEN 'Menos de RD\$15K'
            WHEN u.hourly_rate * 160 BETWEEN 15000 AND 24999 THEN 'RD\$15K-25K'
            WHEN u.hourly_rate * 160 BETWEEN 25000 AND 34999 THEN 'RD\$25K-35K'
            WHEN u.hourly_rate * 160 BETWEEN 35000 AND 49999 THEN 'RD\$35K-50K'
            WHEN u.hourly_rate * 160 >= 50000 THEN 'Más de RD\$50K'
        END as salary_range,
        COUNT(*) as count,
        AVG(u.hourly_rate) as avg_rate
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

// Test 3: Hiring Trend (last 12 months)
echo "\n3. TENDENCIA DE CONTRATACIÓN (últimos 12 meses):\n";
$hiringData = $pdo->query("
    SELECT 
        DATE_FORMAT(hire_date, '%Y-%m') as month,
        DATE_FORMAT(hire_date, '%b %Y') as month_label,
        COUNT(*) as hires
    FROM employees
    WHERE hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month, month_label
    ORDER BY month ASC
")->fetchAll(PDO::FETCH_ASSOC);
print_r($hiringData);

// Test 4: Sample of employees with rates
echo "\n4. MUESTRA DE EMPLEADOS CON TASAS:\n";
$sampleData = $pdo->query("
    SELECT 
        e.id,
        e.first_name,
        e.last_name,
        d.name as department,
        u.hourly_rate,
        u.hourly_rate_dop,
        u.hourly_rate * 160 as monthly_estimate,
        e.hire_date,
        e.employment_status
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    ORDER BY e.id
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
print_r($sampleData);

// Test 5: Count of NULL vs non-NULL rates
echo "\n5. CONTEO DE TARIFAS (NULL vs NO-NULL):\n";
$rateStats = $pdo->query("
    SELECT 
        COUNT(*) as total_employees,
        SUM(CASE WHEN u.hourly_rate IS NULL THEN 1 ELSE 0 END) as null_rates,
        SUM(CASE WHEN u.hourly_rate IS NOT NULL THEN 1 ELSE 0 END) as with_rates,
        AVG(u.hourly_rate) as avg_rate,
        MIN(u.hourly_rate) as min_rate,
        MAX(u.hourly_rate) as max_rate
    FROM employees e
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
")->fetch(PDO::FETCH_ASSOC);
print_r($rateStats);

// Test 6: Turnover in date range
$startDate = date('Y-01-01');
$endDate = date('Y-m-d');
echo "\n6. ROTACIÓN EN PERÍODO ($startDate a $endDate):\n";
$turnoverData = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN hire_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as new_hires,
        SUM(CASE WHEN termination_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as terminations
    FROM employees
");
$turnoverData->execute([$startDate, $endDate, $startDate, $endDate]);
$turnoverResult = $turnoverData->fetch(PDO::FETCH_ASSOC);
print_r($turnoverResult);

echo "\n=== FIN DEL DIAGNÓSTICO ===\n";
