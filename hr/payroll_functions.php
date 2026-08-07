<?php
/**
 * FUNCIONES DE CÁLCULO DE NÓMINA PARA REPÚBLICA DOMINICANA
 * Incluye: AFP, SFS, ISR según normativas vigentes 2025
 */

require_once __DIR__ . '/../lib/compensation_history.php';

/**
 * Obtiene las tasas de descuentos desde la base de datos
 */
function getDeductionRates($pdo)
{
    static $rates = null;
    if ($rates === null) {
        $stmt = $pdo->query("SELECT code, employee_percentage, employer_percentage, is_active FROM payroll_deduction_config");
        $rates = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rates[$row['code']] = $row;
        }
    }
    return $rates;
}

/**
 * Calcula el AFP (Administradora de Fondos de Pensiones)
 *
 * NOTA (tope legal): la ley RD topa el salario cotizable (AFP máx. y SFS máx.
 * en múltiplos del salario mínimo cotizable). Aquí se aplica el % sobre el bruto
 * SIN tope. Es inofensivo con los sueldos actuales (muy por debajo del tope),
 * pero si algún día se contrata por encima del tope habría que topar el bruto
 * cotizable (idealmente configurable en settings) antes de aplicar el %.
 */
function calculateAFP($pdo, $grossSalary, $isEmployer = false)
{
    $rates = getDeductionRates($pdo);
    if (!$rates['AFP']['is_active'])
        return 0.00;
    $rate = $isEmployer ? $rates['AFP']['employer_percentage'] : $rates['AFP']['employee_percentage'];
    return round($grossSalary * ($rate / 100), 2);
}

/**
 * Calcula el SFS (Seguro Familiar de Salud)
 */
function calculateSFS($pdo, $grossSalary, $isEmployer = false)
{
    $rates = getDeductionRates($pdo);
    if (!$rates['SFS']['is_active'])
        return 0.00;
    $rate = $isEmployer ? $rates['SFS']['employer_percentage'] : $rates['SFS']['employee_percentage'];
    return round($grossSalary * ($rate / 100), 2);
}

/**
 * Calcula el SRL (Seguro de Riesgos Laborales)
 */
function calculateSRL($pdo, $grossSalary)
{
    $rates = getDeductionRates($pdo);
    if (!$rates['SRL']['is_active'])
        return 0.00;
    $rate = $rates['SRL']['employer_percentage'];
    return round($grossSalary * ($rate / 100), 2);
}

/**
 * Calcula INFOTEP
 */
function calculateINFOTEP($pdo, $grossSalary)
{
    $rates = getDeductionRates($pdo);
    if (!$rates['INFOTEP']['is_active'])
        return 0.00;
    $rate = $rates['INFOTEP']['employer_percentage'];
    return round($grossSalary * ($rate / 100), 2);
}

/**
 * Calcula el ISR (Impuesto Sobre la Renta) según escala progresiva
 * Escala 2025 (anual):
 * - Hasta RD$416,220.00: Exento
 * - RD$416,220.01 - RD$624,329.00: 15% sobre excedente
 * - RD$624,329.01 - RD$867,123.00: RD$31,216 + 20% sobre excedente
 * - Más de RD$867,123.01: RD$79,775 + 25% sobre excedente
 */
function calculateISR($monthlyGrossSalary)
{
    // NOTA (moneda): la escala está en DOP. El bruto que llega debe estar en DOP.
    // Hoy todos los empleados cobran en DOP (los de preferencia USD tienen tasas en
    // 0). Si algún día se paga en USD, hay que convertir el bruto a DOP antes de
    // entrar aquí y decidir en qué moneda se guarda la retención (política del
    // contador), o los tramos quedarían mal.
    // Convertir a salario anual
    $annualSalary = $monthlyGrossSalary * 12;

    // Aplicar escala progresiva
    $isr = 0;

    if ($annualSalary <= 416220.00) {
        // Exento
        $isr = 0;
    } elseif ($annualSalary <= 624329.00) {
        // 15% sobre excedente de 416,220
        $excess = $annualSalary - 416220.00;
        $isr = $excess * 0.15;
    } elseif ($annualSalary <= 867123.00) {
        // RD$31,216 + 20% sobre excedente de 624,329
        $excess = $annualSalary - 624329.00;
        $isr = 31216.00 + ($excess * 0.20);
    } else {
        // RD$79,775 + 25% sobre excedente de 867,123
        $excess = $annualSalary - 867123.00;
        $isr = 79775.00 + ($excess * 0.25);
    }

    // Convertir a mensual
    $monthlyISR = $isr / 12;

    return round($monthlyISR, 2);
}

/**
 * Calcula todos los descuentos de un empleado.
 *
 * $periodFraction = fracción del MES que representa el período de nómina
 * (1.0 mensual, 0.5 quincenal, 0.25 semanal). AFP/SFS son % planos sobre el
 * bruto del período (no se prorratean). El ISR sí: su escala es mensual/anual,
 * así que se lleva el bruto del período a mensual-equivalente (÷ fracción), se
 * calcula el ISR mensual y se re-prorratea al período (× fracción). Con 1.0 el
 * comportamiento es idéntico al anterior (períodos mensuales).
 */
function calculateAllDeductions($pdo, $grossSalary, $customDeductions = [], $periodFraction = 1.0, $isrExempt = false, array $tssExempt = [])
{
    $periodFraction = (float) $periodFraction;
    if ($periodFraction <= 0 || $periodFraction > 1.0) {
        $periodFraction = 1.0;
    }
    // El ISR se puede apagar desde Nómina → Configuración, igual que AFP, SFS,
    // SRL e INFOTEP. Antes era el ÚNICO que no miraba su casilla "Activo": se
    // podía desmarcar, guardar y regenerar la nómina, y seguía reteniendo. La
    // encargada de nómina lo desactivó, no pasó nada, y el descuento siguió
    // saliendo en los recibos.
    $rates = getDeductionRates($pdo);
    $isrActivo = !isset($rates['ISR']) || (int) $rates['ISR']['is_active'] === 1;

    $isrPeriod = 0.00;
    if ($isrActivo && !$isrExempt) {
        $isrPeriod = ($periodFraction < 1.0)
            ? round(calculateISR($grossSalary / $periodFraction) * $periodFraction, 2)
            : calculateISR($grossSalary);
    }

    // Exenciones de seguridad social por colaborador (ficha o quincena suelta).
    // Existen casos reales: un pensionado no cotiza AFP pero sí SFS, y alguien
    // que no está registrado en la TSS no cotiza ninguna de las dos.
    $afpExento = !empty($tssExempt['afp']);
    $sfsExento = !empty($tssExempt['sfs']);

    $deductions = [
        'afp_employee' => $afpExento ? 0.00 : calculateAFP($pdo, $grossSalary, false),
        'sfs_employee' => $sfsExento ? 0.00 : calculateSFS($pdo, $grossSalary, false),
        'isr' => $isrPeriod,
        'custom_deductions' => 0,
        'total_deductions' => 0
    ];

    // Sumar descuentos personalizados
    foreach ($customDeductions as $deduction) {
        if ($deduction['is_active']) {
            if ($deduction['type'] === 'PERCENTAGE') {
                $deductions['custom_deductions'] += round($grossSalary * ($deduction['amount'] / 100), 2);
            } else {
                $deductions['custom_deductions'] += $deduction['amount'];
            }
        }
    }

    // Total de descuentos
    $deductions['total_deductions'] =
        $deductions['afp_employee'] +
        $deductions['sfs_employee'] +
        $deductions['isr'] +
        $deductions['custom_deductions'];

    return $deductions;
}

