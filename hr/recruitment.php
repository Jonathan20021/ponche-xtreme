<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('hr_recruitment', '../unauthorized.php');

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$job_filter = $_GET['job'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'applied_date';
$order = $_GET['order'] ?? 'DESC';

// Build query
$query = "
    SELECT a.*, j.title as job_title, j.department, u.full_name as assigned_to_name,
           (SELECT COUNT(*) FROM application_comments WHERE application_id = a.id) as comment_count,
           (SELECT COUNT(*) FROM recruitment_interviews WHERE application_id = a.id AND status = 'scheduled') as interview_count
    FROM job_applications a
    LEFT JOIN job_postings j ON a.job_posting_id = j.id
    LEFT JOIN users u ON a.assigned_to = u.id
    WHERE 1=1
";

$params = [];

if ($status_filter !== 'all') {
    $query .= " AND a.status = :status";
    $params['status'] = $status_filter;
}

if ($job_filter !== 'all') {
    $query .= " AND a.job_posting_id = :job_id";
    $params['job_id'] = $job_filter;
}

if (!empty($search)) {
    $query .= " AND (a.first_name LIKE :search OR a.last_name LIKE :search OR a.email LIKE :search OR a.application_code LIKE :search)";
    $params['search'] = "%$search%";
}

$query .= " ORDER BY a.$sort $order";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get job postings for filter
$jobs = $pdo->query("SELECT id, title FROM job_postings ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM job_applications")->fetchColumn(),
    'new' => $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'new'")->fetchColumn(),
    'reviewing' => $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'reviewing'")->fetchColumn(),
    'shortlisted' => $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'shortlisted'")->fetchColumn(),
    'interview_scheduled' => $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'interview_scheduled'")->fetchColumn(),
    'hired' => $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'hired'")->fetchColumn(),
];

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

require_once '../header.php';
?>

