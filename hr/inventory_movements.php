<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';
require_once '../lib/inventory_functions.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$itemFilter   = (int) ($_GET['item'] ?? 0);
$typeFilter   = $_GET['type'] ?? 'all';
$fromDate     = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$toDate       = $_GET['to']   ?? date('Y-m-d');
$openAction   = $_GET['action'] ?? '';
$preselectItem = (int) ($_GET['item'] ?? 0);

$filters = [];
if ($itemFilter > 0)   $filters['item_type_id']  = $itemFilter;
if ($typeFilter !== 'all') $filters['movement_type'] = $typeFilter;
$filters['from_date'] = $fromDate;
$filters['to_date']   = $toDate;

$movements = inv_get_recent_movements($pdo, 500, $filters);

$items = $pdo->query("
    SELECT it.id, it.name, it.unit, it.current_stock, it.is_consumable, it.track_lots,
           c.name AS category_name
    FROM inventory_item_types it
    JOIN inventory_categories c ON c.id = it.category_id
    WHERE it.is_active = 1
    ORDER BY c.name, it.name
")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, name FROM inventory_suppliers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$movementLabels = [
    'ENTRY'      => ['label' => 'Entrada / Recepcion',    'icon' => 'fa-arrow-down', 'color' => 'green'],
    'EXIT'       => ['label' => 'Salida / Consumo',       'icon' => 'fa-arrow-up',   'color' => 'red'],
    'ADJUSTMENT' => ['label' => 'Ajuste manual',          'icon' => 'fa-sliders',    'color' => 'yellow'],
    'ASSIGN'     => ['label' => 'Asignacion empleado',    'icon' => 'fa-user-tag',   'color' => 'blue'],
    'RETURN'     => ['label' => 'Devolucion empleado',    'icon' => 'fa-undo',       'color' => 'cyan'],
    'LOSS'       => ['label' => 'Perdida',                'icon' => 'fa-circle-xmark', 'color' => 'red'],
    'DAMAGE'     => ['label' => 'Dano',                   'icon' => 'fa-triangle-exclamation', 'color' => 'orange'],
    'TRANSFER'   => ['label' => 'Transferencia',          'icon' => 'fa-right-left', 'color' => 'purple'],
];

