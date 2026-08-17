<?php
/**
 * Ajuste de horas pagables (Vicidial) — Gestión de Desempeño.
 *
 * Edita la tabla intermedia `vicidial_payroll_adjustments`. NUNCA toca
 * `vicidial_agent_timesheet` (la fuente cruda de Vicidial queda intacta y
 * siempre se puede comparar el ajuste contra el original).
 *
 * El ajuste se aplica en vicidialGetPaidSecondsByDate(), que consumen tanto la
 * nómina como el portal del agente, así que lo que se corrija aquí se refleja
 * en ambos sin ningún paso adicional.
 */

session_start();
require_once '../db.php';
require_once 'payroll_functions.php';
require_once '../lib/vicidial_api_client.php';
require_once '../lib/logging_functions.php';

ensurePermission('payroll_hours_adjust', '../unauthorized.php');

date_default_timezone_set('America/Santo_Domingo');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$currentUserId = (int) $_SESSION['user_id'];

/** "8:30" | "8.5" | "08:30:00" -> segundos. Devuelve null si no es válido. */
function ph_parseHoursToSeconds(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2}):([0-5]?\d)(?::([0-5]?\d))?$/', $raw, $m)) {
        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + ((int) ($m[3] ?? 0));
    }
    if (preg_match('/^\d{1,2}([.,]\d{1,2})?$/', $raw)) {
        return (int) round((float) str_replace(',', '.', $raw) * 3600);
    }
    return null;
}

function ph_hms(int $seconds): string
{
    $seconds = max(0, $seconds);
    return sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}

/**
 * Texto normalizado sobre el que busca el filtro en vivo: minúsculas y sin
 * acentos, para que "nunez" encuentre a "Núñez" y "garcia" a "García".
 *
 * NO se usa vicidialNormalizeName(): ese pasa por iconv //TRANSLIT, que en
 * Windows convierte "ú" en "'u" y termina partiendo el apellido en "n u nez".
 * Aquí se mapean los acentos a mano y se conservan guiones y guiones bajos,
 * que hacen falta para buscar por fecha (2026-07-08) y por cuenta (Auril_Gzm26).
 * El JS del buscador normaliza igual (NFD + quitar marcas combinantes).
 */
function ph_searchKey(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ]);
    return preg_replace('/\s+/', ' ', $s);
}

