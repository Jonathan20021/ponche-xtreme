<?php
session_start();

include '../db.php';
require_once __DIR__ . '/loans_api_client.php';

$isAgentContext = strtoupper((string) ($_SESSION['role'] ?? '')) === 'AGENT';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ($isAgentContext ? '../login_agent.php' : '../index.php'));
    exit;
}

$headerFile = $isAgentContext ? '../header_agent.php' : '../header.php';
$footerFile = $isAgentContext ? '../footer.php' : '../footer.php';
$backHref   = $isAgentContext ? '../agent_dashboard.php' : '../dashboard.php';

$user_id = (int) $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'Agente';

// Localizar al empleado en la tabla employees por user_id
$stmt = $pdo->prepare("SELECT e.id, e.employee_code,
                              TRIM(CONCAT_WS(' ', e.first_name, e.last_name)) AS name,
                              e.position, e.id_card_number, e.email,
                              d.name AS department
                       FROM employees e
                       LEFT JOIN departments d ON d.id = e.department_id
                       WHERE e.user_id = ?
                       LIMIT 1");
$stmt->execute([$user_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    $errorBootstrap = 'Tu cuenta no está vinculada a un perfil de empleado activo. Contacta a RR.HH.';
}

// Obtener tipos de préstamo disponibles
$loanTypes = $employee ? getLoanTypesFromFinance() : [];

// Manejar envío del formulario
$flash = null;
$createdLoan = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employee && isset($_POST['action']) && $_POST['action'] === 'create_request') {
    $loanTypeCode = trim($_POST['loan_type_code'] ?? '');
    $principalAmount = (float) ($_POST['principal_amount'] ?? 0);
    $installmentCount = (int) ($_POST['installment_count'] ?? 0);
    $installmentFrequency = $_POST['installment_frequency'] ?? 'monthly';
    $purpose = trim($_POST['purpose'] ?? '');
    $hasGuarantor = !empty($_POST['has_guarantor']);
    $guarantorName = trim($_POST['guarantor_name'] ?? '');
    $guarantorDocument = trim($_POST['guarantor_document'] ?? '');
    $guarantorPhone = trim($_POST['guarantor_phone'] ?? '');
    $employeeConsent = !empty($_POST['employee_consent']);
    $firstDueDate = trim($_POST['first_due_date'] ?? '');

    if (!$loanTypeCode || $principalAmount <= 0 || $installmentCount <= 0) {
        $flash = ['type' => 'error', 'msg' => 'Completa todos los campos requeridos con valores válidos.'];
    } elseif (!$employeeConsent) {
        $flash = ['type' => 'error', 'msg' => 'Debes aceptar la autorización de descuento por nómina (Art. 200 Código de Trabajo).'];
    } else {
        $payload = [
            'employee_external_id'  => (int) $employee['id'],
            'loan_type_code'        => $loanTypeCode,
            'principal_amount'      => $principalAmount,
            'installment_count'     => $installmentCount,
            'installment_frequency' => $installmentFrequency,
            'currency'              => 'DOP',
            'purpose'               => $purpose,
            'has_guarantor'         => $hasGuarantor,
            'guarantor_name'        => $guarantorName,
            'guarantor_document'    => $guarantorDocument,
            'guarantor_phone'       => $guarantorPhone,
            'first_due_date'        => $firstDueDate ?: null,
            'employee_consent'      => $employeeConsent,
            'source'                => 'agent_portal',
        ];
        $r = createLoanRequestInFinance($payload);
        if ($r['ok'] && !empty($r['data']['success'])) {
            $createdLoan = $r['data'];
            $flash = ['type' => 'success', 'msg' => 'Solicitud enviada con éxito. Será revisada por el área de finanzas.'];
        } else {
            $flash = ['type' => 'error', 'msg' => $r['error'] ?? 'No fue posible enviar la solicitud.'];
        }
    }
}
?>
<?php include $headerFile; ?>

<style>
    .loan-bg {
        position: fixed; inset: 0; z-index: -1; pointer-events: none;
        background:
            radial-gradient(ellipse 700px 400px at 8% -5%, rgba(16,185,129,.16), transparent 55%),
            radial-gradient(ellipse 600px 500px at 95% 0%, rgba(59,130,246,.13), transparent 55%),
            radial-gradient(ellipse 700px 400px at 50% 110%, rgba(168,85,247,.10), transparent 50%);
    }
    .loan-mono { font-family: 'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, monospace; letter-spacing: -0.02em; }

    /* ---------- HERO ---------- */
    .loan-hero {
        background: linear-gradient(135deg, rgba(16,185,129,.22) 0%, rgba(15,23,42,.5) 45%, rgba(59,130,246,.2) 100%);
        border: 1px solid rgba(148,163,184,.22);
        border-radius: 1.25rem;
        padding: 1.75rem 2rem;
        position: relative;
        overflow: hidden;
        backdrop-filter: blur(14px);
    }
    .loan-hero::before {
        content: ''; position: absolute; top: -40%; right: -10%;
        width: 480px; height: 480px;
        background: radial-gradient(circle, rgba(16,185,129,.25), transparent 60%);
        pointer-events: none;
    }
    .loan-hero h1 {
        font-size: 2.1rem; font-weight: 800;
        background: linear-gradient(135deg, #f1f5f9, #94a3b8);
        -webkit-background-clip: text; background-clip: text; color: transparent;
        line-height: 1.1; letter-spacing: -0.03em;
    }
    .badge-secure {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .68rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
        padding: .35rem .8rem;
        background: rgba(16,185,129,.15); color: #6ee7b7;
        border: 1px solid rgba(16,185,129,.3);
        border-radius: 999px;
    }
    .badge-secure .dot {
        width: 6px; height: 6px; border-radius: 50%; background: #10b981;
        box-shadow: 0 0 0 0 rgba(16,185,129,.7); animation: secpulse 2s infinite;
    }
    @keyframes secpulse {
        0%   { box-shadow: 0 0 0 0   rgba(16,185,129,.6); }
        70%  { box-shadow: 0 0 0 7px rgba(16,185,129,0); }
        100% { box-shadow: 0 0 0 0   rgba(16,185,129,0); }
    }

    /* ---------- PANEL ---------- */
    .panel-card {
        background: linear-gradient(135deg, rgba(15,23,42,.72), rgba(15,23,42,.45));
        border: 1px solid rgba(148,163,184,.14);
        border-radius: 1.1rem;
        padding: 1.5rem;
        backdrop-filter: blur(10px);
    }
    .panel-title {
        font-size: 1rem; font-weight: 700; color: white;
        display: flex; align-items: center; gap: .6rem;
        margin-bottom: 1.1rem;
    }
    .panel-title .num {
        width: 26px; height: 26px; border-radius: .5rem;
        background: linear-gradient(135deg, #10b981, #059669);
        color: white; display: flex; align-items: center; justify-content: center;
        font-size: .8rem; font-weight: 700;
        box-shadow: 0 4px 12px -3px rgba(16,185,129,.5);
    }

    /* ---------- EMPLOYEE TILE ---------- */
    .emp-tile {
        background: linear-gradient(135deg, rgba(16,185,129,.08), rgba(15,23,42,.4));
        border: 1px solid rgba(148,163,184,.15);
        border-radius: .85rem;
        padding: 1rem 1.1rem;
    }
    .emp-tile .lbl {
        font-size: .65rem; font-weight: 700;
        color: #94a3b8; text-transform: uppercase; letter-spacing: .1em;
        margin-bottom: .3rem;
    }
    .emp-tile .val { color: white; font-weight: 600; font-size: .92rem; }
    .emp-avatar {
        width: 56px; height: 56px; border-radius: 50%;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        display: flex; align-items: center; justify-content: center;
        color: white; font-weight: 700; font-size: 1.2rem;
        box-shadow: 0 8px 20px -6px rgba(16,185,129,.45);
    }

    /* ---------- INPUTS ---------- */
    .field-label {
        display: flex; align-items: center; gap: .5rem;
        font-size: .75rem; font-weight: 600;
        color: #cbd5e1; text-transform: uppercase; letter-spacing: .06em;
        margin-bottom: .5rem;
    }
    .field-label .field-icon {
        width: 22px; height: 22px; border-radius: .35rem;
        background: rgba(16,185,129,.15); color: #6ee7b7;
        display: flex; align-items: center; justify-content: center;
        font-size: .7rem;
    }
    .field-label .req { color: #fbbf24; font-weight: 800; }
    .input-control {
        width: 100%;
        background: rgba(15,23,42,.7);
        border: 1px solid rgba(148,163,184,.22);
        border-radius: .65rem;
        padding: .75rem .9rem;
        color: white;
        font-size: .95rem;
        transition: all .2s ease;
    }
    .input-control:focus {
        outline: none;
        border-color: #10b981;
        background: rgba(15,23,42,.85);
        box-shadow: 0 0 0 3px rgba(16,185,129,.18);
    }
    .input-control:hover:not(:focus) { border-color: rgba(148,163,184,.4); }

    .input-with-prefix {
        position: relative;
    }
    .input-with-prefix .prefix {
        position: absolute; left: .9rem; top: 50%; transform: translateY(-50%);
        color: #6ee7b7; font-weight: 700; font-size: .9rem;
        pointer-events: none;
    }
    .input-with-prefix .input-control { padding-left: 3rem; }

    .help-hint {
        font-size: .72rem; color: #94a3b8;
        margin-top: .4rem;
        display: flex; align-items: center; gap: .35rem;
    }

    /* ---------- TYPE INFO ---------- */
    .type-info {
        margin-top: .75rem;
        padding: .85rem 1rem;
        background: linear-gradient(135deg, rgba(16,185,129,.1), rgba(15,23,42,.6));
        border: 1px solid rgba(16,185,129,.25);
        border-radius: .65rem;
        font-size: .82rem;
        color: #cbd5e1;
    }
    .type-info-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: .75rem; margin-top: .5rem;
    }
    .type-info-cell {
        background: rgba(15,23,42,.5);
        border: 1px solid rgba(148,163,184,.12);
        border-radius: .5rem; padding: .5rem .7rem;
    }
    .type-info-cell .lbl { font-size: .62rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .08em; }
    .type-info-cell .val { font-weight: 700; color: #6ee7b7; }

    /* ---------- CHECKBOX CARD ---------- */
    .check-card {
        display: flex; align-items: flex-start; gap: .85rem;
        padding: 1rem 1.1rem;
        background: rgba(15,23,42,.55);
        border: 1px solid rgba(148,163,184,.18);
        border-radius: .85rem;
        cursor: pointer;
        transition: all .2s ease;
    }
    .check-card:hover { border-color: rgba(16,185,129,.4); background: rgba(15,23,42,.7); }
    .check-card input[type=checkbox] {
        width: 20px; height: 20px;
        accent-color: #10b981;
        flex-shrink: 0; margin-top: 2px;
    }
    .check-card-text { flex: 1; }
    .check-card-text .title { color: white; font-weight: 600; font-size: .92rem; }
    .check-card-text .sub { color: #94a3b8; font-size: .78rem; margin-top: .2rem; }

    /* ---------- CONSENT ---------- */
    .consent-card {
        background: linear-gradient(135deg, rgba(59,130,246,.1), rgba(168,85,247,.05));
        border: 1px solid rgba(59,130,246,.3);
        border-radius: .9rem;
        padding: 1.1rem 1.2rem;
        position: relative;
        overflow: hidden;
    }
    .consent-card::before {
        content: '\f3ed';
        font-family: 'Font Awesome 6 Free'; font-weight: 900;
        position: absolute; right: -10px; bottom: -10px;
        font-size: 6rem; color: rgba(59,130,246,.06);
    }
    .consent-card .title-row {
        display: flex; align-items: center; gap: .6rem; margin-bottom: .6rem;
    }
    .consent-card .shield {
        width: 32px; height: 32px; border-radius: .5rem;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        color: white; display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .consent-card .legal-note {
        font-size: .82rem; color: #cbd5e1; line-height: 1.55;
    }
    .consent-card .legal-note strong { color: white; }
    .consent-card .legal-note .law { color: #93c5fd; font-weight: 700; }

    /* ---------- BUTTONS ---------- */
    .btn-cancel {
        padding: .75rem 1.25rem;
        background: rgba(15,23,42,.6);
        border: 1px solid rgba(148,163,184,.25);
        color: #cbd5e1; font-weight: 600;
        border-radius: .7rem;
        transition: all .2s ease;
        text-decoration: none;
        display: inline-flex; align-items: center; gap: .5rem;
    }
    .btn-cancel:hover { background: rgba(15,23,42,.85); color: white; }

    .btn-submit {
        padding: .85rem 1.75rem;
        background: linear-gradient(135deg, #10b981, #059669);
        color: white; font-weight: 700;
        border-radius: .75rem;
        transition: all .2s ease;
        display: inline-flex; align-items: center; gap: .6rem;
        box-shadow: 0 10px 24px -8px rgba(16,185,129,.55);
        font-size: .95rem;
        border: none; cursor: pointer;
    }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 16px 32px -8px rgba(16,185,129,.7); }
    .btn-submit:active { transform: translateY(0); }

    /* ---------- CALCULATOR SIDEBAR ---------- */
    .calc-panel {
        position: sticky; top: 1.5rem;
        background:
            radial-gradient(ellipse 300px 200px at 90% 0%, rgba(16,185,129,.15), transparent 65%),
            linear-gradient(135deg, rgba(15,23,42,.85), rgba(15,23,42,.6));
        border: 1px solid rgba(16,185,129,.25);
        border-radius: 1.1rem;
        padding: 1.5rem;
        overflow: hidden;
    }
    .calc-row {
        display: flex; justify-content: space-between; align-items: baseline;
        padding: .7rem 0;
        border-bottom: 1px dashed rgba(148,163,184,.15);
    }
    .calc-row:last-child { border-bottom: 0; }
    .calc-row .lbl { font-size: .78rem; color: #94a3b8; }
    .calc-row .val { font-weight: 700; color: white; font-family: 'JetBrains Mono', monospace; }
    .calc-highlight {
        margin-top: 1rem; padding: 1.1rem 1.2rem;
        background: linear-gradient(135deg, rgba(16,185,129,.18), rgba(6,182,212,.08));
        border: 1px solid rgba(16,185,129,.35);
        border-radius: .85rem;
        text-align: center;
    }
    .calc-highlight .lbl { font-size: .7rem; color: #6ee7b7; text-transform: uppercase; letter-spacing: .1em; font-weight: 600; }
    .calc-highlight .val {
        font-family: 'JetBrains Mono', monospace;
        font-size: 1.85rem; font-weight: 800;
        background: linear-gradient(135deg, #6ee7b7, #06b6d4);
        -webkit-background-clip: text; background-clip: text; color: transparent;
        margin-top: .15rem;
        letter-spacing: -0.03em;
    }
    .calc-empty {
        padding: 2.5rem 1rem; text-align: center; color: #64748b;
    }
    .calc-empty i { font-size: 2.5rem; color: rgba(100,116,139,.4); margin-bottom: .75rem; }

    /* SUCCESS CARD */
    .success-card {
        background: linear-gradient(135deg, rgba(16,185,129,.15), rgba(6,182,212,.08));
        border: 1px solid rgba(16,185,129,.4);
        border-radius: 1.1rem;
        padding: 2rem;
        text-align: center;
        position: relative; overflow: hidden;
    }
    .success-card::before {
        content: ''; position: absolute; inset: 0;
        background: radial-gradient(circle at 50% 0%, rgba(16,185,129,.15), transparent 60%);
        pointer-events: none;
    }
    .success-icon {
        width: 72px; height: 72px; border-radius: 50%;
        background: linear-gradient(135deg, #10b981, #059669);
        color: white; font-size: 2rem;
        display: inline-flex; align-items: center; justify-content: center;
        box-shadow: 0 12px 30px -8px rgba(16,185,129,.55);
        margin-bottom: 1rem;
    }
    .success-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: .85rem; margin-top: 1.5rem;
    }
    .success-cell {
        background: rgba(15,23,42,.6);
        border: 1px solid rgba(148,163,184,.15);
        border-radius: .75rem; padding: .9rem;
    }
    .success-cell .lbl { font-size: .65rem; text-transform: uppercase; letter-spacing: .1em; color: #94a3b8; font-weight: 700; }
    .success-cell .val { font-family: 'JetBrains Mono', monospace; font-weight: 700; color: white; font-size: 1rem; margin-top: .25rem; }

    @media (max-width: 1024px) {
        .calc-panel { position: static; }
    }
    @media (max-width: 640px) {
        .loan-hero h1 { font-size: 1.5rem; }
        .loan-hero { padding: 1.25rem 1.25rem; }
    }
</style>

<div class="loan-bg"></div>

<div class="max-w-6xl mx-auto px-4 py-6">

    <!-- HERO -->
    <div class="loan-hero mb-6">
        <div class="flex flex-wrap items-start justify-between gap-4 relative">
            <div class="flex items-start gap-4">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center flex-shrink-0"
                     style="background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 12px 26px -8px rgba(16,185,129,.55);">
                    <i class="fas fa-hand-holding-dollar text-2xl text-white"></i>
                </div>
                <div>
                    <span class="badge-secure mb-2"><span class="dot"></span> Aprobacion segura</span>
                    <h1>Solicitar Prestamo</h1>
                    <p class="text-slate-300 mt-2 max-w-xl">Llena el formulario y tu solicitud llegara automaticamente al area de finanzas para revision y aprobacion.</p>
                </div>
            </div>
            <div class="flex flex-col gap-2 items-end">
                <a href="my_loans.php" class="text-sm text-cyan-300 hover:text-cyan-200 font-semibold flex items-center gap-1.5">
                    <i class="fas fa-list-check"></i> Mis prestamos
                </a>
                <a href="<?= htmlspecialchars($backHref) ?>" class="text-xs text-slate-400 hover:text-white flex items-center gap-1.5">
                    <i class="fas fa-arrow-left"></i> Volver al dashboard
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($errorBootstrap)): ?>
        <div class="panel-card border-l-4 mb-6" style="border-color:#f43f5e; background: linear-gradient(135deg, rgba(244,63,94,.1), rgba(15,23,42,.6));">
            <p class="text-rose-300 flex items-center gap-2">
                <i class="fas fa-circle-exclamation"></i><?= htmlspecialchars($errorBootstrap) ?>
            </p>
        </div>
    <?php elseif ($flash && $flash['type'] === 'error'): ?>
        <div class="panel-card border-l-4 mb-6" style="border-color:#f43f5e; background: linear-gradient(135deg, rgba(244,63,94,.1), rgba(15,23,42,.6));">
            <p class="text-rose-300 flex items-center gap-2">
                <i class="fas fa-circle-exclamation"></i><?= htmlspecialchars($flash['msg']) ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($flash && $flash['type'] === 'success' && $createdLoan): ?>
        <div class="success-card mb-6 relative">
            <div class="success-icon"><i class="fas fa-check"></i></div>
            <h2 class="text-2xl font-bold text-white mb-1">Solicitud enviada con exito</h2>
            <p class="text-emerald-200 max-w-md mx-auto"><?= htmlspecialchars($flash['msg']) ?></p>

            <div class="success-grid mt-6">
                <div class="success-cell">
                    <div class="lbl">Numero</div>
                    <div class="val" style="color:#6ee7b7;"><?= htmlspecialchars($createdLoan['loan_number']) ?></div>
                </div>
                <div class="success-cell">
                    <div class="lbl">Cuota</div>
                    <div class="val">RD$ <?= number_format($createdLoan['installment_amount'], 2) ?></div>
                </div>
                <div class="success-cell">
                    <div class="lbl">Total a pagar</div>
                    <div class="val">RD$ <?= number_format($createdLoan['total_payable'], 2) ?></div>
                </div>
            </div>

            <?php if (!empty($createdLoan['affordability_warning'])): ?>
                <div class="mt-5 p-4 rounded-lg text-left" style="background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.35); color: #fde68a;">
                    <i class="fas fa-shield-halved mr-1.5 text-amber-300"></i>
                    <strong>Aviso Art. 201 CT:</strong> <?= htmlspecialchars($createdLoan['affordability_warning']) ?>
                </div>
            <?php endif; ?>

            <div class="flex gap-3 justify-center mt-6">
                <a href="my_loans.php" class="btn-submit"><i class="fas fa-list"></i> Ver mis prestamos</a>
                <a href="request_loan.php" class="btn-cancel"><i class="fas fa-rotate-right"></i> Nueva solicitud</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($employee && !$createdLoan): ?>

        <!-- EMPLOYEE INFO -->
        <div class="panel-card mb-6">
            <div class="flex flex-wrap items-center gap-5">
                <div class="emp-avatar"><?= htmlspecialchars(strtoupper(mb_substr($employee['name'], 0, 1)) . (strpos($employee['name'], ' ') !== false ? strtoupper(mb_substr(strrchr($employee['name'], ' '), 1, 1)) : '')) ?></div>
                <div class="flex-1 min-w-0">
                    <div class="text-white text-lg font-bold"><?= htmlspecialchars($employee['name']) ?></div>
                    <div class="text-sm text-slate-400">Empleado solicitante · <?= htmlspecialchars($employee['department'] ?? 'Sin departamento') ?></div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3 flex-1 min-w-[280px]">
                    <div class="emp-tile">
                        <div class="lbl"><i class="fas fa-id-badge mr-1"></i>Codigo</div>
                        <div class="val loan-mono"><?= htmlspecialchars($employee['employee_code']) ?></div>
                    </div>
                    <div class="emp-tile">
                        <div class="lbl"><i class="fas fa-address-card mr-1"></i>Cedula</div>
                        <div class="val loan-mono"><?= htmlspecialchars($employee['id_card_number'] ?? '—') ?></div>
                    </div>
                    <div class="emp-tile col-span-2 md:col-span-1">
                        <div class="lbl"><i class="fas fa-building mr-1"></i>Posicion</div>
                        <div class="val"><?= htmlspecialchars($employee['position'] ?? '—') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($loanTypes)): ?>
            <div class="panel-card border-l-4" style="border-color:#f59e0b;">
                <p class="text-amber-300">
                    <i class="fas fa-database mr-2"></i>
                    No fue posible cargar los tipos de prestamo. Verifica que el esquema
                    <code class="text-amber-200 bg-amber-900/30 px-1.5 py-0.5 rounded">hhempeos_financial_system</code>
                    este disponible y que la tabla <code class="text-amber-200 bg-amber-900/30 px-1.5 py-0.5 rounded">loan_types</code>
                    tenga registros con <code class="text-amber-200 bg-amber-900/30 px-1.5 py-0.5 rounded">is_active=1</code> y
                    <code class="text-amber-200 bg-amber-900/30 px-1.5 py-0.5 rounded">borrower_type='employee'</code>.
                </p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <!-- FORM -->
                <form method="POST" id="loanForm" class="lg:col-span-2 space-y-5">
                    <input type="hidden" name="action" value="create_request">

                    <!-- Section 1: Loan details -->
                    <div class="panel-card">
                        <h3 class="panel-title"><span class="num">1</span> Detalles del prestamo</h3>

                        <div class="space-y-5">
                            <div>
                                <label class="field-label"><span class="field-icon"><i class="fas fa-tag"></i></span> Tipo de prestamo <span class="req">*</span></label>
                                <select name="loan_type_code" id="loanType" required class="input-control" onchange="updateTypeInfo(this); calc();">
                                    <option value="">— Selecciona un tipo de prestamo —</option>
                                    <?php foreach ($loanTypes as $t): ?>
                                        <option value="<?= htmlspecialchars($t['code']) ?>"
                                                data-rate="<?= htmlspecialchars($t['default_interest_rate']) ?>"
                                                data-term="<?= htmlspecialchars($t['default_term_months']) ?>"
                                                data-max="<?= htmlspecialchars($t['max_amount'] ?? '') ?>"
                                                data-min="<?= htmlspecialchars($t['min_amount'] ?? '') ?>"
                                                data-desc="<?= htmlspecialchars($t['description'] ?? '') ?>">
                                            <?= htmlspecialchars($t['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="typeInfo" class="type-info hidden"></div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="field-label"><span class="field-icon"><i class="fas fa-dollar-sign"></i></span> Monto <span class="req">*</span></label>
                                    <div class="input-with-prefix">
                                        <span class="prefix">RD$</span>
                                        <input type="number" name="principal_amount" id="amount" min="100" step="100" required
                                               placeholder="0.00" class="input-control" oninput="calc()">
                                    </div>
                                </div>
                                <div>
                                    <label class="field-label"><span class="field-icon"><i class="fas fa-hashtag"></i></span> Cuotas <span class="req">*</span></label>
                                    <input type="number" name="installment_count" id="cuotas" min="1" max="60" value="12" required
                                           class="input-control" oninput="calc()">
                                </div>
                                <div>
                                    <label class="field-label"><span class="field-icon"><i class="fas fa-calendar-week"></i></span> Frecuencia <span class="req">*</span></label>
                                    <select name="installment_frequency" id="freq" required class="input-control" onchange="calc()">
                                        <option value="biweekly">Quincenal (recomendado)</option>
                                        <option value="monthly">Mensual</option>
                                        <option value="weekly">Semanal</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="field-label"><span class="field-icon"><i class="fas fa-calendar"></i></span> Primera fecha de vencimiento <span class="text-slate-500 text-xs">(opcional)</span></label>
                                <input type="date" name="first_due_date" class="input-control">
                                <p class="help-hint"><i class="fas fa-circle-info"></i> Si la dejas en blanco, finanzas la programara segun el proximo periodo de nomina.</p>
                            </div>

                            <div>
                                <label class="field-label"><span class="field-icon"><i class="fas fa-comment-dots"></i></span> Proposito del prestamo <span class="req">*</span></label>
                                <textarea name="purpose" rows="3" required
                                          placeholder="Ej: Reparacion de vivienda, gastos medicos, consolidacion de deudas, etc."
                                          class="input-control"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Optional guarantor -->
                    <div class="panel-card">
                        <h3 class="panel-title"><span class="num">2</span> Aval / Garante <span class="text-xs text-slate-500 font-normal ml-1">(opcional)</span></h3>

                        <label class="check-card" for="hasGuarantor">
                            <input type="checkbox" name="has_guarantor" id="hasGuarantor"
                                   onchange="document.getElementById('guarantorFields').classList.toggle('hidden', !this.checked)">
                            <div class="check-card-text">
                                <div class="title">Aporto un aval / garante</div>
                                <div class="sub">Aumenta la probabilidad de aprobacion para montos altos.</div>
                            </div>
                        </label>

                        <div id="guarantorFields" class="hidden mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="field-label"><span class="field-icon"><i class="fas fa-user"></i></span> Nombre del aval</label>
                                <input type="text" name="guarantor_name" class="input-control" placeholder="Nombre completo">
                            </div>
                            <div>
                                <label class="field-label"><span class="field-icon"><i class="fas fa-id-card"></i></span> Cedula del aval</label>
                                <input type="text" name="guarantor_document" class="input-control" placeholder="000-0000000-0">
                            </div>
                            <div>
                                <label class="field-label"><span class="field-icon"><i class="fas fa-phone"></i></span> Telefono del aval</label>
                                <input type="text" name="guarantor_phone" class="input-control" placeholder="809-000-0000">
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Legal consent -->
                    <div class="panel-card">
                        <h3 class="panel-title"><span class="num">3</span> Autorizacion legal</h3>

                        <div class="consent-card">
                            <div class="title-row">
                                <div class="shield"><i class="fas fa-shield-halved"></i></div>
                                <div>
                                    <div class="text-white font-bold">Autorizacion de descuento por nomina</div>
                                    <div class="text-xs text-blue-200/80 uppercase tracking-widest mt-0.5">Codigo de Trabajo R.D.</div>
                                </div>
                            </div>
                            <label class="flex items-start gap-3 cursor-pointer mt-3" style="position:relative; z-index:1;">
                                <input type="checkbox" name="employee_consent" required class="mt-1" style="width: 20px; height: 20px; accent-color: #10b981;">
                                <span class="legal-note">
                                    <strong>Autorizo expresamente</strong> a la empresa, en cumplimiento del <span class="law">Art. 200 del Codigo de Trabajo de R.D.</span>,
                                    a descontar de mi salario las cuotas correspondientes hasta saldar este prestamo, sin exceder el
                                    <span class="law">33.33% del salario neto (Art. 201 CT)</span>. Reconozco que se registrara mi IP y fecha como constancia legal.
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Buttons -->
                    <div class="flex flex-wrap justify-end gap-3 pt-2">
                        <a href="<?= htmlspecialchars($backHref) ?>" class="btn-cancel"><i class="fas fa-xmark"></i> Cancelar</a>
                        <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Enviar Solicitud</button>
                    </div>
                </form>

                <!-- CALCULATOR SIDEBAR -->
                <div class="lg:col-span-1">
                    <div class="calc-panel">
                        <h3 class="panel-title" style="margin-bottom:.5rem;">
                            <span class="num" style="background: linear-gradient(135deg, #06b6d4, #0891b2);"><i class="fas fa-calculator"></i></span>
                            Resumen
                        </h3>
                        <p class="text-xs text-slate-400 mb-4">Calculo estimado en tiempo real. El monto final lo determina finanzas.</p>

                        <div id="calcContent">
                            <div id="calcEmpty" class="calc-empty">
                                <i class="fas fa-receipt block"></i>
                                <p class="text-sm">Completa el formulario para ver tu cuota estimada.</p>
                            </div>
                            <div id="calcResult" class="hidden">
                                <div class="calc-row">
                                    <span class="lbl">Monto solicitado</span>
                                    <span class="val" id="cMonto">—</span>
                                </div>
                                <div class="calc-row">
                                    <span class="lbl">Tasa mensual</span>
                                    <span class="val" id="cTasa">—</span>
                                </div>
                                <div class="calc-row">
                                    <span class="lbl">Cuotas</span>
                                    <span class="val" id="cCuotas">—</span>
                                </div>
                                <div class="calc-row">
                                    <span class="lbl">Frecuencia</span>
                                    <span class="val" id="cFreq">—</span>
                                </div>
                                <div class="calc-row">
                                    <span class="lbl">Intereses estimados</span>
                                    <span class="val" id="cIntereses" style="color:#fbbf24;">—</span>
                                </div>
                                <div class="calc-row">
                                    <span class="lbl">Total a pagar</span>
                                    <span class="val" id="cTotal">—</span>
                                </div>

                                <div class="calc-highlight">
                                    <div class="lbl">Cuota estimada</div>
                                    <div class="val" id="cCuota">RD$ 0.00</div>
                                </div>

                                <div id="cWarning" class="hidden mt-3 p-2.5 rounded-lg text-xs" style="background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.3); color: #fde68a;">
                                    <i class="fas fa-triangle-exclamation mr-1"></i>
                                    <span></span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 pt-4 border-t border-slate-700/40">
                            <div class="text-xs text-slate-400 space-y-1.5">
                                <div class="flex items-center gap-1.5"><i class="fas fa-shield text-emerald-400 text-xs"></i> Datos protegidos y cifrados</div>
                                <div class="flex items-center gap-1.5"><i class="fas fa-clock text-cyan-400 text-xs"></i> Respuesta en 24-48 horas</div>
                                <div class="flex items-center gap-1.5"><i class="fas fa-balance-scale text-amber-400 text-xs"></i> Conforme al Codigo de Trabajo</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <script>
            const fmt = new Intl.NumberFormat('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const fmtInt = new Intl.NumberFormat('es-DO');

            function updateTypeInfo(sel) {
                const info = document.getElementById('typeInfo');
                const opt = sel.options[sel.selectedIndex];
                if (!opt || !opt.value) { info.classList.add('hidden'); return; }
                const rate = opt.dataset.rate || '0';
                const term = opt.dataset.term || '12';
                const max = opt.dataset.max;
                const min = opt.dataset.min;
                const desc = opt.dataset.desc || '';
                let html = '';
                if (desc) html += '<p class="mb-2 text-slate-300">' + desc + '</p>';
                html += '<div class="type-info-grid">';
                html += '<div class="type-info-cell"><div class="lbl">Tasa mensual</div><div class="val">' + rate + '%</div></div>';
                html += '<div class="type-info-cell"><div class="lbl">Plazo sugerido</div><div class="val">' + term + ' meses</div></div>';
                if (min) html += '<div class="type-info-cell"><div class="lbl">Minimo</div><div class="val">RD$ ' + fmtInt.format(min) + '</div></div>';
                if (max) html += '<div class="type-info-cell"><div class="lbl">Maximo</div><div class="val">RD$ ' + fmtInt.format(max) + '</div></div>';
                html += '</div>';
                const rateNum = parseFloat(rate) || 0;
                if (rateNum === 0) {
                    html += '<p class="mt-3 text-xs text-emerald-300"><i class="fas fa-circle-check mr-1"></i>Este tipo de prestamo no genera intereses.</p>';
                } else {
                    html += '<p class="mt-3 text-xs text-amber-300"><i class="fas fa-circle-info mr-1"></i>Este prestamo genera ' + rateNum.toFixed(2) + '% de interes mensual (cuota fija).</p>';
                }
                info.innerHTML = html;
                info.classList.remove('hidden');
            }

            function calc() {
                const sel    = document.getElementById('loanType');
                const opt    = sel.options[sel.selectedIndex];
                const amount = parseFloat(document.getElementById('amount').value) || 0;
                const cuotas = parseInt(document.getElementById('cuotas').value, 10) || 0;
                const freq   = document.getElementById('freq').value;

                if (!opt || !opt.value || amount <= 0 || cuotas <= 0) {
                    document.getElementById('calcEmpty').classList.remove('hidden');
                    document.getElementById('calcResult').classList.add('hidden');
                    return;
                }

                // La tasa del tipo es MENSUAL (igual que en la app de finanzas);
                // se prorratea a la frecuencia: mensual x 12 / periodos al anio.
                const monthlyRate = parseFloat(opt.dataset.rate || '0') / 100;
                const min = parseFloat(opt.dataset.min || '0');
                const max = parseFloat(opt.dataset.max || '0');

                const periodsPerYear = freq === 'monthly' ? 12 : (freq === 'biweekly' ? 26 : 52);
                const r = monthlyRate * 12 / periodsPerYear;

                let installment;
                if (r > 0) {
                    installment = amount * (r * Math.pow(1 + r, cuotas)) / (Math.pow(1 + r, cuotas) - 1);
                } else {
                    installment = amount / cuotas;
                }
                const total = installment * cuotas;
                const interest = total - amount;
                const freqLabel = { biweekly: 'Quincenal', monthly: 'Mensual', weekly: 'Semanal' }[freq];

                document.getElementById('cMonto').textContent     = 'RD$ ' + fmt.format(amount);
                document.getElementById('cTasa').textContent      = (monthlyRate * 100).toFixed(2) + '%';
                document.getElementById('cCuotas').textContent    = cuotas;
                document.getElementById('cFreq').textContent      = freqLabel;
                document.getElementById('cIntereses').textContent = 'RD$ ' + fmt.format(interest);
                document.getElementById('cTotal').textContent     = 'RD$ ' + fmt.format(total);
                document.getElementById('cCuota').textContent     = 'RD$ ' + fmt.format(installment);

                const warn = document.getElementById('cWarning');
                warn.classList.add('hidden');
                if (max > 0 && amount > max) {
                    warn.querySelector('span').textContent = 'El monto excede el maximo permitido (RD$ ' + fmtInt.format(max) + ').';
                    warn.classList.remove('hidden');
                } else if (min > 0 && amount < min) {
                    warn.querySelector('span').textContent = 'El monto es menor al minimo permitido (RD$ ' + fmtInt.format(min) + ').';
                    warn.classList.remove('hidden');
                }

                document.getElementById('calcEmpty').classList.add('hidden');
                document.getElementById('calcResult').classList.remove('hidden');
            }
        </script>
    <?php endif; ?>
</div>

<?php include $footerFile; ?>
