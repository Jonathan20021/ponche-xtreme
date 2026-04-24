<?php
/**
 * API de Análisis Avanzado con Claude AI - Wasapi Reports
 *
 * Usa la API de Anthropic (Claude) para generar reportes de alta calidad
 * orientados a toma de decisiones. Se alimenta de datos reales de Wasapi
 * (KPIs, performance por agente, workflow, staffing) y produce salidas
 * estructuradas en JSON listas para renderizar en el dashboard.
 *
 * Endpoints:
 *   action=executive_report       -> Reporte ejecutivo integral
 *   action=operations_diagnosis   -> Diagnóstico operativo y plan 30/60/90
 *   action=agent_coaching         -> Plan de coaching individual (agent_id requerido)
 *   action=risk_radar             -> Radar de riesgos (SLA, agotamiento, cobertura)
 *   action=staffing_forecast      -> Forecast y plan de staffing semanal
 *   action=campaign_optimizer     -> Optimización y reasignación por campaña
 *
 * La clave de API y el modelo se leen desde system_settings (settings.php):
 *   anthropic_api_key / anthropic_default_model
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Accel-Buffering: no');

// Claude + Wasapi requests can take up to ~120s in the worst case.
@set_time_limit(180);
@ini_set('max_execution_time', '180');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/claude_api_client.php';

// Ensure $pdo is visible in the local script scope for downstream helpers.
if (!isset($pdo)) {
    global $pdo;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

define('WASAPI_TOKEN', '338529|NeQrFHvdJ3lX6O2Hs26QPjc0IyrgzKFxQGwVcvCM0575a229');
define('WASAPI_BASE_URL', 'https://api.wasapi.io/prod/api/v1/');

// -------------------------------------------------------------
// Helpers
// -------------------------------------------------------------

function wasapiMultiRequest(array $endpoints): array {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($endpoints as $key => $endpoint) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => WASAPI_BASE_URL . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . WASAPI_TOKEN,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            // Wait for activity with a cap so we never busy-loop
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);
    $results = [];
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $results[$key] = json_decode($body, true) ?? [];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

function fmtSeconds($sec): string {
    $sec = max(0, (int) $sec);
    if ($sec < 60) return $sec . 's';
    if ($sec < 3600) return floor($sec / 60) . 'm ' . ($sec % 60) . 's';
    $h = floor($sec / 3600);
    $m = floor(($sec % 3600) / 60);
    return $h . 'h ' . $m . 'm';
}

function percentileList(array $arr, float $p): float {
    if (empty($arr)) return 0.0;
    sort($arr);
    $idx = ($p / 100) * (count($arr) - 1);
    $floor = (int) floor($idx);
    $frac = $idx - $floor;
    if ($floor + 1 < count($arr)) {
        return $arr[$floor] + $frac * ($arr[$floor + 1] - $arr[$floor]);
    }
    return $arr[$floor];
}

/**
 * Intenta extraer un objeto JSON del texto devuelto por Claude.
 * Tolera fences ```json ... ``` y texto adicional alrededor.
 *
 * Estrategia:
 *   1. Intentar json_decode directo.
 *   2. Quitar fences ```json / ``` y reintentar.
 *   3. Localizar el primer `{` y buscar su `}` cerrando con conteo de llaves
 *      (respeta strings con comillas escapadas).
 */
function extractJsonFromText(string $text): ?array {
    $trim = trim($text);
    $decoded = json_decode($trim, true);
    if (is_array($decoded)) return $decoded;

    // Strip markdown code fences if present
    $stripped = preg_replace('/^```(?:json)?\s*/i', '', $trim);
    $stripped = preg_replace('/\s*```\s*$/', '', $stripped);
    $stripped = trim($stripped);
    if ($stripped !== $trim) {
        $decoded = json_decode($stripped, true);
        if (is_array($decoded)) return $decoded;
    }

    // Brace-balanced scan from first `{`
    $start = strpos($stripped, '{');
    if ($start === false) return null;
    $len = strlen($stripped);
    $depth = 0;
    $inString = false;
    $escape = false;
    for ($i = $start; $i < $len; $i++) {
        $c = $stripped[$i];
        if ($inString) {
            if ($escape) { $escape = false; continue; }
            if ($c === '\\') { $escape = true; continue; }
            if ($c === '"') { $inString = false; }
            continue;
        }
        if ($c === '"') { $inString = true; continue; }
        if ($c === '{') { $depth++; continue; }
        if ($c === '}') {
            $depth--;
            if ($depth === 0) {
                $candidate = substr($stripped, $start, $i - $start + 1);
                $decoded = json_decode($candidate, true);
                return is_array($decoded) ? $decoded : null;
            }
        }
    }
    return null;
}

