<?php
session_start();
require_once '../db.php';
require_once '../lib/employee_record.php';
ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$employeeId) {
    header('Location: employees.php');
    exit;
}

// Se piden TODAS las columnas de compensación: antes solo venía u.hourly_rate
// (la tarifa en USD) y como el personal cobra en pesos ese campo está en 0, así
// que el perfil mostraba "RD$0.00" a todo el mundo.
// La exención de ISR vive en employees; se garantiza la columna antes del e.*
// para que el perfil no reviente en una base que aún no la tenga.
require_once __DIR__ . '/payroll_functions.php';
ensureEmployeeIsrExemptColumns($pdo);

$stmt = $pdo->prepare("
    SELECT e.*, u.username, u.role, u.overtime_multiplier,
           u.hourly_rate, u.hourly_rate_dop,
           u.monthly_salary, u.monthly_salary_dop,
           u.preferred_currency, u.compensation_type,
           COALESCE(u.payroll_source, 'manual') AS payroll_source,
           d.name as department_name,
           b.name as bank_name,
           YEAR(CURDATE()) - YEAR(e.birth_date) as age,
           DATEDIFF(CURDATE(), e.hire_date) as days_employed
    FROM employees e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN banks b ON b.id = e.bank_id
    WHERE e.id = ?
");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: employees.php');
    exit;
}

$prevStmt = $pdo->prepare("SELECT id FROM employees WHERE id < ? ORDER BY id DESC LIMIT 1");
$prevStmt->execute([$employeeId]);
$prevId = $prevStmt->fetchColumn();

$nextStmt = $pdo->prepare("SELECT id FROM employees WHERE id > ? ORDER BY id ASC LIMIT 1");
$nextStmt->execute([$employeeId]);
$nextId = $nextStmt->fetchColumn();

// Get vacation balance
$vacBalance = $pdo->prepare("SELECT * FROM vacation_balances WHERE employee_id = ? AND year = YEAR(CURDATE())");
$vacBalance->execute([$employeeId]);
$vacationBalance = $vacBalance->fetch(PDO::FETCH_ASSOC);

// Get recent requests
$permissions = $pdo->prepare("SELECT * FROM permission_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 10");
$permissions->execute([$employeeId]);
$permissionsList = $permissions->fetchAll(PDO::FETCH_ASSOC);

$vacations = $pdo->prepare("SELECT * FROM vacation_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 10");
$vacations->execute([$employeeId]);
$vacationsList = $vacations->fetchAll(PDO::FETCH_ASSOC);

// Get document count
$docCount = $pdo->prepare("SELECT COUNT(*) FROM employee_documents WHERE employee_id = ?");
$docCount->execute([$employeeId]);
$documentCount = $docCount->fetchColumn();

// ---------------------------------------------------------------------------
// Expediente: antigüedad, compensación, historial de asistencia, campañas,
// amonestaciones, licencias y estado de la documentación.
// ---------------------------------------------------------------------------
$tenure = employeeTenure($employee['hire_date'], $employee['termination_date'] ?? null);

// Tipo de pago: quien cobra sueldo mensual no tiene tarifa horaria que mostrar.
$paymentType = resolvePaymentType(
    $employee['compensation_type'] ?? '',
    $employee['role'] ?? '',
    max((float) $employee['monthly_salary'], (float) $employee['monthly_salary_dop'])
);
$isFixedPay = ($paymentType === 'fixed');
$prefersDop = strtoupper((string) ($employee['preferred_currency'] ?? 'DOP')) === 'DOP';

// Se muestra la moneda en la que realmente cobra la persona.
if ($isFixedPay) {
    $payAmount   = $prefersDop ? (float) $employee['monthly_salary_dop'] : (float) $employee['monthly_salary'];
    $payCurrency = $prefersDop ? 'RD$' : '$';
    $payLabel    = 'Sueldo mensual';
    $paySuffix   = '/mes';
    if ($payAmount <= 0) {
        $payAmount   = $prefersDop ? (float) $employee['monthly_salary'] : (float) $employee['monthly_salary_dop'];
        $payCurrency = $prefersDop ? '$' : 'RD$';
    }
} else {
    $payAmount   = $prefersDop ? (float) $employee['hourly_rate_dop'] : (float) $employee['hourly_rate'];
    $payCurrency = $prefersDop ? 'RD$' : '$';
    $payLabel    = 'Tarifa por hora';
    $paySuffix   = '/hora';
    if ($payAmount <= 0) {
        $payAmount   = $prefersDop ? (float) $employee['hourly_rate'] : (float) $employee['hourly_rate_dop'];
        $payCurrency = $prefersDop ? '$' : 'RD$';
    }
}

// Historial de asistencia: por defecto los últimos 90 días, ajustable.
$historyDays = isset($_GET['dias']) ? (int) $_GET['dias'] : 90;
if (!in_array($historyDays, [30, 60, 90, 180, 365], true)) {
    $historyDays = 90;
}
$historyFrom = date('Y-m-d', strtotime("-{$historyDays} days"));
$historyTo   = date('Y-m-d');

$attendance = employeeAttendanceHistory(
    $pdo,
    $employeeId,
    (int) $employee['user_id'],
    $historyFrom,
    $historyTo
);

// Restaurantes de Delivery que atiende (para el reparto contable).
// No sustituye a la campaña: la de nómina sigue siendo una sola.
require_once __DIR__ . '/../lib/delivery_restaurants.php';
$employeeRestaurants = deliveryGetEmployeeRestaurants($pdo, $employeeId, false);
$activeRestaurants   = array_values(array_filter($employeeRestaurants, static fn($r) => empty($r['end_date'])));

// Historial de pagos y de cambios salariales
require_once __DIR__ . '/../lib/salary_history.php';
$paymentHistory = getPaymentHistoryForEmployee($pdo, $employeeId, 24);
$paymentTotals  = getPaymentHistoryTotals($paymentHistory);
$salaryHistory  = getSalaryHistoryForEmployee($pdo, $employeeId, (int) $employee['user_id'], 30);

$campaignHistory = employeeCampaignHistory($pdo, $employeeId);
$activeCampaigns = array_values(array_filter($campaignHistory, static fn($c) => empty($c['end_date'])));
$warnings        = employeeWarnings($pdo, $employeeId);
$medicalLeaves   = employeeMedicalLeaves($pdo, $employeeId);
$docStatus       = employeeRequiredDocumentsStatus($pdo, $employeeId);
$personalStatus  = employeePersonalDataStatus($employee);
$warningLabels   = employeeWarningLabels();
$terminationLabels = employeeTerminationLabels();

$activeWarnings = count(array_filter($warnings, static fn($w) => ($w['status'] ?? '') === 'ACTIVA'));

