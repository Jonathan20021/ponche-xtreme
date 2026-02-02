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

if ($action === 'staffing_gap') {
    $defaultStart = date('Y-m-01');
    $defaultEnd = date('Y-m-t');
    $startDate = parseDate($_GET['start_date'] ?? $defaultStart, $defaultStart);
    $endDate = parseDate($_GET['end_date'] ?? $defaultEnd, $defaultEnd);
    if ($endDate < $startDate) {
        $endDate = $startDate;
    }
    $startBound = $startDate . ' 00:00:00';
    $endBound = $endDate . ' 23:59:59';
    $dateList = buildDateRange($startDate, $endDate);

    $campaigns = $pdo->query("SELECT id, name, code FROM campaigns ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $campaignMap = [];
    foreach ($campaigns as $c) {
        $campaignMap[(int) $c['id']] = $c;
    }

    $forecastStmt = $pdo->prepare("
        SELECT *
        FROM campaign_staffing_forecast
        WHERE interval_start BETWEEN ? AND ?
        ORDER BY campaign_id, interval_start
    ");
    $forecastStmt->execute([$startBound, $endBound]);
    $forecastRows = $forecastStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $requiredByCampaignDate = [];
    foreach ($forecastRows as $row) {
        $campaignId = (int) $row['campaign_id'];
        $dateKey = date('Y-m-d', strtotime($row['interval_start']));
        $calc = calcStaffing($row);
        $intervalMinutes = max(1, (int) ($row['interval_minutes'] ?? 30));
        $staffHours = $calc['required_staff'] * ($intervalMinutes / 60);

        if (!isset($requiredByCampaignDate[$campaignId])) {
            $requiredByCampaignDate[$campaignId] = [];
        }
        if (!isset($requiredByCampaignDate[$campaignId][$dateKey])) {
            $requiredByCampaignDate[$campaignId][$dateKey] = 0.0;
        }
        $requiredByCampaignDate[$campaignId][$dateKey] += $staffHours;
    }

    $usersStmt = $pdo->query("
        SELECT u.id, e.campaign_id
        FROM users u
        LEFT JOIN employees e ON e.user_id = u.id
        WHERE e.campaign_id IS NOT NULL
    ");
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $userIds = [];
    $userCampaignMap = [];
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $userIds[] = $uid;
        $userCampaignMap[$uid] = (int) $u['campaign_id'];
    }

    $globalScheduleConfig = getScheduleConfig($pdo);
    $userSchedulesMap = [];
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $schedStmt = $pdo->prepare("
            SELECT * FROM employee_schedules
            WHERE user_id IN ($placeholders)
            AND is_active = 1
            ORDER BY effective_date DESC
        ");
        $schedStmt->execute($userIds);
        foreach ($schedStmt->fetchAll(PDO::FETCH_ASSOC) as $sch) {
            $uid = (int) $sch['user_id'];
            $userSchedulesMap[$uid][] = $sch;
        }
    }

    $scheduledByCampaignDate = [];
    foreach ($userIds as $uid) {
        $campaignId = $userCampaignMap[$uid] ?? 0;
        if ($campaignId <= 0) {
            continue;
        }
        foreach ($dateList as $dateStr) {
            $hours = resolveScheduleHours($userSchedulesMap, $globalScheduleConfig, $uid, $dateStr);
            if (!isset($scheduledByCampaignDate[$campaignId])) {
                $scheduledByCampaignDate[$campaignId] = [];
            }
            if (!isset($scheduledByCampaignDate[$campaignId][$dateStr])) {
                $scheduledByCampaignDate[$campaignId][$dateStr] = 0.0;
            }
            $scheduledByCampaignDate[$campaignId][$dateStr] += $hours;
        }
    }

    $dailyRows = [];
    $campaignTotals = [];
    foreach ($campaignMap as $campaignId => $campaign) {
        foreach ($dateList as $dateStr) {
            $required = $requiredByCampaignDate[$campaignId][$dateStr] ?? 0.0;
            $scheduled = $scheduledByCampaignDate[$campaignId][$dateStr] ?? 0.0;
            if ($required == 0 && $scheduled == 0) {
                continue;
            }
            $gap = $scheduled - $required;
            $coverage = $required > 0 ? round(($scheduled / $required) * 100, 1) : 0.0;

            $dailyRows[] = [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaign['name'],
                'campaign_code' => $campaign['code'],
                'date' => $dateStr,
                'required_hours' => round($required, 2),
                'scheduled_hours' => round($scheduled, 2),
                'gap_hours' => round($gap, 2),
                'coverage_percent' => $coverage
            ];

            if (!isset($campaignTotals[$campaignId])) {
                $campaignTotals[$campaignId] = [
                    'campaign_id' => $campaignId,
                    'campaign_name' => $campaign['name'],
                    'campaign_code' => $campaign['code'],
                    'required_hours' => 0.0,
                    'scheduled_hours' => 0.0
                ];
            }
            $campaignTotals[$campaignId]['required_hours'] += $required;
            $campaignTotals[$campaignId]['scheduled_hours'] += $scheduled;
        }
    }

    $totals = array_values(array_map(function ($row) {
        $gap = $row['scheduled_hours'] - $row['required_hours'];
        $coverage = $row['required_hours'] > 0 ? round(($row['scheduled_hours'] / $row['required_hours']) * 100, 1) : 0.0;
        $row['required_hours'] = round($row['required_hours'], 2);
        $row['scheduled_hours'] = round($row['scheduled_hours'], 2);
        $row['gap_hours'] = round($gap, 2);
        $row['coverage_percent'] = $coverage;
        return $row;
    }, $campaignTotals));

    jsonResponse([
        'success' => true,
        'summary' => [
            'start_date' => $startDate,
            'end_date' => $endDate
        ],
        'totals' => $totals,
        'daily' => $dailyRows
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

jsonResponse(['success' => false, 'error' => 'Accion invalida'], 400);
