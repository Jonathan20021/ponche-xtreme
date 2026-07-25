<?php
/**
 * Instalador idempotente de:
 *   1. Centro de notificaciones del sistema (campana del header).
 *   2. Revisión humana de la disposición sugerida por la IA en Reclutamiento.
 *   3. Ajustes de alertas de stock del inventario.
 *
 * Corre por navegador o por CLI. Se puede ejecutar varias veces sin romper nada.
 * El servidor es MySQL 5.7, que NO acepta `IF NOT EXISTS` en ALTER TABLE: por eso
 * cada paso pregunta primero a information_schema.
 */

require_once __DIR__ . '/db.php';

$cli = (PHP_SAPI === 'cli');
$nl  = $cli ? "\n" : "<br>\n";
if (!$cli) {
    echo "<pre style='font-family:Consolas,monospace;background:#0f172a;color:#cbd5e1;padding:16px;'>";
}

$schema = $pdo->query("SELECT DATABASE()")->fetchColumn();
echo "=== Migracion: Notificaciones + Revision de disposicion IA ==={$nl}DB: $schema{$nl}{$nl}";

$ok = 0; $skipped = 0; $errors = 0;

function step(string $label, callable $fn, &$ok, &$skipped, &$errors, string $nl): void
{
    echo "[*] $label{$nl}";
    try {
        $result = $fn();
        if ($result === 'SKIP') {
            echo "    SKIP{$nl}";
            $skipped++;
        } else {
            echo "    OK{$nl}";
            $ok++;
        }
    } catch (Throwable $e) {
        echo "    ERROR: " . $e->getMessage() . "{$nl}";
        $errors++;
    }
}

function nmColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function nmIndexExists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $indexName]);
    return (int) $stmt->fetchColumn() > 0;
}

// ---------------------------------------------------------------------------
// 1. Tablas del centro de notificaciones
// ---------------------------------------------------------------------------
step('Tabla system_notifications', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_notifications` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `notif_type` VARCHAR(60) NOT NULL,
          `severity` ENUM('LOW','NORMAL','HIGH','CRITICAL') NOT NULL DEFAULT 'NORMAL',
          `title` VARCHAR(255) NOT NULL,
          `message` TEXT NOT NULL,
          `url` VARCHAR(255) DEFAULT NULL,
          `payload_json` LONGTEXT DEFAULT NULL,
          `target_user_id` INT UNSIGNED DEFAULT NULL,
          `target_roles` VARCHAR(255) DEFAULT NULL,
          `target_permission` VARCHAR(100) DEFAULT NULL,
          `dedupe_key` VARCHAR(190) DEFAULT NULL,
          `requires_action` TINYINT(1) NOT NULL DEFAULT 0,
          `resolved_at` DATETIME DEFAULT NULL,
          `resolved_by` INT UNSIGNED DEFAULT NULL,
          `expires_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_system_notifications_dedupe` (`dedupe_key`),
          KEY `idx_system_notifications_created` (`created_at`),
          KEY `idx_system_notifications_type` (`notif_type`),
          KEY `idx_system_notifications_user` (`target_user_id`),
          KEY `idx_system_notifications_pending` (`resolved_at`, `expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

step('Tabla system_notification_reads', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_notification_reads` (
          `notification_id` BIGINT UNSIGNED NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`notification_id`, `user_id`),
          KEY `idx_notification_reads_user` (`user_id`),
          CONSTRAINT `fk_notification_reads_notif`
            FOREIGN KEY (`notification_id`) REFERENCES `system_notifications` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 2. Columnas de la disposicion sugerida por la IA
// ---------------------------------------------------------------------------
$aiColumns = [
    'ai_proposed_status'     => "VARCHAR(30) DEFAULT NULL COMMENT 'disposicion que sugiere la IA' AFTER `ai_recommendation`",
    'ai_proposal_state'      => "ENUM('PENDING','APPROVED','REJECTED','AUTO_APPLIED') DEFAULT NULL COMMENT 'estado de la revision humana' AFTER `ai_proposed_status`",
    'ai_proposal_reason'     => "TEXT DEFAULT NULL COMMENT 'justificacion de la IA' AFTER `ai_proposal_state`",
    'ai_proposed_at'         => "DATETIME DEFAULT NULL AFTER `ai_proposal_reason`",
    'ai_proposal_decided_by' => "INT UNSIGNED DEFAULT NULL AFTER `ai_proposed_at`",
    'ai_proposal_decided_at' => "DATETIME DEFAULT NULL AFTER `ai_proposal_decided_by`",
];

foreach ($aiColumns as $column => $definition) {
    step("job_applications.$column", function () use ($pdo, $column, $definition) {
        if (nmColumnExists($pdo, 'job_applications', $column)) {
            return 'SKIP';
        }
        $pdo->exec("ALTER TABLE `job_applications` ADD COLUMN `$column` $definition");
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

step('Indice idx_job_applications_ai_proposal', function () use ($pdo) {
    if (nmIndexExists($pdo, 'job_applications', 'idx_job_applications_ai_proposal')) {
        return 'SKIP';
    }
    $pdo->exec("ALTER TABLE `job_applications` ADD INDEX `idx_job_applications_ai_proposal` (`ai_proposal_state`)");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 3. Ajustes configurables (todo editable luego desde settings.php)
// ---------------------------------------------------------------------------
$settings = [
    // Campana
    'notifications_enabled'                  => ['1', 'notifications'],
    'notifications_poll_seconds'             => ['90', 'notifications'],
    'notifications_retention_days'           => ['45', 'notifications'],
    // Reclutamiento: revision de la disposicion sugerida por la IA
    'recruitment_ai_require_approval'        => ['1', 'recruitment_ai'],
    'recruitment_ai_notify_roles'            => ['HR,Admin', 'recruitment_ai'],
    'recruitment_ai_notify_user_ids'         => ['', 'recruitment_ai'],
    'recruitment_ai_notify_email'            => ['0', 'recruitment_ai'],
    'recruitment_ai_notify_email_recipients' => ['', 'recruitment_ai'],
    // Inventario: alertas de stock
    'inventory_stock_alerts_enabled'         => ['1', 'inventory'],
    'inventory_stock_alert_roles'            => ['HR,Admin,IT', 'inventory'],
    'inventory_stock_alert_user_ids'         => ['', 'inventory'],
    'inventory_stock_alert_near_pct'         => ['20', 'inventory'],
    'inventory_stock_alert_digest'           => ['1', 'inventory'],
    'inventory_stock_alert_cooldown_hours'   => ['24', 'inventory'],
    'inventory_stock_alert_email'            => ['0', 'inventory'],
    'inventory_stock_alert_email_recipients' => ['', 'inventory'],
];

foreach ($settings as $key => [$value, $category]) {
    step("setting $key", function () use ($pdo, $key, $value, $category) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $check->execute([$key]);
        if ((int) $check->fetchColumn() > 0) {
            return 'SKIP';
        }
        $ins = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, category) VALUES (?, ?, 'string', ?)");
        $ins->execute([$key, $value, $category]);
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

echo "{$nl}=== Resumen ==={$nl}";
echo "OK: $ok   SKIP: $skipped   ERRORES: $errors{$nl}";
if ($errors === 0) {
    echo "{$nl}Listo. La campana de notificaciones ya esta activa y la IA de{$nl}";
    echo "Reclutamiento pasa a proponer disposiciones en vez de aplicarlas sola.{$nl}";
    echo "Todo se ajusta desde settings.php (pestana Notificaciones).{$nl}";
}

if (!$cli) {
    echo "</pre>";
}
