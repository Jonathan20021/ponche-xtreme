<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$successMsg = null;
$errorMsg   = null;

function inv_log(PDO $pdo, string $action, string $description, ?int $entityId = null, array $meta = []): void {
    if (!function_exists('log_custom_action')) return;
    log_custom_action(
        $pdo,
        $_SESSION['user_id'] ?? null,
        $_SESSION['full_name'] ?? 'Sistema',
        $_SESSION['role'] ?? 'system',
        'inventory',
        $action,
        $description,
        'inventory_item_types',
        $entityId,
        $meta
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add_item': {
                $name         = trim($_POST['name'] ?? '');
                $categoryId   = (int) ($_POST['category_id'] ?? 0);
                $description  = trim($_POST['description'] ?? '');
                $unit         = trim($_POST['unit'] ?? 'unidad') ?: 'unidad';
                $isConsumable = isset($_POST['is_consumable']) ? 1 : 0;
                $trackLots    = isset($_POST['track_lots']) ? 1 : 0;
                $minStock     = (float) ($_POST['min_stock'] ?? 0);
                $maxStock     = $_POST['max_stock']    !== '' ? (float) $_POST['max_stock']    : null;
                $reorderQty   = $_POST['reorder_qty']  !== '' ? (float) $_POST['reorder_qty']  : null;
                $unitCost     = $_POST['unit_cost']    !== '' ? (float) $_POST['unit_cost']    : null;
                $sku          = trim($_POST['sku'] ?? '') ?: null;
                if ($name === '' || $categoryId <= 0) {
                    throw new RuntimeException('Nombre y categoría son obligatorios.');
                }
                $stmt = $pdo->prepare("INSERT INTO inventory_item_types
                    (name, category_id, description, sku, unit, is_consumable, track_lots,
                     min_stock, max_stock, reorder_qty, unit_cost)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $categoryId, $description, $sku, $unit,
                                $isConsumable, $trackLots, $minStock, $maxStock, $reorderQty, $unitCost]);
                $successMsg = "Tipo de articulo agregado exitosamente.";
                inv_log($pdo, 'create', "Item creado: $name", (int)$pdo->lastInsertId(), compact('name', 'categoryId'));
                break;
            }

            case 'edit_item': {
                $id           = (int) ($_POST['item_id'] ?? 0);
                $name         = trim($_POST['name'] ?? '');
                $categoryId   = (int) ($_POST['category_id'] ?? 0);
                $description  = trim($_POST['description'] ?? '');
                $unit         = trim($_POST['unit'] ?? 'unidad') ?: 'unidad';
                $isConsumable = isset($_POST['is_consumable']) ? 1 : 0;
                $trackLots    = isset($_POST['track_lots']) ? 1 : 0;
                $minStock     = (float) ($_POST['min_stock'] ?? 0);
                $maxStock     = ($_POST['max_stock'] ?? '')    !== '' ? (float) $_POST['max_stock']    : null;
                $reorderQty   = ($_POST['reorder_qty'] ?? '')  !== '' ? (float) $_POST['reorder_qty']  : null;
                $unitCost     = ($_POST['unit_cost'] ?? '')    !== '' ? (float) $_POST['unit_cost']    : null;
                $sku          = trim($_POST['sku'] ?? '') ?: null;
                if ($id <= 0 || $name === '' || $categoryId <= 0) {
                    throw new RuntimeException('Datos incompletos para editar el item.');
                }
                $stmt = $pdo->prepare("UPDATE inventory_item_types
                    SET name = ?, category_id = ?, description = ?, sku = ?, unit = ?,
                        is_consumable = ?, track_lots = ?, min_stock = ?, max_stock = ?,
                        reorder_qty = ?, unit_cost = ?
                    WHERE id = ?");
                $stmt->execute([$name, $categoryId, $description, $sku, $unit,
                                $isConsumable, $trackLots, $minStock, $maxStock,
                                $reorderQty, $unitCost, $id]);
                $successMsg = "Item actualizado correctamente.";
                inv_log($pdo, 'update', "Item editado: $name", $id, compact('name', 'categoryId'));
                break;
            }

            case 'delete_item': {
                $id = (int) ($_POST['item_id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('Id inválido.');
                $usage = (int) $pdo->query("SELECT COUNT(*) FROM employee_inventory WHERE item_type_id = $id")->fetchColumn();
                if ($usage > 0) {
                    throw new RuntimeException("No se puede eliminar: el item tiene $usage asignación(es). Elimina o reasigna primero las asignaciones.");
                }
                $name = $pdo->query("SELECT name FROM inventory_item_types WHERE id = $id")->fetchColumn();
                $pdo->prepare("DELETE FROM inventory_item_types WHERE id = ?")->execute([$id]);
                $successMsg = "Item eliminado correctamente.";
                inv_log($pdo, 'delete', "Item eliminado: $name", $id, []);
                break;
            }

            case 'add_category': {
                $name = trim($_POST['name'] ?? '');
                if ($name === '') throw new RuntimeException('El nombre de la categoría es obligatorio.');
                $stmt = $pdo->prepare("INSERT INTO inventory_categories (name) VALUES (?)");
                $stmt->execute([$name]);
                $successMsg = "Categoría agregada exitosamente.";
                inv_log($pdo, 'create', "Categoría creada: $name", (int)$pdo->lastInsertId(), compact('name'));
                break;
            }

            case 'edit_category': {
                $id   = (int) ($_POST['category_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                if ($id <= 0 || $name === '') throw new RuntimeException('Datos incompletos para editar la categoría.');
                $stmt = $pdo->prepare("UPDATE inventory_categories SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
                $successMsg = "Categoría actualizada correctamente.";
                inv_log($pdo, 'update', "Categoría editada: $name", $id, compact('name'));
                break;
            }

            case 'delete_category': {
                $id = (int) ($_POST['category_id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('Id inválido.');
                $usage = (int) $pdo->query("SELECT COUNT(*) FROM inventory_item_types WHERE category_id = $id")->fetchColumn();
                if ($usage > 0) {
                    throw new RuntimeException("No se puede eliminar: la categoría tiene $usage item(s). Mueve o elimina primero los items.");
                }
                $name = $pdo->query("SELECT name FROM inventory_categories WHERE id = $id")->fetchColumn();
                $pdo->prepare("DELETE FROM inventory_categories WHERE id = ?")->execute([$id]);
                $successMsg = "Categoría eliminada correctamente.";
                inv_log($pdo, 'delete', "Categoría eliminada: $name", $id, []);
                break;
            }
        }
    } catch (Throwable $e) {
        $errorMsg = "Error: " . $e->getMessage();
    }
}

// Get Data with usage counts
$categories = $pdo->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM inventory_item_types it WHERE it.category_id = c.id) AS items_count
    FROM inventory_categories c
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

$items = $pdo->query("
    SELECT it.*, c.name AS category_name,
           (SELECT COUNT(*) FROM employee_inventory ei WHERE ei.item_type_id = it.id) AS assignments_count,
           (SELECT COUNT(*) FROM employee_inventory ei WHERE ei.item_type_id = it.id AND ei.status = 'ASSIGNED') AS active_count
    FROM inventory_item_types it
    JOIN inventory_categories c ON c.id = it.category_id
    ORDER BY c.name, it.name
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Items - HR</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.75);
            z-index: 50;
            backdrop-filter: blur(4px);
        }
        .modal-content {
            background-color: var(--surface) !important;
            border: 1px solid #334155;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }
        .action-btn { padding: .35rem .55rem; border-radius: .375rem; transition: background-color .15s ease; }
    </style>
</head>

<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex items-center gap-4 mb-6">
            <a href="inventory.php" class="text-slate-400 hover:text-white transition-colors" title="Volver">
                <i class="fas fa-arrow-left text-xl"></i>
            </a>
            <div>
                <h1 class="text-3xl font-bold text-white">Configuración de Inventario</h1>
                <p class="text-slate-400 text-sm">Categorías y tipos de artículo disponibles para asignar.</p>
            </div>
        </div>

        <?php if ($successMsg): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="status-banner error mb-6"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Items Column -->
            <div>
                <div class="glass-card mb-6">
                    <h3 class="text-xl font-bold text-white mb-4">
                        <i class="fas fa-plus-circle text-cyan-400 mr-2"></i>Agregar Nuevo Item
                    </h3>
                    <?php if (empty($categories)): ?>
                        <p class="text-yellow-300 text-sm">
                            <i class="fas fa-info-circle mr-1"></i>
                            Crea una categoría primero antes de agregar items.
                        </p>
                    <?php else: ?>
                        <form method="POST" id="addItemForm">
                            <input type="hidden" name="action" value="add_item">
                            <div class="form-group mb-3">
                                <label class="text-slate-300 flex items-center justify-between">
                                    <span>Nombre del Item *</span>
                                    <button type="button" id="aiSuggestBtn" class="text-xs px-2 py-1 rounded-full" style="background:linear-gradient(135deg,#5e7cba,#1f3f76);color:white;">
                                        <i class="fas fa-magic-wand-sparkles mr-1"></i> Sugerir con IA
                                    </button>
                                </label>
                                <input type="text" name="name" id="addItemName" required
                                    placeholder="Ej: Acetaminofen 500mg" autocomplete="off"
                                    class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                                <p id="aiHint" class="text-xs text-purple-300 hidden mt-1"><i class="fas fa-sparkles mr-1"></i>Claude completara categoria, unidad y stock minimo basandose en el nombre.</p>
                            </div>
                            <div class="grid grid-cols-2 gap-3 mb-3">
                                <div class="form-group">
                                    <label class="text-slate-300">Categoria *</label>
                                    <select name="category_id" id="addItemCategory" required class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="text-slate-300">Unidad</label>
                                    <input type="text" name="unit" id="addItemUnit" value="unidad" placeholder="unidad, caja, litro..." class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                                </div>
                            </div>
                            <div class="form-group mb-3">
                                <label class="text-slate-300">Descripcion</label>
                                <input type="text" name="description" id="addItemDesc" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                            </div>
                            <div class="grid grid-cols-2 gap-3 mb-3">
                                <div class="form-group">
                                    <label class="text-slate-300">SKU</label>
                                    <input type="text" name="sku" placeholder="Opcional" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                                </div>
                                <div class="form-group">
                                    <label class="text-slate-300">Costo unitario ($)</label>
                                    <input type="number" step="0.01" min="0" name="unit_cost" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-3 mb-3">
                                <div class="form-group">
                                    <label class="text-slate-300">Min</label>
                                    <input type="number" step="0.01" min="0" name="min_stock" id="addItemMin" value="0" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                                </div>
                                <div class="form-group">
                                    <label class="text-slate-300">Max</label>
                                    <input type="number" step="0.01" min="0" name="max_stock" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                                </div>
                                <div class="form-group">
                                    <label class="text-slate-300">Reorden</label>
                                    <input type="number" step="0.01" min="0" name="reorder_qty" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-3 mb-4 text-sm text-slate-300">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="is_consumable" id="addItemConsum" value="1" checked class="rounded">
                                    <span>Consumible (papel, medicina, alimento)</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="track_lots" id="addItemLots" value="1" class="rounded">
                                    <span>Rastrear lotes / vencimiento</span>
                                </label>
                            </div>
                            <button type="submit" class="btn-primary w-full"><i class="fas fa-plus mr-2"></i>Agregar Item</button>
                        </form>

                        <script>
                        document.getElementById('aiSuggestBtn').addEventListener('click', async () => {
                            const name = document.getElementById('addItemName').value.trim();
                            if (!name) { alert('Escribe primero el nombre del item.'); return; }
                            const btn = document.getElementById('aiSuggestBtn');
                            const hint = document.getElementById('aiHint');
                            const orig = btn.innerHTML;
                            btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1"></i> Pensando...';
                            try {
                                const fd = new FormData();
                                fd.append('action', 'categorize');
                                fd.append('name', name);
                                const r = await fetch('../api/inventory_ai.php', { method: 'POST', body: fd });
                                const j = await r.json();
                                if (!j.success || !j.suggestion) {
                                    hint.textContent = '⚠ ' + (j.error || 'No se pudo obtener sugerencia');
                                    hint.classList.remove('hidden');
                                    hint.classList.remove('text-purple-300');
                                    hint.classList.add('text-yellow-300');
                                    return;
                                }
                                const s = j.suggestion;
                                document.getElementById('addItemCategory').value = s.category_id;
                                if (s.unit)        document.getElementById('addItemUnit').value = s.unit;
                                if (s.description) document.getElementById('addItemDesc').value = s.description;
                                if (s.min_stock)   document.getElementById('addItemMin').value = s.min_stock;
                                document.getElementById('addItemConsum').checked = !!s.is_consumable;
                                document.getElementById('addItemLots').checked   = !!s.track_lots;
                                hint.innerHTML = '<i class="fas fa-check mr-1"></i> Sugerencia aplicada. Revisa antes de guardar.';
                                hint.classList.remove('hidden');
                                hint.classList.remove('text-yellow-300');
                                hint.classList.add('text-purple-300');
                            } catch (e) {
                                hint.textContent = 'Error: ' + e.message;
                                hint.classList.remove('hidden');
                            } finally {
                                btn.disabled = false; btn.innerHTML = orig;
                            }
                        });
                        </script>
                    <?php endif; ?>
                </div>

                <div class="glass-card">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-white">
                            <i class="fas fa-boxes text-cyan-400 mr-2"></i>Items Existentes
                        </h3>
                        <span class="text-xs px-2 py-1 rounded-full bg-slate-700 text-slate-300">
                            <?= count($items) ?> items
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-slate-400 border-b border-slate-700">
                                <tr>
                                    <th class="py-2">Item</th>
                                    <th class="py-2">Categoría</th>
                                    <th class="py-2 text-center">Uso</th>
                                    <th class="py-2 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                <?php if (empty($items)): ?>
                                    <tr><td colspan="4" class="py-6 text-center text-slate-500">No hay items registrados.</td></tr>
                                <?php else: foreach ($items as $item): ?>
                                    <tr class="hover:bg-slate-800/40 transition-colors">
                                        <td class="py-3 pr-2">
                                            <div class="text-white font-medium"><?= htmlspecialchars($item['name']) ?></div>
                                            <?php if (!empty($item['description'])): ?>
                                                <div class="text-xs text-slate-500"><?= htmlspecialchars($item['description']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 text-slate-400">
                                            <?= htmlspecialchars($item['category_name']) ?>
                                        </td>
                                        <td class="py-3 text-center">
                                            <?php if ($item['assignments_count'] > 0): ?>
                                                <span class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-blue-500/20 text-blue-300"
                                                      title="Activas: <?= $item['active_count'] ?> / Total históricas: <?= $item['assignments_count'] ?>">
                                                    <?= $item['active_count'] ?>/<?= $item['assignments_count'] ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-600">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 text-right">
                                            <button type="button"
                                                data-action="edit-item"
                                                data-id="<?= (int) $item['id'] ?>"
                                                data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>"
                                                data-description="<?= htmlspecialchars($item['description'] ?? '', ENT_QUOTES) ?>"
                                                data-category-id="<?= (int) $item['category_id'] ?>"
                                                data-sku="<?= htmlspecialchars($item['sku'] ?? '', ENT_QUOTES) ?>"
                                                data-unit="<?= htmlspecialchars($item['unit'] ?? 'unidad', ENT_QUOTES) ?>"
                                                data-is-consumable="<?= (int) ($item['is_consumable'] ?? 1) ?>"
                                                data-track-lots="<?= (int) ($item['track_lots'] ?? 0) ?>"
                                                data-min-stock="<?= htmlspecialchars((string) ($item['min_stock'] ?? 0), ENT_QUOTES) ?>"
                                                data-max-stock="<?= htmlspecialchars((string) ($item['max_stock'] ?? ''), ENT_QUOTES) ?>"
                                                data-reorder-qty="<?= htmlspecialchars((string) ($item['reorder_qty'] ?? ''), ENT_QUOTES) ?>"
                                                data-unit-cost="<?= htmlspecialchars((string) ($item['unit_cost'] ?? ''), ENT_QUOTES) ?>"
                                                class="action-btn text-blue-400 hover:bg-blue-500/10"
                                                title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button"
                                                data-action="delete-item"
                                                data-id="<?= (int) $item['id'] ?>"
                                                data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>"
                                                data-usage="<?= (int) $item['assignments_count'] ?>"
                                                class="action-btn text-red-400 hover:bg-red-500/10"
                                                title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Categories Column -->
            <div>
                <div class="glass-card mb-6">
                    <h3 class="text-xl font-bold text-white mb-4">
                        <i class="fas fa-folder-plus text-purple-400 mr-2"></i>Agregar Categoría
                    </h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_category">
                        <div class="form-group mb-4">
                            <label class="text-slate-300">Nombre Categoría *</label>
                            <input type="text" name="name" required
                                placeholder="Ej: Tecnología, Uniforme, Seguridad"
                                class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                        </div>
                        <button type="submit" class="btn-secondary w-full">
                            <i class="fas fa-plus mr-2"></i>Agregar Categoría
                        </button>
                    </form>
                </div>

                <div class="glass-card">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-white">
                            <i class="fas fa-folder text-purple-400 mr-2"></i>Categorías
                        </h3>
                        <span class="text-xs px-2 py-1 rounded-full bg-slate-700 text-slate-300">
                            <?= count($categories) ?>
                        </span>
                    </div>
                    <ul class="space-y-2">
                        <?php if (empty($categories)): ?>
                            <li class="p-4 text-center text-slate-500">No hay categorías.</li>
                        <?php else: foreach ($categories as $cat): ?>
                            <li class="flex items-center justify-between p-3 bg-slate-800/50 rounded-lg hover:bg-slate-800/80 transition-colors">
                                <div class="flex-1">
                                    <span class="text-white font-medium"><?= htmlspecialchars($cat['name']) ?></span>
                                    <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-slate-700 text-slate-300">
                                        <?= (int)$cat['items_count'] ?> item<?= $cat['items_count'] == 1 ? '' : 's' ?>
                                    </span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <span class="text-xs text-slate-500 mr-2">
                                        <?= date('d/M Y', strtotime($cat['created_at'])) ?>
                                    </span>
                                    <button type="button"
                                        data-action="edit-category"
                                        data-id="<?= (int)$cat['id'] ?>"
                                        data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>"
                                        class="action-btn text-blue-400 hover:bg-blue-500/10"
                                        title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button"
                                        data-action="delete-category"
                                        data-id="<?= (int)$cat['id'] ?>"
                                        data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>"
                                        data-usage="<?= (int)$cat['items_count'] ?>"
                                        class="action-btn text-red-400 hover:bg-red-500/10"
                                        title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Item Modal -->
    <div id="editItemModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-xl relative max-h-[90vh] overflow-y-auto">
            <button type="button" data-action="close-modal" data-target="editItemModal"
                class="absolute top-4 right-4 text-slate-400 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
            <h3 class="text-xl font-bold text-white mb-4"><i class="fas fa-edit mr-2"></i>Editar Item</h3>
            <form method="POST">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="item_id" id="edit_item_id">
                <div class="form-group mb-3">
                    <label class="text-slate-300">Nombre *</label>
                    <input type="text" name="name" id="edit_item_name_input" required class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                </div>
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="text-slate-300">Categoria *</label>
                        <select name="category_id" id="edit_item_category" required class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="text-slate-300">Unidad</label>
                        <input type="text" name="unit" id="edit_item_unit" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label class="text-slate-300">Descripcion</label>
                    <input type="text" name="description" id="edit_item_description" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                </div>
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group">
                        <label class="text-slate-300">SKU</label>
                        <input type="text" name="sku" id="edit_item_sku" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label class="text-slate-300">Costo unitario ($)</label>
                        <input type="number" step="0.01" min="0" name="unit_cost" id="edit_item_cost" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3 mb-3">
                    <div class="form-group">
                        <label class="text-slate-300">Min</label>
                        <input type="number" step="0.01" min="0" name="min_stock" id="edit_item_min" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label class="text-slate-300">Max</label>
                        <input type="number" step="0.01" min="0" name="max_stock" id="edit_item_max" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label class="text-slate-300">Reorden</label>
                        <input type="number" step="0.01" min="0" name="reorder_qty" id="edit_item_reorder" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                </div>
                <div class="flex flex-wrap gap-3 mb-5 text-sm text-slate-300">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_consumable" id="edit_item_consum" value="1" class="rounded">
                        <span>Consumible</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="track_lots" id="edit_item_lots" value="1" class="rounded">
                        <span>Rastrear lotes / vencimiento</span>
                    </label>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" data-action="close-modal" data-target="editItemModal" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i>Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Item Modal -->
    <div id="deleteItemModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-md relative">
            <button type="button" data-action="close-modal" data-target="deleteItemModal"
                class="absolute top-4 right-4 text-slate-400 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
            <h3 class="text-xl font-bold text-white mb-4">
                <i class="fas fa-trash text-red-400 mr-2"></i>Eliminar Item
            </h3>
            <form method="POST">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="item_id" id="delete_item_id">
                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-4">
                    <p class="text-slate-300">
                        ¿Eliminar el item <strong id="delete_item_name" class="text-cyan-300"></strong>?
                    </p>
                    <p id="delete_item_warning" class="text-yellow-300 text-xs mt-2 hidden">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        Este item tiene <span id="delete_item_usage"></span> asignación(es). No podrá eliminarse hasta liberarlas.
                    </p>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" data-action="close-modal" data-target="deleteItemModal" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary" style="background-color:#dc2626;">
                        <i class="fas fa-trash mr-2"></i>Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div id="editCategoryModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-md relative">
            <button type="button" data-action="close-modal" data-target="editCategoryModal"
                class="absolute top-4 right-4 text-slate-400 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
            <h3 class="text-xl font-bold text-white mb-4"><i class="fas fa-edit mr-2"></i>Editar Categoría</h3>
            <form method="POST">
                <input type="hidden" name="action" value="edit_category">
                <input type="hidden" name="category_id" id="edit_category_id">
                <div class="form-group mb-6">
                    <label class="text-slate-300">Nombre *</label>
                    <input type="text" name="name" id="edit_category_name_input" required
                        class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" data-action="close-modal" data-target="editCategoryModal" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i>Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Category Modal -->
    <div id="deleteCategoryModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-md relative">
            <button type="button" data-action="close-modal" data-target="deleteCategoryModal"
                class="absolute top-4 right-4 text-slate-400 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
            <h3 class="text-xl font-bold text-white mb-4">
                <i class="fas fa-trash text-red-400 mr-2"></i>Eliminar Categoría
            </h3>
            <form method="POST">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="category_id" id="delete_category_id">
                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-4">
                    <p class="text-slate-300">
                        ¿Eliminar la categoría <strong id="delete_category_name" class="text-purple-300"></strong>?
                    </p>
                    <p id="delete_category_warning" class="text-yellow-300 text-xs mt-2 hidden">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        La categoría contiene <span id="delete_category_usage"></span> item(s). Reasigna o elimina los items primero.
                    </p>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" data-action="close-modal" data-target="deleteCategoryModal" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary" style="background-color:#dc2626;">
                        <i class="fas fa-trash mr-2"></i>Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            'use strict';

            function getModal(id) { return document.getElementById(id); }

            function openModal(id) {
                const m = getModal(id); if (!m) return;
                m.classList.remove('hidden');
                m.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                const f = m.querySelector('input:not([type=hidden]), textarea, select');
                if (f) setTimeout(() => f.focus(), 50);
            }
            function closeModal(id) {
                const m = getModal(id); if (!m) return;
                m.classList.add('hidden');
                m.style.display = 'none';
                document.body.style.overflow = '';
            }
            function closeAll() {
                ['editItemModal','deleteItemModal','editCategoryModal','deleteCategoryModal'].forEach(closeModal);
            }

            document.addEventListener('click', function (e) {
                const trigger = e.target.closest('[data-action]');
                if (!trigger) return;
                const action = trigger.dataset.action;

                if (action === 'edit-item') {
                    const d = trigger.dataset;
                    document.getElementById('edit_item_id').value = d.id;
                    document.getElementById('edit_item_name_input').value = d.name || '';
                    document.getElementById('edit_item_description').value = d.description || '';
                    document.getElementById('edit_item_category').value = d.categoryId || '';
                    document.getElementById('edit_item_sku').value = d.sku || '';
                    document.getElementById('edit_item_unit').value = d.unit || 'unidad';
                    document.getElementById('edit_item_min').value = d.minStock || 0;
                    document.getElementById('edit_item_max').value = d.maxStock || '';
                    document.getElementById('edit_item_reorder').value = d.reorderQty || '';
                    document.getElementById('edit_item_cost').value = d.unitCost || '';
                    document.getElementById('edit_item_consum').checked = d.isConsumable === '1';
                    document.getElementById('edit_item_lots').checked   = d.trackLots === '1';
                    openModal('editItemModal');
                } else if (action === 'delete-item') {
                    document.getElementById('delete_item_id').value = trigger.dataset.id;
                    document.getElementById('delete_item_name').textContent = trigger.dataset.name || '';
                    const usage = parseInt(trigger.dataset.usage || '0', 10);
                    const warn = document.getElementById('delete_item_warning');
                    if (usage > 0) {
                        document.getElementById('delete_item_usage').textContent = usage;
                        warn.classList.remove('hidden');
                    } else {
                        warn.classList.add('hidden');
                    }
                    openModal('deleteItemModal');
                } else if (action === 'edit-category') {
                    document.getElementById('edit_category_id').value = trigger.dataset.id;
                    document.getElementById('edit_category_name_input').value = trigger.dataset.name || '';
                    openModal('editCategoryModal');
                } else if (action === 'delete-category') {
                    document.getElementById('delete_category_id').value = trigger.dataset.id;
                    document.getElementById('delete_category_name').textContent = trigger.dataset.name || '';
                    const usage = parseInt(trigger.dataset.usage || '0', 10);
                    const warn = document.getElementById('delete_category_warning');
                    if (usage > 0) {
                        document.getElementById('delete_category_usage').textContent = usage;
                        warn.classList.remove('hidden');
                    } else {
                        warn.classList.add('hidden');
                    }
                    openModal('deleteCategoryModal');
                } else if (action === 'close-modal') {
                    const target = trigger.dataset.target;
                    if (target) closeModal(target);
                }
            });

            // click outside to close
            ['editItemModal','deleteItemModal','editCategoryModal','deleteCategoryModal'].forEach(id => {
                const m = getModal(id);
                if (m) m.addEventListener('click', e => { if (e.target === m) closeModal(id); });
            });

            // Esc to close
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAll(); });

            // Auto-dismiss success banner
            const ok = document.querySelector('.status-banner.success');
            if (ok) setTimeout(() => {
                ok.style.transition = 'opacity .4s ease'; ok.style.opacity = '0';
                setTimeout(() => ok.remove(), 400);
            }, 5000);
        })();
    </script>
</body>

</html>
