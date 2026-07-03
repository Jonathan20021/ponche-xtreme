<?php
/**
 * Motor de estado EN VIVO de Vicidial (Monitor del supervisor - Fase 2).
 *
 * Fuente: AST_timeonVDADall.php (una sola llamada devuelve TODOS los agentes
 * logueados con su estado en tiempo real). Se parsea la tabla ASCII dentro del
 * bloque <PRE>.
 *
 * Arquitectura anti-throttle y a prueba de fallos:
 *   - El monitor SIEMPRE lee de la tabla local `vicidial_live_status` (rápido).
 *   - `vicidialGetLiveStatus()` refresca desde Vicidial solo si el caché está
 *     viejo (TTL) y logrando un GET_LOCK compartido, así que aunque haya N
 *     supervisores (oficina + HostGator contra la MISMA BD) se consulta a
 *     Vicidial a lo sumo UNA vez por ventana.
 *   - Si el refresco falla (Vicidial caído, IP no autorizada en HostGator, etc.)
 *     NO se borra el snapshot anterior y el dashboard sigue mostrando ponche
 *     normal + el último estado conocido. Nunca rompe el monitor existente.
 */

require_once __DIR__ . '/vicidial_api_client.php';

if (!function_exists('vicidialParseMmssToSeconds')) {
    /**
     * "MM:SS" o "HH:MM:SS" -> segundos. Vacío/invalid -> 0.
     */
    function vicidialParseMmssToSeconds(?string $value): int
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^\d{1,3}:\d{2}(:\d{2})?$/', $value)) {
            return 0;
        }
        $p = array_map('intval', explode(':', $value));
        return count($p) === 3 ? $p[0] * 3600 + $p[1] * 60 + $p[2] : $p[0] * 60 + $p[1];
    }
}

if (!function_exists('vicidialNormalizeLiveStatus')) {
    /**
     * Normaliza el estado crudo de la grilla (columna "STATUS PAUSE") a un
     * enum + etiqueta + color para el badge. Devuelve:
     *   ['status','pause_code','label','color','group']
     * group: on_call | available | paused | dispo | dead | other
     */
    function vicidialNormalizeLiveStatus(string $rawCell): array
    {
        $cell = trim($rawCell);
        $upper = strtoupper($cell);

        // PAUSED puede traer motivo: "PAUSED   Break"
        if (strpos($upper, 'PAUSED') === 0) {
            $pause = trim(substr($cell, 6));
            return [
                'status'     => 'PAUSADO',
                'pause_code' => $pause !== '' ? $pause : null,
                'label'      => $pause !== '' ? ('Pausa · ' . $pause) : 'Pausa',
                'color'      => '#f59e0b',
                'group'      => 'paused',
            ];
        }
        // Primera palabra del estado
        $word = strtoupper(strtok($upper, ' '));
        $onCall    = ['INCALL', 'QUEUE', 'CLOSER', '3WAY', 'INCALLQUEUE', 'MANUAL'];
        $available = ['READY', 'WAITING'];

        if (in_array($word, $onCall, true)) {
            return ['status' => 'EN_LLAMADA', 'pause_code' => null, 'label' => 'En llamada', 'color' => '#10b981', 'group' => 'on_call'];
        }
        if (in_array($word, $available, true)) {
            return ['status' => 'DISPONIBLE', 'pause_code' => null, 'label' => 'Disponible', 'color' => '#38bdf8', 'group' => 'available'];
        }
        if (strpos($word, 'DISPO') === 0) {
            return ['status' => 'DISPO', 'pause_code' => null, 'label' => 'Post-llamada', 'color' => '#818cf8', 'group' => 'dispo'];
        }
        if (strpos($word, 'DEAD') === 0) {
            return ['status' => 'LLAMADA_MUERTA', 'pause_code' => null, 'label' => 'Llamada muerta', 'color' => '#ef4444', 'group' => 'dead'];
        }
        if ($word === 'LOGIN' || $word === '') {
            return ['status' => 'CONECTADO', 'pause_code' => null, 'label' => 'Conectado', 'color' => '#38bdf8', 'group' => 'available'];
        }
        // Desconocido: mostrar crudo sin romper
        return ['status' => $word, 'pause_code' => null, 'label' => ucfirst(strtolower($word)), 'color' => '#94a3b8', 'group' => 'other'];
    }
}

