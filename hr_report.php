<?php
session_start();
require_once __DIR__ . '/db.php';

ensurePermission('hr_report');

function formatHoursMinutes(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return sprintf('%d:%02d', $hours, $minutes);
}

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

$compensation = getUserCompensation($pdo);
$schedule = getScheduleConfig($pdo);

$exitTime = $schedule['exit_time'] ?? '19:00:00';
$lunchMinutes = max(0, (int) ($schedule['lunch_minutes'] ?? 45));
$breakMinutes = max(0, (int) ($schedule['break_minutes'] ?? 15));
$meetingMinutes = max(0, (int) ($schedule['meeting_minutes'] ?? 45));

$lunchSeconds = $lunchMinutes * 60;
$breakSeconds = $breakMinutes * 60;
$meetingSeconds = $meetingMinutes * 60;

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

$employeeStmt = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name");
$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$departmentsList = getAllDepartments($pdo);

// Get paid attendance types for accurate calculation
$paidTypes = getPaidAttendanceTypeSlugs($pdo);

// Calculate productive hours based on PAID punch types only (DISPONIBLE, WASAPI, DIGITACION)
// This matches the payroll calculation logic
$payrollRows = [];
$totalPunchesAnalyzed = 0;
$totalPaidPunches = 0;

if (!empty($paidTypes)) {
    $userQuery = "SELECT id, full_name, username, department_id FROM users";
    if ($employeeFilter !== 'all') {
        $userQuery .= " WHERE id = " . (int)$employeeFilter;
    }
    $userStmt = $pdo->query($userQuery);
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        $userId = $user['id'];
        
        // Get department name
        $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
        $deptStmt->execute([$user['department_id']]);
        $deptName = $deptStmt->fetchColumn();
        
        // Get all punches for this user in the period
        $punchesStmt = $pdo->prepare("
            SELECT timestamp, type, DATE(timestamp) as work_date
            FROM attendance
            WHERE user_id = ?
            AND timestamp BETWEEN ? AND ?
            ORDER BY timestamp ASC
        ");
        $punchesStmt->execute([$userId, $startBound, $endBound]);
        $punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalPunchesAnalyzed += count($punches);
        
        // Group by date
        $punchesByDate = [];
        foreach ($punches as $punch) {
            $date = $punch['work_date'];
            if (!isset($punchesByDate[$date])) {
                $punchesByDate[$date] = [];
            }
            $punchesByDate[$date][] = $punch;
        }
        
        // Calculate productive seconds using INTERVAL logic (paid state periods)
        $totalProductiveSeconds = 0;
        $daysWorked = count($punchesByDate);
        $paidTypesUpper = array_map('strtoupper', $paidTypes);
        
        foreach ($punchesByDate as $date => $dayPunches) {
            $inPaidState = false;
            $paidStartTime = null;
            $lastPaidPunchTime = null;
            
            foreach ($dayPunches as $i => $punch) {
                $punchTime = strtotime($punch['timestamp']);
                $punchType = strtoupper($punch['type']);
                $isPaid = in_array($punchType, $paidTypesUpper);
                
                if ($isPaid) {
                    $totalPaidPunches++;
                    $lastPaidPunchTime = $punchTime;
                    
                    if (!$inPaidState) {
                        // Start of paid period
                        $paidStartTime = $punchTime;
                        $inPaidState = true;
                    }
                } elseif (!$isPaid && $inPaidState) {
                    // End of paid period
                    if ($paidStartTime !== null && $lastPaidPunchTime !== null) {
                        $totalProductiveSeconds += ($lastPaidPunchTime - $paidStartTime);
                    }
                    $inPaidState = false;
                    $paidStartTime = null;
                    $lastPaidPunchTime = null;
                }
            }
            
            // If day ends in paid state, count until last paid punch
            if ($inPaidState && $paidStartTime !== null && $lastPaidPunchTime !== null) {
                $totalProductiveSeconds += ($lastPaidPunchTime - $paidStartTime);
            }
        }
        
        if ($totalProductiveSeconds > 0 || $daysWorked > 0) {
            $payrollRows[] = [
                'id' => $userId,
                'full_name' => $user['full_name'],
                'username' => $user['username'],
                'department_id' => $user['department_id'],
                'department_name' => $deptName,
                'days_worked' => $daysWorked,
                'productive_seconds' => $totalProductiveSeconds
            ];
        }
    }
}

$employeeSummaries = [];
$departmentSummaries = [];
$seenUsers = [];
$totalHours = 0.0;
$totalActualPayUsd = 0.0;
$totalActualPayDop = 0.0;
$totalMonthlyBaseUsd = 0.0;
$totalMonthlyBaseDop = 0.0;
$totalDaysWorked = 0;
$totalEmployees = 0;
$sumHourlyRatesUsd = 0.0;
$sumHourlyRatesDop = 0.0;

foreach ($payrollRows as $row) {
    $username = $row['username'];
    $comp = $compensation[$username] ?? [
        'hourly_rate' => 0.0,
        'monthly_salary' => 0.0,
        'hourly_rate_dop' => 0.0,
        'monthly_salary_dop' => 0.0,
        'preferred_currency' => 'USD',
        'department_id' => $row['department_id'],
        'department_name' => $row['department_name'],
    ];

    $hourlyRateUsd = (float) $comp['hourly_rate'];
    $hourlyRateDop = (float) $comp['hourly_rate_dop'];
    $monthlySalaryUsd = (float) $comp['monthly_salary'];
    $monthlySalaryDop = (float) $comp['monthly_salary_dop'];
    $departmentName = $row['department_name'] ?? ($comp['department_name'] ?? 'Sin departamento');

    $productiveSeconds = (int) $row['productive_seconds'];
    $hours = $productiveSeconds > 0 ? ($productiveSeconds / 3600) : 0.0;
    $actualPayUsd = calculateAmountFromSeconds($productiveSeconds, $hourlyRateUsd);
    $actualPayDop = calculateAmountFromSeconds($productiveSeconds, $hourlyRateDop);

    if (!isset($seenUsers[$username])) {
        $totalMonthlyBaseUsd += $monthlySalaryUsd;
        $totalMonthlyBaseDop += $monthlySalaryDop;
        if ($hourlyRateUsd > 0) {
            $sumHourlyRatesUsd += $hourlyRateUsd;
        }
        if ($hourlyRateDop > 0) {
            $sumHourlyRatesDop += $hourlyRateDop;
        }
        $seenUsers[$username] = true;
    }

    $employeeSummaries[] = [
        'user_id' => (int) $row['id'],
        'full_name' => $row['full_name'],
        'username' => $username,
        'department' => $departmentName,
        'days_worked' => (int) $row['days_worked'],
        'hours' => $hours,
        'hourly_rate_usd' => $hourlyRateUsd,
        'hourly_rate_dop' => $hourlyRateDop,
        'monthly_salary_usd' => $monthlySalaryUsd,
        'monthly_salary_dop' => $monthlySalaryDop,
        'actual_pay_usd' => $actualPayUsd,
        'actual_pay_dop' => $actualPayDop,
        'difference_usd' => $actualPayUsd - $monthlySalaryUsd,
        'difference_dop' => $actualPayDop - $monthlySalaryDop,
    ];

    $deptKey = $departmentName ?: 'Sin departamento';
    if (!isset($departmentSummaries[$deptKey])) {
        $departmentSummaries[$deptKey] = [
            'name' => $deptKey,
            'members' => 0,
            'hours' => 0.0,
            'actual_pay_usd' => 0.0,
            'actual_pay_dop' => 0.0,
            'monthly_salary_usd' => 0.0,
            'monthly_salary_dop' => 0.0,
        ];
    }
    $departmentSummaries[$deptKey]['members']++;
    $departmentSummaries[$deptKey]['hours'] += $hours;
    $departmentSummaries[$deptKey]['actual_pay_usd'] += $actualPayUsd;
    $departmentSummaries[$deptKey]['actual_pay_dop'] += $actualPayDop;
    $departmentSummaries[$deptKey]['monthly_salary_usd'] += $monthlySalaryUsd;
    $departmentSummaries[$deptKey]['monthly_salary_dop'] += $monthlySalaryDop;

    $totalHours += $hours;
    $totalActualPayUsd += $actualPayUsd;
    $totalActualPayDop += $actualPayDop;
    $totalDaysWorked += (int) $row['days_worked'];
}

