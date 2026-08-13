<?php
/**
 * Despachador de los reportes diarios.
 *
 * PROBLEMA QUE RESUELVE: los reportes diarios (reclutamiento, inventario, nómina,
 * tardanzas, ausencias, etc.) estaban construidos y habilitados en settings.php,
 * pero NADA los disparaba: en activity_logs solo había envíos "manualmente" desde
 * el botón de settings. El único automático era el de ausencias, y venía de un
 * truco de carga de página (lib/auto_cron_trigger.php) que dejó de dispararse.
 *
 * CÓMO FUNCIONA: esta tarea corre cada 5 minutos. Para cada reporte revisa en
 * system_settings si está habilitado, si hoy toca (fin de semana) y si estamos en
 * su hora configurada (±5 min, la misma tolerancia que ya trae cada cron). Solo
 * entonces ejecuta su script. Un marcador en daily_report_runs garantiza UN envío
 * por reporte por día, así que da igual que la ventana abarque dos corridas o que
 * la tarea de Windows y el cron de cPanel estén activos a la vez.
 *
 * Cada reporte sigue siendo dueño de su propia lógica: aquí no se duplica nada
 * más que la compuerta de "¿toca ahora?".
 *
 * Tarea de Windows: la registra run_vicidial_sync.bat install
 *   (PoncheXtreme-DailyReports, cada 5 min de 5:55 AM a 11:55 PM).
 *
 * Cron de cPanel (alternativa, hora RD):
 *   *\/5 * * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_daily_reports.php >> /home2/hhempeos/logs/daily_reports.log 2>&1
 *
 * Uso manual:
 *   php cron_daily_reports.php              -> corrida normal (respeta horas y marcadores)
 *   php cron_daily_reports.php --status     -> solo muestra qué está pendiente hoy
 *   php cron_daily_reports.php --force=recruitment  -> fuerza uno, ignorando hora y marcador
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Santo_Domingo');

$logPrefix = '[CRON DAILY REPORTS] ';

/**
 * report_key => script. El prefijo de settings es "<report_key>_report_".
 * Para agregar un reporte nuevo basta añadirlo aquí.
 */
$REPORTS = [
    'absence'             => 'cron_daily_absence_report.php',
    'activity_logs'       => 'cron_daily_activity_logs_report.php',
    'executive_dashboard' => 'cron_daily_executive_dashboard_report.php',
    'ghl'                 => 'cron_daily_ghl_report.php',
    'inventory'           => 'cron_daily_inventory_report.php',
    'login_hours'         => 'cron_daily_login_hours_report.php',
    'login_logs'          => 'cron_daily_login_logs_report.php',
    'payroll'             => 'cron_daily_payroll_report.php',
    'quality_alerts'      => 'cron_daily_quality_alerts_report.php',
    'recruitment'         => 'cron_daily_recruitment_report.php',
    'tardiness'           => 'cron_daily_tardiness_report.php',
    'wasapi'              => 'cron_daily_wasapi_report.php',
    'workforce'           => 'cron_daily_workforce_report.php',
    // El semanal de horas extra se despacha aquí igual que los diarios: el
    // propio script valida que sea el día de la semana configurado.
    'overtime'            => 'cron_weekly_overtime_report.php',
    'over8h'              => 'cron_daily_over8h_report.php',
    // Control de horas: barre excepciones del día anterior y manda el resumen
    // de impacto económico (generado / modificado / pendiente / excepciones).
    'timesheet'           => 'cron_daily_timesheet_report.php',
];

// Argumentos
$statusOnly = false;
$forceKey   = null;
foreach ($argv ?? [] as $arg) {
    if ($arg === '--status') {
        $statusOnly = true;
    } elseif (strpos($arg, '--force=') === 0) {
        $forceKey = substr($arg, 8);
    }
}
if (php_sapi_name() !== 'cli') {
    $statusOnly = isset($_GET['status']);
    $forceKey   = $_GET['force'] ?? null;
}

/** Marcador de "ya se envió hoy". Sin él, la ventana de ±5 min puede abarcar dos corridas. */
function dailyReportsEnsureTable(PDO $pdo): bool
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `daily_report_runs` (
              `report_key` VARCHAR(50) NOT NULL,
              `run_date` DATE NOT NULL,
              `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `exit_code` INT NOT NULL DEFAULT 0,
              PRIMARY KEY (`report_key`, `run_date`),
              KEY `idx_daily_report_runs_date` (`run_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        error_log('dailyReportsEnsureTable: ' . $e->getMessage());
        return false;
    }
}

function dailyReportAlreadyRan(PDO $pdo, string $key, string $date): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_report_runs WHERE report_key = ? AND run_date = ?");
        $stmt->execute([$key, $date]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        // Sin marcador no se puede garantizar "una vez al día": mejor no enviar
        // que mandar el mismo reporte cada 5 minutos.
        error_log('dailyReportAlreadyRan: ' . $e->getMessage());
        return true;
    }
}

function dailyReportMarkRan(PDO $pdo, string $key, string $date, int $exitCode): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO daily_report_runs (report_key, run_date, exit_code)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE sent_at = NOW(), exit_code = VALUES(exit_code)
        ");
        $stmt->execute([$key, $date, $exitCode]);
    } catch (Throwable $e) {
        error_log('dailyReportMarkRan: ' . $e->getMessage());
    }
}

