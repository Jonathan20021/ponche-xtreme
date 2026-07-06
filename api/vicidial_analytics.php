<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';
require_once __DIR__ . '/../lib/vicidial_report_adapter.php';

header('Content-Type: application/json');

// Check permission
if (!isset($_SESSION['user_id']) || !userHasPermission('vicidial_reports')) {
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para realizar esta acción']);
    exit;
}

$action = $_GET['action'] ?? '';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$campaign = $_GET['campaign'] ?? '';

// Keep original range dates for available_dates query
$rangeStartDate = $startDate;
$rangeEndDate = $endDate;

// Daily date override: when a specific day is selected, restrict range to that single day
$dailyDate = $_GET['daily_date'] ?? '';
if ($dailyDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyDate)) {
    $startDate = $dailyDate;
    $endDate = $dailyDate;
}

// ¿La fuente activa (sync o csv) trae códigos de disposición (conversiones)?
$hasDispositions = vicidialReportsHasDispositions($pdo);

try {
    switch ($action) {
        case 'kpis':
            echo json_encode(getKPIs($pdo, $startDate, $endDate, $campaign, $hasDispositions));
            break;

        case 'top_agents':
            echo json_encode(getTopAgents($pdo, $startDate, $endDate, $campaign, $hasDispositions));
            break;

        case 'time_distribution':
            echo json_encode(getTimeDistribution($pdo, $startDate, $endDate, $campaign));
            break;

        case 'disposition_breakdown':
            echo json_encode(getDispositionBreakdown($pdo, $startDate, $endDate, $campaign));
            break;

        case 'trends':
            echo json_encode(getTrends($pdo, $startDate, $endDate, $campaign));
            break;

        case 'alerts':
            echo json_encode(getAlerts($pdo, $startDate, $endDate, $campaign, $hasDispositions));
            break;

        case 'campaigns':
            echo json_encode(getCampaigns($pdo, $startDate, $endDate));
            break;

        case 'available_dates':
            echo json_encode(getAvailableDates($pdo, $rangeStartDate, $rangeEndDate, $campaign));
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Suma los totales de las filas por-agente del adaptador (fuente activa).
 */
function vaAggregate(array $rows): array
{
    $t = ['calls' => 0, 'time' => 0, 'talk' => 0, 'dispo' => 0, 'dead' => 0,
          'pause' => 0, 'wait' => 0, 'conversions' => 0, 'no_contact' => 0, 'agents' => 0];
    foreach ($rows as $r) {
        $t['calls']       += (int) ($r['total_calls'] ?? 0);
        $t['time']        += (int) ($r['time_total'] ?? 0);
        $t['talk']        += (int) ($r['talk_time'] ?? 0);
        $t['dispo']       += (int) ($r['dispo_time'] ?? 0);
        $t['dead']        += (int) ($r['dead_time'] ?? 0);
        $t['pause']       += (int) ($r['pause_time'] ?? 0);
        $t['wait']        += (int) ($r['wait_time'] ?? 0);
        $t['conversions'] += (int) ($r['sale'] ?? 0) + (int) ($r['pedido'] ?? 0) + (int) ($r['orden'] ?? 0);
        $t['no_contact']  += (int) ($r['no_contact'] ?? 0);
        $t['agents']++;
    }
    return $t;
}

/**
 * Get Executive KPIs
 */
function getKPIs($pdo, $startDate, $endDate, $campaign = '', $hasDispositions = true)
{
    $t = vaAggregate(vicidialReportsAgentStats($pdo, $startDate, $endDate, $campaign));

    $conversionRate = $t['calls'] > 0 ? round(($t['conversions'] / $t['calls']) * 100, 2) : 0;
    $aht = $t['calls'] > 0 ? round(($t['talk'] + $t['dispo']) / $t['calls'], 0) : 0;
    $occupancy = $t['time'] > 0 ? round((($t['talk'] + $t['dispo'] + $t['dead']) / $t['time']) * 100, 2) : 0;
    $productivity = $t['time'] > 0 ? round($t['calls'] / ($t['time'] / 3600), 2) : 0;
    $contactRate = $t['calls'] > 0 ? round((($t['calls'] - $t['no_contact']) / $t['calls']) * 100, 2) : 0;
    $efficiency = $t['time'] > 0 ? round(($t['talk'] / $t['time']) * 100, 2) : 0;

    // En modo sync no hay disposiciones → conversión y contacto no aplican.
    $convValue    = $hasDispositions ? $conversionRate : '—';
    $contactValue = $hasDispositions ? $contactRate : '—';

    return [
        'success' => true,
        'dispositions_available' => (bool) $hasDispositions,
        'kpis' => [
            'conversion_rate' => ['value' => $convValue, 'label' => 'Tasa de Conversión', 'unit' => $hasDispositions ? '%' : '', 'icon' => 'fa-chart-line', 'color' => 'cyan'],
            'aht' => ['value' => gmdate('i:s', (int) $aht), 'label' => 'AHT Promedio', 'unit' => 'min', 'icon' => 'fa-clock', 'color' => 'blue'],
            'occupancy' => ['value' => $occupancy, 'label' => 'Ocupación', 'unit' => '%', 'icon' => 'fa-percentage', 'color' => 'green'],
            'productivity' => ['value' => $productivity, 'label' => 'Llamadas/Hora', 'unit' => 'llamadas', 'icon' => 'fa-phone', 'color' => 'purple'],
            'contact_rate' => ['value' => $contactValue, 'label' => 'Tasa de Contacto', 'unit' => $hasDispositions ? '%' : '', 'icon' => 'fa-user-check', 'color' => 'indigo'],
            'efficiency' => ['value' => $efficiency, 'label' => 'Eficiencia', 'unit' => '%', 'icon' => 'fa-tachometer-alt', 'color' => 'pink'],
        ],
        'totals' => [
            'calls' => $t['calls'],
            'conversions' => $t['conversions'],
            'agents' => $t['agents'],
        ],
    ];
}

/**
 * Get Top Agents. Con disposiciones → por conversiones; sin ellas (sync) → por llamadas.
 */
function getTopAgents($pdo, $startDate, $endDate, $campaign = '', $hasDispositions = true, $limit = 10)
{
    $rows = vicidialReportsAgentStats($pdo, $startDate, $endDate, $campaign);
    $agents = [];
    foreach ($rows as $r) {
        $calls = (int) ($r['total_calls'] ?? 0);
        if ($calls <= 0) { continue; }
        $conv = (int) ($r['sale'] ?? 0) + (int) ($r['pedido'] ?? 0) + (int) ($r['orden'] ?? 0);
        $agents[] = [
            'name' => $r['user_name'],
            'calls' => $calls,
            'conversions' => $conv,
            'conversion_rate' => $calls > 0 ? round(($conv / $calls) * 100, 2) : 0,
        ];
    }
    // Ordenar por conversiones (si hay) o por llamadas (sync)
    usort($agents, static fn($a, $b) => $hasDispositions
        ? ($b['conversions'] <=> $a['conversions'])
        : ($b['calls'] <=> $a['calls']));

    return [
        'success' => true,
        'metric' => $hasDispositions ? 'conversions' : 'calls',
        'agents' => array_slice($agents, 0, $limit),
    ];
}

/**
 * Get Time Distribution
 */
function getTimeDistribution($pdo, $startDate, $endDate, $campaign = '')
{
    $t = vaAggregate(vicidialReportsAgentStats($pdo, $startDate, $endDate, $campaign));
    return [
        'success' => true,
        'distribution' => [
            'Talk' => $t['talk'],
            'Pause' => $t['pause'],
            'Wait' => $t['wait'],
            'Dispo' => $t['dispo'],
            'Dead' => $t['dead'],
        ],
    ];
}

/**
 * Get Disposition Breakdown. Requiere códigos de disposición → solo fuente CSV.
 * En modo sync devuelve vacío + available:false (el sync aún no trae disposiciones).
 */
function getDispositionBreakdown($pdo, $startDate, $endDate, $campaign = '')
{
    if (!vicidialReportsHasDispositions($pdo)) {
        return ['success' => true, 'available' => false, 'dispositions' => []];
    }

    // Modo sync: totales por código desde status_breakdown (agregado en PHP).
    if (vicidialReportsUsingSync($pdo)) {
        $totals = vicidialReportsDispositionTotals($pdo, $startDate, $endDate, $campaign);
        arsort($totals);
        return ['success' => true, 'available' => true, 'dispositions' => array_map('intval', $totals)];
    }

    $campaignFilter = $campaign ? "AND current_user_group = :campaign" : "";
    $stmt = $pdo->prepare("
        SELECT
            SUM(sale) as SALE, SUM(pedido) as PEDIDO, SUM(orden) as ORDEN,
            SUM(active) as ACTIVE, SUM(callbk) as CALLBACK, SUM(cortad) as CORTADO,
            SUM(deposi) as DEPOSITO, SUM(duplic) as DUPLICADO, SUM(n) as NO_INTERESADO,
            SUM(nocal) as NO_CONTACTO, SUM(nocon) as NO_CONTESTA, SUM(notie) as NO_TIENE_DINERO,
            SUM(numeq) as NUMERO_EQUIVOCADO, SUM(promo) as PROMO, SUM(pu) as PU,
            SUM(colgo) as COLGO, SUM(silenc) as SILENCIO, SUM(wasapi) as WASAPI
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        $campaignFilter
    ");
    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    if ($campaign) { $params['campaign'] = $campaign; }
    $stmt->execute($params);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'success' => true,
        'available' => true,
        'dispositions' => array_map('intval', $data),
    ];
}

/**
 * Get Trends by Date (source-aware via adapter)
 */
function getTrends($pdo, $startDate, $endDate, $campaign = '')
{
    $trends = vicidialReportsTrendsByDate($pdo, $startDate, $endDate, $campaign);
    return [
        'success' => true,
        'trends' => array_map(static function ($trend) {
            return [
                'date' => $trend['date'],
                'calls' => (int) $trend['calls'],
                'conversions' => (int) $trend['conversions'],
            ];
        }, $trends),
    ];
}

/**
 * Get Alerts and Recommendations (source-aware; conversión solo si hay disposiciones)
 */
function getAlerts($pdo, $startDate, $endDate, $campaign = '', $hasDispositions = true)
{
    $rows = vicidialReportsAgentStats($pdo, $startDate, $endDate, $campaign);
    $alerts = [];

    // Baja conversión — solo si la fuente trae disposiciones (CSV).
    if ($hasDispositions) {
        $lowConversion = [];
        foreach ($rows as $r) {
            $calls = (int) ($r['total_calls'] ?? 0);
            if ($calls < 50) { continue; }
            $conv = (int) ($r['sale'] ?? 0) + (int) ($r['pedido'] ?? 0) + (int) ($r['orden'] ?? 0);
            $rate = $calls > 0 ? ($conv / $calls) * 100 : 0;
            if ($rate < 5) { $lowConversion[] = ['name' => $r['user_name'], 'rate' => $rate]; }
        }
        usort($lowConversion, static fn($a, $b) => $a['rate'] <=> $b['rate']);
        $lowConversion = array_slice($lowConversion, 0, 5);
        if ($lowConversion) {
            $alerts[] = [
                'type' => 'warning', 'icon' => 'fa-exclamation-triangle',
                'title' => 'Baja Tasa de Conversión',
                'message' => count($lowConversion) . ' agentes con conversión menor a 5%',
                'agents' => array_column($lowConversion, 'name'),
            ];
        }
    }

    // Pausa elevada (>30% del tiempo logueado)
    $highPause = [];
    foreach ($rows as $r) {
        $time = (int) ($r['time_total'] ?? 0);
        if ($time <= 0) { continue; }
        $pausePct = ((int) ($r['pause_time'] ?? 0) / $time) * 100;
        if ($pausePct > 30) { $highPause[] = ['name' => $r['user_name'], 'pct' => $pausePct]; }
    }
    usort($highPause, static fn($a, $b) => $b['pct'] <=> $a['pct']);
    $highPause = array_slice($highPause, 0, 5);
    if ($highPause) {
        $alerts[] = [
            'type' => 'info', 'icon' => 'fa-pause-circle',
            'title' => 'Tiempo de Pausa Elevado',
            'message' => count($highPause) . ' agentes con más de 30% en pausa',
            'agents' => array_column($highPause, 'name'),
        ];
    }

    // Bajo volumen (>= 8h logueado, < 50 llamadas)
    $lowVolume = [];
    foreach ($rows as $r) {
        $hours = (int) ($r['time_total'] ?? 0) / 3600;
        $calls = (int) ($r['total_calls'] ?? 0);
        if ($hours >= 8 && $calls < 50) { $lowVolume[] = ['name' => $r['user_name'], 'calls' => $calls]; }
    }
    usort($lowVolume, static fn($a, $b) => $a['calls'] <=> $b['calls']);
    $lowVolume = array_slice($lowVolume, 0, 5);
    if ($lowVolume) {
        $alerts[] = [
            'type' => 'danger', 'icon' => 'fa-phone-slash',
            'title' => 'Bajo Volumen de Llamadas',
            'message' => count($lowVolume) . ' agentes con menos de 50 llamadas en 8+ horas',
            'agents' => array_column($lowVolume, 'name'),
        ];
    }

    return ['success' => true, 'alerts' => $alerts];
}

/**
 * Get Available Campaigns (source-aware via adapter)
 */
function getCampaigns($pdo, $startDate, $endDate)
{
    return [
        'success' => true,
        'campaigns' => vicidialReportsCampaigns($pdo, $startDate, $endDate),
    ];
}

/**
 * Get Available Dates (source-aware)
 */
function getAvailableDates($pdo, $startDate, $endDate, $campaign = '')
{
    try {
        if (vicidialReportsUsingSync($pdo)) {
            $filter = $campaign ? "AND user_group = :campaign" : "";
            $stmt = $pdo->prepare("
                SELECT DISTINCT report_date AS d
                FROM vicidial_agent_timesheet
                WHERE report_date BETWEEN :start_date AND :end_date
                $filter
                ORDER BY report_date DESC
            ");
        } else {
            $filter = $campaign ? "AND current_user_group = :campaign" : "";
            $stmt = $pdo->prepare("
                SELECT DISTINCT upload_date AS d
                FROM vicidial_login_stats
                WHERE upload_date BETWEEN :start_date AND :end_date
                $filter
                ORDER BY upload_date DESC
            ");
        }
        $params = ['start_date' => $startDate, 'end_date' => $endDate];
        if ($campaign) { $params['campaign'] = $campaign; }
        $stmt->execute($params);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $dates = [];
    }

    return ['success' => true, 'dates' => $dates];
}