$totalEmployees = count($employeeSummaries);
$averageHoursPerEmployee = $totalEmployees > 0 ? $totalHours / $totalEmployees : 0.0;
$averageHourlyRateUsd = $totalEmployees > 0 ? ($sumHourlyRatesUsd / $totalEmployees) : 0.0;
$averageHourlyRateDop = $totalEmployees > 0 ? ($sumHourlyRatesDop / $totalEmployees) : 0.0;
$payrollVarianceUsd = $totalActualPayUsd - $totalMonthlyBaseUsd;
$payrollVarianceDop = $totalActualPayDop - $totalMonthlyBaseDop;

usort($employeeSummaries, static function (array $a, array $b): int {
    return strcasecmp($a['full_name'], $b['full_name']);
});

$departmentSummaryList = array_values($departmentSummaries);
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

$topEmployees = $employeeSummaries;
usort($topEmployees, static function (array $a, array $b): int {
    return $b['actual_pay_usd'] <=> $a['actual_pay_usd'];
});
$topEmployees = array_slice($topEmployees, 0, 6);
$employeeChartData = [
    'labels' => array_map(static fn(array $row) => $row['full_name'], $topEmployees),
    'hours' => array_map(static fn(array $row) => round($row['hours'], 2), $topEmployees),
    'actual_usd' => array_map(static fn(array $row) => round($row['actual_pay_usd'], 2), $topEmployees),
    'actual_dop' => array_map(static fn(array $row) => round($row['actual_pay_dop'], 2), $topEmployees),
];

// Calculate daily summaries using PAID punch types
$dailySummaries = [];
$dailyTotalHours = 0.0;
$dailyTotalAmountUsd = 0.0;
$dailyTotalAmountDop = 0.0;

