<?php
/**
 * lib/helpdesk_support.php
 *
 * Extensiones del helpdesk para el EQUIPO DE SOPORTE (consola admin): identidad
 * de roles de soporte, adjuntos (subida/lectura), respuestas guardadas (macros)
 * y self-heal de tablas nuevas. Usa mysqli ($conn) como el resto del helpdesk.
 */

require_once __DIR__ . '/crypto_vault.php';

if (!function_exists('helpdeskSupportRoles')) {
    /**
     * Roles que GESTIONAN tickets (consola admin) y pueden ser asignados.
     * IT + Desarrollador = soporte técnico; Admin + HR = supervisión/RRHH-nómina.
     */
    function helpdeskSupportRoles(): array
    {
        return ['ADMIN', 'HR', 'IT', 'DESARROLLADOR'];
    }
}

if (!function_exists('isHelpdeskSupport')) {
    /** ¿Este rol pertenece al equipo de soporte? (case-insensitive) */
    function isHelpdeskSupport(?string $role): bool
    {
        return in_array(strtoupper(trim((string) $role)), helpdeskSupportRoles(), true);
    }
}

if (!function_exists('ensureHelpdeskSupportTables')) {
    function ensureHelpdeskSupportTables(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        // ===== Tablas BASE del helpdesk (auto-provisión: "git pull y listo") =====
        // Se crean IF NOT EXISTS (no tocan las que ya existen). Sin FKs para que la
        // auto-creación sea a prueba de fallos en cualquier entorno.
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_categories` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `name` VARCHAR(100) NOT NULL,
              `description` TEXT,
              `department` VARCHAR(100),
              `color` VARCHAR(7) DEFAULT '#2A4CCC',
              `sla_response_hours` INT DEFAULT 24,
              `sla_resolution_hours` INT DEFAULT 72,
              `is_active` TINYINT(1) DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `unique_category_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_tickets` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `ticket_number` VARCHAR(20) UNIQUE NOT NULL,
              `user_id` INT UNSIGNED NOT NULL,
              `category_id` INT NOT NULL,
              `subject` VARCHAR(255) NOT NULL,
              `description` TEXT NOT NULL,
              `priority` ENUM('low','medium','high','critical') DEFAULT 'medium',
              `status` ENUM('open','in_progress','pending','resolved','closed','cancelled') DEFAULT 'open',
              `assigned_to` INT UNSIGNED DEFAULT NULL,
              `created_by_type` ENUM('employee','agent','admin') DEFAULT 'employee',
              `sla_response_deadline` DATETIME, `sla_resolution_deadline` DATETIME,
              `first_response_at` DATETIME DEFAULT NULL, `resolved_at` DATETIME DEFAULT NULL, `closed_at` DATETIME DEFAULT NULL,
              `sla_response_breached` TINYINT(1) DEFAULT 0, `sla_resolution_breached` TINYINT(1) DEFAULT 0,
              `ai_analysis` TEXT DEFAULT NULL, `ai_suggested_category` INT DEFAULT NULL, `ai_suggested_priority` VARCHAR(20) DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_user_id` (`user_id`), KEY `idx_assigned_to` (`assigned_to`), KEY `idx_status` (`status`),
              KEY `idx_priority` (`priority`), KEY `idx_created_at` (`created_at`), KEY `idx_category_id` (`category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_comments` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `ticket_id` INT NOT NULL, `user_id` INT UNSIGNED NOT NULL, `comment` TEXT NOT NULL,
              `is_internal` TINYINT(1) DEFAULT 0, `is_ai_generated` TINYINT(1) DEFAULT 0, `attachments` JSON DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_ticket_id` (`ticket_id`), KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_assignments` (
              `id` INT AUTO_INCREMENT PRIMARY KEY, `ticket_id` INT NOT NULL,
              `assigned_from` INT UNSIGNED DEFAULT NULL, `assigned_to` INT UNSIGNED NOT NULL, `assigned_by` INT UNSIGNED NOT NULL,
              `notes` TEXT, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY `idx_ticket_id` (`ticket_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_status_history` (
              `id` INT AUTO_INCREMENT PRIMARY KEY, `ticket_id` INT NOT NULL,
              `old_status` VARCHAR(50), `new_status` VARCHAR(50) NOT NULL, `changed_by` INT UNSIGNED NOT NULL,
              `notes` TEXT, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY `idx_ticket_id` (`ticket_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_notifications` (
              `id` INT AUTO_INCREMENT PRIMARY KEY, `ticket_id` INT NOT NULL, `user_id` INT UNSIGNED NOT NULL,
              `notification_type` ENUM('ticket_created','ticket_assigned','ticket_updated','comment_added','status_changed','sla_warning','sla_breached') NOT NULL,
              `title` VARCHAR(255) NOT NULL, `message` TEXT NOT NULL, `is_read` TINYINT(1) DEFAULT 0,
              `email_sent` TINYINT(1) DEFAULT 0, `email_sent_at` DATETIME DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_user_id` (`user_id`), KEY `idx_is_read` (`is_read`), KEY `idx_email_sent` (`email_sent`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Categorías por defecto (español) — cubren TODO: nómina, correos, horario,
        // técnico, etc. Solo se siembran si la tabla está VACÍA (no pisa las existentes).
        $cnt = $conn->query("SELECT COUNT(*) c FROM helpdesk_categories");
        if ($cnt && ((int) ($cnt->fetch_assoc()['c'] ?? 0)) === 0) {
            $cats = [
                ['Horario y Turnos', 'Discrepancias de horas, turnos o marcaje', 'HR', '#7C5CFC', 4, 24],
                ['Reclamación de Nómina', 'Reclamos y ajustes de pago', 'Nómina', '#E0393B', 4, 24],
                ['Solicitud de Correo / Cuenta', 'Crear o restablecer correo y accesos', 'IT', '#0FA8A7', 2, 8],
                ['Soporte Técnico', 'Problemas de PC, software o red', 'IT', '#2A4CCC', 4, 24],
                ['Vicidial / Discador', 'Discador, login, grabaciones', 'IT', '#0EA5E9', 2, 8],
                ['Sistema / Ponche', 'Errores del sistema de ponche o del portal', 'IT', '#2A4CCC', 2, 8],
                ['Equipo y Headset', 'Equipo físico, diademas, periféricos', 'IT', '#F79009', 24, 72],
                ['Recursos Humanos', 'Consultas y solicitudes de RRHH', 'HR', '#12B76A', 8, 48],
                ['Instalaciones', 'Facilidades físicas, mantenimiento', 'Mantenimiento', '#6C7A94', 12, 48],
                ['Capacitación', 'Entrenamiento y capacitación', 'HR', '#E83E8C', 48, 120],
                ['Consulta General', 'Cualquier otra solicitud', 'General', '#6610F2', 8, 24],
            ];
            $st = $conn->prepare("INSERT INTO helpdesk_categories (name, description, department, color, sla_response_hours, sla_resolution_hours, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
            foreach ($cats as $c) { $st->bind_param('ssssii', $c[0], $c[1], $c[2], $c[3], $c[4], $c[5]); @$st->execute(); }
        }

        // Permisos por defecto (solo si el key no tiene filas — no pisa ajustes manuales).
        foreach ([['helpdesk', ['Admin', 'HR', 'IT', 'Desarrollador']], ['helpdesk_tickets', ['Admin', 'HR', 'IT', 'Desarrollador', 'AGENT', 'Supervisor', 'QA']], ['helpdesk_reports', ['Admin', 'HR', 'IT', 'Desarrollador']]] as $perm) {
            $has = $conn->query("SELECT COUNT(*) c FROM section_permissions WHERE section_key = '" . $conn->real_escape_string($perm[0]) . "'");
            if ($has && ((int) ($has->fetch_assoc()['c'] ?? 0)) === 0) {
                $ps = $conn->prepare("INSERT INTO section_permissions (section_key, role) VALUES (?, ?)");
                foreach ($perm[1] as $rl) { $ps->bind_param('ss', $perm[0], $rl); @$ps->execute(); }
            }
        }

        // Respuestas guardadas (macros)
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_canned_responses` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `title` VARCHAR(150) NOT NULL,
              `body` TEXT NOT NULL,
              `category_id` INT DEFAULT NULL,
              `created_by` INT UNSIGNED DEFAULT NULL,
              `is_active` TINYINT(1) DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Adjuntos: el BINARIO se guarda en la DB compartida (file_blob) para que
        // funcione entre servidores (oficina y HostGator comparten DB, no disco).
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_attachments` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `ticket_id` INT NOT NULL,
              `comment_id` INT DEFAULT NULL,
              `uploaded_by` INT UNSIGNED NOT NULL,
              `file_name` VARCHAR(255) NOT NULL,
              `file_path` VARCHAR(500) DEFAULT NULL,
              `file_blob` LONGBLOB DEFAULT NULL,
              `file_size` INT NOT NULL,
              `file_type` VARCHAR(100),
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_ticket` (`ticket_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Si la tabla ya existía sin file_blob (migración vieja), agregar la columna.
        $col = $conn->query("SHOW COLUMNS FROM helpdesk_attachments LIKE 'file_blob'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE helpdesk_attachments ADD COLUMN `file_blob` LONGBLOB DEFAULT NULL AFTER `file_path`");
        }
        // Bóveda de credenciales de acceso remoto (AnyDesk/RustDesk...). Las
        // contraseñas/notas se guardan CIFRADAS (password_enc/notes_enc).
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_remote_access` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `user_id` INT UNSIGNED DEFAULT NULL,
              `label` VARCHAR(150) NOT NULL,
              `tool` VARCHAR(30) NOT NULL DEFAULT 'anydesk',
              `remote_id` VARCHAR(120) DEFAULT NULL,
              `password_enc` TEXT DEFAULT NULL,
              `notes_enc` TEXT DEFAULT NULL,
              `ip_hostname` VARCHAR(150) DEFAULT NULL,
              `created_by` INT UNSIGNED DEFAULT NULL,
              `updated_by` INT UNSIGNED DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // SLA por prioridad (configurable desde la UI del reporte). Gobierna los
        // plazos de respuesta/resolución de los tickets según su prioridad.
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_sla_priorities` (
              `priority` VARCHAR(10) NOT NULL PRIMARY KEY,
              `response_hours` INT UNSIGNED NOT NULL,
              `resolution_hours` INT UNSIGNED NOT NULL,
              `updated_by` INT UNSIGNED DEFAULT NULL,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $hasSla = $conn->query("SELECT COUNT(*) c FROM helpdesk_sla_priorities");
        if ($hasSla && ((int) ($hasSla->fetch_assoc()['c'] ?? 0)) === 0) {
            // Defaults razonables en horas (crítica = más estricta).
            $conn->query("INSERT INTO helpdesk_sla_priorities (priority, response_hours, resolution_hours) VALUES
                ('critical', 1, 4), ('high', 4, 8), ('medium', 8, 24), ('low', 24, 72)");
        }
        $done = true;
    }
}

if (!function_exists('helpdeskSlaDefaults')) {
    /** Defaults de SLA por prioridad (horas) — usados si la tabla aún no existe. */
    function helpdeskSlaDefaults(): array
    {
        return [
            'critical' => ['response' => 1,  'resolution' => 4],
            'high'     => ['response' => 4,  'resolution' => 8],
            'medium'   => ['response' => 8,  'resolution' => 24],
            'low'      => ['response' => 24, 'resolution' => 72],
        ];
    }
}

if (!function_exists('helpdeskGetSlaPriorities')) {
    /** SLA por prioridad => ['critical'=>['response'=>1,'resolution'=>4], ...]. */
    function helpdeskGetSlaPriorities(): array
    {
        $out = helpdeskSlaDefaults();
        try {
            $res = getMysqli()->query("SELECT priority, response_hours, resolution_hours FROM helpdesk_sla_priorities");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    if (isset($out[$r['priority']])) {
                        $out[$r['priority']] = ['response' => (int) $r['response_hours'], 'resolution' => (int) $r['resolution_hours']];
                    }
                }
            }
        } catch (Throwable $e) { /* tabla ausente: usa defaults */ }
        return $out;
    }
}

if (!function_exists('helpdeskSaveSlaPriorities')) {
    /** Guarda SLA por prioridad. $vals=['critical'=>['response'=>h,'resolution'=>h],...]. */
    function helpdeskSaveSlaPriorities(array $vals, ?int $userId = null): bool
    {
        $conn = getMysqli();
        $stmt = $conn->prepare("
            INSERT INTO helpdesk_sla_priorities (priority, response_hours, resolution_hours, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE response_hours = VALUES(response_hours),
                                    resolution_hours = VALUES(resolution_hours),
                                    updated_by = VALUES(updated_by)
        ");
        foreach (['critical', 'high', 'medium', 'low'] as $p) {
            if (!isset($vals[$p])) { continue; }
            $resp  = max(1, min(8760, (int) ($vals[$p]['response'] ?? 0)));
            $resol = max(1, min(8760, (int) ($vals[$p]['resolution'] ?? 0)));
            if ($resol < $resp) { $resol = $resp; } // resolución nunca antes que la respuesta
            $stmt->bind_param('siii', $p, $resp, $resol, $userId);
            $stmt->execute();
        }
        return true;
    }
}

if (!function_exists('helpdeskAttachmentsDir')) {
    /** Carpeta física de adjuntos (fuera del árbol público idealmente, pero aquí bajo uploads). */
    function helpdeskAttachmentsDir(): string
    {
        $dir = __DIR__ . '/../uploads/helpdesk';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('helpdeskAllowedUploadTypes')) {
    /** Tipos permitidos: imágenes (capturas), PDF, texto/log. */
    function helpdeskAllowedUploadTypes(): array
    {
        return [
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'application/pdf' => 'pdf', 'text/plain' => 'txt',
        ];
    }
}

if (!function_exists('helpdeskSaveUploadedFile')) {
    /**
     * Valida y guarda un archivo subido; registra en helpdesk_attachments.
     * @param array $file  entrada de $_FILES
     * @return array{ok:bool, error?:string, id?:int, file_name?:string}
     */
    function helpdeskSaveUploadedFile(mysqli $conn, int $ticketId, int $uploadedBy, array $file, ?int $commentId = null): array
    {
        ensureHelpdeskSupportTables($conn);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Fallo en la subida (código ' . ($file['error'] ?? '?') . ').'];
        }
        $size = (int) ($file['size'] ?? 0);
        $maxBytes = 10 * 1024 * 1024; // 10 MB
        if ($size <= 0 || $size > $maxBytes) {
            return ['ok' => false, 'error' => 'El archivo excede 10 MB o está vacío.'];
        }
        $tmp = $file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Archivo inválido.'];
        }
        // Detectar tipo real (no confiar en el cliente).
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $allowed = helpdeskAllowedUploadTypes();
        if (!isset($allowed[$mime])) {
            return ['ok' => false, 'error' => 'Tipo no permitido (' . $mime . '). Usa imagen, PDF o texto.'];
        }
        $ext = $allowed[$mime];
        $origName = preg_replace('/[^\w.\- ]+/u', '_', (string) ($file['name'] ?? ('archivo.' . $ext)));
        $origName = mb_substr($origName, 0, 180);

        // Guardar el BINARIO en la DB compartida (file_blob) — así el adjunto se ve
        // desde CUALQUIER servidor (oficina y HostGator comparten DB, no disco).
        $data = file_get_contents($tmp);
        if ($data === false) {
            return ['ok' => false, 'error' => 'No se pudo leer el archivo.'];
        }
        try {
            // file_path='' (la columna es NOT NULL en instalaciones viejas); el binario va en file_blob.
            $stmt = $conn->prepare("INSERT INTO helpdesk_attachments (ticket_id, comment_id, uploaded_by, file_name, file_path, file_blob, file_size, file_type) VALUES (?, ?, ?, ?, '', ?, ?, ?)");
            $stmt->bind_param('iiissis', $ticketId, $commentId, $uploadedBy, $origName, $data, $size, $mime);
            $stmt->execute();
            return ['ok' => true, 'id' => $conn->insert_id, 'file_name' => $origName];
        } catch (Throwable $e) {
            error_log('helpdeskSaveUploadedFile: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo guardar el adjunto (¿tamaño del archivo?).'];
        }
    }
}

if (!function_exists('helpdeskGetTicketAttachments')) {
    /** Adjuntos de un ticket (metadata; el binario se sirve por endpoint). */
    function helpdeskGetTicketAttachments(mysqli $conn, int $ticketId): array
    {
        ensureHelpdeskSupportTables($conn);
        $stmt = $conn->prepare("SELECT id, comment_id, uploaded_by, file_name, file_size, file_type, created_at FROM helpdesk_attachments WHERE ticket_id = ? ORDER BY created_at ASC");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $row['is_image'] = strpos((string) $row['file_type'], 'image/') === 0 ? 1 : 0;
            $out[] = $row;
        }
        return $out;
    }
}
