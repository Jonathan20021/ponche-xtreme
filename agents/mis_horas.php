<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login_agent.php');
    exit;
}

require_once '../db.php';
require_once '../lib/work_hours_calculator.php';
require_once '../hr/payroll_functions.php';
require_once '../lib/vicidial_api_client.php';
require_once '../lib/agent_hours.php';

ensurePayrollPeriodsVisibilityColumn($pdo);
ensurePayrollHolidaysTable($pdo);
ensureUserPayrollSourceColumn($pdo);

$userId = (int)$_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Agente';

// Fuente de horas del agente: 'vicidial' o 'manual' (ponche). Se respeta el mismo
// flag que usa la nómina (hr/payroll.php) para que Mis Horas coincida EXACTO con
// lo que se paga.
$payrollSource = 'manual';
try {
    $psStmt = $pdo->prepare("SELECT COALESCE(payroll_source, 'manual') FROM users WHERE id = ?");
    $psStmt->execute([$userId]);
    $payrollSource = $psStmt->fetchColumn() ?: 'manual';
} catch (Throwable $e) {
    $payrollSource = 'manual';
}

$weeklyOvertimeThresholdHours = 44.00;

$paidTypeSlugsRaw = getPaidAttendanceTypeSlugs($pdo);
$paidTypeSlugs = array_values(array_filter(array_map('sanitizeAttendanceTypeSlug', $paidTypeSlugsRaw)));

