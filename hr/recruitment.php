<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

ensurePermission('hr_recruitment', '../unauthorized.php');

// Filters
$status_filter = $_GET['status'] ?? 'all';
$job_filter    = $_GET['job']    ?? 'all';
$ai_filter     = $_GET['ai']     ?? 'all'; // all|high|medium|low|unknown
$search        = trim((string) ($_GET['search'] ?? ''));
$sort          = $_GET['sort']   ?? 'applied_date';
$order         = strtoupper($_GET['order'] ?? 'DESC');
$view          = $_GET['view']   ?? 'table'; // table|cards|kanban
$page          = max(1, (int) ($_GET['page'] ?? 1));
$per_page      = (int) ($_GET['per_page'] ?? 20);
if ($per_page < 10) {
    $per_page = 10;
} elseif ($per_page > 100) {
    $per_page = 100;
}

// Whitelist sort columns
$sort_whitelist = ['applied_date', 'first_name', 'status', 'application_code', 'ai_score', 'years_of_experience'];
if (!in_array($sort, $sort_whitelist, true)) {
    $sort = 'applied_date';
}
$order = $order === 'ASC' ? 'ASC' : 'DESC';

$baseQuery = "
    FROM job_applications a
    LEFT JOIN job_postings j ON a.job_posting_id = j.id
    LEFT JOIN users u ON a.assigned_to = u.id
    WHERE 1=1
";
$params = [];
if ($status_filter !== 'all') {
    $baseQuery .= " AND a.status = :status";
    $params['status'] = $status_filter;
}
if ($job_filter !== 'all') {
    $baseQuery .= " AND a.job_posting_id = :job_id";
    $params['job_id'] = $job_filter;
}
if ($ai_filter !== 'all') {
    if ($ai_filter === 'unknown') {
        $baseQuery .= " AND a.ai_score IS NULL";
    } elseif ($ai_filter === 'high') {
        $baseQuery .= " AND a.ai_score >= 75";
    } elseif ($ai_filter === 'medium') {
        $baseQuery .= " AND a.ai_score BETWEEN 50 AND 74";
    } elseif ($ai_filter === 'low') {
        $baseQuery .= " AND a.ai_score IS NOT NULL AND a.ai_score < 50";
    }
}
if ($search !== '') {
    $baseQuery .= " AND (
        a.first_name LIKE :search_q
        OR a.last_name LIKE :search_q
        OR CONCAT(a.first_name, ' ', a.last_name) LIKE :search_q
        OR a.email LIKE :search_q
        OR a.phone LIKE :search_q
        OR a.application_code LIKE :search_q
        OR a.cedula LIKE :search_q
    )";
    $params['search_q'] = '%' . $search . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) " . $baseQuery);
$countStmt->execute($params);
$total_filtered = (int) $countStmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_filtered / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

$query = "
    SELECT a.*, j.title AS job_title, j.department AS job_department, u.full_name AS assigned_to_name,
           (SELECT COUNT(*) FROM application_comments WHERE application_id = a.id) AS comment_count,
           (SELECT COUNT(*) FROM recruitment_interviews WHERE application_id = a.id AND status = 'scheduled') AS interview_count
    " . $baseQuery . "
    ORDER BY a.$sort $order
    LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jobs = $pdo->query("SELECT id, title FROM job_postings ORDER BY (status='active') DESC, title")->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'total'                => (int) $pdo->query("SELECT COUNT(*) FROM job_applications")->fetchColumn(),
    'new'                  => (int) $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'new'")->fetchColumn(),
    'reviewing'            => (int) $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'reviewing'")->fetchColumn(),
    'shortlisted'          => (int) $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'shortlisted'")->fetchColumn(),
    'interview_scheduled'  => (int) $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'interview_scheduled'")->fetchColumn(),
    'hired'                => (int) $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'hired'")->fetchColumn(),
    'ai_processed'         => (int) $pdo->query("SELECT COUNT(*) FROM job_applications WHERE ai_processed_at IS NOT NULL")->fetchColumn(),
    'ai_high'              => (int) $pdo->query("SELECT COUNT(*) FROM job_applications WHERE ai_score >= 75")->fetchColumn(),
    'avg_ai_score'         => (float) ($pdo->query("SELECT AVG(ai_score) FROM job_applications WHERE ai_score IS NOT NULL")->fetchColumn() ?: 0),
];

