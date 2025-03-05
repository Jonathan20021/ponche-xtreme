<?php
session_start();
include 'db.php'; // Archivo de conexión a la base de datos

$error = '';

// Verificar si se ha enviado el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if ($username && $password) {
        // Consultar los datos del usuario
        $query = "SELECT id, username, full_name, role, password FROM users WHERE username = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['password'] === $password) { // Comparar directamente la contraseña sin hash
            // Verificar si el rol del usuario está permitido
            if (in_array($user['role'], ['AGENT', 'IT', 'Supervisor'])) {
                // Configurar la sesión
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                // Redirigir al dashboard del empleado
                header('Location: agent_dashboard.php');
                exit;
            } else {
                $error = 'No tienes permisos para acceder.';
            }
        } else {
            $error = 'Nombre de usuario o contraseña incorrectos.';
        }
    } else {
        $error = 'Por favor, completa todos los campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <title>Agent Login</title>
</head>
<body class="bg-gradient-to-r from-blue-500 via-blue-600 to-blue-700 text-gray-800 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md">
        <h2 class="text-3xl font-bold mb-6 text-center text-blue-600">
            <i class="fas fa-user-lock"></i> Agent Login
        </h2>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-800 p-3 rounded-lg mb-4">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <form method="POST" action="login_agent.php">
            <div class="mb-5">
                <label for="username" class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                <input type="text" name="username" id="username" placeholder="Enter your username" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" required>
            </div>
            <div class="mb-5">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                <input type="password" name="password" id="password" placeholder="Enter your password" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" required>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg shadow hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                Login
            </button>
        </form>
        <p class="mt-4 text-center text-sm text-gray-500">
    <span class="text-gray-600">Forgot your password?</span>
    <a href="password_recovery.php" class="text-blue-600 font-medium hover:underline focus:outline-none focus:ring-2 focus:ring-blue-500">
        Recover it here
    </a>
    or <a href="mailto:support@example.com" class="text-green-600 font-medium hover:underline focus:outline-none focus:ring-2 focus:ring-green-500">
        contact support
    </a>.
</p>

    </div>
</body>
</html>
