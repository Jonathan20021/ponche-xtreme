<?php
/**
 * lib/timesheet_control.php
 *
 * Procedimiento de seguridad de horas y nomina.
 *
 *   "Nadie puede modificar silenciosamente una hora que genere dinero."
 *
 * Este archivo es la puerta por la que tiene que pasar CUALQUIER cambio a las
 * horas de un colaborador. Resuelve tres preguntas:
 *
 *   1. ¿Se puede tocar este dia?        -> timesheetGuard()
 *   2. ¿Cuanto dinero movio el cambio?  -> timesheetAmountForSeconds()
 *   3. ¿Quien tiene que enterarse?      -> timesheetNotifyPayrollImpact()
 *
 * Las siete etapas del dia:
 *
 *   OPEN        el dia esta corriendo, se ajusta libremente dentro de la ventana
 *   IN_REVIEW   supervision lo esta revisando
 *   ADJUSTED    tuvo al menos una correccion registrada
 *   CLOSED      firmado por supervision; solo se toca con reapertura autorizada
 *   LOCKED      el periodo de nomina se lo llevo; ni con codigo, hay que reabrir
 *               el periodo desde Nomina
 *
 * Nada aqui recalcula nomina: el impacto en RD$ es una ESTIMACION para que el
 * revisor vea el peso del cambio. El calculo que paga sigue siendo el de
 * hr/payroll_functions.php.
 *
 * Uso tipico en un punto de edicion:
 *
 *     require_once __DIR__ . '/lib/timesheet_control.php';
 *     $guard = timesheetGuard($pdo, $userId, $workDate, [
 *         'context'   => 'edit_records',
 *         'auth_code' => $_POST['authorization_code'] ?? '',
 *         'reason'    => $reason,
 *     ]);
 *     if (!$guard['allowed']) {
 *         // rechazar con $guard['message']
 *     }
 *     ... aplicar el cambio ...
 *     timesheetAfterChange($pdo, $userId, $workDate, $guard, [...]);
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/work_hours_calculator.php';

if (!function_exists('timesheetTablesReady')) {
    /**
     * El control existe? Si la migracion no corrio, todo el modulo se comporta
     * como si estuviera apagado: el ponche sigue funcionando igual que antes.
     */
    function timesheetTablesReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME IN ('timesheet_day_status','timesheet_stage_events',
                                     'timesheet_exceptions','timesheet_comments')
            ");
            $ready = ((int) $stmt->fetchColumn()) === 4;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('timesheetSettings')) {
    /** @return array<string,string> */
    function timesheetSettings(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings
                                 WHERE setting_key LIKE 'timesheet_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[$row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (Throwable $e) {
            error_log('timesheetSettings: ' . $e->getMessage());
        }
        return $cache;
    }
}

if (!function_exists('timesheetSetting')) {
    function timesheetSetting(PDO $pdo, string $key, string $default = ''): string
    {
        $all = timesheetSettings($pdo);
        $val = $all[$key] ?? '';
        return $val === '' ? $default : $val;
    }
}

if (!function_exists('timesheetControlEnabled')) {
    function timesheetControlEnabled(PDO $pdo): bool
    {
        return timesheetTablesReady($pdo)
            && timesheetSetting($pdo, 'timesheet_control_enabled', '1') === '1';
    }
}

if (!function_exists('timesheetLockEnforced')) {
    /**
     * 0 = modo aviso: el cambio pasa pero queda marcado y alerta.
     * 1 = modo bloqueo: el cambio se rechaza. Es el default.
     */
    function timesheetLockEnforced(PDO $pdo): bool
    {
        return timesheetSetting($pdo, 'timesheet_lock_enforced', '1') === '1';
    }
}

