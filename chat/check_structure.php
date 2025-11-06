<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h1>Database Structure Check</h1>";

// Check chat_conversations structure
echo "<h2>chat_conversations columns:</h2>";
try {
    $stmt = $pdo->query("DESCRIBE chat_conversations");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($columns, true) . "</pre>";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

// Check chat_messages structure
echo "<h2>chat_messages columns:</h2>";
try {
    $stmt = $pdo->query("DESCRIBE chat_messages");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($columns, true) . "</pre>";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

// Check chat_attachments structure
echo "<h2>chat_attachments columns:</h2>";
try {
    $stmt = $pdo->query("DESCRIBE chat_attachments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($columns, true) . "</pre>";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

// Check if we need to add missing columns
echo "<h2>Missing columns that need to be added:</h2>";
$requiredColumns = ['is_group', 'group_name'];
foreach ($requiredColumns as $col) {
    $stmt = $pdo->query("SHOW COLUMNS FROM chat_conversations LIKE '$col'");
    if ($stmt->rowCount() === 0) {
        echo "❌ Missing column: <strong>$col</strong><br>";
    } else {
        echo "✅ Column exists: $col<br>";
    }
}
