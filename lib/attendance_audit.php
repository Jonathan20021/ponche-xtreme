<?php
/**
 * lib/attendance_audit.php
 *
 * Historial de modificaciones a las horas del PONCHE, con el mismo nivel de
 * detalle que ya existía para Vicidial (vicidial_payroll_adjustments): valor
 * original, valor modificado y quién lo cambió.
 *
 * La diferencia con Vicidial es que allá se ajusta un total de segundos, mientras
 * que aquí se edita un punch suelto y el efecto en las horas es indirecto. Por eso
 * cada cambio guarda el total del día y la duración POR ESTADO **antes y después**,
 * calculados con la misma lógica que paga la nómina
 * (lib/work_hours_calculator.php).
 *
 * Eso es lo que permite mostrar el ejemplo que pidió el cliente:
 *   "2 minutos en baño registrado en ponche, 5 minutos en baño registrado por mrosario"
 *
 * Uso (envolviendo la edición):
 *
 *     $before = attendanceAuditSnapshot($pdo, $userId, $workDate);
 *     ... UPDATE / INSERT / DELETE sobre attendance ...
 *     attendanceAuditRecord($pdo, [...], $before);
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/work_hours_calculator.php';

if (!function_exists('attendanceAuditSnapshot')) {
    /**
     * Foto de cómo quedan las horas de un día ANTES de tocarlo.
     *
     * @return array{work_seconds:int, durations:array<string,int>}
     */
    function attendanceAuditSnapshot(PDO $pdo, int $userId, string $workDate): array
    {
        $empty = ['work_seconds' => 0, 'durations' => []];
        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            return $empty;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT id, type, timestamp
                FROM attendance
                WHERE user_id = ? AND DATE(timestamp) = ?
                ORDER BY timestamp ASC, id ASC
            ");
            $stmt->execute([$userId, $workDate]);
            $punches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $paid = normalizePaidTypeSlugs(getPaidAttendanceTypeSlugs($pdo));
            $calc = calculateWorkSecondsFromPunches($punches, $paid);

            return [
                'work_seconds' => (int) ($calc['work_seconds'] ?? 0),
                'durations'    => $calc['durations_all'] ?? [],
            ];
        } catch (Throwable $e) {
            error_log('attendanceAuditSnapshot: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('attendanceAuditRecord')) {
    /**
     * Registra el cambio. Se llama DESPUÉS de tocar la tabla attendance.
     *
     * Nunca lanza: perder la bitácora no puede tumbar una corrección de ponche
     * que el supervisor ya aplicó.
     *
     * @param array{
     *   attendance_id?:?int, user_id:int, work_date:string, action:string,
     *   old_type?:?string, new_type?:?string,
     *   old_timestamp?:?string, new_timestamp?:?string,
     *   reason?:?string, source?:?string, performed_by?:?int
     * } $change
     * @param array{work_seconds:int, durations:array<string,int>} $before
     */
    function attendanceAuditRecord(PDO $pdo, array $change, array $before): ?int
    {
        try {
            $userId   = (int) ($change['user_id'] ?? 0);
            $workDate = (string) ($change['work_date'] ?? '');
            if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
                return null;
            }

            $after = attendanceAuditSnapshot($pdo, $userId, $workDate);

            // Columnas del control de horas (migración run_timesheet_control_migration).
            // Se detectan una sola vez: si la migración no corrió, el INSERT
            // sigue siendo el de siempre y nada se rompe.
            static $hasControlCols = null;
            if ($hasControlCols === null) {
                try {
                    $chk = $pdo->query("
                        SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_audit'
                          AND COLUMN_NAME IN ('stage_at_change','impact_amount','authorization_code_id',
                                              'was_outside_window','was_after_close')
                    ");
                    $hasControlCols = ((int) $chk->fetchColumn()) === 5;
                } catch (Throwable $e) {
                    $hasControlCols = false;
                }
            }

            $extraCols = '';
            $extraVals = '';
            if ($hasControlCols) {
                $extraCols = ', stage_at_change, impact_amount, authorization_code_id,
                               was_outside_window, was_after_close';
                $extraVals = ', ?, ?, ?, ?, ?';
            }

            $stmt = $pdo->prepare("
                INSERT INTO attendance_audit
                    (attendance_id, user_id, work_date, action,
                     old_type, new_type, old_timestamp, new_timestamp,
                     old_work_seconds, new_work_seconds,
                     old_durations_json, new_durations_json,
                     reason, source, performed_by{$extraCols})
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$extraVals})
            ");

            $params = [
                isset($change['attendance_id']) && $change['attendance_id'] ? (int) $change['attendance_id'] : null,
                $userId,
                $workDate,
                strtoupper((string) ($change['action'] ?? 'UPDATE')),
                $change['old_type']      ?? null,
                $change['new_type']      ?? null,
                $change['old_timestamp'] ?? null,
                $change['new_timestamp'] ?? null,
                (int) ($before['work_seconds'] ?? 0),
                (int) ($after['work_seconds'] ?? 0),
                json_encode($before['durations'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($after['durations'] ?? [], JSON_UNESCAPED_UNICODE),
                // ?? antes de comparar: 'reason' es opcional y sin él PHP emitía
                // "Undefined array key" en cada corrección de ponche sin motivo.
                ($change['reason'] ?? null) !== null && $change['reason'] !== ''
                    ? mb_substr((string) $change['reason'], 0, 255)
                    : null,
                $change['source'] ?? null,
                isset($change['performed_by']) && $change['performed_by'] ? (int) $change['performed_by'] : ($_SESSION['user_id'] ?? null),
            ];

            if ($hasControlCols) {
                // El impacto en pesos sale de la MISMA diferencia de segundos que
                // ya se guarda arriba, valorada a la tarifa del colaborador. Es
                // una estimación para el revisor, no el cálculo que paga.
                $impact = $change['impact_amount'] ?? null;
                if ($impact === null && function_exists('timesheetAmountForSeconds')) {
                    $deltaSeconds = (int) ($after['work_seconds'] ?? 0) - (int) ($before['work_seconds'] ?? 0);
                    $impact = timesheetAmountForSeconds($pdo, $userId, $deltaSeconds);
                }

                $params[] = $change['stage_at_change'] ?? null;
                $params[] = $impact !== null ? round((float) $impact, 2) : null;
                $params[] = !empty($change['authorization_code_id']) ? (int) $change['authorization_code_id'] : null;
                $params[] = !empty($change['was_outside_window']) ? 1 : 0;
                $params[] = !empty($change['was_after_close']) ? 1 : 0;
            }

            $stmt->execute($params);

            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('attendanceAuditRecord: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('attendanceAuditFormatSeconds')) {
    /** Formato H:MM, el mismo que usa la vista de ajustes de Vicidial. */
    function attendanceAuditFormatSeconds(int $seconds): string
    {
        $neg = $seconds < 0;
        $seconds = abs($seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        return ($neg ? '-' : '') . $h . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('attendanceAuditDurationDiff')) {
    /**
     * Compara la duración por estado antes y después y devuelve solo lo que cambió.
     *
     * De aquí sale la lectura que pidió el cliente: qué estado tenía cuánto en el
     * ponche y en cuánto quedó tras la corrección.
     *
     * @return array<int,array{slug:string,label:string,old:int,new:int,diff:int}>
     */
    function attendanceAuditDurationDiff(PDO $pdo, ?string $oldJson, ?string $newJson): array
    {
        $old = $oldJson ? (json_decode($oldJson, true) ?: []) : [];
        $new = $newJson ? (json_decode($newJson, true) ?: []) : [];

        $slugs = array_unique(array_merge(array_keys($old), array_keys($new)));
        if (empty($slugs)) {
            return [];
        }

        // Etiquetas legibles de los tipos de punch.
        static $labels = null;
        if ($labels === null) {
            $labels = [];
            try {
                foreach (getAttendanceTypes($pdo, false) as $t) {
                    $labels[sanitizeAttendanceTypeSlug($t['slug'] ?? '')] = $t['label'] ?? $t['slug'];
                }
            } catch (Throwable $e) { /* se usa el slug */ }
        }

        $out = [];
        foreach ($slugs as $slug) {
            $o = (int) ($old[$slug] ?? 0);
            $n = (int) ($new[$slug] ?? 0);
            if ($o === $n) {
                continue;
            }
            $out[] = [
                'slug'  => $slug,
                'label' => $labels[$slug] ?? $slug,
                'old'   => $o,
                'new'   => $n,
                'diff'  => $n - $o,
            ];
        }

        // El cambio más grande primero.
        usort($out, static fn($a, $b) => abs($b['diff']) <=> abs($a['diff']));
        return $out;
    }
}

if (!function_exists('attendanceAuditList')) {
    /**
     * Historial de modificaciones para la vista, con el mismo formato que la de
     * Vicidial: fecha, horas originales, horas ajustadas, diferencia, quién y motivo.
     *
     * @param array{user_id?:int, from?:string, to?:string, limit?:int, only_hour_changes?:bool} $filters
     * @return array<int,array<string,mixed>>
     */
    function attendanceAuditList(PDO $pdo, array $filters = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'a.work_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'a.work_date <= ?';
            $params[] = $filters['to'];
        }
        // Por defecto solo interesa lo que MOVIÓ las horas pagadas.
        if (!empty($filters['only_hour_changes'])) {
            $where[] = 'a.old_work_seconds <> a.new_work_seconds';
        }

        $sql = "
            SELECT a.*,
                   u.full_name  AS employee_name,
                   u.username   AS employee_username,
                   e.employee_code,
                   p.full_name  AS performed_by_name,
                   p.username   AS performed_by_username
            FROM attendance_audit a
            LEFT JOIN users u     ON u.id = a.user_id
            LEFT JOIN employees e ON e.user_id = a.user_id
            LEFT JOIN users p     ON p.id = a.performed_by
        ";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $limit = (int) ($filters['limit'] ?? 200);
        $limit = max(1, min(1000, $limit));
        $sql .= " ORDER BY a.work_date DESC, a.id DESC LIMIT $limit";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('attendanceAuditList: ' . $e->getMessage());
            return [];
        }

        foreach ($rows as &$r) {
            $r['old_work_seconds'] = (int) $r['old_work_seconds'];
            $r['new_work_seconds'] = (int) $r['new_work_seconds'];
            $r['diff_seconds']     = $r['new_work_seconds'] - $r['old_work_seconds'];
            $r['old_formatted']    = attendanceAuditFormatSeconds($r['old_work_seconds']);
            $r['new_formatted']    = attendanceAuditFormatSeconds($r['new_work_seconds']);
            $r['diff_formatted']   = ($r['diff_seconds'] >= 0 ? '+' : '') . attendanceAuditFormatSeconds($r['diff_seconds']);
            $r['state_changes']    = attendanceAuditDurationDiff($pdo, $r['old_durations_json'], $r['new_durations_json']);
        }
        unset($r);

        return $rows;
    }
}

if (!function_exists('attendanceAuditForEmployeeDay')) {
    /**
     * Cambios de UN colaborador en UN día, para pintarlos junto a sus horas.
     */
    function attendanceAuditForEmployeeDay(PDO $pdo, int $userId, string $workDate): array
    {
        return attendanceAuditList($pdo, [
            'user_id' => $userId,
            'from'    => $workDate,
            'to'      => $workDate,
            'limit'   => 50,
        ]);
    }
}
