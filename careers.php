<?php
// Public careers page — minimalist redesign with AI-assisted CV intake.
session_start();
require_once 'db.php';

// Active job postings
$stmt = $pdo->query("SELECT * FROM job_postings WHERE status = 'active' AND (closing_date IS NULL OR closing_date >= CURDATE()) ORDER BY posted_date DESC");
$job_postings = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Departments / employment-type filters (UI hints)
$departments = [];
foreach ($job_postings as $j) {
    if (!empty($j['department']) && !in_array($j['department'], $departments, true)) {
        $departments[] = $j['department'];
    }
}

$shareJobId = isset($_GET['job']) ? (int) $_GET['job'] : null;
$shareJob = null;
if ($shareJobId) {
    foreach ($job_postings as $j) {
        if ((int) $j['id'] === $shareJobId) {
            $shareJob = $j;
            break;
        }
    }
}

$shareTitle = $shareJob ? ($shareJob['title'] . " | " . $company_name) : ("Carreras | " . $company_name);
$shareDescription = $shareJob
    ? substr(strip_tags(preg_replace('/\s+/', ' ', $shareJob['description'] ?? '')), 0, 180)
    : "Descubre las vacantes disponibles y unete al equipo de {$company_name}.";
$shareImage = $shareJob && !empty($shareJob['banner_url']) ? $shareJob['banner_url'] : ($base_url . '/assets/logo.png');
$shareUrl = $base_url . '/careers.php' . ($shareJobId ? '?job=' . $shareJobId : '');

