<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/authorization_functions.php';

ensurePermission('wfm_report');

// Increase execution time for heavy reports
set_time_limit(300); // 5 minutes

// Helper Functions
function formatDuration(int $seconds): string
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

function formatCurrencyAmount(float $amount, string $currency): string
{
    if (strtoupper($currency) === 'DOP') {
        return 'RD$' . number_format($amount, 2);
    }
    return '$' . number_format($amount, 2);
}

function formatCurrencyTotals(array $totals): string
{
    if (empty($totals)) {
        return '$0.00';
    }
    $parts = [];
    foreach ($totals as $currency => $amount) {
        $parts[] = strtoupper($currency) . ': ' . formatCurrencyAmount((float)$amount, (string)$currency);
    }
    return implode(' | ', $parts);
}

function erlangC(float $a, int $n): float
{
    if ($a <= 0 || $n <= 0) {
        return 0.0;
    }
    if ($n <= $a) {
        return 1.0;
    }

    $sum = 1.0;
    $term = 1.0;
    for ($k = 1; $k <= $n - 1; $k++) {
        $term *= $a / $k;
        $sum += $term;
    }
    $term *= $a / $n;
    $numer = $term * ($n / ($n - $a));
    $denom = $sum + $numer;
    if ($denom == 0.0) {
        return 1.0;
    }
    return $numer / $denom;
}

function calcStaffing(array $row): array
{
    $intervalMinutes = max(1, (int)($row['interval_minutes'] ?? 30));
    $intervalSeconds = $intervalMinutes * 60;
    $offered = (int)($row['offered_volume'] ?? 0);
    $ahtSeconds = (int)($row['aht_seconds'] ?? 0);
    $targetSl = (float)($row['target_sl'] ?? 0.8);
    $targetAns = (int)($row['target_answer_seconds'] ?? 20);
    $occupancyTarget = (float)($row['occupancy_target'] ?? 0.85);
    $shrinkage = (float)($row['shrinkage'] ?? 0.3);

    if ($targetSl > 1) {
        $targetSl = $targetSl / 100;
    }
    if ($occupancyTarget > 1) {
        $occupancyTarget = $occupancyTarget / 100;
    }
    if ($shrinkage > 1) {
        $shrinkage = $shrinkage / 100;
    }

    $workload = 0.0;
    if ($intervalSeconds > 0 && $ahtSeconds > 0 && $offered > 0) {
        $workload = ($offered * $ahtSeconds) / $intervalSeconds;
    }

    $requiredAgents = 0;
    $serviceLevel = 1.0;
    $occupancy = 0.0;

    if ($workload > 0 && $ahtSeconds > 0) {
        $n = (int)ceil($workload);
        if ($n <= $workload) {
            $n = (int)floor($workload) + 1;
        }
        if ($occupancyTarget > 0) {
            $minOcc = (int)ceil($workload / $occupancyTarget);
            if ($minOcc > $n) {
                $n = $minOcc;
            }
        }

        $maxIterations = 200;
        $serviceLevel = 0.0;
        for ($i = 0; $i <= $maxIterations; $i++) {
            if ($n <= $workload) {
                $n++;
                continue;
            }
            $ec = erlangC($workload, $n);
            $serviceLevel = 1 - $ec * exp(-($n - $workload) * ($targetAns / $ahtSeconds));
            if ($serviceLevel >= $targetSl) {
                break;
            }
            $n++;
        }
        $requiredAgents = $n;
        $occupancy = $requiredAgents > 0 ? ($workload / $requiredAgents) : 0.0;
    }

    $requiredStaff = $requiredAgents;
    if ($shrinkage > 0 && $shrinkage < 1 && $requiredAgents > 0) {
        $requiredStaff = (int)ceil($requiredAgents / (1 - $shrinkage));
    }

    return [
        'workload' => $workload,
        'required_agents' => $requiredAgents,
        'required_staff' => $requiredStaff,
        'service_level' => $serviceLevel,
        'occupancy' => $occupancy
    ];
}

// -----------------------------------------------------------------------------
// Filters
// -----------------------------------------------------------------------------
$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-t');

$startDate = $_GET['start_date'] ?? $defaultStart;
$endDate = $_GET['end_date'] ?? $defaultEnd;
$employeeFilter = $_GET['employee'] ?? 'all';

// Validate Dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = $defaultStart;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = $defaultEnd;
}
if ($endDate < $startDate) {
    $endDate = $startDate;
}

$startBound = $startDate . ' 00:00:00';
$endBound = $endDate . ' 23:59:59';

// -----------------------------------------------------------------------------
// Data Fetching
// -----------------------------------------------------------------------------

// 1. Employees List for Filter
$empStmt = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name");
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// 2. Base Query Logic
$usersQuery = "SELECT u.id, u.full_name, u.username, u.employee_code, u.department_id, u.role, u.hourly_rate, u.hourly_rate_dop, 
    e.campaign_id, c.name as campaign_name, c.color as campaign_color 
    FROM users u 
    LEFT JOIN employees e ON e.user_id = u.id 
    LEFT JOIN campaigns c ON c.id = e.campaign_id
    WHERE u.is_active = 1";
$params = [];
$userIds = [];

if ($employeeFilter !== 'all' && is_numeric($employeeFilter)) {
    $usersQuery .= " AND u.id = ?";
    $params[] = $employeeFilter;
}
$usersStmt = $pdo->prepare($usersQuery);
$usersStmt->execute($params);
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $u) {
    $userIds[] = $u['id'];
}

