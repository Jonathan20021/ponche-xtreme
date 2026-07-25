<?php
/**
 * lib/inventory_alerts.php
 *
 * Alertas automáticas de stock del inventario.
 *
 * Dos disparadores, a propósito:
 *   1. inv_notify_stock_state()  -> en cada movimiento, en el momento en que el
 *      stock cruza el umbral (la salida que deja el papel toalla en 2 avisa ya,
 *      no al día siguiente).
 *   2. inv_scan_stock_alerts()   -> barrido programado, para que un artículo que
 *      lleva días bajo el mínimo y nadie toca siga apareciendo.
 *
 * Niveles:
 *   OUT   -> stock en 0 (crítico)
 *   LOW   -> stock <= mínimo
 *   NEAR  -> stock por encima del mínimo pero dentro del margen configurado
 *            (inventory_stock_alert_near_pct): "próximo a agotarse"
 *
 * Anti-spam: el dedupe_key de la notificación incluye un bucket de tiempo del
 * tamaño de inventory_stock_alert_cooldown_hours. Mientras el artículo siga en el
 * mismo nivel dentro del mismo bucket, no se repite el aviso.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/notifications.php';

if (!function_exists('inv_get_alert_config')) {
    /**
     * @return array<string,string>
     */
    function inv_get_alert_config(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $defaults = [
            'inventory_stock_alerts_enabled'         => '1',
            'inventory_stock_alert_roles'            => 'HR,Admin,IT',
            'inventory_stock_alert_user_ids'         => '',
            'inventory_stock_alert_near_pct'         => '20',
            'inventory_stock_alert_cooldown_hours'   => '24',
            'inventory_stock_alert_digest'           => '1',
            'inventory_stock_alert_email'            => '0',
            'inventory_stock_alert_email_recipients' => '',
        ];
        try {
            $keys = array_keys($defaults);
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
            $stmt->execute($keys);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        } catch (Throwable $e) {
            error_log('inv_get_alert_config: ' . $e->getMessage());
        }

        $cache = $defaults;
        return $cache;
    }
}

if (!function_exists('inv_classify_stock_level')) {
    /**
     * Clasifica el stock de un artículo. Devuelve null si está sano.
     *
     * @return array{level:string, severity:string, label:string}|null
     */
    function inv_classify_stock_level(float $stock, float $minStock, float $nearPct): ?array
    {
        if ($stock <= 0) {
            return ['level' => 'OUT', 'severity' => 'CRITICAL', 'label' => 'Agotado'];
        }
        if ($minStock <= 0) {
            // Sin mínimo configurado no hay forma de saber qué es "bajo".
            return null;
        }
        if ($stock <= $minStock) {
            return ['level' => 'LOW', 'severity' => 'HIGH', 'label' => 'Stock bajo'];
        }

        $nearThreshold = $minStock * (1 + max(0.0, $nearPct) / 100);
        if ($nearPct > 0 && $stock <= $nearThreshold) {
            return ['level' => 'NEAR', 'severity' => 'NORMAL', 'label' => 'Próximo a agotarse'];
        }

        return null;
    }
}