<!-- Bootstrap CSS for modal support -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/recruitment.css">
<style>
    /* Tema del modal adaptable a claro/oscuro */
    .theme-dark .modal-content {
        background: linear-gradient(180deg, #0f172a 0%, #0b1223 100%);
        color: #e2e8f0;
        border: 1px solid rgba(148, 163, 184, 0.2);
    }
    .theme-light .modal-content {
        background: #ffffff;
        color: #0f172a;
    }
    .theme-dark .modal-header,
    .theme-dark .modal-footer {
        border-color: rgba(148, 163, 184, 0.1);
    }
    .theme-light .modal-header,
    .theme-light .modal-footer {
        border-color: #e2e8f0;
    }
    .theme-dark .modal-title { color: #e2e8f0; }
    .theme-light .modal-title { color: #0f172a; }
    .theme-dark .form-label { color: #cbd5e1; }
    .theme-light .form-label { color: #475569; }
    .theme-dark .form-control,
    .theme-dark .form-select {
        background: #0b1223;
        border-color: #1e293b;
        color: #e2e8f0;
    }
    .theme-dark .form-control:focus,
    .theme-dark .form-select:focus {
        box-shadow: 0 0 0 0.15rem rgba(79, 70, 229, 0.3);
        border-color: #6366f1;
    }
    .theme-light .form-control:focus,
    .theme-light .form-select:focus {
        box-shadow: 0 0 0 0.15rem rgba(79, 70, 229, 0.25);
        border-color: #6366f1;
    }
    .theme-dark .form-check-label { color: #e2e8f0; }
    .theme-light .form-check-label { color: #0f172a; }
    .theme-dark .btn-secondary {
        background: #1e293b;
        border: 1px solid #334155;
        color: #e2e8f0;
    }
    .theme-dark .btn-secondary:hover {
        background: #273449;
    }
    .theme-light .btn-secondary {
        background: #e2e8f0;
        border: 1px solid #cbd5e1;
        color: #0f172a;
    }
    .modal-backdrop {
        background-color: rgba(0, 0, 0, 0.6);
    }
</style>

<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold text-white mb-2">
                <i class="fas fa-user-plus text-indigo-400 mr-3"></i>
                Gestión de Reclutamiento
            </h1>
            <p class="text-slate-400">Sistema completo de gestión de candidatos y vacantes</p>
        </div>
        <div class="flex gap-3">
            <a href="index.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
            <a href="job_postings.php" class="btn-primary">
                <i class="fas fa-briefcase"></i> Gestionar Vacantes
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-6 mb-8">
        <div class="glass-card" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-slate-400 text-sm mb-1">Total</p>
                    <h3 class="text-3xl font-bold text-white"><?php echo $stats['total']; ?></h3>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);">
                    <i class="fas fa-users text-white text-xl"></i>
                </div>
            </div>
        </div>

        <div class="glass-card" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(37, 99, 235, 0.1) 100%);">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-slate-400 text-sm mb-1">Nuevas</p>
                    <h3 class="text-3xl font-bold text-white"><?php echo $stats['new']; ?></h3>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                    <i class="fas fa-file-alt text-white text-xl"></i>
                </div>
            </div>
        </div>

        <div class="glass-card" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(217, 119, 6, 0.1) 100%);">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-slate-400 text-sm mb-1">En Revisión</p>
                    <h3 class="text-3xl font-bold text-white"><?php echo $stats['reviewing']; ?></h3>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                    <i class="fas fa-search text-white text-xl"></i>
                </div>
            </div>
        </div>

        <div class="glass-card" style="background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(124, 58, 237, 0.1) 100%);">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-slate-400 text-sm mb-1">Preseleccionados</p>
                    <h3 class="text-3xl font-bold text-white"><?php echo $stats['shortlisted']; ?></h3>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                    <i class="fas fa-star text-white text-xl"></i>
                </div>
            </div>
        </div>

        <div class="glass-card" style="background: linear-gradient(135deg, rgba(236, 72, 153, 0.1) 0%, rgba(219, 39, 119, 0.1) 100%);">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-slate-400 text-sm mb-1">Entrevistas</p>
                    <h3 class="text-3xl font-bold text-white"><?php echo $stats['interview_scheduled']; ?></h3>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);">
                    <i class="fas fa-calendar-check text-white text-xl"></i>
                </div>
            </div>
        </div>

        <div class="glass-card" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(5, 150, 105, 0.1) 100%);">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-slate-400 text-sm mb-1">Contratados</p>
                    <h3 class="text-3xl font-bold text-white"><?php echo $stats['hired']; ?></h3>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                    <i class="fas fa-check-circle text-white text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="glass-card mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Buscar</label>
                <input type="text" class="form-input" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Nombre, email, código...">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Estado</label>
                <select class="form-select" name="status">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Todos</option>
                    <option value="new" <?php echo $status_filter === 'new' ? 'selected' : ''; ?>>Nuevas</option>
                    <option value="reviewing" <?php echo $status_filter === 'reviewing' ? 'selected' : ''; ?>>En Revisión</option>
                    <option value="shortlisted" <?php echo $status_filter === 'shortlisted' ? 'selected' : ''; ?>>Preseleccionados</option>
                    <option value="interview_scheduled" <?php echo $status_filter === 'interview_scheduled' ? 'selected' : ''; ?>>Entrevista Agendada</option>
                    <option value="interviewed" <?php echo $status_filter === 'interviewed' ? 'selected' : ''; ?>>Entrevistados</option>
                    <option value="offer_extended" <?php echo $status_filter === 'offer_extended' ? 'selected' : ''; ?>>Oferta Extendida</option>
                    <option value="hired" <?php echo $status_filter === 'hired' ? 'selected' : ''; ?>>Contratados</option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rechazados</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Vacante</label>
                <select class="form-select" name="job">
                    <option value="all">Todas</option>
                    <?php foreach ($jobs as $job): ?>
                        <option value="<?php echo $job['id']; ?>" <?php echo $job_filter == $job['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($job['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="btn-primary flex-1">
                    <i class="fas fa-filter"></i> Filtrar
                </button>
                <a href="recruitment.php" class="btn-secondary">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Applications Table -->
    <div class="glass-card">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-semibold text-white">
                <i class="fas fa-list mr-2 text-indigo-400"></i>
                Solicitudes (<?php echo count($applications); ?>)
            </h3>
            <button class="btn-success" onclick="exportToExcel()">
                <i class="fas fa-file-excel"></i> Exportar Excel
            </button>
        </div>

        <?php if (empty($applications)): ?>
            <div class="text-center py-12">
                <i class="fas fa-inbox text-slate-600 text-6xl mb-4"></i>
                <h4 class="text-xl font-semibold text-white mb-2">No se encontraron solicitudes</h4>
                <p class="text-slate-400">Intenta ajustar los filtros de búsqueda</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold text-sm cursor-pointer hover:bg-slate-800/50" onclick="sortTable('application_code')">
                                Código <i class="fas fa-sort text-xs ml-1"></i>
                            </th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold text-sm cursor-pointer hover:bg-slate-800/50" onclick="sortTable('first_name')">
                                Candidato <i class="fas fa-sort text-xs ml-1"></i>
                            </th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold text-sm">Vacante</th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold text-sm cursor-pointer hover:bg-slate-800/50" onclick="sortTable('status')">
                                Estado <i class="fas fa-sort text-xs ml-1"></i>
                            </th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold text-sm">Experiencia</th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold text-sm">Calificación</th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold text-sm cursor-pointer hover:bg-slate-800/50" onclick="sortTable('applied_date')">
                                Fecha <i class="fas fa-sort text-xs ml-1"></i>
                            </th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold text-sm">Asignado</th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold text-sm">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app): ?>
                            <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                <td class="py-4 px-4">
                                    <code class="text-indigo-400 text-sm"><?php echo htmlspecialchars($app['application_code']); ?></code>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="font-semibold text-white"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></div>
                                    <div class="text-sm text-slate-400"><?php echo htmlspecialchars($app['email']); ?></div>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="font-medium text-white"><?php echo htmlspecialchars($app['job_title']); ?></div>
                                    <div class="text-sm text-slate-400"><?php echo htmlspecialchars($app['department']); ?></div>
                                </td>
                                <td class="py-4 px-4">
                                    <span class="status-badge-recruitment status-<?php echo $app['status']; ?>">
                                        <?php 
                                            $statuses = [
                                                'new' => 'Nueva',
                                                'reviewing' => 'En Revisión',
                                                'shortlisted' => 'Preseleccionado',
                                                'interview_scheduled' => 'Entrevista',
                                                'interviewed' => 'Entrevistado',
                                                'offer_extended' => 'Oferta',
                                                'hired' => 'Contratado',
                                                'rejected' => 'Rechazado',
                                                'withdrawn' => 'Retirado'
                                            ];
                                            echo $statuses[$app['status']];
                                        ?>
                                    </span>
                                </td>
                                <td class="py-4 px-4 text-slate-300"><?php echo $app['years_of_experience']; ?> años</td>
                                <td class="py-4 px-4">
                                    <?php if ($app['overall_rating']): ?>
                                        <span class="rating-stars">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star<?php echo $i <= $app['overall_rating'] ? '' : '-o'; ?>"></i>
                                            <?php endfor; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-500 text-sm">Sin calificar</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-4 text-slate-300"><?php echo date('d/m/Y', strtotime($app['applied_date'])); ?></td>
                                <td class="py-4 px-4">
                                    <?php if ($app['assigned_to_name']): ?>
                                        <span class="tag-pill" style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);">
                                            <?php echo htmlspecialchars($app['assigned_to_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-500 text-sm">No asignado</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="flex gap-2">
                                        <a href="view_application.php?id=<?php echo $app['id']; ?>" class="btn-action btn-view" title="Ver Detalles">
                                            <i class="fas fa-eye"></i>
                                            <?php if ($app['comment_count'] > 0): ?>
                                                <span class="badge-count"><?php echo $app['comment_count']; ?></span>
                                            <?php endif; ?>
                                        </a>
                                        <button type="button" class="btn-action btn-secondary" title="Evaluar" onclick='openEvaluationModal(<?php echo json_encode([
                                            'id' => $app['id'],
                                            'name' => trim($app['first_name'] . ' ' . $app['last_name']),
                                            'result' => $app['evaluation_result'] ?? '',
                                            'datetime' => $app['evaluation_datetime'] ?? '',
                                            'comments' => $app['evaluation_comments'] ?? '',
                                            'interviewer' => $app['evaluation_interviewer'] ?? '',
                                            'interview_date' => $app['evaluation_interview_date'] ?? ''
                                        ]); ?>)'>
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
    </div>