$employment_types = [
    'full_time'  => 'Tiempo Completo',
    'part_time'  => 'Medio Tiempo',
    'contract'   => 'Contrato',
    'internship' => 'Pasantia',
];
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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Outfit', 'sans-serif'] },
                    colors: {
                        ink: '#0b1220',
                        brand: {
                            50: '#eef4ff', 100: '#dbe7ff', 200: '#bccfff',
                            300: '#90aeff', 400: '#5e85ff', 500: '#3a63f5',
                            600: '#264be3', 700: '#1f3bbe', 800: '#1c3299', 900: '#1a2d7a'
                        }
                    },
                    boxShadow: {
                        'soft': '0 10px 30px -12px rgba(15, 23, 42, 0.12)',
                        'pop':  '0 25px 60px -25px rgba(38, 75, 227, 0.45)'
                    }
                }
            }
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body { font-family: 'Outfit', sans-serif; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .glass-nav {
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }
        .grain-bg {
            background-image:
                radial-gradient(circle at 0% 0%, rgba(58,99,245,0.10), transparent 45%),
                radial-gradient(circle at 100% 0%, rgba(124,58,237,0.10), transparent 45%);
        }
        .job-card { transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease; }
        .job-card:hover { transform: translateY(-4px); border-color: rgba(58,99,245,0.45); box-shadow: 0 20px 40px -20px rgba(58,99,245,0.35); }
        .chip { padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .form-input {
            width: 100%; border-radius: 12px;
            border: 1px solid #e2e8f0; background: #f8fafc;
            padding: 12px 14px; font-size: 14px; color: #0f172a;
            transition: all .15s ease;
        }
        .form-input:focus { outline: none; border-color: #3a63f5; background: #fff; box-shadow: 0 0 0 4px rgba(58,99,245,0.12); }
        .form-label { display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; }
        .req::after { content: ' *'; color: #ef4444; }

        .dropzone {
            border: 2px dashed #cbd5e1; border-radius: 14px; background: #f8fafc;
            transition: background .2s, border-color .2s;
        }
        .dropzone:hover, .dropzone.drag { background: #eef4ff; border-color: #3a63f5; }

        .ai-banner {
            background: linear-gradient(135deg, #1e1b4b 0%, #3730a3 50%, #6d28d9 100%);
        }
        .pulse-dot { animation: pulse 1.5s infinite; }
        @keyframes pulse {
            0%,100% { opacity: 1; }
            50% { opacity: .35; }
        }
        .ai-loading {
            background: linear-gradient(90deg, transparent, rgba(58,99,245,0.18), transparent);
            background-size: 200% 100%;
            animation: shimmer 1.4s infinite linear;
        }
        @keyframes shimmer { 0% {background-position:-200% 0;} 100% {background-position:200% 0;} }

        .modal-enter { animation: enter .25s ease-out; }
        @keyframes enter { from {opacity:0; transform: scale(.97) translateY(10px);} to {opacity:1; transform: scale(1) translateY(0);} }

        .scroll-area { scrollbar-width: thin; }
    </style>
</head>

<body class="bg-slate-50 text-slate-800 antialiased selection:bg-brand-500 selection:text-white">

    <!-- Navbar -->
    <nav id="navbar" class="fixed inset-x-0 top-0 z-50 glass-nav border-b border-slate-200/70 transition-all">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <a href="careers.php" class="flex items-center gap-3 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 to-purple-600 flex items-center justify-center text-white shadow-pop">
                        <i class="fas fa-rocket"></i>
                    </div>
                    <div class="leading-tight">
                        <h1 class="font-bold text-lg tracking-tight text-slate-900"><?php echo htmlspecialchars($company_name); ?></h1>
                        <p class="text-[11px] uppercase tracking-widest text-brand-600 font-semibold">Carreras</p>
                    </div>
                </a>
                <div class="hidden md:flex items-center gap-3">
                    <a href="#vacantes" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-brand-700 transition-colors">Vacantes</a>
                    <a href="track_application.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:border-brand-500 hover:text-brand-700 transition-all shadow-soft">
                        <i class="fas fa-search"></i><span>Rastrear Solicitud</span>
                    </a>
                </div>
                <button class="md:hidden p-2 text-slate-700" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="relative pt-28 lg:pt-36 pb-12 lg:pb-20 overflow-hidden grain-bg">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white border border-slate-200 shadow-soft text-xs font-semibold text-slate-700 mb-6">
                <span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span>
                <?php echo count($job_postings); ?> vacantes activas
                <span class="text-slate-300">·</span>
                <span class="inline-flex items-center gap-1 text-purple-600"><i class="fas fa-wand-magic-sparkles"></i> Postula con IA</span>
            </div>

            <h1 class="text-4xl md:text-6xl font-extrabold text-slate-900 tracking-tight mb-5 leading-[1.05]">
                Postula en
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-600 to-purple-600">menos de 60 segundos</span>
            </h1>
            <p class="max-w-xl mx-auto text-base md:text-lg text-slate-600 mb-8">
                Sube tu CV y nuestra IA pre-llenará tu solicitud. Sólo confirmas tus datos.
            </p>
            <div class="flex flex-wrap justify-center gap-3">
                <a href="#vacantes" class="px-6 py-3 rounded-full bg-slate-900 text-white font-semibold shadow-pop hover:bg-brand-700 transition-colors flex items-center gap-2">
                    Ver vacantes <i class="fas fa-arrow-down text-xs"></i>
                </a>
                <button onclick="openOpenApplication()" class="px-6 py-3 rounded-full bg-white text-slate-900 font-semibold border border-slate-200 hover:border-brand-500 hover:text-brand-700 transition-all flex items-center gap-2 shadow-soft">
                    <i class="fas fa-file-arrow-up"></i> Postulación abierta
                </button>
            </div>
        </div>
    </section>

    <!-- Filters & Vacantes -->
    <section id="vacantes" class="py-14 lg:py-20 bg-white border-t border-slate-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="flex flex-wrap items-end justify-between gap-6 mb-10">
                <div>
                    <h2 class="text-2xl md:text-3xl font-bold text-slate-900">Oportunidades disponibles</h2>
                    <p class="text-slate-500 text-sm mt-1">Encuentra la posición ideal para ti.</p>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 sm:items-center w-full md:w-auto">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" id="jobSearch" placeholder="Buscar puesto, departamento..."
                               class="pl-9 pr-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none w-full sm:w-72">
                    </div>
                    <?php if (!empty($departments)): ?>
                        <select id="deptFilter" class="px-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none">
                            <option value="">Todos los departamentos</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <div id="jobGrid" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php if (empty($job_postings)): ?>
                    <div class="col-span-full text-center py-20 bg-slate-50 rounded-3xl border-2 border-dashed border-slate-200">
                        <i class="fas fa-briefcase text-3xl text-slate-400 mb-3"></i>
                        <h3 class="text-lg font-semibold text-slate-900">Por ahora no hay vacantes activas</h3>
                        <p class="text-slate-500 mt-1">Vuelve pronto o envía una postulación abierta.</p>
                        <button onclick="openOpenApplication()" class="mt-5 px-5 py-2.5 rounded-full bg-slate-900 text-white text-sm font-semibold hover:bg-brand-700 transition-colors">
                            <i class="fas fa-file-arrow-up mr-1"></i> Postulación abierta
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($job_postings as $job): ?>
                        <article class="job-card flex flex-col bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-soft"
                                 data-title="<?php echo htmlspecialchars(strtolower($job['title'])); ?>"
                                 data-dept="<?php echo htmlspecialchars($job['department'] ?? ''); ?>">
                            <div class="relative h-32 bg-gradient-to-br from-brand-600 to-purple-600 overflow-hidden">
                                <?php if (!empty($job['banner_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($job['banner_url']); ?>" alt="" class="w-full h-full object-cover">
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/15 to-transparent"></div>
                                <?php else: ?>
                                    <div class="absolute -right-8 -bottom-8 opacity-25">
                                        <i class="fas fa-briefcase text-[160px] text-white"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="absolute top-3 left-3 flex flex-wrap gap-2">
                                    <?php if (!empty($job['department'])): ?>
                                        <span class="chip bg-white/95 text-slate-800"><i class="fas fa-building mr-1 text-brand-600"></i><?php echo htmlspecialchars($job['department']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="absolute bottom-3 left-3 right-3">
                                    <h3 class="text-white text-lg font-bold leading-tight line-clamp-2 drop-shadow"><?php echo htmlspecialchars($job['title']); ?></h3>
                                </div>
                            </div>
                            <div class="p-5 flex flex-col flex-1">
                                <div class="flex flex-wrap gap-2 text-xs text-slate-600 mb-3">
                                    <?php if (!empty($job['location'])): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-slate-100"><i class="fas fa-map-marker-alt text-brand-500"></i> <?php echo htmlspecialchars($job['location']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($job['employment_type'])): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-indigo-50 text-indigo-700"><i class="fas fa-clock"></i> <?php echo $employment_types[$job['employment_type']] ?? $job['employment_type']; ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($job['salary_range'])): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-emerald-50 text-emerald-700 font-semibold"><i class="fas fa-money-bill-wave"></i> <?php echo htmlspecialchars($job['salary_range']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-slate-600 text-sm leading-relaxed line-clamp-3 mb-5">
                                    <?php echo htmlspecialchars(substr(trim((string) $job['description']), 0, 180)); ?>...
                                </p>
                                <div class="mt-auto flex gap-2">
                                    <button onclick='openJobModal(<?php echo htmlspecialchars(json_encode([
                                        "id"               => $job["id"],
                                        "title"            => $job["title"],
                                        "department"       => $job["department"]       ?? "",
                                        "location"         => $job["location"]         ?? "",
                                        "employment_type"  => $job["employment_type"]  ?? "",
                                        "salary_range"     => $job["salary_range"]     ?? "",
                                        "description"      => $job["description"]      ?? "",
                                        "requirements"     => $job["requirements"]     ?? "",
                                        "responsibilities" => $job["responsibilities"] ?? "",
                                    ]), ENT_QUOTES); ?>)'
                                        class="flex-1 inline-flex items-center justify-center gap-2 py-2.5 rounded-xl bg-slate-900 text-white text-sm font-semibold hover:bg-brand-700 transition-colors">
                                        Ver y postular <i class="fas fa-arrow-right text-xs"></i>
                                    </button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="noResults" class="hidden col-span-full text-center py-12 mt-6 bg-slate-50 rounded-2xl border border-slate-200">
                <i class="fas fa-search text-2xl text-slate-400 mb-2"></i>
                <p class="text-slate-600 text-sm">No se encontraron vacantes con esos criterios.</p>
            </div>
        </div>
    </section>

    <!-- Why us -->
    <section class="py-14 bg-slate-900 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-3 gap-6">
                <div class="p-6 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10 transition-colors">
                    <div class="w-10 h-10 rounded-lg bg-brand-600 flex items-center justify-center mb-3"><i class="fas fa-bolt"></i></div>
                    <h3 class="font-semibold mb-1">Postulación express</h3>
                    <p class="text-sm text-slate-400">Sube tu CV y completamos los datos por ti con IA.</p>
                </div>
                <div class="p-6 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10 transition-colors">
                    <div class="w-10 h-10 rounded-lg bg-purple-600 flex items-center justify-center mb-3"><i class="fas fa-shield-halved"></i></div>
                    <h3 class="font-semibold mb-1">Datos seguros</h3>
                    <p class="text-sm text-slate-400">Tu información se almacena cifrada y se usa sólo para reclutamiento.</p>
                </div>
                <div class="p-6 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10 transition-colors">
                    <div class="w-10 h-10 rounded-lg bg-emerald-600 flex items-center justify-center mb-3"><i class="fas fa-compass"></i></div>
                    <h3 class="font-semibold mb-1">Seguimiento en línea</h3>
                    <p class="text-sm text-slate-400">Rastrea el estado de tu solicitud con tu código único.</p>
                </div>
            </div>
        </div>
    </section>

    <footer class="py-8 bg-slate-950 text-slate-500 text-sm text-center">
        © <?php echo date('Y'); ?> <?php echo htmlspecialchars($company_name); ?>. Todos los derechos reservados.
    </footer>

    <!-- Application Modal -->
    <div id="applicationModal" class="fixed inset-0 z-[100] hidden" role="dialog" aria-modal="true">
        <div id="modalBackdrop" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0"></div>
        <div class="fixed inset-0 z-10 overflow-y-auto">
            <div class="flex min-h-full items-end sm:items-center justify-center p-0 sm:p-4">
                <div id="modalPanel" class="relative w-full sm:max-w-3xl bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl overflow-hidden modal-enter">
                    <!-- Header -->
                    <div class="bg-gradient-to-r from-slate-900 via-brand-700 to-purple-700 px-6 py-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-white font-bold text-lg flex items-center gap-2"><i class="fas fa-rocket"></i> <span id="modalJobTitle">Solicitud</span></h3>
                            <p class="text-brand-100 text-xs mt-0.5" id="modalJobMeta">Postula en menos de 60 segundos</p>
                        </div>
                        <button onclick="closeModal()" class="text-white/80 hover:text-white bg-white/10 hover:bg-white/20 rounded-lg p-2"><i class="fas fa-xmark text-lg"></i></button>
                    </div>

                    <!-- Step 1: Job Info -->
                    <div id="jobInfoPanel" class="hidden bg-slate-50 max-h-[78vh] overflow-y-auto scroll-area">
                        <div class="p-6 space-y-5">
                            <div id="jobMetaBadges" class="flex flex-wrap gap-2"></div>

                            <details class="bg-white rounded-xl border border-slate-200 overflow-hidden" open>
                                <summary class="px-4 py-3 cursor-pointer font-semibold text-slate-800 flex items-center gap-2"><i class="fas fa-align-left text-brand-600"></i> Descripción</summary>
                                <div class="px-4 pb-4 text-sm text-slate-600 whitespace-pre-line" id="jobDescText"></div>
                            </details>
                            <details class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                                <summary class="px-4 py-3 cursor-pointer font-semibold text-slate-800 flex items-center gap-2"><i class="fas fa-tasks text-brand-600"></i> Responsabilidades</summary>
                                <div class="px-4 pb-4 text-sm text-slate-600 whitespace-pre-line" id="jobResponsibilitiesText"></div>
                            </details>
                            <details class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                                <summary class="px-4 py-3 cursor-pointer font-semibold text-slate-800 flex items-center gap-2"><i class="fas fa-clipboard-check text-brand-600"></i> Requisitos</summary>
                                <div class="px-4 pb-4 text-sm text-slate-600 whitespace-pre-line" id="jobRequirementsText"></div>
                            </details>

                            <button type="button" onclick="goToApplicationForm()"
                                class="w-full mt-2 px-6 py-3.5 rounded-xl bg-slate-900 text-white font-semibold hover:bg-brand-700 transition-colors flex items-center justify-center gap-2 shadow-pop">
                                <i class="fas fa-pencil-alt"></i> Comenzar mi postulación
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Form -->
                    <div id="applicationFormPanel" class="hidden bg-slate-50 max-h-[78vh] overflow-y-auto scroll-area">
                        <form id="applicationForm" class="p-6 space-y-5" enctype="multipart/form-data">
                            <input type="hidden" name="job_posting_id" id="job_posting_id">
                            <input type="hidden" name="puesto_aplicado" id="puesto_aplicado">
                            <input type="hidden" name="form_version" value="2026-05-03-compact">

                            <!-- AI banner -->
                            <div class="ai-banner rounded-2xl p-5 text-white relative overflow-hidden">
                                <div class="absolute -right-8 -top-8 opacity-15">
                                    <i class="fas fa-wand-magic-sparkles text-[120px]"></i>
                                </div>
                                <div class="relative">
                                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-purple-200">
                                        <span class="pulse-dot inline-flex w-2 h-2 rounded-full bg-emerald-300"></span> Asistido por IA
                                    </div>
                                    <h4 class="text-lg font-bold mt-1.5">¿Tienes CV?</h4>
                                    <p class="text-sm text-purple-100 mt-0.5">Súbelo y rellenamos el resto por ti.</p>

                                    <label for="cv-file" id="cvDropzone" class="mt-4 dropzone block w-full p-5 cursor-pointer text-center bg-white/10 hover:bg-white/15 border-white/30">
                                        <i class="fas fa-file-arrow-up text-3xl mb-2"></i>
                                        <p class="text-sm font-semibold"><span id="cvFileName">Subir CV (PDF, DOC, DOCX)</span></p>
                                        <p class="text-xs text-purple-200 mt-0.5" id="cvSubText">Máx. 5MB · Procesamiento automático</p>
                                        <input id="cv-file" type="file" name="cv_file" class="hidden" accept=".pdf,.doc,.docx">
                                    </label>
                                    <div id="aiStatus" class="hidden mt-3 px-3 py-2 rounded-lg bg-white/15 border border-white/30 text-sm flex items-center gap-2">
                                        <i class="fas fa-circle-notch fa-spin"></i><span id="aiStatusText">Analizando CV con IA...</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Identidad -->
                            <fieldset class="bg-white rounded-2xl border border-slate-200 p-5">
                                <legend class="px-2 text-xs font-bold uppercase tracking-widest text-brand-700">1. Tus datos</legend>
                                <div class="grid sm:grid-cols-2 gap-4 mt-3">
                                    <div>
                                        <label class="form-label req">Nombres</label>
                                        <input type="text" name="nombres" class="form-input" required>
                                    </div>
                                    <div>
                                        <label class="form-label req">Apellidos</label>
                                        <input type="text" name="apellidos" class="form-input" required>
                                    </div>
                                    <div>
                                        <label class="form-label req">Cédula / Documento</label>
                                        <input type="text" name="cedula" class="form-input" required>
                                    </div>
                                    <div>
                                        <label class="form-label req">Teléfono / WhatsApp</label>
                                        <input type="tel" name="telefono" class="form-input" required>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="form-label">Correo electrónico</label>
                                        <input type="email" name="email" class="form-input" placeholder="opcional, recomendado para recibir respuesta">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="form-label">Dirección / Sector</label>
                                        <input type="text" name="direccion" class="form-input" placeholder="Ciudad, sector">
                                    </div>
                                </div>
                            </fieldset>

                            <!-- Perfil -->
                            <fieldset class="bg-white rounded-2xl border border-slate-200 p-5">
                                <legend class="px-2 text-xs font-bold uppercase tracking-widest text-brand-700">2. Tu perfil</legend>
                                <div class="grid sm:grid-cols-2 gap-4 mt-3">
                                    <div>
                                        <label class="form-label">Puesto o cargo actual</label>
                                        <input type="text" name="current_position" class="form-input" placeholder="Ej: Agente de soporte">
                                    </div>
                                    <div>
                                        <label class="form-label">Empresa actual</label>
                                        <input type="text" name="current_company" class="form-input">
                                    </div>
                                    <div>
                                        <label class="form-label">Años de experiencia</label>
                                        <input type="number" min="0" max="60" name="years_of_experience" class="form-input" placeholder="Ej: 3">
                                    </div>
                                    <div>
                                        <label class="form-label">Expectativa salarial</label>
                                        <input type="text" name="expected_salary" class="form-input" placeholder="Ej: RD$25,000">
                                    </div>
                                    <div>
                                        <label class="form-label">Nivel educativo</label>
                                        <select name="education_level" class="form-input">
                                            <option value="">Selecciona...</option>
                                            <option>Bachillerato</option>
                                            <option>Estudiante universitario</option>
                                            <option>Técnico</option>
                                            <option>Universitario</option>
                                            <option>Postgrado / Maestría</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Disponibilidad</label>
                                        <select name="availability_preference" class="form-input">
                                            <option value="">Selecciona...</option>
                                            <option value="rotating">Turno rotativo</option>
                                            <option value="weekdays">Solo Lunes a Viernes</option>
                                            <option value="weekends">Fines de semana</option>
                                            <option value="flexible">Flexible</option>
                                        </select>
                                    </div>
                                </div>
                            </fieldset>

                            <!-- Mensaje + fuente -->
                            <fieldset class="bg-white rounded-2xl border border-slate-200 p-5">
                                <legend class="px-2 text-xs font-bold uppercase tracking-widest text-brand-700">3. Cuéntanos más</legend>
                                <div class="grid sm:grid-cols-2 gap-4 mt-3">
                                    <div class="sm:col-span-2">
                                        <label class="form-label">Mensaje breve / por qué deberíamos contratarte</label>
                                        <textarea name="cover_letter_short" rows="3" class="form-input" placeholder="Opcional. 1-2 frases con tu valor diferencial."></textarea>
                                    </div>
                                    <div>
                                        <label class="form-label">¿Cómo te enteraste?</label>
                                        <select name="source" class="form-input">
                                            <option value="">Selecciona...</option>
                                            <option>WhatsApp</option>
                                            <option>Instagram</option>
                                            <option>Facebook</option>
                                            <option>Referido</option>
                                            <option>Página web</option>
                                            <option>Otro</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">LinkedIn / Portafolio</label>
                                        <input type="url" name="linkedin_url" class="form-input" placeholder="https://linkedin.com/in/...">
                                    </div>
                                </div>
                            </fieldset>

                            <label class="flex items-start gap-3 px-2 text-sm text-slate-600 cursor-pointer">
                                <input type="checkbox" name="acepta_datos" value="SI" required class="mt-1 rounded text-brand-600">
                                <span>Confirmo que la información provista es veraz y autorizo su uso para fines de reclutamiento.</span>
                            </label>
                        </form>

                        <div class="bg-white border-t border-slate-200 px-6 py-4 flex flex-col sm:flex-row sm:justify-between gap-3 sticky bottom-0">
                            <button type="button" onclick="backToJobInfo()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-semibold text-sm hover:bg-slate-200 transition-colors flex items-center justify-center gap-2">
                                <i class="fas fa-arrow-left"></i> Ver vacante
                            </button>
                            <button type="button" id="btnSubmit" onclick="submitApplication()" class="px-6 py-2.5 rounded-xl bg-slate-900 text-white font-semibold text-sm hover:bg-brand-700 transition-colors flex items-center justify-center gap-2 shadow-pop">
                                Enviar postulación <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const employmentTypeLabels = <?php echo json_encode($employment_types, JSON_UNESCAPED_UNICODE); ?>;
        const modal = document.getElementById('applicationModal');
        const modalBackdrop = document.getElementById('modalBackdrop');
        const modalPanel = document.getElementById('modalPanel');
        const form = document.getElementById('applicationForm');

        // Job filters
        const search = document.getElementById('jobSearch');
        const dept = document.getElementById('deptFilter');
        const grid = document.getElementById('jobGrid');
        const noResults = document.getElementById('noResults');

        function applyFilters() {
            const q = (search?.value || '').toLowerCase().trim();
            const d = (dept?.value || '').trim();
            let visible = 0;
            grid.querySelectorAll('article.job-card').forEach(card => {
                const t = card.dataset.title || '';
                const dp = card.dataset.dept || '';
                const matches = (q === '' || t.includes(q)) && (d === '' || dp === d);
                card.style.display = matches ? '' : 'none';
                if (matches) visible++;
            });
            noResults.classList.toggle('hidden', visible !== 0);
        }
        search?.addEventListener('input', applyFilters);
        dept?.addEventListener('change', applyFilters);

        // Modal
        function fillSection(textId, txt) {
            const el = document.getElementById(textId);
            if (!el) return;
            const v = (txt || '').trim();
            el.textContent = v || 'No especificado.';
        }

        function openJobModal(job) {
            document.getElementById('job_posting_id').value = job.id;
            document.getElementById('puesto_aplicado').value = job.title;
            document.getElementById('modalJobTitle').textContent = job.title;
            const meta = [];
            if (job.department) meta.push(job.department);
            if (job.location) meta.push(job.location);
            if (job.employment_type) meta.push(employmentTypeLabels[job.employment_type] || job.employment_type);
            document.getElementById('modalJobMeta').textContent = meta.join(' · ');

            const badges = document.getElementById('jobMetaBadges');
            badges.innerHTML = '';
            if (job.salary_range) badges.innerHTML += `<span class="chip bg-emerald-100 text-emerald-700"><i class="fas fa-money-bill-wave mr-1"></i>${job.salary_range}</span>`;
            if (job.location) badges.innerHTML += `<span class="chip bg-slate-100 text-slate-700"><i class="fas fa-map-marker-alt mr-1 text-brand-600"></i>${job.location}</span>`;
            if (job.employment_type) badges.innerHTML += `<span class="chip bg-indigo-50 text-indigo-700">${employmentTypeLabels[job.employment_type] || job.employment_type}</span>`;
            if (job.department) badges.innerHTML += `<span class="chip bg-brand-50 text-brand-700"><i class="fas fa-building mr-1"></i>${job.department}</span>`;

            fillSection('jobDescText', job.description);
            fillSection('jobResponsibilitiesText', job.responsibilities);
            fillSection('jobRequirementsText', job.requirements);

            document.getElementById('jobInfoPanel').classList.remove('hidden');
            document.getElementById('applicationFormPanel').classList.add('hidden');
            modal.classList.remove('hidden');
            requestAnimationFrame(() => {
                modalBackdrop.classList.replace('opacity-0', 'opacity-100');
            });
            document.body.style.overflow = 'hidden';
        }

        function openOpenApplication() {
            // Postulación sin vacante específica — toma la primera vacante activa o queda 0
            <?php if (!empty($job_postings)): ?>
                openJobModal(<?php echo json_encode([
                    'id'               => $job_postings[0]['id'],
                    'title'            => 'Postulación abierta - ' . ($job_postings[0]['title'] ?? ''),
                    'department'       => $job_postings[0]['department']       ?? '',
                    'location'         => $job_postings[0]['location']         ?? '',
                    'employment_type'  => $job_postings[0]['employment_type']  ?? '',
                    'salary_range'     => $job_postings[0]['salary_range']     ?? '',
                    'description'      => 'Estamos siempre interesados en conocer a buenos candidatos. Sube tu CV y nos pondremos en contacto cuando exista una vacante adecuada.',
                    'requirements'     => '',
                    'responsibilities' => '',
                ], JSON_UNESCAPED_UNICODE); ?>);
            <?php else: ?>
                Swal.fire({ title:'No hay vacantes activas', text:'Por ahora no podemos recibir postulaciones.', icon:'info' });
            <?php endif; ?>
        }

        function goToApplicationForm() {
            document.getElementById('jobInfoPanel').classList.add('hidden');
            document.getElementById('applicationFormPanel').classList.remove('hidden');
            document.querySelector('#applicationFormPanel').scrollTop = 0;
        }

        function backToJobInfo() {
            document.getElementById('applicationFormPanel').classList.add('hidden');
            document.getElementById('jobInfoPanel').classList.remove('hidden');
        }

        function closeModal() {
            modalBackdrop.classList.replace('opacity-100', 'opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
                document.body.style.overflow = '';
                form.reset();
                document.getElementById('cvFileName').textContent = 'Subir CV (PDF, DOC, DOCX)';
                document.getElementById('aiStatus').classList.add('hidden');
                document.getElementById('jobInfoPanel').classList.add('hidden');
                document.getElementById('applicationFormPanel').classList.add('hidden');
            }, 250);
        }

        modalBackdrop.addEventListener('click', closeModal);
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });

        // CV picker UI
        const cvFile = document.getElementById('cv-file');
        const cvDropzone = document.getElementById('cvDropzone');
        const cvFileName = document.getElementById('cvFileName');
        const cvSubText = document.getElementById('cvSubText');
        const aiStatus = document.getElementById('aiStatus');
        const aiStatusText = document.getElementById('aiStatusText');

        cvFile.addEventListener('change', () => {
            if (!cvFile.files[0]) return;
            const f = cvFile.files[0];
            const sizeMb = (f.size / 1024 / 1024).toFixed(2);
            cvFileName.textContent = f.name;
            cvSubText.textContent = `${sizeMb} MB · listo para enviar`;
            // Optimistic AI hint
            aiStatus.classList.remove('hidden');
            aiStatusText.textContent = 'CV cargado. La IA procesará tu información al enviar.';
        });

        ['dragenter', 'dragover'].forEach(ev => cvDropzone.addEventListener(ev, e => { e.preventDefault(); cvDropzone.classList.add('drag'); }));
        ['dragleave', 'drop'].forEach(ev => cvDropzone.addEventListener(ev, e => { e.preventDefault(); cvDropzone.classList.remove('drag'); }));
        cvDropzone.addEventListener('drop', e => {
            const dt = e.dataTransfer; if (dt && dt.files && dt.files[0]) {
                cvFile.files = dt.files;
                cvFile.dispatchEvent(new Event('change'));
            }
        });

        async function submitApplication() {
            if (!form.checkValidity()) { form.reportValidity(); return; }
            const btn = document.getElementById('btnSubmit');
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Enviando...';
            btn.disabled = true;
            aiStatus.classList.remove('hidden');
            aiStatusText.textContent = 'Enviando solicitud y procesando con IA...';

            try {
                const fd = new FormData(form);
                const r = await fetch('submit_application.php', { method: 'POST', body: fd });
                const text = await r.text();
                let res;
                try { res = JSON.parse(text); } catch (_) {
                    throw new Error('Respuesta inválida del servidor: ' + text.slice(0, 200));
                }
                if (res.success) {
                    closeModal();
                    Swal.fire({
                        title: '¡Solicitud recibida!',
                        html: `Tu código es <code class="bg-slate-100 text-brand-700 px-2 py-0.5 rounded font-mono">${res.application_code || '—'}</code>.<br>Guárdalo para rastrear tu postulación.`,
                        icon: 'success',
                        confirmButtonText: 'Listo',
                        confirmButtonColor: '#3a63f5'
                    });
                } else {
                    Swal.fire({ title: 'Error', text: res.message || 'No se pudo enviar', icon: 'error', confirmButtonColor: '#ef4444' });
                }
            } catch (err) {
                Swal.fire({ title: 'Error', text: err.message || 'Error de conexión', icon: 'error' });
            } finally {
                btn.innerHTML = orig;
                btn.disabled = false;
                aiStatus.classList.add('hidden');
            }
        }

        // Navbar shadow on scroll
        window.addEventListener('scroll', () => {
            const n = document.getElementById('navbar');
            if (window.scrollY > 8) n.classList.add('shadow-soft');
            else n.classList.remove('shadow-soft');
        });

        // Auto-open from share link
        <?php if ($shareJob): ?>
            window.addEventListener('load', () => openJobModal(<?php echo json_encode([
                'id'               => $shareJob['id'],
                'title'            => $shareJob['title'],
                'department'       => $shareJob['department']       ?? '',
                'location'         => $shareJob['location']         ?? '',
                'employment_type'  => $shareJob['employment_type']  ?? '',
                'salary_range'     => $shareJob['salary_range']     ?? '',
                'description'      => $shareJob['description']      ?? '',
                'requirements'     => $shareJob['requirements']     ?? '',
                'responsibilities' => $shareJob['responsibilities'] ?? '',
            ], JSON_UNESCAPED_UNICODE); ?>));
        <?php endif; ?>
    </script>
</body>
</html>
