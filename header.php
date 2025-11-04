<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// Detect if we're in a subdirectory first
$isInSubdir = (strpos($_SERVER['PHP_SELF'], '/agents/') !== false || strpos($_SERVER['PHP_SELF'], '/hr/') !== false);
$baseHref = $isInSubdir ? '../' : '';

$navItems = [
    'dashboard' => ['label' => 'Panel de Control', 'href' => $baseHref . 'dashboard.php', 'icon' => 'fa-gauge'],
    'records' => ['label' => 'Registros', 'href' => $baseHref . 'records.php', 'icon' => 'fa-table'],
    'view_admin_hours' => ['label' => 'Horas Admin', 'href' => $baseHref . 'view_admin_hours.php', 'icon' => 'fa-user-clock'],
    'hr_report' => ['label' => 'Reporte RH', 'href' => $baseHref . 'hr_report.php', 'icon' => 'fa-briefcase'],
    'adherence_report' => ['label' => 'Adherencia', 'href' => $baseHref . 'adherencia_report_hr.php', 'icon' => 'fa-chart-line'],
    'operations_dashboard' => ['label' => 'Operaciones', 'href' => $baseHref . 'operations_dashboard.php', 'icon' => 'fa-sitemap'],
    'register_attendance' => ['label' => 'Registrar Horas', 'href' => $baseHref . 'register_attendance.php', 'icon' => 'fa-calendar-plus'],
    'hr_module' => [
        'label' => 'Recursos Humanos',
        'icon' => 'fa-users-cog',
        'children' => [
            [
                'section' => 'hr_dashboard',
                'label' => 'Panel RH',
                'href' => $baseHref . 'hr/index.php',
                'icon' => 'fa-chart-pie',
            ],
            [
                'section' => 'hr_employees',
                'label' => 'Empleados',
                'href' => $baseHref . 'hr/employees.php',
                'icon' => 'fa-id-card',
            ],
            [
                'section' => 'hr_trial_period',
                'label' => 'Período de Prueba',
                'href' => $baseHref . 'hr/trial_period.php',
                'icon' => 'fa-hourglass-half',
            ],
            [
                'section' => 'hr_payroll',
                'label' => 'Nómina',
                'href' => $baseHref . 'hr/payroll.php',
                'icon' => 'fa-money-bill-wave',
            ],
            [
                'section' => 'hr_birthdays',
                'label' => 'Cumpleaños',
                'href' => $baseHref . 'hr/birthdays.php',
                'icon' => 'fa-birthday-cake',
            ],
            [
                'section' => 'hr_permissions',
                'label' => 'Permisos',
                'href' => $baseHref . 'hr/permissions.php',
                'icon' => 'fa-clipboard-list',
            ],
            [
                'section' => 'hr_vacations',
                'label' => 'Vacaciones',
                'href' => $baseHref . 'hr/vacations.php',
                'icon' => 'fa-umbrella-beach',
            ],
            [
                'section' => 'hr_calendar',
                'label' => 'Calendario',
                'href' => $baseHref . 'hr/calendar.php',
                'icon' => 'fa-calendar-alt',
            ],
            [
                'section' => 'hr_employees',
                'label' => 'Contratos',
                'href' => $baseHref . 'hr/contracts.php',
                'icon' => 'fa-file-contract',
            ],
        ],
    ],
    'agents' => [
        'label' => 'Agentes',
        'icon' => 'fa-user-friends',
        'children' => [
            [
                'section' => 'agent_dashboard',
                'label' => 'Panel de Agente',
                'href' => $baseHref . 'agent_dashboard.php',
                'icon' => 'fa-chart-bar',
            ],
            [
                'section' => 'agent_dashboard',
                'label' => 'Mis Solicitudes',
                'href' => $baseHref . 'agents/my_requests.php',
                'icon' => 'fa-file-alt',
            ],
            [
                'section' => 'register_attendance',
                'label' => 'Marcar Asistencia',
                'href' => $baseHref . 'punch.php',
                'icon' => 'fa-fingerprint',
            ],
        ],
    ],
    'activity_logs' => ['label' => 'Logs de Actividad', 'href' => $baseHref . 'hr/activity_logs.php', 'icon' => 'fa-history'],
    'login_logs' => ['label' => 'Registros de Acceso', 'href' => $baseHref . 'login_logs.php', 'icon' => 'fa-shield-alt'],
    'settings' => ['label' => 'Configuración', 'href' => $baseHref . 'settings.php', 'icon' => 'fa-sliders-h'],
];

$theme = $_SESSION['theme'] ?? 'dark';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'dark';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$themeLabel = $theme === 'light' ? 'Modo Oscuro' : 'Modo Claro';

// Detect if we're in a subdirectory
$isInSubdir = (strpos($_SERVER['PHP_SELF'], '/agents/') !== false || strpos($_SERVER['PHP_SELF'], '/hr/') !== false);
$assetBase = $isInSubdir ? '../assets' : 'assets';
$baseHref = $isInSubdir ? '../' : '';

