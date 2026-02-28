<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

ensurePermission('wfm_planning');

$campaigns = $pdo->query("SELECT id, name FROM campaigns ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/../header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-bold text-white">
                <i class="fas fa-calendar-check text-cyan-400 mr-2"></i> Planificacion WFM
            </h1>
            <p class="text-slate-400">Pronostico de demanda, dimensionamiento y alertas intradia.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 xl:col-span-2">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Carga Inbound Daily Report</h3>
            <form id="forecastForm" class="grid grid-cols-1 md:grid-cols-3 gap-4" enctype="multipart/form-data">
                <div>
                    <label class="text-xs uppercase text-slate-400">Campaña</label>
                    <select name="campaign_id"
                        class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200"
                        required>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs uppercase text-slate-400">Archivo CSV</label>
                    <input type="file" name="report_file"
                        class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200"
                        accept=".csv" required>
                </div>
                <div class="md:col-span-3">
                    <button class="px-4 py-2 rounded bg-cyan-600 text-white hover:bg-cyan-500 transition-colors">
                        <i class="fas fa-upload mr-2"></i> Cargar reporte Inbound
                    </button>
                    <p class="text-xs text-slate-500 mt-2">
                        Sube el archivo CSV "Inbound Daily Report" descargado de Vicidial con métricas de ofrecidas,
                        atendidas, abandonadas, SLAs, etc.
                    </p>
                </div>
            </form>
            <div id="forecastStatus" class="mt-3 text-sm text-slate-400"></div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Filtros</h3>
            <div class="grid grid-cols-1 gap-3">
                <div>
                    <label class="text-xs uppercase text-slate-400">Desde</label>
                    <input type="date" id="gapStart"
                        class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200"
                        value="<?= date('Y-m-01') ?>">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Hasta</label>
                    <input type="date" id="gapEnd"
                        class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200"
                        value="<?= date('Y-m-t') ?>">
                </div>
                <button id="refreshGap"
                    class="px-4 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-500 transition-colors">
                    <i class="fas fa-sync mr-2"></i> Actualizar métricas
                </button>
            </div>
        </div>
    </div>

    <!-- KPIs Row -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8" id="kpiContainer">
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-4 text-center">
            <div class="text-xs uppercase text-slate-400 mb-1">Total Ofrecidas</div>
            <div class="text-2xl font-bold text-slate-200" id="kpiOffered">-</div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-4 text-center">
            <div class="text-xs uppercase text-slate-400 mb-1">Total Atendidas</div>
            <div class="text-2xl font-bold text-emerald-400" id="kpiAnswered">-</div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-4 text-center">
            <div class="text-xs uppercase text-slate-400 mb-1">Nivel Atención</div>
            <div class="text-2xl font-bold text-cyan-400" id="kpiSLA">-</div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-4 text-center">
            <div class="text-xs uppercase text-slate-400 mb-1">ASA Promedio</div>
            <div class="text-2xl font-bold text-amber-400" id="kpiASA">-</div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-4 text-center">
            <div class="text-xs uppercase text-slate-400 mb-1">Tasa Abandono</div>
            <div class="text-2xl font-bold text-rose-400" id="kpiAbandon">-</div>
        </div>
    </div>

    <!-- Charts Row 1 -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Evolución Diaria y Predicción</h3>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="dailyChart"></canvas>
            </div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Perfil Intradía (Promedio por Hora)</h3>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="intradayChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 -->
    <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 mb-8">
        <h3 class="text-lg font-semibold text-slate-200 mb-4">Tasa de Abandono y ASA</h3>
        <div style="position: relative; height: 300px; width: 100%;">
            <canvas id="abandonChart"></canvas>
        </div>
    </div>

    <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 mb-8">
        <h3 class="text-lg font-semibold text-slate-200 mb-4">Métricas Inbound (Totales)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-900/60 text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="p-3">Campaña</th>
                        <th class="p-3 text-right">Ofrecidas</th>
                        <th class="p-3 text-right">Atendidas</th>
                        <th class="p-3 text-right">Abandonadas</th>
                        <th class="p-3 text-right">Abandono %</th>
                        <th class="p-3 text-right">Atención %</th>
                        <th class="p-3 text-right">ASA (seg)</th>
                        <th class="p-3 text-right">AHT (seg)</th>
                    </tr>
                </thead>
                <tbody id="gapTotals" class="divide-y divide-slate-700 text-slate-200">
                    <tr>
                        <td colspan="8" class="p-6 text-center text-slate-500">Cargando...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 mb-8">
        <h3 class="text-lg font-semibold text-slate-200 mb-4">Métricas Inbound por Día</h3>
        <div class="overflow-x-auto" style="max-height: 400px;">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-900/60 text-slate-400 uppercase text-xs sticky top-0">
                    <tr>
                        <th class="p-3">Intervalo</th>
                        <th class="p-3">Campaña</th>
                        <th class="p-3 text-right">Ofrecidas</th>
                        <th class="p-3 text-right">Atendidas</th>
                        <th class="p-3 text-right">Abandonadas</th>
                        <th class="p-3 text-right">Abandono %</th>
                        <th class="p-3 text-right">Atención %</th>
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
        <h3 class="text-lg font-semibold text-rose-400 mb-4"><i class="fas fa-trash-alt mr-2"></i>Gestión de Datos
            Inbound</h3>
        <p class="text-sm text-slate-400 mb-4">Elimina registros importados de forma masiva si subiste datos incorrectos
            o repetidos.</p>
        <form id="deleteDataForm" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div>
                <label class="text-xs uppercase text-slate-400">Campaña a purgar</label>
                <select name="campaign_id"
                    class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" required>
                    <option value="">Seleccione...</option>
                    <?php foreach ($campaigns as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
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
            <div>
                <button type="submit"
                    class="w-full px-4 py-2 rounded bg-rose-600 text-white hover:bg-rose-500 transition-colors flex justify-center items-center">
                    <i class="fas fa-exclamation-triangle mr-2"></i> Eliminar Datos
                </button>
            </div>
        </form>
        <div id="deleteStatus" class="mt-3 text-sm text-slate-400"></div>
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
                <div class="font-medium text-white">${row.campaign_name}</div>
                <div class="text-xs text-slate-500">${row.campaign_code ?? ''}</div>
            </td>
            <td class="p-3 text-right font-medium text-slate-300">${row.offered}</td>
            <td class="p-3 text-right font-medium text-emerald-400">${row.answered}</td>
            <td class="p-3 text-right font-medium text-rose-400">${row.abandoned}</td>
            <td class="p-3 text-right font-medium ${row.abandon_percent > 10 ? 'text-rose-400' : 'text-slate-300'}">${row.abandon_percent}%</td>
            <td class="p-3 text-right font-medium text-slate-300">${row.answer_percent}%</td>
            <td class="p-3 text-right font-medium text-slate-300">${row.avg_answer_speed}</td>
            <td class="p-3 text-right font-medium text-slate-300">${row.avg_talk_time + row.avg_wrap_time}</td>
        </tr>
        `).join('');

        const dailyRows = data.daily || [];
        document.getElementById('gapDaily').innerHTML = dailyRows.map(row => `
        <tr class="hover:bg-slate-700/30 transition-colors">
            <td class="p-3 font-medium text-cyan-400">${row.date}</td>
            <td class="p-3">
                <div class="font-medium text-white">${row.campaign_name}</div>
                <div class="text-xs text-slate-500">${row.campaign_code ?? ''}</div>
            </td>
            <td class="p-3 text-right text-slate-300">${row.offered}</td>
            <td class="p-3 text-right text-emerald-400">${row.answered}</td>
            <td class="p-3 text-right text-rose-400">${row.abandoned}</td>
            <td class="p-3 text-right ${row.abandon_percent > 10 ? 'text-rose-400' : 'text-slate-300'}">${row.abandon_percent}%</td>
            <td class="p-3 text-right text-slate-300">${row.answer_percent}%</td>
            <td class="p-3 text-right text-slate-300">${row.avg_answer_speed}</td>
            <td class="p-3 text-right text-slate-300">${row.avg_talk_time + row.avg_wrap_time}</td>
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
                status.className = 'mt-3 text-sm text-emerald-400';
                const forecastInfo = (typeof payload.forecast_inserted !== 'undefined' || typeof payload.forecast_updated !== 'undefined')
                    ? ` | Staffing WFM: Insertados ${payload.forecast_inserted ?? 0}, Actualizados ${payload.forecast_updated ?? 0}`
                    : '';
                status.textContent = `Cargado. Inbound: Insertados ${payload.inserted}, Actualizados ${payload.updated}, Omitidos ${payload.skipped}${forecastInfo}`;
                refreshGap();
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
