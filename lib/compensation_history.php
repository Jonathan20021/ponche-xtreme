<?php
/**
 * lib/compensation_history.php
 *
 * Compensación con FECHA EFECTIVA.
 *
 * El problema: `users` guarda UNA sola compensación (la vigente). Cuando a un
 * colaborador le cambiaban la campaña a mitad de quincena y con eso el salario,
 * la nómina calculaba TODA la quincena con el salario nuevo — incluidos los días
 * que ya había trabajado con el salario viejo.
 *
 * La solución: cada cambio se registra en `employee_compensation_changes` con la
 * fecha desde la cual aplica, guardando la foto ANTES (prev_*) y DESPUÉS (new_*).
 * Así la nómina puede partir el período en tramos y pagar cada día con lo que de
 * verdad correspondía.
 *
 * Reglas de lectura (compensationForDate):
 *   - Para una fecha d, si existe un cambio con effective_date > d, aplica el
 *     prev_* del PRIMERO de ellos.
 *   - Si no hay ninguno posterior, manda `users` (la vigente) — salvo que el
 *     último cambio ya venciera y todavía no se haya volcado a `users`, en cuyo
 *     caso manda su new_*.
 *
 * Con esa regla, `users` sigue siendo la fuente de la verdad para "hoy" y el
 * historial solo reescribe el pasado: una edición de sueldo hecha por fuera de
 * este flujo NO se pierde ni se ignora.
 *
 * Los cambios con fecha futura NO tocan `users` hasta que llega el día;
 * applyDueCompensationChanges() los vuelca (lo llama la nómina, el perfil y el
 * cron diario).
 */

require_once __DIR__ . '/../db.php';

if (!function_exists('compensationColumns')) {
    /** Columnas de compensación que viven en `users`. */
    function compensationColumns(): array
    {
        return [
            'compensation_type',
            'hourly_rate',
            'hourly_rate_dop',
            'monthly_salary',
            'monthly_salary_dop',
            'daily_salary_usd',
            'daily_salary_dop',
            'preferred_currency',
        ];
    }
}

