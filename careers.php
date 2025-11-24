<?php
// Public careers page - no login required
session_start();
require_once 'db.php';

// Get active job postings
$stmt = $pdo->query("SELECT * FROM job_postings WHERE status = 'active' AND (closing_date IS NULL OR closing_date >= CURDATE()) ORDER BY posted_date DESC");
$job_postings = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Get company info
$company_name = "Evallish BPO Control";

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$base_url = rtrim($protocol . '://' . $host . $base_path, '/');

function getJobBannerUrl(int $jobId, string $baseUrl): ?string
{
    $bannerDir = realpath(__DIR__ . '/uploads/job_banners');
    if (!$bannerDir) {
        return null;
    }

    $baseBannerUrl = rtrim($baseUrl, '/') . '/uploads/job_banners';
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $filePath = $bannerDir . DIRECTORY_SEPARATOR . "job_{$jobId}.{$ext}";
        if (file_exists($filePath)) {
            return $baseBannerUrl . "/job_{$jobId}.{$ext}";
        }
    }

    return null;
}

foreach ($job_postings as &$job) {
    $job['banner_url'] = getJobBannerUrl((int)$job['id'], $base_url);
}
unset($job);

$shareJobId = isset($_GET['job']) ? (int)$_GET['job'] : null;
$shareJob = null;
if ($shareJobId) {
    foreach ($job_postings as $job) {
        if ((int)$job['id'] === $shareJobId) {
            $shareJob = $job;
            break;
        }
    }
}

$shareTitle = $shareJob ? ($shareJob['title'] . " - " . $company_name) : "Carreras - " . $company_name;
$shareDescription = $shareJob
    ? substr(strip_tags(preg_replace('/\s+/', ' ', $shareJob['description'] ?? '')), 0, 180)
    : "Descubre las vacantes disponibles y ��nete al equipo de {$company_name}.";
