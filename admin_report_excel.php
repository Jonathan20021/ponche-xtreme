<?php
session_start();
require_once __DIR__ . '/db.php';

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
$paidTypesUpper = array_map('strtoupper', $paidTypes);
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

    $punchesStmt = $pdo->prepare("
        SELECT timestamp, type, DATE(timestamp) as work_date
        FROM attendance
        WHERE user_id = ?
        AND timestamp BETWEEN ? AND ?
        ORDER BY timestamp ASC
    ");
    $punchesStmt->execute([$userId, $startBound, $endBound]);
    $punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar por fecha
    $punchesByDate = [];
    foreach ($punches as $punch) {
        $date = $punch['work_date'];
        $punchesByDate[$date][] = $punch;
    }

    // Calcular segundos productivos
    $totalProductiveSeconds = 0;
    $daysWorked = count($punchesByDate);

    foreach ($punchesByDate as $dayPunches) {
        $inPaid = false;
        $paidStart = null;
        $lastPaid = null;
        foreach ($dayPunches as $punch) {
            $ts = strtotime($punch['timestamp']);
            $isPaid = in_array(strtoupper($punch['type']), $paidTypesUpper, true);
            if ($isPaid) {
                $lastPaid = $ts;
                if (!$inPaid) {
                    $paidStart = $ts;
                    $inPaid = true;
                }
            } elseif ($inPaid) {
                if ($paidStart !== null && $lastPaid !== null) {
                    $totalProductiveSeconds += ($lastPaid - $paidStart);
                }
                $inPaid = false;
                $paidStart = null;
                $lastPaid = null;
            }
        }
        if ($inPaid && $paidStart !== null && $lastPaid !== null) {
            $totalProductiveSeconds += ($lastPaid - $paidStart);
        }
    }

    if ($totalProductiveSeconds <= 0 && $daysWorked <= 0) {
        continue;
    }

    $comp = $compensation[$user['username']] ?? [
        'hourly_rate' => 0.0,
        'hourly_rate_dop' => 0.0,
        'monthly_salary' => 0.0,
        'monthly_salary_dop' => 0.0,
    ];

    $hours = $totalProductiveSeconds / 3600;
    $rows[] = [
        'full_name' => $user['full_name'],
        'department' => $deptName,
        'days' => $daysWorked,
        'hours' => $hours,
        'hourly_rate_usd' => (float) $comp['hourly_rate'],
        'hourly_rate_dop' => (float) $comp['hourly_rate_dop'],
        'pay_usd' => calculateAmountFromSeconds($totalProductiveSeconds, (float) $comp['hourly_rate']),
        'pay_dop' => calculateAmountFromSeconds($totalProductiveSeconds, (float) $comp['hourly_rate_dop']),
    ];
}

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="admin_hours_' . $payrollStart . '_al_' . $payrollEnd . '.xls"');
header('Cache-Control: max-age=0');

echo "<table border='1'>";
echo "<thead><tr>
    <th>Colaborador</th>
    <th>Departamento</th>
    <th>Días</th>
    <th>Horas</th>
    <th>Tarifa USD</th>
    <th>Pago USD</th>
    <th>Tarifa DOP</th>
    <th>Pago DOP</th>
</tr></thead><tbody>";

foreach ($rows as $row) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($row['full_name']) . '</td>';
    echo '<td>' . htmlspecialchars($row['department']) . '</td>';
    echo '<td>' . number_format($row['days']) . '</td>';
    echo '<td>' . number_format($row['hours'], 2) . '</td>';
    echo '<td>$' . number_format($row['hourly_rate_usd'], 2) . '</td>';
    echo '<td>$' . number_format($row['pay_usd'], 2) . '</td>';
    echo '<td>RD$' . number_format($row['hourly_rate_dop'], 2) . '</td>';
    echo '<td>RD$' . number_format($row['pay_dop'], 2) . '</td>';
    echo '</tr>';
}

if (empty($rows)) {
    echo "<tr><td colspan='8'>No se encontraron administrativos en el rango solicitado.</td></tr>";
}

echo '</tbody></table>';
exit;