// Aggregates for the period
$agg = ['entries' => 0, 'exits' => 0, 'value_in' => 0, 'value_out' => 0];
foreach ($movements as $m) {
    $q = (float) $m['quantity'];
    $cost = $m['unit_cost'] !== null ? (float) $m['unit_cost'] : 0;
    if ($q > 0) {
        $agg['entries'] += $q;
        $agg['value_in'] += $q * $cost;
    } else {
        $agg['exits'] += abs($q);
        $agg['value_out'] += abs($q) * $cost;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movimientos de Inventario</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.75); z-index: 50; backdrop-filter: blur(4px); }
        .modal-content { background: #0f172a !important; border: 1px solid #334155; box-shadow: 0 20px 25px -5px rgba(0,0,0,.5); }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-wrap justify-between items-center gap-3 mb-6">
            <div class="flex items-center gap-4">
                <a href="inventory.php" class="text-slate-400 hover:text-white"><i class="fas fa-arrow-left text-xl"></i></a>
                <div>
                    <h1 class="text-3xl font-bold text-white"><i class="fas fa-right-left text-cyan-400 mr-2"></i>Movimientos</h1>
                    <p class="text-slate-400 text-sm">Ledger de entradas, salidas y ajustes</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button onclick="openModal('entryModal')" class="btn-primary"><i class="fas fa-arrow-down"></i> Recepcion (con lote)</button>
                <button onclick="openModal('quickMovModal','ENTRY')" class="btn-secondary"><i class="fas fa-plus"></i> Entrada simple</button>
                <button onclick="openModal('quickMovModal','EXIT')" class="btn-secondary" style="background:rgba(239,68,68,.15);color:#fca5a5;"><i class="fas fa-minus"></i> Salida</button>
                <button onclick="openModal('quickMovModal','ADJUSTMENT')" class="btn-secondary"><i class="fas fa-sliders"></i> Ajuste</button>
                <a href="inventory_stock.php" class="btn-secondary"><i class="fas fa-boxes-stacked"></i> Catalogo</a>
            </div>
        </div>

        <!-- Period stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="glass-card">
                <p class="text-slate-400 text-xs uppercase">Entradas (unidades)</p>
                <h3 class="text-2xl font-bold text-emerald-400">+<?= number_format($agg['entries'], 2) ?></h3>
            </div>
            <div class="glass-card">
                <p class="text-slate-400 text-xs uppercase">Salidas (unidades)</p>
                <h3 class="text-2xl font-bold text-red-400">-<?= number_format($agg['exits'], 2) ?></h3>
            </div>
            <div class="glass-card">
                <p class="text-slate-400 text-xs uppercase">Valor entradas</p>
                <h3 class="text-xl font-bold text-emerald-400">$<?= number_format($agg['value_in'], 2) ?></h3>
            </div>
            <div class="glass-card">
                <p class="text-slate-400 text-xs uppercase">Valor salidas</p>
                <h3 class="text-xl font-bold text-red-400">$<?= number_format($agg['value_out'], 2) ?></h3>
            </div>
        </div>

        <!-- Filters -->
        <div class="glass-card mb-6">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div class="form-group">
                    <label>Item</label>
                    <select name="item" class="min-w-[200px]">
                        <option value="0">Todos</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= (int) $it['id'] ?>" <?= $itemFilter === (int) $it['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($it['name']) ?> (<?= htmlspecialchars($it['category_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tipo</label>
                    <select name="type">
                        <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>Todos</option>
                        <?php foreach ($movementLabels as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $typeFilter === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Desde</label>
                    <input type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>">
                </div>
                <div class="form-group">
                    <label>Hasta</label>
                    <input type="date" name="to" value="<?= htmlspecialchars($toDate) ?>">
                </div>
                <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                <a href="inventory_movements.php" class="btn-secondary">Limpiar</a>
            </form>
        </div>

        <!-- Movements table -->
        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="text-left text-slate-400 border-b border-slate-700 text-xs uppercase">
                        <tr>
                            <th class="p-3">Fecha</th>
                            <th class="p-3">Tipo</th>
                            <th class="p-3">Item</th>
                            <th class="p-3 text-right">Cantidad</th>
                            <th class="p-3">Empleado / Razon</th>
                            <th class="p-3">Referencia</th>
                            <th class="p-3">Usuario</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/40">
                    <?php if (empty($movements)): ?>
                        <tr><td colspan="7" class="p-8 text-center text-slate-500">Sin movimientos en el periodo seleccionado.</td></tr>
                    <?php else: foreach ($movements as $m): ?>
                        <?php
                            $info = $movementLabels[$m['movement_type']] ?? ['label' => $m['movement_type'], 'icon' => 'fa-circle', 'color' => 'slate'];
                            $q = (float) $m['quantity'];
                            $isPos = $q > 0;
                        ?>
                        <tr class="hover:bg-slate-800/40">
                            <td class="p-3 text-xs text-slate-400 whitespace-nowrap"><?= date('d/m/Y H:i', strtotime($m['performed_at'])) ?></td>
                            <td class="p-3">
                                <span class="text-xs px-2 py-1 rounded-full bg-<?= $info['color'] ?>-500/20 text-<?= $info['color'] ?>-300">
                                    <i class="fas <?= $info['icon'] ?> mr-1"></i><?= $info['label'] ?>
                                </span>
                            </td>
                            <td class="p-3">
                                <div class="text-white text-sm font-medium"><?= htmlspecialchars($m['item_name']) ?></div>
                                <div class="text-xs text-slate-500"><?= htmlspecialchars($m['category_name']) ?></div>
                            </td>
                            <td class="p-3 text-right whitespace-nowrap">
                                <span class="text-lg font-bold <?= $isPos ? 'text-emerald-400' : 'text-red-400' ?>">
                                    <?= $isPos ? '+' : '' ?><?= inv_format_qty($q) ?>
                                </span>
                                <div class="text-xs text-slate-500"><?= htmlspecialchars($m['unit']) ?></div>
                                <?php if ($m['unit_cost']): ?>
                                    <div class="text-xs text-slate-600">$<?= number_format((float) $m['unit_cost'], 2) ?> c/u</div>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-sm text-slate-300">
                                <?php if ($m['employee_name']): ?>
                                    <div class="text-cyan-300"><i class="fas fa-user mr-1"></i><?= htmlspecialchars($m['employee_name']) ?></div>
                                <?php endif; ?>
                                <?php if ($m['reason']): ?>
                                    <div class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars($m['reason']) ?></div>
                                <?php endif; ?>
                                <?php if ($m['notes']): ?>
                                    <div class="text-xs text-slate-500 italic mt-0.5"><?= htmlspecialchars($m['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-xs text-slate-400"><?= htmlspecialchars($m['reference'] ?? '') ?: '—' ?></td>
                            <td class="p-3 text-xs text-slate-400"><?= htmlspecialchars($m['performed_by_name'] ?? 'N/A') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recepción con lote (entry) modal -->
    <div id="entryModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-xl relative max-h-[90vh] overflow-y-auto">
            <button onclick="closeModal('entryModal')" class="absolute top-4 right-4 text-slate-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            <h3 class="text-xl font-bold text-white mb-4"><i class="fas fa-truck-loading text-emerald-400 mr-2"></i>Registrar recepcion / nuevo lote</h3>

            <form id="entryForm">
                <input type="hidden" name="action" value="register_lot">
                <div class="form-group mb-3">
                    <label>Item *</label>
                    <select name="item_type_id" required class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                        <option value="">— Selecciona un item —</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= (int) $it['id'] ?>" data-track-lots="<?= (int) $it['track_lots'] ?>" <?= $preselectItem === (int) $it['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($it['name']) ?> (<?= htmlspecialchars($it['category_name']) ?>) · stock <?= inv_format_qty((float) $it['current_stock'], $it['unit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="form-group">
                        <label>Cantidad *</label>
                        <input type="number" step="0.01" min="0.01" name="quantity" required class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label>Costo unitario ($)</label>
                        <input type="number" step="0.01" min="0" name="unit_cost" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label>Codigo de lote</label>
                        <input type="text" name="lot_code" placeholder="Ej: LOT-2026-A" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label>Fecha recibido</label>
                        <input type="date" name="received_date" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label>Fecha vencimiento</label>
                        <input type="date" name="expiration_date" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                    </div>
                    <div class="form-group">
                        <label>Proveedor</label>
                        <select name="supplier_id" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                            <option value="">— Sin especificar —</option>
                            <?php foreach ($suppliers as $sp): ?>
                                <option value="<?= (int) $sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group mt-3">
                    <label>Referencia (factura, OC)</label>
                    <input type="text" name="reference" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                </div>
                <div class="form-group mt-3">
                    <label>Notas</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2"></textarea>
                </div>
                <div id="entryError" class="hidden mt-3 p-3 bg-red-500/10 border border-red-500/30 rounded text-red-300 text-sm"></div>
                <div class="flex justify-end gap-2 mt-5">
                    <button type="button" onclick="closeModal('entryModal')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-check mr-1"></i>Registrar recepcion</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick movement modal -->
    <div id="quickMovModal" class="modal-backdrop hidden items-center justify-center" style="display:none;">
        <div class="modal-content rounded-xl p-6 w-full max-w-lg relative">
            <button onclick="closeModal('quickMovModal')" class="absolute top-4 right-4 text-slate-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            <h3 id="quickTitle" class="text-xl font-bold text-white mb-4"></h3>
            <form id="quickMovForm">
                <input type="hidden" name="action" id="quickAction">
                <input type="hidden" name="movement_type" id="quickType">
                <div class="form-group mb-3">
                    <label>Item *</label>
                    <select name="item_type_id" required class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                        <option value="">— Selecciona un item —</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= (int) $it['id'] ?>">
                                <?= htmlspecialchars($it['name']) ?> · stock <?= inv_format_qty((float) $it['current_stock'], $it['unit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mb-3">
                    <label>Cantidad <span id="quickHint" class="text-xs text-slate-500"></span></label>
                    <input type="number" step="0.01" id="quickQty" name="quantity" required class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                </div>
                <div class="form-group mb-3">
                    <label>Razon</label>
                    <input type="text" name="reason" placeholder="Motivo del movimiento" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2">
                </div>
                <div class="form-group mb-3">
                    <label>Notas</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-800 border border-slate-700 rounded text-white p-2"></textarea>
                </div>
                <div id="quickError" class="hidden mt-3 p-3 bg-red-500/10 border border-red-500/30 rounded text-red-300 text-sm"></div>
                <div class="flex justify-end gap-2 mt-5">
                    <button type="button" onclick="closeModal('quickMovModal')" class="btn-secondary">Cancelar</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-check mr-1"></i>Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openModal(id, kind) {
        const m = document.getElementById(id);
        if (!m) return;
        m.style.display = 'flex';
        m.classList.remove('hidden');
        if (id === 'quickMovModal' && kind) {
            const titles = {
                'ENTRY':      { title: '<i class="fas fa-arrow-down text-emerald-400 mr-2"></i>Entrada de stock',  action: 'record_movement', hint: '(positiva)',     min: '0.01', name: 'quantity'        },
                'EXIT':       { title: '<i class="fas fa-arrow-up text-red-400 mr-2"></i>Salida de stock',         action: 'record_movement', hint: '(positiva)',     min: '0.01', name: 'quantity'        },
                'ADJUSTMENT': { title: '<i class="fas fa-sliders text-yellow-400 mr-2"></i>Ajuste manual',          action: 'adjust',          hint: '(+/-)',          min: '',     name: 'signed_quantity' },
            };
            const cfg = titles[kind] || titles['EXIT'];
            document.getElementById('quickTitle').innerHTML = cfg.title;
            document.getElementById('quickAction').value = cfg.action;
            document.getElementById('quickType').value = kind;
            document.getElementById('quickHint').textContent = cfg.hint;
            const qty = document.getElementById('quickQty');
            qty.name = cfg.name;
            if (cfg.min) qty.setAttribute('min', cfg.min); else qty.removeAttribute('min');
            qty.value = '';
        }
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.style.display = 'none';
        m.classList.add('hidden');
        const err = m.querySelector('[id$="Error"]'); if (err) err.classList.add('hidden');
    }

    async function submitForm(form, errId) {
        const fd = new FormData(form);
        const errBox = document.getElementById(errId);
        errBox.classList.add('hidden');
        try {
            const r = await fetch('../api/inventory_stock.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.success) {
                errBox.textContent = j.error || 'Error';
                errBox.classList.remove('hidden');
                return false;
            }
            window.location.reload();
            return true;
        } catch (e) {
            errBox.textContent = 'Error de red: ' + e.message;
            errBox.classList.remove('hidden');
            return false;
        }
    }

    document.getElementById('entryForm').addEventListener('submit', e => { e.preventDefault(); submitForm(e.target, 'entryError'); });
    document.getElementById('quickMovForm').addEventListener('submit', e => { e.preventDefault(); submitForm(e.target, 'quickError'); });

    ['entryModal','quickMovModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m) m.addEventListener('click', e => { if (e.target === m) closeModal(id); });
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') ['entryModal','quickMovModal'].forEach(closeModal); });

    <?php if ($openAction === 'entry'): ?>
    document.addEventListener('DOMContentLoaded', () => openModal('entryModal'));
    <?php endif; ?>
    </script>
</body>
</html>
