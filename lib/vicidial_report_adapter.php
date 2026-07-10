<?php
/**
 * Adaptador de fuente para los Reportes Vicidial (vicidial_reports.php + APIs).
 *
 * Enruta las consultas a UNA de dos fuentes, según el setting
 * `vicidial_reports_source` (default 'sync'):
 *   - 'sync' → tabla `vicidial_agent_timesheet` (sincronización nocturna automática por API).
 *   - 'csv'  → tabla `vicidial_login_stats` (subida manual de CSV, sistema legado).
 *
 * IMPORTANTE — brecha de datos: la sincronización automática trae todo lo de
 * tiempo/actividad (llamadas, logueo, talk, pausa, wait, dispo) PERO NO los
 * códigos de disposición por-estado (sale, pedido, orden, nocal, silenc…) que
 * solo venían en el CSV. En modo 'sync' esos campos salen en 0 y las métricas
 * de conversión quedan marcadas como no disponibles (ver vicidialReportsHasDispositions()).
 * El resto (ocupación, eficiencia, productividad, AHT, distribución de tiempo,
 * volumen de llamadas) sí es exacto desde el sync.
 *
 * Las filas devueltas tienen EXACTAMENTE la misma forma que el GROUP BY legado
 * sobre vicidial_login_stats, para que la página y los endpoints cambien lo mínimo.
 */

require_once __DIR__ . '/../db.php';

if (!function_exists('vicidialReportsSource')) {
    /** 'sync' (timesheet automático) | 'csv' (subida manual). Default 'sync'. */
    function vicidialReportsSource(PDO $pdo): string
    {
        try {
            $v = strtolower(trim((string) getSystemSetting($pdo, 'vicidial_reports_source', 'sync')));
        } catch (Throwable $e) {
            $v = 'sync';
        }
        return $v === 'csv' ? 'csv' : 'sync';
    }
}

if (!function_exists('vicidialReportsUsingSync')) {
    function vicidialReportsUsingSync(PDO $pdo): bool
    {
        return vicidialReportsSource($pdo) === 'sync';
    }
}

if (!function_exists('vicidialReportsHasDispositions')) {
    /**
     * ¿La fuente actual trae códigos de disposición (conversiones)? Solo el CSV.
     * En modo sync, las métricas de conversión/contacto no están disponibles.
     */
    function vicidialReportsHasDispositions(PDO $pdo): bool
    {
        // CSV siempre trae disposiciones. En modo sync, solo si el desglose de
        // estados YA se está capturando (columna status_breakdown con datos). Así,
        // si aún no se ha re-sincronizado, degrada elegante a "no disponible".
        if (vicidialReportsSource($pdo) === 'csv') {
            return true;
        }
        try {
            $v = $pdo->query("SELECT 1 FROM vicidial_agent_timesheet WHERE status_breakdown IS NOT NULL AND status_breakdown <> '' LIMIT 1")->fetchColumn();
            return $v !== false;
        } catch (Throwable $e) {
            return false; // la columna aún no existe → sin disposiciones
        }
    }
}

