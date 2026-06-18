<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || !userHasPermission('agent_dashboard')) {
    header('Location: login_agent.php');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'light';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$themeLabel = $theme === 'light' ? 'Modo Oscuro' : 'Modo Claro';
$themeIcon = $theme === 'light' ? 'fa-moon' : 'fa-sun';
$sidebarCollapsed = (($_COOKIE['ev_sidebar'] ?? '') === 'collapsed');

// Robust subdirectory detection for asset + link paths
$inSubdir = (strpos($_SERVER['PHP_SELF'], '/agents/') !== false ||
             strpos($_SERVER['PHP_SELF'], '/chat/') !== false ||
             strpos($_SERVER['PHP_SELF'], '/helpdesk/') !== false);
$assetBase = $inSubdir ? '../assets' : 'assets';
$basePath = $inSubdir ? '../' : '';

$agentNavSections = [
    'Mi Trabajo' => [
        'agent_dashboard' => ['label' => 'Panel de Control', 'href' => $basePath . 'agent_dashboard.php', 'icon' => 'fa-house-user'],
        'agent_quality'   => ['label' => 'Calidad', 'href' => $basePath . 'agent_quality.php', 'icon' => 'fa-star'],
        'agent_records'   => ['label' => 'Registros', 'href' => $basePath . 'agent.php', 'icon' => 'fa-clock'],
        'agent_hours'     => ['label' => 'Mis Horas', 'href' => $basePath . 'agents/mis_horas.php', 'icon' => 'fa-business-time'],
    ],
    'Solicitudes' => [
        'agent_permissions'  => ['label' => 'Solicitar Permiso', 'href' => $basePath . 'agents/request_permission.php', 'icon' => 'fa-calendar-check'],
        'agent_vacations'    => ['label' => 'Solicitar Vacaciones', 'href' => $basePath . 'agents/request_vacation.php', 'icon' => 'fa-umbrella-beach'],
        'agent_loans'        => ['label' => 'Mis Préstamos', 'href' => $basePath . 'agents/my_loans.php', 'icon' => 'fa-money-check-alt'],
        'agent_request_loan' => ['label' => 'Solicitar Préstamo', 'href' => $basePath . 'agents/request_loan.php', 'icon' => 'fa-hand-holding-usd'],
    ],
    'Soporte' => [
        'helpdesk_tickets'     => ['label' => 'Mis Tickets', 'href' => $basePath . 'agents/helpdesk_tickets.php', 'icon' => 'fa-ticket-alt'],
        'helpdesk_suggestions' => ['label' => 'Buzón de Sugerencias', 'href' => $basePath . 'agents/suggestions.php', 'icon' => 'fa-lightbulb'],
    ],
];
$alwaysShowAgent = ['agent_permissions', 'agent_vacations', 'agent_quality', 'agent_hours', 'agent_loans', 'agent_request_loan'];

$enforceExitBeforeLogout = (($_SESSION['role'] ?? '') === 'AGENT');
$hasExitPunchToday = true;

if ($enforceExitBeforeLogout) {
    $exitSlug = sanitizeAttendanceTypeSlug('EXIT');
    if ($exitSlug !== '') {
        try {
            $stmt = $pdo->prepare("
                SELECT 1 FROM attendance
                WHERE user_id = ? AND DATE(timestamp) = CURDATE() AND UPPER(type) = ?
                LIMIT 1
            ");
            $stmt->execute([(int) $_SESSION['user_id'], $exitSlug]);
            $hasExitPunchToday = $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            $hasExitPunchToday = true;
        }
    }
}

$currentPath = basename($_SERVER['PHP_SELF']);
$userDisplayName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Agente');
$userRole = $_SESSION['role'] ?? 'Agente';
$userInitials = 'EV';
if ($userDisplayName !== '') {
    $parts = preg_split('/\s+/', trim($userDisplayName));
    $userInitials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
$themeVer = @filemtime(__DIR__ . '/assets/css/theme.css') ?: '2';
$agentCssVer = @filemtime(__DIR__ . '/assets/css/agent-portal.css') ?: '1';
$appJsVer = @filemtime(__DIR__ . '/assets/js/app.js') ?: '2';
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
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($assetBase) ?>/css/theme.css?v=<?= $themeVer ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars($assetBase) ?>/css/agent-portal.css?v=<?= $agentCssVer ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars($assetBase) ?>/css/chat.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="<?= htmlspecialchars($assetBase) ?>/js/chart-theme.js?v=<?= @filemtime(__DIR__ . '/assets/js/chart-theme.js') ?>"></script>
    <script src="<?= htmlspecialchars($assetBase) ?>/js/app.js?v=<?= $appJsVer ?>" defer></script>
    <?php if (isset($_SESSION['user_id'])): ?>
    <script>
        const currentUserId = <?= (int)$_SESSION['user_id'] ?>;
        const currentUserRole = <?= json_encode($_SESSION['role'] ?? 'agent') ?>;
        <?php
        $chatPermsStmt = $pdo->prepare("SELECT can_create_groups FROM chat_permissions WHERE user_id = ?");
        $chatPermsStmt->execute([$_SESSION['user_id']]);
        $chatPerms = $chatPermsStmt->fetch();
        $canCreateGroups = $chatPerms ? (bool)$chatPerms['can_create_groups'] : false;
        ?>
        const canCreateGroups = <?= json_encode($canCreateGroups) ?>;
        window.currentUserId = currentUserId;
        window.currentUserRole = currentUserRole;
        window.canCreateGroups = canCreateGroups;
    </script>
    <?php $pollCfg = getPollingConfig($pdo); ?>
    <script>
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
    <title>Área de Agentes · Evallish BPO</title>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?><?= $sidebarCollapsed ? ' sidebar-collapsed' : '' ?>">
    <div class="sidebar-overlay" data-sidebar-overlay></div>

    <aside class="app-sidebar" id="appSidebar" aria-label="Navegación de agente">
        <div class="app-sidebar__brand">
            <span class="app-sidebar__logo">
                <img src="<?= htmlspecialchars($assetBase) ?>/logo.png" alt="Evallish BPO">
            </span>
            <span class="app-sidebar__wordmark">
                <strong>Evallish BPO</strong>
                <span>Portal de Agente</span>
            </span>
        </div>

        <nav class="app-sidebar__nav" data-sidebar-nav>
            <?php foreach ($agentNavSections as $sectionLabel => $items): ?>
                <?php
                $rendered = '';
                foreach ($items as $sectionKey => $item) {
                    $showLink = in_array($sectionKey, $alwaysShowAgent, true) || userHasPermission($sectionKey);
                    if (!$showLink) {
                        continue;
                    }
                    $active = $currentPath === basename($item['href']) ? ' is-active' : '';
                    $rendered .= '<a href="' . htmlspecialchars($item['href']) . '" class="sidebar-link' . $active . '">'
                        . '<i class="fas ' . htmlspecialchars($item['icon']) . ' sidebar-link__icon"></i>'
                        . '<span class="sidebar-link__text">' . htmlspecialchars($item['label']) . '</span></a>';
                }
                if ($rendered !== ''):
                    ?>
                    <div class="sidebar-section-label"><?= htmlspecialchars($sectionLabel) ?></div>
                    <?= $rendered ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="app-sidebar__footer">
            <div class="sidebar-userchip">
                <span class="sidebar-userchip__avatar"><?= htmlspecialchars($userInitials) ?></span>
                <span class="sidebar-userchip__meta">
                    <strong><?= htmlspecialchars($userDisplayName) ?></strong>
                    <span><?= htmlspecialchars($userRole) ?></span>
                </span>
            </div>
            <a href="<?= $basePath ?>logout_agent.php" class="sidebar-logout" data-agent-logout>
                <i class="fas fa-sign-out-alt"></i>
                <span>Cerrar Sesión</span>
            </a>
        </div>
    </aside>

    <header class="app-topbar">
        <button type="button" class="topbar-btn" data-sidebar-mobile aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
        <button type="button" class="topbar-btn" data-sidebar-collapse aria-label="Colapsar menú"><i class="fas fa-bars-staggered"></i></button>

        <div class="topbar-search" role="search">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" placeholder="Buscar en el menú…" data-sidebar-search aria-label="Buscar en el menú">
        </div>

        <div class="topbar-spacer"></div>

        <form action="<?= $basePath ?>theme_toggle.php" method="post" class="inline-flex">
            <button type="submit" class="topbar-action" title="<?= htmlspecialchars($themeLabel) ?>">
                <i class="fas <?= $themeIcon ?>"></i>
                <span class="hidden sm:inline"><?= htmlspecialchars($themeLabel) ?></span>
            </button>
        </form>
    </header>

    <?php if ($enforceExitBeforeLogout): ?>
    <script>
    (function () {
        const hasExitToday = <?= $hasExitPunchToday ? 'true' : 'false' ?>;
        document.addEventListener('DOMContentLoaded', function () {
            const logoutLink = document.querySelector('[data-agent-logout]');
            if (!logoutLink) { return; }
            logoutLink.addEventListener('click', function (event) {
                if (!hasExitToday) {
                    event.preventDefault();
                    alert('Debes registrar tu salida (EXIT) antes de cerrar sesion.');
                }
            });
        });
    })();
    </script>
    <?php endif; ?>

    <main class="app-shell agent-shell">
