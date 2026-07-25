<?php
/**
 * Cron: avisos automáticos del módulo de Empleados.
 *
 *   - Período de prueba por vencer (10 días antes, configurable)
 *   - Cumpleaños del mes (solo el día 1)
 *   - Expedientes con documentación o datos incompletos
 *
 * Los avisos van a la campana del sistema. Correrlo varias veces al día es
 * seguro: cada aviso lleva un dedupe_key (por colaborador y fecha de fin de
 * prueba, por mes, por semana) que impide duplicados.
 *
 * Tarea de Windows: la registra run_vicidial_sync.bat install
 *   (PoncheXtreme-EmployeeNotices, 7:30 AM diario).
 *
 * Cron de cPanel (alternativa, hora RD):
 *   30 7 * * * /usr/local/bin/php /home2/hhempeos/punch.evallishbpo.com/cron_employee_notices.php >> /home2/hhempeos/logs/employee_notices.log 2>&1
 *
 * Uso manual:
 *   php cron_employee_notices.php                  -> corrida normal
 *   php cron_employee_notices.php --force-birthdays -> fuerza el aviso de cumpleaños
 */

if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    if ($cronKey !== 'ponche_xtreme_2025') {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/employee_notifications.php';

date_default_timezone_set('America/Santo_Domingo');

$forceBirthdays = in_array('--force-birthdays', $argv ?? [], true) || isset($_GET['force_birthdays']);
$logPrefix = '[CRON EMPLOYEE NOTICES] ';

try {
    echo $logPrefix . 'Starting at ' . date('Y-m-d H:i:s') . "\n";

    $cfg = employeeNoticeSettings($pdo);

    // 1. Período de prueba
    if (($cfg['trial_notice_enabled'] ?? '1') === '1') {
        $trial = notifyTrialPeriodEnding($pdo);
        echo $logPrefix . sprintf(
            "Periodo de prueba: %d por vencer en los proximos %s dias, %d aviso(s) nuevo(s)\n",
            $trial['checked'], $cfg['trial_notice_days_before'] ?? '10', $trial['notified']
        );
    } else {
        echo $logPrefix . "Periodo de prueba: deshabilitado\n";
    }

    // 2. Cumpleaños del mes (solo el día 1, salvo --force-birthdays)
    if (($cfg['birthday_notice_enabled'] ?? '1') === '1') {
        $bd = notifyMonthlyBirthdays($pdo, $forceBirthdays);
        if ((int) date('j') === 1 || $forceBirthdays) {
            echo $logPrefix . sprintf(
                "Cumpleanos del mes: %d colaborador(es), aviso %s\n",
                $bd['birthdays'], $bd['notified'] ? 'creado' : 'ya existia o sin cumpleanos'
            );
        } else {
            echo $logPrefix . "Cumpleanos: solo se avisa el dia 1 del mes (hoy es " . date('j') . ")\n";
        }
    } else {
        echo $logPrefix . "Cumpleanos: deshabilitado\n";
    }

    // 3. Expedientes incompletos
    if (($cfg['docs_notice_enabled'] ?? '1') === '1') {
        $docs = notifyIncompleteDocumentation($pdo);
        echo $logPrefix . sprintf(
            "Expedientes: %d revisados, %d incompletos, aviso %s\n",
            $docs['checked'], $docs['incomplete'], $docs['notified'] ? 'creado' : 'ya existia esta semana'
        );
    } else {
        echo $logPrefix . "Expedientes: deshabilitado\n";
    }

    // 4. Próximos a cumplir el año de antigüedad. Al cumplirlo nace el derecho a
    //    vacaciones, así que RRHH necesita saberlo antes para planificar el
    //    disfrute y no acumular a todo el mundo en el mismo mes.
    require_once __DIR__ . '/lib/vacation_calculator.php';
    $vcfg = vacationSettings($pdo);
    if (($vcfg['vacation_notice_enabled'] ?? '1') === '1') {
        $vac = notifyVacationAnniversary($pdo);
        echo $logPrefix . sprintf(
            "Aniversarios: %d proximo(s) a cumplir el ano, %d aviso(s) nuevo(s)\n",
            $vac['checked'], $vac['notified']
        );
    } else {
        echo $logPrefix . "Aniversarios: deshabilitado\n";
    }

    echo $logPrefix . 'Done at ' . date('Y-m-d H:i:s') . "\n";
    exit(0);
} catch (Throwable $e) {
    echo $logPrefix . 'ERROR: ' . $e->getMessage() . "\n";
    error_log('cron_employee_notices: ' . $e->getMessage());
    exit(1);
}
