<?php
/**
 * Autorizaciones de Gerencia — horas fuera de la ventana de ajuste.
 *
 * Esta pantalla es solo del CEO (los usuarios listados en el ajuste
 * `timesheet_override_issuers`). Aquí ve lo que nómina no puede corregir sola,
 * con el detalle que necesita para decidir —agente, día, horas registradas y
 * cuánto dinero mueve— y emite un código de UN SOLO USO.
 *
 * El código semanal no abre estos días: ese es todo el punto.
 */

session_start();
require_once '../db.php';
require_once '../lib/timesheet_override.php';
require_once '../lib/timesheet_control.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];
$puedeEmitir   = timesheetOverrideCanIssue($pdo, $currentUserId);

if (!$puedeEmitir) {
    // No es un "no tienes permiso" genérico: se le dice a quién pedírselo.
    $emisores = timesheetOverrideIssuers($pdo);
    $nombres  = array_map(static fn($e) => $e['full_name'] ?: $e['username'], $emisores);
    $_SESSION['punch_error'] = 'Las autorizaciones de horas las emite '
        . (empty($nombres) ? 'Gerencia' : implode(' o ', $nombres)) . '.';
    header('Location: payroll_hours.php');
    exit;
}

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

function auth_hms(?int $seconds): string
{
    if ($seconds === null) {
        return '—';
    }
    $seconds = max(0, $seconds);
    return sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}

// ---------------------------------------------------------------------------
// Acciones (PRG)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['action'] ?? '';
    $ok = []; $err = []; $codigoEmitido = null;

    if ($accion === 'approve') {
        $res = timesheetOverrideApprove($pdo, (int) ($_POST['request_id'] ?? 0), [
            'performed_by' => $currentUserId,
            'note'         => $_POST['note'] ?? '',
            'minutes'      => $_POST['minutes'] ?? null,
            'max_uses'     => $_POST['max_uses'] ?? null,
        ]);
        if ($res['ok']) {
            $ok[] = $res['message'];
            $codigoEmitido = ['code' => $res['code'], 'valid_until' => $res['valid_until']];
        } else {
            $err[] = $res['message'];
        }

    } elseif ($accion === 'reject') {
        $res = timesheetOverrideReject($pdo, (int) ($_POST['request_id'] ?? 0), (string) ($_POST['note'] ?? ''), $currentUserId);
        $res['ok'] ? $ok[] = $res['message'] : $err[] = $res['message'];

    } elseif ($accion === 'issue_direct') {
        // Emisión sin solicitud previa: el CEO decide autorizar por su cuenta.
        $motivo = trim((string) ($_POST['label'] ?? ''));
        if ($motivo === '') {
            $err[] = 'Escribe para quién o para qué es el código: es lo que queda en la bitácora.';
        } else {
            $res = timesheetOverrideCreateCode($pdo, [
                'issued_by' => $currentUserId,
                'label'     => $motivo,
                'minutes'   => $_POST['minutes'] ?? null,
                'max_uses'  => $_POST['max_uses'] ?? null,
            ]);
            if (!empty($res['success'])) {
                $ok[] = $res['message'];
                $codigoEmitido = ['code' => $res['code'], 'valid_until' => $res['valid_until']];
            } else {
                $err[] = $res['message'];
            }
        }
    }

    $_SESSION['auth_flash'] = ['ok' => $ok, 'err' => $err, 'code' => $codigoEmitido];
    header('Location: authorizations.php');
    exit;
}

$flash = $_SESSION['auth_flash'] ?? ['ok' => [], 'err' => [], 'code' => null];
unset($_SESSION['auth_flash']);

$pendientes = timesheetOverrideList($pdo, ['status' => 'PENDING', 'limit' => 100]);
$historial  = timesheetOverrideList($pdo, ['limit' => 60]);
$historial  = array_values(array_filter($historial, static fn($r) => $r['status'] !== 'PENDING'));

