<?php
session_start();
include 'db.php';

ensurePermission('voice_ai_reports');

// Get date range from query or use defaults
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Build API URLs
$base_url = "http://" . $_SERVER['HTTP_HOST'] . "/ponche-xtreme/api/voice_ai_reports.php";

$disposition_url = $base_url . "?action=disposition_analytics&start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date);
$quality_url = $base_url . "?action=call_quality&start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date);
$comprehensive_url = $base_url . "?action=comprehensive_report&start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date);

// Fetch data via cURL
function fetchApiData($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, "PHPSESSID=" . session_id());
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

$disposition_data = fetchApiData($disposition_url);
$quality_data = fetchApiData($quality_url);
$comprehensive_data = fetchApiData($comprehensive_url);

// Extract key metrics
$total_calls = $disposition_data['meta']['total_calls_analyzed'] ?? 0;
$total_dispositions = count($disposition_data['disposition_stats'] ?? []);
$recording_coverage = $quality_data['quality_metrics']['recording_coverage_pct'] ?? 0;
$transcript_coverage = $quality_data['quality_metrics']['transcript_coverage_pct'] ?? 0;
$summary_coverage = $quality_data['quality_metrics']['summary_coverage_pct'] ?? 0;

// Get agent scores
$agent_scores = $quality_data['quality_metrics']['quality_scores_by_agent'] ?? [];
usort($agent_scores, function($a, $b) {
    return $b['quality_score'] <=> $a['quality_score'];
});

// Get disposition stats
$disposition_stats = $disposition_data['disposition_stats'] ?? [];
usort($disposition_stats, function($a, $b) {
    return $b['total'] <=> $a['total'];
});

// Get disposition by agent
$disposition_by_agent = $disposition_data['disposition_by_agent'] ?? [];
usort($disposition_by_agent, function($a, $b) {
    return $b['total_calls'] <=> $a['total_calls'];
});