/**
 * Obtiene un snapshot consolidado de Wasapi para el rango pedido.
 * Produce KPIs + agentes + tendencia diaria + percentiles SLA.
 */
function buildWasapiDataPack(string $startDate, string $endDate): array {
    $datesParam = 'dates%5B0%5D=' . $startDate . '&dates%5B1%5D=' . $endDate;
    $endpoints = [
        'online_agents' => 'dashboard/metrics/online-agents',
        'conversations' => 'dashboard/metrics/consolidated-conversations?' . $datesParam,
        'agent_conversations' => 'dashboard/metrics/agent-conversations?' . $datesParam,
        'performance' => 'reports/performance-by-agent?start_date=' . $startDate . '&end_date=' . $endDate,
        'workflow' => 'reports/volume-of-workflow?start_date=' . $startDate . '&end_date=' . $endDate,
        'users' => 'users',
        'campaigns' => 'campaigns',
    ];
    $r = wasapiMultiRequest($endpoints);

    $daysDiff = max(1, (int) (((strtotime($endDate) - strtotime($startDate)) / 86400) + 1));

    // --- Agentes online ---
    $totalAgents = 0; $onlineAgents = 0;
    foreach ($r['online_agents']['users'] ?? [] as $u) {
        $totalAgents++;
        if (!empty($u['online'])) $onlineAgents++;
    }

    // --- Conversaciones consolidadas ---
    $byStatus = ['open' => 0, 'closed' => 0, 'pending' => 0, 'hold' => 0];
    foreach ($r['conversations']['conversations']['data'] ?? [] as $c) {
        $s = $c['status'] ?? '';
        $n = (int) ($c['cant'] ?? 0);
        if (isset($byStatus[$s])) $byStatus[$s] += $n;
    }
    $totalConv = array_sum($byStatus);

    // --- Performance por agente (agrupado) ---
    $agentMap = [];
    $resolutionTimes = [];
    $firstRespTimes = [];
    foreach ($r['performance']['data'] ?? [] as $p) {
        $id = (int) ($p['agent_id'] ?? 0);
        if ($id <= 0) continue;
        if (!isset($agentMap[$id])) {
            $agentMap[$id] = [
                'id' => $id,
                'name' => $p['agent']['name'] ?? ('Agente #' . $id),
                'email' => $p['agent']['email'] ?? '',
                'opened' => 0, 'closed' => 0,
                'res_time' => 0, 'res_count' => 0,
                'first_resp_time' => 0, 'first_resp_count' => 0,
                'escalations' => 0, 'days_active' => 0,
            ];
        }
        $agentMap[$id]['opened'] += (int) ($p['total_open_conversations'] ?? 0);
        $agentMap[$id]['closed'] += (int) ($p['total_close_conversations'] ?? 0);
        $rt = (float) ($p['total_resolution_time'] ?? 0);
        $cc = (int) ($p['total_close_conversations'] ?? 0);
        if ($rt > 0 && $cc > 0) {
            $agentMap[$id]['res_time'] += $rt;
            $agentMap[$id]['res_count'] += $cc;
            $resolutionTimes[] = $rt / $cc;
        }
        $ft = (float) ($p['total_first_response_time'] ?? 0);
        $fc = (int) ($p['total_first_response_count'] ?? 0);
        if ($ft > 0 && $fc > 0) {
            $agentMap[$id]['first_resp_time'] += $ft;
            $agentMap[$id]['first_resp_count'] += $fc;
            $firstRespTimes[] = $ft / $fc;
        }
        $agentMap[$id]['escalations'] += (int) ($p['total_scaled_to_agents'] ?? 0);
        $agentMap[$id]['days_active']++;
    }

    $agents = [];
    foreach ($agentMap as $a) {
        $total = $a['opened'] + $a['closed'];
        $avgRes = $a['res_count'] > 0 ? $a['res_time'] / $a['res_count'] : 0;
        $avgFirst = $a['first_resp_count'] > 0 ? $a['first_resp_time'] / $a['first_resp_count'] : 0;
        $resRate = $total > 0 ? round(($a['closed'] / $total) * 100, 1) : 0;
        $productivity = round($a['closed'] / $daysDiff, 2);
        $agents[] = [
            'id' => $a['id'],
            'name' => $a['name'],
            'total_conversations' => $total,
            'closed' => $a['closed'],
            'resolution_rate' => $resRate,
            'avg_resolution_seconds' => round($avgRes),
            'avg_first_response_seconds' => round($avgFirst),
            'productivity_per_day' => $productivity,
            'escalations' => $a['escalations'],
            'days_active' => $a['days_active'],
        ];
    }
    usort($agents, fn($a, $b) => $b['productivity_per_day'] <=> $a['productivity_per_day']);

    // --- Tendencia diaria ---
    $byDate = [];
    foreach ($r['workflow']['data'] ?? [] as $w) {
        $date = substr($w['date'] ?? '', 0, 10);
        if (!$date) continue;
        if (!isset($byDate[$date])) {
            $byDate[$date] = [
                'date' => $date,
                'opened' => 0, 'closed' => 0, 'first_responses' => 0,
                'escalations' => 0, 'agents' => [],
            ];
        }
        $byDate[$date]['opened'] += (int) ($w['total_open_conversations'] ?? 0);
        $byDate[$date]['closed'] += (int) ($w['total_close_conversations'] ?? 0);
        $byDate[$date]['first_responses'] += (int) ($w['total_first_response_count'] ?? 0);
        $byDate[$date]['escalations'] += (int) ($w['total_scaled_to_agents'] ?? 0);
        $aid = (int) ($w['agent_id'] ?? 0);
        if ($aid > 0) $byDate[$date]['agents'][$aid] = true;
    }
    ksort($byDate);
    $daily = [];
    foreach ($byDate as $d) {
        $daily[] = [
            'date' => $d['date'],
            'weekday' => date('D', strtotime($d['date'])),
            'opened' => $d['opened'],
            'closed' => $d['closed'],
            'first_responses' => $d['first_responses'],
            'escalations' => $d['escalations'],
            'active_agents' => count($d['agents']),
        ];
    }

    // --- Distribución por día de la semana ---
    $weekdayTotals = [];
    $weekdayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    for ($i = 0; $i < 7; $i++) {
        $weekdayTotals[$i] = ['day' => $weekdayNames[$i], 'opened' => 0, 'closed' => 0, 'occurrences' => 0];
    }
    foreach ($daily as $d) {
        $w = (int) date('w', strtotime($d['date']));
        $weekdayTotals[$w]['opened'] += $d['opened'];
        $weekdayTotals[$w]['closed'] += $d['closed'];
        $weekdayTotals[$w]['occurrences']++;
    }
    $weekdayAvg = [];
    foreach ($weekdayTotals as $w) {
        $occ = max(1, $w['occurrences']);
        $weekdayAvg[] = [
            'day' => $w['day'],
            'avg_opened' => round($w['opened'] / $occ, 1),
            'avg_closed' => round($w['closed'] / $occ, 1),
            'occurrences' => $w['occurrences'],
        ];
    }

    // --- Campaigns ---
    $campaignsData = $r['campaigns']['data'] ?? [];
    $campaigns = [];
    foreach ($campaignsData as $c) {
        $campaigns[] = [
            'id' => (int) ($c['id'] ?? 0),
            'name' => $c['name'] ?? ('Campaña #' . ($c['id'] ?? '?')),
        ];
    }

    // --- KPIs globales ---
    $totalClosed = array_sum(array_column($agents, 'closed'));
    $totalAll = array_sum(array_column($agents, 'total_conversations'));
    $teamResolutionRate = $totalAll > 0 ? round(($totalClosed / $totalAll) * 100, 1) : 0;
    $avgRes = count($resolutionTimes) ? array_sum($resolutionTimes) / count($resolutionTimes) : 0;
    $avgFirst = count($firstRespTimes) ? array_sum($firstRespTimes) / count($firstRespTimes) : 0;

    return [
        'period' => [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days' => $daysDiff,
        ],
        'kpis' => [
            'total_conversations' => $totalConv,
            'conversations_by_status' => $byStatus,
            'team_resolution_rate' => $teamResolutionRate,
            'avg_resolution_seconds' => round($avgRes),
            'avg_resolution_formatted' => fmtSeconds($avgRes),
            'avg_first_response_seconds' => round($avgFirst),
            'avg_first_response_formatted' => fmtSeconds($avgFirst),
            'total_escalations' => array_sum(array_column($agents, 'escalations')),
        ],
        'sla_percentiles' => [
            'resolution_p50' => round(percentileList($resolutionTimes, 50)),
            'resolution_p90' => round(percentileList($resolutionTimes, 90)),
            'resolution_p95' => round(percentileList($resolutionTimes, 95)),
            'first_response_p50' => round(percentileList($firstRespTimes, 50)),
            'first_response_p90' => round(percentileList($firstRespTimes, 90)),
            'first_response_p95' => round(percentileList($firstRespTimes, 95)),
        ],
        'agents' => [
            'total' => $totalAgents,
            'online' => $onlineAgents,
            'offline' => $totalAgents - $onlineAgents,
            'availability_rate' => $totalAgents > 0 ? round(($onlineAgents / $totalAgents) * 100, 1) : 0,
            'count_with_activity' => count($agents),
            'top_performers' => array_slice($agents, 0, 5),
            'bottom_performers' => array_slice(array_reverse($agents), 0, 5),
            'all' => $agents,
        ],
        'trend' => [
            'daily' => $daily,
            'by_weekday' => $weekdayAvg,
        ],
        'campaigns' => $campaigns,
    ];
}

