<?php
/**
 * Instalador idempotente de los ajustes de Nómina.
 *
 * Crea:
 *   1. attendance_audit        -> historial de modificaciones al ponche, con el
 *                                 valor ORIGINAL y el MODIFICADO por estado y el
 *                                 usuario que lo cambió (equivalente a lo que ya
 *                                 existe para Vicidial).
 *   2. restaurants             -> restaurantes de Delivery
 *   3. employee_restaurants    -> a qué restaurante(s) atiende cada colaborador,
 *                                 con % de reparto para el sistema contable.
 *   4. Ajustes de los dos reportes nuevos (horas extra semanal y +8h diario).
 *
 * MySQL 5.7: nada de `IF NOT EXISTS` en ALTER; se consulta information_schema.
 */

require_once __DIR__ . '/db.php';

$cli = (PHP_SAPI === 'cli');
$nl  = $cli ? "\n" : "<br>\n";
if (!$cli) {
    echo "<pre style='font-family:Consolas,monospace;background:#0f172a;color:#cbd5e1;padding:16px;'>";
}

echo "=== Migracion: Modulo de Nomina ==={$nl}DB: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "{$nl}{$nl}";

$ok = 0; $skipped = 0; $errors = 0;

function payStep(string $label, callable $fn, &$ok, &$skipped, &$errors, string $nl): void
{
    echo "[*] $label{$nl}";
    try {
        $r = $fn();
        if ($r === 'SKIP') { echo "    SKIP{$nl}"; $skipped++; }
        else { echo "    OK" . (is_string($r) && $r !== 'OK' ? " ($r)" : '') . "{$nl}"; $ok++; }
    } catch (Throwable $e) {
        echo "    ERROR: " . $e->getMessage() . "{$nl}";
        $errors++;
    }
}

function payColumnExists(PDO $pdo, string $table, string $column): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $column]);
    return (int) $s->fetchColumn() > 0;
}

