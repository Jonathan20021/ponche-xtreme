<?php
session_start();
require_once __DIR__ . '/db.php';

ensurePermission('adherence_report');

function formatHoursMinutes(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return sprintf('%d:%02d', $hours, $minutes);
}

$schedule = getScheduleConfig($pdo);
$compensation = getUserCompensation($pdo);

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

$entryTime = $schedule['entry_time'] ?? '10:00:00';
$exitTime = $schedule['exit_time'] ?? '19:00:00';
$lunchMinutes = max(0, (int) ($schedule['lunch_minutes'] ?? 45));
$breakMinutes = max(0, (int) ($schedule['break_minutes'] ?? 15));
$meetingMinutes = max(0, (int) ($schedule['meeting_minutes'] ?? 45));
$scheduledHours = max((float) ($schedule['scheduled_hours'] ?? 8), 0.0);

$lunchSeconds = $lunchMinutes * 60;
$breakSeconds = $breakMinutes * 60;
$meetingSeconds = $meetingMinutes * 60;
$scheduledSecondsPerDay = max((int) round($scheduledHours * 3600), 1);

$selectedMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$startDate = $selectedMonth . '-01';
$endDate = date('Y-m-t', strtotime($startDate));
$startBound = $startDate . ' 00:00:00';
$endBound = $endDate . ' 23:59:59';

$recordsPerPage = 15;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($currentPage - 1) * $recordsPerPage;

$countSql = "
    SELECT COUNT(*) FROM (
        SELECT DATE(timestamp) AS work_date, user_id
        FROM attendance
        WHERE timestamp BETWEEN :start AND :end
        GROUP BY DATE(timestamp), user_id
    ) AS sub
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute([
    ':start' => $startBound,
    ':end' => $endBound,
]);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $recordsPerPage));

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $recordsPerPage;
}

// Obtener tipos de punch pagados desde la base de datos
$paidTypesStmt = $pdo->query("SELECT type FROM attendance_types WHERE is_paid = 1");
$paidTypes = $paidTypesStmt->fetchAll(PDO::FETCH_COLUMN);

// Query simplificado: obtener todas las combinaciones de usuario-fecha con attendance
$dailySql = "
    SELECT DISTINCT
        u.id AS user_id,
        u.full_name AS employee,
        u.username,
        d.name AS department_name,
        DATE(a.timestamp) AS work_date
    FROM attendance a
    JOIN users u ON u.id = a.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE a.timestamp BETWEEN :start AND :end
    GROUP BY u.id, u.full_name, u.username, d.name, DATE(a.timestamp)
    ORDER BY u.full_name, DATE(a.timestamp)
    LIMIT :offset, :limit
";

