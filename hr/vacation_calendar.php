<?php
/**
 * hr/vacation_calendar.php
 *
 * Calendario de vacaciones, con el mismo formato que el de cumpleaños: se elige
 * el mes y se ve quién tiene vacaciones.
 *
 * Muestra dos cosas distintas y las diferencia a propósito:
 *   - SOLICITADAS: fechas reales ya pedidas o aprobadas.
 *   - PREVISTAS:   el mes del aniversario de ingreso, que es cuando le
 *                  corresponde el disfrute a quien todavía no ha solicitado.
 *                  Esto es lo que permite planificar con anticipación.
 */

session_start();
require_once '../db.php';
require_once '../lib/vacation_calculator.php';

ensurePermission('hr_vacations', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$year = (int) ($_GET['anio'] ?? date('Y'));
if ($year < 2020 || $year > 2100) {
    $year = (int) date('Y');
}
$selectedMonth = (int) ($_GET['mes'] ?? date('n'));
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int) date('n');
}

$calendar = vacationCalendarByMonth($pdo, $year);
$current  = $calendar[$selectedMonth - 1] ?? $calendar[0];

// Totales del año
$totScheduled = 0; $totExpected = 0; $totDays = 0.0;
foreach ($calendar as $m) {
    $totScheduled += count($m['scheduled']);
    $totExpected  += count($m['expected']);
    foreach ($m['scheduled'] as $s) {
        $totDays += (float) $s['total_days'];
    }
}

// Próximos aniversarios, que es lo que hay que planificar
$anniversaries = vacationUpcomingAnniversaries($pdo, 60);

