<?php
require_once __DIR__ . '/header_agent.php';
require_once __DIR__ . '/quality_db.php';

date_default_timezone_set('America/Santo_Domingo');

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'Agente';

$qualityPdo = getQualityDbConnection();
$qualityUser = null;
$qualityError = null;
$qualityMetrics = [
    'total_evaluations' => 0,
    'audited_calls' => 0,
    'avg_percentage' => 0.0,
    'max_percentage' => 0.0,
    'min_percentage' => 0.0,
    'last_eval_date' => null,
    'avg_ai_score' => 0.0,
];
$qualityAudits = [];

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-t');
}
if ($endDate < $startDate) {
    $endDate = $startDate;
}

if ($qualityPdo && $username) {
    $qualityUserStmt = $qualityPdo->prepare("SELECT id, full_name, username FROM users WHERE username = ? LIMIT 1");
    $qualityUserStmt->execute([$username]);
    $qualityUser = $qualityUserStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($qualityUser) {
        $qualityUserId = (int) $qualityUser['id'];

        $metricsStmt = $qualityPdo->prepare("
            SELECT
                COUNT(*) AS total_evaluations,
                SUM(CASE WHEN call_id IS NOT NULL THEN 1 ELSE 0 END) AS audited_calls,
                ROUND(AVG(percentage), 2) AS avg_percentage,
                ROUND(MAX(percentage), 2) AS max_percentage,
                ROUND(MIN(percentage), 2) AS min_percentage,
                MAX(COALESCE(call_date, DATE(created_at))) AS last_eval_date
            FROM evaluations
            WHERE agent_id = ?
            AND DATE(created_at) BETWEEN ? AND ?
        ");
        $metricsStmt->execute([$qualityUserId, $startDate, $endDate]);
        $metricsRow = $metricsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $qualityMetrics['total_evaluations'] = (int) ($metricsRow['total_evaluations'] ?? 0);
        $qualityMetrics['audited_calls'] = (int) ($metricsRow['audited_calls'] ?? 0);
        $qualityMetrics['avg_percentage'] = (float) ($metricsRow['avg_percentage'] ?? 0);
        $qualityMetrics['max_percentage'] = (float) ($metricsRow['max_percentage'] ?? 0);
        $qualityMetrics['min_percentage'] = (float) ($metricsRow['min_percentage'] ?? 0);
        $qualityMetrics['last_eval_date'] = $metricsRow['last_eval_date'] ?? null;

        $aiStmt = $qualityPdo->prepare("
            SELECT ROUND(AVG(ai.score), 2) AS avg_ai_score
            FROM call_ai_analytics ai
            INNER JOIN calls c ON c.id = ai.call_id
            INNER JOIN evaluations e ON e.call_id = c.id
            WHERE e.agent_id = ?
            AND DATE(e.created_at) BETWEEN ? AND ?
        ");
        $aiStmt->execute([$qualityUserId, $startDate, $endDate]);
        $qualityMetrics['avg_ai_score'] = (float) (($aiStmt->fetchColumn() ?: 0));

        $auditsStmt = $qualityPdo->prepare("
            SELECT
                e.id AS evaluation_id,
                e.call_id,
                e.percentage,
                e.total_score,
                e.max_possible_score,
                e.general_comments,
                e.call_date,
                e.created_at,
                e.improvement_areas,
                e.improvement_plan,
                e.tasks_commitments,
                c.call_datetime,
                c.duration_seconds,
                c.customer_phone,
                c.recording_path,
                c.call_type,
                camp.name AS campaign_name,
                ai.model AS ai_model,
                ai.score AS ai_score,
                ai.summary AS ai_summary,
                ai.metrics_json AS ai_metrics
            FROM evaluations e
            LEFT JOIN calls c ON c.id = e.call_id
            LEFT JOIN campaigns camp ON camp.id = e.campaign_id
            LEFT JOIN call_ai_analytics ai ON ai.call_id = c.id
            WHERE e.agent_id = ?
            AND DATE(e.created_at) BETWEEN ? AND ?
            ORDER BY e.created_at DESC
            LIMIT 50
        ");
        $auditsStmt->execute([$qualityUserId, $startDate, $endDate]);
        $qualityAudits = $auditsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $qualityError = 'No se encontró el usuario en el sistema de calidad.';
    }
} elseif (!$qualityPdo) {
    $qualityError = 'No se pudo conectar con la base de calidad.';
}

if (!function_exists('resolveQualityRecordingUrl')) {
    function resolveQualityRecordingUrl(?string $recordingPath): ?string
    {
        $recordingPath = trim((string) $recordingPath);
        if ($recordingPath === '') {
            return null;
        }
        if (preg_match('~^https?://~i', $recordingPath)) {
            return $recordingPath;
        }
        $baseUrl = defined('QUALITY_MEDIA_BASE_URL') ? QUALITY_MEDIA_BASE_URL : '';
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/' . ltrim($recordingPath, '/');
        }
        return $recordingPath;
    }
}

function formatDuration(?int $seconds): string
{
    $seconds = (int) ($seconds ?? 0);
    if ($seconds <= 0) {
        return 'N/A';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    }
    return sprintf('%02d:%02d', $minutes, $secs);
}
?>

<main class="agent-dashboard">
    <section class="dashboard-hero glass-card">
        <div class="hero-main">
            <div class="space-y-2">
                <span class="badge badge--info">Calidad</span>
                <h1 class="text-2xl font-semibold text-primary">Calidad de <?= htmlspecialchars($full_name) ?></h1>
                <p class="text-muted text-sm">Métricas y auditorías del <?= htmlspecialchars(date('d/m/Y', strtotime($startDate))) ?> al <?= htmlspecialchars(date('d/m/Y', strtotime($endDate))) ?>.</p>
            </div>
            <div class="hero-progress">
                <span class="text-sm text-muted uppercase tracking-[0.18em]">Promedio</span>
                <div class="progress-circle" style="--progress: <?= min(max($qualityMetrics['avg_percentage'], 0), 100) ?>%;">
                    <span><?= number_format((float) $qualityMetrics['avg_percentage'], 2) ?>%</span>
                </div>
            </div>
        </div>
        <form method="get" class="hero-range-filter">
            <label>
                <span>Desde</span>
                <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="input-control">
            </label>
            <label>
                <span>Hasta</span>
                <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="input-control">
            </label>
            <button type="submit" class="btn-primary">Actualizar</button>
        </form>
    </section>

    <?php if ($qualityError): ?>
        <section class="glass-card mb-6">
            <div class="bg-amber-500/10 border border-amber-500/30 rounded-lg p-4">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle text-amber-400"></i>
                    <p class="text-amber-200 text-sm"><?= htmlspecialchars($qualityError) ?></p>
                </div>
            </div>
        </section>
    <?php else: ?>
        <section class="metric-grid">
            <article class="metric-card" style="--metric-start:#0ea5e9; --metric-end:#2563eb;">
                <div class="metric-icon"><i class="fas fa-clipboard-check"></i></div>
                <p class="metric-label">Evaluaciones</p>
                <p class="metric-value"><?= (int) $qualityMetrics['total_evaluations'] ?></p>
                <p class="metric-sub">Total en el período</p>
            </article>
            <article class="metric-card" style="--metric-start:#22c55e; --metric-end:#16a34a;">
                <div class="metric-icon"><i class="fas fa-chart-line"></i></div>
                <p class="metric-label">Promedio calidad</p>
                <p class="metric-value"><?= number_format((float) $qualityMetrics['avg_percentage'], 2) ?>%</p>
                <p class="metric-sub">Score general</p>
            </article>
            <article class="metric-card" style="--metric-start:#f97316; --metric-end:#ea580c;">
                <div class="metric-icon"><i class="fas fa-headphones"></i></div>
                <p class="metric-label">Llamadas auditadas</p>
                <p class="metric-value"><?= (int) $qualityMetrics['audited_calls'] ?></p>
                <p class="metric-sub">Con call_id</p>
            </article>
            <article class="metric-card" style="--metric-start:#a855f7; --metric-end:#7c3aed;">
                <div class="metric-icon"><i class="fas fa-star"></i></div>
                <p class="metric-label">Mejor / Peor</p>
                <p class="metric-value"><?= number_format((float) $qualityMetrics['max_percentage'], 2) ?>% / <?= number_format((float) $qualityMetrics['min_percentage'], 2) ?>%</p>
                <p class="metric-sub">Rango de desempeño</p>
            </article>
            <article class="metric-card" style="--metric-start:#14b8a6; --metric-end:#0f766e;">
                <div class="metric-icon"><i class="fas fa-chart-bar"></i></div>
                <p class="metric-label">Score Analítico</p>
                <p class="metric-value"><?= number_format((float) $qualityMetrics['avg_ai_score'], 2) ?></p>
                <p class="metric-sub">Promedio QA</p>
            </article>
            <article class="metric-card" style="--metric-start:#64748b; --metric-end:#334155;">
                <div class="metric-icon"><i class="fas fa-calendar-alt"></i></div>
                <p class="metric-label">Última evaluación</p>
                <p class="metric-value"><?= $qualityMetrics['last_eval_date'] ? htmlspecialchars(date('d/m/Y', strtotime($qualityMetrics['last_eval_date']))) : 'N/A' ?></p>
                <p class="metric-sub">Fecha más reciente</p>
            </article>
        </section>

        <section class="glass-card mb-6">
            <header class="flex items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-primary">Auditorías detalladas</h2>
                    <p class="text-sm text-muted">Incluye resumen completo, análisis QA y audio.</p>
                </div>
                <span class="badge badge--info"><?= count($qualityAudits) ?> registros</span>
            </header>

            <?php if (!empty($qualityAudits)): ?>
                <div class="space-y-4">
                    <?php foreach ($qualityAudits as $audit): ?>
                        <?php
                            $audioUrl = resolveQualityRecordingUrl($audit['recording_path'] ?? null);
                            $summaryText = $audit['ai_summary'] ?: ($audit['general_comments'] ?? '');
                            $metricsArray = [];
                            if (!empty($audit['ai_metrics'])) {
                                $decodedMetrics = json_decode($audit['ai_metrics'], true);
                                if (is_array($decodedMetrics)) {
                                    $metricsArray = $decodedMetrics;
                                }
                            }
                        ?>
                        <article class="p-4 rounded-xl bg-slate-900/60 border border-slate-800">
                            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="badge badge--info"><?= htmlspecialchars($audit['campaign_name'] ?? 'Sin campaña') ?></span>
                                        <?php if (!empty($audit['call_type'])): ?>
                                            <span class="badge badge--neutral"><?= htmlspecialchars($audit['call_type']) ?></span>
                                        <?php endif; ?>
                                        <span class="text-xs text-slate-400"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($audit['call_datetime'] ?: $audit['created_at']))) ?></span>
                                    </div>
                                    <h3 class="text-lg font-semibold text-primary">Score: <?= $audit['percentage'] !== null ? number_format((float) $audit['percentage'], 2) . '%' : 'N/A' ?></h3>
                                    <p class="text-sm text-slate-300 leading-relaxed"><?= nl2br(htmlspecialchars($summaryText)) ?></p>
                                </div>
                                <div class="flex flex-col gap-2 min-w-[260px]">
                                    <div class="text-sm text-slate-400">
                                        <div><strong>Duración:</strong> <?= htmlspecialchars(formatDuration($audit['duration_seconds'])) ?></div>
                                        <div><strong>Score QA:</strong> <?= $audit['ai_score'] !== null ? number_format((float) $audit['ai_score'], 2) : 'N/A' ?></div>
                                        <?php if (!empty($audit['customer_phone'])): ?>
                                            <div><strong>Cliente:</strong> <?= htmlspecialchars($audit['customer_phone']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($audioUrl): ?>
                                        <audio controls preload="none">
                                            <source src="<?= htmlspecialchars($audioUrl) ?>" type="audio/mpeg">
                                        </audio>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-500">Audio no disponible</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($audit['improvement_areas']) || !empty($audit['improvement_plan']) || !empty($audit['tasks_commitments'])): ?>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4">
                                    <?php if (!empty($audit['improvement_areas'])): ?>
                                        <div class="bg-slate-800/60 rounded-lg p-3">
                                            <h4 class="text-sm font-semibold text-primary mb-1">Áreas de mejora</h4>
                                            <p class="text-xs text-slate-300 leading-relaxed"><?= nl2br(htmlspecialchars($audit['improvement_areas'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($audit['improvement_plan'])): ?>
                                        <div class="bg-slate-800/60 rounded-lg p-3">
                                            <h4 class="text-sm font-semibold text-primary mb-1">Plan de mejora</h4>
                                            <p class="text-xs text-slate-300 leading-relaxed"><?= nl2br(htmlspecialchars($audit['improvement_plan'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($audit['tasks_commitments'])): ?>
                                        <div class="bg-slate-800/60 rounded-lg p-3">
                                            <h4 class="text-sm font-semibold text-primary mb-1">Compromisos</h4>
                                            <p class="text-xs text-slate-300 leading-relaxed"><?= nl2br(htmlspecialchars($audit['tasks_commitments'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($metricsArray)): ?>
                                <div class="mt-4">
                                    <h4 class="text-sm font-semibold text-primary mb-2">Métricas de Análisis</h4>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($metricsArray as $key => $value): ?>
                                            <?php if (is_scalar($value)): ?>
                                                <span class="badge badge--neutral">
                                                    <?= htmlspecialchars((string) $key) ?>: <?= htmlspecialchars((string) $value) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-sm text-slate-400">No hay auditorías en el rango seleccionado.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
