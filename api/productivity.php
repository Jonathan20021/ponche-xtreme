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

if (!userHasPermission('productivity_dashboard')) {
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

function resolveScheduleHours(array $map, array $defaultConfig, int $userId, string $dateStr): float
{
    $totalHours = 0.0;

    if (isset($map[$userId])) {
        foreach ($map[$userId] as $sch) {
            $effDate = $sch['effective_date'] ?? '0000-00-00';
            $endDate = $sch['end_date'];
            if ($effDate <= $dateStr && ($endDate === null || $endDate >= $dateStr)) {
                $totalHours += (float) ($sch['scheduled_hours'] ?? 0);
            }
        }
    }

    if ($totalHours > 0) {
        return $totalHours;
    }

    return (float) ($defaultConfig['scheduled_hours'] ?? 8.0);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'summary';

if ($action === 'summary') {
    $defaultStart = date('Y-m-01');
    $defaultEnd = date('Y-m-t');
    $startDate = parseDate($_GET['start_date'] ?? $defaultStart, $defaultStart);
    $endDate = parseDate($_GET['end_date'] ?? $defaultEnd, $defaultEnd);
    if ($endDate < $startDate) {
        $endDate = $startDate;
    }

    $scope = $_GET['scope'] ?? 'campaign';
    if (!in_array($scope, ['campaign', 'team', 'user'], true)) {
        $scope = 'campaign';
    }

    $dateList = buildDateRange($startDate, $endDate);
    $startBound = $startDate . ' 00:00:00';
    $endBound = $endDate . ' 23:59:59';

    $campaigns = $pdo->query("SELECT id, name, code FROM campaigns ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $campaignMap = [];
    foreach ($campaigns as $c) {
        $campaignMap[(int) $c['id']] = $c;
    }

    $usersStmt = $pdo->query("
        SELECT u.id, u.full_name, u.username, e.campaign_id, e.supervisor_id
        FROM users u
        LEFT JOIN employees e ON e.user_id = u.id
        ORDER BY u.full_name
    ");
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $userIds = [];
    $userMap = [];
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $userIds[] = $uid;
        $userMap[$uid] = $u;
    }

    $supervisorMap = [];
    if (!empty($userIds)) {
        $supStmt = $pdo->prepare("SELECT id, full_name, username FROM users WHERE id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")");
        $supStmt->execute($userIds);
        foreach ($supStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $supervisorMap[(int) $row['id']] = $row;
        }
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

    $punchesMap = [];
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $punchParams = array_merge($userIds, [$startBound, $endBound]);
        $punchStmt = $pdo->prepare("
            SELECT user_id, timestamp, type
            FROM attendance
            WHERE user_id IN ($placeholders)
            AND timestamp BETWEEN ? AND ?
            ORDER BY timestamp ASC
        ");
        $punchStmt->execute($punchParams);
        foreach ($punchStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $uid = (int) $p['user_id'];
            $punchesMap[$uid][] = $p;
        }
    }

    $paidTypes = getPaidAttendanceTypeSlugs($pdo);
    $paidTypesUpper = array_map('strtoupper', $paidTypes);
    $nonWorkTypes = ['BREAK', 'LUNCH', 'PAUSA', 'EXIT'];

    $groups = [];
    $totals = [
        'scheduled_seconds' => 0,
        'real_net_seconds' => 0,
        'real_gross_seconds' => 0,
        'payroll_seconds' => 0
    ];

    foreach ($users as $user) {
        $userId = (int) $user['id'];
        $campaignId = $user['campaign_id'] !== null ? (int) $user['campaign_id'] : 0;
        $supervisorId = $user['supervisor_id'] !== null ? (int) $user['supervisor_id'] : 0;

        $scheduledSeconds = 0;
        foreach ($dateList as $dateStr) {
            $hours = resolveScheduleHours($userSchedulesMap, $globalScheduleConfig, $userId, $dateStr);
            $scheduledSeconds += ($hours * 3600);
        }

        $userPunches = $punchesMap[$userId] ?? [];
        $punchesByDate = [];
        foreach ($userPunches as $p) {
            $d = date('Y-m-d', strtotime($p['timestamp']));
            $punchesByDate[$d][] = $p;
        }

        $realGrossSeconds = 0;
        $realNetSeconds = 0;
        $payrollSeconds = 0;

        foreach ($punchesByDate as $d => $dayPunches) {
            if (!empty($dayPunches)) {
                $firstTime = strtotime($dayPunches[0]['timestamp']);
                $lastTime = strtotime($dayPunches[count($dayPunches) - 1]['timestamp']);
                $diff = $lastTime - $firstTime;
                if ($diff > 0 && $diff < 86400) {
                    $realGrossSeconds += $diff;
                }
            }

            $workStart = null;
            $isWorking = false;
            foreach ($dayPunches as $p) {
                $t = strtotime($p['timestamp']);
                $type = strtoupper($p['type']);
                if (in_array($type, $nonWorkTypes, true)) {
                    if ($isWorking && $workStart !== null) {
                        $realNetSeconds += ($t - $workStart);
                    }
                    $isWorking = false;
                    $workStart = null;
                } else {
                    if (!$isWorking) {
                        $workStart = $t;
                        $isWorking = true;
                    }
                }
            }

            $inPaidState = false;
            $paidStartTime = null;
            $lastPaidPunchTime = null;
            foreach ($dayPunches as $punch) {
                $punchTime = strtotime($punch['timestamp']);
                $punchType = strtoupper($punch['type']);
                $isPaid = in_array($punchType, $paidTypesUpper, true);

                if ($isPaid) {
                    $lastPaidPunchTime = $punchTime;
                    if (!$inPaidState) {
                        $paidStartTime = $punchTime;
                        $inPaidState = true;
                    }
                } elseif ($inPaidState) {
                    if ($paidStartTime !== null && $lastPaidPunchTime !== null) {
                        $payrollSeconds += ($lastPaidPunchTime - $paidStartTime);
                    }
                    $inPaidState = false;
                    $paidStartTime = null;
                    $lastPaidPunchTime = null;
                }
            }
            if ($inPaidState && $paidStartTime !== null && $lastPaidPunchTime !== null) {
                $payrollSeconds += ($lastPaidPunchTime - $paidStartTime);
            }
        }

        if ($scheduledSeconds === 0 && empty($userPunches)) {
            continue;
        }

        if ($scope === 'campaign') {
            $groupKey = 'campaign_' . $campaignId;
            $label = $campaignId > 0 ? ($campaignMap[$campaignId]['name'] ?? 'Sin campana') : 'Sin campana';
            $meta = [
                'campaign_id' => $campaignId,
                'campaign_code' => $campaignId > 0 ? ($campaignMap[$campaignId]['code'] ?? '') : ''
            ];
        } elseif ($scope === 'team') {
            $groupKey = 'team_' . $supervisorId;
            $label = $supervisorId > 0 ? ($supervisorMap[$supervisorId]['full_name'] ?? 'Equipo') : 'Sin supervisor';
            $meta = [
                'supervisor_id' => $supervisorId
            ];
        } else {
            $groupKey = 'user_' . $userId;
            $label = $user['full_name'] ?? $user['username'] ?? ('User ' . $userId);
            $meta = [
                'user_id' => $userId,
                'username' => $user['username'] ?? ''
            ];
        }

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = array_merge([
                'label' => $label,
                'scheduled_seconds' => 0,
                'real_net_seconds' => 0,
                'real_gross_seconds' => 0,
                'payroll_seconds' => 0,
                'user_count' => 0
            ], $meta);
        }

        $groups[$groupKey]['scheduled_seconds'] += $scheduledSeconds;
        $groups[$groupKey]['real_net_seconds'] += $realNetSeconds;
        $groups[$groupKey]['real_gross_seconds'] += $realGrossSeconds;
        $groups[$groupKey]['payroll_seconds'] += $payrollSeconds;
        $groups[$groupKey]['user_count'] += 1;

        $totals['scheduled_seconds'] += $scheduledSeconds;
        $totals['real_net_seconds'] += $realNetSeconds;
        $totals['real_gross_seconds'] += $realGrossSeconds;
        $totals['payroll_seconds'] += $payrollSeconds;
    }

    $salesByCampaign = [];
    if ($scope === 'campaign') {
        $salesStmt = $pdo->prepare("
            SELECT campaign_id,
                   SUM(sales_amount) AS sales_amount,
                   SUM(revenue_amount) AS revenue_amount,
                   SUM(volume) AS volume
            FROM campaign_sales_reports
            WHERE report_date BETWEEN ? AND ?
            GROUP BY campaign_id
        ");
        $salesStmt->execute([$startDate, $endDate]);
        foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $salesByCampaign[(int) $row['campaign_id']] = [
                'sales_amount' => (float) $row['sales_amount'],
                'revenue_amount' => (float) $row['revenue_amount'],
                'volume' => (int) $row['volume']
            ];
        }
    }

    $rows = [];
    foreach ($groups as $groupKey => $g) {
        $scheduled = $g['scheduled_seconds'];
        $realNet = $g['real_net_seconds'];
        $realGross = $g['real_gross_seconds'];
        $payroll = $g['payroll_seconds'];

        $adherence = $scheduled > 0 ? round(($realNet / $scheduled) * 100, 1) : 0.0;
        $attendance = $scheduled > 0 ? round(($realGross / $scheduled) * 100, 1) : 0.0;

        $row = [
            'label' => $g['label'],
            'scheduled_hours' => round($scheduled / 3600, 2),
            'real_net_hours' => round($realNet / 3600, 2),
            'real_gross_hours' => round($realGross / 3600, 2),
            'payroll_hours' => round($payroll / 3600, 2),
            'adherence_percent' => $adherence,
            'attendance_percent' => $attendance,
            'user_count' => $g['user_count']
        ];

        if ($scope === 'campaign') {
            $campaignId = (int) ($g['campaign_id'] ?? 0);
            $sales = $salesByCampaign[$campaignId] ?? ['sales_amount' => 0, 'revenue_amount' => 0, 'volume' => 0];
            $row['campaign_id'] = $campaignId;
            $row['campaign_code'] = $g['campaign_code'] ?? '';
            $row['sales_amount'] = $sales['sales_amount'];
            $row['revenue_amount'] = $sales['revenue_amount'];
            $row['volume'] = $sales['volume'];
        } elseif ($scope === 'team') {
            $row['supervisor_id'] = (int) ($g['supervisor_id'] ?? 0);
        } else {
            $row['user_id'] = (int) ($g['user_id'] ?? 0);
            $row['username'] = $g['username'] ?? '';
        }

        $rows[] = $row;
    }

    jsonResponse([
        'success' => true,
        'summary' => [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'scope' => $scope
        ],
        'totals' => [
            'scheduled_hours' => round($totals['scheduled_seconds'] / 3600, 2),
            'real_net_hours' => round($totals['real_net_seconds'] / 3600, 2),
            'real_gross_hours' => round($totals['real_gross_seconds'] / 3600, 2),
            'payroll_hours' => round($totals['payroll_seconds'] / 3600, 2),
            'adherence_percent' => $totals['scheduled_seconds'] > 0 ? round(($totals['real_net_seconds'] / $totals['scheduled_seconds']) * 100, 1) : 0.0,
            'attendance_percent' => $totals['scheduled_seconds'] > 0 ? round(($totals['real_gross_seconds'] / $totals['scheduled_seconds']) * 100, 1) : 0.0
        ],
        'rows' => $rows
    ]);
}

if ($action === 'goals') {
    $defaultStart = date('Y-m-01');
    $defaultEnd = date('Y-m-t');
    $startDate = parseDate($_GET['start_date'] ?? $defaultStart, $defaultStart);
    $endDate = parseDate($_GET['end_date'] ?? $defaultEnd, $defaultEnd);
    if ($endDate < $startDate) {
        $endDate = $startDate;
    }

    $scope = $_GET['scope'] ?? null;
    $params = [$startDate, $endDate];
    $where = "NOT (end_date < ? OR start_date > ?)";
    if ($scope && in_array($scope, ['campaign', 'team', 'user'], true)) {
        $where .= " AND scope_type = ?";
        $params[] = $scope;
    }

    $stmt = $pdo->prepare("SELECT * FROM kpi_goals WHERE $where ORDER BY start_date DESC");
    $stmt->execute($params);
    jsonResponse(['success' => true, 'goals' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

if ($action === 'set_goal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $scope = $_POST['scope_type'] ?? 'campaign';
    if (!in_array($scope, ['campaign', 'team', 'user'], true)) {
        jsonResponse(['success' => false, 'error' => 'Scope invalido'], 400);
    }

    $startDate = parseDate($_POST['start_date'] ?? '', date('Y-m-01'));
    $endDate = parseDate($_POST['end_date'] ?? '', date('Y-m-t'));
    if ($endDate < $startDate) {
        $endDate = $startDate;
    }

    $kpiKey = trim((string) ($_POST['kpi_key'] ?? ''));
    $targetValue = (float) ($_POST['target_value'] ?? 0);
    $targetDirection = $_POST['target_direction'] ?? 'target';
    if (!in_array($targetDirection, ['min', 'max', 'target'], true)) {
        $targetDirection = 'target';
    }

    if ($kpiKey === '') {
        jsonResponse(['success' => false, 'error' => 'KPI requerido'], 400);
    }

    $campaignId = isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : null;
    $supervisorId = isset($_POST['supervisor_id']) ? (int) $_POST['supervisor_id'] : null;
    $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : null;
    $notes = trim((string) ($_POST['notes'] ?? ''));

    $stmt = $pdo->prepare("
        INSERT INTO kpi_goals
        (scope_type, campaign_id, supervisor_id, user_id, kpi_key, target_value, target_direction, start_date, end_date, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $scope,
        $campaignId ?: null,
        $supervisorId ?: null,
        $userId ?: null,
        $kpiKey,
        $targetValue,
        $targetDirection,
        $startDate,
        $endDate,
        $notes ?: null,
        $_SESSION['user_id']
    ]);

    jsonResponse(['success' => true, 'goal_id' => (int) $pdo->lastInsertId()]);
}

if ($action === 'coaching') {
    $params = [];
    $where = "1=1";
    if (!empty($_GET['user_id'])) {
        $where .= " AND user_id = ?";
        $params[] = (int) $_GET['user_id'];
    }
    if (!empty($_GET['campaign_id'])) {
        $where .= " AND campaign_id = ?";
        $params[] = (int) $_GET['campaign_id'];
    }
    if (!empty($_GET['supervisor_id'])) {
        $where .= " AND supervisor_id = ?";
        $params[] = (int) $_GET['supervisor_id'];
    }

    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name AS user_name, s.full_name AS supervisor_name
        FROM coaching_sessions c
        LEFT JOIN users u ON u.id = c.user_id
        LEFT JOIN users s ON s.id = c.supervisor_id
        WHERE $where
        ORDER BY c.session_date DESC, c.created_at DESC
    ");
    $stmt->execute($params);
    jsonResponse(['success' => true, 'sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

if ($action === 'add_coaching' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $sessionDate = parseDate($_POST['session_date'] ?? date('Y-m-d'), date('Y-m-d'));
    $topic = trim((string) ($_POST['topic'] ?? ''));
    if ($userId <= 0 || $topic === '') {
        jsonResponse(['success' => false, 'error' => 'Datos incompletos'], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO coaching_sessions
        (user_id, supervisor_id, campaign_id, session_date, topic, notes, action_items, score, next_review_date, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        isset($_POST['supervisor_id']) ? (int) $_POST['supervisor_id'] : null,
        isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : null,
        $sessionDate,
        $topic,
        trim((string) ($_POST['notes'] ?? '')) ?: null,
        trim((string) ($_POST['action_items'] ?? '')) ?: null,
        $_POST['score'] !== '' ? (float) $_POST['score'] : null,
        !empty($_POST['next_review_date']) ? parseDate($_POST['next_review_date'], date('Y-m-d')) : null,
        $_POST['status'] ?? 'open',
        $_SESSION['user_id']
    ]);

    jsonResponse(['success' => true, 'session_id' => (int) $pdo->lastInsertId()]);
}

if ($action === 'award_points' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $points = (int) ($_POST['points'] ?? 0);
    if ($userId <= 0 || $points === 0) {
        jsonResponse(['success' => false, 'error' => 'Datos incompletos'], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO gamification_points
        (user_id, campaign_id, points, reason, awarded_by, awarded_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $userId,
        isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : null,
        $points,
        trim((string) ($_POST['reason'] ?? '')) ?: null,
        $_SESSION['user_id']
    ]);

    jsonResponse(['success' => true]);
}

if ($action === 'leaderboard') {
    $defaultStart = date('Y-m-01');
    $defaultEnd = date('Y-m-t');
    $startDate = parseDate($_GET['start_date'] ?? $defaultStart, $defaultStart);
    $endDate = parseDate($_GET['end_date'] ?? $defaultEnd, $defaultEnd);
    if ($endDate < $startDate) {
        $endDate = $startDate;
    }

    $stmt = $pdo->prepare("
        SELECT g.user_id, u.full_name, u.username, SUM(g.points) AS points
        FROM gamification_points g
        INNER JOIN users u ON u.id = g.user_id
        WHERE g.awarded_at BETWEEN ? AND ?
        GROUP BY g.user_id
        ORDER BY points DESC
        LIMIT 50
    ");
    $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
    jsonResponse(['success' => true, 'leaders' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

jsonResponse(['success' => false, 'error' => 'Accion invalida'], 400);
