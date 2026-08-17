<?php
/**
 * lib/timesheet_override.php
 *
 * Autorización del CEO para corregir horas fuera de la ventana de ajuste.
 *
 * El candado de horas (lib/timesheet_control.php) pedía "un código de
 * autorización" y aceptaba el CÓDIGO SEMANAL. Ese código es de uso general: rota
 * solo, lo tiene el personal que autoriza entradas tempranas y horas extra, y
 * dura siete días. Corregir una hora ya vencida no es lo mismo: mueve dinero que
 * iba camino a la nómina. El CEO pidió que eso solo se abra con un código que
 * emita ÉL.
 *
 * Cómo funciona:
 *
 *   1. Nómina intenta corregir un día vencido -> el guard la detiene.
 *   2. Nómina pulsa "Solicitar autorización" y escribe el motivo. Queda una
 *      solicitud con el agente, el día, las horas y cuánto dinero mueve.
 *   3. Al CEO le llega la campana (y el correo). Entra, ve el detalle y emite.
 *   4. El sistema genera un código de UN SOLO USO, con vencimiento corto, en el
 *      contexto 'timesheet_override'. El semanal NO sirve aquí.
 *   5. Nómina escribe el código y guarda. El uso queda amarrado a la solicitud.
 *
 * Quién emite se guarda por ID de usuario (setting timesheet_override_issuers)
 * y no por rol: el rol ADMIN lo comparte más de una persona, así que por rol no
 * sería "solo el CEO".
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/authorization_functions.php';

/** Contexto propio del código. Un código semanal (contexto NULL) no lo cumple. */
if (!defined('TIMESHEET_OVERRIDE_CONTEXT')) {
    define('TIMESHEET_OVERRIDE_CONTEXT', 'timesheet_override');
}

if (!function_exists('timesheetOverrideTableReady')) {
    function timesheetOverrideTableReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timesheet_override_requests'
            ");
            $ready = ((int) $stmt->fetchColumn()) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('timesheetOverrideEnabled')) {
    /**
     * Si está apagado, el candado vuelve al comportamiento anterior (acepta el
     * código semanal). Sirve de escape si el CEO no está disponible.
     */
    function timesheetOverrideEnabled(PDO $pdo): bool
    {
        return (bool) getSystemSetting($pdo, 'timesheet_override_enabled', false);
    }
}

if (!function_exists('timesheetOverrideIssuerIds')) {
    /** @return int[] IDs de usuario que pueden emitir el código. */
    function timesheetOverrideIssuerIds(PDO $pdo): array
    {
        $raw = (string) getSystemSetting($pdo, 'timesheet_override_issuers', '');
        $ids = array_filter(array_map('intval', preg_split('/[\s,;]+/', $raw) ?: []));
        return array_values(array_unique($ids));
    }
}

if (!function_exists('timesheetOverrideCanIssue')) {
    function timesheetOverrideCanIssue(PDO $pdo, ?int $userId = null): bool
    {
        $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        return in_array($userId, timesheetOverrideIssuerIds($pdo), true);
    }
}

if (!function_exists('timesheetOverrideIssuers')) {
    /** Datos de los emisores, para mostrarlos ("pídeselo a ...") y avisarles. */
    function timesheetOverrideIssuers(PDO $pdo): array
    {
        $ids = timesheetOverrideIssuerIds($pdo);
        if (empty($ids)) {
            return [];
        }
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.username, e.email
                FROM users u
                LEFT JOIN employees e ON e.user_id = u.id
                WHERE u.id IN ($in)
                ORDER BY u.full_name
            ");
            $stmt->execute($ids);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('timesheetOverrideIssuers: ' . $e->getMessage());
            return [];
        }
    }
}

// ---------------------------------------------------------------------------
// El código
// ---------------------------------------------------------------------------

