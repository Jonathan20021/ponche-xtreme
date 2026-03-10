<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

ensurePermission('wfm_planning');

$campaigns = $pdo->query("SELECT id, name FROM campaigns ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Get date range of available data
$dateRangeQuery = $pdo->query("
    SELECT 
        MIN(DATE(interval_start)) as min_date,
        MAX(DATE(interval_start)) as max_date
    FROM vicidial_inbound_hourly
");
$dateRange = $dateRangeQuery->fetch(PDO::FETCH_ASSOC);

// Set default dates: use data range if available, otherwise current month
if ($dateRange && $dateRange['min_date']) {
    $defaultStart = $dateRange['min_date'];
    $defaultEnd = $dateRange['max_date'];
} else {
    $defaultStart = date('Y-m-01');
    $defaultEnd = date('Y-m-t');
}

include __DIR__ . '/../header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-calendar-check text-cyan-400"></i> 
                Planificación WFM
            </h1>
            <p class="text-slate-400 mt-1">Pronóstico de demanda, dimensionamiento y alertas intradía basado en Inbound Daily Reports</p>
        </div>
        <div class="flex gap-2">
            <a href="../docs/wfm_guide.pdf" target="_blank" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors text-sm">
                <i class="fas fa-book mr-2"></i>Guía
            </a>
        </div>
    </div>

    <?php if ($dateRange && $dateRange['min_date']): ?>
        <div class="mb-6 p-4 bg-gradient-to-r from-cyan-900/30 to-blue-900/30 border border-cyan-700/50 rounded-xl">
            <div class="flex items-start gap-3">
                <div class="mt-0.5">
                    <i class="fas fa-database text-cyan-400 text-xl"></i>
                </div>
                <div class="flex-1">
                    <h3 class="text-cyan-300 font-semibold mb-1">Datos Disponibles en el Sistema</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                        <div>
                            <span class="text-slate-400">Período:</span>
                            <span class="text-white font-semibold ml-2">
                                <?= htmlspecialchars($dateRange['min_date']) ?> 
                                <i class="fas fa-arrow-right text-cyan-400 mx-1"></i>
                                <?= htmlspecialchars($dateRange['max_date']) ?>
                            </span>
                        </div>
                        <div>
                            <span class="text-slate-400">Campañas:</span>
                            <span class="text-white font-semibold ml-2"><?= count($campaigns) ?> activas</span>
                        </div>
                        <div>
                            <span class="text-slate-400">Última actualización:</span>
                            <span class="text-white font-semibold ml-2"><?= date('Y-m-d H:i') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 xl:col-span-2">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">
                <i class="fas fa-cloud-upload-alt text-cyan-400 mr-2"></i>
                Carga Inbound Daily Report
            </h3>
            <div class="mb-4 p-3 bg-blue-900/20 border border-blue-700/50 rounded-lg text-sm text-blue-200">
                <div class="flex items-start gap-2">
                    <i class="fas fa-info-circle text-blue-400 mt-0.5"></i>
                    <div>
                        <p class="font-semibold mb-1">Formato Vicidial Inbound Daily Report</p>
                        <ul class="text-xs text-blue-300 space-y-1 ml-4 list-disc">
                            <li>La campaña se detecta automáticamente del archivo</li>
                            <li>Procesa intervalos horarios con métricas de llamadas</li>
                            <li>Incluye: ofrecidas, atendidas, abandonadas, ASA, AHT, wrap time</li>
                        </ul>
                    </div>
                </div>
            </div>
            <form id="forecastForm" class="grid grid-cols-1 gap-4" enctype="multipart/form-data">
                <div>
                    <label class="text-xs uppercase text-slate-400 font-semibold">Archivo CSV</label>
                    <input type="file" name="report_file"
                        class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-cyan-600 file:text-white hover:file:bg-cyan-500 file:cursor-pointer"
                        accept=".csv" required>
                </div>
                <div>
                    <button class="px-6 py-2.5 rounded bg-gradient-to-r from-cyan-600 to-blue-600 text-white hover:from-cyan-500 hover:to-blue-500 transition-all font-semibold shadow-lg">
                        <i class="fas fa-upload mr-2"></i> Cargar y Procesar Reporte
                    </button>
                </div>
            </form>
            <div id="forecastStatus" class="mt-3 text-sm"></div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Filtros</h3>
            <?php if ($dateRange && $dateRange['min_date']): ?>
                <div class="mb-3 p-2 bg-cyan-900/30 border border-cyan-700/50 rounded text-xs text-cyan-300">
                    <i class="fas fa-info-circle mr-1"></i> 
                    Datos disponibles: <?= htmlspecialchars($dateRange['min_date']) ?> a <?= htmlspecialchars($dateRange['max_date']) ?>
                </div>
            <?php endif; ?>
            <div class="grid grid-cols-1 gap-3">
                <div>
                    <label class="text-xs uppercase text-slate-400">Desde</label>
                    <input type="date" id="gapStart"
                        class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200"
                        value="<?= htmlspecialchars($defaultStart) ?>">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Hasta</label>
                    <input type="date" id="gapEnd"
                        class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200"
                        value="<?= htmlspecialchars($defaultEnd) ?>">
                </div>
                <button id="refreshGap"
                    class="px-4 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-500 transition-colors">
                    <i class="fas fa-sync mr-2"></i> Actualizar métricas
                </button>
            </div>
        </div>
    </div>

    <!-- KPIs Row -->
    <div class="mb-4 p-4 bg-gradient-to-r from-cyan-900/20 to-blue-900/20 border border-cyan-700/30 rounded-xl">
        <div class="flex items-center gap-2 text-cyan-300 mb-2">
            <i class="fas fa-chart-line"></i>
            <h2 class="text-sm font-semibold uppercase tracking-wide">Indicadores Clave de Rendimiento (KPIs)</h2>
        </div>
        <p class="text-xs text-cyan-200/70">Métricas consolidadas del período seleccionado</p>
    </div>
    
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8" id="kpiContainer">
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700 rounded-xl p-4 text-center shadow-lg">
            <div class="text-xs uppercase text-slate-400 mb-2 font-semibold">Total Ofrecidas</div>
            <div class="text-3xl font-bold text-white mb-1" id="kpiOffered">-</div>
            <div class="text-xs text-slate-500">Llamadas entrantes</div>
        </div>
        <div class="bg-gradient-to-br from-emerald-900/20 to-slate-900 border border-emerald-700/50 rounded-xl p-4 text-center shadow-lg">
            <div class="text-xs uppercase text-emerald-400 mb-2 font-semibold">Total Atendidas</div>
            <div class="text-3xl font-bold text-emerald-400 mb-1" id="kpiAnswered">-</div>
            <div class="text-xs text-emerald-300/60">Llamadas contestadas</div>
        </div>
        <div class="bg-gradient-to-br from-cyan-900/20 to-slate-900 border border-cyan-700/50 rounded-xl p-4 text-center shadow-lg">
            <div class="text-xs uppercase text-cyan-400 mb-2 font-semibold">Nivel Atención</div>
            <div class="text-3xl font-bold text-cyan-400 mb-1" id="kpiSLA">-</div>
            <div class="text-xs text-cyan-300/60">Service Level</div>
        </div>
        <div class="bg-gradient-to-br from-amber-900/20 to-slate-900 border border-amber-700/50 rounded-xl p-4 text-center shadow-lg">
            <div class="text-xs uppercase text-amber-400 mb-2 font-semibold">ASA Promedio</div>
            <div class="text-3xl font-bold text-amber-400 mb-1" id="kpiASA">-</div>
            <div class="text-xs text-amber-300/60">Avg Speed Answer</div>
        </div>
        <div class="bg-gradient-to-br from-rose-900/20 to-slate-900 border border-rose-700/50 rounded-xl p-4 text-center shadow-lg">
            <div class="text-xs uppercase text-rose-400 mb-2 font-semibold">Tasa Abandono</div>
            <div class="text-3xl font-bold text-rose-400 mb-1" id="kpiAbandon">-</div>
            <div class="text-xs text-rose-300/60">% abandonadas</div>
        </div>
    </div>

    <!-- Charts Row 1 -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-6 shadow-xl">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-slate-200">Evolución Diaria y Predicción</h3>
                    <p class="text-xs text-slate-400 mt-1">Tendencia de llamadas ofrecidas vs atendidas</p>
                </div>
                <i class="fas fa-chart-line text-cyan-400 text-xl"></i>
            </div>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="dailyChart"></canvas>
            </div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-6 shadow-xl">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-slate-200">Perfil Intradía (Promedio por Hora)</h3>
                    <p class="text-xs text-slate-400 mt-1">Distribución horaria de volumen de llamadas</p>
                </div>
                <i class="fas fa-clock text-indigo-400 text-xl"></i>
            </div>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="intradayChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 -->
    <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-6 mb-8 shadow-xl">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-200">Tasa de Abandono y ASA</h3>
                <p class="text-xs text-slate-400 mt-1">Métricas de calidad de servicio por día</p>
            </div>
            <i class="fas fa-chart-bar text-rose-400 text-xl"></i>
        </div>
        <div style="position: relative; height: 300px; width: 100%;">
            <canvas id="abandonChart"></canvas>
        </div>
    </div>

    <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 mb-8">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-200">Métricas Inbound (Totales por Campaña)</h3>
                <p class="text-xs text-slate-400 mt-1">Consolidado de todas las llamadas en el período seleccionado</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-900/60 text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="p-3">Campaña</th>
                        <th class="p-3 text-right">Ofrecidas</th>
                        <th class="p-3 text-right">Atendidas</th>
                        <th class="p-3 text-right">Abandonadas</th>
                        <th class="p-3 text-right">Abandono %</th>
                        <th class="p-3 text-right">Nivel Servicio</th>
                        <th class="p-3 text-right">ASA (seg)</th>
                        <th class="p-3 text-right">AHT (seg)</th>
                    </tr>
                </thead>
                <tbody id="gapTotals" class="divide-y divide-slate-700 text-slate-200">
                    <tr>
                        <td colspan="8" class="p-6 text-center text-slate-500">
                            <i class="fas fa-spinner fa-spin mr-2"></i>Cargando métricas...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 mb-8">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-200">Métricas Inbound por Día</h3>
                <p class="text-xs text-slate-400 mt-1">Desglose diario de métricas por campaña</p>
            </div>
        </div>
        <div class="overflow-x-auto" style="max-height: 500px;">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-900/60 text-slate-400 uppercase text-xs sticky top-0 z-10">
                    <tr>
                        <th class="p-3 sticky left-0 bg-slate-900/90">Fecha</th>
                        <th class="p-3">Campaña</th>
                        <th class="p-3 text-right">Ofrecidas</th>
                        <th class="p-3 text-right">Atendidas</th>
                        <th class="p-3 text-right">Abandonadas</th>
                        <th class="p-3 text-right">Abandono %</th>
                        <th class="p-3 text-right">Nivel Servicio</th>
                        <th class="p-3 text-right">ASA (seg)</th>
                        <th class="p-3 text-right">AHT (seg)</th>
                    </tr>
                </thead>
                <tbody id="gapDaily" class="divide-y divide-slate-700 text-slate-200">
                    <tr>
                        <td colspan="9" class="p-6 text-center text-slate-500">Cargando...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 mb-8">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-200">Alertas intradia</h3>
            <button id="generateAlerts"
                class="px-3 py-2 rounded bg-rose-600 text-white hover:bg-rose-500 transition-colors">
                <i class="fas fa-bell mr-2"></i> Generar alertas de hoy
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-900/60 text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="p-3">Fecha</th>
                        <th class="p-3">Agente</th>
                        <th class="p-3">Campaña</th>
                        <th class="p-3">Tipo</th>
                        <th class="p-3">Detalle</th>
                    </tr>
                </thead>
                <tbody id="alertTable" class="divide-y divide-slate-700 text-slate-200">
                    <tr>
                        <td colspan="5" class="p-6 text-center text-slate-500">Cargando...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-slate-800/60 border border-rose-900/50 rounded-xl p-5 mb-8">
        <h3 class="text-lg font-semibold text-rose-400 mb-4">
            <i class="fas fa-trash-alt mr-2"></i>Gestión de Datos Inbound
        </h3>
        <div class="mb-4 p-3 bg-rose-900/20 border border-rose-700/50 rounded-lg text-sm text-rose-200">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            Elimina registros importados si subiste datos incorrectos. Puedes eliminar por campaña específica o por rango de fechas.
        </div>
        <form id="deleteDataForm" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="text-xs uppercase text-slate-400">Campaña (opcional)</label>
                    <select name="campaign_id"
                        class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <option value="">Todas las campañas</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Dejar en blanco para eliminar todas</p>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Desde la fecha</label>
                    <input type="date" name="start_date"
                        class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" required>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Hasta la fecha</label>
                    <input type="date" name="end_date"
                        class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" required>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit"
                    class="px-6 py-2.5 rounded bg-rose-600 text-white hover:bg-rose-500 transition-colors flex items-center font-semibold">
                    <i class="fas fa-exclamation-triangle mr-2"></i> Eliminar Datos del Período
                </button>
                <span class="text-xs text-slate-500">Esta acción no se puede deshacer</span>
            </div>
        </form>
        <div id="deleteStatus" class="mt-3 text-sm"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const planningApi = '../api/wfm_planning.php';

    // Chart instances
    let dailyChart = null;
    let intradayChart = null;
    let abandonChart = null;

    // Custom Chart Defaults for dark theme
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.scale.grid.color = '#334155';
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.9)';
    Chart.defaults.plugins.tooltip.titleColor = '#f8fafc';
    Chart.defaults.plugins.tooltip.bodyColor = '#cbd5e1';

    function calculateSMA(data, windowSize) {
        let sma = [];
        for (let i = 0; i < data.length; i++) {
            if (i < windowSize - 1) {
                sma.push(null);
            } else {
                let sum = 0;
                for (let j = 0; j < windowSize; j++) {
                    sum += data[i - j];
                }
                sma.push(sum / windowSize);
            }
        }

        // Predict next 3 points
        if (data.length >= windowSize) {
            let lastSma = sma[sma.length - 1];
            for (let i = 0; i < 3; i++) {
                sma.push(lastSma);
            }
        }
        return sma;
    }

    async function refreshGap() {
        const start = document.getElementById('gapStart').value;
        const end = document.getElementById('gapEnd').value;
        const res = await fetch(`${planningApi}?action=inbound_metrics&start_date=${start}&end_date=${end}`);
        const data = await res.json();
        if (!data.success) return;

        const rows = data.totals || [];
        if (!rows.length) {
            document.getElementById('gapTotals').innerHTML = '<tr><td colspan="8" class="p-6 text-center text-slate-500">Sin datos</td></tr>';
            document.getElementById('gapDaily').innerHTML = '<tr><td colspan="9" class="p-6 text-center text-slate-500">Sin datos</td></tr>';
            return;
        }

        document.getElementById('gapTotals').innerHTML = rows.map(row => `
        <tr class="hover:bg-slate-700/30 transition-colors">
            <td class="p-3">
                <div class="font-semibold text-white">${row.campaign_name}</div>
                ${row.campaign_code ? `<div class="text-xs text-slate-500">${row.campaign_code}</div>` : ''}
            </td>
            <td class="p-3 text-right font-semibold text-slate-200">${row.offered.toLocaleString()}</td>
            <td class="p-3 text-right font-semibold text-emerald-400">${row.answered.toLocaleString()}</td>
            <td class="p-3 text-right font-semibold text-rose-400">${row.abandoned.toLocaleString()}</td>
            <td class="p-3 text-right">
                <span class="px-2 py-1 rounded text-xs font-semibold ${row.abandon_percent > 10 ? 'bg-rose-900/40 text-rose-300' : row.abandon_percent > 5 ? 'bg-amber-900/40 text-amber-300' : 'bg-slate-700 text-slate-300'}">
                    ${row.abandon_percent}%
                </span>
            </td>
            <td class="p-3 text-right">
                <span class="px-2 py-1 rounded text-xs font-semibold ${row.answer_percent >= 95 ? 'bg-emerald-900/40 text-emerald-300' : row.answer_percent >= 85 ? 'bg-cyan-900/40 text-cyan-300' : 'bg-amber-900/40 text-amber-300'}">
                    ${row.answer_percent}%
                </span>
            </td>
            <td class="p-3 text-right font-medium text-slate-300">${row.avg_answer_speed}s</td>
            <td class="p-3 text-right font-medium text-slate-300">${row.avg_talk_time + row.avg_wrap_time}s</td>
        </tr>
        `).join('');

        const dailyRows = data.daily || [];
        document.getElementById('gapDaily').innerHTML = dailyRows.map(row => `
        <tr class="hover:bg-slate-700/30 transition-colors">
            <td class="p-3 font-semibold text-cyan-400 sticky left-0 bg-slate-800/95">${row.date}</td>
            <td class="p-3">
                <div class="font-medium text-white">${row.campaign_name}</div>
                ${row.campaign_code ? `<div class="text-xs text-slate-500">${row.campaign_code}</div>` : ''}
            </td>
            <td class="p-3 text-right text-slate-200">${row.offered.toLocaleString()}</td>
            <td class="p-3 text-right text-emerald-400">${row.answered.toLocaleString()}</td>
            <td class="p-3 text-right text-rose-400">${row.abandoned.toLocaleString()}</td>
            <td class="p-3 text-right">
                <span class="text-xs ${row.abandon_percent > 10 ? 'text-rose-400 font-semibold' : 'text-slate-300'}">
                    ${row.abandon_percent}%
                </span>
            </td>
            <td class="p-3 text-right">
                <span class="text-xs ${row.answer_percent >= 95 ? 'text-emerald-400 font-semibold' : row.answer_percent >= 85 ? 'text-cyan-400 font-semibold' : 'text-amber-400'}">
                    ${row.answer_percent}%
                </span>
            </td>
            <td class="p-3 text-right text-slate-300">${row.avg_answer_speed}s</td>
            <td class="p-3 text-right text-slate-300">${row.avg_talk_time + row.avg_wrap_time}s</td>
        </tr>
        `).join('');

        updateKPIs(rows);
        updateCharts(dailyRows, data.intraday || {});
    }

    function updateKPIs(totals) {
        if (!totals.length) {
            ['kpiOffered', 'kpiAnswered', 'kpiSLA', 'kpiASA', 'kpiAbandon'].forEach(id => {
                document.getElementById(id).textContent = '-';
            });
            return;
        }

        let sumOffered = 0, sumAnswered = 0, sumAbandoned = 0, sumASA = 0, count = 0;
        totals.forEach(t => {
            sumOffered += t.offered;
            sumAnswered += t.answered;
            sumAbandoned += t.abandoned;
            if (t.offered > 0) {
                sumASA += t.avg_answer_speed;
                count++;
            }
        });

        const sla = sumOffered > 0 ? ((sumAnswered / sumOffered) * 100).toFixed(1) : 0;
        const aband = sumOffered > 0 ? ((sumAbandoned / sumOffered) * 100).toFixed(1) : 0;
        const avgASA = count > 0 ? Math.round(sumASA / count) : 0;

        document.getElementById('kpiOffered').textContent = sumOffered.toLocaleString();
        document.getElementById('kpiAnswered').textContent = sumAnswered.toLocaleString();
        document.getElementById('kpiSLA').textContent = `${sla}%`;
        document.getElementById('kpiASA').textContent = `${avgASA}s`;
        document.getElementById('kpiAbandon').textContent = `${aband}%`;
    }

    function updateCharts(dailyRows, intradayData) {
        // Aggregate daily data across campaigns for the chart
        const dailyMap = {};
        dailyRows.forEach(r => {
            if (!dailyMap[r.date]) {
                dailyMap[r.date] = { offered: 0, answered: 0, abandoned: 0, sumASA: 0, count: 0 };
            }
            dailyMap[r.date].offered += r.offered;
            dailyMap[r.date].answered += r.answered;
            dailyMap[r.date].abandoned += r.abandoned;
            if (r.offered > 0) {
                dailyMap[r.date].sumASA += r.avg_answer_speed;
                dailyMap[r.date].count++;
            }
        });

        const dates = Object.keys(dailyMap).sort();
        const offeredData = dates.map(d => dailyMap[d].offered);
        const answeredData = dates.map(d => dailyMap[d].answered);
        const abandonRates = dates.map(d => dailyMap[d].offered > 0 ? ((dailyMap[d].abandoned / dailyMap[d].offered) * 100).toFixed(1) : 0);
        const asaData = dates.map(d => dailyMap[d].count > 0 ? Math.round(dailyMap[d].sumASA / dailyMap[d].count) : 0);

        // Simple Moving Average for Predictions (Window size 3)
        const smaData = calculateSMA(offeredData, 3);
        const extendedDates = [...dates];
        if (dates.length > 0) {
            let lastDate = new Date(dates[dates.length - 1]);
            for (let i = 1; i <= 3; i++) {
                lastDate.setDate(lastDate.getDate() + 1);
                extendedDates.push(lastDate.toISOString().split('T')[0]);
            }
        }

        if (dailyChart) dailyChart.destroy();
        dailyChart = new Chart(document.getElementById('dailyChart'), {
            type: 'line',
            data: {
                labels: extendedDates,
                datasets: [
                    {
                        label: 'Ofrecidas',
                        data: offeredData,
                        borderColor: '#94a3b8',
                        backgroundColor: '#94a3b8',
                        tension: 0.3
                    },
                    {
                        label: 'Atendidas',
                        data: answeredData,
                        borderColor: '#10b981',
                        backgroundColor: '#10b981',
                        tension: 0.3
                    },
                    {
                        label: 'SMA (Tendencia 3 días)',
                        data: smaData,
                        borderColor: '#22d3ee',
                        borderDash: [5, 5],
                        pointRadius: 0,
                        tension: 0.3
                    }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });

        if (abandonChart) abandonChart.destroy();
        abandonChart = new Chart(document.getElementById('abandonChart'), {
            type: 'bar',
            data: {
                labels: dates,
                datasets: [
                    {
                        label: 'Tasa Abandono (%)',
                        data: abandonRates,
                        backgroundColor: '#f43f5e',
                        yAxisID: 'y'
                    },
                    {
                        type: 'line',
                        label: 'ASA (seg)',
                        data: asaData,
                        borderColor: '#f59e0b',
                        backgroundColor: '#f59e0b',
                        tension: 0.3,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { type: 'linear', position: 'left' },
                    y1: { type: 'linear', position: 'right', grid: { drawOnChartArea: false } }
                }
            }
        });

        // Intraday Data
        // Combine all campaigns to get an overall view of the day hours
        let hourlyOffered = Array(24).fill(0);
        let hourlyAnswered = Array(24).fill(0);

        Object.values(intradayData).forEach(campData => {
            campData.forEach(hData => {
                hourlyOffered[hData.hour] += hData.avg_offered;
                hourlyAnswered[hData.hour] += hData.avg_answered;
            });
        });

        const hoursLabels = Array.from({ length: 24 }, (_, i) => `${i}:00`);

        if (intradayChart) intradayChart.destroy();
        intradayChart = new Chart(document.getElementById('intradayChart'), {
            type: 'line',
            data: {
                labels: hoursLabels,
                datasets: [
                    {
                        label: 'Vol. Prom. Ofrecidas',
                        data: hourlyOffered,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.2)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Vol. Prom. Atendidas',
                        data: hourlyAnswered,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.2)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }

    async function refreshAlerts() {
        const start = document.getElementById('gapStart').value;
        const end = document.getElementById('gapEnd').value;
        const res = await fetch(`${planningApi}?action=alerts&start_date=${start}&end_date=${end}`);
        const data = await res.json();
        if (!data.success) return;
        const rows = data.alerts || [];
        if (!rows.length) {
            document.getElementById('alertTable').innerHTML = '<tr><td colspan="5" class="p-6 text-center text-slate-500">Sin alertas</td></tr>';
            return;
        }
        document.getElementById('alertTable').innerHTML = rows.map(row => `
        <tr class="hover:bg-slate-700/30 transition-colors">
            <td class="p-3 text-slate-300">${row.alert_date}</td>
            <td class="p-3 text-slate-300">${row.user_name ?? 'Agente'}</td>
            <td class="p-3 text-slate-300">${row.campaign_name ?? '-'}</td>
            <td class="p-3 text-slate-300">${row.alert_type}</td>
            <td class="p-3 text-slate-300">${row.message}</td>
        </tr>
    `).join('');
    }

    document.getElementById('refreshGap').addEventListener('click', () => {
        refreshGap();
        refreshAlerts();
    });

    document.getElementById('generateAlerts').addEventListener('click', async () => {
        const today = new Date().toISOString().slice(0, 10);
        const data = new URLSearchParams();
        data.append('action', 'generate_alerts');
        data.append('alert_date', today);
        const res = await fetch(planningApi, { method: 'POST', body: data });
        const payload = await res.json();
        const status = document.getElementById('forecastStatus');
        if (payload.success) {
            status.textContent = `Alertas creadas: ${payload.created}`;
            refreshAlerts();
        }
    });

    document.getElementById('forecastForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const submitBtn = form.querySelector('button');
        const originalText = submitBtn.innerHTML;
        const status = document.getElementById('forecastStatus');

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Procesando...';
        status.textContent = 'Procesando archivo, por favor espera...';
        status.className = 'mt-3 text-sm text-cyan-400';

        try {
            const formData = new FormData(form);
            const res = await fetch('../api/campaign_staffing.php', { method: 'POST', body: formData });

            let payload;
            try {
                payload = await res.json();
            } catch (err) {
                const text = await res.text();
                throw new Error("El servidor no devolvió una respuesta válida. Intente nuevamente.");
            }

            if (payload.success) {
                const forecastInfo = (typeof payload.forecast_inserted !== 'undefined' || typeof payload.forecast_updated !== 'undefined')
                    ? ` | Staffing WFM: Insertados ${payload.forecast_inserted ?? 0}, Actualizados ${payload.forecast_updated ?? 0}`
                    : '';
                
                let statusClass = 'mt-4 p-4 rounded-lg border';
                let iconClass = 'fas fa-check-circle';
                let icon = '✓';
                
                if (payload.missing_columns && payload.missing_columns.length > 0) {
                    statusClass += ' bg-amber-900/20 border-amber-700/50 text-amber-200';
                    iconClass = 'fas fa-exclamation-triangle';
                    icon = '⚠️';
                } else {
                    statusClass += ' bg-emerald-900/20 border-emerald-700/50 text-emerald-200';
                }
                
                status.className = statusClass;
                
                let html = `
                    <div class="flex items-start gap-3">
                        <i class="${iconClass} text-2xl mt-0.5"></i>
                        <div class="flex-1">
                            <div class="font-semibold text-lg mb-2">Reporte Procesado Exitosamente</div>
                            <div class="space-y-2 text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold">Campaña:</span>
                                    <span class="px-2 py-1 bg-cyan-900/40 rounded text-cyan-200">${payload.campaign_name || 'N/A'}</span>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                    <div>
                                        <span class="text-xs opacity-75">Registros Insertados:</span>
                                        <span class="ml-2 font-bold">${payload.inserted}</span>
                                    </div>
                                    <div>
                                        <span class="text-xs opacity-75">Actualizados:</span>
                                        <span class="ml-2 font-bold">${payload.updated}</span>
                                    </div>
                                    <div>
                                        <span class="text-xs opacity-75">Omitidos:</span>
                                        <span class="ml-2 font-bold">${payload.skipped}</span>
                                    </div>
                                </div>
                `;
                
                if (forecastInfo) {
                    html += `
                        <div class="pt-2 border-t border-current opacity-50 text-xs">
                            ${forecastInfo}
                        </div>
                    `;
                }
                
                if (payload.missing_columns && payload.missing_columns.length > 0) {
                    html += `
                        <div class="mt-3 pt-3 border-t border-amber-700/30">
                            <div class="font-semibold mb-1">Advertencia: Columnas Faltantes</div>
                            <div class="text-xs">
                                Las siguientes columnas no se encontraron en el archivo: 
                                <span class="font-mono bg-amber-900/40 px-2 py-1 rounded">${payload.missing_columns.join(', ')}</span>
                            </div>
                            <div class="text-xs mt-1 opacity-75">Los datos se procesaron con valores por defecto.</div>
                        </div>
                    `;
                }
                
                html += `
                            </div>
                        </div>
                    </div>
                `;
                
                status.innerHTML = html;
                
                // Refresh data after 1 second
                setTimeout(() => refreshGap(), 1000);
            } else {
                status.className = 'mt-3 text-sm text-rose-400';
                status.textContent = payload.error || 'Error cargando reporte';
            }
        } catch (error) {
            status.className = 'mt-3 text-sm text-rose-400';
            status.textContent = error.message || 'Error de conexión o servidor.';
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    });

    document.getElementById('deleteDataForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!confirm('¿Está seguro de eliminar los datos de inbound de este periodo? No podrá deshacer esta acción.')) return;

        const form = e.target;
        const submitBtn = form.querySelector('button');
        const originalText = submitBtn.innerHTML;
        const status = document.getElementById('deleteStatus');

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Eliminando...';
        status.textContent = 'Eliminando...';
        status.className = 'mt-3 text-sm text-cyan-400';

        try {
            const formData = new FormData(form);
            formData.append('action', 'delete_inbound');
            const res = await fetch(planningApi, { method: 'POST', body: formData });
            const payload = await res.json();

            if (payload.success) {
                status.className = 'mt-3 text-sm text-emerald-400';
                status.textContent = payload.message || 'Datos eliminados correctamente';
                refreshGap();
            } else {
                status.className = 'mt-3 text-sm text-rose-400';
                status.textContent = payload.error || 'Error al eliminar datos';
            }
        } catch (error) {
            status.className = 'mt-3 text-sm text-rose-400';
            status.textContent = 'Error de conexión';
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    });

    refreshGap();
    refreshAlerts();
</script>

</body>

</html>
