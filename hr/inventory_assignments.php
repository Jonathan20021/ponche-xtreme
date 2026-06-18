<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';
require_once '../lib/inventory_functions.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle Edit Item (NO stock impact — only metadata)
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
        $successMsg = "Articulo actualizado correctamente.";
    } catch (Exception $e) {
        $errorMsg = "Error al actualizar: " . $e->getMessage();
    }
}

// Handle delete item (NO automatic stock re-add — deleting is admin-only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $inventoryId = (int) $_POST['inventory_id'];
    try {
        $pdo->prepare("DELETE FROM employee_inventory WHERE id = ?")->execute([$inventoryId]);
        $successMsg = "Registro eliminado.";
    } catch (Exception $e) {
        $errorMsg = "Error al eliminar: " . $e->getMessage();
    }
}

// Handle return: mark as RETURNED + record a RETURN movement (adds stock back)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_item'])) {
    $inventoryId = (int) $_POST['inventory_id'];
    $notes = trim($_POST['notes']);
    try {
        $pdo->beginTransaction();

        $row = $pdo->prepare("SELECT * FROM employee_inventory WHERE id = ? FOR UPDATE");
        $row->execute([$inventoryId]);
        $assignment = $row->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) throw new RuntimeException('Asignacion no encontrada');
        if ($assignment['status'] === 'RETURNED') {
            $pdo->rollBack();
            $errorMsg = "Este articulo ya estaba devuelto.";
        } else {
            $upd = $pdo->prepare("UPDATE employee_inventory
                SET status = 'RETURNED', returned_date = CURDATE(),
                    notes = CONCAT(COALESCE(notes, ''), '\n[Devuelto: ', NOW(), '] ', ?)
                WHERE id = ?");
            $upd->execute([$notes, $inventoryId]);

            $qty = (float) ($assignment['quantity'] ?? 1);
            if ($qty <= 0) $qty = 1;
            inv_record_movement($pdo, [
                'item_type_id'  => (int) $assignment['item_type_id'],
                'movement_type' => 'RETURN',
                'quantity'      => $qty,
                'reason'        => 'Devolucion de empleado',
                'employee_id'   => (int) $assignment['employee_id'],
                'assignment_id' => $inventoryId,
                'notes'         => $notes,
            ]);

            $pdo->commit();
            $successMsg = "Articulo devuelto y stock re-incrementado.";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMsg = "Error al devolver: " . $e->getMessage();
    }
}

// Filters
$searchQuery       = $_GET['search'] ?? '';
$statusFilter      = $_GET['status'] ?? 'ASSIGNED';
$categoryFilter    = $_GET['category'] ?? 'all';
$employeeIdFilter  = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;

$query  = "SELECT ei.*, CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_code,
                  it.name AS item_name, it.unit, it.description AS item_description,
                  c.name AS category_name, c.icon AS category_icon, c.color AS category_color
           FROM employee_inventory ei
           JOIN employees e ON e.id = ei.employee_id
           JOIN inventory_item_types it ON it.id = ei.item_type_id
           JOIN inventory_categories c ON c.id = it.category_id
           WHERE 1=1";
