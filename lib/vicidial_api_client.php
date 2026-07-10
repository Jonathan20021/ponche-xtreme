<?php
/**
 * Cliente de la API HTTP de Vicidial + importador de hoja de tiempo (Fase 1).
 *
 * NO usa la base de datos de Vicidial (no tenemos acceso). Consume los reportes
 * web de Vicidial vía HTTP Basic Auth, que devuelven CSV cuando se pasa
 * file_download/type=TEXT:
 *
 *   - AST_agent_performance_detail.php  -> totales por agente (calls, time,
 *     pause, wait, talk, dispo) de todos los agentes en un rango. 1 request/día.
 *   - AST_agent_time_sheet.php          -> FIRST LOGIN / LAST LOG ACTIVITY /
 *     TOTAL LOGGED-IN TIME de UN agente. 1 request por agente.
 *   - non_agent_api.php?function=version -> prueba de credenciales + TZ server.
 *
 * IMPORTANTE (zona horaria): el server Vicidial reporta en su hora local
 * (TZ -05:00). La app de ponche y la tabla `attendance` están en hora local de
 * RD (America/Santo_Domingo, -04:00). Por eso los timestamps de Vicidial se
 * llevan a hora RD sumando `vicidial_tz_offset_minutes` (config, default 0 —
 * verificado: este server Vicidial ya reporta en hora RD; ver nota en el default)
 * antes de guardarlos, para que la conciliación compare hora contra hora.
 *
 * Toda la configuración vive en system_settings (editable desde settings.php),
 * siguiendo la convención del resto del sistema.
 */

require_once __DIR__ . '/../db.php';

