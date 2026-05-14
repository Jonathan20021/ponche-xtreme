<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';

ensurePermission('hr_payroll', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$successMsg = null;
$errorMsg = null;

// Crear
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_deduction'])) {
    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $type = ($_POST['type'] ?? 'FIXED') === 'PERCENTAGE' ? 'PERCENTAGE' : 'FIXED';
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $startDate = trim((string)($_POST['start_date'] ?? '')) ?: null;
    $endDate = trim((string)($_POST['end_date'] ?? '')) ?: null;

    if ($employeeId <= 0) {
        $errorMsg = 'Debes seleccionar un empleado.';
    } elseif ($name === '') {
        $errorMsg = 'El nombre es obligatorio.';
    } elseif ($amount <= 0) {
        $errorMsg = 'El monto debe ser mayor a 0.';
    } elseif ($type === 'PERCENTAGE' && $amount > 100) {
        $errorMsg = 'Para porcentajes, el valor no puede superar 100.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO employee_deductions
                    (employee_id, name, description, type, amount, is_active, start_date, end_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $employeeId,
                mb_substr($name, 0, 100),
                $description !== '' ? $description : null,
                $type,
                $amount,
                $isActive,
                $startDate,
                $endDate
            ]);
            $successMsg = 'Descuento creado correctamente. Recalcula la nómina del período afectado para que aparezca en el slip.';
        } catch (PDOException $e) {
            $errorMsg = 'Error al crear el descuento: ' . $e->getMessage();
        }
    }
}

// Actualizar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_deduction'])) {
    $deductionId = (int)($_POST['deduction_id'] ?? 0);
    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $type = ($_POST['type'] ?? 'FIXED') === 'PERCENTAGE' ? 'PERCENTAGE' : 'FIXED';
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $startDate = trim((string)($_POST['start_date'] ?? '')) ?: null;
    $endDate = trim((string)($_POST['end_date'] ?? '')) ?: null;

    if ($deductionId <= 0) {
        $errorMsg = 'ID de descuento inválido.';
    } elseif ($employeeId <= 0) {
        $errorMsg = 'Debes seleccionar un empleado.';
    } elseif ($name === '') {
        $errorMsg = 'El nombre es obligatorio.';
    } elseif ($amount <= 0) {
        $errorMsg = 'El monto debe ser mayor a 0.';
    } elseif ($type === 'PERCENTAGE' && $amount > 100) {
        $errorMsg = 'Para porcentajes, el valor no puede superar 100.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE employee_deductions
                SET employee_id = ?, name = ?, description = ?, type = ?, amount = ?,
                    is_active = ?, start_date = ?, end_date = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $employeeId,
                mb_substr($name, 0, 100),
                $description !== '' ? $description : null,
                $type,
                $amount,
                $isActive,
                $startDate,
                $endDate,
                $deductionId
            ]);
            $successMsg = 'Descuento actualizado. Recalcula la nómina del período afectado para que el cambio se refleje en el slip.';
        } catch (PDOException $e) {
            $errorMsg = 'Error al actualizar: ' . $e->getMessage();
        }
    }
}

// Toggle activo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    $deductionId = (int)($_POST['deduction_id'] ?? 0);
    if ($deductionId > 0) {
        $pdo->prepare("UPDATE employee_deductions SET is_active = 1 - is_active WHERE id = ?")
            ->execute([$deductionId]);
        $successMsg = 'Estado del descuento actualizado. Recuerda recalcular la nómina del período.';
    }
}

// Eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_deduction'])) {
    $deductionId = (int)($_POST['deduction_id'] ?? 0);
    if ($deductionId > 0) {
        $pdo->prepare("DELETE FROM employee_deductions WHERE id = ?")->execute([$deductionId]);
        $successMsg = 'Descuento eliminado. Recalcula la nómina del período afectado.';
    }
}

// Filtros
$filterEmployeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$filterStatus = $_GET['status'] ?? 'all'; // all | active | inactive
$filterType = $_GET['type'] ?? 'all'; // all | manual | loan

