<?php
/**
 * API de Recomendaciones con IA Gemini - Wasapi Reports
 * Analiza el rendimiento y sugiere dónde reubicar agentes
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Configuración de Gemini API
define('GEMINI_API_KEY', 'AIzaSyDpmWKNdDxjPy5uFVQ7hbEJKZGGJKPmmZM'); // Reemplazar con tu API key
define('GEMINI_MODEL', 'gemini-1.5-flash');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

/**
 * Envía una petición a la API de Gemini
 */
function geminiRequest($prompt, $systemContext = '') {
    $url = GEMINI_API_URL . '?key=' . GEMINI_API_KEY;
    
    $contents = [];
    
    if ($systemContext) {
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $systemContext]]
        ];
        $contents[] = [
            'role' => 'model',
            'parts' => [['text' => 'Entendido. Analizaré los datos de rendimiento de agentes y campañas para proporcionar recomendaciones de reubicación y optimización.']]
        ];
    }
    
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $prompt]]
    ];
    
    $payload = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.7,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 2048,
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => 'Error de conexión: ' . $error];
    }
    
    $data = json_decode($response, true);
    
    if ($httpCode !== 200) {
        return ['error' => 'Error de API: ' . ($data['error']['message'] ?? 'Unknown error')];
    }
    
    // Extraer texto de la respuesta
    $text = '';
    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        $text = $data['candidates'][0]['content']['parts'][0]['text'];
    }
    
    return ['text' => $text, 'raw' => $data];
}

/**
 * Obtiene datos de rendimiento para análisis
 */
