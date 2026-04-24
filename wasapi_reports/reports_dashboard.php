<?php
require_once __DIR__ . '/../header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}
?>

<style>
/*
 * Claude UI scoped overrides (see index.php for rationale).
 * Forces correct Tailwind text/bg colors for the Claude strategy tab, which
 * otherwise gets overwritten by the global `body { color: !important }` rule.
 */
.claude-ui, .claude-ui * { color: #1f2937; }
.claude-ui .text-white, .claude-ui .text-white * { color: #ffffff !important; }
.claude-ui .text-gray-300 { color: #d1d5db !important; }
.claude-ui .text-gray-400 { color: #9ca3af !important; }
.claude-ui .text-gray-500 { color: #6b7280 !important; }
.claude-ui .text-gray-600 { color: #4b5563 !important; }
.claude-ui .text-gray-700 { color: #374151 !important; }
.claude-ui .text-gray-800 { color: #1f2937 !important; }
.claude-ui .text-gray-900 { color: #111827 !important; }
.claude-ui .text-indigo-500 { color: #6366f1 !important; }
.claude-ui .text-indigo-600 { color: #4f46e5 !important; }
.claude-ui .text-indigo-700 { color: #4338ca !important; }
.claude-ui .text-indigo-800 { color: #3730a3 !important; }
.claude-ui .text-violet-500 { color: #8b5cf6 !important; }
.claude-ui .text-violet-600 { color: #7c3aed !important; }
.claude-ui .text-fuchsia-400 { color: #e879f9 !important; }
.claude-ui .text-emerald-400 { color: #34d399 !important; }
.claude-ui .text-emerald-500 { color: #10b981 !important; }
.claude-ui .text-emerald-600 { color: #059669 !important; }
.claude-ui .text-emerald-700 { color: #047857 !important; }
.claude-ui .text-emerald-800 { color: #065f46 !important; }
.claude-ui .text-teal-500 { color: #14b8a6 !important; }
.claude-ui .text-teal-600 { color: #0d9488 !important; }
.claude-ui .text-amber-400 { color: #fbbf24 !important; }
.claude-ui .text-amber-500 { color: #f59e0b !important; }
.claude-ui .text-amber-600 { color: #d97706 !important; }
.claude-ui .text-amber-700 { color: #b45309 !important; }
.claude-ui .text-amber-800 { color: #92400e !important; }
.claude-ui .text-yellow-700 { color: #a16207 !important; }
.claude-ui .text-orange-500 { color: #f97316 !important; }
.claude-ui .text-orange-600 { color: #ea580c !important; }
.claude-ui .text-rose-500 { color: #f43f5e !important; }
.claude-ui .text-rose-600 { color: #e11d48 !important; }
.claude-ui .text-rose-700 { color: #be123c !important; }
.claude-ui .text-rose-800 { color: #9f1239 !important; }
.claude-ui .text-red-500 { color: #ef4444 !important; }
.claude-ui .text-red-600 { color: #dc2626 !important; }
.claude-ui .text-red-700 { color: #b91c1c !important; }
.claude-ui .text-blue-500 { color: #3b82f6 !important; }
.claude-ui .text-blue-600 { color: #2563eb !important; }

.claude-ui .bg-gradient-to-br.text-white,
.claude-ui .bg-gradient-to-br.text-white *,
.claude-ui .bg-gradient-to-r.text-white,
.claude-ui .bg-gradient-to-r.text-white *,
.claude-ui .bg-gray-900,
.claude-ui .bg-gray-900 * {
    color: #ffffff !important;
}
.claude-ui .bg-gray-900 .text-fuchsia-400 { color: #e879f9 !important; }
.claude-ui .bg-gray-900 .text-gray-400 { color: #9ca3af !important; }

.claude-ui .font-mono, .claude-ui code { color: #4338ca !important; }

.claude-ui .bg-white { background-color: #ffffff !important; }
.claude-ui .bg-gray-50 { background-color: #f9fafb !important; }
.claude-ui .bg-gray-100 { background-color: #f3f4f6 !important; }
.claude-ui .bg-gray-200 { background-color: #e5e7eb !important; }
.claude-ui .bg-gray-900 { background-color: #111827 !important; }

/* Extended Tailwind palette — Tailwind 2.2.19 CDN doesn't ship these */
.claude-ui .bg-emerald-50 { background-color: #ecfdf5 !important; }
.claude-ui .bg-emerald-100 { background-color: #d1fae5 !important; }
.claude-ui .bg-emerald-500 { background-color: #10b981 !important; }
.claude-ui .bg-emerald-600 { background-color: #059669 !important; }
.claude-ui .bg-teal-50 { background-color: #f0fdfa !important; }
.claude-ui .bg-teal-500 { background-color: #14b8a6 !important; }
.claude-ui .bg-teal-600 { background-color: #0d9488 !important; }
.claude-ui .bg-amber-50 { background-color: #fffbeb !important; }
.claude-ui .bg-amber-100 { background-color: #fef3c7 !important; }
.claude-ui .bg-amber-200 { background-color: #fde68a !important; }
.claude-ui .bg-amber-500 { background-color: #f59e0b !important; }
.claude-ui .bg-amber-600 { background-color: #d97706 !important; }
.claude-ui .bg-orange-500 { background-color: #f97316 !important; }
.claude-ui .bg-orange-600 { background-color: #ea580c !important; }
.claude-ui .bg-rose-50 { background-color: #fff1f2 !important; }
.claude-ui .bg-rose-100 { background-color: #ffe4e6 !important; }
.claude-ui .bg-rose-500 { background-color: #f43f5e !important; }
.claude-ui .bg-rose-600 { background-color: #e11d48 !important; }
.claude-ui .bg-rose-800 { background-color: #9f1239 !important; }
.claude-ui .bg-fuchsia-100 { background-color: #fae8ff !important; }
.claude-ui .bg-fuchsia-600 { background-color: #c026d3 !important; }
.claude-ui .bg-violet-50 { background-color: #f5f3ff !important; }
.claude-ui .bg-violet-100 { background-color: #ede9fe !important; }
.claude-ui .bg-violet-200 { background-color: #ddd6fe !important; }
.claude-ui .bg-violet-500 { background-color: #8b5cf6 !important; }
.claude-ui .bg-violet-600 { background-color: #7c3aed !important; }
.claude-ui .bg-violet-700 { background-color: #6d28d9 !important; }
.claude-ui .bg-cyan-500 { background-color: #06b6d4 !important; }
.claude-ui .bg-indigo-50 { background-color: #eef2ff !important; }
.claude-ui .bg-indigo-100 { background-color: #e0e7ff !important; }
.claude-ui .bg-indigo-200 { background-color: #c7d2fe !important; }
.claude-ui .bg-indigo-500 { background-color: #6366f1 !important; }
.claude-ui .bg-indigo-600 { background-color: #4f46e5 !important; }
.claude-ui .bg-indigo-700 { background-color: #4338ca !important; }
.claude-ui .bg-blue-50 { background-color: #eff6ff !important; }
.claude-ui .bg-blue-100 { background-color: #dbeafe !important; }
.claude-ui .bg-blue-500 { background-color: #3b82f6 !important; }
.claude-ui .bg-red-50 { background-color: #fef2f2 !important; }
.claude-ui .bg-red-100 { background-color: #fee2e2 !important; }
.claude-ui .bg-red-500 { background-color: #ef4444 !important; }
.claude-ui .bg-red-600 { background-color: #dc2626 !important; }
.claude-ui .bg-red-700 { background-color: #b91c1c !important; }
.claude-ui .bg-red-800 { background-color: #991b1b !important; }
.claude-ui .bg-yellow-100 { background-color: #fef3c7 !important; }

.claude-ui .border-emerald-100 { border-color: #d1fae5 !important; }
.claude-ui .border-emerald-200 { border-color: #a7f3d0 !important; }
.claude-ui .border-emerald-400 { border-color: #34d399 !important; }
.claude-ui .border-amber-100 { border-color: #fef3c7 !important; }
.claude-ui .border-amber-200 { border-color: #fde68a !important; }
.claude-ui .border-amber-400 { border-color: #fbbf24 !important; }
.claude-ui .border-rose-200 { border-color: #fecdd3 !important; }
.claude-ui .border-rose-400 { border-color: #fb7185 !important; }
.claude-ui .border-rose-500 { border-color: #f43f5e !important; }
.claude-ui .border-indigo-100 { border-color: #e0e7ff !important; }
.claude-ui .border-indigo-200 { border-color: #c7d2fe !important; }
.claude-ui .border-violet-100 { border-color: #ede9fe !important; }
.claude-ui .border-violet-200 { border-color: #ddd6fe !important; }
.claude-ui .border-violet-400 { border-color: #a78bfa !important; }
.claude-ui .border-red-300 { border-color: #fca5a5 !important; }
.claude-ui .border-red-500 { border-color: #ef4444 !important; }

/* from-* defaults to 2 stops (no transparent via flare). */
.claude-ui [class*="from-emerald-500"] { --tw-gradient-from: #10b981 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(16, 185, 129, 0)) !important; }
.claude-ui [class*="from-teal-500"]    { --tw-gradient-from: #14b8a6 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(20, 184, 166, 0)) !important; }
.claude-ui [class*="from-amber-500"]   { --tw-gradient-from: #f59e0b !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(245, 158, 11, 0)) !important; }
.claude-ui [class*="from-rose-500"]    { --tw-gradient-from: #f43f5e !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(244, 63, 94, 0)) !important; }
.claude-ui [class*="from-violet-600"]  { --tw-gradient-from: #7c3aed !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(124, 58, 237, 0)) !important; }
.claude-ui [class*="from-fuchsia-500"] { --tw-gradient-from: #d946ef !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(217, 70, 239, 0)) !important; }
.claude-ui [class*="from-indigo-500"]  { --tw-gradient-from: #6366f1 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(99, 102, 241, 0)) !important; }
.claude-ui [class*="from-indigo-600"]  { --tw-gradient-from: #4f46e5 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(79, 70, 229, 0)) !important; }
.claude-ui [class*="from-red-600"]     { --tw-gradient-from: #dc2626 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(220, 38, 38, 0)) !important; }
.claude-ui [class*="from-gray-50"]     { --tw-gradient-from: #f9fafb !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(249, 250, 251, 0)) !important; }
.claude-ui [class*="from-amber-50"]    { --tw-gradient-from: #fffbeb !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(255, 251, 235, 0)) !important; }
.claude-ui [class*="from-emerald-50"]  { --tw-gradient-from: #ecfdf5 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(236, 253, 245, 0)) !important; }
.claude-ui [class*="from-indigo-50"]   { --tw-gradient-from: #eef2ff !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(238, 242, 255, 0)) !important; }
.claude-ui [class*="from-violet-50"]   { --tw-gradient-from: #f5f3ff !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(245, 243, 255, 0)) !important; }

/* via-* overrides stops to 3 stops. Must come AFTER from-*. */
.claude-ui [class*="via-violet-600"]   { --tw-gradient-stops: var(--tw-gradient-from), #7c3aed, var(--tw-gradient-to, rgba(124, 58, 237, 0)) !important; }

.claude-ui [class*="to-teal-600"]      { --tw-gradient-to: #0d9488 !important; }
.claude-ui [class*="to-teal-500"]      { --tw-gradient-to: #14b8a6 !important; }
.claude-ui [class*="to-orange-600"]    { --tw-gradient-to: #ea580c !important; }
.claude-ui [class*="to-orange-500"]    { --tw-gradient-to: #f97316 !important; }
.claude-ui [class*="to-red-600"]       { --tw-gradient-to: #dc2626 !important; }
.claude-ui [class*="to-red-800"]       { --tw-gradient-to: #991b1b !important; }
.claude-ui [class*="to-rose-500"]      { --tw-gradient-to: #f43f5e !important; }
.claude-ui [class*="to-rose-600"]      { --tw-gradient-to: #e11d48 !important; }
.claude-ui [class*="to-rose-800"]      { --tw-gradient-to: #9f1239 !important; }
.claude-ui [class*="to-violet-600"]    { --tw-gradient-to: #7c3aed !important; }
.claude-ui [class*="to-violet-700"]    { --tw-gradient-to: #6d28d9 !important; }
.claude-ui [class*="to-indigo-600"]    { --tw-gradient-to: #4f46e5 !important; }
.claude-ui [class*="to-indigo-700"]    { --tw-gradient-to: #4338ca !important; }
.claude-ui [class*="to-fuchsia-500"]   { --tw-gradient-to: #d946ef !important; }
.claude-ui [class*="to-fuchsia-600"]   { --tw-gradient-to: #c026d3 !important; }
.claude-ui [class*="to-white"]         { --tw-gradient-to: #ffffff !important; }
.claude-ui [class*="to-orange-50"]     { --tw-gradient-to: #fff7ed !important; }
.claude-ui [class*="to-emerald-50"]    { --tw-gradient-to: #ecfdf5 !important; }
.claude-ui [class*="to-teal-50"]       { --tw-gradient-to: #f0fdfa !important; }
.claude-ui [class*="to-violet-50"]     { --tw-gradient-to: #f5f3ff !important; }
.claude-ui [class*="to-gray-200"]      { --tw-gradient-to: #e5e7eb !important; }
.claude-ui [class*="to-gray-100"]      { --tw-gradient-to: #f3f4f6 !important; }

.claude-ui .bg-gradient-to-r  { background-image: linear-gradient(to right, var(--tw-gradient-stops)) !important; }
.claude-ui .bg-gradient-to-br { background-image: linear-gradient(to bottom right, var(--tw-gradient-stops)) !important; }
.claude-ui .bg-gradient-to-b  { background-image: linear-gradient(to bottom, var(--tw-gradient-stops)) !important; }
</style>

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
        <button @click="activeTab = 'claude'"
            :class="activeTab === 'claude' ? 'bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 text-white shadow-md' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex items-center gap-2">
            <i class="fas fa-brain"></i> Estrategia Claude IA
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

    <!-- ============================================================ -->
    <!-- TAB: ESTRATEGIA CLAUDE IA — Reporte ejecutivo con Claude -->
    <!-- ============================================================ -->
    <div x-show="activeTab === 'claude' && !isLoading" x-transition class="claude-ui" style="color:#1f2937;">
        <!-- Hero -->
        <div class="rounded-2xl p-6 mb-8 text-white shadow-lg bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600">
            <div class="flex flex-col lg:flex-row lg:items-center gap-4 justify-between">
                <div class="flex items-start gap-4">
                    <div class="h-12 w-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center">
                        <i class="fas fa-brain text-white text-lg"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold flex items-center gap-2">Estrategia con Claude AI
                            <span class="text-[10px] font-bold uppercase bg-white/25 px-2 py-0.5 rounded">Anthropic</span>
                        </h2>
                        <p class="text-white/85 text-sm mt-1">Reporte ejecutivo, diagnóstico operativo, radar de riesgos, forecast y optimización de campañas.</p>
                        <p class="text-white/60 text-xs mt-1" x-show="claude.model">Modelo: <span x-text="claude.model"></span></p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button @click="runClaudeAction('executive_report')" :disabled="claude.loading"
                        :class="claude.action === 'executive_report' ? 'bg-white text-indigo-700' : 'bg-white/15 hover:bg-white/25 text-white'"
                        class="px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2 disabled:opacity-50">
                        <i class="fas fa-chart-pie"></i> Reporte Ejecutivo
                    </button>
                    <button @click="runClaudeAction('operations_diagnosis')" :disabled="claude.loading"
                        :class="claude.action === 'operations_diagnosis' ? 'bg-white text-indigo-700' : 'bg-white/15 hover:bg-white/25 text-white'"
                        class="px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2 disabled:opacity-50">
                        <i class="fas fa-stethoscope"></i> Diagnóstico
                    </button>
                    <button @click="runClaudeAction('risk_radar')" :disabled="claude.loading"
                        :class="claude.action === 'risk_radar' ? 'bg-white text-indigo-700' : 'bg-white/15 hover:bg-white/25 text-white'"
                        class="px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2 disabled:opacity-50">
                        <i class="fas fa-shield-alt"></i> Radar Riesgos
                    </button>
                    <button @click="runClaudeAction('staffing_forecast')" :disabled="claude.loading"
                        :class="claude.action === 'staffing_forecast' ? 'bg-white text-indigo-700' : 'bg-white/15 hover:bg-white/25 text-white'"
                        class="px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2 disabled:opacity-50">
                        <i class="fas fa-calendar-week"></i> Forecast
                    </button>
                    <button @click="runClaudeAction('campaign_optimizer')" :disabled="claude.loading"
                        :class="claude.action === 'campaign_optimizer' ? 'bg-white text-indigo-700' : 'bg-white/15 hover:bg-white/25 text-white'"
                        class="px-3 py-2 rounded-lg text-sm font-medium flex items-center gap-2 disabled:opacity-50">
                        <i class="fas fa-bullseye"></i> Campañas
                    </button>
                </div>
            </div>
        </div>

        <!-- Configuration warning -->
        <div x-show="claude.needsConfig" class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl">
            <div class="flex items-start gap-3">
                <i class="fas fa-key text-amber-500 mt-1"></i>
                <div class="flex-1">
                    <p class="text-amber-800 font-semibold">Claude no está configurado</p>
                    <p class="text-amber-700 text-sm">Agrega tu clave de Anthropic en <a href="../settings.php#global-ai" class="underline">Ajustes → API de IA Global</a> para habilitar los análisis avanzados.</p>
                </div>
            </div>
        </div>

        <!-- Error -->
        <div x-show="claude.error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <p class="text-red-700 font-semibold"><i class="fas fa-triangle-exclamation mr-2"></i>No se pudo generar el análisis</p>
            <p class="text-red-600 text-sm" x-text="claude.error"></p>
        </div>

        <!-- Loading -->
        <div x-show="claude.loading" class="flex flex-col items-center justify-center py-16">
            <div class="relative">
                <div class="w-24 h-24 border-4 border-indigo-100 rounded-full"></div>
                <div class="absolute inset-0 w-24 h-24 border-4 border-transparent border-t-indigo-500 border-r-fuchsia-500 rounded-full animate-spin"></div>
                <div class="absolute inset-0 flex items-center justify-center">
                    <i class="fas fa-brain text-indigo-500 text-2xl"></i>
                </div>
            </div>
            <p class="text-gray-700 mt-5 font-medium text-lg">Claude está procesando tus datos</p>
            <p class="text-gray-500 text-sm mt-1" x-text="claude.loadingLabel"></p>
            <div class="mt-4 flex items-center gap-3 bg-indigo-50 border border-indigo-100 px-4 py-2 rounded-full">
                <i class="fas fa-hourglass-half text-indigo-500 text-xs animate-pulse"></i>
                <span class="text-indigo-700 text-sm font-mono" x-text="claude.elapsed + 's transcurridos'"></span>
                <span class="text-gray-400 text-xs">(~30-60s)</span>
            </div>
            <div class="w-full mt-8 max-w-3xl space-y-3 opacity-60">
                <div class="h-24 rounded-xl bg-gradient-to-r from-gray-100 via-gray-200 to-gray-100 animate-pulse"></div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="h-20 rounded-xl bg-gradient-to-r from-gray-100 via-gray-200 to-gray-100 animate-pulse"></div>
                    <div class="h-20 rounded-xl bg-gradient-to-r from-gray-100 via-gray-200 to-gray-100 animate-pulse"></div>
                    <div class="h-20 rounded-xl bg-gradient-to-r from-gray-100 via-gray-200 to-gray-100 animate-pulse"></div>
                </div>
            </div>
        </div>

        <!-- Empty (nothing loaded) -->
        <div x-show="!claude.loading && !claude.error && !claude.report && !claude.diagnosis && !claude.radar && !claude.forecast && !claude.optimizer && !claude.needsConfig" class="bg-white rounded-xl shadow p-12 text-center">
            <div class="h-20 w-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-100 via-violet-100 to-fuchsia-100 flex items-center justify-center mb-4">
                <i class="fas fa-brain text-indigo-500 text-3xl"></i>
            </div>
            <h3 class="text-gray-800 font-semibold text-lg mb-2">Análisis estratégico con Claude</h3>
            <p class="text-gray-500 text-sm max-w-md mx-auto">Elige uno de los tipos de análisis arriba. Claude leerá los datos del período seleccionado y generará insights listos para toma de decisiones.</p>
        </div>

        <!-- EXECUTIVE REPORT -->
        <div x-show="!claude.loading && claude.action === 'executive_report' && claude.report" class="space-y-5">
            <div class="rounded-2xl p-6 text-white shadow-lg"
                :class="{
                    'bg-gradient-to-br from-emerald-500 to-teal-600': (claude.report?.health_score || 0) >= 80,
                    'bg-gradient-to-br from-indigo-500 to-violet-600': (claude.report?.health_score || 0) >= 60 && (claude.report?.health_score || 0) < 80,
                    'bg-gradient-to-br from-amber-500 to-orange-600': (claude.report?.health_score || 0) >= 40 && (claude.report?.health_score || 0) < 60,
                    'bg-gradient-to-br from-rose-500 to-red-600': (claude.report?.health_score || 0) < 40
                }">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div class="flex-1">
                        <p class="text-xs uppercase tracking-widest opacity-90">Estado general · <span x-text="claude.report?.health_label || '—'"></span></p>
                        <h3 class="text-3xl font-bold mt-2 leading-tight" x-text="claude.report?.headline"></h3>
                        <p class="text-base mt-3 opacity-95 leading-relaxed" x-text="claude.report?.executive_summary"></p>
                    </div>
                    <div class="text-right">
                        <div class="text-6xl font-black" x-text="claude.report?.health_score || 0"></div>
                        <div class="text-xs uppercase opacity-90 tracking-widest">Health Score</div>
                    </div>
                </div>
            </div>

            <div x-show="(claude.report?.kpi_snapshot || []).length" class="bg-white rounded-xl shadow p-5">
                <h4 class="font-semibold text-gray-800 mb-3 flex items-center gap-2"><i class="fas fa-clipboard-list text-indigo-500"></i> KPIs Snapshot</h4>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <template x-for="(k, i) in (claude.report?.kpi_snapshot || [])" :key="i">
                        <div class="bg-gradient-to-br from-gray-50 to-white border border-gray-100 rounded-lg p-4">
                            <p class="text-[10px] uppercase text-gray-500 tracking-widest" x-text="k.label"></p>
                            <div class="flex items-center gap-2 mt-1">
                                <p class="text-xl font-bold text-gray-800" x-text="k.value"></p>
                                <i class="fas"
                                    :class="k.trend === 'up' ? 'fa-arrow-up text-emerald-500' : k.trend === 'down' ? 'fa-arrow-down text-rose-500' : 'fa-minus text-gray-400'"></i>
                            </div>
                            <p class="text-[11px] text-gray-500 mt-1" x-show="k.target">Objetivo: <span class="font-semibold" x-text="k.target"></span></p>
                        </div>
                    </template>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="bg-white rounded-xl shadow p-5">
                    <h4 class="font-semibold text-gray-800 mb-3 flex items-center gap-2"><i class="fas fa-lightbulb text-amber-500"></i> Hallazgos clave</h4>
                    <div class="space-y-2">
                        <template x-for="(f, i) in (claude.report?.key_findings || [])" :key="i">
                            <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                                <div class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center"
                                    :class="f.icon === 'trend-up' ? 'bg-emerald-100 text-emerald-600' : f.icon === 'trend-down' ? 'bg-rose-100 text-rose-600' : f.icon === 'alert' ? 'bg-amber-100 text-amber-600' : 'bg-indigo-100 text-indigo-600'">
                                    <i class="fas"
                                        :class="f.icon === 'trend-up' ? 'fa-arrow-trend-up' : f.icon === 'trend-down' ? 'fa-arrow-trend-down' : f.icon === 'alert' ? 'fa-triangle-exclamation' : 'fa-circle-check'"></i>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-800" x-text="f.title"></p>
                                    <p class="text-gray-600 text-sm" x-text="f.detail"></p>
                                    <p class="text-indigo-600 text-xs mt-1 font-mono" x-show="f.metric" x-text="f.metric"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="space-y-5">
                    <div class="bg-white rounded-xl shadow p-5 border-l-4 border-emerald-400">
                        <h4 class="text-emerald-700 font-semibold mb-2 flex items-center gap-2"><i class="fas fa-check-double"></i> Fortalezas</h4>
                        <ul class="space-y-1 text-sm text-gray-700">
                            <template x-for="(s, i) in (claude.report?.strengths || [])" :key="i">
                                <li class="flex items-start gap-2"><i class="fas fa-circle text-emerald-400 text-[6px] mt-1.5"></i><span x-text="s"></span></li>
                            </template>
                        </ul>
                    </div>
                    <div class="bg-white rounded-xl shadow p-5 border-l-4 border-rose-400">
                        <h4 class="text-rose-700 font-semibold mb-2 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Riesgos</h4>
                        <div class="space-y-2 text-sm">
                            <template x-for="(r, i) in (claude.report?.risks || [])" :key="i">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded"
                                            :class="{'bg-rose-100 text-rose-700': r.severity === 'critical','bg-amber-100 text-amber-700': r.severity === 'high','bg-yellow-100 text-yellow-700': r.severity === 'medium','bg-gray-100 text-gray-700': r.severity === 'low'}"
                                            x-text="r.severity"></span>
                                        <p class="font-semibold text-gray-800" x-text="r.title"></p>
                                    </div>
                                    <p class="text-gray-600 ml-1" x-text="r.detail"></p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="(claude.report?.opportunities || []).length" class="bg-gradient-to-br from-indigo-50 to-violet-50 border border-indigo-100 rounded-xl p-5">
                <h4 class="font-semibold text-indigo-800 mb-3 flex items-center gap-2"><i class="fas fa-rocket"></i> Oportunidades</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <template x-for="(o, i) in (claude.report?.opportunities || [])" :key="i">
                        <div class="bg-white rounded-lg p-3 shadow-sm">
                            <p class="font-semibold text-gray-800 text-sm" x-text="o.title"></p>
                            <p class="text-gray-600 text-sm mt-1" x-text="o.detail"></p>
                            <p class="text-indigo-600 text-xs mt-2" x-show="o.estimated_impact"><i class="fas fa-chart-line mr-1"></i><span x-text="o.estimated_impact"></span></p>
                        </div>
                    </template>
                </div>
            </div>

            <div x-show="(claude.report?.action_plan || []).length" class="bg-white rounded-xl shadow p-5">
                <h4 class="font-semibold text-gray-800 mb-3 flex items-center gap-2"><i class="fas fa-list-check text-violet-500"></i> Plan de acción priorizado</h4>
                <div class="space-y-2">
                    <template x-for="(a, i) in (claude.report?.action_plan || [])" :key="i">
                        <div class="flex items-start gap-3 p-3 bg-gradient-to-r from-violet-50 to-white border border-violet-100 rounded-lg">
                            <span class="flex-shrink-0 w-7 h-7 rounded-full bg-violet-600 text-white flex items-center justify-center text-xs font-bold" x-text="a.priority || i + 1"></span>
                            <div class="flex-1">
                                <p class="text-gray-800 font-medium" x-text="a.action"></p>
                                <div class="flex flex-wrap gap-2 mt-2 text-xs">
                                    <span class="bg-white border border-gray-200 text-gray-700 px-2 py-0.5 rounded" x-show="a.owner"><i class="fas fa-user-tag mr-1"></i><span x-text="a.owner"></span></span>
                                    <span class="bg-white border border-gray-200 text-gray-700 px-2 py-0.5 rounded" x-show="a.timeframe"><i class="far fa-clock mr-1"></i><span x-text="a.timeframe"></span></span>
                                    <span class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-2 py-0.5 rounded" x-show="a.expected_outcome"><i class="fas fa-arrow-up mr-1"></i><span x-text="a.expected_outcome"></span></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div x-show="claude.report?.board_message" class="bg-gray-900 text-white rounded-xl p-5 shadow-lg">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-quote-left text-fuchsia-400"></i>
                    <p class="text-xs uppercase tracking-widest text-gray-400">Mensaje para dirección</p>
                </div>
                <p class="text-lg leading-relaxed" x-text="claude.report?.board_message"></p>
            </div>
        </div>

        <!-- OPERATIONS DIAGNOSIS -->
        <div x-show="!claude.loading && claude.action === 'operations_diagnosis' && claude.diagnosis" class="space-y-5">
            <div class="bg-gradient-to-br from-violet-600 to-indigo-700 text-white rounded-xl p-6 shadow-lg">
                <p class="text-xs uppercase tracking-widest opacity-90">Diagnóstico operativo</p>
                <p class="text-base mt-2 leading-relaxed" x-text="claude.diagnosis?.diagnosis_overview"></p>
            </div>
            <div x-show="(claude.diagnosis?.bottlenecks || []).length" class="bg-white rounded-xl shadow p-5">
                <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-filter text-violet-500 mr-2"></i>Cuellos de botella</h4>
                <div class="space-y-3">
                    <template x-for="(b, i) in (claude.diagnosis?.bottlenecks || [])" :key="i">
                        <div class="border-l-4 border-violet-400 pl-4 py-2">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] uppercase bg-violet-100 text-violet-700 px-2 py-0.5 rounded font-bold" x-text="b.area"></span>
                                <p class="font-semibold text-gray-800" x-text="b.title"></p>
                            </div>
                            <p class="text-gray-600 text-sm mt-1" x-text="b.detail"></p>
                            <p class="text-violet-600 text-xs mt-1 font-mono" x-show="b.evidence" x-text="b.evidence"></p>
                        </div>
                    </template>
                </div>
            </div>
            <div x-show="claude.diagnosis?.plan_30_60_90" class="bg-white rounded-xl shadow p-5">
                <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-route text-emerald-500 mr-2"></i>Plan 30/60/90</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                    <template x-for="bucket in ['next_24h','next_30d','next_60d','next_90d']" :key="bucket">
                        <div class="bg-gray-50 border border-gray-100 rounded-lg p-3">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2" x-text="bucket.replace('next_','').replace('_',' ')"></p>
                            <ul class="space-y-2">
                                <template x-for="(item, i) in (claude.diagnosis?.plan_30_60_90?.[bucket] || [])" :key="i">
                                    <li class="text-sm">
                                        <span class="text-gray-800" x-text="item.action"></span>
                                        <span class="block text-xs text-gray-500 mt-0.5">
                                            <span x-show="item.owner" class="mr-2"><i class="fas fa-user-tag"></i> <span x-text="item.owner"></span></span>
                                            <span x-show="item.kpi_target" class="text-emerald-600"><i class="fas fa-bullseye"></i> <span x-text="item.kpi_target"></span></span>
                                        </span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>
                </div>
            </div>
            <div x-show="(claude.diagnosis?.quick_wins || []).length" class="bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-100 rounded-xl p-5">
                <h4 class="font-semibold text-emerald-800 mb-3"><i class="fas fa-bolt mr-2"></i>Quick wins</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <template x-for="(q, i) in (claude.diagnosis?.quick_wins || [])" :key="i">
                        <div class="bg-white rounded-lg p-3 shadow-sm">
                            <p class="font-semibold text-gray-800 text-sm" x-text="q.title"></p>
                            <p class="text-xs text-gray-500 mt-1">Impacto: <span class="text-emerald-600 font-medium" x-text="q.expected_impact"></span> · Esfuerzo: <span x-text="q.effort"></span></p>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- RISK RADAR -->
        <div x-show="!claude.loading && claude.action === 'risk_radar' && claude.radar" class="space-y-5">
            <div class="rounded-xl p-6 text-white shadow-lg"
                :class="{
                    'bg-gradient-to-br from-emerald-500 to-teal-600': claude.radar?.overall_risk_level === 'low',
                    'bg-gradient-to-br from-amber-500 to-orange-600': claude.radar?.overall_risk_level === 'medium',
                    'bg-gradient-to-br from-rose-500 to-red-600': claude.radar?.overall_risk_level === 'high',
                    'bg-gradient-to-br from-red-600 to-rose-800': claude.radar?.overall_risk_level === 'critical'
                }">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-widest opacity-90">Radar de riesgos</p>
                        <h3 class="text-2xl font-bold mt-1" x-text="claude.radar?.summary"></h3>
                        <p class="text-sm opacity-90 mt-2">Nivel: <span class="font-bold uppercase" x-text="claude.radar?.overall_risk_level"></span></p>
                    </div>
                    <div class="text-right"><div class="text-5xl font-black" x-text="claude.radar?.overall_score || 0"></div><div class="text-xs uppercase opacity-90">Score</div></div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-satellite-dish text-rose-500 mr-2"></i>Riesgos detectados</h4>
                <div class="space-y-3">
                    <template x-for="(r, i) in (claude.radar?.risks || [])" :key="i">
                        <div class="border-l-4 rounded-r-lg p-3 bg-gray-50"
                            :class="{'border-red-500': r.severity === 'critical','border-rose-400': r.severity === 'high','border-amber-400': r.severity === 'medium','border-emerald-400': r.severity === 'low'}">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] font-bold uppercase bg-gray-100 text-gray-700 px-2 py-0.5 rounded" x-text="r.category"></span>
                                <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded"
                                    :class="{'bg-red-100 text-red-700': r.severity === 'critical','bg-rose-100 text-rose-700': r.severity === 'high','bg-amber-100 text-amber-700': r.severity === 'medium','bg-emerald-100 text-emerald-700': r.severity === 'low'}"
                                    x-text="r.severity"></span>
                                <span class="text-[10px] text-gray-500">prob: <span x-text="r.likelihood"></span></span>
                                <span class="text-[10px] text-gray-500">impacto: <span x-text="r.impact"></span></span>
                                <span class="text-[10px] ml-auto bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded" x-show="r.eta" x-text="'ETA ' + r.eta"></span>
                            </div>
                            <p class="font-semibold text-gray-800 mt-1" x-text="r.title"></p>
                            <p class="text-gray-600 text-sm" x-text="r.evidence"></p>
                            <p class="text-indigo-600 text-sm mt-1"><i class="fas fa-arrow-right mr-1 text-xs"></i><span x-text="r.recommendation"></span></p>
                        </div>
                    </template>
                </div>
            </div>
            <div x-show="(claude.radar?.early_warnings || []).length" class="bg-amber-50 border border-amber-200 rounded-xl p-5">
                <h4 class="font-semibold text-amber-800 mb-2"><i class="fas fa-bell mr-2"></i>Señales tempranas</h4>
                <ul class="space-y-1 text-sm text-amber-900">
                    <template x-for="(w, i) in (claude.radar?.early_warnings || [])" :key="i">
                        <li class="flex items-start gap-2"><i class="fas fa-dot-circle text-amber-500 mt-1 text-[8px]"></i><span x-text="w"></span></li>
                    </template>
                </ul>
            </div>
        </div>

        <!-- STAFFING FORECAST -->
        <div x-show="!claude.loading && claude.action === 'staffing_forecast' && claude.forecast" class="space-y-5">
            <div class="bg-gradient-to-br from-emerald-500 to-teal-600 text-white rounded-xl p-6 shadow-lg">
                <p class="text-xs uppercase tracking-widest opacity-90">Forecast de staffing</p>
                <p class="text-base mt-2 opacity-95" x-text="claude.forecast?.cost_vs_service_tradeoff"></p>
            </div>
            <div x-show="(claude.forecast?.forecast_next_7_days || []).length" class="bg-white rounded-xl shadow p-5">
                <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-chart-line text-emerald-500 mr-2"></i>Próximos 7 días</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
                    <template x-for="(d, i) in (claude.forecast?.forecast_next_7_days || [])" :key="i">
                        <div class="bg-gradient-to-br from-emerald-50 to-white border border-emerald-100 rounded-lg p-3 text-center">
                            <p class="text-[10px] uppercase text-gray-500" x-text="d.weekday"></p>
                            <p class="text-[11px] text-gray-400" x-text="d.date"></p>
                            <p class="text-xl font-bold text-emerald-600 mt-1" x-text="d.expected_conversations"></p>
                            <p class="text-[10px] text-gray-500">conv.</p>
                            <div class="mt-1 text-xs font-semibold text-indigo-600"><i class="fas fa-headset"></i> <span x-text="d.suggested_agents"></span></div>
                            <span class="text-[9px] uppercase mt-1 inline-block px-1.5 py-0.5 rounded"
                                :class="{'bg-emerald-100 text-emerald-700': d.confidence === 'alta','bg-amber-100 text-amber-700': d.confidence === 'media','bg-gray-100 text-gray-600': d.confidence === 'baja'}"
                                x-text="d.confidence"></span>
                        </div>
                    </template>
                </div>
            </div>
            <div x-show="(claude.forecast?.weekly_plan || []).length" class="bg-white rounded-xl shadow p-5">
                <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-calendar-check text-indigo-500 mr-2"></i>Plan semanal</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-xs uppercase text-gray-500">
                                <th class="text-left px-3 py-2">Día</th>
                                <th class="text-left px-3 py-2">Turno</th>
                                <th class="text-center px-3 py-2">Agentes</th>
                                <th class="text-left px-3 py-2">Notas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="(row, i) in (claude.forecast?.weekly_plan || [])" :key="i">
                                <tr><td class="px-3 py-2 font-medium text-gray-800" x-text="row.day"></td><td class="px-3 py-2 text-gray-600" x-text="row.shift"></td><td class="px-3 py-2 text-center font-bold text-indigo-600" x-text="row.agents"></td><td class="px-3 py-2 text-gray-600" x-text="row.notes"></td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <div x-show="claude.forecast?.hiring_recommendation" class="rounded-xl p-5 shadow"
                :class="claude.forecast?.hiring_recommendation?.needed ? 'bg-gradient-to-br from-amber-50 to-orange-50 border border-amber-200' : 'bg-gradient-to-br from-gray-50 to-white border border-gray-200'">
                <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-user-plus text-amber-500 mr-2"></i>Recomendación de contratación</h4>
                <div class="flex items-start gap-4">
                    <div class="text-center">
                        <div class="text-4xl font-black" :class="claude.forecast?.hiring_recommendation?.needed ? 'text-amber-600' : 'text-gray-500'" x-text="claude.forecast?.hiring_recommendation?.count || 0"></div>
                        <div class="text-[10px] uppercase text-gray-500">contrataciones</div>
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-gray-800" x-text="claude.forecast?.hiring_recommendation?.profile"></p>
                        <p class="text-gray-600 text-sm mt-1" x-text="claude.forecast?.hiring_recommendation?.reasoning"></p>
                        <span class="mt-2 inline-block text-[10px] uppercase px-2 py-0.5 rounded font-bold"
                            :class="{'bg-rose-100 text-rose-700': claude.forecast?.hiring_recommendation?.priority === 'alta','bg-amber-100 text-amber-700': claude.forecast?.hiring_recommendation?.priority === 'media','bg-gray-100 text-gray-700': claude.forecast?.hiring_recommendation?.priority === 'baja'}"
                            x-text="'Prioridad ' + (claude.forecast?.hiring_recommendation?.priority || '')"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- CAMPAIGN OPTIMIZER -->
        <div x-show="!claude.loading && claude.action === 'campaign_optimizer' && claude.optimizer" class="space-y-5">
            <div class="bg-gradient-to-br from-amber-500 to-orange-600 text-white rounded-xl p-6 shadow-lg">
                <p class="text-xs uppercase tracking-widest opacity-90">Optimizador de campañas</p>
                <p class="text-base mt-2 opacity-95 leading-relaxed" x-text="claude.optimizer?.overview"></p>
            </div>
            <div x-show="(claude.optimizer?.campaign_insights || []).length" class="bg-white rounded-xl shadow p-5">
                <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-bullhorn text-amber-500 mr-2"></i>Campañas</h4>
                <div class="space-y-3">
                    <template x-for="(c, i) in (claude.optimizer?.campaign_insights || [])" :key="i">
                        <div class="border border-gray-100 rounded-lg p-3 bg-gray-50">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-semibold text-gray-800" x-text="c.campaign"></p>
                                <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded"
                                    :class="{'bg-emerald-100 text-emerald-700': c.status === 'saludable','bg-amber-100 text-amber-700': c.status === 'en_riesgo','bg-rose-100 text-rose-700': c.status === 'crítica'}"
                                    x-text="c.status"></span>
                            </div>
                            <p class="text-gray-600 text-sm mt-1" x-text="c.insight"></p>
                            <p class="text-amber-600 text-sm mt-1"><i class="fas fa-arrow-right mr-1 text-xs"></i><span x-text="c.recommendation"></span></p>
                        </div>
                    </template>
                </div>
            </div>
            <div x-show="(claude.optimizer?.reassignments || []).length" class="bg-white rounded-xl shadow p-5">
                <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-shuffle text-indigo-500 mr-2"></i>Reasignaciones sugeridas</h4>
                <div class="space-y-2">
                    <template x-for="(r, i) in (claude.optimizer?.reassignments || [])" :key="i">
                        <div class="bg-gradient-to-r from-indigo-50 to-white rounded-lg p-3 flex items-center gap-3">
                            <i class="fas fa-user-tag text-indigo-500"></i>
                            <div class="flex-1">
                                <p class="font-semibold text-gray-800 text-sm" x-text="r.agent"></p>
                                <p class="text-xs text-gray-500"><span x-text="r.from || 'N/A'"></span> <i class="fas fa-arrow-right mx-1"></i> <span class="text-indigo-600 font-medium" x-text="r.to"></span></p>
                                <p class="text-gray-600 text-sm mt-1" x-text="r.reason"></p>
                            </div>
                            <div class="text-xs text-emerald-600 font-semibold flex-shrink-0" x-show="r.expected_gain" x-text="r.expected_gain"></div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Model footer -->
        <div x-show="claude.model && !claude.loading" class="mt-6 pt-4 text-center">
            <p class="text-[10px] uppercase tracking-widest text-gray-400">
                Análisis generado por Claude · <span x-text="claude.model"></span>
                <span x-show="claude.usage?.input_tokens" class="ml-2">· <span x-text="claude.usage?.input_tokens"></span>↑ <span x-text="claude.usage?.output_tokens"></span>↓ tokens</span>
            </p>
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

        // Claude AI
        claude: {
            loading: false,
            loadingLabel: '',
            action: null,
            error: null,
            needsConfig: false,
            model: '',
            usage: null,
            elapsed: 0,
            _timer: null,
            report: null,
            diagnosis: null,
            radar: null,
            forecast: null,
            optimizer: null
        },

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
        },

        // ===================================================
        // CLAUDE AI — Strategy reports
        // ===================================================
        resetClaude() {
            this.claude.report = null;
            this.claude.diagnosis = null;
            this.claude.radar = null;
            this.claude.forecast = null;
            this.claude.optimizer = null;
            this.claude.error = null;
            this.claude.needsConfig = false;
        },

        async runClaudeAction(action) {
            this.claude.action = action;
            this.resetClaude();
            this.claude.loading = true;
            const labels = {
                executive_report: 'Construyendo reporte ejecutivo integral...',
                operations_diagnosis: 'Diagnosticando operación y SLAs...',
                risk_radar: 'Escaneando señales de riesgo...',
                staffing_forecast: 'Proyectando demanda y staffing...',
                campaign_optimizer: 'Optimizando mezcla de campañas...'
            };
            this.claude.loadingLabel = labels[action] || 'Procesando...';
            this.claude.elapsed = 0;
            if (this.claude._timer) clearInterval(this.claude._timer);
            this.claude._timer = setInterval(() => { this.claude.elapsed++; }, 1000);

            try {
                const url = `api/claude_insights.php?action=${encodeURIComponent(action)}&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`;
                const res = await fetch(url);
                const data = await res.json();

                if (data.needs_configuration) { this.claude.needsConfig = true; return; }
                if (!data.success) { this.claude.error = data.error || 'No se pudo generar el análisis'; return; }

                this.claude.model = data.model || '';
                this.claude.usage = data.usage || null;

                switch (action) {
                    case 'executive_report':
                        this.claude.report = data.report;
                        if (!data.report) this.claude.error = 'Claude respondió pero no fue JSON válido. Reintenta.';
                        break;
                    case 'operations_diagnosis':
                        this.claude.diagnosis = data.diagnosis;
                        if (!data.diagnosis) this.claude.error = 'Respuesta no estructurada. Reintenta.';
                        break;
                    case 'risk_radar':
                        this.claude.radar = data.radar;
                        if (!data.radar) this.claude.error = 'Respuesta no estructurada. Reintenta.';
                        break;
                    case 'staffing_forecast':
                        this.claude.forecast = data.forecast;
                        if (!data.forecast) this.claude.error = 'Respuesta no estructurada. Reintenta.';
                        break;
                    case 'campaign_optimizer':
                        this.claude.optimizer = data.optimizer;
                        if (!data.optimizer) this.claude.error = 'Respuesta no estructurada. Reintenta.';
                        break;
                }
            } catch (e) {
                this.claude.error = e.message || 'Error de red';
            } finally {
                this.claude.loading = false;
                if (this.claude._timer) { clearInterval(this.claude._timer); this.claude._timer = null; }
            }
        }
    };
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
