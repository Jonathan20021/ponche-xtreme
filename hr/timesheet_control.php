<?php
/**
 * Control de Horas — el tablero diario del procedimiento de seguridad.
 *
 * Responde las cuatro preguntas que pidió el cliente, en este orden:
 *   cuánto se generó, qué se modificó, qué está pendiente y qué requiere revisión.
 *
 * Tres pestañas:
 *   dia          -> una fila por colaborador: horas, dinero, etapa, cerrar
 *   excepciones  -> bandeja con severidad, dueño y resolución
 *   trazabilidad -> expediente de un día: ponche original vs actual, ajustes,
 *                   eliminaciones, comentarios y eventos de etapa
 *
 * Nada de esto edita horas: para corregir se sigue usando Registros. Aquí se
 * revisa, se comenta, se cierra y se reabre.
 */

session_start();
require_once '../db.php';
require_once '../lib/timesheet_control.php';
require_once '../lib/attendance_audit.php';
require_once '../lib/logging_functions.php';

ensurePermission('timesheet_control', '../unauthorized.php');

date_default_timezone_set('America/Santo_Domingo');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

$puedeCerrar  = userHasPermission('timesheet_close_day');
$puedeReabrir = userHasPermission('timesheet_reopen_day');

$fecha = $_GET['fecha'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d');
}
$tab = $_GET['tab'] ?? 'dia';
if (!in_array($tab, ['dia', 'excepciones', 'trazabilidad'], true)) {
    $tab = 'dia';
}

$flash = '';
$flashType = 'ok';

// ---------------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'cerrar_dia' && $puedeCerrar) {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $res = timesheetCloseDay($pdo, $uid, $fecha, [
            'reason'       => trim((string) ($_POST['motivo'] ?? '')),
            'performed_by' => $currentUserId,
        ]);
        $flash = $res['message'];
        $flashType = $res['ok'] ? 'ok' : 'error';

    } elseif ($accion === 'cerrar_todos' && $puedeCerrar) {
        $cerrados = 0; $fallidos = 0;
        foreach (timesheetDayRows($pdo, $fecha) as $row) {
            if (in_array($row['status'], ['CLOSED', 'LOCKED'], true)) {
                continue;
            }
            $res = timesheetCloseDay($pdo, (int) $row['user_id'], $fecha, [
                'reason'       => 'Cierre masivo del día desde el Control de Horas',
                'performed_by' => $currentUserId,
            ]);
            $res['ok'] ? $cerrados++ : $fallidos++;
        }
        $flash = "Se cerraron $cerrados día(s)." . ($fallidos > 0
            ? " Quedaron $fallidos sin cerrar por excepciones abiertas."
            : '');
        $flashType = $fallidos > 0 ? 'warn' : 'ok';

    } elseif ($accion === 'reabrir_dia') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $res = timesheetReopenDay($pdo, $uid, $fecha, [
            'reason'       => trim((string) ($_POST['motivo'] ?? '')),
            'auth_code'    => trim((string) ($_POST['auth_code'] ?? '')),
            'performed_by' => $currentUserId,
        ]);
        $flash = $res['message'];
        $flashType = $res['ok'] ? 'ok' : 'error';

    } elseif ($accion === 'resolver_excepcion') {
        $res = timesheetResolveException(
            $pdo,
            (int) ($_POST['excepcion_id'] ?? 0),
            trim((string) ($_POST['nota'] ?? '')),
            ($_POST['modo'] ?? '') === 'descartar',
            $currentUserId
        );
        $flash = $res['message'];
        $flashType = $res['ok'] ? 'ok' : 'error';

    } elseif ($accion === 'comentar') {
        $id = timesheetAddComment($pdo, [
            'user_id'    => (int) ($_POST['user_id'] ?? 0),
            'work_date'  => $fecha,
            'scope'      => 'DAY',
            'comment'    => trim((string) ($_POST['comentario'] ?? '')),
            'created_by' => $currentUserId,
        ]);
        $flash = $id ? 'Comentario agregado. No se puede editar ni eliminar.' : 'El comentario no puede ir vacío.';
        $flashType = $id ? 'ok' : 'error';

    } elseif ($accion === 'redetectar') {
        $n = timesheetDetectExceptions($pdo, $fecha);
        $flash = "Revisión completada: $n excepción(es) abierta(s) en el día.";
        $flashType = $n > 0 ? 'warn' : 'ok';
    }
}

