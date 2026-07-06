<?php
/**
 * Endpoint del widget "En vivo" del portal del agente.
 * Devuelve el estado en tiempo real (Vicidial) del agente logueado: su fila del
 * snapshot vicidial_live_status. Reusa el motor del monitor del supervisor
 * (lib/vicidial_live.php): refresca a lo sumo cada TTL (25s) bajo lock compartido,
 * así que N agentes polleando = 1 sola consulta a Vicidial por ventana.
 *
 * A prueba de fallos: ante cualquier problema devuelve JSON válido sin romper el
 * dashboard. El fetch en vivo solo funciona desde la IP autorizada (server); los
 * clientes remotos (HostGator) leen el último snapshot que dejó caliente el server.
 */

session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'no-session']);
    exit;
}

require_once __DIR__ . '/lib/vicidial_live.php';

$uid = (int) $_SESSION['user_id'];

try {
    $live = vicidialGetLiveStatus($pdo);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'engine']);
    exit;
}

if (empty($live['enabled'])) {
    echo json_encode(['ok' => true, 'enabled' => false]);
    exit;
}

$mine = $live['by_user'][$uid] ?? null; // null = el agente no está logueado en Vicidial ahora

echo json_encode([
    'ok'      => true,
    'enabled' => true,
    'live'    => $mine,
    'meta'    => [
        'age_seconds' => $live['meta']['age_seconds'] ?? null,
        'source_ok'   => (bool) ($live['meta']['source_ok'] ?? false),
    ],
], JSON_UNESCAPED_UNICODE);
