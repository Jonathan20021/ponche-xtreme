<?php
/**
 * Instalador idempotente del PROCEDIMIENTO DE SEGURIDAD DE HORAS Y NOMINA.
 *
 * Requerimiento aprobado por Hugo (13-ago-2026). Regla de oro:
 *   "nadie puede modificar silenciosamente una hora que genere dinero".
 *
 * Todo lo que crea es ADITIVO: no toca `attendance`, no toca el motor de calculo
 * de nomina y no recalcula nada ya emitido.
 *
 *   1. attendance_original    -> foto INMUTABLE del punch tal como se marco.
 *                                Se llena sola con un trigger AFTER INSERT, asi
 *                                cubre TODAS las vias (punch.php, manual, APIs).
 *   2. attendance_voided      -> archivo de punches eliminados. El borrado deja
 *                                de destruir evidencia.
 *   3. timesheet_day_status   -> etapa por (colaborador, dia): abierto, en
 *                                revision, ajustado, cerrado, bloqueado.
 *   4. timesheet_comments     -> comentarios NO eliminables.
 *   5. timesheet_exceptions   -> bandeja de excepciones con dueño y severidad.
 *   6. timesheet_stage_events -> bitacora de etapas (cierres, reaperturas,
 *                                consolidacion, auditoria, aprobacion).
 *   7. Columnas nuevas en attendance_audit  (etapa, impacto RD$, codigo usado).
 *   8. Columnas nuevas en payroll_periods   (consolidado / auditado / bloqueado).
 *   9. Triggers de inmutabilidad: la propia base de datos rechaza UPDATE y
 *      DELETE sobre las bitacoras.
 *  10. Ajustes en system_settings y permisos de las secciones nuevas.
 *
 * MySQL 5.7: nada de `IF NOT EXISTS` en ALTER; se consulta information_schema.
 *
 * Uso:   php run_timesheet_control_migration.php
 *        (o abrirlo en el navegador con sesion de Admin)
 */

require_once __DIR__ . '/db.php';

$cli = (PHP_SAPI === 'cli');
$nl  = $cli ? "\n" : "<br>\n";
if (!$cli) {
    echo "<pre style='font-family:Consolas,monospace;background:#0f172a;color:#cbd5e1;padding:16px;'>";
}

echo "=== Migracion: Control de Horas y Nomina ==={$nl}";
echo "DB: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "{$nl}{$nl}";

$ok = 0; $skipped = 0; $errors = 0; $warnings = 0;

function tsStep(string $label, callable $fn, &$ok, &$skipped, &$errors, string $nl): void
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

/** Igual que tsStep pero un fallo NO es fatal: se avisa y se sigue. */
function tsSoftStep(string $label, callable $fn, &$ok, &$skipped, &$warnings, string $nl): void
{
    echo "[*] $label{$nl}";
    try {
        $r = $fn();
        if ($r === 'SKIP') { echo "    SKIP{$nl}"; $skipped++; }
        else { echo "    OK" . (is_string($r) && $r !== 'OK' ? " ($r)" : '') . "{$nl}"; $ok++; }
    } catch (Throwable $e) {
        echo "    AVISO: " . $e->getMessage() . "{$nl}";
        echo "    -> El sistema sigue funcionando: lib/timesheet_control.php cubre este caso por codigo.{$nl}";
        $warnings++;
    }
}

function tsColumnExists(PDO $pdo, string $table, string $column): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $column]);
    return (int) $s->fetchColumn() > 0;
}

function tsTableExists(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$table]);
    return (int) $s->fetchColumn() > 0;
}

/**
 * Primer dia del periodo de nomina que cubre hoy. Si no hay ninguno abierto, se
 * usa hoy: mas vale arrancar limpio que cerrar dias que alguien todavia revisa.
 */
function tsDefaultStartDate(PDO $pdo): string
{
    try {
        $s = $pdo->prepare("
            SELECT start_date FROM payroll_periods
            WHERE ? BETWEEN start_date AND end_date
            ORDER BY start_date DESC LIMIT 1
        ");
        $s->execute([date('Y-m-d')]);
        $d = $s->fetchColumn();
        if ($d && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $d)) {
            return substr((string) $d, 0, 10);
        }
    } catch (Throwable $e) {
        // sin periodos: se usa hoy
    }
    return date('Y-m-d');
}

