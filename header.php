<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// Detect if we're in a subdirectory first
$isInSubdir = (strpos($_SERVER['PHP_SELF'], '/agents/') !== false ||
    strpos($_SERVER['PHP_SELF'], '/hr/') !== false ||
    strpos($_SERVER['PHP_SELF'], '/helpdesk/') !== false ||
    strpos($_SERVER['PHP_SELF'], '/chat/') !== false ||
    strpos($_SERVER['PHP_SELF'], '/wasapi_reports/') !== false);
$baseHref = $isInSubdir ? '../' : '';

$navItems = [
    'dashboard' => ['label' => 'Panel de Control', 'href' => $baseHref . 'dashboard.php', 'icon' => 'fa-gauge'],
    'records' => ['label' => 'Registros', 'href' => $baseHref . 'records.php', 'icon' => 'fa-table'],
    'view_admin_hours' => ['label' => 'Horas Admin', 'href' => $baseHref . 'view_admin_hours.php', 'icon' => 'fa-user-clock'],
    'hr_report' => ['label' => 'Reporte RH', 'href' => $baseHref . 'hr_report.php', 'icon' => 'fa-briefcase'],
    'adherence_report' => ['label' => 'Adherencia', 'href' => $baseHref . 'adherencia_report_hr.php', 'icon' => 'fa-chart-line'],
    'wfm_report' => ['label' => 'Reporte WFM', 'href' => $baseHref . 'wfm_report.php', 'icon' => 'fa-chart-area'],
    'vicidial_reports' => ['label' => 'Reportes Vicidial', 'href' => $baseHref . 'vicidial_reports.php', 'icon' => 'fa-phone-volume'],
    'voice_ai_reports' => ['label' => 'Comms GHL', 'href' => $baseHref . 'voice_ai_reports.php', 'icon' => 'fa-robot'],
    'wasapi_reports' => ['label' => 'Centro Wasapi', 'href' => $baseHref . 'wasapi_reports/', 'icon' => 'fa-whatsapp'],
    'operations_dashboard' => ['label' => 'Operaciones', 'href' => $baseHref . 'operations_dashboard.php', 'icon' => 'fa-sitemap'],
    'hr_module' => [
        'label' => 'Recursos Humanos',
        'icon' => 'fa-users-cog',
        'children' => [
            ['section' => 'hr_dashboard', 'label' => 'Panel RH', 'href' => $baseHref . 'hr/index.php', 'icon' => 'fa-chart-pie'],
            ['section' => 'hr_employees', 'label' => 'Empleados', 'href' => $baseHref . 'hr/employees.php', 'icon' => 'fa-id-card'],
            ['section' => 'manage_campaigns', 'label' => 'Gestión de Campañas', 'href' => $baseHref . 'hr/campaigns.php', 'icon' => 'fa-bullhorn'],
            ['section' => 'productivity_dashboard', 'label' => 'Productividad', 'href' => $baseHref . 'hr/productivity.php', 'icon' => 'fa-bullseye'],
            ['section' => 'wfm_planning', 'label' => 'WFM Planificacion', 'href' => $baseHref . 'hr/wfm_planning.php', 'icon' => 'fa-calendar-check'],
            ['section' => 'wfm_planning', 'label' => 'Calculadora SL', 'href' => $baseHref . 'hr/service_level_calculator.php', 'icon' => 'fa-calculator'],
            ['section' => 'hr_trial_period', 'label' => 'Período de Prueba', 'href' => $baseHref . 'hr/trial_period.php', 'icon' => 'fa-hourglass-half'],
            ['section' => 'hr_payroll', 'label' => 'Nómina', 'href' => $baseHref . 'hr/payroll.php', 'icon' => 'fa-money-bill-wave'],
            ['section' => 'hr_payroll', 'label' => 'Volantes de Nómina', 'href' => $baseHref . 'hr/payroll_slip_email.php', 'icon' => 'fa-envelope-open-text'],
            ['section' => 'hr_birthdays', 'label' => 'Cumpleaños', 'href' => $baseHref . 'hr/birthdays.php', 'icon' => 'fa-birthday-cake'],
            ['section' => 'hr_permissions', 'label' => 'Permisos', 'href' => $baseHref . 'hr/permissions.php', 'icon' => 'fa-clipboard-list'],
            ['section' => 'hr_employees', 'label' => 'Inventario', 'href' => $baseHref . 'hr/inventory.php', 'icon' => 'fa-boxes'],
            ['section' => 'hr_vacations', 'label' => 'Vacaciones', 'href' => $baseHref . 'hr/vacations.php', 'icon' => 'fa-umbrella-beach'],
            ['section' => 'hr_calendar', 'label' => 'Calendario', 'href' => $baseHref . 'hr/calendar.php', 'icon' => 'fa-calendar-alt'],
            ['section' => 'hr_employees', 'label' => 'Contratos', 'href' => $baseHref . 'hr/contracts.php', 'icon' => 'fa-file-contract'],
            ['section' => 'hr_recruitment_ai', 'label' => 'Análisis Reclutamiento IA', 'href' => $baseHref . 'hr/recruitment_ai_analysis.php', 'icon' => 'fa-brain'],
            ['section' => 'hr_dashboard', 'label' => 'Asistente Virtual', 'href' => $baseHref . 'hr/hr_assistant.php', 'icon' => 'fa-robot'],
            ['section' => 'system_settings', 'label' => 'Configuración Sistema', 'href' => $baseHref . 'hr/system_settings.php', 'icon' => 'fa-cog'],
        ],
    ],
    'agents' => [
        'label' => 'Agentes',
        'icon' => 'fa-user-friends',
        'children' => [
            ['section' => 'agent_dashboard', 'label' => 'Panel de Agente', 'href' => $baseHref . 'agent_dashboard.php', 'icon' => 'fa-chart-bar'],
            ['section' => 'agent_dashboard', 'label' => 'Mis Solicitudes', 'href' => $baseHref . 'agents/my_requests.php', 'icon' => 'fa-file-alt'],
            ['section' => 'register_attendance', 'label' => 'Marcar Asistencia', 'href' => $baseHref . 'punch.php', 'icon' => 'fa-fingerprint'],
        ],
    ],
    'supervisor_dashboard' => ['label' => 'Monitor en Tiempo Real', 'href' => $baseHref . 'supervisor_dashboard.php', 'icon' => 'fa-users-cog'],
    'manager_dashboard' => ['label' => 'Monitor Administrativos', 'href' => $baseHref . 'manager_dashboard.php', 'icon' => 'fa-user-tie'],
    'executive_dashboard' => ['label' => 'Dashboard Ejecutivo', 'href' => $baseHref . 'executive_dashboard.php', 'icon' => 'fa-chart-pie'],
    'chat' => [
        'label' => 'Comunicaciones',
        'icon' => 'fa-comments',
        'children' => [
            ['section' => 'chat_admin', 'label' => 'Administración Chat', 'href' => $baseHref . 'chat/admin.php', 'icon' => 'fa-comments-alt'],
            ['section' => 'chat_mass_message', 'label' => 'Mensajes Masivos', 'href' => $baseHref . 'chat/mass_message.php', 'icon' => 'fa-bullhorn'],
        ],
    ],
    'tickets' => [
        'label' => 'Tickets',
        'icon' => 'fa-ticket-alt',
        'children' => [
            ['section' => 'helpdesk_tickets', 'label' => 'Mis Tickets', 'href' => $baseHref . 'helpdesk/my_tickets.php', 'icon' => 'fa-list'],
            ['section' => 'helpdesk_tickets', 'label' => 'Crear Ticket', 'href' => $baseHref . 'helpdesk/create_ticket.php', 'icon' => 'fa-plus-circle'],
            ['section' => 'helpdesk', 'label' => 'Gestión Tickets', 'href' => $baseHref . 'helpdesk/dashboard.php', 'icon' => 'fa-tasks'],
            ['section' => 'helpdesk_suggestions', 'label' => 'Buzón de Sugerencias', 'href' => $baseHref . 'helpdesk/suggestions.php', 'icon' => 'fa-lightbulb'],
        ],
    ],
    'my_loans_personal' => [
        'label' => 'Mis Préstamos',
        'icon' => 'fa-hand-holding-usd',
        'always_show' => true,
        'children' => [
            ['label' => 'Mis Préstamos', 'href' => $baseHref . 'agents/my_loans.php', 'icon' => 'fa-money-check-alt', 'always_show' => true],
            ['label' => 'Solicitar Préstamo', 'href' => $baseHref . 'agents/request_loan.php', 'icon' => 'fa-hand-holding-usd', 'always_show' => true],
        ],
    ],
    'activity_logs' => ['label' => 'Logs de Actividad', 'href' => $baseHref . 'hr/activity_logs.php', 'icon' => 'fa-history'],
    'login_logs' => ['label' => 'Registros de Acceso', 'href' => $baseHref . 'login_logs.php', 'icon' => 'fa-shield-alt'],
    'settings' => ['label' => 'Configuración', 'href' => $baseHref . 'settings.php', 'icon' => 'fa-sliders-h'],
];

