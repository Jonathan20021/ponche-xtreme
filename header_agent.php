<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || !userHasPermission('agent_dashboard')) {
    header('Location: login_agent.php');
    exit;
}

// Determine base path for links / assets (position-independent so funciona en
// producción (raíz web) y en localhost bajo /ponche-xtreme/).
$inSubdir = (strpos($_SERVER['PHP_SELF'], '/agents/') !== false ||
             strpos($_SERVER['PHP_SELF'], '/chat/') !== false);
$basePath = $inSubdir ? '../' : '';
$assetBase = $inSubdir ? '../assets' : 'assets';

$agentNavItems = [
    'agent_dashboard' => ['label' => 'Panel de Control', 'href' => $basePath . 'agent_dashboard.php', 'icon' => 'fa-house-chimney'],
    'agent_quality' => ['label' => 'Calidad', 'href' => $basePath . 'agent_quality.php', 'icon' => 'fa-star'],
    'agent_records' => ['label' => 'Registros', 'href' => $basePath . 'agent.php', 'icon' => 'fa-clock'],
    'agent_hours' => ['label' => 'Mis Horas', 'href' => $basePath . 'agents/mis_horas.php', 'icon' => 'fa-business-time'],
    'agent_calls' => ['label' => 'Mis Llamadas', 'href' => $basePath . 'agent_calls.php', 'icon' => 'fa-headphones'],
    'agent_permissions' => ['label' => 'Solicitar Permiso', 'href' => $basePath . 'agents/request_permission.php', 'icon' => 'fa-calendar-check'],
    'agent_vacations' => ['label' => 'Solicitar Vacaciones', 'href' => $basePath . 'agents/request_vacation.php', 'icon' => 'fa-umbrella-beach'],
    'agent_loans' => ['label' => 'Mis Préstamos', 'href' => $basePath . 'agents/my_loans.php', 'icon' => 'fa-money-check-dollar'],
    'agent_request_loan' => ['label' => 'Solicitar Préstamo', 'href' => $basePath . 'agents/request_loan.php', 'icon' => 'fa-hand-holding-dollar'],
    'helpdesk_tickets' => ['label' => 'Mis Tickets', 'href' => $basePath . 'agents/helpdesk_tickets.php', 'icon' => 'fa-ticket'],
    'helpdesk_suggestions' => ['label' => 'Buzón de Sugerencias', 'href' => $basePath . 'agents/suggestions.php', 'icon' => 'fa-lightbulb'],
];

// Grupos del sidebar (estilo Nexus)
$agentNavGroups = [
    'General'     => ['agent_dashboard', 'agent_quality', 'agent_records', 'agent_hours', 'agent_calls'],
    'Solicitudes' => ['agent_permissions', 'agent_vacations', 'agent_loans', 'agent_request_loan'],
    'Soporte'     => ['helpdesk_tickets', 'helpdesk_suggestions'],
];

// La marcación manual del ponche se retiró del portal (la asistencia viene de
// Vicidial), así que ya no se exige marcar EXIT antes de cerrar sesión.
$enforceExitBeforeLogout = false;
$hasExitPunchToday = true;

if ($enforceExitBeforeLogout) {
    $exitSlug = sanitizeAttendanceTypeSlug('EXIT');

    if ($exitSlug !== '') {
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM attendance
                WHERE user_id = ?
                  AND DATE(timestamp) = CURDATE()
                  AND UPPER(type) = ?
                LIMIT 1
            ");
            $stmt->execute([(int) $_SESSION['user_id'], $exitSlug]);
            $hasExitPunchToday = $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            $hasExitPunchToday = true;
        }
    }
}

// La marcación manual del ponche se retiró (la asistencia viene de Vicidial),
// así que el estado del topbar/sidebar es de sesión, no del ponche.
$agentShiftLabel = 'Sesión activa';
$agentShiftState = 'on';

// Identidad del agente para el topbar / sidebar
$agentFullName = trim($_SESSION['full_name'] ?? 'Agente');
$agentRoleLabel = $_SESSION['role'] ?? 'Agente';
$agentNameParts = preg_split('/\s+/', $agentFullName);
$agentInitials = strtoupper(mb_substr($agentNameParts[0] ?? 'A', 0, 1) . (isset($agentNameParts[1]) ? mb_substr($agentNameParts[1], 0, 1) : ''));
if ($agentInitials === '') { $agentInitials = 'A'; }

$mesesCortos = [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];
$agentDatePill = date('d') . ' ' . $mesesCortos[(int) date('n')] . ' ' . date('Y');

