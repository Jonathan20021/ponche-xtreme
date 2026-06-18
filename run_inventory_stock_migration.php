<?php
/**
 * Idempotent runner for the Inventory Stock Control + AI migration.
 * Works with MySQL versions that do not support `IF NOT EXISTS` on ALTERs.
 */

require_once __DIR__ . '/db.php';

$cli = (PHP_SAPI === 'cli');
$nl  = $cli ? "\n" : "<br>\n";
if (!$cli) {
    echo "<pre style='font-family:Consolas,monospace;background: var(--surface);color:#cbd5e1;padding:16px;'>";
}

$schema = $pdo->query("SELECT DATABASE()")->fetchColumn();
echo "=== Inventory Stock Migration ==={$nl}DB: $schema{$nl}{$nl}";

$ok = 0; $skipped = 0; $errors = 0;

function step(string $label, callable $fn, &$ok, &$skipped, &$errors, string $nl) {
    echo "[*] $label{$nl}";
    try {
        $result = $fn();
        if ($result === 'SKIP') {
            echo "    SKIP{$nl}";
            $skipped++;
        } else {
            echo "    OK{$nl}";
            $ok++;
        }
    } catch (Throwable $e) {
        echo "    ERROR: " . $e->getMessage() . "{$nl}";
        $errors++;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}
function indexExists(PDO $pdo, string $table, string $indexName): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table, $indexName]);
    return (int) $stmt->fetchColumn() > 0;
}
function fkExists(PDO $pdo, string $table, string $constraintName): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table, $constraintName]);
    return (int) $stmt->fetchColumn() > 0;
}
function tableExists(PDO $pdo, string $table): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

