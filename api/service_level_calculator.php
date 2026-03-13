<?php
/**
 * Service Level Calculator API
 * Calcula dimensionamiento de agentes usando fórmula de Erlang C
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Permission check
if (!userHasPermission('wfm_planning')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para usar esta herramienta']);
    exit;
}

/**
 * Erlang C formula implementation
 * Calcula la probabilidad de que una llamada tenga que esperar
 * 
 * @param float $a Intensidad de tráfico (Erlangs)
 * @param int $n Número de agentes
 * @return float Probabilidad de espera
 */
function erlangC(float $a, int $n): float
{
    if ($a <= 0 || $n <= 0) {
        return 0.0;
    }
    
    // Si la intensidad es mayor o igual al número de agentes, el sistema está saturado
    if ($n <= $a) {
        return 1.0;
    }

    // Calcular suma de términos: 1 + a/1! + a²/2! + ... + a^(n-1)/(n-1)!
    $sum = 1.0;
    $term = 1.0;
    
    for ($k = 1; $k <= $n - 1; $k++) {
        $term *= $a / $k;
        $sum += $term;
    }
    
    // Calcular término final: a^n / (n! * (1 - a/n))
    $term *= $a / $n;
    $numer = $term * ($n / ($n - $a));
    
    // Probabilidad de Erlang C
    $denom = $sum + $numer;
    if ($denom == 0.0) {
        return 1.0;
    }
    
    return $numer / $denom;
}

/**
 * Calcula el dimensionamiento de agentes requeridos
 * 
 * @param array $params Parámetros de entrada
 * @return array Resultados del cálculo
 */
function calculateStaffing(array $params): array
{
    // Extraer y validar parámetros
    $intervalMinutes = max(1, (int) ($params['intervalMinutes'] ?? 30));
    $intervalSeconds = $intervalMinutes * 60;
    
    $calls = max(0, (int) ($params['calls'] ?? 0));
    $ahtSeconds = max(1, (int) ($params['ahtSeconds'] ?? 180));
    
    $targetSl = (float) ($params['targetSl'] ?? 80);
    if ($targetSl > 1) {
        $targetSl = $targetSl / 100; // Convertir porcentaje a decimal
    }
    
    $targetAns = max(1, (int) ($params['targetAns'] ?? 20));
    
    $occupancyTarget = (float) ($params['occupancyTarget'] ?? 85);
    if ($occupancyTarget > 1) {
        $occupancyTarget = $occupancyTarget / 100;
    }
    
    $shrinkage = (float) ($params['shrinkage'] ?? 30);
    if ($shrinkage > 1) {
        $shrinkage = $shrinkage / 100;
    }

    // Validaciones
    if ($calls <= 0) {
        throw new Exception('El número de llamadas debe ser mayor que 0');
    }
    
    if ($ahtSeconds <= 0) {
        throw new Exception('El AHT debe ser mayor que 0');
    }
    
    if ($targetSl <= 0 || $targetSl > 1) {
        throw new Exception('El Service Level debe estar entre 0 y 100%');
    }
    
    if ($occupancyTarget <= 0 || $occupancyTarget > 0.95) {
        throw new Exception('La ocupación objetivo debe estar entre 1% y 95%');
    }
    
    if ($shrinkage < 0 || $shrinkage >= 1) {
        throw new Exception('El shrinkage debe estar entre 0% y 99%');
    }

    // Calcular intensidad de tráfico (Erlangs)
    // Erlangs = (Llamadas * AHT en segundos) / Duración del intervalo en segundos
    $workload = 0.0;
    if ($intervalSeconds > 0 && $ahtSeconds > 0 && $calls > 0) {
        $workload = ($calls * $ahtSeconds) / $intervalSeconds;
    }

    // Inicializar variables de resultado
    $requiredAgents = 0;
    $serviceLevel = 1.0;
    $occupancy = 0.0;

    // Calcular agentes requeridos usando Erlang C
    if ($workload > 0 && $ahtSeconds > 0) {
        // Comenzar con el número mínimo de agentes (techo de workload)
        $n = (int) ceil($workload);
        
        // Asegurar que n > workload para evitar sistema saturado
        if ($n <= $workload) {
            $n = (int) floor($workload) + 1;
        }
        
        // Aplicar restricción de ocupación mínima
        if ($occupancyTarget > 0) {
            $minOccupancy = (int) ceil($workload / $occupancyTarget);
            if ($minOccupancy > $n) {
                $n = $minOccupancy;
            }
        }

        // Iterar hasta encontrar el número de agentes que cumple el SL objetivo
        $maxIterations = 200;
        $serviceLevel = 0.0;
        
        for ($i = 0; $i <= $maxIterations; $i++) {
            // Asegurar que n > workload
            if ($n <= $workload) {
                $n++;
                continue;
            }
            
            // Calcular Erlang C (probabilidad de espera)
            $erlangC = erlangC($workload, $n);
            
            // Calcular Service Level usando la fórmula:
            // SL = 1 - (Erlang_C * e^(-(n-a)*(target_ans/aht)))
            $exponent = -($n - $workload) * ($targetAns / $ahtSeconds);
            $serviceLevel = 1 - ($erlangC * exp($exponent));
            
            // Si alcanzamos o superamos el SL objetivo, terminamos
            if ($serviceLevel >= $targetSl) {
                break;
            }
            
            // Incrementar agentes y continuar
            $n++;
        }
        
        $requiredAgents = $n;
        
        // Calcular ocupación real
        $occupancy = $requiredAgents > 0 ? ($workload / $requiredAgents) : 0.0;
    }

    // Calcular staff total con shrinkage
    // Staff = Agentes / (1 - Shrinkage)
    $requiredStaff = $requiredAgents;
    if ($shrinkage > 0 && $shrinkage < 1 && $requiredAgents > 0) {
        $requiredStaff = (int) ceil($requiredAgents / (1 - $shrinkage));
    }

    // Preparar resultado
    return [
        'required_agents' => $requiredAgents,
        'required_staff' => $requiredStaff,
        'service_level' => round($serviceLevel, 4),
        'occupancy' => round($occupancy, 4),
        'workload' => round($workload, 4),
        'interval_seconds' => $intervalSeconds,
        'calls_per_agent' => $requiredAgents > 0 ? round($calls / $requiredAgents, 2) : 0,
        'calls_per_staff' => $requiredStaff > 0 ? round($calls / $requiredStaff, 2) : 0
    ];
}

