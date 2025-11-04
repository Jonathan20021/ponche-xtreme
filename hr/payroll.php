<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('hr_payroll');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle period creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_period'])) {
    $name = trim($_POST['name']);
    $periodType = $_POST['period_type'];
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $paymentDate = $_POST['payment_date'];
    
    $stmt = $pdo->prepare("
        INSERT INTO payroll_periods (name, period_type, start_date, end_date, payment_date, created_by) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$name, $periodType, $startDate, $endDate, $paymentDate, $_SESSION['user_id']]);
    $successMsg = "Período de nómina creado correctamente.";
}

// Handle period edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_period'])) {
    $periodId = (int)$_POST['period_id'];
    $name = trim($_POST['name']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $paymentDate = $_POST['payment_date'];
    
    $stmt = $pdo->prepare("
        UPDATE payroll_periods 
        SET name = ?, start_date = ?, end_date = ?, payment_date = ?
        WHERE id = ? AND status = 'DRAFT'
    ");
    $stmt->execute([$name, $startDate, $endDate, $paymentDate, $periodId]);
    $successMsg = "Período actualizado correctamente.";
}

// Handle period deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_period'])) {
    $periodId = (int)$_POST['period_id'];
    
    $pdo->beginTransaction();
    try {
        // Delete payroll records first
        $pdo->prepare("DELETE FROM payroll_records WHERE payroll_period_id = ?")->execute([$periodId]);
        // Delete period (allow DRAFT or CALCULATED)
        $pdo->prepare("DELETE FROM payroll_periods WHERE id = ? AND status IN ('DRAFT', 'CALCULATED')")->execute([$periodId]);
        $pdo->commit();
        $successMsg = "Período eliminado correctamente.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $errorMsg = "Error al eliminar: " . $e->getMessage();
    }
}

