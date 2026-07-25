<?php
/**
 * lib/employee_notifications.php
 *
 * Avisos automáticos del módulo de Empleados, todos a la campana del sistema:
 *
 *   1. notifyTrialPeriodEnding()      -> 10 días antes de cumplir el período de prueba
 *   2. notifyMonthlyBirthdays()       -> al inicio de cada mes, cumpleaños del mes
 *   3. notifyPermissionRegistered()   -> cada vez que se registra un permiso (evento)
 *   4. notifyIncompleteDocumentation() -> expedientes con datos o documentos faltantes
 *
 * Los tres barridos (1, 2 y 4) los dispara cron_employee_notices.php; el 3 se
 * llama en el momento en que se crea el permiso.
 *
 * Todo (días de anticipación, roles avisados, período de gracia) se configura en
 * settings.php; aquí no hay nada fijo.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/employee_record.php';

if (!function_exists('employeeNoticeSettings')) {
    /** @return array<string,string> */
    function employeeNoticeSettings(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $defaults = [
            'trial_notice_enabled'       => '1',
            'trial_period_days'          => '90',
            'trial_notice_days_before'   => '10',
            'trial_notice_roles'         => 'HR,Admin',
            'trial_notice_user_ids'      => '',
            'birthday_notice_enabled'    => '1',
            'birthday_notice_roles'      => 'HR,Admin',
            'birthday_notice_user_ids'   => '',
            'permission_notice_enabled'  => '1',
            'permission_notice_roles'    => 'HR,Admin',
            'permission_notice_user_ids' => '',
            'docs_notice_enabled'        => '1',
            'docs_notice_roles'          => 'HR,Admin',
            'docs_notice_user_ids'       => '',
            'docs_notice_grace_days'     => '15',
        ];

        try {
            $keys = array_keys($defaults);
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($ph)");
            $stmt->execute($keys);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        } catch (Throwable $e) {
            error_log('employeeNoticeSettings: ' . $e->getMessage());
        }

        $cache = $defaults;
        return $cache;
    }
}

if (!function_exists('employeeNoticeFanOut')) {
    /**
     * Crea el aviso para los roles configurados y, además, para los usuarios
     * concretos que se hayan indicado.
     */
    function employeeNoticeFanOut(PDO $pdo, array $opts, string $roles, string $userIdsCsv): ?int
    {
        $id = notifyCreate($pdo, $opts + ['roles' => $roles]);

        foreach (notifyResolveTargetUserIds($pdo, $userIdsCsv) as $uid) {
            $perUser = $opts;
            $perUser['user_id'] = $uid;
            unset($perUser['roles']);
            if (!empty($perUser['dedupe_key'])) {
                $perUser['dedupe_key'] .= ':u' . $uid;
            }
            notifyCreate($pdo, $perUser);
        }

        return $id;
    }
}

