<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';
require_once '../lib/payroll_email_functions.php';

// Check permissions
ensurePermission('hr_payroll', '../unauthorized.php');

$periodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;

if (!$periodId) {
    die('Período no especificado');
}

// Get period data
$periodStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
$periodStmt->execute([$periodId]);
$period = $periodStmt->fetch(PDO::FETCH_ASSOC);

if (!$period) {
    die('Período no encontrado');
}

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

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

include '../header.php';
?>

<div class="container mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">
            <i class="fas fa-eye text-indigo-400 mr-2"></i>
            Vista Previa de Volantes - <?= htmlspecialchars($period['name']) ?>
        </h1>
        <div class="flex gap-2">
            <a href="payroll_slip_email.php?period_id=<?= $period['id'] ?>" class="btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Volver
            </a>
            <button onclick="printAllSlips()" class="btn-primary">
                <i class="fas fa-print"></i>
                Imprimir Todos
            </button>
        </div>
    </div>

    <!-- Period Info -->
    <div class="glass-card mb-6">
        <div class="grid grid-cols-4 gap-4">
            <div>
                <div class="text-sm text-slate-400">Período</div>
                <div class="font-semibold"><?= htmlspecialchars($period['name']) ?></div>
            </div>
            <div>
                <div class="text-sm text-slate-400">Fechas</div>
                <div class="font-semibold">
                    <?= date('d/m/Y', strtotime($period['start_date'])) ?> - 
                    <?= date('d/m/Y', strtotime($period['end_date'])) ?>
                </div>
            </div>
            <div>
                <div class="text-sm text-slate-400">Fecha de Pago</div>
                <div class="font-semibold"><?= date('d/m/Y', strtotime($period['payment_date'])) ?></div>
            </div>
            <div>
                <div class="text-sm text-slate-400">Total Empleados</div>
                <div class="font-semibold"><?= count($payrollRecords) ?></div>
            </div>
        </div>
    </div>

    <!-- Employee List with Preview -->
    <div class="glass-card">
        <h2 class="text-xl font-semibold mb-4">
            <i class="fas fa-list text-indigo-400 mr-2"></i>
            Volantes por Empleado
        </h2>
        
        <div class="space-y-4">
            <?php foreach ($payrollRecords as $index => $record): ?>
                <div class="border border-slate-700 rounded-lg overflow-hidden">
                    <div class="bg-slate-800/50 p-4 flex justify-between items-center cursor-pointer" onclick="toggleSlip(<?= $index ?>)">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 bg-indigo-600 rounded-full flex items-center justify-center text-white font-bold">
                                <?= strtoupper(substr($record['first_name'], 0, 1) . substr($record['last_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="font-semibold"><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></div>
                                <div class="text-sm text-slate-400">
                                    <?= htmlspecialchars($record['employee_code']) ?> - <?= htmlspecialchars($record['position']) ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <div class="font-semibold text-green-400"><?= formatDOP($record['net_salary']) ?></div>
                                <div class="text-sm text-slate-400">Salario Neto</div>
                            </div>
                            <div class="flex gap-2">
                                <a href="payroll_slip_individual.php?period_id=<?= $period['id'] ?>&employee_id=<?= $record['employee_id'] ?>" 
                                   class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm" 
                                   target="_blank">
                                    <i class="fas fa-external-link-alt"></i>
                                    Abrir
                                </a>
                                <a href="payroll_slip_individual.php?period_id=<?= $period['id'] ?>&employee_id=<?= $record['employee_id'] ?>&format=pdf" 
                                   class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-sm" 
                                   target="_blank">
                                    <i class="fas fa-file-pdf"></i>
                                    PDF
                                </a>
                            </div>
                            <i class="fas fa-chevron-down transition-transform" id="chevron-<?= $index ?>"></i>
                        </div>
                    </div>
                    
                    <div id="slip-<?= $index ?>" class="hidden p-4 bg-slate-900/30">
                        <div class="bg-white rounded-lg p-6 text-gray-800" style="max-height: 600px; overflow-y: auto;">
                            <?php
                            $payrollData = getEmployeePayrollData($pdo, $period['id'], $record['employee_id']);
                            if ($payrollData) {
                                // Generate a simplified version for preview
                                echo generatePayrollSlipPreview($payrollData);
                            }
                            ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function toggleSlip(index) {
    const slip = document.getElementById('slip-' + index);
    const chevron = document.getElementById('chevron-' + index);
    
    if (slip.classList.contains('hidden')) {
        slip.classList.remove('hidden');
        chevron.style.transform = 'rotate(180deg)';
    } else {
        slip.classList.add('hidden');
        chevron.style.transform = 'rotate(0deg)';
    }
}

function printAllSlips() {
    if (confirm('¿Abrir todos los volantes en pestañas separadas para imprimir?')) {
        <?php foreach ($payrollRecords as $record): ?>
        window.open('payroll_slip_individual.php?period_id=<?= $period['id'] ?>&employee_id=<?= $record['employee_id'] ?>', '_blank');
        <?php endforeach; ?>
    }
}
</script>

<?php
/**
 * Generate a simplified preview version of the payroll slip
 */
function generatePayrollSlipPreview($data) {
    ob_start();
    ?>
    <div style="font-family: Arial, sans-serif; font-size: 12px;">
        <div style="text-align: center; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 20px;">
            <h3 style="color: #1e40af; margin: 0;">VOLANTE DE NÓMINA</h3>
            <p style="margin: 5px 0;"><?= htmlspecialchars($data['period_name']) ?></p>
            <p style="margin: 5px 0; font-size: 11px;">
                <?= htmlspecialchars($data['period_formatted']) ?> | 
                Pago: <?= htmlspecialchars($data['payment_date_formatted']) ?>
            </p>
        </div>

        <div style="background: #f8fafc; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div><strong>Empleado:</strong> <?= htmlspecialchars($data['employee_name']) ?></div>
                <div><strong>Código:</strong> <?= htmlspecialchars($data['employee_code']) ?></div>
                <div><strong>Cédula:</strong> <?= htmlspecialchars($data['identification_number']) ?></div>
                <div><strong>Posición:</strong> <?= htmlspecialchars($data['position']) ?></div>
                <?php if (!empty($data['hourly_rate']) && $data['hourly_rate'] > 0): ?>
                <div><strong>Tarifa/Hora:</strong> <?= formatDOP($data['hourly_rate']) ?></div>
                <?php endif; ?>
                <?php if (!empty($data['monthly_salary']) && $data['monthly_salary'] > 0): ?>
                <div><strong>Salario Mensual:</strong> <?= formatDOP($data['monthly_salary']) ?></div>
                <?php endif; ?>
                <div><strong>Horas:</strong> <?= number_format($data['total_hours'], 1) ?></div>
                <?php if (!empty($data['overtime_multiplier']) && $data['overtime_multiplier'] > 1): ?>
                <div><strong>Mult. H.Extra:</strong> <?= number_format($data['overtime_multiplier'], 2) ?>x</div>
                <?php endif; ?>
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
            <tr style="background: #2563eb; color: white;">
                <th style="padding: 8px; text-align: left;">Concepto</th>
                <th style="padding: 8px; text-align: right;">Monto</th>
            </tr>
            <tr>
                <td style="padding: 6px; border-bottom: 1px solid #e5e7eb;">Salario Base</td>
                <td style="padding: 6px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #059669;">
                    <?= formatDOP($data['base_salary']) ?>
                </td>
            </tr>
            <?php if ($data['overtime_amount'] > 0): ?>
            <tr>
                <td style="padding: 6px; border-bottom: 1px solid #e5e7eb;">Horas Extra</td>
                <td style="padding: 6px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #059669;">
                    <?= formatDOP($data['overtime_amount']) ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr style="background: #f3f4f6; font-weight: bold;">
                <td style="padding: 8px;">SALARIO BRUTO</td>
                <td style="padding: 8px; text-align: right; color: #059669;">
                    <?= formatDOP($data['gross_salary']) ?>
                </td>
            </tr>
            <tr>
                <td style="padding: 6px; border-bottom: 1px solid #e5e7eb;">AFP</td>
                <td style="padding: 6px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #dc2626;">
                    -<?= formatDOP($data['afp_employee']) ?>
                </td>
            </tr>
            <tr>
                <td style="padding: 6px; border-bottom: 1px solid #e5e7eb;">SFS</td>
                <td style="padding: 6px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #dc2626;">
                    -<?= formatDOP($data['sfs_employee']) ?>
                </td>
            </tr>
            <?php if ($data['isr'] > 0): ?>
            <tr>
                <td style="padding: 6px; border-bottom: 1px solid #e5e7eb;">
                    ISR
                    <div style="font-size: 9px; color: #6b7280; margin-top: 1px;">
                        <?php 
                        $annualSalary = $data['gross_salary'] * 12;
                        if ($annualSalary <= 416220.00) {
                            echo "Exento";
                        } elseif ($annualSalary <= 624329.00) {
                            echo "15% sobre excedente";
                        } elseif ($annualSalary <= 867123.00) {
                            echo "20% sobre excedente";
                        } else {
                            echo "25% sobre excedente";
                        }
                        ?>
                    </div>
                </td>
                <td style="padding: 6px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #dc2626;">
                    -<?= formatDOP($data['isr']) ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr style="background: #dbeafe; font-weight: bold; font-size: 13px;">
                <td style="padding: 10px;">SALARIO NETO</td>
                <td style="padding: 10px; text-align: right; color: #059669;">
                    <?= formatDOP($data['net_salary']) ?>
                </td>
            </tr>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

include '../footer.php';
?>
