<?php
// Verifica si la sesión ya está activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Asegúrate de que solo roles permitidos accedan a esta página
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['AGENT', 'IT', 'Supervisor'])) {
    header('Location: login_agent.php'); // Redirige al login si no tiene permisos
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <title>Agent Dashboard</title>
</head>
<body class="bg-gray-100 text-gray-800">
    <header class="bg-gray-800 text-white p-4 shadow-md">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-lg font-bold"><i class="fas fa-user"></i> Employee Dashboard</h1>
            <nav>
                <a href="agent_dashboard.php" class="px-4 hover:underline">Dashboard</a>
                <a href="agent.php" class="px-4 hover:underline">Records</a>

                <a href="logout_agent.php" class="px-4 hover:underline">Logout</a>
            </nav>
        </div>
    </header>
</body>
</html>
