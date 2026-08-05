<?php
/**
 * Cron: aplica los cambios de salario programados cuya fecha efectiva ya llegó.
 *
 * Un cambio con fecha futura NO toca `users` hasta el día: hasta entonces se
 * sigue pagando el salario actual, y la nómina de un período que cruce esa fecha
 * ya paga cada día con lo que le toca. Este cron es el que vuelca el salario
 * nuevo a `users` cuando amanece el día, para que el portal del agente, los
 * reportes y cualquier otra pantalla vean lo correcto.
 *
 * Es idempotente y a prueba de olvidos: la nómina, el listado de empleados y el
 * perfil también llaman a applyDueCompensationChanges() al cargar, así que si el
 * cron no corre, el salario se aplica igual la primera vez que alguien entra.
 *
 * Tarea de Windows: la registra run_vicidial_sync.bat install
 *   (PoncheXtreme-SalaryChanges, 12:05 AM diario).
 *
 * Cron de cPanel (alternativa, hora RD):
 *   5 0 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_apply_salary_changes.php >> /home2/hhempeos/logs/salary_changes.log 2>&1
 *
 * Uso manual:
 *   php cron_apply_salary_changes.php            -> aplica lo vencido
 *   php cron_apply_salary_changes.php --status   -> solo lista lo pendiente
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/compensation_history.php';

date_default_timezone_set('America/Santo_Domingo');

$statusOnly = in_array('--status', $argv ?? [], true) || isset($_GET['status']);
$prefix     = '[CRON SALARY CHANGES] ';

echo $prefix . 'Inicio ' . date('Y-m-d H:i:s') . PHP_EOL;

try {
    ensureCompensationChangesTable($pdo);

    if ($statusOnly) {
        $pending = getPendingCompensationChanges($pdo);
        if (empty($pending)) {
            echo $prefix . 'No hay cambios de salario programados.' . PHP_EOL;
        } else {
            echo $prefix . count($pending) . ' cambio(s) programado(s):' . PHP_EOL;
            foreach ($pending as $p) {
                $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: ('user #' . $p['user_id']);
                echo '  - ' . $p['effective_date'] . '  ' . $name
                    . '  ' . formatCompensationLabel(compensationSideFromRow($p, 'prev'))
                    . ' -> ' . formatCompensationLabel(compensationSideFromRow($p, 'new'))
                    . PHP_EOL;
            }
        }
        exit(0);
    }

    $applied = applyDueCompensationChanges($pdo);
    echo $prefix . ($applied > 0
        ? "Aplicados {$applied} cambio(s) de salario vencidos."
        : 'Sin cambios de salario vencidos.') . PHP_EOL;

    $stillPending = getPendingCompensationChanges($pdo);
    if (!empty($stillPending)) {
        echo $prefix . count($stillPending) . ' cambio(s) siguen programados para más adelante.' . PHP_EOL;
    }

    echo $prefix . 'Fin OK' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo $prefix . 'ERROR: ' . $e->getMessage() . PHP_EOL;
    error_log('cron_apply_salary_changes: ' . $e->getMessage());
    exit(1);
}
