<?php
/**
 * FUNCIONES DE CÁLCULO DE NÓMINA PARA REPÚBLICA DOMINICANA
 * Incluye: AFP, SFS, ISR según normativas vigentes 2025
 */

/**
 * Obtiene las tasas de descuentos desde la base de datos
 */
function getDeductionRates($pdo) {
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
 */
function calculateAFP($pdo, $grossSalary, $isEmployer = false) {
    $rates = getDeductionRates($pdo);
    if (!$rates['AFP']['is_active']) return 0.00;
    $rate = $isEmployer ? $rates['AFP']['employer_percentage'] : $rates['AFP']['employee_percentage'];
    return round($grossSalary * ($rate / 100), 2);
}

/**
 * Calcula el SFS (Seguro Familiar de Salud)
 */
function calculateSFS($pdo, $grossSalary, $isEmployer = false) {
    $rates = getDeductionRates($pdo);
    if (!$rates['SFS']['is_active']) return 0.00;
    $rate = $isEmployer ? $rates['SFS']['employer_percentage'] : $rates['SFS']['employee_percentage'];
    return round($grossSalary * ($rate / 100), 2);
}

/**
 * Calcula el SRL (Seguro de Riesgos Laborales)
 */
function calculateSRL($pdo, $grossSalary) {
    $rates = getDeductionRates($pdo);
    if (!$rates['SRL']['is_active']) return 0.00;
    $rate = $rates['SRL']['employer_percentage'];
    return round($grossSalary * ($rate / 100), 2);
}

/**
 * Calcula INFOTEP
 */
function calculateINFOTEP($pdo, $grossSalary) {
    $rates = getDeductionRates($pdo);
    if (!$rates['INFOTEP']['is_active']) return 0.00;
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
function calculateISR($monthlyGrossSalary) {
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
 * Calcula todos los descuentos de un empleado
 */
function calculateAllDeductions($pdo, $grossSalary, $customDeductions = []) {
    $deductions = [
        'afp_employee' => calculateAFP($pdo, $grossSalary, false),
        'sfs_employee' => calculateSFS($pdo, $grossSalary, false),
        'isr' => calculateISR($grossSalary),
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
 * Calcula todos los aportes del empleador
 */
function calculateEmployerContributions($pdo, $grossSalary) {
    return [
        'afp_employer' => calculateAFP($pdo, $grossSalary, true),
        'sfs_employer' => calculateSFS($pdo, $grossSalary, true),
        'srl_employer' => calculateSRL($pdo, $grossSalary),
        'infotep_employer' => calculateINFOTEP($pdo, $grossSalary),
        'total_employer' => 0
    ];
}

/**
 * Calcula el salario neto
 */
function calculateNetSalary($grossSalary, $deductions) {
    return round($grossSalary - $deductions['total_deductions'], 2);
}

/**
 * Obtiene descuentos personalizados de un empleado
 */
function getEmployeeCustomDeductions($pdo, $employeeId) {
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
 * Calcula nómina completa para un empleado
 */
function calculateEmployeePayroll($pdo, $employeeId, $periodId, $hoursData) {
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

    // Calcular salario base usando la moneda preferida del empleado
    $preferredCurrency = $employee['preferred_currency'] ?? 'USD';
    $preferredCurrency = strtoupper(trim($preferredCurrency));
    $hourlyRateUsd = (float)$employee['hourly_rate'];
    $hourlyRateDop = (float)$employee['hourly_rate_dop'];
    $monthlySalaryUsd = (float)$employee['monthly_salary'];
    $monthlySalaryDop = (float)$employee['monthly_salary_dop'];
    $dailySalaryUsd = (float)$employee['daily_salary_usd'];
    $dailySalaryDop = (float)$employee['daily_salary_dop'];

    if ($preferredCurrency === 'DOP') {
        $hourlyRate = $hourlyRateDop;
        $monthlySalary = $monthlySalaryDop;
        $dailySalary = $dailySalaryDop;
    } else {
        $hourlyRate = $hourlyRateUsd;
        $monthlySalary = $monthlySalaryUsd;
        $dailySalary = $dailySalaryUsd;
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
    $overtimeMultiplier = (float)($employee['overtime_multiplier'] ?? 1.5);
    $compensationType = strtolower(trim($employee['compensation_type'] ?? 'hourly'));
    $role = strtoupper(trim($employee['role'] ?? ''));
    if ($compensationType === '' || $compensationType === 'hourly') {
        if ($role !== 'AGENT' && $monthlySalary > 0) {
            $compensationType = 'fixed';
        }
    }
    
    // Calcular ingresos
    $regularHours = (float)($hoursData['regular_hours'] ?? 0);
    $overtimeHours = (float)($hoursData['overtime_hours'] ?? 0);
    $daysWorked = (int)($hoursData['days_worked'] ?? 0);

    $regularPay = 0.0;
    $baseSalary = 0.0;

    if ($compensationType === 'fixed') {
        $prorationFactor = 1.0;
        if ($period) {
            if ($period['period_type'] === 'BIWEEKLY') {
                $prorationFactor = 0.5;
            } elseif ($period['period_type'] === 'WEEKLY') {
                $prorationFactor = 0.25;
            } elseif ($period['period_type'] !== 'MONTHLY'
                && !empty($period['start_date'])
                && !empty($period['end_date'])) {
                $startDate = new DateTime($period['start_date']);
                $endDate = new DateTime($period['end_date']);
                $periodDays = $startDate->diff($endDate)->days + 1;
                $daysInMonth = (int)$startDate->format('t');
                if ($periodDays > 0 && $daysInMonth > 0) {
                    $prorationFactor = $periodDays / $daysInMonth;
                }
            }
        }
        $baseSalary = round($monthlySalary * $prorationFactor, 2);
        $regularPay = $baseSalary;
    } elseif ($compensationType === 'daily') {
        $baseSalary = round($dailySalary * $daysWorked, 2);
        $regularPay = $baseSalary;
    } else {
        $regularPay = $regularHours * $hourlyRate;
        $baseSalary = $regularPay;
    }

    $overtimePay = $overtimeHours * $hourlyRate * $overtimeMultiplier;
    $bonuses = $hoursData['bonuses'] ?? 0;
    $commissions = $hoursData['commissions'] ?? 0;
    $otherIncome = $hoursData['other_income'] ?? 0;
    
    $grossSalary = $regularPay + $overtimePay + $bonuses + $commissions + $otherIncome;
    
    // Obtener descuentos personalizados
    $customDeductions = getEmployeeCustomDeductions($pdo, $employeeId);
    
    // Calcular descuentos
    $deductions = calculateAllDeductions($pdo, $grossSalary, $customDeductions);
    
    // Calcular aportes empleador
    $employerContributions = calculateEmployerContributions($pdo, $grossSalary);
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
        'total_hours' => $regularHours + $overtimeHours
    ];
}

/**
 * Formatea montos para República Dominicana
 */
function formatDOP($amount) {
    return 'RD$' . number_format($amount, 2);
}

/**
 * Genera resumen de TSS (Tesorería de la Seguridad Social)
 */
function generateTSSReport($pdo, $periodId) {
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
function generateDGIIReport($pdo, $periodId) {
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