// Barrido silencioso al abrir el panel: el revisor nunca ve datos rancios.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    timesheetDetectExceptions($pdo, $fecha);
}

// ---------------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------------
$instalado = timesheetTablesReady($pdo);
$activo    = timesheetControlEnabled($pdo);
$bloqueo   = timesheetLockEnforced($pdo);
$impacto   = timesheetDailyImpact($pdo, $fecha);
$filas     = timesheetDayRows($pdo, $fecha);
$ventana   = timesheetWindowLabel($pdo, $fecha);
$enVentana = timesheetIsWithinWindow($pdo, $fecha);

$excepciones = [];
if ($tab === 'excepciones') {
    $desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-14 days'));
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) { $desde = date('Y-m-d', strtotime('-14 days')); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) { $hasta = date('Y-m-d'); }
    $excepciones = timesheetOpenExceptions($pdo, $desde, $hasta);
} else {
    $desde = date('Y-m-d', strtotime('-14 days'));
    $hasta = date('Y-m-d');
}

$trazaUserId = (int) ($_GET['u'] ?? 0);
$traza = ($tab === 'trazabilidad' && $trazaUserId > 0)
    ? timesheetTrace($pdo, $trazaUserId, $fecha)
    : null;

function tc_hm(int $seconds): string
{
    $seconds = max(0, $seconds);
    return sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}

function tc_dop(float $n): string
{
    return 'RD$ ' . number_format($n, 2);
}

function tc_etapa(string $status): array
{
    return [
        'OPEN'      => ['Abierto',    'bg-slate-600/40 text-slate-200'],
        'IN_REVIEW' => ['En revisión','bg-blue-600/30 text-blue-200'],
        'ADJUSTED'  => ['Ajustado',   'bg-amber-600/30 text-amber-200'],
        'CLOSED'    => ['Cerrado',    'bg-emerald-600/30 text-emerald-200'],
        'LOCKED'    => ['Bloqueado',  'bg-purple-600/30 text-purple-200'],
    ][$status] ?? [$status, 'bg-slate-600/40 text-slate-200'];
}

