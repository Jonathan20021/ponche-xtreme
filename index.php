<?php
session_start();
include 'db.php';

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $query = "SELECT * FROM users WHERE username = ? AND role IN ('Admin', 'OperationsManager', 'IT', 'HR', 'GeneralManager', 'Supervisor')";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && $password === $user['password']) {
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
        
        if ($accessiblePage === null) {
            // User has no access to any page
            session_destroy();
            $error = "Tu cuenta no tiene permisos para acceder a ninguna seccion del sistema.";
        } else {
            header('Location: ' . $accessiblePage);
            exit;
        }
    } else {
        $error = "Credenciales invalidas o permisos insuficientes.";
    }
}

include 'header.php';
?>
    <section class="login-wrapper">
        <div class="login-card glass-card">
            <div class="text-center mb-5">
                <span class="tag-pill">Portal Administrativo</span>
                <h2 class="mt-3 font-semibold text-white text-balance">Inicia sesion para acceder al panel de control</h2>
            </div>
            <?php if (isset($error)): ?>
                <div class="status-banner error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <div class="form-group">
                    <label for="username">Usuario</label>
                    <input type="text" name="username" id="username" autocomplete="username" required placeholder="ej. admin">
                </div>
                <div class="form-group">
                    <label for="password">Contrasena</label>
                    <input type="password" name="password" id="password" autocomplete="current-password" required placeholder="Tu contrasena">
                </div>
                <button type="submit" name="login" class="w-full btn-primary justify-center">
                    <i class="fas fa-unlock-alt"></i>
                    Ingresar
                </button>
            </form>
        </div>
    </section>
<?php include 'footer.php'; ?>