</div>

<script>
function sortTable(column) {
    const urlParams = new URLSearchParams(window.location.search);
    const currentSort = urlParams.get('sort');
    const currentOrder = urlParams.get('order') || 'DESC';
    
    let newOrder = 'DESC';
    if (currentSort === column && currentOrder === 'DESC') {
        newOrder = 'ASC';
    }
    
    urlParams.set('sort', column);
    urlParams.set('order', newOrder);
    
    window.location.search = urlParams.toString();
}

function exportToExcel() {
    const urlParams = new URLSearchParams(window.location.search);
    window.location.href = 'export_applications.php?' + urlParams.toString();
}

// Evaluation modal logic
function openEvaluationModal(data) {
    document.getElementById('eval_application_id').value = data.id;
    document.getElementById('eval_candidate').innerText = data.name || 'Candidato';
    // Set radios
    const results = ['acceptable','rejected','consideration','interview'];
    results.forEach(val => {
        const radio = document.querySelector(`input[name="evaluation_result"][value="${val}"]`);
        if (radio) {
            radio.checked = (data.result === val);
        }
    });
    document.getElementById('evaluation_datetime').value = data.datetime ? data.datetime.replace(' ', 'T') : '';
    document.getElementById('evaluation_comments').value = data.comments || '';
    document.getElementById('evaluation_interviewer').value = data.interviewer || '';
    document.getElementById('evaluation_interview_date').value = data.interview_date || '';
    const modal = new bootstrap.Modal(document.getElementById('evaluationModal'));
    modal.show();
}

