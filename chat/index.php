<?php
/**
 * Página de Índice del Sistema de Chat
 * Redirige al panel apropiado según el rol del usuario
 */

require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// Si es admin o supervisor, mostrar panel de administración
if (userHasPermission('chat_admin')) {
    header('Location: admin.php');
    exit;
}

// Si tiene permisos de chat, redirigir al dashboard
if (userHasPermission('chat')) {
    header('Location: ../dashboard.php');
    exit;
}

// Sin permisos
header('Location: ../unauthorized.php?section=chat');
exit;
