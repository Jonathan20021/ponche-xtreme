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
$footerFile = '../footer.php';

$user_id = (int) $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Agente';

$stmt = $pdo->prepare("SELECT e.id, e.employee_code, TRIM(CONCAT_WS(' ', e.first_name, e.last_name)) AS name
                       FROM employees e WHERE e.user_id = ? LIMIT 1");
$stmt->execute([$user_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

$loans = $employee ? getEmployeeLoansFromFinance((int) $employee['id']) : [];

$statusLabels = [
    'draft' => ['Borrador', 'gray'],
    'pending' => ['Pendiente de aprobación', 'amber'],
    'approved' => ['Aprobado', 'blue'],
    'active' => ['Vigente', 'emerald'],
    'in_arrears' => ['En atraso', 'rose'],
    'restructured' => ['Reestructurado', 'purple'],
    'paid' => ['Saldado', 'green'],
    'written_off' => ['Castigado', 'slate'],
    'cancelled' => ['Cancelado', 'slate'],
];

$freqLabels = [
    'weekly' => 'semanal',
    'biweekly' => 'quincenal',
    'monthly' => 'mensual',
    'custom' => 'personalizada',
];

$totalActive = 0;
$totalOutstanding = 0;
foreach ($loans as $l) {
    if (in_array($l['status'], ['active','in_arrears','approved'], true)) {
        $totalActive++;
        $totalOutstanding += (float) ($l['outstanding_balance'] ?? 0);
    }
}
?>
<?php include $headerFile; ?>

<div class="max-w-5xl mx-auto px-4 py-8">

    <div class="glass-card mb-6">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                    <i class="fas fa-money-check-alt text-emerald-400 text-xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold">Mis Préstamos</h1>
                    <p class="text-slate-400 text-sm">Estado de tus solicitudes y préstamos vigentes</p>
                </div>
            </div>
            <a href="request_loan.php" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-sm font-medium">
                <i class="fas fa-plus"></i> Nueva Solicitud
            </a>
        </div>
    </div>

    <?php if (!$employee): ?>
        <div class="glass-card border-l-4 border-rose-500">
            <p class="text-rose-300"><i class="fas fa-exclamation-triangle mr-2"></i>Tu cuenta no está vinculada a un perfil de empleado activo.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="glass-card">
                <p class="text-xs text-slate-400 uppercase">Préstamos activos</p>
                <p class="text-3xl font-bold text-emerald-300"><?= $totalActive ?></p>
            </div>
            <div class="glass-card">
                <p class="text-xs text-slate-400 uppercase">Saldo pendiente</p>
                <p class="text-2xl font-bold text-white">RD$ <?= number_format($totalOutstanding, 2) ?></p>
            </div>
            <div class="glass-card">
                <p class="text-xs text-slate-400 uppercase">Total histórico</p>
                <p class="text-2xl font-bold text-blue-300"><?= count($loans) ?></p>
                <p class="text-xs text-slate-500">solicitudes</p>
            </div>
        </div>

        <?php if (empty($loans)): ?>
            <div class="glass-card text-center py-12">
                <i class="fas fa-folder-open text-slate-600 text-4xl mb-3"></i>
                <p class="text-slate-300">Aún no tienes solicitudes de préstamo registradas.</p>
                <a href="request_loan.php" class="inline-flex items-center gap-2 mt-4 px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-sm">
                    <i class="fas fa-plus"></i> Solicitar mi primer préstamo
                </a>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($loans as $loan): ?>
                    <?php
                        $status = $loan['status'] ?? 'draft';
                        [$statusLabel, $statusColor] = $statusLabels[$status] ?? ['Desconocido', 'slate'];
                        $progress = ($loan['installment_count'] ?? 0) > 0
                            ? ($loan['installments_paid'] / $loan['installment_count']) * 100
                            : 0;
                    ?>
                    <div class="glass-card hover:border-slate-600 transition-colors">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="font-mono text-emerald-300 text-sm"><?= htmlspecialchars($loan['loan_number']) ?></span>
                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded bg-<?= $statusColor ?>-500/20 text-<?= $statusColor ?>-300 border border-<?= $statusColor ?>-500/40">
                                        <?= htmlspecialchars($statusLabel) ?>
                                    </span>
                                    <?php if (!empty($loan['overdue_count']) && $loan['overdue_count'] > 0): ?>
                                        <span class="px-2 py-0.5 text-[10px] font-semibold rounded bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                            <i class="fas fa-exclamation-triangle mr-1"></i><?= $loan['overdue_count'] ?> cuota(s) vencida(s)
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-white font-medium"><?= htmlspecialchars($loan['loan_type_name']) ?></p>
                                <?php if (!empty($loan['purpose'])): ?>
                                    <p class="text-xs text-slate-400 italic mt-0.5"><?= htmlspecialchars($loan['purpose']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-slate-400">Capital solicitado</p>
                                <p class="text-lg font-bold text-white"><?= htmlspecialchars($loan['currency']) ?> <?= number_format((float)$loan['principal_amount'], 2) ?></p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4 pt-4 border-t border-slate-700 text-sm">
                            <div>
                                <p class="text-xs text-slate-400">Cuota</p>
                                <p class="text-white"><?= htmlspecialchars($loan['currency']) ?> <?= number_format((float)$loan['installment_amount'], 2) ?></p>
                                <p class="text-[10px] text-slate-500"><?= htmlspecialchars($freqLabels[$loan['installment_frequency']] ?? '') ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-400">Saldo pendiente</p>
                                <p class="text-emerald-300 font-medium"><?= htmlspecialchars($loan['currency']) ?> <?= number_format((float)$loan['outstanding_balance'], 2) ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-400">Próximo vencimiento</p>
                                <p class="text-white">
                                    <?= !empty($loan['next_due_date']) ? date('d/m/Y', strtotime($loan['next_due_date'])) : '—' ?>
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-400">Avance</p>
                                <p class="text-white"><?= (int)($loan['installments_paid'] ?? 0) ?> / <?= (int)($loan['installment_count'] ?? 0) ?></p>
                                <div class="h-1.5 bg-slate-800 rounded mt-1 overflow-hidden">
                                    <div class="h-full bg-emerald-500" style="width: <?= min(100, $progress) ?>%"></div>
                                </div>
                            </div>
                        </div>

                        <?php if ($status === 'pending'): ?>
                            <div class="mt-3 p-2 bg-amber-900/20 border border-amber-700/30 rounded text-xs text-amber-300">
                                <i class="fas fa-clock mr-1"></i>
                                Tu solicitud está en revisión. Recibirás una respuesta del área de finanzas pronto.
                            </div>
                        <?php elseif ($status === 'approved'): ?>
                            <div class="mt-3 p-2 bg-blue-900/20 border border-blue-700/30 rounded text-xs text-blue-300">
                                <i class="fas fa-check mr-1"></i>
                                Aprobado — pendiente de desembolso por finanzas.
                            </div>
                        <?php elseif ($status === 'in_arrears'): ?>
                            <div class="mt-3 p-2 bg-rose-900/20 border border-rose-700/30 rounded text-xs text-rose-300">
                                <i class="fas fa-exclamation-circle mr-1"></i>
                                Contacta al área de finanzas para regularizar las cuotas vencidas.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include $footerFile; ?>