// ---------------------------------------------------------------------------
// 1. Historial de modificaciones al ponche
// ---------------------------------------------------------------------------
payStep('Tabla attendance_audit (historial de cambios del ponche)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `attendance_audit` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `attendance_id` INT DEFAULT NULL COMMENT 'punch afectado; NULL si se borro',
          `user_id` INT UNSIGNED NOT NULL COMMENT 'colaborador dueño del ponche',
          `work_date` DATE NOT NULL,
          `action` VARCHAR(20) NOT NULL COMMENT 'CREATE, UPDATE, DELETE',

          `old_type` VARCHAR(50) DEFAULT NULL,
          `new_type` VARCHAR(50) DEFAULT NULL,
          `old_timestamp` DATETIME DEFAULT NULL,
          `new_timestamp` DATETIME DEFAULT NULL,

          -- Totales del DIA antes y despues del cambio, calculados con la misma
          -- logica que paga la nomina. Es lo que permite mostrar
          -- \"original vs modificado\" igual que en Vicidial.
          `old_work_seconds` INT DEFAULT NULL,
          `new_work_seconds` INT DEFAULT NULL,

          -- Duracion por estado antes y despues, en JSON {SLUG: segundos}.
          -- De aqui sale el ejemplo del cliente: 2 min en bano registrado en el
          -- ponche vs 5 min tras la correccion de mrosario.
          `old_durations_json` TEXT DEFAULT NULL,
          `new_durations_json` TEXT DEFAULT NULL,

          `reason` VARCHAR(255) DEFAULT NULL,
          `source` VARCHAR(30) DEFAULT NULL COMMENT 'donde se hizo el cambio',
          `performed_by` INT UNSIGNED DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

          PRIMARY KEY (`id`),
          KEY `idx_att_audit_user_date` (`user_id`, `work_date`),
          KEY `idx_att_audit_date` (`work_date`),
          KEY `idx_att_audit_attendance` (`attendance_id`),
          KEY `idx_att_audit_by` (`performed_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 2. Restaurantes de Delivery
// ---------------------------------------------------------------------------
payStep('Tabla restaurants (clientes de Delivery)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `restaurants` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `name` VARCHAR(150) NOT NULL,
          `code` VARCHAR(40) DEFAULT NULL COMMENT 'codigo contable',
          `campaign_id` INT UNSIGNED DEFAULT NULL COMMENT 'la campana Delivery a la que pertenece',
          `color` VARCHAR(20) DEFAULT '#6366f1',
          `contact_name` VARCHAR(150) DEFAULT NULL,
          `notes` VARCHAR(255) DEFAULT NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_restaurant_name` (`name`),
          KEY `idx_restaurant_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

payStep('Tabla employee_restaurants (reparto de costo por restaurante)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_restaurants` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `employee_id` INT UNSIGNED NOT NULL,
          `restaurant_id` INT UNSIGNED NOT NULL,
          -- Porcentaje del costo del colaborador que se le carga a este
          -- restaurante. La suma de los vigentes deberia dar 100.
          `allocation_pct` DECIMAL(5,2) NOT NULL DEFAULT 100.00,
          `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
          `start_date` DATE DEFAULT NULL,
          `end_date` DATE DEFAULT NULL COMMENT 'NULL = vigente',
          `notes` VARCHAR(255) DEFAULT NULL,
          `assigned_by` INT UNSIGNED DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_emp_rest_employee` (`employee_id`, `end_date`),
          KEY `idx_emp_rest_restaurant` (`restaurant_id`),
          CONSTRAINT `fk_emp_rest_employee` FOREIGN KEY (`employee_id`)
            REFERENCES `employees` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_emp_rest_restaurant` FOREIGN KEY (`restaurant_id`)
            REFERENCES `restaurants` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 3. salary_history: faltan columnas para dejar constancia de QUE cambio
// ---------------------------------------------------------------------------
$salaryColumns = [
    'user_id'        => "INT UNSIGNED DEFAULT NULL COMMENT 'usuario al que pertenece el cambio' AFTER `employee_id`",
    'currency'       => "VARCHAR(5) DEFAULT 'DOP' AFTER `new_salary`",
    'salary_type'    => "VARCHAR(20) DEFAULT 'MONTHLY' COMMENT 'MONTHLY, HOURLY, DAILY' AFTER `currency`",
    'old_hourly_rate'=> "DECIMAL(12,2) DEFAULT NULL AFTER `salary_type`",
    'new_hourly_rate'=> "DECIMAL(12,2) DEFAULT NULL AFTER `old_hourly_rate`",
];
foreach ($salaryColumns as $col => $def) {
    payStep("salary_history.$col", function () use ($pdo, $col, $def) {
        if (payColumnExists($pdo, 'salary_history', $col)) {
            return 'SKIP';
        }
        $pdo->exec("ALTER TABLE `salary_history` ADD COLUMN `$col` $def");
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

payStep('Indice salary_history por empleado', function () use ($pdo) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salary_history'
                          AND INDEX_NAME = 'idx_salary_history_employee'");
    $s->execute();
    if ((int) $s->fetchColumn() > 0) {
        return 'SKIP';
    }
    $pdo->exec("ALTER TABLE `salary_history` ADD INDEX `idx_salary_history_employee` (`employee_id`, `effective_date`)");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 4. Ajustes de los reportes nuevos
// ---------------------------------------------------------------------------
$settings = [
    // Reporte semanal de horas extra
    'overtime_report_enabled'          => ['1', 'reports'],
    'overtime_report_time'             => ['08:00', 'reports'],
    'overtime_report_weekday'          => ['1', 'reports'],   // 1 = lunes
    'overtime_report_recipients'       => ['', 'reports'],
    'overtime_report_min_hours'        => ['0', 'reports'],
    'overtime_report_exclude_weekends' => ['0', 'reports'],

    // Reporte diario de quienes pasaron de 8 horas
    'over8h_report_enabled'            => ['1', 'reports'],
    'over8h_report_time'               => ['08:15', 'reports'],
    'over8h_report_threshold_hours'    => ['8', 'reports'],
    'over8h_report_recipients'         => ['', 'reports'],
    'over8h_report_exclude_weekends'   => ['1', 'reports'],
];

foreach ($settings as $key => [$value, $category]) {
    payStep("setting $key", function () use ($pdo, $key, $value, $category) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $c->execute([$key]);
        if ((int) $c->fetchColumn() > 0) {
            return 'SKIP';
        }
        $i = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, category) VALUES (?, ?, 'string', ?)");
        $i->execute([$key, $value, $category]);
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// Los destinatarios arrancan con los del reporte de nómina, que ya está configurado.
payStep('Heredar destinatarios del reporte de nomina', function () use ($pdo) {
    $src = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'payroll_report_recipients'")->fetchColumn();
    if (!$src) {
        return 'SKIP';
    }
    $n = 0;
    foreach (['overtime_report_recipients', 'over8h_report_recipients'] as $k) {
        $cur = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $cur->execute([$k]);
        if (trim((string) $cur->fetchColumn()) === '') {
            $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?")->execute([$src, $k]);
            $n++;
        }
    }
    return $n > 0 ? "$n reporte(s)" : 'SKIP';
}, $ok, $skipped, $errors, $nl);

echo "{$nl}=== Resumen ==={$nl}OK: $ok   SKIP: $skipped   ERRORES: $errors{$nl}";

if (!$cli) {
    echo "</pre>";
}