if (!function_exists('timesheetOverrideValidate')) {
    /**
     * Valida un código de horas fuera de ventana. A diferencia de
     * validateAuthorizationCode(), aquí el contexto tiene que ser EXACTAMENTE
     * 'timesheet_override': un código de contexto libre (el semanal) se rechaza,
     * que es justo el punto de todo esto.
     *
     * @return array{valid:bool, code_id:?int, message:string, row:?array}
     */
    function timesheetOverrideValidate(PDO $pdo, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['valid' => false, 'code_id' => null, 'message' => 'Código vacío', 'row' => null];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT id, code_name, usage_context, valid_from, valid_until,
                       max_uses, current_uses, created_by
                FROM authorization_codes
                WHERE code = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('timesheetOverrideValidate: ' . $e->getMessage());
            return ['valid' => false, 'code_id' => null, 'message' => 'Error al validar el código', 'row' => null];
        }

        if (!$row) {
            return ['valid' => false, 'code_id' => null, 'message' => 'código no encontrado o inactivo', 'row' => null];
        }

        if ((string) $row['usage_context'] !== TIMESHEET_OVERRIDE_CONTEXT) {
            // El caso típico: escribieron el código semanal.
            return [
                'valid'   => false,
                'code_id' => (int) $row['id'],
                'message' => 'ese código no autoriza horas fuera de ventana. '
                    . 'El código semanal sirve para entrada temprana y edición de registros, '
                    . 'no para corregir un día vencido: hace falta uno emitido por Gerencia.',
                'row'     => $row,
            ];
        }

        if (!empty($row['valid_from']) && time() < strtotime((string) $row['valid_from'])) {
            return ['valid' => false, 'code_id' => (int) $row['id'], 'message' => 'el código todavía no está vigente', 'row' => $row];
        }
        if (!empty($row['valid_until']) && time() > strtotime((string) $row['valid_until'])) {
            return [
                'valid'   => false,
                'code_id' => (int) $row['id'],
                'message' => 'el código venció el ' . date('d/m/Y H:i', strtotime((string) $row['valid_until']))
                    . '. Hay que pedir uno nuevo.',
                'row'     => $row,
            ];
        }
        if ($row['max_uses'] !== null && (int) $row['current_uses'] >= (int) $row['max_uses']) {
            return [
                'valid'   => false,
                'code_id' => (int) $row['id'],
                'message' => 'ese código ya se usó. Cada autorización sirve para una sola corrección.',
                'row'     => $row,
            ];
        }

        return ['valid' => true, 'code_id' => (int) $row['id'], 'message' => 'Código válido', 'row' => $row];
    }
}

if (!function_exists('timesheetOverrideCreateCode')) {
    /**
     * Emite un código. Solo lo llama quien pasó timesheetOverrideCanIssue().
     *
     * @param array{
     *   issued_by:int, label?:string, minutes?:int, max_uses?:int
     * } $opts
     * @return array{success:bool, code?:string, code_id?:int, valid_until?:string, message:string}
     */
    function timesheetOverrideCreateCode(PDO $pdo, array $opts): array
    {
        $issuedBy = (int) ($opts['issued_by'] ?? 0);
        if ($issuedBy <= 0 || !timesheetOverrideCanIssue($pdo, $issuedBy)) {
            return ['success' => false, 'message' => 'Solo Gerencia puede emitir este código.'];
        }

        $minutes = (int) ($opts['minutes'] ?? getSystemSetting($pdo, 'timesheet_override_ttl_minutes', 240));
        $minutes = max(5, min(60 * 24 * 7, $minutes));

        $maxUses = (int) ($opts['max_uses'] ?? getSystemSetting($pdo, 'timesheet_override_max_uses', 1));
        $maxUses = max(1, min(50, $maxUses));

        $label = trim((string) ($opts['label'] ?? ''));
        $codeName = 'Autorización de Gerencia' . ($label !== '' ? ' · ' . $label : '');

        try {
            $code       = generateUniqueAuthCode($pdo, 8);
            $validFrom  = date('Y-m-d H:i:s');
            $validUntil = date('Y-m-d H:i:s', time() + $minutes * 60);

            $result = createAuthorizationCode(
                $pdo,
                mb_substr($codeName, 0, 100),
                $code,
                'ceo',
                TIMESHEET_OVERRIDE_CONTEXT,
                $issuedBy,
                $validFrom,
                $validUntil,
                $maxUses
            );

            if (empty($result['success'])) {
                return ['success' => false, 'message' => $result['message'] ?? 'No se pudo crear el código.'];
            }

            return [
                'success'     => true,
                'code'        => $code,
                'code_id'     => (int) $result['code_id'],
                'valid_until' => $validUntil,
                'max_uses'    => $maxUses,
                'message'     => 'Código emitido. Vence ' . date('d/m/Y H:i', strtotime($validUntil)) . '.',
            ];
        } catch (Throwable $e) {
            error_log('timesheetOverrideCreateCode: ' . $e->getMessage());
            return ['success' => false, 'message' => 'No se pudo emitir el código: ' . $e->getMessage()];
        }
    }
}

