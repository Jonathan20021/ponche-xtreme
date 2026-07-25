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

$startBound = $payrollStart . ' 00:00:00';
$endBound = $payrollEnd . ' 23:59:59';

$paidTypes = getPaidAttendanceTypeSlugs($pdo);
if (empty($paidTypes)) {
    die('No hay tipos de asistencia pagados configurados.');
}
$compensation = getUserCompensation($pdo);

$users = $pdo->query("SELECT id, full_name, username, department_id, role FROM users WHERE UPPER(role) <> 'AGENT' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$rows = [];

foreach ($users as $user) {
    $userId = $user['id'];
    $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
    $deptStmt->execute([$user['department_id']]);
    $deptName = $deptStmt->fetchColumn() ?: 'Sin departamento';

    $punchesStmt = $pdo->prepare("
        SELECT timestamp, type, DATE(timestamp) as work_date
        FROM attendance
        WHERE user_id = ?
        AND timestamp BETWEEN ? AND ?
        ORDER BY timestamp ASC
    ");
    $punchesStmt->execute([$userId, $startBound, $endBound]);
    $punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Entrada/salida por día solo para desplegar el horario en la columna.
    $scheduleByDate = [];
    foreach ($punches as $punch) {
        $date = $punch['work_date'];
        if (!isset($scheduleByDate[$date])) {
            $scheduleByDate[$date] = ['first_entry' => null, 'last_exit' => null];
        }
        $typeUpper = strtoupper($punch['type']);
        if ($typeUpper === 'ENTRY' && $scheduleByDate[$date]['first_entry'] === null) {
            $scheduleByDate[$date]['first_entry'] = $punch['timestamp'];
        }
        if ($typeUpper === 'EXIT') {
            $scheduleByDate[$date]['last_exit'] = $punch['timestamp'];
        }
    }

    $comp = $compensation[$user['username']] ?? [
        'hourly_rate' => 0.0,
        'hourly_rate_dop' => 0.0,
        'monthly_salary' => 0.0,
        'monthly_salary_dop' => 0.0,
        'payment_type' => 'hourly',
    ];

    // A quien cobra sueldo mensual no se le parte el sueldo por día: se exportan
    // sus horas y, en lugar de un monto diario, su valor mensual como referencia.
    $isFixedPay = ($comp['payment_type'] ?? 'hourly') === 'fixed';
    $rateUsd    = (float) $comp['hourly_rate'];
    $rateDop    = (float) $comp['hourly_rate_dop'];
    $monthlyUsd = (float) ($comp['monthly_salary'] ?? 0);
    $monthlyDop = (float) ($comp['monthly_salary_dop'] ?? 0);

    // Horas con la lógica canónica de la nómina (lib/work_hours_calculator.php).
    $dailySeconds = getPaidWorkSecondsByDateForUser(
        $pdo,
        (int) $userId,
        $payrollStart,
        $payrollEnd,
        $paidTypes
    );

    foreach ($dailySeconds as $date => $productiveSeconds) {
        $firstEntry = $scheduleByDate[$date]['first_entry'] ?? null;
        $lastExit   = $scheduleByDate[$date]['last_exit'] ?? null;

        if ($productiveSeconds <= 0) {
            continue;
        }

        $rows[] = [
            'date' => $date,
            'full_name' => $user['full_name'],
            'department' => $deptName,
            'payment_type' => $isFixedPay ? 'Mensual' : 'Por hora',
            'is_fixed' => $isFixedPay,
            'first_entry' => $firstEntry,
            'last_exit' => $lastExit,
            'hours' => $productiveSeconds / 3600,
            'pay_usd' => $isFixedPay ? null : calculateAmountFromSeconds($productiveSeconds, $rateUsd),
            'pay_dop' => $isFixedPay ? null : calculateAmountFromSeconds($productiveSeconds, $rateDop),
            'monthly_usd' => $monthlyUsd,
            'monthly_dop' => $monthlyDop,
        ];
    }
}

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="admin_diario_' . $payrollStart . '_al_' . $payrollEnd . '.xls"');
header('Cache-Control: max-age=0');

echo "<table border='1'>";
echo "<thead><tr>
    <th>Fecha</th>
    <th>Colaborador</th>
    <th>Departamento</th>
    <th>Tipo de pago</th>
    <th>Entrada</th>
    <th>Salida</th>
    <th>Horas</th>
    <th>Pago USD</th>
    <th>Pago DOP</th>
    <th>Sueldo mensual DOP</th>
</tr></thead><tbody>";

foreach ($rows as $row) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars(date('d/m/Y', strtotime($row['date']))) . '</td>';
    echo '<td>' . htmlspecialchars($row['full_name']) . '</td>';
    echo '<td>' . htmlspecialchars($row['department']) . '</td>';
    echo '<td>' . htmlspecialchars($row['payment_type']) . '</td>';
    echo '<td>' . ($row['first_entry'] ? htmlspecialchars(date('H:i', strtotime($row['first_entry']))) : 'Sin registro') . '</td>';
    echo '<td>' . ($row['last_exit'] ? htmlspecialchars(date('H:i', strtotime($row['last_exit']))) : 'Sin registro') . '</td>';
    echo '<td>' . number_format($row['hours'], 2) . '</td>';
    // En sueldo mensual las columnas de pago diario van vacías (el sueldo no se
    // parte por día) y el valor mensual va en su propia columna.
    echo '<td>' . ($row['pay_usd'] === null ? '' : '$' . number_format($row['pay_usd'], 2)) . '</td>';
    echo '<td>' . ($row['pay_dop'] === null ? '' : 'RD$' . number_format($row['pay_dop'], 2)) . '</td>';
    echo '<td>' . ($row['is_fixed'] ? 'RD$' . number_format($row['monthly_dop'], 2) : '') . '</td>';
    echo '</tr>';
}

if (empty($rows)) {
    echo "<tr><td colspan='10'>Sin registros administrativos en el rango solicitado.</td></tr>";
}

echo '</tbody></table>';
exit;
