<?php
/**
 * hr/delivery_restaurants.php
 *
 * Delivery: qué restaurante atiende cada colaborador y cómo se reparte su costo
 * para el sistema contable.
 *
 * La campaña de nómina sigue siendo UNA sola (Delivery). Esto vive aparte y no
 * toca employees.campaign_id, así que ni la nómina ni los monitores cambian.
 */

session_start();
require_once '../db.php';
require_once '../lib/delivery_restaurants.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$userId = (int) ($_SESSION['user_id'] ?? 0);

$ok = []; $err = [];

// ---------------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add_restaurant') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                $err[] = 'El nombre del restaurante es obligatorio.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO restaurants (name, code, campaign_id, color, contact_name, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $name,
                    trim((string) ($_POST['code'] ?? '')) ?: null,
                    !empty($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : null,
                    trim((string) ($_POST['color'] ?? '')) ?: '#6366f1',
                    trim((string) ($_POST['contact_name'] ?? '')) ?: null,
                    trim((string) ($_POST['notes'] ?? '')) ?: null,
                ]);
                $ok[] = 'Restaurante "' . $name . '" agregado.';
            }
        } elseif ($action === 'toggle_restaurant') {
            $rid = (int) ($_POST['restaurant_id'] ?? 0);
            $pdo->prepare("UPDATE restaurants SET is_active = 1 - is_active WHERE id = ?")->execute([$rid]);
            $ok[] = 'Restaurante actualizado.';
        } elseif ($action === 'assign') {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $rid   = (int) ($_POST['restaurant_id'] ?? 0);
            $pct   = max(0.01, min(100, (float) ($_POST['allocation_pct'] ?? 100)));

            if ($empId <= 0 || $rid <= 0) {
                $err[] = 'Selecciona colaborador y restaurante.';
            } else {
                $dup = $pdo->prepare("SELECT COUNT(*) FROM employee_restaurants WHERE employee_id = ? AND restaurant_id = ? AND end_date IS NULL");
                $dup->execute([$empId, $rid]);
                if ((int) $dup->fetchColumn() > 0) {
                    $err[] = 'Ese colaborador ya está asignado a ese restaurante.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO employee_restaurants
                            (employee_id, restaurant_id, allocation_pct, is_primary, start_date, notes, assigned_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $empId, $rid, $pct,
                        isset($_POST['is_primary']) ? 1 : 0,
                        preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['start_date'] ?? '') ? $_POST['start_date'] : date('Y-m-d'),
                        trim((string) ($_POST['notes'] ?? '')) ?: null,
                        $userId ?: null,
                    ]);
                    $ok[] = 'Asignación creada (' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '% del costo).';
                }
            }
        } elseif ($action === 'end_assignment') {
            $aid = (int) ($_POST['assignment_id'] ?? 0);
            $pdo->prepare("UPDATE employee_restaurants SET end_date = CURDATE() WHERE id = ? AND end_date IS NULL")->execute([$aid]);
            $ok[] = 'Asignación finalizada.';
        } elseif ($action === 'update_pct') {
            $aid = (int) ($_POST['assignment_id'] ?? 0);
            $pct = max(0.01, min(100, (float) ($_POST['allocation_pct'] ?? 100)));
            $pdo->prepare("UPDATE employee_restaurants SET allocation_pct = ? WHERE id = ?")->execute([$pct, $aid]);
            $ok[] = 'Porcentaje actualizado.';
        }
    } catch (Throwable $e) {
        $err[] = 'No se pudo completar la acción: ' . $e->getMessage();
    }

    $_SESSION['dr_flash'] = ['ok' => $ok, 'err' => $err];
    header('Location: delivery_restaurants.php?' . http_build_query(['desde' => $_POST['desde'] ?? '', 'hasta' => $_POST['hasta'] ?? '']));
    exit;
}

$flash = $_SESSION['dr_flash'] ?? ['ok' => [], 'err' => []];
unset($_SESSION['dr_flash']);