$ttlDefault = (int) getSystemSetting($pdo, 'timesheet_override_ttl_minutes', 240);
$usosDefault = (int) getSystemSetting($pdo, 'timesheet_override_max_uses', 1);
$modoActivo = timesheetOverrideEnabled($pdo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autorizaciones de Gerencia</title>
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
                    <i class="fas fa-user-shield text-amber-400 mr-3"></i>Autorizaciones de Gerencia
                </h1>
                <p class="text-slate-400 text-sm max-w-3xl">
                    Corregir una hora que ya salió de la ventana de ajuste mueve dinero que iba camino
                    a la nómina. Solo tú puedes autorizarlo, y cada código que emites aquí sirve
                    <strong>una sola vez</strong>. El código semanal no abre estos días.
                </p>
            </div>
            <a href="payroll_hours.php" class="px-4 py-2 rounded-lg bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 text-sm">
                <i class="fas fa-arrow-left mr-2"></i>Ajuste de Horas
            </a>
        </div>

        <?php if (!$modoActivo): ?>
            <div class="mb-6 rounded-xl border border-slate-600 bg-slate-700/30 p-4 text-slate-300 text-sm">
                <i class="fas fa-circle-info mr-2"></i>
                El modo "solo Gerencia" está <strong>apagado</strong>: ahora mismo el candado también
                acepta el código semanal. Se enciende en Configuración → Control de Horas y Nómina.
            </div>
        <?php endif; ?>

        <?php foreach ($flash['err'] as $m): ?>
            <div class="mb-3 px-4 py-3 rounded-lg bg-red-500/15 border border-red-500/30 text-red-200 text-sm">
                <i class="fas fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($m) ?>
            </div>
        <?php endforeach; ?>
        <?php foreach ($flash['ok'] as $m): ?>
            <div class="mb-3 px-4 py-3 rounded-lg bg-green-500/15 border border-green-500/30 text-green-200 text-sm">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($m) ?>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($flash['code'])): ?>
            <!-- El código en claro se muestra UNA vez, aquí. Después solo queda el rastro. -->
            <div class="mb-6 rounded-xl border-2 border-amber-400 bg-amber-500/10 p-6 text-center">
                <div class="text-amber-200 text-sm uppercase tracking-widest mb-2">Código emitido</div>
                <div class="text-5xl font-mono font-bold text-amber-100 tracking-[0.3em] mb-3" id="codeOut"><?= htmlspecialchars($flash['code']['code']) ?></div>
                <p class="text-amber-100/80 text-sm">
                    Vence el <strong><?= date('d/m/Y \a \l\a\s H:i', strtotime($flash['code']['valid_until'])) ?></strong>.
                    Pásaselo a quien lo pidió; ya le llegó también por la campana del sistema.
                </p>
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('codeOut').textContent.trim());this.textContent='Copiado';"
                        class="mt-4 px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-400 text-slate-900 text-sm font-semibold">
                    <i class="fas fa-copy mr-2"></i>Copiar código
                </button>
            </div>
        <?php endif; ?>

        <!-- Pendientes -->
        <div class="bg-slate-800/40 border border-slate-700/50 rounded-xl mb-8">
            <div class="px-5 py-4 border-b border-slate-700/50 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-white">
                    <i class="fas fa-hourglass-half text-amber-400 mr-2"></i>Solicitudes pendientes
                </h2>
                <span class="px-3 py-1 rounded-full bg-amber-500/20 text-amber-200 text-xs font-semibold">
                    <?= count($pendientes) ?>
                </span>
            </div>

            <?php if (empty($pendientes)): ?>
                <div class="px-5 py-12 text-center text-slate-500">
                    <i class="fas fa-check-circle text-4xl mb-3 opacity-30"></i>
                    <p>No hay nada esperando por ti.</p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-700/40">
                    <?php foreach ($pendientes as $r): ?>
                        <div class="p-5" id="req-<?= (int) $r['id'] ?>">
                            <div class="flex flex-wrap gap-6 justify-between">
                                <div class="flex-1 min-w-[280px]">
                                    <div class="text-white font-semibold text-lg">
                                        <?= htmlspecialchars($r['target_name'] ?? '—') ?>
                                        <span class="text-slate-400 font-normal text-base">
                                            · <?= date('d/m/Y', strtotime($r['work_date'])) ?>
                                        </span>
                                    </div>
                                    <div class="text-xs text-slate-500 mb-3">
                                        Lo pide <?= htmlspecialchars($r['requester_name'] ?? '—') ?>
                                        · <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?>
                                        · fuente: <?= $r['source'] === 'ponche' ? 'ponche' : 'Vicidial' ?>
                                    </div>
                                    <div class="text-slate-300 text-sm bg-slate-900/50 rounded-lg p-3 border-l-2 border-amber-500/50">
                                        <?= nl2br(htmlspecialchars($r['reason'])) ?>
                                    </div>
                                </div>

                                <div class="flex gap-6 text-sm">
                                    <div>
                                        <div class="text-xs text-slate-500 mb-1">Horas hoy</div>
                                        <div class="text-xl font-semibold text-slate-200">
                                            <?= auth_hms($r['current_seconds'] !== null ? (int) $r['current_seconds'] : null) ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-slate-500 mb-1">Impacto estimado</div>
                                        <div class="text-xl font-semibold <?= (float) $r['amount_dop'] < 0 ? 'text-rose-300' : 'text-emerald-300' ?>">
                                            <?= $r['amount_dop'] === null ? '—' : 'RD$ ' . number_format((float) $r['amount_dop'], 2) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-3 mt-4 pt-4 border-t border-slate-700/40">
                                <form method="post" class="flex flex-wrap items-end gap-2">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                                    <div>
                                        <label class="block text-xs text-slate-500 mb-1">Vigencia (min)</label>
                                        <input type="number" name="minutes" min="5" max="10080" value="<?= $ttlDefault ?>"
                                               class="w-24 px-2 py-2 rounded bg-slate-900/60 border border-slate-700 text-slate-100 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-slate-500 mb-1">Usos</label>
                                        <input type="number" name="max_uses" min="1" max="50" value="<?= $usosDefault ?>"
                                               class="w-20 px-2 py-2 rounded bg-slate-900/60 border border-slate-700 text-slate-100 text-sm">
                                    </div>
                                    <button class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold">
                                        <i class="fas fa-key mr-2"></i>Emitir código
                                    </button>
                                </form>

                                <form method="post" class="flex flex-wrap items-end gap-2"
                                      onsubmit="return this.note.value.trim() !== '' || (alert('Escribe por qué no se autoriza.'), false);">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                                    <div>
                                        <label class="block text-xs text-slate-500 mb-1">Motivo del rechazo</label>
                                        <input type="text" name="note" maxlength="500" placeholder="Se lo verá quien lo pidió"
                                               class="w-64 px-3 py-2 rounded bg-slate-900/60 border border-slate-700 text-slate-100 text-sm">
                                    </div>
                                    <button class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-rose-600 text-slate-200 text-sm">
                                        <i class="fas fa-ban mr-2"></i>No autorizar
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Emisión directa -->
        <div class="bg-slate-800/40 border border-slate-700/50 rounded-xl p-5 mb-8">
            <h2 class="text-lg font-semibold text-white mb-1">
                <i class="fas fa-bolt text-indigo-400 mr-2"></i>Emitir un código sin solicitud
            </h2>
            <p class="text-slate-400 text-sm mb-4">
                Para cuando te lo piden por teléfono. Queda igual de registrado.
            </p>
            <form method="post" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="action" value="issue_direct">
                <div class="flex-1 min-w-[260px]">
                    <label class="block text-xs text-slate-400 mb-1">Para quién / para qué</label>
                    <input type="text" name="label" maxlength="80" required
                           placeholder="Ej. Marcela · horas de Yeimy del 13/08"
                           class="w-full px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700 text-slate-100 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Vigencia (min)</label>
                    <input type="number" name="minutes" min="5" max="10080" value="<?= $ttlDefault ?>"
                           class="w-28 px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700 text-slate-100 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Usos</label>
                    <input type="number" name="max_uses" min="1" max="50" value="<?= $usosDefault ?>"
                           class="w-20 px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700 text-slate-100 text-sm">
                </div>
                <button class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold">
                    <i class="fas fa-key mr-2"></i>Emitir
                </button>
            </form>
        </div>

        <!-- Historial -->
        <div class="bg-slate-800/40 border border-slate-700/50 rounded-xl">
            <div class="px-5 py-4 border-b border-slate-700/50">
                <h2 class="text-lg font-semibold text-white">
                    <i class="fas fa-clock-rotate-left text-slate-400 mr-2"></i>Resueltas
                </h2>
            </div>
            <?php if (empty($historial)): ?>
                <div class="px-5 py-10 text-center text-slate-500">Todavía no hay historial.</div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-700/60">
                                <th class="px-4 py-3">Día</th>
                                <th class="px-4 py-3">Colaborador</th>
                                <th class="px-4 py-3">Lo pidió</th>
                                <th class="px-4 py-3">Estado</th>
                                <th class="px-4 py-3">Código</th>
                                <th class="px-4 py-3">Resuelta</th>
                                <th class="px-4 py-3">Nota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historial as $r):
                                $badge = [
                                    'APPROVED' => ['bg-amber-500/20 text-amber-200', 'Emitida, sin usar'],
                                    'USED'     => ['bg-emerald-500/20 text-emerald-200', 'Usada'],
                                    'REJECTED' => ['bg-rose-500/20 text-rose-200', 'No autorizada'],
                                ][$r['status']] ?? ['bg-slate-600/30 text-slate-300', $r['status']];
                            ?>
                                <tr class="border-b border-slate-800/60">
                                    <td class="px-4 py-2 text-slate-300 whitespace-nowrap"><?= date('d/m/Y', strtotime($r['work_date'])) ?></td>
                                    <td class="px-4 py-2 text-slate-200"><?= htmlspecialchars($r['target_name'] ?? '—') ?></td>
                                    <td class="px-4 py-2 text-slate-400"><?= htmlspecialchars($r['requester_name'] ?? '—') ?></td>
                                    <td class="px-4 py-2">
                                        <span class="px-2 py-1 rounded text-xs <?= $badge[0] ?>"><?= $badge[1] ?></span>
                                    </td>
                                    <td class="px-4 py-2 font-mono text-xs text-slate-400">
                                        <?php if (!empty($r['issued_code'])): ?>
                                            <?= htmlspecialchars(substr($r['issued_code'], 0, 3)) ?>•••••
                                            <span class="block text-slate-600"><?= (int) $r['current_uses'] ?>/<?= $r['max_uses'] === null ? '∞' : (int) $r['max_uses'] ?> usos</span>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td class="px-4 py-2 text-slate-400 text-xs">
                                        <?= htmlspecialchars($r['resolver_name'] ?? '—') ?>
                                        <span class="block text-slate-600"><?= $r['resolved_at'] ? date('d/m/Y H:i', strtotime($r['resolved_at'])) : '' ?></span>
                                    </td>
                                    <td class="px-4 py-2 text-slate-400 text-xs max-w-xs"><?= htmlspecialchars($r['resolution_note'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