// 3. Compensation & Types Data
$paidTypes = getPaidAttendanceTypeSlugs($pdo);
$paidTypesUpper = array_map('strtoupper', $paidTypes);
$nonWorkTypes = ['BREAK', 'LUNCH', 'EXIT'];

// 4. Batch Fetch Schedules (Optimization)
// Fetch global config once
$globalScheduleConfig = getScheduleConfig($pdo);

// Fetch all specific schedules for these users
$userSchedulesMap = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    // We fetch all active schedules. Sorting by effective_date DESC helps us find the right one quickly.
    $schedQuery = "
        SELECT * FROM employee_schedules 
        WHERE user_id IN ($placeholders) 
        AND is_active = 1 
        ORDER BY effective_date DESC
    ";
    $schedStmt = $pdo->prepare($schedQuery);
    $schedStmt->execute($userIds);
    $allSchedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allSchedules as $sch) {
        $uid = $sch['user_id'];
        $userSchedulesMap[$uid][] = $sch;
    }
}

// 5. Batch Fetch Punches
// Fetching all punches for all selected users in one go
$punchesMap = []; // userId -> [punches]
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    // We add querying by date strictly
    // array merge params: userIds + startBound + endBound
    $punchParams = array_merge($userIds, [$startBound, $endBound]);
    
    $punchQuery = "
        SELECT user_id, id, timestamp, type, ip_address 
        FROM attendance 
        WHERE user_id IN ($placeholders) 
        AND timestamp BETWEEN ? AND ? 
        ORDER BY timestamp ASC
    ";
    $punchStmt = $pdo->prepare($punchQuery);
    $punchStmt->execute($punchParams);
    $allPunches = $punchStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allPunches as $p) {
        $punchesMap[$p['user_id']][] = $p;
    }
}

$reportData = [];

// Statistics
$totals = [
    'real_gross_seconds' => 0,
    'real_net_seconds' => 0,
    'payroll_seconds' => 0,
    'scheduled_seconds' => 0,
    'payroll_amount_usd' => 0.0,
    'payroll_amount_dop' => 0.0
];

// Campaign Hours Tracking
$campaignHours = []; // campaign_id => ['scheduled_seconds' => X, 'real_net_seconds' => Y, 'payroll_seconds' => Z, 'campaign_name' => '', 'campaign_color' => '', 'employee_count' => 0]

// Campaign Ops (Sales/Revenue/Volume)
$campaignSalesRows = [];
$campaignSalesTotals = [
    'sales' => [],
    'revenue' => [],
    'volume' => 0,
    'records' => 0
];

$staffingRows = [];
$staffingSummary = [
    'intervals' => 0,
    'total_volume' => 0,
    'total_workload' => 0.0,
    'total_staff_hours' => 0.0,
    'max_required_staff' => 0,
    'avg_required_staff' => 0.0
];

// Helper to resolve schedule from memory
function resolveScheduleHours($map, $defaultConfig, $userId, $dateStr) {
    // Get day of week from date (0=Sunday, 1=Monday, etc.)
    $dayOfWeek = date('w', strtotime($dateStr));
    // Convert to Spanish days used in system (Lunes, Martes, etc.)
    $dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $currentDay = $dayNames[$dayOfWeek];

    // Schedules are already sorted by effective_date DESC
    // We need to find the FIRST (most recent) schedule that applies to this date
    if (isset($map[$userId])) {
        foreach ($map[$userId] as $sch) {
            // Check dates: effective_date <= dateStr AND (end_date IS NULL OR end_date >= dateStr)
            $effDate = $sch['effective_date'] ?? '0000-00-00';
            $endDate = $sch['end_date'];

            if ($effDate <= $dateStr && ($endDate === null || $endDate >= $dateStr)) {
                // Check if this schedule applies to this day of week
                $daysOfWeek = $sch['days_of_week'] ?? '';
                
                // If days_of_week is empty or null, assume it applies to all days
                if (empty($daysOfWeek)) {
                    return (float)($sch['scheduled_hours'] ?? 0);
                } else {
                    // Check both formats: numeric (1,2,3) and text (Lunes,Martes,Miércoles)
                    $matchesNumeric = strpos($daysOfWeek, (string)$dayOfWeek) !== false;
                    $matchesText = strpos($daysOfWeek, $currentDay) !== false;
                    
                    if ($matchesNumeric || $matchesText) {
                        return (float)($sch['scheduled_hours'] ?? 0);
                    }
                }
            }
        }
    }

    // If employee has custom schedules but none matched, return 0
    // (e.g., day before effective_date, or weekend when only weekdays are scheduled)
    if (isset($map[$userId]) && !empty($map[$userId])) {
        return 0.0;
    }

    // Fallback to global schedule only if employee has NO custom schedules at all
    return (float)($defaultConfig['scheduled_hours'] ?? 8.0);
}

// Create array of dates once (DatePeriod can only be iterated once)
$dateRangeIter = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($endDate))->modify('+1 day')
);
$datesArray = iterator_to_array($dateRangeIter);

