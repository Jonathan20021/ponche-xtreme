<?php
/**
 * lib/notifications.php
 *
 * Centro de notificaciones DENTRO del sistema de ponche (campana del header).
 *
 * Por qué existe: había reportes por correo para todo, pero nada que avisara en
 * pantalla. Los dos casos que lo pidieron:
 *   - Reclutamiento: ver la evaluación de la IA y su justificación ANTES de que
 *     la disposición quede aplicada al candidato.
 *   - Inventario: enterarse en el momento de que un producto está bajo o por
 *     agotarse, no al día siguiente en el correo.
 *
 * Destinatarios: por usuario concreto, por rol(es), o por permiso de sección
 * (lo ven los roles que tengan ese section_key). Todo se configura en
 * settings.php; aquí no hay nada hardcodeado.
 *
 * API pública:
 *   notifyCreate(PDO, array $opts): ?int
 *   notifyUnreadCount(PDO, int $userId, string $role): int
 *   notifyListForUser(PDO, int $userId, string $role, array $opts = []): array
 *   notifyMarkRead(PDO, int $userId, ?int $notificationId = null): int
 *   notifyResolve(PDO, int $notificationId, int $userId): bool
 *   notifyPurgeOld(PDO, ?int $days = null): int
 */

require_once __DIR__ . '/../db.php';

if (!function_exists('notificationsGetSettings')) {
    /**
     * @return array<string,string>
     */
    function notificationsGetSettings(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $defaults = [
            'notifications_enabled'         => '1',
            'notifications_poll_seconds'    => '90',
            'notifications_retention_days'  => '45',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'notifications_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        } catch (Throwable $e) {
            error_log('notificationsGetSettings: ' . $e->getMessage());
        }

        $cache = $defaults;
        return $cache;
    }
}

if (!function_exists('notificationsEnabled')) {
    function notificationsEnabled(PDO $pdo): bool
    {
        return (notificationsGetSettings($pdo)['notifications_enabled'] ?? '1') === '1';
    }
}

if (!function_exists('notificationsTablesReady')) {
    /**
     * Crea las tablas si faltan (mismo patrón que el helpdesk): así la campana
     * no tumba una página si la migración todavía no corrió en ese entorno.
     */
    function notificationsTablesReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $pdo->query("SELECT 1 FROM system_notifications LIMIT 1");
            $ready = true;
            return true;
        } catch (Throwable $e) {
            // sigue abajo e intenta crearlas
        }

        try {
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
            $ready = true;
        } catch (Throwable $e) {
            error_log('notificationsTablesReady: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
    }
}

if (!function_exists('notificationsParseCsv')) {
    /**
     * @return string[]
     */
    function notificationsParseCsv(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $parts = array_map('trim', preg_split('/[,;]+/', $raw) ?: []);
        return array_values(array_filter($parts, static fn($p) => $p !== ''));
    }
}

