<?php
require_once __DIR__ . '/header.php';

// Only authenticated users
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" x-data="wasapiDashboard()">
    <!-- Header with Filters -->
    <div
        class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8 bg-slate-800/50 p-6 rounded-2xl border border-slate-700/50 backdrop-blur-sm">
        <div>
            <h1 class="text-2xl font-bold text-white flex items-center gap-3">
                <div
                    class="h-10 w-10 rounded-xl bg-emerald-500/20 border border-emerald-500/40 flex items-center justify-center text-emerald-400">
                    <i class="fab fa-whatsapp text-xl"></i>
                </div>
                Reportería Wasapi
            </h1>
            <p class="text-slate-400 mt-1 text-sm">Métricas en tiempo real y rendimiento histórico de la plataforma
                Wasapi</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-calendar text-slate-400"></i>
                </div>
                <input type="date" x-model="filters.start_date" @change="fetchData"
                    class="w-full bg-slate-900 border border-slate-700 text-slate-200 rounded-lg pl-10 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500">
            </div>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-calendar text-slate-400"></i>
                </div>
                <input type="date" x-model="filters.end_date" @change="fetchData"
                    class="w-full bg-slate-900 border border-slate-700 text-slate-200 rounded-lg pl-10 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500">
            </div>
            <button @click="fetchData"
                class="bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2">
                <i class="fas fa-sync-alt" :class="{'fa-spin': isLoading}"></i>
                <span>Actualizar</span>
            </button>
        </div>
    </div>

    <!-- Error State -->
    <div x-show="error" style="display: none;"
        class="mb-8 p-4 bg-rose-500/10 border border-rose-500/20 rounded-xl text-rose-400 text-sm flex items-start gap-3">
        <i class="fas fa-exclamation-circle mt-0.5"></i>
        <div>
            <p class="font-medium">Error al cargar datos</p>
            <p x-text="error" class="text-rose-400/80 mt-1"></p>
        </div>
    </div>

    <!-- KPI Cards (Real Time) -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Online Agents -->
        <div class="bg-slate-800/40 border border-slate-700/50 rounded-2xl p-6 relative overflow-hidden group">
            <div
                class="absolute inset-0 bg-gradient-to-br from-emerald-500/5 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
            </div>
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-slate-400 text-sm font-medium">Agentes en Línea</p>
                    <h3 class="text-3xl font-bold text-white mt-2" x-text="metrics.onlineAgents">0</h3>
                </div>
                <div class="h-10 w-10 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center">
                    <i class="fas fa-headset"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-2 text-xs">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-emerald-400 font-medium">Tiempo real</span>
            </div>
        </div>

        <!-- Total Work Volume (From Reports) -->
        <div class="bg-slate-800/40 border border-slate-700/50 rounded-2xl p-6 relative overflow-hidden group">
            <div
                class="absolute inset-0 bg-gradient-to-br from-blue-500/5 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
            </div>
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-slate-400 text-sm font-medium">Volumen de Trabajo</p>
                    <h3 class="text-3xl font-bold text-white mt-2" x-text="metrics.totalVolume">0</h3>
                </div>
                <div class="h-10 w-10 rounded-xl bg-blue-500/20 text-blue-400 flex items-center justify-center">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
            <p class="text-slate-500 text-xs mt-4" x-text="'Periodo: ' + filters.start_date + ' a ' + filters.end_date">
            </p>
        </div>

        <!-- Performance Average (Derived from Reports) -->
        <div class="bg-slate-800/40 border border-slate-700/50 rounded-2xl p-6 relative overflow-hidden group">
            <div
                class="absolute inset-0 bg-gradient-to-br from-purple-500/5 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
            </div>
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-slate-400 text-sm font-medium">Prom. Resoluciones</p>
                    <h3 class="text-3xl font-bold text-white mt-2" x-text="metrics.avgResolution">0</h3>
                </div>
                <div class="h-10 w-10 rounded-xl bg-purple-500/20 text-purple-400 flex items-center justify-center">
                    <i class="fas fa-check-double"></i>
                </div>
            </div>
            <p class="text-slate-500 text-xs mt-4">Promedio por agente activo</p>
        </div>

        <!-- Total Agents in Report -->
        <div class="bg-slate-800/40 border border-slate-700/50 rounded-2xl p-6 relative overflow-hidden group">
            <div
                class="absolute inset-0 bg-gradient-to-br from-amber-500/5 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
            </div>
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-slate-400 text-sm font-medium">Agentes Activos</p>
                    <h3 class="text-3xl font-bold text-white mt-2" x-text="metrics.activeAgentsReport">0</h3>
                </div>
                <div class="h-10 w-10 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center">
                    <i class="fas fa-users-cog"></i>
                </div>
            </div>
            <p class="text-slate-500 text-xs mt-4">En el rango seleccionado</p>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Volume of Workflow Chart -->
        <div class="bg-slate-800/40 border border-slate-700/50 rounded-2xl p-6 relative">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-white">Volumen de Trabajo por Hora</h3>
            </div>
            <div class="relative h-72">
                <div x-show="isLoading"
                    class="absolute inset-0 flex items-center justify-center bg-slate-800/50 rounded-xl z-10 backdrop-blur-[2px]">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-emerald-500"></div>
                </div>
                <canvas id="workflowChart"></canvas>
            </div>
        </div>

        <!-- Performance by Agent Chart -->
        <div class="bg-slate-800/40 border border-slate-700/50 rounded-2xl p-6 relative">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-white">Rendimiento Top 10 Agentes</h3>
            </div>
            <div class="relative h-72">
                <div x-show="isLoading"
                    class="absolute inset-0 flex items-center justify-center bg-slate-800/50 rounded-xl z-10 backdrop-blur-[2px]">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-emerald-500"></div>
                </div>
                <canvas id="performanceChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Detailed Performance Table -->
    <div class="bg-slate-800/40 border border-slate-700/50 rounded-2xl overflow-hidden">
        <div class="p-6 border-b border-slate-700/50 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-white">Detalle de Rendimiento por Agente</h3>
        </div>
        <div class="overflow-x-auto relative min-h-[200px]">
            <div x-show="isLoading"
                class="absolute inset-0 flex items-center justify-center bg-slate-800/50 z-10 backdrop-blur-[2px]">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-emerald-500"></div>
            </div>
            <table class="w-full text-left text-sm whitespace-nowrap">
                <thead class="bg-slate-800 text-slate-400 font-medium">
                    <tr>
                        <th class="px-6 py-4">Agente</th>
                        <th class="px-6 py-4">Asignaciones</th>
                        <th class="px-6 py-4">Respuestas Enviadas</th>
                        <th class="px-6 py-4">Conversaciones Resueltas</th>
                        <th class="px-6 py-4">TMR (Tiempo Medio de Respuesta)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700/50 text-slate-300">
                    <template x-for="agent in performanceData" :key="agent.agent_id || agent.name">
                        <tr class="hover:bg-slate-800/50 transition-colors">
                            <td class="px-6 py-4 flex items-center gap-3">
                                <div class="h-8 w-8 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-bold text-xs"
                                    x-text="(agent.name || agent.agent_name || 'A').substring(0, 2).toUpperCase()">
                                </div>
                                <span class="font-medium text-white"
                                    x-text="agent.name || agent.agent_name || 'Desconocido'"></span>
                            </td>
                            <td class="px-6 py-4 text-center" x-text="agent.assignments || 0"></td>
                            <td class="px-6 py-4 text-center" x-text="agent.sent_messages || agent.replies || 0"></td>
                            <td class="px-6 py-4 text-center">
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-500/10 text-emerald-400 font-medium text-xs">
                                    <i class="fas fa-check"></i>
                                    <span x-text="agent.resolved_conversations || agent.resolutions || 0"></span>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center text-slate-400"
                                x-text="formatDuration(agent.tmr || agent.average_response_time || 0)"></td>
                        </tr>
                    </template>
                    <tr x-show="performanceData.length === 0 && !isLoading">
                        <td colspan="5" class="px-6 py-8 text-center text-slate-500">
                            No hay datos disponibles para el rango de fechas seleccionado
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        let performanceChartInstance = null;
        let workflowChartInstance = null;

        Alpine.data('wasapiDashboard', () => ({
            isLoading: false,
            error: null,
            filters: {
                start_date: new Date().toISOString().split('T')[0],
                end_date: new Date().toISOString().split('T')[0]
            },
            metrics: {
                onlineAgents: 0,
                totalVolume: 0,
                avgResolution: 0,
                activeAgentsReport: 0
            },
            performanceData: [],
            workflowData: [],

            init() {
                this.initCharts();
                this.fetchData();
                // Automatically refresh online agents every 60 seconds
                setInterval(() => {
                    if (!this.isLoading) this.fetchOnlineAgents();
                }, 60000);
            },

            initCharts() {
                // Chart.js global defaults for dark theme
                Chart.defaults.color = '#94a3b8';
                Chart.defaults.borderColor = 'rgba(51, 65, 85, 0.5)';
                Chart.defaults.font.family = "'Inter', sans-serif";

                const perfCtx = document.getElementById('performanceChart').getContext('2d');
                if (performanceChartInstance) performanceChartInstance.destroy();
                performanceChartInstance = new Chart(perfCtx, {
                    type: 'bar',
                    data: {
                        labels: [],
                        datasets: [
                            {
                                label: 'Resueltas',
                                data: [],
                                backgroundColor: 'rgba(16, 185, 129, 0.8)',
                                borderRadius: 4
                            },
                            {
                                label: 'Asignaciones',
                                data: [],
                                backgroundColor: 'rgba(56, 189, 248, 0.6)',
                                borderRadius: 4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(51, 65, 85, 0.3)' } },
                            x: { grid: { display: false } }
                        }
                    }
                });

                const workCtx = document.getElementById('workflowChart').getContext('2d');
                if (workflowChartInstance) workflowChartInstance.destroy();
                workflowChartInstance = new Chart(workCtx, {
                    type: 'line',
                    data: {
                        labels: [],
                        datasets: [{
                            label: 'Volumen',
                            data: [],
                            borderColor: 'rgba(139, 92, 246, 1)',
                            backgroundColor: 'rgba(139, 92, 246, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: 'rgba(139, 92, 246, 1)',
                            pointBorderColor: '#1e293b',
                            pointBorderWidth: 2,
                            pointRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(51, 65, 85, 0.3)' } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            },

            async fetchData() {
                if (this.isLoading) return;
                this.isLoading = true;
                this.error = null;

                try {
                    // We run promises in parallel
                    await Promise.all([
                        this.fetchOnlineAgents(),
                        this.fetchPerformance(),
                        this.fetchWorkflow()
                    ]);
                } catch (err) {
                    console.error(err);
                    this.error = err.message || 'Ocurrió un error al contactar el proxy Wasapi.';
                } finally {
                    this.isLoading = false;
                }
            },

            async fetchOnlineAgents() {
                try {
                    const res = await fetch(`api/wasapi_proxy.php?endpoint=dashboard/metrics/online-agents`);
                    if (!res.ok) throw new Error('Error de red');
                    const data = await res.json();

                    // Assuming data.users contains the agents, and online=1 means online
                    if (data.users && Array.isArray(data.users)) {
                        this.metrics.onlineAgents = data.users.filter(u => u.online === 1).length;
                    } else if (data.data) {
                        // Fallback depending on exact Wasapi payload structure
                        this.metrics.onlineAgents = data.data.online || 0;
                    }
                } catch (e) {
                    console.warn('Could not fetch online agents', e);
                }
            },

            async fetchPerformance() {
                const res = await fetch(`api/wasapi_proxy.php?endpoint=reports/performance-by-agent&start_date=${this.filters.start_date}&end_date=${this.filters.end_date}`);
                if (!res.ok) {
                    const errData = await res.json().catch(() => ({}));
                    throw new Error(errData.message || 'Error consultando rendimiento de agentes');
                }
                const data = await res.json();

                let rawList = [];
                if (Array.isArray(data)) rawList = data;
                else if (data.data && Array.isArray(data.data)) rawList = data.data;

                // Group by agent since the API returns daily records
                const grouped = {};
                rawList.forEach(item => {
                    if (!item.agent) return;
                    const aId = item.agent.id;
                    if (!grouped[aId]) {
                        grouped[aId] = {
                            agent_id: aId,
                            name: item.agent.name,
                            assignments: 0,
                            sent_messages: 0,
                            resolutions: 0,
                            total_response_time: 0,
                            days_active: 0
                        };
                    }
                    grouped[aId].assignments += parseInt(item.total_asigned_by_automatic || 0) + parseInt(item.total_asigned_by_manual || 0) + parseInt(item.total_scaled_to_agents || 0);
                    grouped[aId].resolutions += parseInt(item.total_close_conversations || 0);
                    grouped[aId].total_response_time += parseFloat(item.avg_first_response_time || 0);
                    grouped[aId].days_active += 1;
                });

                const list = Object.values(grouped).map(a => {
                    a.tmr = a.days_active > 0 ? (a.total_response_time / a.days_active) : 0;
                    return a;
                });

                this.performanceData = list;
                this.metrics.activeAgentsReport = list.length;

                let totalRes = 0;
                list.forEach(a => {
                    totalRes += a.resolutions;
                });
                this.metrics.avgResolution = list.length > 0 ? (totalRes / list.length).toFixed(1) : 0;

                // Update Chart
                const top10 = [...list].sort((a, b) => b.resolutions - a.resolutions).slice(0, 10);
                if (performanceChartInstance) {
                    performanceChartInstance.data.labels = top10.map(a => a.name.split(' ')[0]);
                    performanceChartInstance.data.datasets[0].data = top10.map(a => a.resolutions);
                    performanceChartInstance.data.datasets[1].data = top10.map(a => a.assignments);
                    performanceChartInstance.update();
                }
            },

            async fetchWorkflow() {
                const res = await fetch(`api/wasapi_proxy.php?endpoint=reports/volume-of-workflow&start_date=${this.filters.start_date}&end_date=${this.filters.end_date}`);
                if (!res.ok) {
                    console.warn('Volume of workflow endpoint returned error');
                    return;
                }
                const data = await res.json();

                let list = [];
                if (Array.isArray(data)) list = data;
                else if (data.data && Array.isArray(data.data)) list = data.data;

                this.workflowData = list;

                let sum = 0;
                const hourlyVolume = Array(24).fill(0);

                list.forEach(item => {
                    let v = parseInt(item.total_close_conversations || item.total_open_conversations || 0, 10);
                    let h = parseInt(item.hour || 0, 10);
                    sum += v;
                    if(h >= 0 && h < 24) {
                        hourlyVolume[h] += v;
                    }
                });

                this.metrics.totalVolume = sum;

                if (workflowChartInstance) {
                    workflowChartInstance.data.labels = Array.from({length: 24}, (_, i) => `${i}:00`);
                    workflowChartInstance.data.datasets[0].data = hourlyVolume;
                    workflowChartInstance.update();
                }
            },

            formatDuration(seconds) {
                if (!seconds || isNaN(seconds)) return '0s';
                sec = parseInt(seconds, 10);
                const m = Math.floor(sec / 60);
                const s = sec % 60;
                if (m > 0) return `${m}m ${s}s`;
                return `${s}s`;
            }
        }));
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>