if (!function_exists('getVicidialSyncConfig')) {
    /**
     * Devuelve la configuración de sincronización Vicidial con valores por
     * defecto. Todas las claves viven en system_settings (categoría 'vicidial').
     */
    function getVicidialSyncConfig(PDO $pdo): array
    {
        $defaults = [
            'vicidial_sync_enabled'          => '0',
            'vicidial_api_base_url'          => 'https://evallish-bpo-services.rex-tek.com/vicidial',
            'vicidial_api_user'              => '',
            'vicidial_api_pass'              => '',
            'vicidial_api_source'            => 'ponche',
            'vicidial_tz_offset_minutes'     => '0',   // este server ya reporta en hora RD (verificado); NO fiarse del "TZ -5.00" de la API
            'vicidial_sync_user_groups'      => '',   // vacío = todos los grupos
            'vicidial_sync_min_time_seconds' => '60', // no pedir timesheet a agentes con < X seg logueados
            'vicidial_sync_http_timeout'     => '30',
            'vicidial_ssl_verify'            => '1',  // 0 solo como último recurso si el vendor rompe su cadena
            // Monitor en vivo (Fase 2)
            'vicidial_live_enabled'          => '1',
            'vicidial_live_ttl_seconds'      => '25', // refrescar desde Vicidial a lo sumo cada X seg (caché compartida)
            'vicidial_live_http_timeout'     => '8',  // timeout corto para no frenar el dashboard
            // Nómina desde Vicidial (Fase 3): qué códigos de pausa se PAGAN.
            // NONPAUSE (tiempo trabajando) siempre se paga; aquí van los códigos
            // de pausa que TAMBIÉN se pagan. El resto (Break, Bao, NXDIAL...) no.
            'vicidial_paid_pause_codes'      => '["Coachi","ITRes","LAGGED","LOGIN","Digita","wasapi","SIN_CODIGO"]',
            'vicidial_payroll_daily_cap_hours' => '14', // tope de cordura por día (anomalías)
        ];

        try {
            $stmt = $pdo->query("
                SELECT setting_key, setting_value
                FROM system_settings
                WHERE setting_key LIKE 'vicidial_sync_%'
                   OR setting_key LIKE 'vicidial_api_%'
                   OR setting_key LIKE 'vicidial_live_%'
                   OR setting_key LIKE 'vicidial_payroll_%'
                   OR setting_key = 'vicidial_tz_offset_minutes'
                   OR setting_key = 'vicidial_paid_pause_codes'
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getVicidialSyncConfig: ' . $e->getMessage());
        }

        // Normalizar: quitar barra final del base_url
        $defaults['vicidial_api_base_url'] = rtrim(trim($defaults['vicidial_api_base_url']), '/');

        return $defaults;
    }
}

if (!function_exists('vicidialGetAdjustmentsByDate')) {
    /**
     * Ajustes manuales de horas pagables (tabla intermedia) para un empleado.
     * Gestión de Desempeño los edita; la fuente cruda de Vicidial nunca se toca.
     *
     * @return array<string,array{seconds:int, original:int, reason:string, by:int}>
     */
    function vicidialGetAdjustmentsByDate(PDO $pdo, int $userId, string $startDate, string $endDate): array
    {
        $out = [];
        try {
            $stmt = $pdo->prepare("
                SELECT work_date, adjusted_seconds, original_seconds, reason, adjusted_by
                FROM vicidial_payroll_adjustments
                WHERE user_id = ? AND work_date BETWEEN ? AND ?
            ");
            $stmt->execute([$userId, $startDate, $endDate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[$r['work_date']] = [
                    'seconds'  => max(0, (int) $r['adjusted_seconds']),
                    'original' => max(0, (int) $r['original_seconds']),
                    'reason'   => (string) $r['reason'],
                    'by'       => (int) $r['adjusted_by'],
                ];
            }
        } catch (Throwable $e) {
            // Tabla ausente (deploy a medias) => sin ajustes, nunca romper la nómina.
            error_log('vicidialGetAdjustmentsByDate: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('vicidialApplyDayAdjustment')) {
    /**
     * Aplica el ajuste manual de UN día sobre las horas pagables crudas. Para las
     * vistas que calculan un solo día (portal del agente, registros) y por tanto
     * no pasan por vicidialGetPaidSecondsByDate(). Devuelve siempre un valor
     * utilizable, aunque la tabla de ajustes no exista.
     *
     * @return array{seconds:int, adjusted:bool, original:int, reason:string}
     */
    function vicidialApplyDayAdjustment(PDO $pdo, int $userId, string $date, int $rawPaidSeconds): array
    {
        $adj = vicidialGetAdjustmentsByDate($pdo, $userId, $date, $date);
        if (!isset($adj[$date])) {
            return ['seconds' => $rawPaidSeconds, 'adjusted' => false, 'original' => $rawPaidSeconds, 'reason' => ''];
        }
        return [
            'seconds'  => $adj[$date]['seconds'],
            'adjusted' => true,
            'original' => $rawPaidSeconds,
            'reason'   => $adj[$date]['reason'],
        ];
    }
}

if (!function_exists('vicidialGetPaidSecondsByDate')) {
    /**
     * Horas PAGABLES de Vicidial por fecha para un empleado, en el formato que
     * usa la nómina (calculateDailyWorkSecondsFromPunchRows): [fecha => segundos].
     * by_date solo incluye días con segundos pagables > 0. seen_dates incluye TODOS
     * los días con fila en Vicidial (aunque el pagable sea 0), para que la nómina
     * pueda distinguir "día sin registro en Vicidial" (respaldar con ponche) de
     * "día en Vicidial sin producción" (paga lo de Vicidial). Marca los días que
     * tocaron el tope de cordura (anomalías a revisar).
     *
     * PUNTO ÚNICO de la verdad sobre horas pagables de Vicidial: lo consumen la
     * nómina (hr/payroll.php) y el portal del agente (lib/agent_hours.php). Aquí,
     * y solo aquí, se aplican los ajustes manuales de Gestión de Desempeño.
     *
     * Un agente con DOS cuentas de Vicidial activas el mismo día suma ambas
     * (antes la segunda fila sobrescribía a la primera y se le pagaba una sola).
     * El tope de cordura se aplica al TOTAL del día, después de sumar.
     *
     * @return array{by_date:array<string,int>, capped_days:array<int,string>, days:int, seen_dates:array<int,string>, adjusted_days:array<int,string>}
     */
    function vicidialGetPaidSecondsByDate(PDO $pdo, int $userId, string $startDate, string $endDate): array
    {
        $paidCodes = vicidialGetPaidPauseCodes($pdo);
        $cap = (int) round((float) getSystemSetting($pdo, 'vicidial_payroll_daily_cap_hours', 14) * 3600);

        $byDate = [];
        $cappedDays = [];
        $seen = [];
        try {
            $stmt = $pdo->prepare("
                SELECT report_date, nonpause_seconds, pause_breakdown
                FROM vicidial_agent_timesheet
                WHERE user_id = ? AND report_date BETWEEN ? AND ?
            ");
            $stmt->execute([$userId, $startDate, $endDate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $seen[$r['report_date']] = true;
                $codes = $r['pause_breakdown'] ? json_decode($r['pause_breakdown'], true) : [];
                // Sin tope aquí: el tope es por DÍA, no por cuenta de Vicidial.
                $calc = vicidialComputePaidSeconds((int) $r['nonpause_seconds'], is_array($codes) ? $codes : [], $paidCodes, 0);
                $byDate[$r['report_date']] = ($byDate[$r['report_date']] ?? 0) + $calc['paid_seconds'];
            }
        } catch (Throwable $e) {
            error_log('vicidialGetPaidSecondsByDate: ' . $e->getMessage());
        }

        foreach ($byDate as $d => $sec) {
            if ($cap > 0 && $sec > $cap) {
                $byDate[$d] = $cap;
                $cappedDays[] = $d;
            }
        }

        // Ajustes manuales: mandan sobre lo que diga Vicidial. Un día ajustado
        // cuenta como "visto" aunque Vicidial no lo tenga, para que la nómina no
        // lo respalde con el ponche y termine pagando dos veces.
        $adjusted = [];
        foreach (vicidialGetAdjustmentsByDate($pdo, $userId, $startDate, $endDate) as $d => $adj) {
            $byDate[$d] = $adj['seconds'];
            $seen[$d] = true;
            $adjusted[] = $d;
        }

        // by_date solo lleva días con horas pagables; los de 0 quedan en seen_dates.
        $byDate = array_filter($byDate, static fn($s) => $s > 0);

        ksort($byDate);
        ksort($seen);
        sort($adjusted);
        return [
            'by_date'       => $byDate,
            'capped_days'   => $cappedDays,
            'days'          => count($byDate),
            'seen_dates'    => array_keys($seen),
            'adjusted_days' => $adjusted,
        ];
    }
}

if (!function_exists('getVicidialPayrollEffectiveDate')) {
    /**
     * Fecha (Y-m-d) desde la cual los agentes 'vicidial' se pagan por Vicidial.
     * ANTES de esa fecha se pagan por su PONCHE (el régimen en que trabajaban en
     * la transición). Configurable en settings.php. Default = 2026-07-07 (día en
     * que los agentes dejaron de marcar ponche y pasaron a Vicidial puro).
     * Devuelve null si se deja vacío (= sin corte, todo por Vicidial).
     */
    function getVicidialPayrollEffectiveDate(PDO $pdo): ?string
    {
        $v = trim((string) getSystemSetting($pdo, 'vicidial_payroll_effective_date', '2026-07-07'));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}

if (!function_exists('vicidialMergeDailySeconds')) {
    /**
     * Combina, POR DÍA, las horas del ponche y de Vicidial para un agente 'vicidial',
     * respetando la fecha de corte de la transición:
     *   - Día ANTES de $effectiveDate  -> PONCHE (régimen anterior); si no hay ponche
     *     pero sí Vicidial, usa Vicidial para no perder el día.
     *   - Día DESDE $effectiveDate     -> VICIDIAL manda; el ponche respalda SOLO los
     *     días sin fila en Vicidial (hueco de datos), nunca deja un día trabajado en 0.
     * $effectiveDate null/'' => sin corte: todo por Vicidial con respaldo de ponche.
     *
     * @param array<string,int> $punchDaily  segundos pagables de ponche por día
     * @param array{by_date:array<string,int>,seen_dates?:array<int,string>} $vd
     * @return array{by_date:array<string,int>, source:array<string,string>}
     */
    function vicidialMergeDailySeconds(array $punchDaily, array $vd, ?string $effectiveDate): array
    {
        $vici = $vd['by_date'] ?? [];
        $seen = array_flip($vd['seen_dates'] ?? array_keys($vici));
        $days = array_unique(array_merge(array_keys($punchDaily), array_keys($vici)));
        sort($days);

        $out = [];
        $src = [];
        $hasEff = ($effectiveDate !== null && $effectiveDate !== '');
        foreach ($days as $d) {
            if ($hasEff && $d < $effectiveDate) {
                // Pre-cambio: se paga como se trabajaba entonces, por ponche.
                if (isset($punchDaily[$d])) {
                    $out[$d] = $punchDaily[$d];
                    $src[$d] = 'ponche';
                } elseif (isset($vici[$d])) {
                    $out[$d] = $vici[$d];
                    $src[$d] = 'vicidial';
                }
            } else {
                // Post-cambio: Vicidial manda; ponche respalda días sin registro Vicidial.
                if (isset($vici[$d])) {
                    $out[$d] = $vici[$d];
                    $src[$d] = 'vicidial';
                } elseif (!isset($seen[$d]) && isset($punchDaily[$d])) {
                    $out[$d] = $punchDaily[$d];
                    $src[$d] = 'ponche';
                }
            }
        }
        ksort($out);
        return ['by_date' => $out, 'source' => $src];
    }
}

if (!function_exists('vicidialGetPaidPauseCodes')) {
    /**
     * Lista de códigos de pausa que se PAGAN (además de NONPAUSE, que siempre se
     * paga). Configurable en settings.php. Comparación por nombre en minúsculas
     * para tolerar mayúsculas/minúsculas.
     */
    function vicidialGetPaidPauseCodes(PDO $pdo): array
    {
        $raw = getSystemSetting($pdo, 'vicidial_paid_pause_codes', '["Coachi","ITRes","LAGGED","LOGIN","Digita","wasapi","SIN_CODIGO"]');
        $list = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($list)) {
            $list = ['Coachi', 'ITRes', 'LAGGED', 'LOGIN', 'Digita', 'wasapi', 'SIN_CODIGO'];
        }
        return array_values(array_filter(array_map('strval', $list)));
    }
}

if (!function_exists('vicidialComputePaidSeconds')) {
    /**
     * Segundos PAGABLES de un día de Vicidial = NONPAUSE + suma de los códigos de
     * pausa pagados. Aplica un tope de cordura (anomalías) si se pasa capSeconds>0.
     *
     * @param int   $nonpauseSeconds NONPAUSE (trabajando)
     * @param array $pauseBreakdown  [codigo => segundos] (todos los códigos)
     * @param array $paidCodes       códigos de pausa que se pagan (nombres)
     * @param int   $capSeconds      tope máximo por día (0 = sin tope)
     * @return array{paid_seconds:int, raw_paid_seconds:int, capped:bool}
     */
    function vicidialComputePaidSeconds(int $nonpauseSeconds, array $pauseBreakdown, array $paidCodes, int $capSeconds = 0): array
    {
        $paidSet = [];
        foreach ($paidCodes as $c) {
            $paidSet[strtolower(trim($c))] = true;
        }
        $paid = max(0, $nonpauseSeconds);
        foreach ($pauseBreakdown as $code => $sec) {
            if (isset($paidSet[strtolower(trim((string) $code))])) {
                $paid += max(0, (int) $sec);
            }
        }
        $raw = $paid;
        $capped = false;
        if ($capSeconds > 0 && $paid > $capSeconds) {
            $paid = $capSeconds;
            $capped = true;
        }
        return ['paid_seconds' => $paid, 'raw_paid_seconds' => $raw, 'capped' => $capped];
    }
}

if (!function_exists('vicidialParseHmsToSeconds')) {
    /**
     * Convierte "H:MM:SS" o "HH:MM:SS" (o "MM:SS") a segundos. Cadenas vacías,
     * "0:00:00" o inválidas devuelven 0.
     */
    function vicidialParseHmsToSeconds(?string $value): int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0:00:00') {
            return 0;
        }
        // Quitar comillas remanentes
        $value = trim($value, '"');
        if (!preg_match('/^\d{1,3}:\d{2}(:\d{2})?$/', $value)) {
            return 0;
        }
        $parts = array_map('intval', explode(':', $value));
        if (count($parts) === 3) {
            return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        }
        // MM:SS
        return $parts[0] * 60 + $parts[1];
    }
}

if (!function_exists('vicidialNormalizeName')) {
    /**
     * Normaliza un nombre para poder emparejar Vicidial <-> ponche pese a
     * acentos, dobles espacios y mayúsculas. "Sadelyn  García" -> "sadelyn garcia".
     */
    function vicidialNormalizeName(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($conv !== false && $conv !== null) {
                $value = $conv;
            }
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9 ]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }
}

if (!function_exists('vicidialNameTokensMatch')) {
    /**
     * ¿Dos tokens de nombre son "el mismo"? Tolera acentos ya removidos, una
     * letra de diferencia (typo/letra comida) y prefijos (nombre concatenado).
     */
    function vicidialNameTokensMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (strlen($a) >= 3 && strlen($b) >= 3 && (strpos($a, $b) === 0 || strpos($b, $a) === 0)) {
            return true;
        }
        return levenshtein($a, $b) <= 1;
    }
}

if (!function_exists('vicidialNameMatchScore')) {
    /**
     * Puntúa qué tan probable es que un nombre de Vicidial y uno de ponche sean
     * la misma persona. Los nombres de Vicidial vienen mutilados (acentos y
     * letras faltantes, a veces concatenados), por eso el match es por tokens
     * difusos y no por igualdad exacta.
     *
     * Score ~ [0..2.5]: +1 si el primer token coincide, + cobertura de tokens
     * de Vicidial encontrados en ponche, +0.5 si hay 2+ tokens en común.
     */
    function vicidialNameMatchScore(string $vicidialName, string $poncheName): float
    {
        $v = array_values(array_filter(explode(' ', vicidialNormalizeName($vicidialName))));
        $p = array_values(array_filter(explode(' ', vicidialNormalizeName($poncheName))));
        if (empty($v) || empty($p)) {
            return 0.0;
        }
        $matched = 0;
        foreach ($v as $vt) {
            foreach ($p as $pt) {
                if (vicidialNameTokensMatch($vt, $pt)) {
                    $matched++;
                    break;
                }
            }
        }
        $firstOk = vicidialNameTokensMatch($v[0], $p[0]) ? 1.0 : 0.0;
        $coverage = $matched / count($v);
        return $firstOk + $coverage + ($matched >= 2 ? 0.5 : 0.0);
    }
}

if (!function_exists('vicidialBestUserMatch')) {
    /**
     * Devuelve ['user_id'=>?int, 'score'=>float, 'gap'=>float] con el mejor
     * usuario ponche para un nombre Vicidial. Solo se considera confiable si
     * score >= 2.0 y la brecha con el segundo mejor es >= 0.75 (evita
     * ambigüedades como "Luis" -> varios Luis).
     *
     * @param array $users lista de ['id'=>int,'full_name'=>string]
     */
    function vicidialBestUserMatch(string $vicidialName, array $users): array
    {
        $bestScore = 0.0;
        $secondScore = 0.0;
        $bestId = null;
        foreach ($users as $u) {
            $s = vicidialNameMatchScore($vicidialName, (string) ($u['full_name'] ?? ''));
            if ($s > $bestScore) {
                $secondScore = $bestScore;
                $bestScore = $s;
                $bestId = (int) $u['id'];
            } elseif ($s > $secondScore) {
                $secondScore = $s;
            }
        }
        $gap = $bestScore - $secondScore;
        $confident = ($bestScore >= 2.0 && $gap >= 0.75);
        return [
            'user_id' => $confident ? $bestId : null,
            'score'   => $bestScore,
            'gap'     => $gap,
        ];
    }
}

if (!function_exists('vicidialApiRequest')) {
    /**
     * GET a un script de Vicidial con Basic Auth. Un reintento ante fallo de red
     * o 429. Devuelve ['ok'=>bool, 'http'=>int, 'body'=>string, 'error'=>string].
     *
     * @param array  $cfg    getVicidialSyncConfig()
     * @param string $script p.ej. 'AST_agent_performance_detail.php'
     * @param array  $params query params (los repetidos como user_group[] se
     *                        pasan como ['user_group[]' => ['--ALL--', ...]])
     */
    function vicidialApiRequest(array $cfg, string $script, array $params): array
    {
        $base = rtrim((string) $cfg['vicidial_api_base_url'], '/');
        $user = (string) $cfg['vicidial_api_user'];
        $pass = (string) $cfg['vicidial_api_pass'];
        $timeout = max(5, (int) ($cfg['vicidial_sync_http_timeout'] ?? 30));
        $sslVerify = ($cfg['vicidial_ssl_verify'] ?? '1') !== '0';
        // El server de Vicidial (rex-tek/ZeroSSL) NO envía el intermedio de su
        // cadena, así que ni XAMPP ni HostGator pueden verificarla con su store
        // por defecto. Empaquetamos el intermedio + la raíz en lib/cacert_vicidial.pem
        // y lo usamos como CAINFO para mantener la verificación activa.
        $caBundle = __DIR__ . '/cacert_vicidial.pem';

        if ($base === '' || $user === '' || $pass === '') {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'Credenciales o URL de Vicidial no configuradas.'];
        }

        // Construir query string soportando parámetros repetidos (arrays)
        $pairs = [];
        foreach ($params as $key => $val) {
            if (is_array($val)) {
                foreach ($val as $v) {
                    $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $v);
                }
            } else {
                $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $val);
            }
        }
        $url = $base . '/' . ltrim($script, '/') . '?' . implode('&', $pairs);

        $lastError = '';
        $lastHttp = 0;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
                CURLOPT_USERPWD        => $user . ':' . $pass,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
                CURLOPT_USERAGENT      => 'ponche-xtreme-vicidial-sync/1.0',
            ];
            if ($sslVerify && is_readable($caBundle)) {
                $opts[CURLOPT_CAINFO] = $caBundle;
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $http === 200) {
                return ['ok' => true, 'http' => 200, 'body' => (string) $body, 'error' => ''];
            }

            $lastHttp = $http;
            $lastError = $err !== '' ? $err : ('HTTP ' . $http);

            // Reintentar solo ante 429 / 5xx / error de red
            if ($attempt < 2 && ($http === 429 || $http >= 500 || $http === 0)) {
                usleep(600000); // 0.6 s
                continue;
            }
            break;
        }

        return ['ok' => false, 'http' => $lastHttp, 'body' => '', 'error' => $lastError];
    }
}

