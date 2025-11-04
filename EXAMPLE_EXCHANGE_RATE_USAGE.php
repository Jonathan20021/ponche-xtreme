<?php
/**
 * EJEMPLO DE USO DEL SISTEMA DE TASA DE CAMBIO
 * 
 * Este archivo muestra cómo integrar la tasa de cambio configurada
 * en los cálculos de nómina, reportes y conversiones de moneda.
 */

require_once 'db.php';

// ============================================================================
// EJEMPLO 1: Obtener la tasa de cambio actual
// ============================================================================

// Obtener la tasa de cambio configurada en el sistema
$exchangeRate = getExchangeRate($pdo);
echo "Tasa de cambio actual: 1 USD = {$exchangeRate} DOP\n\n";

// ============================================================================
// EJEMPLO 2: Convertir montos entre USD y DOP
// ============================================================================

// Convertir 100 USD a DOP
$amountUSD = 100;
$amountDOP = convertCurrency($pdo, $amountUSD, 'USD', 'DOP');
echo "USD {$amountUSD} = DOP " . number_format($amountDOP, 2) . "\n";

// Convertir 5850 DOP a USD
$amountDOP = 5850;
$amountUSD = convertCurrency($pdo, $amountDOP, 'DOP', 'USD');
echo "DOP {$amountDOP} = USD " . number_format($amountUSD, 2) . "\n\n";

// ============================================================================
// EJEMPLO 3: Calcular salario con conversión automática
// ============================================================================

// Obtener compensación de empleados
$compensation = getUserCompensation($pdo);

// Ejemplo con un empleado específico
$username = 'ejemplo_usuario';
if (isset($compensation[$username])) {
    $employeeComp = $compensation[$username];
    $hoursWorked = 160; // horas del mes
    
    echo "Empleado: {$username}\n";
    echo "Moneda preferida: {$employeeComp['preferred_currency']}\n";
    
    // Si tiene tarifa por hora
    if ($employeeComp['hourly_rate'] > 0) {
        $salaryUSD = $employeeComp['hourly_rate'] * $hoursWorked;
        $salaryDOP = convertCurrency($pdo, $salaryUSD, 'USD', 'DOP');
        
        echo "Tarifa por hora: USD {$employeeComp['hourly_rate']}\n";
        echo "Horas trabajadas: {$hoursWorked}\n";
        echo "Salario USD: " . number_format($salaryUSD, 2) . "\n";
        echo "Salario DOP: " . number_format($salaryDOP, 2) . "\n";
    }
    
    // Si tiene tarifa por hora en DOP
    if ($employeeComp['hourly_rate_dop'] > 0) {
        $salaryDOP = $employeeComp['hourly_rate_dop'] * $hoursWorked;
        $salaryUSD = convertCurrency($pdo, $salaryDOP, 'DOP', 'USD');
        
        echo "Tarifa por hora DOP: {$employeeComp['hourly_rate_dop']}\n";
        echo "Salario DOP: " . number_format($salaryDOP, 2) . "\n";
        echo "Salario USD: " . number_format($salaryUSD, 2) . "\n";
    }
    
    echo "\n";
}

// ============================================================================
// EJEMPLO 4: Reporte de nómina con ambas monedas
// ============================================================================

echo "REPORTE DE NÓMINA CON CONVERSIÓN AUTOMÁTICA\n";
echo str_repeat("=", 80) . "\n";

foreach ($compensation as $username => $comp) {
    $hoursWorked = 160; // Ejemplo: 160 horas al mes
    
    // Determinar salario base según tipo de compensación
    $salaryInPreferredCurrency = 0;
    $preferredCurrency = $comp['preferred_currency'];
    
    if ($preferredCurrency === 'USD') {
        if ($comp['hourly_rate'] > 0) {
            $salaryInPreferredCurrency = $comp['hourly_rate'] * $hoursWorked;
        } elseif ($comp['monthly_salary'] > 0) {
            $salaryInPreferredCurrency = $comp['monthly_salary'];
        }
        
        // Convertir a DOP para mostrar equivalente
        $salaryInOtherCurrency = convertCurrency($pdo, $salaryInPreferredCurrency, 'USD', 'DOP');
        
        echo sprintf(
            "%-20s | USD %10s | DOP %10s (equivalente)\n",
            $username,
            number_format($salaryInPreferredCurrency, 2),
            number_format($salaryInOtherCurrency, 2)
        );
    } else {
        if ($comp['hourly_rate_dop'] > 0) {
            $salaryInPreferredCurrency = $comp['hourly_rate_dop'] * $hoursWorked;
        } elseif ($comp['monthly_salary_dop'] > 0) {
            $salaryInPreferredCurrency = $comp['monthly_salary_dop'];
        }
        
        // Convertir a USD para mostrar equivalente
        $salaryInOtherCurrency = convertCurrency($pdo, $salaryInPreferredCurrency, 'DOP', 'USD');
        
        echo sprintf(
            "%-20s | DOP %10s | USD %10s (equivalente)\n",
            $username,
            number_format($salaryInPreferredCurrency, 2),
            number_format($salaryInOtherCurrency, 2)
        );
    }
}

