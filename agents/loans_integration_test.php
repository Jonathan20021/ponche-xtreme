<?php
/**
 * Test rápido de integración ponche → finanzas (BD directa).
 *
 * Uso (sólo para diagnóstico, NO desplegar a producción):
 *   php agents/loans_integration_test.php
 */

declare(strict_types=1);

// Cargar dependencias como en una request normal del portal de agentes
require_once __DIR__ . '/../db.php';            // crea $pdo de ponche
require_once __DIR__ . '/loans_api_client.php'; // carga db_finanzas + helpers

echo "=== Test de integración Ponche ↔ Finanzas (DB directa) ===\n\n";

// 1. Conexión BD finanzas
echo "1. Conexión a hhempeos_financial_system... ";
if (!finanzasDbAvailable()) {
    echo "❌ FALLÓ\n";
    exit(1);
}
echo "✅ OK\n";

// 2. Listar tipos de préstamo
$types = getLoanTypesFromFinance();
echo '2. Tipos para empleados disponibles: ' . count($types) . "\n";
foreach (array_slice($types, 0, 3) as $t) {
    echo "   - {$t['code']}: {$t['name']} (tasa {$t['default_interest_rate']}%)\n";
}

// 3. Buscar un empleado activo para usar en el test
$stmt = $pdo->query("SELECT id, first_name, last_name FROM employees WHERE employment_status='ACTIVE' LIMIT 1");
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    echo "❌ Sin empleados activos para probar\n";
    exit(1);
}
echo "3. Empleado de prueba: #{$emp['id']} {$emp['first_name']} {$emp['last_name']}\n";

// 4. Resolver con detalles + salario
$detail = resolveEmployeeForLoan($pdo, (int) $emp['id']);
if ($detail) {
    $sal = $detail['monthly_salary'] ? 'RD$ ' . number_format($detail['monthly_salary'], 2) : 'sin contrato';
    echo "   Salario mensual equivalente: {$sal}\n";
    echo "   Tipo de pago: " . ($detail['payment_type'] ?? '—') . "\n";
}

// 5. Listar préstamos previos del empleado
$existing = getEmployeeLoansFromFinance((int) $emp['id']);
echo '4. Préstamos previos del empleado: ' . count($existing) . "\n";

// 6. Validar cálculo de amortización con datos de prueba (NO inserta nada)
$test = calculateAmortizationPHP([
    'principal' => 50000,
    'annualInterestRate' => 12,
    'installmentCount' => 12,
    'frequency' => 'biweekly',
    'startDate' => date('Y-m-d', strtotime('+14 days')),
    'method' => 'french',
]);
echo "5. Simulación 50K · 12 cuotas quincenales · 12% anual (francés):\n";
echo "   Cuota: RD$ " . number_format($test['installmentAmount'], 2) . "\n";
echo "   Total: RD$ " . number_format($test['totalPayable'], 2) . "\n";
echo "   Intereses: RD$ " . number_format($test['totalInterest'], 2) . "\n";

// 7. Validación Art. 201 CT
if (!empty($detail['monthly_salary'])) {
    $val = validateAffordabilityPHP($test['installmentAmount'], (float) $detail['monthly_salary'], 'biweekly');
    echo "6. Art. 201 CT: " . ($val['ok'] ? '✅ CUMPLE' : '⚠ EXCEDE') . " ({$val['percentageUsed']}% del salario)\n";
}

// 8. Configuración de notificaciones
$finanzasPdo = getFinanzasPdo();
$ceo = getFinanzasConfig($finanzasPdo, 'LOAN_NOTIFICATION_CEO_EMAIL');
$extra = getFinanzasConfig($finanzasPdo, 'LOAN_NOTIFICATION_EXTRA_EMAIL');
$apiKey = getenv('RESEND_API_KEY');
echo "7. Notificaciones de préstamos:\n";
echo "   LOAN_NOTIFICATION_CEO_EMAIL:    " . ($ceo ?: '(no configurado)') . "\n";
echo "   LOAN_NOTIFICATION_EXTRA_EMAIL:  " . ($extra ?: '(no configurado)') . "\n";
echo "   RESEND_API_KEY (env):           " . ($apiKey ? '✅ presente' : '⚠ no presente en env del servidor PHP') . "\n";

echo "\n=== Test completado sin errores ===\n";