if (!function_exists('vicidialTestConnection')) {
    /**
     * Prueba credenciales llamando function=version. Devuelve info útil para la
     * UI (versión y, sobre todo, la TZ del server para ajustar el offset).
     */
    function vicidialTestConnection(array $cfg): array
    {
        $res = vicidialApiRequest($cfg, 'non_agent_api.php', [
            'source'   => $cfg['vicidial_api_source'] ?: 'ponche',
            'user'     => $cfg['vicidial_api_user'],
            'pass'     => $cfg['vicidial_api_pass'],
            'function' => 'version',
        ]);

        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'raw' => ''];
        }

        $body = trim($res['body']);
        // Formato: VERSION: 2.14-202|BUILD: ...|DATE: ...|EPOCH: ...|DST: 0|TZ: -5.00|TZNOW: -5.00|
        if (stripos($body, 'VERSION:') === false) {
            // Puede venir "ERROR: BAD ..." si las credenciales/IP fallan
            return ['ok' => false, 'error' => $body !== '' ? $body : 'Respuesta inesperada del servidor.', 'raw' => $body];
        }

        $info = ['ok' => true, 'error' => '', 'raw' => $body, 'version' => '', 'tz' => '', 'server_date' => ''];
        foreach (explode('|', $body) as $chunk) {
            $chunk = trim($chunk);
            if (stripos($chunk, 'VERSION:') === 0) {
                $info['version'] = trim(substr($chunk, 8));
            } elseif (stripos($chunk, 'TZ:') === 0) {
                $info['tz'] = trim(substr($chunk, 3));
            } elseif (stripos($chunk, 'DATE:') === 0) {
                $info['server_date'] = trim(substr($chunk, 5));
            }
        }
        return $info;
    }
}

