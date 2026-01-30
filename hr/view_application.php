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

$extraData = [
    'cedula' => $application['cedula'] ?? '',
    'telefonos' => $application['phone'] ?? '',
    'sector' => $application['sector_residencia'] ?? '',
    'aplicacion_previa' => $application['applied_before'] ?? '',
    'aplicacion_previa_detalles' => $application['applied_before_details'] ?? '',
    'fuente' => $application['source'] ?? '',
    'fuente_otro' => $application['source_other'] ?? '',
    'conoce_empresa' => $application['knows_company'] ?? '',
    'motivo_interes' => $application['interest_reason'] ?? '',
    'idioma' => $application['application_language'] ?? '',
    'horario_disponible' => $application['availability_time'] ?? '',
    'preferencia_horario' => $application['availability_preference'] ?? '',
    'horario_entrenamiento' => $application['training_schedule'] ?? '',
    'acepta_rotacion' => $application['agrees_rotating_days'] ?? '',
    'fines_semana' => $application['weekend_holidays'] ?? '',
    'empleado_actual' => $application['currently_employed'] ?? '',
    'empleo_actual_detalle' => $application['current_employment_details'] ?? '',
    'exp_reciente_empresa' => $application['recent_company'] ?? '',
    'exp_reciente_puesto' => $application['recent_role'] ?? '',
    'exp_reciente_anios' => $application['recent_years'] ?? '',
    'exp_reciente_salario' => $application['recent_last_salary'] ?? '',
    'exp_call_center' => $application['has_call_center_experience'] ?? '',
    'call_center_nombre' => $application['call_center_name'] ?? '',
    'call_center_puesto' => $application['call_center_role'] ?? '',
    'call_center_salario' => $application['call_center_salary'] ?? '',
];

$formPayload = null;
if (!empty($application['cover_letter'])) {
    $decodedPayload = json_decode($application['cover_letter'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedPayload) && isset($decodedPayload['form_version'])) {
        $formPayload = $decodedPayload;
    }
}