if (!function_exists('ensureCompensationChangesTable')) {
    /**
     * Crea la tabla si falta. Se hace en caliente (no depende de correr una
     * migración a mano) para que un deploy a otra base no rompa la nómina.
     */
    function ensureCompensationChangesTable(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `employee_compensation_changes` (
                  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `user_id` INT UNSIGNED NOT NULL,
                  `employee_id` INT UNSIGNED DEFAULT NULL,
                  `effective_date` DATE NOT NULL COMMENT 'desde este día aplica new_*',

                  `prev_compensation_type` VARCHAR(20) NOT NULL DEFAULT 'hourly',
                  `prev_hourly_rate` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                  `prev_hourly_rate_dop` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                  `prev_monthly_salary` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                  `prev_monthly_salary_dop` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                  `prev_daily_salary_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                  `prev_daily_salary_dop` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                  `prev_preferred_currency` VARCHAR(3) NOT NULL DEFAULT 'USD',

                  `new_compensation_type` VARCHAR(20) NOT NULL DEFAULT 'hourly',
                  `new_hourly_rate` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                  `new_hourly_rate_dop` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                  `new_monthly_salary` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                  `new_monthly_salary_dop` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                  `new_daily_salary_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                  `new_daily_salary_dop` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                  `new_preferred_currency` VARCHAR(3) NOT NULL DEFAULT 'USD',

                  `campaign_id` INT UNSIGNED DEFAULT NULL COMMENT 'campaña que motivó el cambio',
                  `previous_campaign_id` INT UNSIGNED DEFAULT NULL,
                  `source` VARCHAR(32) NOT NULL DEFAULT 'manual',
                  `reason` VARCHAR(255) DEFAULT NULL,
                  `is_applied` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ya volcado a users',
                  `applied_at` DATETIME DEFAULT NULL,
                  `created_by` INT UNSIGNED DEFAULT NULL,
                  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_comp_changes_user_date` (`user_id`, `effective_date`),
                  KEY `idx_comp_changes_pending` (`is_applied`, `effective_date`),
                  KEY `idx_comp_changes_employee` (`employee_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                  COMMENT='Cambios de compensación con fecha efectiva (nómina prorrateada)'
            ");
            $ready = true;
        } catch (Throwable $e) {
            error_log('ensureCompensationChangesTable: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
    }
}

if (!function_exists('normalizeCompensation')) {
    /**
     * Deja un arreglo de compensación con todas las claves y tipos correctos.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    function normalizeCompensation(array $raw): array
    {
        $type = strtolower(trim((string) ($raw['compensation_type'] ?? 'hourly')));
        if (!in_array($type, ['hourly', 'fixed', 'daily'], true)) {
            $type = 'hourly';
        }
        $currency = strtoupper(trim((string) ($raw['preferred_currency'] ?? 'USD')));
        if (!in_array($currency, ['USD', 'DOP'], true)) {
            $currency = 'USD';
        }

        return [
            'compensation_type'  => $type,
            'hourly_rate'        => round(max(0, (float) ($raw['hourly_rate'] ?? 0)), 4),
            'hourly_rate_dop'    => round(max(0, (float) ($raw['hourly_rate_dop'] ?? 0)), 4),
            'monthly_salary'     => round(max(0, (float) ($raw['monthly_salary'] ?? 0)), 2),
            'monthly_salary_dop' => round(max(0, (float) ($raw['monthly_salary_dop'] ?? 0)), 2),
            'daily_salary_usd'   => round(max(0, (float) ($raw['daily_salary_usd'] ?? 0)), 2),
            'daily_salary_dop'   => round(max(0, (float) ($raw['daily_salary_dop'] ?? 0)), 2),
            'preferred_currency' => $currency,
        ];
    }
}

if (!function_exists('compensationEquals')) {
    /** ¿Dos compensaciones son la misma? (comparación por valor, no por texto) */
    function compensationEquals(array $a, array $b): bool
    {
        $a = normalizeCompensation($a);
        $b = normalizeCompensation($b);
        foreach (compensationColumns() as $col) {
            if (is_string($a[$col])) {
                if ($a[$col] !== $b[$col]) {
                    return false;
                }
            } elseif (abs((float) $a[$col] - (float) $b[$col]) > 0.00005) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('getCurrentCompensation')) {
    /**
     * La compensación vigente del usuario, tal como está en `users`.
     *
     * @return array<string,mixed>
     */
    function getCurrentCompensation(PDO $pdo, int $userId): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT compensation_type, hourly_rate, hourly_rate_dop,
                       monthly_salary, monthly_salary_dop,
                       daily_salary_usd, daily_salary_dop, preferred_currency
                FROM users WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return normalizeCompensation($row ?: []);
        } catch (Throwable $e) {
            error_log('getCurrentCompensation: ' . $e->getMessage());
            return normalizeCompensation([]);
        }
    }
}

if (!function_exists('writeCurrentCompensation')) {
    /** Vuelca una compensación a `users` (la deja como la vigente). */
    function writeCurrentCompensation(PDO $pdo, int $userId, array $comp): bool
    {
        $comp = normalizeCompensation($comp);
        try {
            $stmt = $pdo->prepare("
                UPDATE users SET
                    compensation_type = ?, hourly_rate = ?, hourly_rate_dop = ?,
                    monthly_salary = ?, monthly_salary_dop = ?,
                    daily_salary_usd = ?, daily_salary_dop = ?, preferred_currency = ?
                WHERE id = ?
            ");
            return $stmt->execute([
                $comp['compensation_type'],
                $comp['hourly_rate'],
                $comp['hourly_rate_dop'],
                $comp['monthly_salary'],
                $comp['monthly_salary_dop'],
                $comp['daily_salary_usd'],
                $comp['daily_salary_dop'],
                $comp['preferred_currency'],
                $userId,
            ]);
        } catch (Throwable $e) {
            error_log('writeCurrentCompensation: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getCompensationChangeRows')) {
    /**
     * Todos los cambios registrados de un usuario, del más viejo al más nuevo.
     * Cacheado por request: la nómina consulta esto una vez por empleado.
     *
     * @return array<int,array<string,mixed>>
     */
    function getCompensationChangeRows(PDO $pdo, int $userId, bool $fresh = false): array
    {
        static $cache = [];
        if (!$fresh && isset($cache[$userId])) {
            return $cache[$userId];
        }
        if (!ensureCompensationChangesTable($pdo)) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT * FROM employee_compensation_changes
                WHERE user_id = ?
                ORDER BY effective_date ASC, id ASC
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('getCompensationChangeRows: ' . $e->getMessage());
            $rows = [];
        }

        $cache[$userId] = $rows;
        return $rows;
    }
}

if (!function_exists('clearCompensationChangeCache')) {
    /** Invalida el caché de un usuario tras escribir (o de todos si es null). */
    function clearCompensationChangeCache(PDO $pdo, ?int $userId = null): void
    {
        if ($userId !== null) {
            getCompensationChangeRows($pdo, $userId, true);
        }
    }
}

if (!function_exists('compensationSideFromRow')) {
    /**
     * Extrae el lado `prev` o `new` de una fila del historial.
     *
     * @return array<string,mixed>
     */
    function compensationSideFromRow(array $row, string $side): array
    {
        $p = $side === 'prev' ? 'prev_' : 'new_';
        return normalizeCompensation([
            'compensation_type'  => $row[$p . 'compensation_type']  ?? 'hourly',
            'hourly_rate'        => $row[$p . 'hourly_rate']        ?? 0,
            'hourly_rate_dop'    => $row[$p . 'hourly_rate_dop']    ?? 0,
            'monthly_salary'     => $row[$p . 'monthly_salary']     ?? 0,
            'monthly_salary_dop' => $row[$p . 'monthly_salary_dop'] ?? 0,
            'daily_salary_usd'   => $row[$p . 'daily_salary_usd']   ?? 0,
            'daily_salary_dop'   => $row[$p . 'daily_salary_dop']   ?? 0,
            'preferred_currency' => $row[$p . 'preferred_currency'] ?? 'USD',
        ]);
    }
}

if (!function_exists('resolveCompensationFromRows')) {
    /**
     * Compensación aplicable a una fecha, dada la lista de cambios y la vigente.
     *
     * @param array<int,array<string,mixed>> $rows ordenados por effective_date ASC
     * @return array<string,mixed>
     */
    function resolveCompensationFromRows(array $rows, array $current, string $date): array
    {
        foreach ($rows as $row) {
            if ((string) $row['effective_date'] > $date) {
                // El primer cambio POSTERIOR guarda la foto de lo que aplicaba antes.
                return compensationSideFromRow($row, 'prev');
            }
        }

        // La fecha cae después de todos los cambios: manda `users`, salvo que el
        // último cambio ya venciera y aún no se haya volcado (cron atrasado).
        $last = end($rows) ?: null;
        if ($last && (int) $last['is_applied'] === 0) {
            return compensationSideFromRow($last, 'new');
        }

        return normalizeCompensation($current);
    }
}

if (!function_exists('getCompensationForDate')) {
    /**
     * Compensación que aplicaba (o aplicará) a un usuario en una fecha dada.
     *
     * @return array<string,mixed>
     */
    function getCompensationForDate(PDO $pdo, int $userId, string $date): array
    {
        return resolveCompensationFromRows(
            getCompensationChangeRows($pdo, $userId),
            getCurrentCompensation($pdo, $userId),
            $date
        );
    }
}

if (!function_exists('getCompensationSegments')) {
    /**
     * Parte un rango de fechas en tramos de compensación homogénea.
     *
     * Devuelve SIEMPRE al menos un tramo (el rango completo). Si devuelve más de
     * uno, hubo un cambio de salario dentro del período y la nómina debe pagar
     * cada tramo con su propia tarifa.
     *
     * @return array<int,array{start:string,end:string,days:int,comp:array<string,mixed>,change_id:?int,reason:?string,campaign_id:?int}>
     */
    function getCompensationSegments(PDO $pdo, int $userId, string $start, string $end): array
    {
        if ($start === '' || $end === '' || $end < $start) {
            return [];
        }

        $rows    = getCompensationChangeRows($pdo, $userId);
        $current = getCurrentCompensation($pdo, $userId);

        // Fronteras: el inicio del rango + cada cambio que cae DENTRO del rango.
        $boundaries = [$start => null];
        foreach ($rows as $row) {
            $eff = (string) $row['effective_date'];
            if ($eff > $start && $eff <= $end) {
                $boundaries[$eff] = $row;
            }
        }
        ksort($boundaries);

        $dates    = array_keys($boundaries);
        $segments = [];

        foreach ($dates as $i => $segStart) {
            $segEnd = isset($dates[$i + 1])
                ? date('Y-m-d', strtotime($dates[$i + 1] . ' -1 day'))
                : $end;

            if ($segEnd < $segStart) {
                continue;
            }

            $row = $boundaries[$segStart];
            $segments[] = [
                'start'       => $segStart,
                'end'         => $segEnd,
                'days'        => (int) ((new DateTime($segStart))->diff(new DateTime($segEnd))->days) + 1,
                'comp'        => resolveCompensationFromRows($rows, $current, $segStart),
                'change_id'   => $row ? (int) $row['id'] : null,
                'reason'      => $row ? ($row['reason'] ?? null) : null,
                'campaign_id' => $row && $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null,
            ];
        }

        return $segments;
    }
}

if (!function_exists('resequenceCompensationChanges')) {
    /**
     * Encadena la línea de tiempo: el `prev` de cada cambio tiene que ser el
     * `new` del cambio anterior. Hace falta cuando se registra un cambio con
     * fecha retroactiva y ya existían cambios posteriores.
     */
    function resequenceCompensationChanges(PDO $pdo, int $userId): void
    {
        $rows = getCompensationChangeRows($pdo, $userId, true);
        if (count($rows) < 2) {
            return;
        }

        for ($i = 1; $i < count($rows); $i++) {
            $expected = compensationSideFromRow($rows[$i - 1], 'new');
            $actual   = compensationSideFromRow($rows[$i], 'prev');
            if (compensationEquals($expected, $actual)) {
                continue;
            }
            try {
                $stmt = $pdo->prepare("
                    UPDATE employee_compensation_changes SET
                        prev_compensation_type = ?, prev_hourly_rate = ?, prev_hourly_rate_dop = ?,
                        prev_monthly_salary = ?, prev_monthly_salary_dop = ?,
                        prev_daily_salary_usd = ?, prev_daily_salary_dop = ?, prev_preferred_currency = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $expected['compensation_type'],
                    $expected['hourly_rate'],
                    $expected['hourly_rate_dop'],
                    $expected['monthly_salary'],
                    $expected['monthly_salary_dop'],
                    $expected['daily_salary_usd'],
                    $expected['daily_salary_dop'],
                    $expected['preferred_currency'],
                    $rows[$i]['id'],
                ]);
            } catch (Throwable $e) {
                error_log('resequenceCompensationChanges: ' . $e->getMessage());
            }
        }

        getCompensationChangeRows($pdo, $userId, true);
    }
}

if (!function_exists('applyDueCompensationChanges')) {
    /**
     * Vuelca a `users` los cambios cuya fecha efectiva ya llegó y que todavía
     * están pendientes. Idempotente: un cambio ya aplicado no se vuelve a tocar,
     * así que NUNCA pisa una edición de sueldo hecha por otra vía.
     *
     * @return int cuántos cambios se aplicaron
     */
    function applyDueCompensationChanges(PDO $pdo, ?int $userId = null): int
    {
        if (!ensureCompensationChangesTable($pdo)) {
            return 0;
        }

        $today = date('Y-m-d');
        try {
            $sql = "
                SELECT * FROM employee_compensation_changes
                WHERE is_applied = 0 AND effective_date <= ?
            ";
            $params = [$today];
            if ($userId !== null) {
                $sql .= " AND user_id = ?";
                $params[] = $userId;
            }
            $sql .= " ORDER BY effective_date ASC, id ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $due = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('applyDueCompensationChanges: ' . $e->getMessage());
            return 0;
        }

        $applied = 0;
        foreach ($due as $row) {
            if (writeCurrentCompensation($pdo, (int) $row['user_id'], compensationSideFromRow($row, 'new'))) {
                try {
                    $pdo->prepare("UPDATE employee_compensation_changes SET is_applied = 1, applied_at = NOW() WHERE id = ?")
                        ->execute([$row['id']]);
                    $applied++;
                    getCompensationChangeRows($pdo, (int) $row['user_id'], true);
                } catch (Throwable $e) {
                    error_log('applyDueCompensationChanges (marcar): ' . $e->getMessage());
                }
            }
        }

        return $applied;
    }
}

if (!function_exists('recordCompensationChange')) {
    /**
     * Registra un cambio de compensación con fecha efectiva.
     *
     * Si la fecha ya llegó, el cambio se vuelca a `users` en el acto. Si es
     * futura, queda PENDIENTE: `users` no se toca hasta el día, pero la nómina
     * de un período que incluya esa fecha ya paga los días correctos.
     *
     * @param array<string,mixed> $newComp compensación nueva (claves de compensationColumns)
     * @param array{employee_id?:int,campaign_id?:int,previous_campaign_id?:int,reason?:string,source?:string,created_by?:int} $opts
     * @return int|null id del cambio, o null si no cambió nada / falló
     */
    function recordCompensationChange(
        PDO $pdo,
        int $userId,
        array $newComp,
        string $effectiveDate,
        array $opts = []
    ): ?int {
        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return null;
        }
        if (!ensureCompensationChangesTable($pdo)) {
            return null;
        }

        $newComp = normalizeCompensation($newComp);
        $rows    = getCompensationChangeRows($pdo, $userId, true);
        $current = getCurrentCompensation($pdo, $userId);

        // Lo que aplicaba el día anterior a la fecha efectiva.
        $dayBefore = date('Y-m-d', strtotime($effectiveDate . ' -1 day'));
        $prevComp  = resolveCompensationFromRows($rows, $current, $dayBefore);

        if (compensationEquals($prevComp, $newComp)) {
            return null; // no hay cambio real que registrar
        }

        $employeeId = $opts['employee_id'] ?? null;
        if (!$employeeId) {
            try {
                $st = $pdo->prepare("SELECT id FROM employees WHERE user_id = ? LIMIT 1");
                $st->execute([$userId]);
                $employeeId = $st->fetchColumn() ?: null;
            } catch (Throwable $e) { /* puede no tener ficha */ }
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO employee_compensation_changes (
                    user_id, employee_id, effective_date,
                    prev_compensation_type, prev_hourly_rate, prev_hourly_rate_dop,
                    prev_monthly_salary, prev_monthly_salary_dop,
                    prev_daily_salary_usd, prev_daily_salary_dop, prev_preferred_currency,
                    new_compensation_type, new_hourly_rate, new_hourly_rate_dop,
                    new_monthly_salary, new_monthly_salary_dop,
                    new_daily_salary_usd, new_daily_salary_dop, new_preferred_currency,
                    campaign_id, previous_campaign_id, source, reason, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $employeeId ?: null,
                $effectiveDate,
                $prevComp['compensation_type'], $prevComp['hourly_rate'], $prevComp['hourly_rate_dop'],
                $prevComp['monthly_salary'], $prevComp['monthly_salary_dop'],
                $prevComp['daily_salary_usd'], $prevComp['daily_salary_dop'], $prevComp['preferred_currency'],
                $newComp['compensation_type'], $newComp['hourly_rate'], $newComp['hourly_rate_dop'],
                $newComp['monthly_salary'], $newComp['monthly_salary_dop'],
                $newComp['daily_salary_usd'], $newComp['daily_salary_dop'], $newComp['preferred_currency'],
                $opts['campaign_id'] ?? null,
                $opts['previous_campaign_id'] ?? null,
                substr((string) ($opts['source'] ?? 'manual'), 0, 32),
                isset($opts['reason']) ? substr((string) $opts['reason'], 0, 255) : null,
                $opts['created_by'] ?? ($_SESSION['user_id'] ?? null),
            ]);
            $changeId = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('recordCompensationChange: ' . $e->getMessage());
            return null;
        }

        getCompensationChangeRows($pdo, $userId, true);
        resequenceCompensationChanges($pdo, $userId);
        applyDueCompensationChanges($pdo, $userId);

        return $changeId;
    }
}

if (!function_exists('cancelCompensationChange')) {
    /**
     * Elimina un cambio PENDIENTE (aún no volcado a `users`). Los ya aplicados
     * no se borran: son historia y la nómina vieja depende de ellos.
     */
    function cancelCompensationChange(PDO $pdo, int $changeId): bool
    {
        if (!ensureCompensationChangesTable($pdo)) {
            return false;
        }
        try {
            $st = $pdo->prepare("SELECT user_id, is_applied FROM employee_compensation_changes WHERE id = ?");
            $st->execute([$changeId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) $row['is_applied'] === 1) {
                return false;
            }
            $pdo->prepare("DELETE FROM employee_compensation_changes WHERE id = ?")->execute([$changeId]);
            getCompensationChangeRows($pdo, (int) $row['user_id'], true);
            resequenceCompensationChanges($pdo, (int) $row['user_id']);
            return true;
        } catch (Throwable $e) {
            error_log('cancelCompensationChange: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getCompensationChangeTimeline')) {
    /**
     * Línea de tiempo para mostrar en el perfil: cambios aplicados y pendientes.
     *
     * @return array<int,array<string,mixed>>
     */
    function getCompensationChangeTimeline(PDO $pdo, int $userId, int $limit = 30): array
    {
        if (!ensureCompensationChangesTable($pdo)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT ch.*, u.full_name AS created_by_name,
                       c.name AS campaign_name, c.color AS campaign_color
                FROM employee_compensation_changes ch
                LEFT JOIN users u ON u.id = ch.created_by
                LEFT JOIN campaigns c ON c.id = ch.campaign_id
                WHERE ch.user_id = ?
                ORDER BY ch.effective_date DESC, ch.id DESC
                LIMIT " . max(1, min(200, $limit)) . "
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('getCompensationChangeTimeline: ' . $e->getMessage());
            return [];
        }

        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['prev'] = compensationSideFromRow($r, 'prev');
            $r['new']  = compensationSideFromRow($r, 'new');
            $r['is_pending'] = ((int) $r['is_applied'] === 0 && (string) $r['effective_date'] > $today);
            $r['prev_label'] = formatCompensationLabel($r['prev']);
            $r['new_label']  = formatCompensationLabel($r['new']);
        }
        unset($r);

        return $rows;
    }
}

if (!function_exists('formatCompensationLabel')) {
    /** "RD$120.00/hora", "$1,200.00/mes"… para mostrar sin pensar. */
    function formatCompensationLabel(array $comp): string
    {
        $comp = normalizeCompensation($comp);
        $dop  = $comp['preferred_currency'] === 'DOP';
        $sym  = $dop ? 'RD$' : '$';

        switch ($comp['compensation_type']) {
            case 'fixed':
                $amount = $dop ? $comp['monthly_salary_dop'] : $comp['monthly_salary'];
                if ($amount <= 0) {
                    $amount = max($comp['monthly_salary_dop'], $comp['monthly_salary']);
                }
                return $sym . number_format($amount, 2) . '/mes';
            case 'daily':
                $amount = $dop ? $comp['daily_salary_dop'] : $comp['daily_salary_usd'];
                if ($amount <= 0) {
                    $amount = max($comp['daily_salary_dop'], $comp['daily_salary_usd']);
                }
                return $sym . number_format($amount, 2) . '/día';
            default:
                $amount = $dop ? $comp['hourly_rate_dop'] : $comp['hourly_rate'];
                if ($amount <= 0) {
                    $amount = max($comp['hourly_rate_dop'], $comp['hourly_rate']);
                }
                return $sym . number_format($amount, 2) . '/hora';
        }
    }
}

if (!function_exists('getPendingCompensationChanges')) {
    /**
     * Cambios programados que todavía no llegan a su fecha. Sirve para avisar en
     * el perfil y en la nómina antes de generarla.
     *
     * @return array<int,array<string,mixed>>
     */
    function getPendingCompensationChanges(PDO $pdo, ?int $userId = null): array
    {
        if (!ensureCompensationChangesTable($pdo)) {
            return [];
        }
        try {
            $sql = "
                SELECT ch.*, e.first_name, e.last_name, e.employee_code
                FROM employee_compensation_changes ch
                LEFT JOIN employees e ON e.id = ch.employee_id
                WHERE ch.is_applied = 0 AND ch.effective_date > CURDATE()
            ";
            $params = [];
            if ($userId !== null) {
                $sql .= " AND ch.user_id = ?";
                $params[] = $userId;
            }
            $sql .= " ORDER BY ch.effective_date ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('getPendingCompensationChanges: ' . $e->getMessage());
            return [];
        }
    }
}
