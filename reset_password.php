<?php
session_start();
include 'db.php'; // Archivo de conexión a la base de datos

// Validar token y usuario
if (!isset($_GET['token']) || !isset($_GET['user'])) {
    die('Invalid request.');
}

$token = $_GET['token'];
$username = $_GET['user'];
$error = '';
$success = '';

// Verificar el token en la base de datos
$query = "SELECT * FROM users WHERE username = ? AND reset_token = ? AND token_expiry > NOW()";
$stmt = $pdo->prepare($query);
$stmt->execute([$username, $token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Invalid or expired token.');
}

// Procesar formulario de cambio de contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($new_password && $confirm_password) {
        if ($new_password === $confirm_password) {
            // Actualizar la contraseña y eliminar el token
            $updateQuery = "UPDATE users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE username = ?";
            $updateStmt = $pdo->prepare($updateQuery);
            $updateStmt->execute([$new_password, $username]);

            $success = 'Your password has been updated successfully. <a href="login_agent.php" class="text-blue-600 hover:underline">Login here</a>';
        } else {
            $error = 'Passwords do not match.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <title>Reset Password</title>
</head>
<body class="bg-gray-100 text-gray-800 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded shadow-md w-96">
        <h2 class="text-2xl font-bold mb-4 text-center">Reset Password</h2>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-800 p-2 mb-4 rounded">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-100 text-green-800 p-2 mb-4 rounded">
                <?= $success ?>
            </div>
        <?php else: ?>
            <form method="POST" action="">
                <div class="mb-4">
                    <label for="password" class="block text-sm font-bold mb-2">New Password</label>
                    <input type="password" name="password" id="password" class="p-2 border rounded w-full" required>
                </div>
                <div class="mb-4">
                    <label for="confirm_password" class="block text-sm font-bold mb-2">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="p-2 border rounded w-full" required>
                </div>
                <button type="submit" class="w-full bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-700">
                    Reset Password
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>

