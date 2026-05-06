<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

// Check permissions (using hr_employees for now as it's part of HR)
ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle Edit Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
    $inventoryId = (int) $_POST['inventory_id'];
    $details = trim($_POST['details']);
    $uuid = trim($_POST['uuid']) ?: null;
    $status = $_POST['status'];
    $notes = trim($_POST['notes']);

    try {
        $stmt = $pdo->prepare("
            UPDATE employee_inventory 
            SET details = ?, uuid = ?, status = ?, notes = CONCAT(COALESCE(notes, ''), '\n[Editado: ', NOW(), '] ', ?)
            WHERE id = ?
        ");
        $stmt->execute([$details, $uuid, $status, $notes, $inventoryId]);
        $successMsg = "Artículo actualizado correctamente.";

        if (function_exists('log_custom_action')) {
            log_custom_action(
                $pdo,
                $_SESSION['user_id'] ?? null,
                $_SESSION['full_name'] ?? 'Sistema',
                $_SESSION['role'] ?? 'system',
                'inventory',
                'update',
                "Inventario editado ID: $inventoryId",
                'employee_inventory',
                $inventoryId,
                ['details' => $details, 'status' => $status]
            );
        }
    } catch (Exception $e) {
        $errorMsg = "Error al actualizar artículo: " . $e->getMessage();
    }
}

