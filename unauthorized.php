<?php
session_start();
include 'db.php';
include 'find_accessible_page.php';

$section = isset($_GET['section']) ? htmlspecialchars($_GET['section']) : 'esta seccion';

// Find the first accessible page for this user
$accessiblePage = findAccessiblePage();

// If no accessible page found, logout
if ($accessiblePage === null) {
    session_destroy();
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
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
            <a class="btn-primary" href="<?= htmlspecialchars($accessiblePage) ?>">Ir a una pagina permitida</a>
        </div>
    </main>
</body>
</html>
