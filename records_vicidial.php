<?php
/**
 * Registros — Vista VICIDIAL
 *
 * Muestra la hoja de tiempo diaria importada de Vicidial (login/logout/horas
 * logueadas/llamadas) con los MISMOS filtros que la vista de ponche
 * (buscar, usuario, campaña, rango de fechas) y el mismo alcance por supervisor.
 * Es un histórico (datos ya importados por el cron / backfill). No toca ponche.
 *
 * Incluye paginación y filtros de fecha robustos (valida fechas de calendario
 * reales; cualquier rango válido trae sus datos sin errores).
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/vicidial_api_client.php';
date_default_timezone_set('America/Santo_Domingo');

ensurePermission('records');

// Config de pago: códigos de pausa pagados + tope de cordura diario.
$vPaidCodes = vicidialGetPaidPauseCodes($pdo);
$vDailyCapSeconds = (int) round((float) getSystemSetting($pdo, 'vicidial_payroll_daily_cap_hours', 14) * 3600);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
if (!function_exists('vSecondsToHms')) {
    function vSecondsToHms($seconds): string
    {
        $seconds = max(0, (int) $seconds);
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
if (!function_exists('vFmtTime')) {
    function vFmtTime(?string $dt): string
    {
        $dt = trim((string) $dt);
        return ($dt === '' || strtotime($dt) === false) ? '—' : date('H:i:s', strtotime($dt));
    }
}
if (!function_exists('vIsRealDate')) {
    /** Valida una fecha de CALENDARIO real en formato Y-m-d (rechaza 2026-13-45). */
    function vIsRealDate($d): bool
    {
        $d = (string) $d;
        $dt = DateTime::createFromFormat('!Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }
}

// ---------------------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------------------
$search          = trim($_GET['search'] ?? '');
$user_filter     = trim($_GET['user'] ?? '');
$campaign_filter = (int) ($_GET['campaign'] ?? 0);
$collation = 'utf8mb4_unicode_ci';

// --- Rango de fechas robusto -------------------------------------------------
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
// Compatibilidad con el "dates" de la vista ponche ("YYYY-MM-DD - YYYY-MM-DD")
if (($date_from === '' || $date_to === '') && !empty($_GET['dates']) && strpos($_GET['dates'], ' - ') !== false) {
    [$df, $dt] = array_map('trim', explode(' - ', $_GET['dates'], 2));
    if ($date_from === '') { $date_from = $df; }
    if ($date_to === '')   { $date_to = $dt; }
}
// Solo se aceptan fechas de calendario reales; lo inválido se descarta.
$date_from = vIsRealDate($date_from) ? $date_from : '';
$date_to   = vIsRealDate($date_to)   ? $date_to   : '';
// Completar lo que falte con el rango de datos disponible (default = todo).
if ($date_from === '' || $date_to === '') {
    try {
        $bounds = $pdo->query("SELECT MIN(report_date) mn, MAX(report_date) mx FROM vicidial_agent_timesheet")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $bounds = null;
    }
    $defTo   = ($bounds && $bounds['mx']) ? $bounds['mx'] : date('Y-m-d');
    $defFrom = ($bounds && $bounds['mn']) ? $bounds['mn'] : date('Y-m-d', strtotime($defTo . ' -29 days'));
    if ($date_to === '')   { $date_to = $defTo; }
    if ($date_from === '') { $date_from = $defFrom; }
}
// Si vienen al revés, se intercambian (nunca error).
if ($date_to < $date_from) {
    $tmp = $date_from; $date_from = $date_to; $date_to = $tmp;
}

// --- Paginación --------------------------------------------------------------
$perPageOptions = [25, 50, 100, 200, 500];
$perPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 50;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

// ---------------------------------------------------------------------------
// Alcance por supervisor (mismo patrón que records.php)
// ---------------------------------------------------------------------------
$currentUserId   = (int) ($_SESSION['user_id'] ?? 0);
$currentUserRole = $_SESSION['role'] ?? '';
$supervisorFilterSql = '';
$supervisorFilterParams = [];
$supervisorCampaigns = [];

if ($currentUserRole === 'Supervisor' && $currentUserId > 0) {
    $cs = $pdo->prepare("SELECT campaign_id FROM supervisor_campaigns WHERE supervisor_id = ?");
    $cs->execute([$currentUserId]);
    $supervisorCampaigns = array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $conditions = ["users.id = ?", "e.supervisor_id = ?"];
    $supervisorFilterParams = [$currentUserId, $currentUserId];
    if (!empty($supervisorCampaigns)) {
        $ph = implode(',', array_fill(0, count($supervisorCampaigns), '?'));
        $conditions[] = "e.campaign_id IN ($ph)";
        $supervisorFilterParams = array_merge($supervisorFilterParams, $supervisorCampaigns);
    }
    $supervisorFilterSql = ' AND (' . implode(' OR ', $conditions) . ')';
}

// Lista de campañas para el filtro (solo las del supervisor si aplica)
$campaignsListQuery = "SELECT id, name, code FROM campaigns WHERE is_active = 1";
$campaignsListParams = [];
if ($currentUserRole === 'Supervisor' && !empty($supervisorCampaigns)) {
    $ph = implode(',', array_fill(0, count($supervisorCampaigns), '?'));
    $campaignsListQuery .= " AND id IN ($ph)";
    $campaignsListParams = $supervisorCampaigns;
}
$campaignsListQuery .= " ORDER BY name";
try {
    $cst = $pdo->prepare($campaignsListQuery);
    $cst->execute($campaignsListParams);
    $campaignsList = $cst->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $campaignsList = [];
}
$validCampaignIds = array_map('intval', array_column($campaignsList, 'id'));
if ($campaign_filter > 0 && !in_array($campaign_filter, $validCampaignIds, true)) {
    $campaign_filter = 0;
}

// Usuarios que tienen datos de Vicidial (para el dropdown)
try {
    $uStmt = $pdo->query("
        SELECT DISTINCT users.username
        FROM vicidial_agent_timesheet t
        JOIN users ON users.id = t.user_id
        WHERE t.user_id IS NOT NULL
        ORDER BY users.username
    ");
    $users = $uStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $users = [];
}

// ---------------------------------------------------------------------------
// FROM + WHERE reutilizable (para conteo/totales y para la página de datos)
// ---------------------------------------------------------------------------
$fromWhere = "
    FROM vicidial_agent_timesheet t
    JOIN users ON users.id = t.user_id
    LEFT JOIN employees e ON e.user_id = users.id
    WHERE t.user_id IS NOT NULL
      AND t.report_date BETWEEN ? AND ?
";
$params = [$date_from, $date_to];

if ($search !== '') {
    $like = "%$search%";
    $fromWhere .= " AND (
        users.full_name COLLATE {$collation} LIKE ?
        OR users.username COLLATE {$collation} LIKE ?
        OR t.vicidial_name COLLATE {$collation} LIKE ?
        OR t.user_group COLLATE {$collation} LIKE ?
    )";
    array_push($params, $like, $like, $like, $like);
}
if ($user_filter !== '') {
    $fromWhere .= " AND users.username = ?";
    $params[] = $user_filter;
}
if ($campaign_filter > 0) {
    $fromWhere .= " AND e.campaign_id = ?";
    $params[] = $campaign_filter;
}
if ($supervisorFilterSql !== '') {
    $fromWhere .= $supervisorFilterSql;
    $params = array_merge($params, $supervisorFilterParams);
}

// Totales globales (todas las filas que cumplen el filtro, no solo la página)
$totalRows = 0; $totLogged = 0; $totCalls = 0;
$queryError = null;
try {
    $agg = $pdo->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(t.total_logged_seconds),0) logged, COALESCE(SUM(t.calls),0) calls " . $fromWhere);
    $agg->execute($params);
    $aggRow = $agg->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalRows  = (int) ($aggRow['cnt'] ?? 0);
    $totLogged  = (int) ($aggRow['logged'] ?? 0);
    $totCalls   = (int) ($aggRow['calls'] ?? 0);
} catch (Throwable $e) {
    $queryError = $e->getMessage();
}