foreach ($users as $user) {
    $userId = $user['id'];
    $userPunches = $punchesMap[$userId] ?? [];

    // --- LOGIC: SCHEDULER (LO PLANIFICADO) ---
    $scheduledSeconds = 0;
    foreach ($datesArray as $dt) {
        $dateStr = $dt->format('Y-m-d');
        // Optimized resolve
        $hours = resolveScheduleHours($userSchedulesMap, $globalScheduleConfig, $userId, $dateStr);
        $scheduledSeconds += ($hours * 3600);
    }

    // Skip logic for "All" filter
    if (empty($userPunches) && $employeeFilter === 'all' && $scheduledSeconds == 0) {
        continue;
    }

    // --- LOGIC: PUNCH (LO REAL) & PAYROLL ---
    $punchesByDate = [];
    foreach ($userPunches as $p) {
        $d = date('Y-m-d', strtotime($p['timestamp']));
        $punchesByDate[$d][] = $p;
    }

    $realGrossSeconds = 0;
    $realNetSeconds = 0;
    $payrollSeconds = 0;

    foreach ($punchesByDate as $d => $dayPunches) {
        // 1. Gross Presence
        if (!empty($dayPunches)) {
            $firstTime = strtotime($dayPunches[0]['timestamp']);
            $lastTime = strtotime($dayPunches[count($dayPunches)-1]['timestamp']);
            $diff = $lastTime - $firstTime;
            if ($diff > 0 && $diff < 86400) {
                 $realGrossSeconds += $diff;
            }
        }

        // 2. Net Activity
        $workStart = null;
        $isWorking = false;
        foreach ($dayPunches as $p) {
            $t = strtotime($p['timestamp']);
            $type = strtoupper($p['type']);
            if (in_array($type, $nonWorkTypes)) {
                if ($isWorking && $workStart !== null) {
                    $realNetSeconds += ($t - $workStart);
                }
                $isWorking = false;
                $workStart = null;
            } else {
                if (!$isWorking) {
                    $workStart = $t;
                    $isWorking = true;
                }
            }
        }

        // 3. Payroll (Paid Intervals)
        $inPaidState = false;
        $paidStartTime = null;
        $lastPaidPunchTime = null;
        foreach ($dayPunches as $punch) {
            $punchTime = strtotime($punch['timestamp']);
            $punchType = strtoupper($punch['type']);
            $isPaid = in_array($punchType, $paidTypesUpper);
            
            if ($isPaid) {
                $lastPaidPunchTime = $punchTime;
                if (!$inPaidState) {
                    $paidStartTime = $punchTime;
                    $inPaidState = true;
                }
            } elseif (!$isPaid && $inPaidState) {
                if ($paidStartTime !== null && $lastPaidPunchTime !== null) {
                    $payrollSeconds += ($lastPaidPunchTime - $paidStartTime);
                }
                $inPaidState = false;
                $paidStartTime = null;
                $lastPaidPunchTime = null;
            }
        }
        if ($inPaidState && $paidStartTime !== null && $lastPaidPunchTime !== null) {
            $payrollSeconds += ($lastPaidPunchTime - $paidStartTime);
        }
    }

    // --- MONEY CALC ---
    $usdRate = (float)($user['hourly_rate'] ?? 0);
    $dopRate = (float)($user['hourly_rate_dop'] ?? 0);
    
    $payUsd = ($usdRate > 0 && $payrollSeconds > 0) ? ($payrollSeconds / 3600) * $usdRate : 0;
    $payDop = ($dopRate > 0 && $payrollSeconds > 0) ? ($payrollSeconds / 3600) * $dopRate : 0;

    $reportData[] = [
        'user' => $user,
        'real_gross_seconds' => $realGrossSeconds,
        'real_net_seconds' => $realNetSeconds,
        'payroll_seconds' => $payrollSeconds,
        'scheduled_seconds' => $scheduledSeconds,
        'pay_usd' => $payUsd,
        'pay_dop' => $payDop,
        'campaign_id' => $user['campaign_id'],
        'campaign_name' => $user['campaign_name']
    ];
    
    $totals['real_gross_seconds'] += $realGrossSeconds;
    $totals['real_net_seconds'] += $realNetSeconds;
    $totals['payroll_seconds'] += $payrollSeconds;
    $totals['scheduled_seconds'] += $scheduledSeconds;
    $totals['payroll_amount_usd'] += $payUsd;
    $totals['payroll_amount_dop'] += $payDop;
    
    // Track hours by campaign
    $campaignId = $user['campaign_id'];
    if ($campaignId !== null) {
        if (!isset($campaignHours[$campaignId])) {
            $campaignHours[$campaignId] = [
                'scheduled_seconds' => 0,
                'real_net_seconds' => 0,
                'payroll_seconds' => 0,
                'campaign_name' => $user['campaign_name'] ?? 'Sin Nombre',
                'campaign_color' => $user['campaign_color'] ?? '#64748b',
                'employee_count' => 0
            ];
        }
        $campaignHours[$campaignId]['scheduled_seconds'] += $scheduledSeconds;
        $campaignHours[$campaignId]['real_net_seconds'] += $realNetSeconds;
        $campaignHours[$campaignId]['payroll_seconds'] += $payrollSeconds;
        $campaignHours[$campaignId]['employee_count']++;
    }
}

