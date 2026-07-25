<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/work_hours_calculator.php';

ensurePermission('hr_report');

function calculateAmountFromSeconds(int $seconds, float $rate): float
{
    if ($seconds <= 0 || $rate <= 0) {
        return 0.0;
    }
    $rateCents = (int) round($rate * 100);
    if ($rateCents <= 0) {
        return 0.0;
    }
    $amountCents = (int) round(($seconds * $rateCents) / 3600);
    return $amountCents / 100;
}

$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-t');

$payrollStart = $_GET['payroll_start'] ?? $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payrollStart)) {
    $payrollStart = $defaultStart;
}
$payrollEnd = $_GET['payroll_end'] ?? $defaultEnd;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payrollEnd)) {
    $payrollEnd = $defaultEnd;
}
if ($payrollEnd < $payrollStart) {
    $payrollEnd = $payrollStart;
}

$employeeFilter = $_GET['employee'] ?? 'all';
if ($employeeFilter !== 'all' && !ctype_digit((string) $employeeFilter)) {
    $employeeFilter = 'all';
}

$startBound = $payrollStart . ' 00:00:00';
$endBound = $payrollEnd . ' 23:59:59';

$paidTypes = getPaidAttendanceTypeSlugs($pdo);
if (empty($paidTypes)) {
    die('No hay tipos de asistencia pagados configurados.');
}
$compensation = getUserCompensation($pdo);

$userQuery = "SELECT id, full_name, username, department_id, role FROM users WHERE UPPER(role) <> 'AGENT'";
if ($employeeFilter !== 'all') {
    $userQuery .= " AND id = " . (int) $employeeFilter;
}
$userQuery .= " ORDER BY full_name";
$users = $pdo->query($userQuery)->fetchAll(PDO::FETCH_ASSOC);

$rows = [];

foreach ($users as $user) {
    $userId = $user['id'];
    $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
    $deptStmt->execute([$user['department_id']]);
    $deptName = $deptStmt->fetchColumn() ?: 'Sin departamento';

    // Horas con la lógica canónica de la nómina (lib/work_hours_calculator.php),
    // para que el Excel cuadre con lo que muestra hr_report.php y con el pago.
    $dailySeconds = getPaidWorkSecondsByDateForUser(
        $pdo,
        (int) $userId,
        $payrollStart,
        $payrollEnd,
        $paidTypes
    );

    $totalProductiveSeconds = array_sum($dailySeconds);
    $daysWorked = count($dailySeconds);

    if ($totalProductiveSeconds <= 0 && $daysWorked <= 0) {
        continue;
    }

    $comp = $compensation[$user['username']] ?? [
        'hourly_rate' => 0.0,
        'hourly_rate_dop' => 0.0,
        'monthly_salary' => 0.0,
        'monthly_salary_dop' => 0.0,
    ];

    // Quien cobra por sueldo mensual exporta SU VALOR MENSUAL, no horas × tarifa:
    // su pago no depende de las horas y varios administrativos tienen además una
    // tarifa horaria cargada que en su caso no aplica.
    $isFixedPay   = ($comp['payment_type'] ?? 'hourly') === 'fixed';
    $rateUsd      = (float) $comp['hourly_rate'];
    $rateDop      = (float) $comp['hourly_rate_dop'];
    $monthlyUsd   = (float) ($comp['monthly_salary'] ?? 0);
    $monthlyDop   = (float) ($comp['monthly_salary_dop'] ?? 0);

    $hours = $totalProductiveSeconds / 3600;
    $rows[] = [
        'full_name' => $user['full_name'],
        'department' => $deptName,
        'payment_type' => $isFixedPay ? 'Mensual' : 'Por hora',
        'is_fixed' => $isFixedPay,
        'days' => $daysWorked,
        'hours' => $hours,
        'hourly_rate_usd' => $isFixedPay ? null : $rateUsd,
        'hourly_rate_dop' => $isFixedPay ? null : $rateDop,
        'pay_usd' => $isFixedPay ? $monthlyUsd : calculateAmountFromSeconds($totalProductiveSeconds, $rateUsd),
        'pay_dop' => $isFixedPay ? $monthlyDop : calculateAmountFromSeconds($totalProductiveSeconds, $rateDop),
    ];
}

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="admin_hours_' . $payrollStart . '_al_' . $payrollEnd . '.xls"');
header('Cache-Control: max-age=0');

echo "<table border='1'>";
echo "<thead><tr>
    <th>Colaborador</th>
    <th>Departamento</th>
    <th>Tipo de pago</th>
    <th>Días</th>
    <th>Horas</th>
    <th>Tarifa USD</th>
    <th>Pago USD</th>
    <th>Tarifa DOP</th>
    <th>Pago DOP</th>
</tr></thead><tbody>";

foreach ($rows as $row) {
    // En sueldo mensual la columna de pago trae el valor mensual (marcado "/mes")
    // y la tarifa horaria va vacía, porque en su caso no aplica.
    $suffix = $row['is_fixed'] ? ' /mes' : '';
    echo '<tr>';
    echo '<td>' . htmlspecialchars($row['full_name']) . '</td>';
    echo '<td>' . htmlspecialchars($row['department']) . '</td>';
    echo '<td>' . htmlspecialchars($row['payment_type']) . '</td>';
    echo '<td>' . number_format($row['days']) . '</td>';
    echo '<td>' . number_format($row['hours'], 2) . '</td>';
    echo '<td>' . ($row['hourly_rate_usd'] === null ? '' : '$' . number_format($row['hourly_rate_usd'], 2)) . '</td>';
    echo '<td>$' . number_format($row['pay_usd'], 2) . $suffix . '</td>';
    echo '<td>' . ($row['hourly_rate_dop'] === null ? '' : 'RD$' . number_format($row['hourly_rate_dop'], 2)) . '</td>';
    echo '<td>RD$' . number_format($row['pay_dop'], 2) . $suffix . '</td>';
    echo '</tr>';
}

if (empty($rows)) {
    echo "<tr><td colspan='9'>No se encontraron administrativos en el rango solicitado.</td></tr>";
}

echo '</tbody></table>';
exit;