function tc_sev(string $sev): array
{
    return [
        'CRITICAL' => ['Crítica', 'bg-red-600/30 text-red-200 border-red-500/40'],
        'HIGH'     => ['Alta',    'bg-orange-600/30 text-orange-200 border-orange-500/40'],
        'MEDIUM'   => ['Media',   'bg-amber-600/30 text-amber-200 border-amber-500/40'],
        'LOW'      => ['Baja',    'bg-slate-600/30 text-slate-200 border-slate-500/40'],
    ][$sev] ?? [$sev, 'bg-slate-600/30 text-slate-200 border-slate-500/40'];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Horas - HR</title>
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
                    <i class="fas fa-shield-halved text-indigo-400 mr-3"></i>Control de Horas
                </h1>
                <p class="text-slate-400 text-sm max-w-3xl">
                    Nadie puede modificar silenciosamente una hora que genere dinero.
                    Aquí se revisa el día, se resuelven las excepciones y se cierra la etapa
                    antes de que la nómina lo consuma.
                </p>
            </div>
            <div class="flex gap-2">
                <a href="payroll.php" class="px-4 py-2 rounded-lg bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 text-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Nómina
                </a>
            </div>
        </div>

        <?php if (!$instalado): ?>
            <div class="mb-6 p-4 rounded-lg bg-red-900/30 border border-red-500/40 text-red-200 text-sm">
                <strong>El control de horas no está instalado.</strong>
                Corre <code>run_timesheet_control_migration.php</code> para crear las tablas.
                Mientras tanto el ponche funciona como antes, sin etapas ni bloqueo.
            </div>
        <?php elseif (!$activo): ?>
            <div class="mb-6 p-4 rounded-lg bg-amber-900/30 border border-amber-500/40 text-amber-200 text-sm">
                <strong>El control está apagado</strong> (<code>timesheet_control_enabled</code> en Ajustes).
                Se sigue registrando el historial pero no se bloquea nada.
            </div>
        <?php elseif (!$bloqueo): ?>
            <div class="mb-6 p-4 rounded-lg bg-blue-900/30 border border-blue-500/40 text-blue-200 text-sm">
                <strong>Modo aviso.</strong> Los cambios sobre días cerrados pasan igual, pero
                quedan marcados y alertan. Para bloquear de verdad, activa
                <code>timesheet_lock_enforced</code> en Ajustes.
            </div>
        <?php endif; ?>

        <?php if ($flash !== ''): ?>
            <div class="mb-6 p-4 rounded-lg text-sm border <?=
                $flashType === 'ok'    ? 'bg-emerald-900/30 border-emerald-500/40 text-emerald-200' :
                ($flashType === 'warn' ? 'bg-amber-900/30 border-amber-500/40 text-amber-200'
                                       : 'bg-red-900/30 border-red-500/40 text-red-200') ?>">
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <!-- Impacto económico del día -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="p-5 rounded-xl bg-slate-800/60 border border-slate-700/60">
                <p class="text-xs uppercase tracking-widest text-slate-400 mb-1">Generado</p>
                <p class="text-2xl font-bold text-emerald-300"><?= tc_dop((float) $impacto['generated_amount']) ?></p>
                <p class="text-xs text-slate-400 mt-1">
                    <?= tc_hm((int) $impacto['generated_seconds']) ?> h ·
                    <?= (int) $impacto['people'] ?> colaborador(es)
                </p>
            </div>
            <div class="p-5 rounded-xl bg-slate-800/60 border border-slate-700/60">
                <p class="text-xs uppercase tracking-widest text-slate-400 mb-1">Modificado hoy</p>
                <p class="text-2xl font-bold <?= $impacto['modified_amount'] >= 0 ? 'text-amber-300' : 'text-blue-300' ?>">
                    <?= ($impacto['modified_amount'] >= 0 ? '+' : '−') ?><?= tc_dop(abs((float) $impacto['modified_amount'])) ?>
                </p>
                <p class="text-xs text-slate-400 mt-1"><?= (int) $impacto['adjustments'] ?> ajuste(s) registrados</p>
            </div>
            <div class="p-5 rounded-xl bg-slate-800/60 border border-slate-700/60">
                <p class="text-xs uppercase tracking-widest text-slate-400 mb-1">Pendiente de cierre</p>
                <p class="text-2xl font-bold text-slate-100"><?= (int) $impacto['pending_days'] ?></p>
                <p class="text-xs text-slate-400 mt-1"><?= (int) $impacto['closed_days'] ?> ya cerrado(s)</p>
            </div>
            <div class="p-5 rounded-xl border <?= $impacto['exceptions'] > 0
                    ? 'bg-red-900/25 border-red-500/40' : 'bg-slate-800/60 border-slate-700/60' ?>">
                <p class="text-xs uppercase tracking-widest text-slate-400 mb-1">Excepciones</p>
                <p class="text-2xl font-bold <?= $impacto['exceptions'] > 0 ? 'text-red-300' : 'text-slate-100' ?>">
                    <?= (int) $impacto['exceptions'] ?>
                </p>
                <p class="text-xs text-slate-400 mt-1"><?= (int) $impacto['exceptions_critical'] ?> grave(s)</p>
            </div>
        </div>

        <!-- Fecha y ventana -->
        <div class="flex flex-wrap items-end gap-3 mb-6">
            <form method="get" class="flex items-end gap-3">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Fecha</label>
                    <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>"
                           class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-600 text-slate-100 text-sm">
                </div>
                <button class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold">
                    Ver día
                </button>
            </form>
            <form method="post" class="inline">
                <input type="hidden" name="accion" value="redetectar">
                <button class="px-4 py-2 rounded-lg bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 text-sm">
                    <i class="fas fa-rotate mr-2"></i>Revisar excepciones
                </button>
            </form>
            <div class="text-xs <?= $enVentana ? 'text-slate-400' : 'text-amber-300' ?> pb-2">
                <i class="fas fa-hourglass-half mr-1"></i>
                Ventana de ajuste: <strong><?= htmlspecialchars($ventana) ?></strong>
                <?= $enVentana ? '(vigente)' : '(vencida — se exige código)' ?>
            </div>
        </div>

        <!-- Pestañas -->
        <div class="flex gap-2 mb-6 flex-wrap">
            <?php foreach ([
                'dia'          => ['Día', 'fa-calendar-day'],
                'excepciones'  => ['Excepciones', 'fa-triangle-exclamation'],
                'trazabilidad' => ['Trazabilidad', 'fa-fingerprint'],
            ] as $key => [$label, $icon]): ?>
                <a href="?<?= http_build_query(['fecha' => $fecha, 'tab' => $key]) ?>"
                   class="px-4 py-2 rounded-lg text-sm font-semibold <?= $tab === $key
                       ? 'bg-indigo-600 text-white'
                       : 'bg-slate-700/60 text-slate-300 hover:bg-slate-600/60' ?>">
                    <i class="fas <?= $icon ?> mr-2"></i><?= $label ?>
                </a>
            <?php endforeach; ?>
        </div>

<?php if ($tab === 'dia'): ?>
        <!-- ============================ DÍA ============================ -->
        <div class="rounded-xl bg-slate-800/40 border border-slate-700/60 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-slate-700/60">
                <h2 class="font-semibold text-slate-100">
                    <?= date('l d/m/Y', strtotime($fecha)) ?>
                    <span class="text-slate-400 text-sm font-normal">· <?= count($filas) ?> colaborador(es)</span>
                </h2>
                <?php if ($puedeCerrar && $impacto['pending_days'] > 0): ?>
                    <form method="post" onsubmit="return confirm('Se cerrarán todos los días sin excepciones abiertas. ¿Continuar?');">
                        <input type="hidden" name="accion" value="cerrar_todos">
                        <button class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold">
                            <i class="fas fa-lock mr-2"></i>Cerrar todos los que estén limpios
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-900/50 text-slate-400 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="text-left px-5 py-3">Colaborador</th>
                            <th class="text-center px-5 py-3">Fuente</th>
                            <th class="text-left px-5 py-3">Jornada</th>
                            <th class="text-right px-5 py-3">Horas</th>
                            <th class="text-right px-5 py-3">Valor</th>
                            <th class="text-center px-5 py-3">Ajustes</th>
                            <th class="text-center px-5 py-3">Excepciones</th>
                            <th class="text-center px-5 py-3">Etapa</th>
                            <th class="text-right px-5 py-3">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/40">
                    <?php if (empty($filas)): ?>
                        <tr><td colspan="9" class="px-5 py-10 text-center text-slate-400">
                            No hay horas registradas en esta fecha, ni por ponche ni por Vicidial.
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($filas as $r): ?>
                        <?php [$etiqueta, $clase] = tc_etapa($r['status']); ?>
                        <tr class="hover:bg-slate-700/20">
                            <td class="px-5 py-3">
                                <div class="font-medium text-slate-100"><?= htmlspecialchars($r['full_name']) ?></div>
                                <div class="text-xs text-slate-500"><?= htmlspecialchars($r['username']) ?></div>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <?php if (($r['source'] ?? 'ponche') === 'vicidial'): ?>
                                    <span class="px-2 py-0.5 rounded bg-purple-600/30 text-purple-200 text-xs"
                                          title="Las horas pagables vienen de Vicidial">Vicidial</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded bg-slate-600/40 text-slate-300 text-xs"
                                          title="Las horas pagables vienen del ponche">Ponche</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-slate-300 text-xs">
                                <?php if ((int) $r['punch_count'] === 0): ?>
                                    <span class="text-slate-500">sin marcaciones en el ponche</span>
                                <?php else: ?>
                                    <?= $r['first_punch'] ? date('H:i', strtotime($r['first_punch'])) : '—' ?>
                                    →
                                    <?= $r['last_punch'] ? date('H:i', strtotime($r['last_punch'])) : '—' ?>
                                    <span class="text-slate-500">(<?= (int) $r['punch_count'] ?> marcas)</span>
                                    <?php if (($r['source'] ?? 'ponche') === 'ponche' && strtoupper((string) $r['last_type']) !== 'EXIT'): ?>
                                        <span class="ml-1 text-amber-400" title="No hay marcación de salida">
                                            <i class="fas fa-circle-exclamation"></i>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-right font-mono text-slate-100"><?= tc_hm((int) $r['work_seconds']) ?></td>
                            <td class="px-5 py-3 text-right font-mono <?= $r['rate'] > 0 ? 'text-emerald-300' : 'text-red-300' ?>">
                                <?= $r['rate'] > 0 ? tc_dop((float) $r['amount']) : 'sin tarifa' ?>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <?= $r['adjustments'] > 0
                                    ? '<span class="px-2 py-0.5 rounded bg-amber-600/30 text-amber-200 text-xs">' . (int) $r['adjustments'] . '</span>'
                                    : '<span class="text-slate-600">—</span>' ?>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <?= $r['exceptions'] > 0
                                    ? '<span class="px-2 py-0.5 rounded bg-red-600/30 text-red-200 text-xs">' . (int) $r['exceptions'] . '</span>'
                                    : '<span class="text-slate-600">—</span>' ?>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <span class="px-2 py-1 rounded text-xs font-semibold <?= $clase ?>"><?= $etiqueta ?></span>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <a href="?<?= http_build_query(['fecha' => $fecha, 'tab' => 'trazabilidad', 'u' => $r['user_id']]) ?>"
                                   class="px-2 py-1 rounded bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 text-xs"
                                   title="Ver expediente del día">
                                    <i class="fas fa-fingerprint"></i>
                                </a>
                                <?php if (!in_array($r['status'], ['CLOSED', 'LOCKED'], true) && $puedeCerrar): ?>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="accion" value="cerrar_dia">
                                        <input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
                                        <button class="px-2 py-1 rounded bg-emerald-600/80 hover:bg-emerald-500 text-white text-xs"
                                                <?= $r['exceptions'] > 0 ? 'disabled title="Resuelve las excepciones antes de cerrar"' : 'title="Cerrar el día"' ?>
                                                <?= $r['exceptions'] > 0 ? 'style="opacity:.4;cursor:not-allowed"' : '' ?>>
                                            <i class="fas fa-lock"></i>
                                        </button>
                                    </form>
                                <?php elseif ($r['status'] === 'CLOSED' && $puedeReabrir): ?>
                                    <button onclick="abrirReapertura(<?= (int) $r['user_id'] ?>, '<?= htmlspecialchars(addslashes($r['full_name'])) ?>')"
                                            class="px-2 py-1 rounded bg-amber-600/80 hover:bg-amber-500 text-white text-xs"
                                            title="Reabrir (requiere código y motivo)">
                                        <i class="fas fa-lock-open"></i>
                                    </button>
                                <?php elseif ($r['status'] === 'LOCKED'): ?>
                                    <span class="text-xs text-slate-500" title="El período de nómina se lo llevó">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal de reapertura -->
        <div id="modalReapertura" class="fixed inset-0 bg-black/70 hidden items-center justify-center z-50 p-4">
            <form method="post" class="bg-slate-800 border border-slate-600 rounded-xl w-full max-w-lg p-6 space-y-4">
                <h3 class="text-lg font-bold text-slate-100">
                    <i class="fas fa-lock-open text-amber-400 mr-2"></i>Reabrir día cerrado
                </h3>
                <p class="text-sm text-slate-400">
                    Reabrir es un evento formal: queda en la bitácora con tu nombre, el motivo
                    y el código usado, y se notifica a Gerencia. No es una edición más.
                </p>
                <input type="hidden" name="accion" value="reabrir_dia">
                <input type="hidden" name="user_id" id="reaperturaUserId">
                <p class="text-sm text-slate-300">Colaborador: <strong id="reaperturaNombre"></strong> · <?= htmlspecialchars($fecha) ?></p>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Motivo (obligatorio)</label>
                    <textarea name="motivo" rows="3" required
                              class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-600 text-slate-100 text-sm"
                              placeholder="Ej.: el colaborador olvidó marcar la salida y presentó el reporte del supervisor"></textarea>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Código de autorización</label>
                    <input type="text" name="auth_code"
                           class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-600 text-slate-100 text-sm"
                           placeholder="Código semanal vigente">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('modalReapertura').classList.add('hidden')"
                            class="px-4 py-2 rounded-lg bg-slate-700 text-slate-200 text-sm">Cancelar</button>
                    <button class="px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold">
                        Reabrir día
                    </button>
                </div>
            </form>
        </div>
        <script>
        function abrirReapertura(userId, nombre) {
            document.getElementById('reaperturaUserId').value = userId;
            document.getElementById('reaperturaNombre').textContent = nombre;
            var m = document.getElementById('modalReapertura');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }
        </script>

<?php elseif ($tab === 'excepciones'): ?>
        <!-- ======================== EXCEPCIONES ======================== -->
        <form method="get" class="flex flex-wrap items-end gap-3 mb-5">
            <input type="hidden" name="tab" value="excepciones">
            <input type="hidden" name="fecha" value="<?= htmlspecialchars($fecha) ?>">
            <div>
                <label class="block text-xs text-slate-400 mb-1">Desde</label>
                <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"
                       class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-600 text-slate-100 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Hasta</label>
                <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"
                       class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-600 text-slate-100 text-sm">
            </div>
            <button class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold">Filtrar</button>
        </form>

        <div class="space-y-3">
            <?php if (empty($excepciones)): ?>
                <div class="p-10 text-center rounded-xl bg-slate-800/40 border border-slate-700/60 text-slate-400">
                    <i class="fas fa-circle-check text-3xl text-emerald-400 mb-3 block"></i>
                    No hay excepciones abiertas en el rango seleccionado.
                </div>
            <?php endif; ?>

            <?php foreach ($excepciones as $e): ?>
                <?php [$sevLabel, $sevClase] = tc_sev($e['severity']); ?>
                <div class="rounded-xl bg-slate-800/50 border <?= $sevClase ?> p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3 mb-2">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2 py-0.5 rounded text-xs font-semibold <?= $sevClase ?>"><?= $sevLabel ?></span>
                                <span class="font-semibold text-slate-100"><?= htmlspecialchars($e['title']) ?></span>
                            </div>
                            <p class="text-sm text-slate-400 mt-1">
                                <?= htmlspecialchars($e['full_name'] ?: ('usuario #' . $e['user_id'])) ?>
                                · <?= date('d/m/Y', strtotime($e['work_date'])) ?>
                                <?php if ($e['amount_dop'] !== null): ?>
                                    · <span class="text-amber-300"><?= tc_dop((float) $e['amount_dop']) ?> en juego</span>
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($e['detail'])): ?>
                                <p class="text-sm text-slate-300 mt-2"><?= htmlspecialchars($e['detail']) ?></p>
                            <?php endif; ?>
                        </div>
                        <a href="?<?= http_build_query(['fecha' => $e['work_date'], 'tab' => 'trazabilidad', 'u' => $e['user_id']]) ?>"
                           class="px-3 py-1.5 rounded-lg bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 text-xs whitespace-nowrap">
                            <i class="fas fa-fingerprint mr-1"></i>Ver expediente
                        </a>
                    </div>

                    <form method="post" class="flex flex-wrap items-end gap-2 mt-3 pt-3 border-t border-slate-700/50">
                        <input type="hidden" name="accion" value="resolver_excepcion">
                        <input type="hidden" name="excepcion_id" value="<?= (int) $e['id'] ?>">
                        <div class="flex-1 min-w-[240px]">
                            <label class="block text-xs text-slate-400 mb-1">Cómo se resolvió (obligatorio)</label>
                            <input type="text" name="nota" required
                                   class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-600 text-slate-100 text-sm"
                                   placeholder="Ej.: se corrigió la salida con el reporte del supervisor">
                        </div>
                        <button name="modo" value="resolver"
                                class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold">
                            Resolver
                        </button>
                        <button name="modo" value="descartar"
                                class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-200 text-sm">
                            Descartar
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