$displayName = trim(($application['first_name'] ?? '') . ' ' . ($application['last_name'] ?? ''));
if ($displayName === '') {
    $displayName = $application['candidate_name'] ?? 'N/A';
}
$displayPhone = !empty($application['phone']) ? $application['phone'] : 'N/A';
$displayEmail = !empty($application['email']) ? $application['email'] : 'sin-correo@evallish.local';
$displayEducation = !empty($application['education_level']) ? $application['education_level'] : 'N/A';
$displayYears = !empty($application['years_of_experience']) ? $application['years_of_experience'] : ($application['recent_years'] ?? '');
$displayYears = $displayYears !== '' ? $displayYears : 'N/A';
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
                        Este candidato aplicó a múltiples vacantes con el mismo CV usando el código: <strong><?php echo htmlspecialchars($application['application_code']); ?></strong>
                    </p>
                </div>
                <div class="space-y-3">
                    <?php 
                    $employment_types = [
                        'full_time' => 'Tiempo Completo',
                        'part_time' => 'Medio Tiempo',
                        'contract' => 'Contrato',
                        'internship' => 'Pasantía'
                    ];
                    foreach ($other_applications as $other_app): 
                    ?>
                        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700 hover:border-indigo-500/50 transition-colors">
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
                    <?php if (!empty($application['current_position'])): ?>
                    <div>
                        <label class="text-sm text-slate-400">Puesto Actual</label>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($application['current_position']); ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($application['current_company'])): ?>
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
                        <p class="font-medium"><?php echo $evalResult ? ($evalLabels[$evalResult] ?? $evalResult) : 'No registrado'; ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Fecha/Hora de evaluacion</label>
                        <p class="font-medium"><?php echo $evalDatetime ? date('d/m/Y H:i', strtotime($evalDatetime)) : 'No registrado'; ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Comentarios</label>
                        <p class="font-medium whitespace-pre-line"><?php echo $evalComments ? htmlspecialchars($evalComments) : 'No registrado'; ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Entrevistador</label>
                        <p class="font-medium"><?php echo $evalInterviewer ? htmlspecialchars($evalInterviewer) : 'No registrado'; ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400">Fecha</label>
                        <p class="font-medium"><?php echo $evalInterviewDate ? date('d/m/Y', strtotime($evalInterviewDate)) : 'No registrado'; ?></p>
                    </div>
                </div>
            </div>

            <!-- Detalle de Aplicacion -->
            <div class="glass-card">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center gap-2">
                    <i class="fas fa-list text-indigo-400"></i>
                    Detalle de Aplicacion
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-white">
                    <?php
                        $formatYesNo = function($value) {
                            $v = strtoupper(trim((string)$value));
                            if ($v === 'SI' || $v === 'YES' || $v === '1') {
                                return 'SI';
                            }
                            if ($v === 'NO' || $v === '0') {
                                return 'NO';
                            }
                            return $value;
                        };

                        $source = $application['source'] ?? '';
                        if (!empty($application['source_other']) && strtolower($source) === 'otro') {
                            $source = 'Otro: ' . $application['source_other'];
                        }

                        $details = [
                            'Cedula' => $application['cedula'] ?? '',
                            'Telefono(s)' => $displayPhone,
                            'Sector de residencia' => $application['sector_residencia'] ?? '',
                            'Aplicacion previa' => $formatYesNo($application['applied_before'] ?? ''),
                            'Detalle aplicacion previa' => $application['applied_before_details'] ?? '',
                            'Como se entero' => $source,
                            'Conoce la empresa' => $formatYesNo($application['knows_company'] ?? ''),
                            'Motivo de interes' => $application['interest_reason'] ?? '',
                            'Idioma de aplicacion' => $application['application_language'] ?? '',
                            'Horario disponible' => $application['availability_time'] ?? '',
                            'Preferencia de horario' => $application['availability_preference'] ?? '',
                            'Horario de entrenamiento' => $application['training_schedule'] ?? '',
                            'Acepta rotacion de libres' => $formatYesNo($application['agrees_rotating_days'] ?? ''),
                            'Fines de semana/feriados' => $formatYesNo($application['weekend_holidays'] ?? ''),
                            'Empleado actualmente' => $formatYesNo($application['currently_employed'] ?? ''),
                            'Detalle empleo actual' => $application['current_employment_details'] ?? '',
                            'Empresa reciente' => $application['recent_company'] ?? '',
                            'Puesto reciente' => $application['recent_role'] ?? '',
                            'Anios de experiencia reciente' => $application['recent_years'] ?? '',
                            'Ultimo salario reciente' => $application['recent_last_salary'] ?? '',
                            'Experiencia en call center' => $formatYesNo($application['has_call_center_experience'] ?? ''),
                            'Ultimo call center' => $application['call_center_name'] ?? '',
                            'Puesto en call center' => $application['call_center_role'] ?? '',
                            'Salario en call center' => $application['call_center_salary'] ?? '',
                        ];

                        foreach ($details as $label => $value):
                            $display = ($value !== '' && $value !== null) ? $value : 'N/A';
                    ?>
                        <div>
                            <label class="text-sm text-slate-400"><?php echo htmlspecialchars($label); ?></label>
                            <p class="font-medium"><?php echo htmlspecialchars($display); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (!empty($formPayload)): ?>
            <div class="glass-card">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center gap-2">
                    <i class="fas fa-file-alt text-indigo-400"></i>
                    Formulario de Solicitud (Nuevo)
                </h3>
                <?php
                    $payload = $formPayload;
                    $value = function ($key, $default = '') use ($payload) {
                        return $payload[$key] ?? $default;
                    };
                    $display = function ($val) {
                        return ($val !== '' && $val !== null) ? $val : 'N/A';
                    };
                    $yesNo = function ($val) {
                        $v = strtoupper(trim((string)$val));
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
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('puesto_aplicado'))); ?></p>
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
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('direccion'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Apellido paterno</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('apellido_paterno'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Apellido materno</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('apellido_materno'))); ?></p>
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
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('fecha_nacimiento'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Edad</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('edad'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Lugar de nacimiento</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('lugar_nacimiento'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Pais de nacimiento</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('pais_nacimiento'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Nacionalidad</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('nacionalidad'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Sexo</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('sexo'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Estado civil</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('estado_civil'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Tipo de sangre</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('tipo_sangre'))); ?></p>
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
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('personas_dependen'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Tiene hijos</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('tiene_hijos')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Edad de hijos</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('edad_hijos'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Casa propia</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('casa_propia')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Personas vive</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('personas_vive'))); ?></p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-lg font-semibold text-indigo-200 mb-3">Disponibilidad de horario</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-sm text-slate-400">Turno rotativo</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('disponibilidad_turno_rotativo')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Lunes a viernes</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('disponibilidad_lunes_viernes')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Otra disponibilidad</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('disponibilidad_otro')))); ?></p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-sm text-slate-400">Detalle otro</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('disponibilidad_otro_texto'))); ?></p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-lg font-semibold text-indigo-200 mb-3">Modalidad de trabajo solicitada</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-sm text-slate-400">Presencial</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('modalidad_presencial')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Hibrida</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('modalidad_hibrida')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Remota</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('modalidad_remota')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Otra modalidad</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('modalidad_otro')))); ?></p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-sm text-slate-400">Detalle otra modalidad</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('modalidad_otro_texto'))); ?></p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-lg font-semibold text-indigo-200 mb-3">Transporte</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-sm text-slate-400">Carro publico</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('transporte_carro')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Motoconcho</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('transporte_moto')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">A pie</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('transporte_pie')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Otro</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('transporte_otro')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Detalle otro</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('transporte_otro_texto'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Detalles traslado</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('transporte_detalles'))); ?></p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-lg font-semibold text-indigo-200 mb-3">Ultimo nivel academico</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-sm text-slate-400">Primaria</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('nivel_primaria')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Bachillerato</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('nivel_bachillerato')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Estudiante universitario</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('nivel_estudiante')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Tecnico</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('nivel_tecnico')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Tecnico detalle</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('nivel_tecnico_detalle'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Carrera completa</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('nivel_carrera_completa')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Carrera detalle</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('nivel_carrera_detalle'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Postgrado</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('nivel_postgrado')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Postgrado detalle</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('nivel_postgrado_detalle'))); ?></p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-lg font-semibold text-indigo-200 mb-3">Estudios actuales</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-sm text-slate-400">Estudia actualmente</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('estudia_actualmente')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Que estudia</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('que_estudia'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Donde estudia</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('donde_estudia'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Horario de clases</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('horario_clases'))); ?></p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-lg font-semibold text-indigo-200 mb-3">Otros cursos</h4>
                        <div class="space-y-2">
                            <?php
                                $otrosCursos = $payload['otros_cursos'] ?? [];
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
                                    <?php if (empty($curso['curso']) && empty($curso['institucion']) && empty($curso['fecha'])) continue; ?>
                                    <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-3">
                                        <p class="font-medium"><?php echo htmlspecialchars($display($curso['curso'] ?? '')); ?></p>
                                        <p class="text-sm text-slate-400"><?php echo htmlspecialchars($display($curso['institucion'] ?? '')); ?></p>
                                        <p class="text-sm text-slate-400"><?php echo htmlspecialchars($display($curso['fecha'] ?? '')); ?></p>
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
                                    <?php if (empty($idioma['idioma']) && empty($idioma['habla']) && empty($idioma['lee']) && empty($idioma['escribe'])) continue; ?>
                                    <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-3">
                                        <p class="font-medium"><?php echo htmlspecialchars($display($idioma['idioma'] ?? '')); ?></p>
                                        <p class="text-sm text-slate-400">Habla: <?php echo htmlspecialchars($display($idioma['habla'] ?? '')); ?> | Lee: <?php echo htmlspecialchars($display($idioma['lee'] ?? '')); ?> | Escribe: <?php echo htmlspecialchars($display($idioma['escribe'] ?? '')); ?></p>
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
                                        'empresa' => $value('exp1_empresa'),
                                        'superior' => $value('exp1_superior'),
                                        'tiempo' => $value('exp1_tiempo'),
                                        'telefono' => $value('exp1_telefono'),
                                        'cargo' => $value('exp1_cargo'),
                                        'sueldo' => $value('exp1_sueldo'),
                                        'tareas' => $value('exp1_tareas'),
                                        'razon' => $value('exp1_razon_salida'),
                                    ],
                                    [
                                        'empresa' => $value('exp2_empresa'),
                                        'superior' => $value('exp2_superior'),
                                        'tiempo' => $value('exp2_tiempo'),
                                        'telefono' => $value('exp2_telefono'),
                                        'cargo' => $value('exp2_cargo'),
                                        'sueldo' => $value('exp2_sueldo'),
                                        'tareas' => $value('exp2_tareas'),
                                        'razon' => $value('exp2_razon_salida'),
                                    ]
                                ];
                            ?>
                            <?php foreach ($experiencias as $idx => $exp): ?>
                                <?php if (empty($exp['empresa']) && empty($exp['cargo']) && empty($exp['superior']) && empty($exp['tiempo'])) continue; ?>
                                <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-4">
                                    <h5 class="font-semibold text-white mb-2">Experiencia <?php echo $idx + 1; ?></h5>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-sm text-slate-400">Empresa</label>
                                            <p class="font-medium"><?php echo htmlspecialchars($display($exp['empresa'])); ?></p>
                                        </div>
                                        <div>
                                            <label class="text-sm text-slate-400">Cargo</label>
                                            <p class="font-medium"><?php echo htmlspecialchars($display($exp['cargo'])); ?></p>
                                        </div>
                                        <div>
                                            <label class="text-sm text-slate-400">Superior inmediato</label>
                                            <p class="font-medium"><?php echo htmlspecialchars($display($exp['superior'])); ?></p>
                                        </div>
                                        <div>
                                            <label class="text-sm text-slate-400">Tiempo trabajado</label>
                                            <p class="font-medium"><?php echo htmlspecialchars($display($exp['tiempo'])); ?></p>
                                        </div>
                                        <div>
                                            <label class="text-sm text-slate-400">Telefono</label>
                                            <p class="font-medium"><?php echo htmlspecialchars($display($exp['telefono'])); ?></p>
                                        </div>
                                        <div>
                                            <label class="text-sm text-slate-400">Sueldo</label>
                                            <p class="font-medium"><?php echo htmlspecialchars($display($exp['sueldo'])); ?></p>
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="text-sm text-slate-400">Tareas principales</label>
                                            <p class="font-medium whitespace-pre-line"><?php echo htmlspecialchars($display($exp['tareas'])); ?></p>
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="text-sm text-slate-400">Razon de salida</label>
                                            <p class="font-medium whitespace-pre-line"><?php echo htmlspecialchars($display($exp['razon'])); ?></p>
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
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('mayor_logro'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Expectativas salariales</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('expectativas_salariales'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Incapacidad</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('incapacidad')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Incapacidad cual</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('incapacidad_cual'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Horas extras</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('horas_extras')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Dias fiestas</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('dias_fiestas')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Conoce empleado</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($yesNo($value('conoce_empleado')))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Nombre empleado</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('conoce_empleado_nombre'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Medio vacante</label>
                                <p class="font-medium">
                                    <?php
                                        $medioVacante = $payload['medio_vacante'] ?? [];
                                        if (!is_array($medioVacante)) {
                                            $medioVacante = [$medioVacante];
                                        }
                                        $medioTexto = !empty($medioVacante) ? implode(', ', $medioVacante) : '';
                                        if (!empty($payload['medio_vacante_otro_texto'])) {
                                            $medioTexto = $medioTexto !== '' ? $medioTexto . ' / ' . $payload['medio_vacante_otro_texto'] : $payload['medio_vacante_otro_texto'];
                                        }
                                    ?>
                                    <?php echo htmlspecialchars($display($medioTexto)); ?>
                                </p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Firma solicitante</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('firma_solicitante'))); ?></p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-lg font-semibold text-indigo-200 mb-3">Para uso exclusivo del evaluador</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-sm text-slate-400">Nombre del evaluador</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('evaluador_nombre'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Fecha de evaluacion</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('evaluacion_fecha'))); ?></p>
                            </div>
                            <div>
                                <label class="text-sm text-slate-400">Puesto</label>
                                <p class="font-medium"><?php echo htmlspecialchars($display($value('evaluador_puesto'))); ?></p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-sm text-slate-400">Observaciones entrevista</label>
                                <p class="font-medium whitespace-pre-line"><?php echo htmlspecialchars($display($value('observaciones_entrevista'))); ?></p>
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
                <h3 class="text-lg font-semibold text-white mb-4">Acciones Rápidas</h3>
                <div class="space-y-2">
                    <button type="button" class="btn-primary w-full justify-center" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                        <i class="fas fa-exchange-alt"></i>
                        Cambiar Estado
                    </button>
                    <button type="button" class="btn-secondary w-full justify-center" data-bs-toggle="modal" data-bs-target="#scheduleInterviewModal">
                        <i class="fas fa-calendar-plus"></i>
                        Agendar Entrevista
                    </button>
                    <button type="button" class="btn-secondary w-full justify-center" data-bs-toggle="modal" data-bs-target="#addCommentModal">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php require_once '../footer.php'; ?>

<!-- Modals -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
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

<div class="modal fade" id="scheduleInterviewModal" tabindex="-1" aria-labelledby="scheduleInterviewModalLabel" aria-hidden="true">
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