/**
 * @return array{enabled:bool, time:string, exclude_weekends:bool}
 */
function dailyReportSettings(PDO $pdo, string $key): array
{
    $prefix = $key . '_report_';
    $out = ['enabled' => false, 'time' => '08:00', 'exclude_weekends' => true];
    try {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (?, ?, ?)");
        $stmt->execute([$prefix . 'enabled', $prefix . 'time', $prefix . 'exclude_weekends']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $suffix = substr($row['setting_key'], strlen($prefix));
            $value  = (string) ($row['setting_value'] ?? '');
            if ($suffix === 'enabled') {
                $out['enabled'] = ($value === '1');
            } elseif ($suffix === 'time' && preg_match('/^\d{1,2}:\d{2}/', $value)) {
                $out['time'] = $value;
            } elseif ($suffix === 'exclude_weekends') {
                $out['exclude_weekends'] = ($value !== '0');
            }
        }
    } catch (Throwable $e) {
        error_log('dailyReportSettings: ' . $e->getMessage());
    }
    return $out;
}

function dailyReportMinutesFromNow(string $configuredTime): int
{
    $parts = explode(':', $configuredTime);
    $cfgMinutes = ((int) ($parts[0] ?? 8)) * 60 + (int) ($parts[1] ?? 0);
    $nowMinutes = ((int) date('H')) * 60 + (int) date('i');
    return abs($nowMinutes - $cfgMinutes);
}

function dailyReportResolvePhpBinary(): string
{
    if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_file(PHP_BINARY)) {
        return PHP_BINARY;
    }
    return 'php';
}

echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

if (!dailyReportsEnsureTable($pdo)) {
    echo $logPrefix . "ERROR: no se pudo preparar daily_report_runs. Saliendo.\n";
    exit(1);
}

$today       = date('Y-m-d');
$isWeekend   = in_array((int) date('N'), [6, 7], true);
$php         = dailyReportResolvePhpBinary();
$ran = 0; $skipped = 0; $failed = 0;

foreach ($REPORTS as $key => $script) {
    $path = __DIR__ . '/' . $script;
    $forced = ($forceKey !== null && $forceKey === $key);

    if ($forceKey !== null && !$forced) {
        continue;
    }

    if (!is_file($path)) {
        echo $logPrefix . "  $key: FALTA el script $script\n";
        $skipped++;
        continue;
    }

    $cfg = dailyReportSettings($pdo, $key);
    $diff = dailyReportMinutesFromNow($cfg['time']);

    if ($statusOnly) {
        $already = dailyReportAlreadyRan($pdo, $key, $today);
        printf(
            "%s  %-20s enviado_hoy=%-3s habilitado=%-3s hora=%-6s dif=%3d min  finde_excluido=%s\n",
            $logPrefix, $key, $already ? 'si' : 'no', $cfg['enabled'] ? 'si' : 'no',
            $cfg['time'], $diff, $cfg['exclude_weekends'] ? 'si' : 'no'
        );
        continue;
    }

    if (!$forced) {
        if (!$cfg['enabled']) {
            $skipped++;
            continue;
        }
        if ($cfg['exclude_weekends'] && $isWeekend) {
            $skipped++;
            continue;
        }
        if ($diff > 5) {
            $skipped++;
            continue;
        }
        if (dailyReportAlreadyRan($pdo, $key, $today)) {
            echo $logPrefix . "  $key: ya se envió hoy, se omite.\n";
            $skipped++;
            continue;
        }
    }

    echo $logPrefix . "  $key: ejecutando $script (hora configurada {$cfg['time']})...\n";

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($path);
    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    foreach ($output as $line) {
        echo $logPrefix . '    | ' . $line . "\n";
    }

    if ($exitCode === 0) {
        // Exit 0 también cubre "sin actividad, no envío": en ambos casos el
        // reporte quedó resuelto para hoy y no debe reintentarse cada 5 min.
        // Una corrida forzada (--force) NO marca: es para probar, y no debe
        // bloquear el envío real de ese mismo día.
        if (!$forced) {
            dailyReportMarkRan($pdo, $key, $today, 0);
        }
        echo $logPrefix . "  $key: OK" . ($forced ? ' (forzado, no cuenta como envío del día)' : '') . "\n";
        $ran++;
    } else {
        // Sin marcador: si fue un fallo transitorio de correo, el próximo tick reintenta.
        echo $logPrefix . "  $key: FALLO (exit $exitCode), se reintentará en la próxima corrida dentro de la ventana.\n";
        $failed++;
    }
}

if ($statusOnly) {
    echo $logPrefix . "Hora actual: " . date('H:i') . ($isWeekend ? " (fin de semana)\n" : "\n");
    exit(0);
}

echo $logPrefix . "Resumen: ejecutados=$ran  omitidos=$skipped  fallidos=$failed\n";
echo $logPrefix . 'Done at ' . date('Y-m-d H:i:s') . "\n";
exit($failed > 0 ? 1 : 0);
