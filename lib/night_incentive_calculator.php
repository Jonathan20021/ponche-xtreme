<?php
/**
 * Incentivo nocturno automático por campaña.
 *
 * Regla (RD$ por HORA, prorrateada): a cada colaborador de una campaña con regla
 * activa se le paga un monto fijo por cada hora PAGABLE que trabajó dentro de la
 * franja nocturna (por defecto desde las 19:00). Ej: Delivery, RD$5.00/hora desde
 * las 7:00 PM → un turno 15:30–21:30 rinde 2.50 h nocturnas = RD$12.50 ese día.
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
 * 7:00 PM GMT-4 son literalmente las 19:00 de los timestamps guardados. No se
 * convierte nada.
 *
 * El recargo por día feriado NO se aplica aquí: el incentivo es un monto fijo por
 * hora, no una tarifa horaria.
 */

require_once __DIR__ . '/work_hours_calculator.php';

if (!function_exists('ensureCampaignNightIncentivesTable')) {
    /**
     * Garantiza la tabla de reglas y las columnas de nómina que guardan el
     * resultado. Se llama sola desde los puntos de uso (no hace falta correr la
     * migración a mano en cada servidor).
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
                start_time TIME NOT NULL DEFAULT '19:00:00',
                end_time TIME NOT NULL DEFAULT '00:00:00',
                amount_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                effective_from DATE NULL,
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_campaign (campaign_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

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

        $ensured = true;
    }
}

if (!function_exists('getCampaignNightIncentiveRules')) {
    /**
     * Todas las reglas guardadas, incluidas las apagadas (la UI las lista).
     *
     * @return array<int,array{campaign_id:int,start_time:string,end_time:string,amount_per_hour:float,is_active:int,effective_from:?string,notes:?string}>
     */
    function getCampaignNightIncentiveRules(PDO $pdo): array
    {
        ensureCampaignNightIncentivesTable($pdo);

        $out = [];
        try {
            $rows = $pdo->query("SELECT * FROM campaign_night_incentives")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $out[(int) $r['campaign_id']] = [
                    'campaign_id'     => (int) $r['campaign_id'],
                    'start_time'      => (string) $r['start_time'],
                    'end_time'        => (string) $r['end_time'],
                    'amount_per_hour' => (float) $r['amount_per_hour'],
                    'is_active'       => (int) $r['is_active'],
                    'effective_from'  => $r['effective_from'] ?: null,
                    'notes'           => $r['notes'] ?: null,
                ];
            }
        } catch (PDOException $e) {
            error_log('getCampaignNightIncentiveRules: ' . $e->getMessage());
        }

        return $out;
    }
}

if (!function_exists('getActiveCampaignNightIncentiveRules')) {
    /**
     * Solo las reglas que aplican al período: activas, con monto > 0 y cuya fecha
     * de vigencia ya empezó antes de que termine el período.
     *
     * @return array<int,array> campaign_id => regla
     */
    function getActiveCampaignNightIncentiveRules(PDO $pdo, ?string $periodEnd = null): array
    {
        $out = [];
        foreach (getCampaignNightIncentiveRules($pdo) as $campaignId => $rule) {
            if (!$rule['is_active'] || $rule['amount_per_hour'] <= 0) {
                continue;
            }
            if ($periodEnd && $rule['effective_from'] && $rule['effective_from'] > $periodEnd) {
                continue;
            }
            $out[$campaignId] = $rule;
        }
        return $out;
    }
}

if (!function_exists('nightIncentiveWindowSeconds')) {
    /**
     * Duración de la franja en segundos. end_time '00:00' (o igual/menor al
     * inicio) significa que corre hasta la medianoche del día siguiente, para
     * soportar turnos que cruzan (ej. 19:00 → 06:00).
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
     * Incentivo nocturno de un colaborador para el período.
     *
     * @param array  $rule              regla de la campaña (ver getCampaignNightIncentiveRules)
     * @param array  $punches           filas de attendance del rango, ya traídas por quien llama
     * @param string[] $paidTypeSlugs   slugs pagados normalizados
     * @param array<string,int> $paidSecondsByDate  segundos pagables FINALES por día (post-merge)
     * @param array<string,string> $sourceByDate    'ponche' | 'vicidial' por día
     *
     * @return array{amount:float,hours:float,by_date:array<string,float>,estimated_hours:float,estimated_days:array<int,string>}
     */
    function calculateNightIncentiveForUser(
        PDO $pdo,
        int $userId,
        array $rule,
        string $periodStart,
        string $periodEnd,
        array $punches,
        array $paidTypeSlugs,
        array $paidSecondsByDate,
        array $sourceByDate
    ): array {
        $empty = ['amount' => 0.0, 'hours' => 0.0, 'by_date' => [], 'estimated_hours' => 0.0, 'estimated_days' => []];

        $amountPerHour = (float) ($rule['amount_per_hour'] ?? 0);
        if ($amountPerHour <= 0) {
            return $empty;
        }

        // Una regla con fecha de vigencia no paga los días anteriores a ella.
        $effectiveFrom = $rule['effective_from'] ?? null;

        $windows = nightIncentiveBuildWindows(
            $periodStart,
            $periodEnd,
            $rule['start_time'] ?? '19:00:00',
            $rule['end_time'] ?? '00:00:00'
        );
        if (empty($windows)) {
            return $empty;
        }

        $punchNight = nightIncentiveHoursFromPunches($punches, $paidTypeSlugs, $windows);

        // Solo se consulta Vicidial si algún día del período se pagó con esa fuente.
        $hasVicidialDay = in_array('vicidial', $sourceByDate, true);
        $vicidialNight = $hasVicidialDay
            ? nightIncentiveHoursFromVicidial($pdo, $userId, $periodStart, $periodEnd, $windows, $paidSecondsByDate)
            : [];

        $byDate = [];
        $totalSeconds = 0;
        $estimatedSeconds = 0;
        $estimatedDays = [];

        foreach ($paidSecondsByDate as $date => $paidSeconds) {
            if ($date < $periodStart || $date > $periodEnd) {
                continue;
            }
            if ($effectiveFrom && $date < $effectiveFrom) {
                continue;
            }
            $paidSeconds = (int) $paidSeconds;
            if ($paidSeconds <= 0) {
                continue;
            }

            $source = $sourceByDate[$date] ?? 'ponche';
            $nightSeconds = $source === 'vicidial'
                ? (int) ($vicidialNight[$date] ?? 0)
                : (int) ($punchNight[$date] ?? 0);

            // Guardarraíl duro: el incentivo nunca puede cubrir más horas de las
            // que se le están pagando ese día.
            $nightSeconds = min($nightSeconds, $paidSeconds);
            if ($nightSeconds <= 0) {
                continue;
            }

            $byDate[$date] = round($nightSeconds / 3600, 4);
            $totalSeconds += $nightSeconds;

            if ($source === 'vicidial') {
                $estimatedSeconds += $nightSeconds;
                $estimatedDays[] = $date;
            }
        }

        ksort($byDate);
        $hours = round($totalSeconds / 3600, 2);

        return [
            'amount'          => round($hours * $amountPerHour, 2),
            'hours'           => $hours,
            'by_date'         => $byDate,
            'estimated_hours' => round($estimatedSeconds / 3600, 2),
            'estimated_days'  => $estimatedDays,
        ];
    }
}
