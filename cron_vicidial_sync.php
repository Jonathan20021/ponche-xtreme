<?php
/**
 * Cron Job: Sincronización Vicidial (Fase 1 - MODO SOMBRA)
 *
 * Importa por la API de Vicidial la hoja de tiempo (login/logout/total) y los
 * totales de actividad de cada agente del DÍA ANTERIOR y los guarda en
 * vicidial_agent_timesheet para conciliarlos contra la marcación manual de
 * ponche. NO toca la nómina.
 *
 * Cron sugerido (todas las mañanas 01:30 hora RD, cuando ya cerró el día):
 *   30 1 * * * /usr/bin/php /home/hhempeos/public_html/cron_vicidial_sync.php
 *
 * O vía web (con key):
 *   30 1 * * * wget -q -O - 'https://punch.evallishbpo.com/cron_vicidial_sync.php?cron_key=ponche_xtreme_2025'
 *
 * Backfill manual (rellenar días pasados):
 *   php cron_vicidial_sync.php --date=2026-07-02
 *   php cron_vicidial_sync.php --from=2026-06-16 --to=2026-06-30
 *   ...?cron_key=ponche_xtreme_2025&date=2026-07-02
 *   ...?cron_key=ponche_xtreme_2025&from=2026-06-16&to=2026-06-30
 */

// Permitir CLI o disparo web autenticado
if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/vicidial_api_client.php';

date_default_timezone_set('America/Santo_Domingo');

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$logPrefix = '[CRON VICIDIAL SYNC] ';
$out = static function (string $msg) use ($logPrefix) {
    echo $logPrefix . $msg . "\n";
    @flush();
};

/**
 * Lee un argumento tanto de CLI (--key=value) como de GET (?key=value).
 */
$arg = static function (string $key) use ($isCli): ?string {
    if ($isCli) {
        foreach ($_SERVER['argv'] ?? [] as $a) {
            if (strpos($a, "--$key=") === 0) {
                return substr($a, strlen("--$key="));
            }
        }
        return null;
    }
    return isset($_GET[$key]) ? (string) $_GET[$key] : null;
};

$validDate = static function (?string $d): bool {
    return $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
};

try {
    $out('Iniciando ' . date('Y-m-d H:i:s'));

    $cfg = getVicidialSyncConfig($pdo);

    if (($cfg['vicidial_sync_enabled'] ?? '0') !== '1') {
        $out('Sincronización Vicidial DESHABILITADA en settings.php. Saliendo.');
        exit(0);
    }

    // Resolver el rango de fechas a importar
    $dates = [];
    $single = $arg('date');
    $from   = $arg('from');
    $to     = $arg('to');

    if ($validDate($single)) {
        $dates[] = $single;
    } elseif ($validDate($from) && $validDate($to)) {
        $cursor = strtotime($from);
        $end    = strtotime($to);
        if ($cursor > $end) {
            $out('Rango inválido: from > to.');
            exit(1);
        }
        // Tope de seguridad: 62 días por corrida
        $guard = 0;
        while ($cursor <= $end && $guard < 62) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
            $guard++;
        }
    } else {
        // Default: ayer
        $dates[] = date('Y-m-d', strtotime('yesterday'));
    }

    $out('Días a importar: ' . implode(', ', $dates));
    $triggeredBy = $isCli ? 'cli' : 'cron';

    $grandTotals = ['agents' => 0, 'timesheets' => 0, 'rows' => 0, 'maps' => 0, 'errors' => 0];

    foreach ($dates as $date) {
        $out("--- Importando $date ---");
        $summary = importVicidialDay($pdo, $cfg, $date, $triggeredBy);

        $out("  Estado:            {$summary['status']}");
        $out("  Agentes en reporte:{$summary['agents_in_report']}");
        $out("  Timesheets:        {$summary['timesheets_fetched']}");
        $out("  Filas guardadas:   {$summary['rows_upserted']}");
        $out("  Mapeos nuevos:     {$summary['new_mappings']}");
        if (!empty($summary['errors'])) {
            $out('  Errores (' . count($summary['errors']) . '):');
            foreach (array_slice($summary['errors'], 0, 10) as $e) {
                $out('    - ' . $e);
            }
        }

        $grandTotals['agents']     += $summary['agents_in_report'];
        $grandTotals['timesheets'] += $summary['timesheets_fetched'];
        $grandTotals['rows']       += $summary['rows_upserted'];
        $grandTotals['maps']       += $summary['new_mappings'];
        $grandTotals['errors']     += count($summary['errors']);

        // Cortesía anti-throttle si es rango largo (evita el 429 de LiteSpeed)
        if (count($dates) > 1) {
            usleep(400000);
        }
    }

    $out('=== TOTALES ===');
    $out("  Agentes: {$grandTotals['agents']} · Timesheets: {$grandTotals['timesheets']} · Filas: {$grandTotals['rows']} · Mapeos nuevos: {$grandTotals['maps']} · Errores: {$grandTotals['errors']}");
    $out('Finalizado ' . date('Y-m-d H:i:s'));
    exit(0);
} catch (Throwable $e) {
    $out('EXCEPCIÓN: ' . $e->getMessage());
    error_log($logPrefix . $e->getMessage());
    exit(1);
}