// Handle payroll calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_payroll'])) {
    $periodId = (int)$_POST['period_id'];
    
    $pdo->beginTransaction();
    
    try {
        // Get period
        $periodStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
        $periodStmt->execute([$periodId]);
        $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$period) {
            throw new Exception("Período no encontrado");
        }
        
        // Get all active employees
        $employees = $pdo->query("
            SELECT e.*, u.id as user_id, u.hourly_rate, u.monthly_salary, u.overtime_multiplier
            FROM employees e
            JOIN users u ON u.id = e.user_id
            WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $config = getScheduleConfig($pdo);
        $scheduledHours = (float)$config['scheduled_hours'];
        
        foreach ($employees as $emp) {
            $userId = $emp['user_id'];
            $employeeId = $emp['id'];
            
            // Calculate hours from attendance
            $attendanceStmt = $pdo->prepare("
                SELECT 
                    DATE(timestamp) as work_date,
                    MIN(CASE WHEN type = 'ENTRY' THEN timestamp END) as entry_time,
                    MAX(CASE WHEN type = 'EXIT' THEN timestamp END) as exit_time
                FROM attendance
                WHERE user_id = ?
                AND DATE(timestamp) BETWEEN ? AND ?
                GROUP BY DATE(timestamp)
                HAVING entry_time IS NOT NULL AND exit_time IS NOT NULL
            ");
            $attendanceStmt->execute([$userId, $period['start_date'], $period['end_date']]);
            $attendanceData = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $totalRegularHours = 0;
            $totalOvertimeHours = 0;
            
            foreach ($attendanceData as $day) {
                $entry = new DateTime($day['entry_time']);
                $exit = new DateTime($day['exit_time']);
                $workedSeconds = $exit->getTimestamp() - $entry->getTimestamp();
                $workedHours = $workedSeconds / 3600;
                
                // Subtract breaks
                $lunchMinutes = (int)$config['lunch_minutes'];
                $breakMinutes = (int)$config['break_minutes'];
                $totalBreakHours = ($lunchMinutes + $breakMinutes) / 60;
                $netWorkedHours = max(0, $workedHours - $totalBreakHours);
                
                if ($netWorkedHours > $scheduledHours) {
                    $totalRegularHours += $scheduledHours;
                    $totalOvertimeHours += ($netWorkedHours - $scheduledHours);
                } else {
                    $totalRegularHours += $netWorkedHours;
                }
            }
            
            // Calculate payroll
            $hoursData = [
                'regular_hours' => $totalRegularHours,
                'overtime_hours' => $totalOvertimeHours,
                'bonuses' => 0,
                'commissions' => 0,
                'other_income' => 0
            ];
            
            $payrollData = calculateEmployeePayroll($pdo, $employeeId, $periodId, $hoursData);
            
            if ($payrollData) {
                // Check if record exists
                $checkStmt = $pdo->prepare("SELECT id FROM payroll_records WHERE payroll_period_id = ? AND employee_id = ?");
                $checkStmt->execute([$periodId, $employeeId]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    // Update
                    $updateStmt = $pdo->prepare("
                        UPDATE payroll_records SET
                            base_salary = ?, regular_hours = ?, overtime_hours = ?, overtime_amount = ?,
                            bonuses = ?, commissions = ?, other_income = ?, gross_salary = ?,
                            afp_employee = ?, sfs_employee = ?, isr = ?, other_deductions = ?, total_deductions = ?,
                            afp_employer = ?, sfs_employer = ?, srl_employer = ?, infotep_employer = ?, total_employer_contributions = ?,
                            net_salary = ?, total_hours = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        $payrollData['base_salary'], $payrollData['regular_hours'], $payrollData['overtime_hours'], $payrollData['overtime_amount'],
                        $payrollData['bonuses'], $payrollData['commissions'], $payrollData['other_income'], $payrollData['gross_salary'],
                        $payrollData['afp_employee'], $payrollData['sfs_employee'], $payrollData['isr'], $payrollData['other_deductions'], $payrollData['total_deductions'],
                        $payrollData['afp_employer'], $payrollData['sfs_employer'], $payrollData['srl_employer'], $payrollData['infotep_employer'], $payrollData['total_employer_contributions'],
                        $payrollData['net_salary'], $payrollData['total_hours'],
                        $existing['id']
                    ]);
                } else {
                    // Insert
                    $insertStmt = $pdo->prepare("
                        INSERT INTO payroll_records (
                            payroll_period_id, employee_id,
                            base_salary, regular_hours, overtime_hours, overtime_amount,
                            bonuses, commissions, other_income, gross_salary,
                            afp_employee, sfs_employee, isr, other_deductions, total_deductions,
                            afp_employer, sfs_employer, srl_employer, infotep_employer, total_employer_contributions,
                            net_salary, total_hours
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insertStmt->execute([
                        $periodId, $employeeId,
                        $payrollData['base_salary'], $payrollData['regular_hours'], $payrollData['overtime_hours'], $payrollData['overtime_amount'],
                        $payrollData['bonuses'], $payrollData['commissions'], $payrollData['other_income'], $payrollData['gross_salary'],
                        $payrollData['afp_employee'], $payrollData['sfs_employee'], $payrollData['isr'], $payrollData['other_deductions'], $payrollData['total_deductions'],
                        $payrollData['afp_employer'], $payrollData['sfs_employer'], $payrollData['srl_employer'], $payrollData['infotep_employer'], $payrollData['total_employer_contributions'],
                        $payrollData['net_salary'], $payrollData['total_hours']
                    ]);
                }
            }
        }
        
        // Update period totals
        $totalsStmt = $pdo->prepare("
            SELECT SUM(gross_salary) as total_gross, SUM(total_deductions) as total_deductions, SUM(net_salary) as total_net
            FROM payroll_records
            WHERE payroll_period_id = ?
        ");
        $totalsStmt->execute([$periodId]);
        $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
        
        $updatePeriodStmt = $pdo->prepare("
            UPDATE payroll_periods SET
                total_gross = ?, total_deductions = ?, total_net = ?, status = 'CALCULATED', updated_at = NOW()
            WHERE id = ?
        ");
        $updatePeriodStmt->execute([
            $totals['total_gross'], $totals['total_deductions'], $totals['total_net'], $periodId
        ]);
        
        // Log payroll generation
        log_payroll_generated($pdo, $_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], $period['start_date'], $period['end_date'], count($employees));
        
        $pdo->commit();
        $successMsg = "Nómina calculada correctamente para " . count($employees) . " empleados.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $errorMsg = "Error al calcular nómina: " . $e->getMessage();
    }
}

// Get all periods
$periods = $pdo->query("
    SELECT pp.id, pp.name, pp.period_type, pp.start_date, pp.end_date, pp.payment_date, pp.status,
           pp.total_gross, pp.total_deductions, pp.total_net,
           COUNT(pr.id) as employee_count,
           u.username as created_by_username
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pr.payroll_period_id = pp.id
    LEFT JOIN users u ON u.id = pp.created_by
    GROUP BY pp.id, pp.name, pp.period_type, pp.start_date, pp.end_date, pp.payment_date, pp.status,
             pp.total_gross, pp.total_deductions, pp.total_net, u.username
    ORDER BY pp.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get selected period
$selectedPeriodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : null;
$selectedPeriod = null;
$payrollRecords = [];

if ($selectedPeriodId) {
    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$selectedPeriodId]);
    $selectedPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedPeriod) {
        $recordsStmt = $pdo->prepare("
            SELECT pr.*, e.first_name, e.last_name, e.employee_code, e.identification_number, d.name as department_name
            FROM payroll_records pr
            JOIN employees e ON e.id = pr.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE pr.payroll_period_id = ?
            ORDER BY e.last_name, e.first_name
        ");
        $recordsStmt->execute([$selectedPeriodId]);
        $payrollRecords = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nómina RD - HR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold mb-2">
                    <i class="fas fa-money-bill-wave text-green-400 mr-3"></i>
                    Nómina República Dominicana
                </h1>
                <p class="text-slate-400">Sistema completo con AFP, SFS, ISR, TSS y DGII</p>
            </div>
            <div class="flex gap-3">
                <a href="payroll_settings.php" class="btn-secondary">
                    <i class="fas fa-cog"></i>
                    Configuración
                </a>
                <button onclick="document.getElementById('createPeriodModal').classList.remove('hidden')" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    Nuevo Período
                </button>
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver
                </a>
            </div>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        
        <?php if (isset($errorMsg)): ?>
            <div class="status-banner error mb-6"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- Periods List -->
        <div class="glass-card mb-8">
            <h2 class="text-xl font-semibold mb-4">
                <i class="fas fa-calendar-alt text-indigo-400 mr-2"></i>
                Períodos de Nómina
            </h2>
            
            <?php if (empty($periods)): ?>
                <p class="text-slate-400 text-center py-8">No hay períodos creados.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4">Período</th>
                                <th class="text-left py-3 px-4">Fechas</th>
                                <th class="text-left py-3 px-4">Pago</th>
                                <th class="text-center py-3 px-4">Empleados</th>
                                <th class="text-right py-3 px-4">Total Bruto</th>
                                <th class="text-right py-3 px-4">Descuentos</th>
                                <th class="text-right py-3 px-4">Total Neto</th>
                                <th class="text-center py-3 px-4">Estado</th>
                                <th class="text-center py-3 px-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periods as $period): ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 font-medium"><?= htmlspecialchars($period['name']) ?></td>
                                    <td class="py-3 px-4 text-sm">
                                        <?= date('d/m/Y', strtotime($period['start_date'])) ?> - 
                                        <?= date('d/m/Y', strtotime($period['end_date'])) ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm">
                                        <?= date('d/m/Y', strtotime($period['payment_date'])) ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="tag-pill"><?= $period['employee_count'] ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-right font-semibold">
                                        <?= formatDOP($period['total_gross'] ?: 0) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right text-red-400">
                                        <?= formatDOP($period['total_deductions'] ?: 0) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right text-green-400 font-semibold">
                                        <?= formatDOP($period['total_net'] ?: 0) ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <?php
                                        $statusColors = [
                                            'DRAFT' => 'bg-gray-500',
                                            'CALCULATED' => 'bg-blue-500',
                                            'APPROVED' => 'bg-purple-500',
                                            'PAID' => 'bg-green-500',
                                            'CLOSED' => 'bg-gray-600'
                                        ];
                                        $statusColor = $statusColors[$period['status']] ?? 'bg-gray-500';
                                        ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold text-white <?= $statusColor ?>">
                                            <?= htmlspecialchars($period['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex justify-center gap-1">
                                            <a href="?period_id=<?= $period['id'] ?>" class="px-2 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs" title="Ver">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($period['status'] === 'DRAFT' || $period['status'] === 'CALCULATED'): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="calculate_payroll" value="1">
                                                    <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                    <button type="submit" class="px-2 py-1 rounded bg-gray-600 hover:bg-gray-700 text-white text-xs" title="Calcular">
                                                        <i class="fas fa-calculator"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($period['status'] === 'CALCULATED'): ?>
                                                <a href="payroll_export_pdf.php?period_id=<?= $period['id'] ?>" class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs" target="_blank" title="PDF">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                                <a href="payroll_export_excel.php?period_id=<?= $period['id'] ?>" class="px-2 py-1 rounded bg-green-600 hover:bg-green-700 text-white text-xs" title="Excel">
                                                    <i class="fas fa-file-excel"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($period['status'] === 'DRAFT' || $period['status'] === 'CALCULATED'): ?>
                                                <button onclick="editPeriod(<?= $period['id'] ?>, '<?= htmlspecialchars($period['name'], ENT_QUOTES) ?>', '<?= $period['start_date'] ?>', '<?= $period['end_date'] ?>', '<?= $period['payment_date'] ?>')" class="px-2 py-1 rounded bg-yellow-600 hover:bg-yellow-700 text-white text-xs" title="Editar">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este período?')">
                                                    <input type="hidden" name="delete_period" value="1">
                                                    <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                    <button type="submit" class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs" title="Eliminar">
                                                        <i class="fas fa-trash"></i>
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
            <?php endif; ?>
        </div>

        <!-- Payroll Details -->
        <?php if ($selectedPeriod && !empty($payrollRecords)): 
            // Get current deduction rates
            $deductionRates = $pdo->query("SELECT code, employee_percentage FROM payroll_deduction_config WHERE code IN ('AFP', 'SFS')")->fetchAll(PDO::FETCH_KEY_PAIR);
        ?>
            <div class="glass-card mb-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-semibold">
                        <i class="fas fa-list text-indigo-400 mr-2"></i>
                        Detalle: <?= htmlspecialchars($selectedPeriod['name']) ?>
                    </h2>
                    <div class="flex gap-2">
                        <a href="payroll_export_pdf.php?period_id=<?= $selectedPeriod['id'] ?>" class="btn-danger text-sm" target="_blank">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </a>
                        <a href="payroll_export_excel.php?period_id=<?= $selectedPeriod['id'] ?>" class="btn-secondary text-sm">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </a>
                        <a href="payroll_tss.php?period_id=<?= $selectedPeriod['id'] ?>" class="btn-primary text-sm">
                            <i class="fas fa-shield-alt"></i>
                            TSS
                        </a>
                        <a href="payroll_dgii.php?period_id=<?= $selectedPeriod['id'] ?>" class="btn-primary text-sm">
                            <i class="fas fa-landmark"></i>
                            DGII
                        </a>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-2 px-2">Empleado</th>
                                <th class="text-center py-2 px-2">Horas</th>
                                <th class="text-right py-2 px-2">Salario Bruto</th>
                                <th class="text-right py-2 px-2">AFP (<?= number_format($deductionRates['AFP'], 2) ?>%)</th>
                                <th class="text-right py-2 px-2">SFS (<?= number_format($deductionRates['SFS'], 2) ?>%)</th>
                                <th class="text-right py-2 px-2">ISR</th>
                                <th class="text-right py-2 px-2">Otros Desc.</th>
                                <th class="text-right py-2 px-2">Total Desc.</th>
                                <th class="text-right py-2 px-2">Salario Neto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totals = ['gross' => 0, 'afp' => 0, 'sfs' => 0, 'isr' => 0, 'other' => 0, 'deductions' => 0, 'net' => 0];
                            foreach ($payrollRecords as $record): 
                                $totals['gross'] += $record['gross_salary'];
                                $totals['afp'] += $record['afp_employee'];
                                $totals['sfs'] += $record['sfs_employee'];
                                $totals['isr'] += $record['isr'];
                                $totals['other'] += $record['other_deductions'];
                                $totals['deductions'] += $record['total_deductions'];
                                $totals['net'] += $record['net_salary'];
                            ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                    <td class="py-2 px-2">
                                        <div class="font-medium"><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></div>
                                        <div class="text-xs text-slate-400"><?= htmlspecialchars($record['employee_code']) ?></div>
                                    </td>
                                    <td class="py-2 px-2 text-center"><?= number_format($record['total_hours'], 1) ?></td>
                                    <td class="py-2 px-2 text-right font-semibold"><?= formatDOP($record['gross_salary']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($record['afp_employee']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($record['sfs_employee']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($record['isr']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($record['other_deductions']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-500 font-semibold"><?= formatDOP($record['total_deductions']) ?></td>
                                    <td class="py-2 px-2 text-right text-green-400 font-bold"><?= formatDOP($record['net_salary']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="bg-slate-800/70 font-bold">
                                <td colspan="2" class="py-3 px-2">TOTALES</td>
                                <td class="py-3 px-2 text-right"><?= formatDOP($totals['gross']) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['afp']) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['sfs']) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['isr']) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['other']) ?></td>
                                <td class="py-3 px-2 text-right text-red-500"><?= formatDOP($totals['deductions']) ?></td>
                                <td class="py-3 px-2 text-right text-green-400"><?= formatDOP($totals['net']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create Period Modal -->
    <div id="createPeriodModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="glass-card" style="width: min(600px, 90%);">
            <h3 class="text-xl font-semibold mb-4">Crear Período de Nómina</h3>
            <form method="POST">
                <input type="hidden" name="create_period" value="1">
                
                <div class="form-group mb-4">
                    <label for="name">Nombre del período *</label>
                    <input type="text" id="name" name="name" required placeholder="ej. Quincena 1 - Enero 2025">
                </div>
                
                <div class="form-group mb-4">
                    <label for="period_type">Tipo de período *</label>
                    <select id="period_type" name="period_type" required>
                        <option value="BIWEEKLY">Quincenal</option>
                        <option value="MONTHLY">Mensual</option>
                        <option value="WEEKLY">Semanal</option>
                        <option value="CUSTOM">Personalizado</option>
                    </select>
                </div>
                
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="form-group">
                        <label for="start_date">Fecha inicio *</label>
                        <input type="date" id="start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label for="end_date">Fecha fin *</label>
                        <input type="date" id="end_date" name="end_date" required>
                    </div>
                    <div class="form-group">
                        <label for="payment_date">Fecha de pago *</label>
                        <input type="date" id="payment_date" name="payment_date" required>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-check"></i>
                        Crear Período
                    </button>
                    <button type="button" onclick="document.getElementById('createPeriodModal').classList.add('hidden')" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Period Modal -->
    <div id="editPeriodModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="glass-card" style="width: min(600px, 90%);">
            <h3 class="text-xl font-semibold mb-4">Editar Período de Nómina</h3>
            <form method="POST">
                <input type="hidden" name="edit_period" value="1">
                <input type="hidden" id="edit_period_id" name="period_id">
                
                <div class="form-group mb-4">
                    <label for="edit_name">Nombre del período *</label>
                    <input type="text" id="edit_name" name="name" required placeholder="ej. Quincena 1 - Enero 2025">
                </div>
                
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="form-group">
                        <label for="edit_start_date">Fecha inicio *</label>
                        <input type="date" id="edit_start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_end_date">Fecha fin *</label>
                        <input type="date" id="edit_end_date" name="end_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_payment_date">Fecha de pago *</label>
                        <input type="date" id="edit_payment_date" name="payment_date" required>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-save"></i>
                        Guardar Cambios
                    </button>
                    <button type="button" onclick="document.getElementById('editPeriodModal').classList.add('hidden')" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function editPeriod(id, name, startDate, endDate, paymentDate) {
        document.getElementById('edit_period_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_start_date').value = startDate;
        document.getElementById('edit_end_date').value = endDate;
        document.getElementById('edit_payment_date').value = paymentDate;
        document.getElementById('editPeriodModal').classList.remove('hidden');
    }
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>
