<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

if (!userHasPermission('wfm_planning')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tiene permisos']);
    exit;
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function parseDate(string $value, string $default): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
    }
    return $default;
}

function buildDateRange(string $startDate, string $endDate): array
{
    $dates = [];
    $period = new DatePeriod(
        new DateTime($startDate),
        new DateInterval('P1D'),
        (new DateTime($endDate))->modify('+1 day')
    );
    foreach ($period as $dt) {
        $dates[] = $dt->format('Y-m-d');
    }
    return $dates;
}

function erlangC(float $a, int $n): float
{
    if ($a <= 0 || $n <= 0) {
        return 0.0;
    }
    if ($n <= $a) {
        return 1.0;
    }

    $sum = 1.0;
    $term = 1.0;
    for ($k = 1; $k <= $n - 1; $k++) {
        $term *= $a / $k;
        $sum += $term;
    }
    $term *= $a / $n;
    $numer = $term * ($n / ($n - $a));
    $denom = $sum + $numer;
    if ($denom == 0.0) {
        return 1.0;
    }
    return $numer / $denom;
}

function calcStaffing(array $row): array
{
    $intervalMinutes = max(1, (int) ($row['interval_minutes'] ?? 30));
    $intervalSeconds = $intervalMinutes * 60;
    $offered = (int) ($row['offered_volume'] ?? 0);
    $ahtSeconds = (int) ($row['aht_seconds'] ?? 0);
    $targetSl = (float) ($row['target_sl'] ?? 0.8);
    $targetAns = (int) ($row['target_answer_seconds'] ?? 20);
    $occupancyTarget = (float) ($row['occupancy_target'] ?? 0.85);
    $shrinkage = (float) ($row['shrinkage'] ?? 0.3);

    if ($targetSl > 1) {
        $targetSl = $targetSl / 100;
    }
    if ($occupancyTarget > 1) {
        $occupancyTarget = $occupancyTarget / 100;
    }
    if ($shrinkage > 1) {
        $shrinkage = $shrinkage / 100;
    }

    $workload = 0.0;
    if ($intervalSeconds > 0 && $ahtSeconds > 0 && $offered > 0) {
        $workload = ($offered * $ahtSeconds) / $intervalSeconds;
    }

    $requiredAgents = 0;
    $serviceLevel = 1.0;
    $occupancy = 0.0;

    if ($workload > 0 && $ahtSeconds > 0) {
        $n = (int) ceil($workload);
        if ($n <= $workload) {
            $n = (int) floor($workload) + 1;
        }
        if ($occupancyTarget > 0) {
            $minOcc = (int) ceil($workload / $occupancyTarget);
            if ($minOcc > $n) {
                $n = $minOcc;
            }
        }

        $maxIterations = 200;
        $serviceLevel = 0.0;
        for ($i = 0; $i <= $maxIterations; $i++) {
            if ($n <= $workload) {
                $n++;
                continue;
            }
            $ec = erlangC($workload, $n);
            $serviceLevel = 1 - $ec * exp(-($n - $workload) * ($targetAns / $ahtSeconds));
            if ($serviceLevel >= $targetSl) {
                break;
            }
            $n++;
        }
        $requiredAgents = $n;
        $occupancy = $requiredAgents > 0 ? ($workload / $requiredAgents) : 0.0;
    }

    $requiredStaff = $requiredAgents;
    if ($shrinkage > 0 && $shrinkage < 1 && $requiredAgents > 0) {
        $requiredStaff = (int) ceil($requiredAgents / (1 - $shrinkage));
    }

    return [
        'required_staff' => $requiredStaff,
        'service_level' => $serviceLevel,
        'occupancy' => $occupancy
    ];
}