// Lista de empleados (activos + en prueba) para el dropdown
$employees = $pdo->query("
    SELECT e.id, e.employee_code, e.first_name, e.last_name, e.employment_status
    FROM employees e
    JOIN users u ON u.id = e.user_id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    ORDER BY e.last_name, e.first_name
")->fetchAll(PDO::FETCH_ASSOC);

// Lista de descuentos con filtros
$where = [];
$params = [];
if ($filterEmployeeId > 0) {
    $where[] = 'ed.employee_id = ?';
    $params[] = $filterEmployeeId;
}
if ($filterStatus === 'active') {
    $where[] = 'ed.is_active = 1';
} elseif ($filterStatus === 'inactive') {
    $where[] = 'ed.is_active = 0';
}
if ($filterType === 'loan') {
    $where[] = "(ed.name LIKE 'Préstamo%' OR ed.name LIKE 'Prestamo%')";
} elseif ($filterType === 'manual') {
    $where[] = "ed.name NOT LIKE 'Préstamo%' AND ed.name NOT LIKE 'Prestamo%'";
}
$whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

$deductions = $pdo->prepare("
    SELECT ed.id, ed.employee_id, ed.name, ed.description, ed.type, ed.amount,
           ed.is_active, ed.start_date, ed.end_date, ed.created_at,
           e.employee_code, e.first_name, e.last_name
    FROM employee_deductions ed
    LEFT JOIN employees e ON e.id = ed.employee_id
    $whereSql
    ORDER BY e.last_name, e.first_name, ed.is_active DESC, ed.id DESC
");
$deductions->execute($params);
$deductions = $deductions->fetchAll(PDO::FETCH_ASSOC);

// Totales rápidos
$totalActive = 0;
$totalInactive = 0;
foreach ($deductions as $d) {
    if ((int)$d['is_active'] === 1) {
        $totalActive++;
    } else {
        $totalInactive++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descuentos Personalizados por Empleado - HR</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-3">
            <div>
                <h1 class="text-3xl font-bold mb-2">
                    <i class="fas fa-hand-holding-usd text-amber-400 mr-3"></i>
                    Descuentos Personalizados
                </h1>
                <p class="text-slate-400">Gestiona descuentos recurrentes o de un solo período por empleado (préstamos, cooperativas, descuentos varios).</p>
            </div>
            <div class="flex gap-2">
                <button onclick="openCreateModal()" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    Nuevo Descuento
                </button>
                <a href="payroll.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver a Nómina
                </a>
            </div>
        </div>

        <?php if ($successMsg): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="status-banner error mb-6"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-4 mb-6">
            <p class="text-sm text-blue-300">
                <i class="fas fa-info-circle mr-2"></i>
                Los descuentos aquí registrados se aplican automáticamente al calcular la nómina, siempre que el rango de fechas (si está definido) coincida con el período. Tras crear, editar o borrar, recalcula la nómina del período afectado para que los slips reflejen el cambio.
            </p>
        </div>

        <!-- Filtros -->
        <form method="GET" class="glass-card mb-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">Empleado</label>
                    <select name="employee_id" class="form-control w-full" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= (int)$emp['id'] ?>" <?= $filterEmployeeId === (int)$emp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?>
                                (<?= htmlspecialchars($emp['employee_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">Estado</label>
                    <select name="status" class="form-control w-full" onchange="this.form.submit()">
                        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Solo activos</option>
                        <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Solo inactivos</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">Tipo</label>
                    <select name="type" class="form-control w-full" onchange="this.form.submit()">
                        <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="manual" <?= $filterType === 'manual' ? 'selected' : '' ?>>Manuales</option>
                        <option value="loan" <?= $filterType === 'loan' ? 'selected' : '' ?>>Préstamos (Finanzas)</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <a href="employee_deductions.php" class="btn-secondary w-full text-center">
                        <i class="fas fa-times"></i> Limpiar filtros
                    </a>
                </div>
            </div>
            <div class="mt-3 text-sm text-slate-400">
                Activos: <span class="font-semibold text-emerald-400"><?= $totalActive ?></span> ·
                Inactivos: <span class="font-semibold text-slate-300"><?= $totalInactive ?></span> ·
                Total mostrado: <span class="font-semibold"><?= count($deductions) ?></span>
            </div>
        </form>

        <!-- Tabla -->
        <div class="glass-card">
            <?php if (empty($deductions)): ?>
                <p class="text-slate-400 text-center py-10">No hay descuentos que coincidan con los filtros.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-3">Empleado</th>
                                <th class="text-left py-3 px-3">Concepto</th>
                                <th class="text-center py-3 px-3">Tipo</th>
                                <th class="text-right py-3 px-3">Monto</th>
                                <th class="text-center py-3 px-3">Vigencia</th>
                                <th class="text-center py-3 px-3">Estado</th>
                                <th class="text-center py-3 px-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deductions as $d):
                                $isLoan = preg_match('/^(Préstamo|Prestamo)/', (string)$d['name']) === 1;
                                $employeeLabel = $d['employee_id']
                                    ? trim(($d['last_name'] ?? '') . ', ' . ($d['first_name'] ?? '')) . ' (' . htmlspecialchars($d['employee_code'] ?? '') . ')'
                                    : '<span class="text-rose-300">Empleado no encontrado (id=' . (int)$d['employee_id'] . ')</span>';
                            ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/40">
                                    <td class="py-3 px-3">
                                        <?= $employeeLabel ?>
                                    </td>
                                    <td class="py-3 px-3">
                                        <div class="font-medium"><?= htmlspecialchars($d['name'] ?: '(sin nombre)') ?></div>
                                        <?php if (!empty($d['description'])): ?>
                                            <div class="text-xs text-slate-400 mt-1"><?= htmlspecialchars($d['description']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($isLoan): ?>
                                            <span class="text-xs text-emerald-300"><i class="fas fa-link mr-1"></i>Sincronizado desde Finanzas</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3 text-center">
                                        <?php if ($d['type'] === 'PERCENTAGE'): ?>
                                            <span class="px-2 py-1 rounded-full text-xs bg-purple-600 text-white">%</span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 rounded-full text-xs bg-slate-600 text-white">Fijo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3 text-right font-semibold text-rose-300">
                                        <?php if ($d['type'] === 'PERCENTAGE'): ?>
                                            <?= number_format((float)$d['amount'], 2) ?>%
                                        <?php else: ?>
                                            <?= formatDOP((float)$d['amount']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3 text-center text-xs text-slate-300">
                                        <?php
                                        $sd = $d['start_date'] ? date('d/m/Y', strtotime($d['start_date'])) : 'Sin inicio';
                                        $ed = $d['end_date'] ? date('d/m/Y', strtotime($d['end_date'])) : 'Sin fin';
                                        echo htmlspecialchars($sd . ' → ' . $ed);
                                        ?>
                                    </td>
                                    <td class="py-3 px-3 text-center">
                                        <?php if ((int)$d['is_active'] === 1): ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold text-white bg-green-600">Activo</span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold text-white bg-gray-500">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3">
                                        <div class="flex justify-center gap-2">
                                            <button type="button"
                                                onclick='openEditModal(<?= json_encode([
                                                    "id" => (int)$d["id"],
                                                    "employee_id" => (int)$d["employee_id"],
                                                    "name" => $d["name"] ?? "",
                                                    "description" => $d["description"] ?? "",
                                                    "type" => $d["type"],
                                                    "amount" => (float)$d["amount"],
                                                    "is_active" => (int)$d["is_active"],
                                                    "start_date" => $d["start_date"],
                                                    "end_date" => $d["end_date"],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                class="px-2 py-1 rounded bg-yellow-600 hover:bg-yellow-700 text-white text-xs"
                                                title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="toggle_active" value="1">
                                                <input type="hidden" name="deduction_id" value="<?= (int)$d['id'] ?>">
                                                <button type="submit" class="px-2 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs" title="Activar/Desactivar">
                                                    <i class="fas fa-power-off"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este descuento? Esta acción no se puede deshacer.')">
                                                <input type="hidden" name="delete_deduction" value="1">
                                                <input type="hidden" name="deduction_id" value="<?= (int)$d['id'] ?>">
                                                <button type="submit" class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs" title="Eliminar">
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

        <!-- Modal Crear/Editar -->
        <div id="deductionModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div class="glass-card" style="width: min(620px, 100%); max-height: 90vh; overflow-y: auto;">
                <h3 id="modalTitle" class="text-xl font-semibold mb-4">Nuevo Descuento</h3>
                <form method="POST" id="deductionForm">
                    <input type="hidden" name="create_deduction" id="actionField" value="1">
                    <input type="hidden" name="deduction_id" id="deductionIdField" value="">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-300 mb-1">Empleado <span class="text-rose-400">*</span></label>
                            <select name="employee_id" id="employeeIdField" class="form-control w-full" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= (int)$emp['id'] ?>">
                                        <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?>
                                        (<?= htmlspecialchars($emp['employee_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-300 mb-1">Concepto <span class="text-rose-400">*</span></label>
                            <input type="text" name="name" id="nameField" maxlength="100" required
                                placeholder="Ej: Préstamo personal, Adelanto de salario, Uniforme, etc."
                                class="form-control w-full">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Tipo <span class="text-rose-400">*</span></label>
                            <select name="type" id="typeField" class="form-control w-full" required>
                                <option value="FIXED">Monto fijo (RD$)</option>
                                <option value="PERCENTAGE">Porcentaje sobre el bruto (%)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Monto <span class="text-rose-400">*</span></label>
                            <input type="number" name="amount" id="amountField" step="0.01" min="0.01" required
                                class="form-control w-full text-right">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Fecha de inicio</label>
                            <input type="date" name="start_date" id="startDateField" class="form-control w-full">
                            <p class="text-xs text-slate-500 mt-1">Vacío = aplica desde siempre.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Fecha de fin</label>
                            <input type="date" name="end_date" id="endDateField" class="form-control w-full">
                            <p class="text-xs text-slate-500 mt-1">Vacío = sin fecha de corte.</p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-300 mb-1">Descripción / Nota</label>
                            <textarea name="description" id="descriptionField" rows="2" maxlength="500"
                                class="form-control w-full" placeholder="Detalles opcionales para referencia interna"></textarea>
                        </div>

                        <div class="md:col-span-2">
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="is_active" id="isActiveField" class="form-checkbox h-5 w-5 text-blue-600" checked>
                                <span class="text-sm">Activo (se aplica al calcular nómina)</span>
                            </label>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" onclick="closeModal()" class="btn-secondary">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i> Guardar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Nuevo Descuento';
            document.getElementById('actionField').name = 'create_deduction';
            document.getElementById('deductionIdField').value = '';
            document.getElementById('employeeIdField').value = '';
            document.getElementById('nameField').value = '';
            document.getElementById('typeField').value = 'FIXED';
            document.getElementById('amountField').value = '';
            document.getElementById('startDateField').value = '';
            document.getElementById('endDateField').value = '';
            document.getElementById('descriptionField').value = '';
            document.getElementById('isActiveField').checked = true;
            document.getElementById('deductionModal').classList.remove('hidden');
        }

        function openEditModal(data) {
            document.getElementById('modalTitle').textContent = 'Editar Descuento';
            document.getElementById('actionField').name = 'update_deduction';
            document.getElementById('deductionIdField').value = data.id;
            document.getElementById('employeeIdField').value = data.employee_id;
            document.getElementById('nameField').value = data.name || '';
            document.getElementById('typeField').value = data.type;
            document.getElementById('amountField').value = data.amount;
            document.getElementById('startDateField').value = data.start_date || '';
            document.getElementById('endDateField').value = data.end_date || '';
            document.getElementById('descriptionField').value = data.description || '';
            document.getElementById('isActiveField').checked = data.is_active === 1;
            document.getElementById('deductionModal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('deductionModal').classList.add('hidden');
        }

        document.getElementById('deductionModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>
</html>