$dailyStmt = $pdo->prepare($dailySql);
$dailyStmt->bindValue(':start', $startBound);
$dailyStmt->bindValue(':end', $endBound);
$dailyStmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
$dailyStmt->bindValue(':limit', (int) $recordsPerPage, PDO::PARAM_INT);
$dailyStmt->execute();
$dailyRowsBasic = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular productive_seconds usando lógica de paid punches
$dailyRowsRaw = [];
foreach ($dailyRowsBasic as $row) {
    $userId = $row['user_id'];
    $workDate = $row['work_date'];
    
    // Obtener todos los punches del día ordenados cronológicamente
    $punchesStmt = $pdo->prepare("
        SELECT a.timestamp, a.type, at.is_paid
        FROM attendance a
        LEFT JOIN attendance_types at ON at.type = a.type
        WHERE a.user_id = :user_id 
        AND DATE(a.timestamp) = :work_date
        ORDER BY a.timestamp ASC
    ");
    $punchesStmt->execute([':user_id' => $userId, ':work_date' => $workDate]);
    $punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular productive_seconds usando lógica de INTERVALOS (paid state periods)
    $productiveSeconds = 0;
    $firstEntry = null;
    $lastExit = null;
    $lunchCount = 0;
    $breakCount = 0;
    $meetingCount = 0;
    
    $inPaidState = false;
    $paidStartTime = null;
    $lastPaidPunchTime = null;
    
    for ($i = 0; $i < count($punches); $i++) {
        $currentPunch = $punches[$i];
        $currentType = $currentPunch['type'];
        $currentTime = strtotime($currentPunch['timestamp']);
        $isPaid = (int)$currentPunch['is_paid'] === 1;
        
        // Track first entry and last exit for display
        if ($currentType === 'Entry' && $firstEntry === null) {
            $firstEntry = $currentPunch['timestamp'];
        }
        if ($currentType === 'Exit') {
            $lastExit = $currentPunch['timestamp'];
        }
        
        // Count lunch, break, meeting
        if ($currentType === 'Lunch') $lunchCount++;
        if ($currentType === 'Break') $breakCount++;
        if (in_array($currentType, ['Meeting', 'Coaching'])) $meetingCount++;
        
        // Interval logic for paid periods
        if ($isPaid) {
            $lastPaidPunchTime = $currentTime;
            
            if (!$inPaidState) {
                // Start of paid period
                $paidStartTime = $currentTime;
                $inPaidState = true;
            }
        } elseif (!$isPaid && $inPaidState) {
            // End of paid period
            if ($paidStartTime !== null && $lastPaidPunchTime !== null) {
                $productiveSeconds += ($lastPaidPunchTime - $paidStartTime);
            }
            $inPaidState = false;
            $paidStartTime = null;
            $lastPaidPunchTime = null;
        }
    }
    
    // If day ends in paid state, count until last paid punch
    if ($inPaidState && $paidStartTime !== null && $lastPaidPunchTime !== null) {
        $productiveSeconds += ($lastPaidPunchTime - $paidStartTime);
    }
    
    // Si no hay last_exit, usar hora de salida programada
    if ($lastExit === null && count($punches) > 0) {
        $lastExit = $workDate . ' ' . $exitTime;
    }
    
    $dailyRowsRaw[] = [
        'employee' => $row['employee'],
        'username' => $row['username'],
        'department_name' => $row['department_name'],
        'work_date' => $workDate,
        'first_entry' => $firstEntry,
        'last_exit' => $lastExit,
        'lunch_seconds' => $lunchCount * $lunchSeconds,
        'break_seconds' => $breakCount * $breakSeconds,
        'meeting_seconds' => $meetingCount * $meetingSeconds,
        'productive_seconds' => max(0, $productiveSeconds)
    ];
}

$dailyRows = [];
$monthlyAggregates = [];
$adherenceAccumulator = 0;
$adherenceSamples = 0;
$totalEarnedUsd = 0;
$totalEarnedDop = 0;
$totalLate = 0;
$totalProductiveSeconds = 0;

foreach ($dailyRowsRaw as $row) {
    $productive = max(0, (int) $row['productive_seconds']);
    $comp = $compensation[$row['username']] ?? [
        'hourly_rate' => 0.0,
        'hourly_rate_dop' => 0.0,
        'monthly_salary' => 0.0,
        'monthly_salary_dop' => 0.0,
        'department_name' => null,
    ];
    $rateUsd = (float) $comp['hourly_rate'];
    $rateDop = (float) ($comp['hourly_rate_dop'] ?? 0.0);
    $departmentName = $row['department_name'] ?? ($comp['department_name'] ?? 'Sin departamento');
    $monthlySalaryUsd = (float) $comp['monthly_salary'];
    $monthlySalaryDop = (float) ($comp['monthly_salary_dop'] ?? 0.0);
    $amountUsd = calculateAmountFromSeconds($productive, $rateUsd);
    $amountDop = calculateAmountFromSeconds($productive, $rateDop);
    $adherencePercent = $productive > 0
        ? min(round(($productive / $scheduledSecondsPerDay) * 100, 1), 999)
        : 0.0;

    $status = 'Sin datos';
    if ($productive >= $scheduledSecondsPerDay) {
        $status = 'En meta';
    } elseif ($productive > 0) {
        $status = 'Parcial';
    }

    $isLate = false;
    if (!empty($row['first_entry'])) {
        $entryMoment = strtotime($row['first_entry']);
        $scheduledMoment = strtotime($row['work_date'] . ' ' . $entryTime);
        if ($entryMoment !== false && $scheduledMoment !== false && $entryMoment > $scheduledMoment) {
            $isLate = true;
            $totalLate++;
        }
    }

    $dailyRows[] = [
        'employee' => $row['employee'],
        'username' => $row['username'],
        'department' => $departmentName,
        'work_date' => $row['work_date'],
        'first_entry' => $row['first_entry'],
        'last_exit' => $row['last_exit'],
        'lunch_seconds' => (int) $row['lunch_seconds'],
        'break_seconds' => (int) $row['break_seconds'],
        'meeting_seconds' => (int) $row['meeting_seconds'],
        'productive_seconds' => $productive,
        'adherence_percent' => $adherencePercent,
        'status' => $status,
        'amount_usd' => $amountUsd,
        'amount_dop' => $amountDop,
        'is_late' => $isLate,
    ];

    if (!isset($monthlyAggregates[$row['username']])) {
        $monthlyAggregates[$row['username']] = [
            'employee' => $row['employee'],
            'username' => $row['username'],
            'department' => $departmentName,
            'days_worked' => 0,
            'productive_seconds' => 0,
            'lunch_seconds' => 0,
            'break_seconds' => 0,
            'meeting_seconds' => 0,
            'late_days' => 0,
            'amount_usd' => 0.0,
            'amount_dop' => 0.0,
            'hourly_rate_usd' => $rateUsd,
            'hourly_rate_dop' => $rateDop,
            'monthly_salary_usd' => $monthlySalaryUsd,
            'monthly_salary_dop' => $monthlySalaryDop,
        ];
    }

    $monthlyAggregates[$row['username']]['days_worked']++;
    $monthlyAggregates[$row['username']]['productive_seconds'] += $productive;
    $monthlyAggregates[$row['username']]['lunch_seconds'] += (int) $row['lunch_seconds'];
    $monthlyAggregates[$row['username']]['break_seconds'] += (int) $row['break_seconds'];
    $monthlyAggregates[$row['username']]['meeting_seconds'] += (int) $row['meeting_seconds'];
    $monthlyAggregates[$row['username']]['amount_usd'] += $amountUsd;
    $monthlyAggregates[$row['username']]['amount_dop'] += $amountDop;
    if ($isLate) {
        $monthlyAggregates[$row['username']]['late_days']++;
    }

    if ($productive > 0) {
        $adherenceAccumulator += min($adherencePercent, 100);
        $adherenceSamples++;
    }

    $totalEarnedUsd += $amountUsd;
    $totalEarnedDop += $amountDop;
    $totalProductiveSeconds += $productive;
}
$monthlySummary = array_values($monthlyAggregates);
usort($monthlySummary, static function (array $a, array $b): int {
    return strcasecmp($a['employee'], $b['employee']);
});

$totalMonthlyBaseUsd = 0.0;
$totalMonthlyBaseDop = 0.0;
$departmentAggregates = [];
foreach ($monthlySummary as &$item) {
    $expectedSeconds = max($item['days_worked'] * $scheduledSecondsPerDay, 1);
    $item['hours'] = $item['productive_seconds'] / 3600;
    $item['expected_hours'] = $expectedSeconds / 3600;
    $item['adherence_percent'] = $item['productive_seconds'] > 0
        ? min(round(($item['productive_seconds'] / $expectedSeconds) * 100, 1), 999)
        : 0.0;
    $totalMonthlyBaseUsd += $item['monthly_salary_usd'];
    $totalMonthlyBaseDop += $item['monthly_salary_dop'];

    $deptKey = $item['department'] ?? 'Sin departamento';
    if (!isset($departmentAggregates[$deptKey])) {
        $departmentAggregates[$deptKey] = [
            'name' => $deptKey,
            'members' => 0,
            'hours' => 0.0,
            'actual_pay_usd' => 0.0,
            'actual_pay_dop' => 0.0,
            'monthly_salary_usd' => 0.0,
            'monthly_salary_dop' => 0.0,
        ];
    }
    $departmentAggregates[$deptKey]['members']++;
    $departmentAggregates[$deptKey]['hours'] += $item['hours'];
    $departmentAggregates[$deptKey]['actual_pay_usd'] += $item['amount_usd'];
    $departmentAggregates[$deptKey]['actual_pay_dop'] += $item['amount_dop'];
    $departmentAggregates[$deptKey]['monthly_salary_usd'] += $item['monthly_salary_usd'];
    $departmentAggregates[$deptKey]['monthly_salary_dop'] += $item['monthly_salary_dop'];
    $item['difference_usd'] = $item['amount_usd'] - $item['monthly_salary_usd'];
    $item['difference_dop'] = $item['amount_dop'] - $item['monthly_salary_dop'];
}
unset($item);

$departmentSummaryList = array_values($departmentAggregates);
usort($departmentSummaryList, static function (array $a, array $b): int {
    return $b['actual_pay_usd'] <=> $a['actual_pay_usd'];
});

$departmentChartData = [
    'labels' => [],
    'actual_usd' => [],
    'base_usd' => [],
    'actual_dop' => [],
    'base_dop' => [],
];
foreach ($departmentSummaryList as $dept) {
    $departmentChartData['labels'][] = $dept['name'];
    $departmentChartData['actual_usd'][] = round($dept['actual_pay_usd'], 2);
    $departmentChartData['base_usd'][] = round($dept['monthly_salary_usd'], 2);
    $departmentChartData['actual_dop'][] = round($dept['actual_pay_dop'], 2);
    $departmentChartData['base_dop'][] = round($dept['monthly_salary_dop'], 2);
}

$payrollVarianceUsd = $totalEarnedUsd - $totalMonthlyBaseUsd;
$payrollVarianceDop = $totalEarnedDop - $totalMonthlyBaseDop;

$averageAdherence = $adherenceSamples > 0 ? round($adherenceAccumulator / $adherenceSamples, 1) : 0;
$totalEmployees = count($monthlySummary);
$totalHoursWorked = $totalProductiveSeconds / 3600;

$trendStart = date('Y-m-01', strtotime('-5 months'));
$trendEnd = date('Y-m-t');

$trendSql = "
    SELECT
        DATE_FORMAT(DATE(a.timestamp), '%Y-%m') AS month,
        COUNT(DISTINCT a.user_id) AS total_employees,
        AVG(
            CASE
                WHEN (SELECT MIN(a2.timestamp) FROM attendance a2 
                      WHERE a2.user_id = a.user_id 
                      AND DATE(a2.timestamp) = DATE(a.timestamp) 
                      AND a2.type = 'Entry') IS NULL THEN 0
                ELSE IFNULL(
                    TIMESTAMPDIFF(
                        SECOND,
                        (SELECT MIN(a3.timestamp) FROM attendance a3 
                         WHERE a3.user_id = a.user_id 
                         AND DATE(a3.timestamp) = DATE(a.timestamp) 
                         AND a3.type = 'Entry'),
                        IFNULL(
                            (SELECT MAX(a4.timestamp) FROM attendance a4 
                             WHERE a4.user_id = a.user_id 
                             AND DATE(a4.timestamp) = DATE(a.timestamp) 
                             AND a4.type = 'Exit'),
                            CONCAT(DATE(a.timestamp), ' ', :exit_time)
                        )
                    ),
                    0
                )
            END
        ) AS avg_work_seconds,
        SUM(
            CASE
                WHEN (SELECT MIN(a5.timestamp) FROM attendance a5 
                      WHERE a5.user_id = a.user_id 
                      AND DATE(a5.timestamp) = DATE(a.timestamp) 
                      AND a5.type = 'Entry') IS NOT NULL 
                     AND TIME((SELECT MIN(a6.timestamp) FROM attendance a6 
                               WHERE a6.user_id = a.user_id 
                               AND DATE(a6.timestamp) = DATE(a.timestamp) 
                               AND a6.type = 'Entry')) > :entry_time THEN 1
                ELSE 0
            END
        ) AS late_count
    FROM attendance a
    WHERE a.timestamp BETWEEN :trend_start AND :trend_end
    GROUP BY DATE_FORMAT(DATE(a.timestamp), '%Y-%m'), a.user_id, DATE(a.timestamp)
    ORDER BY month ASC
";

$trendStmt = $pdo->prepare($trendSql);
$trendStmt->execute([
    ':trend_start' => $trendStart . ' 00:00:00',
    ':trend_end' => $trendEnd . ' 23:59:59',
    ':exit_time' => $exitTime,
    ':entry_time' => $entryTime,
]);
$trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

$departmentChartJson = json_encode($departmentChartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

include __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="css/pagination-styles.css">
<section class="space-y-10">
    <div class="glass-card">
        <div class="panel-heading">
            <div>
                <span class="tag-pill">Reporte de adherencia</span>
                <h2>Seguimiento mensual de jornadas</h2>
            </div>
            <form method="get" class="flex flex-wrap items-center gap-3">
                <label class="text-sm text-slate-400">
                    Mes
                    <input type="month" name="month" value="<?= htmlspecialchars($selectedMonth) ?>" class="mt-1 w-40">
                </label>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-filter"></i>
                    Aplicar
                </button>
            </form>
        </div>
        <p class="text-sm text-slate-400 max-w-2xl">
            Los calculos consideran los objetivos configurados en la seccion de horarios. Las lineas de tiempo omiten descansos y comidas para evaluar las horas productivas efectivas.
        </p>
    </div>

    <div class="grid gap-6 grid-cols-1 md:grid-cols-2 xl:grid-cols-5">
        <div class="metric-card">
            <span class="label">Colaboradores activos</span>
            <span class="value"><?= number_format($totalEmployees) ?></span>
            <span class="trend neutral"><i class="fas fa-users"></i> En el mes seleccionado</span>
        </div>
        <div class="metric-card">
            <span class="label">Adherencia promedio</span>
            <span class="value"><?= number_format($averageAdherence, 1) ?>%</span>
            <span class="trend <?= $averageAdherence >= 95 ? 'positive' : ($averageAdherence >= 80 ? 'neutral' : 'negative') ?>">
                <i class="fas fa-chart-line"></i> Objetivo 100%
            </span>
        </div>
        <div class="metric-card">
            <span class="label">Horas productivas</span>
            <span class="value"><?= number_format($totalHoursWorked, 1) ?> h</span>
            <span class="trend neutral"><i class="fas fa-clock"></i> Calculadas sin descansos</span>
        </div>
        <div class="metric-card">
            <span class="label">Pago horas (USD)</span>
            <span class="value">$<?= number_format($totalEarnedUsd, 2) ?></span>
            <span class="trend <?= $payrollVarianceUsd >= 0 ? 'positive' : 'negative' ?>">
                <i class="fas fa-balance-scale"></i>
                Base $<?= number_format($totalMonthlyBaseUsd, 2) ?> (<?= $payrollVarianceUsd >= 0 ? '+' : '' ?>$<?= number_format($payrollVarianceUsd, 2) ?>)
            </span>
        </div>
        <div class="metric-card">
            <span class="label">Pago horas (DOP)</span>
            <span class="value">RD$<?= number_format($totalEarnedDop, 2) ?></span>
            <span class="trend <?= $payrollVarianceDop >= 0 ? 'positive' : 'negative' ?>">
                <i class="fas fa-coins"></i>
                Base RD$<?= number_format($totalMonthlyBaseDop, 2) ?> (<?= $payrollVarianceDop >= 0 ? '+' : '' ?>RD$<?= number_format($payrollVarianceDop, 2) ?>)
            </span>
        </div>
    </div>

    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h2>Analitica por departamento</h2>
                <p class="text-sm text-slate-400">Distribucion de horas y pagos contra la base mensual configurada.</p>
            </div>
        </div>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="section-card p-6">
                <canvas id="departmentChart" class="w-full h-72"></canvas>
            </div>
            <div class="section-card p-0 overflow-hidden">
                <table class="table-auto w-full text-sm">
                    <thead>
                        <tr>
                            <th>Departamento</th>
                            <th>Integrantes</th>
                            <th>Horas</th>
                            <th>Pago USD</th>
                            <th>Base USD</th>
                            <th>Dif USD</th>
                            <th>Pago DOP</th>
                            <th>Base DOP</th>
                            <th>Dif DOP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($departmentSummaryList)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-6 text-slate-400">No hay informacion disponible para el periodo.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($departmentSummaryList as $dept): ?>
                                <?php
                                    $deptDifferenceUsd = $dept['actual_pay_usd'] - $dept['monthly_salary_usd'];
                                    $deptDifferenceDop = $dept['actual_pay_dop'] - $dept['monthly_salary_dop'];
                                ?>
                                <tr>
                                    <td class="font-semibold text-primary"><?= htmlspecialchars($dept['name']) ?></td>
                                    <td><?= number_format($dept['members']) ?></td>
                                    <td><?= number_format($dept['hours'], 1) ?> h</td>
                                    <td>$<?= number_format($dept['actual_pay_usd'], 2) ?></td>
                                    <td>$<?= number_format($dept['monthly_salary_usd'], 2) ?></td>
                                    <td class="<?= $deptDifferenceUsd >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                        <?= $deptDifferenceUsd >= 0 ? '+' : '' ?>$<?= number_format($deptDifferenceUsd, 2) ?>
                                    </td>
                                    <td>RD$<?= number_format($dept['actual_pay_dop'], 2) ?></td>
                                    <td>RD$<?= number_format($dept['monthly_salary_dop'], 2) ?></td>
                                    <td class="<?= $deptDifferenceDop >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                        <?= $deptDifferenceDop >= 0 ? '+' : '' ?>RD$<?= number_format($deptDifferenceDop, 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="glass-card">
        <div class="panel-heading">
            <div>
                <h2>Evolucion de los ultimos seis meses</h2>
                <p class="text-sm text-slate-400">Promedio de horas trabajadas por colaborador y llegadas tarde registradas.</p>
            </div>
        </div>
        <div class="relative h-72">
            <canvas id="trendChart" class="w-full h-full"></canvas>
        </div>
    </div>

    <div class="glass-card">
        <div class="panel-heading">
            <div>
                <h2>Detalle diario</h2>
                <p class="text-sm text-slate-400">Incluye horas productivas, entradas tardias y montos generados.</p>
            </div>
            <div class="text-sm text-slate-400">
                Pagina <?= number_format($currentPage) ?> de <?= number_format($totalPages) ?> (<?= number_format($totalRecords) ?> registros)
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table-auto w-full text-sm">
                <thead>
                    <tr>
                        <th>Colaborador</th>
                        <th>Departamento</th>
                        <th>Fecha</th>
                        <th>Entrada</th>
                        <th>Salida</th>
                        <th>Productivo</th>
                        <th>Adherencia</th>
                        <th>Pago USD</th>
                        <th>Pago DOP</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dailyRows)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-6 text-slate-400">No se encontraron registros para el mes seleccionado.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dailyRows as $row): ?>
                            <tr>
                                <td>
                                    <div class="font-semibold"><?= htmlspecialchars($row['employee']) ?></div>
                                    <div class="text-xs text-slate-400"><?= htmlspecialchars($row['username']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($row['department']) ?></td>
                                <td><?= htmlspecialchars($row['work_date']) ?></td>
                                <td>
                                    <?php if (!empty($row['first_entry'])): ?>
                                        <span class="<?= $row['is_late'] ? 'badge badge--danger' : 'badge' ?>">
                                            <?= htmlspecialchars(date('H:i', strtotime($row['first_entry']))) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400">Sin registro</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(!empty($row['last_exit']) ? date('H:i', strtotime($row['last_exit'])) : 'Sin registro') ?></td>
                                <td><?= htmlspecialchars(formatHoursMinutes($row['productive_seconds'])) ?></td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="w-24 h-2 rounded-full overflow-hidden" style="background-color: rgba(148, 163, 184, 0.25);">
                                            <div class="h-2" style="width: <?= min($row['adherence_percent'], 100) ?>%; background-color: var(--accent-cyan);"></div>
                                        </div>
                                        <span><?= number_format($row['adherence_percent'], 1) ?>%</span>
                                    </div>
                                </td>
                                <td>$<?= number_format($row['amount_usd'], 2) ?></td>
                                <td>RD$<?= number_format($row['amount_dop'], 2) ?></td>
                                <td><?= htmlspecialchars($row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    Mostrando <strong><?= number_format(min($totalRecords, $offset + 1)) ?></strong> a
                    <strong><?= number_format(min($totalRecords, $offset + $recordsPerPage)) ?></strong> de
                    <strong><?= number_format($totalRecords) ?></strong> registros
                </div>
                <div class="pagination-controls">
                    <?php if ($currentPage > 1): ?>
                        <a class="pagination-btn" href="?month=<?= urlencode($selectedMonth) ?>&page=<?= $currentPage - 1 ?>">
                            <i class="fas fa-chevron-left"></i>
                            <span>Anterior</span>
                        </a>
                    <?php endif; ?>
                    
                    <div class="pagination-pages">
                        <?php
                        // Calculate page range to display
                        $range = 2; // Pages to show before and after current page
                        $startPage = max(1, $currentPage - $range);
                        $endPage = min($totalPages, $currentPage + $range);
                        
                        // First page
                        if ($startPage > 1): ?>
                            <a class="page-btn" href="?month=<?= urlencode($selectedMonth) ?>&page=1">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="page-ellipsis">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php if ($i == $currentPage): ?>
                                <span class="page-btn active"><?= $i ?></span>
                            <?php else: ?>
                                <a class="page-btn" href="?month=<?= urlencode($selectedMonth) ?>&page=<?= $i ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php // Last page
                        if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="page-ellipsis">...</span>
                            <?php endif; ?>
                            <a class="page-btn" href="?month=<?= urlencode($selectedMonth) ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($currentPage < $totalPages): ?>
                        <a class="pagination-btn primary" href="?month=<?= urlencode($selectedMonth) ?>&page=<?= $currentPage + 1 ?>">
                            <span>Siguiente</span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="glass-card">
        <div class="panel-heading">
            <div>
                <h2>Resumen mensual por colaborador</h2>
                <p class="text-sm text-slate-400">Comparativo entre horas esperadas y productivas, con registro de retardos.</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table-auto w-full text-sm">
                <thead>
                    <tr>
                        <th>Colaborador</th>
                        <th>Departamento</th>
                        <th>Dias</th>
                        <th>Horas esperadas</th>
                        <th>Horas productivas</th>
                        <th>Adherencia</th>
                        <th>Retardos</th>
                        <th>Pago USD</th>
                        <th>Base USD</th>
                        <th>Dif USD</th>
                        <th>Pago DOP</th>
                        <th>Base DOP</th>
                        <th>Dif DOP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthlySummary)): ?>
                        <tr>
                            <td colspan="13" class="text-center py-6 text-slate-400">No se registraron jornadas para el periodo.</td>
                        </tr>
                    <?php else: ?>
                            <?php foreach ($monthlySummary as $item): ?>
                                <tr>
                                    <td>
                                        <div class="font-semibold"><?= htmlspecialchars($item['employee']) ?></div>
                                        <div class="text-xs text-slate-400"><?= htmlspecialchars($item['username']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($item['department']) ?></td>
                                    <td><?= number_format($item['days_worked']) ?></td>
                                    <td><?= number_format($item['expected_hours'], 1) ?> h</td>
                                    <td><?= number_format($item['hours'], 1) ?> h</td>
                                    <td><?= number_format($item['adherence_percent'], 1) ?>%</td>
                                    <td><?= number_format($item['late_days']) ?></td>
                                    <td>$<?= number_format($item['amount_usd'], 2) ?></td>
                                    <td>$<?= number_format($item['monthly_salary_usd'], 2) ?></td>
                                    <td class="<?= $item['difference_usd'] >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                        <?= $item['difference_usd'] >= 0 ? '+' : '' ?>$<?= number_format($item['difference_usd'], 2) ?>
                                    </td>
                                    <td>RD$<?= number_format($item['amount_dop'], 2) ?></td>
                                    <td>RD$<?= number_format($item['monthly_salary_dop'], 2) ?></td>
                                    <td class="<?= $item['difference_dop'] >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                        <?= $item['difference_dop'] >= 0 ? '+' : '' ?>RD$<?= number_format($item['difference_dop'], 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
const departmentChartData = <?= $departmentChartJson ?>;
const trendData = <?= json_encode($trendRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const trendMonths = trendData.map(item => item.month);
const trendHours = trendData.map(item => {
    const seconds = Number(item.avg_work_seconds || 0);
    return (seconds / 3600).toFixed(2);
});
const trendLate = trendData.map(item => Number(item.late_count || 0));

const palette = getComputedStyle(document.body);
const cyan = palette.getPropertyValue('--accent-cyan') || '#22d3ee';
const emerald = palette.getPropertyValue('--accent-emerald') || '#34d399';
const textColor = palette.getPropertyValue('--text-primary') || '#e2e8f0';
const mutedColor = palette.getPropertyValue('--text-muted') || '#94a3b8';
const amber = '#f59e0b';
const violet = '#8b5cf6';

const departmentCtx = document.getElementById('departmentChart');
if (typeof Chart !== 'undefined') {
    if (departmentCtx && departmentChartData.labels.length) {
        new Chart(departmentCtx, {
            type: 'bar',
            data: {
                labels: departmentChartData.labels,
                datasets: [
                    {
                        label: 'Pago USD',
                        data: departmentChartData.actual_usd,
                        backgroundColor: cyan.trim(),
                        borderRadius: 8,
                    },
                    {
                        label: 'Base USD',
                        data: departmentChartData.base_usd,
                        backgroundColor: emerald.trim(),
                        borderRadius: 8,
                    },
                    {
                        label: 'Pago DOP',
                        data: departmentChartData.actual_dop,
                        backgroundColor: amber,
                        borderRadius: 8,
                    },
                    {
                        label: 'Base DOP',
                        data: departmentChartData.base_dop,
                        backgroundColor: violet,
                        borderRadius: 8,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: textColor.trim(),
                            font: { family: 'Inter' }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: mutedColor.trim() },
                        grid: { color: 'rgba(148, 163, 184, 0.12)' }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: mutedColor.trim() },
                        grid: { color: 'rgba(148, 163, 184, 0.12)' }
                    }
                }
            }
        });
    }
}

const ctx = document.getElementById('trendChart');
if (ctx && typeof Chart !== 'undefined') {
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: trendMonths,
            datasets: [
                {
                    label: 'Horas promedio',
                    data: trendHours,
                    yAxisID: 'y',
                    borderColor: cyan.trim(),
                    backgroundColor: 'rgba(34, 211, 238, 0.15)',
                    tension: 0.35,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: cyan.trim(),
                },
                {
                    label: 'Retardos',
                    data: trendLate,
                    yAxisID: 'y1',
                    borderColor: emerald.trim(),
                    backgroundColor: 'rgba(52, 211, 153, 0.15)',
                    tension: 0.25,
                    fill: false,
                    pointRadius: 4,
                    pointBackgroundColor: emerald.trim(),
                    type: 'bar',
                    barThickness: 26,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    labels: {
                        color: textColor.trim(),
                        font: {
                            family: 'Inter'
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label(context) {
                            if (context.dataset.label === 'Horas promedio') {
                                return `${context.dataset.label}: ${context.parsed.y} h`;
                            }
                            return `${context.dataset.label}: ${context.parsed.y}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Horas',
                        color: mutedColor.trim()
                    },
                    ticks: {
                        color: mutedColor.trim()
                    },
                    grid: {
                        color: 'rgba(148, 163, 184, 0.15)'
                    }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Retardos',
                        color: mutedColor.trim()
                    },
                    ticks: {
                        color: mutedColor.trim()
                    },
                    grid: {
                        display: false
                    }
                },
                x: {
                    ticks: {
                        color: mutedColor.trim()
                    },
                    grid: {
                        color: 'rgba(148, 163, 184, 0.12)'
                    }
                }
            }
        }
    });
}
</script>
<?php include __DIR__ . '/footer.php'; ?>