function getPerformanceData($pdo, $startDate, $endDate) {
    // Obtener campañas con sus métricas
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            COUNT(DISTINCT e.id) as total_agents,
            COALESCE(SUM(cap.calls_handled), 0) as total_calls,
            COALESCE(AVG(cap.aht_seconds), 0) as avg_aht,
            COALESCE(AVG(
                CASE WHEN cap.login_time_seconds > 0 
                THEN cap.talk_time_seconds / cap.login_time_seconds * 100 
                ELSE 0 END
            ), 0) as avg_productivity
        FROM campaigns c
        LEFT JOIN employees e ON e.campaign_id = c.id
        LEFT JOIN campaign_ast_performance cap ON cap.agent_id = e.id 
            AND cap.report_date BETWEEN ? AND ?
        WHERE c.is_active = 1
        GROUP BY c.id
        ORDER BY c.name
    ");
    $stmt->execute([$startDate, $endDate]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener agentes con bajo rendimiento
    $stmt = $pdo->prepare("
        SELECT 
            e.id,
            CONCAT(e.first_name, ' ', e.last_name) as name,
            e.employee_code,
            c.name as campaign,
            c.id as campaign_id,
            COALESCE(SUM(cap.calls_handled), 0) as total_calls,
            COALESCE(AVG(cap.aht_seconds), 0) as avg_aht,
            COALESCE(AVG(
                CASE WHEN cap.login_time_seconds > 0 
                THEN cap.talk_time_seconds / cap.login_time_seconds * 100 
                ELSE 0 END
            ), 0) as productivity
        FROM employees e
        JOIN users u ON e.user_id = u.id AND u.is_active = 1
        LEFT JOIN campaigns c ON e.campaign_id = c.id
        LEFT JOIN campaign_ast_performance cap ON cap.agent_id = e.id 
            AND cap.report_date BETWEEN ? AND ?
        GROUP BY e.id
        HAVING productivity < 50 OR total_calls < (
            SELECT AVG(calls_per_agent) * 0.5 FROM (
                SELECT SUM(calls_handled) as calls_per_agent 
                FROM campaign_ast_performance 
                WHERE report_date BETWEEN ? AND ?
                GROUP BY agent_id
            ) avg_table
        )
        ORDER BY productivity ASC
        LIMIT 20
    ");
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $lowPerformers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener agentes con alto rendimiento
    $stmt = $pdo->prepare("
        SELECT 
            e.id,
            CONCAT(e.first_name, ' ', e.last_name) as name,
            c.name as campaign,
            COALESCE(SUM(cap.calls_handled), 0) as total_calls,
            COALESCE(AVG(cap.aht_seconds), 0) as avg_aht,
            COALESCE(AVG(
                CASE WHEN cap.login_time_seconds > 0 
                THEN cap.talk_time_seconds / cap.login_time_seconds * 100 
                ELSE 0 END
            ), 0) as productivity
        FROM employees e
        JOIN users u ON e.user_id = u.id AND u.is_active = 1
        LEFT JOIN campaigns c ON e.campaign_id = c.id
        LEFT JOIN campaign_ast_performance cap ON cap.agent_id = e.id 
            AND cap.report_date BETWEEN ? AND ?
        GROUP BY e.id
        HAVING productivity >= 70
        ORDER BY productivity DESC
        LIMIT 10
    ");
    $stmt->execute([$startDate, $endDate]);
    $topPerformers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'campaigns' => $campaigns,
        'low_performers' => $lowPerformers,
        'top_performers' => $topPerformers
    ];
}

try {
    $action = $_GET['action'] ?? 'recommendations';
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-14 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $agentId = $_GET['agent_id'] ?? null;
    
    switch ($action) {
        case 'recommendations':
            // Obtener datos de rendimiento
            $perfData = getPerformanceData($pdo, $startDate, $endDate);
            
            if (empty($perfData['campaigns'])) {
                echo json_encode([
                    'success' => true,
                    'recommendations' => [],
                    'message' => 'No hay suficientes datos para generar recomendaciones'
                ]);
                exit;
            }
            
            // Construir contexto para Gemini
            $systemContext = "Eres un analista de rendimiento de call center experto. Analizas datos de agentes y campañas para recomendar reubicaciones y mejoras. Responde siempre en español con formato estructurado.";
            
            // Construir prompt con datos
            $campaignSummary = "CAMPAÑAS ACTIVAS:\n";
            foreach ($perfData['campaigns'] as $c) {
                $campaignSummary .= "- {$c['name']}: {$c['total_agents']} agentes, {$c['total_calls']} llamadas, AHT: " . gmdate('i:s', (int)$c['avg_aht']) . ", Productividad: " . round($c['avg_productivity'], 1) . "%\n";
            }
            
            $lowPerfSummary = "\nAGENTES CON BAJO RENDIMIENTO:\n";
            foreach ($perfData['low_performers'] as $a) {
                $lowPerfSummary .= "- {$a['name']} (Campaña: {$a['campaign']}): {$a['total_calls']} llamadas, Productividad: " . round($a['productivity'], 1) . "%, AHT: " . gmdate('i:s', (int)$a['avg_aht']) . "\n";
            }
            
            $topPerfSummary = "\nAGENTES TOP PERFORMERS:\n";
            foreach ($perfData['top_performers'] as $a) {
                $topPerfSummary .= "- {$a['name']} (Campaña: {$a['campaign']}): Productividad: " . round($a['productivity'], 1) . "%\n";
            }
            
            $prompt = "Analiza los siguientes datos de rendimiento del período {$startDate} al {$endDate}:\n\n" 
                . $campaignSummary 
                . $lowPerfSummary 
                . $topPerfSummary 
                . "\n\nProporciona:\n"
                . "1. RESUMEN EJECUTIVO: Análisis general del rendimiento (2-3 oraciones)\n"
                . "2. RECOMENDACIONES DE REUBICACIÓN: Para cada agente de bajo rendimiento, sugiere a qué campaña moverlo y por qué (considera la carga de trabajo y el perfil de cada campaña)\n"
                . "3. ALERTAS: Campañas que necesitan más personal o atención\n"
                . "4. ACCIONES INMEDIATAS: Lista de acciones concretas ordenadas por prioridad\n\n"
                . "Responde en formato JSON con esta estructura:\n"
                . '{"executive_summary": "...", "relocations": [{"agent_name": "...", "current_campaign": "...", "suggested_campaign": "...", "reason": "..."}], "alerts": [{"campaign": "...", "type": "...", "message": "..."}], "actions": [{"priority": 1, "action": "...", "impact": "..."}]}';
            
            $response = geminiRequest($prompt, $systemContext);
            
            if (isset($response['error'])) {
                echo json_encode([
                    'success' => false,
                    'error' => $response['error']
                ]);
                exit;
            }
            
            // Intentar parsear la respuesta como JSON
            $aiText = $response['text'];
            $recommendations = null;
            
            // Buscar JSON en la respuesta
            if (preg_match('/\{[\s\S]*\}/m', $aiText, $matches)) {
                $recommendations = json_decode($matches[0], true);
            }
            
            echo json_encode([
                'success' => true,
                'recommendations' => $recommendations,
                'raw_response' => $aiText,
                'data_analyzed' => [
                    'campaigns_count' => count($perfData['campaigns']),
                    'low_performers_count' => count($perfData['low_performers']),
                    'top_performers_count' => count($perfData['top_performers'])
                ],
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'agent_analysis':
            // Análisis individual de un agente
            if (!$agentId) {
                echo json_encode(['success' => false, 'error' => 'agent_id requerido']);
                exit;
            }
            
            // Obtener datos del agente
            $stmt = $pdo->prepare("
                SELECT 
                    e.id,
                    CONCAT(e.first_name, ' ', e.last_name) as name,
                    e.employee_code,
                    c.name as campaign,
                    c.id as campaign_id,
                    COALESCE(SUM(cap.calls_handled), 0) as total_calls,
                    COALESCE(AVG(cap.aht_seconds), 0) as avg_aht,
                    COALESCE(SUM(cap.login_time_seconds)/3600, 0) as total_hours,
                    COALESCE(AVG(
                        CASE WHEN cap.login_time_seconds > 0 
                        THEN cap.talk_time_seconds / cap.login_time_seconds * 100 
                        ELSE 0 END
                    ), 0) as productivity,
                    COUNT(DISTINCT cap.report_date) as days_worked
                FROM employees e
                LEFT JOIN campaigns c ON e.campaign_id = c.id
                LEFT JOIN campaign_ast_performance cap ON cap.agent_id = e.id 
                    AND cap.report_date BETWEEN ? AND ?
                WHERE e.id = ?
                GROUP BY e.id
            ");
            $stmt->execute([$startDate, $endDate, $agentId]);
            $agent = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$agent) {
                echo json_encode(['success' => false, 'error' => 'Agente no encontrado']);
                exit;
            }
            
            // Obtener tendencia diaria
            $stmt = $pdo->prepare("
                SELECT 
                    report_date,
                    calls_handled,
                    aht_seconds,
                    CASE WHEN login_time_seconds > 0 
                        THEN talk_time_seconds / login_time_seconds * 100 
                        ELSE 0 END as productivity
                FROM campaign_ast_performance
                WHERE agent_id = ? AND report_date BETWEEN ? AND ?
                ORDER BY report_date
            ");
            $stmt->execute([$agentId, $startDate, $endDate]);
            $dailyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Obtener campañas disponibles
            $stmt = $pdo->query("SELECT id, name FROM campaigns WHERE is_active = 1 ORDER BY name");
            $availableCampaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Construir prompt para análisis individual
            $systemContext = "Eres un coach de rendimiento de call center. Analizas el desempeño de agentes individuales y proporcionas recomendaciones personalizadas. Responde en español.";
            
            $trendSummary = "";
            foreach ($dailyData as $d) {
                $trendSummary .= "- {$d['report_date']}: {$d['calls_handled']} llamadas, Productividad: " . round($d['productivity'], 1) . "%\n";
            }
            
            $campaignsList = implode(', ', array_column($availableCampaigns, 'name'));
            
            $prompt = "Analiza el rendimiento del agente:\n\n"
                . "AGENTE: {$agent['name']}\n"
                . "CAMPAÑA ACTUAL: {$agent['campaign']}\n"
                . "PERÍODO: {$startDate} a {$endDate}\n"
                . "DÍAS TRABAJADOS: {$agent['days_worked']}\n"
                . "TOTAL LLAMADAS: {$agent['total_calls']}\n"
                . "HORAS TOTALES: " . round($agent['total_hours'], 1) . "\n"
                . "PRODUCTIVIDAD PROMEDIO: " . round($agent['productivity'], 1) . "%\n"
                . "AHT PROMEDIO: " . gmdate('i:s', (int)$agent['avg_aht']) . "\n\n"
                . "TENDENCIA DIARIA:\n{$trendSummary}\n\n"
                . "CAMPAÑAS DISPONIBLES: {$campaignsList}\n\n"
                . "Proporciona:\n"
                . "1. DIAGNÓSTICO: Evaluación del rendimiento actual\n"
                . "2. FORTALEZAS: Puntos fuertes del agente\n"
                . "3. ÁREAS DE MEJORA: Que necesita trabajar\n"
                . "4. RECOMENDACIÓN DE UBICACIÓN: ¿Debería cambiar de campaña? ¿A cuál?\n"
                . "5. PLAN DE ACCIÓN: Pasos concretos para mejorar\n\n"
                . "Responde en formato JSON:\n"
                . '{"diagnosis": "...", "performance_score": 0-100, "strengths": ["..."], "improvements": ["..."], "relocation": {"recommended": true/false, "campaign": "...", "reason": "..."}, "action_plan": [{"step": 1, "action": "...", "timeframe": "..."}]}';
            
            $response = geminiRequest($prompt, $systemContext);
            
            if (isset($response['error'])) {
                echo json_encode([
                    'success' => false,
                    'error' => $response['error']
                ]);
                exit;
            }
            
            $aiText = $response['text'];
            $analysis = null;
            
            if (preg_match('/\{[\s\S]*\}/m', $aiText, $matches)) {
                $analysis = json_decode($matches[0], true);
            }
            
            echo json_encode([
                'success' => true,
                'agent' => $agent,
                'analysis' => $analysis,
                'raw_response' => $aiText,
                'daily_trend' => $dailyData,
                'available_campaigns' => $availableCampaigns,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]
            ]);
            break;
            
        case 'quick_insights':
            // Insights rápidos sin análisis profundo de IA (usando reglas)
            $perfData = getPerformanceData($pdo, $startDate, $endDate);
            
            $insights = [];
            
            // Detectar campañas con baja productividad
            foreach ($perfData['campaigns'] as $c) {
                if ($c['avg_productivity'] < 50 && $c['total_agents'] > 0) {
                    $insights[] = [
                        'type' => 'warning',
                        'category' => 'campaign',
                        'title' => "Baja productividad en {$c['name']}",
                        'message' => "La campaña {$c['name']} tiene una productividad promedio de " . round($c['avg_productivity'], 1) . "%. Considera revisar los procesos o redistribuir personal.",
                        'campaign_id' => $c['id']
                    ];
                }
                
                if ($c['total_agents'] < 3 && $c['total_calls'] > 0) {
                    $insights[] = [
                        'type' => 'alert',
                        'category' => 'staffing',
                        'title' => "Personal insuficiente en {$c['name']}",
                        'message' => "La campaña {$c['name']} solo tiene {$c['total_agents']} agentes. Considera asignar más personal.",
                        'campaign_id' => $c['id']
                    ];
                }
            }
            
            // Sugerir reubicaciones basadas en reglas
            $relocations = [];
            foreach ($perfData['low_performers'] as $agent) {
                // Buscar campaña con mejor productividad promedio que tenga espacio
                $bestCampaign = null;
                $bestProd = 0;
                
                foreach ($perfData['campaigns'] as $c) {
                    if ($c['id'] != $agent['campaign_id'] && $c['avg_productivity'] > $bestProd && $c['total_agents'] < 15) {
                        $bestProd = $c['avg_productivity'];
                        $bestCampaign = $c;
                    }
                }
                
                if ($bestCampaign && $bestProd > $agent['productivity'] + 20) {
                    $relocations[] = [
                        'agent_id' => $agent['id'],
                        'agent_name' => $agent['name'],
                        'current_campaign' => $agent['campaign'],
                        'current_productivity' => round($agent['productivity'], 1),
                        'suggested_campaign' => $bestCampaign['name'],
                        'suggested_campaign_id' => $bestCampaign['id'],
                        'expected_improvement' => round($bestProd - $agent['productivity'], 1)
                    ];
                }
            }
            
            echo json_encode([
                'success' => true,
                'insights' => $insights,
                'relocations' => array_slice($relocations, 0, 10),
                'summary' => [
                    'total_campaigns' => count($perfData['campaigns']),
                    'low_performers' => count($perfData['low_performers']),
                    'top_performers' => count($perfData['top_performers']),
                    'suggested_relocations' => count($relocations)
                ],
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
