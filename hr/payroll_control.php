<?php
/**
 * Control del Período — etapas 5, 6 y 7 del procedimiento.
 *
 *   Consolidación (Nómina)  ->  Auditoría (firma)  ->  Aprobación del pago (Gerencia)
 *
 * Cada etapa está bloqueada hasta que la anterior se completó, y la primera
 * exige que TODOS los días del período estén cerrados y sin excepciones. Ese es
 * el punto: la nómina no puede calcularse sobre horas que nadie revisó.
 *
 * La segregación de funciones se aplica por permiso, no por confianza:
 *   payroll_consolidate     -> Nómina
 *   payroll_audit_sign      -> Auditoría
 *   payroll_approve_payment -> Gerencia (y es el único que puede reabrir)
 */

session_start();
require_once '../db.php';
require_once '../lib/timesheet_control.php';

ensurePermission('timesheet_control', '../unauthorized.php');

date_default_timezone_set('America/Santo_Domingo');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

$puedeConsolidar = userHasPermission('payroll_consolidate');
$puedeAuditar    = userHasPermission('payroll_audit_sign');
$puedeAprobar    = userHasPermission('payroll_approve_payment');

$flash = '';
$flashType = 'ok';

$periodId = (int) ($_GET['period_id'] ?? $_POST['period_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $periodId > 0) {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'consolidar' && $puedeConsolidar) {
        $res = timesheetConsolidatePeriod($pdo, $periodId, [
            'reason'       => trim((string) ($_POST['nota'] ?? '')),
            'performed_by' => $currentUserId,
        ]);
        $flash = $res['message'];
        $flashType = $res['ok'] ? 'ok' : 'error';

    } elseif ($accion === 'auditar' && $puedeAuditar) {
        $res = timesheetSignAudit(
            $pdo,
            $periodId,
            ($_POST['resultado'] ?? '') === 'firmar',
            trim((string) ($_POST['nota'] ?? '')),
            ['performed_by' => $currentUserId]
        );
        $flash = $res['message'];
        $flashType = $res['ok'] ? 'ok' : 'error';

    } elseif ($accion === 'aprobar' && $puedeAprobar) {
        $res = timesheetApprovePayment($pdo, $periodId, [
            'reason'       => trim((string) ($_POST['nota'] ?? '')),
            'performed_by' => $currentUserId,
        ]);
        $flash = $res['message'];
        $flashType = $res['ok'] ? 'ok' : 'error';

    } elseif ($accion === 'reabrir' && $puedeAprobar) {
        $res = timesheetReopenPeriod($pdo, $periodId, trim((string) ($_POST['nota'] ?? '')), [
            'performed_by' => $currentUserId,
        ]);
        $flash = $res['message'];
        $flashType = $res['ok'] ? 'ok' : 'error';
    } else {
        $flash = 'No tienes autorización para esa acción.';
        $flashType = 'error';
    }
}