// ---------------------------------------------------------------------------
// Acciones (PRG)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ok = [];
    $err = [];

    $uid = (int) ($_POST['user_id'] ?? 0);
    $workDate = trim($_POST['work_date'] ?? '');
    $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) === 1;

    if ($action === 'request_override' && $uid > 0 && $validDate) {
        // Nómina no puede abrir el día sola: se lo pide a Gerencia y queda escrito.
        if (file_exists(__DIR__ . '/../lib/timesheet_override.php')) {
            require_once __DIR__ . '/../lib/timesheet_override.php';
        }
        if (!function_exists('timesheetOverrideRequestCreate')) {
            $res = ['ok' => false, 'message' => 'Falta subir lib/timesheet_override.php al servidor.'];
        } else {
            $res = timesheetOverrideRequestCreate($pdo, [
                'requested_by'     => $currentUserId,
                'target_user_id'   => $uid,
                'work_date'        => $workDate,
                'source'           => 'vicidial',
                'reason'           => (string) ($_POST['request_reason'] ?? ''),
                'proposed_seconds' => ph_parseHoursToSeconds((string) ($_POST['proposed_hours'] ?? '')),
            ]);
        }
        $res['ok'] ? $ok[] = $res['message'] : $err[] = $res['message'];

    } elseif ($action === 'save_adjustment' && $uid > 0 && $validDate) {
        $seconds = ph_parseHoursToSeconds((string) ($_POST['hours'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $original = max(0, (int) ($_POST['original_seconds'] ?? 0));

        if ($seconds === null) {
            $err[] = 'Horas inválidas. Usa formato 8:30 o 8.5.';
        } elseif ($seconds > 24 * 3600) {
            $err[] = 'Las horas de un día no pueden superar 24:00.';
        } elseif ($reason === '') {
            $err[] = 'El motivo es obligatorio: queda en la bitácora de auditoría.';
        } else {
            // Candado del procedimiento de horas. Ajustar los segundos pagables de
            // Vicidial mueve dinero igual que editar un punch, así que pasa por la
            // misma puerta: día cerrado, período bloqueado o ventana vencida.
            require_once __DIR__ . '/../lib/timesheet_control.php';
            $guard = timesheetGuard($pdo, $uid, $workDate, [
                'context'   => 'edit_records',
                'auth_code' => trim((string) ($_POST['authorization_code'] ?? '')),
                'reason'    => $reason,
            ]);
        }

        if (empty($err) && !$guard['allowed']) {
            $err[] = $guard['message'];
        }

        if (empty($err)) {
            try {
                $antesSeconds = timesheetDayWorkSeconds($pdo, $uid, $workDate);

                $prev = $pdo->prepare("SELECT adjusted_seconds FROM vicidial_payroll_adjustments WHERE user_id = ? AND work_date = ?");
                $prev->execute([$uid, $workDate]);
                $oldSeconds = $prev->fetchColumn();
                $oldSeconds = $oldSeconds === false ? null : (int) $oldSeconds;

                $stmt = $pdo->prepare("
                    INSERT INTO vicidial_payroll_adjustments
                        (user_id, work_date, adjusted_seconds, original_seconds, reason, adjusted_by)
                    VALUES (:uid, :wd, :sec, :orig, :reason, :by)
                    ON DUPLICATE KEY UPDATE
                        adjusted_seconds = VALUES(adjusted_seconds),
                        original_seconds = VALUES(original_seconds),
                        reason           = VALUES(reason),
                        adjusted_by      = VALUES(adjusted_by)
                ");
                $stmt->execute([
                    ':uid' => $uid, ':wd' => $workDate, ':sec' => $seconds,
                    ':orig' => $original, ':reason' => $reason, ':by' => $currentUserId,
                ]);

                $log = $pdo->prepare("
                    INSERT INTO vicidial_payroll_adjustment_log
                        (user_id, work_date, action, old_seconds, new_seconds, original_seconds, reason, performed_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $log->execute([
                    $uid, $workDate, $oldSeconds === null ? 'create' : 'update',
                    $oldSeconds, $seconds, $original, $reason, $currentUserId,
                ]);

                // El ajuste entra al mismo expediente que los del ponche: etapa,
                // impacto en RD$, excepciones y alerta si toca dinero ya cerrado.
                timesheetAfterChange($pdo, $uid, $workDate, $guard, [
                    'reason'          => $reason,
                    'source'          => 'ajuste vicidial',
                    'reference_table' => 'vicidial_payroll_adjustments',
                    'old_seconds'     => $antesSeconds,
                    'new_seconds'     => timesheetDayWorkSeconds($pdo, $uid, $workDate),
                ]);

                $ok[] = 'Horas de ' . $workDate . ' guardadas: ' . ph_hms($seconds)
                      . ' (Vicidial reportó ' . ph_hms($original) . ').';
            } catch (Throwable $e) {
                $err[] = 'No se pudo guardar el ajuste: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_adjustment' && $uid > 0 && $validDate) {
        require_once __DIR__ . '/../lib/timesheet_control.php';
        $guard = timesheetGuard($pdo, $uid, $workDate, [
            'context'   => 'delete_records',
            'auth_code' => trim((string) ($_POST['authorization_code'] ?? '')),
            'reason'    => 'Eliminación del ajuste de horas de Vicidial',
        ]);

        if (!$guard['allowed']) {
            $err[] = $guard['message'];
        } else try {
            $antesSeconds = timesheetDayWorkSeconds($pdo, $uid, $workDate);

            $prev = $pdo->prepare("SELECT adjusted_seconds, original_seconds FROM vicidial_payroll_adjustments WHERE user_id = ? AND work_date = ?");
            $prev->execute([$uid, $workDate]);
            $row = $prev->fetch(PDO::FETCH_ASSOC);

            $del = $pdo->prepare("DELETE FROM vicidial_payroll_adjustments WHERE user_id = ? AND work_date = ?");
            $del->execute([$uid, $workDate]);

            if ($row) {
                $log = $pdo->prepare("
                    INSERT INTO vicidial_payroll_adjustment_log
                        (user_id, work_date, action, old_seconds, new_seconds, original_seconds, reason, performed_by)
                    VALUES (?, ?, 'delete', ?, NULL, ?, NULL, ?)
                ");
                $log->execute([$uid, $workDate, (int) $row['adjusted_seconds'], (int) $row['original_seconds'], $currentUserId]);
            }

            timesheetAfterChange($pdo, $uid, $workDate, $guard, [
                'reason'          => 'Eliminación del ajuste de horas de Vicidial',
                'source'          => 'ajuste vicidial',
                'reference_table' => 'vicidial_payroll_adjustments',
                'old_seconds'     => $antesSeconds,
                'new_seconds'     => timesheetDayWorkSeconds($pdo, $uid, $workDate),
            ]);

            $ok[] = 'Ajuste de ' . $workDate . ' eliminado. Vuelve a mandar el dato de Vicidial.';
        } catch (Throwable $e) {
            $err[] = 'No se pudo eliminar el ajuste: ' . $e->getMessage();
        }
    }

    $_SESSION['ph_flash'] = ['ok' => $ok, 'err' => $err];
    header('Location: payroll_hours.php?' . http_build_query([
        'start' => $_POST['start'] ?? '',
        'end'   => $_POST['end'] ?? '',
        'agent' => $_POST['agent'] ?? '',
    ]));
    exit;
}

$flash = $_SESSION['ph_flash'] ?? ['ok' => [], 'err' => []];
unset($_SESSION['ph_flash']);

// ---------------------------------------------------------------------------
// Fuente que se está viendo: el ajuste de Vicidial (lo que ya existía) o el
// historial de modificaciones del ponche (nuevo). El cliente pidió que ambos
// se vean igual, así que comparten página.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../lib/attendance_audit.php';

$fuente = ($_GET['fuente'] ?? 'vicidial') === 'ponche' ? 'ponche' : 'vicidial';

$poncheDesde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$poncheHasta = $_GET['hasta'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $poncheDesde)) { $poncheDesde = date('Y-m-d', strtotime('-30 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $poncheHasta)) { $poncheHasta = date('Y-m-d'); }
if ($poncheHasta < $poncheDesde) { $poncheHasta = $poncheDesde; }

// Por defecto se muestran solo los cambios que MOVIERON las horas pagadas:
// es lo que le importa a nómina. El resto queda a un clic.
$poncheSoloHoras = !isset($_GET['fuente']) || isset($_GET['solo_horas']);

$poncheAudit = [];
$ponchePendientes = 0;
try {
    $ponchePendientes = (int) $pdo->query("
        SELECT COUNT(*) FROM attendance_audit
        WHERE old_work_seconds <> new_work_seconds
          AND work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetchColumn();

    if ($fuente === 'ponche') {
        $poncheAudit = attendanceAuditList($pdo, [
            'from'              => $poncheDesde,
            'to'                => $poncheHasta,
            'only_hour_changes' => $poncheSoloHoras,
            'limit'             => 300,
        ]);
    }
} catch (Throwable $e) {
    // La tabla puede no existir si aún no corrió run_payroll_module_migration.php
    error_log('payroll_hours (ponche): ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------------------
$today = date('Y-m-d');
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-6 days'));
$end   = $_GET['end'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { $start = date('Y-m-d', strtotime('-6 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) { $end = $today; }
if ($start > $end) { [$start, $end] = [$end, $start]; }
$agentFilter = (int) ($_GET['agent'] ?? 0);

$agents = $pdo->query("
    SELECT u.id, u.full_name
    FROM users u
    WHERE COALESCE(u.payroll_source, 'manual') = 'vicidial' AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$paidCodes = vicidialGetPaidPauseCodes($pdo);
$capSeconds = (int) round((float) getSystemSetting($pdo, 'vicidial_payroll_daily_cap_hours', 14) * 3600);
$uncodedCapSeconds = vicidialGetUncodedPauseCapSeconds($pdo);

// Días con datos de Vicidial en el rango, ya mapeados a un empleado.
$sql = "
    SELECT t.user_id, t.report_date, t.vicidial_user, t.total_logged_seconds,
           t.nonpause_seconds, t.pause_breakdown, u.full_name
    FROM vicidial_agent_timesheet t
    JOIN users u ON u.id = t.user_id
    WHERE t.report_date BETWEEN :start AND :end
      AND COALESCE(u.payroll_source, 'manual') = 'vicidial'
";
$params = [':start' => $start, ':end' => $end];
if ($agentFilter > 0) {
    $sql .= " AND t.user_id = :agent";
    $params[':agent'] = $agentFilter;
}
$sql .= " ORDER BY t.report_date DESC, u.full_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Un agente puede tener DOS cuentas de Vicidial el mismo día: se suman, igual
// que en la nómina.
$byKey = [];
foreach ($raw as $r) {
    $key = $r['user_id'] . '|' . $r['report_date'];
    $codes = $r['pause_breakdown'] ? json_decode($r['pause_breakdown'], true) : [];
    if (!is_array($codes)) { $codes = []; }

    if (!isset($byKey[$key])) {
        $byKey[$key] = [
            'user_id' => (int) $r['user_id'], 'full_name' => $r['full_name'],
            'report_date' => $r['report_date'], 'accounts' => [],
            'logged' => 0, 'nonpause' => 0, 'codes' => [],
        ];
    }
    $byKey[$key]['accounts'][] = $r['vicidial_user'];
    $byKey[$key]['logged'] += (int) $r['total_logged_seconds'];
    $byKey[$key]['nonpause'] += (int) $r['nonpause_seconds'];
    foreach ($codes as $c => $s) {
        $byKey[$key]['codes'][$c] = ($byKey[$key]['codes'][$c] ?? 0) + (int) $s;
    }
}

// Los topes (pausa sin código, cordura diaria) se aplican al TOTAL del día, ya
// sumadas las cuentas. Mismo punto de cálculo que la nómina y el portal.
foreach ($byKey as $k => $row) {
    $calc = vicidialComputeDayPaid($pdo, $row['nonpause'], $row['codes'], $capSeconds);
    $byKey[$k]['raw_paid']        = $calc['paid_seconds'];
    $byKey[$k]['capped']          = $calc['capped'];
    $byKey[$k]['uncoded_seconds'] = $calc['uncoded_seconds'];
    $byKey[$k]['uncoded_dropped'] = $calc['uncoded_dropped'];
}

// Ajustes existentes del rango.
$adjMap = [];
try {
    $aStmt = $pdo->prepare("
        SELECT a.user_id, a.work_date, a.adjusted_seconds, a.original_seconds, a.reason, a.updated_at,
               u.full_name AS by_name
        FROM vicidial_payroll_adjustments a
        LEFT JOIN users u ON u.id = a.adjusted_by
        WHERE a.work_date BETWEEN ? AND ?
    ");
    $aStmt->execute([$start, $end]);
    foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
        $adjMap[$a['user_id'] . '|' . $a['work_date']] = $a;
    }
} catch (Throwable $e) {
    $flash['err'][] = 'Falta la tabla de ajustes. Corre sql/create_vicidial_payroll_adjustments.sql.';
}

// Bitácora COMPLETA de cada día ajustado. La tabla de arriba solo guarda el
// ajuste vigente: si un día se corrige tres veces, las dos correcciones
// anteriores solo viven aquí. Antes nunca se mostraban, y por eso al hacer una
// modificación posterior se perdía de vista quién había puesto el valor previo.
$logMap = [];
try {
    $lStmt = $pdo->prepare("
        SELECT l.user_id, l.work_date, l.action, l.old_seconds, l.new_seconds,
               l.original_seconds, l.reason, l.created_at,
               COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS performed_by_name
        FROM vicidial_payroll_adjustment_log l
        LEFT JOIN users u ON u.id = l.performed_by
        WHERE l.work_date BETWEEN ? AND ?
        ORDER BY l.id ASC
    ");
    $lStmt->execute([$start, $end]);
    foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
        $logMap[$l['user_id'] . '|' . $l['work_date']][] = $l;
    }
} catch (Throwable $e) {
    // Sin bitácora la pantalla sigue funcionando; solo se pierde el historial.
    error_log('payroll_hours (bitácora vicidial): ' . $e->getMessage());
}

$rows = array_values($byKey);
$totRaw = 0; $totFinal = 0; $nAdj = 0;
foreach ($rows as $r) {
    $key = $r['user_id'] . '|' . $r['report_date'];
    $totRaw += $r['raw_paid'];
    $totFinal += isset($adjMap[$key]) ? (int) $adjMap[$key]['adjusted_seconds'] : $r['raw_paid'];
    if (isset($adjMap[$key])) { $nAdj++; }
}

// ---------------------------------------------------------------------------
// Candado del control de horas, del lado de la pantalla.
//
// El POST ya exigía código fuera de la ventana de ajuste, pero el formulario
// nunca mandaba uno: la página pedía un código que no tenía dónde escribirse y
// nómina quedaba sin poder corregir nada más viejo que ayer. Aquí se calcula
// qué días de la tabla ya vencieron (o están cerrados) para mostrar el campo y
// marcar las filas afectadas.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../lib/timesheet_control.php';

$tsControlOn   = timesheetControlEnabled($pdo);
$tsRequireCode = $tsControlOn && timesheetSetting($pdo, 'timesheet_require_code_after_window', '1') === '1';
// Gerencia no necesita código: el permiso ya la autoriza.
$tsBypass      = $tsControlOn && function_exists('userHasPermission') && userHasPermission('timesheet_adjust_outside');
$tsStartDate   = $tsControlOn ? timesheetControlStartDate($pdo) : '2000-01-01';

// Etapa real del día (CLOSED/LOCKED no se arreglan con código: hay que reabrir).
$tsDayStage = [];
if ($tsControlOn) {
    try {
        $sStmt = $pdo->prepare("
            SELECT user_id, work_date, status
            FROM timesheet_day_status
            WHERE work_date BETWEEN ? AND ?
        ");
        $sStmt->execute([$start, $end]);
        foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) {
            $tsDayStage[$d['user_id'] . '|' . $d['work_date']] = (string) $d['status'];
        }
    } catch (Throwable $e) {
        error_log('payroll_hours (etapas): ' . $e->getMessage());
    }
}

/** 'ok' | 'code' (vencido, se arregla con código) | 'closed' (hay que reabrir) */
$tsRowState = [];
$tsWindowLabels = [];
$nVencidos = 0; $nCerrados = 0;
if ($tsControlOn) {
    foreach ($rows as $r) {
        $key = $r['user_id'] . '|' . $r['report_date'];
        $fecha = $r['report_date'];
        $etapa = $tsDayStage[$key] ?? ($fecha < $tsStartDate ? 'CLOSED' : 'OPEN');

        if (in_array($etapa, ['CLOSED', 'LOCKED'], true)) {
            $tsRowState[$key] = 'closed';
            $nCerrados++;
            continue;
        }
        if (!isset($tsWindowLabels[$fecha])) {
            $tsWindowLabels[$fecha] = timesheetIsWithinWindow($pdo, $fecha)
                ? null
                : timesheetWindowLabel($pdo, $fecha);
        }
        if ($tsWindowLabels[$fecha] !== null && !$tsBypass && $tsRequireCode) {
            $tsRowState[$key] = 'code';
            $nVencidos++;
        } else {
            $tsRowState[$key] = 'ok';
        }
    }
}
$needsCode = $nVencidos > 0;

// Solicitudes de autorización vivas del rango, para que la fila diga en qué va
// (pendiente / ya aprobada) en vez de dejar a nómina pidiendo dos veces.
// El file_exists cubre un despliegue a medias: sin el módulo, la pantalla vuelve
// al comportamiento anterior en vez de tumbarse con un fatal.
if (file_exists(__DIR__ . '/../lib/timesheet_override.php')) {
    require_once __DIR__ . '/../lib/timesheet_override.php';
}
$hayOverride    = function_exists('timesheetOverrideEnabled');
$soloGerencia   = $hayOverride && timesheetOverrideEnabled($pdo);
$puedeEmitir    = $hayOverride && timesheetOverrideCanIssue($pdo, $currentUserId);
$emisores       = $hayOverride ? timesheetOverrideIssuers($pdo) : [];
$nombresEmisores = implode(' o ', array_map(
    static fn($e) => $e['full_name'] ?: $e['username'],
    $emisores
)) ?: 'Gerencia';

$solicitudes = [];
if ($soloGerencia && timesheetOverrideTableReady($pdo)) {
    try {
        $rStmt = $pdo->prepare("
            SELECT target_user_id, work_date, status, created_at
            FROM timesheet_override_requests
            WHERE work_date BETWEEN ? AND ? AND status IN ('PENDING','APPROVED')
        ");
        $rStmt->execute([$start, $end]);
        foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
            $solicitudes[$s['target_user_id'] . '|' . $s['work_date']] = $s;
        }
    } catch (Throwable $e) {
        error_log('payroll_hours (solicitudes): ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajuste de Horas Pagables - HR</title>
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
                    <i class="fas fa-user-clock text-indigo-400 mr-3"></i>Ajuste de Horas Pagables
                </h1>
                <p class="text-slate-400 text-sm">
                    Corrige las horas que se van a pagar sin alterar el dato original de Vicidial.
                    El agente ve el ajuste en su portal de inmediato.
                </p>
            </div>
            <div class="flex gap-2">
                <?php if ($puedeEmitir): ?>
                    <?php $ovPend = timesheetOverridePendingCount($pdo); ?>
                    <a href="authorizations.php" class="px-4 py-2 rounded-lg bg-amber-600/80 hover:bg-amber-500 text-white text-sm">
                        <i class="fas fa-user-shield mr-2"></i>Autorizaciones
                        <?php if ($ovPend > 0): ?>
                            <span class="ml-1 px-2 py-0.5 rounded-full bg-white/25 text-xs font-bold"><?= (int) $ovPend ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
                <a href="payroll.php" class="px-4 py-2 rounded-lg bg-slate-700/60 hover:bg-slate-600/60 text-slate-200 text-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Volver a Nómina
                </a>
            </div>
        </div>

        <!-- Selector de fuente: Vicidial (ajuste) vs Ponche (historial de cambios) -->
        <div class="flex gap-2 mb-6">
            <a href="payroll_hours.php?<?= http_build_query(array_merge($_GET, ['fuente' => 'vicidial'])) ?>"
               class="px-4 py-2 rounded-lg text-sm font-semibold <?= $fuente === 'vicidial' ? 'bg-indigo-600 text-white' : 'bg-slate-700/60 text-slate-300 hover:bg-slate-600/60' ?>">
                <i class="fas fa-phone-volume mr-2"></i>Horas de Vicidial
            </a>
            <a href="payroll_hours.php?<?= http_build_query(array_merge($_GET, ['fuente' => 'ponche'])) ?>"
               class="px-4 py-2 rounded-lg text-sm font-semibold <?= $fuente === 'ponche' ? 'bg-indigo-600 text-white' : 'bg-slate-700/60 text-slate-300 hover:bg-slate-600/60' ?>">
                <i class="fas fa-fingerprint mr-2"></i>Horas del Ponche
                <?php if ($ponchePendientes > 0): ?>
                    <span class="ml-1 px-2 py-0.5 rounded-full bg-white/20 text-xs"><?= (int) $ponchePendientes ?></span>
                <?php endif; ?>
            </a>
        </div>

        <?php if ($fuente === 'ponche'): ?>
            <div class="glass-card mb-6">
                <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
                    <div>
                        <h2 class="text-xl font-semibold text-white">
                            <i class="fas fa-clock-rotate-left text-amber-400 mr-2"></i>
                            Historial de modificaciones del ponche
                        </h2>
                        <p class="text-slate-400 text-sm mt-1">
                            Cada corrección de un punch, con las horas que tenía antes, las que quedaron
                            y quién la hizo. Mismo detalle que el ajuste de Vicidial.
                        </p>
                    </div>
                    <form method="GET" class="flex flex-wrap items-end gap-2">
                        <input type="hidden" name="fuente" value="ponche">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Desde</label>
                            <input type="date" name="desde" value="<?= htmlspecialchars($poncheDesde) ?>"
                                   class="bg-slate-800 border border-slate-700 rounded px-2 py-1 text-white text-sm">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Hasta</label>
                            <input type="date" name="hasta" value="<?= htmlspecialchars($poncheHasta) ?>"
                                   class="bg-slate-800 border border-slate-700 rounded px-2 py-1 text-white text-sm">
                        </div>
                        <label class="flex items-center gap-2 text-xs text-slate-300 pb-1">
                            <input type="checkbox" name="solo_horas" value="1" <?= $poncheSoloHoras ? 'checked' : '' ?>
                                   class="w-4 h-4">
                            Solo los que cambiaron horas
                        </label>
                        <button type="submit" class="px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-500 text-white text-sm">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                    </form>
                </div>

                <?php if (empty($poncheAudit)): ?>
                    <p class="text-slate-400 text-center py-8">
                        <i class="fas fa-circle-info mr-2"></i>
                        Sin modificaciones al ponche en el período.
                        Aquí aparecerán las correcciones que hagan los supervisores y RRHH.
                    </p>
                <?php else: ?>
                    <div class="overflow-auto">
                        <table class="w-full text-sm">
                            <thead class="text-slate-400 text-xs uppercase">
                                <tr>
                                    <th class="text-left p-2">Fecha</th>
                                    <th class="text-left p-2">Colaborador</th>
                                    <th class="text-right p-2">Original</th>
                                    <th class="text-right p-2">Modificada</th>
                                    <th class="text-right p-2">Diferencia</th>
                                    <th class="text-left p-2">Detalle del cambio</th>
                                    <th class="text-left p-2">Modificado por</th>
                                    <th class="text-left p-2">Motivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($poncheAudit as $a): ?>
                                    <tr class="border-t border-slate-700/50">
                                        <td class="p-2 text-white whitespace-nowrap"><?= date('Y-m-d', strtotime($a['work_date'])) ?></td>
                                        <td class="p-2 text-white">
                                            <?= htmlspecialchars($a['employee_name'] ?: $a['employee_username']) ?>
                                            <?php if (!empty($a['employee_code'])): ?>
                                                <span class="block text-xs text-slate-500"><?= htmlspecialchars($a['employee_code']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 text-right font-mono text-slate-300"><?= htmlspecialchars($a['old_formatted']) ?></td>
                                        <td class="p-2 text-right font-mono text-white font-semibold"><?= htmlspecialchars($a['new_formatted']) ?></td>
                                        <td class="p-2 text-right font-mono <?= $a['diff_seconds'] > 0 ? 'text-emerald-400' : ($a['diff_seconds'] < 0 ? 'text-rose-400' : 'text-slate-500') ?>">
                                            <?= htmlspecialchars($a['diff_formatted']) ?>
                                            <?php if ($a['diff_seconds'] !== 0): ?>
                                                <span class="block text-xs text-slate-500">pagadas</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 text-xs">
                                            <?php if (empty($a['state_changes'])): ?>
                                                <span class="text-slate-500">
                                                    <?= htmlspecialchars(($a['action'] === 'CREATE' ? 'Punch agregado' : ($a['action'] === 'DELETE' ? 'Punch eliminado' : 'Sin efecto en las horas'))) ?>
                                                </span>
                                            <?php else: ?>
                                                <?php foreach ($a['state_changes'] as $c): ?>
                                                    <div class="text-slate-300">
                                                        <strong><?= htmlspecialchars($c['label']) ?>:</strong>
                                                        <?= (int) round($c['old'] / 60) ?> min registrado en ponche →
                                                        <span class="<?= $c['diff'] > 0 ? 'text-emerald-300' : 'text-rose-300' ?>">
                                                            <?= (int) round($c['new'] / 60) ?> min
                                                        </span>
                                                        registrado por <?= htmlspecialchars($a['performed_by_username'] ?: 'sistema') ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($a['old_type']) || !empty($a['new_type'])): ?>
                                                <div class="text-slate-500 mt-1">
                                                    <?php if ($a['action'] === 'UPDATE' && $a['old_type'] !== $a['new_type']): ?>
                                                        Tipo: <?= htmlspecialchars($a['old_type']) ?> → <?= htmlspecialchars($a['new_type']) ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($a['old_timestamp']) && !empty($a['new_timestamp']) && $a['old_timestamp'] !== $a['new_timestamp']): ?>
                                                        Hora: <?= date('H:i', strtotime($a['old_timestamp'])) ?> → <?= date('H:i', strtotime($a['new_timestamp'])) ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 text-slate-300 text-xs">
                                            <?= htmlspecialchars($a['performed_by_name'] ?: 'Sistema') ?>
                                            <span class="block text-slate-500"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></span>
                                        </td>
                                        <td class="p-2 text-slate-400 text-xs"><?= htmlspecialchars($a['reason'] ?: '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-slate-500 mt-3">
                        <i class="fas fa-circle-info mr-1"></i>
                        Las horas se recalculan con la misma lógica que paga la nómina, antes y después de cada cambio.
                        A diferencia de Vicidial, aquí no se ajusta un total: se corrige el punch y el sistema guarda el efecto.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

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

        <?php if ($fuente === 'vicidial'): // el ajuste de Vicidial, tal cual estaba ?>

        <form method="get" class="mb-6 flex flex-wrap items-end gap-3 bg-slate-800/40 border border-slate-700/50 rounded-xl p-4">
            <input type="hidden" name="fuente" value="vicidial">
            <div>
                <label class="block text-xs text-slate-400 mb-1">Desde</label>
                <input type="date" name="start" value="<?= htmlspecialchars($start) ?>" class="px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700 text-slate-100 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Hasta</label>
                <input type="date" name="end" value="<?= htmlspecialchars($end) ?>" class="px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700 text-slate-100 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Agente</label>
                <select name="agent" class="px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700 text-slate-100 text-sm">
                    <option value="0">Todos</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= $agentFilter === (int) $a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">
                <i class="fas fa-filter mr-2"></i>Filtrar
            </button>
        </form>

        <?php if ($needsCode): ?>
            <!-- Fuera de la ventana de ajuste el guard exige código: aquí es donde se escribe. -->
            <div class="mb-6 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[260px]">
                        <div class="text-amber-200 font-semibold text-sm mb-1">
                            <i class="fas fa-key mr-2"></i>Código de autorización
                        </div>
                        <p class="text-amber-100/80 text-xs leading-relaxed">
                            <?= (int) $nVencidos ?> <?= $nVencidos === 1 ? 'día de esta lista ya venció' : 'días de esta lista ya vencieron' ?>
                            su ventana de ajuste (<i class="fas fa-lock text-amber-400"></i> en la fecha).
                            <?php if ($soloGerencia): ?>
                                Estos días solo los abre un código que emite <strong><?= htmlspecialchars($nombresEmisores) ?></strong>,
                                y cada código sirve <strong>una sola vez</strong>.
                                <span class="text-amber-100">El código semanal no funciona aquí.</span>
                                Pulsa <em>Pedir permiso</em> en la fila que necesitas corregir; cuando te
                                lo emitan, escríbelo aquí y guarda normal.
                            <?php else: ?>
                                Escribe aquí el <strong>código semanal</strong> y guarda normal: se usa solo en
                                los días vencidos y queda registrado con tu nombre y el motivo.
                            <?php endif; ?>
                        </p>
                        <?php if ($soloGerencia && $puedeEmitir): ?>
                            <p class="text-xs text-amber-200/70 mt-2">
                                <i class="fas fa-user-shield mr-1"></i>
                                Tú puedes emitirlo:
                                <a href="authorizations.php" class="underline">Autorizaciones de Gerencia</a>.
                            </p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-xs text-amber-200/80 mb-1" for="phAuthCode">Código</label>
                        <input type="text" id="phAuthCode" autocomplete="off" spellcheck="false"
                               placeholder="Ej. WSH4DVG2"
                               class="w-44 px-3 py-2 rounded-lg bg-slate-900/70 border border-amber-500/50 text-amber-100 text-sm tracking-widest uppercase font-mono focus:outline-none focus:border-amber-400">
                    </div>
                </div>
                <?php if ($nCerrados > 0): ?>
                    <p class="text-xs text-rose-200/90 mt-3 pt-3 border-t border-amber-500/20">
                        <i class="fas fa-triangle-exclamation mr-1"></i>
                        Además hay <?= (int) $nCerrados ?> <?= $nCerrados === 1 ? 'día cerrado' : 'días cerrados' ?>
                        (<i class="fas fa-lock text-rose-400"></i> rojo): esos no se abren con código, hay que
                        reabrirlos desde <a href="timesheet_control.php" class="underline">Control de Horas</a>.
                    </p>
                <?php endif; ?>
            </div>
        <?php elseif ($nCerrados > 0): ?>
            <div class="mb-6 rounded-xl border border-rose-500/40 bg-rose-500/10 p-4 text-rose-100 text-sm">
                <i class="fas fa-lock mr-2"></i>
                <?= (int) $nCerrados ?> <?= $nCerrados === 1 ? 'día de esta lista está cerrado' : 'días de esta lista están cerrados' ?>.
                Para corregirlos hay que reabrirlos desde
                <a href="timesheet_control.php" class="underline">Control de Horas</a>.
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-slate-800/40 border border-slate-700/50 rounded-xl p-4">
                <div class="text-xs text-slate-400 mb-1">Horas según Vicidial</div>
                <div class="text-2xl font-bold text-slate-100"><?= ph_hms($totRaw) ?></div>
            </div>
            <div class="bg-slate-800/40 border border-slate-700/50 rounded-xl p-4">
                <div class="text-xs text-slate-400 mb-1">Horas a pagar (con ajustes)</div>
                <div class="text-2xl font-bold text-green-300"><?= ph_hms($totFinal) ?></div>
            </div>
            <div class="bg-slate-800/40 border border-slate-700/50 rounded-xl p-4">
                <div class="text-xs text-slate-400 mb-1">Días ajustados</div>
                <div class="text-2xl font-bold text-blue-300"><?= $nAdj ?> <span class="text-sm text-slate-500">de <?= count($rows) ?></span></div>
            </div>
        </div>

        <div class="bg-slate-800/40 border border-slate-700/50 rounded-xl">
            <!-- Buscador en vivo: filtra las filas ya cargadas, sin recargar la página. -->
            <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-slate-700/50">
                <div class="relative flex-1 min-w-[220px]">
                    <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm"></i>
                    <input type="search" id="phSearch" autocomplete="off"
                           placeholder="Buscar agente, fecha o cuenta de Vicidial…"
                           class="w-full pl-9 pr-9 py-2 rounded-lg bg-slate-900/60 border border-slate-700 text-slate-100 text-sm focus:outline-none focus:border-indigo-500">
                    <button type="button" id="phSearchClear"
                            class="hidden absolute right-2 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-200 px-1"
                            title="Limpiar (Esc)"><i class="fas fa-xmark"></i></button>
                </div>
                <div class="flex items-center gap-3 text-xs">
                    <label class="flex items-center gap-2 text-slate-400 cursor-pointer select-none">
                        <input type="checkbox" id="phOnlyAdjusted" class="accent-indigo-500">
                        Solo días ajustados
                    </label>
                    <label class="flex items-center gap-2 text-slate-400 cursor-pointer select-none">
                        <input type="checkbox" id="phOnlyFlagged" class="accent-orange-500">
                        Solo días a revisar
                    </label>
                    <span id="phCount" class="text-slate-500 whitespace-nowrap"></span>
                </div>
            </div>

            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-700/60">
                        <th class="px-4 py-3">Fecha</th>
                        <th class="px-4 py-3">Agente</th>
                        <th class="px-4 py-3">Logueado</th>
                        <th class="px-4 py-3">Productivo</th>
                        <th class="px-4 py-3">Pausas</th>
                        <th class="px-4 py-3">Vicidial pagable</th>
                        <th class="px-4 py-3">Horas a pagar</th>
                        <th class="px-4 py-3">Motivo</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">
                        No hay días de Vicidial para estos filtros.
                    </td></tr>
                <?php else: foreach ($rows as $r):
                    $key = $r['user_id'] . '|' . $r['report_date'];
                    $adj = $adjMap[$key] ?? null;
                    $final = $adj ? (int) $adj['adjusted_seconds'] : $r['raw_paid'];
                    $paidPause = max(0, $r['raw_paid'] - $r['nonpause']);
                    $codesTitle = [];
                    foreach ($r['codes'] as $c => $s) { $codesTitle[] = $c . ': ' . ph_hms((int) $s); }
                    // Un <form> no puede vivir dentro de un <tr>: el navegador lo saca
                    // de la tabla. Los <form> van en la última celda y los inputs se
                    // asocian por el atributo form= de HTML5.
                    $fid = 'adj-' . (int) $r['user_id'] . '-' . str_replace('-', '', $r['report_date']);
                    $fdel = 'del-' . (int) $r['user_id'] . '-' . str_replace('-', '', $r['report_date']);

                    // Texto sobre el que busca el filtro en vivo: nombre, fecha y cuentas.
                    $haystack = ph_searchKey(
                        $r['full_name'] . ' ' . $r['report_date'] . ' ' . implode(' ', $r['accounts'])
                    );
                    $flagged = $r['capped'] || $r['uncoded_dropped'] > 0;
                    $estadoTs = $tsRowState[$key] ?? 'ok';
                    $etiquetaVentana = $tsWindowLabels[$r['report_date']] ?? null;
                ?>
                    <tr class="ph-row border-b border-slate-800/60 <?= $adj ? 'bg-blue-500/5' : '' ?>"
                        data-search="<?= htmlspecialchars($haystack) ?>"
                        data-adjusted="<?= $adj ? '1' : '0' ?>"
                        data-flagged="<?= $flagged ? '1' : '0' ?>">
                        <td class="px-4 py-2 text-slate-300 whitespace-nowrap">
                            <?= htmlspecialchars($r['report_date']) ?>
                            <?php if ($estadoTs === 'code'): ?>
                                <i class="fas fa-lock text-amber-400 ml-1"
                                   title="La ventana de ajuste venció el <?= htmlspecialchars((string) $etiquetaVentana) ?>. Para guardar, escribe el código de autorización arriba."></i>
                            <?php elseif ($estadoTs === 'closed'): ?>
                                <i class="fas fa-lock text-rose-400 ml-1"
                                   title="Día cerrado o dentro de un período de nómina bloqueado. No se abre con código: hay que reabrirlo desde Control de Horas."></i>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 text-slate-200">
                            <?= htmlspecialchars($r['full_name']) ?>
                            <?php if (count($r['accounts']) > 1): ?>
                                <span class="ml-1 text-xs text-amber-300" title="<?= htmlspecialchars(implode(' + ', $r['accounts'])) ?>">
                                    <i class="fas fa-code-branch"></i> <?= count($r['accounts']) ?> cuentas
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 text-slate-400"><?= ph_hms($r['logged']) ?></td>
                        <td class="px-4 py-2 text-slate-300"><?= ph_hms($r['nonpause']) ?></td>
                        <td class="px-4 py-2 text-slate-400" title="<?= htmlspecialchars(implode(' · ', $codesTitle)) ?>">
                            +<?= ph_hms($paidPause) ?> pagadas
                        </td>
                        <td class="px-4 py-2 text-slate-300">
                            <?= ph_hms($r['raw_paid']) ?>
                            <?php if ($r['capped']): ?>
                                <i class="fas fa-triangle-exclamation text-amber-400" title="Topado a <?= (int) round($capSeconds / 3600) ?>h/día"></i>
                            <?php endif; ?>
                            <?php if ($r['uncoded_dropped'] > 0): ?>
                                <i class="fas fa-scissors text-orange-400"
                                   title="Pausa sin código: <?= ph_hms($r['uncoded_seconds']) ?> en total, se pagan <?= ph_hms($uncodedCapSeconds) ?> y no se pagan <?= ph_hms($r['uncoded_dropped']) ?>. Casi siempre es una sesión dejada abierta."></i>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2">
                            <input form="<?= $fid ?>" type="text" name="hours" value="<?= ph_hms($final) ?>" size="6"
                                   class="w-20 px-2 py-1 rounded bg-slate-900/60 border <?= $adj ? 'border-blue-500/60 text-blue-200' : 'border-slate-700 text-slate-100' ?> text-sm text-center"
                                   placeholder="8:30" title="Formato 8:30 o 8.5">
                        </td>
                        <td class="px-4 py-2">
                            <input form="<?= $fid ?>" type="text" name="reason" maxlength="255"
                                   value="<?= htmlspecialchars($adj['reason'] ?? '') ?>"
                                   class="w-full min-w-[180px] px-2 py-1 rounded bg-slate-900/60 border border-slate-700 text-slate-100 text-sm"
                                   placeholder="Motivo del ajuste (obligatorio)">
                            <?php if ($adj): ?>
                                <div class="text-xs text-slate-500 mt-1">
                                    <?= htmlspecialchars($adj['by_name'] ?? '—') ?> · <?= htmlspecialchars((string) $adj['updated_at']) ?>
                                </div>
                            <?php endif; ?>
                            <?php $historial = $logMap[$key] ?? []; ?>
                            <?php if (!empty($historial)): ?>
                                <details class="mt-1">
                                    <summary class="text-xs text-sky-400 cursor-pointer select-none">
                                        <i class="fas fa-clock-rotate-left"></i>
                                        Historial (<?= count($historial) ?> <?= count($historial) === 1 ? 'cambio' : 'cambios' ?>)
                                    </summary>
                                    <div class="mt-1 space-y-1 border-l-2 border-sky-500/40 pl-2">
                                        <?php foreach (array_reverse($historial) as $h):
                                            $accion = strtolower((string) ($h['action'] ?? 'update'));
                                            $etiqueta = ['create' => 'Creado', 'update' => 'Editado', 'delete' => 'Eliminado'][$accion] ?? 'Modificado';
                                            $antes = $h['old_seconds'] === null ? null : (int) $h['old_seconds'];
                                            $despues = $h['new_seconds'] === null ? null : (int) $h['new_seconds'];
                                        ?>
                                            <div class="text-xs text-slate-400">
                                                <span class="text-slate-200 font-semibold"><?= htmlspecialchars($etiqueta) ?></span>
                                                por <?= htmlspecialchars($h['performed_by_name'] ?: 'Sistema') ?>
                                                · <?= htmlspecialchars((string) $h['created_at']) ?>
                                                <div>
                                                    <?= $antes === null ? 'Vicidial ' . ph_hms((int) $h['original_seconds']) : ph_hms($antes) ?>
                                                    →
                                                    <?= $despues === null ? '<span class="text-amber-300">sin ajuste (vuelve a Vicidial)</span>' : ph_hms($despues) ?>
                                                </div>
                                                <?php if (!empty($h['reason'])): ?>
                                                    <div class="italic text-slate-500">“<?= htmlspecialchars($h['reason']) ?>”</div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            <form method="post" id="<?= $fid ?>" class="inline" data-needs-code="<?= $estadoTs === 'code' ? '1' : '0' ?>">
                                <input type="hidden" name="action" value="save_adjustment">
                                <input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
                                <input type="hidden" name="work_date" value="<?= htmlspecialchars($r['report_date']) ?>">
                                <input type="hidden" name="original_seconds" value="<?= (int) $r['raw_paid'] ?>">
                                <!-- Lo llena el JS con el código escrito arriba (solo si el día lo exige). -->
                                <input type="hidden" name="authorization_code" class="ph-auth-code" value="">
                                <input type="hidden" name="start" value="<?= htmlspecialchars($start) ?>">
                                <input type="hidden" name="end" value="<?= htmlspecialchars($end) ?>">
                                <input type="hidden" name="agent" value="<?= $agentFilter ?>">
                                <button type="submit" class="px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-500 text-white text-xs" title="Guardar">
                                    <i class="fas fa-floppy-disk"></i>
                                </button>
                            </form>
                            <?php if ($adj): ?>
                                <form method="post" id="<?= $fdel ?>" class="inline" data-needs-code="<?= $estadoTs === 'code' ? '1' : '0' ?>"
                                      onsubmit="return confirm('¿Quitar el ajuste y volver al dato de Vicidial?');">
                                    <input type="hidden" name="action" value="delete_adjustment">
                                    <input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
                                    <input type="hidden" name="work_date" value="<?= htmlspecialchars($r['report_date']) ?>">
                                    <input type="hidden" name="authorization_code" class="ph-auth-code" value="">
                                    <input type="hidden" name="start" value="<?= htmlspecialchars($start) ?>">
                                    <input type="hidden" name="end" value="<?= htmlspecialchars($end) ?>">
                                    <input type="hidden" name="agent" value="<?= $agentFilter ?>">
                                    <button type="submit" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-red-600 text-slate-200 text-xs" title="Quitar ajuste">
                                        <i class="fas fa-rotate-left"></i>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($soloGerencia && $estadoTs === 'code'):
                                $sol = $solicitudes[$key] ?? null; ?>
                                <?php if ($sol === null): ?>
                                    <button type="button"
                                            class="ph-ask px-3 py-1.5 rounded bg-amber-600/80 hover:bg-amber-500 text-white text-xs"
                                            title="Pedir a <?= htmlspecialchars($nombresEmisores) ?> la autorización de este día"
                                            data-user="<?= (int) $r['user_id'] ?>"
                                            data-date="<?= htmlspecialchars($r['report_date']) ?>"
                                            data-name="<?= htmlspecialchars($r['full_name']) ?>"
                                            data-hours="<?= ph_hms($final) ?>">
                                        <i class="fas fa-hand"></i> Pedir permiso
                                    </button>
                                <?php elseif ($sol['status'] === 'PENDING'): ?>
                                    <span class="px-3 py-1.5 rounded bg-slate-700/60 text-amber-200 text-xs whitespace-nowrap"
                                          title="Solicitado el <?= date('d/m/Y H:i', strtotime($sol['created_at'])) ?>">
                                        <i class="fas fa-hourglass-half"></i> Esperando
                                    </span>
                                <?php else: ?>
                                    <span class="px-3 py-1.5 rounded bg-emerald-600/25 text-emerald-200 text-xs whitespace-nowrap"
                                          title="Ya te emitieron el código. Escríbelo arriba y guarda.">
                                        <i class="fas fa-key"></i> Autorizado
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                    <tr id="phNoMatch" class="hidden">
                        <td colspan="9" class="px-4 py-8 text-center text-slate-500">
                            Ningún día coincide con la búsqueda.
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>

        <?php if ($soloGerencia): ?>
        <!-- Pedirle el permiso al CEO sin salir de la pantalla. -->
        <div id="phAskModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/70 p-4">
            <div class="bg-slate-800 border border-slate-600 rounded-xl w-full max-w-lg shadow-2xl">
                <form method="post" id="phAskForm">
                    <input type="hidden" name="action" value="request_override">
                    <input type="hidden" name="user_id" id="askUser">
                    <input type="hidden" name="work_date" id="askDate">
                    <input type="hidden" name="proposed_hours" id="askHours">
                    <input type="hidden" name="start" value="<?= htmlspecialchars($start) ?>">
                    <input type="hidden" name="end" value="<?= htmlspecialchars($end) ?>">
                    <input type="hidden" name="agent" value="<?= $agentFilter ?>">

                    <div class="px-5 py-4 border-b border-slate-700">
                        <h3 class="text-lg font-semibold text-white">
                            <i class="fas fa-hand text-amber-400 mr-2"></i>Pedir autorización
                        </h3>
                        <p class="text-slate-400 text-sm mt-1">
                            Le llega a <strong><?= htmlspecialchars($nombresEmisores) ?></strong> con el detalle
                            del día y cuánto dinero mueve.
                        </p>
                    </div>

                    <div class="px-5 py-4 space-y-4">
                        <div class="bg-slate-900/60 rounded-lg p-3 text-sm">
                            <div class="text-white font-semibold" id="askName">—</div>
                            <div class="text-slate-400 text-xs" id="askInfo">—</div>
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">
                                ¿Por qué hay que corregir este día? <span class="text-rose-400">*</span>
                            </label>
                            <textarea name="request_reason" id="askReason" rows="3" maxlength="500" required
                                      placeholder="Ej. El agente trabajó pero Vicidial no registró la sesión de la tarde; el supervisor lo confirmó."
                                      class="w-full px-3 py-2 rounded-lg bg-slate-900/60 border border-slate-700 text-slate-100 text-sm"></textarea>
                            <p class="text-xs text-slate-500 mt-1">
                                Es lo único que va a leer para decidir. Sé concreta.
                            </p>
                        </div>
                    </div>

                    <div class="px-5 py-4 border-t border-slate-700 flex justify-end gap-2">
                        <button type="button" id="phAskCancel"
                                class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-200 text-sm">
                            Cancelar
                        </button>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold">
                            <i class="fas fa-paper-plane mr-2"></i>Enviar solicitud
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <p class="text-xs text-slate-500 mt-4">
            <i class="fas fa-circle-info mr-1"></i>
            Los ajustes mandan sobre Vicidial en la nómina y en el portal del agente. Todo cambio queda
            registrado en <code>vicidial_payroll_adjustment_log</code> con autor, hora y motivo.
            Regenerar la nómina <strong>no</strong> borra estos ajustes.
        </p>
    </div>

    <script>
    // El código de autorización se escribe UNA vez arriba y viaja con la fila que
    // se guarde. Sin esto el POST llegaba sin código y el guard rechazaba el
    // cambio con "se requiere un codigo de autorizacion" sin dar dónde ponerlo.
    (function () {
        const box = document.getElementById('phAuthCode');
        if (!box) { return; }

        box.addEventListener('input', function () {
            box.value = box.value.toUpperCase().replace(/\s+/g, '');
        });

        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (!form || !form.querySelector) { return; }
            const hidden = form.querySelector('.ph-auth-code');
            if (!hidden) { return; }

            const code = box.value.trim();
            if (form.dataset.needsCode === '1' && code === '') {
                e.preventDefault();
                e.stopPropagation();
                box.focus();
                box.classList.add('border-rose-400');
                alert('Ese día ya venció su ventana de ajuste.\n\nEscribe el código de autorización de la semana en el recuadro amarillo antes de guardar.');
                return;
            }
            hidden.value = code;
        }, true);
    })();

    // Modal de solicitud de autorización.
    (function () {
        const modal  = document.getElementById('phAskModal');
        if (!modal) { return; }
        const cancel = document.getElementById('phAskCancel');

        function abrir(btn) {
            document.getElementById('askUser').value  = btn.dataset.user;
            document.getElementById('askDate').value  = btn.dataset.date;
            document.getElementById('askHours').value = btn.dataset.hours || '';
            document.getElementById('askName').textContent = btn.dataset.name;
            const f = btn.dataset.date.split('-');
            document.getElementById('askInfo').textContent =
                'Día ' + f[2] + '/' + f[1] + '/' + f[0] + ' · horas actuales ' + (btn.dataset.hours || '—');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.getElementById('askReason').focus();
        }
        function cerrar() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.querySelectorAll('.ph-ask').forEach(b => b.addEventListener('click', () => abrir(b)));
        cancel.addEventListener('click', cerrar);
        modal.addEventListener('click', e => { if (e.target === modal) { cerrar(); } });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') { cerrar(); } });
    })();

    (function () {
        const input    = document.getElementById('phSearch');
        const clearBtn = document.getElementById('phSearchClear');
        const onlyAdj  = document.getElementById('phOnlyAdjusted');
        const onlyFlag = document.getElementById('phOnlyFlagged');
        const counter  = document.getElementById('phCount');
        const noMatch  = document.getElementById('phNoMatch');
        const rows     = Array.from(document.querySelectorAll('tr.ph-row'));
        if (!input || !rows.length) { if (counter) counter.textContent = ''; return; }

        // Misma normalización que el lado PHP: minúsculas y sin acentos, para que
        // "nunez" encuentre a "Núñez" y "garcia" a "García".
        const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

        function apply() {
            // Cada palabra debe aparecer: "mabel 07-08" filtra por agente Y fecha.
            const terms = norm(input.value).split(/\s+/).filter(Boolean);
            let shown = 0;

            for (const row of rows) {
                const hay = row.dataset.search || '';
                const okText = terms.every((t) => hay.includes(t));
                const okAdj  = !onlyAdj.checked  || row.dataset.adjusted === '1';
                const okFlag = !onlyFlag.checked || row.dataset.flagged === '1';
                const visible = okText && okAdj && okFlag;
                row.classList.toggle('hidden', !visible);
                if (visible) shown++;
            }

            noMatch.classList.toggle('hidden', shown > 0);
            clearBtn.classList.toggle('hidden', input.value === '');
            counter.textContent = shown === rows.length
                ? `${rows.length} día${rows.length === 1 ? '' : 's'}`
                : `${shown} de ${rows.length}`;
        }

        input.addEventListener('input', apply);
        onlyAdj.addEventListener('change', apply);
        onlyFlag.addEventListener('change', apply);
        clearBtn.addEventListener('click', () => { input.value = ''; input.focus(); apply(); });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { input.value = ''; apply(); }
        });

        apply();
    })();
    </script>

    <?php endif; // fin del bloque de Vicidial ?>

    <?php include '../footer.php'; ?>
</body>
</html>