$isAuthenticated = isset($_SESSION['user_id']);
$currentPath = basename($_SERVER['PHP_SELF']);
$userDisplayName = '';
if ($isAuthenticated) {
    $userDisplayName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($assetBase) ?>/css/theme.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="<?= htmlspecialchars($assetBase) ?>/js/app.js" defer></script>
    <title>Evallish BPO Control</title>
</head>

<body class="<?= htmlspecialchars($bodyClass) ?>">
    <header class="bg-gradient-to-r from-slate-900 via-slate-900 to-slate-800 border-b border-slate-800 shadow-lg shadow-black/40">
        <div class="max-w-7xl mx-auto px-6 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center justify-between gap-3 w-full md:w-auto">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-xl bg-cyan-500/20 border border-cyan-500/40 flex items-center justify-center text-cyan-300">
                    <i class="fas fa-bolt"></i>
                </div>
                <div>
                    <h1 class="brand-title text-xl font-semibold">Evallish BPO Control</h1>
                    <?php if ($userDisplayName): ?>
                        <p class="brand-subtitle text-xs">Bienvenido, <?= htmlspecialchars($userDisplayName) ?></p>
                    <?php endif; ?>
                </div>
            </div>
                <button type="button"
                        class="mobile-nav-toggle md:hidden"
                        data-nav-toggle
                        data-nav-target="primary-nav"
                        aria-controls="primary-nav"
                        aria-expanded="false">
                    <i class="fas fa-bars"></i>
                    Menú
                </button>
            </div>

            <nav id="primary-nav" class="main-nav flex flex-wrap items-center gap-2" data-open="false" data-nav>
                <?php if ($isAuthenticated): ?>
                    <?php foreach ($navItems as $sectionKey => $item): ?>
                        <?php if (isset($item['children']) && is_array($item['children'])): ?>
                            <?php
                                $childLinks = [];
                                foreach ($item['children'] as $child) {
                                    $childSection = $child['section'] ?? null;
                                    if ($childSection && !userHasPermission($childSection)) {
                                        continue;
                                    }
                                    $childLinks[] = $child;
                                }
                                if (empty($childLinks)) {
                                    continue;
                                }
                                $isActiveDropdown = false;
                                foreach ($childLinks as $child) {
                                    if ($currentPath === basename($child['href'])) {
                                        $isActiveDropdown = true;
                                        break;
                                    }
                                }
                                $dropdownButtonClasses = $isActiveDropdown
                                    ? 'nav-dropdown-button group inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold bg-cyan-500/20 text-cyan-200 hover:bg-cyan-500/30 transition-colors'
                                    : 'nav-dropdown-button group inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-slate-800/60 transition-colors';
                            ?>
                            <div class="nav-dropdown" data-nav-dropdown>
                                <button type="button"
                                        class="<?= $dropdownButtonClasses ?>"
                                        data-nav-dropdown-trigger
                                        aria-expanded="<?= $isActiveDropdown ? 'true' : 'false' ?>">
                                    <i class="fas <?= htmlspecialchars($item['icon'] ?? 'fa-layer-group') ?> text-xs"></i>
                                    <span><?= htmlspecialchars($item['label']) ?></span>
                                    <i class="fas fa-chevron-down text-xs opacity-70 transition-transform" data-nav-dropdown-icon style="font-size: 0.65rem;"></i>
                                </button>
                                <div class="nav-dropdown-menu" data-nav-dropdown-menu hidden>
                                    <?php foreach ($childLinks as $child): ?>
                                        <?php
                                            $childActive = $currentPath === basename($child['href']);
                                            $childClasses = $childActive
                                                ? 'nav-dropdown-link inline-flex items-center gap-2 px-3 py-2 rounded-md text-sm font-semibold bg-cyan-500/20 text-cyan-100 transition-colors'
                                                : 'nav-dropdown-link inline-flex items-center gap-2 px-3 py-2 rounded-md text-sm text-slate-200 hover:text-white hover:bg-slate-700/70 transition-colors';
                                        ?>
                                        <a href="<?= htmlspecialchars($child['href']) ?>" class="<?= $childClasses ?>">
                                            <i class="fas <?= htmlspecialchars($child['icon'] ?? 'fa-circle') ?> text-xs"></i>
                                            <span><?= htmlspecialchars($child['label']) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php elseif (userHasPermission($sectionKey)): ?>
                            <?php
                                $isActive = $currentPath === basename($item['href']);
                                $classes = $isActive
                                    ? 'group inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold bg-cyan-500/20 text-cyan-200 hover:bg-cyan-500/30 transition-colors'
                                    : 'group inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-slate-800/60 transition-colors';
                            ?>
                            <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $classes ?>">
                                <i class="fas <?= htmlspecialchars($item['icon']) ?> text-xs"></i>
                                <span><?= htmlspecialchars($item['label']) ?></span>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <a href="<?= $baseHref ?>logout.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm bg-rose-500/20 text-rose-200 hover:bg-rose-500/30 transition-colors">
                        <i class="fas fa-sign-out-alt text-xs"></i>
                        <span>Cerrar Sesión</span>
                    </a>
                <?php else: ?>
                    <a href="<?= $baseHref ?>index.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-slate-800/60 transition-colors">
                        <i class="fas fa-sign-in-alt text-xs"></i>
                        <span>Iniciar Sesión</span>
                    </a>
                <?php endif; ?>
                <form action="<?= $baseHref ?>theme_toggle.php" method="post" class="inline-flex">
                    <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm bg-slate-800/70 text-slate-200 hover:bg-slate-700 transition-colors border border-slate-700/70">
                        <i class="fas fa-adjust text-xs"></i>
                        <span><?= htmlspecialchars($themeLabel) ?></span>
                    </button>
                </form>
            </nav>
        </div>
    </header>
    <main class="app-shell">
