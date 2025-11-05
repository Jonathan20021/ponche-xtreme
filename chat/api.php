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
            
        case 'get_typing':
            $conversationId = (int)($_GET['conversation_id'] ?? 0);
            getTypingUsers($pdo, $conversationId, $userId);
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
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.type,
            c.last_message_at,
            (SELECT COUNT(*) FROM chat_messages cm 
             WHERE cm.conversation_id = c.id 
             AND cm.created_at > COALESCE(p.last_read_at, '1970-01-01')
             AND cm.user_id != ?
            ) as unread_count,
            (SELECT cm.message_text FROM chat_messages cm 
             WHERE cm.conversation_id = c.id 
             ORDER BY cm.created_at DESC LIMIT 1
            ) as last_message,
            (SELECT u.username FROM chat_messages cm 
             JOIN users u ON u.id = cm.user_id
             WHERE cm.conversation_id = c.id 
             ORDER BY cm.created_at DESC LIMIT 1
            ) as last_sender,
            CASE 
                WHEN c.type = 'direct' THEN
                    (SELECT u.full_name FROM chat_participants cp2
                     JOIN users u ON u.id = cp2.user_id
                     WHERE cp2.conversation_id = c.id AND cp2.user_id != ? LIMIT 1)
                ELSE c.name
            END as display_name,
            CASE 
                WHEN c.type = 'direct' THEN
                    (SELECT u.id FROM chat_participants cp2
                     JOIN users u ON u.id = cp2.user_id
                     WHERE cp2.conversation_id = c.id AND cp2.user_id != ? LIMIT 1)
                ELSE NULL
            END as other_user_id
        FROM chat_conversations c
        JOIN chat_participants p ON p.conversation_id = c.id
        WHERE p.user_id = ? AND p.is_active = 1 AND c.is_active = 1
        ORDER BY c.last_message_at DESC
    ");
    
    $stmt->execute([$userId, $userId, $userId, $userId]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'conversations' => $conversations]);
}