// Catálogo de campañas para los formularios de asignación
$allCampaigns = [];
try {
    $allCampaigns = $pdo->query("SELECT id, name, code, color FROM campaigns ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { /* sin campañas configuradas */ }

$flashSuccess = $_SESSION['profile_success'] ?? null;
$flashError   = $_SESSION['profile_error'] ?? null;
$signatureLink = $_SESSION['profile_signature_link'] ?? null;
unset($_SESSION['profile_success'], $_SESSION['profile_error'], $_SESSION['profile_signature_link']);

// URL absoluta del enlace de firma, para poder copiarla y enviarla al colaborador.
$signatureUrl = null;
if ($signatureLink) {
    $cfg = @include __DIR__ . '/../config/email_config.php';
    $base = (is_array($cfg) && !empty($cfg['app_url']))
        ? rtrim($cfg['app_url'], '/')
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\'));
    $signatureUrl = $base . '/firmar_documento.php?t=' . $signatureLink['token'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil - <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <div class="flex items-center gap-4">
                <a href="employees.php" class="btn-secondary"><i class="fas fa-arrow-left"></i></a>
                <div>
                    <h1 class="text-3xl font-bold text-white">Perfil de Empleado</h1>
                    <p class="text-slate-400"><?= htmlspecialchars($employee['employee_code']) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="inventory.php?employee_id=<?= (int) $employeeId ?>" class="btn-secondary">
                    <i class="fas fa-boxes"></i>
                    Inventario
                </a>
                <a href="inventory_assign.php?employee_id=<?= (int) $employeeId ?>" class="btn-secondary">
                    <i class="fas fa-plus-circle"></i>
                    Asignar Artículo
                </a>
                <a href="generate_document.php?employee_id=<?= (int) $employeeId ?>" class="btn-secondary">
                    <i class="fas fa-file-pen"></i>
                    Generar documento
                </a>
                <a href="employee_record_export.php?id=<?= (int) $employeeId ?>&formato=excel" class="btn-secondary">
                    <i class="fas fa-file-excel"></i>
                    Expediente
                </a>
                <?php if (($employee['employment_status'] ?? '') !== 'TERMINATED'): ?>
                    <button type="button" onclick="document.getElementById('terminateModal').classList.remove('hidden')"
                            class="btn-secondary" style="border-color: rgba(244,63,94,.4); color:#fda4af;">
                        <i class="fas fa-door-open"></i>
                        Finalizar relación
                    </button>
                <?php endif; ?>
                <?php if (!empty($prevId)): ?>
                    <a href="employee_profile.php?id=<?= (int)$prevId ?>" 
                       class="h-10 w-10 rounded-md bg-slate-700/40 border border-white/20 text-white flex items-center justify-center hover:bg-slate-600/50 hover:border-white/30 hover:shadow-md transition transform hover:scale-105" 
                       title="Anterior">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php else: ?>
                    <span class="h-10 w-10 rounded-md bg-slate-700/20 border border-white/10 text-slate-300 flex items-center justify-center opacity-50 cursor-not-allowed" aria-disabled="true" title="Anterior">
                        <i class="fas fa-chevron-left"></i>
                    </span>
                <?php endif; ?>
                <?php if (!empty($nextId)): ?>
                    <a href="employee_profile.php?id=<?= (int)$nextId ?>" 
                       class="h-10 w-10 rounded-md bg-slate-700/40 border border-white/20 text-white flex items-center justify-center hover:bg-slate-600/50 hover:border-white/30 hover:shadow-md transition transform hover:scale-105" 
                       title="Siguiente">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="h-10 w-10 rounded-md bg-slate-700/20 border border-white/10 text-slate-300 flex items-center justify-center opacity-50 cursor-not-allowed" aria-disabled="true" title="Siguiente">
                        <i class="fas fa-chevron-right"></i>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flashSuccess): ?>
            <div class="mb-6 p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-200">
                <i class="fas fa-circle-check mr-2"></i><?= htmlspecialchars($flashSuccess) ?>
            </div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="mb-6 p-4 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-200">
                <i class="fas fa-circle-exclamation mr-2"></i><?= htmlspecialchars($flashError) ?>
            </div>
        <?php endif; ?>

        <?php if ($signatureUrl): ?>
            <div class="mb-6 p-4 rounded-lg bg-cyan-500/10 border border-cyan-500/30">
                <p class="text-cyan-200 text-sm mb-2">
                    <i class="fas fa-signature mr-1"></i>
                    Enlace de firma de <strong><?= htmlspecialchars($signatureLink['label']) ?></strong>.
                    Envíaselo al colaborador: al firmar, el documento se archiva solo en su expediente.
                </p>
                <div class="flex gap-2">
                    <input type="text" readonly id="signatureUrl" value="<?= htmlspecialchars($signatureUrl) ?>"
                           class="flex-1 bg-slate-900 border border-slate-700 rounded px-3 py-2 text-white text-sm"
                           onclick="this.select()">
                    <button type="button" class="btn-secondary text-sm" onclick="
                        const i = document.getElementById('signatureUrl');
                        i.select(); document.execCommand('copy');
                        this.innerHTML = '<i class=\'fas fa-check\'></i> Copiado';">
                        <i class="fas fa-copy"></i> Copiar
                    </button>
                    <a href="<?= htmlspecialchars($signatureUrl) ?>" target="_blank" class="btn-secondary text-sm">
                        <i class="fas fa-arrow-up-right-from-square"></i> Abrir
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Employee Card -->
        <div class="glass-card mb-8">
            <div class="flex flex-col md:flex-row gap-6">
                <?php if (!empty($employee['photo_path']) && file_exists('../' . $employee['photo_path'])): ?>
                    <img src="../<?= htmlspecialchars($employee['photo_path']) ?>" 
                         alt="<?= htmlspecialchars($employee['first_name']) ?>" 
                         class="w-32 h-32 rounded-full object-cover border-4 border-blue-500 shadow-lg">
                <?php else: ?>
                    <div class="w-32 h-32 rounded-full flex items-center justify-center text-4xl font-bold text-white" 
                         style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                        <?= strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="flex-1">
                    <h2 class="text-3xl font-bold text-white mb-2">
                        <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>
                    </h2>
                    <p class="text-xl text-slate-300 mb-2"><?= htmlspecialchars($employee['position'] ?: 'Sin posición') ?></p>
                    <p class="text-slate-400"><i class="fas fa-building mr-2"></i><?= htmlspecialchars($employee['department_name'] ?: 'Sin departamento') ?></p>
                    
                    <?php if (!empty($activeCampaigns)): ?>
                        <div class="flex flex-wrap gap-2 mt-3">
                            <?php foreach ($activeCampaigns as $camp): ?>
                                <span class="px-2 py-1 rounded-full text-xs font-semibold text-white"
                                      style="background: <?= htmlspecialchars($camp['campaign_color'] ?: '#6366f1') ?>;">
                                    <i class="fas fa-bullhorn mr-1"></i><?= htmlspecialchars($camp['campaign_name'] ?? 'Campaña') ?>
                                    <?php if (!empty($camp['is_primary'])): ?> · principal<?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
                        <div class="bg-slate-800/50 rounded-lg p-4">
                            <p class="text-slate-400 text-sm">Usuario</p>
                            <p class="text-white font-semibold"><?= htmlspecialchars($employee['username']) ?></p>
                        </div>
                        <div class="bg-slate-800/50 rounded-lg p-4">
                            <p class="text-slate-400 text-sm">Estado</p>
                            <p class="text-white font-semibold"><?= htmlspecialchars($employee['employment_status']) ?></p>
                            <?php if (!empty($employee['termination_reason'])): ?>
                                <p class="text-xs text-rose-300 mt-1">
                                    <?= htmlspecialchars($terminationLabels['reasons'][$employee['termination_reason']] ?? $employee['termination_reason']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="bg-slate-800/50 rounded-lg p-4">
                            <p class="text-slate-400 text-sm"><?= htmlspecialchars($payLabel) ?></p>
                            <p class="text-white font-semibold">
                                <?= htmlspecialchars($payCurrency) ?><?= number_format($payAmount, 2) ?><span class="text-xs text-slate-400"><?= $paySuffix ?></span>
                            </p>
                            <p class="text-xs text-slate-400 mt-1">
                                Horas por <?= $employee['payroll_source'] === 'vicidial' ? 'Vicidial' : 'ponche' ?>
                            </p>
                            <?php $isrExento = !empty($employee['isr_exempt']); ?>
                            <details class="mt-2">
                                <summary class="text-xs cursor-pointer <?= $isrExento ? 'text-amber-300' : 'text-slate-500' ?>">
                                    <?= $isrExento ? 'Exento de ISR' : 'Retiene ISR' ?>
                                </summary>
                                <form method="POST" action="employee_profile_actions.php" class="mt-2 space-y-2">
                                    <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                    <input type="hidden" name="action" value="set_isr_exempt">
                                    <label class="flex items-center gap-2 text-xs text-slate-300">
                                        <input type="checkbox" name="isr_exempt" value="1" <?= $isrExento ? 'checked' : '' ?>>
                                        No retenerle ISR
                                    </label>
                                    <input type="text" name="isr_exempt_reason" maxlength="255"
                                           value="<?= htmlspecialchars((string) ($employee['isr_exempt_reason'] ?? '')) ?>"
                                           placeholder="Motivo (obligatorio)"
                                           class="w-full rounded border border-slate-700 bg-slate-900 px-2 py-1 text-xs">
                                    <button type="submit" class="text-xs bg-slate-700 hover:bg-slate-600 rounded px-2 py-1">
                                        Guardar
                                    </button>
                                    <?php if ($isrExento && !empty($employee['isr_exempt_at'])): ?>
                                        <p class="text-[10px] text-slate-500">
                                            Desde <?= htmlspecialchars(date('d/m/Y', strtotime($employee['isr_exempt_at']))) ?>.
                                            Recalcula las quincenas para que aplique.
                                        </p>
                                    <?php endif; ?>
                                </form>
                            </details>
                        </div>
                        <div class="bg-slate-800/50 rounded-lg p-4">
                            <p class="text-slate-400 text-sm">Tiempo laborando</p>
                            <p class="text-white font-semibold"><?= htmlspecialchars($tenure['label']) ?></p>
                            <p class="text-xs text-slate-400 mt-1"><?= number_format($tenure['total_days']) ?> días</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">Información Personal</h3>
                <div class="space-y-3">
                    <?php if ($employee['email']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-envelope text-blue-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Email</p><p class="text-white"><?= htmlspecialchars($employee['email']) ?></p></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($employee['phone']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-phone text-green-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Teléfono</p><p class="text-white"><?= htmlspecialchars($employee['phone']) ?></p></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($employee['birth_date']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-birthday-cake text-pink-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Nacimiento</p><p class="text-white"><?= date('d/m/Y', strtotime($employee['birth_date'])) ?> (<?= $employee['age'] ?> años)</p></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($employee['id_card_number']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-id-card text-yellow-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Cédula</p><p class="text-white"><?= htmlspecialchars($employee['id_card_number']) ?></p></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($employee['gender']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-user text-purple-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Género</p><p class="text-white"><?= htmlspecialchars($employee['gender']) ?></p></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">Información Laboral</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-calendar-check text-green-400 w-5"></i>
                        <div><p class="text-slate-400 text-sm">Ingreso</p><p class="text-white"><?= date('d/m/Y', strtotime($employee['hire_date'])) ?></p></div>
                    </div>
                    <div class="flex items-center gap-3">
                        <i class="fas fa-clock text-blue-400 w-5"></i>
                        <div>
                            <p class="text-slate-400 text-sm">Tiempo laborando</p>
                            <p class="text-white"><?= htmlspecialchars($tenure['label']) ?></p>
                        </div>
                    </div>
                    <?php if (!empty($employee['termination_date'])): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-door-open text-rose-400 w-5"></i>
                            <div>
                                <p class="text-slate-400 text-sm">Salida</p>
                                <p class="text-white"><?= date('d/m/Y', strtotime($employee['termination_date'])) ?></p>
                                <?php if (!empty($employee['rehire_eligibility'])): ?>
                                    <p class="text-xs <?= $employee['rehire_eligibility'] === 'ELIGIBLE' ? 'text-emerald-300' : ($employee['rehire_eligibility'] === 'NO_ELEGIBLE' ? 'text-rose-300' : 'text-amber-300') ?>">
                                        <?= htmlspecialchars($terminationLabels['rehire'][$employee['rehire_eligibility']] ?? $employee['rehire_eligibility']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($vacationBalance): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-umbrella-beach text-cyan-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Vacaciones Disponibles</p><p class="text-white"><?= number_format($vacationBalance['remaining_days'], 1) ?> días</p></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-university text-blue-400 mr-2"></i>
                    Información Bancaria
                </h3>
                <div class="space-y-3">
                    <?php if ($employee['bank_name']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-building text-blue-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Banco</p><p class="text-white"><?= htmlspecialchars($employee['bank_name']) ?></p></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($employee['bank_account_number']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-credit-card text-green-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Número de Cuenta</p><p class="text-white"><?= htmlspecialchars($employee['bank_account_number']) ?></p></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!$employee['bank_name'] && !$employee['bank_account_number']): ?>
                        <p class="text-slate-400 text-center py-4">Sin información bancaria</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ================= Historial de asistencia ================= -->
        <div class="glass-card mb-8">
            <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
                <h3 class="text-lg font-semibold text-white">
                    <i class="fas fa-calendar-xmark text-amber-400 mr-2"></i>
                    Historial de Asistencia
                    <span class="text-xs font-normal text-slate-400 ml-2">ausencias, tardanzas y permisos</span>
                </h3>
                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="id" value="<?= (int) $employeeId ?>">
                    <label class="text-xs text-slate-400">Período:</label>
                    <select name="dias" onchange="this.form.submit()"
                            class="bg-slate-800 border border-slate-700 rounded px-2 py-1 text-white text-sm">
                        <?php foreach ([30 => '30 días', 60 => '60 días', 90 => '90 días', 180 => '6 meses', 365 => '1 año'] as $d => $lbl): ?>
                            <option value="<?= $d ?>" <?= $historyDays === $d ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
                <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-white"><?= (int) $attendance['summary']['scheduled'] ?></p>
                    <p class="text-slate-400 text-xs">Días programados</p>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-emerald-400"><?= (int) $attendance['summary']['worked'] ?></p>
                    <p class="text-slate-400 text-xs">Trabajados</p>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-rose-400"><?= (int) $attendance['summary']['absent'] ?></p>
                    <p class="text-slate-400 text-xs">Ausencias</p>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-cyan-400"><?= (int) $attendance['summary']['absent_justified'] ?></p>
                    <p class="text-slate-400 text-xs">Justificadas</p>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-amber-400"><?= (int) $attendance['summary']['late'] ?></p>
                    <p class="text-slate-400 text-xs">
                        Tardanzas
                        <?php if ($attendance['summary']['late_minutes'] > 0): ?>
                            · <?= number_format($attendance['summary']['late_minutes']) ?> min
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if (empty($attendance['days'])): ?>
                <p class="text-slate-400 text-center py-6">
                    <i class="fas fa-circle-check text-emerald-400 mr-2"></i>
                    Sin ausencias ni tardanzas en el período. Asistencia perfecta.
                </p>
            <?php else: ?>
                <div class="overflow-auto" style="max-height: 24rem;">
                    <table class="w-full text-sm">
                        <thead class="text-slate-400 text-xs uppercase sticky top-0 bg-slate-900">
                            <tr>
                                <th class="text-left p-2">Fecha</th>
                                <th class="text-left p-2">Situación</th>
                                <th class="text-left p-2">Llegada</th>
                                <th class="text-left p-2">Programada</th>
                                <th class="text-left p-2">Justificación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance['days'] as $day): ?>
                                <?php
                                    $badge = [
                                        'AUSENCIA'             => ['bg-rose-500/20 text-rose-300', 'Ausencia'],
                                        'AUSENCIA_JUSTIFICADA' => ['bg-cyan-500/20 text-cyan-300', 'Ausencia justificada'],
                                        'TARDANZA'             => ['bg-amber-500/20 text-amber-300', 'Tardanza'],
                                    ][$day['status']] ?? ['bg-slate-600/30 text-slate-300', $day['status']];
                                ?>
                                <tr class="border-t border-slate-700/50">
                                    <td class="p-2 text-white whitespace-nowrap">
                                        <?= date('d/m/Y', strtotime($day['date'])) ?>
                                        <span class="text-xs text-slate-500"><?= ['', 'Lun','Mar','Mié','Jue','Vie','Sáb','Dom'][(int) date('N', strtotime($day['date']))] ?></span>
                                    </td>
                                    <td class="p-2">
                                        <span class="px-2 py-1 rounded text-xs font-semibold <?= $badge[0] ?>"><?= $badge[1] ?></span>
                                        <?php if ($day['late_minutes'] > 0): ?>
                                            <span class="text-amber-300 text-xs ml-1">+<?= (int) $day['late_minutes'] ?> min</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-2 text-slate-300">
                                        <?= $day['arrival'] ? date('H:i', strtotime($day['arrival'])) : '—' ?>
                                        <?php if ($day['arrival_source'] === 'vicidial'): ?>
                                            <span class="text-xs text-purple-300" title="Hora tomada del login de Vicidial">VD</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-2 text-slate-400">
                                        <?= $day['scheduled_entry'] ? date('H:i', strtotime($day['scheduled_entry'])) : '—' ?>
                                    </td>
                                    <td class="p-2 text-slate-300 text-xs">
                                        <?php if (!empty($day['justification'])): ?>
                                            <?php foreach ($day['justification'] as $j): ?>
                                                <div><?= htmlspecialchars($j['label']) ?><?= $j['detail'] ? ': ' . htmlspecialchars(mb_substr($j['detail'], 0, 50)) : '' ?></div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-slate-500">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============ Amonestaciones y licencias médicas ============ -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="glass-card">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-white">
                        <i class="fas fa-gavel text-rose-400 mr-2"></i>
                        Amonestaciones
                        <?php if ($activeWarnings > 0): ?>
                            <span class="ml-2 px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-300 text-xs"><?= $activeWarnings ?> activa<?= $activeWarnings === 1 ? '' : 's' ?></span>
                        <?php endif; ?>
                    </h3>
                    <button type="button" onclick="document.getElementById('warningModal').classList.remove('hidden')"
                            class="btn-primary text-sm">
                        <i class="fas fa-plus"></i> Registrar
                    </button>
                </div>
                <?php if (empty($warnings)): ?>
                    <p class="text-slate-400 text-center py-6">Sin amonestaciones registradas</p>
                <?php else: ?>
                    <div class="space-y-2" style="max-height: 20rem; overflow-y: auto;">
                        <?php foreach ($warnings as $w): ?>
                            <div class="bg-slate-800/50 rounded p-3 border-l-4 <?= $w['severity'] === 'MUY_GRAVE' ? 'border-rose-500' : ($w['severity'] === 'GRAVE' ? 'border-amber-500' : 'border-slate-500') ?>">
                                <div class="flex justify-between items-start gap-2">
                                    <p class="text-white text-sm font-semibold"><?= htmlspecialchars($w['subject']) ?></p>
                                    <span class="px-2 py-0.5 rounded text-xs whitespace-nowrap <?= $w['status'] === 'ACTIVA' ? 'bg-rose-500/20 text-rose-300' : 'bg-slate-600/30 text-slate-300' ?>">
                                        <?= htmlspecialchars($warningLabels['statuses'][$w['status']] ?? $w['status']) ?>
                                    </span>
                                </div>
                                <p class="text-slate-400 text-xs mt-1">
                                    <?= htmlspecialchars($warningLabels['types'][$w['warning_type']] ?? $w['warning_type']) ?>
                                    · <?= htmlspecialchars($warningLabels['severities'][$w['severity']] ?? $w['severity']) ?>
                                    · <?= date('d/m/Y', strtotime($w['incident_date'])) ?>
                                    <?php if (!empty($w['issued_by_name'])): ?> · por <?= htmlspecialchars($w['issued_by_name']) ?><?php endif; ?>
                                </p>
                                <?php if (!empty($w['description'])): ?>
                                    <p class="text-slate-300 text-xs mt-2"><?= nl2br(htmlspecialchars(mb_substr($w['description'], 0, 220))) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($w['suspension_days'])): ?>
                                    <p class="text-amber-300 text-xs mt-1">Suspensión: <?= rtrim(rtrim(number_format((float) $w['suspension_days'], 1), '0'), '.') ?> día(s)</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="glass-card">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-white">
                        <i class="fas fa-notes-medical text-emerald-400 mr-2"></i>
                        Licencias Médicas
                    </h3>
                    <button type="button" onclick="document.getElementById('leaveModal').classList.remove('hidden')"
                            class="btn-primary text-sm">
                        <i class="fas fa-plus"></i> Registrar
                    </button>
                </div>
                <?php if (empty($medicalLeaves)): ?>
                    <p class="text-slate-400 text-center py-6">Sin licencias médicas registradas</p>
                <?php else: ?>
                    <div class="space-y-2" style="max-height: 20rem; overflow-y: auto;">
                        <?php foreach ($medicalLeaves as $ml): ?>
                            <div class="bg-slate-800/50 rounded p-3">
                                <div class="flex justify-between items-start gap-2">
                                    <p class="text-white text-sm font-semibold">
                                        <?= htmlspecialchars($ml['diagnosis'] ?: ($ml['leave_type'] ?: 'Licencia médica')) ?>
                                    </p>
                                    <span class="px-2 py-0.5 rounded text-xs whitespace-nowrap bg-slate-600/30 text-slate-300">
                                        <?= htmlspecialchars($ml['status']) ?>
                                    </span>
                                </div>
                                <p class="text-slate-400 text-xs mt-1">
                                    <?= date('d/m/Y', strtotime($ml['start_date'])) ?>
                                    <?php if (!empty($ml['end_date'])): ?> → <?= date('d/m/Y', strtotime($ml['end_date'])) ?><?php endif; ?>
                                    <?php if (!empty($ml['total_days'])): ?> · <?= rtrim(rtrim(number_format((float) $ml['total_days'], 1), '0'), '.') ?> día(s)<?php endif; ?>
                                </p>
                                <?php if (!empty($ml['doctor_name']) || !empty($ml['medical_center'])): ?>
                                    <p class="text-slate-400 text-xs mt-1">
                                        <?= htmlspecialchars(trim(($ml['doctor_name'] ?? '') . ' ' . ($ml['medical_center'] ? '· ' . $ml['medical_center'] : ''))) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========== Historial de pagos y de cambios salariales ========== -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="glass-card">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-white">
                        <i class="fas fa-money-check-dollar text-emerald-400 mr-2"></i>
                        Historial de Pagos
                    </h3>
                    <?php if (!empty($paymentHistory)): ?>
                        <span class="text-xs text-slate-400">
                            <?= (int) $paymentTotals['periods'] ?> período<?= $paymentTotals['periods'] === 1 ? '' : 's' ?>
                            · <?= (int) $paymentTotals['paid'] ?> pagado<?= $paymentTotals['paid'] === 1 ? '' : 's' ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($paymentHistory)): ?>
                    <p class="text-slate-400 text-center py-6">Sin nóminas generadas para este colaborador</p>
                <?php else: ?>
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                            <p class="text-xl font-bold text-white">RD$<?= number_format($paymentTotals['gross'], 2) ?></p>
                            <p class="text-slate-400 text-xs">Bruto acumulado</p>
                        </div>
                        <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                            <p class="text-xl font-bold text-emerald-400">RD$<?= number_format($paymentTotals['net'], 2) ?></p>
                            <p class="text-slate-400 text-xs">Neto acumulado</p>
                        </div>
                    </div>
                    <div class="overflow-auto" style="max-height: 20rem;">
                        <table class="w-full text-sm">
                            <thead class="text-slate-400 text-xs uppercase sticky top-0 bg-slate-900">
                                <tr>
                                    <th class="text-left p-2">Período</th>
                                    <th class="text-right p-2">Horas</th>
                                    <th class="text-right p-2">Bruto</th>
                                    <th class="text-right p-2">Neto</th>
                                    <th class="text-center p-2">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paymentHistory as $p): ?>
                                    <tr class="border-t border-slate-700/50">
                                        <td class="p-2">
                                            <span class="text-white"><?= htmlspecialchars($p['period_name']) ?></span>
                                            <span class="block text-xs text-slate-500">
                                                <?= date('d/m/Y', strtotime($p['start_date'])) ?> – <?= date('d/m/Y', strtotime($p['end_date'])) ?>
                                            </span>
                                        </td>
                                        <td class="p-2 text-right text-slate-300">
                                            <?= number_format((float) $p['total_hours'], 1) ?>
                                            <?php if ((float) $p['overtime_hours'] > 0): ?>
                                                <span class="block text-xs text-amber-400">+<?= number_format((float) $p['overtime_hours'], 1) ?> extra</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 text-right text-slate-300">RD$<?= number_format($p['gross_salary'], 2) ?></td>
                                        <td class="p-2 text-right text-white font-semibold">RD$<?= number_format($p['net_salary'], 2) ?></td>
                                        <td class="p-2 text-center">
                                            <?php if ($p['is_paid']): ?>
                                                <span class="px-2 py-0.5 rounded text-xs bg-emerald-500/20 text-emerald-300">Pagado</span>
                                                <?php if (!empty($p['paid_at'])): ?>
                                                    <span class="block text-xs text-slate-500"><?= date('d/m/Y', strtotime($p['paid_at'])) ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded text-xs bg-slate-600/30 text-slate-400">Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-arrow-trend-up text-cyan-400 mr-2"></i>
                    Cambios Salariales
                </h3>

                <?php if (empty($salaryHistory)): ?>
                    <p class="text-slate-400 text-center py-6">
                        Sin cambios salariales registrados.<br>
                        <span class="text-xs">Los próximos ajustes de sueldo o tarifa quedarán aquí con su fecha.</span>
                    </p>
                <?php else: ?>
                    <div class="space-y-2" style="max-height: 24rem; overflow-y: auto;">
                        <?php foreach ($salaryHistory as $s): ?>
                            <?php
                                $up = $s['diff'] > 0;
                                $down = $s['diff'] < 0;
                                $tipoLabel = $s['salary_type'] === 'HOURLY' ? 'Tarifa por hora' : 'Sueldo mensual';
                            ?>
                            <div class="bg-slate-800/50 rounded p-3 border-l-4 <?= $up ? 'border-emerald-500' : ($down ? 'border-rose-500' : 'border-slate-500') ?>">
                                <div class="flex justify-between items-start gap-2">
                                    <div>
                                        <p class="text-white text-sm">
                                            <span class="text-slate-400"><?= htmlspecialchars($s['old_label']) ?></span>
                                            <i class="fas fa-arrow-right text-slate-500 mx-1 text-xs"></i>
                                            <strong><?= htmlspecialchars($s['new_label']) ?></strong>
                                        </p>
                                        <p class="text-slate-400 text-xs mt-1">
                                            <?= htmlspecialchars($tipoLabel) ?> ·
                                            <?= date('d/m/Y', strtotime($s['date'])) ?>
                                            <?php if (!empty($s['by_name'])): ?> · por <?= htmlspecialchars($s['by_name']) ?><?php endif; ?>
                                        </p>
                                    </div>
                                    <?php if ($s['diff'] != 0): ?>
                                        <span class="text-sm font-bold whitespace-nowrap <?= $up ? 'text-emerald-400' : 'text-rose-400' ?>">
                                            <?= $up ? '+' : '' ?><?= number_format($s['diff'], 2) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($s['reason'])): ?>
                                    <p class="text-slate-300 text-xs mt-2"><?= htmlspecialchars($s['reason']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ================= Historial de campañas ================= -->
        <div class="glass-card mb-8">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-white">
                    <i class="fas fa-bullhorn text-indigo-400 mr-2"></i>
                    Historial de Campañas
                </h3>
                <button type="button" onclick="document.getElementById('campaignModal').classList.remove('hidden')"
                        class="btn-primary text-sm">
                    <i class="fas fa-plus"></i> Asignar campaña
                </button>
            </div>
            <?php if (!empty($activeRestaurants)): ?>
                <div class="mb-4 p-3 rounded-lg bg-orange-500/10 border border-orange-500/30">
                    <p class="text-orange-200 text-xs mb-2">
                        <i class="fas fa-utensils mr-1"></i>
                        <strong>Delivery:</strong> restaurantes que atiende, para repartir su costo en contabilidad.
                        La campaña de nómina no cambia.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($activeRestaurants as $r): ?>
                            <span class="px-2 py-1 rounded-full text-xs font-semibold text-white"
                                  style="background: <?= htmlspecialchars($r['color'] ?: '#f97316') ?>;">
                                <?= htmlspecialchars($r['restaurant_name']) ?>
                                · <?= rtrim(rtrim(number_format((float) $r['allocation_pct'], 2), '0'), '.') ?>%
                            </span>
                        <?php endforeach; ?>
                        <a href="delivery_restaurants.php" class="px-2 py-1 rounded-full text-xs bg-slate-700 text-slate-300 hover:bg-slate-600">
                            <i class="fas fa-pen"></i> Gestionar
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($campaignHistory)): ?>
                <p class="text-slate-400 text-center py-6">Sin campañas asignadas</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($campaignHistory as $camp): ?>
                        <?php $vigente = empty($camp['end_date']); ?>
                        <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-800/50 rounded p-3">
                            <div class="flex items-center gap-3">
                                <span class="w-3 h-3 rounded-full" style="background: <?= htmlspecialchars($camp['campaign_color'] ?: '#6366f1') ?>;"></span>
                                <div>
                                    <p class="text-white text-sm font-semibold">
                                        <?= htmlspecialchars($camp['campaign_name'] ?? 'Campaña eliminada') ?>
                                        <?php if (!empty($camp['is_primary'])): ?>
                                            <span class="ml-1 px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 text-xs">principal</span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="text-slate-400 text-xs">
                                        <?= $camp['start_date'] ? date('d/m/Y', strtotime($camp['start_date'])) : 'sin fecha' ?>
                                        → <?= $vigente ? 'actual' : date('d/m/Y', strtotime($camp['end_date'])) ?>
                                        <?php if (!empty($camp['assigned_by_name'])): ?> · asignó <?= htmlspecialchars($camp['assigned_by_name']) ?><?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-1 rounded text-xs <?= $vigente ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-600/30 text-slate-400' ?>">
                                    <?= $vigente ? 'Vigente' : 'Finalizada' ?>
                                </span>
                                <?php if ($vigente): ?>
                                    <form method="POST" action="employee_profile_actions.php" class="inline"
                                          onsubmit="return confirm('¿Finalizar la asignación a esta campaña?');">
                                        <input type="hidden" name="action" value="end_campaign">
                                        <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $camp['id'] ?>">
                                        <button type="submit" class="text-slate-400 hover:text-rose-300 text-xs" title="Finalizar asignación">
                                            <i class="fas fa-xmark"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============ Estado del expediente documental ============ -->
        <div class="glass-card mb-8">
            <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
                <h3 class="text-lg font-semibold text-white">
                    <i class="fas fa-clipboard-check text-cyan-400 mr-2"></i>
                    Documentación Requerida
                    <span class="ml-2 px-2 py-0.5 rounded-full text-xs <?= $docStatus['is_complete'] ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300' ?>">
                        <?= (int) $docStatus['pct'] ?>% completo
                    </span>
                </h3>
                <a href="employee_documents.php?id=<?= (int) $employeeId ?>" class="btn-secondary text-sm">
                    <i class="fas fa-folder-open"></i> Gestionar documentos
                </a>
            </div>

            <?php if (!$personalStatus['is_complete']): ?>
                <div class="mb-4 p-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-200 text-sm">
                    <i class="fas fa-triangle-exclamation mr-1"></i>
                    Información personal incompleta (<?= (int) $personalStatus['pct'] ?>%). Faltan:
                    <strong><?= htmlspecialchars(implode(', ', $personalStatus['missing'])) ?></strong>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <?php foreach ($docStatus['items'] as $item): ?>
                    <div class="flex items-center justify-between gap-3 bg-slate-800/50 rounded p-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <i class="fas <?= $item['present'] ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-rose-400' ?>"></i>
                            <div class="min-w-0">
                                <p class="text-white text-sm truncate"><?= htmlspecialchars($item['label']) ?></p>
                                <p class="text-slate-400 text-xs">
                                    <?php if ($item['present']): ?>
                                        Cargado<?= $item['uploaded_at'] ? ' ' . date('d/m/Y', strtotime($item['uploaded_at'])) : '' ?>
                                    <?php else: ?>
                                        Pendiente
                                    <?php endif; ?>
                                    <?php if ($item['requires_signature']): ?>
                                        <?php if (($item['signature_status'] ?? '') === 'FIRMADO'): ?>
                                            · <span class="text-emerald-300">firmado</span>
                                        <?php elseif (($item['signature_status'] ?? '') === 'PENDIENTE'): ?>
                                            · <span class="text-amber-300">firma pendiente</span>
                                        <?php else: ?>
                                            · requiere firma
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <?php if ($item['present'] && !empty($item['file_path'])): ?>
                                <a href="preview_employee_document.php?id=<?= (int) $item['document_id'] ?>" target="_blank"
                                   class="text-slate-400 hover:text-white text-sm" title="Ver documento">
                                    <i class="fas fa-eye"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($item['requires_signature'] && ($item['signature_status'] ?? '') !== 'FIRMADO'): ?>
                                <form method="POST" action="employee_profile_actions.php" class="inline">
                                    <input type="hidden" name="action" value="request_signature">
                                    <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
                                    <input type="hidden" name="doc_key" value="<?= htmlspecialchars($item['doc_key']) ?>">
                                    <button type="submit" class="text-cyan-300 hover:text-cyan-200 text-xs whitespace-nowrap"
                                            title="Generar enlace de firma para el colaborador">
                                        <i class="fas fa-signature"></i>
                                        <?= ($item['signature_status'] ?? '') === 'PENDIENTE' ? 'Ver enlace' : 'Solicitar firma' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Documents Section -->
        <div class="glass-card mb-8">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-white">
                    <i class="fas fa-folder-open text-blue-400 mr-2"></i>
                    Record Digital de HR
                </h3>
                <a href="employee_documents.php?id=<?= $employeeId ?>" class="btn-primary">
                    <i class="fas fa-folder"></i>
                    Ver Todos los Documentos
                </a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-slate-800/50 rounded-lg p-4 text-center">
                    <i class="fas fa-file-alt text-4xl text-blue-400 mb-2"></i>
                    <p class="text-2xl font-bold text-white"><?= $documentCount ?></p>
                    <p class="text-slate-400 text-sm">Documentos</p>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-4 flex items-center justify-center">
                    <a href="employee_documents.php?id=<?= $employeeId ?>" class="text-center">
                        <i class="fas fa-id-card text-3xl text-green-400 mb-2"></i>
                        <p class="text-white text-sm">Identificación</p>
                    </a>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-4 flex items-center justify-center">
                    <a href="employee_documents.php?id=<?= $employeeId ?>" class="text-center">
                        <i class="fas fa-graduation-cap text-3xl text-purple-400 mb-2"></i>
                        <p class="text-white text-sm">Educación</p>
                    </a>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-4 flex items-center justify-center">
                    <a href="employee_documents.php?id=<?= $employeeId ?>" class="text-center">
                        <i class="fas fa-briefcase text-3xl text-yellow-400 mb-2"></i>
                        <p class="text-white text-sm">Laboral</p>
                    </a>
                </div>
            </div>
            <div class="mt-4 p-4 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                <p class="text-blue-300 text-sm">
                    <i class="fas fa-info-circle mr-2"></i>
                    Sistema completo de gestión documental para mantener el record de HR digitalizado. 
                    Sube cédulas, títulos, certificados, contratos y cualquier documento del empleado.
                </p>
            </div>
        </div>

        <!-- Requests -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4"><i class="fas fa-clipboard-list text-purple-400 mr-2"></i>Permisos Recientes</h3>
                <?php if (empty($permissionsList)): ?>
                    <p class="text-slate-400 text-center py-4">Sin solicitudes</p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach (array_slice($permissionsList, 0, 5) as $perm): ?>
                            <div class="bg-slate-800/50 rounded p-3">
                                <div class="flex justify-between items-start">
                                    <p class="text-white text-sm font-semibold"><?= str_replace('_', ' ', ucwords(strtolower($perm['request_type']))) ?></p>
                                    <span class="px-2 py-1 rounded text-xs text-white <?= $perm['status'] === 'APPROVED' ? 'bg-green-500' : ($perm['status'] === 'PENDING' ? 'bg-yellow-500' : 'bg-red-500') ?>">
                                        <?= $perm['status'] ?>
                                    </span>
                                </div>
                                <p class="text-slate-400 text-xs mt-1"><?= date('d/m/Y', strtotime($perm['start_date'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4"><i class="fas fa-umbrella-beach text-cyan-400 mr-2"></i>Vacaciones Recientes</h3>
                <?php if (empty($vacationsList)): ?>
                    <p class="text-slate-400 text-center py-4">Sin solicitudes</p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach (array_slice($vacationsList, 0, 5) as $vac): ?>
                            <div class="bg-slate-800/50 rounded p-3">
                                <div class="flex justify-between items-start">
                                    <p class="text-white text-sm font-semibold"><?= str_replace('_', ' ', ucwords(strtolower($vac['vacation_type']))) ?></p>
                                    <span class="px-2 py-1 rounded text-xs text-white <?= $vac['status'] === 'APPROVED' ? 'bg-green-500' : ($vac['status'] === 'PENDING' ? 'bg-yellow-500' : 'bg-red-500') ?>">
                                        <?= $vac['status'] ?>
                                    </span>
                                </div>
                                <p class="text-slate-400 text-xs mt-1"><?= date('d/m/Y', strtotime($vac['start_date'])) ?> - <?= date('d/m/Y', strtotime($vac['end_date'])) ?> (<?= $vac['total_days'] ?> días)</p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===================== Modales de registro ===================== -->
    <?php
        $modalInput = 'w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 text-white';
        $modalLabel = 'block text-slate-300 text-sm mb-1';
    ?>

    <!-- Amonestación -->
    <div id="warningModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,.7);">
        <div class="glass-card w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-white"><i class="fas fa-gavel text-rose-400 mr-2"></i>Registrar amonestación</h3>
                <button type="button" onclick="document.getElementById('warningModal').classList.add('hidden')" class="text-slate-400 hover:text-white"><i class="fas fa-xmark"></i></button>
            </div>
            <form method="POST" action="employee_profile_actions.php" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="action" value="add_warning">
                <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
                <div>
                    <label class="<?= $modalLabel ?>">Asunto *</label>
                    <input type="text" name="subject" required maxlength="255" class="<?= $modalInput ?>"
                           placeholder="Ej: Ausencia sin notificar del 12 de julio">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="<?= $modalLabel ?>">Tipo *</label>
                        <select name="warning_type" class="<?= $modalInput ?>">
                            <?php foreach ($warningLabels['types'] as $k => $v): ?>
                                <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="<?= $modalLabel ?>">Gravedad *</label>
                        <select name="severity" class="<?= $modalInput ?>">
                            <?php foreach ($warningLabels['severities'] as $k => $v): ?>
                                <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="<?= $modalLabel ?>">Fecha del hecho *</label>
                        <input type="date" name="incident_date" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="<?= $modalInput ?>">
                    </div>
                </div>
                <div>
                    <label class="<?= $modalLabel ?>">Descripción</label>
                    <textarea name="description" rows="3" class="<?= $modalInput ?>" placeholder="Qué ocurrió"></textarea>
                </div>
                <div>
                    <label class="<?= $modalLabel ?>">Medida correctiva</label>
                    <textarea name="corrective_action" rows="2" class="<?= $modalInput ?>" placeholder="Qué se acordó"></textarea>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="<?= $modalLabel ?>">Días de suspensión</label>
                        <input type="number" step="0.5" min="0" name="suspension_days" class="<?= $modalInput ?>" placeholder="0">
                    </div>
                    <div>
                        <label class="<?= $modalLabel ?>">Documento de respaldo</label>
                        <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png" class="<?= $modalInput ?>">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('warningModal').classList.add('hidden')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Licencia médica -->
    <div id="leaveModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,.7);">
        <div class="glass-card w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-white"><i class="fas fa-notes-medical text-emerald-400 mr-2"></i>Registrar licencia médica</h3>
                <button type="button" onclick="document.getElementById('leaveModal').classList.add('hidden')" class="text-slate-400 hover:text-white"><i class="fas fa-xmark"></i></button>
            </div>
            <form method="POST" action="employee_profile_actions.php" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="action" value="add_medical_leave">
                <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="<?= $modalLabel ?>">Tipo *</label>
                        <select name="leave_type" class="<?= $modalInput ?>">
                            <option value="ENFERMEDAD">Enfermedad común</option>
                            <option value="ACCIDENTE">Accidente</option>
                            <option value="MATERNIDAD">Maternidad</option>
                            <option value="PATERNIDAD">Paternidad</option>
                            <option value="CIRUGIA">Cirugía</option>
                            <option value="OTRO">Otro</option>
                        </select>
                    </div>
                    <div>
                        <label class="<?= $modalLabel ?>">Diagnóstico</label>
                        <input type="text" name="diagnosis" maxlength="255" class="<?= $modalInput ?>">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="<?= $modalLabel ?>">Desde *</label>
                        <input type="date" name="start_date" required class="<?= $modalInput ?>">
                    </div>
                    <div>
                        <label class="<?= $modalLabel ?>">Hasta *</label>
                        <input type="date" name="end_date" required class="<?= $modalInput ?>">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="<?= $modalLabel ?>">Médico</label>
                        <input type="text" name="doctor_name" maxlength="150" class="<?= $modalInput ?>">
                    </div>
                    <div>
                        <label class="<?= $modalLabel ?>">Centro médico</label>
                        <input type="text" name="medical_center" maxlength="200" class="<?= $modalInput ?>">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="<?= $modalLabel ?>">No. certificado</label>
                        <input type="text" name="medical_certificate_number" maxlength="100" class="<?= $modalInput ?>">
                    </div>
                    <div>
                        <label class="<?= $modalLabel ?>">Certificado médico</label>
                        <input type="file" name="medical_certificate_file" accept=".pdf,.jpg,.jpeg,.png" class="<?= $modalInput ?>">
                    </div>
                </div>
                <label class="inline-flex items-center gap-2 text-slate-300 text-sm">
                    <input type="checkbox" name="is_paid" value="1" checked class="w-4 h-4">
                    Licencia con goce de sueldo
                </label>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('leaveModal').classList.add('hidden')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Asignar campaña -->
    <div id="campaignModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,.7);">
        <div class="glass-card w-full max-w-md">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-white"><i class="fas fa-bullhorn text-indigo-400 mr-2"></i>Asignar campaña</h3>
                <button type="button" onclick="document.getElementById('campaignModal').classList.add('hidden')" class="text-slate-400 hover:text-white"><i class="fas fa-xmark"></i></button>
            </div>
            <form method="POST" action="employee_profile_actions.php" class="space-y-3">
                <input type="hidden" name="action" value="assign_campaign">
                <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
                <div>
                    <label class="<?= $modalLabel ?>">Campaña *</label>
                    <select name="campaign_id" required class="<?= $modalInput ?>">
                        <option value="">Seleccionar...</option>
                        <?php foreach ($allCampaigns as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Un colaborador puede estar en varias campañas a la vez.</p>
                </div>
                <div>
                    <label class="<?= $modalLabel ?>">Desde</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" class="<?= $modalInput ?>">
                </div>
                <label class="inline-flex items-center gap-2 text-slate-300 text-sm">
                    <input type="checkbox" name="is_primary" value="1" class="w-4 h-4">
                    Marcar como campaña principal
                </label>
                <p class="text-xs text-slate-400">La principal es la que se muestra en los monitores y reportes.</p>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('campaignModal').classList.add('hidden')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Asignar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Finalizar relación laboral -->
    <div id="terminateModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,.7);">
        <div class="glass-card w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-white"><i class="fas fa-door-open text-rose-400 mr-2"></i>Finalizar relación laboral</h3>
                <button type="button" onclick="document.getElementById('terminateModal').classList.add('hidden')" class="text-slate-400 hover:text-white"><i class="fas fa-xmark"></i></button>
            </div>
            <form method="POST" action="employee_profile_actions.php" class="space-y-3"
                  onsubmit="return confirm('Esto marca al colaborador como TERMINADO, cierra sus campañas y desactiva su acceso. ¿Continuar?');">
                <input type="hidden" name="action" value="terminate">
                <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="<?= $modalLabel ?>">Fecha de salida *</label>
                        <input type="date" name="termination_date" required value="<?= date('Y-m-d') ?>" class="<?= $modalInput ?>">
                    </div>
                    <div>
                        <label class="<?= $modalLabel ?>">Motivo *</label>
                        <select name="termination_reason" required class="<?= $modalInput ?>">
                            <option value="">Seleccionar...</option>
                            <?php foreach ($terminationLabels['reasons'] as $k => $v): ?>
                                <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="<?= $modalLabel ?>">Detalle del motivo</label>
                    <textarea name="termination_notes" rows="2" class="<?= $modalInput ?>"></textarea>
                </div>

                <div>
                    <label class="<?= $modalLabel ?>">¿Recontratable? *</label>
                    <select name="rehire_eligibility" required class="<?= $modalInput ?>">
                        <option value="">Seleccionar...</option>
                        <?php foreach ($terminationLabels['rehire'] as $k => $v): ?>
                            <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Queda en el expediente para futuras contrataciones.</p>
                </div>
                <div>
                    <label class="<?= $modalLabel ?>">Notas sobre recontratación</label>
                    <textarea name="rehire_notes" rows="2" class="<?= $modalInput ?>" placeholder="Por qué sí o por qué no"></textarea>
                </div>

                <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-200 text-xs">
                    <i class="fas fa-triangle-exclamation mr-1"></i>
                    Al guardar: el estado pasa a TERMINADO, se cierran sus campañas vigentes y se desactiva su usuario.
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('terminateModal').classList.add('hidden')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary" style="background:#e11d48;"><i class="fas fa-door-open"></i> Finalizar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Cerrar modales con Escape o clic fuera
        ['warningModal', 'leaveModal', 'campaignModal', 'terminateModal'].forEach(function (id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('click', function (e) {
                if (e.target === el) el.classList.add('hidden');
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            ['warningModal', 'leaveModal', 'campaignModal', 'terminateModal'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) el.classList.add('hidden');
            });
        });
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>
