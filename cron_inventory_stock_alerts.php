<?php
/**
 * Cron: alertas de stock del inventario.
 *
 * Barre el inventario y notifica en el sistema (campana) lo que está agotado,
 * bajo el mínimo o próximo a agotarse. Es el complemento del aviso inmediato que
 * dispara cada movimiento: aquí se cubren los artículos que llevan días bajo el
 * mínimo y que nadie mueve.
 *
 * Correr varias veces al día es seguro: el cooldown configurado en settings.php
 * (inventory_stock_alert_cooldown_hours) impide que se repita el mismo aviso, así
 * que da igual si lo dispara la tarea de Windows, el cron de cPanel, o los dos.
 *
 * Tarea de Windows: la registra run_vicidial_sync.bat install
 *   (PoncheXtreme-InventoryStockAlerts, 8:10 AM y 2:10 PM).
 *
 * Cron de cPanel (opcional, hora RD):
 *   10 8,14 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_inventory_stock_alerts.php >> /home2/hhempeos/logs/inventory_alerts.log 2>&1
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/inventory_alerts.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON INVENTORY STOCK ALERTS] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $cfg = inv_get_alert_config($pdo);
    if (($cfg['inventory_stock_alerts_enabled'] ?? '1') !== '1') {
        echo $logPrefix . "Alertas de stock deshabilitadas en settings. Saliendo.\n";
        exit(0);
    }

    echo $logPrefix . 'Roles avisados: ' . ($cfg['inventory_stock_alert_roles'] ?: '(ninguno)') . "\n";
    echo $logPrefix . 'Margen "proximo a agotarse": ' . $cfg['inventory_stock_alert_near_pct'] . "%\n";
    echo $logPrefix . 'Cooldown: ' . $cfg['inventory_stock_alert_cooldown_hours'] . " h\n";

    $result = inv_scan_stock_alerts($pdo, 'revisión programada');

    echo $logPrefix . "Articulos revisados: {$result['scanned']}\n";
    echo $logPrefix . "Avisos creados:      {$result['alerted']}"
        . " (agotados: {$result['by_level']['OUT']},"
        . " bajos: {$result['by_level']['LOW']},"
        . " por agotarse: {$result['by_level']['NEAR']})\n";

    // Aprovecha el paso para limpiar notificaciones viejas ya atendidas.
    require_once __DIR__ . '/lib/notifications.php';
    $purged = notifyPurgeOld($pdo);
    if ($purged > 0) {
        echo $logPrefix . "Notificaciones antiguas depuradas: $purged\n";
    }

    echo $logPrefix . 'Done at ' . date('Y-m-d H:i:s') . "\n";
    exit(0);
} catch (Throwable $e) {
    echo $logPrefix . 'ERROR: ' . $e->getMessage() . "\n";
    error_log('cron_inventory_stock_alerts: ' . $e->getMessage());
    exit(1);
}