// Campaign Ops data within selected range
try {
    $campaignSalesRowsRaw = [];
    $campaignSalesStmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.code,
            c.color,
            r.currency,
            SUM(r.sales_amount) AS sales_amount,
            SUM(r.revenue_amount) AS revenue_amount,
            SUM(r.volume) AS volume,
            COUNT(r.id) AS records
        FROM campaigns c
        LEFT JOIN campaign_sales_reports r
            ON r.campaign_id = c.id
            AND r.report_date BETWEEN ? AND ?
        GROUP BY c.id, r.currency
        ORDER BY c.name ASC
    ");
    $campaignSalesStmt->execute([$startDate, $endDate]);
    $campaignSalesRowsRaw = $campaignSalesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($campaignSalesRowsRaw as $row) {
        if ($row['currency'] === null && $row['records'] == 0) {
            continue;
        }
        $currency = $row['currency'] ?: 'USD';
        $salesAmount = (float)($row['sales_amount'] ?? 0);
        $revenueAmount = (float)($row['revenue_amount'] ?? 0);
        $volume = (int)($row['volume'] ?? 0);
        $records = (int)($row['records'] ?? 0);

        $campaignSalesRows[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'code' => $row['code'],
            'color' => $row['color'],
            'currency' => $currency,
            'sales_amount' => $salesAmount,
            'revenue_amount' => $revenueAmount,
            'volume' => $volume,
            'records' => $records
        ];

        if (!isset($campaignSalesTotals['sales'][$currency])) {
            $campaignSalesTotals['sales'][$currency] = 0;
        }
        if (!isset($campaignSalesTotals['revenue'][$currency])) {
            $campaignSalesTotals['revenue'][$currency] = 0;
        }
        $campaignSalesTotals['sales'][$currency] += $salesAmount;
        $campaignSalesTotals['revenue'][$currency] += $revenueAmount;
        $campaignSalesTotals['volume'] += $volume;
        $campaignSalesTotals['records'] += $records;
    }
} catch (Exception $e) {
    $campaignSalesRows = [];
    $campaignSalesTotals = [
        'sales' => [],
        'revenue' => [],
        'volume' => 0,
        'records' => 0
    ];
}