if (!function_exists('vicidialFetchPerformanceDetail')) {
    /**
     * Descarga el Agent Performance Detail de un día y lo parsea a una lista de
     * agentes. Cada item: vicidial_user, vicidial_name, user_group, calls,
     * time_seconds, pause_seconds, wait_seconds, talk_seconds, dispo_seconds.
     *
     * @return array{ok:bool, agents:array, error:string}
     */
    function vicidialFetchPerformanceDetail(array $cfg, string $date): array
    {
        $res = vicidialApiRequest($cfg, 'AST_agent_performance_detail.php', [
            'query_date'     => $date,
            'end_date'       => $date,
            'query_time'     => '00:00',
            'end_time'       => '23:59',
            'user_group[]'   => '--ALL--',
            'users[]'        => '--ALL--',
            'group[]'        => '--ALL--',
            'shift'          => 'ALL',
            'file_download'  => '1',
            'type'           => 'TEXT',
            'DB'             => '0',
            'SUBMIT'         => 'SUBMIT',
        ]);

        if (!$res['ok']) {
            return ['ok' => false, 'agents' => [], 'error' => $res['error']];
        }

        $lines = preg_split('/\r\n|\r|\n/', $res['body']);
        $header = null;
        $colIdx = [];
        $dispoCols = []; // columnas de disposición/estado (SALE, PEDIDO, NOCAL…), detectadas dinámicamente
        $agents = [];

        // Columnas que NO son códigos de disposición (identidad + resumen + promedios
        // de tiempo). Todo lo demás en el reporte es un conteo por estado/disposición.
        $nonDispoCols = [
            'USER NAME', 'ID', 'CURRENT USER GROUP', 'MOST RECENT USER GROUP', 'CALL DATE',
            'CALLS', 'TIME', 'PAUSE', 'PAUSAVG', 'WAIT', 'WAITAVG', 'TALK', 'TALKAVG',
            'DISPO', 'DISPAVG', 'DEAD', 'DEADAVG', 'CUSTOMER', 'CUSTAVG', 'TOTAL', 'NONPAUSE', 'STATUS',
        ];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line);
            $cells = array_map(static fn($c) => trim((string) $c), $cells);

            if ($header === null) {
                if (in_array('USER NAME', $cells, true)) {
                    $header = $cells;
                    foreach ($header as $i => $name) {
                        $colIdx[$name] = $i;
                        // Toda columna que no sea de resumen es un código de disposición.
                        $up = strtoupper(trim((string) $name));
                        if ($up !== '' && !in_array($up, $nonDispoCols, true)) {
                            $dispoCols[$up] = $i;
                        }
                    }
                }
                continue;
            }

            // Fin de la sección de agentes: fila TOTALS o sin ID
            $get = static function (string $col) use ($cells, $colIdx) {
                return isset($colIdx[$col]) && isset($cells[$colIdx[$col]]) ? $cells[$colIdx[$col]] : '';
            };

            $vicidialUser = $get('ID');
            $userName     = $get('USER NAME');
            if ($vicidialUser === '' || strtoupper($userName) === 'TOTALS' || in_array('TOTALS', $cells, true)) {
                continue;
            }

            // Desglose de disposiciones (conteos por estado). Se omiten los ceros
            // para mantener el JSON compacto. Claves en MAYÚSCULA (SALE, PEDIDO…).
            $dispositions = [];
            foreach ($dispoCols as $code => $idx) {
                $val = isset($cells[$idx]) ? (int) $cells[$idx] : 0;
                if ($val !== 0) {
                    $dispositions[$code] = $val;
                }
            }

            $agents[] = [
                'vicidial_user'  => $vicidialUser,
                'vicidial_name'  => $userName,
                'user_group'     => $get('CURRENT USER GROUP'),
                'calls'          => (int) $get('CALLS'),
                'time_seconds'   => vicidialParseHmsToSeconds($get('TIME')),
                'pause_seconds'  => vicidialParseHmsToSeconds($get('PAUSE')),
                'wait_seconds'   => vicidialParseHmsToSeconds($get('WAIT')),
                'talk_seconds'   => vicidialParseHmsToSeconds($get('TALK')),
                'dispo_seconds'  => vicidialParseHmsToSeconds($get('DISPO')),
                'dispositions'   => $dispositions,
            ];
        }

        if ($header === null) {
            return ['ok' => false, 'agents' => [], 'error' => 'No se encontró la cabecera del reporte (¿permisos del usuario Vicidial?).'];
        }

        return ['ok' => true, 'agents' => $agents, 'error' => ''];
    }
}

