<?php
/**
 * Incentivo (recargo) nocturno automático.
 *
 * Dos formas de pagarlo, configurables desde Nómina → Configuración:
 *
 *   - PORCENTAJE (lo que manda el Código de Trabajo, art. 204): un % sobre el
 *     valor de la HORA NORMAL del colaborador por cada hora pagable dentro de la
 *     franja. Ej: 15% con tarifa RD$100/h → RD$15 por cada hora nocturna.
 *
 *   - MONTO FIJO: RD$ por hora nocturna, sin mirar el salario (así estaba
 *     Delivery: RD$5.00/h desde las 7:00 PM).
 *
 * La regla puede ser GENERAL (campaign_id = 0, aplica a todo el mundo) o de una
 * campaña puntual, que manda sobre la general. Una campaña también puede quedar
 * EXCLUIDA (mode = 'none') y entonces no cobra aunque exista la general.
 *
 * Las reglas están VERSIONADAS por fecha (`effective_from` / `effective_to`): al
 * cambiar la regla no se pisa la anterior, se le pone fecha de cierre. Así,
 * regenerar una quincena vieja vuelve a pagar lo que de verdad aplicaba esos
 * días y no lo que se configuró después.
 *
 * Las horas nocturnas se sacan de la MISMA fuente con que se le paga el día:
 *
 *   - Días pagados por PONCHE  → exacto. Se cruzan los tramos pagados reales
 *     (computeStateSegments) contra la franja. Las pausas no pagadas no cuentan
 *     porque no aparecen como tramo pagado.
 *
 *   - Días pagados por VICIDIAL → estimado. La hoja de tiempo solo guarda
 *     first_login / last_activity del día, no el detalle minuto a minuto, así que
 *     se toma el solape de esa ventana con la franja y se PRORRATEA por la
 *     proporción de la jornada que resultó pagable (pagables ÷ ventana). Un
 *     agente con 6 h logueado y 2 h pagables cobra un tercio del solape. Nunca
 *     puede pasar de las horas pagadas del día.
 *
 * La zona horaria del sistema es America/Santo_Domingo (ver db.php), así que las
 * 9:00 PM GMT-4 son literalmente las 21:00 de los timestamps guardados. No se
 * convierte nada.
 *
 * El recargo por día feriado NO se aplica aquí: el nocturno se calcula sobre la
 * hora normal, no sobre la hora ya recargada.
 */

require_once __DIR__ . '/work_hours_calculator.php';

if (!function_exists('nightIncentiveNextPayrollPeriodStart')) {
    /**
     * Inicio de la próxima quincena (16 del mes en curso o 1ro del siguiente).
     * Se usa como fecha de arranque por defecto de la regla general.
     */
    function nightIncentiveNextPayrollPeriodStart(?string $today = null): string
    {
        $ts = strtotime($today ?: date('Y-m-d')) ?: time();
        if ((int) date('j', $ts) <= 15) {
            return date('Y-m-16', $ts);
        }
        return date('Y-m-01', strtotime('first day of next month', $ts));
    }
}