// Staffing (Erlang C) data
try {
    $staffingStmt = $pdo->prepare("
        SELECT 
            f.*,
            c.name AS campaign_name,
            c.code AS campaign_code
        FROM campaign_staffing_forecast f
        INNER JOIN campaigns c ON c.id = f.campaign_id
        WHERE f.interval_start BETWEEN ? AND ?
        ORDER BY c.name ASC, f.interval_start ASC
    ");
    $staffingStmt->execute([$startBound, $endBound]);
    $staffingRowsRaw = $staffingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($staffingRowsRaw as $row) {
        $calc = calcStaffing($row);
        $intervalMinutes = max(1, (int)($row['interval_minutes'] ?? 30));
        $staffHours = $calc['required_staff'] * ($intervalMinutes / 60);

        $staffingRows[] = [
            'campaign_name' => $row['campaign_name'],
            'campaign_code' => $row['campaign_code'],
            'interval_start' => $row['interval_start'],
            'interval_minutes' => $intervalMinutes,
            'offered_volume' => (int)$row['offered_volume'],
            'aht_seconds' => (int)$row['aht_seconds'],
            'target_sl' => (float)$row['target_sl'],
            'target_answer_seconds' => (int)$row['target_answer_seconds'],
            'occupancy_target' => (float)$row['occupancy_target'],
            'shrinkage' => (float)$row['shrinkage'],
            'channel' => $row['channel'],
            'workload' => $calc['workload'],
            'required_agents' => $calc['required_agents'],
            'required_staff' => $calc['required_staff'],
            'service_level' => $calc['service_level'],
            'occupancy' => $calc['occupancy']
        ];

        $staffingSummary['intervals']++;
        $staffingSummary['total_volume'] += (int)$row['offered_volume'];
        $staffingSummary['total_workload'] += $calc['workload'];
        $staffingSummary['total_staff_hours'] += $staffHours;
        if ($calc['required_staff'] > $staffingSummary['max_required_staff']) {
            $staffingSummary['max_required_staff'] = $calc['required_staff'];
        }
    }

    if ($staffingSummary['intervals'] > 0) {
        $staffingSummary['avg_required_staff'] = $staffingSummary['total_staff_hours'] /
            ($staffingSummary['intervals'] * ((int)($staffingRows[0]['interval_minutes'] ?? 30) / 60));
    }
} catch (Exception $e) {
    $staffingRows = [];
    $staffingSummary = [
        'intervals' => 0,
        'total_volume' => 0,
        'total_workload' => 0.0,
        'total_staff_hours' => 0.0,
        'max_required_staff' => 0,
        'avg_required_staff' => 0.0
    ];
}

include 'header.php';
?>

<div class="container mx-auto px-4 py-8" x-data="{ activeTab: 'scheduler' }">
    
    <div class="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
        <div>
            <h1 class="text-3xl font-bold text-white mb-2">
                <i class="fas fa-chart-area text-cyan-400 mr-2"></i> Reporte WFM
            </h1>
            <p class="text-slate-400 flex items-center gap-2">
                Comparativa: Planificado vs. Real vs. Pagable
                <?php if (userHasPermission('hr_employees')): ?>
                    <a href="hr/employees.php" class="text-xs bg-slate-700 hover:bg-slate-600 text-cyan-300 px-2 py-1 rounded transition-colors ml-2" title="Configurar Horarios">
                        <i class="fas fa-cog mr-1"></i> Configurar Horarios
                    </a>
                <?php endif; ?>
            </p>
        </div>
        
        <!-- Tab Switcher -->
        <div class="bg-slate-800 p-1 rounded-lg inline-flex">
            <button @click="activeTab = 'scheduler'"
                :class="{ 'bg-violet-600 text-white shadow-lg': activeTab === 'scheduler', 'text-slate-400 hover:text-white': activeTab !== 'scheduler' }"
                class="px-5 py-2 rounded-md font-medium transition-all duration-200 flex items-center gap-2">
                <i class="fas fa-calendar-alt"></i>
                Scheduler
            </button>
            <button @click="activeTab = 'punch'"
                :class="{ 'bg-cyan-600 text-white shadow-lg': activeTab === 'punch', 'text-slate-400 hover:text-white': activeTab !== 'punch' }"
                class="px-5 py-2 rounded-md font-medium transition-all duration-200 flex items-center gap-2">
                <i class="fas fa-fingerprint"></i>
                Punch (Real)
            </button>
            <button @click="activeTab = 'payroll'"
                :class="{ 'bg-emerald-600 text-white shadow-lg': activeTab === 'payroll', 'text-slate-400 hover:text-white': activeTab !== 'payroll' }"
                class="px-5 py-2 rounded-md font-medium transition-all duration-200 flex items-center gap-2">
                <i class="fas fa-file-invoice-dollar"></i>
                Payroll (TSS)
            </button>
            <button @click="activeTab = 'campaign_hours'"
                :class="{ 'bg-indigo-600 text-white shadow-lg': activeTab === 'campaign_hours', 'text-slate-400 hover:text-white': activeTab !== 'campaign_hours' }"
                class="px-5 py-2 rounded-md font-medium transition-all duration-200 flex items-center gap-2">
                <i class="fas fa-chart-pie"></i>
                Campaign Hours
            </button>
            <button @click="activeTab = 'campaign_ops'"
                :class="{ 'bg-amber-600 text-white shadow-lg': activeTab === 'campaign_ops', 'text-slate-400 hover:text-white': activeTab !== 'campaign_ops' }"
                class="px-5 py-2 rounded-md font-medium transition-all duration-200 flex items-center gap-2">
                <i class="fas fa-chart-line"></i>
                Campaign Ops
            </button>
            <button @click="activeTab = 'staffing'"
                :class="{ 'bg-blue-600 text-white shadow-lg': activeTab === 'staffing', 'text-slate-400 hover:text-white': activeTab !== 'staffing' }"
                class="px-5 py-2 rounded-md font-medium transition-all duration-200 flex items-center gap-2">
                <i class="fas fa-users"></i>
                Staffing (Erlang C)
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700 rounded-xl p-6 mb-8 shadow-xl">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-6 items-end">
            <div>
                <label class="block text-sm font-medium text-slate-400 mb-2">Empleado</label>
                <select name="employee"
                    class="w-full bg-slate-900 border border-slate-700 text-white rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-cyan-500 focus:border-transparent outline-none transition-all">
                    <option value="all">Todos los empleados</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $employeeFilter == $emp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-slate-400 mb-2">Fecha Inicio</label>
                <input type="date" name="start_date" value="<?= $startDate ?>"
                    class="w-full bg-slate-900 border border-slate-700 text-white rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-cyan-500 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-slate-400 mb-2">Fecha Fin</label>
                <input type="date" name="end_date" value="<?= $endDate ?>"
                    class="w-full bg-slate-900 border border-slate-700 text-white rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-cyan-500 outline-none">
            </div>
            
            <div>
                <button type="submit"
                    class="w-full bg-cyan-600 hover:bg-cyan-500 text-white font-bold py-2.5 px-6 rounded-lg shadow-lg hover:shadow-cyan-500/25 transition-all duration-200 flex items-center justify-center gap-2">
                    <i class="fas fa-filter"></i> Filtrar Reporte
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <!-- Scheduled -->
        <div class="bg-slate-800/50 border border-slate-700 rounded-xl p-6 relative overflow-hidden group">
            <div class="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                <i class="fas fa-calendar-alt text-6xl text-violet-500"></i>
            </div>
            <h3 class="text-slate-400 font-medium mb-1">Planificado (Scheduler)</h3>
            <div class="text-3xl font-bold text-white">
                <?= formatDuration($totals['scheduled_seconds']) ?>
            </div>
            <div class="text-xs text-violet-400 mt-2">Expectativa Total</div>
        </div>

        <!-- Real (Net) -->
        <div class="bg-slate-800/50 border border-slate-700 rounded-xl p-6 relative overflow-hidden group">
            <div class="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                <i class="fas fa-fingerprint text-6xl text-cyan-500"></i>
            </div>
            <h3 class="text-slate-400 font-medium mb-1">Punch (Real/Neto)</h3>
            <div class="text-3xl font-bold text-white">
                <?= formatDuration($totals['real_net_seconds']) ?>
            </div>
            <div class="text-xs text-cyan-400 mt-2">Actividad Productiva</div>
        </div>

        <!-- Payroll -->
        <div class="bg-slate-800/50 border border-slate-700 rounded-xl p-6 relative overflow-hidden group">
            <div class="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                <i class="fas fa-file-invoice-dollar text-6xl text-emerald-500"></i>
            </div>
            <h3 class="text-slate-400 font-medium mb-1">Payroll (Pagable)</h3>
            <div class="text-3xl font-bold text-white">
                <?= formatDuration($totals['payroll_seconds']) ?>
            </div>
            <div class="text-xs text-emerald-400 mt-2">Estimado: $<?= number_format($totals['payroll_amount_usd'], 0) ?></div>
        </div>

        <!-- Variance -->
        <?php 
            $var = $totals['real_net_seconds'] - $totals['scheduled_seconds'];
            $varColor = $var >= 0 ? 'text-emerald-400' : 'text-rose-400';
            $varSign = $var >= 0 ? '+' : '-';
        ?>
        <div class="bg-slate-800/50 border border-slate-700 rounded-xl p-6 relative overflow-hidden group">
            <div class="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                <i class="fas fa-balance-scale text-6xl text-amber-500"></i>
            </div>
            <h3 class="text-slate-400 font-medium mb-1">Adherencia (Real vs Plan)</h3>
            <div class="text-3xl font-bold <?= $varColor ?>">
                <?= $varSign . formatDuration(abs($var)) ?>
            </div>
            <div class="text-xs text-amber-400 mt-2">Desviación del Plan</div>
        </div>
    </div>

    <!-- Content Tables -->
    <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-xl overflow-hidden">
        
        <!-- TAB: SCHEDULER -->
        <div x-show="activeTab === 'scheduler'" class="p-0">
             <div class="p-6 border-b border-slate-700 bg-slate-800/50">
                <h3 class="text-xl font-bold text-violet-400">
                    <i class="fas fa-calendar-alt mr-2"></i> Detalle Planificado vs. Realidad
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-900/50 text-slate-400 uppercase text-xs font-semibold">
                        <tr>
                            <th class="p-4">Empleado</th>
                            <th class="p-4">Horas Planificadas</th>
                            <th class="p-4">Horas Reales (Net)</th>
                            <th class="p-4">Cumplimiento</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                         <?php if (empty($reportData)): ?>
                            <tr><td colspan="4" class="p-8 text-center text-slate-500">No se encontraron registros.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
                            <?php 
                                $sched = $row['scheduled_seconds'];
                                $real = $row['real_net_seconds'];
                                $compliance = $sched > 0 ? min(100, round(($real / $sched) * 100)) : 0;
                                $barColor = $compliance >= 95 ? 'bg-emerald-500' : ($compliance >= 85 ? 'bg-amber-500' : 'bg-rose-500');
                            ?>
                            <tr class="hover:bg-slate-700/30 transition-colors">
                                <td class="p-4">
                                    <div class="font-medium text-white"><?= htmlspecialchars($row['user']['full_name']) ?></div>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars($row['user']['username']) ?></div>
                                </td>
                                <td class="p-4">
                                    <span class="font-mono text-violet-300"><?= formatDuration($sched) ?></span>
                                </td>
                                <td class="p-4">
                                    <span class="font-mono text-cyan-300"><?= formatDuration($real) ?></span>
                                    <span class="text-xs text-slate-500 ml-1">(<?= formatDuration($row['real_gross_seconds']) ?> gross)</span>
                                </td>
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-full max-w-[100px] h-2 bg-slate-700 rounded-full overflow-hidden">
                                            <div class="h-full <?= $barColor ?>" style="width: <?= $compliance ?>%"></div>
                                        </div>
                                        <span class="text-xs font-bold <?= str_replace('bg-', 'text-', $barColor) ?>"><?= $compliance ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: PUNCH -->
        <div x-show="activeTab === 'punch'" style="display: none;" class="p-0">
            <div class="p-6 border-b border-slate-700 bg-slate-800/50">
                <h3 class="text-xl font-bold text-cyan-400">
                    <i class="fas fa-fingerprint mr-2"></i> Detalle de Punch (Lo Real)
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-900/50 text-slate-400 uppercase text-xs font-semibold">
                        <tr>
                            <th class="p-4">Empleado</th>
                            <th class="p-4">Actividad Neta (Worked)</th>
                            <th class="p-4">Asistencia Bruta (Presence)</th>
                            <th class="p-4 text-center">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <?php if (empty($reportData)): ?>
                            <tr><td colspan="4" class="p-8 text-center text-slate-500">No se encontraron registros.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
                            <tr class="hover:bg-slate-700/30 transition-colors">
                                <td class="p-4">
                                    <div class="font-medium text-white"><?= htmlspecialchars($row['user']['full_name']) ?></div>
                                </td>
                                <td class="p-4">
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-cyan-500/10 text-cyan-400 font-mono font-medium border border-cyan-500/20">
                                        <?= formatDuration($row['real_net_seconds']) ?>
                                    </span>
                                </td>
                                <td class="p-4 text-slate-400 font-mono">
                                    <?= formatDuration($row['real_gross_seconds']) ?>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if ($row['real_net_seconds'] > 0): ?>
                                        <span class="text-xs font-semibold text-emerald-400 bg-emerald-500/10 px-2 py-1 rounded">ACTIVO</span>
                                    <?php else: ?>
                                        <span class="text-xs font-semibold text-slate-500 bg-slate-700/50 px-2 py-1 rounded">SIN DATOS</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: PAYROLL -->
        <div x-show="activeTab === 'payroll'" style="display: none;" class="p-0">
             <div class="p-6 border-b border-slate-700 bg-slate-800/50">
                <h3 class="text-xl font-bold text-emerald-400">
                    <i class="fas fa-file-invoice-dollar mr-2"></i> Detalle Payroll / TSS (Lo Pagable)
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-900/50 text-slate-400 uppercase text-xs font-semibold">
                        <tr>
                            <th class="p-4">Empleado</th>
                            <th class="p-4">Horas Pagables</th>
                            <th class="p-4">Pago (USD)</th>
                            <th class="p-4">Pago (DOP)</th>
                            <th class="p-4">Diferencia vs Plan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                         <?php if (empty($reportData)): ?>
                            <tr><td colspan="5" class="p-8 text-center text-slate-500">No se encontraron registros.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
                            <tr class="hover:bg-slate-700/30 transition-colors">
                                <td class="p-4">
                                    <div class="font-medium text-white"><?= htmlspecialchars($row['user']['full_name']) ?></div>
                                </td>
                                <td class="p-4">
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-400 font-mono font-medium border border-emerald-500/20">
                                        <?= formatDuration($row['payroll_seconds']) ?>
                                    </span>
                                </td>
                                <td class="p-4 text-slate-300 font-mono">
                                    $<?= number_format($row['pay_usd'], 2) ?>
                                </td>
                                <td class="p-4 text-slate-300 font-mono">
                                    RD$<?= number_format($row['pay_dop'], 2) ?>
                                </td>
                                <td class="p-4">
                                    <?php 
                                        $diff = $row['payroll_seconds'] - $row['scheduled_seconds'];
                                        $color = $diff >= 0 ? 'text-emerald-400' : 'text-rose-400';
                                        $sign = $diff >= 0 ? '+' : '-';
                                    ?>
                                    <span class="<?= $color ?> font-medium font-mono text-xs">
                                        <?= $sign . formatDuration(abs($diff)) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: CAMPAIGN HOURS -->
        <div x-show="activeTab === 'campaign_hours'" style="display: none;" class="p-0">
            <div class="p-6 border-b border-slate-700 bg-slate-800/50">
                <h3 class="text-xl font-bold text-indigo-400">
                    <i class="fas fa-chart-pie mr-2"></i> Horas por Campaña
                </h3>
                <p class="text-sm text-slate-400 mt-2">
                    Distribución de horas planificadas, reales y pagables por campaña
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-900/50 text-slate-400 uppercase text-xs font-semibold">
                        <tr>
                            <th class="p-4">Campaña</th>
                            <th class="p-4">Empleados</th>
                            <th class="p-4">Horas Planificadas</th>
                            <th class="p-4">Horas Reales (Net)</th>
                            <th class="p-4">Horas Pagables</th>
                            <th class="p-4">Adherencia</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <?php if (empty($campaignHours)): ?>
                            <tr><td colspan="6" class="p-8 text-center text-slate-500">
                                No hay empleados asignados a campañas en el período seleccionado.
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($campaignHours as $campaignId => $stats): ?>
                            <?php 
                                $schedHours = $stats['scheduled_seconds'];
                                $realHours = $stats['real_net_seconds'];
                                $payrollHours = $stats['payroll_seconds'];
                                $adherence = $schedHours > 0 ? min(100, round(($realHours / $schedHours) * 100)) : 0;
                                $barColor = $adherence >= 95 ? 'bg-emerald-500' : ($adherence >= 85 ? 'bg-amber-500' : 'bg-rose-500');
                            ?>
                            <tr class="hover:bg-slate-700/30 transition-colors">
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-3 h-3 rounded-full" style="background-color: <?= htmlspecialchars($stats['campaign_color']) ?>"></div>
                                        <div class="font-medium text-white"><?= htmlspecialchars($stats['campaign_name']) ?></div>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-700 text-slate-300 text-sm font-medium">
                                        <i class="fas fa-users text-xs"></i>
                                        <?= $stats['employee_count'] ?>
                                    </span>
                                </td>
                                <td class="p-4">
                                    <span class="font-mono text-violet-300"><?= formatDuration($schedHours) ?></span>
                                </td>
                                <td class="p-4">
                                    <span class="font-mono text-cyan-300"><?= formatDuration($realHours) ?></span>
                                </td>
                                <td class="p-4">
                                    <span class="font-mono text-emerald-300"><?= formatDuration($payrollHours) ?></span>
                                </td>
                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-full max-w-[100px] h-2 bg-slate-700 rounded-full overflow-hidden">
                                            <div class="h-full <?= $barColor ?>" style="width: <?= $adherence ?>%"></div>
                                        </div>
                                        <span class="text-xs font-bold <?= str_replace('bg-', 'text-', $barColor) ?>"><?= $adherence ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: CAMPAIGN OPS -->
        <div x-show="activeTab === 'campaign_ops'" style="display: none;" class="p-0">
            <div class="p-6 border-b border-slate-700 bg-slate-800/50">
                <h3 class="text-xl font-bold text-amber-400">
                    <i class="fas fa-chart-line mr-2"></i> Campaign Ops (Ventas / Ingresos / Volumen)
                </h3>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6 border-b border-slate-700 bg-slate-800/30">
                <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
                    <div class="text-sm text-slate-400 mb-1">Ventas Totales</div>
                    <div class="text-xl font-bold text-white">
                        <?= htmlspecialchars(formatCurrencyTotals($campaignSalesTotals['sales'])) ?>
                    </div>
                </div>
                <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
                    <div class="text-sm text-slate-400 mb-1">Ingresos Totales</div>
                    <div class="text-xl font-bold text-white">
                        <?= htmlspecialchars(formatCurrencyTotals($campaignSalesTotals['revenue'])) ?>
                    </div>
                </div>
                <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
                    <div class="text-sm text-slate-400 mb-1">Volumen Total</div>
                    <div class="text-2xl font-bold text-white">
                        <?= number_format($campaignSalesTotals['volume']) ?>
                    </div>
                    <div class="text-xs text-slate-500 mt-1">Registros: <?= number_format($campaignSalesTotals['records']) ?></div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-900/50 text-slate-400 uppercase text-xs font-semibold">
                        <tr>
                            <th class="p-4">CampaÃ±a</th>
                            <th class="p-4">CÃ³digo</th>
                            <th class="p-4">Moneda</th>
                            <th class="p-4">Ventas</th>
                            <th class="p-4">Ingresos</th>
                            <th class="p-4">Volumen</th>
                            <th class="p-4">Registros</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <?php if (empty($campaignSalesRows)): ?>
                            <tr><td colspan="7" class="p-8 text-center text-slate-500">No se encontraron registros de Campaign Ops.</td></tr>
                        <?php else: ?>
                            <?php foreach ($campaignSalesRows as $row): ?>
                            <tr class="hover:bg-slate-700/30 transition-colors">
                                <td class="p-4">
                                    <div class="font-medium text-white"><?= htmlspecialchars($row['name']) ?></div>
                                </td>
                                <td class="p-4 text-slate-400"><?= htmlspecialchars($row['code']) ?></td>
                                <td class="p-4 text-slate-400"><?= htmlspecialchars($row['currency']) ?></td>
                                <td class="p-4 text-slate-300 font-mono">
                                    <?= formatCurrencyAmount($row['sales_amount'], $row['currency']) ?>
                                </td>
                                <td class="p-4 text-slate-300 font-mono">
                                    <?= formatCurrencyAmount($row['revenue_amount'], $row['currency']) ?>
                                </td>
                                <td class="p-4 text-slate-300 font-mono">
                                    <?= number_format($row['volume']) ?>
                                </td>
                                <td class="p-4 text-slate-500 font-mono">
                                    <?= number_format($row['records']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: STAFFING -->
        <div x-show="activeTab === 'staffing'" style="display: none;" class="p-0">
            <div class="p-6 border-b border-slate-700 bg-slate-800/50">
                <h3 class="text-xl font-bold text-blue-400">
                    <i class="fas fa-users mr-2"></i> WFM / Staffing (Erlang C)
                </h3>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-4 gap-6 border-b border-slate-700 bg-slate-800/30">
                <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
                    <div class="text-sm text-slate-400 mb-1">Intervalos</div>
                    <div class="text-2xl font-bold text-white"><?= number_format($staffingSummary['intervals']) ?></div>
                </div>
                <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
                    <div class="text-sm text-slate-400 mb-1">Volumen Total</div>
                    <div class="text-2xl font-bold text-white"><?= number_format($staffingSummary['total_volume']) ?></div>
                </div>
                <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
                    <div class="text-sm text-slate-400 mb-1">Workload (Erlangs)</div>
                    <div class="text-2xl font-bold text-white"><?= number_format($staffingSummary['total_workload'], 2) ?></div>
                </div>
                <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
                    <div class="text-sm text-slate-400 mb-1">Staff Hours</div>
                    <div class="text-2xl font-bold text-white"><?= number_format($staffingSummary['total_staff_hours'], 2) ?></div>
                    <div class="text-xs text-slate-500 mt-1">Max staff: <?= number_format($staffingSummary['max_required_staff']) ?></div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-900/50 text-slate-400 uppercase text-xs font-semibold">
                        <tr>
                            <th class="p-4">CampaÃ±a</th>
                            <th class="p-4">Intervalo</th>
                            <th class="p-4">Volumen</th>
                            <th class="p-4">AHT (s)</th>
                            <th class="p-4">Erlangs</th>
                            <th class="p-4">SL Obj</th>
                            <th class="p-4">SL Est</th>
                            <th class="p-4">Agentes</th>
                            <th class="p-4">DotaciÃ³n</th>
                            <th class="p-4">Ocup.</th>
                            <th class="p-4">Shrink</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <?php if (empty($staffingRows)): ?>
                            <tr><td colspan="11" class="p-8 text-center text-slate-500">No se encontraron intervalos de staffing.</td></tr>
                        <?php else: ?>
                            <?php foreach ($staffingRows as $row): ?>
                                <?php
                                    $slTargetPct = $row['target_sl'] > 1 ? $row['target_sl'] : ($row['target_sl'] * 100);
                                    $slEstPct = $row['service_level'] * 100;
                                    $occPct = $row['occupancy'] * 100;
                                    $shrinkPct = $row['shrinkage'] > 1 ? $row['shrinkage'] : ($row['shrinkage'] * 100);
                                ?>
                                <tr class="hover:bg-slate-700/30 transition-colors">
                                    <td class="p-4">
                                        <div class="font-medium text-white"><?= htmlspecialchars($row['campaign_name']) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($row['campaign_code']) ?></div>
                                    </td>
                                    <td class="p-4 text-slate-300 font-mono">
                                        <?= htmlspecialchars($row['interval_start']) ?>
                                        <div class="text-xs text-slate-500"><?= (int)$row['interval_minutes'] ?>m <?= $row['channel'] ? '· ' . htmlspecialchars($row['channel']) : '' ?></div>
                                    </td>
                                    <td class="p-4 text-slate-300 font-mono"><?= number_format($row['offered_volume']) ?></td>
                                    <td class="p-4 text-slate-300 font-mono"><?= number_format($row['aht_seconds']) ?></td>
                                    <td class="p-4 text-slate-300 font-mono"><?= number_format($row['workload'], 2) ?></td>
                                    <td class="p-4 text-slate-400 font-mono"><?= number_format($slTargetPct, 1) ?>%</td>
                                    <td class="p-4 text-slate-300 font-mono"><?= number_format($slEstPct, 1) ?>%</td>
                                    <td class="p-4 text-slate-300 font-mono"><?= number_format($row['required_agents']) ?></td>
                                    <td class="p-4 text-slate-300 font-mono"><?= number_format($row['required_staff']) ?></td>
                                    <td class="p-4 text-slate-300 font-mono"><?= number_format($occPct, 1) ?>%</td>
                                    <td class="p-4 text-slate-400 font-mono"><?= number_format($shrinkPct, 1) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php 
// Need to close any HTML tags from header if necessary, but header usually is self-contained.
// Just ensuring footer scripts if any.
?>
<script>
    // Simple Alpine init if needed, but x-data handles it.
</script>
</body>
</html>
