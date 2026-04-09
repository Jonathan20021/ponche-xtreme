<?php
require_once 'db.php';

// Create ghl_integrations table WITHOUT foreign key
$sql = "CREATE TABLE IF NOT EXISTS ghl_integrations (
    integration_id INT AUTO_INCREMENT PRIMARY KEY,
    integration_name VARCHAR(255) NOT NULL,
    api_key LONGTEXT NOT NULL,
    location_id VARCHAR(255) NOT NULL UNIQUE,
    timezone VARCHAR(100) DEFAULT 'UTC',
    page_size INT DEFAULT 50,
    max_pages INT DEFAULT 50,
    interaction_page_size INT DEFAULT 100,
    interaction_max_pages INT DEFAULT 200,
    is_default BOOLEAN DEFAULT FALSE,
    user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_location_id (location_id),
    INDEX idx_is_default (is_default),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

try {
    $pdo->exec($sql);
    echo "✓ Table ghl_integrations created successfully\n";
} catch (Exception $e) {
    echo "✗ Error creating table: " . $e->getMessage() . "\n";
    exit(1);
}

// Migrate existing config from system_settings to ghl_integrations
$apiKey = trim((string) getSystemSetting($pdo, 'voice_ai_api_key', ''));
$locationId = trim((string) getSystemSetting($pdo, 'voice_ai_location_id', ''));

if ($apiKey && $locationId) {
    // Check if integration already exists
    $existing = $pdo->prepare('SELECT * FROM ghl_integrations WHERE location_id = ?');
    $existing->execute([$locationId]);
    
    if ($existing->rowCount() === 0) {
        // Insert the migration
        $timezone = trim((string) getSystemSetting($pdo, 'voice_ai_timezone', 'UTC'));
        $pageSize = (int) getSystemSetting($pdo, 'voice_ai_page_size', 50);
        $maxPages = (int) getSystemSetting($pdo, 'voice_ai_max_pages', 50);
        $interactionPageSize = (int) getSystemSetting($pdo, 'voice_ai_interaction_page_size', 100);
        $interactionMaxPages = (int) getSystemSetting($pdo, 'voice_ai_interaction_max_pages', 200);
        
        $stmt = $pdo->prepare("
            INSERT INTO ghl_integrations 
            (integration_name, api_key, location_id, timezone, page_size, max_pages, interaction_page_size, interaction_max_pages, is_default, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?)
        ");
        
        $userId = $_SESSION['user_id'] ?? 1;
        $stmt->execute([
            'Integración Principal',
            $apiKey,
            $locationId,
            $timezone,
            $pageSize,
            $maxPages,
            $interactionPageSize,
            $interactionMaxPages,
            $userId
        ]);
        
        echo "✓ Migrated existing configuration to ghl_integrations\n";
        echo "  - Location ID: $locationId\n";
        echo "  - Integration: Integración Principal\n";
    } else {
        echo "ℹ Integration already exists for this location_id\n";
    }
} else {
    echo "⚠ No existing configuration found in system_settings\n";
}

echo "\n=== NEW INTEGRATIONS TABLE ===\n";
$integrations = $pdo->query('SELECT integration_id, integration_name, location_id, is_default FROM ghl_integrations')->fetchAll(PDO::FETCH_ASSOC);
foreach ($integrations as $int) {
    echo '✓ ' . $int['integration_name'] . ' (Location: ' . $int['location_id'] . ') [' . ($int['is_default'] ? 'DEFAULT' : 'secondary') . "]\n";
}

echo "\n✓ Migration complete!\n";
?>