$shareImage = $shareJob['banner_url'] ?? ($base_url . '/assets/logo.png');
$shareUrl = $base_url . '/careers.php' . ($shareJobId ? '?job=' . $shareJobId : '');
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($shareTitle); ?></title>
    <meta property="og:title" content="<?php echo htmlspecialchars($shareTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($shareDescription); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($shareImage); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($shareUrl); ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($shareTitle); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($shareDescription); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($shareImage); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 font-sans antialiased">
    
    <!-- Header -->
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900"><?php echo $company_name; ?></h1>
                    <p class="text-sm text-gray-600 mt-1">Oportunidades de Carrera</p>
                </div>
                <a href="track_application.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                    <i class="fas fa-search mr-2"></i>
                    Rastrear Solicitud
                </a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="bg-gradient-to-br from-indigo-600 via-purple-600 to-pink-600 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
            <h2 class="text-5xl font-bold mb-6">Construye Tu Futuro Con Nosotros</h2>
            <p class="text-xl text-indigo-100 mb-8 max-w-2xl mx-auto">
                Únete a un equipo innovador donde tu talento marca la diferencia
            </p>
            <div class="flex justify-center gap-8 mt-12">
                <div class="text-center">
                    <div class="text-4xl font-bold"><?php echo count($job_postings); ?></div>
                    <div class="text-indigo-200 text-sm mt-1">Vacantes Activas</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold">100+</div>
                    <div class="text-indigo-200 text-sm mt-1">Empleados</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold">5★</div>
                    <div class="text-indigo-200 text-sm mt-1">Ambiente</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Jobs Section -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        
        <?php if (!empty($job_postings)): ?>
            <div class="text-center mb-12">
                <h3 class="text-3xl font-bold text-gray-900 mb-2">Posiciones Disponibles</h3>
                <p class="text-gray-600">Encuentra la oportunidad perfecta para ti</p>
            </div>

            <div class="grid gap-6">
                <?php foreach ($job_postings as $job): 
                    $employment_types = [
                        'full_time' => 'Tiempo Completo',
                        'part_time' => 'Medio Tiempo',
                        'contract' => 'Contrato',
                        'internship' => 'Pasantía'
                    ];
                ?>
                    <div class="job-card bg-white rounded-xl border border-gray-200 p-6 shadow-sm hover:shadow-lg" id="job-<?php echo $job['id']; ?>">
                        <?php if (!empty($job['banner_url'])): ?>
                            <div class="mb-4 -mx-6 -mt-6 rounded-t-xl overflow-hidden border-b border-gray-200">
                                <img src="<?php echo htmlspecialchars($job['banner_url']); ?>" alt="Banner de la vacante" class="w-full h-48 object-cover">
                            </div>
                        <?php endif; ?>
                        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
                            <!-- Left: Job Info -->
                            <div class="flex-1">
                                <div class="flex items-start gap-4 mb-4">
                                    <div class="w-12 h-12 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-briefcase text-white text-lg"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="text-xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($job['title']); ?></h4>
                                        <div class="flex flex-wrap gap-4 text-sm text-gray-600">
                                            <span class="flex items-center gap-1">
                                                <i class="fas fa-building text-indigo-600"></i>
                                                <?php echo htmlspecialchars($job['department']); ?>
                                            </span>
                                            <span class="flex items-center gap-1">
                                                <i class="fas fa-map-marker-alt text-indigo-600"></i>
                                                <?php echo htmlspecialchars($job['location']); ?>
                                            </span>
                                            <span class="flex items-center gap-1">
                                                <i class="fas fa-clock text-indigo-600"></i>
                                                <?php echo $employment_types[$job['employment_type']]; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <p class="text-gray-700 mb-4 leading-relaxed">
                                    <?php echo nl2br(htmlspecialchars(substr($job['description'], 0, 200))); ?>...
                                </p>

                                <div class="flex flex-wrap gap-2">
                                    <span class="px-3 py-1 bg-indigo-50 text-indigo-700 rounded-full text-sm font-medium">
                                        <?php echo $employment_types[$job['employment_type']]; ?>
                                    </span>
                                    <span class="px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-sm font-medium">
                                        <?php echo htmlspecialchars($job['department']); ?>
                                    </span>
                                    <?php if ($job['salary_range']): ?>
                                        <span class="px-3 py-1 bg-green-50 text-green-700 rounded-full text-sm font-medium">
                                            <i class="fas fa-dollar-sign"></i> <?php echo htmlspecialchars($job['salary_range']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Right: Action Button -->
                            <div class="lg:w-48 flex-shrink-0">
                                <button onclick="openApplicationModal(<?php echo $job['id']; ?>)" 
                                        class="w-full px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-md hover:shadow-lg transition-all duration-200 flex items-center justify-center gap-2">
                                    <span>Aplicar Ahora</span>
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                                <button onclick="showJobDetails(<?php echo $job['id']; ?>)" 
                                        class="w-full mt-2 px-6 py-2 border border-gray-300 hover:border-gray-400 text-gray-700 font-medium rounded-lg transition-colors flex items-center justify-center gap-2">
                                    <span>Ver Detalles</span>
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="text-center py-20">
                <div class="w-24 h-24 mx-auto mb-6 rounded-full bg-gray-100 flex items-center justify-center">
                    <i class="fas fa-briefcase text-gray-400 text-4xl"></i>
                </div>
                <h3 class="text-2xl font-bold text-gray-900 mb-2">No hay vacantes disponibles</h3>
                <p class="text-gray-600">Estamos constantemente creciendo. Vuelve pronto para ver nuevas oportunidades.</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- Application Modal -->
    <div class="modal fade" id="applicationModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content rounded-xl border-0 shadow-2xl">
                <div class="modal-header bg-gradient-to-r from-indigo-600 to-purple-600 text-white border-0 rounded-t-xl">
                    <h5 class="modal-title font-bold"><i class="fas fa-file-alt mr-2"></i>Solicitud de Empleo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-6">
                    <form id="applicationForm" enctype="multipart/form-data">
                        <input type="hidden" name="job_posting_id" id="job_posting_id">

                        <div class="mb-6 space-y-4">
                            <h6 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-user text-indigo-600"></i>
                                Formulario de Solicitud - Evallish BPO
                            </h6>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Nombre del candidato *</label>
                                    <input type="text" name="candidate_name" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Cedula dominicana *</label>
                                    <input type="text" name="cedula" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Numero(s) de telefono *</label>
                                    <input type="text" name="phone_numbers" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Sector donde reside *</label>
                                    <input type="text" name="sector_residencia" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email (opcional)</label>
                                    <input type="email" name="email" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="Para enviarte confirmacion">
                                </div>
                            </div>
                        </div>

                        <div class="mb-6 space-y-3">
                            <h6 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-rotate-left text-indigo-600"></i>
                                Aplicacion previa
                            </h6>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">¿Ha aplicado con nosotros anteriormente? *</label>
                                    <div class="flex items-center gap-4">
                                        <label class="inline-flex items-center gap-2"><input type="radio" name="applied_before" value="SI" required> SI</label>
                                        <label class="inline-flex items-center gap-2"><input type="radio" name="applied_before" value="NO" required> NO</label>
                                    </div>
                                </div>
                                <div id="applied_before_details_group" style="display: none;">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Si su respuesta es si, especifique</label>
                                    <input type="text" name="applied_before_details" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                            </div>
                        </div>

                        <div class="mb-6 space-y-3">
                            <h6 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-bullhorn text-indigo-600"></i>
                                ¿Como se entero de la vacante?
                            </h6>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <label class="flex items-center gap-2"><input type="radio" name="source" value="Instagram" required> Instagram</label>
                                    <label class="flex items-center gap-2"><input type="radio" name="source" value="WhatsApp" required> WhatsApp</label>
                                    <label class="flex items-center gap-2"><input type="radio" name="source" value="Facebook" required> Facebook</label>
                                    <label class="flex items-center gap-2"><input type="radio" name="source" value="Un amigo" required> Un amigo</label>
                                    <label class="flex items-center gap-2"><input type="radio" name="source" value="Otro" required> Otro</label>
                                </div>
                                <div id="source_other_group" style="display: none;">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Si selecciono "Otro", especifique</label>
                                    <input type="text" name="source_other" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus-border-transparent">
                                </div>
                            </div>
                        </div>

                        <div class="mb-6 space-y-3">
                            <h6 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-lightbulb text-indigo-600"></i>
                                Conocimiento sobre la empresa
                            </h6>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">¿Sabe a que se dedica Evallish BPO? *</label>
                                    <div class="flex items-center gap-4">
                                        <label class="inline-flex items-center gap-2"><input type="radio" name="knows_company" value="SI" required> SI</label>
                                        <label class="inline-flex items-center gap-2"><input type="radio" name="knows_company" value="NO" required> NO</label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">¿Por que esta interesado(a) en trabajar para esta empresa? *</label>
                                    <textarea name="interest_reason" required rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="mb-6 space-y-3">
                            <h6 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-language text-indigo-600"></i>
                                Idioma y disponibilidad
                            </h6>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">¿En cual idioma desea aplicar? *</label>
                                    <div class="space-y-2">
                                        <label class="flex items-center gap-2"><input type="radio" name="application_language" value="Ingles" required> Ingles</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="application_language" value="Espanol" required> Espanol</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="application_language" value="Ambos" required> Ambos</label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">¿Cual es su horario de trabajo disponible? *</label>
                                    <div class="space-y-2">
                                        <label class="flex items-center gap-2"><input type="radio" name="availability_time" value="AM" required> AM</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="availability_time" value="PM" required> PM</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="availability_time" value="Horario abierto" required> Horario abierto</label>
                                    </div>
                                    <label class="block text-sm font-medium text-gray-700 mt-3 mb-2">Preferencia de horario</label>
                                    <input type="text" name="availability_preference" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="Ej: 9am-6pm">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">¿Cual es su horario de entrenamiento disponible? *</label>
                                    <div class="space-y-2">
                                        <label class="flex items-center gap-2"><input type="radio" name="training_schedule" value="8:30-5:30 L-V" required> 8:30 a.m. - 5:30 p.m. Lunes a Viernes</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="training_schedule" value="2:30-10:30 L-D" required> 2:30 p.m. - 10:30 p.m. Lunes a Domingo</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="training_schedule" value="Horario abierto" required> Horario abierto</label>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Rotamos los dias libres semanalmente. ¿Esta de acuerdo con esto? *</label>
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-2"><input type="radio" name="agrees_rotating_days" value="SI" required> SI</label>
                                            <label class="inline-flex items-center gap-2"><input type="radio" name="agrees_rotating_days" value="NO" required> NO</label>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">¿Esta disponible para trabajar fines de semana y dias feriados? *</label>
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-2"><input type="radio" name="weekend_holidays" value="SI" required> SI</label>
                                            <label class="inline-flex items-center gap-2"><input type="radio" name="weekend_holidays" value="NO" required> NO</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-6 space-y-3">
                            <h6 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-briefcase text-indigo-600"></i>
                                Experiencia laboral
                            </h6>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">¿Actualmente esta empleado? *</label>
                                    <div class="flex items-center gap-4">
                                        <label class="inline-flex items-center gap-2"><input type="radio" name="currently_employed" value="SI" required> SI</label>
                                        <label class="inline-flex items-center gap-2"><input type="radio" name="currently_employed" value="NO" required> NO</label>
                                    </div>
                                    <div id="current_employment_details_group" style="display: none;">
                                        <label class="block text-sm font-medium text-gray-700 mt-3 mb-2">Si respondio si, especifique</label>
                                        <input type="text" name="current_employment_details" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-gray-700">Experiencia laboral reciente</label>
                                    <input type="text" name="recent_company" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="Empresa">
                                    <input type="text" name="recent_role" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="Puesto">
                                    <input type="number" min="0" name="recent_years" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="Años">
                                    <input type="text" name="recent_last_salary" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus-border-transparent" placeholder="Ultimo salario devengado">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">¿Ha trabajado en call center antes? *</label>
                                    <div class="flex items-center gap-4">
                                        <label class="inline-flex items-center gap-2"><input type="radio" name="has_call_center_experience" value="SI" required> SI</label>
                                        <label class="inline-flex items-center gap-2"><input type="radio" name="has_call_center_experience" value="NO" required> NO</label>
                                    </div>
                                </div>
                                <div id="call_center_details_group" class="space-y-2" style="display: none;">
                                    <label class="block text-sm font-medium text-gray-700">Si respondio si, indique</label>
                                    <input type="text" name="call_center_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus-border-transparent" placeholder="Nombre del ultimo call center">
                                    <input type="text" name="call_center_role" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus-border-transparent" placeholder="Puesto desempenado">
                                    <input type="text" name="call_center_salary" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus-border-transparent" placeholder="Ultimo salario devengado">
                                </div>
                            </div>
                        </div>

                        <div class="mb-6">
                            <h6 class="text-lg font-bold text-gray-900 mb-3 flex items-center gap-2">
                                <i class="fas fa-paperclip text-indigo-600"></i>
                                Curriculum Vitae
                            </h6>
                            <div class="border-2 border-dashed border-gray-300 rounded-xl p-6 text-center hover:border-indigo-400 transition-colors">
                                <input type="file" name="cv_file" id="cv_file" accept=".pdf,.doc,.docx" required class="hidden">
                                <label for="cv_file" class="cursor-pointer">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-2"></i>
                                    <p class="text-sm text-gray-600">Haz clic para subir tu CV (PDF, DOC, DOCX - Max 5MB)</p>
                                    <p id="file-name" class="text-sm text-indigo-600 font-medium mt-2"></p>
                                </label>
                            </div>
                        </div>

                        <hr class="my-6">

                        <div class="mb-6">
                            <h6 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                                <i class="fas fa-list-check text-indigo-600"></i>
                                Aplicar a Otras Vacantes Disponibles
                            </h6>
                            <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 mb-4">
                                <p class="text-sm text-indigo-800">
                                    <i class="fas fa-lightbulb mr-2"></i>
                                    ¿Te interesan otras posiciones? Selecciona las vacantes adicionales a las que deseas aplicar con el mismo CV.
                                </p>
                            </div>
                            <div id="additional-positions-container" class="space-y-3"></div>
                        </div>

                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                            <p class="text-sm text-blue-800">
                                <i class="fas fa-info-circle mr-2"></i>
                                Al enviar esta solicitud, recibirás un código de seguimiento para rastrear el estado de tu aplicación.
                            </p>
                        </div>

                        <button type="submit" class="w-full px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200">
                            <i class="fas fa-paper-plane mr-2"></i>
                            Enviar Solicitud
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Job Details Modal -->
    <div class="modal fade" id="jobDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content rounded-xl border-0 shadow-2xl">
                <div class="modal-header bg-gradient-to-r from-indigo-600 to-purple-600 text-white border-0 rounded-t-xl">
                    <h5 class="modal-title font-bold" id="jobDetailsTitle"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-6" id="jobDetailsBody"></div>
                <div class="modal-footer border-0">
                    <button type="button" onclick="openApplicationModalFromDetails()" 
                            class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition-colors">
                        <i class="fas fa-paper-plane mr-2"></i>
                        Aplicar a esta Posición
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const jobPostings = <?php echo json_encode($job_postings); ?>;
        let currentJobId = null;

        function toggleVisibility(id, show) {
            const el = document.getElementById(id);
            if (!el) return;
            el.style.display = show ? '' : 'none';
        }

        function updateConditionalFields() {
            const appliedBefore = document.querySelector('input[name="applied_before"]:checked')?.value;
            toggleVisibility('applied_before_details_group', appliedBefore === 'SI');

            const source = document.querySelector('input[name="source"]:checked')?.value;
            toggleVisibility('source_other_group', source === 'Otro');

            const employed = document.querySelector('input[name="currently_employed"]:checked')?.value;
            toggleVisibility('current_employment_details_group', employed === 'SI');

            const callCenter = document.querySelector('input[name="has_call_center_experience"]:checked')?.value;
            toggleVisibility('call_center_details_group', callCenter === 'SI');
        }

        function bindConditionalRadios() {
            ['applied_before', 'source', 'currently_employed', 'has_call_center_experience'].forEach(name => {
                document.querySelectorAll(`input[name=\"${name}\"]`).forEach(radio => {
                    radio.addEventListener('change', updateConditionalFields);
                });
            });
        }

        const params = new URLSearchParams(window.location.search);
        const sharedJobId = params.get('job');
        if (sharedJobId) {
            const target = document.getElementById(`job-${sharedJobId}`);
            if (target) {
                target.classList.add('ring-2', 'ring-indigo-200', 'ring-offset-2', 'ring-offset-gray-50');
                setTimeout(() => {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 200);
                setTimeout(() => {
                    target.classList.remove('ring-2', 'ring-indigo-200', 'ring-offset-2', 'ring-offset-gray-50');
                }, 4000);
            }
        }

        // File upload
        document.getElementById('cv_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const fileName = document.getElementById('file-name');
            
            if (file) {
                if (file.size > 5 * 1024 * 1024) {
                    alert('El archivo es demasiado grande. Máximo 5MB.');
                    e.target.value = '';
                    return;
                }
                fileName.textContent = file.name;
            } else {
                fileName.textContent = '';
            }
        });

        function openApplicationModal(jobId) {
            currentJobId = jobId;
            document.getElementById('applicationForm').reset();
            const jobField = document.getElementById('job_posting_id');
            jobField.value = jobId;
            document.getElementById('file-name').textContent = '';
            updateConditionalFields();
            
            // Load additional positions (exclude the current one)
            loadAdditionalPositions(jobId);
            
            new bootstrap.Modal(document.getElementById('applicationModal')).show();
        }

        function loadAdditionalPositions(currentJobId) {
            const container = document.getElementById('additional-positions-container');
            const otherJobs = jobPostings.filter(job => job.id != currentJobId);
            
            if (otherJobs.length === 0) {
                container.innerHTML = '<p class="text-sm text-gray-500 italic">No hay otras vacantes disponibles en este momento.</p>';
                return;
            }

            const employmentTypes = {
                'full_time': 'Tiempo Completo',
                'part_time': 'Medio Tiempo',
                'contract': 'Contrato',
                'internship': 'Pasantía'
            };

            let html = '';
            otherJobs.forEach(job => {
                html += `
                    <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="additional_positions[]" value="${job.id}" 
                                   class="mt-1 w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                            <div class="flex-1">
                                <div class="font-semibold text-gray-900">${job.title}</div>
                                <div class="text-sm text-gray-600 mt-1">
                                    <span class="inline-flex items-center gap-1">
                                        <i class="fas fa-building text-xs"></i>
                                        ${job.department}
                                    </span>
                                    <span class="mx-2">•</span>
                                    <span class="inline-flex items-center gap-1">
                                        <i class="fas fa-map-marker-alt text-xs"></i>
                                        ${job.location}
                                    </span>
                                    <span class="mx-2">•</span>
                                    <span class="inline-flex items-center gap-1">
                                        <i class="fas fa-clock text-xs"></i>
                                        ${employmentTypes[job.employment_type]}
                                    </span>
                                </div>
                            </div>
                        </label>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function showJobDetails(jobId) {
            const job = jobPostings.find(j => j.id == jobId);
            if (!job) return;

            currentJobId = jobId;
            document.getElementById('jobDetailsTitle').textContent = job.title;
            
            const employmentTypes = {
                'full_time': 'Tiempo Completo',
                'part_time': 'Medio Tiempo',
                'contract': 'Contrato',
                'internship': 'Pasantía'
            };

            let detailsHTML = `
                <div class="space-y-4">
                    ${job.banner_url ? `
                    <div class="rounded-lg overflow-hidden border border-gray-200">
                        <img src="${job.banner_url}" alt="Banner de la vacante" class="w-full object-cover max-h-64">
                    </div>
                    ` : ''}
                    <div class="flex flex-wrap gap-4 text-sm text-gray-600 mb-4">
                        <span class="flex items-center gap-2">
                            <i class="fas fa-building text-indigo-600"></i>
                            <strong>Departamento:</strong> ${job.department}
                        </span>
                        <span class="flex items-center gap-2">
                            <i class="fas fa-map-marker-alt text-indigo-600"></i>
                            <strong>Ubicación:</strong> ${job.location}
                        </span>
                        <span class="flex items-center gap-2">
                            <i class="fas fa-clock text-indigo-600"></i>
                            <strong>Tipo:</strong> ${employmentTypes[job.employment_type]}
                        </span>
                        ${job.salary_range ? `
                        <span class="flex items-center gap-2">
                            <i class="fas fa-dollar-sign text-green-600"></i>
                            <strong>Salario:</strong> ${job.salary_range}
                        </span>
                        ` : ''}
                    </div>

                    <div>
                        <h6 class="font-bold text-gray-900 mb-2">Descripción</h6>
                        <p class="text-gray-700">${job.description.replace(/\n/g, '<br>')}</p>
                    </div>

                    ${job.requirements ? `
                    <div>
                        <h6 class="font-bold text-gray-900 mb-2">Requisitos</h6>
                        <p class="text-gray-700">${job.requirements.replace(/\n/g, '<br>')}</p>
                    </div>
                    ` : ''}

                    ${job.responsibilities ? `
                    <div>
                        <h6 class="font-bold text-gray-900 mb-2">Responsabilidades</h6>
                        <p class="text-gray-700">${job.responsibilities.replace(/\n/g, '<br>')}</p>
                    </div>
                    ` : ''}
                </div>
            `;

            document.getElementById('jobDetailsBody').innerHTML = detailsHTML;
            new bootstrap.Modal(document.getElementById('jobDetailsModal')).show();
        }

        function openApplicationModalFromDetails() {
            bootstrap.Modal.getInstance(document.getElementById('jobDetailsModal')).hide();
            setTimeout(() => openApplicationModal(currentJobId), 300);
        }

        bindConditionalRadios();
        updateConditionalFields();

        // Form submission
        document.getElementById('applicationForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            // Ensure job_posting_id is always set
            if (!formData.get('job_posting_id') && currentJobId) {
                formData.set('job_posting_id', currentJobId);
            }
            if (!formData.get('job_posting_id')) {
                alert('No se pudo identificar la vacante. Intenta abrir de nuevo el formulario.');
                return;
            }
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enviando...';
            
            try {
                const response = await fetch('submit_application.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    bootstrap.Modal.getInstance(document.getElementById('applicationModal')).hide();
                    
                    let successMessage = `¡Solicitud enviada exitosamente!\n\n`;
                    if (result.positions_count > 1) {
                        successMessage += `Has aplicado a ${result.positions_count} vacantes con el mismo CV.\n\n`;
                    }
                    successMessage += `Tu código de seguimiento es: ${result.application_code}\n\nGuarda este código para rastrear el estado de tu solicitud.`;
                    
                    alert(successMessage);
                    window.location.href = `track_application.php?code=${result.application_code}`;
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error al enviar la solicitud. Por favor, intenta nuevamente.');
                console.error(error);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    </script>
</body>
</html>