async function submitEvaluation(event) {
    event.preventDefault();
    const form = document.getElementById('evaluationForm');
    const submitBtn = form.querySelector('button[type="submit"]');
    const original = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Guardando...';
    try {
        const response = await fetch('update_evaluation.php', {
            method: 'POST',
            body: new FormData(form)
        });
        const result = await response.json();
        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('evaluationModal')).hide();
            window.location.reload();
        } else {
            alert(result.message || 'No se pudo guardar la evaluación');
        }
    } catch (err) {
        alert('Error al guardar la evaluación');
        console.error(err);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = original;
    }
}
</script>

<!-- Evaluation Modal -->
<div class="modal fade" id="evaluationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="evaluationForm" onsubmit="submitEvaluation(event)">
                <div class="modal-header">
                    <h5 class="modal-title">Resultados de la evaluacion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body space-y-3">
                    <input type="hidden" name="application_id" id="eval_application_id">
                    <div class="mb-2 text-sm text-slate-500">Candidato: <span class="font-semibold text-slate-800" id="eval_candidate"></span></div>
                    <div class="mb-3">
                        <label class="form-label d-block">Resultado</label>
                        <div class="d-flex flex-wrap gap-3">
                            <label class="form-check-label"><input type="radio" name="evaluation_result" value="acceptable" required> Aceptable</label>
                            <label class="form-check-label"><input type="radio" name="evaluation_result" value="rejected" required> Rechazado</label>
                            <label class="form-check-label"><input type="radio" name="evaluation_result" value="consideration" required> En consideracion</label>
                            <label class="form-check-label"><input type="radio" name="evaluation_result" value="interview" required> Citado a entrevista</label>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Fecha/Hora de evaluacion</label>
                            <input type="datetime-local" class="form-control" name="evaluation_datetime" id="evaluation_datetime" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fecha (entrevistador)</label>
                            <input type="date" class="form-control" name="evaluation_interview_date" id="evaluation_interview_date">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comentarios</label>
                        <textarea class="form-control" rows="4" name="evaluation_comments" id="evaluation_comments"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Entrevistador</label>
                        <input type="text" class="form-control" name="evaluation_interviewer" id="evaluation_interviewer">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../footer.php'; ?>

<!-- Bootstrap JS bundle (necesario para modales) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
