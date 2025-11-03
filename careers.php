<?php
// Public careers page - no login required
session_start();
require_once 'db.php';

// Get active job postings
$stmt = $pdo->query("SELECT * FROM job_postings WHERE status = 'active' AND (closing_date IS NULL OR closing_date >= CURDATE()) ORDER BY posted_date DESC");
$job_postings = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Get company info
$company_name = "Evallish BPO Control";
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carreras - <?php echo $company_name; ?></title>
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
                        
                        <!-- Personal Information -->
                        <div class="mb-6">
                            <h6 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                                <i class="fas fa-user text-indigo-600"></i>
                                Información Personal
                            </h6>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Nombre(s) *</label>
                                    <input type="text" name="first_name" required 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Apellido(s) *</label>
                                    <input type="text" name="last_name" required 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                                    <input type="email" name="email" required 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Teléfono *</label>
                                    <input type="tel" name="phone" required 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                            </div>
                        </div>

                        <hr class="my-6">

                        <!-- Professional Information -->
                        <div class="mb-6">
                            <h6 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                                <i class="fas fa-briefcase text-indigo-600"></i>
                                Información Profesional
                            </h6>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Nivel de Educación *</label>
                                    <select name="education_level" required 
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                        <option value="">Seleccionar...</option>
                                        <option value="Secundaria">Secundaria</option>
                                        <option value="Preparatoria">Preparatoria</option>
                                        <option value="Técnico">Técnico</option>
                                        <option value="Licenciatura">Licenciatura</option>
                                        <option value="Maestría">Maestría</option>
                                        <option value="Doctorado">Doctorado</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Años de Experiencia *</label>
                                    <input type="number" name="years_of_experience" min="0" required 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Puesto Actual</label>
                                    <input type="text" name="current_position" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Empresa Actual</label>
                                    <input type="text" name="current_company" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                </div>
                            </div>
                        </div>

                        <hr class="my-6">

                        <!-- CV Upload -->
                        <div class="mb-6">
                            <h6 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                                <i class="fas fa-file-upload text-indigo-600"></i>
                                Curriculum Vitae
                            </h6>
                            <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-indigo-500 transition-colors">
                                <input type="file" id="cv_file" name="cv_file" accept=".pdf,.doc,.docx" required class="hidden">
                                <label for="cv_file" class="cursor-pointer">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-2"></i>
                                    <p class="text-sm text-gray-600">Haz clic para subir tu CV (PDF, DOC, DOCX - Max 5MB)</p>
                                    <p id="file-name" class="text-sm text-indigo-600 font-medium mt-2"></p>
                                </label>
                            </div>
                        </div>

                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                            <p class="text-sm text-blue-800">
                                <i class="fas fa-info-circle mr-2"></i>
                                Al enviar esta solicitud, recibirás un código de seguimiento para rastrear el estado de tu aplicación.
                            </p>
                        </div>

                        <button type="submit" 
                                class="w-full px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200">
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
            document.getElementById('job_posting_id').value = jobId;
            document.getElementById('applicationForm').reset();
            document.getElementById('file-name').textContent = '';
            new bootstrap.Modal(document.getElementById('applicationModal')).show();
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

        // Form submission
        document.getElementById('applicationForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
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
                    alert(`¡Solicitud enviada exitosamente!\n\nTu código de seguimiento es: ${result.application_code}\n\nGuarda este código para rastrear el estado de tu solicitud.`);
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
