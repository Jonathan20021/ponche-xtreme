<?php
require __DIR__ . '/../db.php';

$tables = ['employee_schedules','schedule_templates','schedule_config','employees','users'];
foreach ($tables as $t) {
    $stmt = $pdo->query("SHOW TABLES LIKE '{$t}'");
    $exists = $stmt->fetchColumn() ? 'YES' : 'NO';
    echo "TABLE {$t}: {$exists}\n";
    if ($exists === 'YES') {
        $cols = $pdo->query("SHOW COLUMNS FROM {$t}")->fetchAll(PDO::FETCH_ASSOC);
        $fields = [];
        foreach ($cols as $c) {
            $fields[] = $c['Field'];
        }
        echo "COLUMNS {$t}: " . implode(', ', $fields) . "\n\n";
    }
}
