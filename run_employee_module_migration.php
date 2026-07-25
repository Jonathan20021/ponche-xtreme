<?php
/**
 * Instalador idempotente del módulo de Empleados (ajustes solicitados).
 *
 * Crea:
 *   1. employee_warnings          -> amonestaciones, asignables desde el perfil
 *   2. employee_campaigns         -> varias campañas por empleado + historial
 *   3. employees.termination_*    -> motivo de salida y elegibilidad de recontratación
 *   4. required_document_types    -> catálogo configurable de documentos obligatorios
 *   5. employee_document_signatures -> firma electrónica de esos documentos
 *
 * Corre por navegador o por CLI, las veces que haga falta. El servidor es
 * MySQL 5.7 (no acepta `IF NOT EXISTS` en ALTER TABLE), así que cada paso
 * consulta primero a information_schema.
 */

require_once __DIR__ . '/db.php';

$cli = (PHP_SAPI === 'cli');
$nl  = $cli ? "\n" : "<br>\n";
if (!$cli) {
    echo "<pre style='font-family:Consolas,monospace;background:#0f172a;color:#cbd5e1;padding:16px;'>";
}

$schema = $pdo->query("SELECT DATABASE()")->fetchColumn();
echo "=== Migracion: Modulo de Empleados ==={$nl}DB: $schema{$nl}{$nl}";

$ok = 0; $skipped = 0; $errors = 0;

function empStep(string $label, callable $fn, &$ok, &$skipped, &$errors, string $nl): void
{
    echo "[*] $label{$nl}";
    try {
        $result = $fn();
        if ($result === 'SKIP') { echo "    SKIP{$nl}"; $skipped++; }
        else { echo "    OK" . (is_string($result) ? " ($result)" : '') . "{$nl}"; $ok++; }
    } catch (Throwable $e) {
        echo "    ERROR: " . $e->getMessage() . "{$nl}";
        $errors++;
    }
}

function empColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