if (!defined('VICIDIAL_UNCODED_PAUSE')) {
    /**
     * Nombre interno del bucket de "pausa sin código": el tiempo que el agente
     * pasa en pausa sin haber elegido un código. Vicidial lo devuelve en una
     * columna del CSV cuyo encabezado viene VACÍO, así que le ponemos nombre
     * nosotros para poder verlo, reportarlo y decidir si se paga.
     *
     * Verificado contra la API (95 filas, 5 días): para todo agente se cumple
     *   NONPAUSE + PAUSE = TOTAL   y   suma(códigos con nombre) + SIN_CODIGO = PAUSE
     * al segundo exacto.
     */
    define('VICIDIAL_UNCODED_PAUSE', 'SIN_CODIGO');
}

if (!function_exists('vicidialFetchPauseBreakdown')) {
    /**
     * Descarga el "PAUSE CODE BREAKDOWN" (file_download=2) del Agent Performance
     * Detail. Da, por agente: NONPAUSE (tiempo trabajando) + segundos por CADA
     * código de pausa (Bao, Break, Coachi, Digita, ITRes, LAGGED, LOGIN, NXDIAL,
     * wasapi, ...). Los códigos se detectan dinámicamente del encabezado (si el
     * cliente agrega uno nuevo, se importa igual).
     *
     * Las columnas SIN encabezado se agregan bajo VICIDIAL_UNCODED_PAUSE en vez
     * de descartarse: son pausa real (hasta 1h47m en un día para un agente) y
     * antes quedaban invisibles y sin pagar.
     *
     * `mismatches` lista los agentes cuyo desglose NO cuadra con PAUSE/TOTAL
     * (parseo sospechoso). Es dinero: se registra en el log de sincronización.
     *
     * @return array{ok:bool, agents:array<string,array{nonpause:int,pause_total:int,total:int,codes:array<string,int>}>, mismatches:array<int,string>, error:string}
     */
    function vicidialFetchPauseBreakdown(array $cfg, string $date): array
    {
        $res = vicidialApiRequest($cfg, 'AST_agent_performance_detail.php', [
            'query_date'    => $date,
            'end_date'      => $date,
            'query_time'    => '00:00',
            'end_time'      => '23:59',
            'user_group[]'  => '--ALL--',
            'users[]'       => '--ALL--',
            'group[]'       => '--ALL--',
            'shift'         => 'ALL',
            'type'          => 'TEXT',
            'DB'            => '0',
            'SUBMIT'        => 'SUBMIT',
            'file_download' => '2',
        ]);

        if (!$res['ok']) {
            return ['ok' => false, 'agents' => [], 'mismatches' => [], 'error' => $res['error']];
        }

        // Columnas que NO son códigos de pausa
        $nonCodeCols = ['USER NAME', 'ID', 'CURRENT USER GROUP', 'MOST RECENT USER GROUP', 'TOTAL', 'NONPAUSE', 'PAUSE'];
        $header = null;
        $colIdx = [];
        $codeCols = [];   // nombre del código => índice de columna
        $uncodedIdx = []; // índices de columnas con encabezado vacío (pausa sin código)
        $agents = [];
        $mismatches = [];

        foreach (preg_split('/\r\n|\r|\n/', $res['body']) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map(static fn($c) => trim((string) $c), str_getcsv($line));

            if ($header === null) {
                if (in_array('USER NAME', $cells, true) && in_array('NONPAUSE', $cells, true)) {
                    $header = $cells;
                    foreach ($header as $i => $name) {
                        $name = trim($name);
                        if ($name === '') {
                            // Columna sin encabezado: pausa que el agente tomó sin
                            // elegir código. Se agrega, no se descarta.
                            if ($i >= 4) {
                                $uncodedIdx[] = $i;
                            }
                            continue;
                        }
                        $colIdx[$name] = $i;
                        if (!in_array($name, $nonCodeCols, true)) {
                            $codeCols[$name] = $i; // es un código de pausa
                        }
                    }
                }
                continue;
            }

            $get = static function (string $col) use ($cells, $colIdx) {
                return isset($colIdx[$col], $cells[$colIdx[$col]]) ? $cells[$colIdx[$col]] : '';
            };
            $vu = $get('ID');
            if ($vu === '' || strtoupper($get('USER NAME')) === 'TOTALS' || in_array('TOTALS', $cells, true)) {
                continue;
            }

            $codes = [];
            foreach ($codeCols as $code => $i) {
                $sec = vicidialParseHmsToSeconds($cells[$i] ?? '');
                if ($sec > 0) {
                    $codes[$code] = $sec;
                }
            }
            $uncoded = 0;
            foreach ($uncodedIdx as $i) {
                $uncoded += vicidialParseHmsToSeconds($cells[$i] ?? '');
            }
            if ($uncoded > 0) {
                $codes[VICIDIAL_UNCODED_PAUSE] = $uncoded;
            }

            $nonpause = vicidialParseHmsToSeconds($get('NONPAUSE'));
            $pause    = vicidialParseHmsToSeconds($get('PAUSE'));
            $total    = vicidialParseHmsToSeconds($get('TOTAL'));

            // Cuadre aritmético. Si no cierra, el desglose no es de fiar y no se
            // debe pagar a ciegas: se reporta para revisión manual.
            if (abs(array_sum($codes) - $pause) > 2 || abs(($nonpause + $pause) - $total) > 2) {
                $mismatches[] = $vu;
            }

            $agents[$vu] = [
                'nonpause'    => $nonpause,
                'pause_total' => $pause,
                'total'       => $total,
                'codes'       => $codes,
            ];
        }

        if ($header === null) {
            return ['ok' => false, 'agents' => [], 'mismatches' => [], 'error' => 'No se encontró el desglose de pausas (file_download=2).'];
        }
        return ['ok' => true, 'agents' => $agents, 'mismatches' => $mismatches, 'error' => ''];
    }
}

