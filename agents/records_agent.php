<?php
session_start();
if (!isset($_SESSION['employee_id'])) {
    header('Location: login_agent.php');
    exit;
}

include '../db.php';

if (!function_exists('sanitizeHexColorValue')) {
    function sanitizeHexColorValue(?string $color, string $fallback = '#6366F1'): string
    {
        $value = strtoupper(trim((string) $color));
        return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : strtoupper($fallback);
    }
}

$employee_id = (int) $_SESSION['employee_id'];
$username = $_SESSION['username'] ?? '';

$attendanceTypes = getAttendanceTypes($pdo, false);
$attendanceTypeMap = [];
foreach ($attendanceTypes as $typeRow) {
    $slug = sanitizeAttendanceTypeSlug($typeRow['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $typeRow['slug'] = $slug;
    $attendanceTypeMap[$slug] = $typeRow;
}

$records_query = 'SELECT id, type, DATE(timestamp) AS record_date, TIME(timestamp) AS record_time
    FROM attendance
    WHERE user_id = ?
    ORDER BY timestamp DESC';

$stmt = $pdo->prepare($records_query);
$stmt->execute([$employee_id]);
$rawRecords = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$records = [];
foreach ($rawRecords as $row) {
    $slug = sanitizeAttendanceTypeSlug($row['type'] ?? '');
    if ($slug === '') {
        continue;
    }
    $meta = $attendanceTypeMap[$slug] ?? null;
    $label = $meta['label'] ?? ($row['type'] ?? $slug);
    $icon = $meta['icon_class'] ?? 'fas fa-circle';
    $colorStart = sanitizeHexColorValue($meta['color_start'] ?? '#6366F1', '#6366F1');
    $colorEnd = sanitizeHexColorValue($meta['color_end'] ?? $colorStart, $colorStart);
    $badgeStyle = sprintf(
        'background: linear-gradient(135deg, %1$s 0%%, %2$s 100%%); color:#fff; padding:0.25rem 0.55rem; border-radius:9999px; display:inline-flex; align-items:center; gap:0.3rem; font-weight:600; box-shadow:0 4px 10px rgba(15,23,42,0.18);',
        $colorStart,
        $colorEnd
    );

    $records[] = [
        'id' => $row['id'],
        'type_label' => $label,
        'type_icon' => $icon,
        'badge_style' => $badgeStyle,
        'record_date' => $row['record_date'],
        'record_time' => $row['record_time'],
    ];
}

$tardiness_query = 'SELECT 
        COUNT(CASE WHEN UPPER(type) = \'ENTRY\' AND TIME(timestamp) > \'10:05:00\' THEN 1 END) AS late_entries,
        COUNT(CASE WHEN UPPER(type) = \'LUNCH\' AND TIME(timestamp) > \'14:00:00\' THEN 1 END) AS late_lunches,
        COUNT(CASE WHEN UPPER(type) = \'BREAK\' AND TIME(timestamp) > \'17:00:00\' THEN 1 END) AS late_breaks,
        COUNT(*) AS total_entries
    FROM attendance
    WHERE user_id = ?';

$tardiness_stmt = $pdo->prepare($tardiness_query);
$tardiness_stmt->execute([$employee_id]);
$tardiness_data = $tardiness_stmt->fetch(PDO::FETCH_ASSOC) ?: ['late_entries' => 0, 'late_lunches' => 0, 'late_breaks' => 0, 'total_entries' => 0];

$total_tardiness = 0;
if ((int) ($tardiness_data['total_entries'] ?? 0) > 0) {
    $lateSum = ($tardiness_data['late_entries'] ?? 0) + ($tardiness_data['late_lunches'] ?? 0) + ($tardiness_data['late_breaks'] ?? 0);
    $total_tardiness = round(($lateSum / $tardiness_data['total_entries']) * 100, 2);
}
?>

<?php include '../header_agent.php'; ?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
    <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6 shadow-lg">
        <h2 class="text-lg font-semibold text-slate-100 mb-3">Metricas de tardanza</h2>
        <p class="text-4xl font-bold <?php
            if ($total_tardiness > 50) {
                echo 'text-rose-400';
            } elseif ($total_tardiness > 25) {
                echo 'text-amber-300';
            } else {
                echo 'text-emerald-300';
            }
        ?>"><?= $total_tardiness ?>%</p>
        <p class="text-sm text-slate-400 mt-2">Promedio basado en tus registros historicos.</p>
    </div>

    <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6 shadow-lg">
        <h2 class="text-lg font-semibold text-slate-100 mb-4">Mis registros de asistencia</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-800">
                <thead class="bg-slate-900/80">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-400">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-400">Tipo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-400">Fecha</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-400">Hora</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php if (!empty($records)): ?>
                        <?php foreach ($records as $record): ?>
                            <tr class="hover:bg-slate-800/40 transition-colors">
                                <td class="px-4 py-3 text-sm text-slate-300"><?= $record['id'] ?></td>
                                <td class="px-4 py-3 text-sm text-slate-100">
                                    <span style="<?= htmlspecialchars($record['badge_style'], ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="<?= htmlspecialchars($record['type_icon']) ?> text-xs"></i>
                                        <?= htmlspecialchars($record['type_label']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-300"><?= htmlspecialchars($record['record_date']) ?></td>
                                <td class="px-4 py-3 text-sm text-slate-300"><?= htmlspecialchars($record['record_time']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-slate-400 text-sm">No hay registros disponibles.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include '../footer.php'; ?>