if (!function_exists('ensureCampaignNightIncentivesTable')) {
    /**
     * Garantiza la tabla de reglas, sus columnas y las columnas de nómina que
     * guardan el resultado. Se llama sola desde los puntos de uso (no hace falta
     * correr la migración a mano en cada servidor).
     */
    function ensureCampaignNightIncentivesTable(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS campaign_night_incentives (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campaign_id INT UNSIGNED NOT NULL,
                mode VARCHAR(10) NOT NULL DEFAULT 'fixed',
                start_time TIME NOT NULL DEFAULT '21:00:00',
                end_time TIME NOT NULL DEFAULT '00:00:00',
                amount_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                percent_of_hourly DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                effective_from DATE NULL,
                effective_to DATE NULL,
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_campaign (campaign_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        try {
            $cols = $pdo->query("SHOW COLUMNS FROM campaign_night_incentives")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('mode', $cols, true)) {
                $pdo->exec("ALTER TABLE campaign_night_incentives ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT 'fixed' AFTER campaign_id");
            }
            if (!in_array('percent_of_hourly', $cols, true)) {
                $pdo->exec("ALTER TABLE campaign_night_incentives ADD COLUMN percent_of_hourly DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER amount_per_hour");
            }
            if (!in_array('effective_to', $cols, true)) {
                $pdo->exec("ALTER TABLE campaign_night_incentives ADD COLUMN effective_to DATE NULL AFTER effective_from");
            }

            // Antes había UNA regla por campaña (UNIQUE). Ahora conviven la vigente
            // y las históricas cerradas, así que ese índice tiene que irse.
            $idx = $pdo->query("SHOW INDEX FROM campaign_night_incentives")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($idx as $row) {
                if (($row['Key_name'] ?? '') === 'unique_campaign') {
                    $pdo->exec("ALTER TABLE campaign_night_incentives DROP INDEX unique_campaign");
                    $pdo->exec("ALTER TABLE campaign_night_incentives ADD INDEX idx_campaign (campaign_id)");
                    break;
                }
            }
        } catch (PDOException $e) {
            error_log('ensureCampaignNightIncentivesTable (schema): ' . $e->getMessage());
        }

        // El monto calculado necesita columna propia en la nómina. Hasta ahora el
        // incentivo nocturno solo existía como captura manual y viajaba escondido
        // dentro de `bonuses`, así que ningún reporte podía distinguirlo.
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM payroll_records")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('night_incentive', $cols, true)) {
                $pdo->exec("ALTER TABLE payroll_records ADD COLUMN night_incentive DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER bonuses");
            }
            if (!in_array('night_hours', $cols, true)) {
                $pdo->exec("ALTER TABLE payroll_records ADD COLUMN night_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER night_incentive");
            }
            if (!in_array('night_incentive_source', $cols, true)) {
                $pdo->exec("ALTER TABLE payroll_records ADD COLUMN night_incentive_source VARCHAR(10) NOT NULL DEFAULT '' AFTER night_hours");
            }
        } catch (PDOException $e) {
            error_log('ensureCampaignNightIncentivesTable (payroll_records): ' . $e->getMessage());
        }

        seedGeneralNightIncentiveRule($pdo);

        $ensured = true;
    }
}

