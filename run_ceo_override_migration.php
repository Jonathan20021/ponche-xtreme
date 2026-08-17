<?php
/**
 * Migración: autorización del CEO para tocar horas fuera de la ventana.
 *
 * Hasta ahora el candado de horas aceptaba el CÓDIGO SEMANAL, que es de uso
 * general (lo tiene medio mundo y sirve para entrada temprana, hora extra y
 * edición de registros). Corregir una hora vencida es otra cosa: mueve dinero
 * que ya iba camino a la nómina, así que el CEO pidió que eso solo se abra con
 * un código emitido por él, de un solo uso.
 *
 * Crea:
 *   - timesheet_override_requests : la solicitud de nómina y su resolución
 *   - los ajustes en system_settings (quién emite, cuánto dura, etc.)
 *
 * Es idempotente: se puede correr las veces que haga falta.
 *
 *   php run_ceo_override_migration.php
 */

require_once __DIR__ . '/db.php';

$cli = php_sapi_name() === 'cli';
if (!$cli) {
    session_start();
    if (!isset($_SESSION['user_id']) || strtoupper((string) ($_SESSION['role'] ?? '')) !== 'ADMIN') {
        http_response_code(403);
        die('Solo un Admin puede correr esta migración.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function mig_say(string $msg): void
{
    echo $msg . "\n";
    @flush();
}

mig_say('== Autorización del CEO para horas fuera de ventana ==');
mig_say('Base de datos: ' . $pdo->query('SELECT DATABASE()')->fetchColumn());
mig_say('');

// ---------------------------------------------------------------------------
// 1. La solicitud
// ---------------------------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timesheet_override_requests (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            requested_by          INT UNSIGNED NOT NULL,
            target_user_id        INT UNSIGNED NOT NULL,
            work_date             DATE NOT NULL,
            source                VARCHAR(20) NOT NULL DEFAULT 'vicidial',
            reason                VARCHAR(500) NOT NULL,
            current_seconds       INT NULL,
            proposed_seconds      INT NULL,
            amount_dop            DECIMAL(12,2) NULL,
            status                ENUM('PENDING','APPROVED','REJECTED','USED') NOT NULL DEFAULT 'PENDING',
            authorization_code_id INT UNSIGNED NULL,
            resolved_by           INT UNSIGNED NULL,
            resolved_at           DATETIME NULL,
            resolution_note       VARCHAR(500) NULL,
            created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_tor_status (status, created_at),
            KEY idx_tor_target (target_user_id, work_date),
            KEY idx_tor_requester (requested_by, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    mig_say('  [OK] timesheet_override_requests');
} catch (Throwable $e) {
    mig_say('  [ERROR] timesheet_override_requests: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 2. Ajustes (todos editables desde settings.php)
// ---------------------------------------------------------------------------
// Por defecto el emisor es el usuario 16 (Hugo Hidalgo). Se guarda por ID de
// usuario y NO por rol a propósito: el rol ADMIN lo comparte con otra persona,
// así que restringir por rol no sería "solo el CEO".
$hugoId = 16;
try {
    $s = $pdo->prepare("SELECT id FROM users WHERE username = 'hugo' OR full_name LIKE '%Hugo%Hidalgo%' ORDER BY id LIMIT 1");
    $s->execute();
    $found = (int) $s->fetchColumn();
    if ($found > 0) {
        $hugoId = $found;
    }
} catch (Throwable $e) {
    // se queda el default
}

$defaults = [
    // Interruptor maestro. Apagado, el candado vuelve a aceptar el código semanal.
    'timesheet_override_enabled'      => ['1', 'boolean', 'Exigir código del CEO (y no el semanal) para tocar horas fuera de la ventana de ajuste.'],
    // IDs de usuario que pueden EMITIR el código, separados por coma.
    'timesheet_override_issuers'      => [(string) $hugoId, 'string', 'IDs de usuario autorizados a emitir el código de horas fuera de ventana (el CEO).'],
    // Minutos de vigencia del código emitido.
    'timesheet_override_ttl_minutes'  => ['240', 'number', 'Minutos que dura vigente el código emitido por el CEO.'],
    // Usos permitidos por código. 1 = un solo uso.
    'timesheet_override_max_uses'     => ['1', 'number', 'Cuántas correcciones autoriza cada código emitido. 1 = un solo uso.'],
    // Aviso por correo al CEO cuando entra una solicitud.
    'timesheet_override_notify_email' => ['1', 'boolean', 'Avisar por correo al CEO cuando nómina solicita una autorización.'],
];

foreach ($defaults as $key => [$value, $type, $desc]) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_type, description, category)
            VALUES (?, ?, ?, ?, 'timesheet_control')
            ON DUPLICATE KEY UPDATE description = VALUES(description), category = VALUES(category)
        ");
        $stmt->execute([$key, $value, $type, $desc]);
        $actual = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $actual->execute([$key]);
        mig_say(sprintf('  [OK] %-34s = %s', $key, $actual->fetchColumn()));
    } catch (Throwable $e) {
        mig_say('  [ERROR] ' . $key . ': ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 3. Resumen
// ---------------------------------------------------------------------------
mig_say('');
try {
    $emisores = $pdo->query("
        SELECT u.id, u.full_name, u.role
        FROM users u
        WHERE FIND_IN_SET(u.id, (SELECT setting_value FROM system_settings WHERE setting_key = 'timesheet_override_issuers'))
    ")->fetchAll(PDO::FETCH_ASSOC);
    mig_say('Quién puede emitir el código:');
    foreach ($emisores as $e) {
        mig_say('  - #' . $e['id'] . ' ' . $e['full_name'] . ' (' . $e['role'] . ')');
    }
    if (empty($emisores)) {
        mig_say('  (!) Ninguno. Configúralo en Configuración > Control de Horas y Nómina.');
    }
} catch (Throwable $e) {
    mig_say('No se pudo leer la lista de emisores: ' . $e->getMessage());
}

mig_say('');
mig_say('Listo. Los códigos semanales existentes SIGUEN sirviendo para entrada');
mig_say('temprana, hora extra y edición de registros: lo único que cambió es que');
mig_say('ya no abren un día vencido.');