// Total PAGABLE (NONPAUSE + códigos pagados, con tope) sobre TODAS las filas del
// filtro. Se calcula en PHP porque el desglose de pausas es JSON.
$totPayable = 0;
if ($queryError === null && $totalRows > 0) {
    try {
        $pst = $pdo->prepare("SELECT nonpause_seconds, pause_breakdown " . $fromWhere);
        $pst->execute($params);
        foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $codes = $pr['pause_breakdown'] ? json_decode($pr['pause_breakdown'], true) : [];
            $totPayable += vicidialComputePaidSeconds((int) $pr['nonpause_seconds'], is_array($codes) ? $codes : [], $vPaidCodes, $vDailyCapSeconds)['paid_seconds'];
        }
    } catch (Throwable $e) {
        // no rompas la página por el total
    }
}

// Clamp de la página al total real y cálculo del offset
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

// Página de datos
$rows = [];
if ($queryError === null && $totalRows > 0) {
    try {
        $dataSql = "SELECT t.report_date, t.user_id, t.first_login, t.last_activity, t.total_logged_seconds,
                           t.nonpause_seconds, t.pause_breakdown,
                           t.calls, t.talk_seconds, t.pause_seconds, t.wait_seconds, t.dispo_seconds,
                           t.user_group, t.vicidial_user, users.full_name, users.username "
                 . $fromWhere
                 . " ORDER BY t.report_date DESC, users.full_name ASC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
        $dataStmt = $pdo->prepare($dataSql);
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $queryError = $e->getMessage();
        $rows = [];
    }
}

