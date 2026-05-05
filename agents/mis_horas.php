<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login_agent.php');
    exit;
}

require_once '../db.php';
require_once '../lib/work_hours_calculator.php';
require_once '../hr/payroll_functions.php';

ensurePayrollPeriodsVisibilityColumn($pdo);
ensurePayrollHolidaysTable($pdo);

$userId = (int)$_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Agente';

$config = getScheduleConfig($pdo);
$scheduledHours = (float)($config['scheduled_hours'] ?? 8.00);

$paidTypeSlugsRaw = getPaidAttendanceTypeSlugs($pdo);
$paidTypeSlugs = array_values(array_filter(array_map('sanitizeAttendanceTypeSlug', $paidTypeSlugsRaw)));

// Compensation context for the logged-in user, used to decide whether
// holiday hours qualify for double pay (fixed-salary employees are excluded).
$userComp = [];
try {
    $userCompStmt = $pdo->prepare("SELECT compensation_type, role, monthly_salary FROM users WHERE id = ?");
    $userCompStmt->execute([$userId]);
    $userComp = $userCompStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    // Column may not exist on older schemas — fall back to "qualifies".
    $userComp = [];
}
$userQualifiesForHolidayDouble = shouldApplyHolidayDoublePay(
    $userComp['compensation_type'] ?? null,
    $userComp['role'] ?? null,
    (float)($userComp['monthly_salary'] ?? 0)
);

