<?php
/**
 * API para Monitoreo de Conversaciones de Chat
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Verificar permisos de administrador
if (!isset($_SESSION['user_id']) || !userHasPermission('chat_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_conversations':
            getConversations();
            break;
            
        case 'get_messages':
            getMessages();
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Acción inválida']);
    }
} catch (Exception $e) {
    error_log('Monitoring API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Throwable $e) {
    error_log('Monitoring API Fatal Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error fatal del servidor',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

function getConversations() {
    global $pdo;
    
    $stmt = $pdo->query("
        SELECT 
            c.id,
            c.type,
            c.name,
            c.created_by,
            c.created_at,
            c.is_active,
            c.last_message_at,
            (
                SELECT COUNT(DISTINCT cp2.user_id)
                FROM chat_participants cp2
                WHERE cp2.conversation_id = c.id
            ) as participant_count,
            (
                SELECT m.message_text
                FROM chat_messages m
                WHERE m.conversation_id = c.id
                ORDER BY m.created_at DESC
                LIMIT 1
            ) as last_message
        FROM chat_conversations c
        WHERE c.is_active = 1
        ORDER BY c.last_message_at IS NULL, c.last_message_at DESC
    ");
    
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear nombres de conversaciones
    foreach ($conversations as &$conv) {
        $conv['is_group'] = ($conv['type'] === 'group' || $conv['type'] === 'channel') ? 1 : 0;
        
        if ($conv['type'] === 'group' || $conv['type'] === 'channel') {
            // Para grupos, usar el nombre de la conversación
            if (empty($conv['name'])) {
                $conv['name'] = 'Grupo sin nombre';
            }
        } else {
            // Para conversaciones 1:1, obtener el nombre de los participantes
            if (empty($conv['name'])) {
                $participantsStmt = $pdo->prepare("
                    SELECT u.full_name
                    FROM chat_participants cp
                    JOIN users u ON u.id = cp.user_id
                    WHERE cp.conversation_id = ?
                    LIMIT 2
                ");
                $participantsStmt->execute([$conv['id']]);
                $participants = $participantsStmt->fetchAll(PDO::FETCH_COLUMN);
                $conv['name'] = implode(' y ', $participants);
            }
        }
        
        // Formatear hora del último mensaje
        $lastTime = $conv['last_message_at'];
        if ($lastTime) {
            $time = strtotime($lastTime);
            $now = time();
            $diff = $now - $time;
            
            if ($diff < 60) {
                $conv['last_message_time'] = 'Hace ' . $diff . 's';
            } elseif ($diff < 3600) {
                $conv['last_message_time'] = 'Hace ' . floor($diff / 60) . 'm';
            } elseif ($diff < 86400) {
                $conv['last_message_time'] = 'Hace ' . floor($diff / 3600) . 'h';
            } else {
                $conv['last_message_time'] = date('d/m/Y H:i', $time);
            }
        } else {
            $conv['last_message_time'] = 'Sin actividad';
        }
        
        // Truncar último mensaje
        if ($conv['last_message']) {
            $conv['last_message'] = mb_strlen($conv['last_message']) > 50 
                ? mb_substr($conv['last_message'], 0, 50) . '...' 
                : $conv['last_message'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'conversations' => $conversations
    ]);
}

function getMessages() {
    global $pdo;
    
    try {
        $conversationId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
        
        if ($conversationId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID de conversación inválido']);
            return;
        }
    
    // Obtener información de la conversación
    $convStmt = $pdo->prepare("
        SELECT 
            c.id,
            c.type,
            c.name,
            (
                SELECT COUNT(DISTINCT cp.user_id)
                FROM chat_participants cp
                WHERE cp.conversation_id = c.id
            ) as participant_count
        FROM chat_conversations c
        WHERE c.id = ?
    ");
    $convStmt->execute([$conversationId]);
    $conversation = $convStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conversation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Conversación no encontrada']);
        return;
    }
    
    // Formatear nombre
    if ($conversation['type'] === 'group' || $conversation['type'] === 'channel') {
        if (empty($conversation['name'])) {
            $conversation['name'] = 'Grupo sin nombre';
        }
    } else {
        if (empty($conversation['name'])) {
            $participantsStmt = $pdo->prepare("
                SELECT u.full_name
                FROM chat_participants cp
                JOIN users u ON u.id = cp.user_id
                WHERE cp.conversation_id = ?
                LIMIT 2
            ");
            $participantsStmt->execute([$conversationId]);
            $participants = $participantsStmt->fetchAll(PDO::FETCH_COLUMN);
            $conversation['name'] = implode(' y ', $participants);
        }
    }
    
    // Obtener mensajes
    $messagesStmt = $pdo->prepare("
        SELECT 
            m.id,
            m.user_id as sender_id,
            u.full_name as sender_name,
            m.message_text as content,
            m.message_type,
            m.created_at,
            m.is_edited,
            m.is_deleted,
            m.edited_at,
            a.id as attachment_id,
            a.file_original_name as attachment_name,
            a.file_size as attachment_size,
            a.mime_type as attachment_type
        FROM chat_messages m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN chat_attachments a ON a.message_id = m.id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC
    ");
    $messagesStmt->execute([$conversationId]);
    $messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear mensajes
    foreach ($messages as &$msg) {
        $msg['has_attachment'] = !empty($msg['attachment_id']);
        $msg['is_read'] = 1; // No hay campo is_read en esta estructura, asumimos leído
        $msg['sent_at'] = date('d/m/Y H:i:s', strtotime($msg['created_at']));
    }
    
    echo json_encode([
        'success' => true,
        'conversation' => $conversation,
        'messages' => $messages
    ]);
    
    } catch (PDOException $e) {
        error_log('getMessages PDO Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error de base de datos',
            'message' => $e->getMessage(),
            'code' => $e->getCode()
        ]);
    } catch (Exception $e) {
        error_log('getMessages Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error del servidor',
            'message' => $e->getMessage()
        ]);
    }
}