function getMessages(PDO $pdo, int $userId, int $conversationId, int $lastMessageId = 0): void {
    // Verificar que el usuario es participante
    $stmt = $pdo->prepare("
        SELECT id FROM chat_participants 
        WHERE conversation_id = ? AND user_id = ? AND is_active = 1
    ");
    $stmt->execute([$conversationId, $userId]);
    
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return;
    }
    
    // Obtener mensajes
    $query = "
        SELECT 
            m.id,
            m.user_id,
            m.message_text,
            m.message_type,
            m.is_edited,
            m.is_deleted,
            m.created_at,
            m.parent_message_id,
            u.username,
            u.full_name,
            (SELECT JSON_ARRAYAGG(
                JSON_OBJECT(
                    'id', a.id,
                    'file_name', a.file_name,
                    'file_original_name', a.file_original_name,
                    'file_type', a.file_type,
                    'file_size', a.file_size,
                    'mime_type', a.mime_type,
                    'thumbnail_path', a.thumbnail_path
                )
            ) FROM chat_attachments a WHERE a.message_id = m.id) as attachments,
            (SELECT JSON_ARRAYAGG(
                JSON_OBJECT(
                    'reaction', r.reaction,
                    'user_id', r.user_id,
                    'username', ru.username
                )
            ) FROM chat_reactions r 
            JOIN users ru ON ru.id = r.user_id 
            WHERE r.message_id = m.id) as reactions
        FROM chat_messages m
        JOIN users u ON u.id = m.user_id
        WHERE m.conversation_id = ? AND m.is_deleted = 0
    ";
    
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
    $stmt = $pdo->prepare("
        SELECT id FROM chat_participants 
        WHERE conversation_id = ? AND user_id = ? AND is_active = 1
    ");
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
    $stmt = $pdo->prepare("
        INSERT INTO chat_messages (conversation_id, user_id, message_text, parent_message_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$conversationId, $userId, $messageText, $parentMessageId]);
    $messageId = $pdo->lastInsertId();
    
    // Crear notificaciones para otros participantes
    $stmt = $pdo->prepare("
        INSERT INTO chat_notifications (user_id, conversation_id, message_id, notification_type)
        SELECT user_id, ?, ?, 'new_message'
        FROM chat_participants
        WHERE conversation_id = ? AND user_id != ? AND is_active = 1
    ");
    $stmt->execute([$conversationId, $messageId, $conversationId, $userId]);
    
    echo json_encode([
        'success' => true, 
        'message_id' => $messageId,
        'created_at' => date('Y-m-d H:i:s')
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
            
            $stmt = $pdo->prepare("
                SELECT c.id 
                FROM chat_conversations c
                JOIN chat_participants p1 ON p1.conversation_id = c.id
                JOIN chat_participants p2 ON p2.conversation_id = c.id
                WHERE c.type = 'direct' 
                AND p1.user_id = ? 
                AND p2.user_id = ?
                AND c.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$userId, $otherUserId]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                $pdo->rollBack();
                echo json_encode(['success' => true, 'conversation_id' => $existing['id'], 'existing' => true]);
                return;
            }
        }
        
        // Crear conversación
        $stmt = $pdo->prepare("
            INSERT INTO chat_conversations (type, name, created_by)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$type, $name ?: null, $userId]);
        $conversationId = $pdo->lastInsertId();
        
        // Agregar creador como admin
        $stmt = $pdo->prepare("
            INSERT INTO chat_participants (conversation_id, user_id, role)
            VALUES (?, ?, 'admin')
        ");
        $stmt->execute([$conversationId, $userId]);
        
        // Agregar otros participantes
        $stmt = $pdo->prepare("
            INSERT INTO chat_participants (conversation_id, user_id, role)
            VALUES (?, ?, 'member')
        ");
        
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
    $stmt = $pdo->prepare("
        UPDATE chat_participants
        SET last_read_at = NOW()
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversationId, $userId]);
    
    echo json_encode(['success' => true]);
}

function getUnreadCount(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM chat_notifications
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    
    echo json_encode(['success' => true, 'unread_count' => (int)$result['count']]);
}

function updateUserStatus(PDO $pdo, int $userId, string $status): void {
    if (!in_array($status, ['online', 'offline', 'away', 'busy'])) {
        $status = 'online';
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO chat_user_status (user_id, status, last_seen)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE status = ?, last_seen = NOW()
    ");
    $stmt->execute([$userId, $status, $status]);
    
    echo json_encode(['success' => true]);
}

function getOnlineUsers(PDO $pdo): void {
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.username,
            u.full_name,
            s.status,
            s.last_seen
        FROM users u
        LEFT JOIN chat_user_status s ON s.user_id = u.id
        WHERE s.status = 'online' 
        OR (s.last_seen > DATE_SUB(NOW(), INTERVAL " . CHAT_ONLINE_THRESHOLD . " SECOND))
        ORDER BY u.full_name
    ");
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'users' => $users]);
}

function searchUsers(PDO $pdo, string $query): void {
    $query = '%' . $query . '%';
    
    $stmt = $pdo->prepare("
        SELECT id, username, full_name, role
        FROM users
        WHERE (username LIKE ? OR full_name LIKE ?)
        AND is_active = 1
        ORDER BY full_name
        LIMIT 20
    ");
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
    
    $stmt = $pdo->prepare("
        UPDATE chat_messages
        SET message_text = ?, is_edited = 1, edited_at = NOW()
        WHERE id = ?
    ");
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
    
    $stmt = $pdo->prepare("
        UPDATE chat_messages
        SET is_deleted = 1, deleted_at = NOW(), message_text = '[Mensaje eliminado]'
        WHERE id = ?
    ");
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
        $stmt = $pdo->prepare("
            INSERT INTO chat_reactions (message_id, user_id, reaction)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE reaction = ?
        ");
        $stmt->execute([$messageId, $userId, $reaction, $reaction]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        // Si ya existe, eliminar la reacción
        $stmt = $pdo->prepare("
            DELETE FROM chat_reactions
            WHERE message_id = ? AND user_id = ? AND reaction = ?
        ");
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

function checkChatPermission(PDO $pdo, int $userId, string $permission): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT {$permission}, is_restricted, restricted_until
            FROM chat_permissions
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $perms = $stmt->fetch();
        
        if (!$perms) {
            return true; // Por defecto permitir
        }
        
        if ($perms['is_restricted']) {
            if ($perms['restricted_until'] && strtotime($perms['restricted_until']) > time()) {
                return false;
            }
        }
        
        return (bool)$perms[$permission];
    } catch (Exception $e) {
        // Si la tabla no existe o hay error, permitir por defecto
        error_log("Chat permission check error: " . $e->getMessage());
        return true;
    }
}