if (!function_exists('vicidialFetchTimeSheet')) {
    /**
     * Descarga la hoja de tiempo (CSV, file_download=2) de UN agente para un día.
     * Devuelve first_login/last_activity CRUDOS (hora local de Vicidial) y el
     * total logueado en segundos. La conversión de TZ se hace en el importador.
     *
     * @return array{ok:bool, first_login:?string, last_activity:?string, total_seconds:int, error:string}
     */
    function vicidialFetchTimeSheet(array $cfg, string $agent, string $date): array
    {
        $res = vicidialApiRequest($cfg, 'AST_agent_time_sheet.php', [
            'query_date'    => $date,
            'end_date'      => $date,
            'agent'         => $agent,
            'file_download' => '2',
            'SUBMIT'        => 'SUBMIT',
        ]);

        if (!$res['ok']) {
            return ['ok' => false, 'first_login' => null, 'last_activity' => null, 'total_seconds' => 0, 'error' => $res['error']];
        }

        $firstLogin = null;
        $lastActivity = null;
        $totalSeconds = 0;

        foreach (preg_split('/\r\n|\r|\n/', $res['body']) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line);
            $cells = array_map(static fn($c) => trim((string) $c), $cells);

            foreach ($cells as $i => $cell) {
                $label = strtoupper(rtrim($cell, ':'));
                $next = $cells[$i + 1] ?? '';
                if ($label === 'FIRST LOGIN' && $next !== '') {
                    $firstLogin = $next;
                } elseif ($label === 'LAST LOG ACTIVITY' && $next !== '') {
                    $lastActivity = $next;
                } elseif ($label === 'TOTAL LOGGED-IN TIME' && $next !== '') {
                    $totalSeconds = vicidialParseHmsToSeconds($next);
                }
            }
        }

        return [
            'ok'            => true,
            'first_login'   => $firstLogin,
            'last_activity' => $lastActivity,
            'total_seconds' => $totalSeconds,
            'error'         => '',
        ];
    }
}

if (!function_exists('vicidialShiftToLocal')) {
    /**
     * Suma el offset (minutos) a un datetime crudo de Vicidial para llevarlo a
     * hora local de RD. Devuelve 'Y-m-d H:i:s' o null.
     */
    function vicidialShiftToLocal(?string $rawDateTime, int $offsetMinutes): ?string
    {
        $rawDateTime = trim((string) $rawDateTime);
        if ($rawDateTime === '') {
            return null;
        }
        $ts = strtotime($rawDateTime);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts + $offsetMinutes * 60);
    }
}

if (!function_exists('vicidialAutoSeedUserMap')) {
    /**
     * Siembra vicidial_user_map para agentes aún no mapeados, intentando
     * emparejar por nombre completo normalizado contra users.full_name. Los
     * emparejamientos automáticos quedan con auto_matched=1 para que el admin
     * los revise. Devuelve cuántos mapeos nuevos se crearon.
     */
    function vicidialAutoSeedUserMap(PDO $pdo, array $agents): int
    {
        if (empty($agents)) {
            return 0;
        }

        // Usuarios ponche activos (para el matcher por tokens)
        $users = [];
        try {
            $users = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('vicidialAutoSeedUserMap users: ' . $e->getMessage());
            return 0;
        }

        // Usuarios Vicidial ya presentes en el mapa (para contar solo los nuevos)
        $existing = [];
        try {
            foreach ($pdo->query("SELECT vicidial_user FROM vicidial_user_map")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $vu) {
                $existing[$vu] = true;
            }
        } catch (PDOException $e) {
            error_log('vicidialAutoSeedUserMap map: ' . $e->getMessage());
            return 0;
        }

        // Inserta el mapeo. En filas ya existentes solo refresca la sugerencia
        // si NO ha sido confirmada a mano (auto_matched = 1) y si hay match; una
        // vez el admin la confirma/edita (auto_matched = 0), queda intocable.
        $insert = $pdo->prepare("
            INSERT INTO vicidial_user_map (vicidial_user, vicidial_name, user_id, auto_matched)
            VALUES (:vu, :vn, :uid, 1)
            ON DUPLICATE KEY UPDATE
                vicidial_name = VALUES(vicidial_name),
                user_id = IF(auto_matched = 1 AND VALUES(user_id) IS NOT NULL, VALUES(user_id), user_id)
        ");

        $newCount = 0;
        foreach ($agents as $agent) {
            $vu = $agent['vicidial_user'];
            if ($vu === '') {
                continue;
            }
            $match = vicidialBestUserMatch($agent['vicidial_name'] ?? '', $users);
            try {
                $insert->execute([
                    ':vu'  => $vu,
                    ':vn'  => $agent['vicidial_name'] ?? '',
                    ':uid' => $match['user_id'],
                ]);
                if (!isset($existing[$vu])) {
                    $existing[$vu] = true;
                    $newCount++;
                }
            } catch (PDOException $e) {
                error_log('vicidialAutoSeedUserMap insert ' . $vu . ': ' . $e->getMessage());
            }
        }

        return $newCount;
    }
}

if (!function_exists('vicidialGetUserMap')) {
    /**
     * Devuelve vicidial_user => ['user_id'=>?int, 'ignore'=>bool].
     */
    function vicidialGetUserMap(PDO $pdo): array
    {
        $map = [];
        try {
            $stmt = $pdo->query("SELECT vicidial_user, user_id, ignore_agent FROM vicidial_user_map");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $map[$r['vicidial_user']] = [
                    'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                    'ignore'  => (int) $r['ignore_agent'] === 1,
                ];
            }
        } catch (PDOException $e) {
            error_log('vicidialGetUserMap: ' . $e->getMessage());
        }
        return $map;
    }
}