if (!function_exists('vicidialFetchLiveGrid')) {
    /**
     * Descarga y parsea AST_timeonVDADall.php. Devuelve:
     *   ['ok'=>bool, 'agents'=>[ ['station','name','session','raw_status','status',
     *      'pause_code','label','color','group','seconds_in_status','campaign',
     *      'calls','inbound_calls'] ...], 'error'=>string]
     */
    function vicidialFetchLiveGrid(array $cfg): array
    {
        // Timeout corto propio del live (no frenar el dashboard)
        $liveCfg = $cfg;
        $liveCfg['vicidial_sync_http_timeout'] = (int) ($cfg['vicidial_live_http_timeout'] ?? 8);

        $res = vicidialApiRequest($liveCfg, 'AST_timeonVDADall.php', []);
        if (!$res['ok']) {
            return ['ok' => false, 'agents' => [], 'error' => $res['error']];
        }

        if (!preg_match('/<PRE>(.*?)<\/PRE>/is', $res['body'], $m)) {
            return ['ok' => false, 'agents' => [], 'error' => 'No se encontró la grilla en vivo (¿formato cambiado?).'];
        }
        $pre = html_entity_decode(preg_replace('/<[^>]+>/', '', $m[1]));

        $agents = [];
        foreach (preg_split('/\r\n|\r|\n/', $pre) as $line) {
            if (!preg_match('/^\s*\|/', $line) || strpos($line, '+--') !== false) {
                continue;
            }
            $c = array_map('trim', explode('|', $line));
            // c[1]=station c[2]=name c[3]=session c[4]=status c[5]=mm:ss c[6]=campaign c[7]=calls c[8]=inbound
            if (!isset($c[7])) {
                continue;
            }
            if (strtoupper($c[1]) === 'STATION' || $c[2] === '' || stripos($c[2], 'SHOW ID') !== false) {
                continue; // cabecera
            }
            $name = rtrim($c[2], " +\t");
            $norm = vicidialNormalizeLiveStatus($c[4]);
            $agents[] = [
                'station'           => $c[1],
                'name'              => $name,
                'session'           => $c[3] ?? '',
                'raw_status'        => $c[4],
                'status'            => $norm['status'],
                'pause_code'        => $norm['pause_code'],
                'label'             => $norm['label'],
                'color'             => $norm['color'],
                'group'             => $norm['group'],
                'seconds_in_status' => vicidialParseMmssToSeconds($c[5] ?? ''),
                'campaign'          => $c[6] ?? '',
                'calls'             => (int) ($c[7] ?? 0),
                'inbound_calls'     => (int) ($c[8] ?? 0),
            ];
        }

        return ['ok' => true, 'agents' => $agents, 'error' => ''];
    }
}

