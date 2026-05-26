<?php
/**
 * API del Sistema de Chat en Tiempo Real
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Verificar sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Leer el body una sola vez al inicio
$rawInput = file_get_contents('php://input');
$jsonData = $rawInput ? json_decode($rawInput, true) : null;

// Obtener action del JSON body o query string
$action = ($jsonData['action'] ?? null) ?: ($_GET['action'] ?? $_POST['action'] ?? '');

// Debug mejorado
error_log('=== CHAT API DEBUG ===');
error_log('Method: ' . $_SERVER['REQUEST_METHOD']);
error_log('Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
error_log('Raw Input Length: ' . strlen($rawInput));
error_log('Raw Input: ' . $rawInput);
error_log('JSON Decode Error: ' . json_last_error_msg());
error_log('JSON Data: ' . var_export($jsonData, true));
error_log('GET: ' . var_export($_GET, true));
error_log('POST: ' . var_export($_POST, true));
error_log('Action resolved: ' . var_export($action, true));
error_log('=== END DEBUG ===');

// Si no hay action, devolver error detallado
if (empty($action)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Acción no válida',
        'debug' => [
            'method' => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
            'raw_input_length' => strlen($rawInput),
            'json_error' => json_last_error_msg(),
            'has_json_data' => $jsonData !== null,
            'has_get_action' => isset($_GET['action']),
            'has_post_action' => isset($_POST['action'])
        ]
    ]);
    exit;
}

try {
    switch ($action) {
        case 'get_conversations':
            getConversations($pdo, $userId);
            break;
            
        case 'get_messages':
            $conversationId = (int)($_GET['conversation_id'] ?? 0);
            $lastMessageId = (int)($_GET['last_message_id'] ?? 0);
            getMessages($pdo, $userId, $conversationId, $lastMessageId);
            break;
            
        case 'send_message':
            if ($jsonData === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
                break;
            }
            sendMessage($pdo, $userId, $jsonData);
            break;
            
        case 'create_conversation':
            if ($jsonData === null) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'error' => 'Invalid JSON or empty body',
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'raw_input' => $rawInput,
                    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
                    'json_error' => json_last_error_msg()
                ]);
                break;
            }
            
            createConversation($pdo, $userId, $jsonData);
            break;
            
        case 'mark_as_read':
            $conversationId = (int)($_POST['conversation_id'] ?? 0);
            markConversationAsRead($pdo, $userId, $conversationId);
            break;
            
        case 'get_unread_count':
            getUnreadCount($pdo, $userId);
            break;
            
        case 'update_status':
            $status = $_POST['status'] ?? 'online';
            updateUserStatus($pdo, $userId, $status);
            break;
            
        case 'get_online_users':
            getOnlineUsers($pdo);
            break;
            
        case 'search_users':
            $query = $_GET['q'] ?? '';
            searchUsers($pdo, $query);
            break;
            
        case 'edit_message':
            if ($jsonData === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
                break;
            }
            editMessage($pdo, $userId, $jsonData);
            break;
            
        case 'delete_message':
            $messageId = (int)($_POST['message_id'] ?? 0);
            deleteMessage($pdo, $userId, $messageId);
            break;
            
        case 'add_reaction':
            if ($jsonData === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
                break;
            }
            addReaction($pdo, $userId, $jsonData);
            break;
            
        case 'typing':
            $conversationId = (int)($_POST['conversation_id'] ?? 0);
            updateTypingStatus($pdo, $userId, $conversationId);
            break;
            
        case 'get_typing_users':
            $conversationId = (int)($_GET['conversation_id'] ?? 0);
            getTypingUsers($pdo, $conversationId, $userId);
            break;
            
        case 'send_mass_message':
            if ($jsonData === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
                break;
            }
            sendMassMessage($pdo, $userId, $jsonData);
            break;

        case 'add_group_member':
            if ($jsonData === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
                break;
            }
            addGroupMemberFixed($pdo, $userId, $jsonData);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ==================== FUNCIONES ====================

function getConversations(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("\n        SELECT \n            c.id,\n            c.name,\n            c.type,\n            c.last_message_at,\n            (SELECT COUNT(*) FROM chat_messages cm \n             WHERE cm.conversation_id = c.id \n             AND cm.created_at > COALESCE(p.last_read_at, '1970-01-01')\n             AND cm.user_id != ?\n            ) as unread_count,\n            (SELECT cm.message_text FROM chat_messages cm \n             WHERE cm.conversation_id = c.id \n             ORDER BY cm.created_at DESC LIMIT 1\n            ) as last_message,\n            (SELECT u.username FROM chat_messages cm \n             JOIN users u ON u.id = cm.user_id\n             WHERE cm.conversation_id = c.id \n             ORDER BY cm.created_at DESC LIMIT 1\n            ) as last_sender,\n            CASE \n                WHEN c.type = 'direct' THEN\n                    (SELECT u.full_name FROM chat_participants cp2\n                     JOIN users u ON u.id = cp2.user_id\n                     WHERE cp2.conversation_id = c.id AND cp2.user_id != ? LIMIT 1)\n                ELSE c.name\n            END as display_name,\n            CASE \n                WHEN c.type = 'direct' THEN\n                    (SELECT u.id FROM chat_participants cp2\n                     JOIN users u ON u.id = cp2.user_id\n                     WHERE cp2.conversation_id = c.id AND cp2.user_id != ? LIMIT 1)\n                ELSE NULL\n            END as other_user_id\n        FROM chat_conversations c\n        JOIN chat_participants p ON p.conversation_id = c.id\n        WHERE p.user_id = ? AND p.is_active = 1 AND c.is_active = 1\n        ORDER BY c.last_message_at DESC\n    ");
    
    $stmt->execute([$userId, $userId, $userId, $userId]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich preview data for conversations whose last message is an attachment.
    $previewStmt = $pdo->prepare("
        SELECT
            m.message_type,
            a.file_original_name
        FROM chat_messages m
        LEFT JOIN chat_attachments a ON a.message_id = m.id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at DESC, a.id ASC
        LIMIT 1
    ");

    foreach ($conversations as &$conversation) {
        $conversation['last_message_type'] = null;
        $conversation['last_attachment_name'] = null;

        if (!empty($conversation['last_message'])) {
            continue;
        }

        $previewStmt->execute([(int)$conversation['id']]);
        $preview = $previewStmt->fetch(PDO::FETCH_ASSOC);
        if ($preview) {
            $conversation['last_message_type'] = $preview['message_type'] ?? null;
            $conversation['last_attachment_name'] = $preview['file_original_name'] ?? null;
        }
    }
    unset($conversation);
    
    echo json_encode(['success' => true, 'conversations' => $conversations]);
}

function getMessages(PDO $pdo, int $userId, int $conversationId, int $lastMessageId = 0): void {
    // Verificar que el usuario es participante
    $stmt = $pdo->prepare("\n        SELECT id FROM chat_participants \n        WHERE conversation_id = ? AND user_id = ? AND is_active = 1\n    ");
    $stmt->execute([$conversationId, $userId]);
    
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return;
    }
    
    // Obtener mensajes
    $query = "\n        SELECT \n            m.id,\n            m.user_id,\n            m.message_text,\n            m.message_type,\n            m.is_edited,\n            m.is_deleted,\n            m.created_at,\n            m.parent_message_id,\n            u.username,\n            u.full_name,\n            (SELECT JSON_ARRAYAGG(\n                JSON_OBJECT(\n                    'id', a.id,\n                    'file_name', a.file_name,\n                    'file_original_name', a.file_original_name,\n                    'file_type', a.file_type,\n                    'file_size', a.file_size,\n                    'mime_type', a.mime_type,\n                    'thumbnail_path', a.thumbnail_path\n                )\n            ) FROM chat_attachments a WHERE a.message_id = m.id) as attachments,\n            (SELECT JSON_ARRAYAGG(\n                JSON_OBJECT(\n                    'reaction', r.reaction,\n                    'user_id', r.user_id,\n                    'username', ru.username\n                )\n            ) FROM chat_reactions r \n            JOIN users ru ON ru.id = r.user_id \n            WHERE r.message_id = m.id) as reactions\n        FROM chat_messages m\n        JOIN users u ON u.id = m.user_id\n        WHERE m.conversation_id = ? AND m.is_deleted = 0\n    ";
    
    $params = [$conversationId];
    
    if ($lastMessageId > 0) {
        $query .= " AND m.id > ?";
        $params[] = $lastMessageId;
    }
    
    $query .= " ORDER BY m.created_at ASC LIMIT " . CHAT_MESSAGES_PER_PAGE;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar attachments y reactions
    foreach ($messages as &$msg) {
        $msg['attachments'] = $msg['attachments'] ? json_decode($msg['attachments'], true) : [];
        $msg['reactions'] = $msg['reactions'] ? json_decode($msg['reactions'], true) : [];
    }
    
    echo json_encode(['success' => true, 'messages' => $messages]);
}

function sendMessage(PDO $pdo, int $userId, array $data): void {
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $messageText = trim($data['message_text'] ?? '');
    $parentMessageId = !empty($data['parent_message_id']) ? (int)$data['parent_message_id'] : null;
    
    if ($conversationId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID de conversación inválido']);
        return;
    }
    
    // Verificar permisos
    if (!checkChatPermission($pdo, $userId, 'can_use_chat')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para usar el chat']);
        return;
    }
    
    // Verificar que es participante
    $stmt = $pdo->prepare("\n        SELECT id FROM chat_participants \n        WHERE conversation_id = ? AND user_id = ? AND is_active = 1\n    ");
    $stmt->execute([$conversationId, $userId]);
    
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return;
    }
    
    if (empty($messageText)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El mensaje no puede estar vacío']);
        return;
    }
    
    if (mb_strlen($messageText) > CHAT_MAX_MESSAGE_LENGTH) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El mensaje es demasiado largo']);
        return;
    }
    
    // Insertar mensaje
    $stmt = $pdo->prepare("\n        INSERT INTO chat_messages (conversation_id, user_id, message_text, parent_message_id)\n        VALUES (?, ?, ?, ?)\n    ");
    $stmt->execute([$conversationId, $userId, $messageText, $parentMessageId]);
    $messageId = $pdo->lastInsertId();
    
    // Crear notificaciones para otros participantes
    $stmt = $pdo->prepare("\n        INSERT INTO chat_notifications (user_id, conversation_id, message_id, notification_type)\n        SELECT user_id, ?, ?, 'new_message'\n        FROM chat_participants\n        WHERE conversation_id = ? AND user_id != ? AND is_active = 1\n    ");
    $stmt->execute([$conversationId, $messageId, $conversationId, $userId]);
    
    // Obtener el timestamp exacto del mensaje recién creado
    $stmt = $pdo->prepare("SELECT created_at FROM chat_messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $createdAt = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true, 
        'message_id' => $messageId,
        'created_at' => $createdAt
    ]);
}

function createConversation(PDO $pdo, int $userId, array $data): void {
    $type = $data['type'] ?? 'direct';
    $name = trim($data['name'] ?? '');
    $participantIds = $data['participants'] ?? [];
    
    if (!in_array($type, ['direct', 'group', 'channel'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tipo de conversación inválido']);
        return;
    }
    
    if ($type === 'group' && empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El nombre del grupo es requerido']);
        return;
    }
    
    if (empty($participantIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Debes seleccionar al menos un participante']);
        return;
    }
    
    // Verificar permiso para crear grupos
    if ($type === 'group' && !checkChatPermission($pdo, $userId, 'can_create_groups')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para crear grupos']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        // Para conversaciones directas, verificar si ya existe
        if ($type === 'direct' && count($participantIds) === 1) {
            $otherUserId = (int)$participantIds[0];
            
            $stmt = $pdo->prepare("\n                SELECT c.id \n                FROM chat_conversations c\n                JOIN chat_participants p1 ON p1.conversation_id = c.id\n                JOIN chat_participants p2 ON p2.conversation_id = c.id\n                WHERE c.type = 'direct' \n                AND p1.user_id = ? \n                AND p2.user_id = ?\n                AND c.is_active = 1\n                LIMIT 1\n            ");
            $stmt->execute([$userId, $otherUserId]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                $pdo->rollBack();
                echo json_encode(['success' => true, 'conversation_id' => $existing['id'], 'existing' => true]);
                return;
            }
        }
        
        // Crear conversación
        $stmt = $pdo->prepare("\n            INSERT INTO chat_conversations (type, name, created_by)\n            VALUES (?, ?, ?)\n        ");
        $stmt->execute([$type, $name ?: null, $userId]);
        $conversationId = $pdo->lastInsertId();
        
        // Agregar creador como admin
        $stmt = $pdo->prepare("\n            INSERT INTO chat_participants (conversation_id, user_id, role)\n            VALUES (?, ?, 'admin')\n        ");
        $stmt->execute([$conversationId, $userId]);
        
        // Agregar otros participantes
        $stmt = $pdo->prepare("\n            INSERT INTO chat_participants (conversation_id, user_id, role)\n            VALUES (?, ?, 'member')\n        ");
        
        foreach ($participantIds as $participantId) {
            $participantId = (int)$participantId;
            if ($participantId !== $userId) {
                $stmt->execute([$conversationId, $participantId]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'conversation_id' => $conversationId]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function markConversationAsRead(PDO $pdo, int $userId, int $conversationId): void {
    $stmt = $pdo->prepare("\n        UPDATE chat_participants\n        SET last_read_at = NOW()\n        WHERE conversation_id = ? AND user_id = ?\n    ");
    $stmt->execute([$conversationId, $userId]);
    
    echo json_encode(['success' => true]);
}

function getUnreadCount(PDO $pdo, int $userId): void {
    // Contar mensajes no leídos de todas las conversaciones del usuario
    $stmt = $pdo->prepare("\n        SELECT COUNT(*) as count\n        FROM chat_messages cm\n        JOIN chat_participants p ON p.conversation_id = cm.conversation_id\n        WHERE p.user_id = ? \n        AND p.is_active = 1\n        AND cm.user_id != ?\n        AND cm.created_at > COALESCE(p.last_read_at, '1970-01-01')\n        AND cm.is_deleted = 0\n    ");
    $stmt->execute([$userId, $userId]);
    $result = $stmt->fetch();
    
    echo json_encode(['success' => true, 'unread_count' => (int)$result['count']]);
}

function updateUserStatus(PDO $pdo, int $userId, string $status): void {
    if (!in_array($status, ['online', 'offline', 'away', 'busy'])) {
        $status = 'online';
    }
    
    $stmt = $pdo->prepare("\n        INSERT INTO chat_user_status (user_id, status, last_seen)\n        VALUES (?, ?, NOW())\n        ON DUPLICATE KEY UPDATE status = ?, last_seen = NOW()\n    ");
    $stmt->execute([$userId, $status, $status]);
    
    echo json_encode(['success' => true]);
}

function getOnlineUsers(PDO $pdo): void {
    $stmt = $pdo->query("\n        SELECT \n            u.id,\n            u.username,\n            u.full_name,\n            u.role,\n            COALESCE(s.status, 'offline') as status,\n            s.last_seen\n        FROM users u\n        LEFT JOIN chat_user_status s ON s.user_id = u.id\n        WHERE u.is_active = 1\n        ORDER BY \n            CASE \n                WHEN s.status = 'online' THEN 1\n                WHEN s.last_seen > DATE_SUB(NOW(), INTERVAL " . CHAT_ONLINE_THRESHOLD . " SECOND) THEN 2\n                ELSE 3\n            END,\n            u.full_name\n    ");
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Marcar como online si tiene actividad reciente
    foreach ($users as &$user) {
        if ($user['last_seen'] && strtotime($user['last_seen']) > time() - CHAT_ONLINE_THRESHOLD) {
            $user['is_online'] = true;
        } else {
            $user['is_online'] = ($user['status'] === 'online');
        }
    }
    
    echo json_encode(['success' => true, 'users' => $users]);
}

function searchUsers(PDO $pdo, string $query): void {
    $query = '%' . $query . '%';
    
    $stmt = $pdo->prepare("\n        SELECT id, username, full_name, role\n        FROM users\n        WHERE (username LIKE ? OR full_name LIKE ?)\n        AND is_active = 1\n        ORDER BY full_name\n        LIMIT 20\n    ");
    $stmt->execute([$query, $query]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'users' => $users]);
}

function editMessage(PDO $pdo, int $userId, array $data): void {
    $messageId = (int)($data['message_id'] ?? 0);
    $newText = trim($data['message_text'] ?? '');
    
    if (empty($newText)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El mensaje no puede estar vacío']);
        return;
    }
    
    // Verificar que el usuario es el autor
    $stmt = $pdo->prepare("SELECT user_id FROM chat_messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();
    
    if (!$message || $message['user_id'] != $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return;
    }
    
    $stmt = $pdo->prepare("\n        UPDATE chat_messages\n        SET message_text = ?, is_edited = 1, edited_at = NOW()\n        WHERE id = ?\n    ");
    $stmt->execute([$newText, $messageId]);
    
    echo json_encode(['success' => true]);
}

function deleteMessage(PDO $pdo, int $userId, int $messageId): void {
    // Verificar que el usuario es el autor
    $stmt = $pdo->prepare("SELECT user_id FROM chat_messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();
    
    if (!$message || $message['user_id'] != $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return;
    }
    
    $stmt = $pdo->prepare("\n        UPDATE chat_messages\n        SET is_deleted = 1, deleted_at = NOW(), message_text = '[Mensaje eliminado]'\n        WHERE id = ?\n    ");
    $stmt->execute([$messageId]);
    
    echo json_encode(['success' => true]);
}

function addReaction(PDO $pdo, int $userId, array $data): void {
    $messageId = (int)($data['message_id'] ?? 0);
    $reaction = trim($data['reaction'] ?? '');
    
    if (empty($reaction)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Reacción inválida']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("\n            INSERT INTO chat_reactions (message_id, user_id, reaction)\n            VALUES (?, ?, ?)\n            ON DUPLICATE KEY UPDATE reaction = ?\n        ");
        $stmt->execute([$messageId, $userId, $reaction, $reaction]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        // Si ya existe, eliminar la reacción
        $stmt = $pdo->prepare("\n            DELETE FROM chat_reactions\n            WHERE message_id = ? AND user_id = ? AND reaction = ?\n        ");
        $stmt->execute([$messageId, $userId, $reaction]);
        
        echo json_encode(['success' => true, 'removed' => true]);
    }
}

function updateTypingStatus(PDO $pdo, int $userId, int $conversationId): void {
    // Guardar en cache temporal (puedes usar Redis o memcached en producción)
    $cacheKey = "typing_{$conversationId}_{$userId}";
    $cacheFile = sys_get_temp_dir() . "/{$cacheKey}";
    file_put_contents($cacheFile, time());
    
    echo json_encode(['success' => true]);
}

function getTypingUsers(PDO $pdo, int $conversationId, int $userId): void {
    $typingUsers = [];
    $tempDir = sys_get_temp_dir();
    $pattern = "typing_{$conversationId}_*";
    $currentTime = time();
    
    foreach (glob("{$tempDir}/{$pattern}") as $file) {
        $lastUpdate = (int)file_get_contents($file);
        
        // Si fue actualizado en los últimos 5 segundos
        if (($currentTime - $lastUpdate) < 5) {
            preg_match('/typing_\d+_(\d+)/', basename($file), $matches);
            if (!empty($matches[1]) && (int)$matches[1] !== $userId) {
                $typingUserId = (int)$matches[1];
                
                $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                $stmt->execute([$typingUserId]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $typingUsers[] = $user['full_name'];
                }
            }
        } else {
            // Limpiar archivos antiguos
            @unlink($file);
        }
    }
    
    echo json_encode(['success' => true, 'typing_users' => $typingUsers]);
}

function sendMassMessage(PDO $pdo, int $userId, array $data): void {
    $messageText = trim($data['message_text'] ?? '');
    
    if (empty($messageText)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El mensaje no puede estar vacío']);
        return;
    }
    
    // Verificar permiso de mensajes masivos
    require_once __DIR__ . '/../lib/authorization_functions.php';
    if (!userHasPermission('chat_mass_message')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para enviar mensajes masivos']);
        return;
    }
    
    if (mb_strlen($messageText) > CHAT_MAX_MESSAGE_LENGTH) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El mensaje es demasiado largo']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Obtener todos los usuarios activos excepto el remitente
        $stmt = $pdo->prepare("\n            SELECT id, full_name, username \n            FROM users \n            WHERE id != ? AND is_active = 1\n            ORDER BY full_name\n        ");
        $stmt->execute([$userId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($users)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'No hay usuarios disponibles para enviar el mensaje']);
            return;
        }
        
        $sentCount = 0;
        $errors = [];
        
        // Crear conversación individual con cada usuario y enviar mensaje
        foreach ($users as $user) {
            try {
                // Verificar si ya existe una conversación directa entre estos usuarios
                $conversationStmt = $pdo->prepare("\n                    SELECT c.id \n                    FROM chat_conversations c\n                    JOIN chat_participants p1 ON p1.conversation_id = c.id AND p1.user_id = ?\n                    JOIN chat_participants p2 ON p2.conversation_id = c.id AND p2.user_id = ?\n                    WHERE c.type = 'direct' AND c.is_active = 1\n                    LIMIT 1\n                ");
                $conversationStmt->execute([$userId, $user['id']]);
                $conversation = $conversationStmt->fetch();
                
                $conversationId = null;
                
                if ($conversation) {
                    $conversationId = $conversation['id'];
                } else {
                    // Crear nueva conversación directa
                    $createConvStmt = $pdo->prepare("\n                        INSERT INTO chat_conversations (name, type, created_by) \n                        VALUES (?, 'direct', ?)\n                    ");
                    $createConvStmt->execute([null, $userId]);
                    $conversationId = $pdo->lastInsertId();
                    
                    // Agregar participantes
                    $addParticipantStmt = $pdo->prepare("\n                        INSERT INTO chat_participants (conversation_id, user_id, joined_at) \n                        VALUES (?, ?, NOW())\n                    ");
                    $addParticipantStmt->execute([$conversationId, $userId]);
                    $addParticipantStmt->execute([$conversationId, $user['id']]);
                }
                
                // Enviar mensaje
                $messageStmt = $pdo->prepare("\n                    INSERT INTO chat_messages (conversation_id, user_id, message_text, message_type) \n                    VALUES (?, ?, ?, 'mass_message')\n                ");
                $messageStmt->execute([$conversationId, $userId, $messageText]);
                $messageId = $pdo->lastInsertId();
                
                // Crear notificación
                $notificationStmt = $pdo->prepare("\n                    INSERT INTO chat_notifications (user_id, conversation_id, message_id, notification_type) \n                    VALUES (?, ?, ?, 'mass_message')\n                ");
                $notificationStmt->execute([$user['id'], $conversationId, $messageId]);
                
                // Actualizar timestamp de la conversación
                $updateConvStmt = $pdo->prepare("\n                    UPDATE chat_conversations \n                    SET last_message_at = NOW() \n                    WHERE id = ?\n                ");
                $updateConvStmt->execute([$conversationId]);
                
                $sentCount++;
                
            } catch (Exception $e) {
                $errors[] = "Error enviando a {$user['full_name']}: " . $e->getMessage();
                error_log("Mass message error for user {$user['id']}: " . $e->getMessage());
            }
        }
        
        $pdo->commit();
        
        // Registrar la actividad de mensaje masivo
        try {
            $logStmt = $pdo->prepare("\n                INSERT INTO activity_logs (user_id, action, details, ip_address) \n                VALUES (?, 'mass_message_sent', ?, ?)\n            ");
            $logDetails = json_encode([
                'message_preview' => mb_substr($messageText, 0, 100),
                'recipients_count' => $sentCount,
                'total_users' => count($users),
                'errors_count' => count($errors)
            ]);
            $logStmt->execute([$userId, $logDetails, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        } catch (Exception $e) {
            error_log("Failed to log mass message activity: " . $e->getMessage());
        }
        
        $response = [
            'success' => true,
            'message' => "Mensaje enviado exitosamente a {$sentCount} usuarios",
            'sent_count' => $sentCount,
            'total_users' => count($users)
        ];
        
        if (!empty($errors)) {
            $response['warnings'] = $errors;
        }
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Mass message transaction error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error interno del servidor al enviar mensajes masivos']);
    }
}

function checkChatPermission(PDO $pdo, int $userId, string $permission): bool {
    try {
        $stmt = $pdo->prepare("\n            SELECT {$permission}, is_restricted, restricted_until\n            FROM chat_permissions\n            WHERE user_id = ?\n        ");
        $stmt->execute([$userId]);
        $perms = $stmt->fetch();
        
        if (!$perms) {
            // Si no hay registro, verificar el rol del usuario
            $userStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch();
            
            // Solo ADMIN y SUPERVISOR tienen permisos por defecto para crear grupos
            // AGENT y otros roles deben tener un registro explícito
            if ($permission === 'can_create_groups') {
                return in_array($user['role'] ?? '', ['ADMIN', 'SUPERVISOR']);
            }
            
            // Otros permisos por defecto permitir
            return true;
        }
        
        if ($perms['is_restricted']) {
            if ($perms['restricted_until'] && strtotime($perms['restricted_until']) > time()) {
                return false;
            }
        }
        
        return (bool)$perms[$permission];
    } catch (Exception $e) {
        // Si la tabla no existe o hay error, denegar por seguridad
        error_log("Chat permission check error: " . $e->getMessage());
        return false;
    }
}

// Re-implementación estable de agregar miembro al grupo
function addGroupMemberFixed(PDO $pdo, int $userId, array $data): void {
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $newMemberId = (int)($data['user_id'] ?? 0);

    if ($conversationId <= 0 || $newMemberId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
        return;
    }

    try {
        $pdo->beginTransaction();

        // 1) Validar que es grupo
        $stmtConv = $pdo->prepare("SELECT type, name FROM chat_conversations WHERE id = ? AND is_active = 1");
        $stmtConv->execute([$conversationId]);
        $conv = $stmtConv->fetch(PDO::FETCH_ASSOC);
        if (!$conv || ($conv['type'] ?? '') !== 'group') {
            throw new Exception('Conversación no válida o no es un grupo');
        }

        // 2) Validar permisos del solicitante (admin del grupo)
        $stmtRole = $pdo->prepare("SELECT role FROM chat_participants WHERE conversation_id = ? AND user_id = ? AND is_active = 1");
        $stmtRole->execute([$conversationId, $userId]);
        $roleRow = $stmtRole->fetch(PDO::FETCH_ASSOC);
        if (!$roleRow) {
            throw new Exception('No eres miembro de este grupo');
        }
        if (($roleRow['role'] ?? 'member') !== 'admin') {
            throw new Exception('Solo los administradores del grupo pueden agregar miembros');
        }

        // 3) Reactivar o insertar participante
        $stmtCheck = $pdo->prepare("SELECT id, is_active FROM chat_participants WHERE conversation_id = ? AND user_id = ?");
        $stmtCheck->execute([$conversationId, $newMemberId]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ((int)$existing['is_active'] === 1) {
                throw new Exception('El usuario ya es miembro del grupo');
            }
            $stmtReactivate = $pdo->prepare("UPDATE chat_participants SET is_active = 1, joined_at = NOW(), role = 'member' WHERE id = ?");
            $stmtReactivate->execute([$existing['id']]);
        } else {
            $stmtInsert = $pdo->prepare("INSERT INTO chat_participants (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
            $stmtInsert->execute([$conversationId, $newMemberId]);
        }

        // 4) Mensaje del sistema
        $stmtUser = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmtUser->execute([$newMemberId]);
        $newMemberName = $stmtUser->fetchColumn() ?: 'Usuario';
        $systemMessage = "ha agregado a {$newMemberName} al grupo";

        $stmtMsg = $pdo->prepare("INSERT INTO chat_messages (conversation_id, user_id, message_text, message_type) VALUES (?, ?, ?, 'system')");
        $stmtMsg->execute([$conversationId, $userId, $systemMessage]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
