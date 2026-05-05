<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';

// Check permissions
ensurePermission('hr_payroll', '../unauthorized.php');
ensurePayrollHolidaysTable($pdo);

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle holiday creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_holiday'])) {
    $holidayDate = trim($_POST['holiday_date'] ?? '');
    $holidayName = trim($_POST['holiday_name'] ?? '');
    $multiplier = (float) ($_POST['multiplier'] ?? 2.0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($holidayDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
        $errorMsg = 'Debes indicar una fecha válida para el feriado.';
    } elseif ($holidayName === '') {
        $errorMsg = 'Debes indicar el nombre del feriado.';
    } elseif ($multiplier <= 0) {
        $errorMsg = 'El multiplicador debe ser mayor a 0.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO payroll_holidays (holiday_date, name, multiplier, is_active)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), multiplier = VALUES(multiplier), is_active = VALUES(is_active)
            ");
            $stmt->execute([$holidayDate, mb_substr($holidayName, 0, 150), $multiplier, $isActive]);
            $successMsg = 'Feriado guardado correctamente.';
        } catch (PDOException $e) {
            $errorMsg = 'Error al guardar el feriado: ' . $e->getMessage();
        }
    }
}

// Handle holiday update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_holiday'])) {
    $holidayId = (int) ($_POST['holiday_id'] ?? 0);
    $holidayDate = trim($_POST['holiday_date'] ?? '');
    $holidayName = trim($_POST['holiday_name'] ?? '');
    $multiplier = (float) ($_POST['multiplier'] ?? 2.0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($holidayId <= 0) {
        $errorMsg = 'ID de feriado inválido.';
    } elseif ($holidayDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
        $errorMsg = 'Debes indicar una fecha válida para el feriado.';
    } elseif ($holidayName === '') {
        $errorMsg = 'Debes indicar el nombre del feriado.';
    } elseif ($multiplier <= 0) {
        $errorMsg = 'El multiplicador debe ser mayor a 0.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE payroll_holidays
                SET holiday_date = ?, name = ?, multiplier = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$holidayDate, mb_substr($holidayName, 0, 150), $multiplier, $isActive, $holidayId]);
            $successMsg = 'Feriado actualizado correctamente.';
        } catch (PDOException $e) {
            $errorMsg = 'Error al actualizar el feriado: ' . $e->getMessage();
        }
    }
}

// Handle holiday deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_holiday'])) {
    $holidayId = (int) ($_POST['holiday_id'] ?? 0);
    if ($holidayId > 0) {
        $pdo->prepare("DELETE FROM payroll_holidays WHERE id = ?")->execute([$holidayId]);
        $successMsg = 'Feriado eliminado.';
    }
}

// Handle deduction update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_deductions'])) {
    $pdo->beginTransaction();
    
    try {
        foreach ($_POST['deductions'] as $id => $data) {
            $stmt = $pdo->prepare("
                UPDATE payroll_deduction_config 
                SET employee_percentage = ?, employer_percentage = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['employee_percentage'],
                $data['employer_percentage'],
                isset($data['is_active']) ? 1 : 0,
                $id
            ]);
        }
        
        $pdo->commit();
        $successMsg = "Configuración de deducciones actualizada correctamente.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $errorMsg = "Error al actualizar: " . $e->getMessage();
    }
}