// ---------------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------------
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) { $desde = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) { $hasta = date('Y-m-d'); }
if ($hasta < $desde) { $hasta = $desde; }

$restaurants = deliveryGetRestaurants($pdo, false);
$warnings    = deliveryAllocationWarnings($pdo);
$costReport  = deliveryCostByRestaurant($pdo, $desde, $hasta);

$campaigns = [];
try {
    $campaigns = $pdo->query("SELECT id, name FROM campaigns ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { /* sin campañas */ }

// Colaboradores candidatos: los de campañas de Delivery + los ya asignados
$candidates = [];
try {
    $candidates = $pdo->query("
        SELECT DISTINCT e.id, e.first_name, e.last_name, e.employee_code, c.name AS campaign_name
        FROM employees e
        LEFT JOIN campaigns c ON c.id = e.campaign_id
        LEFT JOIN employee_restaurants er ON er.employee_id = e.id
        WHERE e.employment_status <> 'TERMINATED'
          AND (c.name LIKE '%elivery%' OR er.id IS NOT NULL)
        ORDER BY e.first_name, e.last_name
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { /* sin candidatos */ }

$inputCls = 'w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 text-white text-sm';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery — Restaurantes y Costos</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-wrap justify-between items-start gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-utensils text-orange-400 mr-3"></i>Delivery — Restaurantes
                </h1>
                <p class="text-slate-400 text-sm">
                    A qué restaurante atiende cada colaborador, para dividir el costo en contabilidad.
                    <strong class="text-slate-300">La campaña de nómina sigue siendo una sola:</strong>
                    esto no altera la nómina ni los monitores.
                </p>
            </div>
            <a href="employees.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> Empleados</a>
        </div>

        <?php foreach ($flash['ok'] as $m): ?>
            <div class="mb-3 px-4 py-3 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-200 text-sm">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($m) ?>
            </div>
        <?php endforeach; ?>
        <?php foreach ($flash['err'] as $m): ?>
            <div class="mb-3 px-4 py-3 rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-200 text-sm">
                <i class="fas fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($m) ?>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($warnings)): ?>
            <div class="mb-6 p-4 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-200 text-sm">
                <i class="fas fa-triangle-exclamation mr-2"></i>
                <strong>Reparto incompleto:</strong> estos colaboradores no suman 100% entre sus restaurantes,
                así que su costo quedaría mal dividido:
                <ul class="mt-2 ml-5 list-disc">
                    <?php foreach ($warnings as $w): ?>
                        <li>
                            <?= htmlspecialchars($w['name']) ?> —
                            <?= rtrim(rtrim(number_format($w['total_pct'], 2), '0'), '.') ?>%
                            en <?= (int) $w['restaurants'] ?> restaurante(s)
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- ================= Reparto de costos ================= -->
        <div class="glass-card mb-8">
            <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
                <h2 class="text-xl font-semibold text-white">
                    <i class="fas fa-scale-balanced text-cyan-400 mr-2"></i>
                    Costo por restaurante
                </h2>
                <form method="GET" class="flex flex-wrap items-end gap-2">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Desde</label>
                        <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>" class="<?= $inputCls ?>">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Hasta</label>
                        <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>" class="<?= $inputCls ?>">
                    </div>
                    <button type="submit" class="btn-primary text-sm"><i class="fas fa-filter"></i> Calcular</button>
                </form>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-5">
                <div class="bg-slate-800/50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-white"><?= (int) $costReport['totals']['employees'] ?></p>
                    <p class="text-slate-400 text-xs">Colaboradores</p>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-cyan-400"><?= number_format($costReport['totals']['hours'], 1) ?></p>
                    <p class="text-slate-400 text-xs">Horas del período</p>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-emerald-400">RD$<?= number_format($costReport['totals']['cost'], 2) ?></p>
                    <p class="text-slate-400 text-xs">Costo total</p>
                </div>
            </div>

            <?php if (empty($costReport['restaurants'])): ?>
                <p class="text-slate-400 text-center py-6">
                    Todavía no hay costo repartido. Agrega restaurantes y asigna colaboradores abajo.
                </p>
            <?php else: ?>
                <table class="w-full text-sm mb-4">
                    <thead class="text-slate-400 text-xs uppercase">
                        <tr>
                            <th class="text-left p-2">Restaurante</th>
                            <th class="text-left p-2">Código</th>
                            <th class="text-right p-2">Colaboradores</th>
                            <th class="text-right p-2">Horas</th>
                            <th class="text-right p-2">Costo</th>
                            <th class="text-right p-2">% del total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($costReport['restaurants'] as $r): ?>
                            <tr class="border-t border-slate-700/50">
                                <td class="p-2 text-white">
                                    <span class="inline-block w-3 h-3 rounded-full mr-2" style="background: <?= htmlspecialchars($r['color'] ?: '#6366f1') ?>;"></span>
                                    <?= htmlspecialchars($r['name']) ?>
                                </td>
                                <td class="p-2 text-slate-400"><?= htmlspecialchars((string) $r['code']) ?></td>
                                <td class="p-2 text-right text-slate-300"><?= (int) $r['employees'] ?></td>
                                <td class="p-2 text-right text-slate-300"><?= number_format($r['hours'], 1) ?></td>
                                <td class="p-2 text-right text-white font-semibold">RD$<?= number_format($r['cost'], 2) ?></td>
                                <td class="p-2 text-right text-slate-400">
                                    <?= $costReport['totals']['cost'] > 0 ? number_format($r['cost'] * 100 / $costReport['totals']['cost'], 1) : '0.0' ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($costReport['unassigned'])): ?>
                <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-200 text-sm">
                    <i class="fas fa-user-slash mr-1"></i>
                    <strong><?= count($costReport['unassigned']) ?> colaborador(es) de Delivery sin restaurante asignado</strong>
                    — su costo (RD$<?= number_format(array_sum(array_column($costReport['unassigned'], 'cost')), 2) ?>)
                    no se está repartiendo:
                    <?= htmlspecialchars(implode(', ', array_column($costReport['unassigned'], 'name'))) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Restaurantes -->
            <div class="glass-card">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-store text-orange-400 mr-2"></i>Restaurantes
                </h2>

                <form method="POST" class="space-y-3 mb-5 pb-5 border-b border-slate-700">
                    <input type="hidden" name="action" value="add_restaurant">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-slate-300 text-sm mb-1">Nombre *</label>
                            <input type="text" name="name" required maxlength="150" class="<?= $inputCls ?>">
                        </div>
                        <div>
                            <label class="block text-slate-300 text-sm mb-1">Código contable</label>
                            <input type="text" name="code" maxlength="40" class="<?= $inputCls ?>">
                        </div>
                        <div>
                            <label class="block text-slate-300 text-sm mb-1">Campaña</label>
                            <select name="campaign_id" class="<?= $inputCls ?>">
                                <option value="">— Sin campaña —</option>
                                <?php foreach ($campaigns as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= stripos($c['name'], 'delivery') !== false ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-slate-300 text-sm mb-1">Color</label>
                            <input type="color" name="color" value="#f97316" class="<?= $inputCls ?>" style="height:38px;">
                        </div>
                    </div>
                    <button type="submit" class="btn-primary text-sm"><i class="fas fa-plus"></i> Agregar restaurante</button>
                </form>

                <?php if (empty($restaurants)): ?>
                    <p class="text-slate-400 text-center py-4">Aún no hay restaurantes registrados</p>
                <?php else: ?>
                    <div class="space-y-2" style="max-height: 22rem; overflow-y: auto;">
                        <?php foreach ($restaurants as $r): ?>
                            <div class="flex items-center justify-between gap-3 bg-slate-800/50 rounded p-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="w-3 h-3 rounded-full flex-shrink-0" style="background: <?= htmlspecialchars($r['color'] ?: '#6366f1') ?>;"></span>
                                    <div class="min-w-0">
                                        <p class="text-white text-sm truncate">
                                            <?= htmlspecialchars($r['name']) ?>
                                            <?php if (!$r['is_active']): ?>
                                                <span class="ml-1 text-xs text-slate-500">(inactivo)</span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-slate-400 text-xs">
                                            <?= $r['code'] ? htmlspecialchars($r['code']) . ' · ' : '' ?>
                                            <?= (int) $r['active_employees'] ?> colaborador(es)
                                        </p>
                                    </div>
                                </div>
                                <form method="POST" class="flex-shrink-0">
                                    <input type="hidden" name="action" value="toggle_restaurant">
                                    <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="text-slate-400 hover:text-white text-xs" title="Activar / desactivar">
                                        <i class="fas fa-power-off"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Asignaciones -->
            <div class="glass-card">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-users-line text-indigo-400 mr-2"></i>Asignar colaborador
                </h2>

                <form method="POST" class="space-y-3 mb-5 pb-5 border-b border-slate-700">
                    <input type="hidden" name="action" value="assign">
                    <div>
                        <label class="block text-slate-300 text-sm mb-1">Colaborador *</label>
                        <select name="employee_id" required class="<?= $inputCls ?>">
                            <option value="">Seleccionar...</option>
                            <?php foreach ($candidates as $c): ?>
                                <option value="<?= (int) $c['id'] ?>">
                                    <?= htmlspecialchars(trim($c['first_name'] . ' ' . $c['last_name'])) ?>
                                    <?= $c['campaign_name'] ? ' — ' . htmlspecialchars($c['campaign_name']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-slate-300 text-sm mb-1">Restaurante *</label>
                            <select name="restaurant_id" required class="<?= $inputCls ?>">
                                <option value="">Seleccionar...</option>
                                <?php foreach ($restaurants as $r): ?>
                                    <?php if ($r['is_active']): ?>
                                        <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-slate-300 text-sm mb-1">% del costo *</label>
                            <input type="number" name="allocation_pct" step="0.01" min="0.01" max="100" value="100" required class="<?= $inputCls ?>">
                        </div>
                    </div>
                    <p class="text-xs text-slate-400">
                        Si el colaborador atiende un solo restaurante, déjalo en 100%.
                        Si atiende varios, reparte el porcentaje entre ellos hasta sumar 100%.
                    </p>
                    <button type="submit" class="btn-primary text-sm"><i class="fas fa-link"></i> Asignar</button>
                </form>

                <?php if (empty($costReport['employees'])): ?>
                    <p class="text-slate-400 text-center py-4">Sin asignaciones vigentes</p>
                <?php else: ?>
                    <div class="space-y-2" style="max-height: 22rem; overflow-y: auto;">
                        <?php foreach ($costReport['employees'] as $emp): ?>
                            <div class="bg-slate-800/50 rounded p-3">
                                <div class="flex justify-between items-start gap-2">
                                    <p class="text-white text-sm font-semibold"><?= htmlspecialchars($emp['name']) ?></p>
                                    <span class="text-xs text-slate-400 whitespace-nowrap">
                                        <?= number_format($emp['hours'], 1) ?> h · RD$<?= number_format($emp['cost'], 2) ?>
                                    </span>
                                </div>
                                <?php foreach ($emp['splits'] as $s): ?>
                                    <div class="flex items-center justify-between gap-2 mt-2 text-xs">
                                        <span class="text-slate-300">
                                            <?= htmlspecialchars($s['name']) ?>
                                            <span class="text-slate-500">— <?= rtrim(rtrim(number_format($s['pct'], 2), '0'), '.') ?>%</span>
                                        </span>
                                        <span class="text-slate-400">RD$<?= number_format($s['cost'], 2) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>