if (!function_exists('vicidialRefreshLiveStatus')) {
    /**
     * Refresca el caché en vivo desde Vicidial. En ÉXITO reemplaza el snapshot y
     * actualiza meta; en FALLO conserva el snapshot anterior y marca source_ok=0.
     * Siempre actualiza last_attempt_at para respetar el TTL de reintento.
     */
    function vicidialRefreshLiveStatus(PDO $pdo, array $cfg): array
    {
        $grid = vicidialFetchLiveGrid($cfg);

        if (!$grid['ok']) {
            try {
                $pdo->prepare("UPDATE vicidial_live_meta SET last_attempt_at = NOW(), source_ok = 0, message = ? WHERE id = 1")
                    ->execute([mb_substr($grid['error'], 0, 250)]);
            } catch (PDOException $e) {
                error_log('vicidialRefreshLiveStatus meta: ' . $e->getMessage());
            }
            return ['ok' => false, 'count' => 0, 'error' => $grid['error']];
        }

        // Mapear cada nombre en vivo -> user_id (contra usuarios activos)
        $users = [];
        try {
            $users = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('vicidialRefreshLiveStatus users: ' . $e->getMessage());
        }

        $counts = ['on_call' => 0, 'available' => 0, 'paused' => 0, 'dispo' => 0];
        $now = date('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM vicidial_live_status");
            $ins = $pdo->prepare("
                INSERT INTO vicidial_live_status
                    (user_id, vicidial_name, station, session_id, status, pause_code, raw_status,
                     seconds_in_status, campaign, calls, inbound_calls, snapshot_at)
                VALUES (:uid, :name, :station, :session, :status, :pause, :raw, :secs, :camp, :calls, :inb, :snap)
            ");
            foreach ($grid['agents'] as $a) {
                $match = vicidialBestUserMatch($a['name'], $users);
                $ins->execute([
                    ':uid'     => $match['user_id'],
                    ':name'    => $a['name'],
                    ':station' => $a['station'],
                    ':session' => $a['session'],
                    ':status'  => $a['status'],
                    ':pause'   => $a['pause_code'],
                    ':raw'     => $a['raw_status'],
                    ':secs'    => $a['seconds_in_status'],
                    ':camp'    => $a['campaign'],
                    ':calls'   => $a['calls'],
                    ':inb'     => $a['inbound_calls'],
                    ':snap'    => $now,
                ]);
                if (isset($counts[$a['group']])) {
                    $counts[$a['group']]++;
                }
            }
            $pdo->prepare("
                UPDATE vicidial_live_meta
                SET last_attempt_at = NOW(), last_success_at = NOW(), source_ok = 1,
                    logged_in = ?, in_call = ?, paused = ?, waiting = ?, dispo = ?, message = 'OK'
                WHERE id = 1
            ")->execute([count($grid['agents']), $counts['on_call'], $counts['paused'], $counts['available'], $counts['dispo']]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('vicidialRefreshLiveStatus write: ' . $e->getMessage());
            return ['ok' => false, 'count' => 0, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'count' => count($grid['agents']), 'error' => ''];
    }
}

if (!function_exists('vicidialGetLiveStatus')) {
    /**
     * Devuelve el estado en vivo para el dashboard, refrescando si hace falta.
     * NUNCA lanza: ante cualquier error devuelve lo que haya en caché.
     *
     * @return array [
     *   'by_user' => [user_id => rowLive],
     *   'meta'    => ['fetched_at','age_seconds','fresh','source_ok','counts'=>[...]],
     *   'enabled' => bool
     * ]
     */
    function vicidialGetLiveStatus(PDO $pdo, ?array $cfg = null): array
    {
        $empty = ['by_user' => [], 'meta' => ['fetched_at' => null, 'age_seconds' => null, 'fresh' => false, 'source_ok' => false, 'counts' => []], 'enabled' => false];

        try {
            $cfg = $cfg ?? getVicidialSyncConfig($pdo);
        } catch (Throwable $e) {
            return $empty;
        }

        if (($cfg['vicidial_live_enabled'] ?? '1') !== '1') {
            return $empty;
        }

        $ttl = max(5, (int) ($cfg['vicidial_live_ttl_seconds'] ?? 25));

        try {
            // ¿Está viejo el caché? (basado en el último intento, para no martillar)
            // La edad se calcula con el reloj de la BD (TIMESTAMPDIFF/NOW), no con
            // el de PHP: el laptop de dev y el MySQL remoto de HostGator difieren
            // varios minutos, y con time() el TTL daba edades negativas.
            $metaSql = "SELECT source_ok, logged_in, in_call, paused, waiting, dispo, last_success_at,
                               TIMESTAMPDIFF(SECOND, last_attempt_at, NOW()) AS age_attempt,
                               TIMESTAMPDIFF(SECOND, last_success_at, NOW()) AS age_success
                        FROM vicidial_live_meta WHERE id = 1";
            $meta = $pdo->query($metaSql)->fetch(PDO::FETCH_ASSOC) ?: [];
            $ageAttempt = ($meta['age_attempt'] ?? null) !== null ? (int) $meta['age_attempt'] : PHP_INT_MAX;

            if ($ageAttempt >= $ttl) {
                // Intentar refrescar bajo lock compartido; si otro lo tiene, seguimos con caché.
                $got = (int) $pdo->query("SELECT GET_LOCK('vicidial_live_refresh', 0)")->fetchColumn();
                if ($got === 1) {
                    try {
                        vicidialRefreshLiveStatus($pdo, $cfg);
                    } finally {
                        $pdo->query("SELECT RELEASE_LOCK('vicidial_live_refresh')");
                    }
                    $meta = $pdo->query($metaSql)->fetch(PDO::FETCH_ASSOC) ?: [];
                }
            }

            // Leer el snapshot (mapeado por user_id)
            $byUser = [];
            $rows = $pdo->query("SELECT user_id, vicidial_name, station, status, pause_code, seconds_in_status, campaign, calls, inbound_calls, snapshot_at FROM vicidial_live_status WHERE user_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                // Reconstruir label/color desde el status normalizado (no se guarda para no duplicar)
                $vis = vicidialLiveStatusVisual($r['status'], $r['pause_code']);
                $byUser[(int) $r['user_id']] = [
                    'status'            => $r['status'],
                    'label'             => $vis['label'],
                    'color'             => $vis['color'],
                    'pause_code'        => $r['pause_code'],
                    'seconds_in_status' => (int) $r['seconds_in_status'],
                    'campaign'          => $r['campaign'],
                    'calls'             => (int) $r['calls'],
                    'inbound_calls'     => (int) $r['inbound_calls'],
                ];
            }

            $lastSuccess = $meta['last_success_at'] ?? null;
            $ageSuccess = ($meta['age_success'] ?? null) !== null ? (int) $meta['age_success'] : null;
            return [
                'by_user' => $byUser,
                'meta'    => [
                    'fetched_at'  => $lastSuccess,
                    'age_seconds' => $ageSuccess,
                    'fresh'       => $ageSuccess !== null && $ageSuccess <= ($ttl * 3),
                    'source_ok'   => (int) ($meta['source_ok'] ?? 0) === 1,
                    'counts'      => [
                        'logged_in' => (int) ($meta['logged_in'] ?? 0),
                        'on_call'   => (int) ($meta['in_call'] ?? 0),
                        'paused'    => (int) ($meta['paused'] ?? 0),
                        'available' => (int) ($meta['waiting'] ?? 0),
                        'dispo'     => (int) ($meta['dispo'] ?? 0),
                    ],
                ],
                'enabled' => true,
            ];
        } catch (Throwable $e) {
            error_log('vicidialGetLiveStatus: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('vicidialLiveStatusVisual')) {
    /**
     * Etiqueta + color a partir del status normalizado guardado (sin re-parsear).
     */
    function vicidialLiveStatusVisual(string $status, ?string $pauseCode): array
    {
        switch ($status) {
            case 'EN_LLAMADA':     return ['label' => 'En llamada', 'color' => '#10b981'];
            case 'DISPONIBLE':     return ['label' => 'Disponible', 'color' => '#38bdf8'];
            case 'CONECTADO':      return ['label' => 'Conectado', 'color' => '#38bdf8'];
            case 'PAUSADO':        return ['label' => $pauseCode ? ('Pausa · ' . $pauseCode) : 'Pausa', 'color' => '#f59e0b'];
            case 'DISPO':          return ['label' => 'Post-llamada', 'color' => '#818cf8'];
            case 'LLAMADA_MUERTA': return ['label' => 'Llamada muerta', 'color' => '#ef4444'];
            default:               return ['label' => ucfirst(strtolower($status)), 'color' => '#94a3b8'];
        }
    }
}
