<?php
session_start();
$section = isset($_GET['section']) ? htmlspecialchars($_GET['section']) : 'esta sección';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso no autorizado</title>
    <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body class="theme-light">
    <main class="unauthorized-page">
        <div class="unauthorized-card">
            <h1>Acceso no autorizado</h1>
            <p>No tienes permisos para acceder a <strong><?= $section ?></strong>.</p>
            <a class="btn-primary" href="dashboard.php">Volver al inicio</a>
        </div>
    </main>
</body>
</html>
