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
        $stmt = $pdo->prepare("SELECT id, username, full_name, role, password, is_active FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['password'] === $password) {
            // Check if user is active
            $isActive = isset($user['is_active']) ? (int)$user['is_active'] : 1;
            if ($isActive === 0) {
                $error = 'Tu cuenta ha sido desactivada. Contacta al administrador.';
            } elseif (in_array($user['role'], ['AGENT', 'IT', 'Supervisor'], true)) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                header('Location: agent_dashboard.php');
                exit;
            } else {
                $error = 'No tienes permisos para acceder.';
            }
        } else {
            $error = 'Credenciales invalidas.';
        }
    } else {
        $error = 'Por favor completa todos los campos.';
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/theme.css" rel="stylesheet">
    <title>Acceso de Agentes - Evallish BPO</title>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <div class="split-login-container">
        <div class="split-login-wrapper">
            <!-- Left Panel - Brand -->
            <div class="split-panel brand-panel">
                <div class="brand-content">
                    <img src="assets/logo.png" alt="Evallish BPO" class="panel-logo">
                    <h1 class="panel-title">Portal de<br>Agentes</h1>
                    <p class="panel-description">Registra tus entradas, salidas y actividades diarias de manera rápida y sencilla.</p>
                    
                    <div class="features-list">
                        <div class="feature-item">
                            <i class="fas fa-fingerprint"></i>
                            <span>Registro rápido de asistencia</span>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-calendar-check"></i>
                            <span>Consulta tu historial</span>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-mobile-alt"></i>
                            <span>Acceso desde cualquier dispositivo</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Panel - Login Form -->
            <div class="split-panel form-panel">
                <div class="form-content">
                    <div class="form-icon">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    
                    <div class="form-header">
                        <span class="form-welcome">Bienvenido</span>
                        <h2 class="form-title">Acceso al sistema</h2>
                        <p class="form-subtitle">Ingresa tus credenciales para continuar</p>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="form-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="split-form">
                        <div class="form-field-split">
                            <label for="username">Usuario</label>
                            <input 
                                type="text" 
                                name="username" 
                                id="username" 
                                autocomplete="username" 
                                required 
                                placeholder="usuario@empresa"
                            >
                        </div>
                        
                        <div class="form-field-split">
                            <label for="password">Contraseña</label>
                            <input 
                                type="password" 
                                name="password" 
                                id="password" 
                                autocomplete="current-password" 
                                required 
                                placeholder="••••••••"
                            >
                        </div>
                        
                        <button type="submit" class="split-submit-btn">
                            Iniciar sesión
                        </button>
                        
                        <a href="password_recovery_agent.php" class="split-forgot-link">
                            <i class="fas fa-key"></i>
                            ¿Olvidaste tu contraseña?
                        </a>
                    </form>
                    
                    <form action="theme_toggle.php" method="post" class="theme-switch-inline">
                        <button type="submit" class="theme-btn-inline">
                            <i class="fas fa-adjust"></i>
                            <?= htmlspecialchars($themeLabel) ?>
                        </button>
                    </form>
                    
                    <div class="form-footer-text">
                        <p>&copy; 2026 Evallish BPO. Todos los derechos reservados.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

