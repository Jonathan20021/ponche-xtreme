<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// Detect if we're in a subdirectory first
$isInSubdir = (strpos($_SERVER['PHP_SELF'], '/agents/') !== false || 
               strpos($_SERVER['PHP_SELF'], '/hr/') !== false || 
               strpos($_SERVER['PHP_SELF'], '/helpdesk/') !== false ||
               strpos($_SERVER['PHP_SELF'], '/chat/') !== false);
$baseHref = $isInSubdir ? '../' : '';

$navItems = [
    'dashboard' => ['label' => 'Panel de Control', 'href' => $baseHref . 'dashboard.php', 'icon' => 'fa-gauge'],
    'records' => ['label' => 'Registros', 'href' => $baseHref . 'records.php', 'icon' => 'fa-table'],
    'view_admin_hours' => ['label' => 'Horas Admin', 'href' => $baseHref . 'view_admin_hours.php', 'icon' => 'fa-user-clock'],
    'hr_report' => ['label' => 'Reporte RH', 'href' => $baseHref . 'hr_report.php', 'icon' => 'fa-briefcase'],
    'adherence_report' => ['label' => 'Adherencia', 'href' => $baseHref . 'adherencia_report_hr.php', 'icon' => 'fa-chart-line'],
    'operations_dashboard' => ['label' => 'Operaciones', 'href' => $baseHref . 'operations_dashboard.php', 'icon' => 'fa-sitemap'],
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
                'section' => 'manage_campaigns',
                'label' => 'Gestión de Campañas',
                'href' => $baseHref . 'hr/campaigns.php',
                'icon' => 'fa-bullhorn',
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
            [
                'section' => 'hr_dashboard',
                'label' => 'Asistente Virtual',
                'href' => $baseHref . 'hr/hr_assistant.php',
                'icon' => 'fa-robot',
            ],
            [
                'section' => 'system_settings',
                'label' => 'Configuración Sistema',
                'href' => $baseHref . 'hr/system_settings.php',
                'icon' => 'fa-cog',
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
    'supervisor_dashboard' => ['label' => 'Monitor en Tiempo Real', 'href' => $baseHref . 'supervisor_dashboard.php', 'icon' => 'fa-users-cog'],
    'manager_dashboard' => ['label' => 'Monitor Administrativos', 'href' => $baseHref . 'manager_dashboard.php', 'icon' => 'fa-user-tie'],
    'tickets' => [
        'label' => 'Tickets',
        'icon' => 'fa-ticket-alt',
        'children' => [
            [
                'section' => 'helpdesk_tickets',
                'label' => 'Mis Tickets',
                'href' => $baseHref . 'helpdesk/my_tickets.php',
                'icon' => 'fa-list',
            ],
            [
                'section' => 'helpdesk_tickets',
                'label' => 'Crear Ticket',
                'href' => $baseHref . 'helpdesk/create_ticket.php',
                'icon' => 'fa-plus-circle',
            ],
            [
                'section' => 'helpdesk',
                'label' => 'Gestión Tickets',
                'href' => $baseHref . 'helpdesk/dashboard.php',
                'icon' => 'fa-tasks',
            ],
            [
                'section' => 'helpdesk_suggestions',
                'label' => 'Buzón de Sugerencias',
                'href' => $baseHref . 'helpdesk/suggestions.php',
                'icon' => 'fa-lightbulb',
            ],
        ],
    ],
    'chat_admin' => ['label' => 'Administración de Chat', 'href' => $baseHref . 'chat/admin.php', 'icon' => 'fa-comments-alt'],
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
$isInSubdir = (strpos($_SERVER['PHP_SELF'], '/agents/') !== false || 
               strpos($_SERVER['PHP_SELF'], '/hr/') !== false || 
               strpos($_SERVER['PHP_SELF'], '/helpdesk/') !== false ||
               strpos($_SERVER['PHP_SELF'], '/chat/') !== false);
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
    <link href="<?= htmlspecialchars($assetBase) ?>/css/chat.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="<?= htmlspecialchars($assetBase) ?>/js/app.js" defer></script>
    <?php if ($isAuthenticated && userHasPermission('chat')): ?>
    <script>
        const currentUserId = <?= (int)$_SESSION['user_id'] ?>;
        const currentUserRole = <?= json_encode($_SESSION['role'] ?? 'agent') ?>;
        <?php 
        // Obtener permisos de chat del usuario
        $chatPermsStmt = $pdo->prepare("SELECT can_create_groups FROM chat_permissions WHERE user_id = ?");
        $chatPermsStmt->execute([$_SESSION['user_id']]);
        $chatPerms = $chatPermsStmt->fetch();
        $canCreateGroups = $chatPerms ? (bool)$chatPerms['can_create_groups'] : true;
        ?>
        const canCreateGroups = <?= json_encode($canCreateGroups) ?>;
    </script>
    <script src="<?= htmlspecialchars($assetBase) ?>/js/chat.js?v=<?= time() ?>" defer></script>
    <?php endif; ?>
    <title>Evallish BPO Control</title>
</head>

<body class="<?= htmlspecialchars($bodyClass) ?>">
    <header class="bg-gradient-to-r from-slate-900 via-slate-900 to-slate-800 border-b border-slate-800 shadow-lg shadow-black/40">
        <div class="max-w-full mx-auto px-4 sm:px-6 py-3 flex flex-col md:flex-row md:items-center gap-4">
            <div class="flex items-center justify-between w-full md:w-auto md:flex-shrink-0">
                <div class="flex items-center gap-2">
                    <img src="<?= htmlspecialchars($assetBase) ?>/logo.png" 
                         alt="Evallish BPO Control" 
                         class="h-8 md:h-9 w-auto object-contain flex-shrink-0"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="h-8 md:h-9 w-8 md:w-9 rounded-xl bg-cyan-500/20 border border-cyan-500/40 items-center justify-center text-cyan-300 hidden flex-shrink-0">
                        <i class="fas fa-bolt text-base"></i>
                    </div>
                    <h1 class="brand-title text-sm md:text-base font-semibold whitespace-nowrap">Evallish BPO</h1>
                </div>
                <button type="button"
                        class="mobile-nav-toggle md:hidden ml-4 px-3 py-2 rounded-lg bg-slate-800 text-slate-200"
                        data-nav-toggle
                        data-nav-target="primary-nav"
                        aria-controls="primary-nav"
                        aria-expanded="false">
                    <i class="fas fa-bars mr-2"></i>
                    Menú
                </button>
            </div>

            <nav id="primary-nav" class="main-nav w-full md:w-auto md:flex-1 flex flex-col md:flex-row flex-wrap items-stretch md:items-center justify-start md:justify-end gap-2" data-open="false" data-nav>
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
                                // También marcar como activo si estamos en cualquier página de helpdesk
                                if (!$isActiveDropdown && strpos($_SERVER['PHP_SELF'], '/helpdesk/') !== false) {
                                    $isActiveDropdown = true;
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
                                <div class="nav-dropdown-menu" data-nav-dropdown-menu <?= $isActiveDropdown ? '' : 'hidden' ?>>
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
                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm bg-slate-800/70 text-slate-200 hover:bg-slate-700 transition-colors border border-slate-700/70">
                        <i class="fas fa-adjust text-xs"></i>
                        <span><?= htmlspecialchars($themeLabel) ?></span>
                    </button>
                </form>
            </nav>
        </div>
    </header>
    <main class="app-shell">
