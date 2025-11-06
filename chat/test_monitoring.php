<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h1>Test Monitoring API</h1>";

echo "<h2>Session Info:</h2>";
echo "User ID: " . ($_SESSION['user_id'] ?? 'NOT SET') . "<br>";
echo "Username: " . ($_SESSION['username'] ?? 'NOT SET') . "<br>";

echo "<h2>Permission Check:</h2>";
try {
    $hasPermission = userHasPermission('chat_admin');
    echo "Has chat_admin permission: " . ($hasPermission ? 'YES' : 'NO') . "<br>";
} catch (Exception $e) {
    echo "Error checking permission: " . $e->getMessage() . "<br>";
}

echo "<h2>Database Connection:</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM chat_conversations");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total conversations in DB: " . $result['count'] . "<br>";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "<br>";
}

echo "<h2>Test Query:</h2>";
try {
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
                SELECT m.content
                FROM chat_messages m
                WHERE m.conversation_id = c.id
                ORDER BY m.created_at DESC
                LIMIT 1
            ) as last_message,
            (
                SELECT COUNT(*)
                FROM chat_messages m
                WHERE m.conversation_id = c.id
                AND m.is_read = 0
            ) as unread_count
        FROM chat_conversations c
        WHERE c.is_active = 1
        ORDER BY c.last_message_at IS NULL, c.last_message_at DESC
        LIMIT 10
    ");
    
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Query successful! Found " . count($conversations) . " conversations<br>";
    echo "<pre>" . print_r($conversations, true) . "</pre>";
} catch (PDOException $e) {
    echo "Query error: " . $e->getMessage() . "<br>";
    echo "SQL State: " . $e->getCode() . "<br>";
}
