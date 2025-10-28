<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || !userHasPermission('agent_dashboard')) {
    header('Location: login_agent.php');
    exit;
}

$theme = $_SESSION['theme'] ?? 'dark';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'dark';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$themeLabel = $theme === 'light' ? 'Modo Oscuro' : 'Modo Claro';

$assetBase = (strpos($_SERVER['PHP_SELF'], '/agents/') === 0) ? '../assets' : 'assets';

$agentNavItems = [
    'agent_dashboard' => ['label' => 'Dashboard', 'href' => 'agent_dashboard.php', 'icon' => 'fa-house-user'],
    'agent_records' => ['label' => 'Records', 'href' => 'agent.php', 'icon' => 'fa-clock'],
];

$currentPath = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($assetBase) ?>/css/theme.css" rel="stylesheet">
    <script src="<?= htmlspecialchars($assetBase) ?>/js/app.js" defer></script>
    <title>Agent Area</title>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <header class="bg-gradient-to-r from-slate-900 via-slate-900 to-slate-800 border-b border-slate-800 shadow-lg shadow-black/40">
        <div class="max-w-5xl mx-auto px-6 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center justify-between gap-3 w-full md:w-auto">
                <div class="flex items-center gap-3">
                    <div class="h-9 w-9 rounded-xl bg-emerald-500/20 border border-emerald-500/40 flex items-center justify-center text-emerald-300">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <h1 class="brand-title text-lg font-semibold">Evallish BPO Agents</h1>
                        <p class="brand-subtitle text-xs"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?></p>
                    </div>
                </div>
                <button type="button"
                        class="mobile-nav-toggle md:hidden"
                        data-nav-toggle
                        data-nav-target="agent-nav"
                        aria-controls="agent-nav"
                        aria-expanded="false">
                    <i class="fas fa-bars"></i>
                    Menu
                </button>
            </div>

            <nav id="agent-nav" class="main-nav flex flex-wrap items-center gap-2" data-open="false" data-nav>
                <?php foreach ($agentNavItems as $sectionKey => $item): ?>
                    <?php if (userHasPermission($sectionKey)): ?>
                        <?php
                            $isActive = $currentPath === basename($item['href']);
                            $classes = $isActive
                                ? 'inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold bg-emerald-500/20 text-emerald-200 hover:bg-emerald-500/30 transition-colors'
                                : 'inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-slate-800/60 transition-colors';
                        ?>
                        <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $classes ?>">
                            <i class="fas <?= htmlspecialchars($item['icon']) ?> text-xs"></i>
                            <span><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a href="logout_agent.php" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm bg-rose-500/20 text-rose-200 hover:bg-rose-500/30 transition-colors">
                    <i class="fas fa-sign-out-alt text-xs"></i>
                    <span>Logout</span>
                </a>
                <form action="<?= strpos($_SERVER['PHP_SELF'], '/agents/') === 0 ? '../theme_toggle.php' : 'theme_toggle.php' ?>" method="post" class="inline-flex">
                    <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm bg-slate-800/70 text-slate-200 hover:bg-slate-700 transition-colors border border-slate-700/70">
                        <i class="fas fa-adjust text-xs"></i>
                        <span><?= htmlspecialchars($themeLabel) ?></span>
                    </button>
                </form>
            </nav>
        </div>
    </header>
    <main class="app-shell agent-shell">
