<?php
session_start();
include 'db.php';

if (isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['AGENT', 'IT', 'Supervisor'], true)) {
    header('Location: agent_dashboard.php');
    exit;
}

$error = '';

$theme = $_SESSION['theme'] ?? 'dark';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'dark';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$themeLabel = $theme === 'light' ? 'Modo Oscuro' : 'Modo Claro';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username !== '' && $password !== '') {
        $stmt = $pdo->prepare("SELECT id, username, full_name, role, password FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['password'] === $password) {
            if (in_array($user['role'], ['AGENT', 'IT', 'Supervisor'], true)) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                header('Location: agent_dashboard.php');
                exit;
            }
            $error = 'No tienes permisos para acceder.';
        } else {
            $error = 'Credenciales invalidas.';
        }
    } else {
        $error = 'Por favor completa todos los campos.';
    }
}
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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/theme.css" rel="stylesheet">
    <title>Ingreso de agentes</title>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <div class="login-wrapper">
        <div class="login-card glass-card" style="width: min(400px, 100%);">
            <div class="text-center mb-5">
                <span class="tag-pill">Acceso agente</span>
                <h2 class="mt-3 font-semibold text-white">Bienvenido de nuevo</h2>
                <p class="text-sm text-slate-400">Ingresa tu usuario y contrasena para registrar tus actividades.</p>
            </div>
            <?php if ($error): ?>
                <div class="status-banner error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <div class="form-group">
                    <label for="username">Usuario</label>
                    <input type="text" name="username" id="username" autocomplete="username" required placeholder="ej. agente01">
                </div>
                <div class="form-group">
                    <label for="password">Contrasena</label>
                    <input type="password" name="password" id="password" autocomplete="current-password" required placeholder="Tu contrasena">
                </div>
                <button type="submit" class="w-full btn-primary justify-center">
                    <i class="fas fa-sign-in-alt"></i>
                    Entrar
                </button>
            </form>
        </div>
        <form action="theme_toggle.php" method="post" class="mt-6">
            <button type="submit" class="btn-secondary px-4 py-2 rounded-lg inline-flex items-center gap-2">
                <i class="fas fa-adjust text-sm"></i>
                <span><?= htmlspecialchars($themeLabel) ?></span>
            </button>
        </form>
    </div>
</body>
</html>