if (!function_exists('ensureVicidialStatusBreakdownColumn')) {
    /**
     * Garantiza la columna `vicidial_agent_timesheet.status_breakdown` (JSON con
     * los conteos por disposición: {"SALE":12,"PEDIDO":5,"NOCAL":30,...}).
     * Auto-sana el esquema para que un deploy a otra BD no rompa el upsert con
     * "Unknown column". Corre una sola vez por request.
     */
    function ensureVicidialStatusBreakdownColumn(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) { return; }
        $checked = true;
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM vicidial_agent_timesheet LIKE 'status_breakdown'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE vicidial_agent_timesheet ADD COLUMN status_breakdown TEXT NULL AFTER pause_breakdown");
            }
        } catch (Throwable $e) {
            error_log('ensureVicidialStatusBreakdownColumn: ' . $e->getMessage());
        }
    }
}

if (!function_exists('importVicidialDay')) {
    /**
     * Orquestador de la Fase 1: importa un día completo.
     *   1. Descarga el performance detail (todos los agentes).
     *   2. Auto-siembra el mapeo de usuarios nuevos.
     *   3. Para cada agente relevante (grupo configurado + tiempo mínimo, no
     *      ignorado) descarga su hoja de tiempo.
     *   4. Aplica el offset de TZ y hace upsert en vicidial_agent_timesheet.
     *   5. Registra la corrida en vicidial_sync_log.
     *
     * NO toca la nómina. Modo sombra puro.
     *
     * @return array Resumen de la corrida.
     */
    function importVicidialDay(PDO $pdo, array $cfg, string $date, string $triggeredBy = 'cron', bool $light = false): array
    {
        // $light = modo intradía: NO pide las hojas de tiempo por-agente (lo pesado);
        // solo refresca la actividad acumulada (calls/talk/pausas/nonpause/dispo) con
        // 2 llamadas para TODOS los agentes. El login/logout se preserva de la última
        // corrida completa (nocturna) — no se pisa.
        $startedAt = microtime(true);
        ensureVicidialStatusBreakdownColumn($pdo); // desglose de disposiciones (conversiones)
        $offsetMin = (int) ($cfg['vicidial_tz_offset_minutes'] ?? 0);
        $minTime   = max(0, (int) ($cfg['vicidial_sync_min_time_seconds'] ?? 60));

        // Grupos permitidos (vacío = todos)
        $groupsRaw = trim((string) ($cfg['vicidial_sync_user_groups'] ?? ''));
        $allowedGroups = [];
        if ($groupsRaw !== '') {
            foreach (preg_split('/[,;\s]+/', $groupsRaw) as $g) {
                $g = trim($g);
                if ($g !== '') {
                    $allowedGroups[strtoupper($g)] = true;
                }
            }
        }

        $summary = [
            'date'               => $date,
            'status'             => 'ok',
            'agents_in_report'   => 0,
            'timesheets_fetched' => 0,
            'rows_upserted'      => 0,
            'new_mappings'       => 0,
            'errors'            => [],
        ];

        // 1. Performance detail
        $perf = vicidialFetchPerformanceDetail($cfg, $date);
        if (!$perf['ok']) {
            $summary['status'] = 'error';
            $summary['errors'][] = 'Performance detail: ' . $perf['error'];
            vicidialWriteSyncLog($pdo, $date, $summary, $triggeredBy, (int) round((microtime(true) - $startedAt) * 1000));
            return $summary;
        }
        $summary['agents_in_report'] = count($perf['agents']);

        // 1b. Desglose de pausas (NONPAUSE + códigos por agente). Una sola llamada
        // para todos los agentes del día. Si falla, seguimos sin él (marca parcial).
        $pauseByUser = [];
        $pb = vicidialFetchPauseBreakdown($cfg, $date);
        if ($pb['ok']) {
            $pauseByUser = $pb['agents'];
            if (!empty($pb['mismatches'])) {
                // El desglose no cuadra con PAUSE/TOTAL para estos agentes. Se
                // importa igual (dato crudo), pero queda constancia: sus horas
                // pagables pueden estar mal y hay que revisarlas a mano.
                $summary['errors'][] = 'Desglose de pausas no cuadra para: ' . implode(', ', $pb['mismatches']);
                $summary['status'] = 'partial';
            }
        } else {
            $summary['errors'][] = 'Pause breakdown: ' . $pb['error'];
            $summary['status'] = 'partial';
        }

        // 2. Auto-seed del mapeo
        $summary['new_mappings'] = vicidialAutoSeedUserMap($pdo, $perf['agents']);
        $userMap = vicidialGetUserMap($pdo);

        // Upsert de la hoja de tiempo
        $upsert = $pdo->prepare("
            INSERT INTO vicidial_agent_timesheet
                (report_date, vicidial_user, vicidial_name, user_group, user_id,
                 first_login, last_activity, total_logged_seconds, nonpause_seconds, pause_breakdown, status_breakdown,
                 calls, talk_seconds, pause_seconds, wait_seconds, dispo_seconds,
                 tz_offset_applied_minutes, raw_first_login, raw_last_activity, source)
            VALUES
                (:report_date, :vicidial_user, :vicidial_name, :user_group, :user_id,
                 :first_login, :last_activity, :total_logged_seconds, :nonpause_seconds, :pause_breakdown, :status_breakdown,
                 :calls, :talk_seconds, :pause_seconds, :wait_seconds, :dispo_seconds,
                 :tz_offset, :raw_first_login, :raw_last_activity, 'api')
            ON DUPLICATE KEY UPDATE
                vicidial_name        = VALUES(vicidial_name),
                user_group           = VALUES(user_group),
                user_id              = VALUES(user_id),
                first_login          = IF(:ts_known = 1, VALUES(first_login), first_login),
                last_activity        = IF(:ts_known = 1, VALUES(last_activity), last_activity),
                total_logged_seconds = IF(:total_known = 1, VALUES(total_logged_seconds), total_logged_seconds),
                nonpause_seconds     = IF(:pause_known = 1, VALUES(nonpause_seconds), nonpause_seconds),
                pause_breakdown      = IF(:pause_known = 1, VALUES(pause_breakdown), pause_breakdown),
                status_breakdown     = VALUES(status_breakdown),
                calls                = VALUES(calls),
                talk_seconds         = VALUES(talk_seconds),
                pause_seconds        = VALUES(pause_seconds),
                wait_seconds         = VALUES(wait_seconds),
                dispo_seconds        = VALUES(dispo_seconds),
                tz_offset_applied_minutes = IF(:ts_known = 1, VALUES(tz_offset_applied_minutes), tz_offset_applied_minutes),
                raw_first_login      = IF(:ts_known = 1, VALUES(raw_first_login), raw_first_login),
                raw_last_activity    = IF(:ts_known = 1, VALUES(raw_last_activity), raw_last_activity),
                source               = 'api'
        ");

        foreach ($perf['agents'] as $agent) {
            $vu = $agent['vicidial_user'];
            $mapEntry = $userMap[$vu] ?? ['user_id' => null, 'ignore' => false];

            if ($mapEntry['ignore']) {
                continue;
            }
            // Filtrar por grupo si está configurado
            if (!empty($allowedGroups)) {
                $grp = strtoupper((string) $agent['user_group']);
                if (!isset($allowedGroups[$grp])) {
                    continue;
                }
            }

            // Desglose de pausas del agente (si vino en el file_download=2). Si el
            // fetch de pausas falló o el agente no vino, $pauseKnown=0 y el upsert
            // NO sobrescribe nonpause_seconds/pause_breakdown (preserva lo ya
            // importado) para no borrar datos buenos con 0 en un re-import con
            // throttle (429). Es dinero: esas horas alimentan la nómina.
            $pauseInfo = $pauseByUser[$vu] ?? null;
            $pauseKnown = $pauseInfo !== null ? 1 : 0;

            // Filtrar agentes con tiempo insignificante (ruido). Se mide contra el
            // tiempo LOGUEADO (TOTAL del desglose), NO contra TIME (tiempo en
            // llamadas): un agente puede pasar la jornada entera en coaching o en
            // WhatsApp sin tomar una sola llamada, y esas horas se pagan. Medir
            // contra TIME descartaba su fila completa y le pagaba 0.
            $loggedSeconds = $pauseInfo !== null
                ? (int) ($pauseInfo['total'] ?? 0)
                : (int) $agent['time_seconds']; // sin desglose: se conserva el criterio viejo
            if ($loggedSeconds < $minTime) {
                continue;
            }

            // 3. Hoja de tiempo del agente (login/logout). En modo liviano se OMITE
            // (es 1 llamada por agente = lo pesado); el login se preserva vía :ts_known.
            if ($light) {
                $ts = ['ok' => false, 'first_login' => null, 'last_activity' => null, 'total_seconds' => 0];
                $tsKnown = 0;
            } else {
                $ts = vicidialFetchTimeSheet($cfg, $vu, $date);
                if (!$ts['ok']) {
                    $summary['errors'][] = 'Timesheet ' . $vu . ': ' . $ts['error'];
                    $summary['status'] = 'partial';
                    // Aun así guardamos los totales de actividad sin la ventana de login
                } else {
                    $summary['timesheets_fetched']++;
                }
                $tsKnown = $ts['ok'] ? 1 : 0;
            }

            $firstLocal = vicidialShiftToLocal($ts['first_login'] ?? null, $offsetMin);
            $lastLocal  = vicidialShiftToLocal($ts['last_activity'] ?? null, $offsetMin);

            $nonpauseSeconds = $pauseInfo ? (int) ($pauseInfo['nonpause'] ?? 0) : 0;
            $pauseJson = ($pauseInfo && !empty($pauseInfo['codes'])) ? json_encode($pauseInfo['codes'], JSON_UNESCAPED_UNICODE) : null;

            // Tiempo logueado. La hoja de tiempo (corrida nocturna completa) es la
            // fuente autoritativa; en modo liviano NO se descarga, así que se usa el
            // TOTAL del desglose de pausas, que ya tenemos y cuadra exacto
            // (NONPAUSE + PAUSE = TOTAL). Antes quedaba en 0 durante todo el día y
            // el portal le mostraba al agente "Total logueado 0:00" junto a sus
            // horas pagables reales.
            $tsTotal = ($ts['ok'] ?? false) ? (int) ($ts['total_seconds'] ?? 0) : 0;
            $totalLogged = $tsTotal > 0 ? $tsTotal : $loggedSeconds;
            $totalKnown = $totalLogged > 0 ? 1 : 0; // nunca pisar un valor bueno con 0

            // Desglose de disposiciones/estados del agente (conteos por código),
            // del mismo reporte de performance (file_download=1). Alimenta las
            // métricas de conversión en los Reportes Vicidial en modo sync.
            $statusJson = !empty($agent['dispositions']) ? json_encode($agent['dispositions'], JSON_UNESCAPED_UNICODE) : null;

            try {
                $upsert->execute([
                    ':report_date'          => $date,
                    ':vicidial_user'        => $vu,
                    ':vicidial_name'        => $agent['vicidial_name'],
                    ':user_group'           => $agent['user_group'],
                    ':user_id'              => $mapEntry['user_id'],
                    ':first_login'          => $firstLocal,
                    ':last_activity'        => $lastLocal,
                    ':total_logged_seconds' => $totalLogged,
                    ':nonpause_seconds'     => $nonpauseSeconds,
                    ':pause_breakdown'      => $pauseJson,
                    ':status_breakdown'     => $statusJson,
                    ':pause_known'          => $pauseKnown,
                    ':ts_known'             => $tsKnown,
                    ':total_known'          => $totalKnown,
                    ':calls'                => $agent['calls'],
                    ':talk_seconds'         => $agent['talk_seconds'],
                    ':pause_seconds'        => $agent['pause_seconds'],
                    ':wait_seconds'         => $agent['wait_seconds'],
                    ':dispo_seconds'        => $agent['dispo_seconds'],
                    ':tz_offset'            => $offsetMin,
                    ':raw_first_login'      => $ts['first_login'] ?? null,
                    ':raw_last_activity'    => $ts['last_activity'] ?? null,
                ]);
                $summary['rows_upserted']++;
            } catch (PDOException $e) {
                $summary['errors'][] = 'Upsert ' . $vu . ': ' . $e->getMessage();
                $summary['status'] = 'partial';
            }
        }

        vicidialWriteSyncLog($pdo, $date, $summary, $triggeredBy, (int) round((microtime(true) - $startedAt) * 1000));
        return $summary;
    }
}

if (!function_exists('vicidialWriteSyncLog')) {
    /**
     * Persiste una fila en vicidial_sync_log.
     */
    function vicidialWriteSyncLog(PDO $pdo, string $date, array $summary, string $triggeredBy, int $durationMs): void
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO vicidial_sync_log
                    (target_date, status, agents_in_report, timesheets_fetched, rows_upserted,
                     new_mappings, duration_ms, triggered_by, message)
                VALUES
                    (:d, :status, :air, :tf, :ru, :nm, :dur, :tb, :msg)
            ");
            $msg = empty($summary['errors']) ? 'OK' : implode(' | ', array_slice($summary['errors'], 0, 20));
            $stmt->execute([
                ':d'      => $date,
                ':status' => $summary['status'],
                ':air'    => $summary['agents_in_report'],
                ':tf'     => $summary['timesheets_fetched'],
                ':ru'     => $summary['rows_upserted'],
                ':nm'     => $summary['new_mappings'],
                ':dur'    => $durationMs,
                ':tb'     => $triggeredBy,
                ':msg'    => mb_substr($msg, 0, 60000),
            ]);
        } catch (PDOException $e) {
            error_log('vicidialWriteSyncLog: ' . $e->getMessage());
        }
    }
}