function resolveScheduleHours(array $map, array $defaultConfig, int $userId, string $dateStr): float
{
    if (isset($map[$userId])) {
        foreach ($map[$userId] as $sch) {
            $effDate = $sch['effective_date'] ?? '0000-00-00';
            $endDate = $sch['end_date'];
            if ($effDate <= $dateStr) {
                if ($endDate === null || $endDate >= $dateStr) {
                    return (float) ($sch['scheduled_hours'] ?? 0);
                }
            }
        }
    }
    return (float) ($defaultConfig['scheduled_hours'] ?? 8.0);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'staffing_gap';

if ($action === 'staffing_gap' || $action === 'inbound_metrics') {
    $defaultStart = date('Y-m-01');
    $defaultEnd = date('Y-m-t');
    $startDate = parseDate($_GET['start_date'] ?? $defaultStart, $defaultStart);
    $endDate = parseDate($_GET['end_date'] ?? $defaultEnd, $defaultEnd);
    if ($endDate < $startDate) {
        $endDate = $startDate;
    }
    $startBound = $startDate . ' 00:00:00';
    $endBound = $endDate . ' 23:59:59';

    $campaignsStmt = $pdo->query("SELECT id, name, code FROM campaigns ORDER BY name");
    $campaigns = $campaignsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $campaignMap = [];
    foreach ($campaigns as $c) {
        $campaignMap[(int) $c['id']] = $c;
    }

    $stmt = $pdo->prepare("
        SELECT 
            campaign_id,
            DATE(interval_start) as date_string,
            SUM(offered_calls) as sum_offered,
            SUM(answered_calls) as sum_answered,
            SUM(abandoned_calls) as sum_abandoned,
            SUM(total_talk_sec) as sum_talk_sec,
            SUM(total_wrap_sec) as sum_wrap_sec,
            SUM(total_call_sec) as sum_call_sec,
            AVG(avg_answer_speed_sec) as g_avg_answer_speed,
            AVG(avg_abandon_time_sec) as g_avg_abandon_time
        FROM vicidial_inbound_hourly
        WHERE interval_start BETWEEN ? AND ?
        GROUP BY campaign_id, DATE(interval_start)
        ORDER BY campaign_id, DATE(interval_start)
    ");
    $stmt->execute([$startBound, $endBound]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dailyRows = [];
    $campaignTotals = [];

    foreach ($rows as $row) {
        $campaignId = (int) $row['campaign_id'];
        $campaign = $campaignMap[$campaignId] ?? ['name' => 'Unknown', 'code' => 'N/A'];

        $offered = (int) $row['sum_offered'];
        $answered = (int) $row['sum_answered'];
        $abandoned = (int) $row['sum_abandoned'];

        $abandonPercent = $offered > 0 ? round(($abandoned / $offered) * 100, 2) : 0.0;
        $answerPercent = $offered > 0 ? round(($answered / $offered) * 100, 2) : 0.0;

        $avgTalk = $answered > 0 ? round($row['sum_talk_sec'] / $answered) : 0;
        $avgWrap = $answered > 0 ? round($row['sum_wrap_sec'] / $answered) : 0;

        $dailyRows[] = [
            'campaign_id' => $campaignId,
            'campaign_name' => $campaign['name'],
            'campaign_code' => $campaign['code'],
            'date' => $row['date_string'],
            'offered' => $offered,
            'answered' => $answered,
            'abandoned' => $abandoned,
            'abandon_percent' => $abandonPercent,
            'answer_percent' => $answerPercent,
            'avg_answer_speed' => round((float) $row['g_avg_answer_speed']),
            'avg_talk_time' => $avgTalk,
            'avg_wrap_time' => $avgWrap
        ];

        if (!isset($campaignTotals[$campaignId])) {
            $campaignTotals[$campaignId] = [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaign['name'],
                'campaign_code' => $campaign['code'],
                'offered' => 0,
                'answered' => 0,
                'abandoned' => 0,
                'sum_talk_sec' => 0,
                'sum_wrap_sec' => 0,
                'asa_sum' => 0,
                'intervals' => 0
            ];
        }
        $campaignTotals[$campaignId]['offered'] += $offered;
        $campaignTotals[$campaignId]['answered'] += $answered;
        $campaignTotals[$campaignId]['abandoned'] += $abandoned;
        $campaignTotals[$campaignId]['sum_talk_sec'] += (int) $row['sum_talk_sec'];
        $campaignTotals[$campaignId]['sum_wrap_sec'] += (int) $row['sum_wrap_sec'];
        $campaignTotals[$campaignId]['asa_sum'] += (float) $row['g_avg_answer_speed'];
        $campaignTotals[$campaignId]['intervals']++;
    }

    $totals = [];
    foreach ($campaignTotals as $ct) {
        $offered = $ct['offered'];
        $answered = $ct['answered'];
        $abandoned = $ct['abandoned'];
        $abandonPercent = $offered > 0 ? round(($abandoned / $offered) * 100, 2) : 0.0;
        $answerPercent = $offered > 0 ? round(($answered / $offered) * 100, 2) : 0.0;
        $avgTalk = $answered > 0 ? round($ct['sum_talk_sec'] / $answered) : 0;
        $avgWrap = $answered > 0 ? round($ct['sum_wrap_sec'] / $answered) : 0;
        $avgAsa = $ct['intervals'] > 0 ? round($ct['asa_sum'] / $ct['intervals']) : 0;

        $totals[] = [
            'campaign_id' => $ct['campaign_id'],
            'campaign_name' => $ct['campaign_name'],
            'campaign_code' => $ct['campaign_code'],
            'offered' => $offered,
            'answered' => $answered,
            'abandoned' => $abandoned,
            'abandon_percent' => $abandonPercent,
            'answer_percent' => $answerPercent,
            'avg_answer_speed' => $avgAsa,
            'avg_talk_time' => $avgTalk,
            'avg_wrap_time' => $avgWrap
        ];
    }

    $intradayStmt = $pdo->prepare("
        SELECT 
            campaign_id,
            HOUR(interval_start) as hour_of_day,
            AVG(offered_calls) as avg_offered,
            AVG(answered_calls) as avg_answered,
            AVG(abandoned_calls) as avg_abandoned,
            AVG(avg_answer_speed_sec) as avg_asa
        FROM vicidial_inbound_hourly
        WHERE interval_start BETWEEN ? AND ?
        GROUP BY campaign_id, HOUR(interval_start)
        ORDER BY campaign_id, HOUR(interval_start)
    ");
    $intradayStmt->execute([$startBound, $endBound]);
    $intradayRows = $intradayStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $intraday = [];
    foreach ($intradayRows as $iRow) {
        $cId = (int) $iRow['campaign_id'];
        if (!isset($intraday[$cId])) {
            $intraday[$cId] = [];
        }
        $intraday[$cId][] = [
            'hour' => (int) $iRow['hour_of_day'],
            'avg_offered' => round((float) $iRow['avg_offered'], 1),
            'avg_answered' => round((float) $iRow['avg_answered'], 1),
            'avg_abandoned' => round((float) $iRow['avg_abandoned'], 1),
            'avg_asa' => round((float) $iRow['avg_asa'], 1)
        ];
    }

    jsonResponse([
        'success' => true,
        'summary' => [
            'start_date' => $startDate,
            'end_date' => $endDate
        ],
        'totals' => $totals,
        'daily' => $dailyRows,
        'intraday' => $intraday
    ]);
}

if ($action === 'generate_alerts' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $alertDate = parseDate($_POST['alert_date'] ?? date('Y-m-d'), date('Y-m-d'));
    $toleranceMinutes = (int) ($_POST['tolerance_minutes'] ?? 15);
    if ($toleranceMinutes < 0) {
        $toleranceMinutes = 0;
    }

    $usersStmt = $pdo->query("
        SELECT u.id, e.campaign_id
        FROM users u
        LEFT JOIN employees e ON e.user_id = u.id
    ");
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $startBound = $alertDate . ' 00:00:00';
    $endBound = $alertDate . ' 23:59:59';

    $punchStmt = $pdo->prepare("
        SELECT user_id, MIN(timestamp) AS first_punch, MAX(timestamp) AS last_punch
        FROM attendance
        WHERE timestamp BETWEEN ? AND ?
        GROUP BY user_id
    ");
    $punchStmt->execute([$startBound, $endBound]);
    $punchMap = [];
    foreach ($punchStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $punchMap[(int) $row['user_id']] = $row;
    }

    $insertStmt = $pdo->prepare("
        INSERT IGNORE INTO wfm_adherence_alerts
        (user_id, campaign_id, alert_date, alert_type, expected_time, actual_time, severity, message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $created = 0;
    foreach ($users as $user) {
        $userId = (int) $user['id'];
        $campaignId = $user['campaign_id'] !== null ? (int) $user['campaign_id'] : null;
        $schedule = getScheduleConfigForUser($pdo, $userId, $alertDate);
        $entryTime = $schedule['entry_time'] ?? null;
        $exitTime = $schedule['exit_time'] ?? null;
        if ($entryTime === null && $exitTime === null) {
            continue;
        }

        $punchRow = $punchMap[$userId] ?? null;
        if ($punchRow === null || $punchRow['first_punch'] === null) {
            $insertStmt->execute([
                $userId,
                $campaignId,
                $alertDate,
                'absent',
                $entryTime,
                null,
                'high',
                'Sin registros de asistencia'
            ]);
            if ($insertStmt->rowCount() > 0) {
                $created++;
            }
            continue;
        }

        $firstPunch = $punchRow['first_punch'];
        $lastPunch = $punchRow['last_punch'];

        if ($entryTime) {
            $expectedEntry = strtotime($alertDate . ' ' . $entryTime);
            $actualEntry = strtotime($firstPunch);
            if ($expectedEntry !== false && $actualEntry !== false) {
                if ($actualEntry > ($expectedEntry + ($toleranceMinutes * 60))) {
                    $insertStmt->execute([
                        $userId,
                        $campaignId,
                        $alertDate,
                        'late',
                        $entryTime,
                        date('H:i:s', $actualEntry),
                        'medium',
                        'Entrada tardia'
                    ]);
                    if ($insertStmt->rowCount() > 0) {
                        $created++;
                    }
                }
            }
        }

        if ($exitTime) {
            $expectedExit = strtotime($alertDate . ' ' . $exitTime);
            $actualExit = strtotime($lastPunch);
            if ($expectedExit !== false && $actualExit !== false) {
                if ($actualExit < ($expectedExit - ($toleranceMinutes * 60))) {
                    $insertStmt->execute([
                        $userId,
                        $campaignId,
                        $alertDate,
                        'early_exit',
                        $exitTime,
                        date('H:i:s', $actualExit),
                        'medium',
                        'Salida temprana'
                    ]);
                    if ($insertStmt->rowCount() > 0) {
                        $created++;
                    }
                }
            }
        }
    }

    jsonResponse(['success' => true, 'created' => $created]);
}

if ($action === 'alerts') {
    $defaultStart = date('Y-m-01');
    $defaultEnd = date('Y-m-t');
    $startDate = parseDate($_GET['start_date'] ?? $defaultStart, $defaultStart);
    $endDate = parseDate($_GET['end_date'] ?? $defaultEnd, $defaultEnd);
    if ($endDate < $startDate) {
        $endDate = $startDate;
    }

    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name AS user_name, c.name AS campaign_name
        FROM wfm_adherence_alerts a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN campaigns c ON c.id = a.campaign_id
        WHERE a.alert_date BETWEEN ? AND ?
        ORDER BY a.alert_date DESC, a.created_at DESC
        LIMIT 500
    ");
    $stmt->execute([$startDate, $endDate]);
    jsonResponse(['success' => true, 'alerts' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

if ($action === 'delete_inbound' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!userHasPermission('manage_campaigns')) {
        jsonResponse(['success' => false, 'error' => 'No tiene permisos para eliminar datos']);
    }

    $campaignId = isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : 0;
    $startDate = parseDate($_POST['start_date'] ?? '', '');
    $endDate = parseDate($_POST['end_date'] ?? '', '');

    if ($campaignId <= 0 || !$startDate || !$endDate) {
        jsonResponse(['success' => false, 'error' => 'Debe seleccionar campaña y rango de fechas']);
    }
    if ($endDate < $startDate) {
        jsonResponse(['success' => false, 'error' => 'La fecha de fin no puede ser menor a la inicial']);
    }

    $startBound = $startDate . ' 00:00:00';
    $endBound = $endDate . ' 23:59:59';

    $stmt = $pdo->prepare("DELETE FROM vicidial_inbound_hourly WHERE campaign_id = ? AND interval_start BETWEEN ? AND ?");
    $stmt->execute([$campaignId, $startBound, $endBound]);
    $deleted = $stmt->rowCount();

    jsonResponse(['success' => true, 'deleted' => $deleted, 'message' => "Se eliminaron $deleted registros exitosamente."]);
}

jsonResponse(['success' => false, 'error' => 'Accion invalida'], 400);
