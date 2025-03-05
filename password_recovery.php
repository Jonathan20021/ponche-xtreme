<?php
session_start();
include 'db.php'; // Archivo de conexión a la base de datos

$error = '';
$success = '';
$resetLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);

    if ($username) {
        // Buscar al usuario en la base de datos
        $query = "SELECT id FROM users WHERE username = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Generar un token único y fecha de expiración
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Actualizar el token en la base de datos
            $updateQuery = "UPDATE users SET reset_token = ?, token_expiry = ? WHERE username = ?";
            $updateStmt = $pdo->prepare($updateQuery);
            $updateStmt->execute([$token, $expiry, $username]);

            // Crear enlace de recuperación
            $resetLink = "http://{$_SERVER['HTTP_HOST']}/ponche/reset_password.php?token=$token&user=$username";

            $success = "A recovery link has been generated.";
        } else {
            $error = 'Username not found.';
        }
    } else {
        $error = 'Please enter your username.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <title>Password Recovery</title>
</head>
<body class="bg-gray-100 text-gray-800 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded shadow-md w-96">
        <h2 class="text-2xl font-bold mb-4 text-center">Password Recovery</h2>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-800 p-2 mb-4 rounded">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-100 text-green-800 p-2 mb-4 rounded">
                <?= htmlspecialchars($success) ?>
            </div>
            <div class="text-center mt-4">
                <a href="<?= htmlspecialchars($resetLink) ?>" class="bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-700 inline-block">
                    Reset Password
                </a>
            </div>
        <?php else: ?>
            <form method="POST" action="password_recovery.php">
                <div class="mb-4">
                    <label for="username" class="block text-sm font-bold mb-2">Username</label>
                    <input type="text" name="username" id="username" class="p-2 border rounded w-full" required>
                </div>
                <button type="submit" class="w-full bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-700">
                    Recover Password
                </button>
            </form>
        <?php endif; ?>
        <p class="mt-4 text-center text-sm text-gray-500">
            Remembered it? <a href="login_agent.php" class="text-blue-600 hover:underline">Go back to login</a>
        </p>
    </div>
</body>
</html>