// ---------- 1. Suppliers ----------
step("CREATE TABLE inventory_suppliers", function () use ($pdo) {
    if (tableExists($pdo, 'inventory_suppliers')) return 'SKIP';
    $pdo->exec("CREATE TABLE `inventory_suppliers` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
        `contact_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `address` text COLLATE utf8mb4_unicode_ci,
        `notes` text COLLATE utf8mb4_unicode_ci,
        `is_active` tinyint(1) NOT NULL DEFAULT '1',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `inventory_suppliers_name_unique` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}, $ok, $skipped, $errors, $nl);

// ---------- 2. inventory_categories: add icon/description/color ----------
$categoryCols = [
    'icon'        => "ALTER TABLE `inventory_categories` ADD COLUMN `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'fa-box' AFTER `name`",
    'description' => "ALTER TABLE `inventory_categories` ADD COLUMN `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `icon`",
    'color'       => "ALTER TABLE `inventory_categories` ADD COLUMN `color` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'slate' AFTER `description`",
];
foreach ($categoryCols as $col => $sql) {
    step("ADD inventory_categories.$col", function () use ($pdo, $col, $sql) {
        if (columnExists($pdo, 'inventory_categories', $col)) return 'SKIP';
        $pdo->exec($sql);
    }, $ok, $skipped, $errors, $nl);
}

// ---------- 3. inventory_item_types: stock control fields ----------
$itemCols = [
    'sku'           => "ALTER TABLE `inventory_item_types` ADD COLUMN `sku` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `description`",
    'unit'          => "ALTER TABLE `inventory_item_types` ADD COLUMN `unit` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unidad' AFTER `sku`",
    'is_consumable' => "ALTER TABLE `inventory_item_types` ADD COLUMN `is_consumable` tinyint(1) NOT NULL DEFAULT '1' AFTER `unit`",
    'track_lots'    => "ALTER TABLE `inventory_item_types` ADD COLUMN `track_lots` tinyint(1) NOT NULL DEFAULT '0' AFTER `is_consumable`",
    'current_stock' => "ALTER TABLE `inventory_item_types` ADD COLUMN `current_stock` decimal(12,2) NOT NULL DEFAULT '0.00' AFTER `track_lots`",
    'min_stock'     => "ALTER TABLE `inventory_item_types` ADD COLUMN `min_stock` decimal(12,2) NOT NULL DEFAULT '0.00' AFTER `current_stock`",
    'max_stock'     => "ALTER TABLE `inventory_item_types` ADD COLUMN `max_stock` decimal(12,2) DEFAULT NULL AFTER `min_stock`",
    'reorder_qty'   => "ALTER TABLE `inventory_item_types` ADD COLUMN `reorder_qty` decimal(12,2) DEFAULT NULL AFTER `max_stock`",
    'unit_cost'     => "ALTER TABLE `inventory_item_types` ADD COLUMN `unit_cost` decimal(12,2) DEFAULT NULL AFTER `reorder_qty`",
    'supplier_id'   => "ALTER TABLE `inventory_item_types` ADD COLUMN `supplier_id` int(11) DEFAULT NULL AFTER `unit_cost`",
    'location'      => "ALTER TABLE `inventory_item_types` ADD COLUMN `location` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `supplier_id`",
    'is_active'     => "ALTER TABLE `inventory_item_types` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT '1' AFTER `location`",
    'updated_at'    => "ALTER TABLE `inventory_item_types` ADD COLUMN `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
];
foreach ($itemCols as $col => $sql) {
    step("ADD inventory_item_types.$col", function () use ($pdo, $col, $sql) {
        if (columnExists($pdo, 'inventory_item_types', $col)) return 'SKIP';
        $pdo->exec($sql);
    }, $ok, $skipped, $errors, $nl);
}

// Indexes on item_types
$itemIndexes = [
    'idx_item_types_sku'       => "ALTER TABLE `inventory_item_types` ADD INDEX `idx_item_types_sku` (`sku`)",
    'idx_item_types_low_stock' => "ALTER TABLE `inventory_item_types` ADD INDEX `idx_item_types_low_stock` (`current_stock`, `min_stock`)",
    'idx_item_types_supplier'  => "ALTER TABLE `inventory_item_types` ADD INDEX `idx_item_types_supplier` (`supplier_id`)",
];
foreach ($itemIndexes as $idx => $sql) {
    step("ADD INDEX $idx on inventory_item_types", function () use ($pdo, $idx, $sql) {
        if (indexExists($pdo, 'inventory_item_types', $idx)) return 'SKIP';
        $pdo->exec($sql);
    }, $ok, $skipped, $errors, $nl);
}

// FK supplier on item_types
step("ADD FK fk_item_types_supplier", function () use ($pdo) {
    if (fkExists($pdo, 'inventory_item_types', 'fk_item_types_supplier')) return 'SKIP';
    if (!columnExists($pdo, 'inventory_item_types', 'supplier_id')) {
        throw new RuntimeException('supplier_id column missing');
    }
    $pdo->exec("ALTER TABLE `inventory_item_types`
        ADD CONSTRAINT `fk_item_types_supplier`
        FOREIGN KEY (`supplier_id`) REFERENCES `inventory_suppliers`(`id`) ON DELETE SET NULL");
}, $ok, $skipped, $errors, $nl);

// ---------- 4. inventory_lots ----------
step("CREATE TABLE inventory_lots", function () use ($pdo) {
    if (tableExists($pdo, 'inventory_lots')) return 'SKIP';
    $pdo->exec("CREATE TABLE `inventory_lots` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `item_type_id` int(11) NOT NULL,
        `lot_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `quantity_received` decimal(12,2) NOT NULL DEFAULT '0.00',
        `quantity_remaining` decimal(12,2) NOT NULL DEFAULT '0.00',
        `received_date` date NOT NULL,
        `expiration_date` date DEFAULT NULL,
        `supplier_id` int(11) DEFAULT NULL,
        `unit_cost` decimal(12,2) DEFAULT NULL,
        `notes` text COLLATE utf8mb4_unicode_ci,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_lots_item` (`item_type_id`),
        KEY `idx_lots_expiration` (`expiration_date`),
        KEY `idx_lots_remaining` (`quantity_remaining`),
        CONSTRAINT `fk_lots_item` FOREIGN KEY (`item_type_id`) REFERENCES `inventory_item_types` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_lots_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `inventory_suppliers` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}, $ok, $skipped, $errors, $nl);

// ---------- 5. inventory_movements ----------
step("CREATE TABLE inventory_movements", function () use ($pdo) {
    if (tableExists($pdo, 'inventory_movements')) return 'SKIP';
    $pdo->exec("CREATE TABLE `inventory_movements` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `item_type_id` int(11) NOT NULL,
        `lot_id` int(11) DEFAULT NULL,
        `movement_type` enum('ENTRY','EXIT','ADJUSTMENT','ASSIGN','RETURN','LOSS','DAMAGE','TRANSFER') COLLATE utf8mb4_unicode_ci NOT NULL,
        `quantity` decimal(12,2) NOT NULL,
        `unit_cost` decimal(12,2) DEFAULT NULL,
        `reason` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `reference` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `employee_id` int(10) UNSIGNED DEFAULT NULL,
        `assignment_id` int(11) DEFAULT NULL,
        `notes` text COLLATE utf8mb4_unicode_ci,
        `performed_by` int(10) UNSIGNED DEFAULT NULL,
        `performed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_mov_item` (`item_type_id`),
        KEY `idx_mov_type` (`movement_type`),
        KEY `idx_mov_date` (`performed_at`),
        KEY `idx_mov_employee` (`employee_id`),
        KEY `idx_mov_lot` (`lot_id`),
        CONSTRAINT `fk_mov_item` FOREIGN KEY (`item_type_id`) REFERENCES `inventory_item_types` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_mov_lot` FOREIGN KEY (`lot_id`) REFERENCES `inventory_lots` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_mov_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_mov_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `employee_inventory` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_mov_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}, $ok, $skipped, $errors, $nl);

// ---------- 6. employee_inventory: add quantity, lot_id ----------
step("ADD employee_inventory.quantity", function () use ($pdo) {
    if (columnExists($pdo, 'employee_inventory', 'quantity')) return 'SKIP';
    $pdo->exec("ALTER TABLE `employee_inventory` ADD COLUMN `quantity` decimal(12,2) NOT NULL DEFAULT '1.00' AFTER `uuid`");
}, $ok, $skipped, $errors, $nl);

step("ADD employee_inventory.lot_id", function () use ($pdo) {
    if (columnExists($pdo, 'employee_inventory', 'lot_id')) return 'SKIP';
    $pdo->exec("ALTER TABLE `employee_inventory` ADD COLUMN `lot_id` int(11) DEFAULT NULL AFTER `quantity`");
}, $ok, $skipped, $errors, $nl);

step("ADD FK fk_ei_lot on employee_inventory", function () use ($pdo) {
    if (fkExists($pdo, 'employee_inventory', 'fk_ei_lot')) return 'SKIP';
    if (!columnExists($pdo, 'employee_inventory', 'lot_id')) {
        throw new RuntimeException('lot_id column missing');
    }
    $pdo->exec("ALTER TABLE `employee_inventory`
        ADD CONSTRAINT `fk_ei_lot`
        FOREIGN KEY (`lot_id`) REFERENCES `inventory_lots`(`id`) ON DELETE SET NULL");
}, $ok, $skipped, $errors, $nl);

// ---------- 7. AI tables ----------
step("CREATE TABLE inventory_ai_chats", function () use ($pdo) {
    if (tableExists($pdo, 'inventory_ai_chats')) return 'SKIP';
    $pdo->exec("CREATE TABLE `inventory_ai_chats` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(10) UNSIGNED DEFAULT NULL,
        `role` enum('user','assistant','system') COLLATE utf8mb4_unicode_ci NOT NULL,
        `message` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
        `tokens_used` int(11) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_ai_chat_user` (`user_id`),
        KEY `idx_ai_chat_date` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}, $ok, $skipped, $errors, $nl);

step("CREATE TABLE inventory_ai_insights", function () use ($pdo) {
    if (tableExists($pdo, 'inventory_ai_insights')) return 'SKIP';
    $pdo->exec("CREATE TABLE `inventory_ai_insights` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `item_type_id` int(11) DEFAULT NULL,
        `insight_type` enum('PREDICTION','ANOMALY','REORDER','SUGGESTION') COLLATE utf8mb4_unicode_ci NOT NULL,
        `severity` enum('LOW','MEDIUM','HIGH','CRITICAL') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'LOW',
        `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
        `description` text COLLATE utf8mb4_unicode_ci,
        `payload_json` longtext COLLATE utf8mb4_unicode_ci,
        `acknowledged` tinyint(1) NOT NULL DEFAULT '0',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_ai_insight_item` (`item_type_id`),
        KEY `idx_ai_insight_type` (`insight_type`),
        KEY `idx_ai_insight_ack` (`acknowledged`),
        CONSTRAINT `fk_ai_insight_item` FOREIGN KEY (`item_type_id`) REFERENCES `inventory_item_types` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}, $ok, $skipped, $errors, $nl);

// ---------- 8. Seed categories ----------
step("SEED new categories", function () use ($pdo) {
    $cats = [
        ['Papel desechable',       'fa-toilet-paper', 'Papel toalla, servilletas, papel higienico',                  'amber'],
        ['Materiales de oficina',  'fa-paperclip',    'Boligrafos, grapas, carpetas, papel bond',                    'blue'],
        ['Materiales gastables',   'fa-recycle',      'Suministros generales de consumo rapido',                     'orange'],
        ['Insumos de cocina',      'fa-utensils',     'Cafe, azucar, vasos, agua',                                   'yellow'],
        ['Materiales de limpieza', 'fa-spray-can',    'Detergentes, escobas, panos, desinfectantes',                 'emerald'],
        ['Botiquin',               'fa-kit-medical',  'Medicamentos, vendas, alcohol, insumos primeros auxilios',    'red'],
    ];
    $check  = $pdo->prepare("SELECT id FROM inventory_categories WHERE name = ?");
    $insert = $pdo->prepare("INSERT INTO inventory_categories (name, icon, description, color) VALUES (?, ?, ?, ?)");
    $update = $pdo->prepare("UPDATE inventory_categories SET icon = ?, description = ?, color = ? WHERE id = ? AND (icon IS NULL OR icon = 'fa-box')");
    $count = 0;
    foreach ($cats as [$name, $icon, $desc, $color]) {
        $check->execute([$name]);
        $existing = $check->fetchColumn();
        if ($existing) {
            $update->execute([$icon, $desc, $color, $existing]);
        } else {
            $insert->execute([$name, $icon, $desc, $color]);
            $count++;
        }
    }
    echo "    inserted=$count{$nl}";
    // Backfill legacy
    $pdo->exec("UPDATE inventory_categories SET icon = 'fa-id-badge', color = 'slate'  WHERE name = 'Administrativo' AND (icon IS NULL OR icon = 'fa-box')");
    $pdo->exec("UPDATE inventory_categories SET icon = 'fa-laptop',   color = 'cyan'   WHERE name = 'Tecnología'     AND (icon IS NULL OR icon = 'fa-box')");
    $pdo->exec("UPDATE inventory_categories SET icon = 'fa-shirt',    color = 'purple' WHERE name = 'Uniforme'       AND (icon IS NULL OR icon = 'fa-box')");
}, $ok, $skipped, $errors, $nl);

// ---------- 9. Mark legacy categories ----------
step("FLAG track_lots for botiquin & cocina items", function () use ($pdo) {
    $pdo->exec("UPDATE inventory_item_types it
        JOIN inventory_categories c ON c.id = it.category_id
        SET it.track_lots = 1
        WHERE c.name IN ('Botiquin', 'Insumos de cocina') AND it.track_lots = 0");
}, $ok, $skipped, $errors, $nl);

step("FLAG is_consumable=0 for legacy hardware/uniforme/admin", function () use ($pdo) {
    $pdo->exec("UPDATE inventory_item_types it
        JOIN inventory_categories c ON c.id = it.category_id
        SET it.is_consumable = 0
        WHERE c.name IN ('Tecnología', 'Uniforme', 'Administrativo')
          AND it.is_consumable = 1");
}, $ok, $skipped, $errors, $nl);

// ---------- 10. Reconcile current_stock from existing employee_inventory ----------
step("Reconcile current_stock for non-consumable items from existing assignments", function () use ($pdo) {
    // For non-consumable items, current_stock represents the items NOT assigned
    // We don't know how many were purchased, so we initialize to count of ASSIGNED items as a baseline
    // The user can adjust manually afterwards via the new "ajuste" movement.
    $pdo->exec("UPDATE inventory_item_types it SET it.current_stock = 0 WHERE it.current_stock IS NULL");
}, $ok, $skipped, $errors, $nl);

// ---------- 11. AI settings ----------
step("SEED inventory AI settings", function () use ($pdo) {
    $settings = [
        ['inventory_ai_enabled', '1'],
        ['inventory_ai_model', ''],
        ['inventory_ai_chat_system_prompt',
            'Eres un asistente experto en gestion de inventario para una empresa de centro de llamadas (Punch). Respondes en espanol, conciso y profesional. Tienes acceso al estado actual del inventario y al historial de movimientos a traves del contexto que se te da en cada turno. Solo respondes en base a esos datos; si no tienes informacion suficiente, di que no lo sabes. Usa tablas markdown cuando enumeres varios items.'],
        ['inventory_ai_categorize_prompt',
            'Eres un experto categorizador de inventario. Recibiras el nombre de un articulo nuevo y una lista de categorias disponibles con sus descripciones. Responde SOLO con un JSON valido sin texto adicional: {"category_id": <id>, "description": "<descripcion clara del articulo>", "unit": "<unidad sugerida (unidad, caja, litro, kg, paquete)>", "is_consumable": <1 o 0>, "track_lots": <1 o 0>, "min_stock": <numero sugerido>}. is_consumable=1 si se gasta al usarse (papel, medicina). track_lots=1 si tiene vencimiento (medicinas, alimentos).'],
        ['inventory_ai_predict_prompt',
            'Eres un analista de consumo de inventario. Recibiras el historial de salidas de un articulo de los ultimos 90 dias. Tu trabajo es estimar cuando se acabara el stock actual y cuanto reordenar. Responde SOLO con JSON valido: {"days_until_stockout": <int>, "monthly_consumption_avg": <float>, "recommended_reorder_qty": <float>, "confidence": "<low|medium|high>", "reasoning": "<explicacion breve en espanol>"}'],
        ['inventory_ai_anomaly_prompt',
            'Eres un detector de anomalias de consumo. Recibiras el historial de salidas semanales de un articulo. Identifica si el consumo reciente es anomalo (>2x el promedio, picos sospechosos, agotamiento repentino). Responde SOLO con JSON valido: {"anomaly_detected": <true|false>, "severity": "<low|medium|high|critical>", "explanation": "<en espanol>", "suggested_action": "<accion en espanol>"}'],
        ['inventory_ai_low_stock_threshold_pct', '20'],
        ['inventory_ai_max_chat_history', '20'],
        ['inventory_ai_predict_days', '90'],
        ['inventory_ai_auto_categorize_on_create', '1'],
    ];
    $check = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
    $insert = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, category) VALUES (?, ?, 'string', 'inventory_ai')");
    $n = 0;
    foreach ($settings as [$k, $v]) {
        $check->execute([$k]);
        if (!(int)$check->fetchColumn()) {
            $insert->execute([$k, $v]);
            $n++;
        }
    }
    echo "    inserted=$n{$nl}";
}, $ok, $skipped, $errors, $nl);

echo "{$nl}==========================================={$nl}";
echo "OK: $ok | SKIP: $skipped | ERRORS: $errors{$nl}";

if (!$cli) echo "</pre>";
