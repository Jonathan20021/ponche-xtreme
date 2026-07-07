<?php
/**
 * lib/vicidial_recordings.php
 *
 * Trae las grabaciones de llamadas de cada agente desde Vicidial (vía
 * non_agent_api.php?function=recording_lookup) y las guarda como METADATO en
 * la tabla vicidial_recordings. El AUDIO no se almacena: se transmite bajo
 * demanda con agent_recording.php (proxy), que descarga desde el host de la API
 * con las credenciales del servidor (el agente nunca ve la URL ni las claves).
 *
 * Formato de recording_lookup (stage=pipe, duration=Y):
 *   FECHA HORA | agente | recording_id | lead_id | duración_seg | URL_mp3
 */

require_once __DIR__ . '/vicidial_api_client.php';

if (!function_exists('ensureVicidialRecordingsTable')) {
    function ensureVicidialRecordingsTable(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `vicidial_recordings` (
                  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `recording_id`   INT UNSIGNED NULL,
                  `user_id`        INT UNSIGNED NULL,
                  `vicidial_user`  VARCHAR(60) NOT NULL,
                  `lead_id`        INT UNSIGNED NULL,
                  `call_datetime`  DATETIME NULL,
                  `call_date`      DATE NULL,
                  `length_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
                  `filename`       VARCHAR(255) NOT NULL,
                  `customer_phone` VARCHAR(40) NULL,
                  `imported_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_filename` (`filename`),
                  KEY `idx_user_date` (`user_id`, `call_date`),
                  KEY `idx_vuser_date` (`vicidial_user`, `call_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            error_log('ensureVicidialRecordingsTable: ' . $e->getMessage());
        }
        $done = true;
    }
}

if (!function_exists('vicidialRecordingsEnabled')) {
    function vicidialRecordingsEnabled(PDO $pdo): bool
    {
        return (string) getSystemSetting($pdo, 'vicidial_recordings_enabled', '1') === '1';
    }
}

if (!function_exists('vicidialRecordingsMinSeconds')) {
    /** Duración mínima (seg) para considerar "conversación real" y mostrarla/guardarla. */
    function vicidialRecordingsMinSeconds(PDO $pdo): int
    {
        return max(0, (int) getSystemSetting($pdo, 'vicidial_recordings_min_seconds', 30));
    }
}

if (!function_exists('vicidialRecordingsRetentionDays')) {
    /** Días de historial que guarda la tabla (0 = sin límite). */
    function vicidialRecordingsRetentionDays(PDO $pdo): int
    {
        return max(0, (int) getSystemSetting($pdo, 'vicidial_recordings_retention_days', 60));
    }
}

if (!function_exists('vicidialBuildRecordingUrl')) {
    /**
     * URL real de la grabación en el host de la API (HTTPS), NO la http:// a la IP
     * bloqueada que devuelve recording_lookup. Los MP3 viven en /RECORDINGS/MP3/ del
     * web root (no bajo /vicidial).
     */
    function vicidialBuildRecordingUrl(array $cfg, string $filename): string
    {
        $parts = parse_url((string) $cfg['vicidial_api_base_url']);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        return $scheme . '://' . $host . '/RECORDINGS/MP3/' . rawurlencode($filename);
    }
}