// ---------------------------------------------------------------------------
// La solicitud
// ---------------------------------------------------------------------------

if (!function_exists('timesheetOverrideRequestCreate')) {
    /**
     * Nómina pide autorización para un día concreto.
     *
     * @param array{
     *   requested_by:int, target_user_id:int, work_date:string, reason:string,
     *   source?:string, current_seconds?:?int, proposed_seconds?:?int
     * } $data
     * @return array{ok:bool, message:string, id?:int}
     */
    function timesheetOverrideRequestCreate(PDO $pdo, array $data): array
    {
        if (!timesheetOverrideTableReady($pdo)) {
            return ['ok' => false, 'message' => 'Falta correr run_ceo_override_migration.php.'];
        }

        $requestedBy = (int) ($data['requested_by'] ?? ($_SESSION['user_id'] ?? 0));
        $targetUser  = (int) ($data['target_user_id'] ?? 0);
        $workDate    = (string) ($data['work_date'] ?? '');
        $reason      = trim((string) ($data['reason'] ?? ''));
        $source      = ($data['source'] ?? 'vicidial') === 'ponche' ? 'ponche' : 'vicidial';

        if ($requestedBy <= 0 || $targetUser <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            return ['ok' => false, 'message' => 'Solicitud incompleta.'];
        }
        if ($reason === '') {
            return ['ok' => false, 'message' => 'El motivo es obligatorio: es lo que Gerencia lee para decidir.'];
        }

        // Una sola solicitud viva por agente y día: si insisten, se actualiza el
        // motivo en vez de llenarle la campana al CEO con lo mismo.
        try {
            $dup = $pdo->prepare("
                SELECT id FROM timesheet_override_requests
                WHERE target_user_id = ? AND work_date = ? AND status = 'PENDING'
                LIMIT 1
            ");
            $dup->execute([$targetUser, $workDate]);
            $existing = (int) $dup->fetchColumn();
            if ($existing > 0) {
                $pdo->prepare("UPDATE timesheet_override_requests SET reason = ?, requested_by = ? WHERE id = ?")
                    ->execute([mb_substr($reason, 0, 500), $requestedBy, $existing]);
                return [
                    'ok'      => true,
                    'id'      => $existing,
                    'message' => 'Ya había una solicitud pendiente para ese día; se actualizó el motivo. Gerencia ya está avisada.',
                ];
            }
        } catch (Throwable $e) {
            error_log('timesheetOverrideRequestCreate(dup): ' . $e->getMessage());
        }

        // Cuánto dinero mueve el día, para que el CEO decida con el número
        // delante y no a ciegas.
        $currentSeconds = $data['current_seconds'] ?? null;
        $amount = null;
        try {
            require_once __DIR__ . '/timesheet_control.php';
            if ($currentSeconds === null && function_exists('timesheetDayWorkSeconds')) {
                $currentSeconds = timesheetDayWorkSeconds($pdo, $targetUser, $workDate);
            }
            if (function_exists('timesheetAmountForSeconds')) {
                $delta = (int) ($data['proposed_seconds'] ?? $currentSeconds) - (int) $currentSeconds;
                $amount = timesheetAmountForSeconds($pdo, $targetUser, $delta);
            }
        } catch (Throwable $e) {
            error_log('timesheetOverrideRequestCreate(monto): ' . $e->getMessage());
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO timesheet_override_requests
                    (requested_by, target_user_id, work_date, source, reason,
                     current_seconds, proposed_seconds, amount_dop)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $requestedBy,
                $targetUser,
                $workDate,
                $source,
                mb_substr($reason, 0, 500),
                $currentSeconds !== null ? (int) $currentSeconds : null,
                isset($data['proposed_seconds']) ? (int) $data['proposed_seconds'] : null,
                $amount !== null ? round((float) $amount, 2) : null,
            ]);
            $id = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('timesheetOverrideRequestCreate: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'No se pudo registrar la solicitud: ' . $e->getMessage()];
        }

        timesheetOverrideNotifyIssuers($pdo, $id);

        return [
            'ok'      => true,
            'id'      => $id,
            'message' => 'Solicitud enviada a Gerencia. Te avisamos aquí mismo cuando emitan el código.',
        ];
    }
}

