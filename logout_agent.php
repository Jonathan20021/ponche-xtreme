<?php
session_start();

// La marcación manual del ponche se retiró del portal del agente (la asistencia
// viene de Vicidial), así que YA NO se exige marcar EXIT antes de cerrar sesión.
// Antes, esta validación bloqueaba el logout y redirigía al dashboard en bucle
// ("solo se actualiza la página") porque el agente no podía marcar EXIT.
// Cierre de sesión directo y limpio:

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
}

session_destroy();

header('Location: login_agent.php');
exit;
