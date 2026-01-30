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
    $job['banner_url'] = getJobBannerUrl((int) $job['id'], $base_url);
}
unset($job);

$shareJobId = isset($_GET['job']) ? (int) $_GET['job'] : null;
$shareJob = null;
if ($shareJobId) {
    foreach ($job_postings as $job) {
        if ((int) $job['id'] === $shareJobId) {
            $shareJob = $job;
            break;
        }
    }
}

$shareTitle = $shareJob ? ($shareJob['title'] . " - " . $company_name) : "Carreras - " . $company_name;
$shareDescription = $shareJob
    ? substr(strip_tags(preg_replace('/\s+/', ' ', $shareJob['description'] ?? '')), 0, 180)
    : "Descubre las vacantes disponibles y únete al equipo de {$company_name}.";
$shareImage = $shareJob && !empty($shareJob['banner_url']) ? $shareJob['banner_url'] : ($base_url . '/assets/logo.png');
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

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Outfit', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            900: '#0c4a6e',
                        }
                    },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.8s ease-out forwards',
                        'blob': 'blob 7s infinite',
                    },
                    keyframes: {
                        fadeInUp: {
                            '0%': { opacity: '0', transform: 'translateY(20px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        blob: {
                            '0%': { transform: 'translate(0px, 0px) scale(1)' },
                            '33%': { transform: 'translate(30px, -50px) scale(1.1)' },
                            '66%': { transform: 'translate(-20px, 20px) scale(0.9)' },
                            '100%': { transform: 'translate(0px, 0px) scale(1)' },
                        }
                    }
                }
            }
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .glass-header {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .job-card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
    </style>
</head>

<body class="bg-slate-50 font-sans text-slate-800 antialiased selection:bg-brand-500 selection:text-white">

    <!-- Navbar -->
    <nav class="fixed w-full z-50 transition-all duration-300 glass-header border-b border-slate-200/60" id="navbar">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <div class="flex items-center gap-3 group">
                    <div
                        class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-600 flex items-center justify-center text-white shadow-lg shadow-brand-500/30 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-rocket text-lg"></i>
                    </div>
                    <div>
                        <h1 class="font-bold text-xl tracking-tight text-slate-900 leading-none">
                            <?php echo $company_name; ?>
                        </h1>
                        <p class="text-xs text-brand-600 font-medium tracking-wide uppercase">Carreras</p>
                    </div>
                </div>
                <div class="hidden md:flex items-center gap-6">
                    <a href="#vacantes"
                        class="text-sm font-medium text-slate-600 hover:text-brand-600 transition-colors">Vacantes</a>
                    <a href="track_application.php"
                        class="inline-flex items-center px-5 py-2.5 rounded-full bg-white border border-slate-200 text-sm font-semibold text-slate-700 hover:border-brand-500 hover:text-brand-600 transition-all shadow-sm hover:shadow-md gap-2 group">
                        <i class="fas fa-search group-hover:rotate-90 transition-transform duration-300"></i>
                        <span>Rastrear Solicitud</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden">
        <div
            class="absolute top-0 left-0 -ml-20 -mt-20 w-96 h-96 bg-brand-400 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob">
        </div>
        <div
            class="absolute top-0 right-0 -mr-20 -mt-20 w-96 h-96 bg-purple-400 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-2000">
        </div>
        <div
            class="absolute bottom-0 left-20 -mb-20 w-96 h-96 bg-pink-400 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-4000">
        </div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center z-10">
            <span
                class="inline-block py-1 px-3 rounded-full bg-brand-50 text-brand-700 text-xs font-bold tracking-wider uppercase mb-6 border border-brand-100 animate-fade-in-up">
                Únete al equipo
            </span>
            <h1 class="text-5xl md:text-7xl font-extrabold text-slate-900 tracking-tight mb-8 animate-fade-in-up"
                style="animation-delay: 0.1s;">
                Construye Tu <span
                    class="text-transparent bg-clip-text bg-gradient-to-r from-brand-600 to-purple-600">Futuro</span>
                <br class="hidden md:block" /> Con Nosotros
            </h1>
            <p class="mt-4 max-w-2xl mx-auto text-xl text-slate-600 mb-12 animate-fade-in-up"
                style="animation-delay: 0.2s;">
                Forma parte de una cultura innovadora donde tu crecimiento es nuestra prioridad.
            </p>

            <div class="flex flex-wrap justify-center gap-4 animate-fade-in-up" style="animation-delay: 0.3s;">
                <a href="#vacantes"
                    class="px-8 py-4 rounded-full bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-bold text-lg shadow-xl shadow-brand-500/30 hover:shadow-2xl hover:scale-105 transition-all duration-300 flex items-center gap-2">
                    Ver Vacantes <i class="fas fa-arrow-down animate-bounce"></i>
                </a>
            </div>

            <div class="max-w-4xl mx-auto mt-20 grid grid-cols-2 md:grid-cols-4 gap-8">
                <div
                    class="p-6 rounded-2xl bg-white/50 backdrop-blur-sm border border-white/50 shadow-sm hover:shadow-md transition-all">
                    <div class="text-4xl font-extrabold text-slate-900"><?php echo count($job_postings); ?></div>
                    <div class="text-sm font-medium text-slate-500 mt-1">Vacantes Activas</div>
                </div>
                <div
                    class="p-6 rounded-2xl bg-white/50 backdrop-blur-sm border border-white/50 shadow-sm hover:shadow-md transition-all">
                    <div class="text-4xl font-extrabold text-slate-900">100+</div>
                    <div class="text-sm font-medium text-slate-500 mt-1">Colaboradores</div>
                </div>
                <div
                    class="p-6 rounded-2xl bg-white/50 backdrop-blur-sm border border-white/50 shadow-sm hover:shadow-md transition-all">
                    <div class="text-4xl font-extrabold text-slate-900">24/7</div>
                    <div class="text-sm font-medium text-slate-500 mt-1">Operaciones</div>
                </div>
                <div
                    class="p-6 rounded-2xl bg-white/50 backdrop-blur-sm border border-white/50 shadow-sm hover:shadow-md transition-all">
                    <div class="text-4xl font-extrabold text-slate-900">Top</div>
                    <div class="text-sm font-medium text-slate-500 mt-1">Ambiente</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Vacantes Section -->
    <section id="vacantes" class="py-20 bg-white relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mb-4">Oportunidades Disponibles</h2>
                <div class="h-1.5 w-24 bg-gradient-to-r from-brand-500 to-purple-500 mx-auto rounded-full"></div>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php if (empty($job_postings)): ?>
                    <div
                        class="col-span-full text-center py-20 bg-slate-50 rounded-3xl border-2 border-dashed border-slate-200">
                        <i class="fas fa-search text-3xl text-slate-400 mb-4"></i>
                        <h3 class="text-xl font-bold text-slate-900">No hay vacantes por el momento</h3>
                        <p class="text-slate-500 mt-2">Vuelve pronto.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($job_postings as $job):
                        $employment_types = [
                            'full_time' => 'Tiempo Completo',
                            'part_time' => 'Medio Tiempo',
                            'contract' => 'Contrato',
                            'internship' => 'Pasantía'
                        ];
                        ?>
                        <div
                            class="group bg-white rounded-3xl p-1 border border-slate-100 shadow-xl shadow-slate-200/50 hover:shadow-2xl hover:shadow-brand-500/10 transition-all duration-300 job-card-hover flex flex-col h-full">
                            <div class="relative h-48 rounded-t-[1.3rem] overflow-hidden bg-slate-100">
                                <?php if (!empty($job['banner_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($job['banner_url']); ?>" alt="Job Banner"
                                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                                <?php else: ?>
                                    <div
                                        class="w-full h-full bg-gradient-to-br from-slate-200 to-slate-300 flex items-center justify-center">
                                        <i class="fas fa-briefcase text-4xl text-slate-400"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="absolute bottom-4 left-4 right-4">
                                    <div class="flex flex-wrap gap-2">
                                        <span
                                            class="px-2.5 py-1 rounded-md bg-white/90 backdrop-blur-sm text-xs font-bold text-brand-700 shadow-sm">
                                            <?php echo htmlspecialchars($job['department']); ?>
                                        </span>
                                        <span
                                            class="px-2.5 py-1 rounded-md bg-indigo-500/90 backdrop-blur-sm text-xs font-bold text-white shadow-sm">
                                            <?php echo $employment_types[$job['employment_type']] ?? 'General'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="p-6 flex flex-col flex-1">
                                <h3
                                    class="text-xl font-bold text-slate-900 mb-3 group-hover:text-brand-600 transition-colors line-clamp-2">
                                    <?php echo htmlspecialchars($job['title']); ?>
                                </h3>

                                <div class="flex flex-col gap-2 text-sm text-slate-500 mb-6">
                                    <div class="flex items-start gap-2">
                                        <i class="fas fa-map-marker-alt text-brand-500 mt-1 flex-shrink-0"></i>
                                        <span class="break-all" title="<?php echo htmlspecialchars($job['location']); ?>">
                                            <?php echo htmlspecialchars($job['location']); ?>
                                        </span>
                                    </div>
                                    <?php if ($job['salary_range']): ?>
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-money-bill-wave text-green-500 flex-shrink-0"></i>
                                            <span class="font-medium text-slate-700">
                                                <?php echo htmlspecialchars($job['salary_range']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <p class="text-slate-600 text-sm leading-relaxed mb-6 line-clamp-3">
                                    <?php echo nl2br(htmlspecialchars(substr($job['description'], 0, 150))); ?>...
                                </p>

                                <div class="mt-auto pt-6 border-t border-slate-100 flex gap-3">
                                    <button
                                        onclick="openApplicationModal(<?php echo $job['id']; ?>, '<?php echo htmlspecialchars($job['title'], ENT_QUOTES); ?>')"
                                        class="flex-1 py-3 px-4 rounded-xl bg-slate-900 text-white font-semibold text-sm hover:bg-brand-600 transition-colors shadow-lg shadow-slate-900/20 flex items-center justify-center gap-2 group/btn">
                                        Aplicar
                                        <i class="fas fa-arrow-right group-hover/btn:translate-x-1 transition-transform"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Enhanced Modal -->
    <div id="applicationModal" class="fixed inset-0 z-[100] hidden" aria-labelledby="modal-title" role="dialog"
        aria-modal="true">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0" id="modalBackdrop">
        </div>

        <div class="fixed inset-0 z-10 overflow-y-auto">
            <div class="flex min-h-full items-end justify-center p-0 sm:items-center sm:p-4 text-center sm:text-left">
                <div class="relative transform overflow-hidden rounded-t-2xl sm:rounded-2xl bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-4xl opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    id="modalPanel">

                    <div
                        class="bg-gradient-to-r from-brand-600 to-indigo-600 px-4 py-4 sm:px-6 flex justify-between items-center sticky top-0 z-20 shadow-md">
                        <div>
                            <h3 class="text-lg font-bold leading-6 text-white flex items-center gap-2" id="modal-title">
                                <i class="fas fa-rocket"></i> Solicitud de Empleo
                            </h3>
                            <p class="text-brand-100 text-xs mt-1" id="modalJobTitle">Cargando...</p>
                        </div>
                        <button type="button" onclick="closeModal()"
                            class="text-white/80 hover:text-white bg-white/10 hover:bg-white/20 rounded-lg p-2 transition-colors">
                            <span class="sr-only">Cerrar</span>
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="bg-slate-50 max-h-[85vh] overflow-y-auto">
                        <form id="applicationForm" class="p-4 sm:p-8 space-y-8" enctype="multipart/form-data">
                            <input type="hidden" name="job_posting_id" id="job_posting_id">
                            <input type="hidden" name="puesto_aplicado" id="puesto_aplicado">

                            <!-- Section 1: Datos Personales -->
                            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                                <h4
                                    class="text-lg font-bold text-slate-800 mb-6 flex items-center gap-2 pb-2 border-b border-slate-100">
                                    <span
                                        class="w-8 h-8 rounded-lg bg-brand-100 text-brand-600 flex items-center justify-center text-sm">1</span>
                                    Datos Personales
                                </h4>

                                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                                    <div class="space-y-1 md:col-span-1">
                                        <label
                                            class="block text-xs font-semibold text-slate-600 uppercase">Serie</label>
                                        <input type="text" name="serie"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1 md:col-span-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Cédula <span
                                                class="text-red-500">*</span></label>
                                        <input type="text" name="cedula" required
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1 md:col-span-2">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Dirección
                                            Completa <span class="text-red-500">*</span></label>
                                        <input type="text" name="direccion" required
                                            placeholder="Calle, No., Urbanización, Ciudad"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                                    <div class="space-y-1 md:col-span-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Teléfono
                                            <span class="text-red-500">*</span></label>
                                        <input type="text" name="telefono" required
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1 md:col-span-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Ap. Paterno
                                            <span class="text-red-500">*</span></label>
                                        <input type="text" name="apellido_paterno" required
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1 md:col-span-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Ap. Materno
                                            <span class="text-red-500">*</span></label>
                                        <input type="text" name="apellido_materno" required
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1 md:col-span-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Nombres
                                            <span class="text-red-500">*</span></label>
                                        <input type="text" name="nombres" required
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-4">
                                    <div class="space-y-1 md:col-span-1">
                                        <label
                                            class="block text-xs font-semibold text-slate-600 uppercase">Apodo</label>
                                        <input type="text" name="apodo"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1 md:col-span-2">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">F.
                                            Nacimiento <span class="text-red-500">*</span></label>
                                        <input type="date" name="fecha_nacimiento" required
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1 md:col-span-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Edad</label>
                                        <input type="number" name="edad"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1 md:col-span-2">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Lugar
                                            Nacimiento</label>
                                        <input type="text" name="lugar_nacimiento"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">País
                                            Nacimiento</label>
                                        <input type="text" name="pais_nacimiento"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1">
                                        <label
                                            class="block text-xs font-semibold text-slate-600 uppercase">Nacionalidad</label>
                                        <input type="text" name="nacionalidad"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Sexo <span
                                                class="text-red-500">*</span></label>
                                        <select name="sexo" required
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                            <option value=""></option>
                                            <option value="Masculino">Masculino</option>
                                            <option value="Femenino">Femenino</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Estado Civil
                                            <span class="text-red-500">*</span></label>
                                        <select name="estado_civil" required
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                            <option value=""></option>
                                            <option value="Soltero">Soltero/a</option>
                                            <option value="Casado">Casado/a</option>
                                            <option value="Union libre">Unión Libre</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Tipo
                                            Sangre</label>
                                        <input type="text" name="tipo_sangre"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1">
                                        <label
                                            class="block text-xs font-semibold text-slate-600 uppercase">Estatura</label>
                                        <input type="text" name="estatura"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Peso</label>
                                        <input type="text" name="peso"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Vive con
                                            (Parentesco)</label>
                                        <input type="text" name="vive_con"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Personas
                                            Dependientes</label>
                                        <input type="number" name="personas_dependen"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">¿Tiene
                                            Hijos?</label>
                                        <div class="flex gap-4 mt-2">
                                            <label class="inline-flex items-center"><input type="radio"
                                                    name="tiene_hijos" value="SI" class="text-brand-600 ring-brand-500">
                                                <span class="ml-2 text-sm text-slate-700">SI</span></label>
                                            <label class="inline-flex items-center"><input type="radio"
                                                    name="tiene_hijos" value="NO" class="text-brand-600 ring-brand-500">
                                                <span class="ml-2 text-sm text-slate-700">NO</span></label>
                                        </div>
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Edad de sus
                                            hijos</label>
                                        <input type="text" name="edad_hijos"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">¿Vive en
                                            casa propia?</label>
                                        <div class="flex gap-4 mt-2">
                                            <label class="inline-flex items-center"><input type="radio"
                                                    name="casa_propia" value="SI" class="text-brand-600 ring-brand-500">
                                                <span class="ml-2 text-sm text-slate-700">SI</span></label>
                                            <label class="inline-flex items-center"><input type="radio"
                                                    name="casa_propia" value="NO" class="text-brand-600 ring-brand-500">
                                                <span class="ml-2 text-sm text-slate-700">NO</span></label>
                                        </div>
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">¿Con cuántas
                                            personas vive?</label>
                                        <input type="number" name="personas_vive"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                </div>
                            </div>

                            <!-- Section 2: Disponibilidad y Modalidad -->
                            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                                <h4
                                    class="text-lg font-bold text-slate-800 mb-6 flex items-center gap-2 pb-2 border-b border-slate-100">
                                    <span
                                        class="w-8 h-8 rounded-lg bg-orange-100 text-orange-600 flex items-center justify-center text-sm">2</span>
                                    Disponibilidad y Modalidad
                                </h4>

                                <div class="mb-6">
                                    <p
                                        class="text-xs text-slate-500 italic mb-3 bg-slate-50 p-3 rounded-lg border border-slate-200">
                                        Evallish opera en horarios rotativos 7:30 AM - 10:30 PM (L-D, 1 día libre). L-V
                                        de 8:30 AM - 5:30 PM.
                                    </p>
                                    <div class="space-y-2">
                                        <label class="flex items-start gap-2"><input type="checkbox"
                                                name="disponibilidad_turno_rotativo" value="SI"
                                                class="mt-1 rounded text-brand-600"> <span class="text-sm">Disponible
                                                turno rotativo</span></label>
                                        <label class="flex items-start gap-2"><input type="checkbox"
                                                name="disponibilidad_lunes_viernes" value="SI"
                                                class="mt-1 rounded text-brand-600"> <span class="text-sm">Solo Lunes a
                                                Viernes (8:30am - 5:30pm)</span></label>
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" name="disponibilidad_otro" value="SI"
                                                id="toggleOtroHorario" class="rounded text-brand-600">
                                            <span class="text-sm">Otra disponibilidad:</span>
                                            <input type="text" name="disponibilidad_otro_texto" id="inputOtroHorario" class="hidden flex-1 rounded-lg border-slate-200 bg-slate-50 px-3 py-1.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-2 focus:ring-brand-500/10 transition-all placeholder-slate-400">
                                        </label>
                                    </div>
                                </div>

                                <div class="mb-6">
                                    <h5 class="text-sm font-bold uppercase text-slate-700 mb-2">Modalidad Solicitada
                                    </h5>
                                    <div class="space-y-2">
                                        <label class="flex items-center gap-2"><input type="checkbox"
                                                name="modalidad_presencial" value="SI" class="rounded text-brand-600">
                                            <span class="text-sm">Presencial</span></label>
                                        <label class="flex items-center gap-2"><input type="checkbox"
                                                name="modalidad_hibrida" value="SI" class="rounded text-brand-600">
                                            <span class="text-sm">Híbrida</span></label>
                                        <label class="flex items-center gap-2"><input type="checkbox"
                                                name="modalidad_remota" value="SI" class="rounded text-brand-600"> <span
                                                class="text-sm">Remota</span></label>
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" name="modalidad_otro" value="SI"
                                                id="toggleOtraModalidad" class="rounded text-brand-600">
                                            <span class="text-sm">Otra:</span>
                                            <input type="text" name="modalidad_otro_texto" id="inputOtraModalidad" class="hidden flex-1 rounded-lg border-slate-200 bg-slate-50 px-3 py-1.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-2 focus:ring-brand-500/10 transition-all placeholder-slate-400">
                                        </label>
                                    </div>
                                </div>

                                <div>
                                    <h5 class="text-sm font-bold uppercase text-slate-700 mb-2">Transporte a Evallish
                                    </h5>
                                    <div class="flex flex-wrap gap-4 mb-3">
                                        <label class="flex items-center gap-2"><input type="checkbox"
                                                name="transporte_carro_publico" value="SI"
                                                class="rounded text-brand-600"> <span class="text-sm">Carro
                                                público</span></label>
                                        <label class="flex items-center gap-2"><input type="checkbox"
                                                name="transporte_motoconcho" value="SI" class="rounded text-brand-600">
                                            <span class="text-sm">Motoconcho</span></label>
                                        <label class="flex items-center gap-2"><input type="checkbox"
                                                name="transporte_a_pie" value="SI" class="rounded text-brand-600"> <span
                                                class="text-sm">A pie</span></label>
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" name="transporte_otro" value="SI"
                                                id="toggleOtroTransporte" class="rounded text-brand-600">
                                            <span class="text-sm">Otro:</span>
                                            <input type="text" name="transporte_otro_texto" id="inputOtroTransporte" class="hidden rounded-lg border-slate-200 bg-slate-50 px-3 py-1.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-2 focus:ring-brand-500/10 transition-all placeholder-slate-400 w-40">
                                        </label>
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">Detalles
                                            rutas / tiempo estimado</label>
                                        <textarea name="transporte_detalles" rows="2"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400"></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 3: Educación -->
                            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                                <h4
                                    class="text-lg font-bold text-slate-800 mb-6 flex items-center gap-2 pb-2 border-b border-slate-100">
                                    <span
                                        class="w-8 h-8 rounded-lg bg-green-100 text-green-600 flex items-center justify-center text-sm">3</span>
                                    Educación
                                </h4>

                                <div class="flex flex-wrap gap-4 mb-4">
                                    <label class="flex items-center gap-2"><input type="checkbox" name="nivel_primaria"
                                            value="SI" class="rounded text-brand-600"> <span
                                            class="text-sm">Primaria</span></label>
                                    <label class="flex items-center gap-2"><input type="checkbox"
                                            name="nivel_bachillerato" value="SI" class="rounded text-brand-600"> <span
                                            class="text-sm">Bachillerato</span></label>
                                    <label class="flex items-center gap-2"><input type="checkbox"
                                            name="nivel_estudiante_universitario" value="SI"
                                            class="rounded text-brand-600"> <span class="text-sm">Estudiante
                                            Univ.</span></label>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                    <div>
                                        <label class="flex items-center gap-2 mb-1"><input type="checkbox"
                                                name="nivel_tecnico" value="SI" class="rounded text-brand-600"> <span
                                                class="text-xs font-bold uppercase text-slate-600">Técnico</span></label>
                                        <input type="text" name="nivel_tecnico_detalle" placeholder="¿Cuál?"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div>
                                        <label class="flex items-center gap-2 mb-1"><input type="checkbox"
                                                name="nivel_carrera_completa" value="SI" class="rounded text-brand-600">
                                            <span
                                                class="text-xs font-bold uppercase text-slate-600">Carrera</span></label>
                                        <input type="text" name="nivel_carrera_detalle" placeholder="¿Cuál?"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                    <div>
                                        <label class="flex items-center gap-2 mb-1"><input type="checkbox"
                                                name="nivel_postgrado" value="SI" class="rounded text-brand-600"> <span
                                                class="text-xs font-bold uppercase text-slate-600">Postgrado</span></label>
                                        <input type="text" name="nivel_postgrado_detalle" placeholder="¿Cuál?"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                    </div>
                                </div>

                                <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 mb-4">
                                    <div class="flex items-center gap-2 mb-3">
                                        <input type="checkbox" name="estudia_actualmente" value="SI" id="toggleEstudia"
                                            class="rounded text-brand-600">
                                        <span class="text-sm font-bold text-slate-700">Si estudia actualmente, favor
                                            completar:</span>
                                    </div>
                                    <div id="estudiaDetails" class="hidden grid-cols-1 md:grid-cols-3 gap-4">
                                        <input type="text" name="que_estudia" placeholder="¿Qué estudia?"
                                            class="rounded-lg border-slate-300 text-sm">
                                        <input type="text" name="donde_estudia" placeholder="¿Dónde?"
                                            class="rounded-lg border-slate-300 text-sm">
                                        <input type="text" name="horario_clases" placeholder="Horario"
                                            class="rounded-lg border-slate-300 text-sm">
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <h5 class="text-sm font-bold uppercase text-slate-600 mb-2">Otros Cursos</h5>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm text-left text-slate-500">
                                            <thead class="text-xs text-slate-700 uppercase bg-slate-100">
                                                <tr>
                                                    <th class="px-2 py-2">Curso</th>
                                                    <th class="px-2 py-2">Institución</th>
                                                    <th class="px-2 py-2">Fecha</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php for ($i = 1; $i <= 3; $i++): ?>
                                                    <tr class="border-b">
                                                        <td class="p-1"><input type="text"
                                                                name="otros_curso_<?php echo $i; ?>"
                                                                class="w-full rounded border border-slate-200 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all">
                                                        </td>
                                                        <td class="p-1"><input type="text"
                                                                name="otros_curso_institucion_<?php echo $i; ?>"
                                                                class="w-full rounded border border-slate-200 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all">
                                                        </td>
                                                        <td class="p-1"><input type="text"
                                                                name="otros_curso_fecha_<?php echo $i; ?>"
                                                                class="w-full rounded border border-slate-200 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all">
                                                        </td>
                                                    </tr>
                                                <?php endfor; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div>
                                    <h5 class="text-sm font-bold uppercase text-slate-600 mb-2">Idiomas</h5>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm text-left text-slate-500">
                                            <thead class="text-xs text-slate-700 uppercase bg-slate-100">
                                                <tr>
                                                    <th class="px-2 py-2">Idioma</th>
                                                    <th class="px-2 py-2 text-center">Habla</th>
                                                    <th class="px-2 py-2 text-center">Lee</th>
                                                    <th class="px-2 py-2 text-center">Escribe</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php for ($i = 1; $i <= 3; $i++): ?>
                                                    <tr class="border-b">
                                                        <td class="p-1"><input type="text"
                                                                name="idioma_<?php echo $i; ?>_nombre"
                                                                class="w-full rounded border border-slate-200 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all"
                                                                placeholder="Ej: Inglés"></td>
                                                        <td class="p-1 text-center"><select
                                                                name="idioma_<?php echo $i; ?>_habla"
                                                                class="rounded border border-slate-200 bg-white px-2 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all w-20">
                                                                <option value=""></option>
                                                                <option value="SI">SI</option>
                                                                <option value="NO">NO</option>
                                                            </select></td>
                                                        <td class="p-1 text-center"><select
                                                                name="idioma_<?php echo $i; ?>_lee"
                                                                class="rounded border border-slate-200 bg-white px-2 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all w-20">
                                                                <option value=""></option>
                                                                <option value="SI">SI</option>
                                                                <option value="NO">NO</option>
                                                            </select></td>
                                                        <td class="p-1 text-center"><select
                                                                name="idioma_<?php echo $i; ?>_escribe"
                                                                class="rounded border border-slate-200 bg-white px-2 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all w-20">
                                                                <option value=""></option>
                                                                <option value="SI">SI</option>
                                                                <option value="NO">NO</option>
                                                            </select></td>
                                                    </tr>
                                                <?php endfor; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 4: Experiencias Laborales -->
                            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                                <h4
                                    class="text-lg font-bold text-slate-800 mb-6 flex items-center gap-2 pb-2 border-b border-slate-100">
                                    <span
                                        class="w-8 h-8 rounded-lg bg-pink-100 text-pink-600 flex items-center justify-center text-sm">4</span>
                                    Experiencias Laborales
                                </h4>

                                <?php for ($e = 1; $e <= 2; $e++): ?>
                                    <div class="mb-6 p-4 rounded-xl border border-slate-200 bg-slate-50/50">
                                        <h5 class="text-sm font-bold text-brand-600 mb-3">Experiencia <?php echo $e; ?></h5>
                                        <div class="grid grid-cols-1 md:grid-cols-6 gap-3 mb-3">
                                            <div class="md:col-span-2"><input type="text"
                                                    name="exp<?php echo $e; ?>_empresa" placeholder="Empresa"
                                                    class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                            </div>
                                            <div class="md:col-span-2"><input type="text"
                                                    name="exp<?php echo $e; ?>_superior" placeholder="Superior Inmediato"
                                                    class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                            </div>
                                            <div class="md:col-span-2"><input type="text" name="exp<?php echo $e; ?>_tiempo"
                                                    placeholder="Tiempo Trabajado"
                                                    class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                            </div>
                                            <div class="md:col-span-2"><input type="text"
                                                    name="exp<?php echo $e; ?>_telefono" placeholder="Teléfono"
                                                    class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                            </div>
                                            <div class="md:col-span-2"><input type="text" name="exp<?php echo $e; ?>_cargo"
                                                    placeholder="Cargo"
                                                    class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                            </div>
                                            <div class="md:col-span-2"><input type="text" name="exp<?php echo $e; ?>_sueldo"
                                                    placeholder="Sueldo"
                                                    class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                            </div>
                                        </div>
                                        <div class="space-y-2">
                                            <textarea name="exp<?php echo $e; ?>_tareas" rows="2"
                                                placeholder="Tareas Principales"
                                                class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400"></textarea>
                                            <textarea name="exp<?php echo $e; ?>_razon_salida" rows="2"
                                                placeholder="Razón de salida"
                                                class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400"></textarea>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>

                            <!-- Section 5: Información General -->
                            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                                <h4
                                    class="text-lg font-bold text-slate-800 mb-6 flex items-center gap-2 pb-2 border-b border-slate-100">
                                    <span
                                        class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm">5</span>
                                    Información General
                                </h4>

                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-600 uppercase">¿Cuál ha
                                            sido su mayor logro?</label>
                                        <textarea name="mayor_logro" rows="2"
                                            class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400"></textarea>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label
                                                class="block text-xs font-semibold text-slate-600 uppercase">Expectativa
                                                Salarial</label>
                                            <input type="text" name="expectativas_salariales"
                                                class="w-full rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                        </div>
                                        <div>
                                            <label
                                                class="block text-xs font-semibold text-slate-600 uppercase">¿Problema
                                                de incapacidad?</label>
                                            <div class="flex gap-4 mt-1">
                                                <label class="inline-flex items-center"><input type="radio"
                                                        name="incapacidad" value="NO"
                                                        class="text-brand-600 ring-brand-500"> <span
                                                        class="ml-2 text-sm">NO</span></label>
                                                <label class="inline-flex items-center"><input type="radio"
                                                        name="incapacidad" value="SI" id="toggleIncapacidad"
                                                        class="text-brand-600 ring-brand-500"> <span
                                                        class="ml-2 text-sm">SI (Especifique)</span></label>
                                            </div>
                                            <input type="text" name="incapacidad_cual" id="inputIncapacidad" class="hidden w-full mt-2 rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label
                                                class="block text-xs font-semibold text-slate-600 uppercase">¿Dispuesto
                                                a trabajar horas extras?</label>
                                            <div class="flex gap-4 mt-1">
                                                <label class="inline-flex items-center"><input type="radio"
                                                        name="horas_extras" value="SI"
                                                        class="text-brand-600 ring-brand-500"> <span
                                                        class="ml-2 text-sm">SI</span></label>
                                                <label class="inline-flex items-center"><input type="radio"
                                                        name="horas_extras" value="NO"
                                                        class="text-brand-600 ring-brand-500"> <span
                                                        class="ml-2 text-sm">NO</span></label>
                                            </div>
                                        </div>
                                        <div>
                                            <label
                                                class="block text-xs font-semibold text-slate-600 uppercase">¿Dispuesto
                                                a trabajar días de fiesta?</label>
                                            <div class="flex gap-4 mt-1">
                                                <label class="inline-flex items-center"><input type="radio"
                                                        name="dias_fiestas" value="SI"
                                                        class="text-brand-600 ring-brand-500"> <span
                                                        class="ml-2 text-sm">SI</span></label>
                                                <label class="inline-flex items-center"><input type="radio"
                                                        name="dias_fiestas" value="NO"
                                                        class="text-brand-600 ring-brand-500"> <span
                                                        class="ml-2 text-sm">NO</span></label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-600 uppercase">¿Conoce
                                                algún empleado?</label>
                                            <div class="flex gap-4 mt-1">
                                                <label class="inline-flex items-center"><input type="radio"
                                                        name="conoce_empleado" value="NO"
                                                        class="text-brand-600 ring-brand-500"> <span
                                                        class="ml-2 text-sm">NO</span></label>
                                                <label class="inline-flex items-center"><input type="radio"
                                                        name="conoce_empleado" value="SI" id="toggleConoce"
                                                        class="text-brand-600 ring-brand-500"> <span
                                                        class="ml-2 text-sm">SI (Nombre)</span></label>
                                            </div>
                                            <input type="text" name="conoce_empleado_nombre" id="inputConoce" class="hidden w-full mt-2 rounded-lg border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-700 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all duration-200 placeholder-slate-400">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-600 uppercase">¿Cómo se
                                                enteró de la vacante?</label>
                                            <div class="flex flex-wrap gap-2 mt-1">
                                                <label><input type="checkbox" name="medio_vacante[]" value="WhatsApp">
                                                    <span class="text-xs">WhatsApp</span></label>
                                                <label><input type="checkbox" name="medio_vacante[]" value="Instagram">
                                                    <span class="text-xs">Instagram</span></label>
                                                <label><input type="checkbox" name="medio_vacante[]" value="Telegram">
                                                    <span class="text-xs">Telegram</span></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 6: CV Attachment -->
                            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                                <h4
                                    class="text-lg font-bold text-slate-800 mb-6 flex items-center gap-2 pb-2 border-b border-slate-100">
                                    <span
                                        class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm">6</span>
                                    Curriculum Vitae
                                </h4>

                                <div class="flex items-center justify-center w-full">
                                    <label for="cv-file"
                                        class="flex flex-col items-center justify-center w-full h-32 border-2 border-slate-300 border-dashed rounded-lg cursor-pointer bg-slate-50 hover:bg-slate-100 transition-colors">
                                        <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                            <i class="fas fa-cloud-upload-alt text-3xl text-slate-400 mb-3"></i>
                                            <p class="mb-2 text-sm text-slate-500"><span class="font-semibold">Click
                                                    para subir</span> o arrastra y suelta</p>
                                            <p class="text-xs text-slate-500">PDF, DOCX (Max. 5MB)</p>
                                        </div>
                                        <input id="cv-file" type="file" name="cv_file" class="hidden"
                                            accept=".pdf,.doc,.docx" />
                                    </label>
                                </div>
                                <div id="cv-preview" class="mt-2 text-center text-sm font-semibold text-brand-600">
                                </div>
                            </div>

                            <div class="flex items-start gap-3 mt-6">
                                <input type="checkbox" name="acepta_datos" value="SI" required
                                    class="mt-1 rounded text-brand-600 focus:ring-brand-500">
                                <p class="text-xs text-slate-500">
                                    Doy fe que todos los datos suministrados en esta solicitud son verdaderos y autorizo
                                    a cualquier investigación sobre mis declaraciones.
                                </p>
                            </div>

                        </form>
                    </div>

                    <div class="bg-slate-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 border-t border-slate-200">
                        <button type="button" onclick="submitApplication()"
                            class="inline-flex w-full justify-center rounded-xl bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-brand-500 sm:ml-3 sm:w-auto"
                            id="btnSubmit">Enviar Solicitud</button>
                        <button type="button" onclick="closeModal()"
                            class="mt-3 inline-flex w-full justify-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-slate-300 hover:bg-slate-50 sm:mt-0 sm:w-auto">Cancelar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('applicationModal');
        const modalBackdrop = document.getElementById('modalBackdrop');
        const modalPanel = document.getElementById('modalPanel');
        const form = document.getElementById('applicationForm');

        function openApplicationModal(jobId, jobTitle) {
            document.getElementById('job_posting_id').value = jobId;
            document.getElementById('puesto_aplicado').value = jobTitle;
            document.getElementById('modalJobTitle').textContent = jobTitle;
            modal.classList.remove('hidden');
            setTimeout(() => {
                modalBackdrop.classList.remove('opacity-0');
                modalPanel.classList.remove('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');
                modalPanel.classList.add('opacity-100', 'translate-y-0', 'sm:scale-100');
            }, 10);
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modalBackdrop.classList.remove('opacity-100');
            modalPanel.classList.remove('opacity-100', 'translate-y-0', 'sm:scale-100');
            modalPanel.classList.add('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
                document.body.style.overflow = '';
                form.reset();
                document.getElementById('cv-preview').textContent = '';

                // Hide all dynamic fields on close
                ['inputOtroHorario', 'inputOtraModalidad', 'inputOtroTransporte', 'inputIncapacidad', 'inputConoce'].forEach(id => {
                    document.getElementById(id)?.classList.add('hidden');
                });
                const est = document.getElementById('estudiaDetails');
                if (est) { est.classList.add('hidden'); est.classList.remove('grid'); }
            }, 300);
        }

        // Toggles for checkboxes
        function setupToggle(triggerId, targetId, isGrid = false) {
            const trigger = document.getElementById(triggerId);
            const target = document.getElementById(targetId);
            if (trigger && target) {
                trigger.addEventListener('change', function () {
                    if (this.checked) {
                        target.classList.remove('hidden');
                        if (isGrid) target.classList.add('grid');
                    } else {
                        target.classList.add('hidden');
                        if (isGrid) target.classList.remove('grid');
                    }
                });
            }
        }

        setupToggle('toggleOtroHorario', 'inputOtroHorario');
        setupToggle('toggleOtraModalidad', 'inputOtraModalidad');
        setupToggle('toggleOtroTransporte', 'inputOtroTransporte');
        setupToggle('toggleEstudia', 'estudiaDetails', true);

        // Toggles for Radio Buttons
        function setupRadioToggle(name, targetId, showValue = 'SI') {
            const radios = document.querySelectorAll(`input[name="${name}"]`);
            const target = document.getElementById(targetId);

            radios.forEach(radio => {
                radio.addEventListener('change', function () {
                    if (!target) return;
                    if (this.value === showValue && this.checked) {
                        target.classList.remove('hidden');
                    } else if (this.checked) {
                        target.classList.add('hidden');
                    }
                });
            });
        }

        setupRadioToggle('incapacidad', 'inputIncapacidad', 'SI');
        setupRadioToggle('conoce_empleado', 'inputConoce', 'SI');

        async function submitApplication() {
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            const btn = document.getElementById('btnSubmit');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Enviando...';
            btn.disabled = true;

            try {
                const response = await fetch('submit_application.php', { method: 'POST', body: new FormData(form) });
                const result = await response.json();

                if (result.success) {
                    closeModal();
                    Swal.fire({
                        title: '¡Recibido!',
                        text: 'Solicitud enviada exitosamente.',
                        icon: 'success',
                        confirmButtonColor: '#0ea5e9'
                    });
                } else {
                    Swal.fire({ title: 'Error', text: result.message, icon: 'error', confirmButtonColor: '#ef4444' });
                }
            } catch (error) {
                Swal.fire({ title: 'Error', text: 'Error de conexión', icon: 'error' });
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        window.addEventListener('scroll', () => {
            const nav = document.getElementById('navbar');
            if (window.scrollY > 20) {
                nav.classList.add('shadow-md', 'bg-white/95');
                nav.classList.remove('py-2');
            } else {
                nav.classList.remove('shadow-md', 'bg-white/95');
            }
        });
    </script>
</body>

</html>