// Logical IA grouping of the flat nav into labelled sidebar sections
$navSections = [
    'Operación'        => ['dashboard', 'records', 'view_admin_hours', 'agents', 'operations_dashboard'],
    'Monitoreo'        => ['supervisor_dashboard', 'manager_dashboard', 'executive_dashboard'],
    'Reportes'         => ['hr_report', 'adherence_report', 'wfm_report', 'vicidial_reports', 'voice_ai_reports', 'wasapi_reports'],
    'Recursos Humanos' => ['hr_module'],
    'Comunicación'     => ['chat', 'tickets'],
    'Personal'         => ['my_loans_personal'],
    'Sistema'          => ['activity_logs', 'login_logs', 'settings'],
];

$theme = $_SESSION['theme'] ?? 'light';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'light';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$themeLabel = $theme === 'light' ? 'Modo Oscuro' : 'Modo Claro';
$themeIcon = $theme === 'light' ? 'fa-moon' : 'fa-sun';
$sidebarCollapsed = (($_COOKIE['ev_sidebar'] ?? '') === 'collapsed');

// Detect if we're in a subdirectory (asset paths)
$assetBase = $isInSubdir ? '../assets' : 'assets';

$isAuthenticated = isset($_SESSION['user_id']);
$currentPath = basename($_SERVER['PHP_SELF']);
$userDisplayName = '';
$userRole = $_SESSION['role'] ?? '';
if ($isAuthenticated) {
    $userDisplayName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? '');
}
$userInitials = 'EV';
if ($userDisplayName !== '') {
    $parts = preg_split('/\s+/', trim($userDisplayName));
    $userInitials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
$themeVer = @filemtime(__DIR__ . '/assets/css/theme.css') ?: '2';
$appJsVer = @filemtime(__DIR__ . '/assets/js/app.js') ?: '2';

/**
 * Render a single sidebar entry (simple link or collapsible group).
 * Returns HTML string, or '' if the user has no access.
 */
if (!function_exists('ev_render_nav_entry')) {
    function ev_render_nav_entry(string $key, array $item, string $currentPath): string
    {
        // Collapsible group
        if (isset($item['children']) && is_array($item['children'])) {
            $childLinks = [];
            foreach ($item['children'] as $child) {
                $childAlways = !empty($child['always_show']);
                $childSection = $child['section'] ?? null;
                if (!$childAlways && $childSection && !userHasPermission($childSection)) {
                    continue;
                }
                $childLinks[] = $child;
            }
            if (empty($childLinks)) {
                return '';
            }
            $groupActive = false;
            foreach ($childLinks as $child) {
                if ($currentPath === basename($child['href'])) {
                    $groupActive = true;
                    break;
                }
            }
            $html = '<div class="sidebar-group' . ($groupActive ? ' is-open' : '') . '" data-sidebar-group>';
            $html .= '<button type="button" class="sidebar-link" data-sidebar-group-toggle>';
            $html .= '<i class="fas ' . htmlspecialchars($item['icon'] ?? 'fa-layer-group') . ' sidebar-link__icon"></i>';
            $html .= '<span class="sidebar-link__text">' . htmlspecialchars($item['label']) . '</span>';
            $html .= '<i class="fas fa-chevron-down sidebar-group__chevron"></i></button>';
            $html .= '<div class="sidebar-group__items"><div>';
            foreach ($childLinks as $child) {
                $active = $currentPath === basename($child['href']) ? ' is-active' : '';
                $html .= '<a href="' . htmlspecialchars($child['href']) . '" class="sidebar-link' . $active . '">';
                $html .= '<i class="fas ' . htmlspecialchars($child['icon'] ?? 'fa-circle') . ' sidebar-link__icon"></i>';
                $html .= '<span class="sidebar-link__text">' . htmlspecialchars($child['label']) . '</span></a>';
            }
            $html .= '</div></div></div>';
            return $html;
        }

        // Simple link
        if (empty($item['always_show']) && !userHasPermission($key)) {
            return '';
        }
        $active = $currentPath === basename($item['href']) ? ' is-active' : '';
        $html = '<a href="' . htmlspecialchars($item['href']) . '" class="sidebar-link' . $active . '">';
        $html .= '<i class="fas ' . htmlspecialchars($item['icon']) . ' sidebar-link__icon"></i>';
        $html .= '<span class="sidebar-link__text">' . htmlspecialchars($item['label']) . '</span></a>';
        return $html;
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://cdn.datatables.net">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($assetBase) ?>/css/theme.css?v=<?= $themeVer ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars($assetBase) ?>/css/chat.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="<?= htmlspecialchars($assetBase) ?>/js/chart-theme.js?v=<?= @filemtime(__DIR__ . '/assets/js/chart-theme.js') ?>"></script>
    <script src="<?= htmlspecialchars($assetBase) ?>/js/app.js?v=<?= $appJsVer ?>" defer></script>
    <?php if ($isAuthenticated): ?>
        <script>
            const currentUserId = <?= (int) $_SESSION['user_id'] ?>;
            const currentUserRole = <?= json_encode($_SESSION['role'] ?? 'agent') ?>;
            <?php
            // Obtener permisos de chat del usuario
            $chatPermsStmt = $pdo->prepare("SELECT can_create_groups FROM chat_permissions WHERE user_id = ?");
            $chatPermsStmt->execute([$_SESSION['user_id']]);
            $chatPerms = $chatPermsStmt->fetch();
            $canCreateGroups = $chatPerms ? (bool) $chatPerms['can_create_groups'] : true;
            ?>
            const canCreateGroups = <?= json_encode($canCreateGroups) ?>;
            // Exponer en window para clientes que leen window.currentUserId/canCreateGroups
            window.currentUserId = currentUserId;
            window.currentUserRole = currentUserRole;
            window.canCreateGroups = canCreateGroups;
        </script>
        <?php $pollCfg = getPollingConfig($pdo); ?>
        <script>
            // Intervalos de actualización configurables desde settings.php (Rendimiento)
            window.PonchePolling = {
                chat: <?= $pollCfg['chat_ms'] ?>,
                dashboard: <?= $pollCfg['dashboard_ms'] ?>,
                modal: <?= $pollCfg['modal_ms'] ?>,
                chatAdmin: <?= $pollCfg['chat_admin_ms'] ?>,
                pauseWhenHidden: <?= $pollCfg['pause_when_hidden'] ? 'true' : 'false' ?>
            };
        </script>
        <script src="<?= htmlspecialchars($assetBase) ?>/js/chat.js?v=<?= @filemtime(__DIR__ . '/assets/js/chat.js') ?>" defer></script>
    <?php endif; ?>
    <title>Evallish BPO Control</title>
</head>

<body class="<?= htmlspecialchars($bodyClass) ?><?= $sidebarCollapsed ? ' sidebar-collapsed' : '' ?>">
<?php if ($isAuthenticated): ?>
    <div class="sidebar-overlay" data-sidebar-overlay></div>

    <aside class="app-sidebar" id="appSidebar" aria-label="Navegación principal">
        <div class="app-sidebar__brand">
            <span class="app-sidebar__logo">
                <img src="<?= htmlspecialchars($assetBase) ?>/logo.png" alt="Evallish BPO">
            </span>
            <span class="app-sidebar__wordmark">
                <strong>Evallish BPO</strong>
                <span>Control Suite</span>
            </span>
        </div>

        <nav class="app-sidebar__nav" data-sidebar-nav>
            <?php
            foreach ($navSections as $sectionLabel => $keys) {
                $rendered = '';
                foreach ($keys as $key) {
                    if (!isset($navItems[$key])) {
                        continue;
                    }
                    $rendered .= ev_render_nav_entry($key, $navItems[$key], $currentPath);
                }
                if ($rendered !== '') {
                    echo '<div class="sidebar-section-label">' . htmlspecialchars($sectionLabel) . '</div>';
                    echo $rendered;
                }
            }
            ?>
        </nav>

        <div class="app-sidebar__footer">
            <div class="sidebar-userchip">
                <span class="sidebar-userchip__avatar"><?= htmlspecialchars($userInitials) ?></span>
                <span class="sidebar-userchip__meta">
                    <strong><?= htmlspecialchars($userDisplayName ?: 'Usuario') ?></strong>
                    <span><?= htmlspecialchars($userRole ?: 'Evallish BPO') ?></span>
                </span>
            </div>
            <a href="<?= $baseHref ?>logout.php" class="sidebar-logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Cerrar Sesión</span>
            </a>
        </div>
    </aside>

    <header class="app-topbar">
        <button type="button" class="topbar-btn" data-sidebar-mobile aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
        <button type="button" class="topbar-btn" data-sidebar-collapse aria-label="Colapsar menú">
            <i class="fas fa-bars-staggered"></i>
        </button>

        <div class="topbar-search" role="search">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" placeholder="Buscar en el menú…" data-sidebar-search aria-label="Buscar en el menú">
        </div>

        <div class="topbar-spacer"></div>

        <form action="<?= $baseHref ?>theme_toggle.php" method="post" class="inline-flex">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <button type="submit" class="topbar-action" title="<?= htmlspecialchars($themeLabel) ?>">
                <i class="fas <?= $themeIcon ?>"></i>
                <span class="hidden sm:inline"><?= htmlspecialchars($themeLabel) ?></span>
            </button>
        </form>
    </header>
<?php else: ?>
    <header class="app-topbar" style="left:0;">
        <span class="app-sidebar__logo" style="width:34px;height:34px;background:var(--navy-100);">
            <img src="<?= htmlspecialchars($assetBase) ?>/logo.png" alt="Evallish BPO">
        </span>
        <span class="topbar-title">Evallish BPO</span>
        <div class="topbar-spacer"></div>
        <a href="<?= $baseHref ?>index.php" class="topbar-action"><i class="fas fa-sign-in-alt"></i><span>Iniciar Sesión</span></a>
    </header>
<?php endif; ?>

    <main class="app-shell<?= $isAuthenticated ? '' : ' app-shell--guest' ?>"<?= $isAuthenticated ? '' : ' style="margin-left:0;"' ?>>
