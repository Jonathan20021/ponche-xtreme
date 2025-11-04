<?php
session_start();
include_once 'find_accessible_page.php';
clearPermissionCache();
echo "Caché de permisos limpiada. <a href='dashboard.php'>Ir al Dashboard</a>";
?>