function tsTriggerExists(PDO $pdo, string $trigger): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS
                        WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?");
    $s->execute([$trigger]);
    return (int) $s->fetchColumn() > 0;
}

// ---------------------------------------------------------------------------
// 1. El ponche ORIGINAL, intocable
// ---------------------------------------------------------------------------
tsStep('Tabla attendance_original (foto inmutable del punch)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `attendance_original` (
          `attendance_id` INT NOT NULL COMMENT 'mismo id del punch en attendance',
          `user_id` INT UNSIGNED NOT NULL,
          `work_date` DATE NOT NULL,
          `original_type` VARCHAR(50) NOT NULL COMMENT 'tipo tal como se marco',
          `original_timestamp` DATETIME NOT NULL COMMENT 'hora tal como se marco',
          `captured_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`attendance_id`),
          KEY `idx_att_orig_user_date` (`user_id`, `work_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='Ponche original. Ninguna edicion posterior lo toca.'
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

tsStep('Backfill de attendance_original con los punches existentes', function () use ($pdo) {
    $n = $pdo->exec("
        INSERT IGNORE INTO attendance_original
            (attendance_id, user_id, work_date, original_type, original_timestamp, captured_at)
        SELECT a.id, a.user_id, DATE(a.timestamp), a.type, a.timestamp, NOW()
        FROM attendance a
        LEFT JOIN attendance_original o ON o.attendance_id = a.id
        WHERE o.attendance_id IS NULL
    ");
    return $n > 0 ? "$n punch(es)" : 'SKIP';
}, $ok, $skipped, $errors, $nl);

// El trigger es lo que hace que NINGUNA via de marcacion se escape: punch.php,
// register_attendance.php, el registro manual y los dos API del monitor pasan
// todos por el mismo INSERT.
tsSoftStep('Trigger attendance -> attendance_original (todas las vias)', function () use ($pdo) {
    if (tsTriggerExists($pdo, 'trg_attendance_capture_original')) {
        return 'SKIP';
    }
    $pdo->exec("
        CREATE TRIGGER `trg_attendance_capture_original`
        AFTER INSERT ON `attendance`
        FOR EACH ROW
        INSERT IGNORE INTO `attendance_original`
            (attendance_id, user_id, work_date, original_type, original_timestamp)
        VALUES (NEW.id, NEW.user_id, DATE(NEW.timestamp), NEW.type, NEW.timestamp)
    ");
    return 'OK';
}, $ok, $skipped, $warnings, $nl);

// ---------------------------------------------------------------------------
// 2. Los punches eliminados dejan de desaparecer
// ---------------------------------------------------------------------------
tsStep('Tabla attendance_voided (archivo de punches eliminados)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `attendance_voided` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `attendance_id` INT NOT NULL COMMENT 'id que tenia el punch',
          `user_id` INT UNSIGNED NOT NULL,
          `work_date` DATE NOT NULL,
          `type` VARCHAR(50) NOT NULL,
          `timestamp` DATETIME NOT NULL,
          `ip_address` VARCHAR(64) DEFAULT NULL,
          `row_json` TEXT DEFAULT NULL COMMENT 'fila completa tal como estaba',
          `reason` VARCHAR(255) NOT NULL COMMENT 'motivo obligatorio',
          `source` VARCHAR(30) DEFAULT NULL,
          `voided_by` INT UNSIGNED DEFAULT NULL,
          `authorization_code_id` INT DEFAULT NULL,
          `voided_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_att_void_user_date` (`user_id`, `work_date`),
          KEY `idx_att_void_att` (`attendance_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='Punches eliminados. Se conservan con motivo y autor.'
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 3. Etapa por dia y colaborador
// ---------------------------------------------------------------------------
tsStep('Tabla timesheet_day_status (etapas del dia)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `timesheet_day_status` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` INT UNSIGNED NOT NULL,
          `work_date` DATE NOT NULL,
          `status` ENUM('OPEN','IN_REVIEW','ADJUSTED','CLOSED','LOCKED') NOT NULL DEFAULT 'OPEN',
          `work_seconds` INT DEFAULT NULL COMMENT 'horas pagables al momento del cierre',
          `amount_dop` DECIMAL(12,2) DEFAULT NULL COMMENT 'valor estimado del dia al cerrar',
          `adjustments_count` INT NOT NULL DEFAULT 0,
          `closed_by` INT UNSIGNED DEFAULT NULL,
          `closed_at` DATETIME DEFAULT NULL,
          `reopened_by` INT UNSIGNED DEFAULT NULL,
          `reopened_at` DATETIME DEFAULT NULL,
          `reopen_reason` VARCHAR(255) DEFAULT NULL,
          `reopen_code_id` INT DEFAULT NULL,
          `payroll_period_id` INT DEFAULT NULL COMMENT 'periodo que lo consumio',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_timesheet_day` (`user_id`, `work_date`),
          KEY `idx_timesheet_day_date` (`work_date`, `status`),
          KEY `idx_timesheet_day_period` (`payroll_period_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 4. Comentarios que nadie puede borrar
// ---------------------------------------------------------------------------
tsStep('Tabla timesheet_comments (comentarios no eliminables)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `timesheet_comments` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` INT UNSIGNED NOT NULL COMMENT 'colaborador del que se habla',
          `work_date` DATE DEFAULT NULL,
          `payroll_period_id` INT DEFAULT NULL,
          `scope` ENUM('DAY','PERIOD','EXCEPTION') NOT NULL DEFAULT 'DAY',
          `exception_id` BIGINT UNSIGNED DEFAULT NULL,
          `comment` TEXT NOT NULL,
          `created_by` INT UNSIGNED DEFAULT NULL,
          `created_by_name` VARCHAR(120) DEFAULT NULL COMMENT 'nombre congelado al momento',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_ts_comment_day` (`user_id`, `work_date`),
          KEY `idx_ts_comment_period` (`payroll_period_id`),
          KEY `idx_ts_comment_exc` (`exception_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='Solo se agrega. Los triggers rechazan UPDATE y DELETE.'
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 5. Excepciones
// ---------------------------------------------------------------------------
tsStep('Tabla timesheet_exceptions (panel de excepciones)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `timesheet_exceptions` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` INT UNSIGNED NOT NULL,
          `work_date` DATE NOT NULL,
          `exception_type` VARCHAR(40) NOT NULL COMMENT 'OPEN_SHIFT, OVER_HOURS, ...',
          `severity` ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
          `title` VARCHAR(180) NOT NULL,
          `detail` TEXT DEFAULT NULL,
          `amount_dop` DECIMAL(12,2) DEFAULT NULL COMMENT 'dinero en juego, si aplica',
          `status` ENUM('OPEN','RESOLVED','DISMISSED') NOT NULL DEFAULT 'OPEN',
          `resolved_by` INT UNSIGNED DEFAULT NULL,
          `resolved_at` DATETIME DEFAULT NULL,
          `resolution_note` VARCHAR(255) DEFAULT NULL,
          `detected_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_ts_exception` (`user_id`, `work_date`, `exception_type`),
          KEY `idx_ts_exc_status` (`status`, `work_date`),
          KEY `idx_ts_exc_date` (`work_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 6. Bitacora de etapas: la trazabilidad hasta la aprobacion del pago
// ---------------------------------------------------------------------------
tsStep('Tabla timesheet_stage_events (trazabilidad de etapas)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `timesheet_stage_events` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `scope` ENUM('DAY','PERIOD') NOT NULL DEFAULT 'DAY',
          `user_id` INT UNSIGNED DEFAULT NULL,
          `work_date` DATE DEFAULT NULL,
          `payroll_period_id` INT DEFAULT NULL,
          `from_stage` VARCHAR(20) DEFAULT NULL,
          `to_stage` VARCHAR(20) NOT NULL,
          `reason` VARCHAR(255) DEFAULT NULL,
          `authorization_code_id` INT DEFAULT NULL,
          `amount_dop` DECIMAL(12,2) DEFAULT NULL,
          `days_affected` INT DEFAULT NULL,
          `performed_by` INT UNSIGNED DEFAULT NULL,
          `performed_by_name` VARCHAR(120) DEFAULT NULL,
          `ip_address` VARCHAR(64) DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_ts_stage_day` (`user_id`, `work_date`),
          KEY `idx_ts_stage_period` (`payroll_period_id`),
          KEY `idx_ts_stage_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='Solo se agrega. Los triggers rechazan UPDATE y DELETE.'
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 7. attendance_audit: etapa, impacto en pesos y codigo usado
// ---------------------------------------------------------------------------
$auditCols = [
    'stage_at_change'       => "VARCHAR(20) DEFAULT NULL COMMENT 'etapa del dia cuando se hizo el cambio'",
    'impact_amount'         => "DECIMAL(12,2) DEFAULT NULL COMMENT 'RD$ que el cambio agrego o quito'",
    'authorization_code_id' => "INT DEFAULT NULL COMMENT 'codigo que autorizo el cambio fuera de ventana'",
    'was_outside_window'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'was_after_close'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'el dia ya estaba cerrado'",
];
foreach ($auditCols as $col => $ddl) {
    tsStep("Columna attendance_audit.$col", function () use ($pdo, $col, $ddl) {
        if (!tsTableExists($pdo, 'attendance_audit')) {
            throw new RuntimeException('Falta attendance_audit: corre antes run_payroll_module_migration.php');
        }
        if (tsColumnExists($pdo, 'attendance_audit', $col)) {
            return 'SKIP';
        }
        $pdo->exec("ALTER TABLE `attendance_audit` ADD COLUMN `$col` $ddl");
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// ---------------------------------------------------------------------------
// 8. payroll_periods: consolidacion, auditoria y bloqueo
// ---------------------------------------------------------------------------
$periodCols = [
    'consolidated_by'   => "INT UNSIGNED DEFAULT NULL",
    'consolidated_at'   => "DATETIME DEFAULT NULL",
    'audited_by'        => "INT UNSIGNED DEFAULT NULL",
    'audited_at'        => "DATETIME DEFAULT NULL",
    'audit_note'        => "VARCHAR(255) DEFAULT NULL",
    'audit_result'      => "ENUM('PENDING','SIGNED','RETURNED') NOT NULL DEFAULT 'PENDING'",
    'control_locked'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ningun dia del periodo admite cambios'",
    'reopened_by'       => "INT UNSIGNED DEFAULT NULL",
    'reopened_at'       => "DATETIME DEFAULT NULL",
    'reopen_reason'     => "VARCHAR(255) DEFAULT NULL",
];
foreach ($periodCols as $col => $ddl) {
    tsStep("Columna payroll_periods.$col", function () use ($pdo, $col, $ddl) {
        if (tsColumnExists($pdo, 'payroll_periods', $col)) {
            return 'SKIP';
        }
        $pdo->exec("ALTER TABLE `payroll_periods` ADD COLUMN `$col` $ddl");
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// ---------------------------------------------------------------------------
// 9. Inmutabilidad: la base de datos rechaza alterar las bitacoras
// ---------------------------------------------------------------------------
$immutable = [
    'attendance_original'   => 'el ponche original',
    'attendance_voided'     => 'el archivo de punches eliminados',
    'attendance_audit'      => 'el historial de ajustes',
    'timesheet_comments'    => 'los comentarios',
    'timesheet_stage_events'=> 'la bitacora de etapas',
    // Horas de Vicidial: su bitacora era "inmutable por convencion". Ahora lo es
    // de verdad. OJO: `vicidial_agent_timesheet` (la fuente cruda) NO lleva
    // trigger porque el importador la reescribe por UPSERT cada noche.
    'vicidial_payroll_adjustment_log' => 'la bitacora de ajustes de Vicidial',
];
foreach ($immutable as $table => $what) {
    if (!tsTableExists($pdo, $table)) {
        echo "[*] Trigger sobre $table{$nl}    SKIP (la tabla no existe en esta instalacion){$nl}";
        $skipped++;
        continue;
    }
    foreach (['UPDATE', 'DELETE'] as $event) {
        // attendance_audit se queda SIN trigger de UPDATE a proposito: el codigo
        // nunca la actualiza, pero un trigger ahi rompe cualquier ALTER futuro de
        // columnas sobre esa tabla. Su proteccion real es el trigger de DELETE.
        if ($table === 'attendance_audit' && $event === 'UPDATE') {
            continue;
        }
        $trg = 'trg_' . $table . '_no_' . strtolower($event);
        // MySQL corta el nombre del trigger en 64 chars; los nuestros caben.
        tsSoftStep("Trigger: prohibir $event sobre $table", function () use ($pdo, $trg, $table, $event, $what) {
            if (tsTriggerExists($pdo, $trg)) {
                return 'SKIP';
            }
            $msg = ucfirst($what) . " no se puede " . ($event === 'UPDATE' ? 'modificar' : 'eliminar') . ".";
            $msg = substr($msg, 0, 120);
            $pdo->exec("
                CREATE TRIGGER `$trg`
                BEFORE $event ON `$table`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = " . $pdo->quote($msg) . "
            ");
            return 'OK';
        }, $ok, $skipped, $warnings, $nl);
    }
}

// Por si una corrida anterior de este instalador alcanzo a crearlo.
tsStep('Retirar trigger de UPDATE sobre attendance_audit (choca con ALTER futuros)', function () use ($pdo) {
    if (!tsTriggerExists($pdo, 'trg_attendance_audit_no_update')) {
        return 'SKIP';
    }
    $pdo->exec("DROP TRIGGER `trg_attendance_audit_no_update`");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 10. Ajustes (todo configurable desde settings.php, nada en codigo)
// ---------------------------------------------------------------------------
$settings = [
    // Interruptor maestro. Apagarlo devuelve el sistema al comportamiento viejo.
    'timesheet_control_enabled'          => ['1', 'timesheet_control'],
    // Si esta en 0, el sistema AVISA pero no bloquea (util en marcha blanca).
    'timesheet_lock_enforced'            => ['1', 'timesheet_control'],
    // Ventana de ajuste: hasta las HH:MM del dia +N.
    'timesheet_adjust_deadline_hour'     => ['11:00', 'timesheet_control'],
    'timesheet_adjust_deadline_days'     => ['1', 'timesheet_control'],
    'timesheet_require_code_after_window'=> ['1', 'timesheet_control'],
    // Fecha desde la que aplica el procedimiento. Lo anterior nace cerrado.
    // Arranca en el PRIMER DIA DEL PERIODO EN CURSO, no hoy: si arrancara hoy, la
    // quincena a medio correr quedaria cerrada de golpe y Marcela no podria
    // corregir nada sin pedir reapertura desde el primer dia. Se puede cambiar
    // desde Ajustes > Control de Horas.
    'timesheet_control_start_date'       => [tsDefaultStartDate($pdo), 'timesheet_control'],
    // Umbrales de excepciones
    'timesheet_exception_over_hours'     => ['8', 'timesheet_control'],
    'timesheet_exception_critical_hours' => ['12', 'timesheet_control'],
    'timesheet_exception_impact_amount'  => ['500', 'timesheet_control'],
    'timesheet_exception_impact_pct'     => ['20', 'timesheet_control'],
    'timesheet_vicidial_diff_minutes'    => ['30', 'timesheet_control'],
    // Alertas
    'timesheet_alerts_enabled'           => ['1', 'timesheet_control'],
    'timesheet_alert_recipients'         => ['', 'timesheet_control'],
    'timesheet_alert_roles'              => ['Admin,GeneralManager,HR', 'timesheet_control'],
    // Reporte diario de impacto economico. Los nombres siguen la convencion del
    // despachador cron_daily_reports.php: <key>_report_enabled / _time /
    // _exclude_weekends. La clave del reporte es "timesheet".
    'timesheet_report_enabled'           => ['1', 'timesheet_control'],
    'timesheet_report_time'              => ['08:00', 'timesheet_control'],
    'timesheet_report_exclude_weekends'  => ['0', 'timesheet_control'],
    'timesheet_report_recipients'        => ['', 'timesheet_control'],
];
foreach ($settings as $key => [$value, $category]) {
    tsStep("Ajuste $key", function () use ($pdo, $key, $value, $category) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $s->execute([$key]);
        if ((int) $s->fetchColumn() > 0) {
            return 'SKIP';
        }
        $i = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, category)
                            VALUES (?, ?, 'string', ?)");
        $i->execute([$key, $value, $category]);
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// Los destinatarios arrancan con los del reporte de nomina, que ya esta configurado.
tsStep('Heredar destinatarios del reporte de nomina', function () use ($pdo) {
    $src = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'payroll_report_recipients'")->fetchColumn();
    if (!$src) {
        return 'SKIP';
    }
    $n = 0;
    foreach (['timesheet_alert_recipients', 'timesheet_report_recipients'] as $k) {
        $cur = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $cur->execute([$k]);
        if (trim((string) $cur->fetchColumn()) === '') {
            $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?")->execute([$src, $k]);
            $n++;
        }
    }
    return $n > 0 ? "$n destino(s)" : 'SKIP';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 11. Permisos de las secciones nuevas (segregacion de funciones)
// ---------------------------------------------------------------------------
// Quien ajusta no cierra el periodo, quien consolida no ajusta horas, quien
// audita no aprueba, y solo Gerencia aprueba el pago y autoriza reaperturas.
$sections = [
    'timesheet_control'          => ['Admin', 'GeneralManager', 'OperationsManager', 'HR', 'Supervisor'],
    'timesheet_close_day'        => ['Admin', 'GeneralManager', 'OperationsManager', 'Supervisor'],
    'timesheet_reopen_day'       => ['Admin', 'GeneralManager'],
    'timesheet_adjust_outside'   => ['Admin', 'GeneralManager'],
    'timesheet_exceptions'       => ['Admin', 'GeneralManager', 'OperationsManager', 'HR', 'Supervisor'],
    'payroll_consolidate'        => ['Admin', 'GeneralManager', 'HR'],
    'payroll_audit_sign'         => ['Admin', 'GeneralManager'],
    'payroll_approve_payment'    => ['Admin', 'GeneralManager'],
];
foreach ($sections as $section => $roles) {
    tsStep("Permiso $section", function () use ($pdo, $section, $roles) {
        $existing = $pdo->prepare("SELECT role FROM section_permissions WHERE section_key = ?");
        $existing->execute([$section]);
        $have = array_map('strtoupper', $existing->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $added = 0;
        $ins = $pdo->prepare("INSERT INTO section_permissions (section_key, role) VALUES (?, ?)");
        foreach ($roles as $role) {
            if (in_array(strtoupper($role), $have, true)) {
                continue;
            }
            // Solo roles que existan de verdad en esta instalacion.
            $chk = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
            $chk->execute([$role]);
            if ((int) $chk->fetchColumn() === 0) {
                continue;
            }
            $ins->execute([$section, $role]);
            $added++;
        }
        return $added > 0 ? "$added rol(es)" : 'SKIP';
    }, $ok, $skipped, $errors, $nl);
}

// ---------------------------------------------------------------------------
// 12. Cierre en bloque de lo ya ocurrido
// ---------------------------------------------------------------------------
// Los dias anteriores al arranque del procedimiento nacen CLOSED: nadie va a
// revisar hacia atras, pero tampoco se pueden tocar sin autorizacion.
tsStep('Cerrar en bloque los dias anteriores al arranque', function () use ($pdo) {
    $start = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'timesheet_control_start_date'")->fetchColumn();
    if (!$start) {
        return 'SKIP';
    }
    $n = $pdo->prepare("
        INSERT IGNORE INTO timesheet_day_status
            (user_id, work_date, status, closed_at, adjustments_count)
        SELECT a.user_id, DATE(a.timestamp), 'CLOSED', NOW(), 0
        FROM attendance a
        LEFT JOIN timesheet_day_status d
               ON d.user_id = a.user_id AND d.work_date = DATE(a.timestamp)
        WHERE DATE(a.timestamp) < ? AND d.id IS NULL
        GROUP BY a.user_id, DATE(a.timestamp)
    ");
    $n->execute([$start]);
    $count = $n->rowCount();
    return $count > 0 ? "$count dia(s)-colaborador" : 'SKIP';
}, $ok, $skipped, $errors, $nl);

echo "{$nl}=== Resumen ==={$nl}";
echo "OK: $ok   SKIP: $skipped   AVISOS: $warnings   ERRORES: $errors{$nl}";
if ($warnings > 0) {
    echo "{$nl}Los AVISOS suelen ser falta de privilegio TRIGGER en el hosting.{$nl}";
    echo "El bloqueo sigue vigente por codigo; lo que se pierde es la red de{$nl}";
    echo "seguridad a nivel de base de datos. Pedir el privilegio a HostGator.{$nl}";
}

if (!$cli) {
    echo "</pre>";
}
