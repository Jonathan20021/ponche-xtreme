<?php
/**
 * lib/monitor_history.php
 *
 * Histórico de disposiciones para el Monitor en Tiempo Real.
 *
 * El monitor mostraba solo el estado actual de cada agente y el tiempo en ese
 * estado; al cambiar de disposición se perdía lo anterior. Aquí se reconstruye
 * la jornada completa desde el ponche (que ya es el registro histórico real):
 * la línea de tiempo de estados y el tiempo total en cada uno.
 *
 * Las duraciones salen de computeStateSegments() (lib/work_hours_calculator.php),
 * las MISMAS reglas que usa la nómina, para que el histórico no pueda decir una
 * cosa y el pago otra.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/work_hours_calculator.php';

if (!function_exists('monitorHistoryFormatDuration')) {
    function monitorHistoryFormatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($h > 0) {
            return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
        }
        $s = $seconds % 60;
        return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
    }
}

if (!function_exists('monitorHistoryBuild')) {
    /**
     * @return array{
     *   employee:array<string,mixed>, date:string,
     *   timeline:array<int,array<string,mixed>>,
     *   by_state:array<int,array<string,mixed>>,
     *   totals:array<string,mixed>,
     *   punches:array<int,array<string,mixed>>
     * }
     */
    function monitorHistoryBuild(PDO $pdo, int $userId, string $date): array
    {
        $paidTypes = normalizePaidTypeSlugs(getPaidAttendanceTypeSlugs($pdo));

        // Catálogo de tipos: etiqueta, icono y colores tal como los ve el monitor.
        $typesMap = [];
        foreach (getAttendanceTypes($pdo, false) as $type) {
            $slug = sanitizeAttendanceTypeSlug($type['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $typesMap[$slug] = [
                'slug'        => $slug,
                'label'       => $type['label'] ?? $slug,
                'icon'        => $type['icon_class'] ?? 'fas fa-circle',
                'color_start' => $type['color_start'] ?? '#6B7280',
                'color_end'   => $type['color_end'] ?? '#4B5563',
                'is_paid'     => (int) ($type['is_paid'] ?? 0) === 1,
            ];
        }
        $typeInfo = static function (string $slug) use ($typesMap, $paidTypes): array {
            return $typesMap[$slug] ?? [
                'slug'        => $slug,
                'label'       => $slug,
                'icon'        => 'fas fa-circle',
                'color_start' => '#6B7280',
                'color_end'   => '#4B5563',
                'is_paid'     => in_array($slug, $paidTypes, true),
            ];
        };

        // Datos del empleado (mismo criterio que el monitor)
        $empStmt = $pdo->prepare("
            SELECT u.id AS user_id, u.username, u.full_name,
                   e.first_name, e.last_name, e.photo_path, e.position,
                   d.name AS department_name,
                   c.name AS campaign_name, c.color AS campaign_color
            FROM users u
            LEFT JOIN employees e ON e.user_id = u.id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN campaigns c ON c.id = e.campaign_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $empStmt->execute([$userId]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $punchStmt = $pdo->prepare("
            SELECT id, type, timestamp
            FROM attendance
            WHERE user_id = ? AND DATE(timestamp) = ?
            ORDER BY timestamp ASC, id ASC
        ");
        $punchStmt->execute([$userId, $date]);
        $punches = $punchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Solo se extiende el estado actual "hasta ahora" si la fecha es hoy.
        $isToday = ($date === date('Y-m-d'));
        $nowTs = $isToday ? time() : null;

        $segments = computeStateSegments($punches, $paidTypes, $nowTs);

        $timeline = [];
        $byState  = [];
        $paidSeconds = 0;
        $unpaidSeconds = 0;

        foreach ($segments as $seg) {
            $info = $typeInfo($seg['slug']);
            $timeline[] = [
                'slug'              => $seg['slug'],
                'label'             => $info['label'],
                'icon'              => $info['icon'],
                'color'             => $info['color_start'],
                'is_paid'           => $seg['is_paid'],
                'is_open'           => $seg['is_open'],
                'start'             => date('H:i:s', $seg['start']),
                'end'               => $seg['is_open'] ? null : date('H:i:s', $seg['end']),
                'seconds'           => $seg['seconds'],
                'duration_formatted' => monitorHistoryFormatDuration($seg['seconds']),
            ];

            if (!isset($byState[$seg['slug']])) {
                $byState[$seg['slug']] = [
                    'slug'     => $seg['slug'],
                    'label'    => $info['label'],
                    'icon'     => $info['icon'],
                    'color'    => $info['color_start'],
                    'is_paid'  => $seg['is_paid'],
                    'seconds'  => 0,
                    'episodes' => 0,
                ];
            }
            $byState[$seg['slug']]['seconds'] += $seg['seconds'];
            $byState[$seg['slug']]['episodes']++;

            if ($seg['is_paid']) {
                $paidSeconds += $seg['seconds'];
            } else {
                $unpaidSeconds += $seg['seconds'];
            }
        }

        // Más tiempo primero: es lo que se quiere leer de un vistazo.
        $byState = array_values($byState);
        usort($byState, static fn($a, $b) => $b['seconds'] <=> $a['seconds']);

        $totalSeconds = $paidSeconds + $unpaidSeconds;
        foreach ($byState as &$state) {
            $state['duration_formatted'] = monitorHistoryFormatDuration($state['seconds']);
            $state['pct'] = $totalSeconds > 0 ? round($state['seconds'] * 100 / $totalSeconds, 1) : 0.0;
        }
        unset($state);

        $firstPunch = $punches[0]['timestamp'] ?? null;
        $lastPunch  = $punches ? $punches[count($punches) - 1]['timestamp'] : null;

        return [
            'employee' => [
                'user_id'    => $userId,
                'full_name'  => $emp['full_name'] ?: trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')),
                'position'   => $emp['position'] ?? 'Sin cargo',
                'department' => $emp['department_name'] ?? 'Sin departamento',
                'campaign'   => $emp['campaign_name'] ?? 'Sin campaña',
                'campaign_color' => $emp['campaign_color'] ?? '#6B7280',
                'photo_path' => $emp['photo_path'] ?? null,
            ],
            'date'     => $date,
            'is_today' => $isToday,
            'timeline' => $timeline,
            'by_state' => $byState,
            'totals'   => [
                'paid_seconds'        => $paidSeconds,
                'paid_formatted'      => monitorHistoryFormatDuration($paidSeconds),
                'paid_hours'          => round($paidSeconds / 3600, 2),
                'unpaid_seconds'      => $unpaidSeconds,
                'unpaid_formatted'    => monitorHistoryFormatDuration($unpaidSeconds),
                'total_seconds'       => $totalSeconds,
                'total_formatted'     => monitorHistoryFormatDuration($totalSeconds),
                'punches'             => count($punches),
                'state_changes'       => count($timeline),
                'first_punch'         => $firstPunch ? date('H:i:s', strtotime($firstPunch)) : null,
                'last_punch'          => $lastPunch ? date('H:i:s', strtotime($lastPunch)) : null,
            ],
            'punches' => array_map(static function (array $p) use ($typeInfo) {
                $slug = sanitizeAttendanceTypeSlug($p['type'] ?? '');
                $info = $typeInfo($slug);
                return [
                    'id'    => (int) $p['id'],
                    'slug'  => $slug,
                    'label' => $info['label'],
                    'icon'  => $info['icon'],
                    'color' => $info['color_start'],
                    'time'  => date('H:i:s', strtotime((string) $p['timestamp'])),
                ];
            }, $punches),
        ];
    }
}

if (!function_exists('monitorHistoryRespond')) {
    /** Responde el JSON del histórico. Espera user_id y date por GET. */
    function monitorHistoryRespond(PDO $pdo): void
    {
        $userId = (int) ($_GET['user_id'] ?? 0);
        $date   = (string) ($_GET['date'] ?? date('Y-m-d'));

        if ($userId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'user_id requerido']);
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        if ($date > date('Y-m-d')) {
            $date = date('Y-m-d');
        }

        try {
            $data = monitorHistoryBuild($pdo, $userId, $date);
            echo json_encode(array_merge(['success' => true], $data));
        } catch (Throwable $e) {
            error_log('monitorHistoryRespond: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'No se pudo construir el histórico']);
        }
    }
}
