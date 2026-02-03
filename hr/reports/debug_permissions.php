<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Permisos</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e293b; color: #fff; }
        pre { background: #0f172a; padding: 15px; border-radius: 8px; overflow-x: auto; }
        h2 { color: #3b82f6; }
    </style>
</head>
<body>
    <h2>Debug de Sesión y Permisos</h2>
    
    <h3>Usuario ID:</h3>
    <pre><?= isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NO ESTABLECIDO' ?></pre>
    
    <h3>Permisos Actuales:</h3>
    <pre><?php print_r($_SESSION['permissions'] ?? 'NO HAY PERMISOS'); ?></pre>
    
    <h3>Toda la Sesión:</h3>
    <pre><?php print_r($_SESSION); ?></pre>
    
    <h3>Verificaciones:</h3>
    <pre>
¿Tiene user_id? <?= isset($_SESSION['user_id']) ? 'SÍ' : 'NO' ?>
¿Tiene permissions array? <?= isset($_SESSION['permissions']) ? 'SÍ' : 'NO' ?>
¿Tiene hr_dashboard? <?= in_array('hr_dashboard', $_SESSION['permissions'] ?? []) ? 'SÍ' : 'NO' ?>
    </pre>
</body>
</html>
