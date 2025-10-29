<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_birthdays');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Get current month or selected month
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Get all employees with birthdays
$allBirthdays = $pdo->query("
    SELECT e.*, u.username, d.name as department_name,
           MONTH(e.birth_date) as birth_month,
           DAY(e.birth_date) as birth_day,
           YEAR(CURDATE()) - YEAR(e.birth_date) as current_age
    FROM employees e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.birth_date IS NOT NULL
    AND e.employment_status IN ('ACTIVE', 'TRIAL')
    ORDER BY MONTH(e.birth_date), DAY(e.birth_date)
")->fetchAll(PDO::FETCH_ASSOC);

// Group by month
$birthdaysByMonth = [];
foreach ($allBirthdays as $birthday) {
    $month = $birthday['birth_month'];
    if (!isset($birthdaysByMonth[$month])) {
        $birthdaysByMonth[$month] = [];
    }
    $birthdaysByMonth[$month][] = $birthday;
}

// Get today's birthdays
$todayBirthdays = array_filter($allBirthdays, function($b) {
    return $b['birth_month'] == date('n') && $b['birth_day'] == date('j');
});

// Get this week's birthdays
$weekBirthdays = [];
$today = new DateTime();
$weekEnd = (clone $today)->modify('+7 days');

foreach ($allBirthdays as $birthday) {
    $birthDate = DateTime::createFromFormat('Y-m-d', date('Y') . '-' . sprintf('%02d', $birthday['birth_month']) . '-' . sprintf('%02d', $birthday['birth_day']));
    if ($birthDate >= $today && $birthDate <= $weekEnd) {
        $weekBirthdays[] = $birthday;
    }
}

// Get this month's birthdays
$monthBirthdays = $birthdaysByMonth[$selectedMonth] ?? [];

$monthNames = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cumpleaños - HR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .birthday-card {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }
        .birthday-card:hover {
            background: rgba(30, 41, 59, 0.8);
            border-color: rgba(236, 72, 153, 0.5);
            transform: translateY(-2px);
        }
        .birthday-card.today {
            border: 2px solid #ec4899;
            background: linear-gradient(135deg, rgba(236, 72, 153, 0.1) 0%, rgba(219, 39, 119, 0.1) 100%);
        }
        .theme-light .birthday-card {
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        .theme-light .birthday-card:hover {
            background: rgba(255, 255, 255, 1);
        }
        .month-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 0.5rem;
        }
        .month-btn {
            padding: 0.75rem;
            border-radius: 8px;
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.1);
            color: #94a3b8;
            transition: all 0.3s ease;
            cursor: pointer;
            text-align: center;
        }
        .month-btn:hover {
            background: rgba(30, 41, 59, 0.8);
            color: white;
        }
        .month-btn.active {
            background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
            color: white;
            border-color: #ec4899;
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
                    <i class="fas fa-birthday-cake text-pink-400 mr-3"></i>
                    Cumpleaños de Empleados
                </h1>
                <p class="text-slate-400">Celebra con tu equipo</p>
            </div>
            <a href="index.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Volver a HR
            </a>
        </div>

        <!-- Today's Birthdays -->
        <?php if (!empty($todayBirthdays)): ?>
            <div class="glass-card mb-8" style="background: linear-gradient(135deg, rgba(236, 72, 153, 0.2) 0%, rgba(219, 39, 119, 0.2) 100%); border: 2px solid #ec4899;">
                <h2 class="text-2xl font-bold text-white mb-4">
                    <i class="fas fa-gift text-pink-400 mr-2"></i>
                    ¡Cumpleaños de Hoy! 🎉
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($todayBirthdays as $birthday): ?>
                        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-4 text-center">
                            <div class="w-20 h-20 mx-auto mb-3 rounded-full flex items-center justify-center text-3xl font-bold text-white" 
                                 style="background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);">
                                <?= strtoupper(substr($birthday['first_name'], 0, 1) . substr($birthday['last_name'], 0, 1)) ?>
                            </div>
                            <h3 class="text-xl font-semibold text-white mb-1">
                                <?= htmlspecialchars($birthday['first_name'] . ' ' . $birthday['last_name']) ?>
                            </h3>
                            <p class="text-pink-300 text-sm mb-2"><?= htmlspecialchars($birthday['position'] ?: 'Empleado') ?></p>
                            <p class="text-white font-semibold">¡Cumple <?= $birthday['current_age'] ?> años!</p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- This Week's Birthdays -->
        <?php if (!empty($weekBirthdays) && empty($todayBirthdays)): ?>
            <div class="glass-card mb-8">
                <h2 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-calendar-week text-purple-400 mr-2"></i>
                    Cumpleaños de Esta Semana
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <?php foreach ($weekBirthdays as $birthday): ?>
                        <div class="birthday-card">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold text-white" 
                                     style="background: linear-gradient(135deg, #a855f7 0%, #7c3aed 100%);">
                                    <?= strtoupper(substr($birthday['first_name'], 0, 1) . substr($birthday['last_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <p class="text-white font-medium"><?= htmlspecialchars($birthday['first_name'] . ' ' . $birthday['last_name']) ?></p>
                                    <p class="text-slate-400 text-sm"><?= sprintf('%02d/%02d', $birthday['birth_day'], $birthday['birth_month']) ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Month Selector -->
        <div class="glass-card mb-8">
            <h2 class="text-xl font-semibold text-white mb-4">Seleccionar Mes</h2>
            <div class="month-selector">
                <?php foreach ($monthNames as $monthNum => $monthName): ?>
                    <a href="?month=<?= $monthNum ?>&year=<?= $selectedYear ?>" 
                       class="month-btn <?= $monthNum == $selectedMonth ? 'active' : '' ?>">
                        <?= $monthName ?>
                        <?php if (isset($birthdaysByMonth[$monthNum])): ?>
                            <span class="block text-xs mt-1">(<?= count($birthdaysByMonth[$monthNum]) ?>)</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Month Birthdays -->
        <div class="glass-card">
            <h2 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-calendar-day text-pink-400 mr-2"></i>
                Cumpleaños de <?= $monthNames[$selectedMonth] ?>
            </h2>
            
            <?php if (empty($monthBirthdays)): ?>
                <p class="text-slate-400 text-center py-8">No hay cumpleaños registrados en este mes.</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($monthBirthdays as $birthday): ?>
                        <?php
                        $isToday = ($birthday['birth_month'] == date('n') && $birthday['birth_day'] == date('j'));
                        ?>
                        <div class="birthday-card <?= $isToday ? 'today' : '' ?>">
                            <div class="flex items-start gap-4">
                                <div class="w-16 h-16 rounded-full flex items-center justify-center text-xl font-bold text-white flex-shrink-0" 
                                     style="background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);">
                                    <?= strtoupper(substr($birthday['first_name'], 0, 1) . substr($birthday['last_name'], 0, 1)) ?>
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold text-white mb-1">
                                        <?= htmlspecialchars($birthday['first_name'] . ' ' . $birthday['last_name']) ?>
                                        <?php if ($isToday): ?>
                                            <i class="fas fa-gift text-pink-400 ml-2"></i>
                                        <?php endif; ?>
                                    </h3>
                                    <p class="text-slate-400 text-sm mb-2"><?= htmlspecialchars($birthday['employee_code']) ?></p>
                                    <div class="space-y-1 text-sm">
                                        <p class="text-slate-300">
                                            <i class="fas fa-calendar text-pink-400 mr-2"></i>
                                            <?= sprintf('%02d de %s', $birthday['birth_day'], $monthNames[$birthday['birth_month']]) ?>
                                        </p>
                                        <p class="text-slate-300">
                                            <i class="fas fa-briefcase text-indigo-400 mr-2"></i>
                                            <?= htmlspecialchars($birthday['position'] ?: 'Sin posición') ?>
                                        </p>
                                        <?php if ($birthday['department_name']): ?>
                                            <p class="text-slate-300">
                                                <i class="fas fa-building text-blue-400 mr-2"></i>
                                                <?= htmlspecialchars($birthday['department_name']) ?>
                                            </p>
                                        <?php endif; ?>
                                        <p class="text-white font-semibold mt-2">
                                            <i class="fas fa-birthday-cake text-pink-400 mr-2"></i>
                                            <?= $birthday['current_age'] ?> años
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total Empleados</p>
                        <h3 class="text-3xl font-bold text-white"><?= count($allBirthdays) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Cumpleaños Hoy</p>
                        <h3 class="text-3xl font-bold text-white"><?= count($todayBirthdays) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);">
                        <i class="fas fa-gift text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Esta Semana</p>
                        <h3 class="text-3xl font-bold text-white"><?= count($weekBirthdays) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                        <i class="fas fa-calendar-week text-white text-xl"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>
