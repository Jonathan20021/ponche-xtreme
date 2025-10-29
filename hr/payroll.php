<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_payroll');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle period creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_period'])) {
    $periodName = trim($_POST['period_name']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $paymentDate = $_POST['payment_date'] ?: null;
    
    $stmt = $pdo->prepare("INSERT INTO payroll_periods (period_name, start_date, end_date, payment_date, created_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$periodName, $startDate, $endDate, $paymentDate, $_SESSION['user_id']]);
    $successMsg = "Período de nómina creado correctamente.";
}

// Handle payroll calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_payroll'])) {
    $periodId = (int)$_POST['period_id'];
    
    // Get period details
    $periodStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $periodStmt->execute([$periodId]);
    $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($period) {
        // Get all active employees
        $employees = $pdo->query("
            SELECT e.*, u.id as user_id, u.username, u.hourly_rate, u.preferred_currency, u.overtime_multiplier
            FROM employees e
            JOIN users u ON u.id = e.user_id
            WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $config = getScheduleConfig($pdo);
        $globalOvertimeMultiplier = (float)$config['overtime_multiplier'];
        
        foreach ($employees as $emp) {
            $userId = $emp['user_id'];
            $employeeId = $emp['id'];
            $hourlyRate = (float)$emp['hourly_rate'];
            $overtimeMultiplier = $emp['overtime_multiplier'] !== null ? (float)$emp['overtime_multiplier'] : $globalOvertimeMultiplier;
            
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
            $scheduledHours = (float)$config['scheduled_hours'];
            
            foreach ($attendanceData as $day) {
                $entry = new DateTime($day['entry_time']);
                $exit = new DateTime($day['exit_time']);
                $workedSeconds = $exit->getTimestamp() - $entry->getTimestamp();
                $workedHours = $workedSeconds / 3600;
                
                // Subtract breaks (lunch + break)
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
            
            // Calculate pay
            $regularPay = $totalRegularHours * $hourlyRate;
            $overtimeRate = $hourlyRate * $overtimeMultiplier;
            $overtimePay = $totalOvertimeHours * $overtimeRate;
            $grossPay = $regularPay + $overtimePay;
            $netPay = $grossPay; // No deductions for now
            
            // Check if record exists
            $checkStmt = $pdo->prepare("SELECT id FROM payroll_records WHERE payroll_period_id = ? AND employee_id = ?");
            $checkStmt->execute([$periodId, $employeeId]);
            $existingRecord = $checkStmt->fetch();
            
            if ($existingRecord) {
                // Update existing record
                $updateStmt = $pdo->prepare("
                    UPDATE payroll_records SET
                        regular_hours = ?, overtime_hours = ?, total_hours = ?,
                        hourly_rate = ?, overtime_rate = ?,
                        regular_pay = ?, overtime_pay = ?,
                        gross_pay = ?, net_pay = ?,
                        currency = ?, calculated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $totalRegularHours, $totalOvertimeHours, ($totalRegularHours + $totalOvertimeHours),
                    $hourlyRate, $overtimeRate,
                    $regularPay, $overtimePay,
                    $grossPay, $netPay,
                    $emp['preferred_currency'],
                    $existingRecord['id']
                ]);
            } else {
                // Insert new record
                $insertStmt = $pdo->prepare("
                    INSERT INTO payroll_records (
                        payroll_period_id, employee_id, user_id,
                        regular_hours, overtime_hours, total_hours,
                        hourly_rate, overtime_rate,
                        regular_pay, overtime_pay,
                        gross_pay, net_pay, currency, calculated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insertStmt->execute([
                    $periodId, $employeeId, $userId,
                    $totalRegularHours, $totalOvertimeHours, ($totalRegularHours + $totalOvertimeHours),
                    $hourlyRate, $overtimeRate,
                    $regularPay, $overtimePay,
                    $grossPay, $netPay,
                    $emp['preferred_currency']
                ]);
            }
        }
        
        $successMsg = "Nómina calculada correctamente para todos los empleados.";
    }
}

// Get all payroll periods
$periods = $pdo->query("
    SELECT pp.*, 
           COUNT(pr.id) as employee_count,
           SUM(pr.gross_pay) as total_gross,
           u.username as created_by_username
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pr.payroll_period_id = pp.id
    LEFT JOIN users u ON u.id = pp.created_by
    GROUP BY pp.id
    ORDER BY pp.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get selected period details
$selectedPeriodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : null;
$selectedPeriod = null;
$payrollRecords = [];

if ($selectedPeriodId) {
    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$selectedPeriodId]);
    $selectedPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedPeriod) {
        $recordsStmt = $pdo->prepare("
            SELECT pr.*, e.first_name, e.last_name, e.employee_code, u.username, d.name as department_name
            FROM payroll_records pr
            JOIN employees e ON e.id = pr.employee_id
            JOIN users u ON u.id = pr.user_id
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
    <title>Nómina - HR</title>
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
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-money-bill-wave text-green-400 mr-3"></i>
                    Control de Nómina
                </h1>
                <p class="text-slate-400">Gestión de períodos de pago y cálculo de nómina</p>
            </div>
            <div class="flex gap-3">
                <button onclick="document.getElementById('createPeriodModal').classList.remove('hidden')" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    Nuevo Período
                </button>
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver a HR
                </a>
            </div>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <!-- Periods List -->
        <div class="glass-card mb-8">
            <h2 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-calendar-alt text-indigo-400 mr-2"></i>
                Períodos de Nómina
            </h2>
            
            <?php if (empty($periods)): ?>
                <p class="text-slate-400 text-center py-8">No hay períodos de nómina creados.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Período</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Fechas</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Pago</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Empleados</th>
                                <th class="text-right py-3 px-4 text-slate-400 font-medium">Total Bruto</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Estado</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periods as $period): ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                    <td class="py-3 px-4">
                                        <p class="text-white font-medium"><?= htmlspecialchars($period['period_name']) ?></p>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300 text-sm">
                                        <?= date('d/m/Y', strtotime($period['start_date'])) ?> - 
                                        <?= date('d/m/Y', strtotime($period['end_date'])) ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300 text-sm">
                                        <?= $period['payment_date'] ? date('d/m/Y', strtotime($period['payment_date'])) : 'No definido' ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="tag-pill"><?= $period['employee_count'] ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-right text-white font-semibold">
                                        $<?= number_format($period['total_gross'] ?: 0, 2) ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <?php
                                        $statusColors = [
                                            'OPEN' => 'bg-blue-500',
                                            'PROCESSING' => 'bg-yellow-500',
                                            'PAID' => 'bg-green-500',
                                            'CLOSED' => 'bg-gray-500'
                                        ];
                                        $statusColor = $statusColors[$period['status']] ?? 'bg-gray-500';
                                        ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold text-white <?= $statusColor ?>">
                                            <?= htmlspecialchars($period['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex justify-center gap-2">
                                            <a href="?period_id=<?= $period['id'] ?>" class="btn-primary text-sm">
                                                <i class="fas fa-eye"></i>
                                                Ver
                                            </a>
                                            <?php if ($period['status'] === 'OPEN'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="calculate_payroll" value="1">
                                                    <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                    <button type="submit" class="btn-secondary text-sm" onclick="return confirm('¿Calcular nómina para este período?')">
                                                        <i class="fas fa-calculator"></i>
                                                        Calcular
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
        <?php if ($selectedPeriod): ?>
            <div class="glass-card">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-semibold text-white">
                        <i class="fas fa-list text-indigo-400 mr-2"></i>
                        Detalle de Nómina: <?= htmlspecialchars($selectedPeriod['period_name']) ?>
                    </h2>
                    <button onclick="window.print()" class="btn-secondary text-sm">
                        <i class="fas fa-print"></i>
                        Imprimir
                    </button>
                </div>

                <?php if (empty($payrollRecords)): ?>
                    <p class="text-slate-400 text-center py-8">No hay registros de nómina para este período. Haga clic en "Calcular" para generar la nómina.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-700">
                                    <th class="text-left py-3 px-2 text-slate-400 font-medium">Empleado</th>
                                    <th class="text-left py-3 px-2 text-slate-400 font-medium">Código</th>
                                    <th class="text-center py-3 px-2 text-slate-400 font-medium">Hrs Reg.</th>
                                    <th class="text-center py-3 px-2 text-slate-400 font-medium">Hrs Extra</th>
                                    <th class="text-center py-3 px-2 text-slate-400 font-medium">Total Hrs</th>
                                    <th class="text-right py-3 px-2 text-slate-400 font-medium">Tarifa</th>
                                    <th class="text-right py-3 px-2 text-slate-400 font-medium">Pago Reg.</th>
                                    <th class="text-right py-3 px-2 text-slate-400 font-medium">Pago Extra</th>
                                    <th class="text-right py-3 px-2 text-slate-400 font-medium">Total Bruto</th>
                                    <th class="text-right py-3 px-2 text-slate-400 font-medium">Total Neto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $totalRegularHours = 0;
                                $totalOvertimeHours = 0;
                                $totalGross = 0;
                                $totalNet = 0;
                                
                                foreach ($payrollRecords as $record): 
                                    $totalRegularHours += $record['regular_hours'];
                                    $totalOvertimeHours += $record['overtime_hours'];
                                    $totalGross += $record['gross_pay'];
                                    $totalNet += $record['net_pay'];
                                ?>
                                    <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                        <td class="py-3 px-2 text-white">
                                            <?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?>
                                        </td>
                                        <td class="py-3 px-2 text-slate-300">
                                            <?= htmlspecialchars($record['employee_code']) ?>
                                        </td>
                                        <td class="py-3 px-2 text-center text-slate-300">
                                            <?= number_format($record['regular_hours'], 2) ?>
                                        </td>
                                        <td class="py-3 px-2 text-center text-orange-400">
                                            <?= number_format($record['overtime_hours'], 2) ?>
                                        </td>
                                        <td class="py-3 px-2 text-center text-white font-medium">
                                            <?= number_format($record['total_hours'], 2) ?>
                                        </td>
                                        <td class="py-3 px-2 text-right text-slate-300">
                                            $<?= number_format($record['hourly_rate'], 2) ?>
                                        </td>
                                        <td class="py-3 px-2 text-right text-slate-300">
                                            $<?= number_format($record['regular_pay'], 2) ?>
                                        </td>
                                        <td class="py-3 px-2 text-right text-orange-400">
                                            $<?= number_format($record['overtime_pay'], 2) ?>
                                        </td>
                                        <td class="py-3 px-2 text-right text-white font-semibold">
                                            $<?= number_format($record['gross_pay'], 2) ?>
                                        </td>
                                        <td class="py-3 px-2 text-right text-green-400 font-semibold">
                                            $<?= number_format($record['net_pay'], 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="bg-slate-800/70 font-bold">
                                    <td colspan="2" class="py-3 px-2 text-white">TOTALES</td>
                                    <td class="py-3 px-2 text-center text-white"><?= number_format($totalRegularHours, 2) ?></td>
                                    <td class="py-3 px-2 text-center text-orange-400"><?= number_format($totalOvertimeHours, 2) ?></td>
                                    <td class="py-3 px-2 text-center text-white"><?= number_format($totalRegularHours + $totalOvertimeHours, 2) ?></td>
                                    <td colspan="3" class="py-3 px-2"></td>
                                    <td class="py-3 px-2 text-right text-white">$<?= number_format($totalGross, 2) ?></td>
                                    <td class="py-3 px-2 text-right text-green-400">$<?= number_format($totalNet, 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create Period Modal -->
    <div id="createPeriodModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="glass-card" style="width: min(500px, 90%);">
            <h3 class="text-xl font-semibold text-white mb-4">Crear Período de Nómina</h3>
            <form method="POST">
                <input type="hidden" name="create_period" value="1">
                
                <div class="form-group mb-4">
                    <label for="period_name">Nombre del período *</label>
                    <input type="text" id="period_name" name="period_name" required placeholder="ej. Quincena 1 - Enero 2025">
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="start_date">Fecha inicio *</label>
                        <input type="date" id="start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label for="end_date">Fecha fin *</label>
                        <input type="date" id="end_date" name="end_date" required>
                    </div>
                </div>
                
                <div class="form-group mb-6">
                    <label for="payment_date">Fecha de pago</label>
                    <input type="date" id="payment_date" name="payment_date">
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

    <?php include '../footer.php'; ?>
</body>
</html>