if (!function_exists('seedGeneralNightIncentiveRule')) {
    /**
     * Siembra la regla GENERAL de ley (15% sobre la hora normal, 9:00 PM a 12:00 AM)
     * a partir de la próxima quincena, y le pone fecha de cierre a las reglas de
     * campaña que venían corriendo (el caso real: Delivery, RD$5.00/h desde las
     * 7:00 PM) para que la historia siga pagando lo que pagaba y desde la fecha
     * nueva mande la general.
     *
     * Corre UNA sola vez: si ya existe cualquier fila general no toca nada. La UI
     * nunca borra la fila general (la desactiva), así que no puede resembrarse.
     */
    function seedGeneralNightIncentiveRule(PDO $pdo): void
    {
        try {
            $exists = (int) $pdo->query("SELECT COUNT(*) FROM campaign_night_incentives WHERE campaign_id = 0")->fetchColumn();
            if ($exists > 0) {
                return;
            }

            $from  = nightIncentiveNextPayrollPeriodStart();
            $until = date('Y-m-d', strtotime($from . ' -1 day'));

            // Las reglas de campaña abiertas se cierran el día antes de que entre
            // la general. Las que ya tenían fecha de cierre se respetan.
            $close = $pdo->prepare("
                UPDATE campaign_night_incentives
                SET effective_to = ?
                WHERE campaign_id > 0
                  AND effective_to IS NULL
                  AND (effective_from IS NULL OR effective_from < ?)
            ");
            $close->execute([$until, $from]);

            $ins = $pdo->prepare("
                INSERT INTO campaign_night_incentives
                    (campaign_id, mode, start_time, end_time, amount_per_hour, percent_of_hourly, is_active, effective_from, effective_to, notes)
                VALUES (0, 'percent', '21:00:00', '00:00:00', 0.00, 15.00, 1, ?, NULL, ?)
            ");
            $ins->execute([
                $from,
                'Recargo nocturno de ley: 15% sobre la hora normal, 9:00 PM a 12:00 AM.',
            ]);
        } catch (PDOException $e) {
            error_log('seedGeneralNightIncentiveRule: ' . $e->getMessage());
        }
    }
}

if (!function_exists('normalizeNightIncentiveRule')) {
    /**
     * @param array<string,mixed> $r fila cruda de campaign_night_incentives
     * @return array{id:int,campaign_id:int,mode:string,start_time:string,end_time:string,amount_per_hour:float,percent_of_hourly:float,is_active:int,effective_from:?string,effective_to:?string,notes:?string}
     */
    function normalizeNightIncentiveRule(array $r): array
    {
        $mode = strtolower(trim((string) ($r['mode'] ?? 'fixed')));
        if (!in_array($mode, ['fixed', 'percent', 'none'], true)) {
            $mode = 'fixed';
        }

        return [
            'id'                => (int) ($r['id'] ?? 0),
            'campaign_id'       => (int) ($r['campaign_id'] ?? 0),
            'mode'              => $mode,
            'start_time'        => (string) ($r['start_time'] ?? '21:00:00'),
            'end_time'          => (string) ($r['end_time'] ?? '00:00:00'),
            'amount_per_hour'   => (float) ($r['amount_per_hour'] ?? 0),
            'percent_of_hourly' => (float) ($r['percent_of_hourly'] ?? 0),
            'is_active'         => (int) ($r['is_active'] ?? 0),
            'effective_from'    => !empty($r['effective_from']) ? (string) $r['effective_from'] : null,
            'effective_to'      => !empty($r['effective_to']) ? (string) $r['effective_to'] : null,
            'notes'             => isset($r['notes']) && $r['notes'] !== '' ? (string) $r['notes'] : null,
        ];
    }
}

if (!function_exists('getCampaignNightIncentiveRules')) {
    /**
     * Todas las reglas guardadas (vigentes, históricas y apagadas), ordenadas por
     * campaña y fecha de arranque. La UI las lista tal cual.
     *
     * @return array<int,array<string,mixed>>
     */
    function getCampaignNightIncentiveRules(PDO $pdo): array
    {
        ensureCampaignNightIncentivesTable($pdo);

        $out = [];
        try {
            $rows = $pdo->query("
                SELECT * FROM campaign_night_incentives
                ORDER BY campaign_id ASC, COALESCE(effective_from, '1970-01-01') ASC, id ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $out[] = normalizeNightIncentiveRule($r);
            }
        } catch (PDOException $e) {
            error_log('getCampaignNightIncentiveRules: ' . $e->getMessage());
        }

        return $out;
    }
}

if (!function_exists('getNightIncentiveRuleSet')) {
    /**
     * Las reglas agrupadas para resolver rápido por campaña y fecha.
     *
     * @return array{global:array<int,array<string,mixed>>,by_campaign:array<int,array<int,array<string,mixed>>>}
     */
    function getNightIncentiveRuleSet(PDO $pdo): array
    {
        $set = ['global' => [], 'by_campaign' => []];
        foreach (getCampaignNightIncentiveRules($pdo) as $rule) {
            if ($rule['campaign_id'] === 0) {
                $set['global'][] = $rule;
            } else {
                $set['by_campaign'][$rule['campaign_id']][] = $rule;
            }
        }
        return $set;
    }
}

if (!function_exists('nightIncentiveRuleInForce')) {
    /**
     * ¿La regla estaba vigente ese día? (activa y dentro de su ventana de fechas)
     */
    function nightIncentiveRuleInForce(array $rule, string $date): bool
    {
        if (empty($rule['is_active'])) {
            return false;
        }
        if (!empty($rule['effective_from']) && $date < $rule['effective_from']) {
            return false;
        }
        if (!empty($rule['effective_to']) && $date > $rule['effective_to']) {
            return false;
        }
        return true;
    }
}

if (!function_exists('nightIncentiveRulePays')) {
    /** ¿La regla llega a pagar algo? (una regla en 0 es como no tenerla) */
    function nightIncentiveRulePays(array $rule): bool
    {
        if ($rule['mode'] === 'percent') {
            return (float) $rule['percent_of_hourly'] > 0;
        }
        if ($rule['mode'] === 'fixed') {
            return (float) $rule['amount_per_hour'] > 0;
        }
        return false; // 'none' = excluida a propósito
    }
}

if (!function_exists('nightIncentiveRuleForDate')) {
    /**
     * Regla que aplica a una campaña en una fecha. Manda la de la campaña sobre la
     * general; si la de la campaña dice 'none', esa campaña NO cobra (no cae a la
     * general). Devuelve null cuando ese día no se paga nada.
     *
     * @param array{global:array,by_campaign:array} $ruleSet
     */
    function nightIncentiveRuleForDate(array $ruleSet, int $campaignId, string $date): ?array
    {
        $pick = static function (array $rules) use ($date): ?array {
            $winner = null;
            foreach ($rules as $rule) {
                if (!nightIncentiveRuleInForce($rule, $date)) {
                    continue;
                }
                // Ya vienen ordenadas por fecha de arranque: la última gana.
                $winner = $rule;
            }
            return $winner;
        };

        $campaignRule = $campaignId > 0 ? $pick($ruleSet['by_campaign'][$campaignId] ?? []) : null;
        $rule = $campaignRule ?: $pick($ruleSet['global'] ?? []);

        if (!$rule || !nightIncentiveRulePays($rule)) {
            return null;
        }
        return $rule;
    }
}

if (!function_exists('nightIncentiveRuleSetUsesPercent')) {
    /**
     * ¿Alguna regla activa paga por porcentaje? Si no, la nómina se ahorra
     * resolver la tarifa horaria día por día.
     */
    function nightIncentiveRuleSetUsesPercent(array $ruleSet): bool
    {
        $all = array_merge($ruleSet['global'] ?? [], ...array_values($ruleSet['by_campaign'] ?? [[]]));
        foreach ($all as $rule) {
            if (!empty($rule['is_active']) && $rule['mode'] === 'percent' && (float) $rule['percent_of_hourly'] > 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('nightIncentiveRuleLabel')) {
    /** Descripción corta de la regla para avisos y pantallas. */
    function nightIncentiveRuleLabel(array $rule): string
    {
        $franja = date('g:i A', strtotime($rule['start_time'])) . ' a ' . date('g:i A', strtotime($rule['end_time']));

        if ($rule['mode'] === 'none') {
            return 'Sin incentivo nocturno';
        }
        if ($rule['mode'] === 'percent') {
            return rtrim(rtrim(number_format((float) $rule['percent_of_hourly'], 2), '0'), '.')
                . '% de la hora normal, ' . $franja;
        }
        return 'RD$' . number_format((float) $rule['amount_per_hour'], 2) . '/h, ' . $franja;
    }
}

if (!function_exists('nightIncentiveWindowSeconds')) {
    /**
     * Duración de la franja en segundos. end_time '00:00' (o igual/menor al
     * inicio) significa que corre hasta la medianoche del día siguiente, para
     * soportar turnos que cruzan (ej. 21:00 → 07:00).
     */
    function nightIncentiveWindowSeconds(string $startTime, string $endTime): int
    {
        $start = nightIncentiveTimeToSeconds($startTime);
        $end   = nightIncentiveTimeToSeconds($endTime);
        $span  = $end - $start;
        if ($span <= 0) {
            $span += 86400;
        }
        return max(0, min(86400, $span));
    }
}

if (!function_exists('nightIncentiveTimeToSeconds')) {
    function nightIncentiveTimeToSeconds(string $time): int
    {
        $parts = array_map('intval', explode(':', trim($time)) + [0, 0, 0]);
        return max(0, min(86400, ($parts[0] * 3600) + ($parts[1] * 60) + ($parts[2] ?? 0)));
    }
}

if (!function_exists('nightIncentiveBuildWindows')) {
    /**
     * Ventanas [inicio, fin] en epoch que cubren el rango. Se arranca un día ANTES
     * del inicio para que una franja que cruza medianoche alcance la madrugada del
     * primer día del período.
     *
     * @return array<int,array{0:int,1:int}>
     */
    function nightIncentiveBuildWindows(string $startDate, string $endDate, string $startTime, string $endTime): array
    {
        $spanSeconds = nightIncentiveWindowSeconds($startTime, $endTime);
        if ($spanSeconds <= 0) {
            return [];
        }

        $windows = [];
        $cursor = strtotime($startDate . ' -1 day');
        $last   = strtotime($endDate);
        if ($cursor === false || $last === false) {
            return [];
        }

        while ($cursor <= $last) {
            $open = strtotime(date('Y-m-d', $cursor) . ' ' . $startTime);
            if ($open !== false) {
                $windows[] = [$open, $open + $spanSeconds];
            }
            $cursor = strtotime('+1 day', $cursor);
        }

        return $windows;
    }
}

if (!function_exists('nightIncentiveOverlapByDate')) {
    /**
     * Segundos de [$from,$to] que caen dentro de las ventanas, agrupados por la
     * fecha en que ocurre el solape (no por la fecha del tramo): así la madrugada
     * se le acredita al día que corresponde.
     *
     * @param array<int,array{0:int,1:int}> $windows
     * @return array<string,int>
     */
    function nightIncentiveOverlapByDate(int $from, int $to, array $windows): array
    {
        $out = [];
        if ($to <= $from) {
            return $out;
        }

        foreach ($windows as [$winStart, $winEnd]) {
            $start = max($from, $winStart);
            $end   = min($to, $winEnd);
            if ($end <= $start) {
                continue;
            }
            $date = date('Y-m-d', $start);
            $out[$date] = ($out[$date] ?? 0) + ($end - $start);
        }

        return $out;
    }
}

if (!function_exists('nightIncentiveHoursFromPunches')) {
    /**
     * Horas nocturnas EXACTAS de los tramos pagados del ponche.
     *
     * @param array<int,array{type:string,timestamp:string|int,id?:int}> $punches
     * @param string[] $paidTypeSlugs
     * @param array<int,array{0:int,1:int}> $windows
     * @return array<string,int> fecha => segundos nocturnos
     */
    function nightIncentiveHoursFromPunches(array $punches, array $paidTypeSlugs, array $windows): array
    {
        $out = [];
        foreach (computeStateSegments($punches, $paidTypeSlugs) as $segment) {
            if (empty($segment['is_paid'])) {
                continue;
            }
            foreach (nightIncentiveOverlapByDate((int) $segment['start'], (int) $segment['end'], $windows) as $date => $seconds) {
                $out[$date] = ($out[$date] ?? 0) + $seconds;
            }
        }
        return $out;
    }
}

if (!function_exists('nightIncentiveHoursFromVicidial')) {
    /**
     * Horas nocturnas ESTIMADAS de la hoja de tiempo de Vicidial. Ver la nota de
     * cabecera: solape de la ventana de login con la franja, prorrateado por la
     * fracción pagable del día.
     *
     * @param array<string,int> $paidSecondsByDate segundos pagables ya calculados
     * @param array<int,array{0:int,1:int}> $windows
     * @return array<string,int> fecha => segundos nocturnos
     */
    function nightIncentiveHoursFromVicidial(
        PDO $pdo,
        int $userId,
        string $startDate,
        string $endDate,
        array $windows,
        array $paidSecondsByDate
    ): array {
        $out = [];

        try {
            $stmt = $pdo->prepare("
                SELECT report_date,
                       MIN(first_login)   AS first_login,
                       MAX(last_activity) AS last_activity
                FROM vicidial_agent_timesheet
                WHERE user_id = ?
                  AND report_date BETWEEN ? AND ?
                  AND first_login IS NOT NULL
                  AND last_activity IS NOT NULL
                GROUP BY report_date
            ");
            $stmt->execute([$userId, $startDate, $endDate]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('nightIncentiveHoursFromVicidial: ' . $e->getMessage());
            return $out;
        }

        foreach ($rows as $row) {
            $date = (string) $row['report_date'];
            $paid = (int) ($paidSecondsByDate[$date] ?? 0);
            if ($paid <= 0) {
                continue;
            }

            $login  = strtotime((string) $row['first_login']);
            $logout = strtotime((string) $row['last_activity']);
            if ($login === false || $logout === false || $logout <= $login) {
                continue;
            }

            $span = $logout - $login;
            // La fracción pagable del día. Si Vicidial reporta menos ventana que
            // horas pagadas (ajuste manual al alza, cuentas múltiples), no se
            // infla: el tope es 1.
            $ratio = min(1.0, $paid / $span);

            foreach (nightIncentiveOverlapByDate($login, $logout, $windows) as $d => $seconds) {
                $out[$d] = ($out[$d] ?? 0) + (int) round($seconds * $ratio);
            }
        }

        return $out;
    }
}

if (!function_exists('calculateNightIncentiveForUser')) {
    /**
     * Incentivo nocturno de un colaborador para el período. Resuelve la regla DÍA
     * POR DÍA (puede cambiar a mitad de quincena, ej. el 16 entra la general).
     *
     * @param array  $ruleSet           getNightIncentiveRuleSet()
     * @param int    $campaignId        campaña del colaborador (0 = sin campaña)
     * @param array  $punches           filas de attendance del rango, ya traídas por quien llama
     * @param string[] $paidTypeSlugs   slugs pagados normalizados
     * @param array<string,int> $paidSecondsByDate  segundos pagables FINALES por día (post-merge)
     * @param array<string,string> $sourceByDate    'ponche' | 'vicidial' por día
     * @param array<string,float>  $hourlyRateByDate tarifa/hora NORMAL por día (solo hace falta en modo %)
     *
     * @return array{amount:float,hours:float,by_date:array<string,float>,estimated_hours:float,estimated_days:array<int,string>,rules:array<int,string>,missing_rate_days:array<int,string>}
     */
    function calculateNightIncentiveForUser(
        PDO $pdo,
        int $userId,
        array $ruleSet,
        int $campaignId,
        string $periodStart,
        string $periodEnd,
        array $punches,
        array $paidTypeSlugs,
        array $paidSecondsByDate,
        array $sourceByDate,
        array $hourlyRateByDate = []
    ): array {
        $empty = [
            'amount' => 0.0, 'hours' => 0.0, 'by_date' => [],
            'estimated_hours' => 0.0, 'estimated_days' => [],
            'rules' => [], 'missing_rate_days' => [],
        ];

        // 1) Qué regla le toca a cada día del período.
        $ruleByDate = [];
        $rulesUsed  = [];
        foreach ($paidSecondsByDate as $date => $paidSeconds) {
            $date = (string) $date;
            if ($date < $periodStart || $date > $periodEnd || (int) $paidSeconds <= 0) {
                continue;
            }
            $rule = nightIncentiveRuleForDate($ruleSet, $campaignId, $date);
            if (!$rule) {
                continue;
            }
            $ruleByDate[$date] = $rule;
            $rulesUsed[(int) $rule['id']] = $rule;
        }

        if (empty($ruleByDate)) {
            return $empty;
        }

        // 2) Horas nocturnas por franja. Dos reglas con la misma franja comparten
        //    el cálculo (lo normal: 1 o 2 franjas distintas por período).
        $punchNightByWindow    = [];
        $vicidialNightByWindow = [];
        $hasVicidialDay = in_array('vicidial', $sourceByDate, true);

        foreach ($rulesUsed as $rule) {
            $key = $rule['start_time'] . '|' . $rule['end_time'];
            if (isset($punchNightByWindow[$key])) {
                continue;
            }
            $windows = nightIncentiveBuildWindows($periodStart, $periodEnd, $rule['start_time'], $rule['end_time']);
            if (empty($windows)) {
                $punchNightByWindow[$key]    = [];
                $vicidialNightByWindow[$key] = [];
                continue;
            }
            $punchNightByWindow[$key] = nightIncentiveHoursFromPunches($punches, $paidTypeSlugs, $windows);
            $vicidialNightByWindow[$key] = $hasVicidialDay
                ? nightIncentiveHoursFromVicidial($pdo, $userId, $periodStart, $periodEnd, $windows, $paidSecondsByDate)
                : [];
        }

        // 3) Monto día por día con la regla (y la tarifa) de ese día.
        $byDate = [];
        $totalSeconds = 0;
        $totalAmount = 0.0;
        $estimatedSeconds = 0;
        $estimatedDays = [];
        $missingRateDays = [];
        $ruleLabels = [];

        foreach ($ruleByDate as $date => $rule) {
            $paidSeconds = (int) $paidSecondsByDate[$date];
            $key = $rule['start_time'] . '|' . $rule['end_time'];
            $source = $sourceByDate[$date] ?? 'ponche';

            $nightSeconds = $source === 'vicidial'
                ? (int) ($vicidialNightByWindow[$key][$date] ?? 0)
                : (int) ($punchNightByWindow[$key][$date] ?? 0);

            // Guardarraíl duro: el incentivo nunca puede cubrir más horas de las
            // que se le están pagando ese día.
            $nightSeconds = min($nightSeconds, $paidSeconds);
            if ($nightSeconds <= 0) {
                continue;
            }

            $hours = $nightSeconds / 3600;

            if ($rule['mode'] === 'percent') {
                $rate = (float) ($hourlyRateByDate[$date] ?? 0);
                if ($rate <= 0) {
                    // Sin tarifa horaria no hay sobre qué aplicar el %. Se avisa
                    // en vez de pagar 0 en silencio.
                    $missingRateDays[] = $date;
                    continue;
                }
                $dayAmount = $hours * $rate * ((float) $rule['percent_of_hourly'] / 100);
            } else {
                $dayAmount = $hours * (float) $rule['amount_per_hour'];
            }

            $byDate[$date] = round($hours, 4);
            $totalSeconds += $nightSeconds;
            $totalAmount  += $dayAmount;
            $ruleLabels[(int) $rule['id']] = nightIncentiveRuleLabel($rule);

            if ($source === 'vicidial') {
                $estimatedSeconds += $nightSeconds;
                $estimatedDays[] = $date;
            }
        }

        ksort($byDate);

        return [
            'amount'            => round($totalAmount, 2),
            'hours'             => round($totalSeconds / 3600, 2),
            'by_date'           => $byDate,
            'estimated_hours'   => round($estimatedSeconds / 3600, 2),
            'estimated_days'    => $estimatedDays,
            'rules'             => array_values($ruleLabels),
            'missing_rate_days' => $missingRateDays,
        ];
    }
}