// ---------------------------------------------------------------------------
// 1. Amonestaciones
// ---------------------------------------------------------------------------
empStep('Tabla employee_warnings (amonestaciones)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_warnings` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `employee_id` INT UNSIGNED NOT NULL,
          `warning_type` VARCHAR(30) NOT NULL DEFAULT 'VERBAL'
            COMMENT 'VERBAL, ESCRITA, SUSPENSION, ULTIMA_AMONESTACION',
          `severity` VARCHAR(20) NOT NULL DEFAULT 'LEVE' COMMENT 'LEVE, GRAVE, MUY_GRAVE',
          `subject` VARCHAR(255) NOT NULL,
          `description` TEXT,
          `incident_date` DATE NOT NULL,
          `corrective_action` TEXT COMMENT 'medida correctiva acordada',
          `suspension_days` DECIMAL(4,1) DEFAULT NULL,
          `attachment` VARCHAR(255) DEFAULT NULL,
          `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVA' COMMENT 'ACTIVA, CUMPLIDA, ANULADA',
          `acknowledged_at` DATETIME DEFAULT NULL COMMENT 'cuando el colaborador la firma/acepta',
          `employee_comments` TEXT,
          `issued_by` INT UNSIGNED DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_warnings_employee` (`employee_id`),
          KEY `idx_warnings_date` (`incident_date`),
          KEY `idx_warnings_status` (`status`),
          CONSTRAINT `fk_warnings_employee` FOREIGN KEY (`employee_id`)
            REFERENCES `employees` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 2. Varias campañas por empleado (+ historial)
// ---------------------------------------------------------------------------
empStep('Tabla employee_campaigns (varias campañas + historial)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_campaigns` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `employee_id` INT UNSIGNED NOT NULL,
          `campaign_id` INT UNSIGNED NOT NULL,
          `is_primary` TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'la campaña principal; se refleja en employees.campaign_id',
          `start_date` DATE DEFAULT NULL,
          `end_date` DATE DEFAULT NULL COMMENT 'NULL = asignación vigente',
          `notes` VARCHAR(255) DEFAULT NULL,
          `assigned_by` INT UNSIGNED DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_emp_campaigns_employee` (`employee_id`),
          KEY `idx_emp_campaigns_campaign` (`campaign_id`),
          KEY `idx_emp_campaigns_active` (`employee_id`, `end_date`),
          CONSTRAINT `fk_emp_campaigns_employee` FOREIGN KEY (`employee_id`)
            REFERENCES `employees` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// La campaña que ya tenía cada empleado se convierte en su asignación principal
// vigente, para que el historial no arranque vacío.
empStep('Backfill de la campaña actual de cada empleado', function () use ($pdo) {
    $pending = (int) $pdo->query("
        SELECT COUNT(*) FROM employees e
        WHERE e.campaign_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM employee_campaigns ec WHERE ec.employee_id = e.id)
    ")->fetchColumn();

    if ($pending === 0) {
        return 'SKIP';
    }

    $pdo->exec("
        INSERT INTO employee_campaigns (employee_id, campaign_id, is_primary, start_date, notes)
        SELECT e.id, e.campaign_id, 1, e.hire_date, 'Asignación inicial (migración)'
        FROM employees e
        WHERE e.campaign_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM employee_campaigns ec WHERE ec.employee_id = e.id)
    ");
    return "$pending empleados";
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 3. Motivo de terminación y elegibilidad de recontratación
// ---------------------------------------------------------------------------
$terminationColumns = [
    'termination_reason'    => "VARCHAR(30) DEFAULT NULL COMMENT 'DESAHUCIO, DESPIDO, ABANDONO, RENUNCIA, FIN_CONTRATO, MUTUO_ACUERDO' AFTER `termination_date`",
    'termination_notes'     => "TEXT DEFAULT NULL COMMENT 'detalle del motivo de salida' AFTER `termination_reason`",
    'rehire_eligibility'    => "VARCHAR(20) DEFAULT NULL COMMENT 'ELIGIBLE, REQUIERE_EVALUACION, NO_ELEGIBLE' AFTER `termination_notes`",
    'rehire_notes'          => "TEXT DEFAULT NULL COMMENT 'por qué es o no recontratable' AFTER `rehire_eligibility`",
    'terminated_by'         => "INT UNSIGNED DEFAULT NULL AFTER `rehire_notes`",
    'terminated_at'         => "DATETIME DEFAULT NULL AFTER `terminated_by`",
];
foreach ($terminationColumns as $column => $definition) {
    empStep("employees.$column", function () use ($pdo, $column, $definition) {
        if (empColumnExists($pdo, 'employees', $column)) {
            return 'SKIP';
        }
        $pdo->exec("ALTER TABLE `employees` ADD COLUMN `$column` $definition");
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// ---------------------------------------------------------------------------
// 4. Catálogo de documentos obligatorios del expediente
// ---------------------------------------------------------------------------
empStep('Tabla required_document_types (documentos obligatorios)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `required_document_types` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `doc_key` VARCHAR(60) NOT NULL COMMENT 'clave estable, no cambia aunque se renombre',
          `label` VARCHAR(150) NOT NULL COMMENT 'nombre que ve RRHH',
          `aliases` VARCHAR(255) DEFAULT NULL
            COMMENT 'CSV de document_type equivalentes ya cargados en employee_documents',
          `is_required` TINYINT(1) NOT NULL DEFAULT 1,
          `requires_signature` TINYINT(1) NOT NULL DEFAULT 0
            COMMENT '1 = el colaborador debe firmarlo electrónicamente',
          `applies_to_roles` VARCHAR(255) DEFAULT NULL COMMENT 'CSV de roles; NULL = todos',
          `sort_order` INT NOT NULL DEFAULT 0,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_required_doc_key` (`doc_key`),
          KEY `idx_required_doc_active` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// Los 11 documentos que pidió el cliente. `aliases` mapea a los document_type que
// ya existen en employee_documents (631 documentos cargados), para que el
// expediente NO aparezca vacío el día uno.
$requiredDocs = [
    ['politicas_equipos',   'Políticas de asignación y uso de equipos tecnológicos', 'Política de Empresa', 1, 1, 10],
    ['acta_descargo',       'Acta de descargo de carnet, uniforme y llave de acceso', '', 1, 1, 20],
    ['constancia_ingreso',  'Constancia de ingreso',                                 'Certificado Laboral', 1, 0, 30],
    ['oferta_laboral',      'Oferta laboral',                                        'Carta de Oferta', 1, 1, 40],
    ['registro_tss',        'Registro en TSS',                                       'TSS', 1, 0, 50],
    ['normas_seguridad',    'Normas de seguridad',                                   '', 1, 1, 60],
    ['guia_induccion',      'Guía de inducción',                                     'Código de Conducta', 1, 1, 70],
    ['contrato_confidencialidad', 'Contrato de confidencialidad',                    'Acuerdo de Confidencialidad', 1, 1, 80],
    ['contrato_trabajo',    'Contrato de trabajo',                                   'Contrato de Trabajo', 1, 1, 90],
    ['curriculum',          'Currículum Vitae',                                      'CV/Resume,Curriculum', 1, 0, 100],
    ['cedula',              'Cédula de identidad',                                   'Cédula,Cedula', 1, 0, 110],
];

foreach ($requiredDocs as [$key, $label, $aliases, $required, $signature, $order]) {
    empStep("documento obligatorio: $label", function () use ($pdo, $key, $label, $aliases, $required, $signature, $order) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM required_document_types WHERE doc_key = ?");
        $check->execute([$key]);
        if ((int) $check->fetchColumn() > 0) {
            return 'SKIP';
        }
        $ins = $pdo->prepare("
            INSERT INTO required_document_types (doc_key, label, aliases, is_required, requires_signature, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$key, $label, $aliases !== '' ? $aliases : null, $required, $signature, $order]);
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// ---------------------------------------------------------------------------
// 5. Firma electrónica
// ---------------------------------------------------------------------------
empStep('Tabla employee_document_signatures (firma electrónica)', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_document_signatures` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `employee_id` INT UNSIGNED NOT NULL,
          `doc_key` VARCHAR(60) NOT NULL COMMENT 'required_document_types.doc_key',
          `document_id` BIGINT UNSIGNED DEFAULT NULL
            COMMENT 'employee_documents.id del PDF firmado, una vez archivado',
          `status` VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE'
            COMMENT 'PENDIENTE, FIRMADO, RECHAZADO, CANCELADO',
          `token` VARCHAR(64) NOT NULL COMMENT 'enlace único de firma para el colaborador',
          `signature_image` MEDIUMTEXT DEFAULT NULL COMMENT 'trazo de la firma en data URI (PNG)',
          `signed_name` VARCHAR(150) DEFAULT NULL,
          `signed_id_number` VARCHAR(50) DEFAULT NULL COMMENT 'cédula con la que firmó',
          `signed_at` DATETIME DEFAULT NULL,
          `signed_ip` VARCHAR(45) DEFAULT NULL,
          `signed_user_agent` VARCHAR(255) DEFAULT NULL,
          `content_hash` VARCHAR(64) DEFAULT NULL COMMENT 'SHA-256 de lo que se firmó (evidencia)',
          `requested_by` INT UNSIGNED DEFAULT NULL,
          `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `expires_at` DATETIME DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_doc_signature_token` (`token`),
          KEY `idx_doc_signature_employee` (`employee_id`, `doc_key`),
          KEY `idx_doc_signature_status` (`status`),
          CONSTRAINT `fk_doc_signature_employee` FOREIGN KEY (`employee_id`)
            REFERENCES `employees` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// Enlaza un documento del expediente con la clave del documento obligatorio que
// satisface, para no depender de comparar etiquetas de texto.
empStep('employee_documents.doc_key', function () use ($pdo) {
    if (empColumnExists($pdo, 'employee_documents', 'doc_key')) {
        return 'SKIP';
    }
    $pdo->exec("ALTER TABLE `employee_documents`
                ADD COLUMN `doc_key` VARCHAR(60) DEFAULT NULL
                COMMENT 'required_document_types.doc_key que este archivo satisface'
                AFTER `document_type`");
    $pdo->exec("ALTER TABLE `employee_documents` ADD INDEX `idx_employee_documents_doc_key` (`doc_key`)");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

empStep('employee_documents.signature_id', function () use ($pdo) {
    if (empColumnExists($pdo, 'employee_documents', 'signature_id')) {
        return 'SKIP';
    }
    $pdo->exec("ALTER TABLE `employee_documents`
                ADD COLUMN `signature_id` BIGINT UNSIGNED DEFAULT NULL
                COMMENT 'firma electrónica que generó este archivo'
                AFTER `doc_key`");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 6. Ajustes configurables desde settings.php
// ---------------------------------------------------------------------------
$settings = [
    // Aviso de fin de período de prueba
    'trial_notice_enabled'          => ['1', 'employees'],
    'trial_period_days'             => ['90', 'employees'],
    'trial_notice_days_before'      => ['10', 'employees'],
    'trial_notice_roles'            => ['HR,Admin', 'employees'],
    'trial_notice_user_ids'         => ['', 'employees'],
    // Cumpleaños del mes
    'birthday_notice_enabled'       => ['1', 'employees'],
    'birthday_notice_roles'         => ['HR,Admin', 'employees'],
    'birthday_notice_user_ids'      => ['', 'employees'],
    // Permisos registrados
    'permission_notice_enabled'     => ['1', 'employees'],
    'permission_notice_roles'       => ['HR,Admin', 'employees'],
    'permission_notice_user_ids'    => ['', 'employees'],
    // Documentación incompleta
    'docs_notice_enabled'           => ['1', 'employees'],
    'docs_notice_roles'             => ['HR,Admin', 'employees'],
    'docs_notice_user_ids'          => ['', 'employees'],
    'docs_notice_grace_days'        => ['15', 'employees'],
    // Chat interno en la campana
    'chat_notice_enabled'           => ['1', 'employees'],
];

foreach ($settings as $key => [$value, $category]) {
    empStep("setting $key", function () use ($pdo, $key, $value, $category) {
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

if (!$cli) {
    echo "</pre>";
}
