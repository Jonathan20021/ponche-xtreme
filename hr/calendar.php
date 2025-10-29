<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_calendar');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Get current month or selected month
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Get first and last day of the month
$firstDay = new DateTime("$selectedYear-$selectedMonth-01");
$lastDay = (clone $firstDay)->modify('last day of this month');

// Get all events for the month
$startDate = $firstDay->format('Y-m-d');
$endDate = $lastDay->format('Y-m-d');

// Get birthdays
$birthdays = $pdo->query("
    SELECT e.*, u.username, d.name as department_name,
           CONCAT('$selectedYear-', LPAD(MONTH(e.birth_date), 2, '0'), '-', LPAD(DAY(e.birth_date), 2, '0')) as event_date
    FROM employees e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.birth_date IS NOT NULL
    AND e.employment_status IN ('ACTIVE', 'TRIAL')
    AND MONTH(e.birth_date) = $selectedMonth
")->fetchAll(PDO::FETCH_ASSOC);

// Get approved permissions
$permissions = $pdo->prepare("
    SELECT pr.*, e.first_name, e.last_name, e.employee_code
    FROM permission_requests pr
    JOIN employees e ON e.id = pr.employee_id
    WHERE pr.status = 'APPROVED'
    AND ((pr.start_date BETWEEN ? AND ?) OR (pr.end_date BETWEEN ? AND ?) OR (pr.start_date <= ? AND pr.end_date >= ?))
");
$permissions->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
$permissionsList = $permissions->fetchAll(PDO::FETCH_ASSOC);

// Get approved vacations
$vacations = $pdo->prepare("
    SELECT vr.*, e.first_name, e.last_name, e.employee_code
    FROM vacation_requests vr
    JOIN employees e ON e.id = vr.employee_id
    WHERE vr.status = 'APPROVED'
    AND ((vr.start_date BETWEEN ? AND ?) OR (vr.end_date BETWEEN ? AND ?) OR (vr.start_date <= ? AND vr.end_date >= ?))
");
$vacations->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
$vacationsList = $vacations->fetchAll(PDO::FETCH_ASSOC);

// Build calendar events by date
$eventsByDate = [];

// Add birthdays
foreach ($birthdays as $birthday) {
    $date = $birthday['event_date'];
    if (!isset($eventsByDate[$date])) {
        $eventsByDate[$date] = [];
    }
    $eventsByDate[$date][] = [
        'type' => 'birthday',
        'title' => $birthday['first_name'] . ' ' . $birthday['last_name'],
        'icon' => 'fa-birthday-cake',
        'color' => '#ec4899',
        'data' => $birthday
    ];
}

// Add permissions
foreach ($permissionsList as $permission) {
    $start = new DateTime($permission['start_date']);
    $end = new DateTime($permission['end_date']);
    
    for ($date = clone $start; $date <= $end; $date->modify('+1 day')) {
        $dateStr = $date->format('Y-m-d');
        if ($date >= $firstDay && $date <= $lastDay) {
            if (!isset($eventsByDate[$dateStr])) {
                $eventsByDate[$dateStr] = [];
            }
            $eventsByDate[$dateStr][] = [
                'type' => 'permission',
                'title' => $permission['first_name'] . ' ' . $permission['last_name'],
                'subtitle' => str_replace('_', ' ', ucwords(strtolower($permission['request_type']))),
                'icon' => 'fa-clipboard-list',
                'color' => '#8b5cf6',
                'data' => $permission
            ];
        }
    }
}

// Add vacations
foreach ($vacationsList as $vacation) {
    $start = new DateTime($vacation['start_date']);
    $end = new DateTime($vacation['end_date']);
    
    for ($date = clone $start; $date <= $end; $date->modify('+1 day')) {
        $dateStr = $date->format('Y-m-d');
        if ($date >= $firstDay && $date <= $lastDay) {
            if (!isset($eventsByDate[$dateStr])) {
                $eventsByDate[$dateStr] = [];
            }
            $eventsByDate[$dateStr][] = [
                'type' => 'vacation',
                'title' => $vacation['first_name'] . ' ' . $vacation['last_name'],
                'subtitle' => 'Vacaciones',
                'icon' => 'fa-umbrella-beach',
                'color' => '#06b6d4',
                'data' => $vacation
            ];
        }
    }
}

// Build calendar grid
$calendar = [];
$currentDate = clone $firstDay;
$currentDate->modify('monday this week'); // Start from Monday

while ($currentDate <= $lastDay || $currentDate->format('w') != 1) {
    $week = [];
    for ($i = 0; $i < 7; $i++) {
        $dateStr = $currentDate->format('Y-m-d');
        $week[] = [
            'date' => clone $currentDate,
            'dateStr' => $dateStr,
            'isCurrentMonth' => $currentDate->format('m') == $selectedMonth,
            'isToday' => $dateStr === date('Y-m-d'),
            'events' => $eventsByDate[$dateStr] ?? []
        ];
        $currentDate->modify('+1 day');
    }
    $calendar[] = $week;
    
    if ($currentDate > $lastDay && $currentDate->format('w') == 1) {
        break;
    }
}

$monthNames = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

$prevMonth = $selectedMonth - 1;
$prevYear = $selectedYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $selectedMonth + 1;
$nextYear = $selectedYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario HR - <?= $monthNames[$selectedMonth] ?> <?= $selectedYear ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .calendar-cell {
            min-height: 120px;
            background: rgba(30, 41, 59, 0.3);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s ease;
        }
        .calendar-cell:hover {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(99, 102, 241, 0.3);
        }
        .calendar-cell.today {
            border: 2px solid #6366f1;
            background: rgba(99, 102, 241, 0.1);
        }
        .calendar-cell.other-month {
            opacity: 0.4;
        }
        .event-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: rgba(30, 41, 59, 0.5);
            border-radius: 8px;
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
                    <i class="fas fa-calendar-alt text-red-400 mr-3"></i>
                    Calendario de Recursos Humanos
                </h1>
                <p class="text-slate-400">Vista unificada de eventos, cumpleaños, permisos y vacaciones</p>
            </div>
            <a href="index.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Volver a HR
            </a>
        </div>

        <!-- Month Navigation -->
        <div class="glass-card mb-6">
            <div class="flex items-center justify-between">
                <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn-secondary">
                    <i class="fas fa-chevron-left"></i>
                    <?= $monthNames[$prevMonth] ?>
                </a>
                
                <h2 class="text-2xl font-bold text-white">
                    <?= $monthNames[$selectedMonth] ?> <?= $selectedYear ?>
                </h2>
                
                <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn-secondary">
                    <?= $monthNames[$nextMonth] ?>
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>

        <!-- Legend -->
        <div class="glass-card mb-6">
            <div class="flex flex-wrap gap-4">
                <div class="legend-item">
                    <i class="fas fa-birthday-cake" style="color: #ec4899;"></i>
                    <span class="text-white text-sm">Cumpleaños</span>
                </div>
                <div class="legend-item">
                    <i class="fas fa-clipboard-list" style="color: #8b5cf6;"></i>
                    <span class="text-white text-sm">Permisos</span>
                </div>
                <div class="legend-item">
                    <i class="fas fa-umbrella-beach" style="color: #06b6d4;"></i>
                    <span class="text-white text-sm">Vacaciones</span>
                </div>
            </div>
        </div>

        <!-- Calendar -->
        <div class="glass-card overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="text-center py-3 px-2 text-slate-400 font-semibold border-b border-slate-700">Lunes</th>
                        <th class="text-center py-3 px-2 text-slate-400 font-semibold border-b border-slate-700">Martes</th>
                        <th class="text-center py-3 px-2 text-slate-400 font-semibold border-b border-slate-700">Miércoles</th>
                        <th class="text-center py-3 px-2 text-slate-400 font-semibold border-b border-slate-700">Jueves</th>
                        <th class="text-center py-3 px-2 text-slate-400 font-semibold border-b border-slate-700">Viernes</th>
                        <th class="text-center py-3 px-2 text-slate-400 font-semibold border-b border-slate-700">Sábado</th>
                        <th class="text-center py-3 px-2 text-slate-400 font-semibold border-b border-slate-700">Domingo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($calendar as $week): ?>
                        <tr>
                            <?php foreach ($week as $day): ?>
                                <td class="calendar-cell p-2 align-top <?= $day['isCurrentMonth'] ? '' : 'other-month' ?> <?= $day['isToday'] ? 'today' : '' ?>">
                                    <div class="text-right mb-2">
                                        <span class="text-sm font-semibold <?= $day['isToday'] ? 'text-indigo-400' : 'text-slate-300' ?>">
                                            <?= $day['date']->format('d') ?>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($day['events'])): ?>
                                        <div class="space-y-1">
                                            <?php 
                                            $displayedEvents = array_slice($day['events'], 0, 3);
                                            $remainingCount = count($day['events']) - 3;
                                            ?>
                                            <?php foreach ($displayedEvents as $event): ?>
                                                <div class="event-badge text-white" style="background: <?= $event['color'] ?>;">
                                                    <i class="fas <?= $event['icon'] ?> text-xs"></i>
                                                    <span class="truncate"><?= htmlspecialchars($event['title']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if ($remainingCount > 0): ?>
                                                <div class="text-xs text-slate-400 mt-1 pl-1">
                                                    +<?= $remainingCount ?> más
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Events List -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-8">
            <!-- Birthdays -->
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-birthday-cake text-pink-400 mr-2"></i>
                    Cumpleaños (<?= count($birthdays) ?>)
                </h3>
                <?php if (empty($birthdays)): ?>
                    <p class="text-slate-400 text-sm">No hay cumpleaños este mes.</p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($birthdays as $birthday): ?>
                            <div class="flex items-center gap-2 p-2 bg-slate-800/50 rounded">
                                <i class="fas fa-birthday-cake text-pink-400"></i>
                                <div class="flex-1">
                                    <p class="text-white text-sm font-medium"><?= htmlspecialchars($birthday['first_name'] . ' ' . $birthday['last_name']) ?></p>
                                    <p class="text-slate-400 text-xs"><?= date('d/m', strtotime($birthday['event_date'])) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Permissions -->
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-clipboard-list text-purple-400 mr-2"></i>
                    Permisos (<?= count($permissionsList) ?>)
                </h3>
                <?php if (empty($permissionsList)): ?>
                    <p class="text-slate-400 text-sm">No hay permisos este mes.</p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($permissionsList as $permission): ?>
                            <div class="flex items-center gap-2 p-2 bg-slate-800/50 rounded">
                                <i class="fas fa-clipboard-list text-purple-400"></i>
                                <div class="flex-1">
                                    <p class="text-white text-sm font-medium"><?= htmlspecialchars($permission['first_name'] . ' ' . $permission['last_name']) ?></p>
                                    <p class="text-slate-400 text-xs">
                                        <?= date('d/m', strtotime($permission['start_date'])) ?> - 
                                        <?= date('d/m', strtotime($permission['end_date'])) ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Vacations -->
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-umbrella-beach text-cyan-400 mr-2"></i>
                    Vacaciones (<?= count($vacationsList) ?>)
                </h3>
                <?php if (empty($vacationsList)): ?>
                    <p class="text-slate-400 text-sm">No hay vacaciones este mes.</p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($vacationsList as $vacation): ?>
                            <div class="flex items-center gap-2 p-2 bg-slate-800/50 rounded">
                                <i class="fas fa-umbrella-beach text-cyan-400"></i>
                                <div class="flex-1">
                                    <p class="text-white text-sm font-medium"><?= htmlspecialchars($vacation['first_name'] . ' ' . $vacation['last_name']) ?></p>
                                    <p class="text-slate-400 text-xs">
                                        <?= date('d/m', strtotime($vacation['start_date'])) ?> - 
                                        <?= date('d/m', strtotime($vacation['end_date'])) ?>
                                        (<?= $vacation['total_days'] ?> días)
                                    </p>
                                </div>
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
