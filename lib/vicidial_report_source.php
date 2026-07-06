<?php
/**
 * Fuente de datos Vicidial para reportes administrativos (tardanzas, ausencias, …).
 *
 * Regla de negocio (misma que la nómina): para agentes marcados
 * `users.payroll_source = 'vicidial'` la hora de llegada / la presencia se toman
 * del login de Vicidial (`vicidial_agent_timesheet.first_login`), que es
 * automático e inmutable; para TODOS los demás (y como respaldo si un agente
 * Vicidial no tiene login ese día) se usa la marcación de ponche (`attendance`).
 *
 * Interruptor global: system_settings `reports_use_vicidial_source` (default '1').
 * Ponerlo en '0' hace que TODOS los reportes vuelvan a comportarse 100% ponche,
 * sin redeploy.
 *
 * Todo es de SOLO LECTURA y a prueba de fallos: cualquier problema con las tablas
 * Vicidial degrada al comportamiento de ponche de siempre.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/vicidial_api_client.php'; // vicidialComputePaidSeconds / vicidialGetPaidPauseCodes

if (!function_exists('reportsVicidialSourceEnabled')) {
    /**
     * ¿Está activo el uso de Vicidial como fuente en los reportes? (kill-switch)
     */
    function reportsVicidialSourceEnabled(PDO $pdo): bool
    {
        try {
            return (string) getSystemSetting($pdo, 'reports_use_vicidial_source', '1') === '1';
        } catch (Throwable $e) {
            error_log('reportsVicidialSourceEnabled: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getVicidialLoginMap')) {
    /**
     * Primer login de Vicidial por (user_id, fecha) SOLO para agentes cuyo
     * `payroll_source = 'vicidial'`. Devuelve [user_id => [ 'YYYY-MM-DD' => 'Y-m-d H:i:s' ]].
     * Solo incluye filas con first_login no nulo. Si algo falla, arreglo vacío
     * (los reportes caen a ponche).
     */
    function getVicidialLoginMap(PDO $pdo, string $startDate, string $endDate): array
    {
        $map = [];
        try {
            $stmt = $pdo->prepare("
                SELECT t.user_id, t.report_date, t.first_login
                FROM vicidial_agent_timesheet t
                INNER JOIN users u ON u.id = t.user_id
                WHERE t.user_id IS NOT NULL
                  AND COALESCE(u.payroll_source, 'manual') = 'vicidial'
                  AND t.first_login IS NOT NULL
                  AND t.report_date BETWEEN ? AND ?
            ");
            $stmt->execute([$startDate, $endDate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $uid = (int) $r['user_id'];
                $d   = (string) $r['report_date'];
                $login = (string) $r['first_login'];
                // Si dos cuentas de Vicidial mapean al mismo user_id, gana el login más temprano.
                if (!isset($map[$uid][$d]) || $login < $map[$uid][$d]) {
                    $map[$uid][$d] = $login;
                }
            }
        } catch (Throwable $e) {
            error_log('getVicidialLoginMap: ' . $e->getMessage());
        }
        return $map;
    }
}

if (!function_exists('getReportUserMeta')) {
    /**
     * Metadatos (nombre, código, departamento) para una lista de user_ids.
     * Necesario para agentes que se loguearon en Vicidial pero NO ponchron
     * (no aparecen en la consulta de attendance).
     *
     * @return array<int,array{username:string,full_name:string,first_name:?string,last_name:?string,employee_code:?string,department_name:?string}>
     */
    function getReportUserMeta(PDO $pdo, array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return [];
        }
        $meta = [];
        try {
            $ph = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.full_name,
                       e.first_name, e.last_name, e.employee_code,
                       d.name AS department_name
                FROM users u
                LEFT JOIN employees e   ON e.user_id = u.id
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE u.id IN ($ph)
            ");
            $stmt->execute($userIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $meta[(int) $r['id']] = [
                    'username'        => $r['username'] ?? '',
                    'full_name'       => $r['full_name'] ?? '',
                    'first_name'      => $r['first_name'] ?? null,
                    'last_name'       => $r['last_name'] ?? null,
                    'employee_code'   => $r['employee_code'] ?? null,
                    'department_name' => $r['department_name'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            error_log('getReportUserMeta: ' . $e->getMessage());
        }
        return $meta;
    }
}

if (!function_exists('buildArrivalsForRange')) {
    /**
     * Construye la hora de LLEGADA por (usuario, día) para un rango, combinando
     * ponche + Vicidial según la regla de negocio. Para agentes payroll_source=
     * 'vicidial' con login ese día, la llegada = first_login (fuente 'vicidial');
     * en cualquier otro caso = primer ENTRY de ponche (fuente 'ponche').
     *
     * Devuelve una lista plana de filas:
     *   ['user_id','work_date','arrival'(Y-m-d H:i:s),'source'('vicidial'|'ponche'),
     *    'username','full_name','first_name','last_name','employee_code','department_name']
     *
     * @param bool $useVicidial si false, se comporta 100% ponche (igual que antes).
     */
    function buildArrivalsForRange(PDO $pdo, string $startDate, string $endDate, bool $useVicidial): array
    {
        // 1. Primer ENTRY de ponche por usuario/día, con metadatos.
        $arrivals = []; // [uid][date] => record
        try {
            $stmt = $pdo->prepare("
                SELECT
                    a.user_id,
                    DATE(a.timestamp) AS work_date,
                    MIN(a.timestamp)  AS first_entry,
                    u.username, u.full_name,
                    e.first_name, e.last_name, e.employee_code,
                    d.name AS department_name
                FROM attendance a
                INNER JOIN users u      ON u.id = a.user_id
                LEFT JOIN employees e   ON e.user_id = u.id
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE DATE(a.timestamp) BETWEEN ? AND ?
                  AND UPPER(a.type) = 'ENTRY'
                GROUP BY a.user_id, DATE(a.timestamp), u.username, u.full_name,
                         e.first_name, e.last_name, e.employee_code, d.name
            ");
            $stmt->execute([$startDate, $endDate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $uid = (int) $r['user_id'];
                $d   = (string) $r['work_date'];
                $arrivals[$uid][$d] = [
                    'user_id'         => $uid,
                    'work_date'       => $d,
                    'arrival'         => (string) $r['first_entry'],
                    'source'          => 'ponche',
                    'username'        => $r['username'] ?? '',
                    'full_name'       => $r['full_name'] ?? '',
                    'first_name'      => $r['first_name'] ?? null,
                    'last_name'       => $r['last_name'] ?? null,
                    'employee_code'   => $r['employee_code'] ?? null,
                    'department_name' => $r['department_name'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            error_log('buildArrivalsForRange ponche: ' . $e->getMessage());
        }

        // 2. Overlay de Vicidial (solo agentes payroll_source=vicidial).
        if ($useVicidial) {
            $vicMap = getVicidialLoginMap($pdo, $startDate, $endDate);
            if (!empty($vicMap)) {
                $meta = getReportUserMeta($pdo, array_map('intval', array_keys($vicMap)));
                foreach ($vicMap as $uid => $byDate) {
                    $uid = (int) $uid;
                    foreach ($byDate as $d => $login) {
                        if (isset($arrivals[$uid][$d])) {
                            // El agente ponchó Y se logueó: Vicidial es la fuente autoritativa.
                            $arrivals[$uid][$d]['arrival'] = $login;
                            $arrivals[$uid][$d]['source']  = 'vicidial';
                        } elseif (isset($meta[$uid])) {
                            // Se logueó en Vicidial pero no ponchó: agregarlo.
                            $arrivals[$uid][$d] = $meta[$uid] + [
                                'user_id'   => $uid,
                                'work_date' => $d,
                                'arrival'   => $login,
                                'source'    => 'vicidial',
                            ];
                        }
                    }
                }
            }
        }

        // 3. Aplanar.
        $rows = [];
        foreach ($arrivals as $byDate) {
            foreach ($byDate as $rec) {
                $rows[] = $rec;
            }
        }
        return $rows;
    }
}

if (!function_exists('getVicidialTimesheetMap')) {
    /**
     * Hoja de tiempo COMPLETA de Vicidial por (user_id, fecha) SOLO para agentes
     * `payroll_source='vicidial'`, con las horas ya derivadas al modelo de nómina:
     *   - paid_seconds  = NONPAUSE + códigos de pausa pagados (con tope de cordura) — igual que la nómina.
     *   - break_seconds = suma de las pausas NO pagadas (Break, Bao, NXDIAL…).
     *   - pause_breakdown = [código => segundos] tal cual.
     * Devuelve [user_id => [ 'YYYY-MM-DD' => [ first_login, last_activity, total_logged,
     *   nonpause, paid_seconds, capped, break_seconds, pause_breakdown, calls, talk ] ]].
     * A prueba de fallos: si algo falla, arreglo vacío (el reporte cae a ponche).
     */
    function getVicidialTimesheetMap(PDO $pdo, string $startDate, string $endDate): array
    {
        $map = [];
        try {
            $paidCodes = vicidialGetPaidPauseCodes($pdo);
            $paidSet = [];
            foreach ($paidCodes as $c) { $paidSet[strtolower(trim((string) $c))] = true; }
            $cap = (int) round((float) getSystemSetting($pdo, 'vicidial_payroll_daily_cap_hours', 14) * 3600);

            $stmt = $pdo->prepare("
                SELECT t.user_id, t.report_date, t.first_login, t.last_activity,
                       t.total_logged_seconds, t.nonpause_seconds, t.pause_breakdown,
                       t.calls, t.talk_seconds
                FROM vicidial_agent_timesheet t
                INNER JOIN users u ON u.id = t.user_id
                WHERE t.user_id IS NOT NULL
                  AND COALESCE(u.payroll_source, 'manual') = 'vicidial'
                  AND t.report_date BETWEEN ? AND ?
            ");
            $stmt->execute([$startDate, $endDate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $uid = (int) $r['user_id'];
                $d   = (string) $r['report_date'];
                $codes = $r['pause_breakdown'] ? json_decode((string) $r['pause_breakdown'], true) : [];
                if (!is_array($codes)) { $codes = []; }
                $nonpause = max(0, (int) $r['nonpause_seconds']);
                $calc = vicidialComputePaidSeconds($nonpause, $codes, $paidCodes, $cap);

                // break = pausas que NO se pagan; productive = NONPAUSE + pausas pagadas.
                $breakSeconds = 0;
                $productive = [];
                if ($nonpause > 0) { $productive['Productivo'] = $nonpause; }
                foreach ($codes as $code => $sec) {
                    $sec = max(0, (int) $sec);
                    if (isset($paidSet[strtolower(trim((string) $code))])) {
                        if ($sec > 0) { $productive[(string) $code] = $sec; }
                    } else {
                        $breakSeconds += $sec;
                    }
                }

                $map[$uid][$d] = [
                    'first_login'         => $r['first_login'],
                    'last_activity'       => $r['last_activity'],
                    'total_logged'        => max(0, (int) $r['total_logged_seconds']),
                    'nonpause'            => $nonpause,
                    'paid_seconds'        => $calc['paid_seconds'],
                    'capped'              => $calc['capped'],
                    'break_seconds'       => $breakSeconds,
                    'pause_breakdown'     => $codes,
                    'productive_breakdown'=> $productive,
                    'calls'               => max(0, (int) $r['calls']),
                    'talk'                => max(0, (int) $r['talk_seconds']),
                ];
            }
        } catch (Throwable $e) {
            error_log('getVicidialTimesheetMap: ' . $e->getMessage());
        }
        return $map;
    }
}