if (!function_exists('vicidialFetchAgentRecordings')) {
    /**
     * Lista las grabaciones de UN agente en una fecha (>= $minSeconds).
     * @return array{ok:bool, error?:string, rows:array<int,array<string,mixed>>}
     */
    function vicidialFetchAgentRecordings(array $cfg, string $agentUser, string $date, int $minSeconds = 0): array
    {
        $r = vicidialApiRequest($cfg, 'non_agent_api.php', [
            'function'   => 'recording_lookup',
            'source'     => $cfg['vicidial_api_source'],
            'user'       => $cfg['vicidial_api_user'],
            'pass'       => $cfg['vicidial_api_pass'],
            'agent_user' => $agentUser,
            'date'       => $date,
            'stage'      => 'pipe',
            'duration'   => 'Y',
            'records'    => '100000',
        ]);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => ($r['error'] ?: ('http ' . $r['http'])), 'rows' => []];
        }
        $body = trim((string) $r['body']);
        // Vicidial devuelve "ERROR: ..." o vacío cuando no hay grabaciones.
        if ($body === '' || stripos($body, 'ERROR') === 0) {
            return ['ok' => true, 'rows' => []];
        }
        $rows = [];
        foreach (explode("\n", $body) as $line) {
            $p = explode('|', trim($line));
            if (count($p) < 6) {
                continue;
            }
            $len = (int) $p[4];
            if ($len < $minSeconds) {
                continue;
            }
            $file = basename(trim($p[5]));
            if ($file === '' || !preg_match('~\.(mp3|wav|gsm)$~i', $file)) {
                continue;
            }
            $phone = null;
            if (preg_match('~_(\d{5,})-~', $file, $mp)) {
                $phone = $mp[1];
            }
            $dt = trim($p[0]);
            $rows[] = [
                'recording_id'   => ((int) $p[2]) ?: null,
                'lead_id'        => ((int) $p[3]) ?: null,
                'call_datetime'  => $dt,
                'length_seconds' => $len,
                'filename'       => $file,
                'customer_phone' => $phone,
            ];
        }
        return ['ok' => true, 'rows' => $rows];
    }
}

if (!function_exists('importVicidialRecordingsDay')) {
    /**
     * Importa (upsert) las grabaciones de todos los agentes Vicidial activos y
     * mapeados para una fecha. Idempotente (uq_filename).
     * @return array{agents:int, rows:int, errors:array<int,string>}
     */
    function importVicidialRecordingsDay(PDO $pdo, array $cfg, string $date): array
    {
        ensureVicidialRecordingsTable($pdo);
        $min = vicidialRecordingsMinSeconds($pdo);
        $summary = ['agents' => 0, 'rows' => 0, 'errors' => []];

        $agents = $pdo->query("
            SELECT m.vicidial_user, m.user_id
            FROM vicidial_user_map m
            JOIN users u ON u.id = m.user_id
            JOIN employees e ON e.user_id = u.id
            WHERE m.ignore_agent = 0 AND m.user_id IS NOT NULL
              AND e.employment_status IN ('ACTIVE', 'TRIAL')
            GROUP BY m.vicidial_user, m.user_id
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $up = $pdo->prepare("
            INSERT INTO vicidial_recordings
                (recording_id, user_id, vicidial_user, lead_id, call_datetime, call_date, length_seconds, filename, customer_phone)
            VALUES
                (:rid, :uid, :vu, :lead, :dt, :cd, :len, :file, :phone)
            ON DUPLICATE KEY UPDATE
                recording_id = VALUES(recording_id), user_id = VALUES(user_id), lead_id = VALUES(lead_id),
                call_datetime = VALUES(call_datetime), call_date = VALUES(call_date),
                length_seconds = VALUES(length_seconds), customer_phone = VALUES(customer_phone)
        ");

        foreach ($agents as $a) {
            $summary['agents']++;
            $res = vicidialFetchAgentRecordings($cfg, (string) $a['vicidial_user'], $date, $min);
            if (empty($res['ok'])) {
                $summary['errors'][] = $a['vicidial_user'] . ': ' . ($res['error'] ?? 'error');
                continue;
            }
            foreach ($res['rows'] as $row) {
                try {
                    $up->execute([
                        ':rid'   => $row['recording_id'],
                        ':uid'   => (int) $a['user_id'],
                        ':vu'    => $a['vicidial_user'],
                        ':lead'  => $row['lead_id'],
                        ':dt'    => $row['call_datetime'],
                        ':cd'    => substr((string) $row['call_datetime'], 0, 10),
                        ':len'   => $row['length_seconds'],
                        ':file'  => $row['filename'],
                        ':phone' => $row['customer_phone'],
                    ]);
                    $summary['rows']++;
                } catch (Throwable $e) {
                    $summary['errors'][] = $row['filename'] . ': ' . $e->getMessage();
                }
            }
            usleep(150000); // cortesía anti-throttle entre agentes
        }

        // Limpieza de historial viejo (mantiene la tabla acotada).
        $ret = vicidialRecordingsRetentionDays($pdo);
        if ($ret > 0) {
            try {
                $del = $pdo->prepare("DELETE FROM vicidial_recordings WHERE call_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
                $del->execute([$ret]);
            } catch (Throwable $e) {
                // no crítico
            }
        }

        return $summary;
    }
}
