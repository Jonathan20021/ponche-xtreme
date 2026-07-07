<?php
/**
 * lib/helpdesk_support.php
 *
 * Extensiones del helpdesk para el EQUIPO DE SOPORTE (consola admin): identidad
 * de roles de soporte, adjuntos (subida/lectura), respuestas guardadas (macros)
 * y self-heal de tablas nuevas. Usa mysqli ($conn) como el resto del helpdesk.
 */

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
        // La tabla helpdesk_attachments ya existe (migración add_helpdesk_system.sql);
        // por si una instalación no la tiene, la aseguramos.
        $conn->query("
            CREATE TABLE IF NOT EXISTS `helpdesk_attachments` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `ticket_id` INT NOT NULL,
              `comment_id` INT DEFAULT NULL,
              `uploaded_by` INT UNSIGNED NOT NULL,
              `file_name` VARCHAR(255) NOT NULL,
              `file_path` VARCHAR(500) NOT NULL,
              `file_size` INT NOT NULL,
              `file_type` VARCHAR(100),
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_ticket` (`ticket_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done = true;
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
        // Nombre físico único y opaco.
        $stored = 'tk' . $ticketId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dir = helpdeskAttachmentsDir();
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file($tmp, $dest)) {
            return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
        }
        $relPath = 'uploads/helpdesk/' . $stored;
        $stmt = $conn->prepare("INSERT INTO helpdesk_attachments (ticket_id, comment_id, uploaded_by, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiissis', $ticketId, $commentId, $uploadedBy, $origName, $relPath, $size, $mime);
        if (!$stmt->execute()) {
            @unlink($dest);
            return ['ok' => false, 'error' => 'No se pudo registrar el adjunto.'];
        }
        return ['ok' => true, 'id' => $conn->insert_id, 'file_name' => $origName];
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
