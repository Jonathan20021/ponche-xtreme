<?php
/**
 * Reportes de Soporte (Helpdesk) — panel analítico completo.
 * Estadísticas del período, indicadores clave (CTA), cumplimiento de SLA por
 * agente de soporte, por categoría y por campaña, distribución y tendencia.
 *
 * ACCESO: por permiso asignado 'helpdesk_reports' (gestionable en Permisos).
 * Por defecto: Admin / HR / IT / Desarrollador.
 */
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/helpdesk_support.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// Autoaprovisiona tablas + siembra el permiso 'helpdesk_reports' si falta,
// para que el chequeo de acceso funcione desde el primer git pull.
try { ensureHelpdeskSupportTables(getMysqli()); } catch (Throwable $e) { /* best-effort */ }

// Acceso por permiso asignado.
ensurePermission('helpdesk_reports', '../unauthorized.php');

/* ------------------------- Rango de fechas ------------------------- */
function validDate(?string $s): ?string
{
    if (!$s) return null;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}
$to   = validDate($_GET['to']   ?? null) ?: date('Y-m-d');
$from = validDate($_GET['from'] ?? null) ?: date('Y-m-d', strtotime('-29 days'));
if ($from > $to) { [$from, $to] = [$to, $from]; }
$fromDT = $from . ' 00:00:00';
$toDT   = $to   . ' 23:59:59';
$rangeDays = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;

/* --------------------------- Consultas ---------------------------- */
function q(PDO $pdo, string $sql, array $p): array
{
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
$rp = [$fromDT, $toDT];

// Resumen general del período.
$sum = q($pdo, "
    SELECT
        COUNT(*) total,
        SUM(status='open') s_open,
        SUM(status='in_progress') s_prog,
        SUM(status='pending') s_pend,
        SUM(status='resolved') s_resolved,
        SUM(status='closed') s_closed,
        SUM(status='cancelled') s_cancelled,
        SUM(first_response_at IS NOT NULL) responded,
        SUM(first_response_at IS NOT NULL AND first_response_at <= sla_response_deadline) resp_ok,
        SUM(resolved_at IS NOT NULL) resolved_any,
        SUM(resolved_at IS NOT NULL AND resolved_at <= sla_resolution_deadline) resol_ok,
        AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, first_response_at) END) avg_resp_min,
        AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) avg_resol_min,
        SUM(status IN ('open','in_progress','pending') AND sla_resolution_deadline < NOW()) overdue
    FROM helpdesk_tickets WHERE created_at BETWEEN ? AND ?
", $rp)[0];

// Cumplimiento de SLA por soporte (agente asignado).
$byAgent = q($pdo, "
    SELECT a.id, a.full_name, a.role,
        COUNT(*) assigned,
        SUM(t.resolved_at IS NOT NULL) resolved,
        SUM(t.status IN ('open','in_progress','pending')) active,
        SUM(t.status IN ('open','in_progress','pending') AND t.sla_resolution_deadline < NOW()) overdue,
        SUM(t.first_response_at IS NOT NULL) responded,
        SUM(t.first_response_at IS NOT NULL AND t.first_response_at <= t.sla_response_deadline) resp_ok,
        SUM(t.resolved_at IS NOT NULL AND t.resolved_at <= t.sla_resolution_deadline) resol_ok,
        AVG(CASE WHEN t.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,t.created_at,t.first_response_at) END) avg_resp_min,
        AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,t.created_at,t.resolved_at) END) avg_resol_min
    FROM helpdesk_tickets t JOIN users a ON a.id = t.assigned_to
    WHERE t.created_at BETWEEN ? AND ? AND t.assigned_to IS NOT NULL
    GROUP BY a.id, a.full_name, a.role
    ORDER BY assigned DESC, resolved DESC
", $rp);