/**
 * Slim the Wasapi data pack before sending to Claude:
 *  - drop agents.all (can be 50+ agents × many fields)
 *  - cap trend.daily to last 30 entries
 *  - cap campaigns to first 30 items
 * Top/bottom performers and aggregate metrics are kept.
 */
function slimPackForClaude(array $pack): array {
    if (isset($pack['agents']['all'])) {
        unset($pack['agents']['all']);
    }
    if (isset($pack['trend']['daily']) && is_array($pack['trend']['daily'])) {
        $pack['trend']['daily'] = array_slice($pack['trend']['daily'], -30);
    }
    if (isset($pack['campaigns']) && is_array($pack['campaigns'])) {
        $pack['campaigns'] = array_slice($pack['campaigns'], 0, 30);
    }
    return $pack;
}

function callClaude(array $opts, PDO $pdo) {
    $opts['pdo'] = $pdo;
    if (empty($opts['model'])) {
        $opts['model'] = resolveAnthropicDefaultModel($pdo);
    }
    return callClaudeAPI($opts);
}

function renderClaudeError(array $res): array {
    return [
        'success' => false,
        'error' => $res['error'] ?? 'Error desconocido llamando a Claude',
        'http_code' => $res['http_code'] ?? 0,
    ];
}

// -------------------------------------------------------------
// Router
// -------------------------------------------------------------