if (!empty($paidTypes)) {
    $userDailyQuery = "SELECT id, full_name, username, department_id FROM users";
    if ($employeeFilter !== 'all') {
        $userDailyQuery .= " WHERE id = " . (int)$employeeFilter;
    }
    $userDailyStmt = $pdo->query($userDailyQuery);
    $usersDaily = $userDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($usersDaily as $user) {
        $userId = $user['id'];
        $username = $user['username'];
        
        // Get department name
        $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
        $deptStmt->execute([$user['department_id']]);
        $deptName = $deptStmt->fetchColumn();
        
        $comp = $compensation[$username] ?? [
            'hourly_rate' => 0.0,
            'hourly_rate_dop' => 0.0,
        ];
        
        // Get all punches for this user in the period
        $punchesStmt = $pdo->prepare("
            SELECT timestamp, type, DATE(timestamp) as work_date
            FROM attendance
            WHERE user_id = ?
            AND timestamp BETWEEN ? AND ?
            ORDER BY timestamp ASC
        ");
        $punchesStmt->execute([$userId, $startBound, $endBound]);
        $punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by date
        $punchesByDate = [];
        foreach ($punches as $punch) {
            $date = $punch['work_date'];
            if (!isset($punchesByDate[$date])) {
                $punchesByDate[$date] = [
                    'punches' => [],
                    'first_entry' => null,
                    'last_exit' => null
                ];
            }
            $punchesByDate[$date]['punches'][] = $punch;
            
            // Track first Entry and last Exit for display
            $typeUpper = strtoupper($punch['type']);
            if ($typeUpper === 'ENTRY' && $punchesByDate[$date]['first_entry'] === null) {
                $punchesByDate[$date]['first_entry'] = $punch['timestamp'];
            }
            if ($typeUpper === 'EXIT') {
                $punchesByDate[$date]['last_exit'] = $punch['timestamp'];
            }
        }
        
        $paidTypesUpper = array_map('strtoupper', $paidTypes);
        
        foreach ($punchesByDate as $date => $dayData) {
            $dayPunches = $dayData['punches'];
            $productiveSeconds = 0;
            
            // Calculate using INTERVAL logic (paid state periods)
            $inPaidState = false;
            $paidStartTime = null;
            $lastPaidPunchTime = null;
            
            foreach ($dayPunches as $i => $punch) {
                $punchTime = strtotime($punch['timestamp']);
                $punchType = strtoupper($punch['type']);
                $isPaid = in_array($punchType, $paidTypesUpper);
                
                if ($isPaid) {
                    $lastPaidPunchTime = $punchTime;
                    
                    if (!$inPaidState) {
                        // Start of paid period
                        $paidStartTime = $punchTime;
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
            
            if ($productiveSeconds > 0) {
                $hours = $productiveSeconds / 3600;
                $amountUsd = calculateAmountFromSeconds($productiveSeconds, (float) $comp['hourly_rate']);
                $amountDop = calculateAmountFromSeconds($productiveSeconds, (float) $comp['hourly_rate_dop']);
                
                $dailySummaries[] = [
                    'full_name' => $user['full_name'],
                    'department' => $deptName ?? 'Sin departamento',
                    'work_date' => $date,
                    'first_entry' => $dayData['first_entry'],
                    'last_exit' => $dayData['last_exit'],
                    'hours' => $hours,
                    'amount_usd' => $amountUsd,
                    'amount_dop' => $amountDop,
                ];
                
                $dailyTotalHours += $hours;
                $dailyTotalAmountUsd += $amountUsd;
                $dailyTotalAmountDop += $amountDop;
            }
        }
    }
    
    // Sort by date descending, then by name
    usort($dailySummaries, function($a, $b) {
        $dateComp = strcmp($b['work_date'], $a['work_date']);
        if ($dateComp !== 0) return $dateComp;
        return strcmp($a['full_name'], $b['full_name']);
    });
}

$hoursByDay = [];
$workDates = [];
foreach ($dailySummaries as $row) {
    $dateKey = $row['work_date'];
    $workDates[$dateKey] = true;
    
    if (!isset($hoursByDay[$dateKey])) {
        $hoursByDay[$dateKey] = [
            'date' => $dateKey,
            'hours' => 0.0,
            'amount_usd' => 0.0,
            'amount_dop' => 0.0,
            'records' => 0,
            'collaborators' => [],
        ];
    }
    
    $hoursByDay[$dateKey]['hours'] += $row['hours'];
    $hoursByDay[$dateKey]['amount_usd'] += $row['amount_usd'];
    $hoursByDay[$dateKey]['amount_dop'] += $row['amount_dop'];
    $hoursByDay[$dateKey]['records']++;
    $hoursByDay[$dateKey]['collaborators'][$row['full_name']] = true;
}

$workDatesCount = count($workDates);

foreach ($hoursByDay as &$dayRow) {
    $dayRow['collaborators_count'] = count($dayRow['collaborators']);
    unset($dayRow['collaborators']);
}
unset($dayRow);

$dailyAggregates = array_values($hoursByDay);
usort($dailyAggregates, static function ($a, $b): int {
    return $b['hours'] <=> $a['hours'];
});
$topDaysByHours = array_slice($dailyAggregates, 0, 5);

$topHoursEmployees = $employeeSummaries;
usort($topHoursEmployees, static function (array $a, array $b): int {
    return $b['hours'] <=> $a['hours'];
});
$topHoursEmployees = array_slice($topHoursEmployees, 0, 8);

$paidTypeLabels = array_map('strtoupper', $paidTypes);
$hoursDrillDownPunches = [
    'total_punches' => $totalPunchesAnalyzed,
    'paid_punches' => $totalPaidPunches,
    'daily_rows' => count($dailySummaries),
    'work_dates' => $workDatesCount,
];

$selectedEmployeeName = 'Todos los colaboradores';
if ($employeeFilter !== 'all') {
    foreach ($employees as $employee) {
        if ((int) $employee['id'] === (int) $employeeFilter) {
            $selectedEmployeeName = $employee['full_name'];
            break;
        }
    }
}

$departmentChartJson = json_encode($departmentChartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$employeeChartJson = json_encode($employeeChartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

include __DIR__ . '/header.php';
?>
<style>
.metric-card.actionable {
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative;
}
.metric-card.actionable:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
}
.metric-card .hint {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: var(--accent-cyan, #22d3ee);
    font-size: 0.8rem;
    margin-top: 0.35rem;
}

.hours-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(6, 12, 24, 0.75);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    z-index: 9999;
}
.hours-modal {
    background: linear-gradient(145deg, rgba(15, 23, 42, 0.95), rgba(30, 41, 59, 0.92));
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 18px;
    width: min(1100px, 100%);
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.theme-light .hours-modal {
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.98), rgba(241, 245, 249, 0.95));
    border-color: rgba(148, 163, 184, 0.35);
}
.hours-modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid rgba(148, 163, 184, 0.2);
    gap: 1rem;
}
.hours-modal-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-primary, #e2e8f0);
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
.hours-modal-subtitle {
    color: var(--text-muted, #94a3b8);
    margin-top: 0.25rem;
    font-size: 0.95rem;
}
.hours-modal-close {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    border: 1px solid rgba(148, 163, 184, 0.35);
    background: rgba(255, 255, 255, 0.05);
    color: var(--text-primary, #e2e8f0);
    cursor: pointer;
    transition: all 0.2s ease;
}
.hours-modal-close:hover {
    background: rgba(248, 113, 113, 0.12);
    color: #f87171;
    border-color: rgba(248, 113, 113, 0.35);
}
.hours-modal-body {
    padding: 1.25rem 1.5rem 1.5rem;
    overflow-y: auto;
    max-height: 78vh;
}
.hours-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.75rem;
}
.hours-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.65rem;
    border-radius: 999px;
    background: rgba(34, 211, 238, 0.1);
    border: 1px solid rgba(34, 211, 238, 0.4);
    color: var(--text-primary, #e2e8f0);
    font-size: 0.85rem;
}
.theme-light .hours-chip {
    background: rgba(14, 165, 233, 0.12);
    border-color: rgba(14, 165, 233, 0.35);
}
.hours-mini-card {
    padding: 0.95rem 1rem;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid rgba(148, 163, 184, 0.18);
}
.theme-light .hours-mini-card {
    background: rgba(255, 255, 255, 0.75);
    border-color: rgba(148, 163, 184, 0.35);
}
.hours-mini-card .label {
    font-size: 0.85rem;
    color: var(--text-muted, #94a3b8);
}
.hours-mini-card .value {
    display: block;
    margin-top: 0.35rem;
    font-weight: 700;
    color: var(--text-primary, #e2e8f0);
    font-size: 1.2rem;
}
.hours-section {
    margin-top: 1rem;
    padding: 1rem 0 0;
    border-top: 1px dashed rgba(148, 163, 184, 0.25);
}
.hours-section h4 {
    font-weight: 700;
    color: var(--text-primary, #e2e8f0);
    margin-bottom: 0.5rem;
}
.hours-section p,
.hours-section li {
    color: var(--text-muted, #94a3b8);
    font-size: 0.95rem;
}
.hours-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92rem;
    margin-top: 0.5rem;
}
.hours-table th,
.hours-table td {
    padding: 0.55rem 0.75rem;
    border-bottom: 1px solid rgba(148, 163, 184, 0.15);
    text-align: left;
}
.hours-table th {
    color: var(--text-muted, #94a3b8);
    font-weight: 600;
    font-size: 0.85rem;
    letter-spacing: 0.01em;
}
.hours-table td {
    color: var(--text-primary, #e2e8f0);
}
.theme-light .hours-table th {
    color: #64748b;
}
.theme-light .hours-table td {
    color: #0f172a;
}
.hours-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.6rem;
    border-radius: 999px;
    background: rgba(52, 211, 153, 0.12);
    color: #34d399;
    font-weight: 600;
    font-size: 0.85rem;
}
.hours-muted {
    color: var(--text-muted, #94a3b8);
    font-size: 0.9rem;
}
</style>
<section class="space-y-10">
    <div class="glass-card">
        <div class="panel-heading">
            <div>
                <span class="tag-pill">HR Analytics</span>
                <h2 class="text-primary text-2xl font-semibold mt-2">Resumen de nomina y productividad</h2>
                <p class="text-muted text-sm">Periodo del <?= htmlspecialchars(date('d/m/Y', strtotime($payrollStart))) ?> al <?= htmlspecialchars(date('d/m/Y', strtotime($payrollEnd))) ?> · <?= htmlspecialchars($selectedEmployeeName) ?></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="download_excel_daily.php?date=<?= htmlspecialchars($payrollStart) ?>" class="btn-secondary">
                    <i class="fas fa-file-excel"></i>
                    Exportar diario
                </a>
                <button type="button" onclick="window.print()" class="btn-secondary">
                    <i class="fas fa-print"></i>
                    Imprimir
                </button>
            </div>
        </div>
    </div>

    <form method="GET" class="glass-card space-y-4">
        <div class="panel-heading">
            <div>
                <h3 class="text-primary text-lg font-semibold">Filtros</h3>
                <p class="text-muted text-sm">Ajusta el intervalo de fechas y/o filtra por colaborador.</p>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="form-label" for="payroll_start">Inicio</label>
                <input type="date" id="payroll_start" name="payroll_start" value="<?= htmlspecialchars($payrollStart) ?>" class="input-control">
            </div>
            <div>
                <label class="form-label" for="payroll_end">Fin</label>
                <input type="date" id="payroll_end" name="payroll_end" value="<?= htmlspecialchars($payrollEnd) ?>" class="input-control">
            </div>
            <div class="md:col-span-2">
                <label class="form-label" for="employee">Colaborador</label>
                <select id="employee" name="employee" class="select-control">
                    <option value="all">Todos los colaboradores</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= ((int) $employeeFilter === (int) $employee['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employee['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="btn-primary">
                <i class="fas fa-sync-alt"></i>
                Actualizar
            </button>
        </div>
    </form>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
    <div class="metric-card">
        <span class="label">Colaboradores activos</span>
        <span class="value"><?= number_format($totalEmployees) ?></span>
        <span class="trend neutral"><i class="fas fa-users"></i> Con registros en el periodo</span>
    </div>
    <div class="metric-card actionable" role="button" tabindex="0" onclick="openHoursModal()" onkeydown="if(event.key === 'Enter' || event.key === ' ') { openHoursModal(); }">
        <span class="label">Horas registradas</span>
        <span class="value"><?= number_format($totalHours, 1) ?> h</span>
        <span class="trend neutral"><i class="fas fa-clock"></i> Promedio <?= number_format($averageHoursPerEmployee, 1) ?> h</span>
        <span class="hint"><i class="fas fa-eye"></i> Ver origen de las horas</span>
    </div>
    <div class="metric-card">
        <span class="label">Pago horas (USD)</span>
        <span class="value">$<?= number_format($totalActualPayUsd, 2) ?></span>
        <span class="trend <?= $payrollVarianceUsd >= 0 ? 'positive' : 'negative' ?>">
                <i class="fas fa-balance-scale"></i>
                Base $<?= number_format($totalMonthlyBaseUsd, 2) ?> (<?= $payrollVarianceUsd >= 0 ? '+' : '' ?>$<?= number_format($payrollVarianceUsd, 2) ?>) · Tarifa prom. $<?= number_format($averageHourlyRateUsd, 2) ?>
            </span>
        </div>
        <div class="metric-card">
            <span class="label">Pago horas (DOP)</span>
            <span class="value">RD$<?= number_format($totalActualPayDop, 2) ?></span>
            <span class="trend <?= $payrollVarianceDop >= 0 ? 'positive' : 'negative' ?>">
                <i class="fas fa-coins"></i>
                Base RD$<?= number_format($totalMonthlyBaseDop, 2) ?> (<?= $payrollVarianceDop >= 0 ? '+' : '' ?>RD$<?= number_format($payrollVarianceDop, 2) ?>) · Tarifa prom. RD$<?= number_format($averageHourlyRateDop, 2) ?>
            </span>
        </div>
    </div>



    <div id="hoursModal" class="hours-modal-overlay" onclick="closeHoursModal()" style="display: none;">
        <div class="hours-modal" role="dialog" aria-modal="true" aria-labelledby="hoursModalTitle" onclick="event.stopPropagation();">
            <div class="hours-modal-header">
                <div>
                    <div class="hours-modal-title" id="hoursModalTitle">
                        <i class="fas fa-clipboard-list"></i>
                        Detalle de horas registradas
                    </div>
                    <div class="hours-modal-subtitle">
                        Periodo del <?= htmlspecialchars(date('d/m/Y', strtotime($payrollStart))) ?> al <?= htmlspecialchars(date('d/m/Y', strtotime($payrollEnd))) ?> | <?= htmlspecialchars($selectedEmployeeName) ?>
                    </div>
                </div>
                <button type="button" class="hours-modal-close" aria-label="Cerrar" onclick="closeHoursModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="hours-modal-body">
                <div class="hours-grid">
                    <div class="hours-mini-card">
                        <span class="label">Horas totales</span>
                        <span class="value"><?= number_format($totalHours, 1) ?> h</span>
                    </div>
                    <div class="hours-mini-card">
                        <span class="label">Promedio por colaborador</span>
                        <span class="value"><?= number_format($averageHoursPerEmployee, 1) ?> h</span>
                    </div>
                    <div class="hours-mini-card">
                        <span class="label">Colaboradores con horas</span>
                        <span class="value"><?= number_format($totalEmployees) ?></span>
                    </div>
                    <div class="hours-mini-card">
                        <span class="label">Fechas con tiempo productivo</span>
                        <span class="value"><?= number_format($workDatesCount) ?></span>
                    </div>
                    <div class="hours-mini-card">
                        <span class="label">Registros revisados</span>
                        <span class="value"><?= number_format($hoursDrillDownPunches['total_punches']) ?> punches</span>
                    </div>
                    <div class="hours-mini-card">
                        <span class="label">Punches pagados</span>
                        <span class="value"><?= number_format($hoursDrillDownPunches['paid_punches']) ?> eventos</span>
                    </div>
                </div>

                <div class="hours-section">
                    <h4>Metodologia y fuentes</h4>
                    <p class="hours-muted">Se suman unicamente los intervalos donde el estado del punch esta marcado como pagado.</p>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <?php if (!empty($paidTypeLabels)): ?>
                            <?php foreach ($paidTypeLabels as $label): ?>
                                <span class="hours-chip"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($label) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="hours-muted text-sm">No hay tipos de asistencia pagados configurados.</span>
                        <?php endif; ?>
                    </div>
                    <ul class="list-disc pl-5 space-y-1 mt-3">
                        <li>Intervalo analizado: del <?= htmlspecialchars(date('d/m/Y', strtotime($payrollStart))) ?> al <?= htmlspecialchars(date('d/m/Y', strtotime($payrollEnd))) ?> (<?= htmlspecialchars($selectedEmployeeName) ?>).</li>
                        <li><?= number_format($hoursDrillDownPunches['total_punches']) ?> registros de asistencia revisados (<?= number_format($hoursDrillDownPunches['paid_punches']) ?> pagados) generan <?= number_format($hoursDrillDownPunches['daily_rows']) ?> filas diarias.</li>
                        <li>Sumatoria de <?= number_format($totalDaysWorked) ?> jornadas (por colaborador) distribuidas en <?= number_format($workDatesCount) ?> fechas con actividad productiva.</li>
                    </ul>
                </div>

                <div class="hours-section">
                    <h4>Colaboradores con mas horas en el periodo</h4>
                    <?php if (empty($topHoursEmployees)): ?>
                        <p class="hours-muted">Aun no hay horas registradas en el intervalo seleccionado.</p>
                    <?php else: ?>
                        <table class="hours-table">
                            <thead>
                                <tr>
                                    <th>Colaborador</th>
                                    <th>Departamento</th>
                                    <th>Horas</th>
                                    <th>Dias</th>
                                    <th>Pago USD</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topHoursEmployees as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                                        <td><?= htmlspecialchars($row['department']) ?></td>
                                        <td><?= number_format($row['hours'], 1) ?> h</td>
                                        <td><?= number_format($row['days_worked']) ?></td>
                                        <td>$<?= number_format($row['actual_pay_usd'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="hours-muted mt-2">El resto de colaboradores se muestra en la tabla principal.</p>
                    <?php endif; ?>
                </div>

                <div class="hours-section">
                    <h4>Fechas con mayor carga de horas</h4>
                    <?php if (empty($topDaysByHours)): ?>
                        <p class="hours-muted">No hay fechas con horas pagadas en el periodo.</p>
                    <?php else: ?>
                        <table class="hours-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Horas</th>
                                    <th>Pago USD</th>
                                    <th>Pago DOP</th>
                                    <th>Registros</th>
                                    <th>Colaboradores</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topDaysByHours as $day): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($day['date']))) ?></td>
                                        <td><?= number_format($day['hours'], 2) ?> h</td>
                                        <td>$<?= number_format($day['amount_usd'], 2) ?></td>
                                        <td>RD$<?= number_format($day['amount_dop'], 2) ?></td>
                                        <td><?= number_format($day['records']) ?></td>
                                        <td><?= number_format($day['collaborators_count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="hours-section">
                    <h4>Notas para auditoria rapida</h4>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>La sumatoria de horas coincide con la columna <strong>Horas</strong> de la tabla de detalle por colaborador.</li>
                        <li>Los pagos reflejan las tarifas horarias configuradas; cualquier diferencia con la base mensual se muestra en la columna <strong>Dif</strong> del reporte.</li>
                        <li>Si alguna fecha o colaborador no aparece aqui, no registro estados pagados en el intervalo seleccionado.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>


    <div id="hoursModal" class="hours-modal-overlay" onclick="closeHoursModal()" style="display: none;">
        <div class="hours-modal" role="dialog" aria-modal="true" aria-labelledby="hoursModalTitle" onclick="event.stopPropagation();">
            <div class="hours-modal-header">
                <div>
                    <div class="hours-modal-title" id="hoursModalTitle">
                        <i class="fas fa-clipboard-list"></i>
                        Detalle de horas registradas
                    </div>
                    <div class="hours-modal-subtitle">
                        Periodo del <?= htmlspecialchars(date('d/m/Y', strtotime($payrollStart))) ?> al <?= htmlspecialchars(date('d/m/Y', strtotime($payrollEnd))) ?> | <?= htmlspecialchars($selectedEmployeeName) ?>
                    </div>
                </div>
                <button type="button" class="hours-modal-close" aria-label="Cerrar" onclick="closeHoursModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="hours-modal-body">
                <div class="hours-grid">
                    <div class="hours-mini-card">
                        <span class="label">Horas totales</span>
                        <span class="value"><?= number_format($totalHours, 1) ?> h</span>
                    </div>
                    <div class="hours-mini-card">
                        <span class="label">Promedio por colaborador</span>
                        <span class="value"><?= number_format($averageHoursPerEmployee, 1) ?> h</span>
                    </div>
                    <div class="hours-mini-card">
                        <span class="label">Colaboradores con horas</span>
                        <span class="value"><?= number_format($totalEmployees) ?></span>
                    </div>
                    <div class="hours-mini-card">
                        <span class="label">Fechas con tiempo productivo</span>
                        <span class="value"><?= number_format($workDatesCount) ?></span>
                    </div>
                    <div class="hours-mini-card">
                        <span class="label">Registros revisados</span>
                        <span class="value"><?= number_format($hoursDrillDownPunches['total_punches']) ?> punches</span>
                    </div>
                    <div class="hours-mini-card">
                        <span class="label">Punches pagados</span>
                        <span class="value"><?= number_format($hoursDrillDownPunches['paid_punches']) ?> eventos</span>
                    </div>
                </div>

                <div class="hours-section">
                    <h4>Metodologia y fuentes</h4>
                    <p class="hours-muted">Se suman unicamente los intervalos donde el estado del punch esta marcado como pagado.</p>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <?php if (!empty($paidTypeLabels)): ?>
                            <?php foreach ($paidTypeLabels as $label): ?>
                                <span class="hours-chip"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($label) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="hours-muted text-sm">No hay tipos de asistencia pagados configurados.</span>
                        <?php endif; ?>
                    </div>
                    <ul class="list-disc pl-5 space-y-1 mt-3">
                        <li>Intervalo analizado: del <?= htmlspecialchars(date('d/m/Y', strtotime($payrollStart))) ?> al <?= htmlspecialchars(date('d/m/Y', strtotime($payrollEnd))) ?> (<?= htmlspecialchars($selectedEmployeeName) ?>).</li>
                        <li><?= number_format($hoursDrillDownPunches['total_punches']) ?> registros de asistencia revisados (<?= number_format($hoursDrillDownPunches['paid_punches']) ?> pagados) generan <?= number_format($hoursDrillDownPunches['daily_rows']) ?> filas diarias.</li>
                        <li>Sumatoria de <?= number_format($totalDaysWorked) ?> jornadas (por colaborador) distribuidas en <?= number_format($workDatesCount) ?> fechas con actividad productiva.</li>
                    </ul>
                </div>

                <div class="hours-section">
                    <h4>Colaboradores con mas horas en el periodo</h4>
                    <?php if (empty($topHoursEmployees)): ?>
                        <p class="hours-muted">Aun no hay horas registradas en el intervalo seleccionado.</p>
                    <?php else: ?>
                        <table class="hours-table">
                            <thead>
                                <tr>
                                    <th>Colaborador</th>
                                    <th>Departamento</th>
                                    <th>Horas</th>
                                    <th>Dias</th>
                                    <th>Pago USD</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topHoursEmployees as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                                        <td><?= htmlspecialchars($row['department']) ?></td>
                                        <td><?= number_format($row['hours'], 1) ?> h</td>
                                        <td><?= number_format($row['days_worked']) ?></td>
                                        <td>$<?= number_format($row['actual_pay_usd'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="hours-muted mt-2">El resto de colaboradores se muestra en la tabla principal.</p>
                    <?php endif; ?>
                </div>

                <div class="hours-section">
                    <h4>Fechas con mayor carga de horas</h4>
                    <?php if (empty($topDaysByHours)): ?>
                        <p class="hours-muted">No hay fechas con horas pagadas en el periodo.</p>
                    <?php else: ?>
                        <table class="hours-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Horas</th>
                                    <th>Pago USD</th>
                                    <th>Pago DOP</th>
                                    <th>Registros</th>
                                    <th>Colaboradores</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topDaysByHours as $day): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($day['date']))) ?></td>
                                        <td><?= number_format($day['hours'], 2) ?> h</td>
                                        <td>$<?= number_format($day['amount_usd'], 2) ?></td>
                                        <td>RD$<?= number_format($day['amount_dop'], 2) ?></td>
                                        <td><?= number_format($day['records']) ?></td>
                                        <td><?= number_format($day['collaborators_count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="hours-section">
                    <h4>Notas para auditoria rapida</h4>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>La sumatoria de horas coincide con la columna <strong>Horas</strong> de la tabla de detalle por colaborador.</li>
                        <li>Los pagos reflejan las tarifas horarias configuradas; cualquier diferencia con la base mensual se muestra en la columna <strong>Dif</strong> del reporte.</li>
                        <li>Si alguna fecha o colaborador no aparece aqui, no registro estados pagados en el intervalo seleccionado.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h3 class="text-primary text-xl font-semibold">Analitica por departamento</h3>
                <p class="text-muted text-sm">Comparativo de horas y pagos frente a la base mensual definida.</p>
            </div>
        </div>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="section-card p-6">
                <canvas id="departmentChart" class="w-full h-72"></canvas>
            </div>
            <div class="section-card p-0 overflow-hidden">
                <div class="p-4 border-b border-gray-700">
                    <input type="text" id="searchDepartments" class="input-control" placeholder="Buscar departamento..." onkeyup="filterTable('deptTable', 'searchDepartments', 0)">
                </div>
                <div class="overflow-auto">
                    <table class="table-auto text-sm" id="deptTable">
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
                                <td colspan="9" class="text-center text-muted py-6">No hay datos disponibles.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($departmentSummaryList as $dept): ?>
                                <?php
                                    $differenceUsd = $dept['actual_pay_usd'] - $dept['monthly_salary_usd'];
                                    $differenceDop = $dept['actual_pay_dop'] - $dept['monthly_salary_dop'];
                                ?>
                                <tr>
                                    <td class="font-semibold text-primary"><?= htmlspecialchars($dept['name']) ?></td>
                                    <td><?= number_format($dept['members']) ?></td>
                                    <td><?= number_format($dept['hours'], 1) ?> h</td>
                                    <td>$<?= number_format($dept['actual_pay_usd'], 2) ?></td>
                                    <td>$<?= number_format($dept['monthly_salary_usd'], 2) ?></td>
                                    <td class="<?= $differenceUsd >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                        <?= $differenceUsd >= 0 ? '+' : '' ?>$<?= number_format($differenceUsd, 2) ?>
                                    </td>
                                    <td>RD$<?= number_format($dept['actual_pay_dop'], 2) ?></td>
                                    <td>RD$<?= number_format($dept['monthly_salary_dop'], 2) ?></td>
                                    <td class="<?= $differenceDop >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                        <?= $differenceDop >= 0 ? '+' : '' ?>RD$<?= number_format($differenceDop, 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <div class="p-4 border-t border-gray-700 flex justify-between items-center">
                    <div class="text-sm text-muted">
                        Mostrando <span id="deptCount"><?= count($departmentSummaryList) ?></span> de <?= count($departmentSummaryList) ?> departamentos
                    </div>
                    <div class="flex gap-2" id="deptPagination"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h3 class="text-primary text-xl font-semibold">Top colaboradores por pago</h3>
                <p class="text-muted text-sm">Horas y pagos calculados en el periodo seleccionado.</p>
            </div>
        </div>
        <div class="section-card p-6">
            <canvas id="employeeChart" class="w-full h-72"></canvas>
        </div>
    </div>

    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h3 class="text-primary text-xl font-semibold">Detalle por colaborador</h3>
                <p class="text-muted text-sm">Incluye dias trabajados, horas productivas y comparativa con la base mensual.</p>
            </div>
        </div>
        <div class="section-card p-0 overflow-hidden">
            <div class="p-4 border-b border-gray-700">
                <input type="text" id="searchEmployees" class="input-control" placeholder="Buscar por colaborador o departamento..." onkeyup="filterTable('employeeTable', 'searchEmployees', [0,1])">
            </div>
            <div class="overflow-auto">
                <table class="table-auto text-sm" id="employeeTable">
                    <thead>
                        <tr>
                            <th>Colaborador</th>
                            <th>Departamento</th>
                            <th>Dias</th>
                            <th>Horas</th>
                            <th>Tarifa USD</th>
                            <th>Tarifa DOP</th>
                            <th>Pago USD</th>
                            <th>Base USD</th>
                            <th>Dif USD</th>
                            <th>Pago DOP</th>
                            <th>Base DOP</th>
                            <th>Dif DOP</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($employeeSummaries)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted py-6">No se registraron movimientos en el periodo.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employeeSummaries as $summary): ?>
                            <tr>
                                <td class="font-semibold text-primary"><?= htmlspecialchars($summary['full_name']) ?></td>
                                <td><?= htmlspecialchars($summary['department']) ?></td>
                                <td><?= number_format($summary['days_worked']) ?></td>
                                <td><?= number_format($summary['hours'], 1) ?> h</td>
                                <td>$<?= number_format($summary['hourly_rate_usd'], 2) ?></td>
                                <td>RD$<?= number_format($summary['hourly_rate_dop'], 2) ?></td>
                                <td>$<?= number_format($summary['actual_pay_usd'], 2) ?></td>
                                <td>$<?= number_format($summary['monthly_salary_usd'], 2) ?></td>
                                <td class="<?= $summary['difference_usd'] >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                    <?= $summary['difference_usd'] >= 0 ? '+' : '' ?>$<?= number_format($summary['difference_usd'], 2) ?>
                                </td>
                                <td>RD$<?= number_format($summary['actual_pay_dop'], 2) ?></td>
                                <td>RD$<?= number_format($summary['monthly_salary_dop'], 2) ?></td>
                                <td class="<?= $summary['difference_dop'] >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                    <?= $summary['difference_dop'] >= 0 ? '+' : '' ?>RD$<?= number_format($summary['difference_dop'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            <div class="p-4 border-t border-gray-700 flex justify-between items-center">
                <div class="text-sm text-muted">
                    Mostrando <span id="employeeCount"><?= count($employeeSummaries) ?></span> de <?= count($employeeSummaries) ?> colaboradores
                </div>
                <div class="flex gap-2" id="employeePagination"></div>
            </div>
        </div>
    </div>

    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h3 class="text-primary text-xl font-semibold">Detalle diario</h3>
                <p class="text-muted text-sm">Registros individuales de entrada y salida para auditoria rapida.</p>
            </div>
            <span class="chip"><i class="fas fa-clock"></i> <?= number_format($dailyTotalHours, 1) ?> h | USD $<?= number_format($dailyTotalAmountUsd, 2) ?> | DOP RD$<?= number_format($dailyTotalAmountDop, 2) ?></span>
        </div>
        <div class="section-card p-0 overflow-hidden">
            <div class="p-4 border-b border-gray-700">
                <input type="text" id="searchDaily" class="input-control" placeholder="Buscar por fecha, colaborador o departamento..." onkeyup="filterTable('dailyTable', 'searchDaily', [0,1,2])">
            </div>
            <div class="overflow-auto">
                <table class="table-auto text-sm" id="dailyTable">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Colaborador</th>
                            <th>Departamento</th>
                            <th>Entrada</th>
                            <th>Salida</th>
                            <th>Horas</th>
                            <th>Pago USD</th>
                            <th>Pago DOP</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($dailySummaries)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-6">Sin registros en el periodo seleccionado.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dailySummaries as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('d/m/Y', strtotime($row['work_date']))) ?></td>
                                <td><?= htmlspecialchars($row['full_name']) ?></td>
                                <td><?= htmlspecialchars($row['department']) ?></td>
                                <td><?= $row['first_entry'] ? htmlspecialchars(date('H:i', strtotime($row['first_entry']))) : 'Sin registro' ?></td>
                                <td><?= $row['last_exit'] ? htmlspecialchars(date('H:i', strtotime($row['last_exit']))) : 'Sin registro' ?></td>
                                <td><?= number_format($row['hours'], 2) ?> h</td>
                                <td>$<?= number_format($row['amount_usd'], 2) ?></td>
                                <td>RD$<?= number_format($row['amount_dop'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            <div class="p-4 border-t border-gray-700 flex justify-between items-center">
                <div class="text-sm text-muted">
                    Mostrando <span id="dailyCount"><?= count($dailySummaries) ?></span> de <?= count($dailySummaries) ?> registros
                </div>
                <div class="flex gap-2" id="dailyPagination"></div>
            </div>
        </div>
    </div>
</section>

<script>
function openHoursModal() {
    const modal = document.getElementById('hoursModal');
    if (!modal) return;
    modal.style.display = 'flex';
    document.body.dataset.prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
}

function closeHoursModal() {
    const modal = document.getElementById('hoursModal');
    if (!modal) return;
    modal.style.display = 'none';
    if (document.body.dataset.prevOverflow !== undefined) {
        document.body.style.overflow = document.body.dataset.prevOverflow;
        delete document.body.dataset.prevOverflow;
    } else {
        document.body.style.overflow = '';
    }
}

document.addEventListener('keydown', function(evt) {
    if (evt.key === 'Escape') {
        closeHoursModal();
    }
});
</script>

<script>
// Función de búsqueda en tablas
function filterTable(tableId, searchId, columnIndexes) {
    const input = document.getElementById(searchId);
    const filter = input.value.toUpperCase();
    const table = document.getElementById(tableId);
    const tbody = table.getElementsByTagName('tbody')[0];
    const tr = tbody.getElementsByTagName('tr');
    let visibleCount = 0;

    // Convertir columnIndexes a array si es un número
    const columns = Array.isArray(columnIndexes) ? columnIndexes : [columnIndexes];

    for (let i = 0; i < tr.length; i++) {
        // Skip if this is an "empty" row
        const firstTd = tr[i].getElementsByTagName('td')[0];
        if (firstTd && firstTd.getAttribute('colspan')) {
            continue;
        }

        let found = false;
        const td = tr[i].getElementsByTagName('td');
        
        for (let colIdx of columns) {
            if (td[colIdx]) {
                const txtValue = td[colIdx].textContent || td[colIdx].innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
        }

        if (found) {
            tr[i].style.display = '';
            visibleCount++;
        } else {
            tr[i].style.display = 'none';
        }
    }

    // Update count
    const countId = tableId.replace('Table', 'Count');
    const countEl = document.getElementById(countId);
    if (countEl) {
        countEl.textContent = visibleCount;
    }

    // Reset to first page after filtering
    if (window.tablePaginators && window.tablePaginators[tableId]) {
        window.tablePaginators[tableId].currentPage = 1;
        window.tablePaginators[tableId].render();
    }
}

// Sistema de paginación
class TablePaginator {
    constructor(tableId, rowsPerPage = 20) {
        this.tableId = tableId;
        this.rowsPerPage = rowsPerPage;
        this.currentPage = 1;
        this.table = document.getElementById(tableId);
        this.tbody = this.table ? this.table.getElementsByTagName('tbody')[0] : null;
        this.paginationId = tableId.replace('Table', 'Pagination');
        this.countId = tableId.replace('Table', 'Count');
        
        if (this.tbody) {
            this.init();
        }
    }

    init() {
        this.render();
    }

    getVisibleRows() {
        const allRows = Array.from(this.tbody.getElementsByTagName('tr'));
        return allRows.filter(row => {
            // Skip empty state rows
            const firstTd = row.getElementsByTagName('td')[0];
            if (firstTd && firstTd.getAttribute('colspan')) {
                return false;
            }
            return row.style.display !== 'none';
        });
    }

    render() {
        const visibleRows = this.getVisibleRows();
        const totalRows = visibleRows.length;
        const totalPages = Math.ceil(totalRows / this.rowsPerPage);

        // Hide all visible rows first
        visibleRows.forEach(row => row.classList.add('hidden'));

        // Show only rows for current page
        const start = (this.currentPage - 1) * this.rowsPerPage;
        const end = start + this.rowsPerPage;
        visibleRows.slice(start, end).forEach(row => row.classList.remove('hidden'));

        // Render pagination controls
        this.renderPaginationControls(totalPages);
    }

    renderPaginationControls(totalPages) {
        const container = document.getElementById(this.paginationId);
        if (!container || totalPages <= 1) {
            if (container) container.innerHTML = '';
            return;
        }

        let html = '';

        // Previous button
        html += `<button onclick="window.tablePaginators['${this.tableId}'].goToPage(${this.currentPage - 1})" 
                    class="px-3 py-1 rounded ${this.currentPage === 1 ? 'bg-gray-700 text-gray-500 cursor-not-allowed' : 'bg-primary text-white hover:bg-primary-dark'}" 
                    ${this.currentPage === 1 ? 'disabled' : ''}>
                    <i class="fas fa-chevron-left"></i>
                </button>`;

        // Page numbers
        const maxButtons = 5;
        let startPage = Math.max(1, this.currentPage - Math.floor(maxButtons / 2));
        let endPage = Math.min(totalPages, startPage + maxButtons - 1);

        if (endPage - startPage < maxButtons - 1) {
            startPage = Math.max(1, endPage - maxButtons + 1);
        }

        if (startPage > 1) {
            html += `<button onclick="window.tablePaginators['${this.tableId}'].goToPage(1)" 
                        class="px-3 py-1 rounded bg-gray-700 text-white hover:bg-gray-600">1</button>`;
            if (startPage > 2) {
                html += `<span class="px-2 text-muted">...</span>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            html += `<button onclick="window.tablePaginators['${this.tableId}'].goToPage(${i})" 
                        class="px-3 py-1 rounded ${i === this.currentPage ? 'bg-primary text-white' : 'bg-gray-700 text-white hover:bg-gray-600'}">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="px-2 text-muted">...</span>`;
            }
            html += `<button onclick="window.tablePaginators['${this.tableId}'].goToPage(${totalPages})" 
                        class="px-3 py-1 rounded bg-gray-700 text-white hover:bg-gray-600">${totalPages}</button>`;
        }

        // Next button
        html += `<button onclick="window.tablePaginators['${this.tableId}'].goToPage(${this.currentPage + 1})" 
                    class="px-3 py-1 rounded ${this.currentPage === totalPages ? 'bg-gray-700 text-gray-500 cursor-not-allowed' : 'bg-primary text-white hover:bg-primary-dark'}" 
                    ${this.currentPage === totalPages ? 'disabled' : ''}>
                    <i class="fas fa-chevron-right"></i>
                </button>`;

        container.innerHTML = html;
    }

    goToPage(page) {
        const visibleRows = this.getVisibleRows();
        const totalPages = Math.ceil(visibleRows.length / this.rowsPerPage);
        
        if (page < 1 || page > totalPages) return;
        
        this.currentPage = page;
        this.render();

        // Scroll to top of table
        this.table.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Initialize paginators when DOM is ready
window.tablePaginators = {};
document.addEventListener('DOMContentLoaded', function() {
    window.tablePaginators['deptTable'] = new TablePaginator('deptTable', 10);
    window.tablePaginators['employeeTable'] = new TablePaginator('employeeTable', 20);
    window.tablePaginators['dailyTable'] = new TablePaginator('dailyTable', 25);
});
</script>

<script>
const departmentChartData = <?= $departmentChartJson ?>;
const employeeChartData = <?= $employeeChartJson ?>;
const palette = getComputedStyle(document.body);
const cyan = (palette.getPropertyValue('--accent-cyan') || '#22d3ee').trim();
const emerald = (palette.getPropertyValue('--accent-emerald') || '#34d399').trim();
const textColor = (palette.getPropertyValue('--text-primary') || '#e2e8f0').trim();
const mutedColor = (palette.getPropertyValue('--text-muted') || '#94a3b8').trim();
const amber = '#f59e0b';
const violet = '#8b5cf6';

if (typeof Chart !== 'undefined') {
    const departmentCtx = document.getElementById('departmentChart');
    if (departmentCtx && departmentChartData.labels.length) {
        new Chart(departmentCtx, {
            type: 'bar',
            data: {
                labels: departmentChartData.labels,
                datasets: [
                    {
                        label: 'Pago USD',
                        data: departmentChartData.actual_usd,
                        backgroundColor: cyan,
                        borderRadius: 8,
                    },
                    {
                        label: 'Base USD',
                        data: departmentChartData.base_usd,
                        backgroundColor: emerald,
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
                            color: textColor,
                            font: { family: 'Inter' }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: mutedColor },
                        grid: { color: 'rgba(148, 163, 184, 0.12)' }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: mutedColor },
                        grid: { color: 'rgba(148, 163, 184, 0.12)' }
                    }
                }
            }
        });
    }

    const employeeCtx = document.getElementById('employeeChart');
    if (employeeCtx && employeeChartData.labels.length) {
        new Chart(employeeCtx, {
            type: 'bar',
            data: {
                labels: employeeChartData.labels,
                datasets: [
                    {
                        label: 'Pago USD',
                        data: employeeChartData.actual_usd,
                        backgroundColor: cyan,
                        borderRadius: 6,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Pago DOP',
                        data: employeeChartData.actual_dop,
                        backgroundColor: amber,
                        borderRadius: 6,
                        yAxisID: 'y2'
                    },
                    {
                        label: 'Horas trabajadas',
                        data: employeeChartData.hours,
                        type: 'line',
                        borderColor: emerald,
                        backgroundColor: 'rgba(52, 211, 153, 0.15)',
                        tension: 0.25,
                        fill: false,
                        pointRadius: 4,
                        pointBackgroundColor: emerald,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        labels: {
                            color: textColor,
                            font: { family: 'Inter' }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: mutedColor },
                        grid: { color: 'rgba(148, 163, 184, 0.12)' }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: mutedColor },
                        grid: { color: 'rgba(148, 163, 184, 0.12)' },
                        title: { display: true, text: 'Pago USD', color: mutedColor }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        ticks: { color: mutedColor },
                        grid: { display: false },
                        title: { display: true, text: 'Horas', color: mutedColor }
                    },
                    y2: {
                        beginAtZero: true,
                        position: 'right',
                        ticks: { color: mutedColor },
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: 'Pago DOP', color: mutedColor }
                    }
                }
            }
        });
    }
}
</script>
<?php include __DIR__ . '/footer.php'; ?>
