<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';
require_once '../lib/inventory_functions.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$searchQuery     = trim((string) ($_GET['search'] ?? ''));
$categoryFilter  = (int) ($_GET['category'] ?? 0);
$stockFilter     = $_GET['filter'] ?? 'all';   // all|low|out|in_stock|expiring
$sortBy          = $_GET['sort']   ?? 'name';

$where  = ['it.is_active = 1'];
$params = [];

if ($searchQuery !== '') {
    $where[] = "(it.name LIKE ? OR it.sku LIKE ? OR it.description LIKE ? OR c.name LIKE ?)";
    $t = "%$searchQuery%";
    array_push($params, $t, $t, $t, $t);
}
if ($categoryFilter > 0) {
    $where[] = "it.category_id = ?";
    $params[] = $categoryFilter;
}
switch ($stockFilter) {
    case 'low':       $where[] = "it.min_stock > 0 AND it.current_stock > 0 AND it.current_stock <= it.min_stock"; break;
    case 'out':       $where[] = "it.current_stock <= 0"; break;
    case 'in_stock':  $where[] = "it.current_stock > 0"; break;
    case 'expiring':  $where[] = "EXISTS (SELECT 1 FROM inventory_lots l WHERE l.item_type_id = it.id AND l.quantity_remaining > 0 AND l.expiration_date IS NOT NULL AND l.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))"; break;
}

$orderMap = [
    'name'        => 'it.name ASC',
    'stock_asc'   => 'it.current_stock ASC, it.name ASC',
    'stock_desc'  => 'it.current_stock DESC, it.name ASC',
    'value_desc'  => '(it.current_stock * COALESCE(it.unit_cost, 0)) DESC, it.name ASC',
    'recent'      => 'it.updated_at DESC, it.created_at DESC',
];
$orderBy = $orderMap[$sortBy] ?? $orderMap['name'];