$estadoColor = [
    'APPROVED'  => 'bg-emerald-500/20 text-emerald-300',
    'APROBADO'  => 'bg-emerald-500/20 text-emerald-300',
    'PENDING'   => 'bg-amber-500/20 text-amber-300',
    'PENDIENTE' => 'bg-amber-500/20 text-amber-300',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario de Vacaciones</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .mes-btn {
            border-radius: 10px; padding: 12px 8px; text-align: center;
            transition: all .15s ease; border: 1px solid rgba(148,163,184,.18);
            background: rgba(30,41,59,.5);
        }
        .mes-btn:hover { background: rgba(51,65,85,.7); transform: translateY(-1px); }
        .mes-btn.activo { background: linear-gradient(135deg,#06b6d4,#0891b2); border-color: transparent; }
        .mes-btn .n { display: block; font-size: 11px; opacity: .75; margin-top: 2px; }
        .persona { border-left: 3px solid transparent; }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-wrap justify-between items-start gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-white mb-1">
                    <i class="fas fa-umbrella-beach text-cyan-400 mr-3"></i>Calendario de Vacaciones
                </h1>
                <p class="text-slate-400 text-sm">Planifica con anticipación el disfrute de cada colaborador</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="?anio=<?= $year - 1 ?>&mes=<?= $selectedMonth ?>" class="btn-secondary"><i class="fas fa-chevron-left"></i></a>
                <span class="px-4 py-2 rounded-lg bg-slate-800 text-white font-semibold"><?= $year ?></span>
                <a href="?anio=<?= $year + 1 ?>&mes=<?= $selectedMonth ?>" class="btn-secondary"><i class="fas fa-chevron-right"></i></a>
                <a href="vacations.php" class="btn-secondary ml-2"><i class="fas fa-arrow-left"></i> Vacaciones</a>
            </div>
        </div>

        <!-- Selector de mes, como el de cumpleaños -->
        <div class="glass-card mb-6">
            <h2 class="text-lg font-semibold text-white mb-4">Seleccionar Mes</h2>
            <div class="grid grid-cols-3 md:grid-cols-6 lg:grid-cols-12 gap-2">
                <?php foreach ($calendar as $m): ?>
                    <?php $n = count($m['scheduled']) + count($m['expected']); ?>
                    <a href="?anio=<?= $year ?>&mes=<?= $m['month'] ?>"
                       class="mes-btn text-white <?= $m['month'] === $selectedMonth ? 'activo' : '' ?>">
                        <?= htmlspecialchars($m['name']) ?>
                        <span class="n">(<?= $n ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Mes seleccionado -->
        <div class="glass-card mb-6">
            <h2 class="text-lg font-semibold text-white mb-4">
                <i class="fas fa-calendar-days text-cyan-400 mr-2"></i>
                Vacaciones de <?= htmlspecialchars($current['name']) ?> <?= $year ?>
            </h2>

            <?php if (empty($current['scheduled']) && empty($current['expected'])): ?>
                <p class="text-slate-400 text-center py-10">
                    <i class="fas fa-calendar-xmark text-3xl block mb-3 opacity-40"></i>
                    No hay vacaciones solicitadas ni previstas para este mes.
                </p>
            <?php endif; ?>

            <?php if (!empty($current['scheduled'])): ?>
                <h3 class="text-sm font-semibold text-emerald-300 uppercase tracking-wide mb-3">
                    <i class="fas fa-check-circle"></i> Solicitadas (<?= count($current['scheduled']) ?>)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 mb-6">
                    <?php foreach ($current['scheduled'] as $s): ?>
                        <div class="persona bg-slate-800/50 rounded-lg p-4" style="border-left-color:#10b981;">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                                     style="background: linear-gradient(135deg,#06b6d4,#0891b2);">
                                    <?= strtoupper(mb_substr($s['first_name'], 0, 1) . mb_substr($s['last_name'], 0, 1)) ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-white font-medium truncate"><?= htmlspecialchars($s['name']) ?></p>
                                    <p class="text-slate-400 text-xs"><?= htmlspecialchars((string) $s['employee_code']) ?></p>
                                    <p class="text-slate-300 text-sm mt-2">
                                        <i class="fas fa-plane-departure text-cyan-400 mr-1"></i>
                                        <?= date('d/m', strtotime($s['start_date'])) ?> al <?= date('d/m/Y', strtotime($s['end_date'])) ?>
                                    </p>
                                    <p class="text-slate-400 text-xs mt-1">
                                        <?= number_format((float) $s['total_days'], 1) ?> día(s)
                                        <?php if (!empty($s['department_name'])): ?>
                                            · <?= htmlspecialchars($s['department_name']) ?>
                                        <?php endif; ?>
                                    </p>
                                    <span class="inline-block mt-2 px-2 py-0.5 rounded text-xs <?= $estadoColor[strtoupper($s['status'])] ?? 'bg-slate-600/30 text-slate-300' ?>">
                                        <?= htmlspecialchars(ucfirst(strtolower($s['status']))) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($current['expected'])): ?>
                <h3 class="text-sm font-semibold text-amber-300 uppercase tracking-wide mb-3">
                    <i class="fas fa-clock"></i> Previstas por aniversario (<?= count($current['expected']) ?>)
                </h3>
                <p class="text-slate-400 text-xs mb-3">
                    Todavía no han solicitado. Su aniversario de ingreso cae este mes, así que es cuando
                    les corresponde el disfrute.
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($current['expected'] as $e): ?>
                        <div class="persona bg-slate-800/50 rounded-lg p-4" style="border-left-color:#f59e0b;">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                                     style="background: linear-gradient(135deg,#f59e0b,#d97706);">
                                    <?= strtoupper(mb_substr($e['first_name'], 0, 1) . mb_substr($e['last_name'], 0, 1)) ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-white font-medium truncate"><?= htmlspecialchars($e['name']) ?></p>
                                    <p class="text-slate-400 text-xs"><?= htmlspecialchars((string) $e['employee_code']) ?></p>
                                    <p class="text-slate-300 text-sm mt-2">
                                        <i class="fas fa-cake-candles text-amber-400 mr-1"></i>
                                        Ingresó el <?= date('d/m/Y', strtotime($e['hire_date'])) ?>
                                    </p>
                                    <p class="text-slate-400 text-xs mt-1">
                                        <?= (int) $e['years_at_year_end'] ?> año(s) ·
                                        le corresponden <?= rtrim(rtrim(number_format((float) $e['entitlement'], 1), '0'), '.') ?> días
                                        <?php if (!empty($e['department_name'])): ?>
                                            · <?= htmlspecialchars($e['department_name']) ?>
                                        <?php endif; ?>
                                    </p>
                                    <a href="vacations.php" class="inline-block mt-2 px-2 py-0.5 rounded bg-cyan-500/20 text-cyan-300 text-xs hover:bg-cyan-500/30">
                                        <i class="fas fa-plus"></i> Programar
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Resumen del año y próximos aniversarios -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="glass-card">
                <p class="text-slate-400 text-sm">Solicitadas en <?= $year ?></p>
                <p class="text-3xl font-bold text-white"><?= $totScheduled ?></p>
                <p class="text-slate-500 text-xs mt-1"><?= number_format($totDays, 1) ?> días en total</p>
            </div>
            <div class="glass-card">
                <p class="text-slate-400 text-sm">Previstas sin solicitar</p>
                <p class="text-3xl font-bold text-amber-400"><?= $totExpected ?></p>
                <p class="text-slate-500 text-xs mt-1">Pendientes de coordinar</p>
            </div>
            <div class="glass-card">
                <p class="text-slate-400 text-sm">Cumplen un año pronto</p>
                <p class="text-3xl font-bold text-cyan-400"><?= count($anniversaries) ?></p>
                <p class="text-slate-500 text-xs mt-1">En los próximos 60 días</p>
            </div>
        </div>

        <?php if (!empty($anniversaries)): ?>
            <div class="glass-card mt-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-hourglass-half text-cyan-400 mr-2"></i>
                    Próximos a cumplir el año
                </h2>
                <p class="text-slate-400 text-sm mb-4">
                    Al cumplir el año nace el derecho a vacaciones. Coordina las fechas antes de que se acumulen.
                </p>
                <div class="overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="text-slate-400 text-xs uppercase">
                            <tr>
                                <th class="text-left p-2">Colaborador</th>
                                <th class="text-left p-2">Departamento</th>
                                <th class="text-left p-2">Ingreso</th>
                                <th class="text-left p-2">Cumple</th>
                                <th class="text-right p-2">Faltan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($anniversaries as $a): ?>
                                <tr class="border-t border-slate-700/50">
                                    <td class="p-2 text-white">
                                        <?= htmlspecialchars(trim($a['first_name'] . ' ' . $a['last_name'])) ?>
                                        <span class="block text-xs text-slate-500"><?= htmlspecialchars((string) $a['employee_code']) ?></span>
                                    </td>
                                    <td class="p-2 text-slate-300"><?= htmlspecialchars($a['department_name'] ?: '—') ?></td>
                                    <td class="p-2 text-slate-400"><?= date('d/m/Y', strtotime($a['hire_date'])) ?></td>
                                    <td class="p-2 text-slate-300"><?= date('d/m/Y', strtotime($a['anniversary'])) ?></td>
                                    <td class="p-2 text-right">
                                        <span class="px-2 py-0.5 rounded text-xs <?= (int) $a['days_left'] <= 7 ? 'bg-rose-500/20 text-rose-300' : 'bg-slate-600/30 text-slate-300' ?>">
                                            <?= (int) $a['days_left'] ?> día(s)
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>
