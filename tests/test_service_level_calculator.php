<?php
/**
 * Service Level Calculator - Test Script
 * Prueba la API sin necesidad de interfaz web
 */

require_once __DIR__ . '/../db.php';

echo "=== Service Level Calculator Test ===\n\n";

// Test 1: Escenario estándar
echo "Test 1: Escenario Estándar\n";
echo "- 100 llamadas en 30 minutos\n";
echo "- AHT: 240 segundos\n";
echo "- SL objetivo: 80% en 20 segundos\n\n";

$params1 = [
    'targetSl' => 80,
    'targetAns' => 20,
    'intervalMinutes' => 30,
    'calls' => 100,
    'ahtSeconds' => 240,
    'occupancyTarget' => 85,
    'shrinkage' => 30
];

// Simular cálculo (copiando la función de la API)
function erlangC(float $a, int $n): float
{
    if ($a <= 0 || $n <= 0) return 0.0;
    if ($n <= $a) return 1.0;

    $sum = 1.0;
    $term = 1.0;
    
    for ($k = 1; $k <= $n - 1; $k++) {
        $term *= $a / $k;
        $sum += $term;
    }
    
    $term *= $a / $n;
    $numer = $term * ($n / ($n - $a));
    $denom = $sum + $numer;
    
    return $denom == 0.0 ? 1.0 : $numer / $denom;
}

function testCalculation($params) {
    $intervalMinutes = $params['intervalMinutes'];
    $intervalSeconds = $intervalMinutes * 60;
    $calls = $params['calls'];
    $ahtSeconds = $params['ahtSeconds'];
    $targetSl = $params['targetSl'] > 1 ? $params['targetSl'] / 100 : $params['targetSl'];
    $targetAns = $params['targetAns'];
    $occupancyTarget = $params['occupancyTarget'] > 1 ? $params['occupancyTarget'] / 100 : $params['occupancyTarget'];
    $shrinkage = $params['shrinkage'] > 1 ? $params['shrinkage'] / 100 : $params['shrinkage'];

    $workload = ($calls * $ahtSeconds) / $intervalSeconds;
    
    $n = (int) ceil($workload);
    if ($n <= $workload) $n = (int) floor($workload) + 1;
    
    $minOccupancy = (int) ceil($workload / $occupancyTarget);
    if ($minOccupancy > $n) $n = $minOccupancy;

    $serviceLevel = 0.0;
    for ($i = 0; $i <= 200; $i++) {
        if ($n <= $workload) {
            $n++;
            continue;
        }
        
        $ec = erlangC($workload, $n);
        $exponent = -($n - $workload) * ($targetAns / $ahtSeconds);
        $serviceLevel = 1 - ($ec * exp($exponent));
        
        if ($serviceLevel >= $targetSl) break;
        $n++;
    }
    
    $requiredAgents = $n;
    $occupancy = $requiredAgents > 0 ? ($workload / $requiredAgents) : 0.0;
    $requiredStaff = (int) ceil($requiredAgents / (1 - $shrinkage));

    return [
        'required_agents' => $requiredAgents,
        'required_staff' => $requiredStaff,
        'service_level' => $serviceLevel,
        'occupancy' => $occupancy,
        'workload' => $workload
    ];
}

$result1 = testCalculation($params1);

echo "Resultados:\n";
echo "- Agentes requeridos: " . $result1['required_agents'] . "\n";
echo "- Staff total (con shrinkage): " . $result1['required_staff'] . "\n";
echo "- Service Level: " . round($result1['service_level'] * 100, 2) . "%\n";
echo "- Occupancy: " . round($result1['occupancy'] * 100, 2) . "%\n";
echo "- Workload (Erlangs): " . round($result1['workload'], 3) . "\n";

// Validación
$passed = true;
if ($result1['required_agents'] < $result1['workload']) {
    echo "\n⚠️  ALERTA: Agentes < Workload (sistema saturado)\n";
    $passed = false;
}

if ($result1['service_level'] >= ($params1['targetSl'] / 100)) {
    echo "\n✅ SL objetivo cumplido\n";
} else {
    echo "\n❌ SL objetivo NO cumplido\n";
    $passed = false;
}

if ($result1['occupancy'] >= 0.70 && $result1['occupancy'] <= 0.90) {
    echo "✅ Occupancy en rango óptimo\n";
} else if ($result1['occupancy'] > 0.90) {
    echo "⚠️  Occupancy alta - riesgo de burnout\n";
} else {
    echo "⚠️  Occupancy baja - subutilización\n";
}

echo "\n" . str_repeat("=", 50) . "\n\n";

// Test 2: Alto volumen
echo "Test 2: Escenario Alto Volumen\n";
echo "- 200 llamadas en 15 minutos\n";
echo "- AHT: 180 segundos\n";
echo "- SL objetivo: 80% en 20 segundos\n\n";

$params2 = [
    'targetSl' => 80,
    'targetAns' => 20,
    'intervalMinutes' => 15,
    'calls' => 200,
    'ahtSeconds' => 180,
    'occupancyTarget' => 85,
    'shrinkage' => 30
];

$result2 = testCalculation($params2);

echo "Resultados:\n";
echo "- Agentes requeridos: " . $result2['required_agents'] . "\n";
echo "- Staff total: " . $result2['required_staff'] . "\n";
echo "- Service Level: " . round($result2['service_level'] * 100, 2) . "%\n";
echo "- Occupancy: " . round($result2['occupancy'] * 100, 2) . "%\n";
echo "- Workload (Erlangs): " . round($result2['workload'], 3) . "\n\n";

echo str_repeat("=", 50) . "\n\n";

// Test 3: Premium (SL alto)
echo "Test 3: Escenario Premium\n";
echo "- 80 llamadas en 30 minutos\n";
echo "- AHT: 420 segundos\n";
echo "- SL objetivo: 90% en 15 segundos\n\n";

$params3 = [
    'targetSl' => 90,
    'targetAns' => 15,
    'intervalMinutes' => 30,
    'calls' => 80,
    'ahtSeconds' => 420,
    'occupancyTarget' => 85,
    'shrinkage' => 30
];

$result3 = testCalculation($params3);

echo "Resultados:\n";
echo "- Agentes requeridos: " . $result3['required_agents'] . "\n";
echo "- Staff total: " . $result3['required_staff'] . "\n";
echo "- Service Level: " . round($result3['service_level'] * 100, 2) . "%\n";
echo "- Occupancy: " . round($result3['occupancy'] * 100, 2) . "%\n";
echo "- Workload (Erlangs): " . round($result3['workload'], 3) . "\n\n";

echo str_repeat("=", 50) . "\n\n";

// Comparación de escenarios
echo "Comparación de Escenarios:\n\n";
echo sprintf("%-20s | %-10s | %-10s | %-10s\n", "Escenario", "Agentes", "Staff", "SL (%)");
echo str_repeat("-", 60) . "\n";
echo sprintf("%-20s | %-10d | %-10d | %-10.2f\n", "Estándar", $result1['required_agents'], $result1['required_staff'], $result1['service_level'] * 100);
echo sprintf("%-20s | %-10d | %-10d | %-10.2f\n", "Alto Volumen", $result2['required_agents'], $result2['required_staff'], $result2['service_level'] * 100);
echo sprintf("%-20s | %-10d | %-10d | %-10.2f\n", "Premium", $result3['required_agents'], $result3['required_staff'], $result3['service_level'] * 100);

echo "\n✅ Todos los tests completados\n";
echo "\nPara probar la interfaz web, visita:\n";
echo "http://localhost/ponche-xtreme/hr/service_level_calculator.php\n";
