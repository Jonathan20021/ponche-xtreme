<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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
$totalHours = 0.0;
$totalUsd = 0.0;
$totalDop = 0.0;

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
    $payUsd = calculateAmountFromSeconds($totalProductiveSeconds, (float) $comp['hourly_rate']);
    $payDop = calculateAmountFromSeconds($totalProductiveSeconds, (float) $comp['hourly_rate_dop']);

    $rows[] = [
        'full_name' => $user['full_name'],
        'department' => $deptName,
        'days' => $daysWorked,
        'hours' => $hours,
        'pay_usd' => $payUsd,
        'pay_dop' => $payDop,
    ];

    $totalHours += $hours;
    $totalUsd += $payUsd;
    $totalDop += $payDop;
}

$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; }
        h1 { font-size: 18px; margin: 0 0 6px; }
        p { margin: 2px 0 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px; text-align: left; }
        th { background: #e2e8f0; }
        .summary { margin: 10px 0; }
        .summary span { margin-right: 12px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Reporte de horas administrativas</h1>
    <p>Periodo del <?= htmlspecialchars(date('d/m/Y', strtotime($payrollStart))) ?> al <?= htmlspecialchars(date('d/m/Y', strtotime($payrollEnd))) ?></p>
    <div class="summary">
        <span>Horas totales: <?= number_format($totalHours, 2) ?> h</span>
        <span>Pago USD: $<?= number_format($totalUsd, 2) ?></span>
        <span>Pago DOP: RD$<?= number_format($totalDop, 2) ?></span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Colaborador</th>
                <th>Departamento</th>
                <th>Días</th>
                <th>Horas</th>
                <th>Pago USD</th>
                <th>Pago DOP</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6">No se encontraron registros para administrativos.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><?= htmlspecialchars($row['department']) ?></td>
                        <td><?= number_format($row['days']) ?></td>
                        <td><?= number_format($row['hours'], 2) ?></td>
                        <td>$<?= number_format($row['pay_usd'], 2) ?></td>
                        <td>RD$<?= number_format($row['pay_dop'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
<?php
$html = ob_get_clean();
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('reporte_admin_' . $payrollStart . '_al_' . $payrollEnd . '.pdf', ['Attachment' => true]);
exit;
