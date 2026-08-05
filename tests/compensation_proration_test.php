<?php
/**
 * tests/compensation_proration_test.php
 *
 * Comprueba que un cambio de salario a mitad de quincena se paga PRORRATEADO:
 * los días anteriores a la fecha efectiva con el salario viejo y los posteriores
 * con el nuevo. Es el escenario del cambio de campaña.
 *
 * Trabaja contra la base real dentro de una transacción que SIEMPRE se revierte:
 * crea usuario/empleado/período de mentira, calcula, verifica y hace rollback.
 * No deja rastro.
 *
 * Uso:  php tests/compensation_proration_test.php
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../hr/payroll_functions.php';

$failures = 0;
$checks   = 0;

function check(string $label, $expected, $actual, float $tolerance = 0.01): void
{
    global $failures, $checks;
    $checks++;
    $ok = is_numeric($expected) && is_numeric($actual)
        ? abs((float) $expected - (float) $actual) <= $tolerance
        : $expected === $actual;

    if ($ok) {
        echo "  [OK]   {$label}: " . (is_numeric($actual) ? number_format((float) $actual, 2) : var_export($actual, true)) . PHP_EOL;
    } else {
        $failures++;
        echo "  [FALLA] {$label}: esperado " . var_export($expected, true) . ", obtenido " . var_export($actual, true) . PHP_EOL;
    }
}

// Las tablas se aseguran ANTES de abrir la transacción: un CREATE/ALTER dentro
// de una transacción provoca commit implícito en MySQL y el rollback no serviría.
ensureCompensationChangesTable($pdo);
ensurePayrollSalarySegmentsColumn($pdo);

$pdo->beginTransaction();

try {
    $suffix = 'test_' . bin2hex(random_bytes(4));

    // --- Usuario de prueba: agente, por hora, RD$100/h ---
    $pdo->prepare("
        INSERT INTO users (username, password, full_name, role, hourly_rate, hourly_rate_dop,
                           monthly_salary, monthly_salary_dop, preferred_currency, compensation_type)
        VALUES (?, 'x', 'Prueba Prorrateo', 'AGENT', 0, 100.00, 0, 0, 'DOP', 'hourly')
    ")->execute([$suffix]);
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO employees (user_id, employee_code, first_name, last_name, hire_date, employment_status)
        VALUES (?, ?, 'Prueba', 'Prorrateo', '2024-01-01', 'ACTIVE')
    ")->execute([$userId, strtoupper($suffix)]);
    $employeeId = (int) $pdo->lastInsertId();

    // --- Período quincenal: 1 al 15 ---
    $start = '2026-03-01';
    $end   = '2026-03-15';
    $pdo->prepare("
        INSERT INTO payroll_periods (name, period_type, start_date, end_date, payment_date, status)
        VALUES (?, 'BIWEEKLY', ?, ?, ?, 'DRAFT')
    ")->execute(['TEST ' . $suffix, $start, $end, $end]);
    $periodId = (int) $pdo->lastInsertId();

    // --- Cambio de campaña: RD$100/h -> RD$150/h desde el día 9 ---
    $changeId = recordCompensationChange(
        $pdo,
        $userId,
        ['compensation_type' => 'hourly', 'hourly_rate_dop' => 150.00, 'preferred_currency' => 'DOP'],
        '2026-03-09',
        ['employee_id' => $employeeId, 'source' => 'test', 'reason' => 'Cambio de campaña']
    );
    check('se registró el cambio', true, $changeId !== null);

    // --- 8 horas cada día laborable: 5 días con la vieja, 5 con la nueva ---
    $hoursByDate = [];
    foreach (['02', '03', '04', '05', '06'] as $d) {
        $hoursByDate["2026-03-{$d}"] = ['regular' => 8.0, 'overtime' => 0.0];
    }
    foreach (['09', '10', '11', '12', '13'] as $d) {
        $hoursByDate["2026-03-{$d}"] = ['regular' => 8.0, 'overtime' => 0.0];
    }

    $result = calculateEmployeePayroll($pdo, $employeeId, $periodId, [
        'regular_hours'  => 80.0,
        'overtime_hours' => 0.0,
        'days_worked'    => 10,
        'hours_by_date'  => $hoursByDate,
    ]);

    echo PHP_EOL . "Caso 1: por hora, RD\$100 -> RD\$150 desde el 09/03" . PHP_EOL;
    // 40 h × 100 + 40 h × 150 = 4,000 + 6,000 = 10,000  (no 80 × 150 = 12,000)
    check('salario base prorrateado', 10000.00, $result['base_salary']);
    check('tramos generados', 2, count($result['salary_segments']));
    check('pago del tramo viejo', 4000.00, $result['salary_segments'][0]['regular_pay']);
    check('pago del tramo nuevo', 6000.00, $result['salary_segments'][1]['regular_pay']);
    check('corte del primer tramo', '2026-03-08', $result['salary_segments'][0]['end']);
    check('inicio del segundo tramo', '2026-03-09', $result['salary_segments'][1]['start']);

    // --- Control: sin cambio dentro del período, un solo salario ---
    echo PHP_EOL . "Caso 2 (control): período posterior, sin cambio dentro" . PHP_EOL;
    $pdo->prepare("
        INSERT INTO payroll_periods (name, period_type, start_date, end_date, payment_date, status)
        VALUES (?, 'BIWEEKLY', '2026-04-01', '2026-04-15', '2026-04-15', 'DRAFT')
    ")->execute(['TEST2 ' . $suffix]);
    $periodId2 = (int) $pdo->lastInsertId();

    $hoursByDate2 = [];
    foreach (['01', '02', '03', '06', '07', '08', '09', '10', '13', '14'] as $d) {
        $hoursByDate2["2026-04-{$d}"] = ['regular' => 8.0, 'overtime' => 0.0];
    }
    $result2 = calculateEmployeePayroll($pdo, $employeeId, $periodId2, [
        'regular_hours'  => 80.0,
        'overtime_hours' => 0.0,
        'days_worked'    => 10,
        'hours_by_date'  => $hoursByDate2,
    ]);
    check('sin tramos', 0, count($result2['salary_segments']));
    check('todo a la tarifa nueva', 12000.00, $result2['base_salary']);

    // --- Período anterior al cambio: todo a la tarifa vieja ---
    echo PHP_EOL . "Caso 3: período anterior al cambio, todo a la tarifa vieja" . PHP_EOL;
    $pdo->prepare("
        INSERT INTO payroll_periods (name, period_type, start_date, end_date, payment_date, status)
        VALUES (?, 'BIWEEKLY', '2026-02-01', '2026-02-15', '2026-02-15', 'DRAFT')
    ")->execute(['TEST3 ' . $suffix]);
    $periodId3 = (int) $pdo->lastInsertId();

    $hoursByDate3 = [];
    foreach (['02', '03', '04', '05', '06', '09', '10', '11', '12', '13'] as $d) {
        $hoursByDate3["2026-02-{$d}"] = ['regular' => 8.0, 'overtime' => 0.0];
    }
    $result3 = calculateEmployeePayroll($pdo, $employeeId, $periodId3, [
        'regular_hours'  => 80.0,
        'overtime_hours' => 0.0,
        'days_worked'    => 10,
        'hours_by_date'  => $hoursByDate3,
    ]);
    check('todo a la tarifa vieja', 8000.00, $result3['base_salary']);

    // --- Horas extra: también se pagan con la tarifa del tramo ---
    echo PHP_EOL . "Caso 4: horas extra dentro de cada tramo" . PHP_EOL;
    $hoursByDate4 = $hoursByDate;
    $hoursByDate4['2026-03-06']['overtime'] = 2.0;  // tramo viejo
    $hoursByDate4['2026-03-13']['overtime'] = 2.0;  // tramo nuevo

    $result4 = calculateEmployeePayroll($pdo, $employeeId, $periodId, [
        'regular_hours'  => 80.0,
        'overtime_hours' => 4.0,
        'days_worked'    => 10,
        'hours_by_date'  => $hoursByDate4,
    ]);
    // 2 h × 100 × 1.35 + 2 h × 150 × 1.35 = 270 + 405 = 675 (con el multiplicador
    // configurado; se lee del sistema, así que se compara contra la fórmula).
    $mult = (float) (getScheduleConfig($pdo)['overtime_multiplier'] ?? 1.35);
    check('extras prorrateadas', (2 * 100 * $mult) + (2 * 150 * $mult), $result4['overtime_amount']);

    // --- Sueldo FIJO: se reparte por días CALENDARIO del tramo ---
    echo PHP_EOL . "Caso 5: sueldo mensual fijo, RD\$30,000 -> RD\$45,000 desde el 09/03" . PHP_EOL;
    $suffix2 = 'test_' . bin2hex(random_bytes(4));
    $pdo->prepare("
        INSERT INTO users (username, password, full_name, role, hourly_rate, hourly_rate_dop,
                           monthly_salary, monthly_salary_dop, preferred_currency, compensation_type)
        VALUES (?, 'x', 'Prueba Fijo', 'SUPERVISOR', 0, 0, 0, 30000.00, 'DOP', 'fixed')
    ")->execute([$suffix2]);
    $userId2 = (int) $pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO employees (user_id, employee_code, first_name, last_name, hire_date, employment_status)
        VALUES (?, ?, 'Prueba', 'Fijo', '2024-01-01', 'ACTIVE')
    ")->execute([$userId2, strtoupper($suffix2)]);
    $employeeId2 = (int) $pdo->lastInsertId();

    recordCompensationChange(
        $pdo,
        $userId2,
        ['compensation_type' => 'fixed', 'monthly_salary_dop' => 45000.00, 'preferred_currency' => 'DOP'],
        '2026-03-09',
        ['employee_id' => $employeeId2, 'source' => 'test', 'reason' => 'Ascenso']
    );

    $result5 = calculateEmployeePayroll($pdo, $employeeId2, $periodId, [
        'regular_hours'  => 80.0,
        'overtime_hours' => 0.0,
        'days_worked'    => 10,
        'hours_by_date'  => $hoursByDate,
    ]);
    // Quincena de 15 días: 8 días con 30,000 y 7 con 45,000, al 50% de prorrateo.
    // 30000*0.5*(8/15) + 45000*0.5*(7/15) = 8,000 + 10,500 = 18,500
    check('sueldo fijo prorrateado', 18500.00, $result5['base_salary']);
    check('tramos del sueldo fijo', 2, count($result5['salary_segments']));

    echo PHP_EOL;
} catch (Throwable $e) {
    echo "EXCEPCIÓN: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
    $failures++;
} finally {
    // Nunca se guarda nada: los datos de prueba se van al rollback.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo str_repeat('=', 60) . PHP_EOL;
echo ($failures === 0 ? "TODO OK" : "{$failures} FALLO(S)") . " — {$checks} comprobaciones" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
