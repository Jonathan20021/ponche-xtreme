<?php
/**
 * RETIRADA — reemplazada por la Consola de Soporte (helpdesk/console.php).
 * Redirige según el rol: soporte -> consola; los demás -> portal del agente.
 */
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/helpdesk_support.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
$tk = !empty($_GET['ticket']) ? ('?ticket=' . (int) $_GET['ticket']) : '';
if (isHelpdeskSupport($_SESSION['role'] ?? '')) {
    header('Location: console.php' . $tk);
} else {
    header('Location: ../agents/helpdesk_tickets.php');
}
exit;
