<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_recruitment_ai', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Get recruitment statistics
$totalApplications = $pdo->query("SELECT COUNT(*) FROM job_applications")->fetchColumn();
$newApplications = $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'new'")->fetchColumn();
$activePostings = $pdo->query("SELECT COUNT(*) FROM job_postings WHERE status = 'active'")->fetchColumn();
$interviewScheduled = $pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'interview_scheduled'")->fetchColumn();

// Get active job postings for filters
$jobPostings = $pdo->query("
    SELECT jp.*, 
           COUNT(ja.id) as application_count
    FROM job_postings jp
    LEFT JOIN job_applications ja ON ja.job_posting_id = jp.id
    WHERE jp.status = 'active'
    GROUP BY jp.id
    ORDER BY jp.posted_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get recent applications for quick view
$recentApplications = $pdo->query("
    SELECT ja.*, 
           jp.title as job_title,
           jp.department
    FROM job_applications ja
    LEFT JOIN job_postings jp ON jp.id = ja.job_posting_id
    ORDER BY ja.applied_date DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análisis de Reclutamiento con IA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        .stat-card {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(99, 102, 241, 0.2);
            border-color: rgba(99, 102, 241, 0.4);
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }
        
        .query-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 16px;
            padding: 2rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        
        .theme-light .query-card {
            background: rgba(255, 255, 255, 0.95);
            border-color: rgba(148, 163, 184, 0.3);
        }
        
        .filter-chip {
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #818cf8;
            padding: 0.5rem 1rem;
            border-radius: 999px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .filter-chip:hover {
            background: rgba(99, 102, 241, 0.25);
            border-color: rgba(99, 102, 241, 0.5);
            transform: translateY(-1px);
        }
        
        .filter-chip .remove-btn {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.3);
            color: #ef4444;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            transition: all 0.2s ease;
        }
        
        .filter-chip .remove-btn:hover {
            background: rgba(239, 68, 68, 0.5);
            transform: scale(1.1);
        }
        
        .ai-response-card {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(99, 102, 241, 0.1) 100%);
            border: 1px solid rgba(139, 92, 246, 0.3);
            border-radius: 16px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            backdrop-filter: blur(10px);
        }
        
        .result-table {
            background: rgba(30, 41, 59, 0.4);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 12px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .theme-light .result-table {
            background: rgba(255, 255, 255, 0.8);
        }
        
        .result-table table {
            width: 100%;
            min-width: 1200px;
            border-collapse: collapse;
        }
        
        .result-table thead {
            background: rgba(99, 102, 241, 0.2);
        }
        
        .result-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #a5b4fc;
            white-space: nowrap;
            min-width: 120px;
        }
        
        .result-table td {
            padding: 1rem;
            border-top: 1px solid rgba(148, 163, 184, 0.1);
            white-space: nowrap;
            min-width: 120px;
        }
        
        .result-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .result-table tbody tr:hover {
            background: rgba(99, 102, 241, 0.1);
        }
        
        /* Responsive table */
        @media screen and (max-width: 768px) {
            .result-table table {
                min-width: 900px;
            }
            
            .result-table th,
            .result-table td {
                padding: 0.75rem;
                font-size: 0.875rem;
                min-width: 100px;
            }
            
            .result-count-banner {
                flex-direction: column;
                gap: 0.75rem !important;
                text-align: center;
            }
            
            .result-count-banner .text-lg {
                font-size: 1rem !important;
            }
        }
        
        @media screen and (max-width: 480px) {
            .result-table table {
                min-width: 800px;
            }
            
            .result-table th,
            .result-table td {
                padding: 0.5rem;
                font-size: 0.8125rem;
                min-width: 90px;
            }
        }
        
        /* Pagination styles */
        .pagination-btn {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.2);
            color: #e2e8f0;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
            min-width: 40px;
            text-align: center;
        }
        
        .pagination-btn:hover:not(:disabled) {
            background: rgba(99, 102, 241, 0.3);
            border-color: rgba(99, 102, 241, 0.4);
            transform: translateY(-1px);
        }
        
        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-btn.active {
            background: rgba(99, 102, 241, 0.4);
            border-color: rgba(99, 102, 241, 0.6);
            color: #6366f1;
            font-weight: 600;
        }
        
        .pagination-info {
            color: #94a3b8;
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
        }
        
        @media screen and (max-width: 640px) {
            .pagination-btn {
                padding: 0.375rem 0.75rem;
                font-size: 0.875rem;
                min-width: 36px;
            }
            
            .pagination-info {
                font-size: 0.8125rem;
            }
            
            /* Hide pagination info text on mobile */
            .pagination-info.ml-4 {
                display: none;
            }
        }
        
        /* Responsive controls */
        @media screen and (max-width: 1024px) {
            #items-per-page-container {
                width: 100%;
                justify-content: center;
                margin-top: 0.75rem;
            }
        }
        
        @media screen and (max-width: 640px) {
            .stat-card {
                padding: 1rem;
            }
            
            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 1.5rem;
            }
            
            #export-excel-btn span,
            #export-pdf-btn span,
            #show-sql-btn span {
                display: none;
            }
        }
        
        .status-badge {
            padding: 0.375rem 0.875rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-block;
        }
        
        .status-new { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .status-reviewing { background: rgba(234, 179, 8, 0.2); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.3); }
        .status-shortlisted { background: rgba(139, 92, 246, 0.2); color: #a78bfa; border: 1px solid rgba(139, 92, 246, 0.3); }
        .status-interview_scheduled { background: rgba(99, 102, 241, 0.2); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.3); }
        .status-hired { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); }
        .status-rejected { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        
        .loading-spinner {
            border: 3px solid rgba(99, 102, 241, 0.3);
            border-top: 3px solid #6366f1;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .quick-filter-btn {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.2);
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            color: #e2e8f0;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
            text-align: left;
        }
        
        .quick-filter-btn:hover {
            background: rgba(99, 102, 241, 0.2);
            border-color: rgba(99, 102, 241, 0.4);
            transform: translateY(-2px);
        }
        
        .theme-light .quick-filter-btn {
            background: rgba(255, 255, 255, 0.8);
            color: #1e293b;
        }
        
        .ai-insight-card {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(16, 185, 129, 0.1) 100%);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 1rem;
        }
        
        .chart-container {
            background: rgba(30, 41, 59, 0.4);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .theme-light .chart-container {
            background: rgba(255, 255, 255, 0.8);
        }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-4xl font-bold text-white mb-2">
                    <i class="fas fa-brain text-purple-400 mr-3"></i>
                    Análisis de Reclutamiento con IA
                </h1>
                <p class="text-slate-400 text-lg">Consultas inteligentes y filtros personalizados powered by Gemini AI</p>
            </div>
            <a href="index.php" class="btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1 font-medium">Total Aplicaciones</p>
                        <h3 class="text-3xl font-bold text-white"><?= number_format($totalApplications) ?></h3>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);">
                        <i class="fas fa-file-alt text-white"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1 font-medium">Aplicaciones Nuevas</p>
                        <h3 class="text-3xl font-bold text-white"><?= number_format($newApplications) ?></h3>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                        <i class="fas fa-inbox text-white"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1 font-medium">Vacantes Activas</p>
                        <h3 class="text-3xl font-bold text-white"><?= number_format($activePostings) ?></h3>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                        <i class="fas fa-briefcase text-white"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1 font-medium">Entrevistas Agendadas</p>
                        <h3 class="text-3xl font-bold text-white"><?= number_format($interviewScheduled) ?></h3>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                        <i class="fas fa-calendar-check text-white"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Query Section -->
        <div class="query-card mb-8">
            <div class="flex items-center mb-4">
                <i class="fas fa-sparkles text-purple-400 text-2xl mr-3"></i>
                <h2 class="text-2xl font-bold text-white">Consulta Inteligente</h2>
            </div>
            
            <p class="text-slate-400 mb-6">
                Escribe tu consulta en lenguaje natural. Por ejemplo: "¿Cuántas personas aplicaron a Desarrollador Full Stack con expectativa salarial mayor a RD$25,000?"
            </p>
            
            <div class="relative mb-4">
                <textarea 
                    id="ai-query" 
                    rows="3" 
                    placeholder="Escribe tu consulta aquí... Ejemplo: Mostrar candidatos con más de 5 años de experiencia que aplicaron en los últimos 30 días"
                    class="w-full bg-slate-800/50 border border-slate-700/50 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-500/50 resize-none"
                ></textarea>
                <div class="absolute bottom-3 right-3 text-slate-500 text-sm" id="char-count">0 / 500</div>
            </div>
            
            <div class="flex items-center gap-4">
                <button 
                    id="analyze-btn" 
                    class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold px-8 py-3 rounded-xl transition-all duration-300 shadow-lg hover:shadow-purple-500/50 flex items-center gap-2"
                >
                    <i class="fas fa-robot"></i>
                    <span>Analizar con IA</span>
                </button>
                
                <button 
                    id="clear-query-btn" 
                    class="bg-slate-700/50 hover:bg-slate-700 text-white font-semibold px-6 py-3 rounded-xl transition-all duration-300 flex items-center gap-2"
                >
                    <i class="fas fa-eraser"></i>
                    <span>Limpiar</span>
                </button>
            </div>
        </div>

        <!-- Quick Filters -->
        <div class="query-card mb-8">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-white flex items-center">
                    <i class="fas fa-bolt text-yellow-400 mr-3"></i>
                    Filtros Rápidos
                </h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <button class="quick-filter-btn" data-query="Mostrar todas las aplicaciones nuevas que llegaron en los últimos 7 días">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-clock text-blue-400"></i>
                        <div>
                            <div class="font-semibold">Aplicaciones Recientes</div>
                            <div class="text-xs text-slate-500">Últimos 7 días</div>
                        </div>
                    </div>
                </button>
                
                <button class="quick-filter-btn" data-query="¿Cuántos candidatos tienen expectativas salariales entre RD$20,000 y RD$30,000?">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-dollar-sign text-green-400"></i>
                        <div>
                            <div class="font-semibold">Rango Salarial</div>
                            <div class="text-xs text-slate-500">RD$20k - RD$30k</div>
                        </div>
                    </div>
                </button>
                
                <button class="quick-filter-btn" data-query="Mostrar candidatos con más de 3 años de experiencia ordenados por fecha de aplicación">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-medal text-purple-400"></i>
                        <div>
                            <div class="font-semibold">Experiencia Alta</div>
                            <div class="text-xs text-slate-500">Más de 3 años</div>
                        </div>
                    </div>
                </button>
                
                <button class="quick-filter-btn" data-query="Listar candidatos que están en estatus de entrevista programada o shortlist">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-users text-indigo-400"></i>
                        <div>
                            <div class="font-semibold">En Proceso</div>
                            <div class="text-xs text-slate-500">Entrevistas y shortlist</div>
                        </div>
                    </div>
                </button>
                
                <button class="quick-filter-btn" data-query="Mostrar vacantes activas con más de 10 aplicaciones">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-fire text-orange-400"></i>
                        <div>
                            <div class="font-semibold">Vacantes Populares</div>
                            <div class="text-xs text-slate-500">Más de 10 apps</div>
                        </div>
                    </div>
                </button>
                
                <button class="quick-filter-btn" data-query="Analizar distribución de nivel educativo de todos los candidatos">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-graduation-cap text-pink-400"></i>
                        <div>
                            <div class="font-semibold">Nivel Educativo</div>
                            <div class="text-xs text-slate-500">Distribución</div>
                        </div>
                    </div>
                </button>
                
                <button class="quick-filter-btn" data-query="¿Cuales candidatos tienen experiencia en call center?">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-headset text-cyan-400"></i>
                        <div>
                            <div class="font-semibold">Call Center</div>
                            <div class="text-xs text-slate-500">Experiencia específica</div>
                        </div>
                    </div>
                </button>
            </div>
        </div>

        <!-- Active Filters Display -->
        <div id="active-filters-section" class="hidden mb-6">
            <div class="query-card">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-white flex items-center">
                        <i class="fas fa-filter text-indigo-400 mr-2"></i>
                        Filtros Activos
                    </h3>
                    <button id="clear-all-filters" class="text-red-400 hover:text-red-300 text-sm font-medium">
                        <i class="fas fa-times-circle mr-1"></i>
                        Limpiar Todo
                    </button>
                </div>
                <div id="active-filters-container" class="flex flex-wrap gap-2">
                    <!-- Filter chips will be added here dynamically -->
                </div>
            </div>
        </div>

        <!-- Results Section -->
        <div id="results-section" class="hidden">
            <div class="query-card">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-white flex items-center">
                        <i class="fas fa-chart-bar text-green-400 mr-3"></i>
                        Resultados del Análisis
                    </h2>
                    <div class="flex gap-3 items-center flex-wrap">
                        <button id="show-sql-btn" class="bg-slate-700 hover:bg-slate-600 text-white font-semibold px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2">
                            <i class="fas fa-code"></i>
                            <span>Ver SQL</span>
                        </button>
                        <button id="export-excel-btn" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2">
                            <i class="fas fa-file-excel"></i>
                            <span>Exportar Excel</span>
                        </button>
                        <button id="export-pdf-btn" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2">
                            <i class="fas fa-file-pdf"></i>
                            <span>Exportar PDF</span>
                        </button>
                        <div id="items-per-page-container" class="flex items-center gap-2 ml-auto">
                            <label for="items-per-page" class="text-slate-400 text-sm whitespace-nowrap">Mostrar:</label>
                            <select id="items-per-page" class="bg-slate-700 text-white px-3 py-2 rounded-lg border border-slate-600 focus:outline-none focus:border-blue-500 transition-all" onchange="changeItemsPerPage(this.value)">
                                <option value="10" selected>10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                            <span class="text-slate-400 text-sm whitespace-nowrap">por página</span>
                        </div>
                    </div>
                </div>
                
                <!-- SQL Query Display (hidden by default) -->
                <div id="sql-display" class="hidden mb-6 p-4 bg-slate-800/50 border border-slate-700 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-white flex items-center">
                            <i class="fas fa-database text-blue-400 mr-2"></i>
                            Consulta SQL Generada
                        </h3>
                        <button id="copy-sql-btn" class="text-blue-400 hover:text-blue-300 text-sm">
                            <i class="fas fa-copy mr-1"></i>
                            Copiar
                        </button>
                    </div>
                    <pre id="sql-query-text" class="text-sm text-slate-300 font-mono overflow-x-auto"></pre>
                </div>
                
                <!-- AI Insights -->
                <div id="ai-insights" class="ai-insight-card mb-6">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-lightbulb text-yellow-400 text-xl mt-1"></i>
                        <div>
                            <h3 class="font-semibold text-white mb-2">Insights de IA</h3>
                            <p id="ai-insights-text" class="text-slate-300 text-sm"></p>
                        </div>
                    </div>
                </div>
                
                <!-- Results Table -->
                <div id="results-table-container" class="overflow-x-auto">
                    <!-- Table will be populated here -->
                </div>
                
                <!-- Pagination -->
                <div id="pagination-container" class="flex justify-center items-center gap-2 mt-6">
                    <!-- Pagination will be added here -->
                </div>
            </div>
        </div>

        <!-- Loading State -->
        <div id="loading-section" class="hidden query-card text-center py-12">
            <div class="loading-spinner mx-auto mb-4"></div>
            <p class="text-white text-lg font-semibold mb-2">Analizando datos con IA...</p>
            <p class="text-slate-400">Esto puede tomar unos segundos</p>
        </div>

        <!-- Recent Applications Preview -->
        <div class="query-card mt-8">
            <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                <i class="fas fa-history text-blue-400 mr-3"></i>
                Aplicaciones Recientes
            </h2>
            
            <div class="result-table">
                <table class="w-full">
                    <thead>
                        <tr>
                            <th>Candidato</th>
                            <th>Vacante</th>
                            <th>Departamento</th>
                            <th>Experiencia</th>
                            <th>Expectativa Salarial</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody class="text-slate-300">
                        <?php foreach ($recentApplications as $app): ?>
                        <tr>
                            <td class="font-medium text-white">
                                <?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?>
                            </td>
                            <td><?= htmlspecialchars($app['job_title'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($app['department'] ?? 'N/A') ?></td>
                            <td>
                                <?= $app['years_of_experience'] ? htmlspecialchars($app['years_of_experience']) . ' años' : 'N/A' ?>
                            </td>
                            <td class="font-semibold text-green-400">
                                <?= htmlspecialchars($app['expected_salary'] ?? 'No especificado') ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?= htmlspecialchars($app['status']) ?>">
                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $app['status']))) ?>
                                </span>
                            </td>
                            <td class="text-sm">
                                <?= date('d/m/Y', strtotime($app['applied_date'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Character counter
        const aiQuery = document.getElementById('ai-query');
        const charCount = document.getElementById('char-count');
        
        aiQuery.addEventListener('input', () => {
            const length = aiQuery.value.length;
            charCount.textContent = `${length} / 500`;
            if (length > 500) {
                aiQuery.value = aiQuery.value.substring(0, 500);
                charCount.textContent = '500 / 500';
            }
        });
        
        // Quick filter buttons
        document.querySelectorAll('.quick-filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const query = btn.getAttribute('data-query');
                aiQuery.value = query;
                aiQuery.focus();
            });
        });
        
        // Clear query button
        document.getElementById('clear-query-btn').addEventListener('click', () => {
            aiQuery.value = '';
            charCount.textContent = '0 / 500';
            document.getElementById('results-section').classList.add('hidden');
            document.getElementById('active-filters-section').classList.add('hidden');
        });
        
        // Analyze button
        document.getElementById('analyze-btn').addEventListener('click', async () => {
            const query = aiQuery.value.trim();
            
            if (!query) {
                showNotification('Por favor escribe una consulta primero', 'warning');
                return;
            }
            
            // Show loading
            document.getElementById('loading-section').classList.remove('hidden');
            document.getElementById('results-section').classList.add('hidden');
            
            try {
                const response = await fetch('recruitment_ai_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'analyze',
                        query: query
                    })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                // Hide loading
                document.getElementById('loading-section').classList.add('hidden');
                
                if (data.success) {
                    displayResults(data.results, data.insights, query, data.query);
                    showNotification('Análisis completado exitosamente', 'success');
                } else {
                    const errorMsg = data.error || 'No se pudieron obtener resultados';
                    showNotification(errorMsg, 'error');
                    console.error('API Error:', data);
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('loading-section').classList.add('hidden');
                showNotification('Error al procesar la consulta. Verifica tu conexión e intenta de nuevo.', 'error');
            }
        });
        
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-0`;
            
            // Set style based on type
            const styles = {
                'success': 'bg-green-600 text-white',
                'error': 'bg-red-600 text-white',
                'warning': 'bg-yellow-600 text-white',
                'info': 'bg-blue-600 text-white'
            };
            
            notification.className += ' ' + styles[type];
            
            // Set icon based on type
            const icons = {
                'success': 'fa-check-circle',
                'error': 'fa-exclamation-circle',
                'warning': 'fa-exclamation-triangle',
                'info': 'fa-info-circle'
            };
            
            notification.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fas ${icons[type]} text-xl"></i>
                    <p class="font-medium">${message}</p>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-4 hover:opacity-75">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }
        
        // Pagination state
        let currentPage = 1;
        let itemsPerPage = 10;
        let allResults = [];
        
        function displayResults(results, insights, query, sqlQuery) {
            // Show results section
            document.getElementById('results-section').classList.remove('hidden');
            
            // Store SQL query and results for later use
            window.currentSQLQuery = sqlQuery;
            allResults = results;
            currentPage = 1;
            
            document.getElementById('sql-query-text').textContent = sqlQuery || 'SQL no disponible';
            
            // Display insights
            document.getElementById('ai-insights-text').textContent = insights;
            
            // Build results table
            const tableContainer = document.getElementById('results-table-container');
            
            if (results.length === 0) {
                tableContainer.innerHTML = `
                    <div class="text-center py-12">
                        <i class="fas fa-inbox text-slate-600 text-4xl mb-4"></i>
                        <p class="text-slate-400 text-lg">No se encontraron resultados para esta consulta</p>
                    </div>
                `;
                document.getElementById('pagination-container').innerHTML = '';
                return;
            }
            
            renderTable();
            renderPagination();
            
            // Scroll to results
            document.getElementById('results-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        
        function renderTable() {
            const tableContainer = document.getElementById('results-table-container');
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const paginatedResults = allResults.slice(startIndex, endIndex);
            
            // Get column headers from first result
            const columns = Object.keys(allResults[0]);
            
            let tableHTML = `
                <div class="mb-4 p-4 rounded-lg result-count-banner" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3);">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <i class="fas fa-check-circle text-blue-400 text-xl"></i>
                            <span class="text-white font-semibold text-lg">
                                Se encontraron ${allResults.length} candidato${allResults.length !== 1 ? 's' : ''} que coinciden con tu consulta
                            </span>
                        </div>
                        <span class="text-slate-400 text-sm">Página ${currentPage} de ${Math.ceil(allResults.length / itemsPerPage)}</span>
                    </div>
                </div>
                <div class="result-table">
                    <table class="w-full">
                        <thead>
                            <tr>
                                ${columns.map(col => `<th>${formatColumnName(col)}</th>`).join('')}
                            </tr>
                        </thead>
                        <tbody class="text-slate-300">
                            ${paginatedResults.map(row => `
                                <tr>
                                    ${columns.map(col => `<td>${formatCellValue(col, row[col])}</td>`).join('')}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
            
            tableContainer.innerHTML = tableHTML;
        }
        
        function renderPagination() {
            const paginationContainer = document.getElementById('pagination-container');
            const totalPages = Math.ceil(allResults.length / itemsPerPage);
            
            if (totalPages <= 1) {
                paginationContainer.innerHTML = '';
                return;
            }
            
            let paginationHTML = `
                <button class="pagination-btn" onclick="goToPage(1)" ${currentPage === 1 ? 'disabled' : ''}>
                    <i class="fas fa-angle-double-left"></i>
                </button>
                <button class="pagination-btn" onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>
                    <i class="fas fa-angle-left"></i>
                </button>
            `;
            
            // Show page numbers
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, currentPage + 2);
            
            if (startPage > 1) {
                paginationHTML += `<button class="pagination-btn" onclick="goToPage(1)">1</button>`;
                if (startPage > 2) {
                    paginationHTML += `<span class="pagination-info">...</span>`;
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                paginationHTML += `
                    <button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">
                        ${i}
                    </button>
                `;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    paginationHTML += `<span class="pagination-info">...</span>`;
                }
                paginationHTML += `<button class="pagination-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`;
            }
            
            paginationHTML += `
                <button class="pagination-btn" onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>
                    <i class="fas fa-angle-right"></i>
                </button>
                <button class="pagination-btn" onclick="goToPage(${totalPages})" ${currentPage === totalPages ? 'disabled' : ''}>
                    <i class="fas fa-angle-double-right"></i>
                </button>
                <span class="pagination-info ml-4">Mostrando ${((currentPage - 1) * itemsPerPage) + 1}-${Math.min(currentPage * itemsPerPage, allResults.length)} de ${allResults.length}</span>
            `;
            
            paginationContainer.innerHTML = paginationHTML;
        }
        
        function goToPage(page) {
            const totalPages = Math.ceil(allResults.length / itemsPerPage);
            if (page < 1 || page > totalPages) return;
            
            currentPage = page;
            renderTable();
            renderPagination();
            
            // Scroll to top of results
            document.getElementById('results-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        
        function changeItemsPerPage(newItemsPerPage) {
            itemsPerPage = parseInt(newItemsPerPage);
            currentPage = 1; // Reset to first page
            renderTable();
            renderPagination();
        }
        
        function formatColumnName(col) {
            return col.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        }
        
        function formatCellValue(col, value) {
            if (value === null || value === undefined) return 'N/A';
            
            // Format dates
            if (col.includes('date') || col.includes('_at')) {
                try {
                    const date = new Date(value);
                    return date.toLocaleDateString('es-ES');
                } catch (e) {
                    return value;
                }
            }
            
            // Format status
            if (col === 'status') {
                return `<span class="status-badge status-${value}">${value.replace(/_/g, ' ').toUpperCase()}</span>`;
            }
            
            // Format salary
            if (col.includes('salary') || col.includes('expected_salary')) {
                return `<span class="font-semibold text-green-400">${value}</span>`;
            }
            
            return value;
        }
        
        // Export buttons (placeholder - implement actual export functionality)
        document.getElementById('export-excel-btn').addEventListener('click', () => {
            showNotification('Funcionalidad de exportación a Excel próximamente', 'info');
        });
        
        document.getElementById('export-pdf-btn').addEventListener('click', () => {
            showNotification('Funcionalidad de exportación a PDF próximamente', 'info');
        });
        
        // Show/Hide SQL Query
        document.getElementById('show-sql-btn').addEventListener('click', () => {
            const sqlDisplay = document.getElementById('sql-display');
            const btn = document.getElementById('show-sql-btn');
            
            if (sqlDisplay.classList.contains('hidden')) {
                sqlDisplay.classList.remove('hidden');
                btn.innerHTML = '<i class="fas fa-code"></i><span>Ocultar SQL</span>';
            } else {
                sqlDisplay.classList.add('hidden');
                btn.innerHTML = '<i class="fas fa-code"></i><span>Ver SQL</span>';
            }
        });
        
        // Copy SQL to clipboard
        document.getElementById('copy-sql-btn').addEventListener('click', async () => {
            const sqlText = document.getElementById('sql-query-text').textContent;
            
            try {
                await navigator.clipboard.writeText(sqlText);
                showNotification('SQL copiado al portapapeles', 'success');
            } catch (err) {
                console.error('Error copying to clipboard:', err);
                showNotification('Error al copiar SQL', 'error');
            }
        });
    </script>
</body>
</html>