// Get all deduction configurations
$deductions = $pdo->query("SELECT * FROM payroll_deduction_config ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

// Get ISR scales
$isrScales = $pdo->query("SELECT * FROM payroll_isr_scales WHERE year = 2025 ORDER BY min_amount")->fetchAll(PDO::FETCH_ASSOC);

// Get holidays list
$holidays = $pdo->query("SELECT id, holiday_date, name, multiplier, is_active FROM payroll_holidays ORDER BY holiday_date DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Nómina - HR</title>
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
                    <i class="fas fa-cog text-indigo-400 mr-3"></i>
                    Configuración de Nómina
                </h1>
                <p class="text-slate-400">Personaliza las tasas de descuentos legales y escalas de ISR</p>
            </div>
            <a href="payroll.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Volver a Nómina
            </a>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        
        <?php if (isset($errorMsg)): ?>
            <div class="status-banner error mb-6"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- Deductions Configuration -->
        <div class="glass-card mb-8">
            <h2 class="text-xl font-semibold mb-6">
                <i class="fas fa-percentage text-green-400 mr-2"></i>
                Descuentos Legales República Dominicana
            </h2>

            <form method="POST">
                <input type="hidden" name="update_deductions" value="1">
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4">Código</th>
                                <th class="text-left py-3 px-4">Nombre</th>
                                <th class="text-left py-3 px-4">Descripción</th>
                                <th class="text-center py-3 px-4">% Empleado</th>
                                <th class="text-center py-3 px-4">% Patronal</th>
                                <th class="text-center py-3 px-4">Activo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deductions as $deduction): ?>
                                <tr class="border-b border-slate-800">
                                    <td class="py-3 px-4">
                                        <span class="font-mono font-bold text-blue-400"><?= htmlspecialchars($deduction['code']) ?></span>
                                    </td>
                                    <td class="py-3 px-4 font-medium"><?= htmlspecialchars($deduction['name']) ?></td>
                                    <td class="py-3 px-4 text-sm text-slate-400"><?= htmlspecialchars($deduction['description']) ?></td>
                                    <td class="py-3 px-4">
                                        <input type="number" 
                                               name="deductions[<?= $deduction['id'] ?>][employee_percentage]" 
                                               value="<?= $deduction['employee_percentage'] ?>" 
                                               step="0.01" 
                                               min="0" 
                                               max="100"
                                               class="form-control text-center w-24"
                                               <?= $deduction['code'] === 'ISR' ? 'readonly' : '' ?>>
                                    </td>
                                    <td class="py-3 px-4">
                                        <input type="number" 
                                               name="deductions[<?= $deduction['id'] ?>][employer_percentage]" 
                                               value="<?= $deduction['employer_percentage'] ?>" 
                                               step="0.01" 
                                               min="0" 
                                               max="100"
                                               class="form-control text-center w-24"
                                               <?= $deduction['code'] === 'ISR' ? 'readonly' : '' ?>>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <input type="checkbox" 
                                               name="deductions[<?= $deduction['id'] ?>][is_active]" 
                                               <?= $deduction['is_active'] ? 'checked' : '' ?>
                                               class="form-checkbox h-5 w-5 text-blue-600">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 flex justify-end">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i>
                        Guardar Cambios
                    </button>
                </div>
            </form>
        </div>

        <!-- ISR Scales -->
        <div class="glass-card">
            <h2 class="text-xl font-semibold mb-6">
                <i class="fas fa-chart-line text-purple-400 mr-2"></i>
                Escala de ISR 2025 (Anual)
            </h2>

            <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-4 mb-6">
                <p class="text-sm text-blue-300">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Nota:</strong> La escala de ISR es progresiva y se calcula automáticamente según la ley tributaria de República Dominicana.
                    Para modificar las escalas, contacte a su asesor fiscal.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4">Rango de Ingresos Anuales</th>
                            <th class="text-right py-3 px-4">Base</th>
                            <th class="text-right py-3 px-4">% sobre Excedente</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($isrScales as $scale): ?>
                            <tr class="border-b border-slate-800">
                                <td class="py-3 px-4">
                                    <?php if ($scale['max_amount']): ?>
                                        RD$<?= number_format($scale['min_amount'], 2) ?> - RD$<?= number_format($scale['max_amount'], 2) ?>
                                    <?php else: ?>
                                        Más de RD$<?= number_format($scale['min_amount'], 2) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-right">RD$<?= number_format($scale['base_tax'], 2) ?></td>
                                <td class="py-3 px-4 text-right">
                                    <span class="font-bold <?= $scale['excess_rate'] > 0 ? 'text-orange-400' : 'text-green-400' ?>">
                                        <?= $scale['excess_rate'] ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Días Festivos (pago doble) -->
        <div class="glass-card mt-8">
            <div class="flex items-start justify-between mb-4 gap-3 flex-wrap">
                <div>
                    <h2 class="text-xl font-semibold mb-1">
                        <i class="fas fa-star text-yellow-400 mr-2"></i>
                        Días Festivos (Pago Doble)
                    </h2>
                    <p class="text-sm text-slate-400">Las horas trabajadas en estas fechas se pagan al doble, excepto a empleados con salario fijo.</p>
                </div>
                <button type="button" onclick="document.getElementById('createHolidayModal').classList.remove('hidden')" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    Nuevo Feriado
                </button>
            </div>

            <?php if (empty($holidays)): ?>
                <p class="text-slate-400 text-center py-6">No hay feriados configurados.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4">Fecha</th>
                                <th class="text-left py-3 px-4">Nombre</th>
                                <th class="text-center py-3 px-4">Multiplicador</th>
                                <th class="text-center py-3 px-4">Activo</th>
                                <th class="text-center py-3 px-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($holidays as $h): ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 font-mono text-blue-300">
                                        <?= date('d/m/Y', strtotime($h['holiday_date'])) ?>
                                    </td>
                                    <td class="py-3 px-4 font-medium"><?= htmlspecialchars($h['name']) ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="font-bold text-yellow-400"><?= number_format((float)$h['multiplier'], 2) ?>x</span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <?php if ((int)$h['is_active'] === 1): ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold text-white bg-green-600">Activo</span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold text-white bg-gray-500">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex justify-center gap-2">
                                            <button type="button"
                                                    onclick="editHoliday(<?= (int)$h['id'] ?>, '<?= htmlspecialchars($h['holiday_date'], ENT_QUOTES) ?>', '<?= htmlspecialchars($h['name'], ENT_QUOTES) ?>', <?= (float)$h['multiplier'] ?>, <?= (int)$h['is_active'] ?>)"
                                                    class="px-2 py-1 rounded bg-yellow-600 hover:bg-yellow-700 text-white text-xs">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este feriado?')">
                                                <input type="hidden" name="delete_holiday" value="1">
                                                <input type="hidden" name="holiday_id" value="<?= (int)$h['id'] ?>">
                                                <button type="submit" class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Create Holiday Modal -->
        <div id="createHolidayModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="glass-card" style="width: min(500px, 90%);">
                <h3 class="text-xl font-semibold mb-4">Nuevo Feriado</h3>
                <form method="POST">
                    <input type="hidden" name="create_holiday" value="1">

                    <div class="form-group mb-4">
                        <label for="holiday_date">Fecha del feriado *</label>
                        <input type="date" id="holiday_date" name="holiday_date" required>
                    </div>

                    <div class="form-group mb-4">
                        <label for="holiday_name">Nombre del feriado *</label>
                        <input type="text" id="holiday_name" name="holiday_name" required maxlength="150" placeholder="ej. Día de la Independencia">
                    </div>

                    <div class="form-group mb-4">
                        <label for="multiplier">Multiplicador de horas *</label>
                        <input type="number" id="multiplier" name="multiplier" required step="0.01" min="0.01" value="2.00">
                        <p class="text-xs text-slate-400 mt-1">2.00 = pago doble. Se multiplican las horas trabajadas ese día.</p>
                    </div>

                    <div class="form-group mb-6">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" checked class="form-checkbox h-5 w-5 text-blue-600">
                            <span>Activo</span>
                        </label>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="btn-primary flex-1">
                            <i class="fas fa-check"></i>
                            Guardar
                        </button>
                        <button type="button" onclick="document.getElementById('createHolidayModal').classList.add('hidden')" class="btn-secondary flex-1">
                            <i class="fas fa-times"></i>
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Holiday Modal -->
        <div id="editHolidayModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="glass-card" style="width: min(500px, 90%);">
                <h3 class="text-xl font-semibold mb-4">Editar Feriado</h3>
                <form method="POST">
                    <input type="hidden" name="update_holiday" value="1">
                    <input type="hidden" id="edit_holiday_id" name="holiday_id">

                    <div class="form-group mb-4">
                        <label for="edit_holiday_date">Fecha del feriado *</label>
                        <input type="date" id="edit_holiday_date" name="holiday_date" required>
                    </div>

                    <div class="form-group mb-4">
                        <label for="edit_holiday_name">Nombre del feriado *</label>
                        <input type="text" id="edit_holiday_name" name="holiday_name" required maxlength="150">
                    </div>

                    <div class="form-group mb-4">
                        <label for="edit_multiplier">Multiplicador de horas *</label>
                        <input type="number" id="edit_multiplier" name="multiplier" required step="0.01" min="0.01">
                    </div>

                    <div class="form-group mb-6">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" id="edit_is_active" name="is_active" value="1" class="form-checkbox h-5 w-5 text-blue-600">
                            <span>Activo</span>
                        </label>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="btn-primary flex-1">
                            <i class="fas fa-save"></i>
                            Guardar Cambios
                        </button>
                        <button type="button" onclick="document.getElementById('editHolidayModal').classList.add('hidden')" class="btn-secondary flex-1">
                            <i class="fas fa-times"></i>
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        function editHoliday(id, date, name, multiplier, isActive) {
            document.getElementById('edit_holiday_id').value = id;
            document.getElementById('edit_holiday_date').value = date;
            document.getElementById('edit_holiday_name').value = name;
            document.getElementById('edit_multiplier').value = multiplier;
            document.getElementById('edit_is_active').checked = isActive === 1;
            document.getElementById('editHolidayModal').classList.remove('hidden');
        }
        </script>

        <!-- Info Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">
            <div class="glass-card">
                <div class="flex items-center mb-3">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-green-500 mr-3">
                        <i class="fas fa-check text-white"></i>
                    </div>
                    <h3 class="font-semibold">Cálculo Automático</h3>
                </div>
                <p class="text-sm text-slate-400">
                    Los descuentos se calculan automáticamente basados en el salario bruto de cada empleado.
                </p>
            </div>

            <div class="glass-card">
                <div class="flex items-center mb-3">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-blue-500 mr-3">
                        <i class="fas fa-sync text-white"></i>
                    </div>
                    <h3 class="font-semibold">Actualización en Tiempo Real</h3>
                </div>
                <p class="text-sm text-slate-400">
                    Los cambios se aplican inmediatamente al calcular nuevos períodos de nómina.
                </p>
            </div>

            <div class="glass-card">
                <div class="flex items-center mb-3">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-purple-500 mr-3">
                        <i class="fas fa-shield-alt text-white"></i>
                    </div>
                    <h3 class="font-semibold">Cumplimiento Legal</h3>
                </div>
                <p class="text-sm text-slate-400">
                    Configuración basada en las normativas vigentes de TSS y DGII.
                </p>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>
