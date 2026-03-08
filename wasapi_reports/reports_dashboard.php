<?php
require_once __DIR__ . '/../header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}
?>

<div class="container mx-auto px-4 py-8" x-data="advancedReportsDashboard()">
    <!-- Dashboard Header -->
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-800 flex items-center gap-3">
                <i class="fas fa-chart-line text-indigo-500"></i>
                Centro de Análisis Avanzado
            </h1>
            <p class="text-gray-600">
                Reportes, predicciones y análisis profundo de datos Wasapi
                · Última actualización: <span x-text="lastUpdate"></span>
            </p>
        </div>
        <div class="flex flex-wrap gap-3 items-center">
            <input type="date" x-model="filters.startDate" @change="loadAllData()"
                class="border rounded-lg px-3 py-2 text-sm">
            <input type="date" x-model="filters.endDate" @change="loadAllData()"
                class="border rounded-lg px-3 py-2 text-sm">
            <button @click="loadAllData" :disabled="isLoading"
                class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-lg flex items-center gap-2">
                <i class="fas fa-sync-alt" :class="{'animate-spin': isLoading}"></i> Actualizar
            </button>
            <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex gap-2 mb-6 overflow-x-auto pb-2">
        <button @click="activeTab = 'dashboard'" 
            :class="activeTab === 'dashboard' ? 'bg-indigo-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-tachometer-alt"></i> Dashboard Ejecutivo
        </button>
        <button @click="activeTab = 'performance'" 
            :class="activeTab === 'performance' ? 'bg-purple-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-users-cog"></i> Performance Agentes
        </button>
        <button @click="activeTab = 'trends'" 
            :class="activeTab === 'trends' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-chart-area"></i> Tendencias
        </button>
        <button @click="activeTab = 'predictions'" 
            :class="activeTab === 'predictions' ? 'bg-emerald-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-crystal-ball"></i> Predicciones
        </button>
        <button @click="activeTab = 'sla'" 
            :class="activeTab === 'sla' ? 'bg-amber-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-stopwatch"></i> Análisis SLA
        </button>
        <button @click="activeTab = 'rankings'" 
            :class="activeTab === 'rankings' ? 'bg-rose-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-trophy"></i> Rankings
        </button>
        <button @click="activeTab = 'comparison'" 
            :class="activeTab === 'comparison' ? 'bg-cyan-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-balance-scale"></i> Comparativas
        </button>
        <button @click="activeTab = 'staffing'" 
            :class="activeTab === 'staffing' ? 'bg-orange-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-user-clock"></i> Staffing & Demanda
        </button>
    </div>

    <!-- Loading State -->
    <div x-show="isLoading" class="flex justify-center items-center py-20">
        <div class="animate-spin rounded-full h-16 w-16 border-b-4 border-indigo-500"></div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: DASHBOARD EJECUTIVO -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'dashboard' && !isLoading" x-transition>
        <!-- KPIs Principales -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
            <!-- Total Conversaciones -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Total Conversaciones</p>
                        <h3 class="text-2xl font-bold text-gray-800" x-text="dashboard.kpis?.total_conversations || 0"></h3>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-comments text-blue-500"></i>
                    </div>
                </div>
                <div class="mt-2 flex gap-2 text-xs">
                    <span class="text-green-600" x-text="(dashboard.kpis?.conversations_closed || 0) + ' cerradas'"></span>
                    <span class="text-amber-600" x-text="(dashboard.kpis?.conversations_open || 0) + ' abiertas'"></span>
                </div>
            </div>

            <!-- Tasa Resolución -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Tasa Resolución</p>
                        <h3 class="text-2xl font-bold" :class="(dashboard.kpis?.resolution_rate || 0) >= 80 ? 'text-green-500' : 'text-amber-500'" x-text="(dashboard.kpis?.resolution_rate || 0) + '%'"></h3>
                    </div>
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check-circle text-green-500"></i>
                    </div>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 mt-3">
                    <div class="bg-green-500 h-2 rounded-full transition-all" :style="'width:' + Math.min(dashboard.kpis?.resolution_rate || 0, 100) + '%'"></div>
                </div>
            </div>

            <!-- Tiempo Primera Respuesta -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Primera Respuesta</p>
                        <h3 class="text-2xl font-bold text-indigo-600" x-text="dashboard.kpis?.avg_first_response_formatted || '0s'"></h3>
                    </div>
                    <div class="bg-indigo-100 rounded-full p-3">
                        <i class="fas fa-reply text-indigo-500"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Promedio de respuesta</p>
            </div>

            <!-- Tiempo Resolución -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Tiempo Resolución</p>
                        <h3 class="text-2xl font-bold text-purple-600" x-text="dashboard.kpis?.avg_resolution_time_formatted || '0m'"></h3>
                    </div>
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-clock text-purple-500"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Promedio resolución</p>
            </div>

            <!-- Agentes -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Agentes</p>
                        <h3 class="text-2xl font-bold text-emerald-600">
                            <span x-text="dashboard.agents?.online || 0"></span>
                            <span class="text-gray-400 text-sm">/ <span x-text="dashboard.agents?.total || 0"></span></span>
                        </h3>
                    </div>
                    <div class="bg-emerald-100 rounded-full p-3">
                        <i class="fas fa-headset text-emerald-500"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2" x-text="(dashboard.agents?.availability_rate || 0) + '% disponibilidad'"></p>
            </div>

            <!-- Escalaciones -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs uppercase">Escalaciones</p>
                        <h3 class="text-2xl font-bold" :class="(dashboard.kpis?.escalation_rate || 0) <= 10 ? 'text-green-500' : 'text-amber-500'" x-text="(dashboard.kpis?.escalation_rate || 0) + '%'"></h3>
                    </div>
                    <div class="bg-amber-100 rounded-full p-3">
                        <i class="fas fa-level-up-alt text-amber-500"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Tasa de escalamiento</p>
            </div>
        </div>

        <!-- Gráficos Row 1 -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Distribución por Estado -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-blue-500"></i> Distribución por Estado
                </h3>
                <div class="h-64">
                    <canvas id="statusDistributionChart"></canvas>
                </div>
            </div>

            <!-- Distribución por Asignación -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-sitemap text-purple-500"></i> Tipo de Asignación
                </h3>
                <div class="h-64">
                    <canvas id="assignmentDistributionChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Métricas SLA y Promedios Diarios -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- SLA Percentiles -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-tachometer-alt text-indigo-500"></i> Percentiles SLA
                </h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-sm font-medium text-gray-600 mb-2">Primera Respuesta (segundos)</p>
                        <div class="flex gap-4">
                            <div class="flex-1 bg-green-50 rounded-lg p-3 text-center">
                                <p class="text-xs text-gray-500">P50</p>
                                <p class="text-xl font-bold text-green-600" x-text="dashboard.sla_metrics?.first_response_p50 || 0"></p>
                            </div>
                            <div class="flex-1 bg-amber-50 rounded-lg p-3 text-center">
                                <p class="text-xs text-gray-500">P90</p>
                                <p class="text-xl font-bold text-amber-600" x-text="dashboard.sla_metrics?.first_response_p90 || 0"></p>
                            </div>
                            <div class="flex-1 bg-red-50 rounded-lg p-3 text-center">
                                <p class="text-xs text-gray-500">P95</p>
                                <p class="text-xl font-bold text-red-600" x-text="dashboard.sla_metrics?.first_response_p95 || 0"></p>
                            </div>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-600 mb-2">Tiempo de Resolución (segundos)</p>
                        <div class="flex gap-4">
                            <div class="flex-1 bg-green-50 rounded-lg p-3 text-center">
                                <p class="text-xs text-gray-500">P50</p>
                                <p class="text-xl font-bold text-green-600" x-text="dashboard.sla_metrics?.resolution_p50 || 0"></p>
                            </div>
                            <div class="flex-1 bg-amber-50 rounded-lg p-3 text-center">
                                <p class="text-xs text-gray-500">P90</p>
                                <p class="text-xl font-bold text-amber-600" x-text="dashboard.sla_metrics?.resolution_p90 || 0"></p>
                            </div>
                            <div class="flex-1 bg-red-50 rounded-lg p-3 text-center">
                                <p class="text-xs text-gray-500">P95</p>
                                <p class="text-xl font-bold text-red-600" x-text="dashboard.sla_metrics?.resolution_p95 || 0"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Promedios Diarios -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-calendar-day text-emerald-500"></i> Promedios Diarios
                </h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-blue-50 rounded-lg p-4">
                        <div class="flex items-center gap-3">
                            <div class="bg-blue-100 rounded-full p-2">
                                <i class="fas fa-comments text-blue-500"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Conv/Día</p>
                                <p class="text-2xl font-bold text-blue-600" x-text="dashboard.daily_averages?.conversations_per_day || 0"></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-4">
                        <div class="flex items-center gap-3">
                            <div class="bg-green-100 rounded-full p-2">
                                <i class="fas fa-check text-green-500"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Cerradas/Día</p>
                                <p class="text-2xl font-bold text-green-600" x-text="dashboard.daily_averages?.closed_per_day || 0"></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-4">
                        <div class="flex items-center gap-3">
                            <div class="bg-purple-100 rounded-full p-2">
                                <i class="fas fa-reply text-purple-500"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Respuestas/Día</p>
                                <p class="text-2xl font-bold text-purple-600" x-text="dashboard.daily_averages?.first_responses_per_day || 0"></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-amber-50 rounded-lg p-4">
                        <div class="flex items-center gap-3">
                            <div class="bg-amber-100 rounded-full p-2">
                                <i class="fas fa-user text-amber-500"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Por Agente</p>
                                <p class="text-2xl font-bold text-amber-600" x-text="dashboard.agents?.avg_closed_per_agent || 0"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: PERFORMANCE AGENTES -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'performance' && !isLoading" x-transition>
        <!-- Resumen del Equipo -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Total Agentes</p>
                <h3 class="text-3xl font-bold text-gray-800" x-text="performance.total_agents || 0"></h3>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Productividad Promedio</p>
                <h3 class="text-3xl font-bold text-indigo-600" x-text="(performance.team_averages?.avg_productivity || 0) + '/día'"></h3>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Resolución Promedio</p>
                <h3 class="text-3xl font-bold text-purple-600" x-text="formatTime(performance.team_averages?.avg_resolution || 0)"></h3>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Tasa Resolución Equipo</p>
                <h3 class="text-3xl font-bold text-green-600" x-text="(performance.team_averages?.resolution_rate || 0) + '%'"></h3>
            </div>
        </div>

        <!-- Tabla de Agentes -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-users text-purple-500 mr-2"></i> Performance Individual
                </h3>
                <input type="text" placeholder="Buscar agente..." 
                    class="border rounded-lg px-3 py-2 text-sm w-64"
                    x-model="agentSearch"
                    @input="filterAgents()">
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Agente</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Estado</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Conversaciones</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Cerradas</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Tasa Res.</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">T. Resolución</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">T. Primera Resp.</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Productividad</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="agent in filteredPerformanceAgents" :key="agent.id">
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-bold text-sm"
                                            x-text="(agent.name || 'A').substring(0, 2).toUpperCase()"></div>
                                        <div>
                                            <p class="font-medium text-gray-800" x-text="agent.name"></p>
                                            <p class="text-xs text-gray-400" x-text="agent.email"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium"
                                        :class="agent.is_online ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'"
                                        x-text="agent.is_online ? 'Online' : 'Offline'"></span>
                                </td>
                                <td class="px-4 py-3 text-center font-medium" x-text="agent.conversations?.total || 0"></td>
                                <td class="px-4 py-3 text-center">
                                    <span class="text-green-600 font-medium" x-text="agent.conversations?.closed || 0"></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="font-medium" :class="(agent.metrics?.resolution_rate || 0) >= 80 ? 'text-green-600' : 'text-amber-600'"
                                        x-text="(agent.metrics?.resolution_rate || 0) + '%'"></span>
                                </td>
                                <td class="px-4 py-3 text-center text-purple-600 font-medium" x-text="agent.times?.avg_resolution_formatted || '-'"></td>
                                <td class="px-4 py-3 text-center text-indigo-600 font-medium" x-text="agent.times?.avg_first_response_formatted || '-'"></td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <span class="font-bold text-gray-800" x-text="(agent.metrics?.productivity_per_day || 0) + '/día'"></span>
                                        <div class="w-16 bg-gray-200 rounded-full h-2">
                                            <div class="bg-indigo-500 h-2 rounded-full" :style="'width:' + Math.min((agent.metrics?.productivity_per_day || 0) * 10, 100) + '%'"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: TENDENCIAS -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'trends' && !isLoading" x-transition>
        <!-- Resumen de Tendencia -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-5 flex items-center gap-4">
                <div class="p-3 rounded-full" :class="trends.summary?.trend_direction === 'increasing' ? 'bg-green-100' : trends.summary?.trend_direction === 'decreasing' ? 'bg-red-100' : 'bg-gray-100'">
                    <i class="fas" :class="trends.summary?.trend_direction === 'increasing' ? 'fa-arrow-up text-green-500' : trends.summary?.trend_direction === 'decreasing' ? 'fa-arrow-down text-red-500' : 'fa-minus text-gray-500'"></i>
                </div>
                <div>
                    <p class="text-gray-500 text-xs uppercase">Tendencia</p>
                    <p class="text-lg font-bold" :class="trends.summary?.trend_direction === 'increasing' ? 'text-green-600' : trends.summary?.trend_direction === 'decreasing' ? 'text-red-600' : 'text-gray-600'" x-text="trends.summary?.trend_direction === 'increasing' ? 'En Aumento' : trends.summary?.trend_direction === 'decreasing' ? 'En Descenso' : 'Estable'"></p>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Promedio Diario</p>
                <h3 class="text-2xl font-bold text-indigo-600" x-text="(trends.summary?.avg_daily_opened || 0) + ' conv.'"></h3>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Mejor Día</p>
                <h3 class="text-2xl font-bold text-green-600" x-text="trends.summary?.best_day || '-'"></h3>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Días Analizados</p>
                <h3 class="text-2xl font-bold text-gray-800" x-text="trends.summary?.total_days || 0"></h3>
            </div>
        </div>

        <!-- Gráfico de Tendencias -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-chart-line text-blue-500 mr-2"></i> Evolución Temporal
            </h3>
            <div class="h-80">
                <canvas id="trendsChart"></canvas>
            </div>
        </div>

        <!-- Tabla de Datos Diarios -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-table text-gray-500 mr-2"></i> Detalle por Día
                </h3>
            </div>
            <div class="overflow-x-auto max-h-96">
                <table class="w-full">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Fecha</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Abiertas</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Cerradas</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Tasa Res.</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">T. Resolución</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Agentes Activos</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Cambio</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="day in trends.trends" :key="day.date">
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium" x-text="day.date + ' (' + day.day_name + ')'"></td>
                                <td class="px-4 py-3 text-center" x-text="day.conversations_opened"></td>
                                <td class="px-4 py-3 text-center text-green-600 font-medium" x-text="day.conversations_closed"></td>
                                <td class="px-4 py-3 text-center" x-text="day.resolution_rate + '%'"></td>
                                <td class="px-4 py-3 text-center" x-text="formatTime(day.avg_resolution_time)"></td>
                                <td class="px-4 py-3 text-center" x-text="day.agents_active"></td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-1 rounded text-xs font-medium"
                                        :class="day.change_opened > 0 ? 'bg-green-100 text-green-700' : day.change_opened < 0 ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600'"
                                        x-text="(day.change_opened > 0 ? '+' : '') + day.change_opened + '%'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: PREDICCIONES -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'predictions' && !isLoading" x-transition>
        <!-- Patrón Semanal -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-calendar-week text-emerald-500 mr-2"></i> Patrón Semanal
            </h3>
            <div class="h-64">
                <canvas id="weeklyPatternChart"></canvas>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <template x-for="day in predictions.weekly_pattern" :key="day.day_number">
                    <div class="px-4 py-2 rounded-lg text-sm" :class="day.is_peak ? 'bg-amber-100 text-amber-800 font-bold' : 'bg-gray-100 text-gray-600'">
                        <span x-text="day.day"></span>: <span x-text="day.avg_conversations"></span> conv.
                        <span x-show="day.is_peak" class="ml-1">🔥</span>
                    </div>
                </template>
            </div>
        </div>

        <!-- Predicciones Próximos 7 Días -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-crystal-ball text-purple-500 mr-2"></i> Predicción Próximos 7 Días
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
                <template x-for="pred in predictions.predictions" :key="pred.date">
                    <div class="bg-gradient-to-br from-indigo-50 to-purple-50 rounded-xl p-4 text-center border border-indigo-100">
                        <p class="text-xs text-gray-500" x-text="pred.day_name"></p>
                        <p class="text-sm font-medium text-gray-700" x-text="pred.date"></p>
                        <div class="my-3">
                            <p class="text-2xl font-bold text-indigo-600" x-text="pred.predicted_opened"></p>
                            <p class="text-xs text-gray-400">conversaciones</p>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full" 
                            :class="pred.confidence === 'high' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'"
                            x-text="pred.confidence === 'high' ? 'Alta confianza' : 'Confianza media'"></span>
                    </div>
                </template>
            </div>
        </div>

        <!-- Recomendaciones -->
        <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
                <i class="fas fa-lightbulb"></i> Recomendaciones Basadas en Datos
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white/10 rounded-lg p-4">
                    <p class="text-sm opacity-80">Días Pico</p>
                    <p class="text-xl font-bold" x-text="(predictions.recommendation?.peak_days || []).join(', ') || 'No identificados'"></p>
                </div>
                <div class="bg-white/10 rounded-lg p-4">
                    <p class="text-sm opacity-80">Sugerencia</p>
                    <p class="text-xl font-bold" x-text="predictions.recommendation?.suggested_staffing || 'Sin datos'"></p>
                </div>
                <div class="bg-white/10 rounded-lg p-4">
                    <p class="text-sm opacity-80">Basado en</p>
                    <p class="text-xl font-bold" x-text="(predictions.recommendation?.based_on_days || 0) + ' días de datos'"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: ANÁLISIS SLA -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'sla' && !isLoading" x-transition>
        <!-- Configuración SLA -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-cog text-gray-500 mr-2"></i> Objetivos SLA Configurados
            </h3>
            <div class="grid grid-cols-2 gap-6">
                <div class="flex items-center gap-4">
                    <div class="bg-indigo-100 rounded-full p-4">
                        <i class="fas fa-reply text-indigo-500 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Primera Respuesta</p>
                        <p class="text-2xl font-bold text-indigo-600" x-text="sla.sla_targets?.first_response_formatted || '5m'"></p>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="bg-purple-100 rounded-full p-4">
                        <i class="fas fa-check-double text-purple-500 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Tiempo de Resolución</p>
                        <p class="text-2xl font-bold text-purple-600" x-text="sla.sla_targets?.resolution_formatted || '1h'"></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Métricas Globales SLA -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- Primera Respuesta -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h4 class="font-semibold text-gray-800 mb-4">Primera Respuesta</h4>
                <div class="flex items-center justify-between mb-4">
                    <div class="text-center">
                        <p class="text-4xl font-bold" :class="(sla.global_metrics?.first_response?.compliance_rate || 0) >= 80 ? 'text-green-500' : 'text-amber-500'" x-text="(sla.global_metrics?.first_response?.compliance_rate || 0) + '%'"></p>
                        <p class="text-sm text-gray-500">Cumplimiento</p>
                    </div>
                    <div class="w-32 h-32">
                        <canvas id="slaFirstResponseGauge"></canvas>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-2 text-center text-sm">
                    <div class="bg-gray-50 rounded p-2">
                        <p class="text-gray-500">Promedio</p>
                        <p class="font-bold" x-text="formatTime(sla.global_metrics?.first_response?.average || 0)"></p>
                    </div>
                    <div class="bg-gray-50 rounded p-2">
                        <p class="text-gray-500">P50</p>
                        <p class="font-bold" x-text="formatTime(sla.global_metrics?.first_response?.p50 || 0)"></p>
                    </div>
                    <div class="bg-gray-50 rounded p-2">
                        <p class="text-gray-500">P90</p>
                        <p class="font-bold" x-text="formatTime(sla.global_metrics?.first_response?.p90 || 0)"></p>
                    </div>
                    <div class="bg-gray-50 rounded p-2">
                        <p class="text-gray-500">P95</p>
                        <p class="font-bold" x-text="formatTime(sla.global_metrics?.first_response?.p95 || 0)"></p>
                    </div>
                </div>
            </div>

            <!-- Resolución -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h4 class="font-semibold text-gray-800 mb-4">Tiempo de Resolución</h4>
                <div class="flex items-center justify-between mb-4">
                    <div class="text-center">
                        <p class="text-4xl font-bold" :class="(sla.global_metrics?.resolution?.compliance_rate || 0) >= 80 ? 'text-green-500' : 'text-amber-500'" x-text="(sla.global_metrics?.resolution?.compliance_rate || 0) + '%'"></p>
                        <p class="text-sm text-gray-500">Cumplimiento</p>
                    </div>
                    <div class="w-32 h-32">
                        <canvas id="slaResolutionGauge"></canvas>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-2 text-center text-sm">
                    <div class="bg-gray-50 rounded p-2">
                        <p class="text-gray-500">Promedio</p>
                        <p class="font-bold" x-text="formatTime(sla.global_metrics?.resolution?.average || 0)"></p>
                    </div>
                    <div class="bg-gray-50 rounded p-2">
                        <p class="text-gray-500">P50</p>
                        <p class="font-bold" x-text="formatTime(sla.global_metrics?.resolution?.p50 || 0)"></p>
                    </div>
                    <div class="bg-gray-50 rounded p-2">
                        <p class="text-gray-500">P90</p>
                        <p class="font-bold" x-text="formatTime(sla.global_metrics?.resolution?.p90 || 0)"></p>
                    </div>
                    <div class="bg-gray-50 rounded p-2">
                        <p class="text-gray-500">P95</p>
                        <p class="font-bold" x-text="formatTime(sla.global_metrics?.resolution?.p95 || 0)"></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla SLA por Agente -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-user-clock text-amber-500 mr-2"></i> Cumplimiento SLA por Agente
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Agente</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Conversaciones</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Primera Resp.</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">SLA 1ra Resp.</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">T. Resolución</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">SLA Resolución</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Score</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="agent in sla.agents" :key="agent.id">
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium" x-text="agent.name"></td>
                                <td class="px-4 py-3 text-center" x-text="agent.conversations_handled"></td>
                                <td class="px-4 py-3 text-center" x-text="formatTime(agent.avg_first_response)"></td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium"
                                        :class="agent.first_response_sla_met ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                                        x-text="agent.first_response_sla_met ? '✓ Cumple' : '✗ No cumple'"></span>
                                </td>
                                <td class="px-4 py-3 text-center" x-text="formatTime(agent.avg_resolution)"></td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium"
                                        :class="agent.resolution_sla_met ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                                        x-text="agent.resolution_sla_met ? '✓ Cumple' : '✗ No cumple'"></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <div class="w-12 bg-gray-200 rounded-full h-2">
                                            <div class="h-2 rounded-full" :class="agent.sla_score >= 80 ? 'bg-green-500' : agent.sla_score >= 50 ? 'bg-amber-500' : 'bg-red-500'" :style="'width:' + agent.sla_score + '%'"></div>
                                        </div>
                                        <span class="text-sm font-bold" x-text="agent.sla_score + '%'"></span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: RANKINGS -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'rankings' && !isLoading" x-transition>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Ranking por Productividad -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 text-white p-4">
                    <h3 class="text-lg font-semibold flex items-center gap-2">
                        <i class="fas fa-trophy text-yellow-300"></i> Top 10 - Productividad
                    </h3>
                </div>
                <div class="p-4">
                    <template x-for="(agent, index) in rankings.by_productivity" :key="agent.id">
                        <div class="flex items-center gap-4 py-3 border-b last:border-0">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm"
                                :class="index === 0 ? 'bg-yellow-400 text-white' : index === 1 ? 'bg-gray-300 text-gray-700' : index === 2 ? 'bg-amber-600 text-white' : 'bg-gray-100 text-gray-600'"
                                x-text="index + 1"></div>
                            <div class="flex-1">
                                <p class="font-medium text-gray-800" x-text="agent.name"></p>
                                <p class="text-xs text-gray-400" x-text="agent.closed + ' cerradas'"></p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold text-indigo-600" x-text="agent.productivity + '/día'"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Ranking por Tasa de Resolución -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-green-500 to-emerald-600 text-white p-4">
                    <h3 class="text-lg font-semibold flex items-center gap-2">
                        <i class="fas fa-percentage"></i> Top 10 - Tasa de Resolución
                    </h3>
                </div>
                <div class="p-4">
                    <template x-for="(agent, index) in rankings.by_resolution_rate" :key="agent.id">
                        <div class="flex items-center gap-4 py-3 border-b last:border-0">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm"
                                :class="index === 0 ? 'bg-yellow-400 text-white' : index === 1 ? 'bg-gray-300 text-gray-700' : index === 2 ? 'bg-amber-600 text-white' : 'bg-gray-100 text-gray-600'"
                                x-text="index + 1"></div>
                            <div class="flex-1">
                                <p class="font-medium text-gray-800" x-text="agent.name"></p>
                                <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                                    <div class="bg-green-500 h-2 rounded-full" :style="'width:' + agent.resolution_rate + '%'"></div>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold text-green-600" x-text="agent.resolution_rate + '%'"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Ranking por Velocidad -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-blue-500 to-cyan-600 text-white p-4">
                    <h3 class="text-lg font-semibold flex items-center gap-2">
                        <i class="fas fa-bolt"></i> Top 10 - Más Rápidos
                    </h3>
                </div>
                <div class="p-4">
                    <template x-for="(agent, index) in rankings.by_speed" :key="agent.id">
                        <div class="flex items-center gap-4 py-3 border-b last:border-0">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm"
                                :class="index === 0 ? 'bg-yellow-400 text-white' : index === 1 ? 'bg-gray-300 text-gray-700' : index === 2 ? 'bg-amber-600 text-white' : 'bg-gray-100 text-gray-600'"
                                x-text="index + 1"></div>
                            <div class="flex-1">
                                <p class="font-medium text-gray-800" x-text="agent.name"></p>
                                <p class="text-xs text-gray-400" x-text="agent.closed + ' cerradas'"></p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold text-blue-600" x-text="formatTime(agent.avg_resolution_time)"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Ranking por Primera Respuesta -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-amber-500 to-orange-600 text-white p-4">
                    <h3 class="text-lg font-semibold flex items-center gap-2">
                        <i class="fas fa-reply"></i> Top 10 - Más Respuestas
                    </h3>
                </div>
                <div class="p-4">
                    <template x-for="(agent, index) in rankings.by_first_response" :key="agent.id">
                        <div class="flex items-center gap-4 py-3 border-b last:border-0">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm"
                                :class="index === 0 ? 'bg-yellow-400 text-white' : index === 1 ? 'bg-gray-300 text-gray-700' : index === 2 ? 'bg-amber-600 text-white' : 'bg-gray-100 text-gray-600'"
                                x-text="index + 1"></div>
                            <div class="flex-1">
                                <p class="font-medium text-gray-800" x-text="agent.name"></p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold text-amber-600" x-text="agent.first_responses"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: COMPARATIVAS -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'comparison' && !isLoading" x-transition>
        <!-- Períodos Comparados -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-calendar-alt text-cyan-500 mr-2"></i> Períodos Comparados
            </h3>
            <div class="grid grid-cols-2 gap-6">
                <div class="bg-indigo-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-indigo-600 font-medium">Período Actual</p>
                    <p class="text-lg font-bold text-indigo-800" x-text="(comparison.periods?.current?.start || '') + ' a ' + (comparison.periods?.current?.end || '')"></p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-gray-600 font-medium">Período Anterior</p>
                    <p class="text-lg font-bold text-gray-800" x-text="(comparison.periods?.previous?.start || '') + ' a ' + (comparison.periods?.previous?.end || '')"></p>
                </div>
            </div>
        </div>

        <!-- Métricas Comparativas -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <template x-for="metric in Object.values(comparison.comparison || {})" :key="metric.metric">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <p class="text-sm text-gray-500 mb-2" x-text="metric.metric"></p>
                    <div class="flex items-end justify-between">
                        <div>
                            <p class="text-3xl font-bold text-gray-800" x-text="typeof metric.current === 'number' && metric.current % 1 !== 0 ? metric.current.toFixed(1) : metric.current"></p>
                            <p class="text-sm text-gray-400">Actual</p>
                        </div>
                        <div class="text-right">
                            <p class="text-lg text-gray-600" x-text="typeof metric.previous === 'number' && metric.previous % 1 !== 0 ? metric.previous.toFixed(1) : metric.previous"></p>
                            <p class="text-sm text-gray-400">Anterior</p>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <i class="fas" :class="(metric.trend === 'up' && !metric.is_lower_better) || (metric.trend === 'down' && metric.is_lower_better) ? 'fa-arrow-up text-green-500' : 'fa-arrow-down text-red-500'"></i>
                            <span class="text-sm font-medium" :class="(metric.trend === 'up' && !metric.is_lower_better) || (metric.trend === 'down' && metric.is_lower_better) ? 'text-green-600' : 'text-red-600'" x-text="(metric.change_percent > 0 ? '+' : '') + metric.change_percent + '%'"></span>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full" :class="(metric.trend === 'up' && !metric.is_lower_better) || (metric.trend === 'down' && metric.is_lower_better) ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'" x-text="(metric.trend === 'up' && !metric.is_lower_better) || (metric.trend === 'down' && metric.is_lower_better) ? 'Mejoró' : 'Empeoró'"></span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Gráfico Comparativo -->
        <div class="bg-white rounded-xl shadow-lg p-6 mt-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-chart-bar text-cyan-500 mr-2"></i> Comparación Visual
            </h3>
            <div class="h-80">
                <canvas id="comparisonChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: STAFFING & DEMANDA -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'staffing' && !isLoading" x-transition>
        <!-- Header con Actualización Automática -->
        <div class="rounded-xl shadow-lg p-6 mb-8" style="background: linear-gradient(to right, #f97316, #ef4444); color: white;">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold flex items-center gap-3" style="color: white;">
                        <i class="fas fa-user-clock"></i> Centro de Análisis de Demanda
                    </h2>
                    <p class="mt-1" style="color: #fed7aa;">Optimiza tu equipo con datos reales - Actualización cada 30 segundos</p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="rounded-lg px-4 py-2 flex items-center gap-2" style="background: rgba(255,255,255,0.2);">
                        <span class="relative flex h-3 w-3">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-white"></span>
                        </span>
                        <span class="text-sm font-medium" style="color: white;">En Vivo</span>
                    </div>
                    <button @click="loadStaffingData()" class="px-4 py-2 rounded-lg flex items-center gap-2" style="background: rgba(255,255,255,0.2); color: white;">
                        <i class="fas fa-sync-alt" :class="{'animate-spin': isLoadingStaffing}"></i> Actualizar
                    </button>
                </div>
            </div>
        </div>

        <!-- KPIs Resumen Rápido -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Día Más Ocupado</p>
                <h3 class="text-xl font-bold text-red-600" x-text="staffing.peak_day?.name || '-'"></h3>
                <p class="text-xs text-gray-400" x-text="(staffing.peak_day?.avg_conversations || 0) + ' conv. promedio'"></p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Día Más Bajo</p>
                <h3 class="text-xl font-bold text-green-600" x-text="staffing.low_day?.name || '-'"></h3>
                <p class="text-xs text-gray-400" x-text="(staffing.low_day?.avg_conversations || 0) + ' conv. promedio'"></p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Variación Semanal</p>
                <h3 class="text-xl font-bold text-indigo-600" x-text="(staffing.variation_percent || 0) + '%'"></h3>
                <p class="text-xs text-gray-400">Diferencia pico vs bajo</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Agentes Sugeridos</p>
                <h3 class="text-xl font-bold text-purple-600" x-text="staffing.suggested_agents?.peak || '-'"></h3>
                <p class="text-xs text-gray-400">Para días pico</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Productividad/Agente</p>
                <h3 class="text-xl font-bold text-blue-600" x-text="(staffing.productivity_per_agent || 0) + '/día'"></h3>
                <p class="text-xs text-gray-400">Conversaciones cerradas</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-xs uppercase">Eficiencia Actual</p>
                <h3 class="text-xl font-bold" :class="(staffing.efficiency_score || 0) >= 80 ? 'text-green-600' : 'text-amber-600'" x-text="(staffing.efficiency_score || 0) + '%'"></h3>
                <p class="text-xs text-gray-400">Capacidad utilizada</p>
            </div>
        </div>

        <!-- Gráfico Principal: Demanda por Día de Semana -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-bar text-orange-500"></i> Demanda por Día de Semana
                </h3>
                <div class="h-72">
                    <canvas id="weeklyDemandChart"></canvas>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-users text-purple-500"></i> Agentes Necesarios por Día
                </h3>
                <div class="h-72">
                    <canvas id="agentsNeededChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabla Detallada por Día -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-orange-50 to-red-50 p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-calendar-week text-orange-500"></i> Análisis Detallado Lunes a Domingo
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Día</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Conv. Promedio</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Conv. Total</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Cerradas</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Tasa Res.</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Nivel Demanda</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Agentes Sugeridos</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="day in staffing.daily_analysis" :key="day.day_number">
                            <tr class="hover:bg-gray-50" :class="day.is_peak ? 'bg-red-50' : (day.is_low ? 'bg-green-50' : '')">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-gray-800" x-text="day.day_name"></span>
                                        <span x-show="day.is_peak" class="text-red-500"><i class="fas fa-fire"></i></span>
                                        <span x-show="day.is_low" class="text-green-500"><i class="fas fa-leaf"></i></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center font-medium" x-text="day.avg_conversations"></td>
                                <td class="px-4 py-3 text-center" x-text="day.total_conversations"></td>
                                <td class="px-4 py-3 text-center text-green-600 font-medium" x-text="day.total_closed"></td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium"
                                        :class="day.resolution_rate >= 80 ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'"
                                        x-text="day.resolution_rate + '%'"></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <div class="w-24 bg-gray-200 rounded-full h-3">
                                            <div class="h-3 rounded-full transition-all" 
                                                :class="day.demand_level >= 80 ? 'bg-red-500' : day.demand_level >= 50 ? 'bg-amber-500' : 'bg-green-500'"
                                                :style="'width:' + day.demand_level + '%'"></div>
                                        </div>
                                        <span class="text-xs font-medium" x-text="day.demand_level + '%'"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="text-xl font-bold" :class="day.is_peak ? 'text-red-600' : 'text-gray-700'" x-text="day.suggested_agents"></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium"
                                        :class="day.status === 'critical' ? 'bg-red-100 text-red-700' : day.status === 'high' ? 'bg-amber-100 text-amber-700' : day.status === 'normal' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'"
                                        x-text="day.status === 'critical' ? '🔥 Crítico' : day.status === 'high' ? '⚠️ Alto' : day.status === 'normal' ? '✓ Normal' : '🌿 Bajo'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recomendaciones de Staffing -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Recomendaciones Principales -->
            <div class="lg:col-span-2 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
                <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
                    <i class="fas fa-lightbulb"></i> Recomendaciones de Personal
                </h3>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="bg-white/10 rounded-lg p-4">
                        <p class="text-sm opacity-80">Personal Mínimo</p>
                        <p class="text-3xl font-bold" x-text="staffing.recommendations?.min_agents || '-'"></p>
                        <p class="text-xs opacity-60">Para días de baja demanda</p>
                    </div>
                    <div class="bg-white/10 rounded-lg p-4">
                        <p class="text-sm opacity-80">Personal Máximo</p>
                        <p class="text-3xl font-bold" x-text="staffing.recommendations?.max_agents || '-'"></p>
                        <p class="text-xs opacity-60">Para días pico</p>
                    </div>
                    <div class="bg-white/10 rounded-lg p-4">
                        <p class="text-sm opacity-80">Personal Promedio</p>
                        <p class="text-3xl font-bold" x-text="staffing.recommendations?.avg_agents || '-'"></p>
                        <p class="text-xs opacity-60">Recomendación general</p>
                    </div>
                    <div class="bg-white/10 rounded-lg p-4">
                        <p class="text-sm opacity-80">Capacidad por Agente</p>
                        <p class="text-3xl font-bold" x-text="(staffing.recommendations?.capacity_per_agent || 0) + '/día'"></p>
                        <p class="text-xs opacity-60">Conversaciones cerradas</p>
                    </div>
                </div>
                <div class="bg-white/10 rounded-lg p-4">
                    <p class="text-sm opacity-80 mb-2">Distribución Óptima Semanal</p>
                    <div class="flex items-center gap-1">
                        <template x-for="day in ['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab', 'Dom']" :key="day">
                            <div class="flex-1 text-center">
                                <p class="text-xs opacity-60" x-text="day"></p>
                                <p class="text-lg font-bold" x-text="staffing.weekly_distribution?.[day] || '-'"></p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Alertas y Acciones -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle text-amber-500"></i> Alertas
                </h3>
                <div class="space-y-3">
                    <template x-for="alert in staffing.alerts" :key="alert.id">
                        <div class="p-3 rounded-lg border-l-4"
                            :class="alert.type === 'danger' ? 'bg-red-50 border-red-500' : alert.type === 'warning' ? 'bg-amber-50 border-amber-500' : 'bg-blue-50 border-blue-500'">
                            <p class="font-medium text-sm" :class="alert.type === 'danger' ? 'text-red-700' : alert.type === 'warning' ? 'text-amber-700' : 'text-blue-700'" x-text="alert.title"></p>
                            <p class="text-xs text-gray-600" x-text="alert.message"></p>
                        </div>
                    </template>
                    <div x-show="!staffing.alerts || staffing.alerts.length === 0" class="text-center text-gray-400 py-4">
                        <i class="fas fa-check-circle text-2xl text-green-500"></i>
                        <p class="text-sm mt-2">Sin alertas pendientes</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráfico de Carga Horaria (si hay datos) -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-clock text-blue-500"></i> Comparativa de Carga por Día
            </h3>
            <div class="h-80">
                <canvas id="dailyLoadComparisonChart"></canvas>
            </div>
        </div>

        <!-- Métricas Avanzadas -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center gap-3">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check-double text-green-500"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Cobertura Actual</p>
                        <p class="text-2xl font-bold text-green-600" x-text="(staffing.coverage_rate || 0) + '%'"></p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center gap-3">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-hourglass-half text-blue-500"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">T. Espera Promedio</p>
                        <p class="text-2xl font-bold text-blue-600" x-text="staffing.avg_wait_time || '0s'"></p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center gap-3">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-tachometer-alt text-purple-500"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Carga Promedio</p>
                        <p class="text-2xl font-bold text-purple-600" x-text="(staffing.avg_load || 0) + ' conv/agente'"></p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 rounded-full p-3">
                        <i class="fas fa-chart-line text-amber-500"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Tendencia Demanda</p>
                        <p class="text-2xl font-bold" :class="staffing.demand_trend === 'increasing' ? 'text-red-600' : staffing.demand_trend === 'decreasing' ? 'text-green-600' : 'text-gray-600'" 
                            x-text="staffing.demand_trend === 'increasing' ? '↑ Subiendo' : staffing.demand_trend === 'decreasing' ? '↓ Bajando' : '→ Estable'"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function advancedReportsDashboard() {
    return {
        activeTab: 'dashboard',
        isLoading: false,
        isLoadingStaffing: false,
        lastUpdate: '-',
        staffingInterval: null,
        
        filters: {
            startDate: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
            endDate: new Date().toISOString().split('T')[0]
        },
        
        // Data containers
        dashboard: {},
        performance: { agents: [], team_averages: {} },
        trends: { trends: [], summary: {} },
        predictions: { predictions: [], weekly_pattern: [], recommendation: {} },
        sla: { agents: [], global_metrics: {}, sla_targets: {} },
        rankings: { by_productivity: [], by_resolution_rate: [], by_speed: [], by_first_response: [] },
        comparison: { comparison: {}, periods: {} },
        staffing: { daily_analysis: [], recommendations: {}, alerts: [], weekly_distribution: {} },
        
        // Filters
        agentSearch: '',
        filteredPerformanceAgents: [],
        
        // Charts
        charts: {},
        
        init() {
            this.loadAllData();
            // Auto-refresh staffing cada 30 segundos cuando la tab esté activa
            this.staffingInterval = setInterval(() => {
                if (this.activeTab === 'staffing') {
                    this.loadStaffingData();
                }
            }, 30000);
        },
        
        formatTime(seconds) {
            if (!seconds || seconds === 0) return '0s';
            if (seconds < 60) return Math.round(seconds) + 's';
            if (seconds < 3600) return Math.round(seconds / 60) + 'm';
            return (seconds / 3600).toFixed(1) + 'h';
        },
        
        async loadAllData() {
            this.isLoading = true;
            const params = `start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`;
            
            try {
                const [dashboardRes, performanceRes, trendsRes, predictionsRes, slaRes, rankingsRes, comparisonRes] = await Promise.all([
                    fetch(`api/advanced_reports.php?action=dashboard&${params}`),
                    fetch(`api/advanced_reports.php?action=agent_performance&${params}`),
                    fetch(`api/advanced_reports.php?action=trends&${params}`),
                    fetch(`api/advanced_reports.php?action=predictions&${params}`),
                    fetch(`api/advanced_reports.php?action=sla&${params}`),
                    fetch(`api/advanced_reports.php?action=rankings&${params}`),
                    fetch(`api/advanced_reports.php?action=comparison&${params}`)
                ]);
                
                const [dashboardData, performanceData, trendsData, predictionsData, slaData, rankingsData, comparisonData] = await Promise.all([
                    dashboardRes.json(),
                    performanceRes.json(),
                    trendsRes.json(),
                    predictionsRes.json(),
                    slaRes.json(),
                    rankingsRes.json(),
                    comparisonRes.json()
                ]);
                
                if (dashboardData.success) {
                    this.dashboard = dashboardData.dashboard;
                }
                if (performanceData.success) {
                    this.performance = performanceData;
                    this.filteredPerformanceAgents = performanceData.agents || [];
                }
                if (trendsData.success) {
                    this.trends = trendsData;
                }
                if (predictionsData.success) {
                    this.predictions = predictionsData;
                }
                if (slaData.success) {
                    this.sla = slaData;
                }
                if (rankingsData.success) {
                    this.rankings = rankingsData.rankings;
                }
                if (comparisonData.success) {
                    this.comparison = comparisonData;
                }
                
                this.lastUpdate = new Date().toLocaleTimeString();
                
                // Load staffing data
                await this.loadStaffingData();
                
                // Render charts after data loads
                this.$nextTick(() => {
                    this.renderCharts();
                });
                
            } catch (e) {
                console.error('Error loading data:', e);
            } finally {
                this.isLoading = false;
            }
        },
        
        filterAgents() {
            if (!this.agentSearch) {
                this.filteredPerformanceAgents = this.performance.agents || [];
                return;
            }
            const search = this.agentSearch.toLowerCase();
            this.filteredPerformanceAgents = (this.performance.agents || []).filter(a => 
                (a.name || '').toLowerCase().includes(search) || 
                (a.email || '').toLowerCase().includes(search)
            );
        },
        
        async loadStaffingData() {
            this.isLoadingStaffing = true;
            const params = `start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`;
            
            try {
                const response = await fetch(`api/advanced_reports.php?action=staffing&${params}`);
                const data = await response.json();
                
                if (data.success) {
                    this.staffing = data;
                    this.$nextTick(() => {
                        this.renderStaffingCharts();
                    });
                }
            } catch (e) {
                console.error('Error loading staffing data:', e);
            } finally {
                this.isLoadingStaffing = false;
            }
        },
        
        renderStaffingCharts() {
            this.renderWeeklyDemandChart();
            this.renderAgentsNeededChart();
            this.renderDailyLoadComparisonChart();
        },
        
        renderWeeklyDemandChart() {
            const ctx = document.getElementById('weeklyDemandChart');
            if (!ctx) return;
            
            if (this.charts.weeklyDemandChart) this.charts.weeklyDemandChart.destroy();
            
            const days = this.staffing.daily_analysis || [];
            
            this.charts.weeklyDemandChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: days.map(d => d.day_name),
                    datasets: [{
                        label: 'Conversaciones Promedio',
                        data: days.map(d => d.avg_conversations),
                        backgroundColor: days.map(d => d.is_peak ? '#EF4444' : d.is_low ? '#10B981' : '#6366F1'),
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                afterLabel: (ctx) => {
                                    const day = days[ctx.dataIndex];
                                    return `Nivel: ${day.demand_level}%\nAgentes: ${day.suggested_agents}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Conversaciones' } }
                    }
                }
            });
        },
        
        renderAgentsNeededChart() {
            const ctx = document.getElementById('agentsNeededChart');
            if (!ctx) return;
            
            if (this.charts.agentsNeededChart) this.charts.agentsNeededChart.destroy();
            
            const days = this.staffing.daily_analysis || [];
            
            this.charts.agentsNeededChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: days.map(d => d.day_name),
                    datasets: [
                        {
                            label: 'Agentes Sugeridos',
                            data: days.map(d => d.suggested_agents),
                            borderColor: '#8B5CF6',
                            backgroundColor: 'rgba(139, 92, 246, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 6,
                            pointBackgroundColor: days.map(d => d.is_peak ? '#EF4444' : '#8B5CF6')
                        },
                        {
                            label: 'Mínimo Requerido',
                            data: days.map(() => this.staffing.recommendations?.min_agents || 0),
                            borderColor: '#10B981',
                            borderDash: [5, 5],
                            fill: false,
                            pointRadius: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Agentes' } }
                    }
                }
            });
        },
        
        renderDailyLoadComparisonChart() {
            const ctx = document.getElementById('dailyLoadComparisonChart');
            if (!ctx) return;
            
            if (this.charts.dailyLoadComparisonChart) this.charts.dailyLoadComparisonChart.destroy();
            
            const days = this.staffing.daily_analysis || [];
            
            this.charts.dailyLoadComparisonChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: days.map(d => d.day_name),
                    datasets: [
                        {
                            label: 'Total Conversaciones',
                            data: days.map(d => d.total_conversations),
                            backgroundColor: '#3B82F6',
                            borderRadius: 4
                        },
                        {
                            label: 'Cerradas',
                            data: days.map(d => d.total_closed),
                            backgroundColor: '#10B981',
                            borderRadius: 4
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
        },
        
        renderCharts() {
            // Status Distribution Chart
            this.renderStatusChart();
            // Assignment Distribution Chart
            this.renderAssignmentChart();
            // Trends Chart
            this.renderTrendsChart();
            // Weekly Pattern Chart
            this.renderWeeklyPatternChart();
            // Comparison Chart
            this.renderComparisonChart();
        },
        
        renderStatusChart() {
            const ctx = document.getElementById('statusDistributionChart');
            if (!ctx) return;
            
            if (this.charts.statusChart) this.charts.statusChart.destroy();
            
            const distribution = this.dashboard.distribution?.by_status || [];
            
            this.charts.statusChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: distribution.map(d => d.status),
                    datasets: [{
                        data: distribution.map(d => d.count),
                        backgroundColor: distribution.map(d => d.color),
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        },
        
        renderAssignmentChart() {
            const ctx = document.getElementById('assignmentDistributionChart');
            if (!ctx) return;
            
            if (this.charts.assignmentChart) this.charts.assignmentChart.destroy();
            
            const distribution = this.dashboard.distribution?.by_assignment || [];
            
            this.charts.assignmentChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: distribution.map(d => d.type),
                    datasets: [{
                        label: 'Cantidad',
                        data: distribution.map(d => d.count),
                        backgroundColor: ['#6366F1', '#8B5CF6', '#F59E0B'],
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        },
        
        renderTrendsChart() {
            const ctx = document.getElementById('trendsChart');
            if (!ctx) return;
            
            if (this.charts.trendsChart) this.charts.trendsChart.destroy();
            
            const trends = this.trends.trends || [];
            
            this.charts.trendsChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: trends.map(t => t.date),
                    datasets: [
                        {
                            label: 'Abiertas',
                            data: trends.map(t => t.conversations_opened),
                            borderColor: '#3B82F6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Cerradas',
                            data: trends.map(t => t.conversations_closed),
                            borderColor: '#10B981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        legend: { position: 'top' }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        },
        
        renderWeeklyPatternChart() {
            const ctx = document.getElementById('weeklyPatternChart');
            if (!ctx) return;
            
            if (this.charts.weeklyPatternChart) this.charts.weeklyPatternChart.destroy();
            
            const pattern = this.predictions.weekly_pattern || [];
            
            this.charts.weeklyPatternChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: pattern.map(p => p.day),
                    datasets: [{
                        label: 'Promedio Conversaciones',
                        data: pattern.map(p => p.avg_conversations),
                        backgroundColor: pattern.map(p => p.is_peak ? '#F59E0B' : '#6366F1'),
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        },
        
        renderComparisonChart() {
            const ctx = document.getElementById('comparisonChart');
            if (!ctx) return;
            
            if (this.charts.comparisonChart) this.charts.comparisonChart.destroy();
            
            const comparison = this.comparison.comparison || {};
            const metrics = Object.values(comparison).filter(m => m.metric && !m.metric.includes('seg'));
            
            this.charts.comparisonChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: metrics.map(m => m.metric),
                    datasets: [
                        {
                            label: 'Período Actual',
                            data: metrics.map(m => m.current),
                            backgroundColor: '#6366F1',
                            borderRadius: 4
                        },
                        {
                            label: 'Período Anterior',
                            data: metrics.map(m => m.previous),
                            backgroundColor: '#9CA3AF',
                            borderRadius: 4
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
    };
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