$params = [];
if ($searchQuery) {
    $query .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ? OR it.name LIKE ? OR ei.details LIKE ? OR ei.uuid LIKE ?)";
    $t = "%$searchQuery%";
    array_push($params, $t, $t, $t, $t, $t, $t);
}
if ($statusFilter !== 'all')   { $query .= " AND ei.status = ?";   $params[] = $statusFilter; }
if ($categoryFilter !== 'all') { $query .= " AND c.id = ?";        $params[] = (int) $categoryFilter; }
if ($employeeIdFilter > 0)     { $query .= " AND e.id = ?";        $params[] = $employeeIdFilter; }
$query .= " ORDER BY ei.assigned_date DESC LIMIT 500";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inventoryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT * FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'assigned' => (int) $pdo->query("SELECT COUNT(*) FROM employee_inventory WHERE status = 'ASSIGNED'")->fetchColumn(),
    'returned' => (int) $pdo->query("SELECT COUNT(*) FROM employee_inventory WHERE status = 'RETURNED'")->fetchColumn(),
    'lost'     => (int) $pdo->query("SELECT COUNT(*) FROM employee_inventory WHERE status = 'LOST'")->fetchColumn(),
    'damaged'  => (int) $pdo->query("SELECT COUNT(*) FROM employee_inventory WHERE status = 'DAMAGED'")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asignaciones a Empleados</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.75); z-index: 50; backdrop-filter: blur(4px); }
        .modal-content { background: var(--surface) !important; border: 1px solid #334155; box-shadow: 0 20px 25px -5px rgba(0,0,0,.5); }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-wrap justify-between items-center gap-3 mb-6">
            <div class="flex items-center gap-4">
                <a href="inventory.php" class="text-slate-400 hover:text-white"><i class="fas fa-arrow-left text-xl"></i></a>
                <div>
                    <h1 class="text-3xl font-bold text-white"><i class="fas fa-user-tag text-cyan-400 mr-2"></i>Asignaciones a Empleados</h1>
                    <p class="text-slate-400 text-sm">Inventario fisico entregado al personal</p>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="inventory_assign.php" class="btn-primary"><i class="fas fa-plus-circle"></i> Nueva asignacion</a>
                <a href="inventory_movements.php" class="btn-secondary"><i class="fas fa-right-left"></i> Movimientos</a>
            </div>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if (isset($errorMsg)): ?>
            <div class="status-banner error mb-6"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <a href="?status=ASSIGNED" class="glass-card hover:ring-2 hover:ring-blue-500/40">
                <p class="text-slate-400 text-xs uppercase">Asignados</p>
                <h3 class="text-2xl font-bold text-blue-400"><?= $stats['assigned'] ?></h3>
            </a>
            <a href="?status=RETURNED" class="glass-card hover:ring-2 hover:ring-green-500/40">
                <p class="text-slate-400 text-xs uppercase">Devueltos</p>
                <h3 class="text-2xl font-bold text-green-400"><?= $stats['returned'] ?></h3>
            </a>
            <a href="?status=LOST" class="glass-card hover:ring-2 hover:ring-red-500/40">
                <p class="text-slate-400 text-xs uppercase">Perdidos</p>
                <h3 class="text-2xl font-bold text-red-400"><?= $stats['lost'] ?></h3>
            </a>
            <a href="?status=DAMAGED" class="glass-card hover:ring-2 hover:ring-yellow-500/40">
                <p class="text-slate-400 text-xs uppercase">Danados</p>
                <h3 class="text-2xl font-bold text-yellow-400"><?= $stats['damaged'] ?></h3>
            </a>
        </div>

        <div class="glass-card mb-6">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div class="form-group flex-1 min-w-[220px]">
                    <label>Buscar</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Empleado, codigo, item, serial...">
                </div>
                <div class="form-group">
                    <label>Estado</label>
                    <select name="status">
                        <option value="all"      <?= $statusFilter === 'all'      ? 'selected' : '' ?>>Todos</option>
                        <option value="ASSIGNED" <?= $statusFilter === 'ASSIGNED' ? 'selected' : '' ?>>Asignado</option>
                        <option value="RETURNED" <?= $statusFilter === 'RETURNED' ? 'selected' : '' ?>>Devuelto</option>
                        <option value="LOST"     <?= $statusFilter === 'LOST'     ? 'selected' : '' ?>>Perdido</option>
                        <option value="DAMAGED"  <?= $statusFilter === 'DAMAGED'  ? 'selected' : '' ?>>Danado</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Categoria</label>
                    <select name="category">
                        <option value="all">Todas</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $categoryFilter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                <a href="inventory_assignments.php" class="btn-secondary">Limpiar</a>
            </form>
        </div>

        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="text-left text-slate-400 border-b border-slate-700 text-xs uppercase">
                        <tr>
                            <th class="p-3">Empleado</th>
                            <th class="p-3">Articulo</th>
                            <th class="p-3">Categoria</th>
                            <th class="p-3">Cantidad</th>
                            <th class="p-3">Detalles / Serial</th>
                            <th class="p-3">Fecha</th>
                            <th class="p-3">Estado</th>
                            <th class="p-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/40">
                    <?php if (empty($inventoryItems)): ?>
                        <tr><td colspan="8" class="p-8 text-center text-slate-500">No hay registros.</td></tr>
                    <?php else: foreach ($inventoryItems as $item): ?>
                        <?php
                            $statusColors = [
                                'ASSIGNED' => 'bg-blue-500/20 text-blue-300',
                                'RETURNED' => 'bg-green-500/20 text-green-300',
                                'LOST'     => 'bg-red-500/20 text-red-300',
                                'DAMAGED'  => 'bg-yellow-500/20 text-yellow-300',
                            ];
                            $statusLabel = ['ASSIGNED'=>'Asignado','RETURNED'=>'Devuelto','LOST'=>'Perdido','DAMAGED'=>'Danado'][$item['status']] ?? $item['status'];
                            $statusClass = $statusColors[$item['status']] ?? 'bg-slate-500/20 text-slate-300';
                        ?>
                        <tr class="hover:bg-slate-800/40">
                            <td class="p-3">
                                <div class="text-white font-medium"><?= htmlspecialchars($item['employee_name']) ?></div>
                                <div class="text-xs text-slate-500"><?= htmlspecialchars($item['employee_code']) ?></div>
                            </td>
                            <td class="p-3">
                                <div class="text-cyan-300 font-medium"><?= htmlspecialchars($item['item_name']) ?></div>
                                <?php if ($item['item_description']): ?>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars($item['item_description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-sm">
                                <span class="text-xs px-2 py-1 rounded-full bg-slate-700 text-slate-300">
                                    <i class="fas <?= htmlspecialchars($item['category_icon'] ?? 'fa-box') ?> text-<?= htmlspecialchars($item['category_color'] ?? 'slate') ?>-400 mr-1"></i>
                                    <?= htmlspecialchars($item['category_name']) ?>
                                </span>
                            </td>
                            <td class="p-3 text-white"><?= inv_format_qty((float) ($item['quantity'] ?? 1), $item['unit']) ?></td>
                            <td class="p-3 text-sm text-slate-300">
                                <?= htmlspecialchars($item['details'] ?? '—') ?>
                                <?php if ($item['uuid']): ?>
                                    <div class="text-xs text-slate-500 font-mono mt-0.5"><i class="fas fa-barcode mr-1"></i><?= htmlspecialchars($item['uuid']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-slate-300 text-sm whitespace-nowrap">
                                <?= date('d/m/Y', strtotime($item['assigned_date'])) ?>
                                <?php if ($item['returned_date']): ?>
                                    <div class="text-xs text-green-400"><i class="fas fa-undo-alt mr-1"></i><?= date('d/m/Y', strtotime($item['returned_date'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-3"><span class="text-xs px-2 py-1 rounded-full <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                            <td class="p-3 text-right whitespace-nowrap">
                                <button onclick="openEdit(<?= (int) $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['employee_name'])) ?>', '<?= htmlspecialchars(addslashes($item['item_name'])) ?>', '<?= htmlspecialchars(addslashes($item['details'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($item['uuid'] ?? '')) ?>', '<?= $item['status'] ?>')"
                                    class="text-blue-400 hover:bg-blue-500/10 p-2 rounded" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($item['status'] === 'ASSIGNED'): ?>
                                <button onclick="openReturn(<?= (int) $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['item_name'])) ?>')"
                                    class="text-green-400 hover:bg-green-500/10 p-2 rounded" title="Marcar devuelto">
                                    <i class="fas fa-undo"></i>
                                </button>
                                <?php endif; ?>
                                <button onclick="openDelete(<?= (int) $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['item_name'])) ?>', '<?= htmlspecialchars(addslashes($item['employee_name'])) ?>')"
                                    class="text-red-400 hover:bg-red-500/10 p-2 rounded" title="Eliminar">
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

    <!-- Edit modal -->
    <div id="editModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-xl relative">
            <button onclick="closeM('editModal')" class="absolute top-4 right-4 text-slate-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            <h3 class="text-xl font-bold text-white mb-4"><i class="fas fa-edit text-blue-400 mr-2"></i>Editar asignacion</h3>
            <form method="POST">
                <input type="hidden" name="edit_item" value="1">
                <input type="hidden" name="inventory_id" id="edit_id">
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group"><label>Empleado</label><input type="text" id="edit_emp" readonly class="w-full bg-slate-700 text-slate-400 border-slate-600 rounded p-2 cursor-not-allowed"></div>
                    <div class="form-group"><label>Item</label><input type="text" id="edit_item" readonly class="w-full bg-slate-700 text-slate-400 border-slate-600 rounded p-2 cursor-not-allowed"></div>
                </div>
                <div class="form-group mb-3"><label>Detalles *</label><input type="text" name="details" id="edit_details" required class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2"></div>
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="form-group"><label>Serial / Tag</label><input type="text" name="uuid" id="edit_uuid" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2"></div>
                    <div class="form-group">
                        <label>Estado *</label>
                        <select name="status" id="edit_status" required class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                            <option value="ASSIGNED">Asignado</option>
                            <option value="RETURNED">Devuelto</option>
                            <option value="LOST">Perdido</option>
                            <option value="DAMAGED">Danado</option>
                        </select>
                    </div>
                </div>
                <div class="form-group mb-5"><label>Notas</label><textarea name="notes" rows="2" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2"></textarea></div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeM('editModal')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-save mr-1"></i>Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Return modal -->
    <div id="returnModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-md relative">
            <button onclick="closeM('returnModal')" class="absolute top-4 right-4 text-slate-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            <h3 class="text-xl font-bold text-white mb-4"><i class="fas fa-undo text-green-400 mr-2"></i>Confirmar devolucion</h3>
            <form method="POST">
                <input type="hidden" name="return_item" value="1">
                <input type="hidden" name="inventory_id" id="ret_id">
                <p class="text-slate-300 mb-3">Marcar <strong id="ret_name" class="text-cyan-300"></strong> como devuelto. El stock sera re-incrementado automaticamente.</p>
                <div class="form-group mb-5"><label>Notas (opcional)</label><textarea name="notes" rows="2" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2"></textarea></div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeM('returnModal')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary" style="background:#16a34a;"><i class="fas fa-check mr-1"></i>Confirmar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete modal -->
    <div id="deleteModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-md relative">
            <button onclick="closeM('deleteModal')" class="absolute top-4 right-4 text-slate-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            <h3 class="text-xl font-bold text-white mb-4"><i class="fas fa-trash text-red-400 mr-2"></i>Eliminar registro</h3>
            <form method="POST">
                <input type="hidden" name="delete_item" value="1">
                <input type="hidden" name="inventory_id" id="del_id">
                <div class="bg-red-500/10 border border-red-500/30 rounded p-3 mb-5">
                    <p class="text-slate-300">Eliminar <strong id="del_name" class="text-cyan-300"></strong> de <strong id="del_emp" class="text-slate-200"></strong>?</p>
                    <p class="text-red-400 text-xs mt-2">Esta accion no se puede deshacer y no afecta el stock.</p>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeM('deleteModal')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary" style="background:#dc2626;"><i class="fas fa-trash mr-1"></i>Eliminar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openM(id) { const m = document.getElementById(id); m.style.display='flex'; m.classList.remove('hidden'); }
    function closeM(id) { const m = document.getElementById(id); m.style.display='none'; m.classList.add('hidden'); }
    function openEdit(id, emp, item, details, uuid, status) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_emp').value = emp;
        document.getElementById('edit_item').value = item;
        document.getElementById('edit_details').value = details;
        document.getElementById('edit_uuid').value = uuid;
        document.getElementById('edit_status').value = status;
        openM('editModal');
    }
    function openReturn(id, name) {
        document.getElementById('ret_id').value = id;
        document.getElementById('ret_name').textContent = name;
        openM('returnModal');
    }
    function openDelete(id, name, emp) {
        document.getElementById('del_id').value = id;
        document.getElementById('del_name').textContent = name;
        document.getElementById('del_emp').textContent = emp;
        openM('deleteModal');
    }
    ['editModal','returnModal','deleteModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m) m.addEventListener('click', e => { if (e.target === m) closeM(id); });
    });
    document.addEventListener('keydown', e => { if (e.key==='Escape') ['editModal','returnModal','deleteModal'].forEach(closeM); });
    </script>
</body>
</html>