if (!function_exists('vicidialFetchStatusRows')) {
    /**
     * Filas crudas de desglose de estados (disposiciones) del sync, ya decodificadas:
     *   [ ['vicidial_user'=>, 'report_date'=>, 'codes'=>['SALE'=>12,'NOCAL'=>30,...]], ... ]
     * Solo agentes no-ignorados. A prueba de fallos (si la columna no existe → []).
     */
    function vicidialFetchStatusRows(PDO $pdo, string $startDate, string $endDate, string $campaign = ''): array
    {
        $out = [];
        try {
            $filter = $campaign !== '' ? 'AND t.user_group = :campaign' : '';
            $stmt = $pdo->prepare("
                SELECT t.vicidial_user, t.report_date, t.status_breakdown
                FROM vicidial_agent_timesheet t
                LEFT JOIN vicidial_user_map m ON m.vicidial_user = t.vicidial_user
                WHERE t.report_date BETWEEN :start_date AND :end_date
                  AND COALESCE(m.ignore_agent, 0) = 0
                  AND t.status_breakdown IS NOT NULL AND t.status_breakdown <> ''
                $filter
            ");
            $params = ['start_date' => $startDate, 'end_date' => $endDate];
            if ($campaign !== '') { $params['campaign'] = $campaign; }
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $codes = json_decode((string) $r['status_breakdown'], true);
                if (!is_array($codes)) { continue; }
                $out[] = ['vicidial_user' => $r['vicidial_user'], 'report_date' => $r['report_date'], 'codes' => $codes];
            }
        } catch (Throwable $e) {
            error_log('vicidialFetchStatusRows: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('vicidialReportsDispositionTotals')) {
    /**
     * Totales por código de disposición (sumados sobre todos los agentes) para el
     * gráfico de disposiciones. Devuelve ['SALE'=>N, 'PEDIDO'=>N, ...]. Vacío si el
     * sync aún no captura disposiciones (o en CSV usar la consulta legada).
     */
    function vicidialReportsDispositionTotals(PDO $pdo, string $startDate, string $endDate, string $campaign = ''): array
    {
        $totals = [];
        foreach (vicidialFetchStatusRows($pdo, $startDate, $endDate, $campaign) as $row) {
            foreach ($row['codes'] as $code => $n) {
                $code = strtoupper(trim((string) $code));
                $totals[$code] = ($totals[$code] ?? 0) + (int) $n;
            }
        }
        return $totals;
    }
}

if (!function_exists('vicidialReportsAgentStats')) {
    /**
     * Estadísticas agregadas por agente para un rango (y campaña opcional).
     * Devuelve filas con la MISMA forma en ambas fuentes:
     *   user_name, user_id, current_user_group, total_calls, time_total,
     *   pause_time, wait_time, talk_time, dispo_time, dead_time, customer_time,
     *   sale, pedido, orden, other_dispositions
     * (en modo sync los últimos 4 = 0).
     */
    function vicidialReportsAgentStats(PDO $pdo, string $startDate, string $endDate, string $campaign = ''): array
    {
        return vicidialReportsUsingSync($pdo)
            ? vicidialReportsAgentStatsSync($pdo, $startDate, $endDate, $campaign)
            : vicidialReportsAgentStatsCsv($pdo, $startDate, $endDate, $campaign);
    }
}

if (!function_exists('vicidialReportsAgentStatsCsv')) {
    /** Legado: vicidial_login_stats (subida manual). */
    function vicidialReportsAgentStatsCsv(PDO $pdo, string $startDate, string $endDate, string $campaign = ''): array
    {
        $filter = $campaign !== '' ? 'AND current_user_group = :campaign' : '';
        $stmt = $pdo->prepare("
            SELECT
                user_name, user_id, current_user_group,
                SUM(calls) AS total_calls,
                SUM(time_total) AS time_total,
                SUM(pause_time) AS pause_time,
                SUM(wait_time) AS wait_time,
                SUM(talk_time) AS talk_time,
                SUM(dispo_time) AS dispo_time,
                SUM(dead_time) AS dead_time,
                SUM(customer_time) AS customer_time,
                SUM(sale) AS sale,
                SUM(pedido) AS pedido,
                SUM(orden) AS orden,
                SUM(nocal + silenc) AS no_contact,
                SUM(active + a + b + callbk + colgo + cortad + dair + dc + `dec` + deposi + dnc + duplic + n + ni + nocal + nocon + notie + np + numeq + pregun + promo + ptrans + pu + quejas + reserv + seguim + silenc + wasapi + xfer) AS other_dispositions
            FROM vicidial_login_stats
            WHERE upload_date BETWEEN :start_date AND :end_date
            $filter
            GROUP BY user_name, user_id, current_user_group
            ORDER BY total_calls DESC
        ");
        $params = ['start_date' => $startDate, 'end_date' => $endDate];
        if ($campaign !== '') { $params['campaign'] = $campaign; }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('vicidialReportsAgentStatsSync')) {
    /**
     * Nuevo: vicidial_agent_timesheet (sync automático). Excluye agentes marcados
     * ignore_agent en el mapa. dead_time se deriva (logueado − trabajando − pausa).
     * Los códigos de disposición no existen aquí → 0.
     */
    function vicidialReportsAgentStatsSync(PDO $pdo, string $startDate, string $endDate, string $campaign = ''): array
    {
        $filter = $campaign !== '' ? 'AND t.user_group = :campaign' : '';
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(t.vicidial_name), ''), t.vicidial_user) AS user_name,
                t.vicidial_user AS user_id,
                t.user_group AS current_user_group,
                SUM(t.calls) AS total_calls,
                SUM(t.total_logged_seconds) AS time_total,
                -- La pausa real del día es TOTAL - NONPAUSE (invariante del desglose de
                -- pausas). `pause_seconds` viene del OTRO reporte (tiempos por llamada) y
                -- no cuadra con el día: usarlo aquí daba dead_time absurdos (una agente
                -- sin llamadas salía con 9h de tiempo muerto).
                SUM(GREATEST(0, t.total_logged_seconds - t.nonpause_seconds)) AS pause_time,
                SUM(t.wait_seconds) AS wait_time,
                SUM(t.talk_seconds) AS talk_time,
                SUM(t.dispo_seconds) AS dispo_time,
                SUM(GREATEST(0, t.nonpause_seconds - t.wait_seconds - t.talk_seconds - t.dispo_seconds)) AS dead_time,
                0 AS customer_time,
                0 AS sale, 0 AS pedido, 0 AS orden, 0 AS no_contact, 0 AS other_dispositions
            FROM vicidial_agent_timesheet t
            LEFT JOIN vicidial_user_map m ON m.vicidial_user = t.vicidial_user
            WHERE t.report_date BETWEEN :start_date AND :end_date
              AND COALESCE(m.ignore_agent, 0) = 0
            $filter
            GROUP BY t.vicidial_user, t.vicidial_name, t.user_group
            ORDER BY total_calls DESC
        ");
        $params = ['start_date' => $startDate, 'end_date' => $endDate];
        if ($campaign !== '') { $params['campaign'] = $campaign; }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Inyectar disposiciones por agente (agregadas en PHP desde status_breakdown,
        // sin depender de funciones JSON de SQL). Clave = vicidial_user (= 'user_id').
        $dispoByUser = [];
        foreach (vicidialFetchStatusRows($pdo, $startDate, $endDate, $campaign) as $sr) {
            $vu = $sr['vicidial_user'];
            if (!isset($dispoByUser[$vu])) {
                $dispoByUser[$vu] = ['sale' => 0, 'pedido' => 0, 'orden' => 0, 'no_contact' => 0, 'other' => 0];
            }
            foreach ($sr['codes'] as $code => $n) {
                $c = strtoupper(trim((string) $code));
                $n = (int) $n;
                if ($c === 'SALE') { $dispoByUser[$vu]['sale'] += $n; }
                elseif ($c === 'PEDIDO') { $dispoByUser[$vu]['pedido'] += $n; }
                elseif ($c === 'ORDEN') { $dispoByUser[$vu]['orden'] += $n; }
                elseif ($c === 'NOCAL' || $c === 'SILENC') { $dispoByUser[$vu]['no_contact'] += $n; }
                else { $dispoByUser[$vu]['other'] += $n; }
            }
        }
        foreach ($rows as &$r) {
            $vu = $r['user_id'] ?? '';
            if (isset($dispoByUser[$vu])) {
                $r['sale']               = $dispoByUser[$vu]['sale'];
                $r['pedido']             = $dispoByUser[$vu]['pedido'];
                $r['orden']              = $dispoByUser[$vu]['orden'];
                $r['no_contact']         = $dispoByUser[$vu]['no_contact'];
                $r['other_dispositions'] = $dispoByUser[$vu]['other'];
            }
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('vicidialReportsTrendsByDate')) {
    /**
     * Serie por fecha: [ ['date'=>Y-m-d, 'calls'=>int, 'conversions'=>int], ... ].
     * En modo sync conversions = 0 (sin disposiciones).
     */
    function vicidialReportsTrendsByDate(PDO $pdo, string $startDate, string $endDate, string $campaign = ''): array
    {
        $usingSync = vicidialReportsUsingSync($pdo);
        if ($usingSync) {
            $filter = $campaign !== '' ? 'AND t.user_group = :campaign' : '';
            $stmt = $pdo->prepare("
                SELECT t.report_date AS date, SUM(t.calls) AS calls
                FROM vicidial_agent_timesheet t
                LEFT JOIN vicidial_user_map m ON m.vicidial_user = t.vicidial_user
                WHERE t.report_date BETWEEN :start_date AND :end_date
                  AND COALESCE(m.ignore_agent, 0) = 0
                $filter
                GROUP BY t.report_date
                ORDER BY t.report_date ASC
            ");
        } else {
            $filter = $campaign !== '' ? 'AND current_user_group = :campaign' : '';
            $stmt = $pdo->prepare("
                SELECT upload_date AS date, SUM(calls) AS calls, SUM(sale + pedido + orden) AS conversions
                FROM vicidial_login_stats
                WHERE upload_date BETWEEN :start_date AND :end_date
                $filter
                GROUP BY upload_date
                ORDER BY upload_date ASC
            ");
        }
        $params = ['start_date' => $startDate, 'end_date' => $endDate];
        if ($campaign !== '') { $params['campaign'] = $campaign; }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($usingSync) {
            // Conversiones por fecha desde status_breakdown (PHP; SALE+PEDIDO+ORDEN).
            $convByDate = [];
            foreach (vicidialFetchStatusRows($pdo, $startDate, $endDate, $campaign) as $sr) {
                $d = $sr['report_date'];
                $c = (int) ($sr['codes']['SALE'] ?? 0) + (int) ($sr['codes']['PEDIDO'] ?? 0) + (int) ($sr['codes']['ORDEN'] ?? 0);
                $convByDate[$d] = ($convByDate[$d] ?? 0) + $c;
            }
            foreach ($rows as &$r) {
                $r['conversions'] = $convByDate[$r['date']] ?? 0;
            }
            unset($r);
        }
        return $rows;
    }
}

if (!function_exists('vicidialReportsCampaigns')) {
    /** Lista de campañas (user_group) disponibles en la fuente activa para el rango. */
    function vicidialReportsCampaigns(PDO $pdo, string $startDate, string $endDate): array
    {
        try {
            if (vicidialReportsUsingSync($pdo)) {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT user_group AS campaign
                    FROM vicidial_agent_timesheet
                    WHERE report_date BETWEEN ? AND ? AND user_group IS NOT NULL AND user_group <> ''
                    ORDER BY campaign
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT current_user_group AS campaign
                    FROM vicidial_login_stats
                    WHERE upload_date BETWEEN ? AND ? AND current_user_group IS NOT NULL AND current_user_group <> ''
                    ORDER BY campaign
                ");
            }
            $stmt->execute([$startDate, $endDate]);
            return array_map(static fn($r) => $r['campaign'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            error_log('vicidialReportsCampaigns: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('vicidialReportsDateBounds')) {
    /** Rango de fechas con datos en la fuente activa: ['min'=>, 'max'=>]. */
    function vicidialReportsDateBounds(PDO $pdo): array
    {
        try {
            if (vicidialReportsUsingSync($pdo)) {
                $row = $pdo->query("SELECT MIN(report_date) AS min_dt, MAX(report_date) AS max_dt FROM vicidial_agent_timesheet")->fetch(PDO::FETCH_ASSOC);
            } else {
                $row = $pdo->query("SELECT MIN(min_date) AS min_dt, MAX(max_date) AS max_dt FROM vicidial_uploads")->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            $row = null;
        }
        return [
            'min' => $row['min_dt'] ?? date('Y-m-01'),
            'max' => $row['max_dt'] ?? date('Y-m-t'),
        ];
    }
}
