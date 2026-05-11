<?php
/**
 * Inventory data layer.
 *
 * All stock changes flow through recordMovement() to keep:
 *   - inventory_movements (immutable ledger, source of truth)
 *   - inventory_item_types.current_stock (denormalized counter)
 *   - inventory_lots.quantity_remaining (when applicable)
 *
 * Everything runs in a transaction. If the calling code already has an
 * active transaction, the function reuses it instead of nesting.
 */

if (!function_exists('inv_log_action')) {
    /** Log an inventory action via log_custom_action if available. */
    function inv_log_action(PDO $pdo, string $action, string $description, ?int $entityId = null, array $meta = []): void
    {
        if (!function_exists('log_custom_action')) return;
        log_custom_action(
            $pdo,
            $_SESSION['user_id']  ?? null,
            $_SESSION['full_name'] ?? 'Sistema',
            $_SESSION['role']     ?? 'system',
            'inventory',
            $action,
            $description,
            'inventory_movements',
            $entityId,
            $meta
        );
    }
}

if (!function_exists('inv_movement_sign')) {
    /** Returns +1 if movement adds stock, -1 if it removes, 0 if neutral. */
    function inv_movement_sign(string $type): int
    {
        switch (strtoupper($type)) {
            case 'ENTRY':
            case 'RETURN':
                return 1;
            case 'EXIT':
            case 'ASSIGN':
            case 'LOSS':
            case 'DAMAGE':
                return -1;
            case 'ADJUSTMENT':
            case 'TRANSFER':
                return 0; // sign is determined by the quantity itself
            default:
                throw new InvalidArgumentException("Unknown movement type: $type");
        }
    }
}