$currentPath = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($assetBase) ?>/css/theme.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($assetBase) ?>/css/chat.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($assetBase) ?>/css/agent-theme.css?v=<?= @filemtime(__DIR__ . '/assets/css/agent-theme.css') ?: '1' ?>" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="<?= htmlspecialchars($assetBase) ?>/js/app.js" defer></script>
    <?php if (isset($_SESSION['user_id'])): ?>
    <script>
        const currentUserId = <?= (int)$_SESSION['user_id'] ?>;
        const currentUserRole = <?= json_encode($_SESSION['role'] ?? 'agent') ?>;
        <?php
        // Obtener permisos de chat del usuario
        $chatPermsStmt = $pdo->prepare("SELECT can_create_groups FROM chat_permissions WHERE user_id = ?");
        $chatPermsStmt->execute([$_SESSION['user_id']]);
        $chatPerms = $chatPermsStmt->fetch();
        $canCreateGroups = $chatPerms ? (bool)$chatPerms['can_create_groups'] : false; // Por defecto false para agentes
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
    <script src="<?= htmlspecialchars($assetBase) ?>/js/chat.js" defer></script>
    <?php endif; ?>
    <title>Área de Agentes · Evallish BPO</title>
</head>
<body class="agent-ui theme-light">
    <script>/* aplica el estado colapsado antes de pintar para evitar parpadeo */try{if(localStorage.getItem('agNavCollapsed')==='1'&&window.innerWidth>1024)document.body.classList.add('nav-collapsed');}catch(e){}</script>

    <!-- ===================== SIDEBAR ===================== -->
    <aside class="ag-sidebar" id="agSidebar">
        <a href="<?= htmlspecialchars($basePath) ?>agent_dashboard.php" class="ag-brand">
            <img src="<?= htmlspecialchars($assetBase) ?>/logo.png" alt="Evallish BPO"
                 onerror="this.style.display='none';">
            <div class="ag-name">Evall<span>ish</span></div>
        </a>

        <nav>
            <?php foreach ($agentNavGroups as $groupLabel => $keys): ?>
                <?php
                    // ¿El grupo tiene al menos un enlace visible?
                    $visibleKeys = [];
                    foreach ($keys as $sectionKey) {
                        if (!isset($agentNavItems[$sectionKey])) { continue; }
                        $showLink = in_array($sectionKey, ['agent_permissions', 'agent_vacations', 'agent_quality', 'agent_hours', 'agent_calls', 'agent_loans', 'agent_request_loan'], true)
                            || userHasPermission($sectionKey);
                        if ($showLink) { $visibleKeys[] = $sectionKey; }
                    }
                    if (empty($visibleKeys)) { continue; }
                ?>
                <div class="ag-navgroup">
                    <div class="ag-lbl"><?= htmlspecialchars($groupLabel) ?></div>
                    <?php foreach ($visibleKeys as $sectionKey): ?>
                        <?php
                            $item = $agentNavItems[$sectionKey];
                            $isActive = $currentPath === basename($item['href']);
                        ?>
                        <a href="<?= htmlspecialchars($item['href']) ?>" class="ag-nav<?= $isActive ? ' active' : '' ?>" data-label="<?= htmlspecialchars($item['label']) ?>">
                            <i class="fas <?= htmlspecialchars($item['icon']) ?>"></i>
                            <span><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="ag-side-status">
            <div class="t1"><span class="ag-dot"></span> <?= htmlspecialchars($agentShiftLabel) ?></div>
            <div class="t2"><?= htmlspecialchars($agentFullName) ?></div>
            <div class="t3"><?= htmlspecialchars($agentRoleLabel) ?></div>
        </div>
        <a href="<?= htmlspecialchars($basePath) ?>logout_agent.php" class="ag-logout" data-agent-logout data-label="Cerrar sesión">
            <i class="fas fa-arrow-right-from-bracket"></i> <span>Cerrar sesión</span>
        </a>
    </aside>

    <!-- ===================== TOPBAR ===================== -->
    <header class="ag-topbar">
        <button type="button" class="ag-burger" id="agBurger" aria-label="Menú"><i class="fas fa-bars"></i></button>
        <div class="ag-search" id="agSearch">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" id="agSearchInput" placeholder="Buscar página…" aria-label="Buscar página"
                   autocomplete="off" role="combobox" aria-expanded="false" aria-controls="agSearchResults" aria-autocomplete="list">
            <kbd class="ag-kbd">Ctrl K</kbd>
            <div class="ag-search-results" id="agSearchResults" role="listbox" aria-label="Resultados"></div>
        </div>
        <div class="ag-spacer"></div>
        <div class="ag-pill hide-sm"><i class="fas fa-calendar-day"></i> <?= htmlspecialchars($agentDatePill) ?></div>
        <div class="ag-pill <?= $agentShiftState === 'on' ? 'on-shift' : 'off-shift' ?>">
            <span class="live"></span> <?= htmlspecialchars($agentShiftLabel) ?>
        </div>
        <div class="ag-user">
            <div class="av"><?= htmlspecialchars($agentInitials) ?></div>
            <div class="hide-sm">
                <div class="nm"><?= htmlspecialchars($agentFullName) ?></div>
                <div class="rl">Agente · <?= htmlspecialchars($agentRoleLabel) ?></div>
            </div>
        </div>
    </header>

    <div class="ag-scrim" id="agScrim"></div>

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
    <script>
    // Toggle del sidebar en móvil
    (function () {
        const body = document.body;
        const burger = document.getElementById('agBurger');
        const scrim = document.getElementById('agScrim');
        if (burger) {
            burger.addEventListener('click', function () {
                if (window.innerWidth > 1024) {
                    // Desktop: colapsar/expandir el sidebar a rail de iconos
                    body.classList.toggle('nav-collapsed');
                    try { localStorage.setItem('agNavCollapsed', body.classList.contains('nav-collapsed') ? '1' : '0'); } catch (e) {}
                } else {
                    // Móvil: abrir/cerrar el drawer
                    body.classList.toggle('nav-open');
                }
            });
        }
        if (scrim) { scrim.addEventListener('click', function () { body.classList.remove('nav-open'); }); }
        document.querySelectorAll('.ag-sidebar .ag-nav').forEach(function (a) {
            a.addEventListener('click', function () { body.classList.remove('nav-open'); });
        });
    })();
    </script>

    <script>
    // Buscador del topbar → quick-nav de páginas (teclado + Ctrl/Cmd+K).
    // Indexa los enlaces reales del sidebar, así respeta permisos y nunca queda desincronizado.
    (function () {
        const wrap = document.getElementById('agSearch');
        const input = document.getElementById('agSearchInput');
        const box = document.getElementById('agSearchResults');
        if (!wrap || !input || !box) { return; }

        const items = Array.prototype.map.call(
            document.querySelectorAll('.ag-sidebar .ag-nav'),
            function (a) {
                const grp = a.closest('.ag-navgroup');
                const iconEl = a.querySelector('i');
                return {
                    label: (a.getAttribute('data-label') || a.textContent || '').trim(),
                    href: a.getAttribute('href') || '#',
                    icon: iconEl ? iconEl.className : 'fas fa-file',
                    group: grp && grp.querySelector('.ag-lbl') ? grp.querySelector('.ag-lbl').textContent.trim() : ''
                };
            }
        );

        let shown = [], active = -1;
        const norm = function (s) {
            return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        };
        const esc = function (s) {
            return (s || '').replace(/[&<>"']/g, function (c) {
                return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
            });
        };

        function open() { box.classList.add('open'); input.setAttribute('aria-expanded', 'true'); }
        function close() { box.classList.remove('open'); input.setAttribute('aria-expanded', 'false'); active = -1; }

        function render(q) {
            const nq = norm(q);
            shown = nq ? items.filter(function (it) { return norm(it.label).indexOf(nq) !== -1 || norm(it.group).indexOf(nq) !== -1; }) : items.slice();
            active = shown.length ? 0 : -1;
            if (!shown.length) {
                box.innerHTML = '<div class="ag-search-empty"><i class="fas fa-magnifying-glass" style="opacity:.5;margin-right:6px;"></i>Sin resultados para “' + esc(q) + '”</div>';
            } else {
                box.innerHTML = shown.map(function (it, i) {
                    return '<a href="' + esc(it.href) + '" class="ag-search-res' + (i === active ? ' active' : '') + '" role="option" data-i="' + i + '">' +
                        '<span class="ic"><i class="' + esc(it.icon) + '"></i></span>' +
                        '<span class="lb">' + esc(it.label) + (it.group ? ' <span class="gp">· ' + esc(it.group) + '</span>' : '') + '</span>' +
                        '<span class="go"><i class="fas fa-arrow-turn-down fa-rotate-90"></i> Ir</span></a>';
                }).join('');
            }
            open();
        }

        function paintActive() {
            const els = box.querySelectorAll('.ag-search-res');
            els.forEach(function (el, i) { el.classList.toggle('active', i === active); });
            if (els[active]) { els[active].scrollIntoView({ block: 'nearest' }); }
        }
        function go() { if (shown[active]) { window.location.href = shown[active].href; } }

        input.addEventListener('focus', function () { render(input.value); });
        input.addEventListener('input', function () { render(input.value); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (shown.length) { active = (active + 1) % shown.length; paintActive(); } }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (shown.length) { active = (active - 1 + shown.length) % shown.length; paintActive(); } }
            else if (e.key === 'Enter') { e.preventDefault(); go(); }
            else if (e.key === 'Escape') { close(); input.blur(); }
        });
        // mousedown (no click) para navegar antes de que el blur cierre el panel
        box.addEventListener('mousedown', function (e) {
            const res = e.target.closest('.ag-search-res');
            if (res) { e.preventDefault(); active = parseInt(res.getAttribute('data-i'), 10); go(); }
        });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) { close(); } });
        document.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); input.focus(); input.select(); }
        });
    })();
    </script>

    <main class="app-shell agent-shell">