/**
 * Calcula todos los aportes del empleador.
 *
 * Si el colaborador está exento de AFP o SFS, la empresa tampoco cotiza ese
 * concepto por él: la exención significa que no está cotizando en la TSS por
 * ese renglón, no que la empresa asuma la parte del empleado. SRL e INFOTEP
 * son conceptos aparte y no se tocan.
 */
function calculateEmployerContributions($pdo, $grossSalary, array $tssExempt = [])
{
    return [
        'afp_employer' => !empty($tssExempt['afp']) ? 0.00 : calculateAFP($pdo, $grossSalary, true),
        'sfs_employer' => !empty($tssExempt['sfs']) ? 0.00 : calculateSFS($pdo, $grossSalary, true),
        'srl_employer' => calculateSRL($pdo, $grossSalary),
        'infotep_employer' => calculateINFOTEP($pdo, $grossSalary),
        'total_employer' => 0
    ];
}

/**
 * Calcula el salario neto
 */
function calculateNetSalary($grossSalary, $deductions)
{
    return round($grossSalary - $deductions['total_deductions'], 2);
}

/**
 * Fracción del MES que representa un período de nómina. Se usa para (a) prorratear
 * la escala MENSUAL del ISR al período y (b) proyectar montos anuales del reporte
 * DGII de forma correcta. 1.0 mensual, 0.5 quincenal, 0.25 semanal; para CUSTOM =
 * días del período / días del mes de inicio.
 */
function getPeriodMonthFraction($period): float
{
    if (!is_array($period)) {
        return 1.0;
    }
    $type = $period['period_type'] ?? '';
    if ($type === 'BIWEEKLY') {
        return 0.5;
    }
    if ($type === 'WEEKLY') {
        return 0.25;
    }
    if ($type !== 'MONTHLY' && !empty($period['start_date']) && !empty($period['end_date'])) {
        try {
            $sd = new DateTime($period['start_date']);
            $ed = new DateTime($period['end_date']);
            $pDays = $sd->diff($ed)->days + 1;
            $dInMonth = (int) $sd->format('t');
            if ($pDays > 0 && $dInMonth > 0) {
                return $pDays / $dInMonth;
            }
        } catch (Throwable $e) {
            // fallthrough al default mensual
        }
    }
    return 1.0;
}

/**
 * Obtiene descuentos personalizados de un empleado.
 *
 * Si se proveen las fechas de un período de nómina, la deducción se aplica
 * cuando su rango de vigencia [start_date, end_date] se intersecta con el
 * período. Esto garantiza que las cuotas de préstamo programadas desde la
 * app de Finanzas (que registra fechas exactas del período) se apliquen en
 * el cálculo aún cuando la nómina se procese días después del cierre.
 *
 * Si no se proveen fechas, se mantiene el comportamiento legacy basado en
 * CURDATE() para descuentos recurrentes (TSS adicionales, cooperativa, etc.).
 *
 * @param PDO         $pdo
 * @param int         $employeeId
 * @param string|null $periodStart Fecha YYYY-MM-DD inicio del período
 * @param string|null $periodEnd   Fecha YYYY-MM-DD fin del período
 */
