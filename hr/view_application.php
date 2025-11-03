<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_recruitment');

$application_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT a.*, j.title as job_title, j.department, j.location, u.full_name as assigned_to_name
    FROM job_applications a
    LEFT JOIN job_postings j ON a.job_posting_id = j.id
    LEFT JOIN users u ON a.assigned_to = u.id
    WHERE a.id = ?
");
$stmt->execute([$application_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    header("Location: recruitment.php");
    exit;
}

$stmt = $pdo->prepare("SELECT c.*, u.full_name as user_name FROM application_comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.application_id = ? ORDER BY c.created_at DESC");
$stmt->execute([$application_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT i.*, u.full_name as created_by_name FROM recruitment_interviews i LEFT JOIN users u ON i.created_by = u.id WHERE i.application_id = ? ORDER BY i.interview_date DESC");
$stmt->execute([$application_id]);
$interviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT sh.*, u.full_name as changed_by_name FROM application_status_history sh LEFT JOIN users u ON sh.changed_by = u.id WHERE sh.application_id = ? ORDER BY sh.changed_at DESC");
$stmt->execute([$application_id]);
$status_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hr_users = $pdo->query("SELECT id, full_name as name FROM users WHERE role IN ('Admin', 'HR') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$status_labels = [
    'new' => 'Nueva',
    'reviewing' => 'En Revisión',
    'shortlisted' => 'Preseleccionado',
    'interview_scheduled' => 'Entrevista Agendada',
    'interviewed' => 'Entrevistado',
    'offer_extended' => 'Oferta Extendida',
    'hired' => 'Contratado',
    'rejected' => 'Rechazado',
    'withdrawn' => 'Retirado'
];

require_once '../header.php';
?>

<link rel="stylesheet" href="../assets/css/recruitment.css">

<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="mb-6">
        <a href="recruitment.php" class="btn-secondary inline-flex items-center gap-2 mb-4">
            <i class="fas fa-arrow-left"></i>
            Volver a Solicitudes
        </a>
        
        <div class="glass-card p-6">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-white mb-2">
                        <?php echo htmlspecialchars($application['first_name'] . ' ' . $application['last_name']); ?>
                    </h1>
                    <div class="flex flex-wrap gap-3 text-slate-300">
                        <span class="flex items-center gap-2">
                            <i class="fas fa-code text-indigo-400"></i>
                            <?php echo htmlspecialchars($application['application_code']); ?>
                        </span>
                        <span class="flex items-center gap-2">
                            <i class="fas fa-briefcase text-indigo-400"></i>
                            <?php echo htmlspecialchars($application['job_title']); ?>
                        </span>
                        <span class="flex items-center gap-2">
                            <i class="fas fa-calendar text-indigo-400"></i>
                            <?php echo date('d/m/Y', strtotime($application['applied_date'])); ?>
                        </span>
                    </div>
                </div>
                <div>
                    <span class="status-badge-recruitment status-<?php echo $application['status']; ?> text-lg">
                        <?php echo $status_labels[$application['status']]; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Personal Information -->
            <div class="glass-card">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center gap-2">
                    <i class="fas fa-user text-indigo-400"></i>
                    Información Personal
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm text-slate-400">Email</label>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($application['email']); ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Teléfono</label>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($application['phone']); ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Nivel de Educación</label>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($application['education_level']); ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Años de Experiencia</label>
                        <p class="text-white font-medium"><?php echo $application['years_of_experience']; ?> años</p>
                    </div>
                    <?php if ($application['current_position']): ?>
                    <div>
                        <label class="text-sm text-slate-400">Puesto Actual</label>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($application['current_position']); ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($application['current_company']): ?>
                    <div>
                        <label class="text-sm text-slate-400">Empresa Actual</label>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($application['current_company']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($application['cv_path']): ?>
                <div class="mt-4">
                    <a href="../<?php echo htmlspecialchars($application['cv_path']); ?>" target="_blank" 
                       class="btn-success inline-flex items-center gap-2">
                        <i class="fas fa-download"></i>
                        Descargar CV
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Comments -->
            <div class="glass-card">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold text-white flex items-center gap-2">
                        <i class="fas fa-comments text-indigo-400"></i>
                        Comentarios (<?php echo count($comments); ?>)
                    </h3>
                    <button class="btn-primary" data-bs-toggle="modal" data-bs-target="#addCommentModal">
                        <i class="fas fa-plus"></i>
                        Agregar Comentario
                    </button>
                </div>
                <div class="space-y-3">
                    <?php if (empty($comments)): ?>
                        <p class="text-slate-400 text-center py-4">No hay comentarios aún</p>
                    <?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                            <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <span class="font-semibold text-white"><?php echo htmlspecialchars($comment['user_name']); ?></span>
                                        <?php if ($comment['is_internal']): ?>
                                            <span class="ml-2 text-xs bg-yellow-500/20 text-yellow-400 px-2 py-1 rounded">Interno</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-sm text-slate-400"><?php echo date('d/m/Y H:i', strtotime($comment['created_at'])); ?></span>
                                </div>
                                <p class="text-slate-300"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Interviews -->
            <?php if (!empty($interviews)): ?>
            <div class="glass-card">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center gap-2">
                    <i class="fas fa-calendar-alt text-indigo-400"></i>
                    Entrevistas (<?php echo count($interviews); ?>)
                </h3>
                <div class="space-y-3">
                    <?php foreach ($interviews as $interview): ?>
                        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h4 class="font-semibold text-white"><?php echo ucfirst(str_replace('_', ' ', $interview['interview_type'])); ?></h4>
                                    <p class="text-slate-400 text-sm mt-1">
                                        <i class="fas fa-calendar mr-1"></i>
                                        <?php echo date('d/m/Y H:i', strtotime($interview['interview_date'])); ?>
                                    </p>
                                    <?php if ($interview['location']): ?>
                                        <p class="text-slate-400 text-sm">
                                            <i class="fas fa-map-marker-alt mr-1"></i>
                                            <?php echo htmlspecialchars($interview['location']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <span class="status-badge-recruitment status-<?php echo $interview['status']; ?>">
                                    <?php echo ucfirst($interview['status']); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Status History -->
            <?php if (!empty($status_history)): ?>
            <div class="glass-card">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center gap-2">
                    <i class="fas fa-history text-indigo-400"></i>
                    Historial de Estados
                </h3>
                <div class="space-y-2">
                    <?php foreach ($status_history as $history): ?>
                        <div class="flex items-start gap-3 text-sm">
                            <div class="w-2 h-2 rounded-full bg-indigo-500 mt-2"></div>
                            <div class="flex-1">
                                <p class="text-white">
                                    <span class="font-medium"><?php echo $status_labels[$history['old_status']] ?? $history['old_status']; ?></span>
                                    <i class="fas fa-arrow-right mx-2 text-slate-500"></i>
                                    <span class="font-medium"><?php echo $status_labels[$history['new_status']]; ?></span>
                                </p>
                                <p class="text-slate-400 text-xs mt-1">
                                    <?php echo htmlspecialchars($history['changed_by_name']); ?> • 
                                    <?php echo date('d/m/Y H:i', strtotime($history['changed_at'])); ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">Acciones Rápidas</h3>
                <div class="space-y-2">
                    <button class="btn-primary w-full justify-center" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                        <i class="fas fa-exchange-alt"></i>
                        Cambiar Estado
                    </button>
                    <button class="btn-secondary w-full justify-center" data-bs-toggle="modal" data-bs-target="#scheduleInterviewModal">
                        <i class="fas fa-calendar-plus"></i>
                        Agendar Entrevista
                    </button>
                    <button class="btn-secondary w-full justify-center" data-bs-toggle="modal" data-bs-target="#addCommentModal">
                        <i class="fas fa-comment"></i>
                        Agregar Comentario
                    </button>
                </div>
            </div>

            <!-- Rating -->
            <?php if ($application['overall_rating']): ?>
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-3">Calificación</h3>
                <div class="flex gap-1 text-2xl">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star <?php echo $i <= $application['overall_rating'] ? 'text-yellow-400' : 'text-slate-600'; ?>"></i>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Assigned To -->
            <?php if ($application['assigned_to_name']): ?>
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-3">Asignado a</h3>
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-full bg-indigo-600 flex items-center justify-center">
                        <i class="fas fa-user text-white"></i>
                    </div>
                    <span class="text-white font-medium"><?php echo htmlspecialchars($application['assigned_to_name']); ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modals -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="update_application_status.php" method="POST">
                <div class="modal-header">
                    <h5>Cambiar Estado</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="application_id" value="<?php echo $application_id; ?>">
                    <select class="form-select mb-3" name="new_status" required>
                        <option value="new">Nueva</option>
                        <option value="reviewing">En Revisión</option>
                        <option value="shortlisted">Preseleccionado</option>
                        <option value="interview_scheduled">Entrevista Agendada</option>
                        <option value="interviewed">Entrevistado</option>
                        <option value="offer_extended">Oferta Extendida</option>
                        <option value="hired">Contratado</option>
                        <option value="rejected">Rechazado</option>
                    </select>
                    <textarea class="form-control" name="notes" placeholder="Notas" rows="3"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Actualizar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addCommentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="add_comment.php" method="POST">
                <div class="modal-header">
                    <h5>Agregar Comentario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="application_id" value="<?php echo $application_id; ?>">
                    <textarea class="form-control mb-3" name="comment" required rows="4"></textarea>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_internal" value="1" id="isInternal">
                        <label class="form-check-label" for="isInternal">Comentario interno (no visible para el candidato)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Agregar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="scheduleInterviewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="schedule_interview.php" method="POST">
                <div class="modal-header">
                    <h5>Agendar Entrevista</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="application_id" value="<?php echo $application_id; ?>">
                    <div class="mb-3">
                        <label>Tipo</label>
                        <select class="form-select" name="interview_type" required>
                            <option value="phone_screening">Llamada de Filtro</option>
                            <option value="technical">Técnica</option>
                            <option value="hr">RRHH</option>
                            <option value="manager">Gerente</option>
                            <option value="final">Final</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Fecha y Hora</label>
                        <input type="datetime-local" class="form-control" name="interview_date" required>
                    </div>
                    <div class="mb-3">
                        <label>Duración (minutos)</label>
                        <input type="number" class="form-control" name="duration_minutes" value="60">
                    </div>
                    <div class="mb-3">
                        <label>Ubicación / Link</label>
                        <input type="text" class="form-control" name="location">
                    </div>
                    <div class="mb-3">
                        <label>Notas</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Agendar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../footer.php'; ?>
