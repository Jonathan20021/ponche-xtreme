<?php
/**
 * Inventory AI endpoints — JSON.
 *
 * Routes (POST):
 *   action=chat            { message:string }
 *   action=categorize      { name:string }
 *   action=predict         { item_type_id:int }
 *   action=anomalies       { item_type_id:int }
 *   action=chat_history    { limit?:int }
 *   action=clear_history   {}
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/logging_functions.php';
require_once __DIR__ . '/../lib/inventory_ai.php';

// Auth — same permission as the inventory module (manual JSON-friendly check)
if (!isset($_SESSION['user_id']) || !function_exists('userHasPermission') || !userHasPermission('hr_employees')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'chat': {
            $msg = trim((string) ($_POST['message'] ?? ''));
            if ($msg === '') throw new RuntimeException('Mensaje vacio');
            $r = inv_ai_chat($pdo, $userId, $msg);
            if (!$r['success']) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $r['error']]);
                exit;
            }
            echo json_encode([
                'success' => true,
                'reply'   => $r['reply'],
                'tokens'  => $r['tokens'],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'categorize': {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Nombre vacio');
            $r = inv_ai_categorize_item($pdo, $name);
            echo json_encode([
                'success'    => $r['success'],
                'suggestion' => $r['suggestion'],
                'error'      => $r['error'],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'predict': {
            $itemId = (int) ($_POST['item_type_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('item_type_id requerido');
            $r = inv_ai_predict_consumption($pdo, $itemId);
            echo json_encode([
                'success'    => $r['success'],
                'prediction' => $r['prediction'],
                'error'      => $r['error'],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'anomalies': {
            $itemId = (int) ($_POST['item_type_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('item_type_id requerido');
            $r = inv_ai_detect_anomalies($pdo, $itemId);
            echo json_encode([
                'success' => $r['success'],
                'anomaly' => $r['anomaly'],
                'error'   => $r['error'],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'chat_history': {
            $limit = max(1, min(200, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 50)));
            $stmt = $pdo->prepare("SELECT id, role, message, created_at FROM inventory_ai_chats
                WHERE user_id = ? AND role IN ('user','assistant')
                ORDER BY id DESC LIMIT ?");
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
            $stmt->execute();
            $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode(['success' => true, 'messages' => $rows], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'clear_history': {
            $stmt = $pdo->prepare("DELETE FROM inventory_ai_chats WHERE user_id = ?");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
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