/**
 * Log de cálculos para auditoría
 */
function logCalculation(int $userId, array $params, array $result): void
{
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO service_level_calculations 
            (user_id, interval_minutes, offered_calls, aht_seconds, target_sl, target_answer_seconds,
             occupancy_target, shrinkage, required_agents, required_staff, calculated_sl, 
             calculated_occupancy, workload_erlangs, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            $params['intervalMinutes'] ?? 0,
            $params['calls'] ?? 0,
            $params['ahtSeconds'] ?? 0,
            $params['targetSl'] ?? 0,
            $params['targetAns'] ?? 0,
            $params['occupancyTarget'] ?? 0,
            $params['shrinkage'] ?? 0,
            $result['required_agents'] ?? 0,
            $result['required_staff'] ?? 0,
            $result['service_level'] ?? 0,
            $result['occupancy'] ?? 0,
            $result['workload'] ?? 0
        ]);
    } catch (PDOException $e) {
        // Si la tabla no existe, silenciosamente ignorar
        // En producción podrías loguear esto
        error_log('Error logging calculation: ' . $e->getMessage());
    }
}

// Main execution
try {
    // Get request body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido');
    }
    
    $action = $data['action'] ?? 'calculate';
    
    switch ($action) {
        case 'calculate':
            // Validar parámetros requeridos
            $requiredParams = ['targetSl', 'targetAns', 'intervalMinutes', 'calls', 'ahtSeconds'];
            foreach ($requiredParams as $param) {
                if (!isset($data[$param])) {
                    throw new Exception("Parámetro requerido faltante: $param");
                }
            }
            
            // Realizar cálculo
            $result = calculateStaffing($data);
            
            // Log del cálculo (opcional)
            logCalculation($_SESSION['user_id'], $data, $result);
            
            // Retornar resultado
            echo json_encode([
                'success' => true,
                'data' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'batch_calculate':
            // Calcular múltiples escenarios
            if (!isset($data['scenarios']) || !is_array($data['scenarios'])) {
                throw new Exception('Se requiere un array de escenarios');
            }
            
            $results = [];
            foreach ($data['scenarios'] as $scenario) {
                try {
                    $results[] = [
                        'success' => true,
                        'params' => $scenario,
                        'result' => calculateStaffing($scenario)
                    ];
                } catch (Exception $e) {
                    $results[] = [
                        'success' => false,
                        'params' => $scenario,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $results,
                'count' => count($results)
            ]);
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