// Compensation context for the logged-in user, used to decide whether
// holiday hours qualify for double pay (fixed-salary employees are excluded).
$userComp = [];
try {
    $userCompStmt = $pdo->prepare("SELECT compensation_type, role, monthly_salary, monthly_salary_dop FROM users WHERE id = ?");
    $userCompStmt->execute([$userId]);
    $userComp = $userCompStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    // Column may not exist on older schemas — fall back to "qualifies".
    $userComp = [];
}
$userQualifiesForHolidayDouble = shouldApplyHolidayDoublePay(
    $userComp['compensation_type'] ?? null,
    $userComp['role'] ?? null,
    max((float)($userComp['monthly_salary'] ?? 0), (float)($userComp['monthly_salary_dop'] ?? 0))
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

// El cálculo de horas por período vive ahora en lib/agent_hours.php
// (computePeriodHoursForUser), compartido con el dashboard del agente y alineado
// EXACTO con la nómina, incluido el respaldo al ponche cuando un agente de
// Vicidial no tiene datos en el período.

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
        $weeklyOvertimeThresholdHours,
        $userQualifiesForHolidayDouble,
        $payrollSource
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

<div class="agent-dashboard">
    <div class="ag-pagehead">
        <div>
            <h1><i class="fas fa-business-time" style="color:var(--ag-brand);"></i> Mis Horas por Quincena</h1>
            <p>Horas acumuladas <?= $payrollSource === 'vicidial' ? 'desde Vicidial' : 'a partir de tus marcaciones' ?>, iguales a las de tu nómina.</p>
        </div>
        <div class="ag-head-actions">
            <span class="ag-chip"><i class="fas fa-rotate"></i> Actualizado <?= date('d/m · H:i') ?></span>
        </div>
    </div>

    <?php if (empty($periods)): ?>
        <div class="ag-card ag-sec">
            <div class="ag-empty-state"><i class="fas fa-calendar-xmark"></i><p>No hay quincenas disponibles. Tu supervisor aún no ha habilitado períodos para que los veas.</p></div>
        </div>
    <?php else: ?>
        <?php
            $tagStyles = [
                'DRAFT' => 'background:#EEF1F6;color:#64748B',
                'CALCULATED' => 'background:#E8F1FE;color:#1D5DB8',
                'APPROVED' => 'background:#EDEBFB;color:#5347CE',
                'PAID' => 'background:var(--ag-green-bg);color:#0B7A4B',
                'CLOSED' => 'background:#E5E8EE;color:#475569',
            ];
        ?>

        <?php if ($selectedPeriod && $selectedSummary): ?>
            <?php $isCurrent = $todayISO >= $selectedPeriod['start_date'] && $todayISO <= $selectedPeriod['end_date']; ?>
            <div class="ag-card ag-sec">
                <div class="ag-sec-head">
                    <div>
                        <div class="ttl" style="flex-wrap:wrap; gap:10px;">
                            <?= htmlspecialchars($selectedPeriod['name']) ?>
                            <span class="ag-tag" style="<?= $tagStyles[$selectedPeriod['status']] ?? 'background:#EEF1F6;color:#64748B' ?>"><?= htmlspecialchars($selectedPeriod['status']) ?></span>
                            <?php if ($isCurrent): ?><span class="ag-tag" style="background:var(--ag-green-bg);color:#0B7A4B;"><i class="fas fa-bolt"></i> Actual</span><?php endif; ?>
                        </div>
                        <div class="sub"><i class="fas fa-calendar" style="margin-right:5px;"></i><?= formatDateShort($selectedPeriod['start_date']) ?> – <?= formatDateShort($selectedPeriod['end_date']) ?></div>
                    </div>
                </div>

                <div class="ag-grid ag-kpis" style="gap:12px;">
                    <div class="ag-statcard">
                        <div class="sc-top"><div class="sc-ico" style="background:var(--ag-brand);"><i class="fas fa-clock"></i></div><div class="sc-lbl">Horas totales</div></div>
                        <div class="sc-val"><?= formatDecimalHours($selectedSummary['total_seconds']) ?></div>
                        <div class="sc-sub"><?= formatHoursHM($selectedSummary['total_seconds']) ?></div>
                    </div>
                    <div class="ag-statcard">
                        <div class="sc-top"><div class="sc-ico" style="background:var(--ag-blue);"><i class="fas fa-briefcase"></i></div><div class="sc-lbl">Regulares</div></div>
                        <div class="sc-val"><?= formatDecimalHours($selectedSummary['regular_seconds']) ?></div>
                        <div class="sc-sub"><?= formatHoursHM($selectedSummary['regular_seconds']) ?></div>
                    </div>
                    <div class="ag-statcard">
                        <div class="sc-top"><div class="sc-ico" style="background:var(--ag-amber);"><i class="fas fa-bolt"></i></div><div class="sc-lbl">Extra</div></div>
                        <div class="sc-val"><?= formatDecimalHours($selectedSummary['overtime_seconds']) ?></div>
                        <div class="sc-sub"><?= formatHoursHM($selectedSummary['overtime_seconds']) ?></div>
                    </div>
                    <div class="ag-statcard">
                        <div class="sc-top"><div class="sc-ico" style="background:var(--ag-purple);"><i class="fas fa-calendar-check"></i></div><div class="sc-lbl">Días trabajados</div></div>
                        <div class="sc-val"><?= (int) $selectedSummary['days_worked'] ?></div>
                        <div class="sc-sub">base semanal: <?= number_format($weeklyOvertimeThresholdHours, 0) ?>h</div>
                    </div>
                </div>

                <?php if (!empty($selectedSummary['by_day'])): ?>
                    <div style="margin-top:20px;">
                        <div style="font-size:13.5px; font-weight:700; color:var(--ag-text); display:flex; align-items:center; gap:8px; margin-bottom:10px;"><i class="fas fa-calendar-day" style="color:var(--ag-brand);"></i> Detalle diario</div>
                        <div style="overflow-x:auto;">
                            <table class="ag-table">
                                <thead><tr><th>Fecha</th><th style="text-align:right;">Horas</th><th style="text-align:right;">Equivalente</th></tr></thead>
                                <tbody>
                                    <?php foreach ($selectedSummary['by_day'] as $date => $secs): ?>
                                        <?php $holidayInfo = $selectedSummary['holiday_days'][$date] ?? null; ?>
                                        <tr>
                                            <td>
                                                <?= formatDateShort($date) ?>
                                                <?php if ($holidayInfo): ?>
                                                    <?php if (!empty($holidayInfo['applied'])): ?>
                                                        <span class="ag-tag" style="background:var(--ag-amber-bg);color:#B54708;margin-left:8px;" title="<?= htmlspecialchars($holidayInfo['name']) ?>"><i class="fas fa-star"></i> <?= number_format($holidayInfo['multiplier'], 2) ?>x</span>
                                                    <?php else: ?>
                                                        <span class="ag-tag" style="background:#EEF1F6;color:var(--ag-muted);margin-left:8px;" title="<?= htmlspecialchars($holidayInfo['name']) ?>"><i class="fas fa-star"></i> Festivo</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right; font-weight:700;">
                                                <?= formatDecimalHours($secs) ?>
                                                <?php if ($holidayInfo && !empty($holidayInfo['applied'])): ?><div class="ag-tsub" style="color:#B54708;">Real: <?= formatDecimalHours($holidayInfo['raw_seconds']) ?> × <?= number_format($holidayInfo['multiplier'], 2) ?></div><?php endif; ?>
                                            </td>
                                            <td style="text-align:right;" class="ag-tsub"><?= formatHoursHM($secs) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!empty($selectedSummary['holiday_days'])): ?>
                            <p class="ag-hint" style="margin-top:12px;"><i class="fas fa-circle-info" style="margin-right:5px;"></i><?= $userQualifiesForHolidayDouble ? 'Las horas de días festivos se pagan al doble.' : 'Los días festivos no aplican a empleados con salario fijo.' ?></p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="ag-empty-state" style="min-height:110px;"><i class="fas fa-clock"></i><p>Aún no tienes horas registradas en este período.</p></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="ag-card ag-sec ag-mt">
            <div class="ag-sec-head"><div class="ttl"><i class="fas fa-list-ul"></i> Todas las quincenas</div><span class="ag-chip"><?= count($periods) ?> períodos</span></div>
            <div style="overflow-x:auto;">
                <table class="ag-table">
                    <thead><tr><th>Período</th><th>Rango</th><th style="text-align:center;">Estado</th><th style="text-align:right;">Horas</th><th style="text-align:right;">Días</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($periods as $p): ?>
                            <?php
                                $sum = $periodSummaries[(int)$p['id']] ?? ['total_seconds' => 0, 'days_worked' => 0];
                                $isActive = $selectedPeriod && (int)$selectedPeriod['id'] === (int)$p['id'];
                                $isCur = $todayISO >= $p['start_date'] && $todayISO <= $p['end_date'];
                            ?>
                            <tr<?= $isActive ? ' style="background:#F5F9FF;"' : '' ?>>
                                <td>
                                    <b><?= htmlspecialchars($p['name']) ?></b>
                                    <?php if ($isCur): ?><div class="ag-tsub" style="color:var(--ag-green);"><i class="fas fa-circle" style="font-size:6px;"></i> En curso</div><?php endif; ?>
                                </td>
                                <td class="ag-tsub"><?= formatDateShort($p['start_date']) ?> – <?= formatDateShort($p['end_date']) ?></td>
                                <td style="text-align:center;"><span class="ag-tag" style="<?= $tagStyles[$p['status']] ?? 'background:#EEF1F6;color:#64748B' ?>"><?= htmlspecialchars($p['status']) ?></span></td>
                                <td style="text-align:right; font-weight:700;"><?= formatDecimalHours($sum['total_seconds']) ?></td>
                                <td style="text-align:right;"><?= (int)$sum['days_worked'] ?></td>
                                <td style="text-align:right;"><a href="?period_id=<?= (int)$p['id'] ?>" class="ag-chip" style="text-decoration:none;"><i class="fas fa-eye"></i> Ver</a></td>
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
