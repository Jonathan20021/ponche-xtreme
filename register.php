<?php
include 'db.php';

if (isset($_POST['register'])) {
    $username = $_POST['username'];
    $full_name = $_POST['full_name']; // Capturar el nombre completo
    $password = 'defaultpassword'; // Contraseña predeterminada
    $role = 'AGENT'; // Rol predeterminado

    // Validar si el usuario ya existe
    $query = "SELECT COUNT(*) as count FROM users WHERE username = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$username]);
    $result = $stmt->fetch();

    if ($result['count'] > 0) {
        $error = "The username '$username' is already registered.";
    } else {
        // Registrar el nuevo usuario
        $stmt = $pdo->prepare("INSERT INTO users (username, full_name, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $full_name, $password, $role]);

        $success = "User '$username' registered successfully with role 'Agent'. You can now punch in.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <title>Register for Punch</title>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto mt-6">
        <h2 class="text-2xl font-bold mb-4 text-center">Register for Punch</h2>
        <div class="bg-white p-6 rounded shadow-md max-w-lg mx-auto">
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <?php if (isset($success)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-4">
                    <label for="username" class="block text-sm font-medium">Username:</label>
                    <input type="text" id="username" name="username" class="w-full mt-1 p-2 border rounded" required>
                </div>
                <div class="mb-4">
                    <label for="full_name" class="block text-sm font-medium">Full Name:</label>
                    <input type="text" id="full_name" name="full_name" class="w-full mt-1 p-2 border rounded" required>
                </div>
                <button type="submit" name="register" class="w-full bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-700">Register</button>
            </form>
            <div class="text-center mt-3">
                <a href="punch.php" class="text-blue-500 underline">Go to Punch</a>
            </div>
        </div>
    </div>
</body>
</html>

