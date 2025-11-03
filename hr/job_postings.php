<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_job_postings');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Get all job postings with application counts
$stmt = $pdo->query("
    SELECT j.*, 
           COUNT(a.id) as application_count,
           SUM(CASE WHEN a.status = 'new' THEN 1 ELSE 0 END) as new_applications
    FROM job_postings j
    LEFT JOIN job_applications a ON j.id = a.job_posting_id
    GROUP BY j.id
    ORDER BY j.posted_date DESC
");
$job_postings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get base URL for public links
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . '://' . $host . dirname(dirname($_SERVER['PHP_SELF']));

require_once '../header.php';
?>

<link rel="stylesheet" href="../assets/css/recruitment.css">

<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold text-white mb-2">
                <i class="fas fa-briefcase text-indigo-400 mr-3"></i>
                Gestión de Vacantes
            </h1>
            <p class="text-slate-400">Publica y administra ofertas de empleo</p>
        </div>
        <div class="flex gap-3">
            <a href="index.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
            <a href="recruitment.php" class="btn-secondary">
                <i class="fas fa-users"></i> Ver Solicitudes
            </a>
            <button class="btn-primary" data-bs-toggle="modal" data-bs-target="#addJobModal">
                <i class="fas fa-plus-circle"></i> Nueva Vacante
            </button>
        </div>
    </div>

    <!-- Public Links Section -->
    <div class="glass-card mb-6" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%); border-left: 4px solid #6366f1;">
        <h3 class="text-xl font-semibold text-white mb-4">
            <i class="fas fa-link text-indigo-400 mr-2"></i>
            Enlaces Públicos para Candidatos
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Página de Carreras (Ver Vacantes)</label>
                <div class="flex gap-2">
                    <input type="text" class="form-input flex-1" id="careersUrl" value="<?php echo $base_url; ?>/careers.php" readonly>
                    <button class="btn-primary" onclick="copyToClipboard('careersUrl', event)">
                        <i class="fas fa-copy"></i> Copiar
                    </button>
                </div>
                <p class="text-slate-400 text-sm mt-1">Los candidatos pueden ver todas las vacantes activas y aplicar</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Rastrear Solicitud</label>
                <div class="flex gap-2">
                    <input type="text" class="form-input flex-1" id="trackUrl" value="<?php echo $base_url; ?>/track_application.php" readonly>
                    <button class="btn-primary" onclick="copyToClipboard('trackUrl', event)">
                        <i class="fas fa-copy"></i> Copiar
                    </button>
                </div>
                <p class="text-slate-400 text-sm mt-1">Los candidatos pueden rastrear el estado de su solicitud</p>
            </div>
        </div>
    </div>

    <?php if (empty($job_postings)): ?>
        <div class="glass-card text-center py-12">
            <i class="fas fa-briefcase text-slate-600 text-6xl mb-4"></i>
            <h4 class="text-xl font-semibold text-white mb-2">No hay vacantes publicadas</h4>
            <p class="text-slate-400 mb-4">Crea una nueva vacante para comenzar a recibir solicitudes</p>
            <button class="btn-primary" data-bs-toggle="modal" data-bs-target="#addJobModal">
                <i class="fas fa-plus-circle"></i> Crear Primera Vacante
            </button>
        </div>
    <?php else: ?>
        <div class="space-y-6">
            <?php foreach ($job_postings as $job): ?>
                <div class="glass-card">
                    <div class="flex flex-col lg:flex-row gap-6">
                        <!-- Left Column: Job Info -->
                        <div class="flex-1">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex-1">
                                    <h3 class="text-2xl font-bold text-white mb-2"><?php echo htmlspecialchars($job['title']); ?></h3>
                                    <div class="flex flex-wrap gap-4 text-sm text-slate-400 mb-3">
                                        <span><i class="fas fa-building mr-1"></i> <?php echo htmlspecialchars($job['department']); ?></span>
                                        <span><i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($job['location']); ?></span>
                                        <span><i class="fas fa-calendar mr-1"></i> <?php echo date('d/m/Y', strtotime($job['posted_date'])); ?></span>
                                        <?php if ($job['closing_date']): ?>
                                            <span><i class="fas fa-calendar-times mr-1"></i> Cierre: <?php echo date('d/m/Y', strtotime($job['closing_date'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="status-badge-recruitment status-<?php echo $job['status']; ?> ml-4">
                                    <?php 
                                        $status_labels = ['active' => 'Activa', 'inactive' => 'Inactiva', 'closed' => 'Cerrada'];
                                        echo $status_labels[$job['status']];
                                    ?>
                                </span>
                            </div>
                            
                            <p class="text-slate-300 mb-4 line-clamp-3"><?php echo nl2br(htmlspecialchars(substr($job['description'], 0, 250))); ?>...</p>
                            
                            <div class="flex flex-wrap gap-3">
                                <span class="tag-pill" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                                    <i class="fas fa-users mr-1"></i>
                                    <?php echo $job['application_count']; ?> Solicitudes
                                </span>
                                <?php if ($job['new_applications'] > 0): ?>
                                    <span class="tag-pill" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                                        <i class="fas fa-bell mr-1"></i>
                                        <?php echo $job['new_applications']; ?> Nuevas
                                    </span>
                                <?php endif; ?>
                                <?php if ($job['status'] === 'active'): ?>
                                    <span class="tag-pill" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                        <i class="fas fa-check-circle mr-1"></i>
                                        Recibiendo solicitudes
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Public Link for this job -->
                            <?php if ($job['status'] === 'active'): ?>
                                <div class="mt-4 p-3 rounded-lg" style="background: rgba(99, 102, 241, 0.1);">
                                    <label class="block text-xs font-medium text-slate-400 mb-1">
                                        <i class="fas fa-link mr-1"></i> Enlace público para esta vacante:
                                    </label>
                                    <div class="flex gap-2">
                                        <input type="text" class="form-input flex-1 text-sm" id="jobUrl<?php echo $job['id']; ?>" value="<?php echo $base_url; ?>/careers.php#job-<?php echo $job['id']; ?>" readonly>
                                        <button class="btn-sm btn-primary" onclick="copyToClipboard('jobUrl<?php echo $job['id']; ?>', event)">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Right Column: Actions -->
                        <div class="lg:w-48 flex flex-col gap-2">
                            <a href="recruitment.php?job=<?php echo $job['id']; ?>" class="btn-primary w-full text-center">
                                <i class="fas fa-eye"></i> Ver Solicitudes
                            </a>
                            <a href="edit_job_posting.php?id=<?php echo $job['id']; ?>" class="btn-secondary w-full text-center">
                                <i class="fas fa-edit"></i> Editar
                            </a>
                            <?php if ($job['status'] === 'active'): ?>
                                <a href="toggle_job_status.php?id=<?php echo $job['id']; ?>&status=inactive" class="btn-warning w-full text-center" onclick="return confirm('¿Desactivar esta vacante?')">
                                    <i class="fas fa-pause-circle"></i> Desactivar
                                </a>
                            <?php else: ?>
                                <a href="toggle_job_status.php?id=<?php echo $job['id']; ?>&status=active" class="btn-success w-full text-center">
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

<!-- Add Job Modal -->
<div class="modal fade" id="addJobModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="save_job_posting.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Nueva Vacante</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Título del Puesto *</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Departamento *</label>
                            <input type="text" class="form-control" name="department" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ubicación *</label>
                            <input type="text" class="form-control" name="location" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tipo de Empleo *</label>
                            <select class="form-select" name="employment_type" required>
                                <option value="full_time">Tiempo Completo</option>
                                <option value="part_time">Medio Tiempo</option>
                                <option value="contract">Contrato</option>
                                <option value="internship">Pasantía</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción *</label>
                        <textarea class="form-control" name="description" rows="4" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Requisitos</label>
                        <textarea class="form-control" name="requirements" rows="4"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsabilidades</label>
                        <textarea class="form-control" name="responsibilities" rows="4"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Rango Salarial</label>
                            <input type="text" class="form-control" name="salary_range" placeholder="$25,000 - $35,000 MXN">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Fecha de Cierre</label>
                            <input type="date" class="form-control" name="closing_date">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Publicar Vacante</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function copyToClipboard(elementId, event) {
    const input = document.getElementById(elementId);
    input.select();
    input.setSelectionRange(0, 99999); // For mobile devices
    
    navigator.clipboard.writeText(input.value).then(function() {
        // Show success message
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copiado';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-primary');
        
        setTimeout(function() {
            btn.innerHTML = originalHTML;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-primary');
        }, 2000);
    }).catch(function(err) {
        alert('Error al copiar: ' + err);
    });
}
</script>

<?php require_once '../footer.php'; ?>