$fromRow = $totalRows > 0 ? ($offset + 1) : 0;
$toRow   = min($offset + $perPage, $totalRows);

// Query string base para paginación y toggle (preserva los filtros)
$baseFilters = array_filter([
    'search'    => $search,
    'user'      => $user_filter,
    'campaign'  => $campaign_filter > 0 ? $campaign_filter : '',
    'date_from' => $date_from,
    'date_to'   => $date_to,
    'per_page'  => $perPage,
], static fn($v) => $v !== '' && $v !== 0);
$pageUrl = static function (int $p) use ($baseFilters): string {
    return 'records_vicidial.php?' . http_build_query(array_merge($baseFilters, ['page' => $p]));
};
// Toggle a ponche (usa "dates")
$toggleQs = http_build_query(array_filter([
    'search'   => $search,
    'user'     => $user_filter,
    'campaign' => $campaign_filter > 0 ? $campaign_filter : '',
    'dates'    => $date_from . ' - ' . $date_to,
], static fn($v) => $v !== '' && $v !== 0));

include 'header.php';
?>

<div class="container mx-auto px-4 py-6">
    <div class="page-hero">
        <div class="page-hero-content">
            <div>
                <h1><i class="fas fa-headset text-indigo-400 mr-2"></i>Registros — Vicidial</h1>
                <p>Hoja de tiempo diaria de los agentes tomada de Vicidial (login, última actividad, horas logueadas y
                    llamadas). Histórico importado; no incluye datos de ponche.</p>
            </div>
            <div class="page-meta">
                <span class="chip"><i class="fas fa-table-list"></i> <?= number_format($totalRows) ?> filas</span>
                <span class="chip"><i class="fas fa-clock"></i> <?= vSecondsToHms($totLogged) ?> logueado</span>
                <span class="chip" style="background:rgba(16,185,129,.14);border-color:rgba(16,185,129,.35);color:#34d399;"><i class="fas fa-hand-holding-dollar"></i> <?= vSecondsToHms($totPayable) ?> pagable</span>
                <span class="chip"><i class="fas fa-phone"></i> <?= number_format($totCalls) ?> llamadas</span>
            </div>
        </div>
    </div>

    <!-- Toggle Ponche / Vicidial -->
    <div class="mb-5 flex items-center gap-2">
        <span class="text-sm text-slate-400 mr-1">Fuente:</span>
        <a href="records.php?<?= htmlspecialchars($toggleQs) ?>"
           class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-700/60 text-slate-300 hover:bg-slate-600/60">
            <i class="fas fa-fingerprint"></i> Ponche
        </a>
        <a href="<?= htmlspecialchars($pageUrl(1)) ?>"
           class="px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white">
            <i class="fas fa-headset"></i> Vicidial
        </a>
    </div>

    <?php if ($queryError !== null): ?>
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-500/15 border border-red-500/30 text-red-200 text-sm">
            <i class="fas fa-triangle-exclamation mr-2"></i>
            No se pudieron cargar los datos de Vicidial. Intenta de nuevo o ajusta los filtros.
        </div>
    <?php endif; ?>

    <!-- Filtros -->
    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h2>Filtros</h2>
                <p class="text-muted text-sm">Mismos filtros que la vista de ponche: usuario, campaña y rango de fechas.</p>
            </div>
        </div>
        <form method="GET" class="space-y-6" id="vFilterForm">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-5">
                <div class="form-group">
                    <label class="form-label" for="search">Buscar</label>
                    <input id="search" type="text" name="search" class="input-control" placeholder="Nombre, usuario o campaña"
                           value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="user-filter">Usuario</label>
                    <select id="user-filter" name="user" class="select-control">
                        <option value="">Todos los usuarios</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user) ?>" <?= $user_filter === $user ? 'selected' : '' ?>><?= htmlspecialchars($user) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($campaignsList)): ?>
                <div class="form-group">
                    <label class="form-label" for="campaign-filter">Campaña</label>
                    <select id="campaign-filter" name="campaign" class="select-control">
                        <option value="">Todas las campañas</option>
                        <?php foreach ($campaignsList as $campaign): ?>
                            <option value="<?= (int) $campaign['id'] ?>" <?= $campaign_filter === (int) $campaign['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($campaign['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label" for="date_from">Desde</label>
                    <input id="date_from" type="date" name="date_from" class="input-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="date_to">Hasta</label>
                    <input id="date_to" type="date" name="date_to" class="input-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="per_page">Por página</label>
                    <select id="per_page" name="per_page" class="select-control">
                        <?php foreach ($perPageOptions as $opt): ?>
                            <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                <a href="records_vicidial.php" class="btn-secondary w-full sm:w-auto"><i class="fas fa-eraser"></i> Limpiar</a>
                <button type="submit" class="btn-primary w-full sm:w-auto"><i class="fas fa-filter"></i> Aplicar filtros</button>
            </div>
        </form>
    </div>

    <!-- Tabla -->
    <div class="glass-card space-y-6">
        <div class="panel-heading">
            <div>
                <h2>Detalle Vicidial</h2>
                <p class="text-muted text-sm">Una fila por agente y día. Horas logueadas = TOTAL LOGGED-IN TIME de Vicidial.</p>
            </div>
            <div class="table-actions w-full xl:w-auto">
                <button type="button" id="vExportCsv" class="btn-secondary w-full sm:w-auto" title="Exporta las filas de esta página"><i class="fas fa-file-csv"></i> Exportar CSV (página)</button>
            </div>
        </div>
        <div class="responsive-scroll">
            <table id="vRecordsTable" class="data-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Agente</th>
                        <th>Usuario</th>
                        <th>Grupo/Campaña</th>
                        <th>Login</th>
                        <th>Trabajando</th>
                        <th title="Horas logueadas totales (incluye pausas)">Logueado</th>
                        <th title="NONPAUSE + códigos de pausa pagados (lo que se pagaría)">Pagables</th>
                        <th>Llamadas</th>
                        <th title="Pausas NO pagadas (Break, Bao, ...)">Pausa no pag.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-6">
                            No hay datos de Vicidial para los filtros seleccionados.
                        </td></tr>
                    <?php else: foreach ($rows as $r):
                        $codes = $r['pause_breakdown'] ? json_decode($r['pause_breakdown'], true) : [];
                        $calc = vicidialComputePaidSeconds((int) $r['nonpause_seconds'], is_array($codes) ? $codes : [], $vPaidCodes, $vDailyCapSeconds);
                        $rowAdj = $r['user_id'] !== null
                            ? vicidialApplyDayAdjustment($pdo, (int) $r['user_id'], $r['report_date'], $calc['paid_seconds'])
                            : ['seconds' => $calc['paid_seconds'], 'adjusted' => false, 'reason' => ''];
                        $paidSec = $rowAdj['seconds'];
                        $unpaidSec = max(0, (int) $r['total_logged_seconds'] - $paidSec);
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($r['report_date']) ?></td>
                            <td><?= htmlspecialchars($r['full_name'] ?: $r['vicidial_user']) ?></td>
                            <td class="text-muted"><?= htmlspecialchars($r['username']) ?></td>
                            <td><?= htmlspecialchars($r['user_group'] ?: '—') ?></td>
                            <td><?= vFmtTime($r['first_login']) ?></td>
                            <td><?= vSecondsToHms($r['nonpause_seconds']) ?></td>
                            <td class="text-muted"><?= vSecondsToHms($r['total_logged_seconds']) ?></td>
                            <td style="color:#34d399;font-weight:700;">
                                <?= vSecondsToHms($paidSec) ?>
                                <?php if ($rowAdj['adjusted']): ?>
                                    <i class="fas fa-pen-to-square" style="color:#60a5fa;" title="Ajuste manual (Vicidial reportó <?= vSecondsToHms($calc['paid_seconds']) ?>) — <?= htmlspecialchars($rowAdj['reason']) ?>"></i>
                                <?php elseif ($calc['capped']): ?>
                                    <i class="fas fa-triangle-exclamation" style="color:#f59e0b;" title="Superó el tope de <?= (int) round($vDailyCapSeconds/3600) ?>h/día — revisar (posible sesión dejada abierta)"></i>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $r['calls'] ?></td>
                            <td class="text-muted"><?= vSecondsToHms($unpaidSec) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2 border-t border-slate-700/40">
            <div class="text-sm text-muted">
                <?php if ($totalRows > 0): ?>
                    Mostrando <strong><?= number_format($fromRow) ?></strong>–<strong><?= number_format($toRow) ?></strong>
                    de <strong><?= number_format($totalRows) ?></strong> · Página <?= $page ?> de <?= $totalPages ?>
                <?php else: ?>
                    Sin resultados
                <?php endif; ?>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="flex items-center gap-1 flex-wrap">
                <?php
                    $btn = 'px-3 py-1.5 rounded-lg text-sm bg-slate-700/60 text-slate-200 hover:bg-slate-600/60';
                    $btnActive = 'px-3 py-1.5 rounded-lg text-sm bg-indigo-600 text-white';
                    $btnDisabled = 'px-3 py-1.5 rounded-lg text-sm bg-slate-800/40 text-slate-600 cursor-not-allowed';
                    $windowStart = max(1, $page - 2);
                    $windowEnd = min($totalPages, $page + 2);
                ?>
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($pageUrl(1)) ?>" class="<?= $btn ?>" title="Primera"><i class="fas fa-angles-left"></i></a>
                    <a href="<?= htmlspecialchars($pageUrl($page - 1)) ?>" class="<?= $btn ?>" title="Anterior"><i class="fas fa-angle-left"></i></a>
                <?php else: ?>
                    <span class="<?= $btnDisabled ?>"><i class="fas fa-angles-left"></i></span>
                    <span class="<?= $btnDisabled ?>"><i class="fas fa-angle-left"></i></span>
                <?php endif; ?>

                <?php if ($windowStart > 1): ?><span class="px-2 text-slate-500">…</span><?php endif; ?>
                <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                    <a href="<?= htmlspecialchars($pageUrl($p)) ?>" class="<?= $p === $page ? $btnActive : $btn ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($windowEnd < $totalPages): ?><span class="px-2 text-slate-500">…</span><?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars($pageUrl($page + 1)) ?>" class="<?= $btn ?>" title="Siguiente"><i class="fas fa-angle-right"></i></a>
                    <a href="<?= htmlspecialchars($pageUrl($totalPages)) ?>" class="<?= $btn ?>" title="Última"><i class="fas fa-angles-right"></i></a>
                <?php else: ?>
                    <span class="<?= $btnDisabled ?>"><i class="fas fa-angle-right"></i></span>
                    <span class="<?= $btnDisabled ?>"><i class="fas fa-angles-right"></i></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <p class="text-xs text-muted">
            <i class="fas fa-circle-info mr-1"></i>
            <strong>Pagables</strong> = <em>Trabajando (NONPAUSE)</em> + los códigos de pausa pagados
            (<?= htmlspecialchars(implode(', ', $vPaidCodes)) ?>). <strong>Pausa no pag.</strong> = el resto del tiempo
            logueado (Break, Bao, etc.). Los pagados se configuran en
            <a href="settings.php#vicidial-sync-config" class="text-indigo-300 underline">Configuración → Vicidial</a>.
            El <i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> marca días que superan el tope de cordura
            (<?= (int) round($vDailyCapSeconds/3600) ?>h) — revisar antes de pagar. Hora RD.
        </p>
    </div>
</div>

<script>
// Exportar la tabla visible (página actual) a CSV, sin dependencias
document.getElementById('vExportCsv')?.addEventListener('click', function () {
    const rows = Array.from(document.querySelectorAll('#vRecordsTable tr'));
    const csv = rows.map(tr =>
        Array.from(tr.querySelectorAll('th,td')).map(td => {
            const t = (td.innerText || '').replace(/"/g, '""').trim();
            return '"' + t + '"';
        }).join(',')
    ).join('\r\n');
    const blob = new Blob(["﻿" + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'vicidial_registros_<?= $date_from ?>_a_<?= $date_to ?>_p<?= $page ?>.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
});
</script>

<?php include 'footer.php'; ?>
