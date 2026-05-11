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

$stockByCat = $pdo->query("
    SELECT c.id, c.name, c.icon, c.color,
           COUNT(it.id) AS items_count,
           COALESCE(SUM(it.current_stock), 0) AS total_stock,
           COALESCE(SUM(CASE WHEN it.current_stock <= 0 THEN 1 ELSE 0 END), 0) AS out_count,
           COALESCE(SUM(CASE WHEN it.min_stock > 0 AND it.current_stock <= it.min_stock AND it.current_stock > 0 THEN 1 ELSE 0 END), 0) AS low_count,
           COALESCE(SUM(it.current_stock * COALESCE(it.unit_cost, 0)), 0) AS total_value
    FROM inventory_categories c
    LEFT JOIN inventory_item_types it ON it.category_id = c.id AND it.is_active = 1
    GROUP BY c.id, c.name, c.icon, c.color
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

$topMovers = $pdo->query("
    SELECT it.id, it.name, it.unit, it.current_stock, c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
           COUNT(m.id) AS movements_count,
           ABS(SUM(CASE WHEN m.quantity < 0 THEN m.quantity ELSE 0 END)) AS units_out_30d
    FROM inventory_item_types it
    JOIN inventory_categories c ON c.id = it.category_id
    LEFT JOIN inventory_movements m ON m.item_type_id = it.id AND m.performed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    WHERE it.is_active = 1
    GROUP BY it.id
    HAVING units_out_30d > 0
    ORDER BY units_out_30d DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$movementLabels = [
    'ENTRY'      => ['label' => 'Entrada',       'icon' => 'fa-arrow-down',           'grad' => 'linear-gradient(135deg,#10b981,#059669)'],
    'EXIT'       => ['label' => 'Salida',        'icon' => 'fa-arrow-up',             'grad' => 'linear-gradient(135deg,#ef4444,#dc2626)'],
    'ADJUSTMENT' => ['label' => 'Ajuste',        'icon' => 'fa-sliders',              'grad' => 'linear-gradient(135deg,#f59e0b,#d97706)'],
    'ASSIGN'     => ['label' => 'Asignacion',    'icon' => 'fa-user-tag',             'grad' => 'linear-gradient(135deg,#3b82f6,#1d4ed8)'],
    'RETURN'     => ['label' => 'Devolucion',    'icon' => 'fa-undo',                 'grad' => 'linear-gradient(135deg,#06b6d4,#0891b2)'],
    'LOSS'       => ['label' => 'Perdida',       'icon' => 'fa-circle-xmark',         'grad' => 'linear-gradient(135deg,#ef4444,#991b1b)'],
    'DAMAGE'     => ['label' => 'Dano',          'icon' => 'fa-triangle-exclamation', 'grad' => 'linear-gradient(135deg,#f97316,#c2410c)'],
    'TRANSFER'   => ['label' => 'Transferencia', 'icon' => 'fa-right-left',           'grad' => 'linear-gradient(135deg,#a855f7,#7e22ce)'],
];

// Per-category color → gradient map (for visual richness)
$colorGrad = [
    'amber'   => ['from'=>'#f59e0b','to'=>'#d97706','glow'=>'rgba(245,158,11,.35)'],
    'blue'    => ['from'=>'#3b82f6','to'=>'#1d4ed8','glow'=>'rgba(59,130,246,.35)'],
    'orange'  => ['from'=>'#f97316','to'=>'#c2410c','glow'=>'rgba(249,115,22,.35)'],
    'yellow'  => ['from'=>'#eab308','to'=>'#a16207','glow'=>'rgba(234,179,8,.35)'],
    'emerald' => ['from'=>'#10b981','to'=>'#047857','glow'=>'rgba(16,185,129,.35)'],
    'red'     => ['from'=>'#ef4444','to'=>'#991b1b','glow'=>'rgba(239,68,68,.35)'],
    'slate'   => ['from'=>'#64748b','to'=>'#334155','glow'=>'rgba(100,116,139,.35)'],
    'cyan'    => ['from'=>'#06b6d4','to'=>'#0e7490','glow'=>'rgba(6,182,212,.35)'],
    'purple'  => ['from'=>'#a855f7','to'=>'#7e22ce','glow'=>'rgba(168,85,247,.35)'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario · Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        :root {
            --bg-card: rgba(15, 23, 42, 0.55);
            --bg-card-hover: rgba(15, 23, 42, 0.75);
            --border-soft: rgba(148, 163, 184, 0.12);
            --border-strong: rgba(148, 163, 184, 0.25);
            --text-muted: #94a3b8;
        }

        body { font-family: 'Inter', sans-serif; }

        /* Animated radial backdrop behind the page */
        .inv-bg {
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background:
                radial-gradient(ellipse 800px 400px at 10% -10%, rgba(124,58,237,.18), transparent 50%),
                radial-gradient(ellipse 600px 500px at 95% 0%, rgba(8,145,178,.15), transparent 55%),
                radial-gradient(ellipse 700px 400px at 50% 110%, rgba(168,85,247,.10), transparent 50%);
        }

        .num-mono { font-family: 'JetBrains Mono', monospace; letter-spacing: -0.02em; }

        /* ---------- HERO ---------- */
        .inv-hero {
            background: linear-gradient(135deg, rgba(124,58,237,.22) 0%, rgba(15,23,42,.4) 40%, rgba(8,145,178,.22) 100%);
            border: 1px solid var(--border-strong);
            border-radius: 1.25rem;
            padding: 1.75rem 2rem;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(12px);
        }
        .inv-hero::before {
            content: '';
            position: absolute; top: -50%; right: -10%;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(124,58,237,.25), transparent 60%);
            pointer-events: none;
        }
        .inv-hero h1 {
            font-size: 2.2rem; font-weight: 800;
            background: linear-gradient(135deg, #f1f5f9, #94a3b8);
            -webkit-background-clip: text; background-clip: text; color: transparent;
            line-height: 1.1; letter-spacing: -0.03em;
        }
        .inv-hero .pulse-dot {
            display: inline-block; width: 8px; height: 8px; border-radius: 50%;
            background: #10b981; margin-right: .5rem;
            box-shadow: 0 0 0 0 rgba(16,185,129,.7); animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0   rgba(16,185,129,.6); }
            70%  { box-shadow: 0 0 0 8px rgba(16,185,129,0); }
            100% { box-shadow: 0 0 0 0   rgba(16,185,129,0); }
        }

        /* ---------- ACTION BUTTONS (top nav) ---------- */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: .75rem;
        }
        .action-btn {
            position: relative;
            display: flex; flex-direction: column; align-items: flex-start; justify-content: space-between;
            gap: .5rem;
            padding: 1rem 1.1rem;
            border-radius: .9rem;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid var(--border-strong);
            color: #e2e8f0;
            text-decoration: none;
            transition: all .2s ease;
            overflow: hidden;
            min-height: 90px;
        }
        .action-btn::before {
            content: '';
            position: absolute; inset: 0;
            background: var(--btn-grad, linear-gradient(135deg, rgba(8,145,178,.25), rgba(124,58,237,.15)));
            opacity: 0;
            transition: opacity .25s ease;
            z-index: 0;
        }
        .action-btn > * { position: relative; z-index: 1; }
        .action-btn:hover {
            transform: translateY(-3px);
            border-color: var(--btn-border, rgba(8,145,178,.6));
            box-shadow: 0 12px 30px -10px var(--btn-glow, rgba(8,145,178,.45));
            color: white;
        }
        .action-btn:hover::before { opacity: 1; }
        .action-btn .ico {
            width: 38px; height: 38px; border-radius: .65rem;
            background: var(--btn-grad, linear-gradient(135deg, #0891b2, #7c3aed));
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.05rem;
            box-shadow: 0 6px 18px -4px var(--btn-glow, rgba(8,145,178,.5));
        }
        .action-btn .lbl { font-weight: 600; font-size: .92rem; }
        .action-btn .sub { font-size: .7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; font-weight: 500; }

        .action-btn[data-variant="ai"] {
            background: linear-gradient(135deg, rgba(124,58,237,.25), rgba(8,145,178,.22));
            border-color: rgba(124,58,237,.5);
        }
        .action-btn[data-variant="ai"] .ico { background: linear-gradient(135deg, #a855f7, #06b6d4); }
        .action-btn[data-variant="ai"]:hover { border-color: rgba(168,85,247,.7); box-shadow: 0 12px 30px -10px rgba(168,85,247,.5); }

        /* ---------- STAT CARDS ---------- */
        .stat-card {
            position: relative;
            padding: 1.25rem 1.25rem;
            border-radius: 1rem;
            background: linear-gradient(135deg, rgba(15,23,42,.7), rgba(15,23,42,.45));
            border: 1px solid var(--border-soft);
            overflow: hidden;
            transition: all .25s ease;
            backdrop-filter: blur(8px);
        }
        .stat-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
            background: var(--accent, linear-gradient(90deg, #06b6d4, #7c3aed));
            opacity: .8;
        }
        .stat-card::after {
            content: ''; position: absolute;
            bottom: -30px; right: -30px;
            width: 120px; height: 120px; border-radius: 50%;
            background: var(--accent-glow, radial-gradient(circle, rgba(6,182,212,.15), transparent 70%));
            pointer-events: none;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            border-color: var(--accent-border, rgba(6,182,212,.4));
            box-shadow: 0 20px 40px -15px rgba(0,0,0,.5);
        }
        .stat-card .stat-icon {
            position: absolute; top: 1rem; right: 1rem;
            width: 38px; height: 38px; border-radius: .6rem;
            display: flex; align-items: center; justify-content: center;
            background: var(--accent-icon-bg, rgba(6,182,212,.15));
            color: var(--accent-icon, #67e8f9);
            font-size: .95rem;
        }
        .stat-label {
            font-size: .68rem; font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase; letter-spacing: .08em;
            margin-bottom: .5rem;
        }
        .stat-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 2rem; font-weight: 700;
            line-height: 1; letter-spacing: -0.04em;
            color: white;
        }
        .stat-value.danger  { background: linear-gradient(135deg, #fca5a5, #ef4444); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .stat-value.warning { background: linear-gradient(135deg, #fcd34d, #f59e0b); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .stat-value.success { background: linear-gradient(135deg, #6ee7b7, #10b981); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .stat-trend {
            margin-top: .6rem;
            font-size: .72rem; color: var(--text-muted);
            display: flex; align-items: center; gap: .35rem;
        }

        /* ---------- CATEGORY TILE ---------- */
        .cat-tile {
            position: relative; padding: 1.1rem;
            border-radius: .9rem;
            background: linear-gradient(135deg, rgba(15,23,42,.7), rgba(15,23,42,.4));
            border: 1px solid var(--border-soft);
            text-decoration: none;
            transition: all .25s ease;
            display: flex; gap: .85rem; align-items: flex-start;
            overflow: hidden;
        }
        .cat-tile::before {
            content: ''; position: absolute;
            inset: 0; border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, var(--cat-from), transparent 50%);
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor; mask-composite: exclude;
            opacity: .4; transition: opacity .25s ease;
            pointer-events: none;
        }
        .cat-tile:hover {
            transform: translateY(-3px);
            background: linear-gradient(135deg, rgba(15,23,42,.85), rgba(15,23,42,.55));
            box-shadow: 0 14px 30px -12px var(--cat-glow);
        }
        .cat-tile:hover::before { opacity: 1; }
        .cat-tile .cat-ico {
            flex-shrink: 0;
            width: 44px; height: 44px; border-radius: .7rem;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--cat-from), var(--cat-to));
            color: white; font-size: 1.05rem;
            box-shadow: 0 6px 16px -4px var(--cat-glow);
        }
        .cat-tile h4 { color: white; font-weight: 600; font-size: .92rem; margin-bottom: .15rem; }
        .cat-tile .cat-meta { font-size: .72rem; color: var(--text-muted); }
        .cat-tile .cat-badges { display: flex; gap: .25rem; margin-top: .4rem; flex-wrap: wrap; }
        .mini-badge {
            font-size: .62rem; padding: 2px 7px; border-radius: 999px;
            font-weight: 600; letter-spacing: .02em;
        }
        .mb-out  { background: rgba(239,68,68,.15);  color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
        .mb-low  { background: rgba(234,179,8,.15);  color: #fde047; border: 1px solid rgba(234,179,8,.3); }
        .mb-ok   { background: rgba(16,185,129,.15); color: #6ee7b7; border: 1px solid rgba(16,185,129,.3); }

        /* ---------- PANEL HEADERS ---------- */
        .panel {
            background: linear-gradient(135deg, rgba(15,23,42,.7), rgba(15,23,42,.45));
            border: 1px solid var(--border-soft);
            border-radius: 1.1rem;
            padding: 1.5rem;
            backdrop-filter: blur(8px);
        }
        .panel-title { font-size: 1.05rem; font-weight: 700; color: white; display: flex; align-items: center; gap: .65rem; }
        .panel-title .dot {
            width: 6px; height: 24px; border-radius: 3px;
            background: var(--title-accent, linear-gradient(180deg, #06b6d4, #7c3aed));
        }

        .progress-bar { height: 6px; border-radius: 4px; overflow: hidden; background: rgba(148,163,184,.12); }
        .progress-fill { height: 100%; transition: width .6s ease; border-radius: 4px; }
        .pf-red   { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .pf-yel   { background: linear-gradient(90deg, #fcd34d, #f59e0b); }
        .pf-grn   { background: linear-gradient(90deg, #34d399, #10b981); }
        .pf-orng  { background: linear-gradient(90deg, #fb923c, #ea580c); }

        /* ---------- MOVEMENT FEED ---------- */
        .mov-item {
            display: flex; gap: .85rem; padding: .65rem 0;
            border-bottom: 1px solid rgba(148,163,184,.07);
        }
        .mov-item:last-child { border-bottom: 0; }
        .mov-ico {
            flex-shrink: 0;
            width: 34px; height: 34px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: .8rem;
        }

        /* ---------- AI PANEL ---------- */
        .ai-panel {
            position: relative;
            background:
                radial-gradient(ellipse 400px 200px at 10% 10%, rgba(168,85,247,.22), transparent 60%),
                radial-gradient(ellipse 400px 250px at 95% 90%, rgba(6,182,212,.18), transparent 60%),
                linear-gradient(135deg, rgba(15,23,42,.85), rgba(15,23,42,.55));
            border: 1px solid rgba(168,85,247,.3);
            border-radius: 1.1rem;
            padding: 1.5rem;
            overflow: hidden;
        }
        .ai-panel .robot-bg {
            position: absolute; right: -20px; bottom: -30px;
            font-size: 11rem;
            color: rgba(168,85,247,.06);
            pointer-events: none;
            transform: rotate(-12deg);
        }
        .ai-suggestion {
            display: flex; align-items: center; gap: .7rem;
            padding: .7rem .9rem;
            background: rgba(15,23,42,.6);
            border: 1px solid rgba(148,163,184,.15);
            border-radius: .7rem;
            color: #cbd5e1;
            text-align: left;
            width: 100%;
            font-size: .87rem;
            transition: all .2s ease;
            cursor: pointer;
        }
        .ai-suggestion:hover {
            background: rgba(15,23,42,.85);
            border-color: rgba(168,85,247,.45);
            color: white;
            transform: translateX(3px);
        }
        .ai-suggestion .chev { color: #a855f7; font-size: .75rem; transition: transform .2s; }
        .ai-suggestion:hover .chev { transform: translateX(3px); color: #06b6d4; }

        .btn-ai {
            background: linear-gradient(135deg, #a855f7, #06b6d4);
            color: white; font-weight: 600;
            padding: .75rem 1.25rem;
            border-radius: .75rem;
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            transition: all .2s ease;
            box-shadow: 0 8px 24px -8px rgba(168,85,247,.45);
            text-decoration: none;
        }
        .btn-ai:hover { transform: translateY(-2px); box-shadow: 0 14px 32px -8px rgba(168,85,247,.65); color: white; }

        .btn-ai-pill {
            background: rgba(168,85,247,.15);
            color: #d8b4fe;
            border: 1px solid rgba(168,85,247,.3);
            font-size: .75rem; font-weight: 600;
            padding: .35rem .85rem;
            border-radius: 999px;
            transition: all .2s ease;
            cursor: pointer;
        }
        .btn-ai-pill:hover { background: rgba(168,85,247,.3); color: white; }

        /* Alerts table rows */
        .alert-row {
            padding: .65rem 0;
            border-bottom: 1px solid rgba(148,163,184,.07);
        }
        .alert-row:last-child { border-bottom: 0; }

        .replenish-btn {
            display: inline-flex; align-items: center; gap: .35rem;
            background: linear-gradient(135deg, rgba(16,185,129,.25), rgba(5,150,105,.15));
            color: #6ee7b7;
            border: 1px solid rgba(16,185,129,.35);
            font-size: .72rem; font-weight: 600;
            padding: .35rem .75rem;
            border-radius: 999px;
            text-decoration: none;
            transition: all .2s ease;
        }
        .replenish-btn:hover { background: linear-gradient(135deg, rgba(16,185,129,.4), rgba(5,150,105,.25)); color: white; }

        @media (max-width: 768px) {
            .inv-hero h1 { font-size: 1.6rem; }
            .stat-value { font-size: 1.6rem; }
        }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <div class="inv-bg"></div>
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-6">

        <!-- HERO -->
        <div class="inv-hero mb-6">
            <div class="flex flex-wrap items-start justify-between gap-6 relative">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-widest text-purple-300/80">
                            <span class="pulse-dot"></span> Sistema en linea
                        </span>
                        <span class="text-xs text-slate-500">·</span>
                        <span class="text-xs text-slate-400"><?= date('d M Y · H:i') ?></span>
                    </div>
                    <h1>Inventario</h1>
                    <p class="text-slate-300 mt-2 max-w-xl">Control de stock en tiempo real, movimientos completos y asistente IA con Claude.</p>
                </div>
                <div class="text-right">
                    <div class="text-xs uppercase tracking-widest text-slate-400 mb-1">Valor total</div>
                    <div class="num-mono text-4xl font-bold" style="background: linear-gradient(135deg, #10b981, #06b6d4); -webkit-background-clip: text; background-clip: text; color: transparent;">
                        $<?= number_format($summary['total_value'], 2) ?>
                    </div>
                    <div class="text-xs text-slate-400 mt-1"><?= number_format($summary['total_units'], 0) ?> unidades en stock</div>
                </div>
            </div>
        </div>

        <!-- ACTION BUTTONS -->
        <div class="action-grid mb-8">
            <a href="inventory_stock.php" class="action-btn" style="--btn-grad: linear-gradient(135deg,#06b6d4,#0e7490); --btn-glow: rgba(6,182,212,.45); --btn-border: rgba(6,182,212,.6);">
                <div class="flex items-start justify-between w-full">
                    <div class="ico"><i class="fas fa-boxes-stacked"></i></div>
                </div>
                <div>
                    <div class="lbl">Catalogo</div>
                    <div class="sub">Stock por item</div>
                </div>
            </a>
            <a href="inventory_movements.php" class="action-btn" style="--btn-grad: linear-gradient(135deg,#3b82f6,#1d4ed8); --btn-glow: rgba(59,130,246,.45); --btn-border: rgba(59,130,246,.6);">
                <div class="ico"><i class="fas fa-right-left"></i></div>
                <div>
                    <div class="lbl">Movimientos</div>
                    <div class="sub">Entradas y salidas</div>
                </div>
            </a>
            <a href="inventory_ai_chat.php" class="action-btn" data-variant="ai">
                <div class="ico"><i class="fas fa-robot"></i></div>
                <div>
                    <div class="lbl">Asistente IA</div>
                    <div class="sub">Claude · pregunta</div>
                </div>
            </a>
            <a href="inventory_assignments.php" class="action-btn" style="--btn-grad: linear-gradient(135deg,#a855f7,#7e22ce); --btn-glow: rgba(168,85,247,.45); --btn-border: rgba(168,85,247,.6);">
                <div class="ico"><i class="fas fa-users"></i></div>
                <div>
                    <div class="lbl">Asignaciones</div>
                    <div class="sub">Por empleado</div>
                </div>
            </a>
            <a href="inventory_assign.php" class="action-btn" style="--btn-grad: linear-gradient(135deg,#10b981,#047857); --btn-glow: rgba(16,185,129,.45); --btn-border: rgba(16,185,129,.6);">
                <div class="ico"><i class="fas fa-user-tag"></i></div>
                <div>
                    <div class="lbl">Asignar</div>
                    <div class="sub">Entrega rapida</div>
                </div>
            </a>
            <a href="inventory_manage.php" class="action-btn" style="--btn-grad: linear-gradient(135deg,#f59e0b,#d97706); --btn-glow: rgba(245,158,11,.45); --btn-border: rgba(245,158,11,.6);">
                <div class="ico"><i class="fas fa-cog"></i></div>
                <div>
                    <div class="lbl">Items</div>
                    <div class="sub">Configurar</div>
                </div>
            </a>
        </div>

        <!-- STAT CARDS -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
            <div class="stat-card" style="--accent: linear-gradient(90deg, #06b6d4, #0891b2); --accent-glow: radial-gradient(circle, rgba(6,182,212,.15), transparent 70%); --accent-border: rgba(6,182,212,.4); --accent-icon-bg: rgba(6,182,212,.15); --accent-icon: #67e8f9;">
                <div class="stat-icon"><i class="fas fa-cube"></i></div>
                <div class="stat-label">Items activos</div>
                <div class="stat-value"><?= number_format($summary['total_items']) ?></div>
                <div class="stat-trend"><i class="fas fa-layer-group"></i> <?= number_format($summary['total_units'], 0) ?> unidades</div>
            </div>

            <a href="inventory_stock.php?filter=low" class="stat-card" style="--accent: linear-gradient(90deg, #f59e0b, #d97706); --accent-glow: radial-gradient(circle, rgba(245,158,11,.2), transparent 70%); --accent-border: rgba(245,158,11,.5); --accent-icon-bg: rgba(245,158,11,.15); --accent-icon: #fde047;">
                <div class="stat-icon"><i class="fas fa-bell"></i></div>
                <div class="stat-label">Stock bajo</div>
                <div class="stat-value <?= $summary['low_stock'] > 0 ? 'warning' : '' ?>"><?= number_format($summary['low_stock']) ?></div>
                <div class="stat-trend"><?= $summary['low_stock'] > 0 ? '<i class="fas fa-arrow-trend-down text-yellow-400"></i> Reabastecer pronto' : '<i class="fas fa-check text-emerald-400"></i> Sin alertas' ?></div>
            </a>

            <a href="inventory_stock.php?filter=out" class="stat-card" style="--accent: linear-gradient(90deg, #ef4444, #dc2626); --accent-glow: radial-gradient(circle, rgba(239,68,68,.2), transparent 70%); --accent-border: rgba(239,68,68,.5); --accent-icon-bg: rgba(239,68,68,.15); --accent-icon: #fca5a5;">
                <div class="stat-icon"><i class="fas fa-circle-xmark"></i></div>
                <div class="stat-label">Agotados</div>
                <div class="stat-value <?= $summary['out_of_stock'] > 0 ? 'danger' : '' ?>"><?= number_format($summary['out_of_stock']) ?></div>
                <div class="stat-trend"><?= $summary['out_of_stock'] > 0 ? '<i class="fas fa-triangle-exclamation text-red-400"></i> Requieren atencion' : '<i class="fas fa-check text-emerald-400"></i> Todo en stock' ?></div>
            </a>

            <a href="inventory_movements.php?filter=expiring" class="stat-card" style="--accent: linear-gradient(90deg, #f97316, #c2410c); --accent-glow: radial-gradient(circle, rgba(249,115,22,.2), transparent 70%); --accent-border: rgba(249,115,22,.5); --accent-icon-bg: rgba(249,115,22,.15); --accent-icon: #fdba74;">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Por vencer (30d)</div>
                <div class="stat-value <?= $summary['expiring_soon'] > 0 ? 'warning' : '' ?>"><?= number_format($summary['expiring_soon']) ?></div>
                <div class="stat-trend"><i class="fas fa-flask"></i> Lotes con vencimiento</div>
            </a>

            <div class="stat-card" style="--accent: linear-gradient(90deg, #10b981, #047857); --accent-glow: radial-gradient(circle, rgba(16,185,129,.2), transparent 70%); --accent-border: rgba(16,185,129,.4); --accent-icon-bg: rgba(16,185,129,.15); --accent-icon: #6ee7b7;">
                <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-label">Valor inventario</div>
                <div class="stat-value success" style="font-size:1.55rem;">$<?= number_format($summary['total_value'], 0) ?></div>
                <div class="stat-trend"><i class="fas fa-chart-line"></i> Costo total en stock</div>
            </div>
        </div>

        <!-- CATEGORIES -->
        <div class="mb-8">
            <div class="flex justify-between items-end mb-4">
                <div>
                    <h2 class="panel-title"><span class="dot"></span> Categorias</h2>
                    <p class="text-xs text-slate-500 mt-1 ml-4">Stock agrupado por tipo de inventario</p>
                </div>
                <a href="inventory_manage.php" class="text-xs text-cyan-400 hover:text-cyan-300 font-semibold flex items-center gap-1.5">
                    <i class="fas fa-gear text-xs"></i> Gestionar categorias <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                <?php foreach ($stockByCat as $cat):
                    $grad = $colorGrad[$cat['color']] ?? $colorGrad['slate'];
                ?>
                    <a href="inventory_stock.php?category=<?= (int) $cat['id'] ?>" class="cat-tile"
                       style="--cat-from: <?= $grad['from'] ?>; --cat-to: <?= $grad['to'] ?>; --cat-glow: <?= $grad['glow'] ?>;">
                        <div class="cat-ico"><i class="fas <?= htmlspecialchars($cat['icon'] ?: 'fa-box') ?>"></i></div>
                        <div class="flex-1 min-w-0">
                            <h4 class="truncate"><?= htmlspecialchars($cat['name']) ?></h4>
                            <div class="cat-meta">
                                <span class="num-mono"><?= (int) $cat['items_count'] ?></span> items ·
                                <span class="num-mono"><?= number_format((float) $cat['total_stock'], 0) ?></span> u.
                                <?php if ($cat['total_value'] > 0): ?>
                                    · $<?= number_format((float) $cat['total_value'], 0) ?>
                                <?php endif; ?>
                            </div>
                            <div class="cat-badges">
                                <?php if ($cat['out_count'] > 0): ?>
                                    <span class="mini-badge mb-out"><?= (int) $cat['out_count'] ?> agotado<?= $cat['out_count'] > 1 ? 's' : '' ?></span>
                                <?php endif; ?>
                                <?php if ($cat['low_count'] > 0): ?>
                                    <span class="mini-badge mb-low"><?= (int) $cat['low_count'] ?> bajo</span>
                                <?php endif; ?>
                                <?php if ($cat['out_count'] == 0 && $cat['low_count'] == 0 && $cat['items_count'] > 0): ?>
                                    <span class="mini-badge mb-ok"><i class="fas fa-check mr-0.5"></i> saludable</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ALERTS + RECENT MOVEMENTS -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-8">
            <!-- Alerts -->
            <div class="lg:col-span-2 panel">
                <div class="flex justify-between items-start mb-5">
                    <h2 class="panel-title" style="--title-accent: linear-gradient(180deg, #fbbf24, #f59e0b);">
                        <span class="dot"></span> Alertas de stock
                        <span class="text-xs font-medium text-slate-500 ml-2">
                            <?= count($lowStock) + count($expiringLots) ?> alertas activas
                        </span>
                    </h2>
                    <button onclick="window.location.href='inventory_ai_chat.php?prompt=' + encodeURIComponent('Dame un plan de reorden para los items en stock bajo, incluyendo prioridad y cantidad sugerida')"
                            class="btn-ai-pill">
                        <i class="fas fa-sparkles mr-1"></i> Plan de reorden IA
                    </button>
                </div>

                <?php if (empty($lowStock) && empty($expiringLots)): ?>
                    <div class="text-center py-12">
                        <div class="inline-block p-4 rounded-full mb-3" style="background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.1));">
                            <i class="fas fa-check-circle text-emerald-400 text-4xl"></i>
                        </div>
                        <h3 class="text-white font-semibold mb-1">Todo en niveles saludables</h3>
                        <p class="text-slate-400 text-sm">No hay items que requieran atencion ahora mismo.</p>
                    </div>
                <?php else: ?>
                    <?php if (!empty($lowStock)): ?>
                        <h3 class="text-xs font-bold text-yellow-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                            <i class="fas fa-bell"></i> Stock bajo (<?= count($lowStock) ?>)
                        </h3>
                        <div class="space-y-1 max-h-72 overflow-y-auto pr-1">
                            <?php foreach (array_slice($lowStock, 0, 10) as $item):
                                $cur  = (float) $item['current_stock'];
                                $minS = (float) $item['min_stock'];
                                $pct  = $minS > 0 ? min(100, ($cur / $minS) * 100) : 0;
                                $bar  = $pct < 30 ? 'pf-red' : ($pct < 70 ? 'pf-yel' : 'pf-grn');
                                $grad = $colorGrad[$item['category_color']] ?? $colorGrad['slate'];
                            ?>
                                <div class="alert-row flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                         style="background: linear-gradient(135deg, <?= $grad['from'] ?>22, <?= $grad['to'] ?>33);">
                                        <i class="fas <?= htmlspecialchars($item['category_icon'] ?: 'fa-box') ?> text-xs" style="color: <?= $grad['from'] ?>;"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-white text-sm font-medium truncate"><?= htmlspecialchars($item['name']) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($item['category_name']) ?></div>
                                    </div>
                                    <div class="w-32 flex-shrink-0">
                                        <div class="flex justify-between text-xs mb-1">
                                            <span class="num-mono text-yellow-400 font-bold"><?= inv_format_qty($cur) ?></span>
                                            <span class="text-slate-500 num-mono">/ <?= inv_format_qty($minS) ?></span>
                                        </div>
                                        <div class="progress-bar"><div class="progress-fill <?= $bar ?>" style="width: <?= $pct ?>%"></div></div>
                                    </div>
                                    <a href="inventory_movements.php?item=<?= (int) $item['id'] ?>&action=entry" class="replenish-btn flex-shrink-0">
                                        <i class="fas fa-plus text-xs"></i> Reponer
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($expiringLots)): ?>
                        <h3 class="text-xs font-bold text-orange-400 uppercase tracking-widest mb-3 mt-5 flex items-center gap-2">
                            <i class="fas fa-clock"></i> Lotes por vencer (<?= count($expiringLots) ?>)
                        </h3>
                        <div class="space-y-1 max-h-60 overflow-y-auto pr-1">
                            <?php foreach (array_slice($expiringLots, 0, 10) as $lot):
                                $d = (int) $lot['days_to_expire'];
                                $badgeClass = $d < 0 ? 'mb-out' : ($d <= 7 ? 'mb-out' : ($d <= 15 ? 'mb-low' : 'mb-low'));
                                $label = $d < 0 ? "Vencido hace " . abs($d) . "d" : ($d === 0 ? "Vence hoy" : "Vence en " . $d . "d");
                                $grad = $colorGrad[$lot['category_color']] ?? $colorGrad['orange'];
                            ?>
                                <div class="alert-row flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                         style="background: linear-gradient(135deg, <?= $grad['from'] ?>22, <?= $grad['to'] ?>33);">
                                        <i class="fas fa-flask text-xs" style="color: <?= $grad['from'] ?>;"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-white text-sm font-medium truncate"><?= htmlspecialchars($lot['item_name']) ?></div>
                                        <div class="text-xs text-slate-500">
                                            Lote <span class="num-mono"><?= htmlspecialchars($lot['lot_code'] ?: 's/c') ?></span> ·
                                            <span class="num-mono"><?= inv_format_qty((float) $lot['quantity_remaining']) ?></span> <?= htmlspecialchars($lot['unit']) ?>
                                        </div>
                                    </div>
                                    <span class="mini-badge <?= $badgeClass ?>"><?= $label ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Recent movements -->
            <div class="panel">
                <div class="flex justify-between items-start mb-5">
                    <h2 class="panel-title" style="--title-accent: linear-gradient(180deg, #06b6d4, #0891b2);">
                        <span class="dot"></span> Movimientos
                    </h2>
                    <a href="inventory_movements.php" class="text-xs text-cyan-400 hover:text-cyan-300 font-semibold">
                        Ver todo <i class="fas fa-arrow-right text-xs ml-0.5"></i>
                    </a>
                </div>
                <?php if (empty($recent)): ?>
                    <div class="text-center py-10">
                        <i class="fas fa-clock-rotate-left text-slate-600 text-3xl mb-2"></i>
                        <p class="text-slate-500 text-sm">Sin movimientos aun</p>
                        <a href="inventory_movements.php?action=entry" class="text-xs text-cyan-400 hover:text-cyan-300 font-semibold mt-3 inline-block">
                            Registrar primera entrada <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-0 max-h-[34rem] overflow-y-auto pr-1">
                        <?php foreach ($recent as $m):
                            $info = $movementLabels[$m['movement_type']] ?? ['label' => $m['movement_type'], 'icon' => 'fa-circle', 'grad' => 'linear-gradient(135deg,#64748b,#334155)'];
                            $qty = (float) $m['quantity'];
                            $isPos = $qty > 0;
                        ?>
                            <div class="mov-item">
                                <div class="mov-ico" style="background: <?= $info['grad'] ?>;">
                                    <i class="fas <?= $info['icon'] ?>"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex justify-between items-baseline gap-2">
                                        <span class="text-white text-sm font-medium truncate"><?= htmlspecialchars($m['item_name']) ?></span>
                                        <span class="num-mono text-sm font-bold <?= $isPos ? 'text-emerald-400' : 'text-red-400' ?>">
                                            <?= $isPos ? '+' : '' ?><?= inv_format_qty($qty) ?>
                                        </span>
                                    </div>
                                    <div class="text-xs text-slate-500 truncate mt-0.5">
                                        <span class="text-slate-400"><?= $info['label'] ?></span>
                                        <?php if ($m['employee_name']): ?> · <?= htmlspecialchars($m['employee_name']) ?><?php endif; ?>
                                        <?php if ($m['reason']): ?> · <?= htmlspecialchars($m['reason']) ?><?php endif; ?>
                                    </div>
                                    <div class="text-xs text-slate-600 mt-0.5 num-mono"><?= date('d/m H:i', strtotime($m['performed_at'])) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TOP MOVERS + AI PANEL -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div class="panel">
                <div class="flex justify-between items-start mb-5">
                    <h2 class="panel-title" style="--title-accent: linear-gradient(180deg, #f97316, #ea580c);">
                        <span class="dot"></span> Mas consumidos
                        <span class="text-xs font-medium text-slate-500 ml-2">ultimos 30 dias</span>
                    </h2>
                </div>
                <?php if (empty($topMovers)): ?>
                    <div class="text-center py-10">
                        <i class="fas fa-fire text-slate-600 text-3xl mb-2"></i>
                        <p class="text-slate-500 text-sm">Sin movimientos en los ultimos 30 dias</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php
                            $max = max(array_map(static fn($x) => (float) $x['units_out_30d'], $topMovers));
                            foreach ($topMovers as $i => $tm):
                                $pct = $max > 0 ? ((float) $tm['units_out_30d'] / $max) * 100 : 0;
                                $rank = $i + 1;
                        ?>
                            <div>
                                <div class="flex justify-between items-baseline mb-1.5 gap-3">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="text-xs num-mono text-slate-500 font-bold w-5">#<?= $rank ?></span>
                                        <i class="fas <?= htmlspecialchars($tm['category_icon']) ?> text-slate-500 text-xs"></i>
                                        <span class="text-white text-sm truncate"><?= htmlspecialchars($tm['name']) ?></span>
                                    </div>
                                    <span class="num-mono text-xs whitespace-nowrap">
                                        <strong class="text-orange-400"><?= number_format((float) $tm['units_out_30d'], 0) ?></strong>
                                        <span class="text-slate-500"><?= htmlspecialchars($tm['unit']) ?></span>
                                        <span class="text-slate-600 ml-1">· stock: <?= inv_format_qty((float) $tm['current_stock']) ?></span>
                                    </span>
                                </div>
                                <div class="progress-bar"><div class="progress-fill pf-orng" style="width: <?= $pct ?>%"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ai-panel">
                <i class="fas fa-robot robot-bg"></i>
                <div class="relative">
                    <div class="flex items-center gap-3 mb-1">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg,#a855f7,#06b6d4); box-shadow: 0 8px 24px -6px rgba(168,85,247,.5);">
                            <i class="fas fa-sparkles text-white"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white">Asistente IA</h2>
                            <p class="text-xs text-purple-300/80 uppercase tracking-widest font-semibold">Powered by Claude</p>
                        </div>
                    </div>
                    <p class="text-slate-300 text-sm mb-4 mt-3">Pregunta en lenguaje natural. Claude conoce stock, lotes, movimientos y vencimientos en tiempo real.</p>
                    <div class="space-y-2 mb-5">
                        <button onclick="window.location.href='inventory_ai_chat.php?prompt=' + encodeURIComponent('Que items tengo en stock bajo y cuanto deberia reordenar de cada uno')"
                                class="ai-suggestion">
                            <i class="fas fa-chevron-right chev"></i>
                            <span>Que reordenar esta semana?</span>
                        </button>
                        <button onclick="window.location.href='inventory_ai_chat.php?prompt=' + encodeURIComponent('Resumeme el estado del botiquin y proyecta cuanto durara al ritmo actual')"
                                class="ai-suggestion">
                            <i class="fas fa-chevron-right chev"></i>
                            <span>Estado del botiquin</span>
                        </button>
                        <button onclick="window.location.href='inventory_ai_chat.php?prompt=' + encodeURIComponent('Hay consumo anomalo en algun item en las ultimas semanas?')"
                                class="ai-suggestion">
                            <i class="fas fa-chevron-right chev"></i>
                            <span>Detectar consumo anomalo</span>
                        </button>
                    </div>
                    <a href="inventory_ai_chat.php" class="btn-ai w-full">
                        <i class="fas fa-comments"></i> Abrir chat completo
                    </a>
                </div>
            </div>
        </div>

    </div>
</body>
</html>
