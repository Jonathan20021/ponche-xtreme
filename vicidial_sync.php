<?php
/**
 * Conciliación Vicidial vs Marcación Manual (Fase 1 - MODO SOMBRA)
 *
 * Compara, por empleado y día, la hoja de tiempo importada de Vicidial
 * (login/logout/total) contra la marcación manual de ponche (ENTRY/EXIT y horas
 * pagadas). NO altera la nómina: es solo para medir el desfase antes de decidir
 * la Fase 2. Incluye gestión del mapeo Vicidial->empleado, importación manual y
 * bitácora de corridas.
 */

session_start();
require_once 'db.php';
require_once 'lib/authorization_functions.php';
require_once 'lib/vicidial_api_client.php';
require_once 'lib/work_hours_calculator.php';

ensurePermission('vicidial_sync');

date_default_timezone_set('America/Santo_Domingo');

// ---------------------------------------------------------------------------
// Acciones (POST) — patrón PRG: procesar, guardar flash en sesión y redirigir.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $okMsgs = [];
    $errMsgs = [];
    $redirTab = $_POST['tab'] ?? 'concile';
    $redirDate = $_POST['date'] ?? date('Y-m-d', strtotime('yesterday'));

    if ($action === 'save_mapping') {
        $vu = trim($_POST['vicidial_user'] ?? '');
        $uid = ($_POST['user_id'] ?? '') !== '' ? (int) $_POST['user_id'] : null;
        $ignore = isset($_POST['ignore_agent']) ? 1 : 0;
        if ($vu !== '') {
            try {
                // Confirmar a mano bloquea la sugerencia automática (auto_matched=0).
                $stmt = $pdo->prepare("
                    UPDATE vicidial_user_map
                    SET user_id = :uid, ignore_agent = :ig, auto_matched = 0, updated_at = NOW()
                    WHERE vicidial_user = :vu
                ");
                $stmt->execute([':uid' => $uid, ':ig' => $ignore, ':vu' => $vu]);
                $okMsgs[] = 'Mapeo de ' . $vu . ' guardado.';
            } catch (PDOException $e) {
                $errMsgs[] = 'Error al guardar el mapeo de ' . $vu . '.';
            }
        }
        $redirTab = 'mapping';
    } elseif ($action === 'import_now') {
        $d = $_POST['import_date'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $summary = importVicidialDay($pdo, getVicidialSyncConfig($pdo), $d, 'manual');
            if ($summary['status'] === 'error') {
                $errMsgs[] = 'Importación de ' . $d . ' falló: ' . implode(' | ', array_slice($summary['errors'], 0, 3));
            } else {
                $okMsgs[] = "Importado $d · agentes: {$summary['agents_in_report']} · timesheets: {$summary['timesheets_fetched']} · filas: {$summary['rows_upserted']} · mapeos nuevos: {$summary['new_mappings']}"
                    . ($summary['status'] === 'partial' ? ' (parcial, ver bitácora)' : '');
            }
            $redirDate = $d;
        } else {
            $errMsgs[] = 'Fecha de importación inválida.';
        }
        $redirTab = 'status';
    } elseif ($action === 'test_connection') {
        $t = vicidialTestConnection(getVicidialSyncConfig($pdo));
        if ($t['ok']) {
            $okMsgs[] = 'Conexión OK · Versión ' . ($t['version'] ?? '') . ' · TZ server: ' . ($t['tz'] ?? '');
        } else {
            $errMsgs[] = 'Conexión falló: ' . ($t['error'] ?? 'desconocido');
        }
        $redirTab = 'status';
    } elseif ($action === 'save_payroll_source') {
        // Activa/desactiva el pago DESDE Vicidial por agente (users.payroll_source).
        // Es lo que hace que la nómina use las horas Vicidial de ese empleado.
        $uid = (int) ($_POST['user_id'] ?? 0);
        $src = ($_POST['payroll_source'] ?? 'manual') === 'vicidial' ? 'vicidial' : 'manual';
        if ($uid > 0) {
            try {
                $pdo->prepare("UPDATE users SET payroll_source = ? WHERE id = ?")->execute([$src, $uid]);
                $okMsgs[] = 'Fuente de pago actualizada a ' . ($src === 'vicidial' ? 'VICIDIAL' : 'Ponche') . '.';
            } catch (PDOException $e) {
                $errMsgs[] = 'Error al actualizar la fuente de pago.';
            }
        }
        $redirTab = 'payroll';
    } elseif ($action === 'bulk_payroll_source') {
        // Política masiva: todos los AGENTES de call (rol AGENT, mapeados y no
        // ignorados) pagan por Vicidial; el resto (administrativos) por ponche.
        // Solo toca agentes MAPEADOS (con datos) para que ninguno cobre 0.
        $mode = $_POST['bulk_mode'] ?? '';
        try {
            if ($mode === 'agents_vicidial') {
                $pdo->exec("UPDATE users u JOIN vicidial_user_map m ON m.user_id = u.id AND m.ignore_agent = 0 SET u.payroll_source = 'vicidial' WHERE UPPER(u.role) = 'AGENT'");
                $n = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE payroll_source = 'vicidial'")->fetchColumn();
                $okMsgs[] = "Aplicado: $n agente(s) de call pagan por Vicidial. Los administrativos siguen por ponche.";
            } elseif ($mode === 'all_manual') {
                $pdo->exec("UPDATE users SET payroll_source = 'manual'");
                $okMsgs[] = 'Revertido: TODOS pagan por ponche manual (la nómina no cambia).';
            }
        } catch (PDOException $e) {
            $errMsgs[] = 'Error al aplicar la política masiva.';
        }
        $redirTab = 'payroll';
    }

    $_SESSION['vsync_flash'] = ['ok' => $okMsgs, 'err' => $errMsgs];
    header('Location: vicidial_sync.php?tab=' . urlencode($redirTab) . '&date=' . urlencode($redirDate));
    exit;
}

