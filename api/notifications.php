<?php
/**
 * api/notifications.php
 *
 * Endpoint de la campana del header (centro de notificaciones).
 *
 * Acciones (GET action=..., o POST):
 *   count      -> { count }
 *   list       -> { count, notifications[] }
 *   read       -> marca una como leída (id requerido)
 *   read_all   -> marca todas las visibles como leídas
 *   resolve    -> cierra una que pedía acción (id requerido)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/notifications.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role   = (string) ($_SESSION['role'] ?? '');
$action = $_POST['action'] ?? $_GET['action'] ?? 'count';

try {
    switch ($action) {
        case 'count':
            // El chat suma al contador aunque no viva en system_notifications.
            $chat = notifyChatUnreadForUser($pdo, $userId);
            echo json_encode([
                'success'       => true,
                'count'         => notifyUnreadCount($pdo, $userId, $role) + ($chat ? 1 : 0),
                'chat_unread'   => $chat['count'] ?? 0,
                'poll_seconds'  => (int) (notificationsGetSettings($pdo)['notifications_poll_seconds'] ?? 90),
            ]);
            break;

        case 'list':
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
            $notifications = notifyListForUser($pdo, $userId, $role, [
                'limit'       => $limit,
                'only_unread' => !empty($_GET['only_unread']),
                'type'        => $_GET['type'] ?? null,
            ]);

            // El chat va de primero: es lo más inmediato que puede haber.
            $chatEntry = notifyChatAsNotification($pdo, $userId);
            if ($chatEntry !== null) {
                array_unshift($notifications, $chatEntry);
            }

            echo json_encode([
                'success'       => true,
                'count'         => notifyUnreadCount($pdo, $userId, $role) + ($chatEntry ? 1 : 0),
                'notifications' => $notifications,
            ]);
            break;

        case 'read':
            // El chat es una entrada sintética: se marca leído dentro del chat.
            if (($_POST['id'] ?? $_GET['id'] ?? '') === 'chat') {
                echo json_encode(['success' => true, 'count' => notifyUnreadCount($pdo, $userId, $role)]);
                break;
            }
            $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'id requerido']);
                break;
            }
            // Solo se marca si de verdad le corresponde al usuario: si no, alguien
            // podría marcar leídas notificaciones de otra persona pasando ids.
            $visible = notifyListForUser($pdo, $userId, $role, ['limit' => 100]);
            $allowed = in_array($id, array_map(static fn($n) => (int) $n['id'], $visible), true);
            if (!$allowed) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Notificación no disponible']);
                break;
            }
            notifyMarkRead($pdo, $userId, $id, $role);
            echo json_encode(['success' => true, 'count' => notifyUnreadCount($pdo, $userId, $role)]);
            break;

        case 'read_all':
            $marked = notifyMarkRead($pdo, $userId, null, $role);
            echo json_encode(['success' => true, 'marked' => $marked, 'count' => notifyUnreadCount($pdo, $userId, $role)]);
            break;

        case 'resolve':
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'id requerido']);
                break;
            }
            $visible = notifyListForUser($pdo, $userId, $role, ['limit' => 100]);
            $allowed = in_array($id, array_map(static fn($n) => (int) $n['id'], $visible), true);
            if (!$allowed) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Notificación no disponible']);
                break;
            }
            notifyMarkRead($pdo, $userId, $id, $role);
            echo json_encode(['success' => true, 'resolved' => notifyResolve($pdo, $id, $userId)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);
    }
} catch (Throwable $e) {
    error_log('api/notifications.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno']);
}