try {
    $action = $_GET['action'] ?? 'executive_report';
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-14 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $agentId = (int) ($_GET['agent_id'] ?? 0);

    // Valida clave Claude antes de gastar ciclos en Wasapi
    $apiKey = resolveAnthropicApiKey($pdo);
    if ($apiKey === '') {
        echo json_encode([
            'success' => false,
            'error' => 'La clave de Anthropic (Claude) no está configurada. Ve a Ajustes → API de IA Global.',
            'needs_configuration' => true,
        ]);
        exit;
    }

    $model = resolveAnthropicDefaultModel($pdo);

    switch ($action) {

        // =========================================================
        // EXECUTIVE REPORT — Reporte ejecutivo integral
        // =========================================================
        case 'executive_report': {
            $pack = buildWasapiDataPack($startDate, $endDate);

            $system = "Eres Director de Operaciones senior especializado en contact centers omnicanal. "
                . "Redactas reportes ejecutivos accionables en español neutro para el CEO y COO. "
                . "Usas lenguaje directo, cita métricas exactas, identifica causas raíz y propone acciones con impacto estimado. "
                . "REGLAS DE FORMATO: responde SOLO con JSON puro (sin ```, sin texto extra). Sé CONCISO: máx 4 items por array, strings ≤200 caracteres. Prioriza insight sobre volumen.";

            $prompt = "Analiza el desempeño del contact center WhatsApp (Wasapi) entre {$startDate} y {$endDate}.\n\n"
                . "DATOS REALES EN JSON:\n" . json_encode(slimPackForClaude($pack), JSON_UNESCAPED_UNICODE) . "\n\n"
                . "Genera un reporte ejecutivo en JSON con esta estructura EXACTA:\n"
                . '{'
                . '"headline": "Titular de una línea con el mensaje clave del período",'
                . '"health_score": 0-100,'
                . '"health_label": "Crítico|Requiere atención|Saludable|Sobresaliente",'
                . '"executive_summary": "Resumen de 4-6 líneas con la lectura estratégica",'
                . '"key_findings": [{"icon": "trend-up|trend-down|alert|check", "title": "...", "detail": "...", "metric": "..."}],'
                . '"strengths": ["punto fuerte 1", "punto fuerte 2"],'
                . '"risks": [{"severity": "low|medium|high|critical", "title": "...", "detail": "...", "impact": "..."}],'
                . '"opportunities": [{"title": "...", "detail": "...", "estimated_impact": "..."}],'
                . '"kpi_snapshot": [{"label": "...", "value": "...", "trend": "up|down|flat", "target": "..."}],'
                . '"action_plan": [{"priority": 1, "action": "...", "owner": "Supervisor|Líder|RRHH|Operaciones", "timeframe": "24h|7d|30d", "expected_outcome": "..."}],'
                . '"board_message": "Mensaje breve (≤280 caracteres) listo para compartir con dirección"'
                . '}';

            $res = callClaude([
                'system_prompt' => $system,
                'user_prompt' => $prompt,
                'max_tokens' => 7000,
                'temperature' => 0.3,
                'timeout' => 90,
            ], $pdo);

            if (!$res['success']) { echo json_encode(renderClaudeError($res)); exit; }
            $report = extractJsonFromText($res['content']);

            echo json_encode([
                'success' => true,
                'action' => $action,
                'report' => $report,
                'raw_response' => $report ? null : $res['content'],
                'model' => $model,
                'usage' => $res['usage'] ?? null,
                'data_snapshot' => [
                    'kpis' => $pack['kpis'],
                    'agents_count' => $pack['agents']['count_with_activity'],
                    'period' => $pack['period'],
                ],
                'timestamp' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // =========================================================
        // OPERATIONS DIAGNOSIS — Diagnóstico operativo y plan 30/60/90
        // =========================================================
        case 'operations_diagnosis': {
            $pack = buildWasapiDataPack($startDate, $endDate);

            $system = "Eres consultor sénior de operaciones con foco en SLAs, productividad y experiencia del cliente en canales digitales. "
                . "Detectas cuellos de botella, quiebres de SLA, desperdicio de capacidad y patrones anómalos. "
                . "REGLAS: responde SOLO con JSON puro (sin ``` ni texto extra). Máx 4 items por array, strings ≤180 caracteres. Prioriza claridad.";

            $prompt = "A partir del siguiente snapshot real de Wasapi entre {$startDate} y {$endDate}, entrega un diagnóstico operativo:\n\n"
                . json_encode(slimPackForClaude($pack), JSON_UNESCAPED_UNICODE) . "\n\n"
                . "Devuelve JSON con esta estructura EXACTA:\n"
                . '{'
                . '"diagnosis_overview": "Lectura operativa de 3-5 líneas",'
                . '"bottlenecks": [{"area": "SLA|Asignación|Backlog|Capacidad|Calidad", "title": "...", "detail": "...", "evidence": "metric real"}],'
                . '"sla_breaches": [{"metric": "first_response|resolution", "level": "warning|critical", "detail": "...", "suggested_target": "..."}],'
                . '"capacity_analysis": {"current_capacity": "...", "utilization": "...", "gap": "...", "comment": "..."},'
                . '"anomalies": [{"title": "...", "detail": "...", "severity": "low|medium|high"}],'
                . '"plan_30_60_90": {'
                . '"next_24h": [{"action": "...", "owner": "..."}],'
                . '"next_30d": [{"action": "...", "owner": "...", "kpi_target": "..."}],'
                . '"next_60d": [{"action": "...", "owner": "...", "kpi_target": "..."}],'
                . '"next_90d": [{"action": "...", "owner": "...", "kpi_target": "..."}]'
                . '},'
                . '"quick_wins": [{"title": "...", "expected_impact": "...", "effort": "bajo|medio|alto"}]'
                . '}';

            $res = callClaude([
                'system_prompt' => $system,
                'user_prompt' => $prompt,
                'max_tokens' => 7000,
                'temperature' => 0.3,
                'timeout' => 90,
            ], $pdo);

            if (!$res['success']) { echo json_encode(renderClaudeError($res)); exit; }
            $diag = extractJsonFromText($res['content']);

            echo json_encode([
                'success' => true,
                'action' => $action,
                'diagnosis' => $diag,
                'raw_response' => $diag ? null : $res['content'],
                'model' => $model,
                'usage' => $res['usage'] ?? null,
                'period' => $pack['period'],
                'timestamp' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // =========================================================
        // RISK RADAR — Radar de riesgos
        // =========================================================
        case 'risk_radar': {
            $pack = buildWasapiDataPack($startDate, $endDate);

            $system = "Eres analista de riesgos operativos para un contact center. "
                . "Detectas señales tempranas de degradación: agentes agotados, campañas sin cobertura, "
                . "deuda de backlog, abandono de clientes y riesgos de SLA. "
                . "REGLAS: responde SOLO con JSON puro (sin ``` ni texto extra). Máx 6 riesgos, strings ≤180 caracteres.";

            $prompt = "Datos reales del período {$startDate} al {$endDate}:\n" . json_encode(slimPackForClaude($pack), JSON_UNESCAPED_UNICODE) . "\n\n"
                . "Genera un radar de riesgos en JSON con la estructura:\n"
                . '{'
                . '"overall_risk_level": "low|medium|high|critical",'
                . '"overall_score": 0-100,'
                . '"summary": "Resumen de una línea del riesgo agregado",'
                . '"risks": ['
                . '  {"category": "SLA|Cobertura|Fatiga|Backlog|Calidad|Campaña",'
                . '   "title": "Nombre corto del riesgo",'
                . '   "severity": "low|medium|high|critical",'
                . '   "likelihood": "baja|media|alta",'
                . '   "impact": "operativo|financiero|reputacional|cliente",'
                . '   "evidence": "Métrica o dato que lo sustenta",'
                . '   "recommendation": "Acción concreta para mitigar",'
                . '   "eta": "24h|7d|30d"}'
                . '],'
                . '"early_warnings": ["Señal temprana 1", "Señal temprana 2"]'
                . '}';

            $res = callClaude([
                'system_prompt' => $system,
                'user_prompt' => $prompt,
                'max_tokens' => 5000,
                'temperature' => 0.35,
                'timeout' => 90,
            ], $pdo);

            if (!$res['success']) { echo json_encode(renderClaudeError($res)); exit; }
            $radar = extractJsonFromText($res['content']);

            echo json_encode([
                'success' => true,
                'action' => $action,
                'radar' => $radar,
                'raw_response' => $radar ? null : $res['content'],
                'model' => $model,
                'usage' => $res['usage'] ?? null,
                'period' => $pack['period'],
                'timestamp' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // =========================================================
        // STAFFING FORECAST — Forecast y plan de staffing semanal
        // =========================================================
        case 'staffing_forecast': {
            $pack = buildWasapiDataPack($startDate, $endDate);

            $system = "Eres experto en workforce management para contact centers. "
                . "Con base en histórico real, construyes planes de staffing semanal realistas con supuestos claros. "
                . "REGLAS: responde SOLO con JSON puro (sin ``` ni texto extra). Exactamente 7 días en el forecast. Strings ≤150 caracteres.";

            $prompt = "Histórico real del período {$startDate} al {$endDate}:\n" . json_encode(slimPackForClaude($pack), JSON_UNESCAPED_UNICODE) . "\n\n"
                . "Propone un forecast y plan de staffing en JSON:\n"
                . '{'
                . '"assumptions": ["supuesto 1", "supuesto 2"],'
                . '"forecast_next_7_days": [{"date": "YYYY-MM-DD", "weekday": "Lun", "expected_conversations": 0, "confidence": "alta|media|baja", "suggested_agents": 0}],'
                . '"weekly_plan": [{"day": "Lunes", "shift": "AM|PM|Full", "agents": 0, "notes": "..."}],'
                . '"hiring_recommendation": {"needed": true|false, "count": 0, "profile": "...", "priority": "alta|media|baja", "reasoning": "..."},'
                . '"cost_vs_service_tradeoff": "Explicación breve del balance",'
                . '"watchlist": [{"condition": "...", "trigger": "métrica>=X", "action": "..."}]'
                . '}';

            $res = callClaude([
                'system_prompt' => $system,
                'user_prompt' => $prompt,
                'max_tokens' => 5500,
                'temperature' => 0.3,
                'timeout' => 90,
            ], $pdo);

            if (!$res['success']) { echo json_encode(renderClaudeError($res)); exit; }
            $forecast = extractJsonFromText($res['content']);

            echo json_encode([
                'success' => true,
                'action' => $action,
                'forecast' => $forecast,
                'raw_response' => $forecast ? null : $res['content'],
                'model' => $model,
                'usage' => $res['usage'] ?? null,
                'period' => $pack['period'],
                'timestamp' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // =========================================================
        // CAMPAIGN OPTIMIZER — Optimización y reasignación por campaña
        // =========================================================
        case 'campaign_optimizer': {
            $pack = buildWasapiDataPack($startDate, $endDate);

            $system = "Eres un analista de campañas omnicanal. Optimizas la mezcla de campañas y la asignación de agentes "
                . "en función de productividad, SLA y escalaciones. "
                . "REGLAS: responde SOLO con JSON puro (sin ``` ni texto extra). Máx 6 items por array, strings ≤180 caracteres.";

            $prompt = "Datos del período {$startDate} al {$endDate}:\n" . json_encode(slimPackForClaude($pack), JSON_UNESCAPED_UNICODE) . "\n\n"
                . "Sugiere optimizaciones de campañas en JSON:\n"
                . '{'
                . '"overview": "Lectura general de 2-3 líneas",'
                . '"campaign_insights": [{"campaign": "Nombre", "status": "saludable|en_riesgo|crítica", "insight": "...", "recommendation": "..."}],'
                . '"reassignments": [{"agent": "Nombre", "from": "Campaña actual (si aplica)", "to": "Campaña sugerida", "reason": "...", "expected_gain": "..."}],'
                . '"focus_campaigns": ["Campañas que requieren atención prioritaria"],'
                . '"kill_or_pivot": [{"campaign": "Nombre", "recommendation": "pausar|pivotar|reescalar", "reason": "..."}]'
                . '}';

            $res = callClaude([
                'system_prompt' => $system,
                'user_prompt' => $prompt,
                'max_tokens' => 5500,
                'temperature' => 0.35,
                'timeout' => 90,
            ], $pdo);

            if (!$res['success']) { echo json_encode(renderClaudeError($res)); exit; }
            $opt = extractJsonFromText($res['content']);

            echo json_encode([
                'success' => true,
                'action' => $action,
                'optimizer' => $opt,
                'raw_response' => $opt ? null : $res['content'],
                'model' => $model,
                'usage' => $res['usage'] ?? null,
                'period' => $pack['period'],
                'timestamp' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // =========================================================
        // AGENT COACHING — Plan individual de coaching
        // =========================================================
        case 'agent_coaching': {
            if ($agentId <= 0) {
                echo json_encode(['success' => false, 'error' => 'agent_id requerido']);
                exit;
            }

            $pack = buildWasapiDataPack($startDate, $endDate);

            // Localiza al agente dentro del pack
            $target = null;
            foreach ($pack['agents']['all'] as $a) {
                if ((int) $a['id'] === $agentId) { $target = $a; break; }
            }
            if (!$target) {
                echo json_encode(['success' => false, 'error' => 'Agente no tiene actividad en el período']);
                exit;
            }

            // Ranking del agente
            $all = $pack['agents']['all'];
            usort($all, fn($a, $b) => $b['productivity_per_day'] <=> $a['productivity_per_day']);
            $rank = 0;
            foreach ($all as $idx => $a) {
                if ((int) $a['id'] === $agentId) { $rank = $idx + 1; break; }
            }

            $teamAvg = [
                'productivity' => count($all) ? round(array_sum(array_column($all, 'productivity_per_day')) / count($all), 2) : 0,
                'resolution_rate' => count($all) ? round(array_sum(array_column($all, 'resolution_rate')) / count($all), 1) : 0,
                'avg_first_response' => count($all) ? round(array_sum(array_column($all, 'avg_first_response_seconds')) / count($all)) : 0,
                'avg_resolution' => count($all) ? round(array_sum(array_column($all, 'avg_resolution_seconds')) / count($all)) : 0,
            ];

            $system = "Eres coach profesional de agentes de atención al cliente. "
                . "Generas planes personalizados, empáticos y medibles con metas 30/60/90 días. "
                . "REGLAS: responde SOLO con JSON puro (sin ``` ni texto extra). Máx 3 items por bucket, strings ≤150 caracteres.";

            $prompt = "AGENTE: {$target['name']} (ID {$target['id']})\n"
                . "RANKING EQUIPO: {$rank} de " . count($all) . "\n"
                . "PERÍODO: {$startDate} a {$endDate} (" . $pack['period']['days'] . " días)\n\n"
                . "MÉTRICAS DEL AGENTE:\n" . json_encode($target, JSON_UNESCAPED_UNICODE) . "\n\n"
                . "PROMEDIOS DEL EQUIPO:\n" . json_encode($teamAvg, JSON_UNESCAPED_UNICODE) . "\n\n"
                . "Entrega en JSON exacto:\n"
                . '{'
                . '"performance_score": 0-100,'
                . '"performance_label": "Bajo|En desarrollo|Sólido|Estrella",'
                . '"narrative": "2-3 líneas de lectura del agente",'
                . '"strengths": ["..."],'
                . '"gaps": ["..."],'
                . '"coaching_plan": {'
                . '"30_days": [{"action": "...", "kpi": "...", "target": "..."}],'
                . '"60_days": [{"action": "...", "kpi": "...", "target": "..."}],'
                . '"90_days": [{"action": "...", "kpi": "...", "target": "..."}]'
                . '},'
                . '"recognition_message": "Frase motivacional concreta basada en sus fortalezas",'
                . '"risk_of_attrition": "bajo|medio|alto",'
                . '"suggested_next_conversation": "Pauta breve para 1:1 con supervisor"'
                . '}';

            $res = callClaude([
                'system_prompt' => $system,
                'user_prompt' => $prompt,
                'max_tokens' => 4000,
                'temperature' => 0.4,
                'timeout' => 90,
            ], $pdo);

            if (!$res['success']) { echo json_encode(renderClaudeError($res)); exit; }
            $coaching = extractJsonFromText($res['content']);

            echo json_encode([
                'success' => true,
                'action' => $action,
                'agent' => $target,
                'team_ranking' => ['position' => $rank, 'of' => count($all)],
                'team_averages' => $teamAvg,
                'coaching' => $coaching,
                'raw_response' => $coaching ? null : $res['content'],
                'model' => $model,
                'usage' => $res['usage'] ?? null,
                'period' => $pack['period'],
                'timestamp' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no soportada: ' . htmlspecialchars($action)]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
