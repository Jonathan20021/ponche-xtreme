<?php
/**
 * Inventory stock operations — JSON.
 *
 * Routes (POST):
 *   action=record_movement   { item_type_id, movement_type, quantity, lot_id?, reason?, reference?, notes? }
 *   action=register_lot      { item_type_id, quantity, lot_code?, received_date?, expiration_date?,
 *                              supplier_id?, unit_cost?, reason?, reference?, notes? }
 *   action=quick_exit        { item_type_id, quantity, employee_id?, reason?, notes? }
 *   action=adjust            { item_type_id, signed_quantity, reason, notes? }
 *   action=set_min_max       { item_type_id, min_stock?, max_stock?, reorder_qty?, unit_cost?, supplier_id? }
 *   action=item_stock        { item_type_id }   GET allowed too
 *   action=movement_log      { item_type_id?, movement_type?, from?, to?, limit? }   GET allowed
 *   action=lots              { item_type_id }
 *   action=suppliers_list    GET
 *   action=supplier_save     { id?, name, contact_name?, email?, phone?, address?, notes? }
 *   action=supplier_delete   { id }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/logging_functions.php';
require_once __DIR__ . '/../lib/inventory_functions.php';

// Auth (JSON-friendly)
if (!isset($_SESSION['user_id']) || !function_exists('userHasPermission') || !userHasPermission('hr_employees')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

function in_post_or_get(string $key, $default = null) {
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

try {
    switch ($action) {

        case 'record_movement': {
            $id = inv_record_movement($pdo, [
                'item_type_id'  => (int) ($_POST['item_type_id'] ?? 0),
                'movement_type' => $_POST['movement_type'] ?? '',
                'quantity'      => (float) ($_POST['quantity'] ?? 0),
                'lot_id'        => $_POST['lot_id'] ?? null,
                'unit_cost'     => isset($_POST['unit_cost']) && $_POST['unit_cost'] !== '' ? (float) $_POST['unit_cost'] : null,
                'reason'        => $_POST['reason']    ?? '',
                'reference'     => $_POST['reference'] ?? '',
                'employee_id'   => $_POST['employee_id'] ?? null,
                'notes'         => $_POST['notes']     ?? '',
            ]);
            echo json_encode(['success' => true, 'movement_id' => $id]);
            break;
        }

        case 'register_lot': {
            $r = inv_register_lot($pdo, [
                'item_type_id'    => (int) ($_POST['item_type_id'] ?? 0),
                'quantity'        => (float) ($_POST['quantity'] ?? 0),
                'lot_code'        => $_POST['lot_code'] ?? '',
                'received_date'   => $_POST['received_date'] ?? date('Y-m-d'),
                'expiration_date' => $_POST['expiration_date'] ?? null,
                'supplier_id'     => $_POST['supplier_id'] ?? null,
                'unit_cost'       => isset($_POST['unit_cost']) && $_POST['unit_cost'] !== '' ? (float) $_POST['unit_cost'] : null,
                'reason'          => $_POST['reason']    ?? 'Recepcion',
                'reference'       => $_POST['reference'] ?? '',
                'notes'           => $_POST['notes']     ?? '',
            ]);
            echo json_encode(['success' => true, 'lot_id' => $r['lot_id'], 'movement_id' => $r['movement_id']]);
            break;
        }

        case 'quick_exit': {
            $id = inv_record_movement($pdo, [
                'item_type_id'  => (int) ($_POST['item_type_id'] ?? 0),
                'movement_type' => 'EXIT',
                'quantity'      => (float) ($_POST['quantity'] ?? 0),
                'reason'        => $_POST['reason'] ?? 'Salida',
                'employee_id'   => $_POST['employee_id'] ?? null,
                'notes'         => $_POST['notes'] ?? '',
            ]);
            echo json_encode(['success' => true, 'movement_id' => $id]);
            break;
        }

        case 'adjust': {
            $signedQty = (float) ($_POST['signed_quantity'] ?? 0);
            if ($signedQty === 0.0) throw new RuntimeException('signed_quantity no puede ser 0');
            $id = inv_record_movement($pdo, [
                'item_type_id'  => (int) ($_POST['item_type_id'] ?? 0),
                'movement_type' => 'ADJUSTMENT',
                'quantity'      => $signedQty,
                'reason'        => $_POST['reason'] ?? 'Ajuste manual',
                'notes'         => $_POST['notes']  ?? '',
            ]);
            echo json_encode(['success' => true, 'movement_id' => $id]);
            break;
        }

        case 'set_min_max': {
            $itemId = (int) ($_POST['item_type_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('item_type_id requerido');
            $fields = ['min_stock', 'max_stock', 'reorder_qty', 'unit_cost', 'supplier_id'];
            $set = [];
            $params = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $_POST) && $_POST[$f] !== '') {
                    $set[] = "`$f` = ?";
                    $params[] = $f === 'supplier_id' ? (int) $_POST[$f] : (float) $_POST[$f];
                }
            }
            if (!$set) throw new RuntimeException('Nada que actualizar');
            $params[] = $itemId;
            $pdo->prepare("UPDATE inventory_item_types SET " . implode(', ', $set) . " WHERE id = ?")->execute($params);
            echo json_encode(['success' => true]);
            break;
        }

        case 'item_stock': {
            $itemId = (int) in_post_or_get('item_type_id', 0);
            if ($itemId <= 0) throw new RuntimeException('item_type_id requerido');
            $stmt = $pdo->prepare("SELECT it.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                                          s.name AS supplier_name
                                   FROM inventory_item_types it
                                   JOIN inventory_categories c ON c.id = it.category_id
                                   LEFT JOIN inventory_suppliers s ON s.id = it.supplier_id
                                   WHERE it.id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new RuntimeException('Item no existe');
            echo json_encode(['success' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'movement_log': {
            $filters = [];
            if (!empty($_REQUEST['item_type_id']))  $filters['item_type_id']  = (int) $_REQUEST['item_type_id'];
            if (!empty($_REQUEST['movement_type'])) $filters['movement_type'] = $_REQUEST['movement_type'];
            if (!empty($_REQUEST['from']))          $filters['from_date']     = $_REQUEST['from'];
            if (!empty($_REQUEST['to']))            $filters['to_date']       = $_REQUEST['to'];
            $limit = max(1, min(500, (int) ($_REQUEST['limit'] ?? 100)));
            $rows = inv_get_recent_movements($pdo, $limit, $filters);
            echo json_encode(['success' => true, 'movements' => $rows], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'lots': {
            $itemId = (int) in_post_or_get('item_type_id', 0);
            if ($itemId <= 0) throw new RuntimeException('item_type_id requerido');
            $stmt = $pdo->prepare("SELECT l.*, s.name AS supplier_name
                                   FROM inventory_lots l
                                   LEFT JOIN inventory_suppliers s ON s.id = l.supplier_id
                                   WHERE l.item_type_id = ?
                                   ORDER BY (l.expiration_date IS NULL), l.expiration_date ASC, l.received_date ASC");
            $stmt->execute([$itemId]);
            echo json_encode(['success' => true, 'lots' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'suppliers_list': {
            $rows = $pdo->query("SELECT * FROM inventory_suppliers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'suppliers' => $rows], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'supplier_save': {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Nombre requerido');
            $fields = ['name','contact_name','email','phone','address','notes'];
            $vals = [];
            foreach ($fields as $f) $vals[$f] = trim((string) ($_POST[$f] ?? ''));
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE inventory_suppliers SET name=?, contact_name=?, email=?, phone=?, address=?, notes=? WHERE id=?");
                $stmt->execute([$vals['name'],$vals['contact_name'],$vals['email'],$vals['phone'],$vals['address'],$vals['notes'],$id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO inventory_suppliers (name, contact_name, email, phone, address, notes) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$vals['name'],$vals['contact_name'],$vals['email'],$vals['phone'],$vals['address'],$vals['notes']]);
                $id = (int) $pdo->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id]);
            break;
        }

        case 'supplier_delete': {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id requerido');
            $pdo->prepare("UPDATE inventory_suppliers SET is_active = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Accion desconocida']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
