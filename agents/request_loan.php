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

<div class="max-w-4xl mx-auto px-4 py-8">

    <div class="glass-card mb-6">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-12 h-12 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                <i class="fas fa-hand-holding-usd text-emerald-400 text-xl"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold">Solicitar Préstamo</h1>
                <p class="text-slate-400 text-sm">Tu solicitud llega automáticamente al área de finanzas para aprobación</p>
            </div>
        </div>
    </div>

    <?php if (isset($errorBootstrap)): ?>
        <div class="glass-card mb-6 border-l-4 border-rose-500">
            <p class="text-rose-300">
                <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($errorBootstrap) ?>
            </p>
        </div>
    <?php elseif ($flash): ?>
        <div class="glass-card mb-6 border-l-4 <?= $flash['type'] === 'success' ? 'border-emerald-500' : 'border-rose-500' ?>">
            <p class="<?= $flash['type'] === 'success' ? 'text-emerald-300' : 'text-rose-300' ?>">
                <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                <?= htmlspecialchars($flash['msg']) ?>
            </p>
            <?php if ($createdLoan): ?>
                <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                    <div class="bg-slate-800/50 p-3 rounded">
                        <p class="text-slate-400 text-xs uppercase">Número</p>
                        <p class="font-mono text-emerald-300"><?= htmlspecialchars($createdLoan['loan_number']) ?></p>
                    </div>
                    <div class="bg-slate-800/50 p-3 rounded">
                        <p class="text-slate-400 text-xs uppercase">Cuota</p>
                        <p class="text-white font-semibold">RD$ <?= number_format($createdLoan['installment_amount'], 2) ?></p>
                    </div>
                    <div class="bg-slate-800/50 p-3 rounded">
                        <p class="text-slate-400 text-xs uppercase">Total a pagar</p>
                        <p class="text-white font-semibold">RD$ <?= number_format($createdLoan['total_payable'], 2) ?></p>
                    </div>
                </div>
                <?php if (!empty($createdLoan['affordability_warning'])): ?>
                    <div class="mt-3 p-3 bg-amber-900/30 border border-amber-700 rounded text-amber-200 text-sm">
                        <i class="fas fa-shield-alt mr-1"></i>
                        <strong>Aviso Art. 201 CT:</strong> <?= htmlspecialchars($createdLoan['affordability_warning']) ?>
                    </div>
                <?php endif; ?>
                <a href="my_loans.php" class="inline-flex items-center gap-2 mt-4 px-4 py-2 bg-blue-500/20 hover:bg-blue-500/30 border border-blue-500/40 rounded-lg text-blue-200 text-sm">
                    <i class="fas fa-list"></i> Ver mis préstamos
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($employee && !$createdLoan): ?>
        <div class="glass-card mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-slate-400 text-xs uppercase">Empleado</p>
                    <p class="text-white font-medium"><?= htmlspecialchars($employee['name']) ?></p>
                </div>
                <div>
                    <p class="text-slate-400 text-xs uppercase">Código</p>
                    <p class="text-white font-mono"><?= htmlspecialchars($employee['employee_code']) ?></p>
                </div>
                <div>
                    <p class="text-slate-400 text-xs uppercase">Cédula</p>
                    <p class="text-white"><?= htmlspecialchars($employee['id_card_number'] ?? '—') ?></p>
                </div>
                <div>
                    <p class="text-slate-400 text-xs uppercase">Departamento</p>
                    <p class="text-white"><?= htmlspecialchars($employee['department'] ?? '—') ?></p>
                </div>
            </div>
        </div>

        <?php if (empty($loanTypes)): ?>
            <div class="glass-card border-l-4 border-amber-500">
                <p class="text-amber-300">
                    <i class="fas fa-database mr-2"></i>
                    No fue posible cargar los tipos de préstamo. Verifica que el esquema
                    <code class="text-amber-200 bg-amber-900/30 px-1">hhempeos_financial_system</code>
                    esté disponible y que la tabla <code class="text-amber-200 bg-amber-900/30 px-1">loan_types</code>
                    tenga registros con <code class="text-amber-200 bg-amber-900/30 px-1">is_active=1</code> y
                    <code class="text-amber-200 bg-amber-900/30 px-1">borrower_type='employee'</code>.
                </p>
            </div>
        <?php else: ?>
            <form method="POST" class="glass-card space-y-4">
                <input type="hidden" name="action" value="create_request">

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">Tipo de préstamo *</label>
                    <select name="loan_type_code" required
                            class="w-full bg-slate-800/70 border border-slate-700 rounded-lg px-3 py-2 text-white focus:border-emerald-500 focus:outline-none"
                            onchange="updateTypeInfo(this)">
                        <option value="">— Selecciona —</option>
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
                    <div id="typeInfo" class="hidden mt-2 p-3 bg-slate-800/40 border border-slate-700 rounded text-xs space-y-1"></div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Monto a solicitar (RD$) *</label>
                        <input type="number" name="principal_amount" min="100" step="100" required
                               class="w-full bg-slate-800/70 border border-slate-700 rounded-lg px-3 py-2 text-white focus:border-emerald-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Número de cuotas *</label>
                        <input type="number" name="installment_count" min="1" max="60" value="12" required
                               class="w-full bg-slate-800/70 border border-slate-700 rounded-lg px-3 py-2 text-white focus:border-emerald-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Frecuencia *</label>
                        <select name="installment_frequency" required
                                class="w-full bg-slate-800/70 border border-slate-700 rounded-lg px-3 py-2 text-white focus:border-emerald-500 focus:outline-none">
                            <option value="biweekly">Quincenal (recomendado)</option>
                            <option value="monthly">Mensual</option>
                            <option value="weekly">Semanal</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">Primera fecha de vencimiento (opcional)</label>
                    <input type="date" name="first_due_date"
                           class="w-full bg-slate-800/70 border border-slate-700 rounded-lg px-3 py-2 text-white focus:border-emerald-500 focus:outline-none">
                    <p class="text-xs text-slate-500 mt-1">Si la dejas en blanco, finanzas la programará según el próximo período de nómina.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">Propósito del préstamo *</label>
                    <textarea name="purpose" rows="3" required placeholder="Ej: Reparación de vivienda, gastos médicos, etc."
                              class="w-full bg-slate-800/70 border border-slate-700 rounded-lg px-3 py-2 text-white focus:border-emerald-500 focus:outline-none"></textarea>
                </div>

                <div class="border-t border-slate-700 pt-4">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="has_guarantor" id="hasGuarantor" onchange="document.getElementById('guarantorFields').classList.toggle('hidden', !this.checked)">
                        <span class="text-sm text-slate-300">Aporto un aval / garante</span>
                    </label>

                    <div id="guarantorFields" class="hidden mt-3 grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Nombre del aval</label>
                            <input type="text" name="guarantor_name"
                                   class="w-full bg-slate-800/70 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Cédula del aval</label>
                            <input type="text" name="guarantor_document"
                                   class="w-full bg-slate-800/70 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm focus:border-emerald-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Teléfono del aval</label>
                            <input type="text" name="guarantor_phone"
                                   class="w-full bg-slate-800/70 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm focus:border-emerald-500 focus:outline-none">
                        </div>
                    </div>
                </div>

                <div class="border-t border-slate-700 pt-4 bg-blue-500/5 -mx-4 px-4 py-3 rounded-b">
                    <label class="inline-flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" name="employee_consent" required class="mt-1">
                        <span class="text-sm text-slate-300">
                            <strong>Autorizo expresamente</strong> a la empresa, en cumplimiento del <strong>Art. 200 del Código de Trabajo de R.D.</strong>,
                            a descontar de mi salario las cuotas correspondientes hasta saldar este préstamo, sin exceder el 33.33% del salario neto
                            (Art. 201 CT). Reconozco que se registrará mi IP y fecha como constancia.
                        </span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <a href="<?= htmlspecialchars($backHref) ?>" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm">Cancelar</a>
                    <button type="submit" class="px-6 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-sm font-medium">
                        <i class="fas fa-paper-plane mr-1"></i> Enviar Solicitud
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <script>
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
                if (desc) html += '<p class="text-slate-400">' + desc + '</p>';
                html += '<p class="text-slate-400">Tasa anual: <span class="text-emerald-300">' + rate + '%</span> · ';
                html += 'Plazo sugerido: <span class="text-emerald-300">' + term + ' meses</span>';
                if (max) html += ' · Máximo: <span class="text-emerald-300">RD$ ' + Number(max).toLocaleString('es-DO') + '</span>';
                if (min) html += ' · Mínimo: <span class="text-emerald-300">RD$ ' + Number(min).toLocaleString('es-DO') + '</span>';
                html += '</p>';
                info.innerHTML = html;
                info.classList.remove('hidden');
            }
        </script>
    <?php endif; ?>
</div>

<?php include $footerFile; ?>