$status_labels = [
    'new'                 => 'Nueva',
    'reviewing'           => 'En Revisión',
    'shortlisted'         => 'Preseleccionado',
    'interview_scheduled' => 'Entrevista',
    'interviewed'         => 'Entrevistado',
    'offer_extended'      => 'Oferta',
    'hired'               => 'Contratado',
    'rejected'            => 'Rechazado',
    'withdrawn'           => 'Retirado',
];

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

require_once '../header.php';

function scoreClass(?int $score): string
{
    if ($score === null) return 'unknown';
    if ($score >= 75) return 'high';
    if ($score >= 50) return 'medium';
    return 'low';
}
function scoreLabel(?int $score): string
{
    if ($score === null) return 'Sin evaluar';
    if ($score >= 75) return 'Alto';
    if ($score >= 50) return 'Medio';
    return 'Bajo';
}
function scoreColor(?int $score): string
{
    if ($score === null) return '#94a3b8';
    if ($score >= 75) return '#10b981';
    if ($score >= 50) return '#f59e0b';
    return '#ef4444';
}
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/recruitment.css">
<style>
    .view-toggle button { padding: .45rem .9rem; border-radius: 10px; font-size: .85rem; font-weight: 600; border: 1px solid transparent; }
    .view-toggle button.active { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #fff; }
    .view-toggle button.idle { background: rgba(148,163,184,0.12); color: #94a3b8; }
    .theme-light .view-toggle button.idle { background: #f1f5f9; color: #475569; }

    .ai-action-btn {
        background: linear-gradient(135deg, #7c3aed, #4f46e5);
        color: #fff; padding: .35rem .65rem; border-radius: 8px; font-size: .72rem;
        display:inline-flex; align-items:center; gap:.25rem;
    }
    .ai-action-btn:disabled { opacity:.5; }

    .pill { display:inline-flex; align-items:center; gap:.25rem; padding:.2rem .55rem; border-radius:999px; font-size:.7rem; font-weight:600; }
    .pill.muted { background: rgba(148,163,184,0.15); color:#94a3b8; }
    .pill.indigo { background: rgba(99,102,241,0.18); color:#a5b4fc; }
    .pill.amber  { background: rgba(245,158,11,0.18); color:#fbbf24; }
    .pill.emerald { background: rgba(16,185,129,0.18); color:#34d399; }

    .modal-content { border: 1px solid rgba(148,163,184,0.2); }
</style>

<div class="container-fluid mx-auto px-4 py-6">
    <!-- Header -->
    <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white mb-1">
                <i class="fas fa-user-plus text-indigo-400 mr-3"></i>Reclutamiento
            </h1>
            <p class="text-slate-200 text-sm">Gestiona candidatos con apoyo de IA: parseo de CV, scoring automático y recomendaciones.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="index.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> HR</a>
            <a href="job_postings.php" class="btn-secondary"><i class="fas fa-briefcase"></i> Vacantes</a>
            <?php if (function_exists('userHasPermission') && userHasPermission('hr_recruitment_ai')): ?>
                <a href="recruitment_ai_analysis.php" class="ai-button" style="text-decoration:none;">
                    <i class="fas fa-brain"></i> Análisis IA
                </a>
            <?php endif; ?>
            <button class="btn-success" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Exportar</button>
        </div>
    </div>

    <!-- Statistics -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <?php
        $statBoxes = [
            ['Total',          $stats['total'],               '#6366f1', 'fa-users'],
            ['Nuevas',         $stats['new'],                 '#3b82f6', 'fa-file-circle-plus'],
            ['Revisión',       $stats['reviewing'],           '#f59e0b', 'fa-magnifying-glass'],
            ['Shortlist',      $stats['shortlisted'],         '#8b5cf6', 'fa-star'],
            ['Entrevistas',    $stats['interview_scheduled'], '#ec4899', 'fa-calendar-check'],
            ['Contratados',    $stats['hired'],               '#10b981', 'fa-circle-check'],
        ];
        foreach ($statBoxes as [$label, $value, $color, $icon]):
        ?>
            <div class="glass-card p-4" style="background: linear-gradient(135deg, <?php echo $color; ?>26 0%, rgba(15,23,42,0.5) 90%); border: 1px solid <?php echo $color; ?>40;">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-[11px] font-semibold uppercase tracking-widest text-slate-200 opacity-90 whitespace-nowrap"><?php echo $label; ?></div>
                        <div class="text-3xl font-extrabold text-white mt-1 leading-none"><?php echo number_format((int) $value); ?></div>
                    </div>
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 shadow-md" style="background: <?php echo $color; ?>;">
                        <i class="fas <?php echo $icon; ?> text-white text-lg"></i>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- AI banner -->
    <div class="ai-banner-card mb-6 flex flex-col lg:flex-row lg:items-center gap-4">
        <div class="flex-1">
            <div class="text-xs font-semibold uppercase tracking-widest text-purple-300 flex items-center gap-2">
                <i class="fas fa-brain"></i> <span class="text-purple-200">Inteligencia de candidatos</span>
            </div>
            <div class="flex items-center gap-3 mt-2">
                <div class="score-donut" style="--pct: <?php echo (int) round($stats['avg_ai_score']); ?>; --color:<?php echo scoreColor((int) round($stats['avg_ai_score'])); ?>;">
                    <span><?php echo $stats['avg_ai_score'] > 0 ? round($stats['avg_ai_score']) : '—'; ?></span>
                </div>
                <div>
                    <div class="text-base font-bold text-white">Score promedio del talento</div>
                    <div class="text-xs text-slate-200 mt-0.5">
                        <strong class="text-white"><?php echo $stats['ai_processed']; ?></strong> de <strong class="text-white"><?php echo $stats['total']; ?></strong> ya procesadas ·
                        <span class="text-emerald-300 font-semibold"><?php echo $stats['ai_high']; ?> alto-fit</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 lg:justify-end">
            <button class="ai-button" onclick="bulkProcessUnscored()" id="bulkProcessBtn">
                <i class="fas fa-wand-magic-sparkles"></i> Procesar nuevos con IA
            </button>
        </div>
    </div>

    <!-- Filters bar -->
    <div class="glass-card mb-5">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            <div class="md:col-span-3">
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-200 mb-1.5">Buscar</label>
                <input type="text" class="form-input w-full" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Nombre, email, código, cédula...">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-200 mb-1.5">Estado</label>
                <select class="form-select" name="status">
                    <option value="all">Todos</option>
                    <?php foreach ($status_labels as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php if ($status_filter === $k) echo 'selected'; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-3">
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-200 mb-1.5">Vacante</label>
                <select class="form-select" name="job">
                    <option value="all">Todas las vacantes</option>
                    <?php foreach ($jobs as $j): ?>
                        <option value="<?php echo $j['id']; ?>" <?php if ($job_filter == $j['id']) echo 'selected'; ?>><?php echo htmlspecialchars($j['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-200 mb-1.5">Score IA</label>
                <select class="form-select" name="ai">
                    <option value="all"     <?php if ($ai_filter==='all')     echo 'selected'; ?>>Todos</option>
                    <option value="high"    <?php if ($ai_filter==='high')    echo 'selected'; ?>>Alto (75+)</option>
                    <option value="medium"  <?php if ($ai_filter==='medium')  echo 'selected'; ?>>Medio (50-74)</option>
                    <option value="low"     <?php if ($ai_filter==='low')     echo 'selected'; ?>>Bajo (&lt;50)</option>
                    <option value="unknown" <?php if ($ai_filter==='unknown') echo 'selected'; ?>>Sin evaluar</option>
                </select>
            </div>
            <div class="md:col-span-2 flex gap-2">
                <button class="btn-primary flex-1"><i class="fas fa-filter"></i> Filtrar</button>
                <a href="recruitment.php" class="btn-secondary"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>

    <!-- View toggle + actions -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
        <div class="text-sm text-slate-200">
            <strong class="text-white text-lg"><?php echo $total_filtered; ?></strong> <span class="text-slate-300">solicitud(es) encontradas</span>
        </div>
        <div class="flex items-center gap-2 view-toggle">
            <a href="<?php echo '?' . http_build_query(array_merge($_GET, ['view'=>'table'])); ?>"><button class="<?php echo $view==='table'?'active':'idle'; ?>"><i class="fas fa-table-list"></i> Tabla</button></a>
            <a href="<?php echo '?' . http_build_query(array_merge($_GET, ['view'=>'cards'])); ?>"><button class="<?php echo $view==='cards'?'active':'idle'; ?>"><i class="fas fa-grip"></i> Tarjetas</button></a>
        </div>
    </div>

    <!-- Results -->
    <div class="glass-card">
        <?php if (empty($applications)): ?>
            <div class="text-center py-12">
                <i class="fas fa-inbox text-slate-500 text-5xl mb-3"></i>
                <h4 class="text-lg font-semibold text-white mb-1">No se encontraron solicitudes</h4>
                <p class="text-slate-300">Prueba ajustando los filtros.</p>
            </div>
        <?php elseif ($view === 'cards'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($applications as $app):
                    $name = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
                    $score = $app['ai_score'] !== null ? (int) $app['ai_score'] : null;
                    $sc = scoreClass($score);
                ?>
                    <div class="applicant-card flex flex-col gap-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 rounded-full bg-gradient-to-br from-indigo-600 to-purple-600 flex items-center justify-center text-white font-bold uppercase">
                                    <?php echo htmlspecialchars(strtoupper(substr($name, 0, 1))); ?>
                                </div>
                                <div>
                                    <div class="font-semibold text-white leading-tight"><?php echo htmlspecialchars($name); ?></div>
                                    <div class="text-xs text-slate-300"><?php echo htmlspecialchars($app['application_code']); ?></div>
                                </div>
                            </div>
                            <div class="score-donut" style="--pct: <?php echo $score ?? 0; ?>; --color:<?php echo scoreColor($score); ?>;">
                                <span><?php echo $score === null ? '—' : $score; ?></span>
                            </div>
                        </div>

                        <div class="text-sm text-slate-300 flex flex-wrap gap-2">
                            <span><i class="fas fa-briefcase mr-1 text-indigo-400"></i><?php echo htmlspecialchars($app['job_title'] ?? 'Sin vacante'); ?></span>
                            <?php if (!empty($app['email']) && $app['email'] !== 'sin-correo@evallish.local'): ?>
                                <span><i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($app['email']); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($app['ai_summary'])): ?>
                            <p class="text-xs text-slate-300 line-clamp-3 italic">"<?php echo htmlspecialchars($app['ai_summary']); ?>"</p>
                        <?php endif; ?>

                        <div class="flex items-center justify-between">
                            <span class="status-badge-recruitment status-<?php echo $app['status']; ?>"><?php echo $status_labels[$app['status']] ?? $app['status']; ?></span>
                            <span class="score-pill <?php echo $sc; ?>"><i class="fas fa-brain"></i> <?php echo scoreLabel($score); ?></span>
                        </div>

                        <div class="flex gap-2">
                            <a href="view_application.php?id=<?php echo $app['id']; ?>" class="btn-primary flex-1 text-sm text-center"><i class="fas fa-eye"></i> Ver</a>
                            <button class="ai-action-btn" onclick="processWithAI(<?php echo $app['id']; ?>, this)" title="Procesar con IA"><i class="fas fa-wand-magic-sparkles"></i> IA</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto recruitment-table-scroll" data-sync-scroll="recruitment-table">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-indigo-500/30 bg-slate-900/40">
                            <th class="text-left py-3 px-4 text-slate-100 text-xs font-bold uppercase tracking-wider">Código</th>
                            <th class="text-left py-3 px-4 text-slate-100 text-xs font-bold uppercase tracking-wider">Candidato</th>
                            <th class="text-left py-3 px-4 text-slate-100 text-xs font-bold uppercase tracking-wider">Vacante</th>
                            <th class="text-center py-3 px-4 text-slate-100 text-xs font-bold uppercase tracking-wider">IA</th>
                            <th class="text-left py-3 px-4 text-slate-100 text-xs font-bold uppercase tracking-wider">Estado</th>
                            <th class="text-left py-3 px-4 text-slate-100 text-xs font-bold uppercase tracking-wider">Aplicó</th>
                            <th class="text-left py-3 px-4 text-slate-100 text-xs font-bold uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app):
                            $name = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
                            $score = $app['ai_score'] !== null ? (int) $app['ai_score'] : null;
                            $sc = scoreClass($score);
                            $emailDisplay = (!empty($app['email']) && $app['email'] !== 'sin-correo@evallish.local') ? $app['email'] : '';
                        ?>
                            <tr class="border-b border-slate-700/40 hover:bg-slate-800/40 transition-colors">
                                <td class="py-3 px-4">
                                    <code class="text-indigo-300 text-xs font-mono bg-indigo-500/10 px-2 py-1 rounded"><?php echo htmlspecialchars($app['application_code']); ?></code>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="font-semibold text-white text-sm leading-tight"><?php echo htmlspecialchars($name); ?></div>
                                    <div class="text-xs text-slate-300 mt-0.5 flex flex-wrap gap-x-3">
                                        <?php if ($emailDisplay): ?><span><i class="fas fa-envelope mr-1 text-slate-400"></i><?php echo htmlspecialchars($emailDisplay); ?></span><?php endif; ?>
                                        <?php if (!empty($app['phone'])): ?><span><i class="fas fa-phone mr-1 text-slate-400"></i><?php echo htmlspecialchars($app['phone']); ?></span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="text-sm text-white font-medium"><?php echo htmlspecialchars($app['job_title'] ?? '—'); ?></div>
                                    <?php if (!empty($app['job_department'])): ?>
                                        <div class="text-xs text-slate-300 mt-0.5"><i class="fas fa-building mr-1 text-slate-400"></i><?php echo htmlspecialchars($app['job_department']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <div class="inline-flex flex-col items-center gap-1">
                                        <div class="score-donut" style="width:46px;height:46px;--pct: <?php echo $score ?? 0; ?>; --color:<?php echo scoreColor($score); ?>;">
                                            <span style="font-size:.78rem"><?php echo $score === null ? '—' : $score; ?></span>
                                        </div>
                                        <?php if (!empty($app['ai_recommendation'])): ?>
                                            <span class="text-[9px] uppercase font-bold text-slate-200 bg-slate-700/60 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($app['ai_recommendation']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="status-badge-recruitment status-<?php echo $app['status']; ?>"><?php echo $status_labels[$app['status']] ?? $app['status']; ?></span>
                                </td>
                                <td class="py-3 px-4 text-slate-200 text-sm whitespace-nowrap">
                                    <i class="far fa-calendar text-slate-400 mr-1"></i>
                                    <?php echo date('d/m/Y', strtotime($app['applied_date'])); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex gap-1.5 items-center">
                                        <?php
                                        $returnParams = array_filter([
                                            'status' => $status_filter !== 'all' ? $status_filter : null,
                                            'job'    => $job_filter    !== 'all' ? $job_filter    : null,
                                            'ai'     => $ai_filter     !== 'all' ? $ai_filter     : null,
                                            'search' => $search ?: null,
                                            'view'   => $view !== 'table' ? $view : null,
                                            'page'   => $page > 1 ? $page : null,
                                        ]);
                                        $returnUrl = 'recruitment.php' . ($returnParams ? '?' . http_build_query($returnParams) : '');
                                        ?>
                                        <a href="view_application.php?id=<?php echo $app['id']; ?>&returnUrl=<?php echo urlencode($returnUrl); ?>" class="btn-action btn-view" title="Ver">
                                            <i class="fas fa-eye"></i>
                                            <?php if ($app['comment_count'] > 0): ?><span class="badge-count"><?php echo $app['comment_count']; ?></span><?php endif; ?>
                                        </a>
                                        <button type="button" class="ai-action-btn" onclick="processWithAI(<?php echo $app['id']; ?>, this)" title="Procesar con IA">
                                            <i class="fas fa-wand-magic-sparkles"></i>
                                        </button>
                                        <button type="button" class="btn-action btn-secondary" title="Evaluar" onclick='openEvaluationModal(<?php echo json_encode([
                                            'id' => $app['id'],
                                            'name' => $name,
                                            'result' => $app['evaluation_result'] ?? '',
                                            'datetime' => $app['evaluation_datetime'] ?? '',
                                            'comments' => $app['evaluation_comments'] ?? '',
                                            'interviewer' => $app['evaluation_interviewer'] ?? '',
                                            'interview_date' => $app['evaluation_interview_date'] ?? ''
                                        ], JSON_UNESCAPED_UNICODE); ?>)'>
                                            <i class="fas fa-clipboard-check"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($total_pages > 1): ?>
            <?php
            $paginationParams = $_GET;
            $startItem = $total_filtered > 0 ? ($offset + 1) : 0;
            $endItem = min($offset + $per_page, $total_filtered);
            ?>
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mt-5">
                <div class="text-sm text-slate-200">Mostrando <strong class="text-white"><?php echo $startItem; ?>-<?php echo $endItem; ?></strong> de <strong class="text-white"><?php echo $total_filtered; ?></strong></div>
                <div class="flex flex-wrap gap-2">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($total_pages, $page + 1);

                    $paginationParams['page'] = $prevPage;
                    $prevUrl = '?' . http_build_query($paginationParams);
                    $paginationParams['page'] = $nextPage;
                    $nextUrl = '?' . http_build_query($paginationParams);
                    ?>
                    <a class="btn-secondary <?php echo $page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>" href="<?php echo $prevUrl; ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php
                    $maxButtons = 5;
                    $startPage = max(1, $page - (int) floor($maxButtons / 2));
                    $endPage = min($total_pages, $startPage + $maxButtons - 1);
                    if ($endPage - $startPage + 1 < $maxButtons) {
                        $startPage = max(1, $endPage - $maxButtons + 1);
                    }
                    for ($p = $startPage; $p <= $endPage; $p++):
                        $paginationParams['page'] = $p;
                        $pageUrl = '?' . http_build_query($paginationParams);
                    ?>
                        <a class="<?php echo $p === $page ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo $pageUrl; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                    <a class="btn-secondary <?php echo $page >= $total_pages ? 'opacity-50 pointer-events-none' : ''; ?>" href="<?php echo $nextUrl; ?>"><i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Evaluation modal -->
<div class="modal fade" id="evaluationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="evaluationForm" onsubmit="submitEvaluation(event)">
                <div class="modal-header">
                    <h5 class="modal-title">Resultados de la evaluación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body space-y-3">
                    <input type="hidden" name="application_id" id="eval_application_id">
                    <div class="text-sm text-slate-300">Candidato: <span class="font-semibold text-white" id="eval_candidate"></span></div>
                    <div>
                        <label class="form-label d-block">Resultado</label>
                        <div class="d-flex flex-wrap gap-3">
                            <label><input type="radio" name="evaluation_result" value="acceptable" required> Aceptable</label>
                            <label><input type="radio" name="evaluation_result" value="rejected" required> Rechazado</label>
                            <label><input type="radio" name="evaluation_result" value="consideration" required> En consideración</label>
                            <label><input type="radio" name="evaluation_result" value="interview" required> Citado a entrevista</label>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Fecha/Hora</label><input type="datetime-local" class="form-control" name="evaluation_datetime" id="evaluation_datetime" required></div>
                        <div class="col-md-6"><label class="form-label">Fecha entrevista</label><input type="date" class="form-control" name="evaluation_interview_date" id="evaluation_interview_date"></div>
                    </div>
                    <div><label class="form-label">Comentarios</label><textarea class="form-control" rows="3" name="evaluation_comments" id="evaluation_comments"></textarea></div>
                    <div><label class="form-label">Entrevistador</label><input type="text" class="form-control" name="evaluation_interviewer" id="evaluation_interviewer"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    function exportToExcel() {
        const params = new URLSearchParams(window.location.search);
        window.location.href = 'export_applications.php?' + params.toString();
    }

    function openEvaluationModal(data) {
        document.getElementById('eval_application_id').value = data.id;
        document.getElementById('eval_candidate').innerText = data.name || 'Candidato';
        ['acceptable','rejected','consideration','interview'].forEach(val => {
            const radio = document.querySelector(`input[name="evaluation_result"][value="${val}"]`);
            if (radio) radio.checked = (data.result === val);
        });
        document.getElementById('evaluation_datetime').value = data.datetime ? data.datetime.replace(' ', 'T') : '';
        document.getElementById('evaluation_comments').value = data.comments || '';
        document.getElementById('evaluation_interviewer').value = data.interviewer || '';
        document.getElementById('evaluation_interview_date').value = data.interview_date || '';
        new bootstrap.Modal(document.getElementById('evaluationModal')).show();
    }

    async function submitEvaluation(event) {
        event.preventDefault();
        const form = document.getElementById('evaluationForm');
        const submitBtn = form.querySelector('button[type="submit"]');
        const orig = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Guardando...';
        try {
            const r = await fetch('update_evaluation.php', { method: 'POST', body: new FormData(form) });
            const d = await r.json();
            if (d.success) {
                bootstrap.Modal.getInstance(document.getElementById('evaluationModal')).hide();
                window.location.reload();
            } else {
                alert(d.message || 'No se pudo guardar la evaluación');
            }
        } catch (err) {
            alert('Error al guardar la evaluación');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = orig;
        }
    }

    async function processWithAI(applicationId, btn) {
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
        try {
            const fd = new FormData();
            fd.append('action', 'process_application');
            fd.append('application_id', applicationId);
            const r = await fetch('recruitment_ai_endpoints.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success) {
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => location.reload(), 700);
            } else {
                alert('IA: ' + (d.error || 'No se pudo procesar.'));
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        } catch (err) {
            alert('Error: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }

    async function bulkProcessUnscored() {
        if (!confirm('Procesar todas las solicitudes sin evaluar con la IA? Esto puede tomar varios minutos.')) return;
        const btn = document.getElementById('bulkProcessBtn');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Procesando...';
        try {
            const fd = new FormData();
            fd.append('action', 'bulk_process_unscored');
            const r = await fetch('recruitment_ai_endpoints.php?action=bulk_process_unscored', { method: 'GET' });
            const d = await r.json().catch(() => ({}));
            if (d.success) {
                alert('Procesadas: ' + (d.processed || 0));
                location.reload();
            } else {
                alert('Error: ' + (d.error || 'No se pudo procesar.'));
            }
        } catch (err) {
            alert('Endpoint no implementado todavía. Procesa candidatos individualmente.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }
</script>

<?php require_once '../footer.php'; ?>