// Por categoría.
$byCat = q($pdo, "
    SELECT COALESCE(c.name,'(Sin categoría)') name, c.color,
        COUNT(*) total,
        SUM(t.resolved_at IS NOT NULL) resolved,
        SUM(t.resolved_at IS NOT NULL AND t.resolved_at <= t.sla_resolution_deadline) resol_ok,
        AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,t.created_at,t.resolved_at) END) avg_resol_min
    FROM helpdesk_tickets t LEFT JOIN helpdesk_categories c ON c.id = t.category_id
    WHERE t.created_at BETWEEN ? AND ?
    GROUP BY t.category_id, c.name, c.color
    ORDER BY total DESC
", $rp);

// Por campaña del solicitante (de dónde vienen los tickets).
$byCamp = q($pdo, "
    SELECT COALESCE(c.name,'(Sin campaña)') name, c.color,
        COUNT(*) total,
        SUM(t.resolved_at IS NOT NULL) resolved
    FROM helpdesk_tickets t
    JOIN users u ON u.id = t.user_id
    LEFT JOIN employees e ON e.user_id = u.id
    LEFT JOIN campaigns c ON c.id = e.campaign_id
    WHERE t.created_at BETWEEN ? AND ?
    GROUP BY c.id, c.name, c.color
    ORDER BY total DESC
", $rp);

// Por prioridad.
$byPrio = [];
foreach (q($pdo, "SELECT priority, COUNT(*) total FROM helpdesk_tickets WHERE created_at BETWEEN ? AND ? GROUP BY priority", $rp) as $r) {
    $byPrio[$r['priority']] = (int) $r['total'];
}

// Tendencia diaria: creados por día y resueltos por día (por fecha de resolución).
$createdByDay = $resolvedByDay = [];
foreach (q($pdo, "SELECT DATE(created_at) d, COUNT(*) n FROM helpdesk_tickets WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at)", $rp) as $r) { $createdByDay[$r['d']] = (int) $r['n']; }
foreach (q($pdo, "SELECT DATE(resolved_at) d, COUNT(*) n FROM helpdesk_tickets WHERE resolved_at BETWEEN ? AND ? GROUP BY DATE(resolved_at)", $rp) as $r) { $resolvedByDay[$r['d']] = (int) $r['n']; }

/* --------------------------- Helpers ------------------------------ */
function pct($num, $den): ?float { $den = (float) $den; return $den > 0 ? round(($num / $den) * 100, 1) : null; }
function pctTxt(?float $p): string { return $p === null ? '—' : number_format($p, 1) . '%'; }
function dur(?float $min): string
{
    if ($min === null) return '—';
    $min = (float) $min;
    if ($min < 60) return round($min) . ' min';
    if ($min < 1440) return round($min / 60, 1) . ' h';
    return round($min / 1440, 1) . ' d';
}
function slaClass(?float $p): string { return $p === null ? '' : ($p >= 90 ? 'ok' : ($p >= 70 ? 'mid' : 'bad')); }

$respCompliance  = pct($sum['resp_ok'], $sum['responded']);
$resolCompliance = pct($sum['resol_ok'], $sum['resolved_any']);
$resolutionRate  = pct((int) $sum['s_resolved'] + (int) $sum['s_closed'], $sum['total']);

/* ---------------------- Exportar CSV ------------------------------ */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_soporte_' . $from . '_a_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // BOM para Excel
    fputcsv($out, ['Reporte de Soporte', "$from a $to"]);
    fputcsv($out, []);
    fputcsv($out, ['Indicador', 'Valor']);
    fputcsv($out, ['Tickets del período', (int) $sum['total']]);
    fputcsv($out, ['Resueltos + Cerrados', (int) $sum['s_resolved'] + (int) $sum['s_closed']]);
    fputcsv($out, ['Tasa de resolución', pctTxt($resolutionRate)]);
    fputcsv($out, ['Cumplimiento 1ra respuesta (CTA)', pctTxt($respCompliance)]);
    fputcsv($out, ['Cumplimiento SLA resolución', pctTxt($resolCompliance)]);
    fputcsv($out, ['Tiempo prom. 1ra respuesta', dur($sum['avg_resp_min'] !== null ? (float) $sum['avg_resp_min'] : null)]);
    fputcsv($out, ['Tiempo prom. resolución', dur($sum['avg_resol_min'] !== null ? (float) $sum['avg_resol_min'] : null)]);
    fputcsv($out, ['Vencidos (SLA activo)', (int) $sum['overdue']]);
    fputcsv($out, []);
    fputcsv($out, ['Cumplimiento por soporte']);
    fputcsv($out, ['Agente', 'Rol', 'Asignados', 'Resueltos', 'Activos', 'Vencidos', '1ra Resp OK %', 'Resol SLA OK %', 'Prom. 1ra Resp', 'Prom. Resolución']);
    foreach ($byAgent as $a) {
        fputcsv($out, [
            $a['full_name'], $a['role'], (int) $a['assigned'], (int) $a['resolved'], (int) $a['active'], (int) $a['overdue'],
            pctTxt(pct($a['resp_ok'], $a['responded'])), pctTxt(pct($a['resol_ok'], $a['resolved'])),
            dur($a['avg_resp_min'] !== null ? (float) $a['avg_resp_min'] : null),
            dur($a['avg_resol_min'] !== null ? (float) $a['avg_resol_min'] : null),
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Por categoría']);
    fputcsv($out, ['Categoría', 'Total', 'Resueltos', 'SLA OK %', 'Prom. Resolución']);
    foreach ($byCat as $c) {
        fputcsv($out, [$c['name'], (int) $c['total'], (int) $c['resolved'], pctTxt(pct($c['resol_ok'], $c['resolved'])), dur($c['avg_resol_min'] !== null ? (float) $c['avg_resol_min'] : null)]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Por campaña del solicitante']);
    fputcsv($out, ['Campaña', 'Total', 'Resueltos']);
    foreach ($byCamp as $c) { fputcsv($out, [$c['name'], (int) $c['total'], (int) $c['resolved']]); }
    fclose($out);
    exit;
}

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../header.php';

// Presets de rango.
$presets = [
    'Hoy'        => [date('Y-m-d'), date('Y-m-d')],
    '7 días'     => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    '30 días'    => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    'Este mes'   => [date('Y-m-01'), date('Y-m-d')],
    '90 días'    => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
];
$prioLabels = ['critical' => 'Crítica', 'high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja'];
$prioColors = ['critical' => '#E0393B', 'high' => '#F79009', 'medium' => '#4A6CF7', 'low' => '#98A6C0'];
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
  --hd-bg:#F4F6FB; --hd-card:#FFFFFF; --hd-ink:#161F35; --hd-muted:#5B6B88; --hd-faint:#8A97AE;
  --hd-line:#E6EBF3; --hd-soft:#F7F9FC; --hd-brand:#2A4CCC; --hd-brand-tint:#EAF0FF;
  --hd-shadow:0 1px 2px rgba(20,30,60,.04),0 8px 24px rgba(20,30,60,.06); --hd-shadow-sm:0 1px 2px rgba(20,30,60,.06);
  --ok:#0E9F6E; --ok-tint:#E4F7EE; --mid:#B25E09; --mid-tint:#FEF0DA; --bad:#E0393B; --bad-tint:#FBE9E9;
}
.theme-dark{ --hd-bg:#0F1626; --hd-card:#161F33; --hd-ink:#EAF0FB; --hd-muted:#9FB0CC; --hd-faint:#7C8AA6; --hd-line:#243149; --hd-soft:#1B2438; --hd-brand-tint:#20304F; --ok-tint:#14342A; --mid-tint:#33270F; --bad-tint:#3A1E20; }
.hrp *{box-sizing:border-box;}
.hrp{font-family:'Inter','Plus Jakarta Sans',system-ui,sans-serif; color:var(--hd-ink); padding:22px 26px; max-width:1500px; margin:0 auto;}
.hrp-head{display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:8px;}
.hrp-title{font-size:22px; font-weight:800; letter-spacing:-.3px; display:flex; align-items:center; gap:11px;}
.hrp-title i{color:var(--hd-brand);}
.hrp-sub{font-size:12.5px; color:var(--hd-muted); margin-top:3px;}
.hrp-actions{display:flex; gap:8px; flex-wrap:wrap;}
.hrp-btn{border:1px solid var(--hd-line); background:var(--hd-card); color:var(--hd-ink); border-radius:10px; padding:9px 14px; font-size:12.5px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:7px; text-decoration:none; font-family:inherit;}
.hrp-btn:hover{border-color:var(--hd-brand);}
.hrp-btn.primary{background:var(--hd-brand); color:#fff; border-color:var(--hd-brand);}
.hrp-toolbar{display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:16px 0 20px; padding:12px 14px; background:var(--hd-card); border:1px solid var(--hd-line); border-radius:14px; box-shadow:var(--hd-shadow-sm);}
.hrp-chips{display:flex; gap:6px; flex-wrap:wrap;}
.hrp-chip{border:1px solid var(--hd-line); background:var(--hd-soft); color:var(--hd-muted); border-radius:999px; padding:6px 13px; font-size:12px; font-weight:700; text-decoration:none;}
.hrp-chip:hover{border-color:var(--hd-brand); color:var(--hd-brand);}
.hrp-chip.active{background:var(--hd-brand); color:#fff; border-color:var(--hd-brand);}
.hrp-datef{display:flex; align-items:center; gap:7px; margin-left:auto; flex-wrap:wrap;}
.hrp-datef input{border:1px solid var(--hd-line); border-radius:9px; padding:8px 10px; font-size:12.5px; background:var(--hd-soft); color:var(--hd-ink); font-family:inherit;}
.hrp-datef label{font-size:11px; font-weight:700; color:var(--hd-faint);}
.hrp-kpis{display:grid; grid-template-columns:repeat(auto-fit,minmax(158px,1fr)); gap:12px; margin-bottom:18px;}
.hrp-kpi{background:var(--hd-card); border:1px solid var(--hd-line); border-radius:14px; padding:15px 16px; box-shadow:var(--hd-shadow-sm); position:relative; overflow:hidden;}
.hrp-kpi .ic{position:absolute; right:12px; top:12px; font-size:15px; opacity:.28;}
.hrp-kpi .n{font-size:26px; font-weight:800; letter-spacing:-.6px; line-height:1; font-variant-numeric:tabular-nums;}
.hrp-kpi .l{font-size:11px; color:var(--hd-muted); font-weight:700; margin-top:7px; text-transform:uppercase; letter-spacing:.4px;}
.hrp-kpi .s{font-size:11px; color:var(--hd-faint); margin-top:3px; font-weight:600;}
.hrp-kpi.ok{background:var(--ok-tint);} .hrp-kpi.ok .n{color:var(--ok);}
.hrp-kpi.mid{background:var(--mid-tint);} .hrp-kpi.mid .n{color:var(--mid);}
.hrp-kpi.bad{background:var(--bad-tint);} .hrp-kpi.bad .n{color:var(--bad);}
.hrp-kpi.brand .n{color:var(--hd-brand);}
.hrp-grid{display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px;}
@media(max-width:900px){ .hrp-grid{grid-template-columns:1fr;} }
.hrp-card{background:var(--hd-card); border:1px solid var(--hd-line); border-radius:16px; box-shadow:var(--hd-shadow); padding:18px 20px;}
.hrp-card h3{font-size:14px; font-weight:800; margin:0 0 14px; display:flex; align-items:center; gap:9px; letter-spacing:-.2px;}
.hrp-card h3 i{color:var(--hd-brand); font-size:13px;}
.hrp-card h3 .tag{margin-left:auto; font-size:10.5px; font-weight:700; color:var(--hd-faint); text-transform:uppercase; letter-spacing:.4px;}
.hrp-table{width:100%; border-collapse:collapse; font-size:12.5px;}
.hrp-table th{text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.4px; color:var(--hd-faint); font-weight:800; padding:8px 10px; border-bottom:1px solid var(--hd-line); white-space:nowrap;}
.hrp-table td{padding:10px; border-bottom:1px solid var(--hd-line); color:var(--hd-ink); font-weight:600; vertical-align:middle;}
.hrp-table tr:last-child td{border-bottom:none;}
.hrp-table td.num,.hrp-table th.num{text-align:right; font-variant-numeric:tabular-nums;}
.hrp-who{display:flex; align-items:center; gap:9px;}
.hrp-av{width:26px; height:26px; border-radius:50%; background:var(--hd-brand); color:#fff; font-size:10px; font-weight:700; display:grid; place-items:center; flex-shrink:0;}
.hrp-pill{font-size:11px; font-weight:800; padding:3px 9px; border-radius:999px; font-variant-numeric:tabular-nums; display:inline-block; min-width:52px; text-align:center;}
.hrp-pill.ok{background:var(--ok-tint); color:var(--ok);} .hrp-pill.mid{background:var(--mid-tint); color:var(--mid);} .hrp-pill.bad{background:var(--bad-tint); color:var(--bad);} .hrp-pill.na{background:var(--hd-soft); color:var(--hd-faint);}
.hrp-bar{height:8px; border-radius:5px; background:var(--hd-soft); overflow:hidden; min-width:60px;}
.hrp-bar > span{display:block; height:100%; border-radius:5px;}
.hrp-dist{display:flex; flex-direction:column; gap:11px;}
.hrp-distrow{display:grid; grid-template-columns:120px 1fr 42px; align-items:center; gap:10px; font-size:12.5px;}
.hrp-distrow .lb{font-weight:700; color:var(--hd-muted); display:flex; align-items:center; gap:7px;}
.hrp-distrow .vv{text-align:right; font-weight:800; font-variant-numeric:tabular-nums;}
.hrp-dot{width:9px; height:9px; border-radius:50%; flex-shrink:0;}
.hrp-trend{display:flex; align-items:flex-end; gap:4px; height:150px; overflow-x:auto; padding-top:10px;}
.hrp-tcol{display:flex; flex-direction:column; align-items:center; gap:5px; min-width:20px; flex:1;}
.hrp-tbars{display:flex; align-items:flex-end; gap:2px; height:120px;}
.hrp-tbars i{width:7px; border-radius:3px 3px 0 0; display:block;}
.hrp-tbars .c{background:var(--hd-brand);} .hrp-tbars .r{background:var(--ok);}
.hrp-tlbl{font-size:9px; color:var(--hd-faint); white-space:nowrap; transform:rotate(-45deg); transform-origin:center; height:22px;}
.hrp-legend{display:flex; gap:16px; font-size:11.5px; color:var(--hd-muted); font-weight:600; margin-top:8px;}
.hrp-legend span{display:inline-flex; align-items:center; gap:6px;}
.hrp-legend i{width:10px; height:10px; border-radius:3px; display:inline-block;}
.hrp-empty{text-align:center; color:var(--hd-faint); font-size:12.5px; padding:24px;}
@media print{ header,nav,.hrp-toolbar .hrp-datef,.hrp-actions{display:none!important;} .hrp{padding:0;} .hrp-card,.hrp-kpi{box-shadow:none;} }
</style>

<div class="hrp">
  <div class="hrp-head">
    <div>
      <div class="hrp-title"><i class="fas fa-chart-line"></i> Reportes de Soporte</div>
      <div class="hrp-sub">Período: <b><?= $e(date('d/M/Y', strtotime($from))) ?></b> — <b><?= $e(date('d/M/Y', strtotime($to))) ?></b> · <?= $rangeDays ?> día<?= $rangeDays > 1 ? 's' : '' ?></div>
    </div>
    <div class="hrp-actions">
      <a class="hrp-btn" href="console.php"><i class="fas fa-headset"></i> Consola</a>
      <a class="hrp-btn" href="?from=<?= $e($from) ?>&to=<?= $e($to) ?>&export=csv"><i class="fas fa-file-csv"></i> Exportar CSV</a>
      <button class="hrp-btn primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
    </div>
  </div>

  <form class="hrp-toolbar" method="get" id="hrpForm">
    <div class="hrp-chips">
      <?php foreach ($presets as $lb => $rng): $act = ($rng[0] === $from && $rng[1] === $to); ?>
        <a class="hrp-chip <?= $act ? 'active' : '' ?>" href="?from=<?= $rng[0] ?>&to=<?= $rng[1] ?>"><?= $e($lb) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="hrp-datef">
      <label>Desde</label><input type="date" name="from" value="<?= $e($from) ?>" max="<?= $e(date('Y-m-d')) ?>">
      <label>Hasta</label><input type="date" name="to" value="<?= $e($to) ?>" max="<?= $e(date('Y-m-d')) ?>">
      <button class="hrp-btn primary" type="submit"><i class="fas fa-filter"></i> Aplicar</button>
    </div>
  </form>

  <!-- Indicadores clave (CTA) -->
  <div class="hrp-kpis">
    <div class="hrp-kpi brand"><i class="fas fa-inbox ic"></i><div class="n"><?= (int) $sum['total'] ?></div><div class="l">Tickets del período</div><div class="s"><?= (int) $sum['s_open'] + (int) $sum['s_prog'] + (int) $sum['s_pend'] ?> activos · <?= (int) $sum['s_cancelled'] ?> cancelados</div></div>
    <div class="hrp-kpi"><i class="fas fa-circle-check ic"></i><div class="n"><?= (int) $sum['s_resolved'] + (int) $sum['s_closed'] ?></div><div class="l">Resueltos / Cerrados</div><div class="s">Tasa <?= pctTxt($resolutionRate) ?></div></div>
    <div class="hrp-kpi <?= slaClass($respCompliance) ?>"><i class="fas fa-bolt ic"></i><div class="n"><?= pctTxt($respCompliance) ?></div><div class="l">Cumplimiento 1ra respuesta</div><div class="s">CTA · <?= (int) $sum['resp_ok'] ?>/<?= (int) $sum['responded'] ?> a tiempo</div></div>
    <div class="hrp-kpi <?= slaClass($resolCompliance) ?>"><i class="fas fa-gauge-high ic"></i><div class="n"><?= pctTxt($resolCompliance) ?></div><div class="l">Cumplimiento SLA resolución</div><div class="s"><?= (int) $sum['resol_ok'] ?>/<?= (int) $sum['resolved_any'] ?> dentro de SLA</div></div>
    <div class="hrp-kpi"><i class="fas fa-stopwatch ic"></i><div class="n"><?= dur($sum['avg_resp_min'] !== null ? (float) $sum['avg_resp_min'] : null) ?></div><div class="l">Prom. 1ra respuesta</div></div>
    <div class="hrp-kpi"><i class="fas fa-hourglass-half ic"></i><div class="n"><?= dur($sum['avg_resol_min'] !== null ? (float) $sum['avg_resol_min'] : null) ?></div><div class="l">Prom. resolución</div></div>
    <div class="hrp-kpi <?= ((int) $sum['overdue']) > 0 ? 'bad' : 'ok' ?>"><i class="fas fa-triangle-exclamation ic"></i><div class="n"><?= (int) $sum['overdue'] ?></div><div class="l">Vencidos (SLA activo)</div><div class="s">Tickets abiertos fuera de plazo</div></div>
  </div>

  <!-- Cumplimiento por soporte -->
  <div class="hrp-card" style="margin-bottom:18px;">
    <h3><i class="fas fa-user-shield"></i> Cumplimiento de SLA por soporte <span class="tag">agente asignado</span></h3>
    <?php if (!$byAgent): ?>
      <div class="hrp-empty">Sin tickets asignados en este período.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="hrp-table">
      <thead><tr>
        <th>Agente de soporte</th>
        <th class="num">Asignados</th><th class="num">Resueltos</th><th class="num">Activos</th><th class="num">Vencidos</th>
        <th class="num">1ra Resp.</th><th class="num">SLA Resol.</th><th class="num">Prom. Resp.</th><th class="num">Prom. Resol.</th>
      </tr></thead>
      <tbody>
      <?php foreach ($byAgent as $a):
        $respP = pct($a['resp_ok'], $a['responded']); $resolP = pct($a['resol_ok'], $a['resolved']);
        $ini = strtoupper(mb_substr($a['full_name'], 0, 1) . (($sp = mb_strpos($a['full_name'], ' ')) !== false ? mb_substr($a['full_name'], $sp + 1, 1) : ''));
      ?>
        <tr>
          <td><div class="hrp-who"><span class="hrp-av"><?= $e($ini) ?></span><div><div><?= $e($a['full_name']) ?></div><div style="font-size:10.5px;color:var(--hd-faint);font-weight:700;text-transform:uppercase;letter-spacing:.3px;"><?= $e($a['role']) ?></div></div></div></td>
          <td class="num"><?= (int) $a['assigned'] ?></td>
          <td class="num"><?= (int) $a['resolved'] ?></td>
          <td class="num"><?= (int) $a['active'] ?></td>
          <td class="num"><?= ((int) $a['overdue']) > 0 ? '<b style="color:var(--bad)">' . (int) $a['overdue'] . '</b>' : '0' ?></td>
          <td class="num"><span class="hrp-pill <?= $respP === null ? 'na' : slaClass($respP) ?>"><?= pctTxt($respP) ?></span></td>
          <td class="num"><span class="hrp-pill <?= $resolP === null ? 'na' : slaClass($resolP) ?>"><?= pctTxt($resolP) ?></span></td>
          <td class="num"><?= dur($a['avg_resp_min'] !== null ? (float) $a['avg_resp_min'] : null) ?></td>
          <td class="num"><?= dur($a['avg_resol_min'] !== null ? (float) $a['avg_resol_min'] : null) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="hrp-grid">
    <!-- Tendencia -->
    <div class="hrp-card">
      <h3><i class="fas fa-chart-column"></i> Tendencia diaria</h3>
      <?php
        $days = [];
        $cur = strtotime($from); $end = strtotime($to);
        while ($cur <= $end) { $days[] = date('Y-m-d', $cur); $cur = strtotime('+1 day', $cur); }
        $maxDay = 1;
        foreach ($days as $d) { $maxDay = max($maxDay, $createdByDay[$d] ?? 0, $resolvedByDay[$d] ?? 0); }
      ?>
      <?php if (array_sum($createdByDay) + array_sum($resolvedByDay) === 0): ?>
        <div class="hrp-empty">Sin actividad en el período.</div>
      <?php else: ?>
      <div class="hrp-trend">
        <?php foreach ($days as $d):
          $c = $createdByDay[$d] ?? 0; $r = $resolvedByDay[$d] ?? 0;
          $hc = max(2, (int) round(($c / $maxDay) * 120)); $hr = max(2, (int) round(($r / $maxDay) * 120));
        ?>
          <div class="hrp-tcol" title="<?= $e(date('d/M', strtotime($d))) ?> · <?= $c ?> creados, <?= $r ?> resueltos">
            <div class="hrp-tbars">
              <i class="c" style="height:<?= $c ? $hc : 0 ?>px"></i>
              <i class="r" style="height:<?= $r ? $hr : 0 ?>px"></i>
            </div>
            <?php if ($rangeDays <= 31): ?><div class="hrp-tlbl"><?= $e(date('d/m', strtotime($d))) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="hrp-legend"><span><i style="background:var(--hd-brand)"></i> Creados</span><span><i style="background:var(--ok)"></i> Resueltos</span></div>
      <?php endif; ?>
    </div>

    <!-- Prioridad + Estado -->
    <div class="hrp-card">
      <h3><i class="fas fa-layer-group"></i> Distribución</h3>
      <div style="font-size:11px;font-weight:800;color:var(--hd-faint);text-transform:uppercase;letter-spacing:.4px;margin-bottom:9px;">Por prioridad</div>
      <div class="hrp-dist" style="margin-bottom:18px;">
        <?php $tot = max(1, (int) $sum['total']); foreach ($prioLabels as $k => $lb): $v = $byPrio[$k] ?? 0; $w = round(($v / $tot) * 100); ?>
          <div class="hrp-distrow">
            <span class="lb"><span class="hrp-dot" style="background:<?= $prioColors[$k] ?>"></span><?= $e($lb) ?></span>
            <span class="hrp-bar"><span style="width:<?= $w ?>%;background:<?= $prioColors[$k] ?>"></span></span>
            <span class="vv"><?= $v ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="font-size:11px;font-weight:800;color:var(--hd-faint);text-transform:uppercase;letter-spacing:.4px;margin-bottom:9px;">Por estado</div>
      <div class="hrp-dist">
        <?php
          $states = [['Abiertos', (int) $sum['s_open'], '#1D5DD8'], ['En progreso', (int) $sum['s_prog'], '#B25E09'], ['Pendientes', (int) $sum['s_pend'], '#5B48D0'], ['Resueltos', (int) $sum['s_resolved'], '#0B7A4B'], ['Cerrados', (int) $sum['s_closed'], '#5B6B88'], ['Cancelados', (int) $sum['s_cancelled'], '#B42318']];
          foreach ($states as [$lb, $v, $col]): $w = round(($v / $tot) * 100); ?>
          <div class="hrp-distrow">
            <span class="lb"><span class="hrp-dot" style="background:<?= $col ?>"></span><?= $e($lb) ?></span>
            <span class="hrp-bar"><span style="width:<?= $w ?>%;background:<?= $col ?>"></span></span>
            <span class="vv"><?= $v ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="hrp-grid">
    <!-- Por categoría -->
    <div class="hrp-card">
      <h3><i class="fas fa-tags"></i> Por categoría</h3>
      <?php if (!$byCat): ?><div class="hrp-empty">Sin datos.</div><?php else: ?>
      <div style="overflow-x:auto;">
      <table class="hrp-table">
        <thead><tr><th>Categoría</th><th class="num">Total</th><th class="num">Resueltos</th><th class="num">SLA OK</th><th class="num">Prom. Resol.</th></tr></thead>
        <tbody>
        <?php foreach ($byCat as $c): $p = pct($c['resol_ok'], $c['resolved']); ?>
          <tr>
            <td><span class="hrp-dot" style="display:inline-block;margin-right:7px;background:<?= $e($c['color'] ?: '#98A6C0') ?>"></span><?= $e($c['name']) ?></td>
            <td class="num"><?= (int) $c['total'] ?></td>
            <td class="num"><?= (int) $c['resolved'] ?></td>
            <td class="num"><span class="hrp-pill <?= $p === null ? 'na' : slaClass($p) ?>"><?= pctTxt($p) ?></span></td>
            <td class="num"><?= dur($c['avg_resol_min'] !== null ? (float) $c['avg_resol_min'] : null) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Por campaña -->
    <div class="hrp-card">
      <h3><i class="fas fa-bullhorn"></i> Por campaña del solicitante <span class="tag">origen</span></h3>
      <?php if (!$byCamp): ?><div class="hrp-empty">Sin datos.</div><?php else: ?>
      <?php $maxCamp = max(1, ...array_map(fn($c) => (int) $c['total'], $byCamp)); ?>
      <div class="hrp-dist">
        <?php foreach ($byCamp as $c): $w = round(((int) $c['total'] / $maxCamp) * 100); ?>
          <div class="hrp-distrow" style="grid-template-columns:150px 1fr 70px;">
            <span class="lb"><span class="hrp-dot" style="background:<?= $e($c['color'] ?: '#2A4CCC') ?>"></span><?= $e($c['name']) ?></span>
            <span class="hrp-bar"><span style="width:<?= $w ?>%;background:<?= $e($c['color'] ?: '#2A4CCC') ?>"></span></span>
            <span class="vv"><?= (int) $c['total'] ?> <span style="color:var(--hd-faint);font-weight:600;font-size:11px;">(<?= (int) $c['resolved'] ?>✓)</span></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