if (!function_exists('timesheetControlStartDate')) {
    function timesheetControlStartDate(PDO $pdo): string
    {
        $d = timesheetSetting($pdo, 'timesheet_control_start_date', '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '2000-01-01';
    }
}

// ---------------------------------------------------------------------------
// Dinero
// ---------------------------------------------------------------------------

if (!function_exists('timesheetHourlyRateDop')) {
    /**
     * Tarifa por hora en DOP del colaborador, con la MISMA regla que usa el
     * resto del sistema (ver lib/delivery_restaurants.php): si cobra fijo, el
     * mensual se divide entre 23.83 dias y 8 horas; si cobra por hora, es su
     * tarifa. Devuelve 0.0 cuando no hay nada configurado — ese caso se levanta
     * como excepcion NO_RATE en vez de mostrar RD$0.00 a secas.
     */
    function timesheetHourlyRateDop(PDO $pdo, int $userId): float
    {
        static $cache = [];
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        $rate = 0.0;
        try {
            // La compensación se lee por el camino canónico (el mismo que usa la
            // nómina), que ya conoce qué columnas existen en esta instalación.
            // Ojo: si esto falla y el error se traga, TODOS salen con tarifa 0 y
            // el sistema levanta un NO_RATE por colaborador. Por eso el fallo se
            // aisla aqui y se cae al respaldo, en vez de abortar la lectura.
            $u = null;
            try {
                if (file_exists(__DIR__ . '/compensation_history.php')) {
                    require_once __DIR__ . '/compensation_history.php';
                    if (function_exists('getCurrentCompensation')) {
                        $candidate = getCurrentCompensation($pdo, $userId);
                        // Una compensacion vacia (todo en 0) no sirve: se prefiere
                        // el respaldo antes que reportar "sin tarifa" por error.
                        $tieneMonto = $candidate && (
                            (float) ($candidate['hourly_rate'] ?? 0) > 0
                            || (float) ($candidate['hourly_rate_dop'] ?? 0) > 0
                            || (float) ($candidate['monthly_salary'] ?? 0) > 0
                            || (float) ($candidate['monthly_salary_dop'] ?? 0) > 0
                        );
                        if ($tieneMonto) {
                            $u = $candidate;
                            $roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                            $roleStmt->execute([$userId]);
                            $u['role'] = $roleStmt->fetchColumn() ?: '';
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('timesheetHourlyRateDop(compensacion canonica): ' . $e->getMessage());
                $u = null;
            }

            if (!$u) {
                // Respaldo: instalación sin el historial de compensación.
                $stmt = $pdo->prepare("
                    SELECT u.role, u.preferred_currency,
                           COALESCE(u.hourly_rate, 0)        AS hourly_rate,
                           COALESCE(u.hourly_rate_dop, 0)    AS hourly_rate_dop,
                           COALESCE(u.monthly_salary, 0)     AS monthly_salary,
                           COALESCE(u.monthly_salary_dop, 0) AS monthly_salary_dop
                    FROM users u
                    WHERE u.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($u) {
                $prefersDop = strtoupper((string) ($u['preferred_currency'] ?? 'DOP')) === 'DOP';
                $monthlyAny = max((float) ($u['monthly_salary'] ?? 0), (float) ($u['monthly_salary_dop'] ?? 0));
                $paymentType = function_exists('resolvePaymentType')
                    ? resolvePaymentType($u['compensation_type'] ?? '', $u['role'] ?? '', $monthlyAny)
                    : ($monthlyAny > 0 ? 'fixed' : 'hourly');

                $mDop = (float) ($u['monthly_salary_dop'] ?? 0);
                $mUsd = (float) ($u['monthly_salary'] ?? 0);
                $hDop = (float) ($u['hourly_rate_dop'] ?? 0);
                $hUsd = (float) ($u['hourly_rate'] ?? 0);

                if ($paymentType === 'fixed') {
                    $monthly = $prefersDop ? $mDop : $mUsd;
                    if ($monthly <= 0) {
                        $monthly = $prefersDop ? $mUsd : $mDop;
                    }
                    // 23.83 dias y 8 horas: el divisor legal, el mismo que usa el
                    // resto del sistema (ver lib/delivery_restaurants.php).
                    $rate = $monthly > 0 ? round($monthly / 23.83 / 8, 2) : 0.0;
                } else {
                    $rate = $prefersDop ? $hDop : $hUsd;
                    if ($rate <= 0) {
                        $rate = $prefersDop ? $hUsd : $hDop;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('timesheetHourlyRateDop: ' . $e->getMessage());
        }

        $cache[$userId] = max(0.0, (float) $rate);
        return $cache[$userId];
    }
}

if (!function_exists('timesheetAmountForSeconds')) {
    /** Estimacion en RD$ de una cantidad de segundos pagables. */
    function timesheetAmountForSeconds(PDO $pdo, int $userId, int $seconds): float
    {
        $rate = timesheetHourlyRateDop($pdo, $userId);
        if ($rate <= 0) {
            return 0.0;
        }
        return round(($seconds / 3600) * $rate, 2);
    }
}

if (!function_exists('timesheetPayrollSource')) {
    /**
     * De donde salen las horas que se le pagan a este colaborador:
     * 'vicidial' o 'manual' (ponche). Es la MISMA regla de hr/payroll.php.
     */
    function timesheetPayrollSource(PDO $pdo, int $userId): string
    {
        static $cache = [];
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }
        $src = 'manual';
        try {
            $s = $pdo->prepare("SELECT COALESCE(payroll_source, 'manual') FROM users WHERE id = ?");
            $s->execute([$userId]);
            $v = strtolower(trim((string) $s->fetchColumn()));
            $src = $v === 'vicidial' ? 'vicidial' : 'manual';
        } catch (Throwable $e) {
            // Instalacion sin la columna: todos por ponche.
        }
        $cache[$userId] = $src;
        return $src;
    }
}

if (!function_exists('timesheetPoncheSeconds')) {
    /** Segundos pagables del PONCHE (tabla attendance) para un dia. */
    function timesheetPoncheSeconds(PDO $pdo, int $userId, string $workDate): int
    {
        try {
            $stmt = $pdo->prepare("
                SELECT id, type, timestamp
                FROM attendance
                WHERE user_id = ? AND DATE(timestamp) = ?
                ORDER BY timestamp ASC, id ASC
            ");
            $stmt->execute([$userId, $workDate]);
            $punches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$punches) {
                return 0;
            }
            $paid = normalizePaidTypeSlugs(getPaidAttendanceTypeSlugs($pdo));
            $calc = calculateWorkSecondsFromPunches($punches, $paid);
            return (int) ($calc['work_seconds'] ?? 0);
        } catch (Throwable $e) {
            error_log('timesheetPoncheSeconds: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('timesheetDaySeconds')) {
    /**
     * Segundos pagables del dia y de DONDE salieron.
     *
     * Un agente de Vicidial genera dinero sin tocar el ponche: sus horas vienen
     * del importador (`vicidial_agent_timesheet`) y del ajuste manual
     * (`vicidial_payroll_adjustments`). Si el control solo mirara `attendance`,
     * esas horas quedarian fuera del procedimiento — que es justo lo que la
     * regla de oro prohibe.
     *
     * Se reusa `vicidialMergeDailySeconds()`, la MISMA funcion que decide la
     * fuente en la nomina, con su fecha de corte. Asi el panel no puede
     * divergir de lo que se paga.
     *
     * @return array{seconds:int, source:string} source: 'vicidial'|'ponche'
     */
    function timesheetDaySeconds(PDO $pdo, int $userId, string $workDate): array
    {
        $poncheSeconds = timesheetPoncheSeconds($pdo, $userId, $workDate);

        if (timesheetPayrollSource($pdo, $userId) !== 'vicidial') {
            return ['seconds' => $poncheSeconds, 'source' => 'ponche'];
        }

        try {
            require_once __DIR__ . '/vicidial_api_client.php';
            if (!function_exists('vicidialGetPaidSecondsByDate') || !function_exists('vicidialMergeDailySeconds')) {
                return ['seconds' => $poncheSeconds, 'source' => 'ponche'];
            }

            $vd = vicidialGetPaidSecondsByDate($pdo, $userId, $workDate, $workDate);
            $effective = function_exists('getVicidialPayrollEffectiveDate')
                ? getVicidialPayrollEffectiveDate($pdo)
                : null;

            $punchDaily = $poncheSeconds > 0 ? [$workDate => $poncheSeconds] : [];
            $merged = vicidialMergeDailySeconds($punchDaily, $vd, $effective);

            return [
                'seconds' => (int) ($merged['by_date'][$workDate] ?? 0),
                'source'  => (string) ($merged['source'][$workDate] ?? 'ponche'),
            ];
        } catch (Throwable $e) {
            error_log('timesheetDaySeconds(vicidial): ' . $e->getMessage());
            return ['seconds' => $poncheSeconds, 'source' => 'ponche'];
        }
    }
}

if (!function_exists('timesheetDayWorkSeconds')) {
    /** Segundos pagables del dia con la logica canonica (la que paga la nomina). */
    function timesheetDayWorkSeconds(PDO $pdo, int $userId, string $workDate): int
    {
        return timesheetDaySeconds($pdo, $userId, $workDate)['seconds'];
    }
}

if (!function_exists('timesheetVicidialUsersForDate')) {
    /**
     * Colaboradores que generaron horas por VICIDIAL en una fecha: los que el
     * importador trajo y los que tienen un ajuste manual ese dia.
     *
     * @return array<int,int> user_ids
     */
    function timesheetVicidialUsersForDate(PDO $pdo, string $workDate): array
    {
        $ids = [];
        try {
            $s = $pdo->prepare("
                SELECT DISTINCT t.user_id
                FROM vicidial_agent_timesheet t
                JOIN users u ON u.id = t.user_id
                WHERE t.report_date = ?
                  AND LOWER(COALESCE(u.payroll_source, 'manual')) = 'vicidial'
            ");
            $s->execute([$workDate]);
            $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            // Sin integracion de Vicidial en esta instalacion.
            return [];
        }

        try {
            $s = $pdo->prepare("
                SELECT DISTINCT a.user_id
                FROM vicidial_payroll_adjustments a
                WHERE a.work_date = ?
            ");
            $s->execute([$workDate]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $ids[] = (int) $id;
            }
        } catch (Throwable $e) {
            // Sin tabla de ajustes: solo lo importado.
        }

        return array_values(array_unique($ids));
    }
}

// ---------------------------------------------------------------------------
// Etapa del dia
// ---------------------------------------------------------------------------

if (!function_exists('timesheetGetDay')) {
    /**
     * Estado del dia. Si nunca se toco, devuelve un OPEN sintetico (o CLOSED si
     * la fecha es anterior al arranque del procedimiento).
     *
     * @return array{status:string,row:?array,exists:bool}
     */
    function timesheetGetDay(PDO $pdo, int $userId, string $workDate): array
    {
        if (!timesheetTablesReady($pdo)) {
            return ['status' => 'OPEN', 'row' => null, 'exists' => false];
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM timesheet_day_status WHERE user_id = ? AND work_date = ? LIMIT 1");
            $stmt->execute([$userId, $workDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return ['status' => (string) $row['status'], 'row' => $row, 'exists' => true];
            }
        } catch (Throwable $e) {
            error_log('timesheetGetDay: ' . $e->getMessage());
        }

        $implicit = $workDate < timesheetControlStartDate($pdo) ? 'CLOSED' : 'OPEN';
        return ['status' => $implicit, 'row' => null, 'exists' => false];
    }
}

if (!function_exists('timesheetEnsureDay')) {
    /** Crea la fila del dia si no existe y devuelve su estado. */
    function timesheetEnsureDay(PDO $pdo, int $userId, string $workDate): array
    {
        $day = timesheetGetDay($pdo, $userId, $workDate);
        if ($day['exists'] || !timesheetTablesReady($pdo)) {
            return $day;
        }
        try {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO timesheet_day_status (user_id, work_date, status)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, $workDate, $day['status']]);
        } catch (Throwable $e) {
            error_log('timesheetEnsureDay: ' . $e->getMessage());
        }
        return timesheetGetDay($pdo, $userId, $workDate);
    }
}

// ---------------------------------------------------------------------------
// Ventana de ajuste
// ---------------------------------------------------------------------------

if (!function_exists('timesheetWindowDeadline')) {
    /**
     * Hasta cuando se puede ajustar un dia sin pedir codigo.
     * Configurable: hora limite + cuantos dias despues.
     */
    function timesheetWindowDeadline(PDO $pdo, string $workDate): int
    {
        $hour = timesheetSetting($pdo, 'timesheet_adjust_deadline_hour', '11:00');
        if (!preg_match('/^\d{1,2}:\d{2}$/', $hour)) {
            $hour = '11:00';
        }
        $days = (int) timesheetSetting($pdo, 'timesheet_adjust_deadline_days', '1');
        $days = max(0, min(30, $days));

        $base = strtotime($workDate . ' ' . $hour . ':00');
        if ($base === false) {
            return PHP_INT_MAX;
        }
        return strtotime("+{$days} day", $base);
    }
}

if (!function_exists('timesheetWindowLabel')) {
    function timesheetWindowLabel(PDO $pdo, string $workDate): string
    {
        $ts = timesheetWindowDeadline($pdo, $workDate);
        if ($ts === PHP_INT_MAX) {
            return 'sin limite';
        }
        return date('d/m/Y', $ts) . ' a las ' . date('H:i', $ts);
    }
}

if (!function_exists('timesheetIsWithinWindow')) {
    function timesheetIsWithinWindow(PDO $pdo, string $workDate, ?int $now = null): bool
    {
        return ($now ?? time()) <= timesheetWindowDeadline($pdo, $workDate);
    }
}

// ---------------------------------------------------------------------------
// El candado
// ---------------------------------------------------------------------------

if (!function_exists('timesheetGuard')) {
    /**
     * Decide si este cambio puede entrar y con que marcas queda.
     *
     * @param array{
     *   context?:string, auth_code?:string, reason?:string,
     *   performed_by?:?int, role?:?string
     * } $opts
     * @return array{
     *   allowed:bool, message:string, stage:string, outside_window:bool,
     *   after_close:bool, requires_code:bool, code_id:?int, enforced:bool,
     *   deadline_label:string
     * }
     */
    function timesheetGuard(PDO $pdo, int $userId, string $workDate, array $opts = []): array
    {
        $context = (string) ($opts['context'] ?? 'edit_records');
        $code    = trim((string) ($opts['auth_code'] ?? ''));
        $actor   = isset($opts['performed_by']) && $opts['performed_by']
            ? (int) $opts['performed_by']
            : (int) ($_SESSION['user_id'] ?? 0);

        $result = [
            'allowed'        => true,
            'message'        => '',
            'stage'          => 'OPEN',
            'outside_window' => false,
            'after_close'    => false,
            'requires_code'  => false,
            'requires_ceo_code' => false,
            'code_id'        => null,
            'enforced'       => true,
            'deadline_label' => '',
        ];

        if (!timesheetControlEnabled($pdo)) {
            return $result;
        }
        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            return $result;
        }

        $enforced = timesheetLockEnforced($pdo);
        $result['enforced'] = $enforced;
        $result['context']  = $context;
        $result['deadline_label'] = timesheetWindowLabel($pdo, $workDate);

        $day   = timesheetGetDay($pdo, $userId, $workDate);
        $stage = $day['status'];
        $result['stage'] = $stage;

        $result['outside_window'] = !timesheetIsWithinWindow($pdo, $workDate);
        $result['after_close']    = in_array($stage, ['CLOSED', 'LOCKED'], true);

        // 1. El periodo de nomina ya se llevo el dia: no hay codigo que valga.
        //    Hay que reabrir el PERIODO desde Nomina, con su propio rastro.
        if ($stage === 'LOCKED') {
            $result['allowed'] = !$enforced;
            $result['message'] = 'Este dia pertenece a un periodo de nomina bloqueado. '
                . 'Para modificarlo hay que reabrir el periodo desde Nomina, con autorizacion de Gerencia.';
            return $result;
        }

        // 2. Dia cerrado: solo con reapertura formal.
        if ($stage === 'CLOSED') {
            $result['allowed'] = !$enforced;
            $result['message'] = 'El dia ' . date('d/m/Y', strtotime($workDate)) . ' esta CERRADO. '
                . 'Para corregirlo hay que reabrirlo desde el Control de Horas '
                . '(requiere codigo de autorizacion y queda registrado).';
            return $result;
        }

        // 3. Fuera de la ventana de ajuste: se exige codigo.
        $requireCode = timesheetSetting($pdo, 'timesheet_require_code_after_window', '1') === '1';
        if ($result['outside_window'] && $requireCode) {
            $result['requires_code'] = true;

            // Quien tenga el permiso explicito no necesita codigo.
            $bypass = $actor > 0 && function_exists('userHasPermission')
                && userHasPermission('timesheet_adjust_outside', $opts['role'] ?? ($_SESSION['role'] ?? null));

            if ($bypass) {
                $result['requires_code'] = false;
                return $result;
            }

            // Que codigo abre un dia vencido. El semanal es de uso general (lo
            // tiene quien autoriza entradas tempranas y horas extra, y dura 7
            // dias); corregir una hora vencida mueve dinero que ya iba a la
            // nomina, asi que el CEO pidio que eso solo lo abra un codigo
            // emitido por el, de un solo uso. Si el modo esta apagado, se vuelve
            // al comportamiento anterior.
            // El file_exists no sobra: estos archivos se suben a mano a dos
            // servidores. Si llega este sin timesheet_override.php, el ponche
            // entero moriria con un fatal; asi solo se comporta como antes.
            $soloGerencia = false;
            if (file_exists(__DIR__ . '/timesheet_override.php')) {
                require_once __DIR__ . '/timesheet_override.php';
                $soloGerencia = function_exists('timesheetOverrideEnabled')
                    && timesheetOverrideEnabled($pdo);
            }
            $result['requires_ceo_code'] = $soloGerencia;

            if ($code === '') {
                $result['allowed'] = !$enforced;
                $result['message'] = 'La ventana de ajuste de este dia vencio el '
                    . $result['deadline_label'] . '. '
                    . ($soloGerencia
                        ? 'Hace falta un codigo de autorizacion emitido por Gerencia (el codigo semanal no sirve para esto).'
                        : 'Se requiere un codigo de autorizacion.');
                return $result;
            }

            if ($soloGerencia) {
                $validation = timesheetOverrideValidate($pdo, $code);
            } else {
                require_once __DIR__ . '/authorization_functions.php';
                $validation = validateAuthorizationCode($pdo, $code, $context, $actor);
            }

            if (empty($validation['valid'])) {
                $result['allowed'] = !$enforced;
                $result['message'] = 'Codigo de autorizacion invalido: '
                    . ($validation['error'] ?? ($validation['message'] ?? 'no valido'));
                return $result;
            }
            $result['code_id'] = isset($validation['code_id']) ? (int) $validation['code_id'] : null;
        }

        return $result;
    }
}

if (!function_exists('timesheetConsumeCode')) {
    /**
     * Quema el codigo con el que entro el cambio: incrementa su contador de usos
     * (por eso una autorizacion de un solo uso no sirve dos veces), deja el
     * rastro en authorization_code_logs y cierra la solicitud que lo origino.
     *
     * Se ejecuta UNA sola vez por codigo y request: edit_record.php evalua DOS
     * dias cuando un punch cambia de fecha, y una sola correccion no puede
     * consumir dos autorizaciones.
     */
    function timesheetConsumeCode(PDO $pdo, array $guard, array $ctx = []): void
    {
        $codeId = (int) ($guard['code_id'] ?? 0);
        if ($codeId <= 0) {
            return;
        }

        static $consumidos = [];
        if (isset($consumidos[$codeId])) {
            return;
        }
        $consumidos[$codeId] = true;

        $actor = (int) ($ctx['performed_by'] ?? ($_SESSION['user_id'] ?? 0));

        try {
            require_once __DIR__ . '/authorization_functions.php';
            if (function_exists('logAuthorizationCodeUsage')) {
                logAuthorizationCodeUsage(
                    $pdo,
                    $codeId,
                    $actor,
                    (string) ($guard['context'] ?? 'edit_records'),
                    null,
                    (string) ($ctx['reference_table'] ?? 'attendance'),
                    [
                        'accion'    => $ctx['source'] ?? 'ajuste de horas fuera de ventana',
                        'user_id'   => $ctx['user_id'] ?? null,
                        'work_date' => $ctx['work_date'] ?? null,
                        'motivo'    => $ctx['reason'] ?? null,
                    ]
                );
            }
        } catch (Throwable $e) {
            error_log('timesheetConsumeCode(log): ' . $e->getMessage());
        }

        try {
            if (file_exists(__DIR__ . '/timesheet_override.php')) {
                require_once __DIR__ . '/timesheet_override.php';
                if (function_exists('timesheetOverrideMarkUsed')) {
                    timesheetOverrideMarkUsed($pdo, $codeId);
                }
            }
        } catch (Throwable $e) {
            error_log('timesheetConsumeCode(solicitud): ' . $e->getMessage());
        }
    }
}

if (!function_exists('timesheetAfterChange')) {
    /**
     * Se llama DESPUES de aplicar el cambio. Mueve el dia a ADJUSTED, cuenta el
     * ajuste y dispara la alerta si el cambio toco algo que ya iba a pagarse.
     *
     * @param array $guard resultado de timesheetGuard()
     * @param array{
     *   reason?:string, source?:string, impact_amount?:float,
     *   old_seconds?:int, new_seconds?:int, performed_by?:?int
     * } $change
     */
    function timesheetAfterChange(PDO $pdo, int $userId, string $workDate, array $guard, array $change = []): void
    {
        if (!timesheetControlEnabled($pdo)) {
            return;
        }

        $actor = isset($change['performed_by']) && $change['performed_by']
            ? (int) $change['performed_by']
            : (int) ($_SESSION['user_id'] ?? 0);

        $impact = isset($change['impact_amount'])
            ? (float) $change['impact_amount']
            : timesheetAmountForSeconds(
                $pdo,
                $userId,
                (int) ($change['new_seconds'] ?? 0) - (int) ($change['old_seconds'] ?? 0)
            );

        try {
            $day = timesheetGetDay($pdo, $userId, $workDate);
            if (!$day['exists']) {
                $pdo->prepare("
                    INSERT IGNORE INTO timesheet_day_status (user_id, work_date, status, adjustments_count)
                    VALUES (?, ?, 'ADJUSTED', 1)
                ")->execute([$userId, $workDate]);
            } else {
                // Un dia CLOSED que recibio un cambio (modo aviso) NO vuelve a
                // ADJUSTED solo: seguiria pareciendo normal. Se queda cerrado y
                // el cambio se levanta como excepcion.
                $newStage = in_array($day['status'], ['CLOSED', 'LOCKED'], true)
                    ? $day['status']
                    : 'ADJUSTED';
                $pdo->prepare("
                    UPDATE timesheet_day_status
                    SET status = ?, adjustments_count = adjustments_count + 1
                    WHERE user_id = ? AND work_date = ?
                ")->execute([$newStage, $userId, $workDate]);
            }
        } catch (Throwable $e) {
            error_log('timesheetAfterChange(estado): ' . $e->getMessage());
        }

        timesheetLogStage($pdo, [
            'scope'       => 'DAY',
            'user_id'     => $userId,
            'work_date'   => $workDate,
            'from_stage'  => $guard['stage'] ?? null,
            'to_stage'    => 'ADJUSTED',
            'reason'      => $change['reason'] ?? null,
            'authorization_code_id' => $guard['code_id'] ?? null,
            'amount_dop'  => $impact,
            'performed_by'=> $actor,
        ]);

        // El codigo se quema aqui, con el cambio ya aplicado: si el guard lo
        // hubiera consumido al validar, un intento fallido mas adelante habria
        // dejado a la persona sin autorizacion y sin correccion.
        timesheetConsumeCode($pdo, $guard, [
            'performed_by'    => $actor,
            'user_id'         => $userId,
            'work_date'       => $workDate,
            'reason'          => $change['reason'] ?? null,
            'source'          => $change['source'] ?? null,
            'reference_table' => $change['reference_table'] ?? 'attendance',
        ]);

        // Excepciones que nacen del cambio mismo
        if (!empty($guard['after_close'])) {
            timesheetRaiseException($pdo, [
                'user_id'    => $userId,
                'work_date'  => $workDate,
                'type'       => 'CHANGE_AFTER_CLOSE',
                'severity'   => 'CRITICAL',
                'title'      => 'Cambio sobre un dia ya cerrado',
                'detail'     => 'Motivo: ' . ($change['reason'] ?? 'sin motivo') . '. Origen: ' . ($change['source'] ?? 'n/d'),
                'amount_dop' => $impact,
            ]);
        } elseif (!empty($guard['outside_window'])) {
            timesheetRaiseException($pdo, [
                'user_id'    => $userId,
                'work_date'  => $workDate,
                'type'       => 'OUTSIDE_WINDOW',
                'severity'   => 'HIGH',
                'title'      => 'Ajuste fuera de la ventana de tiempo',
                'detail'     => 'La ventana vencio el ' . ($guard['deadline_label'] ?? '') . '. Motivo: ' . ($change['reason'] ?? 'sin motivo'),
                'amount_dop' => $impact,
            ]);
        }

        $limitAmount = (float) timesheetSetting($pdo, 'timesheet_exception_impact_amount', '500');
        if ($limitAmount > 0 && abs($impact) >= $limitAmount) {
            timesheetRaiseException($pdo, [
                'user_id'    => $userId,
                'work_date'  => $workDate,
                'type'       => 'HIGH_IMPACT',
                'severity'   => 'HIGH',
                'title'      => 'Ajuste de alto impacto economico',
                'detail'     => 'El cambio movio RD$ ' . number_format(abs($impact), 2)
                    . ' (limite configurado: RD$ ' . number_format($limitAmount, 2) . ').',
                'amount_dop' => $impact,
            ]);
        }

        timesheetNotifyPayrollImpact($pdo, [
            'user_id'    => $userId,
            'work_date'  => $workDate,
            'impact'     => $impact,
            'guard'      => $guard,
            'reason'     => $change['reason'] ?? '',
            'source'     => $change['source'] ?? '',
            'performed_by' => $actor,
        ]);
    }
}

// ---------------------------------------------------------------------------
// Cierre y reapertura
// ---------------------------------------------------------------------------

if (!function_exists('timesheetLogStage')) {
    function timesheetLogStage(PDO $pdo, array $data): ?int
    {
        if (!timesheetTablesReady($pdo)) {
            return null;
        }
        try {
            $actorId = isset($data['performed_by']) && $data['performed_by']
                ? (int) $data['performed_by']
                : (int) ($_SESSION['user_id'] ?? 0);

            $actorName = $data['performed_by_name'] ?? ($_SESSION['full_name'] ?? null);
            if (!$actorName && $actorId > 0) {
                $s = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                $s->execute([$actorId]);
                $actorName = $s->fetchColumn() ?: null;
            }

            $stmt = $pdo->prepare("
                INSERT INTO timesheet_stage_events
                    (scope, user_id, work_date, payroll_period_id, from_stage, to_stage,
                     reason, authorization_code_id, amount_dop, days_affected,
                     performed_by, performed_by_name, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['scope'] ?? 'DAY',
                !empty($data['user_id']) ? (int) $data['user_id'] : null,
                $data['work_date'] ?? null,
                !empty($data['payroll_period_id']) ? (int) $data['payroll_period_id'] : null,
                $data['from_stage'] ?? null,
                (string) ($data['to_stage'] ?? 'UNKNOWN'),
                isset($data['reason']) && $data['reason'] !== '' ? mb_substr((string) $data['reason'], 0, 255) : null,
                !empty($data['authorization_code_id']) ? (int) $data['authorization_code_id'] : null,
                isset($data['amount_dop']) ? round((float) $data['amount_dop'], 2) : null,
                isset($data['days_affected']) ? (int) $data['days_affected'] : null,
                $actorId > 0 ? $actorId : null,
                $actorName ? mb_substr((string) $actorName, 0, 120) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('timesheetLogStage: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('timesheetCloseDay')) {
    /**
     * Cierra el dia de un colaborador. Falla si quedan excepciones abiertas,
     * porque cerrar con excepciones abiertas es exactamente lo que el
     * procedimiento quiere impedir.
     *
     * @return array{ok:bool, message:string}
     */
    function timesheetCloseDay(PDO $pdo, int $userId, string $workDate, array $opts = []): array
    {
        if (!timesheetControlEnabled($pdo)) {
            return ['ok' => false, 'message' => 'El control de horas esta desactivado.'];
        }

        $day = timesheetGetDay($pdo, $userId, $workDate);
        if (in_array($day['status'], ['CLOSED', 'LOCKED'], true)) {
            return ['ok' => false, 'message' => 'El dia ya estaba cerrado.'];
        }

        if (empty($opts['ignore_exceptions'])) {
            $open = timesheetOpenExceptions($pdo, $workDate, $workDate, $userId);
            if (!empty($open)) {
                return [
                    'ok' => false,
                    'message' => 'No se puede cerrar: hay ' . count($open) . ' excepcion(es) abierta(s) en este dia.',
                ];
            }
        }

        $seconds = timesheetDayWorkSeconds($pdo, $userId, $workDate);
        $amount  = timesheetAmountForSeconds($pdo, $userId, $seconds);
        $actor   = (int) ($opts['performed_by'] ?? ($_SESSION['user_id'] ?? 0));

        try {
            $pdo->prepare("
                INSERT INTO timesheet_day_status
                    (user_id, work_date, status, work_seconds, amount_dop, closed_by, closed_at)
                VALUES (?, ?, 'CLOSED', ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'CLOSED', work_seconds = VALUES(work_seconds),
                    amount_dop = VALUES(amount_dop), closed_by = VALUES(closed_by),
                    closed_at = NOW()
            ")->execute([$userId, $workDate, $seconds, $amount, $actor ?: null]);
        } catch (Throwable $e) {
            error_log('timesheetCloseDay: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'No se pudo cerrar el dia: ' . $e->getMessage()];
        }

        timesheetLogStage($pdo, [
            'scope'      => 'DAY',
            'user_id'    => $userId,
            'work_date'  => $workDate,
            'from_stage' => $day['status'],
            'to_stage'   => 'CLOSED',
            'reason'     => $opts['reason'] ?? null,
            'amount_dop' => $amount,
            'performed_by' => $actor,
        ]);

        return ['ok' => true, 'message' => 'Dia cerrado.'];
    }
}

if (!function_exists('timesheetReopenDay')) {
    /**
     * Reapertura: exige permiso, motivo y codigo de autorizacion. Es un evento
     * propio en la bitacora, nunca "una edicion mas".
     *
     * @return array{ok:bool, message:string}
     */
    function timesheetReopenDay(PDO $pdo, int $userId, string $workDate, array $opts = []): array
    {
        if (!timesheetControlEnabled($pdo)) {
            return ['ok' => false, 'message' => 'El control de horas esta desactivado.'];
        }

        $reason = trim((string) ($opts['reason'] ?? ''));
        if ($reason === '') {
            return ['ok' => false, 'message' => 'El motivo de la reapertura es obligatorio.'];
        }

        $day = timesheetGetDay($pdo, $userId, $workDate);
        if ($day['status'] === 'LOCKED') {
            return [
                'ok' => false,
                'message' => 'El dia pertenece a un periodo de nomina bloqueado. Primero hay que reabrir el periodo desde Nomina.',
            ];
        }
        if ($day['status'] !== 'CLOSED') {
            return ['ok' => false, 'message' => 'El dia no esta cerrado.'];
        }

        $actor = (int) ($opts['performed_by'] ?? ($_SESSION['user_id'] ?? 0));

        if (function_exists('userHasPermission') && !userHasPermission('timesheet_reopen_day')) {
            return ['ok' => false, 'message' => 'No tienes autorizacion para reabrir dias cerrados.'];
        }

        $codeId = null;
        $code = trim((string) ($opts['auth_code'] ?? ''));
        if (timesheetSetting($pdo, 'timesheet_require_code_after_window', '1') === '1') {
            if ($code === '') {
                return ['ok' => false, 'message' => 'Se requiere un codigo de autorizacion para reabrir el dia.'];
            }
            require_once __DIR__ . '/authorization_functions.php';
            $validation = validateAuthorizationCode($pdo, $code, 'edit_records', $actor);
            if (empty($validation['valid'])) {
                return [
                    'ok' => false,
                    'message' => 'Codigo invalido: ' . ($validation['error'] ?? ($validation['message'] ?? '')),
                ];
            }
            $codeId = isset($validation['code_id']) ? (int) $validation['code_id'] : null;
        }

        try {
            $pdo->prepare("
                UPDATE timesheet_day_status
                SET status = 'IN_REVIEW', reopened_by = ?, reopened_at = NOW(),
                    reopen_reason = ?, reopen_code_id = ?
                WHERE user_id = ? AND work_date = ?
            ")->execute([$actor ?: null, mb_substr($reason, 0, 255), $codeId, $userId, $workDate]);
        } catch (Throwable $e) {
            error_log('timesheetReopenDay: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'No se pudo reabrir: ' . $e->getMessage()];
        }

        timesheetLogStage($pdo, [
            'scope'      => 'DAY',
            'user_id'    => $userId,
            'work_date'  => $workDate,
            'from_stage' => 'CLOSED',
            'to_stage'   => 'IN_REVIEW',
            'reason'     => $reason,
            'authorization_code_id' => $codeId,
            'performed_by' => $actor,
        ]);

        if ($codeId && function_exists('logAuthorizationCodeUsage')) {
            logAuthorizationCodeUsage($pdo, $codeId, $actor, 'edit_records', null, 'timesheet_day_status', [
                'accion'    => 'reapertura de dia',
                'user_id'   => $userId,
                'work_date' => $workDate,
                'motivo'    => $reason,
            ]);
        }

        // Reabrir un dia cerrado es exactamente el evento que Gerencia quiere ver.
        timesheetNotify($pdo, [
            'type'     => 'timesheet_reopen',
            'severity' => 'HIGH',
            'title'    => 'Dia de ponche reabierto',
            'message'  => 'Se reabrio el ' . date('d/m/Y', strtotime($workDate)) . ' de '
                . timesheetUserName($pdo, $userId) . '. Motivo: ' . $reason,
            'url'      => 'hr/timesheet_control.php?fecha=' . $workDate,
            'dedupe'   => 'reopen-' . $userId . '-' . $workDate . '-' . time(),
        ]);

        return ['ok' => true, 'message' => 'Dia reabierto. Queda registrado en la bitacora.'];
    }
}

// ---------------------------------------------------------------------------
// Comentarios (solo se agregan)
// ---------------------------------------------------------------------------

if (!function_exists('timesheetAddComment')) {
    function timesheetAddComment(PDO $pdo, array $data): ?int
    {
        if (!timesheetTablesReady($pdo)) {
            return null;
        }
        $comment = trim((string) ($data['comment'] ?? ''));
        if ($comment === '') {
            return null;
        }
        try {
            $actorId = (int) ($data['created_by'] ?? ($_SESSION['user_id'] ?? 0));
            $stmt = $pdo->prepare("
                INSERT INTO timesheet_comments
                    (user_id, work_date, payroll_period_id, scope, exception_id,
                     comment, created_by, created_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                (int) ($data['user_id'] ?? 0),
                $data['work_date'] ?? null,
                !empty($data['payroll_period_id']) ? (int) $data['payroll_period_id'] : null,
                $data['scope'] ?? 'DAY',
                !empty($data['exception_id']) ? (int) $data['exception_id'] : null,
                $comment,
                $actorId ?: null,
                mb_substr((string) ($data['created_by_name'] ?? ($_SESSION['full_name'] ?? '')), 0, 120) ?: null,
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('timesheetAddComment: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('timesheetGetComments')) {
    function timesheetGetComments(PDO $pdo, int $userId, string $workDate): array
    {
        if (!timesheetTablesReady($pdo)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT c.*, COALESCE(c.created_by_name, u.full_name) AS author
                FROM timesheet_comments c
                LEFT JOIN users u ON u.id = c.created_by
                WHERE c.user_id = ? AND c.work_date = ?
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([$userId, $workDate]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

// ---------------------------------------------------------------------------
// Excepciones
// ---------------------------------------------------------------------------

if (!function_exists('timesheetRaiseException')) {
    /**
     * Levanta (o reactiva) una excepcion. La clave unica es
     * (colaborador, dia, tipo): la misma condicion no se duplica.
     */
    function timesheetRaiseException(PDO $pdo, array $data): ?int
    {
        if (!timesheetTablesReady($pdo)) {
            return null;
        }
        $userId = (int) ($data['user_id'] ?? 0);
        $date   = (string) ($data['work_date'] ?? '');
        $type   = strtoupper((string) ($data['type'] ?? ''));
        if ($userId <= 0 || $type === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $severity = strtoupper((string) ($data['severity'] ?? 'MEDIUM'));
        if (!in_array($severity, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true)) {
            $severity = 'MEDIUM';
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO timesheet_exceptions
                    (user_id, work_date, exception_type, severity, title, detail, amount_dop, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'OPEN')
                ON DUPLICATE KEY UPDATE
                    severity = VALUES(severity),
                    title = VALUES(title),
                    detail = VALUES(detail),
                    amount_dop = VALUES(amount_dop)
            ");
            $stmt->execute([
                $userId,
                $date,
                $type,
                $severity,
                mb_substr((string) ($data['title'] ?? $type), 0, 180),
                $data['detail'] ?? null,
                isset($data['amount_dop']) ? round((float) $data['amount_dop'], 2) : null,
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('timesheetRaiseException: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('timesheetClearException')) {
    /** Baja automatica: la condicion ya no se cumple. Solo toca las OPEN. */
    function timesheetClearException(PDO $pdo, int $userId, string $workDate, string $type): void
    {
        if (!timesheetTablesReady($pdo)) {
            return;
        }
        try {
            $pdo->prepare("
                UPDATE timesheet_exceptions
                SET status = 'RESOLVED', resolved_at = NOW(),
                    resolution_note = 'Se corrigio la condicion que la origino.'
                WHERE user_id = ? AND work_date = ? AND exception_type = ? AND status = 'OPEN'
            ")->execute([$userId, $workDate, strtoupper($type)]);
        } catch (Throwable $e) {
            error_log('timesheetClearException: ' . $e->getMessage());
        }
    }
}

if (!function_exists('timesheetResolveException')) {
    function timesheetResolveException(PDO $pdo, int $id, string $note, bool $dismiss = false, ?int $actor = null): array
    {
        if (!timesheetTablesReady($pdo)) {
            return ['ok' => false, 'message' => 'Control de horas no instalado.'];
        }
        $note = trim($note);
        if ($note === '') {
            return ['ok' => false, 'message' => 'La nota de resolucion es obligatoria.'];
        }
        $actor = $actor ?? (int) ($_SESSION['user_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                UPDATE timesheet_exceptions
                SET status = ?, resolved_by = ?, resolved_at = NOW(), resolution_note = ?
                WHERE id = ? AND status = 'OPEN'
            ");
            $stmt->execute([$dismiss ? 'DISMISSED' : 'RESOLVED', $actor ?: null, mb_substr($note, 0, 255), $id]);
            if ($stmt->rowCount() === 0) {
                return ['ok' => false, 'message' => 'La excepcion ya estaba resuelta.'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        // El comentario queda en la bitacora no eliminable.
        try {
            $row = $pdo->prepare("SELECT user_id, work_date FROM timesheet_exceptions WHERE id = ?");
            $row->execute([$id]);
            if ($exc = $row->fetch(PDO::FETCH_ASSOC)) {
                timesheetAddComment($pdo, [
                    'user_id'      => (int) $exc['user_id'],
                    'work_date'    => $exc['work_date'],
                    'scope'        => 'EXCEPTION',
                    'exception_id' => $id,
                    'comment'      => ($dismiss ? '[Descartada] ' : '[Resuelta] ') . $note,
                    'created_by'   => $actor,
                ]);
            }
        } catch (Throwable $e) {
            error_log('timesheetResolveException(comentario): ' . $e->getMessage());
        }

        return ['ok' => true, 'message' => $dismiss ? 'Excepcion descartada.' : 'Excepcion resuelta.'];
    }
}

if (!function_exists('timesheetOpenExceptions')) {
    function timesheetOpenExceptions(PDO $pdo, string $from, string $to, ?int $userId = null): array
    {
        if (!timesheetTablesReady($pdo)) {
            return [];
        }
        try {
            $sql = "
                SELECT e.*, u.full_name, u.username
                FROM timesheet_exceptions e
                LEFT JOIN users u ON u.id = e.user_id
                WHERE e.status = 'OPEN' AND e.work_date BETWEEN ? AND ?
            ";
            $params = [$from, $to];
            if ($userId !== null && $userId > 0) {
                $sql .= " AND e.user_id = ?";
                $params[] = $userId;
            }
            $sql .= " ORDER BY FIELD(e.severity,'CRITICAL','HIGH','MEDIUM','LOW'), e.work_date DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('timesheetOpenExceptions: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('timesheetStoreDayValue')) {
    /**
     * Guarda cuanto vale el dia (segundos pagables + RD$) en timesheet_day_status.
     *
     * No cambia la etapa: si la fila no existe nace en OPEN (o CLOSED si es
     * anterior al arranque del procedimiento) y si existe se respeta la que tenga.
     * En un dia CLOSED/LOCKED que ya trae valor NO se pisa nada: ese numero es la
     * foto del momento del cierre y es lo que auditoria revisa.
     */
    function timesheetStoreDayValue(PDO $pdo, int $userId, string $workDate, int $seconds): void
    {
        if (!timesheetTablesReady($pdo) || $userId <= 0) {
            return;
        }
        try {
            $amount  = timesheetAmountForSeconds($pdo, $userId, $seconds);
            $initial = $workDate < timesheetControlStartDate($pdo) ? 'CLOSED' : 'OPEN';

            $pdo->prepare("
                INSERT INTO timesheet_day_status
                    (user_id, work_date, status, work_seconds, amount_dop)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    work_seconds = IF(status IN ('CLOSED','LOCKED') AND work_seconds IS NOT NULL,
                                      work_seconds, VALUES(work_seconds)),
                    amount_dop   = IF(status IN ('CLOSED','LOCKED') AND amount_dop IS NOT NULL,
                                      amount_dop, VALUES(amount_dop))
            ")->execute([$userId, $workDate, $initial, $seconds, $amount]);
        } catch (Throwable $e) {
            error_log('timesheetStoreDayValue: ' . $e->getMessage());
        }
    }
}

if (!function_exists('timesheetDetectExceptions')) {
    /**
     * Barrido del dia. Idempotente: se puede correr las veces que sea.
     * Lo llama el cron diario y tambien el panel cuando alguien lo abre.
     *
     * @return int excepciones abiertas tras el barrido
     */
    function timesheetDetectExceptions(PDO $pdo, string $workDate, ?int $onlyUserId = null): int
    {
        if (!timesheetControlEnabled($pdo)) {
            return 0;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            return 0;
        }

        $overHours     = (float) timesheetSetting($pdo, 'timesheet_exception_over_hours', '8');
        $criticalHours = (float) timesheetSetting($pdo, 'timesheet_exception_critical_hours', '12');
        $isPastDay     = $workDate < date('Y-m-d');

        try {
            $sql = "
                SELECT a.user_id, a.type, a.timestamp, a.id
                FROM attendance a
                WHERE DATE(a.timestamp) = ?
            ";
            $params = [$workDate];
            if ($onlyUserId) {
                $sql .= " AND a.user_id = ?";
                $params[] = $onlyUserId;
            }
            $sql .= " ORDER BY a.user_id, a.timestamp ASC, a.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('timesheetDetectExceptions: ' . $e->getMessage());
            return 0;
        }

        $byUser = [];
        foreach ($rows as $r) {
            $byUser[(int) $r['user_id']][] = $r;
        }

        // Agentes que generaron horas por Vicidial ese dia: entran a la revision
        // aunque no hayan tocado el ponche.
        if ($onlyUserId === null || $onlyUserId <= 0) {
            foreach (timesheetVicidialUsersForDate($pdo, $workDate) as $vid) {
                if (!isset($byUser[$vid])) {
                    $byUser[$vid] = [];
                }
            }
        } elseif (!isset($byUser[$onlyUserId])
                  && in_array($onlyUserId, timesheetVicidialUsersForDate($pdo, $workDate), true)) {
            $byUser[$onlyUserId] = [];
        }

        foreach ($byUser as $userId => $punches) {
            $day     = timesheetDaySeconds($pdo, (int) $userId, $workDate);
            $seconds = $day['seconds'];
            $hours   = $seconds / 3600;

            // Se deja escrito cuanto vale el dia AUNQUE siga abierto. Finanzas lee
            // estas columnas para su tarjeta de impacto economico y no tiene el
            // calculador de horas: si solo se escribieran al cerrar, la pantalla de
            // Finanzas mostraria RD$ 0.00 todos los dias hasta el cierre.
            // En un dia ya cerrado NO se pisa el valor: esa es la foto del cierre.
            timesheetStoreDayValue($pdo, (int) $userId, $workDate, $seconds);

            // 1. Jornada abierta: el ultimo punch no es la salida. Solo aplica a
            //    quien se mide por ponche — en Vicidial no hay "salida" que marcar,
            //    el login/logout lo trae el importador.
            $lastType = !empty($punches) ? strtoupper((string) end($punches)['type']) : '';
            if ($isPastDay && $day['source'] === 'ponche' && $lastType !== '' && $lastType !== 'EXIT') {
                timesheetRaiseException($pdo, [
                    'user_id'   => $userId,
                    'work_date' => $workDate,
                    'type'      => 'OPEN_SHIFT',
                    'severity'  => 'HIGH',
                    'title'     => 'Jornada sin marcacion de salida',
                    'detail'    => 'La ultima marcacion del dia fue "' . $lastType . '". Sin salida, las horas del dia no son confiables.',
                    'amount_dop'=> timesheetAmountForSeconds($pdo, $userId, $seconds),
                ]);
            } else {
                timesheetClearException($pdo, $userId, $workDate, 'OPEN_SHIFT');
            }

            // 2. Jornadas largas
            if ($criticalHours > 0 && $hours >= $criticalHours) {
                timesheetRaiseException($pdo, [
                    'user_id'   => $userId,
                    'work_date' => $workDate,
                    'type'      => 'CRITICAL_HOURS',
                    'severity'  => 'CRITICAL',
                    'title'     => 'Jornada de ' . number_format($hours, 2) . ' horas',
                    'detail'    => 'Supera el limite critico de ' . $criticalHours . ' h configurado.',
                    'amount_dop'=> timesheetAmountForSeconds($pdo, $userId, $seconds),
                ]);
                timesheetClearException($pdo, $userId, $workDate, 'OVER_HOURS');
            } elseif ($overHours > 0 && $hours > $overHours) {
                timesheetRaiseException($pdo, [
                    'user_id'   => $userId,
                    'work_date' => $workDate,
                    'type'      => 'OVER_HOURS',
                    'severity'  => 'MEDIUM',
                    'title'     => 'Jornada de ' . number_format($hours, 2) . ' horas',
                    'detail'    => 'Supera las ' . $overHours . ' h de jornada ordinaria.',
                    'amount_dop'=> timesheetAmountForSeconds($pdo, $userId, $seconds),
                ]);
                timesheetClearException($pdo, $userId, $workDate, 'CRITICAL_HOURS');
            } else {
                timesheetClearException($pdo, $userId, $workDate, 'OVER_HOURS');
                timesheetClearException($pdo, $userId, $workDate, 'CRITICAL_HOURS');
            }

            // 3. Horas sin tarifa: el costo de este dia sale en 0 y nadie lo nota.
            if ($seconds > 0 && timesheetHourlyRateDop($pdo, $userId) <= 0) {
                timesheetRaiseException($pdo, [
                    'user_id'   => $userId,
                    'work_date' => $workDate,
                    'type'      => 'NO_RATE',
                    'severity'  => 'MEDIUM',
                    'title'     => 'Colaborador sin tarifa ni sueldo configurado',
                    'detail'    => 'Trabajo ' . number_format($hours, 2) . ' h y el sistema no puede valorarlas. El costo de este dia aparece en RD$ 0.00.',
                ]);
            } else {
                timesheetClearException($pdo, $userId, $workDate, 'NO_RATE');
            }
        }

        // 4. Dias vencidos sin cerrar: la etapa se atraso.
        if ($isPastDay && !timesheetIsWithinWindow($pdo, $workDate)) {
            foreach (array_keys($byUser) as $userId) {
                $day = timesheetGetDay($pdo, (int) $userId, $workDate);
                if (!in_array($day['status'], ['CLOSED', 'LOCKED'], true)) {
                    timesheetRaiseException($pdo, [
                        'user_id'   => (int) $userId,
                        'work_date' => $workDate,
                        'type'      => 'NOT_CLOSED',
                        'severity'  => 'MEDIUM',
                        'title'     => 'Dia vencido sin cerrar',
                        'detail'    => 'La ventana de ajuste vencio el ' . timesheetWindowLabel($pdo, $workDate) . ' y el dia sigue en ' . $day['status'] . '.',
                    ]);
                } else {
                    timesheetClearException($pdo, (int) $userId, $workDate, 'NOT_CLOSED');
                }
            }
        }

        return count(timesheetOpenExceptions($pdo, $workDate, $workDate, $onlyUserId));
    }
}

// ---------------------------------------------------------------------------
// Impacto economico del dia
// ---------------------------------------------------------------------------

if (!function_exists('timesheetDailyImpact')) {
    /**
     * Las cuatro cifras del panel: cuanto se genero, que se modifico, que falta
     * cerrar y que requiere revision.
     *
     * @return array{
     *   date:string, generated_amount:float, generated_seconds:int,
     *   people:int, modified_amount:float, adjustments:int,
     *   pending_days:int, closed_days:int, exceptions:int, exceptions_critical:int
     * }
     */
    function timesheetDailyImpact(PDO $pdo, string $workDate): array
    {
        $out = [
            'date'                => $workDate,
            'generated_amount'    => 0.0,
            'generated_seconds'   => 0,
            'people'              => 0,
            'modified_amount'     => 0.0,
            'adjustments'         => 0,
            'pending_days'        => 0,
            'closed_days'         => 0,
            'exceptions'          => 0,
            'exceptions_critical' => 0,
        ];

        try {
            $stmt = $pdo->prepare("
                SELECT a.user_id, a.type, a.timestamp, a.id
                FROM attendance a
                WHERE DATE(a.timestamp) = ?
                ORDER BY a.user_id, a.timestamp ASC, a.id ASC
            ");
            $stmt->execute([$workDate]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $byUser = [];
            foreach ($rows as $r) {
                $byUser[(int) $r['user_id']][] = $r;
            }

            // Los agentes de Vicidial generan dinero sin tocar el ponche: si no se
            // suman aqui, el "generado" del dia sale corto y nadie lo nota.
            foreach (timesheetVicidialUsersForDate($pdo, $workDate) as $vid) {
                if (!isset($byUser[$vid])) {
                    $byUser[$vid] = [];
                }
            }

            foreach (array_keys($byUser) as $userId) {
                $sec = timesheetDayWorkSeconds($pdo, (int) $userId, $workDate);
                $out['generated_seconds'] += $sec;
                $out['generated_amount']  += timesheetAmountForSeconds($pdo, (int) $userId, $sec);
            }
            $out['people'] = count($byUser);
        } catch (Throwable $e) {
            error_log('timesheetDailyImpact(generado): ' . $e->getMessage());
        }

        // Lo modificado HOY sobre cualquier fecha: es lo que importa vigilar.
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS n, COALESCE(SUM(impact_amount), 0) AS total
                FROM attendance_audit
                WHERE DATE(created_at) = ?
            ");
            $stmt->execute([$workDate]);
            if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out['adjustments']     = (int) $r['n'];
                $out['modified_amount'] = round((float) $r['total'], 2);
            }
        } catch (Throwable $e) {
            // attendance_audit sin la columna impact_amount todavia: no es fatal.
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_audit WHERE DATE(created_at) = ?");
                $stmt->execute([$workDate]);
                $out['adjustments'] = (int) $stmt->fetchColumn();
            } catch (Throwable $e2) {
                error_log('timesheetDailyImpact(modificado): ' . $e2->getMessage());
            }
        }

        if (timesheetTablesReady($pdo)) {
            try {
                $stmt = $pdo->prepare("
                    SELECT
                        SUM(CASE WHEN status IN ('CLOSED','LOCKED') THEN 1 ELSE 0 END) AS cerrados,
                        SUM(CASE WHEN status NOT IN ('CLOSED','LOCKED') THEN 1 ELSE 0 END) AS abiertos
                    FROM timesheet_day_status
                    WHERE work_date = ?
                ");
                $stmt->execute([$workDate]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['closed_days']  = (int) ($r['cerrados'] ?? 0);
                // Los colaboradores con punches y SIN fila de estado tambien estan pendientes.
                $out['pending_days'] = max(0, $out['people'] - $out['closed_days']);
            } catch (Throwable $e) {
                error_log('timesheetDailyImpact(dias): ' . $e->getMessage());
            }

            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS n,
                           SUM(CASE WHEN severity IN ('CRITICAL','HIGH') THEN 1 ELSE 0 END) AS graves
                    FROM timesheet_exceptions
                    WHERE work_date = ? AND status = 'OPEN'
                ");
                $stmt->execute([$workDate]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['exceptions']          = (int) ($r['n'] ?? 0);
                $out['exceptions_critical'] = (int) ($r['graves'] ?? 0);
            } catch (Throwable $e) {
                error_log('timesheetDailyImpact(excepciones): ' . $e->getMessage());
            }
        }

        $out['generated_amount'] = round($out['generated_amount'], 2);
        return $out;
    }
}

if (!function_exists('timesheetDayRows')) {
    /**
     * Una fila por colaborador con marcaciones ese dia: horas, dinero, etapa,
     * ajustes y excepciones. Es lo que pinta el panel.
     */
    function timesheetDayRows(PDO $pdo, string $workDate): array
    {
        $out = [];
        try {
            $stmt = $pdo->prepare("
                SELECT a.user_id, a.id, a.type, a.timestamp,
                       u.full_name, u.username, u.role
                FROM attendance a
                JOIN users u ON u.id = a.user_id
                WHERE DATE(a.timestamp) = ?
                ORDER BY u.full_name, a.timestamp ASC, a.id ASC
            ");
            $stmt->execute([$workDate]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('timesheetDayRows: ' . $e->getMessage());
            return [];
        }

        $byUser = [];
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'user_id'   => $uid,
                    'full_name' => $r['full_name'] ?: $r['username'],
                    'username'  => $r['username'],
                    'punches'   => [],
                ];
            }
            $byUser[$uid]['punches'][] = $r;
        }

        // Agentes cuyas horas vienen de Vicidial: no tienen punches, pero SI
        // generan dinero. Sin esto no aparecerian en el panel y su dia nunca se
        // cerraria, que es el hueco por el que se cuela un cambio silencioso.
        $vicidialIds = timesheetVicidialUsersForDate($pdo, $workDate);
        $faltantes = array_values(array_diff($vicidialIds, array_keys($byUser)));
        if (!empty($faltantes)) {
            try {
                $ph = implode(',', array_fill(0, count($faltantes), '?'));
                $s = $pdo->prepare("SELECT id, full_name, username FROM users WHERE id IN ($ph)");
                $s->execute($faltantes);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
                    $byUser[(int) $u['id']] = [
                        'user_id'   => (int) $u['id'],
                        'full_name' => $u['full_name'] ?: $u['username'],
                        'username'  => $u['username'],
                        'punches'   => [],
                    ];
                }
            } catch (Throwable $e) {
                error_log('timesheetDayRows(vicidial): ' . $e->getMessage());
            }
        }

        // Estados y excepciones en dos consultas, no una por colaborador.
        $states = [];
        $excCount = [];
        if (timesheetTablesReady($pdo)) {
            try {
                $s = $pdo->prepare("SELECT * FROM timesheet_day_status WHERE work_date = ?");
                $s->execute([$workDate]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $states[(int) $row['user_id']] = $row;
                }
            } catch (Throwable $e) { /* estado por defecto */ }

            try {
                $s = $pdo->prepare("
                    SELECT user_id, COUNT(*) AS n,
                           SUM(CASE WHEN severity IN ('CRITICAL','HIGH') THEN 1 ELSE 0 END) AS graves
                    FROM timesheet_exceptions
                    WHERE work_date = ? AND status = 'OPEN'
                    GROUP BY user_id
                ");
                $s->execute([$workDate]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $excCount[(int) $row['user_id']] = ['n' => (int) $row['n'], 'graves' => (int) $row['graves']];
                }
            } catch (Throwable $e) { /* sin excepciones */ }
        }

        // Ajustes del dia por colaborador
        $adjust = [];
        try {
            $s = $pdo->prepare("
                SELECT user_id, COUNT(*) AS n
                FROM attendance_audit
                WHERE work_date = ?
                GROUP BY user_id
            ");
            $s->execute([$workDate]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $adjust[(int) $row['user_id']] = (int) $row['n'];
            }
        } catch (Throwable $e) { /* sin bitacora */ }

        $implicitStatus = $workDate < timesheetControlStartDate($pdo) ? 'CLOSED' : 'OPEN';

        foreach ($byUser as $uid => $data) {
            $day     = timesheetDaySeconds($pdo, $uid, $workDate);
            $seconds = $day['seconds'];
            $state   = $states[$uid] ?? null;
            $last    = !empty($data['punches']) ? end($data['punches']) : [];

            $out[] = [
                'user_id'      => $uid,
                'full_name'    => $data['full_name'],
                'username'     => $data['username'],
                'source'       => $day['source'],
                'work_seconds' => $seconds,
                'hours'        => round($seconds / 3600, 2),
                'rate'         => timesheetHourlyRateDop($pdo, $uid),
                'amount'       => timesheetAmountForSeconds($pdo, $uid, $seconds),
                'status'       => $state['status'] ?? $implicitStatus,
                'closed_by'    => $state['closed_by'] ?? null,
                'closed_at'    => $state['closed_at'] ?? null,
                'reopened_at'  => $state['reopened_at'] ?? null,
                'adjustments'  => $adjust[$uid] ?? 0,
                'exceptions'   => $excCount[$uid]['n'] ?? 0,
                'exceptions_severe' => $excCount[$uid]['graves'] ?? 0,
                'first_punch'  => $data['punches'][0]['timestamp'] ?? null,
                'last_punch'   => $last['timestamp'] ?? null,
                'last_type'    => $last['type'] ?? null,
                'punch_count'  => count($data['punches']),
            ];
        }

        return $out;
    }
}

// ---------------------------------------------------------------------------
// Avisos
// ---------------------------------------------------------------------------

if (!function_exists('timesheetUserName')) {
    function timesheetUserName(PDO $pdo, int $userId): string
    {
        static $cache = [];
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }
        try {
            $s = $pdo->prepare("SELECT COALESCE(full_name, username) FROM users WHERE id = ?");
            $s->execute([$userId]);
            $cache[$userId] = (string) ($s->fetchColumn() ?: ('usuario #' . $userId));
        } catch (Throwable $e) {
            $cache[$userId] = 'usuario #' . $userId;
        }
        return $cache[$userId];
    }
}

if (!function_exists('timesheetNotify')) {
    /** Campana + correo, segun lo configurado. Nunca lanza. */
    function timesheetNotify(PDO $pdo, array $data): void
    {
        if (timesheetSetting($pdo, 'timesheet_alerts_enabled', '1') !== '1') {
            return;
        }

        try {
            if (file_exists(__DIR__ . '/notifications.php')) {
                require_once __DIR__ . '/notifications.php';
                if (function_exists('notifyCreate')) {
                    notifyCreate($pdo, [
                        'type'     => $data['type'] ?? 'timesheet',
                        'severity' => $data['severity'] ?? 'HIGH',
                        'title'    => $data['title'] ?? 'Control de horas',
                        'message'  => $data['message'] ?? '',
                        'url'      => $data['url'] ?? null,
                        'roles'    => timesheetSetting($pdo, 'timesheet_alert_roles', 'Admin,GeneralManager,HR'),
                        'dedupe_key' => $data['dedupe'] ?? null,
                        'requires_action' => true,
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('timesheetNotify(campana): ' . $e->getMessage());
        }

        $recipients = trim(timesheetSetting($pdo, 'timesheet_alert_recipients', ''));
        if ($recipients === '' || empty($data['email'])) {
            return;
        }

        try {
            if (file_exists(__DIR__ . '/email_functions.php')) {
                require_once __DIR__ . '/email_functions.php';
                if (function_exists('sendEmail')) {
                    foreach (preg_split('/[,;]+/', $recipients) as $to) {
                        $to = trim($to);
                        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                            sendEmail($to, (string) ($data['title'] ?? 'Control de horas'), (string) $data['email']);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('timesheetNotify(correo): ' . $e->getMessage());
        }
    }
}

if (!function_exists('timesheetNotifyPayrollImpact')) {
    /**
     * Alerta cuando un cambio impacta dinero que ya iba camino a la nomina:
     * dia cerrado, dia fuera de ventana, o monto sobre el limite.
     */
    function timesheetNotifyPayrollImpact(PDO $pdo, array $ctx): void
    {
        $guard  = $ctx['guard'] ?? [];
        $impact = (float) ($ctx['impact'] ?? 0);
        $limit  = (float) timesheetSetting($pdo, 'timesheet_exception_impact_amount', '500');

        $afterClose = !empty($guard['after_close']);
        $outside    = !empty($guard['outside_window']);
        $bigMoney   = $limit > 0 && abs($impact) >= $limit;

        if (!$afterClose && !$outside && !$bigMoney) {
            return;
        }

        $userId   = (int) ($ctx['user_id'] ?? 0);
        $workDate = (string) ($ctx['work_date'] ?? '');
        $who      = timesheetUserName($pdo, $userId);
        $actor    = (int) ($ctx['performed_by'] ?? 0);
        $actorName = $actor > 0 ? timesheetUserName($pdo, $actor) : 'usuario del sistema';

        $motivo = trim((string) ($ctx['reason'] ?? '')) ?: 'sin motivo registrado';
        $signo  = $impact >= 0 ? '+' : '-';
        $monto  = 'RD$ ' . $signo . number_format(abs($impact), 2);

        $titulo = $afterClose
            ? 'Cambio de horas sobre un dia CERRADO'
            : ($outside ? 'Ajuste de horas fuera de la ventana' : 'Ajuste de horas de alto impacto');

        $mensaje = $actorName . ' modifico el ' . date('d/m/Y', strtotime($workDate))
            . ' de ' . $who . '. Impacto estimado: ' . $monto . '. Motivo: ' . $motivo . '.';

        $html = '<p><strong>' . htmlspecialchars($titulo) . '</strong></p>'
            . '<p>' . htmlspecialchars($mensaje) . '</p>'
            . '<p>Etapa del dia al momento del cambio: <strong>'
            . htmlspecialchars((string) ($guard['stage'] ?? 'OPEN')) . '</strong></p>';

        timesheetNotify($pdo, [
            'type'     => 'timesheet_impact',
            'severity' => $afterClose ? 'CRITICAL' : 'HIGH',
            'title'    => $titulo,
            'message'  => $mensaje,
            'email'    => $html,
            'url'      => 'hr/timesheet_control.php?fecha=' . $workDate,
            'dedupe'   => 'impact-' . $userId . '-' . $workDate . '-' . substr(md5($mensaje), 0, 8),
        ]);
    }
}

// ---------------------------------------------------------------------------
// Etapas del PERIODO de nomina
// ---------------------------------------------------------------------------

if (!function_exists('timesheetPeriodReadiness')) {
    /**
     * ¿El periodo esta listo para avanzar? Devuelve exactamente que falta y de
     * quien depende, que es lo que el panel tiene que mostrar.
     *
     * @return array{
     *   ok:bool, period:?array, open_days:int, open_exceptions:int,
     *   blockers:string[], total_days:int, closed_days:int
     * }
     */
    function timesheetPeriodReadiness(PDO $pdo, int $periodId): array
    {
        $out = [
            'ok' => false, 'period' => null, 'open_days' => 0, 'open_exceptions' => 0,
            'blockers' => [], 'total_days' => 0, 'closed_days' => 0,
        ];

        try {
            $s = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
            $s->execute([$periodId]);
            $period = $s->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $out['blockers'][] = 'No se pudo leer el periodo: ' . $e->getMessage();
            return $out;
        }

        if (!$period) {
            $out['blockers'][] = 'El periodo no existe.';
            return $out;
        }
        $out['period'] = $period;

        if (!timesheetControlEnabled($pdo)) {
            $out['ok'] = true;
            return $out;
        }

        $from = (string) $period['start_date'];
        $to   = (string) $period['end_date'];

        // Dias con actividad dentro del periodo que NO estan cerrados. Cuenta las
        // DOS fuentes: si solo mirara el ponche, un periodo entero de agentes de
        // Vicidial se consolidaria sin que nadie hubiera revisado un solo dia.
        try {
            $s = $pdo->prepare("
                SELECT COUNT(*) FROM (
                    SELECT a.user_id, DATE(a.timestamp) AS d
                    FROM attendance a
                    LEFT JOIN timesheet_day_status t
                           ON t.user_id = a.user_id AND t.work_date = DATE(a.timestamp)
                    WHERE DATE(a.timestamp) BETWEEN ? AND ?
                      AND (t.status IS NULL OR t.status NOT IN ('CLOSED','LOCKED'))
                    GROUP BY a.user_id, DATE(a.timestamp)
                ) x
            ");
            $s->execute([$from, $to]);
            $out['open_days'] = (int) $s->fetchColumn();

            try {
                $v = $pdo->prepare("
                    SELECT COUNT(*) FROM (
                        SELECT vt.user_id, vt.report_date AS d
                        FROM vicidial_agent_timesheet vt
                        JOIN users u ON u.id = vt.user_id
                        LEFT JOIN timesheet_day_status t
                               ON t.user_id = vt.user_id AND t.work_date = vt.report_date
                        LEFT JOIN attendance a
                               ON a.user_id = vt.user_id AND DATE(a.timestamp) = vt.report_date
                        WHERE vt.report_date BETWEEN ? AND ?
                          AND LOWER(COALESCE(u.payroll_source, 'manual')) = 'vicidial'
                          AND (t.status IS NULL OR t.status NOT IN ('CLOSED','LOCKED'))
                          AND a.id IS NULL   -- los que ya conto el ponche no se duplican
                        GROUP BY vt.user_id, vt.report_date
                    ) y
                ");
                $v->execute([$from, $to]);
                $out['open_days'] += (int) $v->fetchColumn();
            } catch (Throwable $e) {
                // Instalacion sin integracion de Vicidial: solo cuenta el ponche.
            }

            $s = $pdo->prepare("
                SELECT COUNT(*) FROM timesheet_day_status
                WHERE work_date BETWEEN ? AND ? AND status IN ('CLOSED','LOCKED')
            ");
            $s->execute([$from, $to]);
            $out['closed_days'] = (int) $s->fetchColumn();
            $out['total_days']  = $out['closed_days'] + $out['open_days'];
        } catch (Throwable $e) {
            $out['blockers'][] = 'No se pudo revisar el cierre de dias: ' . $e->getMessage();
        }

        try {
            $s = $pdo->prepare("
                SELECT COUNT(*) FROM timesheet_exceptions
                WHERE work_date BETWEEN ? AND ? AND status = 'OPEN'
            ");
            $s->execute([$from, $to]);
            $out['open_exceptions'] = (int) $s->fetchColumn();
        } catch (Throwable $e) {
            $out['blockers'][] = 'No se pudieron revisar las excepciones: ' . $e->getMessage();
        }

        if ($out['open_days'] > 0) {
            $out['blockers'][] = $out['open_days'] . ' dia(s)-colaborador sin cerrar.';
        }
        if ($out['open_exceptions'] > 0) {
            $out['blockers'][] = $out['open_exceptions'] . ' excepcion(es) sin resolver.';
        }

        $out['ok'] = empty($out['blockers']);
        return $out;
    }
}

if (!function_exists('timesheetConsolidatePeriod')) {
    /**
     * Etapa 5: la nomina toma el periodo. Bloquea todos sus dias (LOCKED) para
     * que ninguna correccion posterior entre por la puerta de atras.
     */
    function timesheetConsolidatePeriod(PDO $pdo, int $periodId, array $opts = []): array
    {
        $ready = timesheetPeriodReadiness($pdo, $periodId);
        if (!$ready['period']) {
            return ['ok' => false, 'message' => 'El periodo no existe.'];
        }
        if (!$ready['ok'] && empty($opts['force'])) {
            return ['ok' => false, 'message' => 'No se puede consolidar: ' . implode(' ', $ready['blockers'])];
        }

        $period = $ready['period'];
        $actor  = (int) ($opts['performed_by'] ?? ($_SESSION['user_id'] ?? 0));

        try {
            $pdo->prepare("
                UPDATE timesheet_day_status
                SET status = 'LOCKED', payroll_period_id = ?
                WHERE work_date BETWEEN ? AND ? AND status = 'CLOSED'
            ")->execute([$periodId, $period['start_date'], $period['end_date']]);

            $pdo->prepare("
                UPDATE payroll_periods
                SET consolidated_by = ?, consolidated_at = NOW(), control_locked = 1
                WHERE id = ?
            ")->execute([$actor ?: null, $periodId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo consolidar: ' . $e->getMessage()];
        }

        timesheetLogStage($pdo, [
            'scope' => 'PERIOD',
            'payroll_period_id' => $periodId,
            'from_stage' => 'CLOSED',
            'to_stage'   => 'CONSOLIDATED',
            'reason'     => $opts['reason'] ?? null,
            'days_affected' => $ready['closed_days'],
            'performed_by'  => $actor,
        ]);

        return ['ok' => true, 'message' => 'Periodo consolidado y bloqueado.'];
    }
}

if (!function_exists('timesheetSignAudit')) {
    /** Etapa 6: auditoria firma o devuelve el periodo. */
    function timesheetSignAudit(PDO $pdo, int $periodId, bool $signed, string $note, array $opts = []): array
    {
        $note = trim($note);
        if ($note === '') {
            return ['ok' => false, 'message' => 'La nota de auditoria es obligatoria.'];
        }
        $actor = (int) ($opts['performed_by'] ?? ($_SESSION['user_id'] ?? 0));

        try {
            $s = $pdo->prepare("SELECT consolidated_at FROM payroll_periods WHERE id = ?");
            $s->execute([$periodId]);
            $consolidated = $s->fetchColumn();
            if (!$consolidated) {
                return ['ok' => false, 'message' => 'El periodo todavia no esta consolidado por Nomina.'];
            }

            $pdo->prepare("
                UPDATE payroll_periods
                SET audited_by = ?, audited_at = NOW(), audit_note = ?, audit_result = ?
                WHERE id = ?
            ")->execute([$actor ?: null, mb_substr($note, 0, 255), $signed ? 'SIGNED' : 'RETURNED', $periodId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo registrar la auditoria: ' . $e->getMessage()];
        }

        timesheetLogStage($pdo, [
            'scope' => 'PERIOD',
            'payroll_period_id' => $periodId,
            'from_stage' => 'CONSOLIDATED',
            'to_stage'   => $signed ? 'AUDITED' : 'RETURNED',
            'reason'     => $note,
            'performed_by' => $actor,
        ]);

        return ['ok' => true, 'message' => $signed ? 'Auditoria firmada.' : 'Periodo devuelto a Nomina.'];
    }
}

if (!function_exists('timesheetApprovePayment')) {
    /** Etapa 7: solo con auditoria firmada. */
    function timesheetApprovePayment(PDO $pdo, int $periodId, array $opts = []): array
    {
        $actor = (int) ($opts['performed_by'] ?? ($_SESSION['user_id'] ?? 0));

        try {
            $s = $pdo->prepare("SELECT audit_result, total_net FROM payroll_periods WHERE id = ?");
            $s->execute([$periodId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['ok' => false, 'message' => 'El periodo no existe.'];
            }
            if (($row['audit_result'] ?? 'PENDING') !== 'SIGNED') {
                return ['ok' => false, 'message' => 'No se puede aprobar el pago sin la firma de auditoria.'];
            }

            $pdo->prepare("
                UPDATE payroll_periods
                SET status = 'APPROVED', approved_by = ?, approved_at = NOW()
                WHERE id = ?
            ")->execute([$actor ?: null, $periodId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo aprobar: ' . $e->getMessage()];
        }

        timesheetLogStage($pdo, [
            'scope' => 'PERIOD',
            'payroll_period_id' => $periodId,
            'from_stage' => 'AUDITED',
            'to_stage'   => 'APPROVED',
            'reason'     => $opts['reason'] ?? null,
            'amount_dop' => isset($row['total_net']) ? (float) $row['total_net'] : null,
            'performed_by' => $actor,
        ]);

        return ['ok' => true, 'message' => 'Pago aprobado.'];
    }
}

if (!function_exists('timesheetReopenPeriod')) {
    /** Reapertura del periodo: devuelve los dias a CLOSED y reinicia auditoria. */
    function timesheetReopenPeriod(PDO $pdo, int $periodId, string $reason, array $opts = []): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'El motivo de la reapertura es obligatorio.'];
        }
        if (function_exists('userHasPermission') && !userHasPermission('payroll_approve_payment')) {
            return ['ok' => false, 'message' => 'Solo Gerencia puede reabrir un periodo de nomina.'];
        }

        $actor = (int) ($opts['performed_by'] ?? ($_SESSION['user_id'] ?? 0));

        try {
            $s = $pdo->prepare("SELECT start_date, end_date, status FROM payroll_periods WHERE id = ?");
            $s->execute([$periodId]);
            $period = $s->fetch(PDO::FETCH_ASSOC);
            if (!$period) {
                return ['ok' => false, 'message' => 'El periodo no existe.'];
            }
            if (($period['status'] ?? '') === 'PAID') {
                return ['ok' => false, 'message' => 'El periodo ya fue pagado: no se puede reabrir.'];
            }

            $upd = $pdo->prepare("
                UPDATE timesheet_day_status
                SET status = 'CLOSED', payroll_period_id = NULL
                WHERE payroll_period_id = ? AND status = 'LOCKED'
            ");
            $upd->execute([$periodId]);
            $days = $upd->rowCount();

            $pdo->prepare("
                UPDATE payroll_periods
                SET control_locked = 0, audit_result = 'PENDING', audited_by = NULL,
                    audited_at = NULL, reopened_by = ?, reopened_at = NOW(), reopen_reason = ?
                WHERE id = ?
            ")->execute([$actor ?: null, mb_substr($reason, 0, 255), $periodId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo reabrir: ' . $e->getMessage()];
        }

        timesheetLogStage($pdo, [
            'scope' => 'PERIOD',
            'payroll_period_id' => $periodId,
            'from_stage' => 'LOCKED',
            'to_stage'   => 'REOPENED',
            'reason'     => $reason,
            'days_affected' => $days ?? null,
            'performed_by'  => $actor,
        ]);

        timesheetNotify($pdo, [
            'type'     => 'payroll_reopen',
            'severity' => 'CRITICAL',
            'title'    => 'Periodo de nomina reabierto',
            'message'  => 'Se reabrio el periodo #' . $periodId . '. Motivo: ' . $reason
                . '. La auditoria vuelve a estar pendiente.',
            'email'    => '<p>Se reabrio el periodo de nomina #' . $periodId . '.</p><p>Motivo: '
                . htmlspecialchars($reason) . '</p><p>La firma de auditoria quedo anulada.</p>',
            'url'      => 'hr/payroll.php',
            'dedupe'   => 'period-reopen-' . $periodId . '-' . time(),
        ]);

        return ['ok' => true, 'message' => 'Periodo reabierto. La auditoria quedo pendiente de nuevo.'];
    }
}

if (!function_exists('timesheetTrace')) {
    /**
     * Trazabilidad de un dia: ponche original, ajustes, eventos de etapa,
     * comentarios y eliminaciones. Es lo que se muestra en el expediente.
     */
    function timesheetTrace(PDO $pdo, int $userId, string $workDate): array
    {
        $trace = ['original' => [], 'current' => [], 'audit' => [], 'stages' => [], 'comments' => [], 'voided' => []];

        $safe = static function (callable $fn) {
            try { return $fn(); } catch (Throwable $e) { return []; }
        };

        $trace['original'] = $safe(function () use ($pdo, $userId, $workDate) {
            $s = $pdo->prepare("SELECT * FROM attendance_original WHERE user_id = ? AND work_date = ? ORDER BY original_timestamp");
            $s->execute([$userId, $workDate]);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });

        $trace['current'] = $safe(function () use ($pdo, $userId, $workDate) {
            $s = $pdo->prepare("SELECT id, type, timestamp FROM attendance WHERE user_id = ? AND DATE(timestamp) = ? ORDER BY timestamp");
            $s->execute([$userId, $workDate]);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });

        $trace['audit'] = $safe(function () use ($pdo, $userId, $workDate) {
            $s = $pdo->prepare("
                SELECT a.*, COALESCE(u.full_name, u.username) AS actor
                FROM attendance_audit a
                LEFT JOIN users u ON u.id = a.performed_by
                WHERE a.user_id = ? AND a.work_date = ?
                ORDER BY a.created_at ASC
            ");
            $s->execute([$userId, $workDate]);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });

        $trace['stages'] = $safe(function () use ($pdo, $userId, $workDate) {
            $s = $pdo->prepare("
                SELECT * FROM timesheet_stage_events
                WHERE user_id = ? AND work_date = ?
                ORDER BY created_at ASC
            ");
            $s->execute([$userId, $workDate]);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });

        $trace['voided'] = $safe(function () use ($pdo, $userId, $workDate) {
            $s = $pdo->prepare("
                SELECT v.*, COALESCE(u.full_name, u.username) AS actor
                FROM attendance_voided v
                LEFT JOIN users u ON u.id = v.voided_by
                WHERE v.user_id = ? AND v.work_date = ?
                ORDER BY v.voided_at ASC
            ");
            $s->execute([$userId, $workDate]);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });

        $trace['comments'] = timesheetGetComments($pdo, $userId, $workDate);

        return $trace;
    }
}
