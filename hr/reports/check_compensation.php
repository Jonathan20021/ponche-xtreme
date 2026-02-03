<?php
require_once __DIR__ . '/../../db.php';

echo "=== ESTRUCTURA DE COMPENSACIÓN ===\n\n";

// Ver campos de users relacionados con compensación
$result = $pdo->query("DESCRIBE users");
echo "CAMPOS DE LA TABLA USERS:\n";
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    if (strpos($row['Field'], 'salary') !== false || 
        strpos($row['Field'], 'rate') !== false ||
        strpos($row['Field'], 'compensation') !== false) {
        echo "  - {$row['Field']} ({$row['Type']})\n";
    }
}

// Ver ejemplo de datos reales
echo "\n\nEJEMPLOS DE EMPLEADOS POR ROL:\n\n";

$roles = ['AGENT', 'TEAM_LEAD', 'SUPERVISOR', 'ADMIN'];
foreach ($roles as $role) {
    echo "ROL: $role\n";
    $stmt = $pdo->prepare("
        SELECT 
            e.first_name,
            e.last_name,
            u.role,
            u.compensation_type,
            u.hourly_rate,
            u.hourly_rate_dop,
            u.monthly_salary,
            u.monthly_salary_dop,
            u.preferred_currency
        FROM employees e
        JOIN users u ON u.id = e.user_id
        WHERE u.role = ? AND e.employment_status IN ('ACTIVE', 'TRIAL')
        LIMIT 3
    ");
    $stmt->execute([$role]);
    $examples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($examples)) {
        echo "  (No hay empleados con este rol)\n\n";
    } else {
        foreach ($examples as $emp) {
            echo "  • {$emp['first_name']} {$emp['last_name']}\n";
            echo "    - Tipo: {$emp['compensation_type']}\n";
            echo "    - Hourly USD: {$emp['hourly_rate']}, DOP: {$emp['hourly_rate_dop']}\n";
            echo "    - Monthly USD: {$emp['monthly_salary']}, DOP: {$emp['monthly_salary_dop']}\n";
            echo "    - Moneda preferida: {$emp['preferred_currency']}\n\n";
        }
    }
}