if (!function_exists('timesheetOverrideNotifyIssuers')) {
    /** Campana para el CEO (dirigida a él, no al rol) y correo si está activo. */
    function timesheetOverrideNotifyIssuers(PDO $pdo, int $requestId): void
    {
        $req = timesheetOverrideGet($pdo, $requestId);
        if (!$req) {
            return;
        }

        $titulo = 'Autorización de horas solicitada';
        $detalle = $req['requester_name'] . ' pide autorización para corregir el '
            . date('d/m/Y', strtotime($req['work_date'])) . ' de ' . $req['target_name']
            . '. Motivo: ' . $req['reason'];

        try {
            if (file_exists(__DIR__ . '/notifications.php')) {
                require_once __DIR__ . '/notifications.php';
                if (function_exists('notifyCreate')) {
                    foreach (timesheetOverrideIssuerIds($pdo) as $issuerId) {
                        notifyCreate($pdo, [
                            'type'            => 'timesheet_override_request',
                            'severity'        => 'HIGH',
                            'title'           => $titulo,
                            'message'         => $detalle,
                            'url'             => 'hr/authorizations.php?req=' . $requestId,
                            'user_id'         => $issuerId,
                            'dedupe_key'      => 'tor-' . $requestId . '-' . $issuerId,
                            'requires_action' => true,
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('timesheetOverrideNotifyIssuers(campana): ' . $e->getMessage());
        }

        if (!getSystemSetting($pdo, 'timesheet_override_notify_email', true)) {
            return;
        }

        $monto = $req['amount_dop'] !== null
            ? 'RD$ ' . number_format((float) $req['amount_dop'], 2)
            : 'sin estimar';
        $horas = $req['current_seconds'] !== null
            ? sprintf('%d:%02d', intdiv((int) $req['current_seconds'], 3600), intdiv((int) $req['current_seconds'] % 3600, 60))
            : '—';

        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937">'
            . '<h2 style="color:#b45309;margin:0 0 12px">Autorización de horas solicitada</h2>'
            . '<p><strong>' . htmlspecialchars($req['requester_name']) . '</strong> necesita corregir un día '
            . 'que ya salió de la ventana de ajuste y no puede hacerlo sin tu autorización.</p>'
            . '<table cellpadding="6" style="border-collapse:collapse;margin:12px 0">'
            . '<tr><td style="color:#6b7280">Colaborador</td><td><strong>' . htmlspecialchars($req['target_name']) . '</strong></td></tr>'
            . '<tr><td style="color:#6b7280">Día</td><td><strong>' . date('d/m/Y', strtotime($req['work_date'])) . '</strong></td></tr>'
            . '<tr><td style="color:#6b7280">Horas registradas hoy</td><td>' . $horas . '</td></tr>'
            . '<tr><td style="color:#6b7280">Impacto estimado</td><td>' . $monto . '</td></tr>'
            . '<tr><td style="color:#6b7280">Motivo</td><td>' . htmlspecialchars($req['reason']) . '</td></tr>'
            . '</table>'
            . '<p>Entra al sistema, en <strong>Nómina &gt; Autorizaciones de Gerencia</strong>, para emitir '
            . 'el código o rechazar la solicitud. El código que se genera sirve una sola vez.</p>'
            . '<p style="color:#6b7280;font-size:12px">Ponche Xtreme · Control de Horas y Nómina</p>'
            . '</div>';

        try {
            if (file_exists(__DIR__ . '/email_functions.php')) {
                require_once __DIR__ . '/email_functions.php';
                if (function_exists('sendEmail')) {
                    foreach (timesheetOverrideIssuers($pdo) as $issuer) {
                        $to = trim((string) ($issuer['email'] ?? ''));
                        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                            sendEmail($to, $titulo . ' — ' . $req['target_name'], $html, $issuer['full_name'] ?? null);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('timesheetOverrideNotifyIssuers(correo): ' . $e->getMessage());
        }
    }
}

if (!function_exists('timesheetOverrideGet')) {
    function timesheetOverrideGet(PDO $pdo, int $id): ?array
    {
        if (!timesheetOverrideTableReady($pdo)) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT r.*,
                       COALESCE(NULLIF(TRIM(ru.full_name), ''), ru.username) AS requester_name,
                       COALESCE(NULLIF(TRIM(tu.full_name), ''), tu.username) AS target_name,
                       COALESCE(NULLIF(TRIM(su.full_name), ''), su.username) AS resolver_name,
                       ac.code AS issued_code, ac.valid_until AS code_valid_until,
                       ac.current_uses, ac.max_uses
                FROM timesheet_override_requests r
                LEFT JOIN users ru ON ru.id = r.requested_by
                LEFT JOIN users tu ON tu.id = r.target_user_id
                LEFT JOIN users su ON su.id = r.resolved_by
                LEFT JOIN authorization_codes ac ON ac.id = r.authorization_code_id
                WHERE r.id = ?
            ");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('timesheetOverrideGet: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('timesheetOverrideList')) {
    /**
     * @param array{status?:string, requested_by?:int, limit?:int} $filters
     */
    function timesheetOverrideList(PDO $pdo, array $filters = []): array
    {
        if (!timesheetOverrideTableReady($pdo)) {
            return [];
        }
        $sql = "
            SELECT r.*,
                   COALESCE(NULLIF(TRIM(ru.full_name), ''), ru.username) AS requester_name,
                   COALESCE(NULLIF(TRIM(tu.full_name), ''), tu.username) AS target_name,
                   COALESCE(NULLIF(TRIM(su.full_name), ''), su.username) AS resolver_name,
                   ac.code AS issued_code, ac.valid_until AS code_valid_until,
                   ac.current_uses, ac.max_uses
            FROM timesheet_override_requests r
            LEFT JOIN users ru ON ru.id = r.requested_by
            LEFT JOIN users tu ON tu.id = r.target_user_id
            LEFT JOIN users su ON su.id = r.resolved_by
            LEFT JOIN authorization_codes ac ON ac.id = r.authorization_code_id
            WHERE 1 = 1
        ";
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= " AND r.status = ?";
            $params[] = strtoupper((string) $filters['status']);
        }
        if (!empty($filters['requested_by'])) {
            $sql .= " AND r.requested_by = ?";
            $params[] = (int) $filters['requested_by'];
        }
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $sql .= " ORDER BY FIELD(r.status,'PENDING','APPROVED','USED','REJECTED'), r.created_at DESC LIMIT $limit";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('timesheetOverrideList: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('timesheetOverridePendingCount')) {
    function timesheetOverridePendingCount(PDO $pdo): int
    {
        if (!timesheetOverrideTableReady($pdo)) {
            return 0;
        }
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM timesheet_override_requests WHERE status = 'PENDING'")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('timesheetOverrideApprove')) {
    /**
     * El CEO emite el código para una solicitud. Devuelve el código en claro
     * (es lo único que se le muestra a él; no se vuelve a mostrar completo).
     *
     * @return array{ok:bool, message:string, code?:string, valid_until?:string}
     */
    function timesheetOverrideApprove(PDO $pdo, int $requestId, array $opts = []): array
    {
        $actor = (int) ($opts['performed_by'] ?? ($_SESSION['user_id'] ?? 0));
        if (!timesheetOverrideCanIssue($pdo, $actor)) {
            return ['ok' => false, 'message' => 'Solo Gerencia puede emitir este código.'];
        }

        $req = timesheetOverrideGet($pdo, $requestId);
        if (!$req) {
            return ['ok' => false, 'message' => 'La solicitud no existe.'];
        }
        if ($req['status'] !== 'PENDING') {
            return ['ok' => false, 'message' => 'Esa solicitud ya fue resuelta (' . $req['status'] . ').'];
        }

        $issued = timesheetOverrideCreateCode($pdo, [
            'issued_by' => $actor,
            'label'     => $req['target_name'] . ' ' . date('d/m/Y', strtotime($req['work_date'])),
            'minutes'   => isset($opts['minutes']) ? (int) $opts['minutes'] : null,
            'max_uses'  => isset($opts['max_uses']) ? (int) $opts['max_uses'] : null,
        ]);
        if (empty($issued['success'])) {
            return ['ok' => false, 'message' => $issued['message']];
        }

        try {
            $pdo->prepare("
                UPDATE timesheet_override_requests
                SET status = 'APPROVED', authorization_code_id = ?, resolved_by = ?,
                    resolved_at = NOW(), resolution_note = ?
                WHERE id = ? AND status = 'PENDING'
            ")->execute([
                $issued['code_id'],
                $actor,
                mb_substr(trim((string) ($opts['note'] ?? '')), 0, 500) ?: null,
                $requestId,
            ]);
        } catch (Throwable $e) {
            error_log('timesheetOverrideApprove: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'El código se creó pero no se pudo enlazar a la solicitud: ' . $e->getMessage()];
        }

        timesheetOverrideNotifyRequester($pdo, $requestId, $issued['code'], $issued['valid_until']);

        return [
            'ok'          => true,
            'code'        => $issued['code'],
            'valid_until' => $issued['valid_until'],
            'message'     => 'Código emitido para ' . $req['target_name'] . ' · '
                . date('d/m/Y', strtotime($req['work_date'])) . '.',
        ];
    }
}

if (!function_exists('timesheetOverrideReject')) {
    function timesheetOverrideReject(PDO $pdo, int $requestId, string $note, ?int $actor = null): array
    {
        $actor = (int) ($actor ?? ($_SESSION['user_id'] ?? 0));
        if (!timesheetOverrideCanIssue($pdo, $actor)) {
            return ['ok' => false, 'message' => 'Solo Gerencia puede resolver esta solicitud.'];
        }
        $note = trim($note);
        if ($note === '') {
            return ['ok' => false, 'message' => 'Escribe por qué no se autoriza: el solicitante lo va a leer.'];
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE timesheet_override_requests
                SET status = 'REJECTED', resolved_by = ?, resolved_at = NOW(), resolution_note = ?
                WHERE id = ? AND status = 'PENDING'
            ");
            $stmt->execute([$actor, mb_substr($note, 0, 500), $requestId]);
            if ($stmt->rowCount() === 0) {
                return ['ok' => false, 'message' => 'Esa solicitud ya estaba resuelta.'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        timesheetOverrideNotifyRequester($pdo, $requestId, null, null, $note);
        return ['ok' => true, 'message' => 'Solicitud rechazada. El solicitante ya fue avisado.'];
    }
}

if (!function_exists('timesheetOverrideNotifyRequester')) {
    /** Le avisa a quien pidió: con el código si se aprobó, con el motivo si no. */
    function timesheetOverrideNotifyRequester(
        PDO $pdo,
        int $requestId,
        ?string $code = null,
        ?string $validUntil = null,
        ?string $rejectNote = null
    ): void {
        $req = timesheetOverrideGet($pdo, $requestId);
        if (!$req) {
            return;
        }

        $aprobada = $code !== null;
        $titulo = $aprobada ? 'Autorización aprobada' : 'Autorización rechazada';
        $mensaje = $aprobada
            ? 'Gerencia autorizó la corrección del ' . date('d/m/Y', strtotime($req['work_date']))
                . ' de ' . $req['target_name'] . '. Código: ' . $code
                . ' (vence ' . date('d/m/Y H:i', strtotime((string) $validUntil)) . ', un solo uso).'
            : 'Gerencia no autorizó la corrección del ' . date('d/m/Y', strtotime($req['work_date']))
                . ' de ' . $req['target_name'] . '. Motivo: ' . (string) $rejectNote;

        try {
            if (file_exists(__DIR__ . '/notifications.php')) {
                require_once __DIR__ . '/notifications.php';
                if (function_exists('notifyCreate')) {
                    notifyCreate($pdo, [
                        'type'            => 'timesheet_override_resolved',
                        'severity'        => $aprobada ? 'HIGH' : 'NORMAL',
                        'title'           => $titulo,
                        'message'         => $mensaje,
                        'url'             => 'hr/payroll_hours.php?start=' . $req['work_date'] . '&end=' . $req['work_date'],
                        'user_id'         => (int) $req['requested_by'],
                        'dedupe_key'      => 'tor-res-' . $requestId,
                        'requires_action' => $aprobada,
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('timesheetOverrideNotifyRequester: ' . $e->getMessage());
        }
    }
}

if (!function_exists('timesheetOverrideMarkUsed')) {
    /**
     * Cierra la solicitud cuando su código efectivamente se usó. Lo llama
     * timesheetAfterChange() a través de timesheetConsumeCode().
     */
    function timesheetOverrideMarkUsed(PDO $pdo, int $codeId): void
    {
        if ($codeId <= 0 || !timesheetOverrideTableReady($pdo)) {
            return;
        }
        try {
            $pdo->prepare("
                UPDATE timesheet_override_requests
                SET status = 'USED'
                WHERE authorization_code_id = ? AND status = 'APPROVED'
            ")->execute([$codeId]);
        } catch (Throwable $e) {
            error_log('timesheetOverrideMarkUsed: ' . $e->getMessage());
        }
    }
}