function getEmployeeCustomDeductions($pdo, $employeeId, ?string $periodStart = null, ?string $periodEnd = null)
{
    if ($periodStart && $periodEnd) {
        // Intersección entre [start_date, end_date] del descuento y [periodStart, periodEnd]
        $stmt = $pdo->prepare("
            SELECT ed.*, pdc.name as config_name, pdc.type as config_type
            FROM employee_deductions ed
            LEFT JOIN payroll_deduction_config pdc ON pdc.id = ed.deduction_config_id
            WHERE ed.employee_id = ?
              AND ed.is_active = 1
              AND (ed.start_date IS NULL OR ed.start_date <= ?)
              AND (ed.end_date   IS NULL OR ed.end_date   >= ?)
        ");
        $stmt->execute([$employeeId, $periodEnd, $periodStart]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("
        SELECT ed.*, pdc.name as config_name, pdc.type as config_type
        FROM employee_deductions ed
        LEFT JOIN payroll_deduction_config pdc ON pdc.id = ed.deduction_config_id
        WHERE ed.employee_id = ?
        AND ed.is_active = 1
        AND (ed.start_date IS NULL OR ed.start_date <= CURDATE())
        AND (ed.end_date IS NULL OR ed.end_date >= CURDATE())
    ");
    $stmt->execute([$employeeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtiene el total de cuotas de préstamo programadas para un empleado
 * dentro de un período de nómina específico.
 *
 * Detecta las cuotas por su nombre con prefijo "Préstamo " (insertadas
 * por la app de Finanzas vía POST /api/loans/payroll-sync).
 *
 * @return float Monto total de cuotas de préstamo en el período
 */
function getEmployeeLoanDeductionsForPeriod(PDO $pdo, int $employeeId, string $periodStart, string $periodEnd): float
{
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM employee_deductions
            WHERE employee_id = ?
              AND is_active = 1
              AND type = 'FIXED'
              AND (name LIKE 'Préstamo%' OR name LIKE 'Prestamo%')
              AND (start_date IS NULL OR start_date <= ?)
              AND (end_date   IS NULL OR end_date   >= ?)
        ");
        $stmt->execute([$employeeId, $periodEnd, $periodStart]);
        return (float) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0.0;
    }
}

/**
 * Versión batched: retorna [employee_id => total_loan_amount] para un grupo
 * de empleados durante un período. Una sola query, para evitar N+1 en el
 * listado de nómina.
 */
function getLoanDeductionsForEmployees(PDO $pdo, array $employeeIds, string $periodStart, string $periodEnd): array
{
    if (empty($employeeIds)) {
        return [];
    }
    try {
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $params = array_merge(array_map('intval', $employeeIds), [$periodEnd, $periodStart]);
        $stmt = $pdo->prepare("
            SELECT employee_id, COALESCE(SUM(amount), 0) AS total
            FROM employee_deductions
            WHERE employee_id IN ($placeholders)
              AND is_active = 1
              AND type = 'FIXED'
              AND (name LIKE 'Préstamo%' OR name LIKE 'Prestamo%')
              AND (start_date IS NULL OR start_date <= ?)
              AND (end_date   IS NULL OR end_date   >= ?)
            GROUP BY employee_id
        ");
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['employee_id']] = (float) $row['total'];
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Obtiene el detalle de cuotas de préstamo (nombre, monto) para un empleado
 * en un período, útil para mostrar en el slip individual.
 *
 * @return array<array{name:string, amount:float, description:?string}>
 */
function getEmployeeLoanDeductionDetails(PDO $pdo, int $employeeId, string $periodStart, string $periodEnd): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT name, amount, description
            FROM employee_deductions
            WHERE employee_id = ?
              AND is_active = 1
              AND type = 'FIXED'
              AND (name LIKE 'Préstamo%' OR name LIKE 'Prestamo%')
              AND (start_date IS NULL OR start_date <= ?)
              AND (end_date   IS NULL OR end_date   >= ?)
            ORDER BY name
        ");
        $stmt->execute([$employeeId, $periodEnd, $periodStart]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Garantiza la tabla de incentivos manuales por periodo.
 */
function ensurePayrollManualIncentivesTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_manual_incentives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payroll_period_id INT NOT NULL,
            employee_id INT NOT NULL,
            sales_incentive DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            night_incentive DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            use_manual_hours TINYINT(1) NOT NULL DEFAULT 0,
            manual_regular_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            manual_overtime_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_period_employee (payroll_period_id, employee_id),
            INDEX idx_payroll_period_id (payroll_period_id),
            INDEX idx_employee_id (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM payroll_manual_incentives")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('use_manual_hours', $columns, true)) {
        $pdo->exec("ALTER TABLE payroll_manual_incentives ADD COLUMN use_manual_hours TINYINT(1) NOT NULL DEFAULT 0 AFTER night_incentive");
    }
    if (!in_array('manual_regular_hours', $columns, true)) {
        $pdo->exec("ALTER TABLE payroll_manual_incentives ADD COLUMN manual_regular_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER use_manual_hours");
    }
    if (!in_array('manual_overtime_hours', $columns, true)) {
        $pdo->exec("ALTER TABLE payroll_manual_incentives ADD COLUMN manual_overtime_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER manual_regular_hours");
    }
    if (!in_array('cooperative_deduction', $columns, true)) {
        $pdo->exec("ALTER TABLE payroll_manual_incentives ADD COLUMN cooperative_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER notes");
    }
    if (!in_array('additional_deduction', $columns, true)) {
        $pdo->exec("ALTER TABLE payroll_manual_incentives ADD COLUMN additional_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cooperative_deduction");
    }
    // Exonerar el ISR de UNA quincena suelta, sin tocar la marca permanente de
    // la ficha del colaborador.
    if (!in_array('isr_exempt', $columns, true)) {
        $pdo->exec("ALTER TABLE payroll_manual_incentives ADD COLUMN isr_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER additional_deduction");
    }
    // Igual que el ISR, pero para la seguridad social: exonerar AFP y/o SFS de
    // UNA quincena suelta sin tocar la marca permanente de la ficha.
    if (!in_array('afp_exempt', $columns, true)) {
        $pdo->exec("ALTER TABLE payroll_manual_incentives ADD COLUMN afp_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER isr_exempt");
    }
    if (!in_array('sfs_exempt', $columns, true)) {
        $pdo->exec("ALTER TABLE payroll_manual_incentives ADD COLUMN sfs_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER afp_exempt");
    }

    $ensured = true;
}

/**
 * Marca permanente de exención de ISR en la ficha del colaborador.
 *
 * Se guarda quién la puso y cuándo: dejar de retenerle un impuesto a alguien es
 * una decisión que después hay que poder justificar ante la DGII.
 */
function ensureEmployeeIsrExemptColumns(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        $columns = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('isr_exempt', $columns, true)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN isr_exempt TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('isr_exempt_reason', $columns, true)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN isr_exempt_reason VARCHAR(255) NULL AFTER isr_exempt");
        }
        if (!in_array('isr_exempt_by', $columns, true)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN isr_exempt_by INT NULL AFTER isr_exempt_reason");
        }
        if (!in_array('isr_exempt_at', $columns, true)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN isr_exempt_at DATETIME NULL AFTER isr_exempt_by");
        }
    } catch (Throwable $e) {
        error_log('ensureEmployeeIsrExemptColumns: ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * ¿A este colaborador se le retiene ISR en este período?
 *
 * Tres puertas, y basta que una cierre: el interruptor global de Nómina →
 * Configuración, la marca permanente de su ficha, o la exoneración de esa
 * quincena en concreto.
 */
function isEmployeeIsrExempt(PDO $pdo, int $employeeId, bool $periodExempt = false): bool
{
    if ($periodExempt) {
        return true;
    }
    if ($employeeId <= 0) {
        return false;
    }

    // El set completo se lee UNA vez: generar la nómina recorre ~150 empleados y
    // la base es remota, así que una consulta por cabeza se notaría.
    static $exentos = null;
    if ($exentos === null) {
        ensureEmployeeIsrExemptColumns($pdo);
        $exentos = [];
        try {
            foreach ($pdo->query("SELECT id FROM employees WHERE isr_exempt = 1")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $exentos[(int) $id] = true;
            }
        } catch (Throwable $e) {
            error_log('isEmployeeIsrExempt: ' . $e->getMessage());
        }
    }

    return isset($exentos[$employeeId]);
}

/**
 * Marca permanente de exención de seguridad social (AFP / SFS) en la ficha.
 *
 * Se guardan por separado porque en la práctica no siempre van juntas: un
 * pensionado no cotiza AFP pero sí SFS. Igual que con el ISR, se registra quién
 * la puso y cuándo: dejar de cotizarle a alguien hay que poder justificarlo
 * después ante la TSS.
 */
function ensureEmployeeTssExemptColumns(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        $columns = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('afp_exempt', $columns, true)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN afp_exempt TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('sfs_exempt', $columns, true)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN sfs_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER afp_exempt");
        }
        if (!in_array('tss_exempt_reason', $columns, true)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN tss_exempt_reason VARCHAR(255) NULL AFTER sfs_exempt");
        }
        if (!in_array('tss_exempt_by', $columns, true)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN tss_exempt_by INT NULL AFTER tss_exempt_reason");
        }
        if (!in_array('tss_exempt_at', $columns, true)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN tss_exempt_at DATETIME NULL AFTER tss_exempt_by");
        }
    } catch (Throwable $e) {
        error_log('ensureEmployeeTssExemptColumns: ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * ¿A este colaborador se le descuenta AFP y SFS en este período?
 *
 * Devuelve ['afp' => bool, 'sfs' => bool] donde true significa EXENTO. Manda
 * cualquiera de las dos puertas: la marca permanente de su ficha o la
 * exoneración de esa quincena en concreto.
 */
function getEmployeeTssExemptions(PDO $pdo, int $employeeId, bool $periodAfpExempt = false, bool $periodSfsExempt = false): array
{
    // El set completo se lee UNA vez: generar la nómina recorre ~150 empleados
    // y la base es remota, así que una consulta por cabeza se notaría.
    static $exentos = null;
    if ($exentos === null) {
        ensureEmployeeTssExemptColumns($pdo);
        $exentos = [];
        try {
            $rows = $pdo->query("SELECT id, afp_exempt, sfs_exempt FROM employees WHERE afp_exempt = 1 OR sfs_exempt = 1")
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $exentos[(int) $row['id']] = [
                    'afp' => (int) $row['afp_exempt'] === 1,
                    'sfs' => (int) $row['sfs_exempt'] === 1,
                ];
            }
        } catch (Throwable $e) {
            error_log('getEmployeeTssExemptions: ' . $e->getMessage());
        }
    }

    $ficha = ($employeeId > 0 && isset($exentos[$employeeId]))
        ? $exentos[$employeeId]
        : ['afp' => false, 'sfs' => false];

    return [
        'afp' => $ficha['afp'] || $periodAfpExempt,
        'sfs' => $ficha['sfs'] || $periodSfsExempt,
    ];
}

/**
 * Garantiza la tabla payroll_holidays para registrar fechas en que las horas
 * trabajadas se pagan al doble (excluyendo empleados con salario fijo).
 */
function ensurePayrollHolidaysTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payroll_holidays (
                id INT AUTO_INCREMENT PRIMARY KEY,
                holiday_date DATE NOT NULL,
                name VARCHAR(150) NOT NULL DEFAULT 'Día Festivo',
                multiplier DECIMAL(4,2) NOT NULL DEFAULT 2.00,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_holiday_date (holiday_date),
                INDEX idx_holiday_active (is_active, holiday_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Fallar silenciosamente: si no podemos crear la tabla, las funciones
        // dependientes degradarán (no aplicarán doble pago en feriados) en
        // lugar de romper el cálculo de nómina.
    }

    $ensured = true;
}

/**
 * Devuelve un mapa de holidays activos dentro del rango [startDate, endDate]
 * con la forma: ['YYYY-MM-DD' => ['name' => '...', 'multiplier' => 2.0]].
 * Si la tabla no existe o la consulta falla, devuelve un arreglo vacío.
 */
function getPayrollHolidaysMap(PDO $pdo, string $startDate, string $endDate): array
{
    ensurePayrollHolidaysTable($pdo);

    try {
        $stmt = $pdo->prepare("
            SELECT holiday_date, name, multiplier
            FROM payroll_holidays
            WHERE is_active = 1
              AND holiday_date BETWEEN ? AND ?
        ");
        $stmt->execute([$startDate, $endDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $multiplier = (float) $row['multiplier'];
        if ($multiplier <= 0) {
            continue;
        }
        $map[$row['holiday_date']] = [
            'name' => $row['name'],
            'multiplier' => $multiplier,
        ];
    }

    return $map;
}

/**
 * Determina si el empleado debe recibir el pago doble por feriado.
 * Solo aplica a empleados que NO tengan salario fijo (compensación 'fixed').
 */
function shouldApplyHolidayDoublePay(?string $compensationType, ?string $role, float $monthlySalary): bool
{
    $comp = strtolower(trim((string) $compensationType));
    $roleUpper = strtoupper(trim((string) $role));

    if ($comp === '' || $comp === 'hourly') {
        if ($roleUpper !== 'AGENT' && $monthlySalary > 0) {
            $comp = 'fixed';
        }
    }

    return $comp !== 'fixed';
}

/**
 * Garantiza la columna visible_to_agents en payroll_periods.
 * Permite al admin controlar qué quincenas pueden ver los agentes
 * en su panel de horas acumuladas.
 */
function ensurePayrollPeriodsVisibilityColumn(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        $columns = $pdo->query("SHOW COLUMNS FROM payroll_periods")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('visible_to_agents', $columns, true)) {
            $pdo->exec("ALTER TABLE payroll_periods ADD COLUMN visible_to_agents TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
            $pdo->exec("CREATE INDEX idx_payroll_periods_visible_to_agents ON payroll_periods(visible_to_agents)");
        }
    } catch (PDOException $e) {
        // Tabla aún no existe; se creará cuando se instale el módulo de nómina.
    }

    $ensured = true;
}

/**
 * Garantiza la columna users.payroll_source (manual|vicidial). La nómina la lee
 * para decidir si las horas de un empleado vienen del ponche o de Vicidial. Se
 * asegura aquí (no depende de una migración manual) para que un deploy a otra
 * base NO rompa TODA la nómina con "Unknown column payroll_source".
 */
function ensureUserPayrollSourceColumn(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payroll_source', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN payroll_source VARCHAR(10) NOT NULL DEFAULT 'manual'");
        }
    } catch (PDOException $e) {
        // Si users no existe aún, no hay nada que asegurar.
    }
    $ensured = true;
}

/**
 * Garantiza la columna payroll_records.salary_segments, donde queda guardado el
 * desglose cuando un período se pagó con más de un salario (cambio de campaña a
 * mitad de quincena). Se asegura en caliente para no depender de una migración.
 */
function ensurePayrollSalarySegmentsColumn(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM payroll_records")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('salary_segments', $columns, true)) {
            $pdo->exec("ALTER TABLE payroll_records ADD COLUMN salary_segments TEXT NULL COMMENT 'JSON: desglose por tramo cuando hubo cambio de salario en el período'");
        }
        $ready = true;
    } catch (PDOException $e) {
        error_log('ensurePayrollSalarySegmentsColumn: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * Obtiene incentivos manuales por empleado dentro de un periodo.
 */
function getPayrollManualIncentivesMap(PDO $pdo, int $periodId): array
{
    ensurePayrollManualIncentivesTable($pdo);

    $stmt = $pdo->prepare("
        SELECT employee_id, sales_incentive, night_incentive, use_manual_hours, manual_regular_hours, manual_overtime_hours, notes, cooperative_deduction, additional_deduction, isr_exempt, afp_exempt, sfs_exempt
        FROM payroll_manual_incentives
        WHERE payroll_period_id = ?
    ");
    $stmt->execute([$periodId]);

    $incentives = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $incentives[(int) $row['employee_id']] = [
            'sales_incentive' => (float) $row['sales_incentive'],
            'night_incentive' => (float) $row['night_incentive'],
            'use_manual_hours' => (int) ($row['use_manual_hours'] ?? 0),
            'manual_regular_hours' => (float) ($row['manual_regular_hours'] ?? 0),
            'manual_overtime_hours' => (float) ($row['manual_overtime_hours'] ?? 0),
            'notes' => $row['notes'] ?? null,
            'cooperative_deduction' => (float) ($row['cooperative_deduction'] ?? 0),
            'additional_deduction' => (float) ($row['additional_deduction'] ?? 0),
            'isr_exempt' => (int) ($row['isr_exempt'] ?? 0),
            'afp_exempt' => (int) ($row['afp_exempt'] ?? 0),
            'sfs_exempt' => (int) ($row['sfs_exempt'] ?? 0),
        ];
    }

    return $incentives;
}

/**
 * Traduce una compensación (la foto de `users` o la de un tramo del historial) a
 * los tres montos que usa el cálculo: tarifa/hora, sueldo mensual y sueldo diario,
 * ya resueltos en la moneda preferida y con el respaldo de conversión.
 *
 * Se extrajo de calculateEmployeePayroll() para que el camino normal y el camino
 * por tramos (cambio de salario a mitad de período) usen EXACTAMENTE la misma
 * regla y no se puedan desincronizar.
 *
 * @param array<string,mixed> $comp claves de compensationColumns()
 * @return array{type:string,hourly:float,monthly:float,daily:float,currency:string}
 */
function resolvePayrollCompensationAmounts(PDO $pdo, array $comp, string $role): array
{
    $comp = normalizeCompensation($comp);

    $preferredCurrency = $comp['preferred_currency'];
    $hourlyRateUsd     = (float) $comp['hourly_rate'];
    $hourlyRateDop     = (float) $comp['hourly_rate_dop'];
    $monthlySalaryUsd  = (float) $comp['monthly_salary'];
    $monthlySalaryDop  = (float) $comp['monthly_salary_dop'];
    $dailySalaryUsd    = (float) $comp['daily_salary_usd'];
    $dailySalaryDop    = (float) $comp['daily_salary_dop'];

    if ($preferredCurrency === 'DOP') {
        $hourlyRate    = $hourlyRateDop;
        $monthlySalary = $monthlySalaryDop;
        $dailySalary   = $dailySalaryDop;
    } else {
        $hourlyRate    = $hourlyRateUsd;
        $monthlySalary = $monthlySalaryUsd;
        $dailySalary   = $dailySalaryUsd;
    }

    // Fallback de moneda si el valor preferido está en 0 pero existe en la otra moneda
    if ($monthlySalary <= 0) {
        if ($preferredCurrency === 'DOP' && $monthlySalaryUsd > 0) {
            $monthlySalary = convertCurrency($pdo, $monthlySalaryUsd, 'USD', 'DOP');
        } elseif ($preferredCurrency === 'USD' && $monthlySalaryDop > 0) {
            $monthlySalary = convertCurrency($pdo, $monthlySalaryDop, 'DOP', 'USD');
        }
    }
    if ($hourlyRate <= 0) {
        if ($preferredCurrency === 'DOP' && $hourlyRateUsd > 0) {
            $hourlyRate = convertCurrency($pdo, $hourlyRateUsd, 'USD', 'DOP');
        } elseif ($preferredCurrency === 'USD' && $hourlyRateDop > 0) {
            $hourlyRate = convertCurrency($pdo, $hourlyRateDop, 'DOP', 'USD');
        }
    }
    if ($dailySalary <= 0) {
        if ($preferredCurrency === 'DOP' && $dailySalaryUsd > 0) {
            $dailySalary = convertCurrency($pdo, $dailySalaryUsd, 'USD', 'DOP');
        } elseif ($preferredCurrency === 'USD' && $dailySalaryDop > 0) {
            $dailySalary = convertCurrency($pdo, $dailySalaryDop, 'DOP', 'USD');
        }
    }

    $compensationType = $comp['compensation_type'];
    if ($compensationType === '' || $compensationType === 'hourly') {
        if (strtoupper(trim($role)) !== 'AGENT' && $monthlySalary > 0) {
            $compensationType = 'fixed';
        }
    }

    // Si es sueldo fijo y la tarifa/hora no tiene sentido, se deriva del mensual
    // (23.83 días/mes × 8 h/día) para poder pagar horas extra.
    if ($compensationType === 'fixed' && ($hourlyRate <= 0 || $hourlyRate >= $monthlySalary)) {
        $hourlyRate = round($monthlySalary / 23.83 / 8, 2);
    }

    return [
        'type'     => $compensationType,
        'hourly'   => (float) $hourlyRate,
        'monthly'  => (float) $monthlySalary,
        'daily'    => (float) $dailySalary,
        'currency' => $preferredCurrency,
    ];
}

/**
 * Reparte las horas de un tramo cuando NO se conoce el detalle día por día.
 * Se prorratea por días calendario del tramo — aproximación honesta, solo se usa
 * si quien llama no pasó `hours_by_date`.
 *
 * @param array<int,array<string,mixed>> $segments
 * @return array<int,array{regular:float,overtime:float}>
 */
function distributeHoursAcrossSegments(array $segments, float $regularHours, float $overtimeHours): array
{
    $totalDays = 0;
    foreach ($segments as $seg) {
        $totalDays += max(1, (int) $seg['days']);
    }
    if ($totalDays <= 0) {
        return [];
    }

    $out = [];
    foreach ($segments as $i => $seg) {
        $share = max(1, (int) $seg['days']) / $totalDays;
        $out[$i] = [
            'regular'  => $regularHours * $share,
            'overtime' => $overtimeHours * $share,
        ];
    }
    return $out;
}

/**
 * Reparte un total de horas corregidas a mano sobre el calendario del período.
 *
 * Se respeta la FORMA del ponche (cada día conserva su peso relativo). Si no hubo
 * marcaciones, se reparte parejo entre los días del período: sin esto, unas horas
 * corregidas a mano no sabrían de qué lado de un cambio de salario caen.
 *
 * @param array<string,array{regular:float,overtime:float}> $hoursByDate
 * @return array<string,array{regular:float,overtime:float}>
 */
function redistributeHoursByDate(array $hoursByDate, float $regularTotal, float $overtimeTotal, string $startDate, string $endDate): array
{
    $sumRegular = 0.0;
    $sumOvertime = 0.0;
    foreach ($hoursByDate as $h) {
        $sumRegular  += (float) ($h['regular'] ?? 0);
        $sumOvertime += (float) ($h['overtime'] ?? 0);
    }

    if (!empty($hoursByDate) && ($sumRegular > 0 || $sumOvertime > 0)) {
        $out = [];
        $dayCount = count($hoursByDate);
        foreach ($hoursByDate as $date => $h) {
            $rShare = $sumRegular  > 0 ? ((float) $h['regular']  / $sumRegular)  : (1 / $dayCount);
            $oShare = $sumOvertime > 0 ? ((float) $h['overtime'] / $sumOvertime) : (1 / $dayCount);
            $out[$date] = [
                'regular'  => $regularTotal  * $rShare,
                'overtime' => $overtimeTotal * $oShare,
            ];
        }
        return $out;
    }

    // Sin ponche: parejo entre los días calendario del período.
    $days = [];
    try {
        $cursor = new DateTime($startDate);
        $limit  = new DateTime($endDate);
        while ($cursor <= $limit) {
            $days[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }
    } catch (Throwable $e) {
        return $hoursByDate;
    }
    if (empty($days)) {
        return $hoursByDate;
    }

    $out = [];
    foreach ($days as $d) {
        $out[$d] = [
            'regular'  => $regularTotal / count($days),
            'overtime' => $overtimeTotal / count($days),
        ];
    }
    return $out;
}

/**
 * Calcula nómina completa para un empleado
 *
 * $hoursData['hours_by_date'] (opcional) permite pagar cada día con el salario
 * que de verdad le tocaba: si hubo un cambio de compensación con fecha efectiva
 * dentro del período (típicamente por cambio de campaña), el período se parte en
 * tramos y se paga cada tramo con su propia tarifa.
 */
function calculateEmployeePayroll($pdo, $employeeId, $periodId, $hoursData)
{
    // Obtener datos del empleado
    $empStmt = $pdo->prepare("
        SELECT e.*, u.hourly_rate, u.monthly_salary, u.hourly_rate_dop, u.monthly_salary_dop, 
               u.daily_salary_usd, u.daily_salary_dop, u.preferred_currency, u.overtime_multiplier,
               u.compensation_type, u.role
        FROM employees e
        JOIN users u ON u.id = e.user_id
        WHERE e.id = ?
    ");
    $empStmt->execute([$employeeId]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        return null;
    }

    // Obtener datos del periodo (cacheado por llamada)
    static $periodCache = [];
    if (!isset($periodCache[$periodId])) {
        $periodStmt = $pdo->prepare("SELECT period_type, start_date, end_date FROM payroll_periods WHERE id = ?");
        $periodStmt->execute([$periodId]);
        $periodCache[$periodId] = $periodStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $period = $periodCache[$periodId];

    $role = strtoupper(trim($employee['role'] ?? ''));

    // === Compensación del período (con fecha efectiva) ===
    // Un colaborador que cambia de campaña a mitad de quincena y con eso de
    // salario NO puede cobrar toda la quincena a la tarifa nueva: los días
    // anteriores a la fecha efectiva se pagan con la tarifa vieja. Los tramos
    // salen del historial (lib/compensation_history.php); si no hay cambios
    // registrados dentro del período, sale un solo tramo = la compensación de
    // `users` y el cálculo es idéntico al de siempre.
    $compensationSegments = [];
    if ($period && !empty($period['start_date']) && !empty($period['end_date'])) {
        $compensationSegments = getCompensationSegments(
            $pdo,
            (int) $employee['user_id'],
            $period['start_date'],
            $period['end_date']
        );
    }
    if (empty($compensationSegments)) {
        $compensationSegments = [[
            'start'       => $period['start_date'] ?? null,
            'end'         => $period['end_date'] ?? null,
            'days'        => 0,
            'comp'        => normalizeCompensation($employee),
            'change_id'   => null,
            'reason'      => null,
            'campaign_id' => null,
        ]];
    }

    // El tramo vigente al final del período es el que representa "la" compensación
    // del empleado en los campos de salida (tarifa mostrada, tipo de compensación).
    $lastSegment = $compensationSegments[count($compensationSegments) - 1];
    $resolved = resolvePayrollCompensationAmounts($pdo, $lastSegment['comp'], $role);
    $preferredCurrency = $resolved['currency'];
    $hourlyRate    = $resolved['hourly'];
    $monthlySalary = $resolved['monthly'];
    $dailySalary   = $resolved['daily'];

    // Multiplicador de horas extra: prioridad al valor por empleado
    // (users.overtime_multiplier) si está definido; si no, se usa el multiplicador
    // GLOBAL configurado en settings.php (schedule_config.overtime_multiplier), el
    // mismo que ya usan los reportes diarios (records.php, download_daily_attendance_report.php).
    // En RD el recargo legal de horas extra (44–68 h/sem) es 35% => 1.35x
    // (Código de Trabajo, art. 203). Antes este default estaba hardcodeado en 1.5x,
    // por lo que la nómina pagaba el recargo más alto que el resto del sistema y el
    // bruto no cuadraba con "tarifa × horas" (pagaba de más en quienes tenían extras).
    $defaultOvertimeMultiplier = (float) (getScheduleConfig($pdo)['overtime_multiplier'] ?? 1.35);
    if ($defaultOvertimeMultiplier <= 0) {
        $defaultOvertimeMultiplier = 1.35;
    }
    $empOvertimeMultiplier = $employee['overtime_multiplier'];
    $overtimeMultiplier = ($empOvertimeMultiplier !== null && (float) $empOvertimeMultiplier > 0)
        ? (float) $empOvertimeMultiplier
        : $defaultOvertimeMultiplier;

    $compensationType = $resolved['type'];

    // Calcular ingresos
    $regularHours = (float) ($hoursData['regular_hours'] ?? 0);
    $overtimeHours = (float) ($hoursData['overtime_hours'] ?? 0);
    $daysWorked = (int) ($hoursData['days_worked'] ?? 0);

    // Factor de prorrateo del sueldo FIJO al período (idéntico al de siempre).
    $prorationFactor = 1.0;
    $periodDays = 0;
    if ($period) {
        if (!empty($period['start_date']) && !empty($period['end_date'])) {
            $periodDays = (new DateTime($period['start_date']))->diff(new DateTime($period['end_date']))->days + 1;
        }
        if ($period['period_type'] === 'BIWEEKLY') {
            $prorationFactor = 0.5;
        } elseif ($period['period_type'] === 'WEEKLY') {
            $prorationFactor = 0.25;
        } elseif ($period['period_type'] !== 'MONTHLY' && $periodDays > 0) {
            $daysInMonth = (int) (new DateTime($period['start_date']))->format('t');
            if ($daysInMonth > 0) {
                $prorationFactor = $periodDays / $daysInMonth;
            }
        }
    }

    $regularPay = 0.0;
    $baseSalary = 0.0;
    $overtimePay = 0.0;
    $salarySegments = [];

    if (count($compensationSegments) > 1) {
        // === Período con cambio de salario a mitad de camino ===
        // Cada tramo se paga con SU compensación. Las horas del tramo salen del
        // detalle día por día (hours_by_date); si quien llama no lo pasó, se
        // prorratean por días calendario del tramo.
        $hoursByDate = is_array($hoursData['hours_by_date'] ?? null) ? $hoursData['hours_by_date'] : [];

        $segHours = [];
        if (!empty($hoursByDate)) {
            foreach ($compensationSegments as $i => $seg) {
                $r = 0.0;
                $o = 0.0;
                foreach ($hoursByDate as $date => $h) {
                    if ($date >= $seg['start'] && $date <= $seg['end']) {
                        $r += (float) ($h['regular'] ?? 0);
                        $o += (float) ($h['overtime'] ?? 0);
                    }
                }
                $segHours[$i] = ['regular' => $r, 'overtime' => $o];
            }
        } else {
            $segHours = distributeHoursAcrossSegments($compensationSegments, $regularHours, $overtimeHours);
        }

        // Días trabajados por tramo (solo lo usa la compensación DIARIA). Se
        // reparte el total ya calculado para que la suma cuadre exactamente.
        $totalSegHours = 0.0;
        foreach ($segHours as $h) {
            $totalSegHours += $h['regular'] + $h['overtime'];
        }
        $segDays = [];
        $assignedDays = 0;
        foreach ($compensationSegments as $i => $seg) {
            if ($i === count($compensationSegments) - 1) {
                $segDays[$i] = max(0, $daysWorked - $assignedDays);
            } else {
                $share = $totalSegHours > 0
                    ? (($segHours[$i]['regular'] + $segHours[$i]['overtime']) / $totalSegHours)
                    : (max(1, (int) $seg['days']) / max(1, $periodDays));
                $segDays[$i] = (int) round($daysWorked * $share);
                $assignedDays += $segDays[$i];
            }
        }

        foreach ($compensationSegments as $i => $seg) {
            $segResolved = resolvePayrollCompensationAmounts($pdo, $seg['comp'], $role);
            $sr = (float) ($segHours[$i]['regular'] ?? 0);
            $so = (float) ($segHours[$i]['overtime'] ?? 0);

            if ($segResolved['type'] === 'fixed') {
                // El sueldo fijo se reparte por días CALENDARIO del tramo dentro
                // del período: 5 días con el sueldo viejo + 10 con el nuevo.
                $segShare = $periodDays > 0 ? ((int) $seg['days'] / $periodDays) : 1.0;
                $segRegularPay = round($segResolved['monthly'] * $prorationFactor * $segShare, 2);
            } elseif ($segResolved['type'] === 'daily') {
                $segRegularPay = round($segResolved['daily'] * (int) ($segDays[$i] ?? 0), 2);
            } else {
                $segRegularPay = $sr * $segResolved['hourly'];
            }

            $segOvertimePay = $so * $segResolved['hourly'] * $overtimeMultiplier;

            $regularPay  += $segRegularPay;
            $overtimePay += $segOvertimePay;

            $salarySegments[] = [
                'start'          => $seg['start'],
                'end'            => $seg['end'],
                'days'           => (int) $seg['days'],
                'type'           => $segResolved['type'],
                'currency'       => $segResolved['currency'],
                'hourly_rate'    => round($segResolved['hourly'], 4),
                'monthly_salary' => round($segResolved['monthly'], 2),
                'daily_salary'   => round($segResolved['daily'], 2),
                'regular_hours'  => round($sr, 2),
                'overtime_hours' => round($so, 2),
                'days_worked'    => (int) ($segDays[$i] ?? 0),
                'regular_pay'    => round($segRegularPay, 2),
                'overtime_pay'   => round($segOvertimePay, 2),
                'reason'         => $seg['reason'],
                'label'          => formatCompensationLabel($seg['comp']),
            ];
        }

        $regularPay = round($regularPay, 2);
        $baseSalary = $regularPay;
    } elseif ($compensationType === 'fixed') {
        $baseSalary = round($monthlySalary * $prorationFactor, 2);
        $regularPay = $baseSalary;
        $overtimePay = $overtimeHours * $hourlyRate * $overtimeMultiplier;
    } elseif ($compensationType === 'daily') {
        $baseSalary = round($dailySalary * $daysWorked, 2);
        $regularPay = $baseSalary;
        $overtimePay = $overtimeHours * $hourlyRate * $overtimeMultiplier;
    } else {
        $regularPay = $regularHours * $hourlyRate;
        $baseSalary = $regularPay;
        $overtimePay = $overtimeHours * $hourlyRate * $overtimeMultiplier;
    }
    $bonuses = $hoursData['bonuses'] ?? 0;
    $commissions = $hoursData['commissions'] ?? 0;
    $otherIncome = $hoursData['other_income'] ?? 0;
    $nightIncentive = (float) ($hoursData['night_incentive'] ?? 0);
    $salesIncentive = (float) ($hoursData['sales_incentive'] ?? 0);

    $grossSalary = $regularPay + $overtimePay + $bonuses + $commissions + $otherIncome;

    // Obtener descuentos personalizados — pasar fechas del período para que las
    // cuotas de préstamo programadas en el rango exacto se apliquen aún si la
    // nómina se procesa días después del cierre del período.
    $deductionsStart = $period['start_date'] ?? null;
    $deductionsEnd   = $period['end_date']   ?? null;
    $customDeductions = getEmployeeCustomDeductions($pdo, $employeeId, $deductionsStart, $deductionsEnd);

    // Fracción del mes que representa el período (para prorratear la escala
    // MENSUAL del ISR al período). Aplica a todo tipo de compensación.
    $isrPeriodFraction = getPeriodMonthFraction($period);

    // Exención de ISR: la marca permanente de su ficha o la exoneración de esta
    // quincena en concreto.
    $isrExempt = isEmployeeIsrExempt(
        $pdo,
        (int) $employeeId,
        !empty($hoursData['isr_exempt'])
    );

    // Exención de seguridad social (AFP / SFS): igual que el ISR, manda la marca
    // permanente de la ficha o la exoneración de esta quincena en concreto.
    $tssExempt = getEmployeeTssExemptions(
        $pdo,
        (int) $employeeId,
        !empty($hoursData['afp_exempt']),
        !empty($hoursData['sfs_exempt'])
    );

    // Calcular descuentos
    $deductions = calculateAllDeductions($pdo, $grossSalary, $customDeductions, $isrPeriodFraction, $isrExempt, $tssExempt);

    // Aplicar descuento de cooperativa (monto fijo manual)
    $cooperativeDeduction = round((float)($hoursData['cooperative_deduction'] ?? 0), 2);
    if ($cooperativeDeduction > 0) {
        $deductions['custom_deductions'] = round($deductions['custom_deductions'] + $cooperativeDeduction, 2);
        $deductions['total_deductions'] = round($deductions['total_deductions'] + $cooperativeDeduction, 2);
    }

    // Aplicar descuento adicional libre (monto fijo manual)
    $additionalDeduction = round((float)($hoursData['additional_deduction'] ?? 0), 2);
    if ($additionalDeduction > 0) {
        $deductions['custom_deductions'] = round($deductions['custom_deductions'] + $additionalDeduction, 2);
        $deductions['total_deductions'] = round($deductions['total_deductions'] + $additionalDeduction, 2);
    }

    // Guardarraíl: el neto NUNCA puede ser negativo. Las deducciones OBLIGATORIAS
    // (AFP/SFS/ISR) se respetan siempre; si las PERSONALIZADAS (préstamos,
    // cooperativa, adicionales) exceden lo que queda del bruto, se recortan al
    // disponible — el resto queda pendiente para el próximo período, nunca se paga
    // en negativo. Se expone deduction_capped para avisar en la revisión.
    $mandatoryDeductions = round(
        $deductions['afp_employee'] + $deductions['sfs_employee'] + $deductions['isr'],
        2
    );
    $availableForCustom = max(0, round($grossSalary - $mandatoryDeductions, 2));
    $deductions['deduction_capped'] = 0;
    if ($deductions['custom_deductions'] > $availableForCustom) {
        $deductions['deduction_capped'] = round($deductions['custom_deductions'] - $availableForCustom, 2);
        $deductions['custom_deductions'] = $availableForCustom;
        $deductions['total_deductions'] = round($mandatoryDeductions + $availableForCustom, 2);
    }

    // Calcular aportes empleador
    $employerContributions = calculateEmployerContributions($pdo, $grossSalary, $tssExempt);
    $employerContributions['total_employer'] =
        $employerContributions['afp_employer'] +
        $employerContributions['sfs_employer'] +
        $employerContributions['srl_employer'] +
        $employerContributions['infotep_employer'];

    // Calcular neto
    $netSalary = calculateNetSalary($grossSalary, $deductions);

    return [
        'employee_id' => $employeeId,
        'base_salary' => $baseSalary,
        'regular_hours' => $regularHours,
        'overtime_hours' => $overtimeHours,
        'overtime_amount' => $overtimePay,
        'bonuses' => $bonuses,
        'commissions' => $commissions,
        'other_income' => $otherIncome,
        'night_incentive' => $nightIncentive,
        'sales_incentive' => $salesIncentive,
        'gross_salary' => $grossSalary,
        'afp_employee' => $deductions['afp_employee'],
        'sfs_employee' => $deductions['sfs_employee'],
        'isr' => $deductions['isr'],
        'other_deductions' => $deductions['custom_deductions'],
        'total_deductions' => $deductions['total_deductions'],
        'afp_employer' => $employerContributions['afp_employer'],
        'sfs_employer' => $employerContributions['sfs_employer'],
        'srl_employer' => $employerContributions['srl_employer'],
        'infotep_employer' => $employerContributions['infotep_employer'],
        'total_employer_contributions' => $employerContributions['total_employer'],
        'net_salary' => $netSalary,
        'total_hours' => $regularHours + $overtimeHours,
        // Vacío cuando el período se pagó con una sola compensación. Con contenido,
        // el período tuvo un cambio de salario y aquí está el desglose tramo a tramo.
        'salary_segments' => $salarySegments,
        'compensation_type' => $compensationType,
        'hourly_rate_used' => round($hourlyRate, 4),
    ];
}

/**
 * Formatea montos para República Dominicana
 */
function formatDOP($amount)
{
    return 'RD$' . number_format($amount, 2);
}

/**
 * Genera resumen de TSS (Tesorería de la Seguridad Social)
 */
function generateTSSReport($pdo, $periodId)
{
    $stmt = $pdo->prepare("
        SELECT 
            e.employee_code,
            e.first_name,
            e.last_name,
            e.identification_number,
            pr.gross_salary,
            pr.afp_employee,
            pr.afp_employer,
            pr.sfs_employee,
            pr.sfs_employer,
            pr.srl_employer
        FROM payroll_records pr
        JOIN employees e ON e.id = pr.employee_id
        WHERE pr.payroll_period_id = ?
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute([$periodId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Genera resumen de DGII (Dirección General de Impuestos Internos)
 */
function generateDGIIReport($pdo, $periodId)
{
    $stmt = $pdo->prepare("
        SELECT 
            e.employee_code,
            e.first_name,
            e.last_name,
            e.identification_number,
            pr.gross_salary,
            pr.isr,
            pr.net_salary
        FROM payroll_records pr
        JOIN employees e ON e.id = pr.employee_id
        WHERE pr.payroll_period_id = ?
        AND pr.isr > 0
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute([$periodId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