$flash = $_SESSION['vsync_flash'] ?? ['ok' => [], 'err' => []];
unset($_SESSION['vsync_flash']);

// ---------------------------------------------------------------------------
// Parámetros de vista
// ---------------------------------------------------------------------------
$tab = $_GET['tab'] ?? 'concile';
$date = $_GET['date'] ?? date('Y-m-d', strtotime('yesterday'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d', strtotime('yesterday'));
}

$cfg = getVicidialSyncConfig($pdo);
$syncEnabled = ($cfg['vicidial_sync_enabled'] ?? '0') === '1';

// ---------------------------------------------------------------------------
// Helpers de formato
// ---------------------------------------------------------------------------
function vfmtTime(?string $dt): string
{
    $dt = trim((string) $dt);
    if ($dt === '' || strtotime($dt) === false) {
        return '—';
    }
    return date('H:i', strtotime($dt));
}
function vfmtHours(int $seconds): string
{
    if ($seconds <= 0) {
        return '—';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? sprintf('%dh %02dm', $h, $m) : sprintf('%dm', $m);
}
/** Delta en minutos con signo + color según magnitud. */
function vfmtDeltaMin(?int $min): array
{
    if ($min === null) {
        return ['text' => '—', 'class' => 'text-slate-500'];
    }
    $abs = abs($min);
    $cls = $abs <= 5 ? 'text-green-400' : ($abs <= 20 ? 'text-amber-400' : 'text-red-400');
    $sign = $min > 0 ? '+' : ($min < 0 ? '−' : '');
    return ['text' => $sign . $abs . 'm', 'class' => $cls];
}

// ---------------------------------------------------------------------------
// Datos de conciliación para la fecha
// ---------------------------------------------------------------------------
$rows = [];
$kpi = ['compared' => 0, 'missing_manual' => 0, 'missing_vici' => 0, 'entry_deltas' => [], 'hours_delta_sec' => 0];

if ($tab === 'concile') {
    // 1) Hoja de tiempo Vicidial del día, con mapeo en vivo (no el snapshot).
    $vStmt = $pdo->prepare("
        SELECT t.vicidial_user, t.vicidial_name, t.user_group, t.first_login, t.last_activity,
               t.total_logged_seconds, t.calls, t.pause_seconds, t.talk_seconds,
               m.user_id AS mapped_user_id, m.ignore_agent,
               u.username, u.full_name
        FROM vicidial_agent_timesheet t
        LEFT JOIN vicidial_user_map m ON m.vicidial_user = t.vicidial_user
        LEFT JOIN users u ON u.id = m.user_id
        WHERE t.report_date = :d
        ORDER BY t.total_logged_seconds DESC
    ");
    $vStmt->execute([':d' => $date]);
    $vRows = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 2) Marcación manual del día para los usuarios mapeados.
    $mappedIds = [];
    foreach ($vRows as $r) {
        if ($r['mapped_user_id'] !== null && (int) $r['ignore_agent'] !== 1) {
            $mappedIds[(int) $r['mapped_user_id']] = true;
        }
    }
    $manualByUser = [];
    if (!empty($mappedIds)) {
        $ids = array_keys($mappedIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $aStmt = $pdo->prepare("
            SELECT user_id, type, timestamp
            FROM attendance
            WHERE DATE(timestamp) = ? AND user_id IN ($ph)
            ORDER BY timestamp ASC
        ");
        $aStmt->execute(array_merge([$date], $ids));
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
            $manualByUser[(int) $a['user_id']][] = $a;
        }
    }

    // Slugs pagados EXACTAMENTE como la nómina oficial (hr/payroll.php): sanitizados
    // y filtrando vacíos, para que las horas trabajadas reconcilien byte a byte.
    $paidSlugs = array_values(array_filter(array_map('sanitizeAttendanceTypeSlug', getPaidAttendanceTypeSlugs($pdo))));

    // 3) Combinar y calcular deltas.
    foreach ($vRows as $r) {
        $uid = $r['mapped_user_id'] !== null ? (int) $r['mapped_user_id'] : null;
        $ignored = (int) $r['ignore_agent'] === 1;

        $manualEntry = null;
        $manualExit = null;
        $manualWorked = 0;
        $hasManual = false;

        if ($uid !== null && isset($manualByUser[$uid])) {
            $hasManual = true;
            foreach ($manualByUser[$uid] as $p) {
                $t = strtotime($p['timestamp']);
                $type = strtoupper($p['type']);
                if ($type === 'ENTRY' && ($manualEntry === null || $t < $manualEntry)) {
                    $manualEntry = $t;
                }
                if ($type === 'EXIT' && ($manualExit === null || $t > $manualExit)) {
                    $manualExit = $t;
                }
            }
            $calc = calculateWorkSecondsFromPunches($manualByUser[$uid], $paidSlugs);
            $manualWorked = (int) ($calc['work_seconds'] ?? 0);
        }

        $viciLogin = $r['first_login'] ? strtotime($r['first_login']) : null;
        $viciLast = $r['last_activity'] ? strtotime($r['last_activity']) : null;
        $viciLogged = (int) $r['total_logged_seconds'];

        $entryDelta = ($viciLogin !== null && $manualEntry !== null) ? (int) round(($viciLogin - $manualEntry) / 60) : null;
        $exitDelta = ($viciLast !== null && $manualExit !== null) ? (int) round(($viciLast - $manualExit) / 60) : null;
        $hoursDelta = ($hasManual && $manualWorked > 0) ? ($viciLogged - $manualWorked) : null;

        $status = 'ok';
        if ($uid === null && !$ignored) {
            $status = 'unmapped';
        } elseif ($ignored) {
            $status = 'ignored';
        } elseif (!$hasManual) {
            $status = 'missing_manual';
        }

        // KPIs (solo filas conciliables reales)
        if ($status === 'ok' || $status === 'missing_manual') {
            if ($status === 'ok') {
                $kpi['compared']++;
                if ($entryDelta !== null) {
                    $kpi['entry_deltas'][] = $entryDelta;
                }
                if ($hoursDelta !== null) {
                    $kpi['hours_delta_sec'] += $hoursDelta;
                }
            } else {
                $kpi['missing_manual']++;
            }
        }

        $rows[] = [
            'vicidial_user' => $r['vicidial_user'],
            'vicidial_name' => $r['vicidial_name'],
            'user_group' => $r['user_group'],
            'username' => $r['username'],
            'full_name' => $r['full_name'],
            'status' => $status,
            'manual_entry' => $manualEntry,
            'manual_exit' => $manualExit,
            'manual_worked' => $manualWorked,
            'vici_login' => $viciLogin,
            'vici_last' => $viciLast,
            'vici_logged' => $viciLogged,
            'calls' => (int) $r['calls'],
            'entry_delta' => $entryDelta,
            'exit_delta' => $exitDelta,
            'hours_delta' => $hoursDelta,
        ];
    }

    // Empleados que SÍ marcaron manual pero NO están en Vicidial ese día
    // (candidatos a revisión: trabajo fuera de call o mapeo faltante). Solo
    // contamos, para no ensuciar la tabla principal.
    $kpi['missing_vici'] = 0; // (se puede ampliar en Fase 2)
}

// Datos para pestaña de mapeo
$mapRows = [];
$activeUsers = [];
if ($tab === 'mapping') {
    $mapRows = $pdo->query("
        SELECT m.vicidial_user, m.vicidial_name, m.user_id, m.auto_matched, m.ignore_agent,
               u.username, u.full_name
        FROM vicidial_user_map m
        LEFT JOIN users u ON u.id = m.user_id
        ORDER BY (m.user_id IS NULL) DESC, m.auto_matched DESC, m.vicidial_user ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $activeUsers = $pdo->query("SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Datos para pestaña de estado / bitácora
$syncLog = [];
if ($tab === 'status') {
    $syncLog = $pdo->query("
        SELECT target_date, status, agents_in_report, timesheets_fetched, rows_upserted,
               new_mappings, duration_ms, triggered_by, message, created_at
        FROM vicidial_sync_log
        ORDER BY created_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Datos para la pestaña de Nómina (control por-agente + semáforo de match)
$payrollRows = [];
$refStart = $refEnd = null;
if ($tab === 'payroll') {
    require_once __DIR__ . '/lib/work_hours_calculator.php';
    $refEnd = $date;
    $refStart = date('Y-m-d', strtotime($date . ' -13 days')); // 2 semanas de referencia
    $paidSlugs = array_values(array_filter(array_map('sanitizeAttendanceTypeSlug', getPaidAttendanceTypeSlugs($pdo))));
    $agents = $pdo->query("
        SELECT u.id, u.full_name, u.role, COALESCE(u.payroll_source, 'manual') AS payroll_source
        FROM vicidial_user_map m
        JOIN users u ON u.id = m.user_id
        WHERE m.user_id IS NOT NULL AND m.ignore_agent = 0
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($agents as $a) {
        $st = $pdo->prepare("SELECT id, type, timestamp, DATE(timestamp) work_date FROM attendance WHERE user_id = ? AND DATE(timestamp) BETWEEN ? AND ? ORDER BY timestamp");
        $st->execute([$a['id'], $refStart, $refEnd]);
        $md = calculateDailyWorkSecondsFromPunchRows($st->fetchAll(PDO::FETCH_ASSOC), $paidSlugs);
        $ponche = array_sum($md);
        $vd = vicidialGetPaidSecondsByDate($pdo, (int) $a['id'], $refStart, $refEnd);
        $vici = array_sum($vd['by_date']);
        $ratio = $ponche > 0 ? (int) round(100 * $vici / $ponche) : ($vici > 0 ? 999 : 0);
        $payrollRows[] = [
            'id' => (int) $a['id'], 'full_name' => $a['full_name'], 'role' => $a['role'],
            'source' => $a['payroll_source'], 'ponche' => $ponche, 'vici' => $vici, 'ratio' => $ratio,
        ];
    }
    usort($payrollRows, static fn($x, $y) => $y['ratio'] <=> $x['ratio']);
}

$avgEntryDelta = !empty($kpi['entry_deltas']) ? (int) round(array_sum($kpi['entry_deltas']) / count($kpi['entry_deltas'])) : null;

include 'header.php';
?>

<div class="container mx-auto px-4 py-6">
    <!-- Encabezado -->
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-100 mb-2">
                <i class="fas fa-scale-balanced text-indigo-400 mr-3"></i>
                Conciliación Vicidial
            </h1>
            <p class="text-slate-400">Login/logout de Vicidial vs marcación manual ·
                <span class="text-amber-300 font-semibold">Modo sombra</span> (no afecta la nómina)</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm <?= $syncEnabled ? 'bg-green-500/15 text-green-300' : 'bg-amber-500/15 text-amber-300' ?>">
                <i class="fas fa-<?= $syncEnabled ? 'circle-check' : 'circle-pause' ?>"></i>
                Cron <?= $syncEnabled ? 'activo' : 'inactivo' ?>
            </span>
            <a href="settings.php#vicidial-sync-config" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm bg-slate-700/60 text-slate-200 hover:bg-slate-600/60">
                <i class="fas fa-gear"></i> Configuración
            </a>
        </div>
    </div>

    <!-- Flash -->
    <?php foreach ($flash['ok'] as $m): ?>
        <div class="mb-3 px-4 py-3 rounded-lg bg-green-500/15 border border-green-500/30 text-green-200 text-sm">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($m) ?>
        </div>
    <?php endforeach; ?>
    <?php foreach ($flash['err'] as $m): ?>
        <div class="mb-3 px-4 py-3 rounded-lg bg-red-500/15 border border-red-500/30 text-red-200 text-sm">
            <i class="fas fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($m) ?>
        </div>
    <?php endforeach; ?>

    <!-- Tabs -->
    <?php
    $tabs = [
        'concile' => ['Conciliación', 'fa-scale-balanced'],
        'mapping' => ['Mapeo de Usuarios', 'fa-people-arrows'],
        'payroll' => ['Nómina (pago Vicidial)', 'fa-hand-holding-dollar'],
        'status'  => ['Estado y Bitácora', 'fa-clock-rotate-left'],
    ];
    ?>
    <div class="flex flex-wrap gap-1 mb-6 border-b border-slate-700">
        <?php foreach ($tabs as $k => $t): ?>
            <a href="vicidial_sync.php?tab=<?= $k ?>&date=<?= urlencode($date) ?>"
               class="px-4 py-2.5 text-sm font-medium rounded-t-lg -mb-px border-b-2 <?= $tab === $k ? 'border-indigo-400 text-indigo-300 bg-slate-800/40' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fas <?= $t[1] ?> mr-2"></i><?= $t[0] ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($tab === 'concile'): ?>
        <!-- Selector de fecha -->
        <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
            <input type="hidden" name="tab" value="concile">
            <div>
                <label class="block text-sm text-slate-400 mb-1">Fecha</label>
                <input type="date" name="date" value="<?= htmlspecialchars($date) ?>"
                    class="px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-slate-200">
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-medium">
                <i class="fas fa-magnifying-glass mr-2"></i>Ver
            </button>
            <div class="flex gap-1 ml-1">
                <?php
                $prev = date('Y-m-d', strtotime($date . ' -1 day'));
                $next = date('Y-m-d', strtotime($date . ' +1 day'));
                ?>
                <a href="vicidial_sync.php?tab=concile&date=<?= $prev ?>" class="px-3 py-2 bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 rounded-lg text-sm"><i class="fas fa-chevron-left"></i></a>
                <a href="vicidial_sync.php?tab=concile&date=<?= $next ?>" class="px-3 py-2 bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 rounded-lg text-sm"><i class="fas fa-chevron-right"></i></a>
            </div>
        </form>

        <!-- KPIs -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
                <div class="text-slate-400 text-xs uppercase tracking-wide">Conciliados</div>
                <div class="text-2xl font-bold text-slate-100 mt-1"><?= $kpi['compared'] ?></div>
                <div class="text-xs text-slate-500 mt-1">agentes con ambas fuentes</div>
            </div>
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
                <div class="text-slate-400 text-xs uppercase tracking-wide">Δ Entrada prom.</div>
                <div class="text-2xl font-bold <?= $avgEntryDelta === null ? 'text-slate-500' : (abs($avgEntryDelta) <= 5 ? 'text-green-400' : (abs($avgEntryDelta) <= 20 ? 'text-amber-400' : 'text-red-400')) ?> mt-1">
                    <?= $avgEntryDelta === null ? '—' : (($avgEntryDelta > 0 ? '+' : ($avgEntryDelta < 0 ? '−' : '')) . abs($avgEntryDelta) . 'm') ?>
                </div>
                <div class="text-xs text-slate-500 mt-1">Vicidial − Ponche</div>
            </div>
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
                <div class="text-slate-400 text-xs uppercase tracking-wide">Δ Horas total</div>
                <?php $hd = $kpi['hours_delta_sec']; ?>
                <div class="text-2xl font-bold <?= abs($hd) < 1800 ? 'text-green-400' : (abs($hd) < 7200 ? 'text-amber-400' : 'text-red-400') ?> mt-1">
                    <?= ($hd > 0 ? '+' : ($hd < 0 ? '−' : '')) . vfmtHours(abs($hd)) ?>
                </div>
                <div class="text-xs text-slate-500 mt-1">logueado − trabajado (pagado)</div>
            </div>
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
                <div class="text-slate-400 text-xs uppercase tracking-wide">Sin marcar (manual)</div>
                <div class="text-2xl font-bold <?= $kpi['missing_manual'] > 0 ? 'text-amber-400' : 'text-slate-100' ?> mt-1"><?= $kpi['missing_manual'] ?></div>
                <div class="text-xs text-slate-500 mt-1">en Vicidial pero sin ponche</div>
            </div>
        </div>

        <!-- Tabla de conciliación -->
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-900/50 text-slate-400 text-xs uppercase">
                        <tr>
                            <th class="text-left px-4 py-3">Empleado</th>
                            <th class="text-left px-3 py-3">Grupo</th>
                            <th class="text-center px-3 py-3" colspan="2">Ponche manual</th>
                            <th class="text-center px-3 py-3" colspan="2">Vicidial</th>
                            <th class="text-center px-3 py-3">Δ Ent.</th>
                            <th class="text-right px-3 py-3">Horas P/V</th>
                            <th class="text-right px-3 py-3">Δ Horas</th>
                        </tr>
                        <tr class="text-[10px] text-slate-500">
                            <th></th><th></th>
                            <th class="px-3 pb-2">Entrada</th><th class="px-3 pb-2">Salida</th>
                            <th class="px-3 pb-2">Login</th><th class="px-3 pb-2">Últ. act.*</th>
                            <th colspan="3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/50">
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">
                                No hay datos de Vicidial para <?= htmlspecialchars($date) ?>.
                                Importa el día desde <a href="vicidial_sync.php?tab=status&date=<?= urlencode($date) ?>" class="text-indigo-300 underline">Estado y Bitácora</a>.
                            </td></tr>
                        <?php else: foreach ($rows as $r):
                            $ed = vfmtDeltaMin($r['entry_delta']);
                            $rowCls = $r['status'] === 'unmapped' ? 'opacity-50' : '';
                        ?>
                            <tr class="hover:bg-slate-700/20 <?= $rowCls ?>">
                                <td class="px-4 py-3">
                                    <?php if ($r['username']): ?>
                                        <div class="text-slate-100 font-medium"><?= htmlspecialchars($r['full_name'] ?: $r['username']) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($r['vicidial_user']) ?></div>
                                    <?php else: ?>
                                        <div class="text-slate-300"><?= htmlspecialchars($r['vicidial_name'] ?: $r['vicidial_user']) ?></div>
                                        <div class="text-xs">
                                            <?php if ($r['status'] === 'ignored'): ?>
                                                <span class="text-slate-500"><i class="fas fa-ban"></i> ignorado</span>
                                            <?php else: ?>
                                                <a href="vicidial_sync.php?tab=mapping" class="text-amber-400 hover:underline"><i class="fas fa-link-slash"></i> sin mapear</a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-slate-400 text-xs"><?= htmlspecialchars($r['user_group'] ?: '—') ?></td>
                                <td class="px-3 py-3 text-center text-slate-300"><?= $r['manual_entry'] ? date('H:i', $r['manual_entry']) : '<span class="text-slate-600">—</span>' ?></td>
                                <td class="px-3 py-3 text-center text-slate-300"><?= $r['manual_exit'] ? date('H:i', $r['manual_exit']) : '<span class="text-slate-600">—</span>' ?></td>
                                <td class="px-3 py-3 text-center text-slate-300"><?= $r['vici_login'] ? date('H:i', $r['vici_login']) : '<span class="text-slate-600">—</span>' ?></td>
                                <td class="px-3 py-3 text-center text-slate-400"><?= $r['vici_last'] ? date('H:i', $r['vici_last']) : '<span class="text-slate-600">—</span>' ?></td>
                                <td class="px-3 py-3 text-center font-medium <?= $ed['class'] ?>"><?= $ed['text'] ?></td>
                                <td class="px-3 py-3 text-right text-slate-300 whitespace-nowrap">
                                    <span class="text-slate-400"><?= vfmtHours($r['manual_worked']) ?></span>
                                    <span class="text-slate-600">/</span>
                                    <span class="text-slate-200"><?= vfmtHours($r['vici_logged']) ?></span>
                                </td>
                                <td class="px-3 py-3 text-right font-medium whitespace-nowrap">
                                    <?php if ($r['hours_delta'] === null): ?>
                                        <span class="text-slate-600">—</span>
                                    <?php else:
                                        $hdCls = abs($r['hours_delta']) < 1800 ? 'text-green-400' : (abs($r['hours_delta']) < 7200 ? 'text-amber-400' : 'text-red-400');
                                        echo '<span class="' . $hdCls . '">' . ($r['hours_delta'] > 0 ? '+' : ($r['hours_delta'] < 0 ? '−' : '')) . vfmtHours(abs($r['hours_delta'])) . '</span>';
                                    endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="text-xs text-slate-500 mt-3 space-y-1">
            <p><i class="fas fa-circle-info mr-1"></i>
                <strong>Δ Entrada</strong> = login Vicidial − entrada Ponche (positivo = Vicidial después). Verde ≤ 5 min ·
                Ámbar ≤ 20 min · Rojo &gt; 20 min.</p>
            <p><i class="fas fa-shield-halved mr-1 text-green-500"></i>
                <strong>Horas P/V</strong> = trabajadas (Ponche) / logueadas (Vicidial). Las horas de Ponche se calculan con
                <strong>el mismo motor que la nómina oficial</strong> (mismos tipos pagados, mismo cierre de intervalos) →
                reconcilian exacto. Las de Vicidial son su <em>TOTAL LOGGED-IN TIME</em> tal cual.</p>
            <p><i class="fas fa-triangle-exclamation mr-1 text-amber-500"></i>
                <strong>* Últ. act.</strong> es la última actividad registrada en Vicidial, <strong>no el logout real</strong>
                (Vicidial no expone la hora exacta de salida por API); por eso no se compara como salida. Para "¿trabajó su
                jornada?" usa <strong>Δ Horas</strong>, que sí es preciso.
                <?php $offNow = (int) ($cfg['vicidial_tz_offset_minutes'] ?? 0); ?>
                Ajuste de zona horaria aplicado: <?= ($offNow >= 0 ? '+' : '') . $offNow ?> min.</p>
        </div>

    <?php elseif ($tab === 'mapping'): ?>
        <!-- Mapeo de usuarios -->
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5 mb-4">
            <p class="text-slate-300 text-sm">
                <i class="fas fa-circle-info text-indigo-400 mr-1"></i>
                Empareja cada agente de Vicidial con su empleado en ponche. Las sugerencias
                <span class="text-amber-300 font-medium">automáticas</span> (por nombre) deben confirmarse. Marca
                <span class="text-slate-400 font-medium">"Ignorar"</span> las cuentas que no son empleados (IT, entrenamiento, pruebas).
            </p>
        </div>
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-900/50 text-slate-400 text-xs uppercase">
                        <tr>
                            <th class="text-left px-4 py-3">Agente Vicidial</th>
                            <th class="text-left px-3 py-3">Estado</th>
                            <th class="text-left px-3 py-3">Empleado ponche</th>
                            <th class="text-center px-3 py-3">Ignorar</th>
                            <th class="text-right px-4 py-3">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/50">
                        <?php foreach ($mapRows as $m): ?>
                            <tr class="hover:bg-slate-700/20">
                                <form method="POST">
                                    <input type="hidden" name="action" value="save_mapping">
                                    <input type="hidden" name="vicidial_user" value="<?= htmlspecialchars($m['vicidial_user']) ?>">
                                    <td class="px-4 py-3">
                                        <div class="text-slate-100 font-medium"><?= htmlspecialchars($m['vicidial_name'] ?: $m['vicidial_user']) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($m['vicidial_user']) ?></div>
                                    </td>
                                    <td class="px-3 py-3">
                                        <?php if ((int) $m['ignore_agent'] === 1): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-slate-600/40 text-slate-300"><i class="fas fa-ban"></i> Ignorado</span>
                                        <?php elseif ($m['user_id'] === null): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-red-500/15 text-red-300"><i class="fas fa-link-slash"></i> Sin mapear</span>
                                        <?php elseif ((int) $m['auto_matched'] === 1): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-amber-500/15 text-amber-300"><i class="fas fa-wand-magic-sparkles"></i> Sugerido</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-green-500/15 text-green-300"><i class="fas fa-circle-check"></i> Confirmado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-3">
                                        <select name="user_id" class="w-full max-w-xs px-3 py-1.5 bg-slate-900/50 border border-slate-600 rounded-lg text-slate-200 text-sm">
                                            <option value="">— Sin asignar —</option>
                                            <?php foreach ($activeUsers as $u): ?>
                                                <option value="<?= (int) $u['id'] ?>" <?= (int) $m['user_id'] === (int) $u['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(($u['full_name'] ?: $u['username']) . ' (' . $u['username'] . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <input type="checkbox" name="ignore_agent" value="1" class="w-4 h-4 accent-slate-500" <?= (int) $m['ignore_agent'] === 1 ? 'checked' : '' ?>>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button type="submit" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-medium">
                                            <i class="fas fa-save mr-1"></i>Guardar
                                        </button>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($mapRows)): ?>
                            <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Aún no hay agentes. Importa un día primero.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($tab === 'payroll'): ?>
        <!-- Control por-agente: activar pago desde Vicidial, guiado por el semáforo -->
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5 mb-4">
            <p class="text-slate-300 text-sm">
                <i class="fas fa-circle-info text-indigo-400 mr-1"></i>
                Activa el <strong>pago desde Vicidial</strong> por agente. El <strong>semáforo</strong> compara, en las 2
                semanas de referencia (<?= htmlspecialchars((string) $refStart) ?> a <?= htmlspecialchars((string) $refEnd) ?>),
                las horas pagables de Vicidial contra el ponche.
                <span class="text-green-400">🟢 match alto</span> = el trabajo del agente vive en el discador → justo pagar
                por Vicidial. <span class="text-red-400">🔴 bajo</span> = trabaja fuera del discador (WhatsApp/admin) →
                <strong>déjalo en Ponche</strong> o le recortarías el sueldo injustamente.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <form method="POST" onsubmit="return confirm('¿Poner a TODOS los agentes de call (rol AGENT, mapeados) a pagar por Vicidial? Los administrativos siguen por ponche. La nómina queda en CALCULADA hasta que la apruebes.');">
                <input type="hidden" name="action" value="bulk_payroll_source">
                <input type="hidden" name="bulk_mode" value="agents_vicidial">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-green-600 hover:bg-green-500 text-white">
                    <i class="fas fa-bolt"></i> Todos los agentes de call → Vicidial
                </button>
            </form>
            <form method="POST" onsubmit="return confirm('¿Revertir TODOS a ponche manual? La nómina no cambiará nada.');">
                <input type="hidden" name="action" value="bulk_payroll_source">
                <input type="hidden" name="bulk_mode" value="all_manual">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-700/60 hover:bg-slate-600/60 text-slate-200">
                    <i class="fas fa-rotate-left"></i> Revertir todos a ponche
                </button>
            </form>
            <span class="text-xs text-slate-500">Administrativos (HR, IT, QA, Supervisor, Admin) nunca se tocan con estos botones.</span>
        </div>
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-900/50 text-slate-400 text-xs uppercase">
                        <tr>
                            <th class="text-left px-4 py-3">Agente</th>
                            <th class="text-right px-3 py-3">Ponche (2 sem)</th>
                            <th class="text-right px-3 py-3">Vicidial pagable</th>
                            <th class="text-center px-3 py-3">Match</th>
                            <th class="text-left px-3 py-3">Recomendación</th>
                            <th class="text-left px-3 py-3">Paga por</th>
                            <th class="text-right px-4 py-3">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/50">
                        <?php foreach ($payrollRows as $pr):
                            $r = $pr['ratio'];
                            if ($r === 999)      { $sem = ['⚪', 'text-slate-400', 'Sin ponche que comparar']; }
                            elseif ($r >= 85)    { $sem = ['🟢', 'text-green-400', 'Vicidial justo']; }
                            elseif ($r >= 65)    { $sem = ['🟡', 'text-amber-400', 'Revisar']; }
                            else                 { $sem = ['🔴', 'text-red-400', 'Dejar en Ponche (trabaja fuera del discador)']; }
                            $fh = static fn($s) => sprintf('%d:%02d', intdiv((int) $s, 3600), intdiv((int) $s % 3600, 60));
                        ?>
                            <tr class="hover:bg-slate-700/20">
                                <form method="POST">
                                    <input type="hidden" name="action" value="save_payroll_source">
                                    <input type="hidden" name="user_id" value="<?= (int) $pr['id'] ?>">
                                    <td class="px-4 py-3">
                                        <div class="text-slate-100 font-medium"><?= htmlspecialchars($pr['full_name']) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($pr['role'] ?: '') ?></div>
                                    </td>
                                    <td class="px-3 py-3 text-right text-slate-300"><?= $fh($pr['ponche']) ?></td>
                                    <td class="px-3 py-3 text-right text-slate-200"><?= $fh($pr['vici']) ?></td>
                                    <td class="px-3 py-3 text-center font-bold <?= $sem[1] ?>"><?= $r === 999 ? '—' : $r . '%' ?></td>
                                    <td class="px-3 py-3 <?= $sem[1] ?> text-xs"><?= $sem[0] ?> <?= htmlspecialchars($sem[2]) ?></td>
                                    <td class="px-3 py-3">
                                        <select name="payroll_source" class="px-3 py-1.5 bg-slate-900/50 border border-slate-600 rounded-lg text-slate-200 text-sm">
                                            <option value="manual" <?= $pr['source'] === 'manual' ? 'selected' : '' ?>>Ponche (manual)</option>
                                            <option value="vicidial" <?= $pr['source'] === 'vicidial' ? 'selected' : '' ?>>Vicidial</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button type="submit" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-medium">
                                            <i class="fas fa-save mr-1"></i>Guardar
                                        </button>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payrollRows)): ?>
                            <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No hay agentes mapeados. Ve a la pestaña Mapeo primero.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-3">
            <i class="fas fa-shield-halved mr-1 text-green-500"></i>
            Los que pongas en <strong>Vicidial</strong> cobrarán por sus horas pagables de Vicidial en la próxima nómina que
            generes; el resto sigue por ponche. La nómina queda en <strong>CALCULADA</strong> hasta que la apruebes — nada se
            paga solo. Ajusta los <strong>códigos pagados</strong> en
            <a href="settings.php#vicidial-sync-config" class="text-indigo-300 underline">Configuración → Vicidial</a>.
        </p>

    <?php elseif ($tab === 'status'): ?>
        <!-- Estado, importación manual y bitácora -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5">
                <h3 class="text-slate-100 font-semibold mb-3"><i class="fas fa-download text-indigo-400 mr-2"></i>Importar un día ahora</h3>
                <form method="POST" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="action" value="import_now">
                    <input type="hidden" name="tab" value="status">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Fecha</label>
                        <input type="date" name="import_date" value="<?= htmlspecialchars($date) ?>" required
                            class="px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-slate-200">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-medium">
                        <i class="fas fa-cloud-arrow-down mr-2"></i>Importar
                    </button>
                </form>
                <p class="text-xs text-slate-500 mt-3">Descarga de Vicidial y guarda la hoja de tiempo del día. Tarda ~1 seg/agente.</p>
            </div>
            <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-5">
                <h3 class="text-slate-100 font-semibold mb-3"><i class="fas fa-plug text-indigo-400 mr-2"></i>Prueba de conexión</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="tab" value="status">
                    <button type="submit" class="px-4 py-2 bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 rounded-lg text-sm font-medium">
                        <i class="fas fa-satellite-dish mr-2"></i>Probar credenciales / ver TZ
                    </button>
                </form>
                <p class="text-xs text-slate-500 mt-3">
                    Cron sugerido (cPanel):
                    <code class="text-slate-400">30 1 * * * php <?= htmlspecialchars(__DIR__) ?>/cron_vicidial_sync.php</code>
                </p>
            </div>
        </div>

        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-700/50 text-slate-200 font-semibold">
                <i class="fas fa-clock-rotate-left text-indigo-400 mr-2"></i>Últimas corridas
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-900/50 text-slate-400 text-xs uppercase">
                        <tr>
                            <th class="text-left px-4 py-3">Cuándo</th>
                            <th class="text-left px-3 py-3">Día</th>
                            <th class="text-left px-3 py-3">Estado</th>
                            <th class="text-right px-3 py-3">Agentes</th>
                            <th class="text-right px-3 py-3">Timesheets</th>
                            <th class="text-right px-3 py-3">Filas</th>
                            <th class="text-right px-3 py-3">Mapeos</th>
                            <th class="text-right px-3 py-3">Dur.</th>
                            <th class="text-left px-3 py-3">Origen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/50">
                        <?php foreach ($syncLog as $l):
                            $stCls = $l['status'] === 'ok' ? 'text-green-400' : ($l['status'] === 'partial' ? 'text-amber-400' : ($l['status'] === 'error' ? 'text-red-400' : 'text-slate-400'));
                        ?>
                            <tr class="hover:bg-slate-700/20">
                                <td class="px-4 py-3 text-slate-300 whitespace-nowrap"><?= htmlspecialchars($l['created_at']) ?></td>
                                <td class="px-3 py-3 text-slate-300"><?= htmlspecialchars($l['target_date']) ?></td>
                                <td class="px-3 py-3 font-medium <?= $stCls ?>"><?= htmlspecialchars($l['status']) ?></td>
                                <td class="px-3 py-3 text-right text-slate-300"><?= (int) $l['agents_in_report'] ?></td>
                                <td class="px-3 py-3 text-right text-slate-300"><?= (int) $l['timesheets_fetched'] ?></td>
                                <td class="px-3 py-3 text-right text-slate-300"><?= (int) $l['rows_upserted'] ?></td>
                                <td class="px-3 py-3 text-right text-slate-300"><?= (int) $l['new_mappings'] ?></td>
                                <td class="px-3 py-3 text-right text-slate-400"><?= number_format($l['duration_ms'] / 1000, 1) ?>s</td>
                                <td class="px-3 py-3 text-slate-400 text-xs"><?= htmlspecialchars($l['triggered_by']) ?></td>
                            </tr>
                            <?php if (!empty($l['message']) && $l['message'] !== 'OK'): ?>
                                <tr class="bg-slate-900/30"><td colspan="9" class="px-4 py-2 text-xs text-slate-500"><?= htmlspecialchars(mb_substr($l['message'], 0, 300)) ?></td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (empty($syncLog)): ?>
                            <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">Sin corridas todavía.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
