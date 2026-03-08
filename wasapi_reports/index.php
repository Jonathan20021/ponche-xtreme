<?php
require_once __DIR__ . '/../header.php';

// Only authenticated users
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}
?>

<div class="container mx-auto px-4 py-8" x-data="wasapiRealtimeDashboard()">
    <!-- Dashboard Header - Same style as main dashboard -->
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Centro de Control Wasapi</h1>
            <p class="text-gray-600">
                <span x-text="isConnected ? 'Conectado' : 'Sin conexión'"></span> · 
                <span x-show="realtimeMode" class="text-emerald-600">Actualización automática cada 30s</span>
                <span x-show="!realtimeMode" class="text-amber-600">Actualización pausada (datos históricos)</span>
                · Última actualización: <span x-text="lastUpdate"></span>
            </p>
        </div>
        <div class="flex flex-wrap gap-3 items-center">
            <!-- Realtime Mode Indicator -->
            <div x-show="realtimeMode" class="flex items-center gap-2 bg-emerald-100 text-emerald-700 px-3 py-2 rounded-lg text-sm font-medium">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                </span>
                Tiempo Real
            </div>
            
            <!-- Historical Mode Indicator + Return Button -->
            <div x-show="!realtimeMode" class="flex items-center gap-2">
                <span class="bg-amber-100 text-amber-700 px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-history"></i> Modo Histórico
                </span>
                <button @click="returnToRealtime()"
                    class="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-2 rounded-lg text-sm flex items-center gap-2">
                    <i class="fas fa-bolt"></i> Volver a Realtime
                </button>
            </div>
            
            <input type="date" x-model="filters.startDate" @change="applyDateFilter()"
                class="border rounded-lg px-3 py-2 text-sm">
            <input type="date" x-model="filters.endDate" @change="applyDateFilter()"
                class="border rounded-lg px-3 py-2 text-sm">
            <button @click="loadAllData" :disabled="isLoading"
                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center gap-2">
                <i class="fas fa-sync-alt" :class="{'animate-spin': isLoading}"></i> Actualizar
            </button>
            <a href="reports_dashboard.php"
                class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-lg flex items-center gap-2">
                <i class="fas fa-chart-line"></i> Análisis Avanzado
            </a>
            <button @click="showAIPanel = true"
                class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg flex items-center gap-2">
                <i class="fas fa-robot"></i> IA Gemini
            </button>
        </div>
    </div>
    
    <!-- Navigation Tabs -->
    <div class="flex gap-2 mb-6 overflow-x-auto pb-2">
        <button @click="activeTab = 'realtime'" 
            :class="activeTab === 'realtime' ? 'bg-emerald-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-bolt"></i> Tiempo Real
        </button>
        <button @click="activeTab = 'campaigns'" 
            :class="activeTab === 'campaigns' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-bullhorn"></i> Campañas
        </button>
        <button @click="activeTab = 'agents'" 
            :class="activeTab === 'agents' ? 'bg-purple-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-users"></i> Agentes
        </button>
        <button @click="activeTab = 'chats'" 
            :class="activeTab === 'chats' ? 'bg-amber-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-comments"></i> Chats
            <span x-show="chatStatus.pending_chats > 0" 
                class="bg-white/20 text-white text-xs font-bold px-1.5 py-0.5 rounded-full"
                x-text="chatStatus.pending_chats"></span>
        </button>
    </div>

    <!-- Error Alert -->
    <div x-show="error" x-transition class="mb-6 p-4 bg-red-100 border border-red-300 rounded-xl flex items-start gap-3">
        <i class="fas fa-exclamation-circle text-red-500 mt-0.5"></i>
        <div class="flex-1">
            <p class="text-red-700 font-medium">Error</p>
            <p class="text-red-600 text-sm" x-text="error"></p>
        </div>
        <button @click="error = null" class="text-red-500 hover:text-red-700"><i class="fas fa-times"></i></button>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: TIEMPO REAL -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'realtime'" x-transition>
        <!-- Real-time KPI Cards - Same style as main dashboard -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-6 mb-8">
            <!-- Online Agents -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Agentes Online</p>
                        <h3 class="text-3xl font-bold text-emerald-500" x-text="realtimeMetrics.online_agents">0</h3>
                    </div>
                    <div class="bg-emerald-100 rounded-full p-3">
                        <i class="fas fa-headset text-emerald-500"></i>
                    </div>
                </div>
                <div class="mt-2 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs text-gray-400">Tiempo real</span>
                </div>
            </div>
            
            <!-- Pending Chats -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Chats en Espera</p>
                        <h3 class="text-3xl font-bold" :class="chatStatus.pending_chats > 10 ? 'text-red-500' : chatStatus.pending_chats > 5 ? 'text-amber-500' : 'text-gray-800'" x-text="chatStatus.pending_chats">0</h3>
                    </div>
                    <div class="bg-amber-100 rounded-full p-3">
                        <i class="fas fa-clock text-amber-500"></i>
                    </div>
                </div>
            </div>
            
            <!-- Active Chats -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Chats Activos</p>
                        <h3 class="text-3xl font-bold text-blue-500" x-text="chatStatus.active_chats">0</h3>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-comments text-blue-500"></i>
                    </div>
                </div>
            </div>
            
            <!-- Unassigned -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Sin Asignar</p>
                        <h3 class="text-3xl font-bold" :class="chatStatus.unassigned_chats > 0 ? 'text-red-500' : 'text-gray-800'" x-text="chatStatus.unassigned_chats">0</h3>
                    </div>
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-user-slash text-red-500"></i>
                    </div>
                </div>
            </div>
            
            <!-- Capacity -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Capacidad</p>
                        <h3 class="text-3xl font-bold" :class="chatStatus.capacity_used_percent > 80 ? 'text-amber-500' : 'text-purple-500'" x-text="chatStatus.capacity_used_percent + '%'">0%</h3>
                    </div>
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-tachometer-alt text-purple-500"></i>
                    </div>
                </div>
            </div>
            
            <!-- Avg Load -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Carga/Agente</p>
                        <h3 class="text-3xl font-bold text-teal-500" x-text="chatStatus.avg_load_per_agent">0</h3>
                    </div>
                    <div class="bg-teal-100 rounded-full p-3">
                        <i class="fas fa-user-clock text-teal-500"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Second Row: Handling Time Metrics -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-8">
            <!-- Avg Handling Time -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Tiempo Manejo Prom.</p>
                        <h3 class="text-2xl font-bold text-indigo-500" x-text="realtimeMetrics.avg_handling_time_formatted || '00:00'">00:00</h3>
                    </div>
                    <div class="bg-indigo-100 rounded-full p-3">
                        <i class="fas fa-stopwatch text-indigo-500"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Resolución por conversación</p>
            </div>
            
            <!-- Avg First Response -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Primera Respuesta</p>
                        <h3 class="text-2xl font-bold text-cyan-500" x-text="realtimeMetrics.avg_first_response_formatted || '00:00'">00:00</h3>
                    </div>
                    <div class="bg-cyan-100 rounded-full p-3">
                        <i class="fas fa-reply text-cyan-500"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Tiempo promedio</p>
            </div>
            
            <!-- Chats per Hour -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Chats/Hora</p>
                        <h3 class="text-2xl font-bold text-pink-500" x-text="realtimeMetrics.avg_chats_per_hour || 0">0</h3>
                    </div>
                    <div class="bg-pink-100 rounded-full p-3">
                        <i class="fas fa-chart-line text-pink-500"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Promedio general</p>
            </div>
            
            <!-- Resolved Today -->
            <div class="bg-white rounded-xl shadow-lg p-5 transform hover:scale-105 transition-transform duration-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Resueltos Hoy</p>
                        <h3 class="text-2xl font-bold text-emerald-500" x-text="realtimeMetrics.resolved_today || 0">0</h3>
                    </div>
                    <div class="bg-emerald-100 rounded-full p-3">
                        <i class="fas fa-check-double text-emerald-500"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Conversaciones cerradas</p>
            </div>
        </div>
        
        <!-- Alerts Section -->
        <div x-show="chatAlerts.length > 0" class="mb-6 space-y-2">
            <template x-for="alert in chatAlerts" :key="alert.message">
                <div class="p-4 rounded-xl flex items-center gap-4 bg-white shadow-lg"
                    :class="alert.type === 'critical' ? 'border-l-4 border-red-500' : 'border-l-4 border-amber-500'">
                    <div class="h-10 w-10 rounded-full flex items-center justify-center"
                        :class="alert.type === 'critical' ? 'bg-red-100 text-red-500' : 'bg-amber-100 text-amber-500'">
                        <i :class="alert.type === 'critical' ? 'fas fa-exclamation-circle' : 'fas fa-exclamation-triangle'"></i>
                    </div>
                    <div class="flex-1">
                        <p class="font-medium" :class="alert.type === 'critical' ? 'text-red-600' : 'text-amber-600'" x-text="alert.message"></p>
                        <p class="text-sm text-gray-500" x-text="alert.action"></p>
                    </div>
                </div>
            </template>
        </div>
        
        <!-- Charts Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Online Agents List -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-headset text-emerald-500"></i>
                    Agentes Conectados
                </h3>
                <div class="space-y-2 max-h-64 overflow-y-auto">
                    <template x-for="agent in realtimeMetrics.online_agents_list" :key="agent.id">
                        <div class="flex items-center justify-between p-3 bg-gray-100 rounded-lg">
                            <div class="flex items-center gap-3">
                                <div class="h-8 w-8 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xs font-bold"
                                    x-text="(agent.name || 'A').substring(0, 2).toUpperCase()"></div>
                                <span class="text-gray-800 font-medium" x-text="agent.name"></span>
                            </div>
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        </div>
                    </template>
                    <div x-show="realtimeMetrics.online_agents_list.length === 0" class="text-center text-gray-500 py-4">
                        No hay agentes conectados
                    </div>
                </div>
            </div>
            
            <!-- Chat Status Distribution -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-blue-500"></i>
                    Distribución de Chats
                </h3>
                <div class="relative h-52">
                    <canvas id="chatDistributionChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Hourly Volume Chart -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-chart-area text-indigo-500"></i>
                Volumen de Chats por Hora
            </h3>
            <div class="h-64">
                <canvas id="hourlyVolumeChart"></canvas>
            </div>
        </div>
        
        <!-- Agent Performance Table -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-user-chart text-purple-500"></i>
                Performance por Agente (Tiempos)
            </h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-100 text-gray-600 font-medium text-xs uppercase">
                        <tr>
                            <th class="px-4 py-3">Agente</th>
                            <th class="px-4 py-3 text-center">Conv. Totales</th>
                            <th class="px-4 py-3 text-center">Cerradas</th>
                            <th class="px-4 py-3 text-center">Tiempo Resolución</th>
                            <th class="px-4 py-3 text-center">1ra Respuesta</th>
                            <th class="px-4 py-3 text-center">Conv/Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="agent in realtimeMetrics.agent_performance" :key="agent.agent_id">
                            <tr class="hover:bg-gray-50 border-b">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-8 w-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-xs font-bold"
                                            x-text="(agent.agent_name || 'A').substring(0, 2).toUpperCase()"></div>
                                        <span class="text-gray-800 font-medium" x-text="agent.agent_name"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-800" x-text="agent.total_conversations"></td>
                                <td class="px-4 py-3 text-center text-emerald-600 font-medium" x-text="agent.closed_conversations"></td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-1 rounded text-xs font-medium"
                                        :class="agent.avg_resolution_time > 600 ? 'bg-red-100 text-red-600' : agent.avg_resolution_time > 300 ? 'bg-amber-100 text-amber-600' : 'bg-emerald-100 text-emerald-600'"
                                        x-text="formatSeconds(agent.avg_resolution_time)"></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-1 rounded text-xs font-medium"
                                        :class="agent.avg_first_response > 120 ? 'bg-red-100 text-red-600' : agent.avg_first_response > 60 ? 'bg-amber-100 text-amber-600' : 'bg-emerald-100 text-emerald-600'"
                                        x-text="formatSeconds(agent.avg_first_response)"></span>
                                </td>
                                <td class="px-4 py-3 text-center text-indigo-600 font-medium" x-text="agent.conversations_per_hour"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="!realtimeMetrics.agent_performance || realtimeMetrics.agent_performance.length === 0" class="text-center text-gray-500 py-8">
                    No hay datos de performance disponibles
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: CAMPAÑAS (Canales WhatsApp) -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'campaigns'" x-transition>
        <!-- Campaign Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Canales WhatsApp</p>
                        <h3 class="text-3xl font-bold text-blue-500" x-text="campaignTotals.campaigns_count">0</h3>
                    </div>
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-bullhorn text-blue-500"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Total Conversaciones</p>
                        <h3 class="text-3xl font-bold text-emerald-500" x-text="formatNumber(campaignTotals.total_transactions)">0</h3>
                    </div>
                    <div class="bg-emerald-100 rounded-full p-3">
                        <i class="fas fa-comments text-emerald-500"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Cerradas Hoy</p>
                        <h3 class="text-3xl font-bold text-purple-500" x-text="formatNumber(campaignTotals.total_sales)">0</h3>
                    </div>
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-check-circle text-purple-500"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Respuestas</p>
                        <h3 class="text-3xl font-bold text-amber-500" x-text="formatNumber(campaignTotals.total_calls_handled)">0</h3>
                    </div>
                    <div class="bg-amber-100 rounded-full p-3">
                        <i class="fas fa-reply text-amber-500"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Campaign Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-8">
            <template x-for="campaign in campaigns" :key="campaign.id">
                <div class="bg-white rounded-xl shadow-lg p-5 relative overflow-hidden hover:shadow-xl transition-all cursor-pointer transform hover:scale-102"
                    @click="selectCampaign(campaign)">
                    <!-- Color stripe -->
                    <div class="absolute top-0 left-0 right-0 h-1" :style="'background-color: ' + (campaign.color || '#3B82F6')"></div>
                    
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h4 class="text-gray-800 font-semibold text-lg" x-text="campaign.name"></h4>
                            <p class="text-gray-500 text-sm" x-text="campaign.total_agents + ' agentes'"></p>
                        </div>
                        <div class="h-10 w-10 rounded-lg flex items-center justify-center" :style="'background-color: ' + (campaign.color || '#3B82F6') + '20'">
                            <i class="fas fa-bullhorn" :style="'color: ' + (campaign.color || '#3B82F6')"></i>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-gray-100 rounded-lg p-3">
                            <p class="text-gray-500 text-xs">Conversaciones</p>
                            <p class="text-gray-800 font-bold text-lg" x-text="formatNumber(campaign.transactions)"></p>
                        </div>
                        <div class="bg-gray-100 rounded-lg p-3">
                            <p class="text-gray-500 text-xs">Respuestas</p>
                            <p class="text-gray-800 font-bold text-lg" x-text="formatNumber(campaign.calls_handled)"></p>
                        </div>
                        <div class="bg-gray-100 rounded-lg p-3">
                            <p class="text-gray-500 text-xs">Cerradas</p>
                            <p class="text-emerald-500 font-bold text-lg" x-text="formatNumber(campaign.sales)"></p>
                        </div>
                        <div class="bg-gray-100 rounded-lg p-3">
                            <p class="text-gray-500 text-xs">Agentes</p>
                            <p class="text-gray-800 font-bold text-lg" x-text="formatNumber(campaign.total_agents)"></p>
                        </div>
                    </div>
                </div>
            </template>
        </div>
        
        <!-- Campaign Comparison Chart -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Comparativa de Canales</h3>
            <div class="h-80">
                <canvas id="campaignComparisonChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: AGENTES -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'agents'" x-transition>
        <!-- Agent Stats Summary -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">Total Agentes</p>
                <p class="text-3xl font-bold text-gray-800" x-text="agentStats.total_agents">0</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">Activos</p>
                <p class="text-3xl font-bold text-emerald-500" x-text="agentStats.active_agents">0</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">Alto Rendimiento</p>
                <p class="text-3xl font-bold text-blue-500" x-text="agentStats.high_performers">0</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">Bajo Rendimiento</p>
                <p class="text-3xl font-bold text-red-500" x-text="agentStats.low_performers">0</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">Productividad Prom.</p>
                <p class="text-3xl font-bold text-purple-500" x-text="agentStats.avg_productivity + '%'">0%</p>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="flex flex-wrap gap-3 mb-6">
            <select x-model="agentFilters.campaign" @change="loadAgents"
                class="border rounded-lg px-3 py-2 text-sm">
                <option value="">Todas las campañas</option>
                <template x-for="c in campaigns" :key="c.id">
                    <option :value="c.id" x-text="c.name"></option>
                </template>
            </select>
            <select x-model="agentFilters.performance" @change="filterAgents"
                class="border rounded-lg px-3 py-2 text-sm">
                <option value="">Todos los niveles</option>
                <option value="high">Alto rendimiento</option>
                <option value="medium">Rendimiento medio</option>
                <option value="low">Bajo rendimiento</option>
            </select>
            <input type="search" x-model="agentFilters.search" @input="filterAgents"
                placeholder="Buscar agente..."
                class="border rounded-lg px-3 py-2 text-sm w-48">
        </div>
        
        <!-- Agent Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-100 text-gray-600 font-medium text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3">Agente</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3 text-center">Conversaciones</th>
                            <th class="px-4 py-3 text-center">Cerradas</th>
                            <th class="px-4 py-3 text-center">Activas</th>
                            <th class="px-4 py-3 text-center">Productividad</th>
                            <th class="px-4 py-3 text-center">Nivel</th>
                            <th class="px-4 py-3 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="agent in filteredAgents" :key="agent.id">
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-8 w-8 rounded-full flex items-center justify-center text-xs font-bold"
                                            :class="agent.performance_level === 'high' ? 'bg-emerald-100 text-emerald-600' : agent.performance_level === 'medium' ? 'bg-amber-100 text-amber-600' : 'bg-red-100 text-red-600'"
                                            x-text="(agent.full_name || 'A').substring(0, 2).toUpperCase()"></div>
                                        <div>
                                            <p class="text-gray-800 font-medium" x-text="agent.full_name"></p>
                                            <p class="text-gray-500 text-xs" x-text="agent.employee_code"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-xs font-medium"
                                        :class="agent.is_online ? 'bg-emerald-100 text-emerald-600' : 'bg-gray-100 text-gray-500'"
                                        x-text="agent.is_online ? 'Online' : 'Offline'"></span>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-800 font-medium" x-text="formatNumber(agent.total_conversations || 0)"></td>
                                <td class="px-4 py-3 text-center text-emerald-600 font-medium" x-text="formatNumber(agent.total_calls_handled || 0)"></td>
                                <td class="px-4 py-3 text-center text-blue-500" x-text="formatNumber(agent.active_conversations || 0)"></td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <div class="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                                            <div class="h-full rounded-full"
                                                :class="agent.productivity_percent >= 70 ? 'bg-emerald-500' : agent.productivity_percent >= 50 ? 'bg-amber-500' : 'bg-red-500'"
                                                :style="'width: ' + Math.min(agent.productivity_percent, 100) + '%'"></div>
                                        </div>
                                        <span class="text-gray-600 text-xs" x-text="agent.productivity_percent + '%'"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex px-2 py-1 rounded-md text-xs font-medium"
                                        :class="agent.performance_level === 'high' ? 'bg-emerald-100 text-emerald-600' : agent.performance_level === 'medium' ? 'bg-amber-100 text-amber-600' : 'bg-red-100 text-red-600'"
                                        x-text="agent.performance_label"></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button @click="analyzeAgent(agent)" 
                                        class="p-2 text-gray-400 hover:text-purple-500 transition-colors" title="Análisis IA">
                                        <i class="fas fa-brain"></i>
                                    </button>
                                    <button @click="viewAgentDetail(agent)" 
                                        class="p-2 text-gray-400 hover:text-blue-500 transition-colors" title="Ver detalles">
                                        <i class="fas fa-chart-line"></i>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div x-show="filteredAgents.length === 0" class="p-8 text-center text-gray-500">
                No se encontraron agentes
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB: CHATS -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'chats'" x-transition>
        <!-- Chat Status Overview -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Main Status Card -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-signal text-emerald-500"></i>
                    Estado del Sistema
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-gray-100 rounded-xl p-4 text-center">
                        <div class="text-4xl font-bold text-emerald-500 mb-1" x-text="chatStatus.online_agents">0</div>
                        <div class="text-gray-500 text-sm">Agentes Online</div>
                    </div>
                    <div class="bg-gray-100 rounded-xl p-4 text-center">
                        <div class="text-4xl font-bold mb-1" :class="chatStatus.pending_chats > 10 ? 'text-red-500' : 'text-amber-500'" x-text="chatStatus.pending_chats">0</div>
                        <div class="text-gray-500 text-sm">En Espera</div>
                    </div>
                    <div class="bg-gray-100 rounded-xl p-4 text-center">
                        <div class="text-4xl font-bold text-blue-500 mb-1" x-text="chatStatus.active_chats">0</div>
                        <div class="text-gray-500 text-sm">Activos</div>
                    </div>
                    <div class="bg-gray-100 rounded-xl p-4 text-center">
                        <div class="text-4xl font-bold mb-1" :class="chatStatus.unassigned_chats > 0 ? 'text-red-500' : 'text-gray-600'" x-text="chatStatus.unassigned_chats">0</div>
                        <div class="text-gray-500 text-sm">Sin Asignar</div>
                    </div>
                </div>
                
                <!-- Capacity Bar -->
                <div class="mt-6">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-gray-500 text-sm">Capacidad del Equipo</span>
                        <span class="text-gray-800 font-medium" x-text="chatStatus.capacity_used_percent + '%'"></span>
                    </div>
                    <div class="h-4 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-500"
                            :class="chatStatus.capacity_used_percent > 80 ? 'bg-gradient-to-r from-red-500 to-orange-500' : chatStatus.capacity_used_percent > 60 ? 'bg-gradient-to-r from-amber-500 to-yellow-500' : 'bg-gradient-to-r from-emerald-500 to-teal-500'"
                            :style="'width: ' + Math.min(chatStatus.capacity_used_percent, 100) + '%'"></div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Acciones Rápidas</h3>
                <div class="space-y-3">
                    <button @click="refreshChats" class="w-full bg-emerald-100 hover:bg-emerald-200 text-emerald-600 px-4 py-3 rounded-lg text-sm font-medium transition-all flex items-center gap-3">
                        <i class="fas fa-sync-alt"></i>
                        Actualizar Estado
                    </button>
                    <button @click="showAIPanel = true" class="w-full bg-purple-100 hover:bg-purple-200 text-purple-600 px-4 py-3 rounded-lg text-sm font-medium transition-all flex items-center gap-3">
                        <i class="fas fa-robot"></i>
                        Obtener Recomendaciones IA
                    </button>
                    <a href="../hr/campaigns.php" class="w-full bg-blue-100 hover:bg-blue-200 text-blue-600 px-4 py-3 rounded-lg text-sm font-medium transition-all flex items-center gap-3">
                        <i class="fas fa-users-cog"></i>
                        Gestionar Agentes
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Agent Load Distribution -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-balance-scale text-blue-500"></i>
                Carga por Agente
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                <template x-for="agent in chatAgentLoad" :key="agent.id">
                    <div class="bg-gray-100 rounded-lg p-4 flex items-center gap-4">
                        <div class="h-10 w-10 rounded-full flex items-center justify-center text-sm font-bold"
                            :class="agent.status === 'at_capacity' ? 'bg-amber-100 text-amber-600' : 'bg-emerald-100 text-emerald-600'"
                            x-text="(agent.name || 'A').substring(0, 2).toUpperCase()"></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-gray-800 font-medium truncate" x-text="agent.name"></p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs px-1.5 py-0.5 rounded"
                                    :class="agent.status === 'at_capacity' ? 'bg-amber-200 text-amber-700' : 'bg-emerald-200 text-emerald-700'"
                                    x-text="agent.active_conversations + ' chats'"></span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <div x-show="chatAgentLoad.length === 0" class="text-center text-gray-500 py-8">
                No hay datos de carga disponibles
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- AI RECOMMENDATIONS PANEL (Slide-over) -->
    <!-- ============================================================ -->
    <div x-show="showAIPanel" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-black/60 z-50" @click="showAIPanel = false">
    </div>
    <div x-show="showAIPanel" x-transition:enter="transition ease-out duration-300 transform" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-200 transform" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
        class="fixed right-0 top-0 bottom-0 w-full max-w-2xl bg-white z-50 overflow-y-auto shadow-2xl">
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-xl bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center">
                        <i class="fas fa-robot text-white"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-800">Análisis con Gemini AI</h2>
                        <p class="text-gray-500 text-sm">Recomendaciones inteligentes para tu equipo</p>
                    </div>
                </div>
                <button @click="showAIPanel = false" class="p-2 text-gray-400 hover:text-gray-800 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <!-- Actions -->
            <div class="flex gap-3 mb-6">
                <button @click="getAIRecommendations" :disabled="aiLoading"
                    class="flex-1 bg-gradient-to-r from-purple-500 to-indigo-600 hover:from-purple-600 hover:to-indigo-700 disabled:opacity-50 text-white px-4 py-3 rounded-xl font-medium transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-magic" :class="{'animate-spin': aiLoading}"></i>
                    <span x-text="aiLoading ? 'Analizando...' : 'Generar Recomendaciones'"></span>
                </button>
                <button @click="getQuickInsights" :disabled="aiLoading"
                    class="bg-gray-200 hover:bg-gray-300 disabled:opacity-50 text-gray-800 px-4 py-3 rounded-xl font-medium transition-all flex items-center gap-2">
                    <i class="fas fa-bolt"></i>
                    Insights Rápidos
                </button>
            </div>
            
            <!-- Loading State -->
            <div x-show="aiLoading" class="flex flex-col items-center justify-center py-12">
                <div class="relative">
                    <div class="w-16 h-16 border-4 border-purple-200 rounded-full"></div>
                    <div class="absolute inset-0 w-16 h-16 border-4 border-transparent border-t-purple-500 rounded-full animate-spin"></div>
                </div>
                <p class="text-gray-500 mt-4">Gemini está analizando los datos...</p>
            </div>
            
            <!-- AI Results -->
            <div x-show="!aiLoading && aiResults" class="space-y-6">
                <!-- Executive Summary -->
                <div x-show="aiResults?.recommendations?.executive_summary" class="bg-gray-100 rounded-xl p-5">
                    <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-clipboard-list text-blue-500"></i>
                        Resumen Ejecutivo
                    </h3>
                    <p class="text-gray-600 leading-relaxed" x-text="aiResults?.recommendations?.executive_summary"></p>
                </div>
                
                <!-- Relocations -->
                <div x-show="aiResults?.recommendations?.relocations?.length > 0" class="bg-gray-100 rounded-xl p-5">
                    <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-exchange-alt text-emerald-500"></i>
                        Reubicaciones Recomendadas
                    </h3>
                    <div class="space-y-3">
                        <template x-for="reloc in (aiResults?.recommendations?.relocations || [])" :key="reloc.agent_name">
                            <div class="bg-white rounded-lg p-4 shadow">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="text-gray-800 font-medium" x-text="reloc.agent_name"></span>
                                    <i class="fas fa-arrow-right text-gray-400"></i>
                                    <span class="text-emerald-500 font-medium" x-text="reloc.suggested_campaign"></span>
                                </div>
                                <p class="text-gray-500 text-sm" x-text="reloc.reason"></p>
                            </div>
                        </template>
                    </div>
                </div>
                
                <!-- Alerts -->
                <div x-show="aiResults?.recommendations?.alerts?.length > 0" class="bg-gray-100 rounded-xl p-5">
                    <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle text-amber-500"></i>
                        Alertas
                    </h3>
                    <div class="space-y-2">
                        <template x-for="alert in (aiResults?.recommendations?.alerts || [])" :key="alert.message">
                            <div class="flex items-start gap-3 p-3 bg-amber-100 border border-amber-300 rounded-lg">
                                <i class="fas fa-exclamation-circle text-amber-500 mt-0.5"></i>
                                <div>
                                    <p class="text-amber-700 font-medium" x-text="alert.campaign"></p>
                                    <p class="text-gray-600 text-sm" x-text="alert.message"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
                
                <!-- Actions -->
                <div x-show="aiResults?.recommendations?.actions?.length > 0" class="bg-gray-100 rounded-xl p-5">
                    <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-tasks text-purple-500"></i>
                        Acciones Recomendadas
                    </h3>
                    <div class="space-y-2">
                        <template x-for="action in (aiResults?.recommendations?.actions || [])" :key="action.action">
                            <div class="flex items-start gap-3 p-3 bg-white rounded-lg shadow">
                                <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-xs font-bold" x-text="action.priority"></span>
                                <div>
                                    <p class="text-gray-800" x-text="action.action"></p>
                                    <p class="text-gray-500 text-sm" x-text="'Impacto: ' + (action.impact || 'N/A')"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
                
                <!-- Quick Insights (when using quick mode) -->
                <div x-show="aiResults?.insights?.length > 0" class="bg-gray-100 rounded-xl p-5">
                    <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-lightbulb text-yellow-500"></i>
                        Insights Rápidos
                    </h3>
                    <div class="space-y-2">
                        <template x-for="insight in (aiResults?.insights || [])" :key="insight.title">
                            <div class="flex items-start gap-3 p-3 rounded-lg"
                                :class="insight.type === 'alert' ? 'bg-red-100 border border-red-300' : 'bg-amber-100 border border-amber-300'">
                                <i :class="insight.type === 'alert' ? 'fas fa-exclamation-circle text-red-500' : 'fas fa-exclamation-triangle text-amber-500'" class="mt-0.5"></i>
                                <div>
                                    <p :class="insight.type === 'alert' ? 'text-red-700' : 'text-amber-700'" class="font-medium" x-text="insight.title"></p>
                                    <p class="text-gray-600 text-sm" x-text="insight.message"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
                
                <!-- Quick Relocations -->
                <div x-show="aiResults?.relocations?.length > 0" class="bg-gray-100 rounded-xl p-5">
                    <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-user-friends text-emerald-500"></i>
                        Sugerencias de Reubicación
                    </h3>
                    <div class="space-y-3">
                        <template x-for="reloc in (aiResults?.relocations || [])" :key="reloc.agent_id">
                            <div class="bg-white rounded-lg p-4 flex items-center justify-between shadow">
                                <div>
                                    <p class="text-gray-800 font-medium" x-text="reloc.agent_name"></p>
                                    <p class="text-gray-500 text-sm">
                                        <span x-text="reloc.current_campaign"></span> → 
                                        <span class="text-emerald-500" x-text="reloc.suggested_campaign"></span>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-gray-500 text-sm">Productividad actual</p>
                                    <p class="text-red-500 font-bold" x-text="reloc.current_productivity + '%'"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            
            <!-- Empty State -->
            <div x-show="!aiLoading && !aiResults" class="flex flex-col items-center justify-center py-12 text-center">
                <div class="h-16 w-16 rounded-full bg-purple-100 flex items-center justify-center mb-4">
                    <i class="fas fa-robot text-purple-500 text-2xl"></i>
                </div>
                <h3 class="text-gray-800 font-semibold mb-2">Listo para analizar</h3>
                <p class="text-gray-500 text-sm max-w-sm">Haz clic en "Generar Recomendaciones" para que Gemini AI analice el rendimiento de tu equipo y te brinde sugerencias personalizadas.</p>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- AGENT DETAIL MODAL -->
    <!-- ============================================================ -->
    <div x-show="showAgentModal" x-transition class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4" @click.self="showAgentModal = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-4">
                        <div class="h-12 w-12 rounded-full flex items-center justify-center text-lg font-bold"
                            :class="selectedAgent?.performance_level === 'high' ? 'bg-emerald-100 text-emerald-600' : selectedAgent?.performance_level === 'medium' ? 'bg-amber-100 text-amber-600' : 'bg-red-100 text-red-600'"
                            x-text="(selectedAgent?.full_name || 'A').substring(0, 2).toUpperCase()"></div>
                        <div>
                            <h2 class="text-xl font-bold text-gray-800" x-text="selectedAgent?.full_name"></h2>
                            <p class="text-gray-500" x-text="selectedAgent?.campaign_name"></p>
                        </div>
                    </div>
                    <button @click="showAgentModal = false" class="p-2 text-gray-400 hover:text-gray-800">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <!-- Agent AI Analysis -->
                <div x-show="agentAnalysis" class="space-y-4">
                    <div class="bg-gray-100 rounded-xl p-5">
                        <h3 class="text-gray-800 font-semibold mb-3">Diagnóstico IA</h3>
                        <p class="text-gray-600" x-text="agentAnalysis?.analysis?.diagnosis"></p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-5">
                            <h3 class="text-emerald-600 font-semibold mb-3"><i class="fas fa-check-circle mr-2"></i>Fortalezas</h3>
                            <ul class="space-y-1">
                                <template x-for="s in (agentAnalysis?.analysis?.strengths || [])">
                                    <li class="text-gray-600 text-sm flex items-start gap-2">
                                        <i class="fas fa-plus text-emerald-500 mt-1 text-xs"></i>
                                        <span x-text="s"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-5">
                            <h3 class="text-amber-600 font-semibold mb-3"><i class="fas fa-exclamation-circle mr-2"></i>Áreas de Mejora</h3>
                            <ul class="space-y-1">
                                <template x-for="i in (agentAnalysis?.analysis?.improvements || [])">
                                    <li class="text-gray-600 text-sm flex items-start gap-2">
                                        <i class="fas fa-arrow-up text-amber-500 mt-1 text-xs"></i>
                                        <span x-text="i"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>
                    
                    <div x-show="agentAnalysis?.analysis?.relocation?.recommended" class="bg-purple-50 border border-purple-200 rounded-xl p-5">
                        <h3 class="text-purple-600 font-semibold mb-2"><i class="fas fa-exchange-alt mr-2"></i>Reubicación Recomendada</h3>
                        <p class="text-gray-800">Mover a: <span class="text-purple-600 font-medium" x-text="agentAnalysis?.analysis?.relocation?.campaign"></span></p>
                        <p class="text-gray-500 text-sm mt-1" x-text="agentAnalysis?.analysis?.relocation?.reason"></p>
                    </div>
                </div>
                
                <div x-show="agentAnalysisLoading" class="flex items-center justify-center py-12">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-purple-500"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('alpine:init', () => {
    let chatDistChart = null;
    let campaignChart = null;
    let hourlyVolumeChart = null;
    
    // Helper function to get local date in YYYY-MM-DD format
    const getLocalDate = () => {
        const d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    };
    
    Alpine.data('wasapiRealtimeDashboard', () => ({
        // State
        isLoading: false,
        isConnected: true,
        error: null,
        lastUpdate: '-',
        activeTab: 'realtime',
        realtimeMode: true,
        
        // Filters
        filters: {
            startDate: getLocalDate(),
            endDate: getLocalDate()
        },
        
        // Data
        realtimeMetrics: {
            online_agents: 0,
            online_agents_list: [],
            pending_chats: 0,
            active_conversations: 0,
            avg_handling_time: 0,
            avg_handling_time_formatted: '0m',
            avg_first_response_time: 0,
            avg_first_response_formatted: '0s',
            avg_chats_per_hour: 0,
            total_resolved_today: 0,
            hourly_volume: [],
            agent_performance: []
        },
        
        chatStatus: {
            pending_chats: 0,
            unassigned_chats: 0,
            active_chats: 0,
            online_agents: 0,
            avg_load_per_agent: 0,
            capacity_used_percent: 0
        },
        chatAlerts: [],
        chatAgentLoad: [],
        
        campaigns: [],
        campaignTotals: {
            campaigns_count: 0,
            total_transactions: 0,
            total_sales: 0,
            total_calls_handled: 0,
            total_agents: 0
        },
        
        agents: [],
        filteredAgents: [],
        agentStats: {
            total_agents: 0,
            active_agents: 0,
            high_performers: 0,
            low_performers: 0,
            avg_productivity: 0
        },
        agentFilters: {
            campaign: '',
            performance: '',
            search: ''
        },
        
        // AI
        showAIPanel: false,
        aiLoading: false,
        aiResults: null,
        
        // Agent Detail
        showAgentModal: false,
        selectedAgent: null,
        agentAnalysis: null,
        agentAnalysisLoading: false,
        
        // Lifecycle
        init() {
            this.loadAllData();
            this.initCharts();
            
            // Auto-refresh every 30 seconds (only in realtime mode)
            setInterval(() => {
                if (!this.isLoading && this.realtimeMode) {
                    this.loadRealtimeData();
                }
            }, 30000);
        },
        
        applyDateFilter() {
            const today = getLocalDate();
            // If both dates are today, stay in realtime mode
            if (this.filters.startDate === today && this.filters.endDate === today) {
                this.realtimeMode = true;
            } else {
                this.realtimeMode = false;
            }
            this.loadAllData();
        },
        
        returnToRealtime() {
            const today = getLocalDate();
            this.filters.startDate = today;
            this.filters.endDate = today;
            this.realtimeMode = true;
            this.loadAllData();
        },
        
        initCharts() {
            Chart.defaults.color = '#94a3b8';
            Chart.defaults.borderColor = 'rgba(51, 65, 85, 0.5)';
            
            // Initialize after DOM ready
            this.$nextTick(() => {
                this.setupChatDistChart();
                this.setupCampaignChart();
                this.setupHourlyVolumeChart();
            });
        },
        
        formatSeconds(seconds) {
            if (!seconds || seconds == 0) return '0s';
            seconds = parseFloat(seconds);
            if (seconds < 60) return Math.round(seconds) + 's';
            if (seconds < 3600) return Math.round(seconds / 60) + 'm ' + Math.round(seconds % 60) + 's';
            const hours = Math.floor(seconds / 3600);
            const mins = Math.round((seconds % 3600) / 60);
            return hours + 'h ' + mins + 'm';
        },
        
        setupChatDistChart() {
            const ctx = document.getElementById('chatDistributionChart');
            if (!ctx) return;
            
            if (chatDistChart) chatDistChart.destroy();
            chatDistChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['En Espera', 'Activos', 'Sin Asignar'],
                    datasets: [{
                        data: [0, 0, 0],
                        backgroundColor: ['#f59e0b', '#3b82f6', '#ef4444'],
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
        
        setupCampaignChart() {
            const ctx = document.getElementById('campaignComparisonChart');
            if (!ctx) return;
            
            if (campaignChart) campaignChart.destroy();
            campaignChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: 'Conversaciones',
                            data: [],
                            backgroundColor: 'rgba(16, 185, 129, 0.8)',
                            borderRadius: 4
                        },
                        {
                            label: 'Respuestas',
                            data: [],
                            backgroundColor: 'rgba(59, 130, 246, 0.8)',
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        },
        
        setupHourlyVolumeChart() {
            const ctx = document.getElementById('hourlyVolumeChart');
            if (!ctx) return;
            
            if (hourlyVolumeChart) hourlyVolumeChart.destroy();
            hourlyVolumeChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: 'Abiertos',
                            data: [],
                            backgroundColor: 'rgba(59, 130, 246, 0.7)',
                            borderRadius: 4
                        },
                        {
                            label: 'Cerrados',
                            data: [],
                            backgroundColor: 'rgba(16, 185, 129, 0.7)',
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { position: 'top' },
                        title: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Cantidad' } },
                        x: { title: { display: true, text: 'Hora' } }
                    }
                }
            });
        },
        
        async loadAllData() {
            this.isLoading = true;
            this.error = null;
            
            try {
                await Promise.all([
                    this.loadRealtimeData(),
                    this.loadCampaigns(),
                    this.loadAgents()
                ]);
                this.lastUpdate = new Date().toLocaleTimeString();
                this.isConnected = true;
            } catch (e) {
                console.error(e);
                this.error = e.message || 'Error al cargar datos';
                this.isConnected = false;
            } finally {
                this.isLoading = false;
            }
        },
        
        async loadRealtimeData() {
            try {
                // Load realtime metrics with date filters
                const dateParams = `start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`;
                const [metricsRes, chatsRes] = await Promise.all([
                    fetch(`api/realtime_metrics.php?action=all&${dateParams}`),
                    fetch(`api/pending_chats.php?action=status&${dateParams}`)
                ]);
                
                const metricsData = await metricsRes.json();
                const chatsData = await chatsRes.json();
                
                if (metricsData.success) {
                    this.realtimeMetrics = metricsData.metrics;
                    
                    // Update hourly volume chart
                    if (hourlyVolumeChart && this.realtimeMetrics.hourly_volume && this.realtimeMetrics.hourly_volume.length > 0) {
                        const hourlyData = this.realtimeMetrics.hourly_volume;
                        hourlyVolumeChart.data.labels = hourlyData.map(h => h.hour + ':00');
                        hourlyVolumeChart.data.datasets[0].data = hourlyData.map(h => h.open);
                        hourlyVolumeChart.data.datasets[1].data = hourlyData.map(h => h.closed);
                        hourlyVolumeChart.update();
                    }
                }
                
                if (chatsData.success) {
                    this.chatStatus = chatsData.status;
                    this.chatAlerts = chatsData.alerts || [];
                    
                    // Update chart
                    if (chatDistChart) {
                        chatDistChart.data.datasets[0].data = [
                            chatsData.status.pending_chats,
                            chatsData.status.active_chats,
                            chatsData.status.unassigned_chats
                        ];
                        chatDistChart.update();
                    }
                }
                
                // Load agent load
                const loadRes = await fetch(`api/pending_chats.php?action=agent_load&${dateParams}`);
                const loadData = await loadRes.json();
                if (loadData.success) {
                    this.chatAgentLoad = loadData.agents;
                }
                
            } catch (e) {
                console.error('Realtime load error:', e);
            }
        },
        
        async loadCampaigns() {
            const res = await fetch(`api/campaign_transactions.php?action=summary&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`);
            const data = await res.json();
            
            if (data.success) {
                this.campaigns = data.campaigns;
                this.campaignTotals = data.totals;
                
                // Update chart
                if (campaignChart && this.campaigns.length > 0) {
                    campaignChart.data.labels = this.campaigns.map(c => c.name);
                    campaignChart.data.datasets[0].data = this.campaigns.map(c => c.transactions);
                    campaignChart.data.datasets[1].data = this.campaigns.map(c => c.calls_handled);
                    campaignChart.update();
                }
            }
        },
        
        async loadAgents() {
            const campaignParam = this.agentFilters.campaign ? `&campaign_id=${this.agentFilters.campaign}` : '';
            const res = await fetch(`api/agent_analysis.php?action=list&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}${campaignParam}`);
            const data = await res.json();
            
            if (data.success) {
                this.agents = data.agents;
                this.agentStats = data.stats;
                this.filterAgents();
            }
        },
        
        filterAgents() {
            let filtered = [...this.agents];
            
            if (this.agentFilters.performance) {
                filtered = filtered.filter(a => a.performance_level === this.agentFilters.performance);
            }
            
            if (this.agentFilters.search) {
                const search = this.agentFilters.search.toLowerCase();
                filtered = filtered.filter(a => 
                    a.full_name.toLowerCase().includes(search) ||
                    a.employee_code?.toLowerCase().includes(search)
                );
            }
            
            this.filteredAgents = filtered;
        },
        
        async refreshChats() {
            await this.loadRealtimeData();
        },
        
        selectCampaign(campaign) {
            // Could open a modal with campaign details
            console.log('Selected campaign:', campaign);
        },
        
        async getAIRecommendations() {
            this.aiLoading = true;
            this.aiResults = null;
            
            try {
                const res = await fetch(`api/gemini_recommendations.php?action=recommendations&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`);
                const data = await res.json();
                
                if (data.success) {
                    this.aiResults = data;
                } else {
                    this.error = data.error || 'Error al obtener recomendaciones';
                }
            } catch (e) {
                this.error = e.message;
            } finally {
                this.aiLoading = false;
            }
        },
        
        async getQuickInsights() {
            this.aiLoading = true;
            this.aiResults = null;
            
            try {
                const res = await fetch(`api/gemini_recommendations.php?action=quick_insights&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`);
                const data = await res.json();
                
                if (data.success) {
                    this.aiResults = data;
                } else {
                    this.error = data.error;
                }
            } catch (e) {
                this.error = e.message;
            } finally {
                this.aiLoading = false;
            }
        },
        
        async analyzeAgent(agent) {
            this.selectedAgent = agent;
            this.showAgentModal = true;
            this.agentAnalysisLoading = true;
            this.agentAnalysis = null;
            
            try {
                const res = await fetch(`api/gemini_recommendations.php?action=agent_analysis&agent_id=${agent.id}&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`);
                const data = await res.json();
                
                if (data.success) {
                    this.agentAnalysis = data;
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.agentAnalysisLoading = false;
            }
        },
        
        viewAgentDetail(agent) {
            window.location.href = `agent_detail.php?id=${agent.id}`;
        },
        
        // Helpers
        formatNumber(num) {
            if (num === null || num === undefined) return '0';
            return new Intl.NumberFormat('es-DO').format(num);
        },
        
        formatSeconds(seconds) {
            if (!seconds) return '0:00';
            const m = Math.floor(seconds / 60);
            const s = Math.floor(seconds % 60);
            return `${m}:${s.toString().padStart(2, '0')}`;
        }
    }));
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