$periodos = $pdo->query("
    SELECT id, name, start_date, end_date, status, total_net,
           consolidated_at, audited_at, audit_result, approved_at, control_locked
    FROM payroll_periods
    ORDER BY start_date DESC
    LIMIT 24
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($periodId <= 0 && !empty($periodos)) {
    $periodId = (int) $periodos[0]['id'];
}

$estado = $periodId > 0 ? timesheetPeriodReadiness($pdo, $periodId) : null;
$periodo = $estado['period'] ?? null;

$eventos = [];
if ($periodId > 0) {
    try {
        $s = $pdo->prepare("
            SELECT * FROM timesheet_stage_events
            WHERE payroll_period_id = ? AND scope = 'PERIOD'
            ORDER BY created_at ASC
        ");
        $s->execute([$periodId]);
        $eventos = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $eventos = [];
    }
}

// Estado de las etapas para el semáforo
$etapaConsolidado = $periodo && !empty($periodo['consolidated_at']);
$etapaAuditado    = $periodo && ($periodo['audit_result'] ?? 'PENDING') === 'SIGNED';
$etapaDevuelto    = $periodo && ($periodo['audit_result'] ?? '') === 'RETURNED';
$etapaAprobado    = $periodo && in_array($periodo['status'] ?? '', ['APPROVED', 'PAID', 'CLOSED'], true);

function pc_dop(float $n): string { return 'RD$ ' . number_format($n, 2); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control del Período - Nómina</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">

        <div class="flex flex-wrap justify-between items-start gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold mb-2">
                    <i class="fas fa-shield-halved text-indigo-400 mr-3"></i>Control del Período
                </h1>
                <p class="text-slate-400 text-sm max-w-3xl">
                    Ninguna etapa avanza sin que la anterior esté firmada. La nómina no se
                    consolida con días abiertos y el pago no se aprueba sin auditoría.
                </p>
            </div>
            <div class="flex gap-2">
                <a href="timesheet_control.php" class="px-4 py-2 rounded-lg bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 text-sm">
                    <i class="fas fa-calendar-day mr-2"></i>Control diario
                </a>
                <a href="payroll.php" class="px-4 py-2 rounded-lg bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 text-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Nómina
                </a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="mb-6 p-4 rounded-lg text-sm border <?=
                $flashType === 'ok' ? 'bg-emerald-900/30 border-emerald-500/40 text-emerald-200'
                                    : 'bg-red-900/30 border-red-500/40 text-red-200' ?>">
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <form method="get" class="flex items-end gap-3 mb-6">
            <div>
                <label class="block text-xs text-slate-400 mb-1">Período</label>
                <select name="period_id" onchange="this.form.submit()"
                        class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-600 text-slate-100 text-sm min-w-[280px]">
                    <?php foreach ($periodos as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $periodId === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                            (<?= date('d/m', strtotime($p['start_date'])) ?>–<?= date('d/m/Y', strtotime($p['end_date'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if (!$periodo): ?>
            <div class="p-10 text-center rounded-xl bg-slate-800/40 border border-slate-700/60 text-slate-400">
                No hay períodos de nómina registrados.
            </div>
        <?php else: ?>

        <!-- Semáforo de etapas -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
            <?php
            $etapas = [
                ['Días cerrados', $estado['open_days'] === 0 && $estado['open_exceptions'] === 0,
                 $estado['open_days'] . ' sin cerrar · ' . $estado['open_exceptions'] . ' excepción(es)', 'fa-lock'],
                ['Consolidado', $etapaConsolidado,
                 $etapaConsolidado ? date('d/m/Y H:i', strtotime($periodo['consolidated_at'])) : 'pendiente de Nómina', 'fa-layer-group'],
                ['Auditado', $etapaAuditado,
                 $etapaAuditado ? date('d/m/Y H:i', strtotime($periodo['audited_at']))
                                : ($etapaDevuelto ? 'devuelto a Nómina' : 'pendiente de Auditoría'), 'fa-clipboard-check'],
                ['Pago aprobado', $etapaAprobado,
                 $etapaAprobado && !empty($periodo['approved_at']) ? date('d/m/Y H:i', strtotime($periodo['approved_at'])) : 'pendiente de Gerencia', 'fa-circle-check'],
            ];
            foreach ($etapas as [$titulo, $listo, $detalle, $icono]): ?>
                <div class="p-4 rounded-xl border <?= $listo
                        ? 'bg-emerald-900/20 border-emerald-500/40'
                        : 'bg-slate-800/50 border-slate-700/60' ?>">
                    <p class="text-xs uppercase tracking-widest text-slate-400 mb-1">
                        <i class="fas <?= $icono ?> mr-1"></i><?= $titulo ?>
                    </p>
                    <p class="text-lg font-bold <?= $listo ? 'text-emerald-300' : 'text-slate-300' ?>">
                        <?= $listo ? 'Listo' : 'Pendiente' ?>
                    </p>
                    <p class="text-xs text-slate-400 mt-1"><?= htmlspecialchars($detalle) ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

            <!-- Bloqueos -->
            <div class="lg:col-span-2 rounded-xl bg-slate-800/40 border border-slate-700/60 p-5">
                <h2 class="font-semibold text-slate-100 mb-3">
                    <?= htmlspecialchars($periodo['name']) ?>
                    <span class="text-slate-400 text-sm font-normal">
                        · <?= date('d/m/Y', strtotime($periodo['start_date'])) ?>
                        al <?= date('d/m/Y', strtotime($periodo['end_date'])) ?>
                        · estado <?= htmlspecialchars($periodo['status']) ?>
                    </span>
                </h2>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4 text-sm">
                    <div><p class="text-xs text-slate-400">Días cerrados</p>
                         <p class="text-lg font-bold text-emerald-300"><?= (int) $estado['closed_days'] ?></p></div>
                    <div><p class="text-xs text-slate-400">Días abiertos</p>
                         <p class="text-lg font-bold <?= $estado['open_days'] > 0 ? 'text-amber-300' : 'text-slate-300' ?>"><?= (int) $estado['open_days'] ?></p></div>
                    <div><p class="text-xs text-slate-400">Excepciones</p>
                         <p class="text-lg font-bold <?= $estado['open_exceptions'] > 0 ? 'text-red-300' : 'text-slate-300' ?>"><?= (int) $estado['open_exceptions'] ?></p></div>
                    <div><p class="text-xs text-slate-400">Neto del período</p>
                         <p class="text-lg font-bold text-slate-100"><?= pc_dop((float) ($periodo['total_net'] ?? 0)) ?></p></div>
                </div>

                <?php if (!empty($estado['blockers'])): ?>
                    <div class="p-4 rounded-lg bg-amber-900/25 border border-amber-500/40 text-amber-200 text-sm">
                        <p class="font-semibold mb-1">Falta antes de consolidar:</p>
                        <ul class="list-disc list-inside space-y-0.5">
                            <?php foreach ($estado['blockers'] as $b): ?>
                                <li><?= htmlspecialchars($b) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="timesheet_control.php?tab=excepciones&desde=<?= htmlspecialchars($periodo['start_date']) ?>&hasta=<?= htmlspecialchars($periodo['end_date']) ?>"
                           class="inline-block mt-2 px-3 py-1.5 rounded bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold">
                            Ir a resolverlo
                        </a>
                    </div>
                <?php else: ?>
                    <div class="p-4 rounded-lg bg-emerald-900/25 border border-emerald-500/40 text-emerald-200 text-sm">
                        Todos los días del período están cerrados y sin excepciones abiertas.
                    </div>
                <?php endif; ?>

                <?php if (!empty($periodo['audit_note'])): ?>
                    <div class="mt-4 p-3 rounded-lg bg-slate-900/50 border border-slate-700 text-sm">
                        <p class="text-xs text-slate-400 mb-1">Nota de auditoría</p>
                        <p class="text-slate-200"><?= htmlspecialchars($periodo['audit_note']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($periodo['reopen_reason'])): ?>
                    <div class="mt-4 p-3 rounded-lg bg-red-900/25 border border-red-500/40 text-sm">
                        <p class="text-xs text-red-300 mb-1">Última reapertura</p>
                        <p class="text-red-200"><?= htmlspecialchars($periodo['reopen_reason']) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Acciones por etapa -->
            <div class="space-y-4">
                <?php if (!$etapaConsolidado): ?>
                    <form method="post" class="rounded-xl bg-slate-800/40 border border-slate-700/60 p-5 space-y-3">
                        <h3 class="font-semibold text-slate-100 text-sm">
                            <i class="fas fa-layer-group text-indigo-400 mr-2"></i>Consolidar (Nómina)
                        </h3>
                        <p class="text-xs text-slate-400">
                            Bloquea todos los días cerrados del período. A partir de aquí ninguna
                            corrección de ponche entra sin reabrir el período.
                        </p>
                        <input type="hidden" name="accion" value="consolidar">
                        <input type="hidden" name="period_id" value="<?= (int) $periodId ?>">
                        <input type="text" name="nota" placeholder="Nota (opcional)"
                               class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-600 text-slate-100 text-sm">
                        <button class="w-full px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold
                                       <?= (!$puedeConsolidar || !$estado['ok']) ? 'opacity-40 cursor-not-allowed' : '' ?>"
                                <?= (!$puedeConsolidar || !$estado['ok']) ? 'disabled' : '' ?>>
                            Consolidar período
                        </button>
                        <?php if (!$puedeConsolidar): ?>
                            <p class="text-xs text-slate-500">Requiere el permiso de Nómina.</p>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>

                <?php if ($etapaConsolidado && !$etapaAuditado): ?>
                    <form method="post" class="rounded-xl bg-slate-800/40 border border-slate-700/60 p-5 space-y-3">
                        <h3 class="font-semibold text-slate-100 text-sm">
                            <i class="fas fa-clipboard-check text-indigo-400 mr-2"></i>Auditoría
                        </h3>
                        <p class="text-xs text-slate-400">
                            Revisa el expediente del período: ajustes con su motivo, excepciones
                            resueltas y reaperturas. Firma o devuelve a Nómina.
                        </p>
                        <input type="hidden" name="accion" value="auditar">
                        <input type="hidden" name="period_id" value="<?= (int) $periodId ?>">
                        <textarea name="nota" rows="3" required placeholder="Nota de auditoría (obligatoria)"
                                  class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-600 text-slate-100 text-sm"></textarea>
                        <div class="flex gap-2">
                            <button name="resultado" value="firmar"
                                    class="flex-1 px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold
                                           <?= !$puedeAuditar ? 'opacity-40 cursor-not-allowed' : '' ?>"
                                    <?= !$puedeAuditar ? 'disabled' : '' ?>>Firmar</button>
                            <button name="resultado" value="devolver"
                                    class="flex-1 px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-200 text-sm
                                           <?= !$puedeAuditar ? 'opacity-40 cursor-not-allowed' : '' ?>"
                                    <?= !$puedeAuditar ? 'disabled' : '' ?>>Devolver</button>
                        </div>
                        <?php if (!$puedeAuditar): ?>
                            <p class="text-xs text-slate-500">Requiere el permiso de Auditoría.</p>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>

                <?php if ($etapaAuditado && !$etapaAprobado): ?>
                    <form method="post" class="rounded-xl bg-slate-800/40 border border-emerald-600/40 p-5 space-y-3">
                        <h3 class="font-semibold text-slate-100 text-sm">
                            <i class="fas fa-circle-check text-emerald-400 mr-2"></i>Aprobar el pago
                        </h3>
                        <p class="text-xs text-slate-400">
                            Con la auditoría firmada, Gerencia libera el pago. Finanzas solo puede
                            contabilizarlo después de este paso.
                        </p>
                        <input type="hidden" name="accion" value="aprobar">
                        <input type="hidden" name="period_id" value="<?= (int) $periodId ?>">
                        <input type="text" name="nota" placeholder="Nota (opcional)"
                               class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-600 text-slate-100 text-sm">
                        <button class="w-full px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold
                                       <?= !$puedeAprobar ? 'opacity-40 cursor-not-allowed' : '' ?>"
                                <?= !$puedeAprobar ? 'disabled' : '' ?>>
                            Aprobar el pago
                        </button>
                        <?php if (!$puedeAprobar): ?>
                            <p class="text-xs text-slate-500">Solo Gerencia.</p>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>

                <?php if ($puedeAprobar && !empty($periodo['control_locked'])): ?>
                    <form method="post" class="rounded-xl bg-slate-800/40 border border-amber-600/40 p-5 space-y-3"
                          onsubmit="return confirm('Reabrir devuelve los días a CERRADO y anula la firma de auditoría. ¿Continuar?');">
                        <h3 class="font-semibold text-slate-100 text-sm">
                            <i class="fas fa-lock-open text-amber-400 mr-2"></i>Reabrir el período
                        </h3>
                        <p class="text-xs text-slate-400">
                            Anula la firma de auditoría y notifica a Gerencia. Queda registrado
                            como evento propio en la bitácora.
                        </p>
                        <input type="hidden" name="accion" value="reabrir">
                        <input type="hidden" name="period_id" value="<?= (int) $periodId ?>">
                        <textarea name="nota" rows="2" required placeholder="Motivo de la reapertura (obligatorio)"
                                  class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-600 text-slate-100 text-sm"></textarea>
                        <button class="w-full px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold">
                            Reabrir período
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bitácora del período -->
        <div class="rounded-xl bg-slate-800/40 border border-slate-700/60 overflow-hidden mt-4">
            <div class="px-5 py-3 border-b border-slate-700/60">
                <h3 class="font-semibold text-slate-100 text-sm">
                    <i class="fas fa-timeline text-indigo-400 mr-2"></i>Trazabilidad del período
                </h3>
            </div>
            <div class="p-5 space-y-3">
                <?php if (empty($eventos)): ?>
                    <p class="text-slate-500 text-sm">Todavía no hay eventos registrados en este período.</p>
                <?php endif; ?>
                <?php foreach ($eventos as $ev): ?>
                    <div class="border-l-2 border-indigo-500/50 pl-4">
                        <p class="text-sm text-slate-200">
                            <?= htmlspecialchars((string) ($ev['from_stage'] ?: '—')) ?>
                            → <strong><?= htmlspecialchars((string) $ev['to_stage']) ?></strong>
                            <?php if ($ev['days_affected'] !== null): ?>
                                <span class="text-slate-400 text-xs">(<?= (int) $ev['days_affected'] ?> día(s))</span>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($ev['reason'])): ?>
                            <p class="text-sm text-slate-400"><?= htmlspecialchars($ev['reason']) ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-slate-500">
                            <?= htmlspecialchars((string) ($ev['performed_by_name'] ?: 'sistema')) ?> ·
                            <?= date('d/m/Y H:i', strtotime($ev['created_at'])) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>
