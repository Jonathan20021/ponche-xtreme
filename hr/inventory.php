<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';
require_once '../lib/inventory_functions.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$summary       = inv_get_stock_summary($pdo);
$lowStock      = inv_get_low_stock_items($pdo, 50);
$expiringLots  = inv_get_expiring_lots($pdo, 30, 50);
$recent        = inv_get_recent_movements($pdo, 15);

// Stock per category
$stockByCat = $pdo->query("
    SELECT c.id, c.name, c.icon, c.color,
           COUNT(it.id) AS items_count,
           COALESCE(SUM(it.current_stock), 0) AS total_stock,
           COALESCE(SUM(CASE WHEN it.current_stock <= 0 THEN 1 ELSE 0 END), 0) AS out_count,
           COALESCE(SUM(CASE WHEN it.min_stock > 0 AND it.current_stock <= it.min_stock AND it.current_stock > 0 THEN 1 ELSE 0 END), 0) AS low_count
    FROM inventory_categories c
    LEFT JOIN inventory_item_types it ON it.category_id = c.id AND it.is_active = 1
    GROUP BY c.id, c.name, c.icon, c.color
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

// Top movers (most active items in last 30 days)
$topMovers = $pdo->query("
    SELECT it.id, it.name, it.unit, it.current_stock, c.name AS category_name, c.icon AS category_icon,
           COUNT(m.id) AS movements_count,
           ABS(SUM(CASE WHEN m.quantity < 0 THEN m.quantity ELSE 0 END)) AS units_out_30d
    FROM inventory_item_types it
    JOIN inventory_categories c ON c.id = it.category_id
    LEFT JOIN inventory_movements m ON m.item_type_id = it.id AND m.performed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    WHERE it.is_active = 1
    GROUP BY it.id
    HAVING units_out_30d > 0
    ORDER BY units_out_30d DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$movementLabels = [
    'ENTRY'      => ['label' => 'Entrada',    'icon' => 'fa-arrow-down', 'color' => 'green'],
    'EXIT'       => ['label' => 'Salida',     'icon' => 'fa-arrow-up',   'color' => 'red'],
    'ADJUSTMENT' => ['label' => 'Ajuste',     'icon' => 'fa-sliders',    'color' => 'yellow'],
    'ASSIGN'     => ['label' => 'Asignacion', 'icon' => 'fa-user-tag',   'color' => 'blue'],
    'RETURN'     => ['label' => 'Devolucion', 'icon' => 'fa-undo',       'color' => 'cyan'],
    'LOSS'       => ['label' => 'Perdida',    'icon' => 'fa-circle-xmark', 'color' => 'red'],
    'DAMAGE'     => ['label' => 'Dano',       'icon' => 'fa-triangle-exclamation', 'color' => 'orange'],
    'TRANSFER'   => ['label' => 'Transferencia', 'icon' => 'fa-right-left', 'color' => 'purple'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .stat-card { transition: transform .2s ease, box-shadow .2s ease; }
        .stat-card:hover { transform: translateY(-2px); }
        .cat-tile { transition: all .2s ease; cursor: pointer; }
        .cat-tile:hover { transform: scale(1.02); }
        .progress-bar { height: 6px; border-radius: 4px; overflow: hidden; background: rgba(148,163,184,.15); }
        .progress-fill { height: 100%; transition: width .6s ease; }
        .mini-badge { font-size:.65rem; padding:2px 6px; border-radius:999px; }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex flex-wrap justify-between items-center gap-3 mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-warehouse text-cyan-400 mr-3"></i>
                    Inventario · Dashboard
                </h1>
                <p class="text-slate-400">Control de stock, movimientos y asistente IA</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="inventory_stock.php" class="btn-primary">
                    <i class="fas fa-boxes-stacked"></i> Catalogo de Stock
                </a>
                <a href="inventory_movements.php" class="btn-secondary">
                    <i class="fas fa-right-left"></i> Movimientos
                </a>
                <a href="inventory_ai_chat.php" class="btn-secondary" style="background:linear-gradient(135deg,#7c3aed,#0891b2);color:white;">
                    <i class="fas fa-robot"></i> Asistente IA
                </a>
                <a href="inventory_assignments.php" class="btn-secondary">
                    <i class="fas fa-users"></i> Asignaciones
                </a>
                <a href="inventory_assign.php" class="btn-secondary">
                    <i class="fas fa-user-tag"></i> Asignar
                </a>
                <a href="inventory_manage.php" class="btn-secondary">
                    <i class="fas fa-cog"></i> Items
                </a>
            </div>
        </div>

        <!-- Top Stats -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
            <div class="glass-card stat-card">
                <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">Items activos</p>
                <h3 class="text-2xl font-bold text-white"><?= number_format($summary['total_items']) ?></h3>
                <i class="fas fa-cube text-cyan-400 text-xs mt-2"></i>
            </div>
            <div class="glass-card stat-card">
                <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">Unidades totales</p>
                <h3 class="text-2xl font-bold text-white"><?= number_format($summary['total_units'], 0) ?></h3>
                <i class="fas fa-layer-group text-blue-400 text-xs mt-2"></i>
            </div>
            <a href="inventory_stock.php?filter=low" class="glass-card stat-card hover:ring-2 hover:ring-yellow-500/40 transition-all">
                <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">Stock bajo</p>
                <h3 class="text-2xl font-bold <?= $summary['low_stock'] > 0 ? 'text-yellow-400' : 'text-white' ?>"><?= number_format($summary['low_stock']) ?></h3>
                <i class="fas fa-bell text-yellow-400 text-xs mt-2"></i>
            </a>
            <a href="inventory_stock.php?filter=out" class="glass-card stat-card hover:ring-2 hover:ring-red-500/40 transition-all">
                <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">Agotados</p>
                <h3 class="text-2xl font-bold <?= $summary['out_of_stock'] > 0 ? 'text-red-400' : 'text-white' ?>"><?= number_format($summary['out_of_stock']) ?></h3>
                <i class="fas fa-circle-xmark text-red-400 text-xs mt-2"></i>
            </a>
            <a href="inventory_movements.php?filter=expiring" class="glass-card stat-card hover:ring-2 hover:ring-orange-500/40 transition-all">
                <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">Por vencer</p>
                <h3 class="text-2xl font-bold <?= $summary['expiring_soon'] > 0 ? 'text-orange-400' : 'text-white' ?>"><?= number_format($summary['expiring_soon']) ?></h3>
                <i class="fas fa-clock text-orange-400 text-xs mt-2"></i>
            </a>
            <div class="glass-card stat-card">
                <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">Valor inventario</p>
                <h3 class="text-2xl font-bold text-emerald-400">$<?= number_format($summary['total_value'], 2) ?></h3>
                <i class="fas fa-dollar-sign text-emerald-400 text-xs mt-2"></i>
            </div>
        </div>

        <!-- Categories Grid -->
        <div class="mb-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold text-white"><i class="fas fa-folder-tree text-purple-400 mr-2"></i>Categorias</h2>
                <a href="inventory_manage.php" class="text-cyan-400 hover:text-cyan-300 text-sm">Gestionar <i class="fas fa-arrow-right ml-1"></i></a>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                <?php foreach ($stockByCat as $cat): ?>
                    <a href="inventory_stock.php?category=<?= (int) $cat['id'] ?>" class="cat-tile glass-card flex items-start gap-3">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-<?= htmlspecialchars($cat['color']) ?>-500/20 text-<?= htmlspecialchars($cat['color']) ?>-400">
                            <i class="fas <?= htmlspecialchars($cat['icon'] ?: 'fa-box') ?>"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="text-white text-sm font-semibold truncate"><?= htmlspecialchars($cat['name']) ?></h4>
                            <div class="text-xs text-slate-400 mt-0.5"><?= (int) $cat['items_count'] ?> items · <?= number_format((float) $cat['total_stock'], 0) ?> u.</div>
                            <div class="flex gap-1 mt-1">
                                <?php if ($cat['out_count'] > 0): ?>
                                    <span class="mini-badge bg-red-500/20 text-red-300"><?= (int) $cat['out_count'] ?> agot.</span>
                                <?php endif; ?>
                                <?php if ($cat['low_count'] > 0): ?>
                                    <span class="mini-badge bg-yellow-500/20 text-yellow-300"><?= (int) $cat['low_count'] ?> bajo</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Two-column: Alerts + Recent movements -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Stock alerts -->
            <div class="lg:col-span-2 glass-card">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-triangle-exclamation text-yellow-400 mr-2"></i>Alertas de stock
                    </h2>
                    <button onclick="window.location.href='inventory_ai_chat.php?prompt=Dame%20un%20plan%20de%20reorden%20para%20los%20items%20en%20stock%20bajo'"
                            class="text-xs px-3 py-1 rounded-full bg-purple-500/20 text-purple-300 hover:bg-purple-500/30 transition">
                        <i class="fas fa-robot mr-1"></i> Plan de reorden IA
                    </button>
                </div>
                <?php if (empty($lowStock) && empty($expiringLots)): ?>
                    <div class="text-center py-8 text-slate-500">
                        <i class="fas fa-check-circle text-green-400 text-4xl mb-2"></i>
                        <p>Todo el inventario esta en niveles saludables.</p>
                    </div>
                <?php else: ?>
                    <?php if (!empty($lowStock)): ?>
                        <h3 class="text-sm font-semibold text-yellow-400 uppercase tracking-wide mb-2">Stock bajo (<?= count($lowStock) ?>)</h3>
                        <div class="overflow-y-auto max-h-72 mb-4">
                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-slate-700/50">
                                    <?php foreach (array_slice($lowStock, 0, 10) as $item): ?>
                                        <?php
                                            $minS = (float) $item['min_stock'];
                                            $cur  = (float) $item['current_stock'];
                                            $pct = $minS > 0 ? min(100, ($cur / $minS) * 100) : 0;
                                            $bar = $pct < 30 ? 'bg-red-500' : ($pct < 70 ? 'bg-yellow-500' : 'bg-emerald-500');
                                        ?>
                                        <tr class="hover:bg-slate-800/30">
                                            <td class="py-2 pr-2">
                                                <div class="flex items-center gap-2">
                                                    <i class="fas <?= htmlspecialchars($item['category_icon'] ?: 'fa-box') ?> text-<?= htmlspecialchars($item['category_color']) ?>-400 text-xs w-4"></i>
                                                    <div>
                                                        <div class="text-white font-medium"><?= htmlspecialchars($item['name']) ?></div>
                                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($item['category_name']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-2 px-2 w-32">
                                                <div class="text-xs text-slate-300"><?= inv_format_qty($cur, $item['unit']) ?> / <?= inv_format_qty($minS, $item['unit']) ?></div>
                                                <div class="progress-bar mt-1"><div class="progress-fill <?= $bar ?>" style="width: <?= $pct ?>%"></div></div>
                                            </td>
                                            <td class="py-2 pl-2 w-28 text-right">
                                                <a href="inventory_movements.php?item=<?= (int) $item['id'] ?>&action=entry"
                                                   class="text-xs px-3 py-1 rounded bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30">
                                                    <i class="fas fa-plus"></i> Reponer
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($expiringLots)): ?>
                        <h3 class="text-sm font-semibold text-orange-400 uppercase tracking-wide mb-2 mt-2">Lotes por vencer (<?= count($expiringLots) ?>)</h3>
                        <div class="overflow-y-auto max-h-60">
                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-slate-700/50">
                                    <?php foreach (array_slice($expiringLots, 0, 10) as $lot): ?>
                                        <?php
                                            $d = (int) $lot['days_to_expire'];
                                            $badge = $d < 0 ? 'bg-red-500/30 text-red-300' : ($d <= 7 ? 'bg-red-500/20 text-red-300' : ($d <= 15 ? 'bg-orange-500/20 text-orange-300' : 'bg-yellow-500/20 text-yellow-300'));
                                            $label = $d < 0 ? "Vencido hace " . abs($d) . "d" : ($d === 0 ? "Vence hoy" : "$d dias");
                                        ?>
                                        <tr class="hover:bg-slate-800/30">
                                            <td class="py-2 pr-2">
                                                <div class="text-white font-medium"><?= htmlspecialchars($lot['item_name']) ?></div>
                                                <div class="text-xs text-slate-500">
                                                    Lote <?= htmlspecialchars($lot['lot_code'] ?: 'sin codigo') ?> · <?= inv_format_qty((float) $lot['quantity_remaining'], $lot['unit']) ?>
                                                </div>
                                            </td>
                                            <td class="py-2 px-2 text-right">
                                                <span class="text-xs px-2 py-1 rounded-full <?= $badge ?>">
                                                    <i class="fas fa-clock mr-1"></i><?= $label ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Recent movements -->
            <div class="glass-card">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-clock-rotate-left text-cyan-400 mr-2"></i>Movimientos recientes
                    </h2>
                    <a href="inventory_movements.php" class="text-cyan-400 hover:text-cyan-300 text-xs">Ver todo <i class="fas fa-arrow-right ml-1"></i></a>
                </div>
                <?php if (empty($recent)): ?>
                    <p class="text-center text-slate-500 py-8 text-sm">Sin movimientos aun.</p>
                <?php else: ?>
                    <div class="space-y-2 overflow-y-auto max-h-[34rem]">
                        <?php foreach ($recent as $m): ?>
                            <?php
                                $info = $movementLabels[$m['movement_type']] ?? ['label' => $m['movement_type'], 'icon' => 'fa-circle', 'color' => 'slate'];
                                $qty = (float) $m['quantity'];
                                $isPos = $qty > 0;
                            ?>
                            <div class="flex items-start gap-3 py-2 border-b border-slate-700/30 last:border-0">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center bg-<?= $info['color'] ?>-500/20 text-<?= $info['color'] ?>-400 flex-shrink-0">
                                    <i class="fas <?= $info['icon'] ?> text-xs"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex justify-between items-baseline gap-2">
                                        <span class="text-white text-sm font-medium truncate"><?= htmlspecialchars($m['item_name']) ?></span>
                                        <span class="text-xs font-bold <?= $isPos ? 'text-emerald-400' : 'text-red-400' ?>">
                                            <?= $isPos ? '+' : '' ?><?= inv_format_qty($qty, $m['unit']) ?>
                                        </span>
                                    </div>
                                    <div class="text-xs text-slate-500 truncate">
                                        <?= $info['label'] ?>
                                        <?php if ($m['employee_name']): ?>
                                            · <?= htmlspecialchars($m['employee_name']) ?>
                                        <?php endif; ?>
                                        <?php if ($m['reason']): ?>
                                            · <?= htmlspecialchars($m['reason']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-xs text-slate-600 mt-0.5"><?= date('d/m H:i', strtotime($m['performed_at'])) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top movers + AI prompt -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="glass-card">
                <h2 class="text-xl font-bold text-white mb-4">
                    <i class="fas fa-fire text-orange-400 mr-2"></i>Items mas consumidos (30d)
                </h2>
                <?php if (empty($topMovers)): ?>
                    <p class="text-center text-slate-500 py-6 text-sm">Sin movimientos en los ultimos 30 dias.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php
                            $max = max(array_map(static fn($x) => (float) $x['units_out_30d'], $topMovers));
                            foreach ($topMovers as $tm):
                                $pct = $max > 0 ? ((float) $tm['units_out_30d'] / $max) * 100 : 0;
                        ?>
                            <div>
                                <div class="flex justify-between items-baseline mb-1">
                                    <span class="text-white text-sm">
                                        <i class="fas <?= htmlspecialchars($tm['category_icon']) ?> text-slate-500 text-xs mr-1"></i>
                                        <?= htmlspecialchars($tm['name']) ?>
                                    </span>
                                    <span class="text-xs text-slate-400">
                                        <strong class="text-orange-400"><?= number_format((float) $tm['units_out_30d'], 0) ?></strong> <?= htmlspecialchars($tm['unit']) ?>
                                        · stock: <?= inv_format_qty((float) $tm['current_stock'], $tm['unit']) ?>
                                    </span>
                                </div>
                                <div class="progress-bar"><div class="progress-fill bg-gradient-to-r from-orange-500 to-red-500" style="width: <?= $pct ?>%"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="glass-card relative overflow-hidden" style="background: linear-gradient(135deg, rgba(124,58,237,0.15) 0%, rgba(8,145,178,0.15) 100%);">
                <div class="absolute top-0 right-0 opacity-10 text-9xl"><i class="fas fa-robot text-white"></i></div>
                <h2 class="text-xl font-bold text-white mb-2">
                    <i class="fas fa-sparkles text-purple-400 mr-2"></i>Asistente IA de inventario
                </h2>
                <p class="text-slate-300 text-sm mb-4">Pregunta en lenguaje natural sobre el inventario. Claude conoce el stock, los movimientos y los lotes en tiempo real.</p>
                <div class="space-y-2 mb-4">
                    <button onclick="window.location.href='inventory_ai_chat.php?prompt=' + encodeURIComponent('Que items tengo en stock bajo y cuanto deberia reordenar de cada uno')"
                            class="w-full text-left text-sm px-3 py-2 bg-slate-800/60 hover:bg-slate-700/60 rounded text-slate-300 transition">
                        <i class="fas fa-chevron-right text-cyan-400 mr-2 text-xs"></i>Que reordenar esta semana?
                    </button>
                    <button onclick="window.location.href='inventory_ai_chat.php?prompt=' + encodeURIComponent('Resumeme el estado del botiquin y cuanto durara el stock al ritmo actual')"
                            class="w-full text-left text-sm px-3 py-2 bg-slate-800/60 hover:bg-slate-700/60 rounded text-slate-300 transition">
                        <i class="fas fa-chevron-right text-cyan-400 mr-2 text-xs"></i>Estado del botiquin?
                    </button>
                    <button onclick="window.location.href='inventory_ai_chat.php?prompt=' + encodeURIComponent('Hay consumo anomalo en algun item en las ultimas semanas?')"
                            class="w-full text-left text-sm px-3 py-2 bg-slate-800/60 hover:bg-slate-700/60 rounded text-slate-300 transition">
                        <i class="fas fa-chevron-right text-cyan-400 mr-2 text-xs"></i>Detectar consumo anomalo
                    </button>
                </div>
                <a href="inventory_ai_chat.php" class="btn-primary w-full text-center" style="background: linear-gradient(135deg, #7c3aed, #0891b2);">
                    <i class="fas fa-comments mr-1"></i> Abrir chat completo
                </a>
            </div>
        </div>
    </div>
</body>
</html>