$periodsStmt = $pdo->prepare("
    SELECT id, name, period_type, start_date, end_date, payment_date, status
    FROM payroll_periods
    WHERE visible_to_agents = 1
    ORDER BY start_date DESC
");
$periodsStmt->execute();
$periods = $periodsStmt->fetchAll(PDO::FETCH_ASSOC);

$todayISO = date('Y-m-d');

$selectedPeriodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;
$selectedPeriod = null;
if ($selectedPeriodId > 0) {
    foreach ($periods as $p) {
        if ((int)$p['id'] === $selectedPeriodId) {
            $selectedPeriod = $p;
            break;
        }
    }
}
// Default: primer período cuyo rango contenga hoy; si no, el más reciente
if (!$selectedPeriod && !empty($periods)) {
    foreach ($periods as $p) {
        if ($todayISO >= $p['start_date'] && $todayISO <= $p['end_date']) {
            $selectedPeriod = $p;
            break;
        }
    }
    if (!$selectedPeriod) {
        $selectedPeriod = $periods[0];
    }
}

/**
 * Calcula horas trabajadas por día y totales para un rango de fechas.
 * Solo cuenta días hasta HOY (si la quincena incluye fechas futuras).
 *
 * Si el usuario califica para pago doble en feriados, las horas de ese día
 * se multiplican por el multiplicador del feriado (típicamente 2x), igual
 * que en el cálculo de nómina, para que el agente vea cifras consistentes.
 */
function computePeriodHoursForUser(PDO $pdo, int $userId, string $startDate, string $endDate, array $paidTypeSlugs, float $scheduledHours, bool $applyHolidayDouble = false): array
{
    $today = date('Y-m-d');
    $effectiveEnd = ($endDate > $today) ? $today : $endDate;

    $result = [
        'total_seconds' => 0,
        'regular_seconds' => 0,
        'overtime_seconds' => 0,
        'days_worked' => 0,
        'by_day' => [],
        'holiday_days' => [],
    ];

    if ($startDate > $effectiveEnd) {
        return $result;
    }

    $stmt = $pdo->prepare("
        SELECT id, type, timestamp, DATE(timestamp) AS work_date
        FROM attendance
        WHERE user_id = ?
          AND DATE(timestamp) BETWEEN ? AND ?
        ORDER BY timestamp ASC
    ");
    $stmt->execute([$userId, $startDate, $effectiveEnd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byDate = [];
    foreach ($rows as $r) {
        $byDate[$r['work_date']][] = $r;
    }

    $holidaysMap = getPayrollHolidaysMap($pdo, $startDate, $effectiveEnd);
    $scheduledSeconds = (int) round($scheduledHours * 3600);

    foreach ($byDate as $date => $punches) {
        $calc = calculateWorkSecondsFromPunches($punches, $paidTypeSlugs);
        $workSeconds = (int)($calc['work_seconds'] ?? 0);
        if ($workSeconds <= 0) {
            continue;
        }

        $rawSeconds = $workSeconds;
        $isHoliday = isset($holidaysMap[$date]);

        // 1) Split actual worked hours into regular vs. overtime first.
        if ($workSeconds > $scheduledSeconds) {
            $regSeconds = $scheduledSeconds;
            $otSeconds = $workSeconds - $scheduledSeconds;
        } else {
            $regSeconds = $workSeconds;
            $otSeconds = 0;
        }

        // 2) Apply holiday multiplier to BOTH parts after splitting, so the displayed
        // hours match what payroll will pay (true multiplier × normal pay).
        if ($isHoliday && $applyHolidayDouble) {
            $multiplier = (float) $holidaysMap[$date]['multiplier'];
            $regSeconds = (int) round($regSeconds * $multiplier);
            $otSeconds = (int) round($otSeconds * $multiplier);
        }

        $daySeconds = $regSeconds + $otSeconds;
        $result['days_worked']++;
        $result['total_seconds'] += $daySeconds;
        $result['regular_seconds'] += $regSeconds;
        $result['overtime_seconds'] += $otSeconds;
        $result['by_day'][$date] = $daySeconds;

        if ($isHoliday) {
            $result['holiday_days'][$date] = [
                'name' => $holidaysMap[$date]['name'],
                'multiplier' => (float) $holidaysMap[$date]['multiplier'],
                'applied' => $applyHolidayDouble,
                'raw_seconds' => $rawSeconds,
            ];
        }
    }

    ksort($result['by_day']);
    return $result;
}

function formatHoursHM(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return sprintf('%dh %02dm', $h, $m);
}

function formatDecimalHours(int $seconds): string
{
    return number_format($seconds / 3600, 2);
}

function formatDateShort(string $isoDate): string
{
    $t = strtotime($isoDate);
    return $t ? date('d/m/Y', $t) : $isoDate;
}

$periodSummaries = [];
foreach ($periods as $p) {
    $summary = computePeriodHoursForUser(
        $pdo,
        $userId,
        $p['start_date'],
        $p['end_date'],
        $paidTypeSlugs,
        $scheduledHours,
        $userQualifiesForHolidayDouble
    );
    $periodSummaries[(int)$p['id']] = $summary;
}

$selectedSummary = $selectedPeriod
    ? ($periodSummaries[(int)$selectedPeriod['id']] ?? null)
    : null;

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
?>
<?php include '../header_agent.php'; ?>

<div class="max-w-6xl mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6 gap-3">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold text-white mb-1">
                <i class="fas fa-business-time text-emerald-400 mr-2"></i>
                Mis Horas por Quincena
            </h1>
            <p class="text-slate-400 text-sm">Horas acumuladas calculadas en vivo a partir de tus marcaciones.</p>
        </div>
        <div class="text-xs text-slate-500">
            <i class="fas fa-sync-alt mr-1"></i>
            Última actualización: <?= date('d/m/Y H:i') ?>
        </div>
    </div>

    <?php if (empty($periods)): ?>
        <div class="glass-card text-center py-10">
            <i class="fas fa-calendar-times text-slate-500 text-4xl mb-3"></i>
            <h2 class="text-lg font-semibold text-white mb-1">No hay quincenas disponibles</h2>
            <p class="text-slate-400 text-sm">Tu supervisor aún no ha habilitado períodos para que los veas.</p>
        </div>
    <?php else: ?>

        <?php if ($selectedPeriod && $selectedSummary): ?>
            <?php
                $statusColors = [
                    'DRAFT' => 'bg-gray-500',
                    'CALCULATED' => 'bg-blue-500',
                    'APPROVED' => 'bg-purple-500',
                    'PAID' => 'bg-green-500',
                    'CLOSED' => 'bg-gray-600',
                ];
                $statusColor = $statusColors[$selectedPeriod['status']] ?? 'bg-gray-500';
                $isCurrent = $todayISO >= $selectedPeriod['start_date'] && $todayISO <= $selectedPeriod['end_date'];
            ?>
            <div class="glass-card mb-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-xl font-semibold text-white"><?= htmlspecialchars($selectedPeriod['name']) ?></h2>
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold text-white <?= $statusColor ?>">
                                <?= htmlspecialchars($selectedPeriod['status']) ?>
                            </span>
                            <?php if ($isCurrent): ?>
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                    <i class="fas fa-bolt"></i> Actual
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-sm text-slate-400 mt-1">
                            <i class="fas fa-calendar-alt mr-1"></i>
                            <?= formatDateShort($selectedPeriod['start_date']) ?> – <?= formatDateShort($selectedPeriod['end_date']) ?>
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="bg-slate-800/60 border border-slate-700 rounded-lg p-4">
                        <p class="text-xs text-slate-400 uppercase">Horas Totales</p>
                        <p class="text-2xl font-bold text-emerald-400"><?= formatDecimalHours($selectedSummary['total_seconds']) ?></p>
                        <p class="text-xs text-slate-500"><?= formatHoursHM($selectedSummary['total_seconds']) ?></p>
                    </div>
                    <div class="bg-slate-800/60 border border-slate-700 rounded-lg p-4">
                        <p class="text-xs text-slate-400 uppercase">Horas Regulares</p>
                        <p class="text-2xl font-bold text-sky-400"><?= formatDecimalHours($selectedSummary['regular_seconds']) ?></p>
                        <p class="text-xs text-slate-500"><?= formatHoursHM($selectedSummary['regular_seconds']) ?></p>
                    </div>
                    <div class="bg-slate-800/60 border border-slate-700 rounded-lg p-4">
                        <p class="text-xs text-slate-400 uppercase">Horas Extra</p>
                        <p class="text-2xl font-bold text-amber-400"><?= formatDecimalHours($selectedSummary['overtime_seconds']) ?></p>
                        <p class="text-xs text-slate-500"><?= formatHoursHM($selectedSummary['overtime_seconds']) ?></p>
                    </div>
                    <div class="bg-slate-800/60 border border-slate-700 rounded-lg p-4">
                        <p class="text-xs text-slate-400 uppercase">Días Trabajados</p>
                        <p class="text-2xl font-bold text-indigo-400"><?= (int)$selectedSummary['days_worked'] ?></p>
                        <p class="text-xs text-slate-500">jornada base: <?= number_format($scheduledHours, 2) ?>h</p>
                    </div>
                </div>

                <?php if (!empty($selectedSummary['by_day'])): ?>
                    <div class="mt-6">
                        <h3 class="text-sm font-semibold text-slate-300 mb-2">Detalle diario</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-700 text-slate-400">
                                        <th class="text-left py-2 px-3">Fecha</th>
                                        <th class="text-right py-2 px-3">Horas trabajadas</th>
                                        <th class="text-right py-2 px-3">Equivalente</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($selectedSummary['by_day'] as $date => $secs): ?>
                                        <?php $holidayInfo = $selectedSummary['holiday_days'][$date] ?? null; ?>
                                        <tr class="border-b border-slate-800/60 hover:bg-slate-800/40 <?= $holidayInfo ? 'bg-yellow-500/5' : '' ?>">
                                            <td class="py-2 px-3">
                                                <?= formatDateShort($date) ?>
                                                <?php if ($holidayInfo): ?>
                                                    <?php if (!empty($holidayInfo['applied'])): ?>
                                                        <span class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-yellow-500/20 text-yellow-300 border border-yellow-500/40" title="<?= htmlspecialchars($holidayInfo['name']) ?>">
                                                            <i class="fas fa-star mr-1"></i>
                                                            Pago <?= number_format($holidayInfo['multiplier'], 2) ?>x · <?= htmlspecialchars($holidayInfo['name']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-600/40 text-slate-300 border border-slate-500/40" title="<?= htmlspecialchars($holidayInfo['name']) ?>">
                                                            <i class="fas fa-star mr-1"></i>
                                                            Festivo (no aplica a salario fijo)
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 px-3 text-right font-mono text-slate-200">
                                                <?= formatDecimalHours($secs) ?>
                                                <?php if ($holidayInfo && !empty($holidayInfo['applied'])): ?>
                                                    <div class="text-[10px] text-yellow-300/80 font-normal">
                                                        Real: <?= formatDecimalHours($holidayInfo['raw_seconds']) ?> × <?= number_format($holidayInfo['multiplier'], 2) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 px-3 text-right text-slate-400 text-xs"><?= formatHoursHM($secs) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!empty($selectedSummary['holiday_days'])): ?>
                            <p class="mt-3 text-xs text-yellow-300/80">
                                <i class="fas fa-info-circle mr-1"></i>
                                <?php if ($userQualifiesForHolidayDouble): ?>
                                    Las horas de los días marcados como festivo se pagan al doble.
                                <?php else: ?>
                                    Los días festivos no aplican a empleados con salario fijo.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="mt-6 text-slate-400 text-sm text-center py-4">
                        <i class="fas fa-info-circle mr-1"></i>
                        Aún no tienes horas registradas en este período.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="glass-card">
            <h2 class="text-lg font-semibold text-white mb-4">
                <i class="fas fa-list text-slate-400 mr-2"></i>
                Todas las quincenas disponibles
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-700 text-slate-400">
                            <th class="text-left py-2 px-3">Período</th>
                            <th class="text-left py-2 px-3">Rango</th>
                            <th class="text-center py-2 px-3">Estado</th>
                            <th class="text-right py-2 px-3">Horas totales</th>
                            <th class="text-right py-2 px-3">Días</th>
                            <th class="text-center py-2 px-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periods as $p): ?>
                            <?php
                                $sum = $periodSummaries[(int)$p['id']] ?? ['total_seconds' => 0, 'days_worked' => 0];
                                $isActive = $selectedPeriod && (int)$selectedPeriod['id'] === (int)$p['id'];
                                $isCurrent = $todayISO >= $p['start_date'] && $todayISO <= $p['end_date'];
                                $rowClass = $isActive ? 'bg-slate-800/60' : 'hover:bg-slate-800/40';
                            ?>
                            <tr class="border-b border-slate-800/60 <?= $rowClass ?>">
                                <td class="py-2 px-3">
                                    <div class="font-medium text-white"><?= htmlspecialchars($p['name']) ?></div>
                                    <?php if ($isCurrent): ?>
                                        <span class="text-xs text-emerald-300"><i class="fas fa-circle text-[6px] mr-1"></i>En curso</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 px-3 text-slate-300">
                                    <?= formatDateShort($p['start_date']) ?> – <?= formatDateShort($p['end_date']) ?>
                                </td>
                                <td class="py-2 px-3 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold text-white <?= $statusColors[$p['status']] ?? 'bg-gray-500' ?>">
                                        <?= htmlspecialchars($p['status']) ?>
                                    </span>
                                </td>
                                <td class="py-2 px-3 text-right font-mono"><?= formatDecimalHours($sum['total_seconds']) ?></td>
                                <td class="py-2 px-3 text-right"><?= (int)$sum['days_worked'] ?></td>
                                <td class="py-2 px-3 text-center">
                                    <a href="?period_id=<?= (int)$p['id'] ?>" class="px-2 py-1 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-xs">
                                        <i class="fas fa-eye"></i> Ver
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>
</body>
</html>