if (!function_exists('inv_stock_alert_notify')) {
    /**
     * Crea la notificación de un artículo en estado de alerta.
     *
     * @param array<string,mixed> $item fila de inventory_item_types (+ categoría opcional)
     * @return int|null id de la notificación, o null si no se creó (cooldown/deshabilitado)
     */
    function inv_stock_alert_notify(PDO $pdo, array $item, array $classification, string $trigger = 'movimiento'): ?int
    {
        $cfg = inv_get_alert_config($pdo);
        if (($cfg['inventory_stock_alerts_enabled'] ?? '1') !== '1') {
            return null;
        }

        $itemId   = (int) ($item['id'] ?? 0);
        $name     = (string) ($item['name'] ?? 'Artículo');
        $unit     = (string) ($item['unit'] ?? 'unidad');
        $stock    = (float) ($item['current_stock'] ?? 0);
        $minStock = (float) ($item['min_stock'] ?? 0);
        $reorder  = isset($item['reorder_qty']) && $item['reorder_qty'] !== null ? (float) $item['reorder_qty'] : null;
        $category = (string) ($item['category_name'] ?? '');

        if ($itemId <= 0) {
            return null;
        }

        $fmt = static function (float $q) use ($unit): string {
            $n = rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
            return ($n === '' ? '0' : $n) . ' ' . $unit;
        };

        $lines = [];
        $lines[] = 'Existencia actual: ' . $fmt($stock);
        if ($minStock > 0) {
            $lines[] = 'Mínimo configurado: ' . $fmt($minStock);
        }
        if ($reorder !== null && $reorder > 0) {
            $lines[] = 'Cantidad de reorden sugerida: ' . $fmt($reorder);
        }
        if ($category !== '') {
            $lines[] = 'Categoría: ' . $category;
        }
        $lines[] = 'Detectado por: ' . $trigger;

        $title = $classification['label'] . ': ' . $name;

        // Bucket de tiempo = ventana de silencio configurada.
        $cooldownHours = max(1, (int) ($cfg['inventory_stock_alert_cooldown_hours'] ?? 24));
        $bucket = (int) floor(time() / ($cooldownHours * 3600));

        $notifId = notifyCreate($pdo, [
            'type'       => 'INVENTORY_' . $classification['level'] . '_STOCK',
            'title'      => $title,
            'message'    => implode("\n", $lines),
            'severity'   => $classification['severity'],
            'url'        => 'hr/inventory_stock.php?filter=' . ($classification['level'] === 'OUT' ? 'out' : 'low'),
            'roles'      => $cfg['inventory_stock_alert_roles'] ?? 'HR,Admin,IT',
            'payload'    => [
                'item_type_id'  => $itemId,
                'item_name'     => $name,
                'level'         => $classification['level'],
                'current_stock' => $stock,
                'min_stock'     => $minStock,
                'reorder_qty'   => $reorder,
            ],
            'dedupe_key' => 'inventory_stock:' . $itemId . ':' . $classification['level'] . ':' . $bucket,
        ]);

        // Personas concretas, además de los roles configurados.
        foreach (notifyResolveTargetUserIds($pdo, $cfg['inventory_stock_alert_user_ids'] ?? '') as $uid) {
            notifyCreate($pdo, [
                'type'       => 'INVENTORY_' . $classification['level'] . '_STOCK',
                'title'      => $title,
                'message'    => implode("\n", $lines),
                'severity'   => $classification['severity'],
                'url'        => 'hr/inventory_stock.php',
                'user_id'    => $uid,
                'payload'    => ['item_type_id' => $itemId, 'level' => $classification['level']],
                'dedupe_key' => 'inventory_stock:' . $itemId . ':' . $classification['level'] . ':' . $bucket . ':u' . $uid,
            ]);
        }

        // Copia por correo solo si se pidió y si de verdad se creó el aviso
        // (si el cooldown lo bloqueó, tampoco se manda correo).
        if ($notifId !== null && ($cfg['inventory_stock_alert_email'] ?? '0') === '1') {
            $recipients = notificationsParseCsv($cfg['inventory_stock_alert_email_recipients'] ?? '');
            $recipients = array_values(array_filter($recipients, static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
            if (!empty($recipients)) {
                require_once __DIR__ . '/email_functions.php';
                if (function_exists('sendEmail')) {
                    $html = '<p><strong>' . htmlspecialchars($title) . '</strong></p>'
                        . '<pre style="font-family:inherit;white-space:pre-wrap">' . htmlspecialchars(implode("\n", $lines)) . '</pre>';
                    foreach ($recipients as $to) {
                        try {
                            sendEmail($to, $title, $html);
                        } catch (Throwable $e) {
                            error_log('inv_stock_alert_notify email: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        return $notifId;
    }
}

if (!function_exists('inv_notify_stock_state')) {
    /**
     * Se llama justo después de aplicar un movimiento. Solo avisa si el
     * movimiento EMPEORÓ el nivel del artículo: una entrada que sube el stock no
     * tiene por qué disparar nada, aunque siga bajo el mínimo (para eso está el
     * barrido programado).
     *
     * Nunca lanza: un fallo notificando no puede tumbar un movimiento de stock.
     */
    function inv_notify_stock_state(PDO $pdo, int $itemTypeId, float $previousStock, float $newStock): void
    {
        try {
            $cfg = inv_get_alert_config($pdo);
            if (($cfg['inventory_stock_alerts_enabled'] ?? '1') !== '1') {
                return;
            }
            if ($newStock >= $previousStock) {
                return; // el stock subió o quedó igual
            }

            $stmt = $pdo->prepare("
                SELECT it.id, it.name, it.unit, it.current_stock, it.min_stock, it.reorder_qty,
                       c.name AS category_name
                FROM inventory_item_types it
                LEFT JOIN inventory_categories c ON c.id = it.category_id
                WHERE it.id = ?
            ");
            $stmt->execute([$itemTypeId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                return;
            }

            $nearPct = (float) ($cfg['inventory_stock_alert_near_pct'] ?? 20);
            $minStock = (float) $item['min_stock'];

            $now  = inv_classify_stock_level($newStock, $minStock, $nearPct);
            if ($now === null) {
                return;
            }

            // Si ya estaba en el mismo nivel antes del movimiento, el aviso lo
            // gobierna el cooldown del dedupe_key; el cruce de umbral avisa igual.
            inv_stock_alert_notify($pdo, $item, $now, 'movimiento de inventario');
        } catch (Throwable $e) {
            error_log('inv_notify_stock_state: ' . $e->getMessage());
        }
    }
}

if (!function_exists('inv_scan_stock_alerts')) {
    /**
     * Barrido de todo el inventario: avisa de lo que está agotado, bajo el
     * mínimo o próximo a agotarse. Lo llama la tarea programada del inventario.
     *
     * Los AGOTADOS van uno por uno (son pocos y hay que actuar sobre cada uno).
     * Los bajos y los próximos a agotarse van en UN resumen: en el inventario real
     * hay decenas por debajo del mínimo a la vez y una notificación por artículo
     * dejaría la campana inservible.
     *
     * @return array{scanned:int, alerted:int, by_level:array<string,int>, digest:bool}
     */
    function inv_scan_stock_alerts(PDO $pdo, string $trigger = 'revisión programada'): array
    {
        $out = ['scanned' => 0, 'alerted' => 0, 'by_level' => ['OUT' => 0, 'LOW' => 0, 'NEAR' => 0], 'digest' => false];

        $cfg = inv_get_alert_config($pdo);
        if (($cfg['inventory_stock_alerts_enabled'] ?? '1') !== '1') {
            return $out;
        }

        try {
            $rows = $pdo->query("
                SELECT it.id, it.name, it.unit, it.current_stock, it.min_stock, it.reorder_qty,
                       c.name AS category_name
                FROM inventory_item_types it
                LEFT JOIN inventory_categories c ON c.id = it.category_id
                WHERE it.is_active = 1
                ORDER BY it.name
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('inv_scan_stock_alerts: ' . $e->getMessage());
            return $out;
        }

        $nearPct = (float) ($cfg['inventory_stock_alert_near_pct'] ?? 20);
        $useDigest = ($cfg['inventory_stock_alert_digest'] ?? '1') === '1';

        $grouped = ['OUT' => [], 'LOW' => [], 'NEAR' => []];

        foreach ($rows as $item) {
            $out['scanned']++;
            $classification = inv_classify_stock_level(
                (float) $item['current_stock'],
                (float) $item['min_stock'],
                $nearPct
            );
            if ($classification === null) {
                continue;
            }
            $grouped[$classification['level']][] = ['item' => $item, 'class' => $classification];
        }

        // Agotados: aviso individual, siempre.
        foreach ($grouped['OUT'] as $entry) {
            if (inv_stock_alert_notify($pdo, $entry['item'], $entry['class'], $trigger) !== null) {
                $out['alerted']++;
                $out['by_level']['OUT']++;
            }
        }

        $lowAndNear = array_merge($grouped['LOW'], $grouped['NEAR']);

        if (!$useDigest) {
            foreach ($lowAndNear as $entry) {
                if (inv_stock_alert_notify($pdo, $entry['item'], $entry['class'], $trigger) !== null) {
                    $out['alerted']++;
                    $out['by_level'][$entry['class']['level']]++;
                }
            }
            return $out;
        }

        if (!empty($lowAndNear)) {
            $out['by_level']['LOW']  = count($grouped['LOW']);
            $out['by_level']['NEAR'] = count($grouped['NEAR']);
            if (inv_stock_digest_notify($pdo, $grouped['LOW'], $grouped['NEAR'], $trigger) !== null) {
                $out['alerted']++;
                $out['digest'] = true;
            }
        }

        return $out;
    }
}

if (!function_exists('inv_stock_digest_notify')) {
    /**
     * Un solo aviso con el listado de artículos bajo el mínimo y próximos a
     * agotarse, ordenados por qué tan lejos están del mínimo.
     *
     * @param array<int,array{item:array<string,mixed>,class:array<string,string>}> $low
     * @param array<int,array{item:array<string,mixed>,class:array<string,string>}> $near
     */
    function inv_stock_digest_notify(PDO $pdo, array $low, array $near, string $trigger): ?int
    {
        $cfg = inv_get_alert_config($pdo);
        if (($cfg['inventory_stock_alerts_enabled'] ?? '1') !== '1') {
            return null;
        }

        $maxListed = 15;

        $fmt = static function (array $item): string {
            $unit  = (string) ($item['unit'] ?? 'unidad');
            $clean = static fn(float $q): string => (($n = rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.')) === '' ? '0' : $n);
            return sprintf(
                '· %s: %s de %s %s',
                (string) ($item['name'] ?? '—'),
                $clean((float) ($item['current_stock'] ?? 0)),
                $clean((float) ($item['min_stock'] ?? 0)),
                $unit
            );
        };

        // Más urgente primero: mayor déficit frente al mínimo.
        $sorter = static function (array $a, array $b): int {
            $da = (float) $a['item']['min_stock'] - (float) $a['item']['current_stock'];
            $db = (float) $b['item']['min_stock'] - (float) $b['item']['current_stock'];
            return $db <=> $da;
        };
        usort($low, $sorter);
        usort($near, $sorter);

        $lines = [];
        if (!empty($low)) {
            $lines[] = 'BAJO EL MÍNIMO (' . count($low) . '):';
            foreach (array_slice($low, 0, $maxListed) as $entry) {
                $lines[] = $fmt($entry['item']);
            }
            if (count($low) > $maxListed) {
                $lines[] = '· … y ' . (count($low) - $maxListed) . ' más';
            }
        }
        if (!empty($near)) {
            if (!empty($lines)) {
                $lines[] = '';
            }
            $lines[] = 'PRÓXIMOS A AGOTARSE (' . count($near) . '):';
            foreach (array_slice($near, 0, $maxListed) as $entry) {
                $lines[] = $fmt($entry['item']);
            }
            if (count($near) > $maxListed) {
                $lines[] = '· … y ' . (count($near) - $maxListed) . ' más';
            }
        }
        $lines[] = '';
        $lines[] = 'Detectado por: ' . $trigger;

        $title = sprintf(
            'Inventario: %d bajo el mínimo%s',
            count($low),
            !empty($near) ? ' y ' . count($near) . ' por agotarse' : ''
        );

        $cooldownHours = max(1, (int) ($cfg['inventory_stock_alert_cooldown_hours'] ?? 24));
        $bucket = (int) floor(time() / ($cooldownHours * 3600));

        return notifyCreate($pdo, [
            'type'       => 'INVENTORY_LOW_STOCK_DIGEST',
            'title'      => $title,
            'message'    => implode("\n", $lines),
            'severity'   => !empty($low) ? 'HIGH' : 'NORMAL',
            'url'        => 'hr/inventory_stock.php?filter=low',
            'roles'      => $cfg['inventory_stock_alert_roles'] ?? 'HR,Admin,IT',
            'payload'    => [
                'low_count'  => count($low),
                'near_count' => count($near),
                'low_items'  => array_map(static fn($e) => $e['item']['name'], array_slice($low, 0, $maxListed)),
            ],
            // Un resumen por ventana de cooldown, no uno por corrida.
            'dedupe_key' => 'inventory_stock_digest:' . $bucket,
        ]);
    }
}