// Get interaction data if available
$interaction_totals = $comprehensive_data['interactions']['totals_by_channel'] ?? [];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHL Voice AI - Reportes Integrales</title>
    <link rel="stylesheet" href="css/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --primary: #3B82F6;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --info: #06B6D4;
        }
        
        body {
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
            color: #E2E8F0;
        }
        
        .dashboard-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .metric-card {
            background: linear-gradient(135deg, #1E293B 0%, #0F172A 100%);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            border-color: rgba(59, 130, 246, 0.5);
            transform: translateY(-2px);
            box-shadow: 0 8px 12px rgba(59, 130, 246, 0.2);
        }
        
        .metric-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0.5rem 0;
        }
        
        .metric-label {
            font-size: 0.875rem;
            color: #94A3B8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .chart-container {
            background: linear-gradient(135deg, #1E293B 0%, #0F172A 100%);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            position: relative;
            height: 400px;
        }
        
        .table-container {
            background: linear-gradient(135deg, #1E293B 0%, #0F172A 100%);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .table-header {
            background: linear-gradient(90deg, #0F172A 0%, #1E293B 100%);
            padding: 1.5rem;
            border-bottom: 2px solid rgba(59, 130, 246, 0.3);
            font-weight: 600;
            font-size: 0.95rem;
        }
        
        .table-row {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            display: grid;
            gap: 1rem;
            align-items: center;
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .table-row:hover {
            background: rgba(59, 130, 246, 0.05);
        }
        
        .badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: #10B981;
        }
        
        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #F59E0B;
        }
        
        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #EF4444;
        }
        
        .badge-info {
            background: rgba(6, 182, 212, 0.15);
            color: #06B6D4;
        }
        
        .progress-bar {
            height: 8px;
            background: rgba(148, 163, 184, 0.2);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .header-section {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid rgba(59, 130, 246, 0.2);
        }
        
        .title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(90deg, #3B82F6 0%, #06B6D4 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .subtitle {
            color: #94A3B8;
            font-size: 0.875rem;
        }
        
        .filter-section {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-input, .filter-button {
            padding: 0.75rem 1rem;
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: 8px;
            background: rgba(15, 23, 42, 0.5);
            color: #E2E8F0;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }
        
        .filter-input:focus, .filter-button:hover {
            border-color: rgb(59, 130, 246);
            background: rgba(59, 130, 246, 0.1);
            outline: none;
        }
        
        .filter-button {
            background: linear-gradient(135deg, #3B82F6 0%, #1E40AF 100%);
            border: none;
            cursor: pointer;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .grid-4 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        
        .section-title {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #F1F5F9;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .section-title::before {
            content: '';
            width: 4px;
            height: 24px;
            background: linear-gradient(90deg, #3B82F6 0%, #06B6D4 100%);
            border-radius: 2px;
        }
        
        .no-data {
            padding: 3rem;
            text-align: center;
            color: #94A3B8;
            background: rgba(59, 130, 246, 0.05);
            border-radius: 12px;
            border: 2px dashed rgba(59, 130, 246, 0.3);
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
            }
            
            .grid-2, .grid-3, .grid-4 {
                grid-template-columns: 1fr;
            }
            
            .title {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="header-section">
            <h1 class="title">📊 Dashboard GHL Voice AI</h1>
            <p class="subtitle">Reportes Integrales de Disposiciones, Calidad y Análisis</p>
        </div>

        <!-- Filtros de Fecha -->
        <form method="GET" class="filter-section">
            <label style="color: #94A3B8; font-weight: 500;">Período:</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="filter-input" required>
            <span style="color: #64748B;">a</span>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="filter-input" required>
            <button type="submit" class="filter-button">🔄 Actualizar Reportes</button>
        </form>

        <!-- Métricas Clave -->
        <div class="grid-4">
            <div class="metric-card">
                <div class="metric-label">📞 Total Llamadas</div>
                <div class="metric-value" style="color: #3B82F6;"><?php echo number_format($total_calls); ?></div>
                <div class="metric-label" style="font-size: 0.75rem; margin-top: 0.5rem;">Período seleccionado</div>
            </div>

            <div class="metric-card">
                <div class="metric-label">📋 Disposiciones Únicas</div>
                <div class="metric-value" style="color: #10B981;"><?php echo number_format($total_dispositions); ?></div>
                <div class="metric-label" style="font-size: 0.75rem; margin-top: 0.5rem;">Tipos registrados</div>
            </div>

            <div class="metric-card">
                <div class="metric-label">🎙️ Grabaciones</div>
                <div class="metric-value" style="color: #06B6D4;"><?php echo number_format($recording_coverage, 1); ?>%</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min($recording_coverage, 100); ?>%; background: linear-gradient(90deg, #06B6D4 0%, #0891B2 100%);"></div>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-label">✍️ Transcripciones</div>
                <div class="metric-value" style="color: #F59E0B;"><?php echo number_format($transcript_coverage, 1); ?>%</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min($transcript_coverage, 100); ?>%; background: linear-gradient(90deg, #F59E0B 0%, #FBBF24 100%);"></div>
                </div>
            </div>
        </div>

        <!-- SECCIÓN 1: ANÁLISIS DE DISPOSICIONES -->
        <div style="margin-top: 3rem;">
            <h2 class="section-title">📊 Análisis de Disposiciones</h2>
            
            <?php if (empty($disposition_stats)): ?>
                <div class="no-data">
                    <p>Sin datos de disposiciones para el período seleccionado</p>
                </div>
            <?php else: ?>
                <!-- Gráfico de Disposiciones (Top 5) -->
                <div class="grid-2" style="margin-bottom: 2rem;">
                    <div class="chart-container">
                        <canvas id="dispositionChart" style="position: absolute; height: 100%; width: 100%;"></canvas>
                    </div>

                    <div class="table-container">
                        <div class="table-header">Disposiciones Más Frecuentes</div>
                        <?php foreach (array_slice($disposition_stats, 0, 5) as $disp): ?>
                            <div class="table-row" style="grid-template-columns: 1fr auto;">
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($disp['disposition'] ?? 'N/A'); ?></div>
                                <div style="text-align: right; font-weight: 600; color: #3B82F6;"><?php echo number_format($disp['total'] ?? 0); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Tabla de Disposiciones Detallada -->
                <div class="table-container">
                    <div class="table-header" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem;">
                        <div>Disposición</div>
                        <div style="text-align: center;">Total</div>
                        <div style="text-align: center;">Entrantes</div>
                        <div style="text-align: center;">Salientes</div>
                        <div style="text-align: center;">Grabadas</div>
                    </div>
                    <?php foreach ($disposition_stats as $disp): ?>
                        <div class="table-row" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem;">
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($disp['disposition'] ?? 'N/A'); ?></div>
                            <div style="text-align: center; color: #3B82F6; font-weight: 600;"><?php echo number_format($disp['total'] ?? 0); ?></div>
                            <div style="text-align: center; color: #10B981;"><?php echo number_format($disp['inbound'] ?? 0); ?></div>
                            <div style="text-align: center; color: #F59E0B;"><?php echo number_format($disp['outbound'] ?? 0); ?></div>
                            <div style="text-align: center;">
                                <?php 
                                    $recorded = $disp['recorded_calls'] ?? 0;
                                    $total = $disp['total'] ?? 1;
                                    $pct = round(($recorded / $total) * 100, 1);
                                    $class = $pct >= 95 ? 'success' : ($pct >= 80 ? 'warning' : 'danger');
                                ?>
                                <span class="badge badge-<?php echo $class; ?>"><?php echo $pct; ?>%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Timeline de Disposiciones -->
                <?php if (!empty($disposition_data['disposition_timeline'])): ?>
                    <div class="chart-container" style="margin-top: 2rem; height: 350px;">
                        <canvas id="timelineChart"></canvas>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- SECCIÓN 2: ANÁLISIS DE CALIDAD POR AGENTE -->
        <div style="margin-top: 3rem;">
            <h2 class="section-title">🏆 Análisis de Calidad por Agente</h2>
            
            <?php if (empty($agent_scores)): ?>
                <div class="no-data">
                    <p>Sin datos de calidad disponibles</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <div class="table-header" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr; gap: 1rem;">
                        <div>Agente</div>
                        <div style="text-align: center;">Puntuación</div>
                        <div style="text-align: center;">Grabación</div>
                        <div style="text-align: center;">Transcripción</div>
                        <div style="text-align: center;">Resumen</div>
                    </div>
                    
                    <?php foreach ($agent_scores as $agent): ?>
                        <?php
                            $score = $agent['quality_score'] ?? 0;
                            $score_class = $score >= 90 ? 'success' : ($score >= 75 ? 'warning' : 'danger');
                        ?>
                        <div class="table-row" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr; gap: 1rem; align-items: center;">
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($agent['agent_name'] ?? 'N/A'); ?></div>
                            <div style="text-align: center;">
                                <span class="badge badge-<?php echo $score_class; ?>"><?php echo number_format($score, 1); ?></span>
                            </div>
                            <div style="text-align: center; color: #06B6D4;"><?php echo number_format($agent['recording_pct'] ?? 0, 1); ?>%</div>
                            <div style="text-align: center; color: #F59E0B;"><?php echo number_format($agent['transcript_pct'] ?? 0, 1); ?>%</div>
                            <div style="text-align: center; color: #10B981;"><?php echo number_format($agent['summary_pct'] ?? 0, 1); ?>%</div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Gráfico de Puntuaciones -->
                <div class="chart-container" style="height: 350px; margin-top: 2rem;">
                    <canvas id="agentScoresChart"></canvas>
                </div>
            <?php endif; ?>
        </div>

        <!-- SECCIÓN 3: DISPOSICIONES POR AGENTE -->
        <div style="margin-top: 3rem;">
            <h2 class="section-title">👥 Disposiciones por Agente</h2>
            
            <?php if (empty($disposition_by_agent)): ?>
                <div class="no-data">
                    <p>Sin datos de disposiciones por agente</p>
                </div>
            <?php else: ?>
                <div class="grid-2">
                    <div class="chart-container">
                        <canvas id="agentDispositionChart"></canvas>
                    </div>

                    <div class="table-container">
                        <div class="table-header" style="display: grid; grid-template-columns: 1fr auto auto; gap: 1rem;">
                            <div>Agente</div>
                            <div style="text-align: center;">Llamadas</div>
                            <div style="text-align: center;">Manejadas</div>
                        </div>
                        <?php foreach (array_slice($disposition_by_agent, 0, 10) as $agent): ?>
                            <div class="table-row" style="display: grid; grid-template-columns: 1fr auto auto; gap: 1rem;">
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($agent['agent_name'] ?? 'N/A'); ?></div>
                                <div style="text-align: center; color: #3B82F6; font-weight: 600;"><?php echo number_format($agent['total_calls'] ?? 0); ?></div>
                                <div style="text-align: center; color: #10B981; font-weight: 600;"><?php echo number_format($agent['total_handled_calls'] ?? 0); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- SECCIÓN 4: COBERTURA DE CALIDAD -->
        <div style="margin-top: 3rem; margin-bottom: 3rem;">
            <h2 class="section-title">✅ Métricas de Cobertura</h2>
            
            <div class="grid-3">
                <div class="metric-card">
                    <div class="metric-label">🎙️ Cobertura de Grabación</div>
                    <div class="metric-value" style="color: #06B6D4;"><?php echo number_format($recording_coverage, 1); ?>%</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min($recording_coverage, 100); ?>%; background: linear-gradient(90deg, #06B6D4 0%, #0891B2 100%);"></div>
                    </div>
                    <div class="badge badge-<?php echo $recording_coverage >= 95 ? 'success' : 'warning'; ?>" style="margin-top: 0.75rem;">
                        <?php echo $recording_coverage >= 95 ? '✓ Excelente' : '⚠ Revisar'; ?>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-label">✍️ Cobertura de Transcripción</div>
                    <div class="metric-value" style="color: #F59E0B;"><?php echo number_format($transcript_coverage, 1); ?>%</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min($transcript_coverage, 100); ?>%; background: linear-gradient(90deg, #F59E0B 0%, #FBBF24 100%);"></div>
                    </div>
                    <div class="badge badge-<?php echo $transcript_coverage >= 90 ? 'success' : 'warning'; ?>" style="margin-top: 0.75rem;">
                        <?php echo $transcript_coverage >= 90 ? '✓ Excelente' : '⚠ Revisar'; ?>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-label">📝 Cobertura de Resúmenes</div>
                    <div class="metric-value" style="color: #10B981;"><?php echo number_format($summary_coverage, 1); ?>%</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min($summary_coverage, 100); ?>%; background: linear-gradient(90deg, #10B981 0%, #059669 100%);"></div>
                    </div>
                    <div class="badge badge-<?php echo $summary_coverage >= 85 ? 'success' : 'warning'; ?>" style="margin-top: 0.75rem;">
                        <?php echo $summary_coverage >= 85 ? '✓ Excelente' : '⚠ Revisar'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Colores consistentes
        const colors = {
            primary: '#3B82F6',
            success: '#10B981',
            warning: '#F59E0B',
            danger: '#EF4444',
            info: '#06B6D4',
            dark: '#1E293B',
            darkest: '#0F172A'
        };

        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: '#E2E8F0',
                        font: { size: 12 }
                    }
                }
            },
            scales: {
                y: {
                    ticks: { color: '#94A3B8' },
                    grid: { color: 'rgba(148, 163, 184, 0.1)' }
                },
                x: {
                    ticks: { color: '#94A3B8' },
                    grid: { color: 'rgba(148, 163, 184, 0.1)' }
                }
            }
        };

        // Gráfico de Disposiciones
        <?php if (!empty($disposition_stats)): ?>
            const dispositionLabels = <?php echo json_encode(array_map(fn($d) => $d['disposition'] ?? 'N/A', array_slice($disposition_stats, 0, 8))); ?>;
            const dispositionData = <?php echo json_encode(array_map(fn($d) => $d['total'] ?? 0, array_slice($disposition_stats, 0, 8))); ?>;

            new Chart(document.getElementById('dispositionChart'), {
                type: 'doughnut',
                data: {
                    labels: dispositionLabels,
                    datasets: [{
                        data: dispositionData,
                        backgroundColor: [
                            '#3B82F6', '#10B981', '#F59E0B', '#EF4444', 
                            '#06B6D4', '#8B5CF6', '#EC4899', '#14B8A6'
                        ],
                        borderColor: '#0F172A',
                        borderWidth: 2
                    }]
                },
                options: { ...chartOptions, plugins: { ...chartOptions.plugins, legend: { position: 'bottom' } } }
            });
        <?php endif; ?>

        // Gráfico de Timeline
        <?php if (!empty($disposition_data['disposition_timeline'])): ?>
            <?php
                $timeline = $disposition_data['disposition_timeline'];
                $dates = array_keys($timeline);
                $topDispositions = [];
                foreach ($timeline as $day) {
                    foreach ($day as $disp => $count) {
                        if (!isset($topDispositions[$disp])) {
                            $topDispositions[$disp] = [];
                        }
                    }
                }
            ?>
            const timelineLabels = <?php echo json_encode($dates); ?>;
            const timelineDatasets = [
                <?php 
                    $top_disps = array_slice($disposition_stats, 0, 3);
                    foreach ($top_disps as $idx => $disp):
                        $disp_name = $disp['disposition'] ?? 'N/A';
                        $data = [];
                        foreach ($timeline as $day) {
                            $data[] = $day[$disp_name] ?? 0;
                        }
                        $colors_list = ['#3B82F6', '#10B981', '#F59E0B'];
                ?>
                {
                    label: '<?php echo htmlspecialchars($disp_name); ?>',
                    data: <?php echo json_encode($data); ?>,
                    borderColor: '<?php echo $colors_list[$idx]; ?>',
                    backgroundColor: '<?php echo $colors_list[$idx]; ?>33',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                    pointBackgroundColor: '<?php echo $colors_list[$idx]; ?>'
                }<?php echo $idx < 2 ? ',' : ''; ?>
                <?php endforeach; ?>
            ];

            new Chart(document.getElementById('timelineChart'), {
                type: 'line',
                data: {
                    labels: timelineLabels,
                    datasets: timelineDatasets
                },
                options: chartOptions
            });
        <?php endif; ?>

        // Gráfico de Puntuaciones de Agentes
        <?php if (!empty($agent_scores)): ?>
            const agentNames = <?php echo json_encode(array_map(fn($a) => $a['agent_name'] ?? 'N/A', array_slice($agent_scores, 0, 10))); ?>;
            const agentScores = <?php echo json_encode(array_map(fn($a) => $a['quality_score'] ?? 0, array_slice($agent_scores, 0, 10))); ?>;

            const scoreColors = agentScores.map(score => 
                score >= 90 ? '#10B981' : (score >= 75 ? '#F59E0B' : '#EF4444')
            );

            new Chart(document.getElementById('agentScoresChart'), {
                type: 'bar',
                data: {
                    labels: agentNames,
                    datasets: [{
                        label: 'Puntuación de Calidad',
                        data: agentScores,
                        backgroundColor: scoreColors,
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: {
                    ...chartOptions,
                    indexAxis: 'y',
                    scales: {
                        x: {
                            ...chartOptions.scales.y,
                            min: 0,
                            max: 100
                        }
                    }
                }
            });
        <?php endif; ?>

        // Gráfico de Disposiciones por Agente
        <?php if (!empty($disposition_by_agent)): ?>
            const agentDispNames = <?php echo json_encode(array_map(fn($a) => $a['agent_name'] ?? 'N/A', array_slice($disposition_by_agent, 0, 8))); ?>;
            const agentDispCalls = <?php echo json_encode(array_map(fn($a) => $a['total_calls'] ?? 0, array_slice($disposition_by_agent, 0, 8))); ?>;
            const agentDispHandled = <?php echo json_encode(array_map(fn($a) => $a['total_handled_calls'] ?? 0, array_slice($disposition_by_agent, 0, 8))); ?>;

            new Chart(document.getElementById('agentDispositionChart'), {
                type: 'bar',
                data: {
                    labels: agentDispNames,
                    datasets: [
                        {
                            label: 'Total de Llamadas',
                            data: agentDispCalls,
                            backgroundColor: '#3B82F6',
                            borderRadius: 6,
                            borderSkipped: false
                        },
                        {
                            label: 'Llamadas Manejadas',
                            data: agentDispHandled,
                            backgroundColor: '#10B981',
                            borderRadius: 6,
                            borderSkipped: false
                        }
                    ]
                },
                options: chartOptions
            });
        <?php endif; ?>
    </script>
</body>
</html>