<?php else: ?>
        <!-- ======================= TRAZABILIDAD ======================= -->
        <?php if (!$traza): ?>
            <div class="rounded-xl bg-slate-800/40 border border-slate-700/60 p-5">
                <p class="text-slate-300 mb-4 text-sm">Elige un colaborador para ver su expediente del <?= date('d/m/Y', strtotime($fecha)) ?>:</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($filas as $r): ?>
                        <a href="?<?= http_build_query(['fecha' => $fecha, 'tab' => 'trazabilidad', 'u' => $r['user_id']]) ?>"
                           class="px-3 py-2 rounded-lg bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 text-sm">
                            <?= htmlspecialchars($r['full_name']) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($filas)): ?>
                        <span class="text-slate-500 text-sm">No hay marcaciones en esta fecha.</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <?php $nombreTraza = timesheetUserName($pdo, $trazaUserId); ?>
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-bold text-slate-100">
                    Expediente de <?= htmlspecialchars($nombreTraza) ?>
                    <span class="text-slate-400 font-normal text-base">· <?= date('d/m/Y', strtotime($fecha)) ?></span>
                </h2>
                <a href="?<?= http_build_query(['fecha' => $fecha, 'tab' => 'trazabilidad']) ?>"
                   class="px-3 py-1.5 rounded-lg bg-slate-700/60 text-slate-200 text-sm">Cambiar colaborador</a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                <!-- Ponche original vs actual -->
                <div class="rounded-xl bg-slate-800/40 border border-slate-700/60 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700/60 flex items-center justify-between">
                        <h3 class="font-semibold text-slate-100 text-sm">
                            <i class="fas fa-stamp text-indigo-400 mr-2"></i>Ponche original
                        </h3>
                        <span class="text-xs text-slate-500">tal como se marcó</span>
                    </div>
                    <div class="p-4 space-y-1 text-sm">
                        <?php if (empty($traza['original'])): ?>
                            <p class="text-slate-500 text-xs">Sin copia original (día anterior a la instalación del control).</p>
                        <?php endif; ?>
                        <?php foreach ($traza['original'] as $o): ?>
                            <?php
                                $actual = null;
                                foreach ($traza['current'] as $c) {
                                    if ((int) $c['id'] === (int) $o['attendance_id']) { $actual = $c; break; }
                                }
                                $cambio = $actual && ($actual['type'] !== $o['original_type']
                                                   || $actual['timestamp'] !== $o['original_timestamp']);
                            ?>
                            <div class="flex items-center justify-between gap-3 py-1 border-b border-slate-700/30 last:border-0">
                                <span class="font-mono text-slate-300">
                                    <?= date('H:i:s', strtotime($o['original_timestamp'])) ?>
                                    <span class="text-slate-500"><?= htmlspecialchars($o['original_type']) ?></span>
                                </span>
                                <?php if ($actual === null): ?>
                                    <span class="text-xs text-red-300"><i class="fas fa-trash mr-1"></i>eliminado</span>
                                <?php elseif ($cambio): ?>
                                    <span class="text-xs text-amber-300 font-mono">
                                        → <?= date('H:i:s', strtotime($actual['timestamp'])) ?>
                                        <?= htmlspecialchars($actual['type']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-xs text-emerald-400"><i class="fas fa-check"></i> intacto</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Comentarios -->
                <div class="rounded-xl bg-slate-800/40 border border-slate-700/60 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700/60 flex items-center justify-between">
                        <h3 class="font-semibold text-slate-100 text-sm">
                            <i class="fas fa-comments text-indigo-400 mr-2"></i>Comentarios
                        </h3>
                        <span class="text-xs text-slate-500">no se pueden editar ni borrar</span>
                    </div>
                    <div class="p-4 space-y-3">
                        <?php if (empty($traza['comments'])): ?>
                            <p class="text-slate-500 text-xs">Sin comentarios.</p>
                        <?php endif; ?>
                        <?php foreach ($traza['comments'] as $c): ?>
                            <div class="text-sm border-l-2 border-indigo-500/50 pl-3">
                                <p class="text-slate-200"><?= nl2br(htmlspecialchars($c['comment'])) ?></p>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    <?= htmlspecialchars($c['author'] ?: 'sistema') ?> ·
                                    <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?>
                                </p>
                            </div>
                        <?php endforeach; ?>

                        <form method="post" class="pt-2 border-t border-slate-700/50 space-y-2">
                            <input type="hidden" name="accion" value="comentar">
                            <input type="hidden" name="user_id" value="<?= (int) $trazaUserId ?>">
                            <textarea name="comentario" rows="2" required
                                      class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-600 text-slate-100 text-sm"
                                      placeholder="Agregar un comentario al expediente…"></textarea>
                            <button class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold">
                                Agregar comentario
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Ajustes -->
            <div class="rounded-xl bg-slate-800/40 border border-slate-700/60 overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-slate-700/60">
                    <h3 class="font-semibold text-slate-100 text-sm">
                        <i class="fas fa-pen-to-square text-amber-400 mr-2"></i>Ajustes registrados
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-900/50 text-slate-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="text-left px-5 py-2">Cuándo</th>
                                <th class="text-left px-5 py-2">Quién</th>
                                <th class="text-left px-5 py-2">Qué cambió</th>
                                <th class="text-left px-5 py-2">Por qué</th>
                                <th class="text-right px-5 py-2">Horas</th>
                                <th class="text-right px-5 py-2">Impacto</th>
                                <th class="text-center px-5 py-2">Etapa</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700/40">
                        <?php if (empty($traza['audit'])): ?>
                            <tr><td colspan="7" class="px-5 py-6 text-center text-slate-500">Sin ajustes: el día está tal como se ponchó.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($traza['audit'] as $a): ?>
                            <?php $delta = (int) $a['new_work_seconds'] - (int) $a['old_work_seconds']; ?>
                            <tr>
                                <td class="px-5 py-2 text-slate-400 text-xs whitespace-nowrap"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
                                <td class="px-5 py-2 text-slate-200"><?= htmlspecialchars($a['actor'] ?: '—') ?></td>
                                <td class="px-5 py-2 text-xs font-mono text-slate-300">
                                    <?= htmlspecialchars((string) $a['action']) ?>:
                                    <?= $a['old_timestamp'] ? date('H:i', strtotime($a['old_timestamp'])) . ' ' . htmlspecialchars((string) $a['old_type']) : '—' ?>
                                    →
                                    <?= $a['new_timestamp'] ? date('H:i', strtotime($a['new_timestamp'])) . ' ' . htmlspecialchars((string) $a['new_type']) : '—' ?>
                                </td>
                                <td class="px-5 py-2 text-slate-300 text-xs max-w-xs"><?= htmlspecialchars((string) ($a['reason'] ?: 'sin motivo')) ?></td>
                                <td class="px-5 py-2 text-right font-mono <?= $delta > 0 ? 'text-amber-300' : ($delta < 0 ? 'text-blue-300' : 'text-slate-400') ?>">
                                    <?= $delta >= 0 ? '+' : '−' ?><?= attendanceAuditFormatSeconds(abs($delta)) ?>
                                </td>
                                <td class="px-5 py-2 text-right font-mono text-slate-200">
                                    <?= isset($a['impact_amount']) && $a['impact_amount'] !== null ? tc_dop((float) $a['impact_amount']) : '—' ?>
                                </td>
                                <td class="px-5 py-2 text-center text-xs">
                                    <?php if (!empty($a['was_after_close'])): ?>
                                        <span class="px-2 py-0.5 rounded bg-red-600/30 text-red-200">tras el cierre</span>
                                    <?php elseif (!empty($a['was_outside_window'])): ?>
                                        <span class="px-2 py-0.5 rounded bg-amber-600/30 text-amber-200">fuera de ventana</span>
                                    <?php else: ?>
                                        <span class="text-slate-500"><?= htmlspecialchars((string) ($a['stage_at_change'] ?? '—')) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Eliminados + eventos de etapa -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="rounded-xl bg-slate-800/40 border border-slate-700/60 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700/60">
                        <h3 class="font-semibold text-slate-100 text-sm">
                            <i class="fas fa-box-archive text-red-400 mr-2"></i>Marcaciones eliminadas
                        </h3>
                    </div>
                    <div class="p-4 space-y-2 text-sm">
                        <?php if (empty($traza['voided'])): ?>
                            <p class="text-slate-500 text-xs">Ninguna.</p>
                        <?php endif; ?>
                        <?php foreach ($traza['voided'] as $v): ?>
                            <div class="border-l-2 border-red-500/50 pl-3">
                                <p class="font-mono text-slate-300 text-xs">
                                    <?= date('H:i:s', strtotime($v['timestamp'])) ?> <?= htmlspecialchars($v['type']) ?>
                                </p>
                                <p class="text-slate-300 text-xs"><?= htmlspecialchars($v['reason']) ?></p>
                                <p class="text-xs text-slate-500">
                                    <?= htmlspecialchars($v['actor'] ?: 'sistema') ?> · <?= date('d/m/Y H:i', strtotime($v['voided_at'])) ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="rounded-xl bg-slate-800/40 border border-slate-700/60 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700/60">
                        <h3 class="font-semibold text-slate-100 text-sm">
                            <i class="fas fa-timeline text-indigo-400 mr-2"></i>Etapas del día
                        </h3>
                    </div>
                    <div class="p-4 space-y-2 text-sm">
                        <?php if (empty($traza['stages'])): ?>
                            <p class="text-slate-500 text-xs">Sin eventos de etapa.</p>
                        <?php endif; ?>
                        <?php foreach ($traza['stages'] as $s): ?>
                            <div class="border-l-2 border-indigo-500/50 pl-3">
                                <p class="text-slate-200 text-xs">
                                    <?= htmlspecialchars((string) ($s['from_stage'] ?: '—')) ?>
                                    → <strong><?= htmlspecialchars((string) $s['to_stage']) ?></strong>
                                    <?php if ($s['amount_dop'] !== null): ?>
                                        <span class="text-amber-300 ml-1"><?= tc_dop((float) $s['amount_dop']) ?></span>
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($s['reason'])): ?>
                                    <p class="text-slate-400 text-xs"><?= htmlspecialchars($s['reason']) ?></p>
                                <?php endif; ?>
                                <p class="text-xs text-slate-500">
                                    <?= htmlspecialchars((string) ($s['performed_by_name'] ?: 'sistema')) ?> ·
                                    <?= date('d/m/Y H:i', strtotime($s['created_at'])) ?>
                                    <?php if (!empty($s['authorization_code_id'])): ?>
                                        · código #<?= (int) $s['authorization_code_id'] ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
<?php endif; ?>

    </div>

    <?php include '../footer.php'; ?>
</body>
</html>
