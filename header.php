<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

$navItems = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'fa-gauge'],
    'records' => ['label' => 'Records', 'href' => 'records.php', 'icon' => 'fa-table'],
    'records_qa' => ['label' => 'Records QA', 'href' => 'records_qa.php', 'icon' => 'fa-clipboard-check'],
    'view_admin_hours' => ['label' => 'Admin Hours', 'href' => 'view_admin_hours.php', 'icon' => 'fa-user-clock'],
    'hr_report' => ['label' => 'HR Report', 'href' => 'hr_report.php', 'icon' => 'fa-briefcase'],
    'adherence_report' => ['label' => 'Adherence', 'href' => 'adherencia_report_hr.php', 'icon' => 'fa-chart-line'],
    'operations_dashboard' => ['label' => 'Operations', 'href' => 'operations_dashboard.php', 'icon' => 'fa-sitemap'],
    'register_attendance' => ['label' => 'Register Hours', 'href' => 'register_attendance.php', 'icon' => 'fa-calendar-plus'],
    'login_logs' => ['label' => 'Login Logs', 'href' => 'login_logs.php', 'icon' => 'fa-shield-alt'],
    'settings' => ['label' => 'Settings', 'href' => 'settings.php', 'icon' => 'fa-sliders-h'],
];

$theme = $_SESSION['theme'] ?? 'dark';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'dark';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$themeLabel = $theme === 'light' ? 'Modo Oscuro' : 'Modo Claro';

$assetBase = (strpos($_SERVER['PHP_SELF'], '/agents/') === 0) ? '../assets' : 'assets';

$isAuthenticated = isset($_SESSION['user_id']);
$currentPath = basename($_SERVER['PHP_SELF']);
$userDisplayName = '';
if ($isAuthenticated) {
    $userDisplayName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
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
                        <h1 class="text-xl font-semibold text-white">Evallish BPO Control</h1>
                        <?php if ($userDisplayName): ?>
                            <p class="text-xs text-slate-400">Bienvenido, <?= htmlspecialchars($userDisplayName) ?></p>
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
                    Menu
                </button>
            </div>

            <nav id="primary-nav" class="main-nav flex flex-wrap items-center gap-2" data-open="false" data-nav>
                <?php if ($isAuthenticated): ?>
                    <?php foreach ($navItems as $sectionKey => $item): ?>
                        <?php if (userHasPermission($sectionKey)): ?>
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
                    <a href="logout.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm bg-rose-500/20 text-rose-200 hover:bg-rose-500/30 transition-colors">
                        <i class="fas fa-sign-out-alt text-xs"></i>
                        <span>Logout</span>
                    </a>
                <?php else: ?>
                    <a href="index.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-slate-800/60 transition-colors">
                        <i class="fas fa-sign-in-alt text-xs"></i>
                        <span>Login</span>
                    </a>
                <?php endif; ?>
                <form action="theme_toggle.php" method="post" class="inline-flex">
                    <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm bg-slate-800/70 text-slate-200 hover:bg-slate-700 transition-colors border border-slate-700/70">
                        <i class="fas fa-adjust text-xs"></i>
                        <span><?= htmlspecialchars($themeLabel) ?></span>
                    </button>
                </form>
            </nav>
        </div>
    </header>
    <main class="app-shell">