$sql = "
    SELECT it.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
           s.name AS supplier_name
    FROM inventory_item_types it
    JOIN inventory_categories c ON c.id = it.category_id
    LEFT JOIN inventory_suppliers s ON s.id = it.supplier_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $orderBy
    LIMIT 500
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT id, name, icon, color FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers  = $pdo->query("SELECT id, name FROM inventory_suppliers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catalogo de Stock</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.75); z-index: 50; backdrop-filter: blur(4px); }
        .modal-content { background: #0f172a !important; border: 1px solid #334155; box-shadow: 0 20px 25px -5px rgba(0,0,0,.5); }
        .progress-bar { height: 6px; border-radius: 4px; overflow: hidden; background: rgba(148,163,184,.15); }
        .progress-fill { height: 100%; transition: width .6s ease; }
        .stock-cell { min-width: 160px; }
        .ai-badge { background: linear-gradient(135deg,#7c3aed,#0891b2); color:white; font-size:.65rem; padding:2px 8px; border-radius:999px; }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-wrap justify-between items-center gap-3 mb-6">
            <div class="flex items-center gap-4">
                <a href="inventory.php" class="text-slate-400 hover:text-white" title="Volver al dashboard"><i class="fas fa-arrow-left text-xl"></i></a>
                <div>
                    <h1 class="text-3xl font-bold text-white"><i class="fas fa-boxes-stacked text-cyan-400 mr-2"></i>Catalogo de Stock</h1>
                    <p class="text-slate-400 text-sm"><?= count($items) ?> items mostrados</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button onclick="openEntryModal()" class="btn-primary"><i class="fas fa-plus"></i> Recepcion / Entrada</button>
                <a href="inventory_movements.php" class="btn-secondary"><i class="fas fa-right-left"></i> Ver movimientos</a>
                <a href="inventory_manage.php" class="btn-secondary"><i class="fas fa-cog"></i> Items</a>
            </div>
        </div>

        <!-- Filters -->
        <div class="glass-card mb-6">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div class="form-group flex-1 min-w-[220px]">
                    <label>Buscar</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Nombre, SKU, categoria...">
                </div>
                <div class="form-group">
                    <label>Categoria</label>
                    <select name="category">
                        <option value="0">Todas</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $categoryFilter === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Stock</label>
                    <select name="filter">
                        <option value="all"      <?= $stockFilter === 'all'      ? 'selected' : '' ?>>Todos</option>
                        <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>Con stock</option>
                        <option value="low"      <?= $stockFilter === 'low'      ? 'selected' : '' ?>>Stock bajo</option>
                        <option value="out"      <?= $stockFilter === 'out'      ? 'selected' : '' ?>>Agotados</option>
                        <option value="expiring" <?= $stockFilter === 'expiring' ? 'selected' : '' ?>>Por vencer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Ordenar</label>
                    <select name="sort">
                        <option value="name"       <?= $sortBy === 'name'       ? 'selected' : '' ?>>Nombre A-Z</option>
                        <option value="stock_asc"  <?= $sortBy === 'stock_asc'  ? 'selected' : '' ?>>Stock menor primero</option>
                        <option value="stock_desc" <?= $sortBy === 'stock_desc' ? 'selected' : '' ?>>Stock mayor primero</option>
                        <option value="value_desc" <?= $sortBy === 'value_desc' ? 'selected' : '' ?>>Valor mayor primero</option>
                        <option value="recent"     <?= $sortBy === 'recent'     ? 'selected' : '' ?>>Recien actualizados</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Aplicar</button>
                <a href="inventory_stock.php" class="btn-secondary">Limpiar</a>
            </form>
        </div>

        <!-- Items table -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="text-left text-slate-400 border-b border-slate-700 text-xs uppercase">
                        <tr>
                            <th class="p-3">Item</th>
                            <th class="p-3">Categoria</th>
                            <th class="p-3">Stock</th>
                            <th class="p-3">Min / Max</th>
                            <th class="p-3">Tipo</th>
                            <th class="p-3">Valor</th>
                            <th class="p-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/50">
                    <?php if (empty($items)): ?>
                        <tr><td colspan="7" class="p-8 text-center text-slate-500">Sin items que coincidan con los filtros.</td></tr>
                    <?php else: foreach ($items as $it): ?>
                        <?php
                            $cur   = (float) $it['current_stock'];
                            $minS  = (float) $it['min_stock'];
                            $maxS  = $it['max_stock'] !== null ? (float) $it['max_stock'] : null;
                            $cost  = $it['unit_cost'] !== null ? (float) $it['unit_cost'] : null;
                            $value = $cost !== null ? $cur * $cost : null;

                            $stockState = 'ok';
                            if ($cur <= 0)                    $stockState = 'out';
                            elseif ($minS > 0 && $cur <= $minS) $stockState = 'low';

                            $pct = 0; $bar = 'bg-emerald-500';
                            if ($maxS !== null && $maxS > 0) {
                                $pct = min(100, ($cur / $maxS) * 100);
                                $bar = $cur <= $minS ? 'bg-red-500' : ($cur <= ($minS + ($maxS - $minS) * 0.3) ? 'bg-yellow-500' : 'bg-emerald-500');
                            } elseif ($minS > 0) {
                                $pct = min(100, ($cur / max($minS, 1)) * 50);
                                $bar = $cur <= $minS ? 'bg-red-500' : 'bg-emerald-500';
                            } else {
                                $pct = $cur > 0 ? 80 : 0;
                            }
                        ?>
                        <tr class="hover:bg-slate-800/40 transition-colors">
                            <td class="p-3 align-top">
                                <div class="flex items-start gap-2">
                                    <i class="fas <?= htmlspecialchars($it['category_icon'] ?: 'fa-box') ?> text-<?= htmlspecialchars($it['category_color']) ?>-400 mt-1"></i>
                                    <div>
                                        <div class="text-white font-medium"><?= htmlspecialchars($it['name']) ?></div>
                                        <?php if (!empty($it['description'])): ?>
                                            <div class="text-xs text-slate-500"><?= htmlspecialchars($it['description']) ?></div>
                                        <?php endif; ?>
                                        <div class="text-xs text-slate-600 mt-0.5">
                                            <?php if ($it['sku']): ?>SKU: <?= htmlspecialchars($it['sku']) ?> · <?php endif; ?>
                                            Unidad: <?= htmlspecialchars($it['unit']) ?>
                                            <?php if ($it['supplier_name']): ?> · Prov: <?= htmlspecialchars($it['supplier_name']) ?><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="p-3 align-top text-sm text-slate-400">
                                <?= htmlspecialchars($it['category_name']) ?>
                            </td>
                            <td class="p-3 align-top stock-cell">
                                <div class="flex items-baseline justify-between">
                                    <span class="text-lg font-bold <?= $stockState === 'out' ? 'text-red-400' : ($stockState === 'low' ? 'text-yellow-400' : 'text-emerald-400') ?>">
                                        <?= inv_format_qty($cur) ?>
                                    </span>
                                    <span class="text-xs text-slate-500"><?= htmlspecialchars($it['unit']) ?></span>
                                </div>
                                <div class="progress-bar mt-1"><div class="progress-fill <?= $bar ?>" style="width: <?= $pct ?>%"></div></div>
                                <?php if ($stockState === 'out'): ?>
                                    <span class="inline-block mt-1 text-xs px-2 py-0.5 rounded-full bg-red-500/20 text-red-300">AGOTADO</span>
                                <?php elseif ($stockState === 'low'): ?>
                                    <span class="inline-block mt-1 text-xs px-2 py-0.5 rounded-full bg-yellow-500/20 text-yellow-300">STOCK BAJO</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 align-top text-sm text-slate-300">
                                <div>Min: <?= inv_format_qty($minS) ?></div>
                                <div class="text-xs text-slate-500">
                                    <?= $maxS !== null ? "Max: " . inv_format_qty($maxS) : "Max: -" ?>
                                </div>
                                <?php if ($it['reorder_qty']): ?>
                                    <div class="text-xs text-slate-600">Reorden: <?= inv_format_qty((float) $it['reorder_qty']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 align-top">
                                <?php if ((int) $it['is_consumable'] === 1): ?>
                                    <span class="text-xs px-2 py-1 rounded-full bg-orange-500/20 text-orange-300"><i class="fas fa-recycle mr-1"></i>Consumible</span>
                                <?php else: ?>
                                    <span class="text-xs px-2 py-1 rounded-full bg-blue-500/20 text-blue-300"><i class="fas fa-tag mr-1"></i>Asignable</span>
                                <?php endif; ?>
                                <?php if ((int) $it['track_lots'] === 1): ?>
                                    <div class="text-xs text-purple-300 mt-1"><i class="fas fa-flask mr-1"></i>Lotes / vence</div>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 align-top text-sm">
                                <?php if ($cost !== null): ?>
                                    <div class="text-emerald-400 font-medium">$<?= number_format($value ?? 0, 2) ?></div>
                                    <div class="text-xs text-slate-500">$<?= number_format($cost, 2) ?> / <?= htmlspecialchars($it['unit']) ?></div>
                                <?php else: ?>
                                    <span class="text-slate-600 text-xs">Sin costo</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 align-top text-right whitespace-nowrap">
                                <button onclick="openMovementModal(<?= (int) $it['id'] ?>, '<?= htmlspecialchars(addslashes($it['name'])) ?>', 'EXIT', '<?= htmlspecialchars($it['unit']) ?>')"
                                        class="text-red-400 hover:bg-red-500/10 p-2 rounded" title="Registrar salida">
                                    <i class="fas fa-arrow-up"></i>
                                </button>
                                <button onclick="openMovementModal(<?= (int) $it['id'] ?>, '<?= htmlspecialchars(addslashes($it['name'])) ?>', 'ENTRY', '<?= htmlspecialchars($it['unit']) ?>')"
                                        class="text-emerald-400 hover:bg-emerald-500/10 p-2 rounded" title="Registrar entrada">
                                    <i class="fas fa-arrow-down"></i>
                                </button>
                                <button onclick="openMovementModal(<?= (int) $it['id'] ?>, '<?= htmlspecialchars(addslashes($it['name'])) ?>', 'ADJUSTMENT', '<?= htmlspecialchars($it['unit']) ?>')"
                                        class="text-yellow-400 hover:bg-yellow-500/10 p-2 rounded" title="Ajustar stock">
                                    <i class="fas fa-sliders"></i>
                                </button>
                                <button onclick="openConfigModal(<?= (int) $it['id'] ?>, '<?= htmlspecialchars(addslashes($it['name'])) ?>', <?= json_encode($it['min_stock']) ?>, <?= json_encode($it['max_stock']) ?>, <?= json_encode($it['reorder_qty']) ?>, <?= json_encode($it['unit_cost']) ?>, <?= json_encode($it['supplier_id']) ?>)"
                                        class="text-slate-400 hover:bg-slate-500/10 p-2 rounded" title="Configurar limites">
                                    <i class="fas fa-cog"></i>
                                </button>
                                <button onclick="askAI(<?= (int) $it['id'] ?>, '<?= htmlspecialchars(addslashes($it['name'])) ?>', 'predict')"
                                        class="text-purple-400 hover:bg-purple-500/10 p-2 rounded" title="Prediccion IA">
                                    <i class="fas fa-magic-wand-sparkles"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Movement modal -->
    <div id="movementModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-lg relative">
            <button type="button" onclick="closeModal('movementModal')" class="absolute top-4 right-4 text-slate-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            <h3 class="text-xl font-bold text-white mb-4">
                <i id="movIcon" class="fas fa-arrow-down text-emerald-400 mr-2"></i>
                <span id="movTitle">Movimiento</span>
            </h3>
            <p class="text-slate-300 mb-4">Item: <strong id="movItemName" class="text-cyan-300"></strong></p>

            <form id="movementForm">
                <input type="hidden" name="action"        id="movAction" value="record_movement">
                <input type="hidden" name="item_type_id"  id="movItemId">
                <input type="hidden" name="movement_type" id="movType">

                <div id="movQtyContainer" class="form-group mb-4">
                    <label>Cantidad <span id="movUnit" class="text-slate-500 text-xs"></span></label>
                    <input type="number" step="0.01" min="0.01" name="quantity" id="movQty" required class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    <p id="movQtyHint" class="text-xs text-slate-500 mt-1 hidden">Para ajustar, ingresa positivo (suma) o negativo (resta).</p>
                </div>

                <div class="form-group mb-4">
                    <label>Razon / motivo</label>
                    <input type="text" name="reason" id="movReason" placeholder="Ej: Recepcion proveedor / Consumo cocina / Ajuste fisico" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                </div>

                <div class="form-group mb-4">
                    <label>Referencia (opcional)</label>
                    <input type="text" name="reference" placeholder="Factura, OC, ticket..." class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                </div>

                <div class="form-group mb-6">
                    <label>Notas</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2"></textarea>
                </div>

                <div id="movError" class="hidden mb-4 p-3 bg-red-500/10 border border-red-500/30 rounded text-red-300 text-sm"></div>

                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeModal('movementModal')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary" id="movSubmit"><i class="fas fa-check mr-1"></i>Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Config modal (min/max/reorder/cost) -->
    <div id="configModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-md relative">
            <button type="button" onclick="closeModal('configModal')" class="absolute top-4 right-4 text-slate-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            <h3 class="text-xl font-bold text-white mb-4"><i class="fas fa-sliders text-cyan-400 mr-2"></i>Configurar limites</h3>
            <p class="text-slate-300 mb-4">Item: <strong id="cfgItemName" class="text-cyan-300"></strong></p>
            <form id="configForm">
                <input type="hidden" name="action" value="set_min_max">
                <input type="hidden" name="item_type_id" id="cfgItemId">
                <div class="grid grid-cols-2 gap-3">
                    <div class="form-group">
                        <label>Stock minimo</label>
                        <input type="number" step="0.01" min="0" name="min_stock" id="cfgMin" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label>Stock maximo</label>
                        <input type="number" step="0.01" min="0" name="max_stock" id="cfgMax" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label>Cantidad reorden</label>
                        <input type="number" step="0.01" min="0" name="reorder_qty" id="cfgReorder" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label>Costo unitario ($)</label>
                        <input type="number" step="0.01" min="0" name="unit_cost" id="cfgCost" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group col-span-2">
                        <label>Proveedor</label>
                        <select name="supplier_id" id="cfgSupplier" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                            <option value="">Sin proveedor</option>
                            <?php foreach ($suppliers as $sp): ?>
                                <option value="<?= (int) $sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="cfgError" class="hidden mt-4 p-3 bg-red-500/10 border border-red-500/30 rounded text-red-300 text-sm"></div>
                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeModal('configModal')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-save mr-1"></i>Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- AI insight modal -->
    <div id="aiModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-lg relative">
            <button type="button" onclick="closeModal('aiModal')" class="absolute top-4 right-4 text-slate-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            <h3 class="text-xl font-bold text-white mb-2">
                <span class="ai-badge mr-2"><i class="fas fa-robot"></i> Claude</span>
                <span id="aiTitle">Analisis IA</span>
            </h3>
            <p class="text-slate-400 text-sm mb-4">Item: <strong id="aiItemName" class="text-cyan-300"></strong></p>
            <div id="aiBody" class="text-slate-200 text-sm space-y-2 min-h-[120px]">
                <div class="text-center py-6 text-slate-500"><i class="fas fa-circle-notch fa-spin text-2xl mb-2"></i><div>Analizando con Claude...</div></div>
            </div>
        </div>
    </div>

    <script>
    function openMovementModal(itemId, name, type, unit) {
        document.getElementById('movItemId').value = itemId;
        document.getElementById('movItemName').textContent = name;
        document.getElementById('movType').value = type;
        document.getElementById('movUnit').textContent = unit ? '(en ' + unit + ')' : '';
        document.getElementById('movError').classList.add('hidden');
        document.getElementById('movQty').value = '';
        document.querySelector('#movementForm [name=reason]').value = '';
        document.querySelector('#movementForm [name=reference]').value = '';
        document.querySelector('#movementForm [name=notes]').value = '';
        const titleMap = {
            'ENTRY':      { title: 'Registrar entrada',  icon: 'fa-arrow-down text-emerald-400', action: 'record_movement', allowNegative: false },
            'EXIT':       { title: 'Registrar salida',   icon: 'fa-arrow-up text-red-400',       action: 'record_movement', allowNegative: false },
            'ADJUSTMENT': { title: 'Ajustar stock',      icon: 'fa-sliders text-yellow-400',     action: 'adjust',          allowNegative: true  },
        };
        const cfg = titleMap[type];
        document.getElementById('movTitle').textContent = cfg.title;
        document.getElementById('movIcon').className = 'fas ' + cfg.icon + ' mr-2';
        document.getElementById('movAction').value = cfg.action;
        const qtyInput = document.getElementById('movQty');
        const hint = document.getElementById('movQtyHint');
        if (cfg.allowNegative) {
            qtyInput.removeAttribute('min');
            qtyInput.setAttribute('step', '0.01');
            qtyInput.name = 'signed_quantity';
            hint.classList.remove('hidden');
        } else {
            qtyInput.setAttribute('min', '0.01');
            qtyInput.name = 'quantity';
            hint.classList.add('hidden');
        }
        document.getElementById('movementModal').style.display = 'flex';
        document.getElementById('movementModal').classList.remove('hidden');
    }
    function openConfigModal(id, name, min, max, reorder, cost, supplierId) {
        document.getElementById('cfgItemId').value = id;
        document.getElementById('cfgItemName').textContent = name;
        document.getElementById('cfgMin').value = min ?? '';
        document.getElementById('cfgMax').value = max ?? '';
        document.getElementById('cfgReorder').value = reorder ?? '';
        document.getElementById('cfgCost').value = cost ?? '';
        document.getElementById('cfgSupplier').value = supplierId ?? '';
        document.getElementById('cfgError').classList.add('hidden');
        document.getElementById('configModal').style.display = 'flex';
        document.getElementById('configModal').classList.remove('hidden');
    }
    function openEntryModal() {
        window.location.href = 'inventory_movements.php?action=entry';
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        m.style.display = 'none';
        m.classList.add('hidden');
    }

    async function askAI(itemId, name, kind) {
        document.getElementById('aiItemName').textContent = name;
        document.getElementById('aiTitle').textContent = kind === 'predict' ? 'Prediccion de consumo' : 'Deteccion de anomalias';
        document.getElementById('aiBody').innerHTML = '<div class="text-center py-6 text-slate-500"><i class="fas fa-circle-notch fa-spin text-2xl mb-2"></i><div>Analizando con Claude...</div></div>';
        document.getElementById('aiModal').style.display = 'flex';
        document.getElementById('aiModal').classList.remove('hidden');

        const fd = new FormData();
        fd.append('action', kind === 'predict' ? 'predict' : 'anomalies');
        fd.append('item_type_id', itemId);
        try {
            const r = await fetch('../api/inventory_ai.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.success) {
                document.getElementById('aiBody').innerHTML = '<div class="p-3 bg-red-500/10 border border-red-500/30 rounded text-red-300">' + (j.error || 'Error') + '</div>';
                return;
            }
            const data = j.prediction || j.anomaly;
            if (!data) { document.getElementById('aiBody').innerHTML = '<div class="text-slate-400">Sin datos.</div>'; return; }
            let html = '';
            if (kind === 'predict') {
                html += '<div class="grid grid-cols-2 gap-3">';
                html += '<div class="p-3 bg-slate-800/60 rounded"><div class="text-xs text-slate-400">Dias restantes</div><div class="text-2xl font-bold text-cyan-300">' + (data.days_until_stockout ?? '—') + '</div></div>';
                html += '<div class="p-3 bg-slate-800/60 rounded"><div class="text-xs text-slate-400">Consumo mensual prom.</div><div class="text-2xl font-bold text-orange-300">' + (data.monthly_consumption_avg ?? '—') + '</div></div>';
                html += '<div class="p-3 bg-slate-800/60 rounded"><div class="text-xs text-slate-400">Recomendado reordenar</div><div class="text-2xl font-bold text-emerald-300">' + (data.recommended_reorder_qty ?? '—') + '</div></div>';
                html += '<div class="p-3 bg-slate-800/60 rounded"><div class="text-xs text-slate-400">Confianza</div><div class="text-lg font-semibold text-purple-300">' + (data.confidence ?? '—') + '</div></div>';
                html += '</div>';
                if (data.reasoning) html += '<div class="mt-3 p-3 bg-slate-800/40 rounded text-sm">' + escapeHtml(data.reasoning) + '</div>';
            } else {
                const isAnomaly = !!data.anomaly_detected;
                html += '<div class="p-3 rounded ' + (isAnomaly ? 'bg-red-500/15 border border-red-500/40' : 'bg-emerald-500/10 border border-emerald-500/30') + '">';
                html += '<div class="font-bold ' + (isAnomaly ? 'text-red-300' : 'text-emerald-300') + '">' + (isAnomaly ? '⚠ Anomalia detectada' : '✓ Consumo normal') + '</div>';
                if (data.severity) html += '<div class="text-xs text-slate-400 mt-1">Severidad: ' + escapeHtml(data.severity) + '</div>';
                html += '</div>';
                if (data.explanation) html += '<div class="p-3 bg-slate-800/40 rounded text-sm"><strong class="text-slate-300">Explicacion:</strong> ' + escapeHtml(data.explanation) + '</div>';
                if (data.suggested_action) html += '<div class="p-3 bg-slate-800/40 rounded text-sm"><strong class="text-slate-300">Accion sugerida:</strong> ' + escapeHtml(data.suggested_action) + '</div>';
            }
            document.getElementById('aiBody').innerHTML = html;
        } catch (e) {
            document.getElementById('aiBody').innerHTML = '<div class="p-3 bg-red-500/10 border border-red-500/30 rounded text-red-300">Error de red: ' + escapeHtml(e.message) + '</div>';
        }
    }
    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    document.getElementById('movementForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const errBox = document.getElementById('movError');
        errBox.classList.add('hidden');
        const btn = document.getElementById('movSubmit');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1"></i>Procesando...';
        try {
            const r = await fetch('../api/inventory_stock.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.success) {
                errBox.textContent = j.error || 'Error';
                errBox.classList.remove('hidden');
                btn.disabled = false; btn.innerHTML = '<i class="fas fa-check mr-1"></i>Registrar';
                return;
            }
            window.location.reload();
        } catch (err) {
            errBox.textContent = 'Error de red: ' + err.message;
            errBox.classList.remove('hidden');
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-check mr-1"></i>Registrar';
        }
    });

    document.getElementById('configForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const errBox = document.getElementById('cfgError');
        errBox.classList.add('hidden');
        try {
            const r = await fetch('../api/inventory_stock.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.success) {
                errBox.textContent = j.error || 'Error';
                errBox.classList.remove('hidden');
                return;
            }
            window.location.reload();
        } catch (err) {
            errBox.textContent = 'Error de red: ' + err.message;
            errBox.classList.remove('hidden');
        }
    });

    // Close on backdrop click / Esc
    ['movementModal','configModal','aiModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m) m.addEventListener('click', e => { if (e.target === m) closeModal(id); });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') ['movementModal','configModal','aiModal'].forEach(closeModal);
    });
    </script>
</body>
</html>
