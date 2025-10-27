<?php
include 'db.php';

$success = null;
$error = null;

if (isset($_POST['register'])) {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password = 'defaultpassword';
    $role = 'AGENT';

    if ($username === '' || $full_name === '') {
        $error = 'Todos los campos son obligatorios.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $exists = $stmt->fetch();

        if ($exists && (int)$exists['count'] > 0) {
            $error = "El usuario {$username} ya esta registrado.";
        } else {
            $insert = $pdo->prepare("INSERT INTO users (username, full_name, password, role) VALUES (?, ?, ?, ?)");
            $insert->execute([$username, $full_name, $password, $role]);
            $success = "Usuario {$username} creado correctamente. Puedes usar el portal de marcaciones.";
        }
    }
}

$theme = $_SESSION['theme'] ?? 'dark';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'dark';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$themeLabel = $theme === 'light' ? 'Modo Oscuro' : 'Modo Claro';
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
    <title>Registro de agentes</title>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <div class="login-wrapper">
        <div class="login-card glass-card" style="width: min(420px, 100%);">
            <div class="text-center mb-5">
                <span class="tag-pill">Alta de nuevo agente</span>
                <h2 class="mt-3 font-semibold text-white">Crea el usuario para el portal de marcaciones</h2>
                <p class="text-sm text-slate-400">Se asignara una contrasena por defecto. El agente podra actualizarla luego.</p>
            </div>
            <?php if ($error): ?>
                <div class="status-banner error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="status-banner success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <div class="form-group">
                    <label for="username">Usuario</label>
                    <input type="text" id="username" name="username" required placeholder="ej. agente02">
                </div>
                <div class="form-group">
                    <label for="full_name">Nombre completo</label>
                    <input type="text" id="full_name" name="full_name" required placeholder="Nombre y apellido">
                </div>
                <button type="submit" name="register" class="w-full btn-primary justify-center">
                    <i class="fas fa-user-plus"></i>
                    Registrar agente
                </button>
            </form>
            <div class="text-center mt-4 text-sm">
                <a href="punch.php" class="text-slate-300 hover:text-white transition-colors">Ir al portal de marcaciones</a>
            </div>
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
