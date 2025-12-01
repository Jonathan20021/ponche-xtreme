<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';
require_once '../lib/email_functions.php';
require_once '../lib/payroll_email_functions.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('hr_payroll', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle individual email sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_individual_email'])) {
    $periodId = (int)$_POST['period_id'];
    $employeeId = (int)$_POST['employee_id'];
    
    try {
        $result = sendIndividualPayrollSlip($pdo, $periodId, $employeeId);
        if ($result['success']) {
            $successMsg = $result['message'];
            // Log the email send
            $employeeStmt = $pdo->prepare("SELECT email FROM employees WHERE id = ?");
            $employeeStmt->execute([$employeeId]);
            $email = $employeeStmt->fetchColumn();
            if ($email) {
                logPayrollEmailSent($pdo, $periodId, $employeeId, $email);
            }
        } else {
            $errorMsg = $result['message'];
        }
    } catch (Exception $e) {
        $errorMsg = "Error al enviar volante: " . $e->getMessage();
    }
}

// Handle email sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_payroll_emails'])) {
    $periodId = (int)$_POST['period_id'];
    
    try {
        $result = sendPayrollSlipEmails($pdo, $periodId);
        if ($result['success']) {
            $successMsg = $result['message'];
        } else {
            $errorMsg = $result['message'];
        }
    } catch (Exception $e) {
        $errorMsg = "Error al enviar volantes: " . $e->getMessage();
    }
}

