<?php
require_once '../../db.php';

echo "<!DOCTYPE html>";
echo "<html><head>";
echo "<meta charset='UTF-8'>";
echo "<title>Validación de Estructura DB</title>";
echo "<style>";
echo "body { font-family: monospace; padding: 20px; background: var(--surface-2); color: #e2e8f0; }";
echo "h2 { color: #5e7cba; border-bottom: 2px solid #264b8b; padding-bottom: 10px; }";
echo "h3 { color: #34d399; margin-top: 20px; }";
echo "table { border-collapse: collapse; width: 100%; margin: 10px 0; background: #334155; }";
echo "th, td { border: 1px solid #475569; padding: 8px; text-align: left; }";
echo "th { background: #1f3f76; color: white; }";
echo ".success { color: #4ade80; }";
echo ".error { color: #f87171; }";
echo ".warning { color: #fbbf24; }";
echo ".info { color: #5e7cba; }";
echo "</style>";
echo "</head><body>";

echo "<h1>🔍 Validación de Estructura de Base de Datos</h1>";

// List of tables to validate
$tablesToCheck = [
    'employees',
    'users',
    'departments',
    'campaigns',
    'campaign_employees',
    'payroll_records',
    'payroll_periods',
    'employment_contracts',
    'permission_requests',
    'vacation_requests',
    'medical_leaves',
    'job_postings',
    'job_applications',
    'punch_records'
];

foreach ($tablesToCheck as $tableName) {
    echo "<h2>📋 Tabla: $tableName</h2>";
    
    try {
        $columns = $pdo->query("DESCRIBE $tableName")->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($columns)) {
            echo "<p class='error'>❌ La tabla '$tableName' no existe o está vacía.</p>";
            continue;
        }
        
        echo "<p class='success'>✅ Tabla encontrada con " . count($columns) . " columnas</p>";
        
        echo "<table>";
        echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td><strong>" . htmlspecialchars($column['Field']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        // Count records
        $count = $pdo->query("SELECT COUNT(*) FROM $tableName")->fetchColumn();
        echo "<p class='info'>📊 Total de registros: $count</p>";
        
    } catch (PDOException $e) {
        echo "<p class='error'>❌ Error al consultar la tabla '$tableName': " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// Check for common issues
echo "<h2>🔧 Verificaciones Específicas para Reportes</h2>";

echo "<h3>Verificación: hourly_rate en employees vs users</h3>";
try {
    $employeesColumns = $pdo->query("DESCRIBE employees")->fetchAll(PDO::FETCH_COLUMN);
    $usersColumns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('hourly_rate', $employeesColumns)) {
        echo "<p class='success'>✅ hourly_rate existe en employees</p>";
    } else {
        echo "<p class='warning'>⚠️ hourly_rate NO existe en employees</p>";
    }
    
    if (in_array('hourly_rate', $usersColumns)) {
        echo "<p class='success'>✅ hourly_rate existe en users</p>";
    } else {
        echo "<p class='error'>❌ hourly_rate NO existe en users</p>";
    }
} catch (PDOException $e) {
    echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h3>Verificación: department en employees</h3>";
try {
    $employeesColumns = $pdo->query("DESCRIBE employees")->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('department', $employeesColumns)) {
        echo "<p class='success'>✅ department (campo texto) existe en employees</p>";
    } else {
        echo "<p class='warning'>⚠️ department (campo texto) NO existe en employees</p>";
    }
    
    if (in_array('department_id', $employeesColumns)) {
        echo "<p class='success'>✅ department_id (FK) existe en employees</p>";
    } else {
        echo "<p class='error'>❌ department_id NO existe en employees</p>";
    }
} catch (PDOException $e) {
    echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h3>Verificación: Relaciones importantes</h3>";

// Check employees -> users relationship
try {
    $result = $pdo->query("
        SELECT COUNT(*) as count
        FROM employees e
        LEFT JOIN users u ON e.user_id = u.id
        WHERE u.id IS NULL
    ")->fetch();
    
    if ($result['count'] == 0) {
        echo "<p class='success'>✅ Todos los employees tienen un user_id válido</p>";
    } else {
        echo "<p class='warning'>⚠️ Hay {$result['count']} employees sin user_id válido</p>";
    }
} catch (PDOException $e) {
    echo "<p class='error'>Error verificando employees->users: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Check employees -> departments relationship
try {
    $result = $pdo->query("
        SELECT COUNT(*) as count
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE e.department_id IS NOT NULL AND d.id IS NULL
    ")->fetch();
    
    if ($result['count'] == 0) {
        echo "<p class='success'>✅ Todos los department_id son válidos</p>";
    } else {
        echo "<p class='warning'>⚠️ Hay {$result['count']} employees con department_id inválido</p>";
    }
} catch (PDOException $e) {
    echo "<p class='error'>Error verificando employees->departments: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>📝 Recomendaciones para Consultas</h2>";

echo "<div style='background: #334155; padding: 15px; border-left: 4px solid #264b8b; margin: 15px 0;'>";
echo "<p><strong>Para obtener hourly_rate:</strong></p>";
echo "<pre style='color: #4ade80;'>
SELECT e.*, u.hourly_rate, u.hourly_rate_dop
FROM employees e
JOIN users u ON e.user_id = u.id
WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
</pre>";
echo "</div>";

echo "<div style='background: #334155; padding: 15px; border-left: 4px solid #6f8bbd; margin: 15px 0;'>";
echo "<p><strong>Para obtener department:</strong></p>";
echo "<pre style='color: #4ade80;'>
SELECT e.*, d.name as department_name
FROM employees e
LEFT JOIN departments d ON e.department_id = d.id
WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
</pre>";
echo "</div>";

echo "<div style='background: #334155; padding: 15px; border-left: 4px solid #10b981; margin: 15px 0;'>";
echo "<p><strong>Para cálculos de nómina:</strong></p>";
echo "<pre style='color: #4ade80;'>
SELECT 
    e.*,
    u.hourly_rate,
    d.name as department,
    SUM(pr.gross_salary) as total_gross
FROM employees e
JOIN users u ON e.user_id = u.id
LEFT JOIN departments d ON e.department_id = d.id
LEFT JOIN payroll_records pr ON e.id = pr.employee_id
WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
GROUP BY e.id
</pre>";
echo "</div>";

echo "</body></html>";
?>
