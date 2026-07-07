<?php
/**
 * RETIRADA — reemplazada por la Consola de Soporte (helpdesk/console.php).
 * Redirige según el rol: soporte -> consola; los demás -> portal del agente.
 * Conserva ?ticket / ?id si viene (los enlaces de los emails apuntan aquí).
 */
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/helpdesk_support.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
$tk = '';
if (!empty($_GET['ticket'])) {
    $tk = '?ticket=' . (int) $_GET['ticket'];
} elseif (!empty($_GET['id'])) {
    $tk = '?ticket=' . (int) $_GET['id'];
}
if (isHelpdeskSupport($_SESSION['role'] ?? '')) {
    header('Location: ../helpdesk/console.php' . $tk);
} else {
    header('Location: ../agents/helpdesk_tickets.php');
}
exit;