// Get payroll periods
$periodsStmt = $pdo->query("
    SELECT pp.*, 
           COUNT(pr.id) as employee_count,
           SUM(pr.gross_salary) as total_gross,
           SUM(pr.total_deductions) as total_deductions,
           SUM(pr.net_salary) as total_net
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pr.payroll_period_id = pp.id
    WHERE pp.status IN ('CALCULATED', 'APPROVED', 'PAID')
    GROUP BY pp.id
    ORDER BY pp.created_at DESC
");
$periods = $periodsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected period details if specified
$selectedPeriod = null;
$payrollRecords = [];
$emailStats = [];

if (isset($_GET['period_id'])) {
    $periodId = (int)$_GET['period_id'];
    
    $periodStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $periodStmt->execute([$periodId]);
    $selectedPeriod = $periodStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedPeriod) {
        // Get payroll records with employee data
        $recordsStmt = $pdo->prepare("
            SELECT pr.*, 
                   e.first_name, e.last_name, e.employee_code, e.identification_number, 
                   e.position, e.email, e.employment_status,
                   d.name as department_name
            FROM payroll_records pr
            JOIN employees e ON e.id = pr.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE pr.payroll_period_id = ?
            ORDER BY e.last_name, e.first_name
        ");
        $recordsStmt->execute([$periodId]);
        $payrollRecords = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get email statistics
        $emailStats = getPayrollEmailStats($pdo, $periodId);
    }
}

include '../header.php';
?>

<div class="container mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">
            <i class="fas fa-envelope text-indigo-400 mr-2"></i>
            Envío de Volantes de Nómina
        </h1>
        <a href="payroll.php" class="btn-secondary">
            <i class="fas fa-arrow-left"></i>
            Volver a Nómina
        </a>
    </div>

    <?php if (isset($successMsg)): ?>
        <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
            <i class="fas fa-check-circle mr-2"></i>
            <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($errorMsg)): ?>
        <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <!-- Periods List -->
    <div class="glass-card mb-6">
        <h2 class="text-xl font-semibold mb-4">
            <i class="fas fa-calendar-alt text-indigo-400 mr-2"></i>
            Períodos de Nómina Disponibles
        </h2>
        
        <?php if (empty($periods)): ?>
            <p class="text-slate-400">No hay períodos de nómina calculados disponibles.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4">Período</th>
                            <th class="text-center py-3 px-4">Fechas</th>
                            <th class="text-center py-3 px-4">Empleados</th>
                            <th class="text-right py-3 px-4">Total Neto</th>
                            <th class="text-center py-3 px-4">Estado</th>
                            <th class="text-center py-3 px-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periods as $period): ?>
                            <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                <td class="py-3 px-4">
                                    <div class="font-medium"><?= htmlspecialchars($period['name']) ?></div>
                                    <div class="text-xs text-slate-400"><?= htmlspecialchars($period['period_type']) ?></div>
                                </td>
                                <td class="py-3 px-4 text-center text-sm">
                                    <?= date('d/m/Y', strtotime($period['start_date'])) ?> - 
                                    <?= date('d/m/Y', strtotime($period['end_date'])) ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="tag-pill"><?= $period['employee_count'] ?></span>
                                </td>
                                <td class="py-3 px-4 text-right font-semibold text-green-400">
                                    <?= formatDOP($period['total_net'] ?: 0) ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?php
                                    $statusColors = [
                                        'CALCULATED' => 'bg-blue-500',
                                        'APPROVED' => 'bg-purple-500',
                                        'PAID' => 'bg-green-500'
                                    ];
                                    $statusColor = $statusColors[$period['status']] ?? 'bg-gray-500';
                                    ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold text-white <?= $statusColor ?>">
                                        <?= htmlspecialchars($period['status']) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <a href="?period_id=<?= $period['id'] ?>" class="btn-primary text-sm">
                                        <i class="fas fa-envelope"></i>
                                        Gestionar Envíos
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Selected Period Details -->
    <?php if ($selectedPeriod && !empty($payrollRecords)): ?>
        <div class="glass-card mb-6">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-semibold">
                    <i class="fas fa-users text-indigo-400 mr-2"></i>
                    <?= htmlspecialchars($selectedPeriod['name']) ?> - Volantes Individuales
                </h2>
                <div class="flex gap-2">
                    <form method="POST" class="inline">
                        <input type="hidden" name="send_payroll_emails" value="1">
                        <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                        <button type="submit" class="btn-primary" onclick="return confirm('¿Enviar volantes de nómina por correo a todos los empleados?')">
                            <i class="fas fa-paper-plane"></i>
                            Enviar Todos los Volantes
                        </button>
                    </form>
                    <a href="payroll_slips_preview.php?period_id=<?= $selectedPeriod['id'] ?>" class="btn-secondary" target="_blank">
                        <i class="fas fa-eye"></i>
                        Vista Previa
                    </a>
                </div>
            </div>

            <!-- Email Statistics -->
            <?php if (!empty($emailStats)): ?>
                <div class="grid grid-cols-4 gap-4 mb-6">
                    <div class="bg-blue-600/20 border border-blue-500/30 rounded-lg p-4">
                        <div class="text-2xl font-bold text-blue-400"><?= $emailStats['total'] ?></div>
                        <div class="text-sm text-slate-300">Total Empleados</div>
                    </div>
                    <div class="bg-green-600/20 border border-green-500/30 rounded-lg p-4">
                        <div class="text-2xl font-bold text-green-400"><?= $emailStats['sent'] ?></div>
                        <div class="text-sm text-slate-300">Enviados</div>
                    </div>
                    <div class="bg-yellow-600/20 border border-yellow-500/30 rounded-lg p-4">
                        <div class="text-2xl font-bold text-yellow-400"><?= $emailStats['pending'] ?></div>
                        <div class="text-sm text-slate-300">Pendientes</div>
                    </div>
                    <div class="bg-red-600/20 border border-red-500/30 rounded-lg p-4">
                        <div class="text-2xl font-bold text-red-400"><?= $emailStats['no_email'] ?></div>
                        <div class="text-sm text-slate-300">Sin Email</div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Employee List -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4">Empleado</th>
                            <th class="text-center py-3 px-4">Email</th>
                            <th class="text-right py-3 px-4">Salario Neto</th>
                            <th class="text-center py-3 px-4">Estado Email</th>
                            <th class="text-center py-3 px-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payrollRecords as $record): ?>
                            <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                <td class="py-3 px-4">
                                    <div class="font-medium"><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></div>
                                    <div class="text-xs text-slate-400"><?= htmlspecialchars($record['employee_code']) ?> - <?= htmlspecialchars($record['position']) ?></div>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?php if (!empty($record['email'])): ?>
                                        <span class="text-green-400"><?= htmlspecialchars($record['email']) ?></span>
                                    <?php else: ?>
                                        <span class="text-red-400">Sin email</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-right font-semibold text-green-400">
                                    <?= formatDOP($record['net_salary']) ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?php
                                    $emailSent = checkPayrollEmailSent($pdo, $selectedPeriod['id'], $record['employee_id']);
                                    if (empty($record['email'])):
                                    ?>
                                        <span class="px-2 py-1 rounded-full text-xs bg-red-600 text-white">Sin Email</span>
                                    <?php elseif ($emailSent): ?>
                                        <span class="px-2 py-1 rounded-full text-xs bg-green-600 text-white">Enviado</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 rounded-full text-xs bg-yellow-600 text-white">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <div class="flex justify-center gap-1">
                                        <a href="payroll_slip_individual.php?period_id=<?= $selectedPeriod['id'] ?>&employee_id=<?= $record['employee_id'] ?>" 
                                           class="px-2 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs" 
                                           target="_blank" title="Ver Volante">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (!empty($record['email'])): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="send_individual_email" value="1">
                                                <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                                                <input type="hidden" name="employee_id" value="<?= $record['employee_id'] ?>">
                                                <button type="submit" class="px-2 py-1 rounded bg-green-600 hover:bg-green-700 text-white text-xs" title="Enviar Email">
                                                    <i class="fas fa-paper-plane"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../footer.php'; ?>
