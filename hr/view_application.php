<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_recruitment', '../unauthorized.php');

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

// Get all other positions this applicant applied to (same application_code)
$stmt = $pdo->prepare("
    SELECT a.id, a.status, j.title as job_title, j.department, j.location, j.employment_type
    FROM job_applications a
    LEFT JOIN job_postings j ON a.job_posting_id = j.id
    WHERE a.application_code = ? AND a.id != ?
    ORDER BY a.applied_date ASC
");
$stmt->execute([$application['application_code'], $application_id]);
$other_applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Parse JSON payload if exists
$formPayload = null;
if (!empty($application['cover_letter'])) {
    $decodedPayload = json_decode($application['cover_letter'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedPayload) && isset($decodedPayload['form_version'])) {
        $formPayload = $decodedPayload;
    }
}

// Helper function to get value from JSON or field
$getValue = function ($fieldValue, $jsonPath) use ($formPayload) {
    if (!empty($fieldValue)) {
        return $fieldValue;
    }
    if ($formPayload && !empty($jsonPath)) {
        $keys = explode('.', $jsonPath);
        $value = $formPayload;
        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return '';
            }
        }
        return $value;
    }
    return '';
};



$displayName = trim(($application['first_name'] ?? '') . ' ' . ($application['last_name'] ?? ''));
if ($displayName === '' && $formPayload) {
    $displayName = trim(($formPayload['nombres'] ?? '') . ' ' . ($formPayload['apellido_paterno'] ?? '') . ' ' . ($formPayload['apellido_materno'] ?? ''));
}
if ($displayName === '') {
    $displayName = $application['candidate_name'] ?? 'N/A';
}
$displayPhone = !empty($application['phone']) ? $application['phone'] : ($formPayload['telefono'] ?? 'N/A');
$displayEmail = !empty($application['email']) && $application['email'] !== 'sin-correo@evallish.local' ? $application['email'] : 'sin-correo@evallish.local';
$displayEducation = !empty($application['education_level']) ? $application['education_level'] : (
    $formPayload && !empty($formPayload['educacion']['nivel'])
    ? (is_array($formPayload['educacion']['nivel']) ? implode(', ', $formPayload['educacion']['nivel']) : $formPayload['educacion']['nivel'])
    : 'N/A'
);
$displayYears = !empty($application['years_of_experience']) ? $application['years_of_experience'] : (
    $formPayload && !empty($formPayload['experiencias'][0]['tiempo'])
    ? $formPayload['experiencias'][0]['tiempo']
    : ($application['recent_years'] ?? '')
);
$displayYears = $displayYears !== '' ? $displayYears : 'N/A';
$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$status_labels = [
    'new' => 'Nueva',
    'reviewing' => 'En Revisi�n',
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

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/recruitment.css">

<style>
    /* Ensure modals are hidden by default */
    .modal {
        display: none !important;
    }

    .modal.show {
        display: block !important;
    }

    .modal-backdrop {
        background-color: rgba(0, 0, 0, 0.5);
    }
</style>

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
                        <?php echo htmlspecialchars($displayName); ?>
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
            <!-- Other Applied Positions -->
            <?php if (!empty($other_applications)): ?>
                    <div class="glass-card">
                        <h3 class="text-xl font-semibold text-white mb-4 flex items-center gap-2">
                            <i class="fas fa-briefcase text-indigo-400"></i>
                            Otras Vacantes Aplicadas (<?php echo count($other_applications); ?>)
                        </h3>
                        <div class="bg-indigo-50/10 border border-indigo-500/30 rounded-lg p-4 mb-4">
                            <p class="text-sm text-indigo-300">
                                <i class="fas fa-info-circle mr-2"></i>
                                Este candidato aplic� a m�ltiples vacantes con el mismo CV usando el c�digo:
                                <strong><?php echo htmlspecialchars($application['application_code']); ?></strong>
                            </p>
                        </div>
                        <div class="space-y-3">
                            <?php
                            $employment_types = [
                                'full_time' => 'Tiempo Completo',
                                'part_time' => 'Medio Tiempo',
                                'contract' => 'Contrato',
                                'internship' => 'Pasant�a'
                            ];
                            foreach ($other_applications as $other_app):
                                ?>
                                    <div
                                        class="bg-slate-800/50 rounded-lg p-4 border border-slate-700 hover:border-indigo-500/50 transition-colors">
                                        <div class="flex justify-between items-start gap-4">
                                            <div class="flex-1">
                                                <h4 class="font-semibold text-white mb-2 flex items-center gap-2">
                                                    <i class="fas fa-briefcase text-indigo-400 text-sm"></i>
                                                    <?php echo htmlspecialchars($other_app['job_title']); ?>
                                                </h4>
                                                <div class="flex flex-wrap gap-3 text-sm text-slate-400">
                                                    <span class="flex items-center gap-1">
                                                        <i class="fas fa-building text-xs"></i>
                                                        <?php echo htmlspecialchars($other_app['department']); ?>
                                                    </span>
                                                    <span class="flex items-center gap-1">
                                                        <i class="fas fa-map-marker-alt text-xs"></i>
                                                        <?php echo htmlspecialchars($other_app['location']); ?>
                                                    </span>
                                                    <span class="flex items-center gap-1">
                                                        <i class="fas fa-clock text-xs"></i>
                                                        <?php echo $employment_types[$other_app['employment_type']] ?? $other_app['employment_type']; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="flex flex-col items-end gap-2">
                                                <span class="status-badge-recruitment status-<?php echo $other_app['status']; ?>">
                                                    <?php echo $status_labels[$other_app['status']]; ?>
                                                </span>
                                                <a href="view_application.php?id=<?php echo $other_app['id']; ?>"
                                                    class="text-indigo-400 hover:text-indigo-300 text-sm flex items-center gap-1">
                                                    <span>Ver detalles</span>
                                                    <i class="fas fa-arrow-right text-xs"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
            <?php endif; ?>

            <!-- Personal Information -->
            <div class="glass-card">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center gap-2">
                    <i class="fas fa-user text-indigo-400"></i>
                    Informacion Personal
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm text-slate-400">Email</label>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($displayEmail); ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Telefono</label>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($displayPhone); ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Nivel de Educacion</label>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($displayEducation); ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Anios de Experiencia</label>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($displayYears); ?></p>
                    </div>
                    <?php
                    $currentPosition = $application['current_position'];
                    if (empty($currentPosition) && $formPayload && !empty($formPayload['experiencias'][0]['cargo'])) {
                        $currentPosition = $formPayload['experiencias'][0]['cargo'];
                    }
                    if (!empty($currentPosition)):
                        ?>
                            <div>
                                <label class="text-sm text-slate-400">Puesto Actual</label>
                                <p class="text-white font-medium"><?php echo htmlspecialchars($currentPosition); ?></p>
                            </div>
                    <?php endif; ?>
                    <?php
                    $currentCompany = $application['current_company'];
                    if (empty($currentCompany) && $formPayload && !empty($formPayload['experiencias'][0]['empresa'])) {
                        $currentCompany = $formPayload['experiencias'][0]['empresa'];
                    }
                    if (!empty($currentCompany)):
                        ?>
                            <div>
                                <label class="text-sm text-slate-400">Empresa Actual</label>
                                <p class="text-white font-medium"><?php echo htmlspecialchars($currentCompany); ?></p>
                            </div>
                    <?php endif; ?>
                </div>
                <?php if ($application['cv_path']): ?>
                        <div class="mt-4 flex items-center gap-3">
                            <a href="../<?php echo htmlspecialchars($application['cv_path']); ?>" target="_blank"
                                class="btn-success inline-flex items-center gap-2">
                                <i class="fas fa-download"></i>
                                Descargar CV
                            </a>
                            <?php if (!empty($application['cv_filename'])): ?>
                                    <span class="text-slate-400 text-sm">
                                        <i class="fas fa-file-alt mr-1"></i>
                                        <?php echo htmlspecialchars($application['cv_filename']); ?>
                                    </span>
                            <?php endif; ?>
                        </div>
                <?php endif; ?>
            </div>

            <!-- Resultados de la evaluacion -->
            <div class="glass-card">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center gap-2">
                    <i class="fas fa-clipboard-check text-indigo-400"></i>
                    Resultados de la evaluacion
                </h3>
                <?php
                $evalLabels = [
                    'acceptable' => 'Aceptable',
                    'rejected' => 'Rechazado',
                    'consideration' => 'En consideracion',
                    'interview' => 'Citado a entrevista'
                ];
                $evalResult = $application['evaluation_result'] ?? '';
                $evalDatetime = $application['evaluation_datetime'] ?? '';
                $evalComments = $application['evaluation_comments'] ?? '';
                $evalInterviewer = $application['evaluation_interviewer'] ?? '';
                $evalInterviewDate = $application['evaluation_interview_date'] ?? '';
                ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-white">
                    <div>
                        <label class="text-sm text-slate-400">Resultado</label>
                        <p class="font-medium">
                            <?php echo $evalResult ? ($evalLabels[$evalResult] ?? $evalResult) : 'No registrado'; ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Fecha/Hora de evaluacion</label>
                        <p class="font-medium">
                            <?php echo $evalDatetime ? date('d/m/Y H:i', strtotime($evalDatetime)) : 'No registrado'; ?>
                        </p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Comentarios</label>
                        <p class="font-medium whitespace-pre-line">
                            <?php echo $evalComments ? htmlspecialchars($evalComments) : 'No registrado'; ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Entrevistador</label>
                        <p class="font-medium">
                            <?php echo $evalInterviewer ? htmlspecialchars($evalInterviewer) : 'No registrado'; ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Fecha</label>
                        <p class="font-medium">
                            <?php echo $evalInterviewDate ? date('d/m/Y', strtotime($evalInterviewDate)) : 'No registrado'; ?>
                        </p>
                    </div>
                </div>
            </div>

            <?php if (!empty($formPayload)): ?>
                    <div class="glass-card">
                        <h3 class="text-xl font-semibold text-white mb-4 flex items-center gap-2">
                            <i class="fas fa-file-alt text-indigo-400"></i>
                            Solicitud de Empleo
                        </h3>
                        <?php
                        $payload = $formPayload;
                        $value = function ($key, $default = '') use ($payload) {
                            $keys = explode('.', $key);
                            $current = $payload;
                            foreach ($keys as $k) {
                                if (is_array($current) && isset($current[$k])) {
                                    $current = $current[$k];
                                } else {
                                    return $default;
                                }
                            }
                            return $current;
                        };
                        $display = function ($val) {
                            return ($val !== '' && $val !== null) ? $val : 'N/A';
                        };
                        $yesNo = function ($val) {
                            $v = strtoupper(trim((string) $val));
                            if ($v == 'SI' || $v == 'YES' || $v == '1') {
                                return 'SI';
                            }
                            if ($v == 'NO' || $v == '0') {
                                return 'NO';
                            }
                            return $val;
                        };
                        ?>
                        <div class="space-y-6 text-white">
                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Datos personales</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm text-slate-400">Puesto aplicado</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('puesto_aplicado'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Serie</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('serie'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Cedula</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('cedula'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Telefono</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('telefono'))); ?></p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="text-sm text-slate-400">Direccion</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('direccion'))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Apellido paterno</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('apellido_paterno'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Apellido materno</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('apellido_materno'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Nombre(s)</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('nombres'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Apodo</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('apodo'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Fecha de nacimiento</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('fecha_nacimiento'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Edad</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('edad'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Lugar de nacimiento</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('lugar_nacimiento'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Pais de nacimiento</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('pais_nacimiento'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Nacionalidad</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('nacionalidad'))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Sexo</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('sexo'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Estado civil</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('estado_civil'))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Tipo de sangre</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('tipo_sangre'))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Estatura</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('estatura'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Peso</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('peso'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Con quien vive</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('vive_con'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Personas dependen</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('personas_dependen'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Tiene hijos</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('tiene_hijos')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Edad de hijos</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('edad_hijos'))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Casa propia</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('casa_propia')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Personas vive</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('personas_vive'))); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Disponibilidad de horario</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm text-slate-400">Turno rotativo</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('disponibilidad.turno_rotativo')))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Lunes a viernes</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('disponibilidad.lunes_viernes')))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Otra disponibilidad</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('disponibilidad.otro')))); ?></p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="text-sm text-slate-400">Detalle otro</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('disponibilidad.otro_texto'))); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Modalidad de trabajo solicitada</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm text-slate-400">Presencial</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('modalidad.presencial')))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Hibrida</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('modalidad.hibrida')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Remota</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('modalidad.remota')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Otra modalidad</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('modalidad.otro')))); ?></p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="text-sm text-slate-400">Detalle otra modalidad</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('modalidad.otro_texto'))); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Transporte</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm text-slate-400">Carro publico</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('transporte.carro_publico')))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Motoconcho</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('transporte.motoconcho')))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">A pie</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('transporte.a_pie')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Otro</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('transporte.otro')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Detalle otro</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('transporte.otro_texto'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Detalles traslado</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('transporte.detalles'))); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Ultimo nivel academico</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <?php
                                        $nivelesEducacion = $value('educacion.nivel', []);
                                        if (!is_array($nivelesEducacion)) $nivelesEducacion = [];
                                        $hasPrimaria = false; $hasBachillerato = false; $hasEstudiante = false;
                                        $hasTecnico = false; $hasCarrera = false; $hasPostgrado = false;
                                        foreach ($nivelesEducacion as $niv) {
                                            $nivLower = strtolower($niv);
                                            if (strpos($nivLower, 'primaria') !== false) $hasPrimaria = true;
                                            if (strpos($nivLower, 'bachillerato') !== false) $hasBachillerato = true;
                                            if (strpos($nivLower, 'estudiante') !== false) $hasEstudiante = true;
                                            if (strpos($nivLower, 'tecnico') !== false || strpos($nivLower, 'técnico') !== false) $hasTecnico = true;
                                            if (strpos($nivLower, 'carrera') !== false) $hasCarrera = true;
                                            if (strpos($nivLower, 'postgrado') !== false || strpos($nivLower, 'maestria') !== false) $hasPostgrado = true;
                                        }
                                    ?>
                                    <div>
                                        <label class="text-sm text-slate-400">Primaria</label>
                                        <p class="font-medium"><?php echo $hasPrimaria ? 'SI' : 'NO'; ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Bachillerato</label>
                                        <p class="font-medium"><?php echo $hasBachillerato ? 'SI' : 'NO'; ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Estudiante universitario</label>
                                        <p class="font-medium"><?php echo $hasEstudiante ? 'SI' : 'NO'; ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Tecnico</label>
                                        <p class="font-medium"><?php echo $hasTecnico ? 'SI' : 'NO'; ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Tecnico detalle</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('educacion.nivel_tecnico_detalle'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Carrera completa</label>
                                        <p class="font-medium"><?php echo $hasCarrera ? 'SI' : 'NO'; ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Carrera detalle</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('educacion.nivel_carrera_detalle'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Postgrado</label>
                                        <p class="font-medium"><?php echo $hasPostgrado ? 'SI' : 'NO'; ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Postgrado detalle</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('educacion.nivel_postgrado_detalle'))); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Estudios actuales</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm text-slate-400">Estudia actualmente</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('educacion.estudia_actualmente')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Que estudia</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('educacion.que_estudia'))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Donde estudia</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('educacion.donde_estudia'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Horario de clases</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('educacion.horario_clases'))); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Otros cursos</h4>
                                <div class="space-y-2">
                                    <?php
                                    $otrosCursos = $value('educacion.otros_cursos', []);
                                    $mostrarCursos = false;
                                    if (is_array($otrosCursos)) {
                                        foreach ($otrosCursos as $curso) {
                                            if (!empty($curso['curso']) || !empty($curso['institucion']) || !empty($curso['fecha'])) {
                                                $mostrarCursos = true;
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <?php if ($mostrarCursos): ?>
                                            <?php foreach ($otrosCursos as $curso): ?>
                                                    <?php if (empty($curso['curso']) && empty($curso['institucion']) && empty($curso['fecha']))
                                                        continue; ?>
                                                    <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-3">
                                                        <p class="font-medium"><?php echo htmlspecialchars($display($curso['curso'] ?? '')); ?>
                                                        </p>
                                                        <p class="text-sm text-slate-400">
                                                            <?php echo htmlspecialchars($display($curso['institucion'] ?? '')); ?></p>
                                                        <p class="text-sm text-slate-400">
                                                            <?php echo htmlspecialchars($display($curso['fecha'] ?? '')); ?></p>
                                                    </div>
                                            <?php endforeach; ?>
                                    <?php else: ?>
                                            <p class="text-slate-400">N/A</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Idiomas</h4>
                                <div class="space-y-2">
                                    <?php
                                    $idiomas = $payload['idiomas'] ?? [];
                                    $mostrarIdiomas = false;
                                    if (is_array($idiomas)) {
                                        foreach ($idiomas as $idioma) {
                                            if (!empty($idioma['idioma']) || !empty($idioma['habla']) || !empty($idioma['lee']) || !empty($idioma['escribe'])) {
                                                $mostrarIdiomas = true;
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <?php if ($mostrarIdiomas): ?>
                                            <?php foreach ($idiomas as $idioma): ?>
                                                    <?php if (empty($idioma['idioma']) && empty($idioma['habla']) && empty($idioma['lee']) && empty($idioma['escribe']))
                                                        continue; ?>
                                                    <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-3">
                                                        <p class="font-medium">
                                                            <?php echo htmlspecialchars($display($idioma['idioma'] ?? '')); ?></p>
                                                        <p class="text-sm text-slate-400">Habla:
                                                            <?php echo htmlspecialchars($display($idioma['habla'] ?? '')); ?> | Lee:
                                                            <?php echo htmlspecialchars($display($idioma['lee'] ?? '')); ?> | Escribe:
                                                            <?php echo htmlspecialchars($display($idioma['escribe'] ?? '')); ?></p>
                                                    </div>
                                            <?php endforeach; ?>
                                    <?php else: ?>
                                            <p class="text-slate-400">N/A</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Experiencias laborales</h4>
                                <div class="space-y-4">
                                    <?php
                                    $experiencias = [
                                        [
                                            'empresa' => $value('experiencias.0.empresa'),
                                            'superior' => $value('experiencias.0.superior'),
                                            'tiempo' => $value('experiencias.0.tiempo'),
                                            'telefono' => $value('experiencias.0.telefono'),
                                            'cargo' => $value('experiencias.0.cargo'),
                                            'sueldo' => $value('experiencias.0.sueldo'),
                                            'tareas' => $value('experiencias.0.tareas'),
                                            'razon' => $value('experiencias.0.razon_salida'),
                                        ],
                                        [
                                            'empresa' => $value('experiencias.1.empresa'),
                                            'superior' => $value('experiencias.1.superior'),
                                            'tiempo' => $value('experiencias.1.tiempo'),
                                            'telefono' => $value('experiencias.1.telefono'),
                                            'cargo' => $value('experiencias.1.cargo'),
                                            'sueldo' => $value('experiencias.1.sueldo'),
                                            'tareas' => $value('experiencias.1.tareas'),
                                            'razon' => $value('experiencias.1.razon_salida'),
                                        ]
                                    ];
                                    ?>
                                    <?php foreach ($experiencias as $idx => $exp): ?>
                                            <?php if (empty($exp['empresa']) && empty($exp['cargo']) && empty($exp['superior']) && empty($exp['tiempo']))
                                                continue; ?>
                                            <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                                                <h5 class="font-semibold text-white mb-2">Experiencia <?php echo $idx + 1; ?></h5>
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                    <div>
                                                        <label class="text-sm text-slate-400">Empresa</label>
                                                        <p class="font-medium">
                                                            <?php echo htmlspecialchars($display($exp['empresa'])); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="text-sm text-slate-400">Cargo</label>
                                                        <p class="font-medium"><?php echo htmlspecialchars($display($exp['cargo'])); ?>
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <label class="text-sm text-slate-400">Superior inmediato</label>
                                                        <p class="font-medium">
                                                            <?php echo htmlspecialchars($display($exp['superior'])); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="text-sm text-slate-400">Tiempo trabajado</label>
                                                        <p class="font-medium"><?php echo htmlspecialchars($display($exp['tiempo'])); ?>
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <label class="text-sm text-slate-400">Telefono</label>
                                                        <p class="font-medium">
                                                            <?php echo htmlspecialchars($display($exp['telefono'])); ?></p>
                                                    </div>
                                                    <div>
                                                        <label class="text-sm text-slate-400">Sueldo</label>
                                                        <p class="font-medium"><?php echo htmlspecialchars($display($exp['sueldo'])); ?>
                                                        </p>
                                                    </div>
                                                    <div class="md:col-span-2">
                                                        <label class="text-sm text-slate-400">Tareas principales</label>
                                                        <p class="font-medium whitespace-pre-line">
                                                            <?php echo htmlspecialchars($display($exp['tareas'])); ?></p>
                                                    </div>
                                                    <div class="md:col-span-2">
                                                        <label class="text-sm text-slate-400">Razon de salida</label>
                                                        <p class="font-medium whitespace-pre-line">
                                                            <?php echo htmlspecialchars($display($exp['razon'])); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Informacion adicional</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm text-slate-400">Mayor logro</label>
                                        <p class="font-medium"><?php echo htmlspecialchars($display($value('adicional.mayor_logro'))); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Expectativas salariales</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('adicional.expectativas_salariales'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Incapacidad</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('adicional.incapacidad')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Incapacidad cual</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('adicional.incapacidad_cual'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Horas extras</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('adicional.horas_extras')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Dias fiestas</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('adicional.dias_fiestas')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Conoce empleado</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($yesNo($value('adicional.conoce_empleado')))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Nombre empleado</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('adicional.conoce_empleado_nombre'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Medio vacante</label>
                                        <p class="font-medium">
                                            <?php
                                            $medioVacante = $value('adicional.medio_vacante', []);
                                            if (!is_array($medioVacante)) {
                                                $medioVacante = [$medioVacante];
                                            }
                                            $medioTexto = !empty($medioVacante) ? implode(', ', $medioVacante) : '';
                                            $medioOtro = $value('adicional.medio_vacante_otro');
                                            if (!empty($medioOtro)) {
                                                $medioTexto = $medioTexto !== '' ? $medioTexto . ' / ' . $medioOtro : $medioOtro;
                                            }
                                            ?>
                                            <?php echo htmlspecialchars($display($medioTexto)); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Firma solicitante</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('adicional.firma'))); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-lg font-semibold text-indigo-200 mb-3">Para uso exclusivo del evaluador</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm text-slate-400">Nombre del evaluador</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('evaluador.nombre'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Fecha de evaluacion</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('evaluador.fecha'))); ?></p>
                                    </div>
                                    <div>
                                        <label class="text-sm text-slate-400">Puesto</label>
                                        <p class="font-medium">
                                            <?php echo htmlspecialchars($display($value('evaluador.puesto'))); ?></p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="text-sm text-slate-400">Observaciones entrevista</label>
                                        <p class="font-medium whitespace-pre-line">
                                            <?php echo htmlspecialchars($display($value('evaluador.observaciones'))); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
            <?php endif; ?>


            <!-- Comments -->
            <div class="glass-card">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold text-white flex items-center gap-2">
                        <i class="fas fa-comments text-indigo-400"></i>
                        Comentarios (<?php echo count($comments); ?>)
                    </h3>
                    <button type="button" class="btn-primary" data-bs-toggle="modal" data-bs-target="#addCommentModal">
                        <i class="fas fa-plus"></i>
                        Agregar Comentario
                    </button>
                </div>
                <div class="space-y-3">
                    <?php if (empty($comments)): ?>
                            <p class="text-slate-400 text-center py-4">No hay comentarios a�n</p>
                    <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                                    <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
                                        <div class="flex justify-between items-start mb-2">
                                            <div>
                                                <span
                                                    class="font-semibold text-white"><?php echo htmlspecialchars($comment['user_name']); ?></span>
                                                <?php if ($comment['is_internal']): ?>
                                                        <span
                                                            class="ml-2 text-xs bg-yellow-500/20 text-yellow-400 px-2 py-1 rounded">Interno</span>
                                                <?php endif; ?>
                                            </div>
                                            <span
                                                class="text-sm text-slate-400"><?php echo date('d/m/Y H:i', strtotime($comment['created_at'])); ?></span>
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
                                                <h4 class="font-semibold text-white">
                                                    <?php echo ucfirst(str_replace('_', ' ', $interview['interview_type'])); ?></h4>
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
                                                <span
                                                    class="font-medium"><?php echo $status_labels[$history['old_status']] ?? $history['old_status']; ?></span>
                                                <i class="fas fa-arrow-right mx-2 text-slate-500"></i>
                                                <span class="font-medium"><?php echo $status_labels[$history['new_status']]; ?></span>
                                            </p>
                                            <p class="text-slate-400 text-xs mt-1">
                                                <?php echo htmlspecialchars($history['changed_by_name']); ?>
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
                <h3 class="text-lg font-semibold text-white mb-4">Acciones R�pidas</h3>
                <div class="space-y-2">
                    <button type="button" class="btn-primary w-full justify-center" data-bs-toggle="modal"
                        data-bs-target="#updateStatusModal">
                        <i class="fas fa-exchange-alt"></i>
                        Cambiar Estado
                    </button>
                    <button type="button" class="btn-secondary w-full justify-center" data-bs-toggle="modal"
                        data-bs-target="#scheduleInterviewModal">
                        <i class="fas fa-calendar-plus"></i>
                        Agendar Entrevista
                    </button>
                    <button type="button" class="btn-secondary w-full justify-center" data-bs-toggle="modal"
                        data-bs-target="#addCommentModal">
                        <i class="fas fa-comment"></i>
                        Agregar Comentario
                    </button>
                </div>
            </div>

            <!-- Rating -->
            <?php if ($application['overall_rating']): ?>
                    <div class="glass-card">
                        <h3 class="text-lg font-semibold text-white mb-3">Calificaci�n</h3>
                        <div class="flex gap-1 text-2xl">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i
                                        class="fas fa-star <?php echo $i <= $application['overall_rating'] ? 'text-yellow-400' : 'text-slate-600'; ?>"></i>
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
                            <span
                                class="text-white font-medium"><?php echo htmlspecialchars($application['assigned_to_name']); ?></span>
                        </div>
                    </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php require_once '../footer.php'; ?>

<!-- Modals -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel"
    aria-hidden="true">
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
                        <option value="reviewing">En Revisi�n</option>
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

<div class="modal fade" id="addCommentModal" tabindex="-1" aria-labelledby="addCommentModalLabel" aria-hidden="true">
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
                        <label class="form-check-label" for="isInternal">Comentario interno (no visible para el
                            candidato)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Agregar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="scheduleInterviewModal" tabindex="-1" aria-labelledby="scheduleInterviewModalLabel"
    aria-hidden="true">
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
                            <option value="technical">T�cnica</option>
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
                        <label>Duraci�n (minutos)</label>
                        <input type="number" class="form-control" name="duration_minutes" value="60">
                    </div>
                    <div class="mb-3">
                        <label>Ubicaci�n / Link</label>
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