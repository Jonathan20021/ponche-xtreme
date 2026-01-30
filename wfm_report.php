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
$usersQuery = "SELECT id, full_name, username, employee_code, department_id, role, hourly_rate, hourly_rate_dop FROM users"; // Added rates to select
$params = [];
$userIds = [];

if ($employeeFilter !== 'all' && is_numeric($employeeFilter)) {
    $usersQuery .= " WHERE id = ?";
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

// Helper to resolve schedule from memory
function resolveScheduleHours($map, $defaultConfig, $userId, $dateStr) {
    if (isset($map[$userId])) {
        foreach ($map[$userId] as $sch) {
            // Check dates
            // effective_date <= dateStr AND (end_date IS NULL OR end_date >= dateStr)
            $effDate = $sch['effective_date'] ?? '0000-00-00';
            $endDate = $sch['end_date'];
            
            if ($effDate <= $dateStr) {
                if ($endDate === null || $endDate >= $dateStr) {
                    return (float)($sch['scheduled_hours'] ?? 0);
                }
            }
        }
    }
    // Fallback global
    return (float)($defaultConfig['scheduled_hours'] ?? 8.0);
}

$dateRangeIter = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1D'),
    (new DateTime($endDate))->modify('+1 day')
);

foreach ($users as $user) {
    $userId = $user['id'];
    $userPunches = $punchesMap[$userId] ?? [];

    // --- LOGIC: SCHEDULER (LO PLANIFICADO) ---
    $scheduledSeconds = 0;
    foreach ($dateRangeIter as $dt) {
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
        'pay_dop' => $payDop
    ];
    
    $totals['real_gross_seconds'] += $realGrossSeconds;
    $totals['real_net_seconds'] += $realNetSeconds;
    $totals['payroll_seconds'] += $payrollSeconds;
    $totals['scheduled_seconds'] += $scheduledSeconds;
    $totals['payroll_amount_usd'] += $payUsd;
    $totals['payroll_amount_dop'] += $payDop;
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