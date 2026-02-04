<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$employeeId = $_GET['employee_id'] ?? null;
$returnUrl = 'inventory.php';
if (!empty($employeeId)) {
    $returnUrl .= '?employee_id=' . (int) $employeeId;
}

// Handle Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $employeeId = (int) $_POST['employee_id'];
        $itemTypeId = (int) $_POST['item_type_id'];
        $details = trim($_POST['details']);
        $uuid = trim($_POST['uuid']) ?: null;
        $notes = trim($_POST['notes']);
        $assignedDate = $_POST['assigned_date'];

        $stmt = $pdo->prepare("
            INSERT INTO employee_inventory 
            (employee_id, item_type_id, details, uuid, status, assigned_date, assigned_by, notes)
            VALUES (?, ?, ?, ?, 'ASSIGNED', ?, ?, ?)
        ");
        $stmt->execute([
            $employeeId,
            $itemTypeId,
            $details,
            $uuid,
            $assignedDate,
            $_SESSION['user_id'],
            $notes
        ]);

        $successMsg = "Artículo asignado correctamente.";

        $metaStmt = $pdo->prepare("
            SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                   e.employee_code,
                   it.name AS item_name,
                   c.name AS category_name
            FROM employees e
            JOIN inventory_item_types it ON it.id = ?
            JOIN inventory_categories c ON c.id = it.category_id
            WHERE e.id = ?
        ");
        $metaStmt->execute([$itemTypeId, $employeeId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC);

        if ($meta && function_exists('log_custom_action')) {
            $description = "Inventario asignado: {$meta['item_name']} a {$meta['employee_name']}";
            log_custom_action(
                $pdo,
                $_SESSION['user_id'] ?? null,
                $_SESSION['full_name'] ?? 'Sistema',
                $_SESSION['role'] ?? 'system',
                'inventory',
                'assign',
                $description,
                'employee_inventory',
                (int) $pdo->lastInsertId(),
                [
                    'employee_code' => $meta['employee_code'],
                    'category' => $meta['category_name'],
                    'uuid' => $uuid,
                    'details' => $details,
                    'assigned_date' => $assignedDate,
                ]
            );
        }

        // Redirect if it came from employee profile
        if (isset($_GET['redirect_employee'])) {
            // We can redirect back or just show success
        }

    } catch (Exception $e) {
        $errorMsg = "Error al asignar: " . $e->getMessage();
    }
}

// Get Data - Get ALL active employees without limits
$employees = $pdo->query("
    SELECT id, first_name, last_name, employee_code, department 
    FROM employees 
    WHERE employment_status = 'ACTIVE' 
    ORDER BY first_name, last_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get Item Types grouped by category
$itemsQuery = $pdo->query("
    SELECT it.*, c.name as category_name 
    FROM inventory_item_types it 
    JOIN inventory_categories c ON c.id = it.category_id 
    ORDER BY c.name, it.name
");
$itemTypes = [];
while ($row = $itemsQuery->fetch(PDO::FETCH_ASSOC)) {
    $itemTypes[$row['category_name']][] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asignar Inventario - HR</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .select2-container--default .select2-selection--single {
            background-color: #1e293b;
            border: 1px solid #334155;
            border-radius: 0.5rem;
            height: 48px;
            padding: 8px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: white;
            line-height: 32px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 46px;
        }
        .select2-dropdown {
            background-color: #1e293b;
            border: 1px solid #334155;
        }
        .select2-container--default .select2-results__option {
            color: #cbd5e1;
        }
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #0891b2;
        }
        .select2-container--default .select2-search--dropdown .select2-search__field {
            background-color: #0f172a;
            border: 1px solid #334155;
            color: white;
        }
    </style>
</head>

<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="max-w-2xl mx-auto">
            <div class="flex items-center gap-4 mb-6">
                <a href="<?= htmlspecialchars($returnUrl) ?>" class="text-slate-400 hover:text-white transition-colors">
                    <i class="fas fa-arrow-left text-xl"></i>
                </a>
                <h1 class="text-3xl font-bold text-white">Asignar Artículo</h1>
            </div>

            <?php if (isset($successMsg)): ?>
                <div class="status-banner success mb-6">
                    <?= htmlspecialchars($successMsg) ?>
                </div>
            <?php endif; ?>
            <?php if (isset($errorMsg)): ?>
                <div class="status-banner error mb-6">
                    <?= htmlspecialchars($errorMsg) ?>
                </div>
            <?php endif; ?>

            <div class="glass-card p-6">
                <form method="POST">
                    <div class="form-group mb-4">
                        <label class="block text-slate-300 mb-2">Empleado *</label>
                        <select name="employee_id" id="employee_id"
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg p-3 text-white focus:outline-none focus:border-cyan-500"
                            required>
                            <option value="">Buscar y seleccionar empleado...</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>" <?= (isset($employeeId) && $employeeId == $emp['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?> - 
                                    <?= htmlspecialchars($emp['employee_code']) ?> 
                                    <?= !empty($emp['department']) ? '(' . htmlspecialchars($emp['department']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-slate-500 text-xs mt-1 block">
                            <i class="fas fa-info-circle"></i> Escribe para buscar por nombre, código o departamento
                        </small>
                    </div>

                    <div class="form-group mb-4">
                        <label class="block text-slate-300 mb-2">Artículo / Equipo</label>
                        <select name="item_type_id"
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg p-3 text-white focus:outline-none focus:border-cyan-500"
                            required>
                            <option value="">Seleccionar Tipo de Artículo...</option>
                            <?php foreach ($itemTypes as $category => $items): ?>
                                <optgroup label="<?= htmlspecialchars($category) ?>">
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= $item['id'] ?>">
                                            <?= htmlspecialchars($item['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div class="form-group">
                            <label class="block text-slate-300 mb-2">Fecha Asignación</label>
                            <input type="date" name="assigned_date" value="<?= date('Y-m-d') ?>"
                                class="w-full bg-slate-800 border border-slate-700 rounded-lg p-3 text-white focus:outline-none focus:border-cyan-500"
                                required>
                        </div>
                        <div class="form-group">
                            <label class="block text-slate-300 mb-2">Código / Serial / Tag (Opcional)</label>
                            <input type="text" name="uuid" placeholder="Ej: LT-001, KEY-123"
                                class="w-full bg-slate-800 border border-slate-700 rounded-lg p-3 text-white focus:outline-none focus:border-cyan-500">
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="block text-slate-300 mb-2">Detalles / Descripción</label>
                        <input type="text" name="details" placeholder="Ej: Talla M, Color Azul, Marca Dell, Modelo X"
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg p-3 text-white focus:outline-none focus:border-cyan-500"
                            required>
                    </div>

                    <div class="form-group mb-6">
                        <label class="block text-slate-300 mb-2">Notas Adicionales</label>
                        <textarea name="notes" rows="3"
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg p-3 text-white focus:outline-none focus:border-cyan-500"></textarea>
                    </div>

                    <div class="flex justify-end gap-4">
                        <a href="<?= htmlspecialchars($returnUrl) ?>" class="btn-secondary">Cancelar</a>
                        <button type="submit" class="btn-primary px-8">Asignar Artículo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2 for employee selection
            $('#employee_id').select2({
                placeholder: 'Buscar y seleccionar empleado...',
                allowClear: true,
                width: '100%',
                language: {
                    noResults: function() {
                        return "No se encontraron empleados";
                    },
                    searching: function() {
                        return "Buscando...";
                    }
                }
            });
        });
    </script>
</body>

</html>