// Handle delete item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $inventoryId = (int) $_POST['inventory_id'];

    try {
        $metaStmt = $pdo->prepare("
            SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                   it.name AS item_name
            FROM employee_inventory ei
            JOIN employees e ON e.id = ei.employee_id
            JOIN inventory_item_types it ON it.id = ei.item_type_id
            WHERE ei.id = ?
        ");
        $metaStmt->execute([$inventoryId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("DELETE FROM employee_inventory WHERE id = ?")->execute([$inventoryId]);
        $successMsg = "Artículo eliminado correctamente.";

        if ($meta && function_exists('log_custom_action')) {
            log_custom_action(
                $pdo,
                $_SESSION['user_id'] ?? null,
                $_SESSION['full_name'] ?? 'Sistema',
                $_SESSION['role'] ?? 'system',
                'inventory',
                'delete',
                "Inventario eliminado: {$meta['item_name']} de {$meta['employee_name']}",
                'employee_inventory',
                $inventoryId,
                []
            );
        }
    } catch (Exception $e) {
        $errorMsg = "Error al eliminar artículo: " . $e->getMessage();
    }
}

// Handle return item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_item'])) {
    $inventoryId = (int) $_POST['inventory_id'];
    $notes = trim($_POST['notes']);

    try {
        $stmt = $pdo->prepare("
            UPDATE employee_inventory 
            SET status = 'RETURNED', returned_date = CURDATE(), notes = CONCAT(COALESCE(notes, ''), '\n[Devuelto: ', NOW(), '] ', ?)
            WHERE id = ?
        ");
        $stmt->execute([$notes, $inventoryId]);
        $successMsg = "Artículo marcado como devuelto correctamente.";

        $metaStmt = $pdo->prepare("
            SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                   it.name AS item_name
            FROM employee_inventory ei
            JOIN employees e ON e.id = ei.employee_id
            JOIN inventory_item_types it ON it.id = ei.item_type_id
            WHERE ei.id = ?
        ");
        $metaStmt->execute([$inventoryId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC);

        if ($meta && function_exists('log_custom_action')) {
            $description = "Inventario devuelto: {$meta['item_name']} por {$meta['employee_name']}";
            log_custom_action(
                $pdo,
                $_SESSION['user_id'] ?? null,
                $_SESSION['full_name'] ?? 'Sistema',
                $_SESSION['role'] ?? 'system',
                'inventory',
                'return',
                $description,
                'employee_inventory',
                $inventoryId,
                ['notes' => $notes]
            );
        }
    } catch (Exception $e) {
        $errorMsg = "Error al devolver artículo: " . $e->getMessage();
    }
}

// Get filter parameters
$searchQuery = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'ASSIGNED';
$categoryFilter = $_GET['category'] ?? 'all';
$employeeIdFilter = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
$clearUrl = 'inventory.php';
if ($employeeIdFilter > 0) {
    $clearUrl .= '?employee_id=' . $employeeIdFilter;
}

// Build Query
$query = "
    SELECT ei.*, 
           CONCAT(e.first_name, ' ', e.last_name) as employee_name, e.employee_code,
           it.name as item_name, it.description as item_description,
           c.name as category_name
    FROM employee_inventory ei
    JOIN employees e ON e.id = ei.employee_id
    JOIN inventory_item_types it ON it.id = ei.item_type_id
    JOIN inventory_categories c ON c.id = it.category_id
    WHERE 1=1
";

$params = [];

if ($searchQuery) {
    $query .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ? OR it.name LIKE ? OR ei.details LIKE ? OR ei.uuid LIKE ?)";
    $term = "%$searchQuery%";
    $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
}

if ($statusFilter !== 'all') {
    $query .= " AND ei.status = ?";
    $params[] = $statusFilter;
}

if ($categoryFilter !== 'all') {
    $query .= " AND c.id = ?";
    $params[] = $categoryFilter;
}

if ($employeeIdFilter > 0) {
    $query .= " AND e.id = ?";
    $params[] = $employeeIdFilter;
}

$query .= " ORDER BY ei.assigned_date DESC";

// Pagination
$itemsPerPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $itemsPerPage;

// Count total records
$countQuery = "SELECT COUNT(*) FROM (" . $query . ") as total_count";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalItems = $countStmt->fetchColumn();
$totalPages = ceil($totalItems / $itemsPerPage);

// Add pagination to query
$query .= " LIMIT ? OFFSET ?";
$params[] = $itemsPerPage;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inventoryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Categories for filter
$categories = $pdo->query("SELECT * FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = [
    'assigned' => $pdo->query("SELECT COUNT(*) FROM employee_inventory WHERE status = 'ASSIGNED'")->fetchColumn(),
    'returned' => $pdo->query("SELECT COUNT(*) FROM employee_inventory WHERE status = 'RETURNED'")->fetchColumn(),
    'lost'     => $pdo->query("SELECT COUNT(*) FROM employee_inventory WHERE status = 'LOST'")->fetchColumn(),
    'damaged'  => $pdo->query("SELECT COUNT(*) FROM employee_inventory WHERE status = 'DAMAGED'")->fetchColumn(),
    'total'    => $pdo->query("SELECT COUNT(*) FROM employee_inventory")->fetchColumn(),
];

// Category icon map (Font Awesome) for visual hint per category
function inv_category_icon(string $name): string {
    $n = mb_strtolower($name);
    if (str_contains($n, 'tecn') || str_contains($n, 'tech') || str_contains($n, 'computo')) return 'fa-laptop';
    if (str_contains($n, 'unif') || str_contains($n, 'ropa') || str_contains($n, 'cami'))    return 'fa-shirt';
    if (str_contains($n, 'adm') || str_contains($n, 'oficin') || str_contains($n, 'office')) return 'fa-id-badge';
    if (str_contains($n, 'herr') || str_contains($n, 'tool'))                                return 'fa-screwdriver-wrench';
    if (str_contains($n, 'veh') || str_contains($n, 'auto'))                                 return 'fa-car';
    if (str_contains($n, 'segur') || str_contains($n, 'safe'))                               return 'fa-shield-halved';
    return 'fa-box';
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - HR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        /* Modal styles to ensure visibility */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.75);
            z-index: 50;
            backdrop-filter: blur(4px);
        }
        .modal-content {
            background-color: #0f172a !important;
            border: 1px solid #334155;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }
    </style>
</head>

<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-boxes text-blue-400 mr-3"></i>
                    Control de Inventario
                </h1>
                <p class="text-slate-400">Gestión de activos y entregables a empleados</p>
            </div>
            <div class="flex gap-3">
                <a href="inventory_assign.php" class="btn-primary">
                    <i class="fas fa-plus-circle"></i>
                    Asignar Artículo
                </a>
                <a href="inventory_manage.php" class="btn-secondary">
                    <i class="fas fa-cog"></i>
                    Gestionar Items
                </a>
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver a HR
                </a>
            </div>
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

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <a href="?status=ASSIGNED<?= $employeeIdFilter > 0 ? '&employee_id=' . $employeeIdFilter : '' ?>"
               class="glass-card hover:ring-2 hover:ring-blue-500/40 transition-all">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-xs mb-1 uppercase tracking-wide">Asignados</p>
                        <h3 class="text-2xl font-bold text-white"><?= $stats['assigned'] ?></h3>
                    </div>
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-blue-500/20 text-blue-400">
                        <i class="fas fa-box-open"></i>
                    </div>
                </div>
            </a>
            <a href="?status=RETURNED<?= $employeeIdFilter > 0 ? '&employee_id=' . $employeeIdFilter : '' ?>"
               class="glass-card hover:ring-2 hover:ring-green-500/40 transition-all">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-xs mb-1 uppercase tracking-wide">Devueltos</p>
                        <h3 class="text-2xl font-bold text-white"><?= $stats['returned'] ?></h3>
                    </div>
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-green-500/20 text-green-400">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </a>
            <a href="?status=LOST<?= $employeeIdFilter > 0 ? '&employee_id=' . $employeeIdFilter : '' ?>"
               class="glass-card hover:ring-2 hover:ring-red-500/40 transition-all">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-xs mb-1 uppercase tracking-wide">Perdidos</p>
                        <h3 class="text-2xl font-bold text-white"><?= $stats['lost'] ?></h3>
                    </div>
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-red-500/20 text-red-400">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                </div>
            </a>
            <a href="?status=DAMAGED<?= $employeeIdFilter > 0 ? '&employee_id=' . $employeeIdFilter : '' ?>"
               class="glass-card hover:ring-2 hover:ring-yellow-500/40 transition-all">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-xs mb-1 uppercase tracking-wide">Dañados</p>
                        <h3 class="text-2xl font-bold text-white"><?= $stats['damaged'] ?></h3>
                    </div>
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-yellow-500/20 text-yellow-400">
                        <i class="fas fa-wrench"></i>
                    </div>
                </div>
            </a>
            <a href="?status=all<?= $employeeIdFilter > 0 ? '&employee_id=' . $employeeIdFilter : '' ?>"
               class="glass-card hover:ring-2 hover:ring-purple-500/40 transition-all">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-xs mb-1 uppercase tracking-wide">Total</p>
                        <h3 class="text-2xl font-bold text-white"><?= $stats['total'] ?></h3>
                    </div>
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-purple-500/20 text-purple-400">
                        <i class="fas fa-list"></i>
                    </div>
                </div>
            </a>
        </div>

        <!-- Filters -->
        <div class="glass-card mb-6">
            <form method="GET" class="flex flex-wrap gap-4 items-end">
                <?php if ($employeeIdFilter > 0): ?>
                    <input type="hidden" name="employee_id" value="<?= $employeeIdFilter ?>">
                <?php endif; ?>
                <div class="form-group flex-1 min-w-[200px]">
                    <label>Buscar</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>"
                        placeholder="Empleado, código, item, serial...">
                </div>
                <div class="form-group flex-1 min-w-[150px]">
                    <label>Estado</label>
                    <select name="status">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="ASSIGNED" <?= $statusFilter === 'ASSIGNED' ? 'selected' : '' ?>>Asignados</option>
                        <option value="RETURNED" <?= $statusFilter === 'RETURNED' ? 'selected' : '' ?>>Devueltos</option>
                        <option value="LOST" <?= $statusFilter === 'LOST' ? 'selected' : '' ?>>Perdidos</option>
                        <option value="DAMAGED" <?= $statusFilter === 'DAMAGED' ? 'selected' : '' ?>>Dañados</option>
                    </select>
                </div>
                <div class="form-group flex-1 min-w-[150px]">
                    <label>Categoría</label>
                    <select name="category">
                        <option value="all">Todas</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-search"></i> Filtrar
                </button>
                <a href="<?= htmlspecialchars($clearUrl) ?>" class="btn-secondary">Limpiar</a>
            </form>
        </div>

        <!-- List -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="text-left border-b border-slate-700 text-slate-400">
                            <th class="p-4">Empleado</th>
                            <th class="p-4">Artículo</th>
                            <th class="p-4">Categoría</th>
                            <th class="p-4">Detalles / Serial</th>
                            <th class="p-4">Fecha</th>
                            <th class="p-4">Estado</th>
                            <th class="p-4 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <?php if (empty($inventoryItems)): ?>
                            <tr>
                                <td colspan="7" class="p-8 text-center text-slate-500">
                                    No se encontraron registros.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventoryItems as $item): ?>
                                <?php
                                    $catIcon = inv_category_icon($item['category_name']);
                                    $statusColors = [
                                        'ASSIGNED' => 'bg-blue-500/20 text-blue-400 border border-blue-500/30',
                                        'RETURNED' => 'bg-green-500/20 text-green-400 border border-green-500/30',
                                        'LOST'     => 'bg-red-500/20 text-red-400 border border-red-500/30',
                                        'DAMAGED'  => 'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30',
                                    ];
                                    $statusClass = $statusColors[$item['status']] ?? 'bg-slate-500/20 text-slate-400';
                                    $statusLabel = [
                                        'ASSIGNED' => 'Asignado',
                                        'RETURNED' => 'Devuelto',
                                        'LOST'     => 'Perdido',
                                        'DAMAGED'  => 'Dañado',
                                    ][$item['status']] ?? $item['status'];
                                ?>
                                <tr class="hover:bg-slate-800/40 transition-colors">
                                    <td class="p-4">
                                        <div class="font-medium text-white">
                                            <?= htmlspecialchars($item['employee_name']) ?>
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            <?= htmlspecialchars($item['employee_code']) ?>
                                        </div>
                                    </td>
                                    <td class="p-4">
                                        <div class="font-medium text-cyan-300">
                                            <?= htmlspecialchars($item['item_name']) ?>
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            <?= htmlspecialchars($item['item_description'] ?? '') ?>
                                        </div>
                                    </td>
                                    <td class="p-4">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-1 text-xs rounded-full bg-slate-700 text-slate-300">
                                            <i class="fas <?= $catIcon ?> text-slate-400"></i>
                                            <?= htmlspecialchars($item['category_name']) ?>
                                        </span>
                                    </td>
                                    <td class="p-4">
                                        <div class="text-sm text-slate-300">
                                            <?= htmlspecialchars($item['details'] ?? '—') ?>
                                        </div>
                                        <?php if (!empty($item['uuid'])): ?>
                                            <div class="text-xs text-slate-500 font-mono mt-1">
                                                <i class="fas fa-barcode mr-1"></i>
                                                <?= htmlspecialchars($item['uuid']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4 text-slate-300">
                                        <div><?= date('d/m/Y', strtotime($item['assigned_date'])) ?></div>
                                        <?php if (!empty($item['returned_date'])): ?>
                                            <div class="text-xs text-green-400">
                                                <i class="fas fa-undo-alt mr-1"></i>
                                                <?= date('d/m/Y', strtotime($item['returned_date'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4">
                                        <span class="px-2 py-1 text-xs rounded-full <?= $statusClass ?>">
                                            <?= $statusLabel ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-right">
                                        <div class="flex gap-2 justify-end">
                                            <button type="button"
                                                data-action="edit"
                                                data-id="<?= (int) $item['id'] ?>"
                                                data-employee="<?= htmlspecialchars($item['employee_name'], ENT_QUOTES) ?>"
                                                data-employee-code="<?= htmlspecialchars($item['employee_code'], ENT_QUOTES) ?>"
                                                data-item-name="<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>"
                                                data-details="<?= htmlspecialchars($item['details'] ?? '', ENT_QUOTES) ?>"
                                                data-uuid="<?= htmlspecialchars($item['uuid'] ?? '', ENT_QUOTES) ?>"
                                                data-status="<?= htmlspecialchars($item['status'], ENT_QUOTES) ?>"
                                                class="action-btn text-blue-400 hover:text-blue-300 hover:bg-blue-500/10 rounded-md p-2 transition-colors"
                                                title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($item['status'] === 'ASSIGNED'): ?>
                                                <button type="button"
                                                    data-action="return"
                                                    data-id="<?= (int) $item['id'] ?>"
                                                    data-item-name="<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>"
                                                    class="action-btn text-green-400 hover:text-green-300 hover:bg-green-500/10 rounded-md p-2 transition-colors"
                                                    title="Marcar Devuelto">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button"
                                                data-action="delete"
                                                data-id="<?= (int) $item['id'] ?>"
                                                data-item-name="<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>"
                                                data-employee="<?= htmlspecialchars($item['employee_name'], ENT_QUOTES) ?>"
                                                class="action-btn text-red-400 hover:text-red-300 hover:bg-red-500/10 rounded-md p-2 transition-colors"
                                                title="Eliminar registro">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="flex justify-center items-center gap-2 p-4 border-t border-slate-700">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
                            class="px-3 py-2 bg-slate-800 text-slate-300 rounded hover:bg-slate-700 transition-colors">
                            <i class="fas fa-chevron-left"></i> Anterior
                        </a>
                    <?php endif; ?>

                    <div class="flex gap-1">
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($startPage > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>"
                                class="px-3 py-2 bg-slate-800 text-slate-300 rounded hover:bg-slate-700 transition-colors">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="px-3 py-2 text-slate-500">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                                class="px-3 py-2 rounded transition-colors <?= $i === $page ? 'bg-cyan-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="px-3 py-2 text-slate-500">...</span>
                            <?php endif; ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>"
                                class="px-3 py-2 bg-slate-800 text-slate-300 rounded hover:bg-slate-700 transition-colors"><?= $totalPages ?></a>
                        <?php endif; ?>
                    </div>

                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
                            class="px-3 py-2 bg-slate-800 text-slate-300 rounded hover:bg-slate-700 transition-colors">
                            Siguiente <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>

                    <span class="ml-4 text-slate-400 text-sm">
                        Página <?= $page ?> de <?= $totalPages ?> (<?= $totalItems ?> registros)
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal"
        class="modal-backdrop hidden items-center justify-center"
        style="display: none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-2xl relative max-h-[90vh] overflow-y-auto">
            <button type="button" data-action="close-modal" data-target="edit"
                class="absolute top-4 right-4 text-slate-400 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>

            <h3 class="text-xl font-bold text-white mb-4"><i class="fas fa-edit mr-2"></i>Editar Artículo</h3>

            <form method="POST">
                <input type="hidden" name="edit_item" value="1">
                <input type="hidden" name="inventory_id" id="edit_inventory_id">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="text-slate-300">Empleado</label>
                        <input type="text" id="edit_employee_name" readonly
                            class="w-full bg-slate-700 border-slate-600 rounded text-slate-400 cursor-not-allowed">
                    </div>
                    <div class="form-group">
                        <label class="text-slate-300">Artículo</label>
                        <input type="text" id="edit_item_name" readonly
                            class="w-full bg-slate-700 border-slate-600 rounded text-slate-400 cursor-not-allowed">
                    </div>
                </div>

                <div class="form-group mb-4">
                    <label class="text-slate-300">Detalles / Descripción *</label>
                    <input type="text" name="details" id="edit_details" required
                        class="w-full bg-slate-800 border-slate-700 rounded text-white">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="text-slate-300">Código / Serial / Tag</label>
                        <input type="text" name="uuid" id="edit_uuid"
                            class="w-full bg-slate-800 border-slate-700 rounded text-white">
                    </div>
                    <div class="form-group">
                        <label class="text-slate-300">Estado *</label>
                        <select name="status" id="edit_status" required
                            class="w-full bg-slate-800 border-slate-700 rounded text-white">
                            <option value="ASSIGNED">Asignado</option>
                            <option value="RETURNED">Devuelto</option>
                            <option value="LOST">Perdido</option>
                            <option value="DAMAGED">Dañado</option>
                        </select>
                    </div>
                </div>

                <div class="form-group mb-6">
                    <label class="text-slate-300">Notas de edición</label>
                    <textarea name="notes" class="w-full bg-slate-800 border-slate-700 rounded text-white"
                        rows="2" placeholder="Describe los cambios realizados..."></textarea>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" data-action="close-modal" data-target="edit" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i>Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Return Modal -->
    <div id="returnModal"
        class="modal-backdrop hidden items-center justify-center"
        style="display: none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-md relative">
            <button type="button" data-action="close-modal" data-target="return"
                class="absolute top-4 right-4 text-slate-400 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>

            <h3 class="text-xl font-bold text-white mb-4">
                <i class="fas fa-undo text-green-400 mr-2"></i>Confirmar Devolución
            </h3>

            <form method="POST">
                <input type="hidden" name="return_item" value="1">
                <input type="hidden" name="inventory_id" id="return_inventory_id">

                <p class="text-slate-300 mb-4">
                    ¿Estás seguro de que deseas marcar <strong id="return_item_name" class="text-cyan-300"></strong>
                    como devuelto?
                </p>

                <div class="form-group mb-6">
                    <label>Notas de devolución (opcional)</label>
                    <textarea name="notes" class="w-full bg-slate-800 border-slate-700 rounded text-slate-300"
                        rows="2"></textarea>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" data-action="close-modal" data-target="return" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary bg-green-600 hover:bg-green-700">
                        <i class="fas fa-check mr-2"></i>Confirmar Devolución
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal"
        class="modal-backdrop hidden items-center justify-center"
        style="display: none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-md relative">
            <button type="button" data-action="close-modal" data-target="delete"
                class="absolute top-4 right-4 text-slate-400 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>

            <h3 class="text-xl font-bold text-white mb-4">
                <i class="fas fa-trash text-red-400 mr-2"></i>Eliminar Registro
            </h3>

            <form method="POST">
                <input type="hidden" name="delete_item" value="1">
                <input type="hidden" name="inventory_id" id="delete_inventory_id">

                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-4">
                    <p class="text-slate-300 mb-1">
                        ¿Estás seguro de que deseas <strong class="text-red-400">eliminar permanentemente</strong> el registro de:
                    </p>
                    <p class="text-white font-semibold mt-2">
                        <span id="delete_item_name" class="text-cyan-300"></span>
                        <span class="text-slate-400 text-sm"> — </span>
                        <span id="delete_employee_name" class="text-slate-200"></span>
                    </p>
                    <p class="text-red-400 text-xs mt-2"><i class="fas fa-exclamation-triangle mr-1"></i>Esta acción no se puede deshacer.</p>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" data-action="close-modal" data-target="delete" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary" style="background-color: #dc2626;">
                        <i class="fas fa-trash mr-2"></i>Eliminar Permanentemente
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            'use strict';

            const modals = {
                edit:   document.getElementById('editModal'),
                return: document.getElementById('returnModal'),
                delete: document.getElementById('deleteModal'),
            };

            function openModal(name) {
                const modal = modals[name];
                if (!modal) return;
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                const firstInput = modal.querySelector('input:not([type=hidden]), textarea, select');
                if (firstInput) setTimeout(() => firstInput.focus(), 50);
            }

            function closeModal(name) {
                const modal = modals[name];
                if (!modal) return;
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }

            function closeAllModals() {
                Object.keys(modals).forEach(closeModal);
            }

            // Open via data-action
            document.addEventListener('click', function (e) {
                const trigger = e.target.closest('[data-action]');
                if (!trigger) return;
                const action = trigger.dataset.action;
                const id = trigger.dataset.id;

                if (action === 'edit') {
                    document.getElementById('edit_inventory_id').value = id;
                    document.getElementById('edit_employee_name').value =
                        trigger.dataset.employee + ' (' + trigger.dataset.employeeCode + ')';
                    document.getElementById('edit_item_name').value = trigger.dataset.itemName;
                    document.getElementById('edit_details').value = trigger.dataset.details || '';
                    document.getElementById('edit_uuid').value = trigger.dataset.uuid || '';
                    document.getElementById('edit_status').value = trigger.dataset.status;
                    openModal('edit');
                } else if (action === 'return') {
                    document.getElementById('return_inventory_id').value = id;
                    document.getElementById('return_item_name').textContent = trigger.dataset.itemName;
                    openModal('return');
                } else if (action === 'delete') {
                    document.getElementById('delete_inventory_id').value = id;
                    document.getElementById('delete_item_name').textContent = trigger.dataset.itemName;
                    document.getElementById('delete_employee_name').textContent = trigger.dataset.employee;
                    openModal('delete');
                } else if (action === 'close-modal') {
                    const target = trigger.dataset.target;
                    if (target) closeModal(target);
                }
            });

            // Click-outside to close
            Object.entries(modals).forEach(([name, modal]) => {
                if (!modal) return;
                modal.addEventListener('click', function (e) {
                    if (e.target === this) closeModal(name);
                });
            });

            // ESC to close
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeAllModals();
            });

            // Auto-dismiss success banner after 5s
            const successBanner = document.querySelector('.status-banner.success');
            if (successBanner) {
                setTimeout(() => {
                    successBanner.style.transition = 'opacity .4s ease';
                    successBanner.style.opacity = '0';
                    setTimeout(() => successBanner.remove(), 400);
                }, 5000);
            }
        })();
    </script>
</body>

</html>
