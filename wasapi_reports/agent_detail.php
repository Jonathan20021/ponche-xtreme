<?php
require_once __DIR__ . '/../header.php';

// Only authenticated users
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$agentId = $_GET['id'] ?? null;
if (!$agentId) {
    header("Location: index.php");
    exit;
}
?>

<div class="container mx-auto px-4 py-8" x-data="wasapiAgentDetail(<?= (int)$agentId ?>)">
    <!-- Back Button & Header -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-8">
        <div class="flex items-center gap-4">
            <a href="index.php" class="p-3 bg-white shadow-lg hover:shadow-xl rounded-xl text-gray-600 hover:text-emerald-600 transition-all">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <div class="flex items-center gap-4">
                    <div class="h-16 w-16 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-2xl font-bold text-white shadow-lg"
                        x-text="getInitials()"></div>
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800" x-text="agent?.name || 'Cargando...'"></h1>
                        <div class="flex items-center gap-3 mt-1">
                            <span class="text-gray-500" x-text="agent?.email"></span>
                            <span x-show="agent?.online" class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-100 text-emerald-600 rounded-full text-xs font-medium">
                                <span class="relative flex h-2 w-2">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                </span>
                                Online
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="flex gap-3">
            <button @click="analyzeWithAI" :disabled="aiLoading"
                class="bg-gradient-to-r from-purple-500 to-indigo-600 hover:from-purple-600 hover:to-indigo-700 disabled:opacity-50 text-white px-5 py-2.5 rounded-xl text-sm font-medium transition-all flex items-center gap-2 shadow-lg">
                <i class="fas fa-robot" :class="{'animate-pulse': aiLoading}"></i>
                <span x-text="aiLoading ? 'Analizando...' : 'Análisis IA'"></span>
            </button>
        </div>
    </div>
    
    <!-- Date Filter & Realtime Badge -->
    <div class="flex flex-wrap items-center gap-4 mb-8">
        <div x-show="realtimeMode" class="flex items-center gap-2 bg-emerald-100 text-emerald-700 px-4 py-2 rounded-xl text-sm font-medium">
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
            </span>
            Tiempo Real
        </div>
        <div x-show="!realtimeMode" class="flex items-center gap-2">
            <span class="bg-amber-100 text-amber-700 px-4 py-2 rounded-xl text-sm font-medium flex items-center gap-2">
                <i class="fas fa-history"></i> Histórico
            </span>
            <button @click="returnToRealtime()" class="bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded-xl text-sm flex items-center gap-2">
                <i class="fas fa-bolt"></i> Volver a Realtime
            </button>
        </div>
        
        <input type="date" x-model="filters.startDate" @change="applyDateFilter()"
            class="border-0 bg-white shadow rounded-xl px-4 py-2 text-sm focus:ring-2 focus:ring-emerald-500">
        <input type="date" x-model="filters.endDate" @change="applyDateFilter()"
            class="border-0 bg-white shadow rounded-xl px-4 py-2 text-sm focus:ring-2 focus:ring-emerald-500">
        <button @click="loadData" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-5 py-2 rounded-xl text-sm font-medium shadow-lg flex items-center gap-2">
            <i class="fas fa-sync-alt" :class="{'animate-spin': loading}"></i> Actualizar
        </button>
    </div>
    
    <!-- Loading State -->
    <div x-show="loading && !agent && !error" class="flex items-center justify-center py-16">
        <div class="text-center">
            <div class="animate-spin rounded-full h-16 w-16 border-4 border-emerald-200 border-t-emerald-500 mx-auto mb-4"></div>
            <p class="text-gray-500">Cargando datos del agente...</p>
        </div>
    </div>
    
    <!-- Error State -->
    <div x-show="error && !loading" class="flex items-center justify-center py-16">
        <div class="text-center bg-red-50 rounded-xl p-8">
            <i class="fas fa-exclamation-triangle text-4xl text-red-500 mb-4"></i>
            <p class="text-red-600 font-medium" x-text="error"></p>
            <button @click="loadData()" class="mt-4 bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">
                <i class="fas fa-redo mr-2"></i> Reintentar
            </button>
        </div>
    </div>
    
    <!-- Main Content -->
    <div x-show="agent" x-transition class="space-y-8">
        <!-- Performance KPIs - Modern Glass Style -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <!-- Conversaciones Totales -->
            <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-xl" style="background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);">
                <i class="fas fa-comments text-4xl absolute bottom-3 right-3" style="opacity: 0.2;"></i>
                <p class="text-xs font-medium uppercase tracking-wider" style="color: rgba(255,255,255,0.8);">Conversaciones</p>
                <p class="text-3xl font-bold mt-2" x-text="formatNumber(metrics?.total_conversations || 0)"></p>
            </div>
            
            <!-- Cerradas -->
            <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-xl" style="background: linear-gradient(135deg, #10B981 0%, #059669 100%);">
                <i class="fas fa-check-circle text-4xl absolute bottom-3 right-3" style="opacity: 0.2;"></i>
                <p class="text-xs font-medium uppercase tracking-wider" style="color: rgba(255,255,255,0.8);">Cerradas</p>
                <p class="text-3xl font-bold mt-2" x-text="formatNumber(metrics?.closed_conversations || 0)"></p>
            </div>
            
            <!-- Tiempo Resolución -->
            <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-xl" style="background: linear-gradient(135deg, #8B5CF6 0%, #6366F1 100%);">
                <i class="fas fa-clock text-4xl absolute bottom-3 right-3" style="opacity: 0.2;"></i>
                <p class="text-xs font-medium uppercase tracking-wider" style="color: rgba(255,255,255,0.8);">T. Resolución</p>
                <p class="text-3xl font-bold mt-2" x-text="metrics?.avg_resolution_formatted || '0m'"></p>
            </div>
            
            <!-- Primera Respuesta -->
            <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-xl" style="background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);">
                <i class="fas fa-reply text-4xl absolute bottom-3 right-3" style="opacity: 0.2;"></i>
                <p class="text-xs font-medium uppercase tracking-wider" style="color: rgba(255,255,255,0.8);">1ra Respuesta</p>
                <p class="text-3xl font-bold mt-2" x-text="metrics?.avg_first_response_formatted || '0s'"></p>
            </div>
            
            <!-- Escalados -->
            <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-xl" style="background: linear-gradient(135deg, #F43F5E 0%, #DC2626 100%);">
                <i class="fas fa-arrow-up text-4xl absolute bottom-3 right-3" style="opacity: 0.2;"></i>
                <p class="text-xs font-medium uppercase tracking-wider" style="color: rgba(255,255,255,0.8);">Escalados</p>
                <p class="text-3xl font-bold mt-2" x-text="formatNumber(metrics?.total_scaled || 0)"></p>
            </div>
            
            <!-- Eficiencia -->
            <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-xl" style="background: linear-gradient(135deg, #06B6D4 0%, #0284C7 100%);">
                <i class="fas fa-chart-pie text-4xl absolute bottom-3 right-3" style="opacity: 0.2;"></i>
                <p class="text-xs font-medium uppercase tracking-wider" style="color: rgba(255,255,255,0.8);">Eficiencia</p>
                <p class="text-3xl font-bold mt-2" x-text="(metrics?.efficiency_rate || 0) + '%'"></p>
            </div>
        </div>
        
        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Daily Conversations Chart -->
            <div class="bg-white rounded-2xl shadow-xl p-6">
                <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <span class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center">
                        <i class="fas fa-chart-bar text-blue-500"></i>
                    </span>
                    Conversaciones por Día
                </h3>
                <div class="h-72">
                    <canvas id="dailyConversationsChart"></canvas>
                </div>
            </div>
            
            <!-- Response Times Chart -->
            <div class="bg-white rounded-2xl shadow-xl p-6">
                <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <span class="w-10 h-10 rounded-xl bg-purple-100 flex items-center justify-center">
                        <i class="fas fa-stopwatch text-purple-500"></i>
                    </span>
                    Tiempos de Respuesta (seg)
                </h3>
                <div class="h-72">
                    <canvas id="responseTimesChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- AI Analysis Section -->
        <div x-show="aiAnalysis" x-transition class="bg-gradient-to-br from-purple-50 to-indigo-50 border border-purple-200 rounded-2xl p-6 shadow-lg">
            <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-3">
                <span class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center shadow-lg">
                    <i class="fas fa-robot text-white text-xl"></i>
                </span>
                Análisis Gemini AI
            </h3>
            
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Diagnosis & Score -->
                <div class="bg-white/70 backdrop-blur rounded-xl p-5">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-stethoscope text-purple-500"></i> Diagnóstico
                    </h4>
                    <p class="text-gray-600 leading-relaxed" x-text="aiAnalysis?.analysis?.diagnosis"></p>
                    
                    <div class="mt-4 flex items-center gap-4">
                        <span class="text-gray-500 text-sm">Puntuación:</span>
                        <div class="flex-1 flex items-center gap-3">
                            <div class="flex-1 h-3 bg-gray-200 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500"
                                    :class="(aiAnalysis?.analysis?.performance_score || 0) >= 70 ? 'bg-emerald-500' : (aiAnalysis?.analysis?.performance_score || 0) >= 50 ? 'bg-amber-500' : 'bg-red-500'"
                                    :style="'width: ' + (aiAnalysis?.analysis?.performance_score || 0) + '%'"></div>
                            </div>
                            <span class="text-gray-800 font-bold text-lg" x-text="(aiAnalysis?.analysis?.performance_score || 0) + '/100'"></span>
                        </div>
                    </div>
                </div>
                
                <!-- Strengths & Improvements -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-emerald-50 rounded-xl p-4">
                        <h4 class="text-emerald-700 font-semibold mb-3 flex items-center gap-2">
                            <i class="fas fa-check-circle"></i> Fortalezas
                        </h4>
                        <ul class="space-y-2">
                            <template x-for="s in (aiAnalysis?.analysis?.strengths || [])">
                                <li class="text-gray-600 text-sm flex items-start gap-2">
                                    <i class="fas fa-circle text-emerald-400 text-[6px] mt-2"></i>
                                    <span x-text="s"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <div class="bg-amber-50 rounded-xl p-4">
                        <h4 class="text-amber-700 font-semibold mb-3 flex items-center gap-2">
                            <i class="fas fa-arrow-up"></i> Áreas de Mejora
                        </h4>
                        <ul class="space-y-2">
                            <template x-for="i in (aiAnalysis?.analysis?.improvements || [])">
                                <li class="text-gray-600 text-sm flex items-start gap-2">
                                    <i class="fas fa-circle text-amber-400 text-[6px] mt-2"></i>
                                    <span x-text="i"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Action Plan -->
            <div x-show="aiAnalysis?.analysis?.action_plan?.length > 0" class="mt-6">
                <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2">
                    <i class="fas fa-tasks text-indigo-500"></i> Plan de Acción
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <template x-for="action in (aiAnalysis?.analysis?.action_plan || [])">
                        <div class="bg-white rounded-xl p-4 shadow-sm border border-indigo-100 flex items-start gap-3">
                            <span class="flex-shrink-0 w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-bold" x-text="action.step"></span>
                            <div>
                                <p class="text-gray-800 font-medium text-sm" x-text="action.action"></p>
                                <p class="text-gray-400 text-xs mt-1" x-text="action.timeframe"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
        
        <!-- Performance Detail Table -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="p-6 border-b bg-gradient-to-r from-gray-50 to-white">
                <h3 class="text-xl font-bold text-gray-800 flex items-center gap-3">
                    <span class="w-10 h-10 rounded-xl bg-emerald-100 flex items-center justify-center">
                        <i class="fas fa-table text-emerald-500"></i>
                    </span>
                    Detalle por Día
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-gray-500 font-medium text-xs uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-4">Fecha</th>
                            <th class="px-6 py-4 text-center">Abiertas</th>
                            <th class="px-6 py-4 text-center">Cerradas</th>
                            <th class="px-6 py-4 text-center">T. Resolución</th>
                            <th class="px-6 py-4 text-center">1ra Respuesta</th>
                            <th class="px-6 py-4 text-center">Escalados</th>
                            <th class="px-6 py-4 text-center">Eficiencia</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="day in dailyData" :key="day.date">
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 text-gray-800 font-medium" x-text="formatDateShort(day.date)"></td>
                                <td class="px-6 py-4 text-center text-blue-600 font-medium" x-text="day.open"></td>
                                <td class="px-6 py-4 text-center text-emerald-600 font-medium" x-text="day.closed"></td>
                                <td class="px-6 py-4 text-center">
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-medium"
                                        :class="day.avg_resolution > 900 ? 'bg-red-100 text-red-600' : day.avg_resolution > 600 ? 'bg-amber-100 text-amber-600' : 'bg-emerald-100 text-emerald-600'"
                                        x-text="formatSecondsShort(day.avg_resolution)"></span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-medium"
                                        :class="day.avg_first_response > 120 ? 'bg-red-100 text-red-600' : day.avg_first_response > 60 ? 'bg-amber-100 text-amber-600' : 'bg-emerald-100 text-emerald-600'"
                                        x-text="formatSecondsShort(day.avg_first_response)"></span>
                                </td>
                                <td class="px-6 py-4 text-center text-rose-600 font-medium" x-text="day.scaled"></td>
                                <td class="px-6 py-4 text-center">
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-medium"
                                        :class="day.efficiency >= 80 ? 'bg-emerald-100 text-emerald-600' : day.efficiency >= 60 ? 'bg-amber-100 text-amber-600' : 'bg-red-100 text-red-600'"
                                        x-text="day.efficiency + '%'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="dailyData.length === 0" class="text-center text-gray-500 py-12">
                    <i class="fas fa-inbox text-4xl text-gray-300 mb-3"></i>
                    <p>No hay datos para el período seleccionado</p>
                </div>
            </div>
        </div>
        
        <!-- Assignment Distribution -->
        <div class="bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-3">
                <span class="w-10 h-10 rounded-xl bg-cyan-100 flex items-center justify-center">
                    <i class="fas fa-random text-cyan-500"></i>
                </span>
                Distribución de Asignaciones
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="rounded-xl p-5 text-center border-2" style="background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%); border-color: #93C5FD;">
                    <p class="text-sm font-semibold" style="color: #3B82F6;">Automáticas</p>
                    <p class="text-4xl font-bold mt-2" style="color: #1D4ED8;" x-text="metrics?.automatic_assignments || 0"></p>
                </div>
                <div class="rounded-xl p-5 text-center border-2" style="background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%); border-color: #C4B5FD;">
                    <p class="text-sm font-semibold" style="color: #8B5CF6;">Manuales</p>
                    <p class="text-4xl font-bold mt-2" style="color: #6D28D9;" x-text="metrics?.manual_assignments || 0"></p>
                </div>
                <div class="rounded-xl p-5 text-center border-2" style="background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border-color: #6EE7B7;">
                    <p class="text-sm font-semibold" style="color: #10B981;">Por Bot</p>
                    <p class="text-4xl font-bold mt-2" style="color: #047857;" x-text="metrics?.bot_assignments || 0"></p>
                </div>
                <div class="rounded-xl p-5 text-center border-2" style="background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); border-color: #FCD34D;">
                    <p class="text-sm font-semibold" style="color: #F59E0B;">Por Make</p>
                    <p class="text-4xl font-bold mt-2" style="color: #B45309;" x-text="metrics?.make_assignments || 0"></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Helper function to get local date (avoids UTC timezone issues)
function getLocalDate() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

document.addEventListener('alpine:init', () => {
    let dailyChart = null;
    let responseChart = null;
    
    Alpine.data('wasapiAgentDetail', (agentId) => ({
        agentId: agentId,
        agent: null,
        metrics: null,
        dailyData: [],
        loading: false,
        error: null,
        aiLoading: false,
        aiAnalysis: null,
        realtimeMode: true,
        refreshInterval: null,
        
        filters: {
            startDate: getLocalDate(),
            endDate: getLocalDate()
        },
        
        init() {
            this.loadData();
            
            // Auto-refresh every 30 seconds in realtime mode
            this.refreshInterval = setInterval(() => {
                if (!this.loading && this.realtimeMode) {
                    this.loadData();
                }
            }, 30000);
        },
        
        applyDateFilter() {
            const today = getLocalDate();
            this.realtimeMode = (this.filters.startDate === today && this.filters.endDate === today);
            this.loadData();
        },
        
        returnToRealtime() {
            const today = getLocalDate();
            this.filters.startDate = today;
            this.filters.endDate = today;
            this.realtimeMode = true;
            this.loadData();
        },
        
        async loadData() {
            this.loading = true;
            this.error = null;
            
            try {
                const url = `api/agent_detail.php?agent_id=${this.agentId}&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`;
                console.log('Fetching:', url);
                
                const res = await fetch(url);
                console.log('Response status:', res.status);
                
                const data = await res.json();
                console.log('Data:', data);
                
                if (data.success) {
                    this.agent = data.agent;
                    this.metrics = data.metrics;
                    this.dailyData = data.daily || [];
                    if (!this.agent) {
                        this.error = 'No se encontraron datos del agente';
                    } else {
                        this.$nextTick(() => this.updateCharts());
                    }
                } else {
                    this.error = data.error || 'Error desconocido';
                    console.error('API Error:', data.error);
                }
            } catch (e) {
                this.error = e.message;
                console.error('Error loading agent data:', e);
            } finally {
                this.loading = false;
            }
        },
        
        updateCharts() {
            const daily = this.dailyData;
            
            // Daily Conversations Chart
            const ctx1 = document.getElementById('dailyConversationsChart');
            if (ctx1 && daily.length > 0) {
                if (dailyChart) dailyChart.destroy();
                dailyChart = new Chart(ctx1, {
                    type: 'bar',
                    data: {
                        labels: daily.map(d => this.formatDateShort(d.date)),
                        datasets: [
                            {
                                label: 'Abiertas',
                                data: daily.map(d => d.open),
                                backgroundColor: 'rgba(59, 130, 246, 0.8)',
                                borderRadius: 6
                            },
                            {
                                label: 'Cerradas',
                                data: daily.map(d => d.closed),
                                backgroundColor: 'rgba(16, 185, 129, 0.8)',
                                borderRadius: 6
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { position: 'top' }
                        },
                        scales: {
                            y: { beginAtZero: true },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }
            
            // Response Times Chart
            const ctx2 = document.getElementById('responseTimesChart');
            if (ctx2 && daily.length > 0) {
                if (responseChart) responseChart.destroy();
                responseChart = new Chart(ctx2, {
                    type: 'line',
                    data: {
                        labels: daily.map(d => this.formatDateShort(d.date)),
                        datasets: [
                            {
                                label: '1ra Respuesta',
                                data: daily.map(d => d.avg_first_response),
                                borderColor: 'rgba(245, 158, 11, 1)',
                                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: 'Resolución',
                                data: daily.map(d => d.avg_resolution),
                                borderColor: 'rgba(139, 92, 246, 1)',
                                backgroundColor: 'rgba(139, 92, 246, 0.1)',
                                fill: true,
                                tension: 0.4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { position: 'top' }
                        },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            }
        },
        
        async analyzeWithAI() {
            this.aiLoading = true;
            
            try {
                const res = await fetch(`api/gemini_recommendations.php?action=agent_analysis&agent_id=${this.agentId}&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`);
                const data = await res.json();
                
                if (data.success) {
                    this.aiAnalysis = data;
                }
            } catch (e) {
                console.error('AI Analysis error:', e);
            } finally {
                this.aiLoading = false;
            }
        },
        
        getInitials() {
            if (!this.agent?.name) return 'AG';
            const parts = this.agent.name.split(' ');
            return (parts[0]?.charAt(0) || '') + (parts[1]?.charAt(0) || '');
        },
        
        formatNumber(num) {
            if (!num) return '0';
            return new Intl.NumberFormat('es-DO').format(num);
        },
        
        formatDateShort(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleDateString('es-DO', { month: 'short', day: 'numeric' });
        },
        
        formatSecondsShort(seconds) {
            if (!seconds || seconds == 0) return '0s';
            seconds = parseFloat(seconds);
            if (seconds < 60) return Math.round(seconds) + 's';
            if (seconds < 3600) return Math.round(seconds / 60) + 'm';
            return (seconds / 3600).toFixed(1) + 'h';
        }
    }));
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