if (!function_exists('notifyCreate')) {
    /**
     * Inserta una notificación. Devuelve el id, o null si no se insertó
     * (deshabilitada, tablas ausentes, o dedupe_key ya presente).
     *
     * @param array{
     *   type:string, title:string, message:string,
     *   severity?:string, url?:?string, payload?:mixed,
     *   user_id?:?int, roles?:string|string[]|null, permission?:?string,
     *   dedupe_key?:?string, requires_action?:bool, expires_at?:?string
     * } $opts
     */
    function notifyCreate(PDO $pdo, array $opts): ?int
    {
        if (!notificationsEnabled($pdo) || !notificationsTablesReady($pdo)) {
            return null;
        }

        $type    = trim((string) ($opts['type'] ?? ''));
        $title   = trim((string) ($opts['title'] ?? ''));
        $message = trim((string) ($opts['message'] ?? ''));
        if ($type === '' || $title === '') {
            return null;
        }

        $severity = strtoupper((string) ($opts['severity'] ?? 'NORMAL'));
        if (!in_array($severity, ['LOW', 'NORMAL', 'HIGH', 'CRITICAL'], true)) {
            $severity = 'NORMAL';
        }

        $roles = $opts['roles'] ?? null;
        if (is_array($roles)) {
            $roles = implode(',', $roles);
        }
        $roles = notificationsParseCsv($roles);
        $rolesCsv = empty($roles) ? null : implode(',', $roles);

        $payload = $opts['payload'] ?? null;
        if ($payload !== null && !is_string($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO system_notifications
                    (notif_type, severity, title, message, url, payload_json,
                     target_user_id, target_roles, target_permission,
                     dedupe_key, requires_action, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $type,
                $severity,
                mb_substr($title, 0, 255),
                $message,
                $opts['url'] ?? null,
                $payload,
                isset($opts['user_id']) && $opts['user_id'] ? (int) $opts['user_id'] : null,
                $rolesCsv,
                $opts['permission'] ?? null,
                isset($opts['dedupe_key']) && $opts['dedupe_key'] !== '' ? mb_substr((string) $opts['dedupe_key'], 0, 190) : null,
                !empty($opts['requires_action']) ? 1 : 0,
                $opts['expires_at'] ?? null,
            ]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            // 23000 = choque con uq_system_notifications_dedupe: el mismo evento
            // ya está notificado. No es un error, es el comportamiento buscado.
            if ($e->getCode() === '23000') {
                return null;
            }
            error_log('notifyCreate: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('notificationsVisibilityClause')) {
    /**
     * Condición SQL de "esta notificación le corresponde a este usuario".
     *
     * @return array{sql:string, params:array<int,mixed>}
     */
    function notificationsVisibilityClause(int $userId, string $role): array
    {
        $roleUpper = strtoupper(trim($role));

        // Vista por rol: directo al usuario, o dirigida a su rol, o al permiso de
        // una sección que su rol tenga concedido en section_permissions.
        $sql = "(
            n.target_user_id = ?
            OR (
                n.target_user_id IS NULL
                AND (n.target_roles IS NULL OR FIND_IN_SET(?, UPPER(REPLACE(n.target_roles, ' ', ''))) > 0)
                AND (
                    n.target_permission IS NULL
                    OR EXISTS (
                        SELECT 1 FROM section_permissions sp
                        WHERE sp.section_key = n.target_permission
                          AND UPPER(sp.role) = ?
                    )
                )
            )
        )";

        return ['sql' => $sql, 'params' => [$userId, $roleUpper, $roleUpper]];
    }
}

if (!function_exists('notifyUnreadCount')) {
    function notifyUnreadCount(PDO $pdo, int $userId, string $role): int
    {
        if (!notificationsEnabled($pdo) || !notificationsTablesReady($pdo)) {
            return 0;
        }

        $vis = notificationsVisibilityClause($userId, $role);
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM system_notifications n
                LEFT JOIN system_notification_reads r
                       ON r.notification_id = n.id AND r.user_id = ?
                WHERE r.notification_id IS NULL
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                  AND {$vis['sql']}
            ");
            $stmt->execute(array_merge([$userId], $vis['params']));
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('notifyUnreadCount: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('notifyListForUser')) {
    /**
     * @param array{limit?:int, only_unread?:bool, type?:string} $opts
     * @return array<int,array<string,mixed>>
     */
    function notifyListForUser(PDO $pdo, int $userId, string $role, array $opts = []): array
    {
        if (!notificationsEnabled($pdo) || !notificationsTablesReady($pdo)) {
            return [];
        }

        $limit = (int) ($opts['limit'] ?? 25);
        if ($limit < 1 || $limit > 100) {
            $limit = 25;
        }

        $vis = notificationsVisibilityClause($userId, $role);
        $params = array_merge([$userId], $vis['params']);

        $extra = '';
        if (!empty($opts['only_unread'])) {
            $extra .= " AND r.notification_id IS NULL";
        }
        if (!empty($opts['type'])) {
            $extra .= " AND n.notif_type = ?";
            $params[] = (string) $opts['type'];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT n.id, n.notif_type, n.severity, n.title, n.message, n.url,
                       n.payload_json, n.requires_action, n.resolved_at, n.created_at,
                       (r.notification_id IS NOT NULL) AS is_read
                FROM system_notifications n
                LEFT JOIN system_notification_reads r
                       ON r.notification_id = n.id AND r.user_id = ?
                WHERE (n.expires_at IS NULL OR n.expires_at > NOW())
                  AND {$vis['sql']}
                  {$extra}
                ORDER BY n.created_at DESC, n.id DESC
                LIMIT {$limit}
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as &$row) {
                $row['is_read']         = (bool) $row['is_read'];
                $row['requires_action'] = (bool) $row['requires_action'];
                $row['payload']         = $row['payload_json'] ? json_decode((string) $row['payload_json'], true) : null;
                unset($row['payload_json']);
            }
            unset($row);

            return $rows;
        } catch (Throwable $e) {
            error_log('notifyListForUser: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('notifyMarkRead')) {
    /**
     * Marca una notificación como leída, o todas las visibles si $notificationId
     * es null. Devuelve cuántas quedaron marcadas.
     */
    function notifyMarkRead(PDO $pdo, int $userId, ?int $notificationId = null, string $role = ''): int
    {
        if (!notificationsTablesReady($pdo)) {
            return 0;
        }

        try {
            if ($notificationId !== null) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_notification_reads (notification_id, user_id)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE read_at = read_at
                ");
                $stmt->execute([$notificationId, $userId]);
                return 1;
            }

            $vis = notificationsVisibilityClause($userId, $role);
            $stmt = $pdo->prepare("
                INSERT INTO system_notification_reads (notification_id, user_id)
                SELECT n.id, ?
                FROM system_notifications n
                LEFT JOIN system_notification_reads r
                       ON r.notification_id = n.id AND r.user_id = ?
                WHERE r.notification_id IS NULL
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                  AND {$vis['sql']}
                ON DUPLICATE KEY UPDATE read_at = read_at
            ");
            $stmt->execute(array_merge([$userId, $userId], $vis['params']));
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('notifyMarkRead: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('notifyResolve')) {
    /** Cierra una notificación que pedía acción (ya se atendió). */
    function notifyResolve(PDO $pdo, int $notificationId, int $userId): bool
    {
        if (!notificationsTablesReady($pdo)) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("
                UPDATE system_notifications
                SET resolved_at = NOW(), resolved_by = ?
                WHERE id = ? AND resolved_at IS NULL
            ");
            $stmt->execute([$userId, $notificationId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('notifyResolve: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('notifyPurgeOld')) {
    /** Borra notificaciones viejas ya leídas/resueltas según la retención configurada. */
    function notifyPurgeOld(PDO $pdo, ?int $days = null): int
    {
        if (!notificationsTablesReady($pdo)) {
            return 0;
        }
        if ($days === null) {
            $days = (int) (notificationsGetSettings($pdo)['notifications_retention_days'] ?? 45);
        }
        if ($days < 1) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("
                DELETE FROM system_notifications
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND (requires_action = 0 OR resolved_at IS NOT NULL)
            ");
            $stmt->execute([$days]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('notifyPurgeOld: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('notifyChatUnreadForUser')) {
    /**
     * Mensajes sin leer del chat interno, para mostrarlos en la campana.
     *
     * NO se copian a system_notifications a propósito: el chat ya lleva su propia
     * tabla (chat_notifications, con miles de filas) y duplicarlas ahogaría la
     * campana y obligaría a mantener dos estados de "leído" en sincronía. Aquí se
     * lee en vivo y se muestra como UNA entrada agregada que enlaza al chat.
     *
     * @return array{count:int, conversations:int, last_at:?string, preview:?string}|null
     */
    function notifyChatUnreadForUser(PDO $pdo, int $userId): ?array
    {
        if ((notificationsGetSettings($pdo)['chat_notice_enabled'] ?? '1') !== '1') {
            return null;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS unread,
                       COUNT(DISTINCT n.conversation_id) AS conversations,
                       MAX(n.created_at) AS last_at
                FROM chat_notifications n
                WHERE n.user_id = ? AND n.is_read = 0
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $count = (int) ($row['unread'] ?? 0);
            if ($count <= 0) {
                return null;
            }

            // Quién escribió lo último, para que el aviso diga algo útil.
            $preview = null;
            try {
                $p = $pdo->prepare("
                    SELECT u.full_name
                    FROM chat_notifications n
                    INNER JOIN chat_messages m ON m.id = n.message_id
                    INNER JOIN users u ON u.id = m.sender_id
                    WHERE n.user_id = ? AND n.is_read = 0
                    ORDER BY n.created_at DESC
                    LIMIT 1
                ");
                $p->execute([$userId]);
                $preview = $p->fetchColumn() ?: null;
            } catch (Throwable $e) { /* el preview es opcional */ }

            return [
                'count'         => $count,
                'conversations' => (int) ($row['conversations'] ?? 0),
                'last_at'       => $row['last_at'] ?? null,
                'preview'       => $preview,
            ];
        } catch (Throwable $e) {
            error_log('notifyChatUnreadForUser: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('notifyChatAsNotification')) {
    /**
     * Convierte el conteo del chat en una entrada con la misma forma que las
     * demás notificaciones, para que la campana la pinte sin casos especiales.
     */
    function notifyChatAsNotification(PDO $pdo, int $userId): ?array
    {
        $chat = notifyChatUnreadForUser($pdo, $userId);
        if ($chat === null) {
            return null;
        }

        $msg = $chat['count'] . ' mensaje' . ($chat['count'] === 1 ? '' : 's') . ' sin leer';
        if ($chat['conversations'] > 1) {
            $msg .= ' en ' . $chat['conversations'] . ' conversaciones';
        }
        if (!empty($chat['preview'])) {
            $msg .= "\nÚltimo de: " . $chat['preview'];
        }

        return [
            'id'              => 'chat',           // id sintético: no vive en la tabla
            'notif_type'      => 'CHAT_UNREAD',
            'severity'        => 'NORMAL',
            'title'           => 'Chat interno',
            'message'         => $msg,
            'url'             => 'chat/index.php',
            'payload'         => ['unread' => $chat['count'], 'conversations' => $chat['conversations']],
            'requires_action' => false,
            'resolved_at'     => null,
            'created_at'      => $chat['last_at'] ?? date('Y-m-d H:i:s'),
            'is_read'         => false,
            'is_virtual'      => true,
        ];
    }
}

if (!function_exists('notifyResolveTargetUserIds')) {
    /**
     * Convierte un CSV de ids de usuario configurado en settings a ids válidos
     * y activos. Se usa para "avisar además a estas personas en concreto".
     *
     * @return int[]
     */
    function notifyResolveTargetUserIds(PDO $pdo, ?string $csv): array
    {
        $ids = array_values(array_filter(array_map('intval', notificationsParseCsv($csv)), static fn($i) => $i > 0));
        if (empty($ids)) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND is_active = 1");
            $stmt->execute($ids);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            error_log('notifyResolveTargetUserIds: ' . $e->getMessage());
            return [];
        }
    }
}