echo "\n";

// ============================================================================
// EJEMPLO 5: Cálculo de horas extras con conversión
// ============================================================================

echo "CÁLCULO DE HORAS EXTRAS CON CONVERSIÓN\n";
echo str_repeat("=", 80) . "\n";

$username = 'ejemplo_usuario';
if (isset($compensation[$username])) {
    $comp = $compensation[$username];
    $regularHours = 160;
    $overtimeHours = 10;
    $overtimeMultiplier = 1.5;
    
    if ($comp['hourly_rate'] > 0) {
        $regularPayUSD = $comp['hourly_rate'] * $regularHours;
        $overtimePayUSD = $comp['hourly_rate'] * $overtimeHours * $overtimeMultiplier;
        $totalPayUSD = $regularPayUSD + $overtimePayUSD;
        
        // Convertir a DOP
        $regularPayDOP = convertCurrency($pdo, $regularPayUSD, 'USD', 'DOP');
        $overtimePayDOP = convertCurrency($pdo, $overtimePayUSD, 'USD', 'DOP');
        $totalPayDOP = convertCurrency($pdo, $totalPayUSD, 'USD', 'DOP');
        
        echo "Empleado: {$username}\n";
        echo "Horas regulares: {$regularHours} @ USD {$comp['hourly_rate']}/hr\n";
        echo "Horas extras: {$overtimeHours} @ USD " . ($comp['hourly_rate'] * $overtimeMultiplier) . "/hr\n";
        echo "\n";
        echo "Pago regular:    USD " . number_format($regularPayUSD, 2) . " = DOP " . number_format($regularPayDOP, 2) . "\n";
        echo "Pago horas extra: USD " . number_format($overtimePayUSD, 2) . " = DOP " . number_format($overtimePayDOP, 2) . "\n";
        echo "TOTAL:           USD " . number_format($totalPayUSD, 2) . " = DOP " . number_format($totalPayDOP, 2) . "\n";
    }
}

echo "\n";

// ============================================================================
// EJEMPLO 6: Actualizar la tasa de cambio (solo para ADMIN/HR)
// ============================================================================

// NOTA: Esto debe hacerse desde la interfaz web hr/system_settings.php
// pero aquí está el código de ejemplo si necesitas hacerlo programáticamente

/*
session_start();
if (isset($_SESSION['user_id']) && userHasPermission('system_settings')) {
    $newRate = 59.50;
    $oldRate = getExchangeRate($pdo);
    
    // Actualizar la tasa
    updateSystemSetting($pdo, 'exchange_rate_usd_to_dop', $newRate, $_SESSION['user_id']);
    updateSystemSetting($pdo, 'exchange_rate_last_update', date('Y-m-d H:i:s'), $_SESSION['user_id']);
    
    // Registrar el cambio en los logs
    log_system_setting_changed($pdo, $_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], 
        'exchange_rate_usd_to_dop', [
            'old_rate' => $oldRate,
            'new_rate' => $newRate,
            'updated_at' => date('Y-m-d H:i:s')
        ]
    );
    
    echo "Tasa de cambio actualizada de {$oldRate} a {$newRate} DOP\n";
}
*/

// ============================================================================
// EJEMPLO 7: Función auxiliar para mostrar salario en ambas monedas
// ============================================================================

function displaySalaryInBothCurrencies($pdo, $amount, $currency) {
    if ($currency === 'USD') {
        $amountDOP = convertCurrency($pdo, $amount, 'USD', 'DOP');
        return sprintf(
            "USD %s (DOP %s)",
            number_format($amount, 2),
            number_format($amountDOP, 2)
        );
    } else {
        $amountUSD = convertCurrency($pdo, $amount, 'DOP', 'USD');
        return sprintf(
            "DOP %s (USD %s)",
            number_format($amount, 2),
            number_format($amountUSD, 2)
        );
    }
}

// Uso de la función auxiliar
$salary = 1000;
echo "Salario: " . displaySalaryInBothCurrencies($pdo, $salary, 'USD') . "\n";
echo "Salario: " . displaySalaryInBothCurrencies($pdo, 58500, 'DOP') . "\n";

echo "\n";
echo "Para más información, consulta EXCHANGE_RATE_SYSTEM.md\n";
?>
