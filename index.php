<?php
session_start();
include 'db.php';

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Validar si el usuario es administrativo
    $query = "SELECT * FROM users WHERE username = ? AND role IN ('Admin', 'OperationsManager', 'IT', 'HR', 'GeneralManager', 'Supervisor')";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && $password === $user['password']) { // Comparar directamente
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];

        // Capturar la dirección IP local
        $local_ip = $_SERVER['REMOTE_ADDR'] === '::1' ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];

        // Capturar la dirección IP pública
        $public_ip = "Unknown";
        try {
            $public_ip = file_get_contents("https://api64.ipify.org?format=json");
            $public_ip = json_decode($public_ip, true)['ip'];
        } catch (Exception $e) {
            error_log("Failed to fetch public IP: " . $e->getMessage());
        }

        // Determinar la ubicación mediante la API de ipinfo.io usando la IP pública
        $location = "Unknown";
        $api_token = "df9ab1b87c9150"; // Reemplaza con tu token de ipinfo.io
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

        // Registrar el inicio de sesión en la tabla
        $log_stmt = $pdo->prepare("
            INSERT INTO admin_login_logs (user_id, username, role, ip_address, location, login_time, public_ip) 
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
        $log_stmt->execute([$user['id'], $username, $user['role'], $local_ip, $location, $public_ip]);

        header('Location: dashboard.php');
        exit;
    } else {
        $error = "Invalid credentials or insufficient permissions.";
    }
}
?>




<!DOCTYPE html>
<html lang="en">
<?php include 'header.php'; ?>
<body class="bg-gray-100">
    <div class="container mx-auto mt-10 max-w-md">
        <div class="bg-white shadow-md rounded-lg p-6">
            <h2 class="text-2xl font-bold text-center mb-4">Administrative Login</h2>
            <?php if (isset($error)): ?>
                <div class="bg-red-100 text-red-800 p-3 rounded mb-4 text-center"><?= $error ?></div>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label for="username" class="block font-semibold text-gray-700 mb-1">Username:</label>
                    <input type="text" name="username" id="username" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" required>
                </div>
                <div>
                    <label for="password" class="block font-semibold text-gray-700 mb-1">Password:</label>
                    <input type="password" name="password" id="password" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" required>
                </div>
                <button type="submit" name="login" class="w-full bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600 transition">
                    Login
                </button>
            </form>
        </div>
    </div>
</body>
</html>
