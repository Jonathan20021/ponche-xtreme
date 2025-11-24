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

$startBound = $payrollStart . ' 00:00:00';
$endBound = $payrollEnd . ' 23:59:59';

$paidTypes = getPaidAttendanceTypeSlugs($pdo);
if (empty($paidTypes)) {
    die('No hay tipos de asistencia pagados configurados.');
}
$paidTypesUpper = array_map('strtoupper', $paidTypes);
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

    $punchesByDate = [];
    foreach ($punches as $punch) {
        $date = $punch['work_date'];
        $punchesByDate[$date][] = $punch;
    }

    $comp = $compensation[$user['username']] ?? [
        'hourly_rate' => 0.0,
        'hourly_rate_dop' => 0.0,
    ];

    foreach ($punchesByDate as $date => $dayPunches) {
        $inPaid = false;
        $paidStart = null;
        $lastPaid = null;
        $firstEntry = null;
        $lastExit = null;
        $productiveSeconds = 0;

        foreach ($dayPunches as $punch) {
            $ts = strtotime($punch['timestamp']);
            $typeUpper = strtoupper($punch['type']);

            if ($typeUpper === 'ENTRY' && $firstEntry === null) {
                $firstEntry = $punch['timestamp'];
            }
            if ($typeUpper === 'EXIT') {
                $lastExit = $punch['timestamp'];
            }

            $isPaid = in_array($typeUpper, $paidTypesUpper, true);
            if ($isPaid) {
                $lastPaid = $ts;
                if (!$inPaid) {
                    $paidStart = $ts;
                    $inPaid = true;
                }
            } elseif ($inPaid) {
                if ($paidStart !== null && $lastPaid !== null) {
                    $productiveSeconds += ($lastPaid - $paidStart);
                }
                $inPaid = false;
                $paidStart = null;
                $lastPaid = null;
            }
        }

        if ($inPaid && $paidStart !== null && $lastPaid !== null) {
            $productiveSeconds += ($lastPaid - $paidStart);
        }

        if ($productiveSeconds <= 0) {
            continue;
        }

        $rows[] = [
            'date' => $date,
            'full_name' => $user['full_name'],
            'department' => $deptName,
            'first_entry' => $firstEntry,
            'last_exit' => $lastExit,
            'hours' => $productiveSeconds / 3600,
            'pay_usd' => calculateAmountFromSeconds($productiveSeconds, (float) $comp['hourly_rate']),
            'pay_dop' => calculateAmountFromSeconds($productiveSeconds, (float) $comp['hourly_rate_dop']),
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
    <th>Entrada</th>
    <th>Salida</th>
    <th>Horas</th>
    <th>Pago USD</th>
    <th>Pago DOP</th>
</tr></thead><tbody>";

foreach ($rows as $row) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars(date('d/m/Y', strtotime($row['date']))) . '</td>';
    echo '<td>' . htmlspecialchars($row['full_name']) . '</td>';
    echo '<td>' . htmlspecialchars($row['department']) . '</td>';
    echo '<td>' . ($row['first_entry'] ? htmlspecialchars(date('H:i', strtotime($row['first_entry']))) : 'Sin registro') . '</td>';
    echo '<td>' . ($row['last_exit'] ? htmlspecialchars(date('H:i', strtotime($row['last_exit']))) : 'Sin registro') . '</td>';
    echo '<td>' . number_format($row['hours'], 2) . '</td>';
    echo '<td>$' . number_format($row['pay_usd'], 2) . '</td>';
    echo '<td>RD$' . number_format($row['pay_dop'], 2) . '</td>';
    echo '</tr>';
}

if (empty($rows)) {
    echo "<tr><td colspan='8'>Sin registros administrativos en el rango solicitado.</td></tr>";
}

echo '</tbody></table>';
exit;
