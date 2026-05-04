<?php
session_start();
require_once '../db.php';

ensurePermission('hr_job_postings', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Filters
$status_filter = $_GET['status'] ?? 'all';
$search        = trim((string) ($_GET['search'] ?? ''));

$where = " WHERE 1=1 ";
$params = [];
if ($status_filter !== 'all') {
    $where .= " AND j.status = :st";
    $params['st'] = $status_filter;
}
if ($search !== '') {
    $where .= " AND (j.title LIKE :q OR j.department LIKE :q OR j.location LIKE :q) ";
    $params['q'] = '%' . $search . '%';
}

$stmt = $pdo->prepare("
    SELECT j.*,
           (SELECT COUNT(*) FROM job_applications a WHERE a.job_posting_id = j.id) AS application_count,
           (SELECT COUNT(*) FROM job_applications a WHERE a.job_posting_id = j.id AND a.status = 'new') AS new_applications,
           (SELECT COUNT(*) FROM job_applications a WHERE a.job_posting_id = j.id AND a.status = 'shortlisted') AS shortlisted_count
    FROM job_postings j
    $where
    ORDER BY (j.status = 'active') DESC, j.posted_date DESC
");
$stmt->execute($params);
$job_postings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = [
    'all'      => (int) $pdo->query("SELECT COUNT(*) FROM job_postings")->fetchColumn(),
    'active'   => (int) $pdo->query("SELECT COUNT(*) FROM job_postings WHERE status = 'active'")->fetchColumn(),
    'inactive' => (int) $pdo->query("SELECT COUNT(*) FROM job_postings WHERE status = 'inactive'")->fetchColumn(),
    'closed'   => (int) $pdo->query("SELECT COUNT(*) FROM job_postings WHERE status = 'closed'")->fetchColumn(),
];

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_path = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
$base_url = rtrim($protocol . '://' . $host . $base_path, '/');

function getJobBannerUrl($jobId, $baseUrl)
{
    $bannerDir = realpath(__DIR__ . '/../uploads/job_banners');
    if (!$bannerDir) {
        return null;
    }
    $baseBannerUrl = rtrim($baseUrl, '/') . '/uploads/job_banners';
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $filePath = $bannerDir . DIRECTORY_SEPARATOR . "job_{$jobId}." . $ext;
        if (file_exists($filePath)) {
            return $baseBannerUrl . "/job_{$jobId}.{$ext}";
        }
    }
    return null;
}
foreach ($job_postings as &$j) {
    $j['banner_url'] = getJobBannerUrl($j['id'], $base_url);
}
unset($j);

require_once '../header.php';
?>

<link rel="stylesheet" href="../assets/css/recruitment.css">

<style>
    .job-tile {
        background: rgba(30, 41, 59, 0.55);
        border: 1px solid rgba(148, 163, 184, 0.15);
        border-radius: 18px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: transform .2s, box-shadow .2s, border-color .2s;
    }
    .job-tile:hover { transform: translateY(-3px); border-color: rgba(99,102,241,0.45); box-shadow: 0 18px 30px -20px rgba(99,102,241,0.5); }
    .theme-light .job-tile { background: #fff; border: 1px solid #e2e8f0; color:#0f172a; }
    .job-tile-banner {
        height: 110px;
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        position: relative; overflow: hidden;
    }
    .job-tile-banner img { width:100%; height:100%; object-fit:cover; }
    .job-tile-banner::after {
        content:''; position:absolute; inset:0;
        background: linear-gradient(180deg, transparent 30%, rgba(0,0,0,0.55));
    }
    .job-tile-banner .meta {
        position:absolute; bottom: 10px; left: 12px; right: 12px; z-index: 1;
        color: #fff;
    }
    .filter-tab {
        padding: 0.5rem 1rem; border-radius: 999px; font-size: 0.85rem; font-weight: 600;
        border: 1px solid transparent; transition: all .15s ease;
    }
    .filter-tab.active { background: linear-gradient(135deg, #4f46e5, #7c3aed); color:#fff; border-color: transparent; }
    .filter-tab.idle   { color:#94a3b8; background: rgba(148,163,184,0.1); }
    .filter-tab.idle:hover { color:#fff; background: rgba(148,163,184,0.25); }
    .theme-light .filter-tab.idle { color: #475569; background:#f1f5f9; }
    .theme-light .filter-tab.idle:hover { color:#0f172a; background:#e2e8f0; }

    .ai-button {
        background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 50%, #06b6d4 100%);
        color:#fff; border-radius: 12px; padding: .55rem .9rem;
        display:inline-flex; align-items:center; gap:.5rem; font-weight:600; font-size:.85rem;
        border: none; cursor:pointer; transition: filter .15s ease, transform .15s ease;
    }
    .ai-button:hover { filter:brightness(1.1); transform: translateY(-1px); }
    .ai-button:disabled { opacity:.5; cursor: progress; }

    .modal { display:none; }
    .modal.show { display:flex; }
    .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,0.7); backdrop-filter: blur(6px); z-index:200; }
    .modal-panel {
        position: fixed; inset: 0; z-index: 210; display:flex; align-items:center; justify-content:center; padding: 1rem;
    }
    .modal-card {
        max-width: 56rem; width: 100%; max-height: 90vh; overflow-y: auto;
        background: #0f172a; color:#e2e8f0; border-radius: 18px; border: 1px solid rgba(148,163,184,0.2);
    }
    .theme-light .modal-card { background:#fff; color:#0f172a; border-color:#e2e8f0; }

    .field-group { display:grid; gap: 1rem; }
    @media (min-width: 768px) { .field-group.cols-2 { grid-template-columns: 1fr 1fr; } }

    .field label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color:#cbd5e1; display:block; margin-bottom:.25rem; }
    .theme-light .field label { color:#334155; }
    .field input, .field select, .field textarea {
        width:100%; margin-top: .25rem; padding: .55rem .75rem; border-radius: 10px;
        background: #0b1223; border: 1px solid #1e293b; color:#e2e8f0; font-size: .875rem;
    }
    .theme-light .field input, .theme-light .field select, .theme-light .field textarea {
        background:#f8fafc; border-color:#e2e8f0; color:#0f172a;
    }
    .field input:focus, .field select:focus, .field textarea:focus {
        outline:none; border-color:#6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.2);
    }
</style>

<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-bold text-white mb-1">
                <i class="fas fa-briefcase text-indigo-400 mr-3"></i>Gestión de Vacantes
            </h1>
            <p class="text-slate-200 text-sm">Publica, edita y promociona ofertas de empleo. Genera descripciones con IA.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="index.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> HR</a>
            <a href="recruitment.php" class="btn-secondary"><i class="fas fa-users"></i> Solicitudes</a>
            <button class="btn-primary" onclick="openJobModal()">
                <i class="fas fa-plus-circle"></i> Nueva Vacante
            </button>
        </div>
    </div>

    <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="status-banner success mb-4"><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_message'])): ?>
        <div class="status-banner error mb-4"><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></div>
    <?php endif; ?>

    <!-- AI banner / Public links -->
    <div class="ai-banner-card mb-6 flex flex-col lg:flex-row lg:items-center gap-4">
        <div class="flex-1">
            <div class="text-xs font-semibold uppercase tracking-widest text-purple-200">
                <i class="fas fa-wand-magic-sparkles"></i> Asistente IA
            </div>
            <h3 class="text-xl font-bold mt-1 text-white">Crea descripciones de vacantes en segundos</h3>
            <p class="text-sm text-slate-200 mt-1">Solo dale el título y el departamento — Claude redactará descripción, responsabilidades y requisitos por ti.</p>
        </div>
        <div class="flex flex-wrap gap-2 lg:justify-end">
            <button class="ai-button" onclick="openJobModal(true)">
                <i class="fas fa-wand-magic-sparkles"></i> Generar con IA
            </button>
            <button class="btn-secondary" onclick="navigator.clipboard.writeText('<?php echo $base_url; ?>/careers.php'); this.innerHTML='<i class=\'fas fa-check\'></i> Copiado'; setTimeout(()=>this.innerHTML='<i class=\'fas fa-link\'></i> Copiar URL pública',1800);">
                <i class="fas fa-link"></i> Copiar URL pública
            </button>
        </div>
    </div>

    <!-- Filters / Search -->
    <div class="glass-card mb-6 flex flex-col md:flex-row gap-4 md:items-center md:justify-between">
        <form method="GET" class="flex flex-1 gap-2">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="Buscar título, departamento o ubicación..." class="form-input flex-1">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
            <button class="btn-primary"><i class="fas fa-search"></i></button>
        </form>
        <div class="flex flex-wrap gap-2">
            <?php
            $tabs = [
                'all'      => ['Todas',     $counts['all']],
                'active'   => ['Activas',   $counts['active']],
                'inactive' => ['Inactivas', $counts['inactive']],
                'closed'   => ['Cerradas',  $counts['closed']],
            ];
            foreach ($tabs as $key => [$label, $cnt]):
                $isActive = $status_filter === $key;
                $url = '?' . http_build_query(array_filter(['status' => $key, 'search' => $search ?: null]));
            ?>
                <a href="<?php echo htmlspecialchars($url); ?>" class="filter-tab <?php echo $isActive ? 'active' : 'idle'; ?>">
                    <?php echo $label; ?> <span class="opacity-70">·</span> <?php echo $cnt; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($job_postings)): ?>
        <div class="glass-card text-center py-12">
            <i class="fas fa-briefcase text-slate-500 text-5xl mb-3"></i>
            <h3 class="text-xl font-semibold text-white mb-1">No hay vacantes</h3>
            <p class="text-slate-300 mb-4">Crea tu primera vacante con la ayuda de la IA.</p>
            <button class="ai-button" onclick="openJobModal(true)"><i class="fas fa-wand-magic-sparkles"></i> Crear con IA</button>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            <?php foreach ($job_postings as $job):
                $statusLabel = ['active' => 'Activa', 'inactive' => 'Inactiva', 'closed' => 'Cerrada'][$job['status']] ?? $job['status'];
                $statusBg    = ['active' => '#10b981', 'inactive' => '#6b7280', 'closed' => '#ef4444'][$job['status']] ?? '#6b7280';
                $publicUrl   = $base_url . '/careers.php?job=' . $job['id'];
            ?>
            <div class="job-tile">
                <div class="job-tile-banner">
                    <?php if (!empty($job['banner_url'])): ?>
                        <img src="<?php echo htmlspecialchars($job['banner_url']); ?>" alt="">
                    <?php else: ?>
                        <div class="absolute -right-6 -bottom-6 opacity-20"><i class="fas fa-briefcase text-white" style="font-size:140px"></i></div>
                    <?php endif; ?>
                    <div class="absolute top-3 left-3 z-10 flex gap-1.5">
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full text-white" style="background:<?php echo $statusBg; ?>"><?php echo $statusLabel; ?></span>
                        <?php if ($job['ai_generated']): ?>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full text-white bg-purple-600/90"><i class="fas fa-wand-magic-sparkles"></i> IA</span>
                        <?php endif; ?>
                    </div>
                    <div class="meta">
                        <h3 class="font-bold text-base leading-tight line-clamp-2"><?php echo htmlspecialchars($job['title']); ?></h3>
                        <div class="flex flex-wrap gap-2 text-[11px] mt-1 opacity-90">
                            <?php if (!empty($job['department'])): ?><span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($job['department']); ?></span><?php endif; ?>
                            <?php if (!empty($job['location'])): ?><span><i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars($job['location']); ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="p-4 flex-1 flex flex-col">
                    <p class="text-sm text-slate-200 line-clamp-3 mb-3 leading-relaxed">
                        <?php echo htmlspecialchars(substr(trim((string) $job['description']), 0, 180)); ?>...
                    </p>

                    <div class="grid grid-cols-3 gap-2 mb-4 text-center">
                        <div class="px-2 py-2.5 rounded-lg border border-indigo-500/30" style="background:rgba(99,102,241,0.18)">
                            <div class="text-xl font-extrabold text-white"><?php echo (int) $job['application_count']; ?></div>
                            <div class="text-[10px] font-semibold uppercase text-indigo-200 tracking-wider mt-0.5">Solicitudes</div>
                        </div>
                        <div class="px-2 py-2.5 rounded-lg border border-amber-500/30" style="background:rgba(245,158,11,0.18)">
                            <div class="text-xl font-extrabold text-amber-300"><?php echo (int) $job['new_applications']; ?></div>
                            <div class="text-[10px] font-semibold uppercase text-amber-200 tracking-wider mt-0.5">Nuevas</div>
                        </div>
                        <div class="px-2 py-2.5 rounded-lg border border-emerald-500/30" style="background:rgba(16,185,129,0.18)">
                            <div class="text-xl font-extrabold text-emerald-300"><?php echo (int) $job['shortlisted_count']; ?></div>
                            <div class="text-[10px] font-semibold uppercase text-emerald-200 tracking-wider mt-0.5">Shortlist</div>
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5 text-xs text-slate-200 mb-3 flex-wrap">
                        <span class="inline-flex items-center gap-1"><i class="fas fa-calendar text-slate-400"></i> Publicada <strong class="text-white"><?php echo date('d/m/Y', strtotime($job['posted_date'])); ?></strong></span>
                        <?php if ($job['closing_date']): ?>
                            <span class="text-slate-500">·</span>
                            <span class="inline-flex items-center gap-1"><i class="fas fa-calendar-times text-slate-400"></i> Cierre <strong class="text-white"><?php echo date('d/m/Y', strtotime($job['closing_date'])); ?></strong></span>
                        <?php endif; ?>
                    </div>

                    <div class="mt-auto grid grid-cols-2 gap-2">
                        <a href="recruitment.php?job=<?php echo $job['id']; ?>" class="btn-primary w-full text-center text-sm">
                            <i class="fas fa-eye"></i> Solicitudes
                        </a>
                        <a href="edit_job_posting.php?id=<?php echo $job['id']; ?>" class="btn-secondary w-full text-center text-sm">
                            <i class="fas fa-pen"></i> Editar
                        </a>
                        <button onclick="copyToClipboard('<?php echo htmlspecialchars($publicUrl); ?>', this)" class="btn-secondary w-full text-center text-sm col-span-1">
                            <i class="fas fa-link"></i> Copiar URL
                        </button>
                        <?php if ($job['status'] === 'active'): ?>
                            <a href="toggle_job_status.php?id=<?php echo $job['id']; ?>&status=inactive" class="btn-warning w-full text-center text-sm" onclick="return confirm('¿Desactivar esta vacante?')">
                                <i class="fas fa-pause-circle"></i> Pausar
                            </a>
                        <?php else: ?>
                            <a href="toggle_job_status.php?id=<?php echo $job['id']; ?>&status=active" class="btn-success w-full text-center text-sm">
                                <i class="fas fa-play-circle"></i> Activar
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Job Modal (create) -->
<div id="addJobModal" class="modal">
    <div class="modal-overlay" onclick="closeJobModal()"></div>
    <div class="modal-panel">
        <div class="modal-card">
            <form action="save_job_posting.php" method="POST" enctype="multipart/form-data" class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h2 class="text-xl font-bold">Nueva Vacante</h2>
                        <p class="text-sm text-slate-300">Define la vacante o usa la IA para autogenerar el contenido.</p>
                    </div>
                    <button type="button" class="text-slate-300 hover:text-white" onclick="closeJobModal()"><i class="fas fa-xmark text-xl"></i></button>
                </div>

                <!-- AI helper -->
                <div class="ai-glow rounded-xl p-3 mb-4 flex flex-wrap gap-2 items-center">
                    <i class="fas fa-wand-magic-sparkles text-purple-400 text-lg"></i>
                    <span class="text-sm flex-1 min-w-[180px]">
                        <strong>Generador IA</strong> — escribe título y departamento, opcionalmente notas, y deja que Claude redacte.
                    </span>
                    <input type="text" id="ai_notes" placeholder="Notas opcionales (skills, idiomas, contexto...)"
                           class="px-3 py-2 rounded-lg bg-slate-800/50 border border-slate-600 text-sm flex-1 min-w-[220px]">
                    <button type="button" class="ai-button" id="aiGenerateBtn" onclick="aiGenerateJobDescription()">
                        <i class="fas fa-wand-magic-sparkles"></i> Generar con IA
                    </button>
                </div>

                <div class="field-group cols-2 mb-3">
                    <div class="field"><label>Título del puesto *</label><input type="text" name="title" id="jobTitleInput" required></div>
                    <div class="field"><label>Departamento *</label><input type="text" name="department" id="jobDeptInput" required></div>
                </div>
                <div class="field-group cols-2 mb-3">
                    <div class="field"><label>Ubicación *</label><input type="text" name="location" required placeholder="Ciudad / Remoto"></div>
                    <div class="field"><label>Tipo de empleo *</label>
                        <select name="employment_type" required>
                            <option value="full_time">Tiempo Completo</option>
                            <option value="part_time">Medio Tiempo</option>
                            <option value="contract">Contrato</option>
                            <option value="internship">Pasantía</option>
                        </select>
                    </div>
                </div>

                <div class="field mb-3">
                    <label>Descripción *</label>
                    <textarea name="description" id="jobDescInput" rows="4" required></textarea>
                </div>
                <div class="field-group cols-2 mb-3">
                    <div class="field"><label>Responsabilidades</label><textarea name="responsibilities" id="jobRespInput" rows="4"></textarea></div>
                    <div class="field"><label>Requisitos</label><textarea name="requirements" id="jobReqInput" rows="4"></textarea></div>
                </div>
                <div class="field-group cols-2 mb-3">
                    <div class="field"><label>Rango salarial</label><input type="text" name="salary_range" placeholder="RD$25,000 - RD$35,000"></div>
                    <div class="field"><label>Fecha de cierre</label><input type="date" name="closing_date"></div>
                </div>

                <div class="field mb-4">
                    <label>Banner (opcional, JPG/PNG/WebP, ≤5MB)</label>
                    <input type="file" name="banner_image" accept="image/png, image/jpeg, image/webp">
                </div>

                <input type="hidden" name="ai_generated" id="aiGeneratedFlag" value="0">

                <div class="flex justify-end gap-2 pt-3 border-t border-slate-700">
                    <button type="button" class="btn-secondary" onclick="closeJobModal()">Cancelar</button>
                    <button type="submit" class="btn-primary">Publicar Vacante</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openJobModal(focusAi = false) {
    document.getElementById('addJobModal').classList.add('show');
    document.body.style.overflow = 'hidden';
    if (focusAi) {
        setTimeout(() => document.getElementById('jobTitleInput')?.focus(), 80);
    }
}
function closeJobModal() {
    document.getElementById('addJobModal').classList.remove('show');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeJobModal();
});

function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copiado';
        setTimeout(() => btn.innerHTML = orig, 1800);
    });
}

async function aiGenerateJobDescription() {
    const title = document.getElementById('jobTitleInput').value.trim();
    const department = document.getElementById('jobDeptInput').value.trim();
    const notes = document.getElementById('ai_notes').value.trim();
    if (!title) {
        alert('Escribe primero el título del puesto.');
        return;
    }
    const btn = document.getElementById('aiGenerateBtn');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Generando...';
    try {
        const fd = new FormData();
        fd.append('action', 'generate_job_description');
        fd.append('title', title);
        fd.append('department', department);
        fd.append('notes', notes);
        const r = await fetch('recruitment_ai_endpoints.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            if (data.description)      document.getElementById('jobDescInput').value = data.description;
            if (data.responsibilities) document.getElementById('jobRespInput').value = data.responsibilities;
            if (data.requirements)     document.getElementById('jobReqInput').value  = data.requirements;
            document.getElementById('aiGeneratedFlag').value = '1';
        } else {
            alert('La IA no pudo generar el contenido: ' + (data.error || 'Error desconocido'));
        }
    } catch (err) {
        alert('Error de red: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}
</script>

<?php require_once '../footer.php'; ?>