if (!function_exists('inv_record_movement')) {
    /**
     * Record a stock movement and update related counters atomically.
     *
     * @param PDO   $pdo
     * @param array $data {
     *   @var int        item_type_id   (required)
     *   @var string     movement_type  ENTRY|EXIT|ADJUSTMENT|ASSIGN|RETURN|LOSS|DAMAGE
     *   @var float      quantity       Always positive for ENTRY/EXIT/ASSIGN/RETURN/LOSS/DAMAGE.
     *                                  Signed value for ADJUSTMENT (+5 / -3).
     *   @var int|null   lot_id         Optional (required for track_lots items on EXIT/ASSIGN).
     *   @var float|null unit_cost
     *   @var string     reason
     *   @var string     reference
     *   @var int|null   employee_id
     *   @var int|null   assignment_id
     *   @var string     notes
     *   @var int|null   performed_by
     * }
     * @return int Newly inserted movement id.
     */
    function inv_record_movement(PDO $pdo, array $data): int
    {
        $itemTypeId = (int) ($data['item_type_id'] ?? 0);
        $type       = strtoupper(trim((string) ($data['movement_type'] ?? '')));
        $quantity   = (float) ($data['quantity'] ?? 0);

        if ($itemTypeId <= 0) {
            throw new InvalidArgumentException('item_type_id requerido');
        }
        if ($type === '') {
            throw new InvalidArgumentException('movement_type requerido');
        }
        if ($quantity == 0.0) {
            throw new InvalidArgumentException('quantity no puede ser 0');
        }

        $sign = inv_movement_sign($type);
        // Normalize quantity sign:
        //  - ADJUSTMENT/TRANSFER use the sign passed by the caller (+/-)
        //  - All others: caller passes positive; we store +/- according to sign().
        if ($sign === 0) {
            $signedQty = $quantity; // adjustment carries its own sign
        } else {
            $signedQty = abs($quantity) * $sign;
        }

        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            // Lock the item row for the update
            $stmt = $pdo->prepare("SELECT id, name, current_stock, track_lots, is_consumable FROM inventory_item_types WHERE id = ? FOR UPDATE");
            $stmt->execute([$itemTypeId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new RuntimeException("Item id=$itemTypeId no existe");
            }

            $newStock = (float) $item['current_stock'] + $signedQty;
            if ($newStock < 0) {
                throw new RuntimeException(sprintf(
                    'Stock insuficiente para "%s". Stock actual: %s, intento: %s',
                    $item['name'],
                    rtrim(rtrim(number_format($item['current_stock'], 2), '0'), '.'),
                    rtrim(rtrim(number_format($signedQty, 2), '0'), '.')
                ));
            }

            // Lot handling (for tracked items)
            $lotId = isset($data['lot_id']) && $data['lot_id'] !== '' ? (int) $data['lot_id'] : null;

            if ((int) $item['track_lots'] === 1) {
                if ($signedQty < 0 && $lotId === null && $type !== 'ADJUSTMENT') {
                    // For tracked items, auto-pick the oldest non-empty lot (FEFO: by expiration)
                    $pick = $pdo->prepare("SELECT id FROM inventory_lots
                        WHERE item_type_id = ? AND quantity_remaining > 0
                        ORDER BY (expiration_date IS NULL), expiration_date ASC, received_date ASC
                        LIMIT 1");
                    $pick->execute([$itemTypeId]);
                    $autoLot = $pick->fetchColumn();
                    if ($autoLot) {
                        $lotId = (int) $autoLot;
                    }
                }
            }

            if ($lotId !== null) {
                $lotStmt = $pdo->prepare("SELECT id, quantity_remaining FROM inventory_lots WHERE id = ? AND item_type_id = ? FOR UPDATE");
                $lotStmt->execute([$lotId, $itemTypeId]);
                $lot = $lotStmt->fetch(PDO::FETCH_ASSOC);
                if (!$lot) {
                    throw new RuntimeException("Lote $lotId no existe para este item");
                }
                $newLotQty = (float) $lot['quantity_remaining'] + $signedQty;
                if ($newLotQty < 0) {
                    throw new RuntimeException("Stock insuficiente en el lote seleccionado");
                }
                $upd = $pdo->prepare("UPDATE inventory_lots SET quantity_remaining = ? WHERE id = ?");
                $upd->execute([$newLotQty, $lotId]);
            }

            // Insert movement
            $insert = $pdo->prepare("INSERT INTO inventory_movements
                (item_type_id, lot_id, movement_type, quantity, unit_cost, reason, reference,
                 employee_id, assignment_id, notes, performed_by, performed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $insert->execute([
                $itemTypeId,
                $lotId,
                $type,
                $signedQty,
                $data['unit_cost']     ?? null,
                trim((string) ($data['reason'] ?? '')),
                trim((string) ($data['reference'] ?? '')),
                isset($data['employee_id'])   && $data['employee_id']   !== '' ? (int) $data['employee_id']   : null,
                isset($data['assignment_id']) && $data['assignment_id'] !== '' ? (int) $data['assignment_id'] : null,
                trim((string) ($data['notes'] ?? '')),
                $data['performed_by'] ?? ($_SESSION['user_id'] ?? null),
            ]);
            $movementId = (int) $pdo->lastInsertId();

            // Update denormalized stock
            $updItem = $pdo->prepare("UPDATE inventory_item_types SET current_stock = ? WHERE id = ?");
            $updItem->execute([$newStock, $itemTypeId]);

            if ($startedTx) $pdo->commit();

            inv_log_action(
                $pdo,
                'movement_' . strtolower($type),
                "Movimiento $type de {$item['name']}: $signedQty (stock: $newStock)",
                $movementId,
                [
                    'item_type_id' => $itemTypeId,
                    'quantity'     => $signedQty,
                    'lot_id'       => $lotId,
                ]
            );

            return $movementId;
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('inv_register_lot')) {
    /**
     * Register a new lot of an item, atomically adding stock via an ENTRY movement.
     *
     * @return array{lot_id:int, movement_id:int}
     */
    function inv_register_lot(PDO $pdo, array $data): array
    {
        $itemTypeId  = (int) ($data['item_type_id'] ?? 0);
        $quantity    = (float) ($data['quantity'] ?? 0);
        if ($itemTypeId <= 0 || $quantity <= 0) {
            throw new InvalidArgumentException('item_type_id y quantity > 0 son requeridos');
        }

        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO inventory_lots
                (item_type_id, lot_code, quantity_received, quantity_remaining,
                 received_date, expiration_date, supplier_id, unit_cost, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $itemTypeId,
                trim((string) ($data['lot_code']     ?? '')) ?: null,
                $quantity,
                $quantity, // initial remaining
                $data['received_date'] ?? date('Y-m-d'),
                !empty($data['expiration_date']) ? $data['expiration_date'] : null,
                !empty($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                $data['unit_cost'] ?? null,
                trim((string) ($data['notes'] ?? '')),
            ]);
            $lotId = (int) $pdo->lastInsertId();

            $movementId = inv_record_movement($pdo, [
                'item_type_id'  => $itemTypeId,
                'movement_type' => 'ENTRY',
                'quantity'      => $quantity,
                'lot_id'        => $lotId,
                'unit_cost'     => $data['unit_cost']  ?? null,
                'reason'        => $data['reason']     ?? 'Recepcion',
                'reference'     => $data['reference']  ?? '',
                'notes'         => $data['notes']      ?? '',
            ]);

            if ($startedTx) $pdo->commit();
            return ['lot_id' => $lotId, 'movement_id' => $movementId];
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('inv_get_stock_summary')) {
    /**
     * Aggregate counters for the dashboard cards.
     *
     * @return array{
     *   total_items:int,
     *   total_units:float,
     *   low_stock:int,
     *   out_of_stock:int,
     *   expiring_soon:int,
     *   total_value:float
     * }
     */
    function inv_get_stock_summary(PDO $pdo, int $expiringDays = 30): array
    {
        $out = [
            'total_items'   => 0,
            'total_units'   => 0,
            'low_stock'     => 0,
            'out_of_stock'  => 0,
            'expiring_soon' => 0,
            'total_value'   => 0,
        ];
        $row = $pdo->query("
            SELECT COUNT(*) AS total_items,
                   COALESCE(SUM(current_stock), 0) AS total_units,
                   COALESCE(SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
                   COALESCE(SUM(CASE WHEN current_stock > 0 AND min_stock > 0 AND current_stock <= min_stock THEN 1 ELSE 0 END), 0) AS low_stock,
                   COALESCE(SUM(current_stock * COALESCE(unit_cost, 0)), 0) AS total_value
            FROM inventory_item_types
            WHERE is_active = 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $out['total_items']  = (int) $row['total_items'];
            $out['total_units']  = (float) $row['total_units'];
            $out['low_stock']    = (int) $row['low_stock'];
            $out['out_of_stock'] = (int) $row['out_of_stock'];
            $out['total_value']  = (float) $row['total_value'];
        }

        $exp = $pdo->prepare("
            SELECT COUNT(*) FROM inventory_lots
            WHERE quantity_remaining > 0
              AND expiration_date IS NOT NULL
              AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ");
        $exp->execute([$expiringDays]);
        $out['expiring_soon'] = (int) $exp->fetchColumn();

        return $out;
    }
}

if (!function_exists('inv_get_low_stock_items')) {
    /** Items where current_stock <= min_stock (and min_stock > 0). */
    function inv_get_low_stock_items(PDO $pdo, int $limit = 50): array
    {
        $stmt = $pdo->prepare("
            SELECT it.id, it.name, it.unit, it.current_stock, it.min_stock, it.max_stock,
                   it.reorder_qty, it.unit_cost, it.is_consumable, it.track_lots,
                   c.name AS category_name, c.icon AS category_icon, c.color AS category_color
            FROM inventory_item_types it
            JOIN inventory_categories c ON c.id = it.category_id
            WHERE it.is_active = 1
              AND it.min_stock > 0
              AND it.current_stock <= it.min_stock
            ORDER BY (it.min_stock - it.current_stock) DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_get_expiring_lots')) {
    /** Lots within $days days of expiration with remaining stock. */
    function inv_get_expiring_lots(PDO $pdo, int $days = 30, int $limit = 50): array
    {
        $stmt = $pdo->prepare("
            SELECT l.id, l.lot_code, l.quantity_remaining, l.received_date, l.expiration_date,
                   DATEDIFF(l.expiration_date, CURDATE()) AS days_to_expire,
                   it.id AS item_type_id, it.name AS item_name, it.unit,
                   c.name AS category_name, c.icon AS category_icon, c.color AS category_color
            FROM inventory_lots l
            JOIN inventory_item_types it ON it.id = l.item_type_id
            JOIN inventory_categories c ON c.id = it.category_id
            WHERE l.quantity_remaining > 0
              AND l.expiration_date IS NOT NULL
              AND l.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY l.expiration_date ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $days,  PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_get_recent_movements')) {
    function inv_get_recent_movements(PDO $pdo, int $limit = 20, array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['item_type_id'])) {
            $where[] = "m.item_type_id = ?";
            $params[] = (int) $filters['item_type_id'];
        }
        if (!empty($filters['movement_type'])) {
            $where[] = "m.movement_type = ?";
            $params[] = $filters['movement_type'];
        }
        if (!empty($filters['from_date'])) {
            $where[] = "m.performed_at >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }
        if (!empty($filters['to_date'])) {
            $where[] = "m.performed_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        $sql = "
            SELECT m.*,
                   it.name AS item_name, it.unit,
                   c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                   CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                   u.full_name AS performed_by_name
            FROM inventory_movements m
            JOIN inventory_item_types it ON it.id = m.item_type_id
            JOIN inventory_categories c ON c.id = it.category_id
            LEFT JOIN employees e ON e.id = m.employee_id
            LEFT JOIN users u ON u.id = m.performed_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.performed_at DESC
            LIMIT ?
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $val) $stmt->bindValue($i + 1, $val);
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_get_consumption_history')) {
    /**
     * Daily consumption (sum of negative movements) for the last N days, grouped by day.
     */
    function inv_get_consumption_history(PDO $pdo, int $itemTypeId, int $days = 90): array
    {
        $stmt = $pdo->prepare("
            SELECT DATE(performed_at) AS day,
                   ABS(SUM(quantity)) AS units_out
            FROM inventory_movements
            WHERE item_type_id = ?
              AND quantity < 0
              AND performed_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(performed_at)
            ORDER BY day ASC
        ");
        $stmt->bindValue(1, $itemTypeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $days,       PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_get_weekly_consumption')) {
    /** Weekly consumption for the last N weeks. Used by anomaly detection. */
    function inv_get_weekly_consumption(PDO $pdo, int $itemTypeId, int $weeks = 12): array
    {
        $days = $weeks * 7;
        $stmt = $pdo->prepare("
            SELECT YEARWEEK(performed_at, 3) AS yw,
                   MIN(DATE(performed_at)) AS week_start,
                   ABS(SUM(quantity)) AS units_out
            FROM inventory_movements
            WHERE item_type_id = ?
              AND quantity < 0
              AND performed_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY YEARWEEK(performed_at, 3)
            ORDER BY yw ASC
        ");
        $stmt->bindValue(1, $itemTypeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $days,       PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_format_qty')) {
    function inv_format_qty(float $qty, string $unit = ''): string
    {
        $s = abs($qty - (int) $qty) < 0.005
            ? (string) (int) $qty
            : rtrim(rtrim(number_format($qty, 2), '0'), '.');
        return $unit !== '' ? "$s $unit" : $s;
    }
}
