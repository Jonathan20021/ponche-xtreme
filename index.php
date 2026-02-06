<?php
session_start();
include 'db.php';

// Auto-trigger cron jobs (absence report)
if (file_exists(__DIR__ . '/lib/auto_cron_trigger.php')) {
    include_once __DIR__ . '/lib/auto_cron_trigger.php';
}

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $query = "SELECT * FROM users WHERE username = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && $password === $user['password']) {
        // Check if user is active
        $isActive = isset($user['is_active']) ? (int)$user['is_active'] : 1;
        if ($isActive === 0) {
            $error = "Tu cuenta ha sido desactivada. Contacta al administrador.";
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];

            $local_ip = $_SERVER['REMOTE_ADDR'] === '::1' ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];

            $public_ip = "Unknown";
            try {
                $public_ip_response = file_get_contents("https://api64.ipify.org?format=json");
                $public_ip = json_decode($public_ip_response, true)['ip'];
            } catch (Exception $e) {
                error_log("Failed to fetch public IP: " . $e->getMessage());
            }

            $location = "Unknown";
            $api_token = "df9ab1b87c9150";
            $geo_url = "https://ipinfo.io/{$public_ip}/json?token={$api_token}";

            try {
                $response = file_get_contents($geo_url);
                $data = json_decode($response, true);

                if (!empty($data['city']) && !empty($data['region']) && !empty($data['country'])) {
                    $location = "{$data['city']}, {$data['region']}, {$data['country']}";
                }
            } catch (Exception $e) {
                error_log("Failed to fetch location data: " . $e->getMessage());
            }

            $log_stmt = $pdo->prepare("
                INSERT INTO admin_login_logs (user_id, username, role, ip_address, location, login_time, public_ip)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ");
            $log_stmt->execute([$user['id'], $username, $user['role'], $local_ip, $location, $public_ip]);

            // Store additional session data
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];

            // Find the first accessible page and redirect there
            include 'find_accessible_page.php';
            $accessiblePage = findAccessiblePage();
            
            // Debug logging
            error_log("LOGIN DEBUG - User: {$user['username']}, Role: '{$user['role']}', Accessible Page: " . ($accessiblePage ?? 'NULL'));
            
            if ($accessiblePage === null) {
                // User has no access to any page
                error_log("LOGIN ERROR - No accessible page found for user {$user['username']} with role '{$user['role']}'");
                session_destroy();
                $error = "Tu cuenta no tiene permisos para acceder a ninguna sección del sistema.";
            } else {
                error_log("LOGIN SUCCESS - Redirecting {$user['username']} to $accessiblePage");
                header('Location: ' . $accessiblePage);
                exit;
            }
        }
    } else {
        $error = "Credenciales invalidas.";
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
    <title>Evallish BPO - Sistema de Control de Asistencia</title>
</head>
<body>
    <div class="split-login-container">
        <div class="split-login-wrapper">
            <!-- Left Panel - Brand -->
            <div class="split-panel brand-panel">
                <div class="brand-content">
                    <img src="assets/logo.png" alt="Evallish BPO" class="panel-logo">
                    <h1 class="panel-title">Sistema de Control<br>de Asistencia</h1>
                    <p class="panel-description">Gestiona entradas, salidas y registros de personal con precisión y eficiencia.</p>
                    
                    <div class="features-list">
                        <div class="feature-item">
                            <i class="fas fa-clock"></i>
                            <span>Registro en tiempo real</span>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-chart-line"></i>
                            <span>Reportes y analíticas avanzadas</span>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-shield-alt"></i>
                            <span>Sistema seguro y confiable</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Panel - Login Form -->
            <div class="split-panel form-panel">
                <div class="form-content">
                    <div class="form-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    
                    <div class="form-header">
                        <span class="form-welcome">Bienvenido</span>
                        <h2 class="form-title">Acceso al sistema</h2>
                        <p class="form-subtitle">Ingresa tus credenciales para continuar</p>
                    </div>
                    
                    <?php if (isset($error)): ?>
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
                        
                        <button type="submit" name="login" class="split-submit-btn">
                            Iniciar sesión
                        </button>
                        
                        <a href="password_recovery.php" class="split-forgot-link">
                            <i class="fas fa-key"></i>
                            ¿Olvidaste tu contraseña?
                        </a>
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



