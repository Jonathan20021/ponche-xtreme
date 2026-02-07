<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

header('Content-Type: application/json');

// Check permission
if (!isset($_SESSION['user_id']) || !userHasPermission('vicidial_reports')) {
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para realizar esta acción']);
    exit;
}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$campaign = $_GET['campaign'] ?? '';

try {
    $rankings = calculateRankings($pdo, $startDate, $endDate, $campaign);

    echo json_encode([
        'success' => true,
        'rankings' => $rankings
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Calculate comprehensive agent rankings
 */
function calculateRankings($pdo, $startDate, $endDate, $campaign = '')
{
    $campaignFilter = $campaign ? "AND current_user_group = :campaign" : "";

    $stmt = $pdo->prepare("
        SELECT 
            user_name,
            user_id,
            current_user_group,
            SUM(calls) as total_calls,
            SUM(time_total) as time_total,
            SUM(talk_time) as talk_time,
            SUM(dispo_time) as dispo_time,
            SUM(dead_time) as dead_time,
            SUM(sale + pedido + orden) as conversions,
            SUM(nocal + silenc) as no_contact
        FROM vicidial_login_stats
        WHERE upload_date BETWEEN :start_date AND :end_date
        $campaignFilter
        GROUP BY user_name, user_id, current_user_group
        HAVING total_calls >= 10
    ");

    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    if ($campaign) {
        $params['campaign'] = $campaign;
    }

    $stmt->execute($params);
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($agents)) {
        return [];
    }

    // Calculate metrics for each agent
    $agentMetrics = [];
    foreach ($agents as $agent) {
        $calls = (int) $agent['total_calls'];
        $time = (int) $agent['time_total'];
        $talk = (int) $agent['talk_time'];
        $dispo = (int) $agent['dispo_time'];
        $dead = (int) $agent['dead_time'];
        $conversions = (int) $agent['conversions'];
        $noContact = (int) $agent['no_contact'];

        $conversionRate = $calls > 0 ? ($conversions / $calls) * 100 : 0;
        $occupancy = $time > 0 ? (($talk + $dispo + $dead) / $time) * 100 : 0;
        $efficiency = $time > 0 ? ($talk / $time) * 100 : 0;
        $productivity = $time > 0 ? $calls / ($time / 3600) : 0;
        $contactRate = $calls > 0 ? (($calls - $noContact) / $calls) * 100 : 0;

        $agentMetrics[] = [
            'name' => $agent['user_name'],
            'user_id' => $agent['user_id'],
            'campaign' => $agent['current_user_group'],
            'calls' => $calls,
            'conversions' => $conversions,
            'conversion_rate' => round($conversionRate, 2),
            'occupancy' => round($occupancy, 2),
            'efficiency' => round($efficiency, 2),
            'productivity' => round($productivity, 2),
            'contact_rate' => round($contactRate, 2)
        ];
    }

    // Normalize metrics to 0-100 scale
    $normalized = normalizeMetrics($agentMetrics);

    // Calculate composite scores
    $weights = [
        'conversion_rate' => 0.30,  // 30%
        'productivity' => 0.25,      // 25%
        'occupancy' => 0.20,         // 20%
        'efficiency' => 0.15,        // 15%
        'contact_rate' => 0.10       // 10%
    ];

    foreach ($normalized as &$agent) {
        $score = 0;
        foreach ($weights as $metric => $weight) {
            $score += ($agent['normalized'][$metric] ?? 0) * $weight;
        }
        $agent['score'] = round($score, 2);
    }

    // Sort by score descending
    usort($normalized, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    // Add rank and medal
    foreach ($normalized as $index => &$agent) {
        $agent['rank'] = $index + 1;
        if ($index === 0) {
            $agent['medal'] = 'gold';
        } elseif ($index === 1) {
            $agent['medal'] = 'silver';
        } elseif ($index === 2) {
            $agent['medal'] = 'bronze';
        } else {
            $agent['medal'] = null;
        }
    }

    return $normalized;
}

/**
 * Normalize metrics to 0-100 scale
 */
function normalizeMetrics($agents)
{
    $metrics = ['conversion_rate', 'occupancy', 'efficiency', 'productivity', 'contact_rate'];

    // Find min and max for each metric
    $ranges = [];
    foreach ($metrics as $metric) {
        $values = array_column($agents, $metric);
        $ranges[$metric] = [
            'min' => min($values),
            'max' => max($values)
        ];
    }

    // Normalize each agent's metrics
    foreach ($agents as &$agent) {
        $agent['normalized'] = [];
        foreach ($metrics as $metric) {
            $value = $agent[$metric];
            $min = $ranges[$metric]['min'];
            $max = $ranges[$metric]['max'];

            if ($max - $min > 0) {
                $normalized = (($value - $min) / ($max - $min)) * 100;
            } else {
                $normalized = 100;
            }

            $agent['normalized'][$metric] = round($normalized, 2);
        }
    }

    return $agents;
}
?>