if (!function_exists('notifyTrialPeriodEnding')) {
    /**
     * Avisa N días antes de que un colaborador cumpla su período de prueba.
     *
     * El aviso se emite una sola vez por colaborador y período gracias al
     * dedupe_key (lleva la fecha de fin), así que correr esto varias veces al día
     * no genera duplicados.
     *
     * @return array{checked:int, notified:int}
     */
    function notifyTrialPeriodEnding(PDO $pdo): array
    {
        $out = ['checked' => 0, 'notified' => 0];
        $cfg = employeeNoticeSettings($pdo);

        if (($cfg['trial_notice_enabled'] ?? '1') !== '1') {
            return $out;
        }

        $trialDays  = max(1, (int) ($cfg['trial_period_days'] ?? 90));
        $daysBefore = max(0, (int) ($cfg['trial_notice_days_before'] ?? 10));

        try {
            // Colaboradores cuyo fin de prueba cae dentro de la ventana de aviso
            // y que todavía no la han cumplido.
            $stmt = $pdo->prepare("
                SELECT e.id, e.employee_code, e.first_name, e.last_name, e.hire_date,
                       e.position, e.employment_status,
                       d.name AS department_name,
                       DATE_ADD(e.hire_date, INTERVAL ? DAY) AS trial_end,
                       DATEDIFF(DATE_ADD(e.hire_date, INTERVAL ? DAY), CURDATE()) AS days_left
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE e.employment_status <> 'TERMINATED'
                  AND DATEDIFF(DATE_ADD(e.hire_date, INTERVAL ? DAY), CURDATE()) BETWEEN 0 AND ?
                ORDER BY days_left ASC
            ");
            $stmt->execute([$trialDays, $trialDays, $trialDays, $daysBefore]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('notifyTrialPeriodEnding: ' . $e->getMessage());
            return $out;
        }

        foreach ($rows as $r) {
            $out['checked']++;
            $name = trim($r['first_name'] . ' ' . $r['last_name']);
            $daysLeft = (int) $r['days_left'];

            $when = $daysLeft === 0
                ? 'HOY'
                : ('en ' . $daysLeft . ' día' . ($daysLeft === 1 ? '' : 's'));

            $message = implode("\n", array_filter([
                'Cumple sus ' . $trialDays . ' días de prueba ' . $when . ' (' . date('d/m/Y', strtotime($r['trial_end'])) . ').',
                'Ingreso: ' . date('d/m/Y', strtotime($r['hire_date'])),
                $r['position'] ? 'Posición: ' . $r['position'] : null,
                $r['department_name'] ? 'Departamento: ' . $r['department_name'] : null,
                'Hay que decidir si pasa a fijo o termina la relación.',
            ]));

            $id = employeeNoticeFanOut($pdo, [
                'type'            => 'EMPLOYEE_TRIAL_ENDING',
                'title'           => 'Período de prueba por vencer: ' . $name,
                'message'         => $message,
                'severity'        => $daysLeft <= 3 ? 'HIGH' : 'NORMAL',
                'url'             => 'hr/employee_profile.php?id=' . (int) $r['id'],
                'payload'         => [
                    'employee_id' => (int) $r['id'],
                    'days_left'   => $daysLeft,
                    'trial_end'   => $r['trial_end'],
                ],
                // Uno por colaborador y fecha de fin: no se repite cada día.
                'dedupe_key'      => 'trial_end:' . (int) $r['id'] . ':' . $r['trial_end'],
                'requires_action' => true,
            ], $cfg['trial_notice_roles'] ?? 'HR,Admin', $cfg['trial_notice_user_ids'] ?? '');

            if ($id !== null) {
                $out['notified']++;
            }
        }

        return $out;
    }
}

if (!function_exists('notifyMonthlyBirthdays')) {
    /**
     * Al inicio de cada mes, el listado de cumpleaños del mes.
     *
     * @param bool $force ignora la comprobación de "es día 1" (para probarlo)
     * @return array{birthdays:int, notified:bool}
     */
    function notifyMonthlyBirthdays(PDO $pdo, bool $force = false): array
    {
        $out = ['birthdays' => 0, 'notified' => false];
        $cfg = employeeNoticeSettings($pdo);

        if (($cfg['birthday_notice_enabled'] ?? '1') !== '1') {
            return $out;
        }

        // Solo el primer día del mes, salvo que se fuerce.
        if (!$force && (int) date('j') !== 1) {
            return $out;
        }

        $month = (int) date('n');

        try {
            $stmt = $pdo->prepare("
                SELECT e.id, e.first_name, e.last_name, e.birth_date, e.position,
                       d.name AS department_name,
                       DAY(e.birth_date) AS day_of_month
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE e.employment_status <> 'TERMINATED'
                  AND e.birth_date IS NOT NULL
                  AND MONTH(e.birth_date) = ?
                ORDER BY DAY(e.birth_date) ASC, e.first_name ASC
            ");
            $stmt->execute([$month]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('notifyMonthlyBirthdays: ' . $e->getMessage());
            return $out;
        }

        $out['birthdays'] = count($rows);
        if (empty($rows)) {
            return $out;
        }

        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                  'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $monthName = $meses[$month] ?? (string) $month;

        $lines = [];
        foreach ($rows as $r) {
            $name = trim($r['first_name'] . ' ' . $r['last_name']);
            $lines[] = sprintf(
                '· %2d de %s — %s%s',
                (int) $r['day_of_month'],
                $monthName,
                $name,
                $r['department_name'] ? ' (' . $r['department_name'] . ')' : ''
            );
        }

        $id = employeeNoticeFanOut($pdo, [
            'type'       => 'EMPLOYEE_BIRTHDAYS_MONTH',
            'title'      => 'Cumpleaños de ' . $monthName . ': ' . count($rows) . ' colaborador' . (count($rows) === 1 ? '' : 'es'),
            'message'    => implode("\n", $lines),
            'severity'   => 'LOW',
            'url'        => 'hr/birthdays.php',
            'payload'    => ['month' => $month, 'count' => count($rows)],
            // Uno por mes y año.
            'dedupe_key' => 'birthdays:' . date('Y-m'),
        ], $cfg['birthday_notice_roles'] ?? 'HR,Admin', $cfg['birthday_notice_user_ids'] ?? '');

        $out['notified'] = ($id !== null);
        return $out;
    }
}

if (!function_exists('notifyPermissionRegistered')) {
    /**
     * Avisa que se registró una solicitud de permiso. Se llama en el momento en
     * que se crea, no por barrido: el cliente pidió enterarse "cada vez que se
     * registre un permiso".
     */
    function notifyPermissionRegistered(PDO $pdo, int $permissionId): ?int
    {
        $cfg = employeeNoticeSettings($pdo);
        if (($cfg['permission_notice_enabled'] ?? '1') !== '1') {
            return null;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT p.*, e.first_name, e.last_name, e.employee_code, e.id AS emp_id,
                       d.name AS department_name
                FROM permission_requests p
                LEFT JOIN employees e ON e.id = p.employee_id
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE p.id = ?
            ");
            $stmt->execute([$permissionId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('notifyPermissionRegistered: ' . $e->getMessage());
            return null;
        }

        if (!$p) {
            return null;
        }

        $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: 'Colaborador';
        $tipo = str_replace('_', ' ', ucwords(strtolower((string) ($p['request_type'] ?? 'permiso'))));

        $periodo = date('d/m/Y', strtotime($p['start_date']));
        if (!empty($p['end_date']) && $p['end_date'] !== $p['start_date']) {
            $periodo .= ' → ' . date('d/m/Y', strtotime($p['end_date']));
        }
        if (!empty($p['start_time'])) {
            $periodo .= ' ' . substr((string) $p['start_time'], 0, 5);
            if (!empty($p['end_time'])) {
                $periodo .= '–' . substr((string) $p['end_time'], 0, 5);
            }
        }

        $message = implode("\n", array_filter([
            'Tipo: ' . $tipo,
            'Período: ' . $periodo,
            !empty($p['total_days']) ? 'Días: ' . rtrim(rtrim(number_format((float) $p['total_days'], 1), '0'), '.') : null,
            $p['department_name'] ? 'Departamento: ' . $p['department_name'] : null,
            !empty($p['reason']) ? 'Motivo: ' . mb_substr((string) $p['reason'], 0, 200) : null,
            'Estado: ' . ($p['status'] ?? 'PENDING'),
        ]));

        return employeeNoticeFanOut($pdo, [
            'type'            => 'EMPLOYEE_PERMISSION_REGISTERED',
            'title'           => 'Permiso registrado: ' . $name,
            'message'         => $message,
            'severity'        => 'NORMAL',
            'url'             => 'hr/permissions.php',
            'payload'         => [
                'permission_id' => $permissionId,
                'employee_id'   => (int) ($p['emp_id'] ?? 0),
                'status'        => $p['status'] ?? null,
            ],
            'dedupe_key'      => 'permission:' . $permissionId,
            // Si llega pendiente, alguien tiene que aprobarlo o rechazarlo.
            'requires_action' => strtoupper((string) ($p['status'] ?? '')) === 'PENDING',
        ], $cfg['permission_notice_roles'] ?? 'HR,Admin', $cfg['permission_notice_user_ids'] ?? '');
    }
}

if (!function_exists('notifyIncompleteDocumentation')) {
    /**
     * Avisa de los expedientes incompletos: datos personales o documentos
     * obligatorios faltantes.
     *
     * Se respeta un período de gracia desde el ingreso (configurable): a alguien
     * que entró ayer no se le reclama el expediente completo.
     *
     * Va en UN resumen, no un aviso por persona: con 57 activos, uno por cada uno
     * dejaría la campana inservible.
     *
     * @return array{checked:int, incomplete:int, notified:bool}
     */
    function notifyIncompleteDocumentation(PDO $pdo): array
    {
        $out = ['checked' => 0, 'incomplete' => 0, 'notified' => false];
        $cfg = employeeNoticeSettings($pdo);

        if (($cfg['docs_notice_enabled'] ?? '1') !== '1') {
            return $out;
        }

        $graceDays = max(0, (int) ($cfg['docs_notice_grace_days'] ?? 15));

        try {
            $stmt = $pdo->prepare("
                SELECT e.*, d.name AS department_name
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE e.employment_status <> 'TERMINATED'
                  AND DATEDIFF(CURDATE(), e.hire_date) >= ?
                ORDER BY e.hire_date DESC
            ");
            $stmt->execute([$graceDays]);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('notifyIncompleteDocumentation: ' . $e->getMessage());
            return $out;
        }

        $pending = [];
        foreach ($employees as $e) {
            $out['checked']++;

            $docs = employeeRequiredDocumentsStatus($pdo, (int) $e['id']);
            $data = employeePersonalDataStatus($e);

            if ($docs['is_complete'] && $data['is_complete']) {
                continue;
            }

            $out['incomplete']++;
            $pending[] = [
                'id'           => (int) $e['id'],
                'name'         => trim($e['first_name'] . ' ' . $e['last_name']),
                // 'missing' es un CONTEO; las etiquetas van en 'missing_labels'.
                'docs_missing' => (int) $docs['missing'],
                'docs_labels'  => $docs['missing_labels'],
                'data_missing' => $data['missing'],
            ];
        }

        if (empty($pending)) {
            return $out;
        }

        // Primero los más incompletos.
        usort($pending, static fn($a, $b) =>
            ($b['docs_missing'] + count($b['data_missing'])) <=> ($a['docs_missing'] + count($a['data_missing']))
        );

        $maxListed = 12;
        $lines = [];
        foreach (array_slice($pending, 0, $maxListed) as $p) {
            $bits = [];
            if (!empty($p['docs_labels'])) {
                $bits[] = count($p['docs_labels']) . ' doc.: ' . implode(', ', array_slice($p['docs_labels'], 0, 3))
                    . (count($p['docs_labels']) > 3 ? '…' : '');
            }
            if (!empty($p['data_missing'])) {
                $bits[] = 'datos: ' . implode(', ', array_slice($p['data_missing'], 0, 3))
                    . (count($p['data_missing']) > 3 ? '…' : '');
            }
            $lines[] = '· ' . $p['name'] . ' — ' . implode(' | ', $bits);
        }
        if (count($pending) > $maxListed) {
            $lines[] = '· … y ' . (count($pending) - $maxListed) . ' más';
        }

        $id = employeeNoticeFanOut($pdo, [
            'type'       => 'EMPLOYEE_DOCS_INCOMPLETE',
            'title'      => 'Expedientes incompletos: ' . count($pending) . ' colaborador' . (count($pending) === 1 ? '' : 'es'),
            'message'    => implode("\n", $lines),
            'severity'   => 'NORMAL',
            'url'        => 'hr/employees.php',
            'payload'    => ['count' => count($pending), 'employee_ids' => array_column($pending, 'id')],
            // Un resumen por semana: es una tarea de seguimiento, no una urgencia.
            'dedupe_key' => 'docs_incomplete:' . date('o-\WW'),
        ], $cfg['docs_notice_roles'] ?? 'HR,Admin', $cfg['docs_notice_user_ids'] ?? '');

        $out['notified'] = ($id !== null);
        return $out;
    }
}

if (!function_exists('notifyVacationAnniversary')) {
    /**
     * Avisa que un colaborador está por cumplir su año de antigüedad.
     *
     * Al cumplir el año nace el derecho a los 14 días de vacaciones (art. 177
     * del Código de Trabajo), así que RRHH necesita saberlo ANTES para poder
     * planificar el disfrute y no acumular a todo el mundo en el mismo mes.
     *
     * Un aviso por colaborador y aniversario: correrlo a diario no duplica.
     *
     * @return array{checked:int, notified:int}
     */
    function notifyVacationAnniversary(PDO $pdo): array
    {
        require_once __DIR__ . '/vacation_calculator.php';

        $out = ['checked' => 0, 'notified' => 0];
        $cfg = vacationSettings($pdo);

        if (($cfg['vacation_notice_enabled'] ?? '1') !== '1') {
            return $out;
        }

        $daysBefore = max(1, (int) ($cfg['vacation_notice_days_before'] ?? 30));

        foreach (vacationUpcomingAnniversaries($pdo, $daysBefore) as $e) {
            $out['checked']++;

            $name = trim($e['first_name'] . ' ' . $e['last_name']);
            $daysLeft = (int) $e['days_left'];
            $entitlement = vacationEntitlementDays($pdo, $e['hire_date'], $e['anniversary']);

            $cuando = $daysLeft === 0
                ? 'HOY'
                : ('en ' . $daysLeft . ' día' . ($daysLeft === 1 ? '' : 's'));

            $message = implode("
", array_filter([
                'Cumple su año de antigüedad ' . $cuando . ' (' . date('d/m/Y', strtotime($e['anniversary'])) . ').',
                'Ingreso: ' . date('d/m/Y', strtotime($e['hire_date'])),
                $e['position'] ? 'Posición: ' . $e['position'] : null,
                $e['department_name'] ? 'Departamento: ' . $e['department_name'] : null,
                'Le corresponderán ' . rtrim(rtrim(number_format($entitlement, 1), '0'), '.') . ' días de vacaciones.',
                'Conviene coordinar con él las fechas de disfrute.',
            ]));

            $id = employeeNoticeFanOut($pdo, [
                'type'       => 'VACATION_ANNIVERSARY',
                'title'      => 'Cumple un año: ' . $name,
                'message'    => $message,
                'severity'   => $daysLeft <= 7 ? 'HIGH' : 'NORMAL',
                'url'        => 'hr/vacation_calendar.php',
                'payload'    => [
                    'employee_id' => (int) $e['employee_id'],
                    'anniversary' => $e['anniversary'],
                    'days_left'   => $daysLeft,
                    'entitlement' => $entitlement,
                ],
                // Uno por colaborador y aniversario concreto.
                'dedupe_key' => 'vacation_anniversary:' . (int) $e['employee_id'] . ':' . $e['anniversary'],
            ], $cfg['vacation_notice_roles'] ?? 'HR,Admin', $cfg['vacation_notice_user_ids'] ?? '');

            if ($id !== null) {
                $out['notified']++;
            }
        }

        return $out;
    }
}
