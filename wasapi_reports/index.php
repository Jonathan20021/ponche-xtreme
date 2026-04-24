<?php
require_once __DIR__ . '/../header.php';

// Only authenticated users
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}
?>

<style>
/*
 * Claude UI scoped overrides.
 * The global theme.css sets `body { color: var(--text-primary) !important; }`
 * which forces all text to light gray in theme-dark, making Tailwind color
 * utilities ineffective on white/soft backgrounds. These rules re-establish
 * correct colors for the Claude panel, modal and reports tab only.
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
.claude-ui .text-slate-800 { color: #1e293b !important; }
.claude-ui .text-indigo-400 { color: #818cf8 !important; }
.claude-ui .text-indigo-500 { color: #6366f1 !important; }
.claude-ui .text-indigo-600 { color: #4f46e5 !important; }
.claude-ui .text-indigo-700 { color: #4338ca !important; }
.claude-ui .text-indigo-800 { color: #3730a3 !important; }
.claude-ui .text-indigo-900 { color: #312e81 !important; }
.claude-ui .text-violet-500 { color: #8b5cf6 !important; }
.claude-ui .text-violet-600 { color: #7c3aed !important; }
.claude-ui .text-violet-700 { color: #6d28d9 !important; }
.claude-ui .text-fuchsia-400 { color: #e879f9 !important; }
.claude-ui .text-fuchsia-500 { color: #d946ef !important; }
.claude-ui .text-fuchsia-600 { color: #c026d3 !important; }
.claude-ui .text-emerald-400 { color: #34d399 !important; }
.claude-ui .text-emerald-500 { color: #10b981 !important; }
.claude-ui .text-emerald-600 { color: #059669 !important; }
.claude-ui .text-emerald-700 { color: #047857 !important; }
.claude-ui .text-emerald-800 { color: #065f46 !important; }
.claude-ui .text-emerald-900 { color: #064e3b !important; }
.claude-ui .text-teal-500 { color: #14b8a6 !important; }
.claude-ui .text-teal-600 { color: #0d9488 !important; }
.claude-ui .text-amber-400 { color: #fbbf24 !important; }
.claude-ui .text-amber-500 { color: #f59e0b !important; }
.claude-ui .text-amber-600 { color: #d97706 !important; }
.claude-ui .text-amber-700 { color: #b45309 !important; }
.claude-ui .text-amber-800 { color: #92400e !important; }
.claude-ui .text-amber-900 { color: #78350f !important; }
.claude-ui .text-yellow-500 { color: #eab308 !important; }
.claude-ui .text-yellow-700 { color: #a16207 !important; }
.claude-ui .text-orange-500 { color: #f97316 !important; }
.claude-ui .text-orange-600 { color: #ea580c !important; }
.claude-ui .text-rose-400 { color: #fb7185 !important; }
.claude-ui .text-rose-500 { color: #f43f5e !important; }
.claude-ui .text-rose-600 { color: #e11d48 !important; }
.claude-ui .text-rose-700 { color: #be123c !important; }
.claude-ui .text-rose-800 { color: #9f1239 !important; }
.claude-ui .text-rose-900 { color: #881337 !important; }
.claude-ui .text-red-500 { color: #ef4444 !important; }
.claude-ui .text-red-600 { color: #dc2626 !important; }
.claude-ui .text-red-700 { color: #b91c1c !important; }
.claude-ui .text-blue-500 { color: #3b82f6 !important; }
.claude-ui .text-blue-600 { color: #2563eb !important; }
.claude-ui .text-blue-700 { color: #1d4ed8 !important; }
.claude-ui .text-cyan-500 { color: #06b6d4 !important; }
.claude-ui .text-cyan-600 { color: #0891b2 !important; }
.claude-ui .text-purple-500 { color: #a855f7 !important; }
.claude-ui .text-purple-600 { color: #9333ea !important; }

/* White-on-dark heroes inside the panel must stay white */
.claude-ui .claude-hero,
.claude-ui .claude-hero *,
.claude-ui .bg-gradient-to-br.text-white,
.claude-ui .bg-gradient-to-br.text-white *,
.claude-ui .bg-gradient-to-r.text-white,
.claude-ui .bg-gradient-to-r.text-white *,
.claude-ui .bg-gray-900,
.claude-ui .bg-gray-900 * {
    color: #ffffff !important;
}
/* Keep icon accents readable even inside white-text heroes */
.claude-ui .bg-gray-900 .text-fuchsia-400 { color: #e879f9 !important; }
.claude-ui .bg-gray-900 .text-gray-400 { color: #9ca3af !important; }
.claude-ui .bg-gradient-to-br .text-fuchsia-400 { color: #e879f9 !important; }

.claude-ui input, .claude-ui textarea, .claude-ui select { color: #1f2937 !important; }
.claude-ui code, .claude-ui .font-mono { color: #4338ca !important; }

/* Ensure soft-tinted card backgrounds remain themselves (theme.css forces bg-white/50/100 to lighter) */
.claude-ui .bg-white { background-color: #ffffff !important; }
.claude-ui .bg-gray-50 { background-color: #f9fafb !important; }
.claude-ui .bg-gray-100 { background-color: #f3f4f6 !important; }
.claude-ui .bg-gray-200 { background-color: #e5e7eb !important; }
.claude-ui .bg-gray-900 { background-color: #111827 !important; }

/*
 * Extended Tailwind palette (emerald, teal, amber, orange, rose, fuchsia,
 * violet, cyan, sky). These are NOT shipped in the Tailwind 2.2.19 CDN build
 * the site loads, so we define them ourselves so Alpine :class bindings like
 * `bg-gradient-to-br from-emerald-500 to-teal-600` actually render.
 */
/* ---- backgrounds ---- */
.claude-ui .bg-emerald-50 { background-color: #ecfdf5 !important; }
.claude-ui .bg-emerald-100 { background-color: #d1fae5 !important; }
.claude-ui .bg-emerald-200 { background-color: #a7f3d0 !important; }
.claude-ui .bg-emerald-500 { background-color: #10b981 !important; }
.claude-ui .bg-emerald-600 { background-color: #059669 !important; }
.claude-ui .bg-teal-50 { background-color: #f0fdfa !important; }
.claude-ui .bg-teal-100 { background-color: #ccfbf1 !important; }
.claude-ui .bg-teal-500 { background-color: #14b8a6 !important; }
.claude-ui .bg-teal-600 { background-color: #0d9488 !important; }
.claude-ui .bg-amber-50 { background-color: #fffbeb !important; }
.claude-ui .bg-amber-100 { background-color: #fef3c7 !important; }
.claude-ui .bg-amber-200 { background-color: #fde68a !important; }
.claude-ui .bg-amber-500 { background-color: #f59e0b !important; }
.claude-ui .bg-amber-600 { background-color: #d97706 !important; }
.claude-ui .bg-orange-50 { background-color: #fff7ed !important; }
.claude-ui .bg-orange-500 { background-color: #f97316 !important; }
.claude-ui .bg-orange-600 { background-color: #ea580c !important; }
.claude-ui .bg-rose-50 { background-color: #fff1f2 !important; }
.claude-ui .bg-rose-100 { background-color: #ffe4e6 !important; }
.claude-ui .bg-rose-200 { background-color: #fecdd3 !important; }
.claude-ui .bg-rose-500 { background-color: #f43f5e !important; }
.claude-ui .bg-rose-600 { background-color: #e11d48 !important; }
.claude-ui .bg-rose-800 { background-color: #9f1239 !important; }
.claude-ui .bg-fuchsia-50 { background-color: #fdf4ff !important; }
.claude-ui .bg-fuchsia-100 { background-color: #fae8ff !important; }
.claude-ui .bg-fuchsia-500 { background-color: #d946ef !important; }
.claude-ui .bg-fuchsia-600 { background-color: #c026d3 !important; }
.claude-ui .bg-violet-50 { background-color: #f5f3ff !important; }
.claude-ui .bg-violet-100 { background-color: #ede9fe !important; }
.claude-ui .bg-violet-200 { background-color: #ddd6fe !important; }
.claude-ui .bg-violet-500 { background-color: #8b5cf6 !important; }
.claude-ui .bg-violet-600 { background-color: #7c3aed !important; }
.claude-ui .bg-violet-700 { background-color: #6d28d9 !important; }
.claude-ui .bg-cyan-50 { background-color: #ecfeff !important; }
.claude-ui .bg-cyan-500 { background-color: #06b6d4 !important; }
.claude-ui .bg-sky-500 { background-color: #0ea5e9 !important; }
.claude-ui .bg-indigo-50 { background-color: #eef2ff !important; }
.claude-ui .bg-indigo-100 { background-color: #e0e7ff !important; }
.claude-ui .bg-indigo-200 { background-color: #c7d2fe !important; }
.claude-ui .bg-indigo-500 { background-color: #6366f1 !important; }
.claude-ui .bg-indigo-600 { background-color: #4f46e5 !important; }
.claude-ui .bg-indigo-700 { background-color: #4338ca !important; }
.claude-ui .bg-blue-50 { background-color: #eff6ff !important; }
.claude-ui .bg-blue-100 { background-color: #dbeafe !important; }
.claude-ui .bg-blue-500 { background-color: #3b82f6 !important; }
.claude-ui .bg-blue-600 { background-color: #2563eb !important; }
.claude-ui .bg-red-50 { background-color: #fef2f2 !important; }
.claude-ui .bg-red-100 { background-color: #fee2e2 !important; }
.claude-ui .bg-red-500 { background-color: #ef4444 !important; }
.claude-ui .bg-red-600 { background-color: #dc2626 !important; }
.claude-ui .bg-red-700 { background-color: #b91c1c !important; }
.claude-ui .bg-red-800 { background-color: #991b1b !important; }
.claude-ui .bg-yellow-100 { background-color: #fef3c7 !important; }
.claude-ui .bg-purple-100 { background-color: #ede9fe !important; }
.claude-ui .bg-purple-600 { background-color: #9333ea !important; }

/* ---- borders ---- */
.claude-ui .border-emerald-100 { border-color: #d1fae5 !important; }
.claude-ui .border-emerald-200 { border-color: #a7f3d0 !important; }
.claude-ui .border-emerald-400 { border-color: #34d399 !important; }
.claude-ui .border-emerald-500 { border-color: #10b981 !important; }
.claude-ui .border-teal-200 { border-color: #99f6e4 !important; }
.claude-ui .border-amber-100 { border-color: #fef3c7 !important; }
.claude-ui .border-amber-200 { border-color: #fde68a !important; }
.claude-ui .border-amber-400 { border-color: #fbbf24 !important; }
.claude-ui .border-amber-500 { border-color: #f59e0b !important; }
.claude-ui .border-rose-200 { border-color: #fecdd3 !important; }
.claude-ui .border-rose-400 { border-color: #fb7185 !important; }
.claude-ui .border-rose-500 { border-color: #f43f5e !important; }
.claude-ui .border-indigo-100 { border-color: #e0e7ff !important; }
.claude-ui .border-indigo-200 { border-color: #c7d2fe !important; }
.claude-ui .border-indigo-300 { border-color: #a5b4fc !important; }
.claude-ui .border-violet-100 { border-color: #ede9fe !important; }
.claude-ui .border-violet-200 { border-color: #ddd6fe !important; }
.claude-ui .border-violet-300 { border-color: #c4b5fd !important; }
.claude-ui .border-violet-400 { border-color: #a78bfa !important; }
.claude-ui .border-fuchsia-300 { border-color: #e879f9 !important; }
.claude-ui .border-red-300 { border-color: #fca5a5 !important; }
.claude-ui .border-red-500 { border-color: #ef4444 !important; }

/*
 * Gradient support. Tailwind 2 has `.bg-gradient-to-*` but only ships stops
 * for the default palette. We supply the missing ones below so
 * `from-emerald-500 to-teal-600`, `via-violet-600`, etc. render properly.
 *
 * `bg-gradient-to-br` in Tailwind 2 already sets
 *   background-image: linear-gradient(to bottom right, var(--tw-gradient-stops));
 * We just need to supply --tw-gradient-from / --tw-gradient-via / --tw-gradient-to.
 */
/* from-* rules — default to 2-stop (from → to). Do NOT include via in stops
 * unless the element also has a via-* class (handled below), otherwise the
 * gradient fades through transparent in the middle and creates a white flare. */
.claude-ui [class*="from-emerald-500"] { --tw-gradient-from: #10b981 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(16, 185, 129, 0)) !important; }
.claude-ui [class*="from-teal-500"]    { --tw-gradient-from: #14b8a6 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(20, 184, 166, 0)) !important; }
.claude-ui [class*="from-amber-500"]   { --tw-gradient-from: #f59e0b !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(245, 158, 11, 0)) !important; }
.claude-ui [class*="from-rose-500"]    { --tw-gradient-from: #f43f5e !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(244, 63, 94, 0)) !important; }
.claude-ui [class*="from-violet-600"]  { --tw-gradient-from: #7c3aed !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(124, 58, 237, 0)) !important; }
.claude-ui [class*="from-fuchsia-500"] { --tw-gradient-from: #d946ef !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(217, 70, 239, 0)) !important; }
.claude-ui [class*="from-indigo-500"]  { --tw-gradient-from: #6366f1 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(99, 102, 241, 0)) !important; }
.claude-ui [class*="from-indigo-600"]  { --tw-gradient-from: #4f46e5 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(79, 70, 229, 0)) !important; }
.claude-ui [class*="from-red-600"]     { --tw-gradient-from: #dc2626 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(220, 38, 38, 0)) !important; }
.claude-ui [class*="from-slate-900"]   { --tw-gradient-from: #0f172a !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(15, 23, 42, 0)) !important; }
.claude-ui [class*="from-gray-50"]     { --tw-gradient-from: #f9fafb !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(249, 250, 251, 0)) !important; }
.claude-ui [class*="from-gray-100"]    { --tw-gradient-from: #f3f4f6 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(243, 244, 246, 0)) !important; }
.claude-ui [class*="from-amber-50"]    { --tw-gradient-from: #fffbeb !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(255, 251, 235, 0)) !important; }
.claude-ui [class*="from-emerald-50"]  { --tw-gradient-from: #ecfdf5 !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(236, 253, 245, 0)) !important; }
.claude-ui [class*="from-indigo-50"]   { --tw-gradient-from: #eef2ff !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(238, 242, 255, 0)) !important; }
.claude-ui [class*="from-violet-50"]   { --tw-gradient-from: #f5f3ff !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(245, 243, 255, 0)) !important; }
.claude-ui [class*="from-indigo-100"]  { --tw-gradient-from: #e0e7ff !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to, rgba(224, 231, 255, 0)) !important; }

/* via-* overrides the stops to 3-stop (from → via → to). Must come AFTER from-*
 * so it wins when both apply (via → violet → fuchsia gradients). */
.claude-ui [class*="via-violet-600"]   { --tw-gradient-stops: var(--tw-gradient-from), #7c3aed, var(--tw-gradient-to, rgba(124, 58, 237, 0)) !important; }
.claude-ui [class*="via-slate-900"]    { --tw-gradient-stops: var(--tw-gradient-from), #0f172a, var(--tw-gradient-to, rgba(15, 23, 42, 0)) !important; }

.claude-ui [class*="to-teal-600"]      { --tw-gradient-to: #0d9488 !important; }
.claude-ui [class*="to-teal-500"]      { --tw-gradient-to: #14b8a6 !important; }
.claude-ui [class*="to-orange-600"]    { --tw-gradient-to: #ea580c !important; }
.claude-ui [class*="to-orange-500"]    { --tw-gradient-to: #f97316 !important; }
.claude-ui [class*="to-red-600"]       { --tw-gradient-to: #dc2626 !important; }
.claude-ui [class*="to-red-700"]       { --tw-gradient-to: #b91c1c !important; }
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
.claude-ui [class*="to-fuchsia-50"]    { --tw-gradient-to: #fdf4ff !important; }
.claude-ui [class*="to-white"]         { --tw-gradient-to: #ffffff !important; }
.claude-ui [class*="to-orange-50"]     { --tw-gradient-to: #fff7ed !important; }
.claude-ui [class*="to-emerald-50"]    { --tw-gradient-to: #ecfdf5 !important; }
.claude-ui [class*="to-teal-50"]       { --tw-gradient-to: #f0fdfa !important; }
.claude-ui [class*="to-violet-50"]     { --tw-gradient-to: #f5f3ff !important; }
.claude-ui [class*="to-gray-200"]      { --tw-gradient-to: #e5e7eb !important; }
.claude-ui [class*="to-gray-100"]      { --tw-gradient-to: #f3f4f6 !important; }
.claude-ui [class*="to-slate-800"]     { --tw-gradient-to: #1e293b !important; }

/* Fallback gradient utilities if Tailwind 2 miss them */
.claude-ui .bg-gradient-to-r  { background-image: linear-gradient(to right, var(--tw-gradient-stops)) !important; }
.claude-ui .bg-gradient-to-br { background-image: linear-gradient(to bottom right, var(--tw-gradient-stops)) !important; }
.claude-ui .bg-gradient-to-b  { background-image: linear-gradient(to bottom, var(--tw-gradient-stops)) !important; }
</style>

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
            <button @click="openClaudePanel()"
                class="bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 hover:from-indigo-700 hover:via-violet-700 hover:to-fuchsia-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 shadow-md">
                <i class="fas fa-brain"></i> Claude AI
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
        
        <!-- Weekly Volume Chart (Por Día de Semana - Para Staffing) -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-semibold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-calendar-week text-orange-500"></i>
                    Histórico por Día de Semana
                    <span class="text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded-full ml-2">Para Staffing</span>
                </h3>
                <div class="text-sm text-gray-500">
                    <i class="fas fa-info-circle"></i> Promedio histórico del período seleccionado
                </div>
            </div>
            <div class="h-64">
                <canvas id="weeklyVolumeChart"></canvas>
            </div>
            <!-- Resumen rápido de staffing -->
            <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-3" x-show="realtimeMetrics.weekly_volume && realtimeMetrics.weekly_volume.length > 0">
                <template x-for="(day, idx) in realtimeMetrics.weekly_volume" :key="idx">
                    <div class="p-3 rounded-lg text-center" 
                        :class="day.avg_total === Math.max(...realtimeMetrics.weekly_volume.map(d => d.avg_total)) ? 'bg-red-50 border-2 border-red-200' : 
                               (day.avg_total === Math.min(...realtimeMetrics.weekly_volume.map(d => d.avg_total)) ? 'bg-green-50 border-2 border-green-200' : 'bg-gray-50')">
                        <p class="font-bold text-sm" x-text="day.day_short"></p>
                        <p class="text-lg font-bold" 
                            :class="day.avg_total === Math.max(...realtimeMetrics.weekly_volume.map(d => d.avg_total)) ? 'text-red-600' : 
                                   (day.avg_total === Math.min(...realtimeMetrics.weekly_volume.map(d => d.avg_total)) ? 'text-green-600' : 'text-gray-700')"
                            x-text="Math.round(day.avg_total)"></p>
                        <p class="text-xs text-gray-500">conv. prom</p>
                        <p class="text-xs mt-1" x-show="day.avg_total === Math.max(...realtimeMetrics.weekly_volume.map(d => d.avg_total))" class="text-red-500">
                            <i class="fas fa-fire"></i> Más ocupado
                        </p>
                        <p class="text-xs mt-1" x-show="day.avg_total === Math.min(...realtimeMetrics.weekly_volume.map(d => d.avg_total))" class="text-green-500">
                            <i class="fas fa-leaf"></i> Menos ocupado
                        </p>
                    </div>
                </template>
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
                    <button @click="openClaudePanel()" class="w-full bg-gradient-to-r from-indigo-100 to-fuchsia-100 hover:from-indigo-200 hover:to-fuchsia-200 text-indigo-700 px-4 py-3 rounded-lg text-sm font-medium transition-all flex items-center gap-3">
                        <i class="fas fa-brain"></i>
                        Obtener Recomendaciones IA Claude
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
    <!-- CLAUDE AI PANEL (Slide-over) - Advanced decision insights -->
    <!-- ============================================================ -->
    <template x-teleport="body">
    <div x-show="showAIPanel" class="claude-ui" style="position:fixed; inset:0; z-index:10000;">
        <div x-show="showAIPanel" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            style="position:absolute; inset:0; background:rgba(15,23,42,0.72); backdrop-filter: blur(4px);"
            @click="showAIPanel = false">
        </div>
        <div x-show="showAIPanel" x-transition:enter="transition ease-out duration-300 transform" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-200 transform" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
            class="overflow-y-auto shadow-2xl"
            style="position:absolute; right:0; top:0; bottom:0; width:100%; max-width:48rem; background:#ffffff; border-left:1px solid #e5e7eb; color:#1f2937;">
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div class="h-12 w-12 rounded-xl bg-gradient-to-br from-indigo-500 via-violet-600 to-fuchsia-600 flex items-center justify-center shadow-lg">
                        <i class="fas fa-brain text-white text-lg"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                            Claude AI
                            <span class="text-[10px] font-semibold uppercase tracking-wider bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Anthropic</span>
                        </h2>
                        <p class="text-gray-500 text-sm">Análisis profundo para toma de decisiones</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="copyClaudeReport()" x-show="claudeReport || claudeDiagnosis || claudeRadar || claudeForecast || claudeOptimizer" title="Copiar reporte"
                        class="p-2 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors">
                        <i class="fas fa-copy"></i>
                    </button>
                    <button @click="showAIPanel = false" class="p-2 text-gray-400 hover:text-gray-800 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>

            <!-- Configuration warning -->
            <div x-show="claudeNeedsConfig" class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl">
                <div class="flex items-start gap-3">
                    <i class="fas fa-key text-amber-500 mt-1"></i>
                    <div class="flex-1">
                        <p class="text-amber-800 font-semibold">Claude no está configurado</p>
                        <p class="text-amber-700 text-sm">Agrega tu clave de Anthropic en <a href="../settings.php#global-ai" class="underline">Ajustes → API de IA Global</a> para habilitar los análisis avanzados.</p>
                    </div>
                </div>
            </div>

            <!-- Analysis tabs -->
            <div class="flex flex-wrap gap-2 mb-6">
                <button @click="runClaudeAction('executive_report')" :disabled="claudeLoading"
                    :class="claudeActiveAction === 'executive_report' ? 'bg-indigo-600 text-white shadow-md' : 'bg-white text-gray-700 border border-gray-200 hover:border-indigo-300'"
                    class="px-3 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-2 disabled:opacity-50">
                    <i class="fas fa-chart-pie"></i> Reporte Ejecutivo
                </button>
                <button @click="runClaudeAction('operations_diagnosis')" :disabled="claudeLoading"
                    :class="claudeActiveAction === 'operations_diagnosis' ? 'bg-violet-600 text-white shadow-md' : 'bg-white text-gray-700 border border-gray-200 hover:border-violet-300'"
                    class="px-3 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-2 disabled:opacity-50">
                    <i class="fas fa-stethoscope"></i> Diagnóstico Operativo
                </button>
                <button @click="runClaudeAction('risk_radar')" :disabled="claudeLoading"
                    :class="claudeActiveAction === 'risk_radar' ? 'bg-rose-600 text-white shadow-md' : 'bg-white text-gray-700 border border-gray-200 hover:border-rose-300'"
                    class="px-3 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-2 disabled:opacity-50">
                    <i class="fas fa-shield-alt"></i> Radar de Riesgos
                </button>
                <button @click="runClaudeAction('staffing_forecast')" :disabled="claudeLoading"
                    :class="claudeActiveAction === 'staffing_forecast' ? 'bg-emerald-600 text-white shadow-md' : 'bg-white text-gray-700 border border-gray-200 hover:border-emerald-300'"
                    class="px-3 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-2 disabled:opacity-50">
                    <i class="fas fa-calendar-week"></i> Forecast Staffing
                </button>
                <button @click="runClaudeAction('campaign_optimizer')" :disabled="claudeLoading"
                    :class="claudeActiveAction === 'campaign_optimizer' ? 'bg-amber-600 text-white shadow-md' : 'bg-white text-gray-700 border border-gray-200 hover:border-amber-300'"
                    class="px-3 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-2 disabled:opacity-50">
                    <i class="fas fa-bullseye"></i> Optimizador Campañas
                </button>
            </div>

            <!-- Loading State -->
            <div x-show="claudeLoading" class="flex flex-col items-center justify-center py-14">
                <div class="relative">
                    <div class="w-20 h-20 border-4 border-indigo-100 rounded-full"></div>
                    <div class="absolute inset-0 w-20 h-20 border-4 border-transparent border-t-indigo-500 border-r-fuchsia-500 rounded-full animate-spin"></div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <i class="fas fa-brain text-indigo-500 text-xl"></i>
                    </div>
                </div>
                <p class="text-gray-700 mt-5 font-medium">Claude está analizando tus datos</p>
                <p class="text-gray-500 text-sm mt-1" x-text="claudeLoadingLabel"></p>
                <div class="mt-4 flex items-center gap-3 bg-indigo-50 border border-indigo-100 px-4 py-2 rounded-full">
                    <i class="fas fa-hourglass-half text-indigo-500 text-xs animate-pulse"></i>
                    <span class="text-indigo-700 text-sm font-mono" x-text="claudeElapsed + 's transcurridos'"></span>
                    <span class="text-gray-400 text-xs">(~30-60s)</span>
                </div>
                <!-- Skeleton preview -->
                <div class="w-full mt-8 space-y-3 max-w-lg opacity-60">
                    <div class="h-20 rounded-xl bg-gradient-to-r from-gray-100 via-gray-200 to-gray-100 animate-pulse"></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="h-24 rounded-xl bg-gradient-to-r from-gray-100 via-gray-200 to-gray-100 animate-pulse"></div>
                        <div class="h-24 rounded-xl bg-gradient-to-r from-gray-100 via-gray-200 to-gray-100 animate-pulse"></div>
                    </div>
                    <div class="h-16 rounded-xl bg-gradient-to-r from-gray-100 via-gray-200 to-gray-100 animate-pulse"></div>
                </div>
            </div>

            <!-- Error state -->
            <div x-show="!claudeLoading && claudeError" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                <div class="flex items-start gap-3">
                    <i class="fas fa-exclamation-triangle text-red-500 mt-1"></i>
                    <div>
                        <p class="text-red-700 font-semibold">No se pudo generar el análisis</p>
                        <p class="text-red-600 text-sm" x-text="claudeError"></p>
                    </div>
                </div>
            </div>

            <!-- ======= EXECUTIVE REPORT ======= -->
            <div x-show="!claudeLoading && claudeActiveAction === 'executive_report' && claudeReport" class="space-y-5">
                <!-- Health score headline -->
                <div class="rounded-2xl p-6 text-white shadow-lg"
                    :class="{
                        'bg-gradient-to-br from-emerald-500 to-teal-600': (claudeReport?.health_score || 0) >= 80,
                        'bg-gradient-to-br from-indigo-500 to-violet-600': (claudeReport?.health_score || 0) >= 60 && (claudeReport?.health_score || 0) < 80,
                        'bg-gradient-to-br from-amber-500 to-orange-600': (claudeReport?.health_score || 0) >= 40 && (claudeReport?.health_score || 0) < 60,
                        'bg-gradient-to-br from-rose-500 to-red-600': (claudeReport?.health_score || 0) < 40
                    }">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <p class="text-xs uppercase tracking-widest opacity-90">Estado general · <span x-text="claudeReport?.health_label || '—'"></span></p>
                            <h3 class="text-2xl font-bold mt-1 leading-snug" x-text="claudeReport?.headline || 'Reporte ejecutivo'"></h3>
                            <p class="text-sm mt-3 opacity-95 leading-relaxed" x-text="claudeReport?.executive_summary"></p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-5xl font-black" x-text="(claudeReport?.health_score || 0)"></div>
                            <div class="text-xs uppercase opacity-90">Health Score</div>
                        </div>
                    </div>
                </div>

                <!-- Key findings -->
                <div x-show="(claudeReport?.key_findings || []).length" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-4 flex items-center gap-2">
                        <i class="fas fa-lightbulb text-amber-500"></i> Hallazgos clave
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <template x-for="(finding, idx) in (claudeReport?.key_findings || [])" :key="idx">
                            <div class="bg-gradient-to-br from-gray-50 to-white border border-gray-100 rounded-lg p-4">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center"
                                        :class="finding.icon === 'trend-up' ? 'bg-emerald-100 text-emerald-600' : finding.icon === 'trend-down' ? 'bg-rose-100 text-rose-600' : finding.icon === 'alert' ? 'bg-amber-100 text-amber-600' : 'bg-indigo-100 text-indigo-600'">
                                        <i class="fas"
                                            :class="finding.icon === 'trend-up' ? 'fa-arrow-trend-up' : finding.icon === 'trend-down' ? 'fa-arrow-trend-down' : finding.icon === 'alert' ? 'fa-triangle-exclamation' : 'fa-circle-check'"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-semibold text-gray-800" x-text="finding.title"></p>
                                        <p class="text-gray-600 text-sm mt-1" x-text="finding.detail"></p>
                                        <p class="text-indigo-600 text-xs mt-2 font-mono" x-show="finding.metric" x-text="finding.metric"></p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Strengths + Risks side by side -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white rounded-xl shadow-md p-5 border-l-4 border-emerald-400">
                        <h4 class="text-emerald-700 font-semibold mb-3 flex items-center gap-2">
                            <i class="fas fa-check-double"></i> Fortalezas
                        </h4>
                        <ul class="space-y-2">
                            <template x-for="(s, i) in (claudeReport?.strengths || [])" :key="i">
                                <li class="flex items-start gap-2 text-sm text-gray-700">
                                    <i class="fas fa-circle text-emerald-400 text-[6px] mt-1.5"></i>
                                    <span x-text="s"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <div class="bg-white rounded-xl shadow-md p-5 border-l-4 border-rose-400">
                        <h4 class="text-rose-700 font-semibold mb-3 flex items-center gap-2">
                            <i class="fas fa-triangle-exclamation"></i> Riesgos
                        </h4>
                        <div class="space-y-3">
                            <template x-for="(risk, i) in (claudeReport?.risks || [])" :key="i">
                                <div>
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-[10px] uppercase tracking-wide font-bold px-2 py-0.5 rounded"
                                            :class="{
                                                'bg-rose-100 text-rose-700': risk.severity === 'critical',
                                                'bg-amber-100 text-amber-700': risk.severity === 'high',
                                                'bg-yellow-100 text-yellow-700': risk.severity === 'medium',
                                                'bg-gray-100 text-gray-700': risk.severity === 'low'
                                            }" x-text="risk.severity"></span>
                                        <p class="font-semibold text-gray-800 text-sm" x-text="risk.title"></p>
                                    </div>
                                    <p class="text-gray-600 text-sm" x-text="risk.detail"></p>
                                    <p class="text-rose-500 text-xs mt-1" x-show="risk.impact"><i class="fas fa-bolt text-[10px] mr-1"></i><span x-text="risk.impact"></span></p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Opportunities -->
                <div x-show="(claudeReport?.opportunities || []).length" class="bg-gradient-to-br from-indigo-50 to-violet-50 border border-indigo-100 rounded-xl p-5">
                    <h4 class="text-indigo-800 font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-rocket"></i> Oportunidades
                    </h4>
                    <div class="space-y-2">
                        <template x-for="(opp, i) in (claudeReport?.opportunities || [])" :key="i">
                            <div class="bg-white rounded-lg p-3 shadow-sm">
                                <p class="font-semibold text-gray-800 text-sm" x-text="opp.title"></p>
                                <p class="text-gray-600 text-sm mt-1" x-text="opp.detail"></p>
                                <p class="text-indigo-600 text-xs mt-2" x-show="opp.estimated_impact"><i class="fas fa-chart-line mr-1"></i><span x-text="opp.estimated_impact"></span></p>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Action Plan -->
                <div x-show="(claudeReport?.action_plan || []).length" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-4 flex items-center gap-2">
                        <i class="fas fa-list-check text-violet-500"></i> Plan de acción priorizado
                    </h4>
                    <div class="space-y-2">
                        <template x-for="(action, i) in (claudeReport?.action_plan || [])" :key="i">
                            <div class="flex items-start gap-3 p-3 bg-gradient-to-r from-violet-50 to-white border border-violet-100 rounded-lg">
                                <span class="flex-shrink-0 w-7 h-7 rounded-full bg-violet-600 text-white flex items-center justify-center text-xs font-bold" x-text="action.priority || i + 1"></span>
                                <div class="flex-1">
                                    <p class="text-gray-800 font-medium" x-text="action.action"></p>
                                    <div class="flex flex-wrap gap-2 mt-2 text-xs">
                                        <span class="bg-white border border-gray-200 text-gray-700 px-2 py-0.5 rounded" x-show="action.owner">
                                            <i class="fas fa-user-tag mr-1"></i><span x-text="action.owner"></span>
                                        </span>
                                        <span class="bg-white border border-gray-200 text-gray-700 px-2 py-0.5 rounded" x-show="action.timeframe">
                                            <i class="far fa-clock mr-1"></i><span x-text="action.timeframe"></span>
                                        </span>
                                        <span class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-2 py-0.5 rounded" x-show="action.expected_outcome">
                                            <i class="fas fa-arrow-up mr-1"></i><span x-text="action.expected_outcome"></span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Board-ready message -->
                <div x-show="claudeReport?.board_message" class="bg-gray-900 text-white rounded-xl p-5 shadow-lg">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-quote-left text-fuchsia-400"></i>
                        <p class="text-xs uppercase tracking-widest text-gray-400">Mensaje para dirección</p>
                    </div>
                    <p class="text-lg leading-relaxed" x-text="claudeReport?.board_message"></p>
                </div>
            </div>

            <!-- ======= OPERATIONS DIAGNOSIS ======= -->
            <div x-show="!claudeLoading && claudeActiveAction === 'operations_diagnosis' && claudeDiagnosis" class="space-y-5">
                <div class="bg-gradient-to-br from-violet-600 to-indigo-700 text-white rounded-xl p-5 shadow-lg">
                    <p class="text-xs uppercase tracking-widest opacity-90">Diagnóstico operativo</p>
                    <p class="text-base leading-relaxed mt-2" x-text="claudeDiagnosis?.diagnosis_overview"></p>
                </div>

                <div x-show="(claudeDiagnosis?.bottlenecks || []).length" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-filter text-violet-500"></i> Cuellos de botella</h4>
                    <div class="space-y-3">
                        <template x-for="(b, i) in (claudeDiagnosis?.bottlenecks || [])" :key="i">
                            <div class="border-l-4 border-violet-400 pl-4 py-2">
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] uppercase tracking-wide bg-violet-100 text-violet-700 px-2 py-0.5 rounded font-bold" x-text="b.area"></span>
                                    <p class="font-semibold text-gray-800" x-text="b.title"></p>
                                </div>
                                <p class="text-gray-600 text-sm mt-1" x-text="b.detail"></p>
                                <p class="text-violet-600 text-xs mt-1 font-mono" x-show="b.evidence" x-text="b.evidence"></p>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="(claudeDiagnosis?.sla_breaches || []).length" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-gauge text-rose-500"></i> Brechas de SLA</h4>
                    <div class="space-y-2">
                        <template x-for="(sb, i) in (claudeDiagnosis?.sla_breaches || [])" :key="i">
                            <div class="p-3 rounded-lg" :class="sb.level === 'critical' ? 'bg-rose-50 border border-rose-200' : 'bg-amber-50 border border-amber-200'">
                                <p class="font-semibold text-gray-800" x-text="sb.metric + ' — ' + (sb.level || '')"></p>
                                <p class="text-gray-600 text-sm" x-text="sb.detail"></p>
                                <p class="text-indigo-600 text-xs mt-1" x-show="sb.suggested_target">Objetivo sugerido: <span x-text="sb.suggested_target"></span></p>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="claudeDiagnosis?.capacity_analysis" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-people-group text-indigo-500"></i> Capacidad</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="bg-indigo-50 rounded-lg p-3">
                            <p class="text-xs text-indigo-600 uppercase">Capacidad actual</p>
                            <p class="text-gray-800 font-medium mt-1" x-text="claudeDiagnosis?.capacity_analysis?.current_capacity"></p>
                        </div>
                        <div class="bg-emerald-50 rounded-lg p-3">
                            <p class="text-xs text-emerald-600 uppercase">Utilización</p>
                            <p class="text-gray-800 font-medium mt-1" x-text="claudeDiagnosis?.capacity_analysis?.utilization"></p>
                        </div>
                        <div class="bg-amber-50 rounded-lg p-3">
                            <p class="text-xs text-amber-600 uppercase">Brecha</p>
                            <p class="text-gray-800 font-medium mt-1" x-text="claudeDiagnosis?.capacity_analysis?.gap"></p>
                        </div>
                    </div>
                    <p class="text-gray-600 text-sm mt-3" x-text="claudeDiagnosis?.capacity_analysis?.comment"></p>
                </div>

                <div x-show="claudeDiagnosis?.plan_30_60_90" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-route text-emerald-500"></i> Plan 30/60/90</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <template x-for="bucket in ['next_24h','next_30d','next_60d','next_90d']" :key="bucket">
                            <div class="bg-gradient-to-br from-gray-50 to-white border border-gray-100 rounded-lg p-3">
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2" x-text="bucket.replace('next_','').replace('_',' ')"></p>
                                <ul class="space-y-2">
                                    <template x-for="(item, i) in (claudeDiagnosis?.plan_30_60_90?.[bucket] || [])" :key="i">
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

                <div x-show="(claudeDiagnosis?.quick_wins || []).length" class="bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-100 rounded-xl p-5">
                    <h4 class="text-emerald-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-bolt"></i> Quick wins</h4>
                    <div class="space-y-2">
                        <template x-for="(qw, i) in (claudeDiagnosis?.quick_wins || [])" :key="i">
                            <div class="bg-white rounded-lg p-3 shadow-sm">
                                <p class="font-semibold text-gray-800 text-sm" x-text="qw.title"></p>
                                <p class="text-gray-600 text-xs mt-1">
                                    Impacto: <span class="text-emerald-600 font-medium" x-text="qw.expected_impact"></span> ·
                                    Esfuerzo: <span class="font-medium" x-text="qw.effort"></span>
                                </p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- ======= RISK RADAR ======= -->
            <div x-show="!claudeLoading && claudeActiveAction === 'risk_radar' && claudeRadar" class="space-y-5">
                <div class="rounded-xl p-5 text-white shadow-lg"
                    :class="{
                        'bg-gradient-to-br from-emerald-500 to-teal-600': claudeRadar?.overall_risk_level === 'low',
                        'bg-gradient-to-br from-amber-500 to-orange-600': claudeRadar?.overall_risk_level === 'medium',
                        'bg-gradient-to-br from-rose-500 to-red-600': claudeRadar?.overall_risk_level === 'high',
                        'bg-gradient-to-br from-red-600 to-rose-800': claudeRadar?.overall_risk_level === 'critical'
                    }">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs uppercase tracking-widest opacity-90">Radar de riesgos</p>
                            <h3 class="text-2xl font-bold mt-1" x-text="claudeRadar?.summary"></h3>
                            <p class="text-sm opacity-90 mt-2">Nivel: <span class="font-bold uppercase" x-text="claudeRadar?.overall_risk_level"></span></p>
                        </div>
                        <div class="text-right">
                            <div class="text-5xl font-black" x-text="(claudeRadar?.overall_score || 0)"></div>
                            <div class="text-xs uppercase opacity-90">Score</div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-satellite-dish text-rose-500"></i> Riesgos detectados</h4>
                    <div class="space-y-3">
                        <template x-for="(r, i) in (claudeRadar?.risks || [])" :key="i">
                            <div class="border-l-4 rounded-r-lg p-3 bg-gray-50"
                                :class="{
                                    'border-red-500': r.severity === 'critical',
                                    'border-rose-400': r.severity === 'high',
                                    'border-amber-400': r.severity === 'medium',
                                    'border-emerald-400': r.severity === 'low'
                                }">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded bg-gray-100 text-gray-700" x-text="r.category"></span>
                                    <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded"
                                        :class="{'bg-red-100 text-red-700': r.severity === 'critical','bg-rose-100 text-rose-700': r.severity === 'high','bg-amber-100 text-amber-700': r.severity === 'medium','bg-emerald-100 text-emerald-700': r.severity === 'low'}"
                                        x-text="r.severity"></span>
                                    <span class="text-[10px] text-gray-500">probabilidad: <span x-text="r.likelihood"></span></span>
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

                <div x-show="(claudeRadar?.early_warnings || []).length" class="bg-amber-50 border border-amber-200 rounded-xl p-5">
                    <h4 class="text-amber-800 font-semibold mb-2 flex items-center gap-2"><i class="fas fa-bell"></i> Señales tempranas</h4>
                    <ul class="space-y-1 text-sm text-amber-900">
                        <template x-for="(w, i) in (claudeRadar?.early_warnings || [])" :key="i">
                            <li class="flex items-start gap-2"><i class="fas fa-dot-circle text-amber-500 mt-1 text-[8px]"></i><span x-text="w"></span></li>
                        </template>
                    </ul>
                </div>
            </div>

            <!-- ======= STAFFING FORECAST ======= -->
            <div x-show="!claudeLoading && claudeActiveAction === 'staffing_forecast' && claudeForecast" class="space-y-5">
                <div class="bg-gradient-to-br from-emerald-500 to-teal-600 text-white rounded-xl p-5 shadow-lg">
                    <p class="text-xs uppercase tracking-widest opacity-90">Forecast de staffing</p>
                    <p class="text-base mt-2 opacity-95" x-text="claudeForecast?.cost_vs_service_tradeoff"></p>
                </div>

                <div x-show="(claudeForecast?.forecast_next_7_days || []).length" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-chart-line text-emerald-500"></i> Próximos 7 días</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-2">
                        <template x-for="(d, i) in (claudeForecast?.forecast_next_7_days || [])" :key="i">
                            <div class="bg-gradient-to-br from-emerald-50 to-white border border-emerald-100 rounded-lg p-3 text-center">
                                <p class="text-[10px] uppercase text-gray-500" x-text="d.weekday"></p>
                                <p class="text-[11px] text-gray-400" x-text="d.date"></p>
                                <p class="text-xl font-bold text-emerald-600 mt-1" x-text="d.expected_conversations"></p>
                                <p class="text-[10px] text-gray-500">conv.</p>
                                <div class="mt-1 text-xs font-semibold text-indigo-600">
                                    <i class="fas fa-headset"></i> <span x-text="d.suggested_agents"></span>
                                </div>
                                <span class="text-[9px] uppercase tracking-wide mt-1 inline-block px-1.5 py-0.5 rounded"
                                    :class="{'bg-emerald-100 text-emerald-700': d.confidence === 'alta','bg-amber-100 text-amber-700': d.confidence === 'media','bg-gray-100 text-gray-600': d.confidence === 'baja'}"
                                    x-text="d.confidence"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="(claudeForecast?.weekly_plan || []).length" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-calendar-check text-indigo-500"></i> Plan semanal</h4>
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
                                <template x-for="(row, i) in (claudeForecast?.weekly_plan || [])" :key="i">
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-gray-800" x-text="row.day"></td>
                                        <td class="px-3 py-2 text-gray-600" x-text="row.shift"></td>
                                        <td class="px-3 py-2 text-center font-bold text-indigo-600" x-text="row.agents"></td>
                                        <td class="px-3 py-2 text-gray-600" x-text="row.notes"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="claudeForecast?.hiring_recommendation" class="rounded-xl p-5 shadow-md"
                    :class="claudeForecast?.hiring_recommendation?.needed ? 'bg-gradient-to-br from-amber-50 to-orange-50 border border-amber-200' : 'bg-gradient-to-br from-gray-50 to-white border border-gray-200'">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-user-plus text-amber-500"></i> Recomendación de contratación</h4>
                    <div class="flex items-start gap-4">
                        <div class="text-center">
                            <div class="text-3xl font-black" :class="claudeForecast?.hiring_recommendation?.needed ? 'text-amber-600' : 'text-gray-500'"
                                x-text="claudeForecast?.hiring_recommendation?.count || 0"></div>
                            <div class="text-[10px] uppercase text-gray-500">contrataciones</div>
                        </div>
                        <div class="flex-1">
                            <p class="font-semibold text-gray-800" x-text="claudeForecast?.hiring_recommendation?.profile"></p>
                            <p class="text-gray-600 text-sm mt-1" x-text="claudeForecast?.hiring_recommendation?.reasoning"></p>
                            <span class="mt-2 inline-block text-[10px] uppercase tracking-wide px-2 py-0.5 rounded font-bold"
                                :class="{'bg-rose-100 text-rose-700': claudeForecast?.hiring_recommendation?.priority === 'alta','bg-amber-100 text-amber-700': claudeForecast?.hiring_recommendation?.priority === 'media','bg-gray-100 text-gray-700': claudeForecast?.hiring_recommendation?.priority === 'baja'}"
                                x-text="'Prioridad ' + (claudeForecast?.hiring_recommendation?.priority || '')"></span>
                        </div>
                    </div>
                </div>

                <div x-show="(claudeForecast?.assumptions || []).length" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-2 flex items-center gap-2"><i class="fas fa-sliders text-gray-500"></i> Supuestos</h4>
                    <ul class="text-sm text-gray-600 space-y-1">
                        <template x-for="(a, i) in (claudeForecast?.assumptions || [])" :key="i">
                            <li class="flex items-start gap-2"><i class="fas fa-check text-emerald-500 mt-1 text-xs"></i><span x-text="a"></span></li>
                        </template>
                    </ul>
                </div>
            </div>

            <!-- ======= CAMPAIGN OPTIMIZER ======= -->
            <div x-show="!claudeLoading && claudeActiveAction === 'campaign_optimizer' && claudeOptimizer" class="space-y-5">
                <div class="bg-gradient-to-br from-amber-500 to-orange-600 text-white rounded-xl p-5 shadow-lg">
                    <p class="text-xs uppercase tracking-widest opacity-90">Optimizador de campañas</p>
                    <p class="text-base mt-2 opacity-95 leading-relaxed" x-text="claudeOptimizer?.overview"></p>
                </div>

                <div x-show="(claudeOptimizer?.campaign_insights || []).length" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-bullhorn text-amber-500"></i> Campañas</h4>
                    <div class="space-y-3">
                        <template x-for="(c, i) in (claudeOptimizer?.campaign_insights || [])" :key="i">
                            <div class="border border-gray-100 rounded-lg p-3 bg-gray-50">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-semibold text-gray-800" x-text="c.campaign"></p>
                                    <span class="text-[10px] uppercase tracking-wide font-bold px-2 py-0.5 rounded"
                                        :class="{'bg-emerald-100 text-emerald-700': c.status === 'saludable','bg-amber-100 text-amber-700': c.status === 'en_riesgo','bg-rose-100 text-rose-700': c.status === 'crítica'}"
                                        x-text="c.status"></span>
                                </div>
                                <p class="text-gray-600 text-sm mt-1" x-text="c.insight"></p>
                                <p class="text-amber-600 text-sm mt-1"><i class="fas fa-arrow-right mr-1 text-xs"></i><span x-text="c.recommendation"></span></p>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="(claudeOptimizer?.reassignments || []).length" class="bg-white rounded-xl shadow-md p-5">
                    <h4 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-shuffle text-indigo-500"></i> Reasignaciones sugeridas</h4>
                    <div class="space-y-2">
                        <template x-for="(r, i) in (claudeOptimizer?.reassignments || [])" :key="i">
                            <div class="bg-gradient-to-r from-indigo-50 to-white rounded-lg p-3 flex items-center gap-3">
                                <i class="fas fa-user-tag text-indigo-500"></i>
                                <div class="flex-1">
                                    <p class="font-semibold text-gray-800 text-sm" x-text="r.agent"></p>
                                    <p class="text-xs text-gray-500">
                                        <span x-text="r.from || 'N/A'"></span>
                                        <i class="fas fa-arrow-right mx-1"></i>
                                        <span class="text-indigo-600 font-medium" x-text="r.to"></span>
                                    </p>
                                    <p class="text-gray-600 text-sm mt-1" x-text="r.reason"></p>
                                </div>
                                <div class="text-xs text-emerald-600 font-semibold flex-shrink-0" x-show="r.expected_gain" x-text="r.expected_gain"></div>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="(claudeOptimizer?.focus_campaigns || []).length" class="bg-indigo-50 border border-indigo-100 rounded-xl p-5">
                    <h4 class="text-indigo-800 font-semibold mb-2 flex items-center gap-2"><i class="fas fa-flag"></i> Foco prioritario</h4>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="(f, i) in (claudeOptimizer?.focus_campaigns || [])" :key="i">
                            <span class="bg-white border border-indigo-200 text-indigo-700 px-3 py-1 rounded-full text-sm" x-text="f"></span>
                        </template>
                    </div>
                </div>

                <div x-show="(claudeOptimizer?.kill_or_pivot || []).length" class="bg-rose-50 border border-rose-200 rounded-xl p-5">
                    <h4 class="text-rose-800 font-semibold mb-2 flex items-center gap-2"><i class="fas fa-skull-crossbones"></i> Kill o pivotar</h4>
                    <div class="space-y-2">
                        <template x-for="(k, i) in (claudeOptimizer?.kill_or_pivot || [])" :key="i">
                            <div class="bg-white rounded-lg p-3 shadow-sm">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-gray-800" x-text="k.campaign"></span>
                                    <span class="text-[10px] uppercase tracking-wide font-bold bg-rose-100 text-rose-700 px-2 py-0.5 rounded" x-text="k.recommendation"></span>
                                </div>
                                <p class="text-gray-600 text-sm mt-1" x-text="k.reason"></p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div x-show="!claudeLoading && !claudeError && !claudeReport && !claudeDiagnosis && !claudeRadar && !claudeForecast && !claudeOptimizer && !claudeNeedsConfig" class="flex flex-col items-center justify-center py-14 text-center">
                <div class="h-20 w-20 rounded-2xl bg-gradient-to-br from-indigo-100 via-violet-100 to-fuchsia-100 flex items-center justify-center mb-4">
                    <i class="fas fa-brain text-indigo-500 text-3xl"></i>
                </div>
                <h3 class="text-gray-800 font-semibold text-lg mb-2">Analiza con Claude AI</h3>
                <p class="text-gray-500 text-sm max-w-md">Elige un tipo de análisis arriba para que Claude lea tus datos de Wasapi y genere recomendaciones accionables para tu equipo.</p>
                <div class="mt-4 flex flex-wrap justify-center gap-2 text-xs text-gray-500">
                    <span class="bg-gray-100 px-2 py-1 rounded-full"><i class="fas fa-calendar mr-1"></i><span x-text="filters.startDate"></span> → <span x-text="filters.endDate"></span></span>
                </div>
            </div>

            <!-- Model footer -->
            <div x-show="claudeModel" class="mt-6 pt-4 border-t border-gray-100 text-center">
                <p class="text-[10px] uppercase tracking-widest text-gray-400">
                    Análisis generado por Claude · <span x-text="claudeModel"></span>
                    <span x-show="claudeUsage?.input_tokens" class="ml-2">· <span x-text="claudeUsage?.input_tokens"></span>↑ <span x-text="claudeUsage?.output_tokens"></span>↓ tokens</span>
                </p>
            </div>
        </div>
    </div>
    </div>
    </template>

    <!-- ============================================================ -->
    <!-- AGENT DETAIL MODAL — Claude Coaching -->
    <!-- ============================================================ -->
    <template x-teleport="body">
    <div x-show="showAgentModal" x-transition class="claude-ui" style="position:fixed; inset:0; z-index:10001; display:flex; align-items:center; justify-content:center; padding:1rem; background:rgba(15,23,42,0.72); backdrop-filter: blur(4px);" @click.self="showAgentModal = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto" style="color:#1f2937;">
            <!-- Header -->
            <div class="bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 text-white p-6 rounded-t-2xl">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="h-14 w-14 rounded-full bg-white/20 backdrop-blur flex items-center justify-center text-xl font-bold"
                            x-text="(selectedAgent?.full_name || selectedAgent?.name || 'A').substring(0, 2).toUpperCase()"></div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-xl font-bold" x-text="selectedAgent?.full_name || selectedAgent?.name"></h2>
                                <span class="text-[10px] font-bold uppercase bg-white/25 px-2 py-0.5 rounded">Coaching IA</span>
                            </div>
                            <p class="text-white/80 text-sm" x-text="selectedAgent?.campaign_name || ''"></p>
                            <p class="text-white/80 text-xs mt-1" x-show="claudeCoachingRanking">
                                <i class="fas fa-medal mr-1"></i>
                                Ranking <span x-text="claudeCoachingRanking?.position"></span> de <span x-text="claudeCoachingRanking?.of"></span>
                            </p>
                        </div>
                    </div>
                    <button @click="showAgentModal = false" class="p-2 text-white/80 hover:text-white hover:bg-white/10 rounded-lg">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>

            <div class="p-6">
                <!-- Loading -->
                <div x-show="agentAnalysisLoading" class="flex flex-col items-center justify-center py-14">
                    <div class="relative">
                        <div class="w-16 h-16 border-4 border-indigo-100 rounded-full"></div>
                        <div class="absolute inset-0 w-16 h-16 border-4 border-transparent border-t-indigo-500 border-r-fuchsia-500 rounded-full animate-spin"></div>
                    </div>
                    <p class="text-gray-700 mt-4 font-medium">Claude está generando el plan de coaching...</p>
                </div>

                <!-- Error -->
                <div x-show="!agentAnalysisLoading && claudeCoachingError" class="p-4 bg-red-50 border border-red-200 rounded-xl">
                    <p class="text-red-700 font-semibold"><i class="fas fa-triangle-exclamation mr-2"></i>No se pudo generar el coaching</p>
                    <p class="text-red-600 text-sm" x-text="claudeCoachingError"></p>
                </div>

                <!-- Coaching content -->
                <div x-show="!agentAnalysisLoading && claudeCoaching" class="space-y-5">
                    <!-- Performance score -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-1 rounded-xl p-4 text-white"
                            :class="{
                                'bg-gradient-to-br from-emerald-500 to-teal-600': (claudeCoaching?.performance_score || 0) >= 80,
                                'bg-gradient-to-br from-indigo-500 to-violet-600': (claudeCoaching?.performance_score || 0) >= 60 && (claudeCoaching?.performance_score || 0) < 80,
                                'bg-gradient-to-br from-amber-500 to-orange-600': (claudeCoaching?.performance_score || 0) >= 40 && (claudeCoaching?.performance_score || 0) < 60,
                                'bg-gradient-to-br from-rose-500 to-red-600': (claudeCoaching?.performance_score || 0) < 40
                            }">
                            <p class="text-xs uppercase opacity-90 tracking-widest">Performance Score</p>
                            <p class="text-5xl font-black mt-1" x-text="claudeCoaching?.performance_score || 0"></p>
                            <p class="text-sm opacity-95 mt-1" x-text="claudeCoaching?.performance_label"></p>
                        </div>
                        <div class="md:col-span-2 bg-gray-50 rounded-xl p-4">
                            <p class="text-xs uppercase text-gray-500 tracking-widest mb-1">Lectura del agente</p>
                            <p class="text-gray-800 text-sm leading-relaxed" x-text="claudeCoaching?.narrative"></p>
                            <div class="mt-3 flex items-center gap-2 text-xs flex-wrap">
                                <span class="px-2 py-1 rounded-full font-semibold"
                                    :class="{'bg-emerald-100 text-emerald-700': claudeCoaching?.risk_of_attrition === 'bajo','bg-amber-100 text-amber-700': claudeCoaching?.risk_of_attrition === 'medio','bg-rose-100 text-rose-700': claudeCoaching?.risk_of_attrition === 'alto'}"
                                    x-text="'Riesgo de rotación: ' + (claudeCoaching?.risk_of_attrition || 'n/a')"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Strengths & Gaps -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4">
                            <h3 class="text-emerald-700 font-semibold mb-2 flex items-center gap-2">
                                <i class="fas fa-check-circle"></i> Fortalezas
                            </h3>
                            <ul class="space-y-1">
                                <template x-for="(s, i) in (claudeCoaching?.strengths || [])" :key="i">
                                    <li class="text-gray-700 text-sm flex items-start gap-2">
                                        <i class="fas fa-plus text-emerald-500 mt-1 text-[10px]"></i>
                                        <span x-text="s"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                            <h3 class="text-amber-700 font-semibold mb-2 flex items-center gap-2">
                                <i class="fas fa-arrow-up-right-dots"></i> Áreas de mejora
                            </h3>
                            <ul class="space-y-1">
                                <template x-for="(g, i) in (claudeCoaching?.gaps || [])" :key="i">
                                    <li class="text-gray-700 text-sm flex items-start gap-2">
                                        <i class="fas fa-angle-right text-amber-500 mt-1 text-[10px]"></i>
                                        <span x-text="g"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>

                    <!-- Coaching plan 30/60/90 -->
                    <div class="bg-white border border-gray-200 rounded-xl p-4">
                        <h3 class="text-gray-800 font-semibold mb-4 flex items-center gap-2">
                            <i class="fas fa-route text-indigo-500"></i> Plan de coaching 30 / 60 / 90 días
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <template x-for="bucket in [['30_days','30 días','from-indigo-400 to-violet-500'],['60_days','60 días','from-violet-400 to-fuchsia-500'],['90_days','90 días','from-fuchsia-400 to-rose-500']]" :key="bucket[0]">
                                <div>
                                    <div class="rounded-lg px-3 py-1.5 text-white text-xs font-bold uppercase tracking-widest inline-block mb-2 bg-gradient-to-r" :class="bucket[2]" x-text="bucket[1]"></div>
                                    <ul class="space-y-2">
                                        <template x-for="(item, i) in (claudeCoaching?.coaching_plan?.[bucket[0]] || [])" :key="i">
                                            <li class="bg-gray-50 rounded-lg p-3">
                                                <p class="text-gray-800 text-sm font-medium" x-text="item.action"></p>
                                                <div class="mt-1 flex items-center gap-2 text-xs">
                                                    <span class="bg-white border border-gray-200 text-gray-600 px-2 py-0.5 rounded" x-show="item.kpi" x-text="item.kpi"></span>
                                                    <span class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-2 py-0.5 rounded" x-show="item.target" x-text="'Meta: ' + item.target"></span>
                                                </div>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Recognition & Next 1:1 -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-gradient-to-br from-indigo-500 to-violet-600 text-white rounded-xl p-4 shadow">
                            <p class="text-xs uppercase tracking-widest opacity-90"><i class="fas fa-award mr-1"></i> Mensaje de reconocimiento</p>
                            <p class="mt-2 text-base font-medium leading-relaxed" x-text="claudeCoaching?.recognition_message"></p>
                        </div>
                        <div class="bg-gray-900 text-white rounded-xl p-4 shadow">
                            <p class="text-xs uppercase tracking-widest text-gray-400"><i class="fas fa-comments mr-1"></i> Pauta próxima 1:1</p>
                            <p class="mt-2 text-sm leading-relaxed" x-text="claudeCoaching?.suggested_next_conversation"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </template>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('alpine:init', () => {
    let chatDistChart = null;
    let campaignChart = null;
    let hourlyVolumeChart = null;
    let weeklyVolumeChart = null;
    
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
            weekly_volume: [],
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
        
        // AI (Claude)
        showAIPanel: false,
        aiLoading: false,
        aiResults: null,
        claudeLoading: false,
        claudeError: null,
        claudeNeedsConfig: false,
        claudeActiveAction: null,
        claudeLoadingLabel: '',
        claudeElapsed: 0,
        claudeTimer: null,
        claudeReport: null,
        claudeDiagnosis: null,
        claudeRadar: null,
        claudeForecast: null,
        claudeOptimizer: null,
        claudeModel: '',
        claudeUsage: null,
        claudeCoaching: null,
        claudeCoachingAgent: null,
        claudeCoachingRanking: null,
        claudeCoachingLoading: false,
        claudeCoachingError: null,

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
                this.setupWeeklyVolumeChart();
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
        
        setupWeeklyVolumeChart() {
            const ctx = document.getElementById('weeklyVolumeChart');
            if (!ctx) return;
            
            if (weeklyVolumeChart) weeklyVolumeChart.destroy();
            weeklyVolumeChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
                    datasets: [
                        {
                            label: 'Abiertos (Promedio)',
                            data: [],
                            backgroundColor: 'rgba(249, 115, 22, 0.7)',
                            borderRadius: 4
                        },
                        {
                            label: 'Cerrados (Promedio)',
                            data: [],
                            backgroundColor: 'rgba(34, 197, 94, 0.7)',
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { position: 'top' },
                        title: { display: false },
                        tooltip: {
                            callbacks: {
                                afterBody: function(context) {
                                    return 'Útil para planificar staffing';
                                }
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Promedio de Conversaciones' } },
                        x: { title: { display: true, text: 'Día de la Semana' } }
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
                    
                    // Update weekly volume chart (por día de semana para staffing)
                    if (weeklyVolumeChart && this.realtimeMetrics.weekly_volume && this.realtimeMetrics.weekly_volume.length > 0) {
                        const weeklyData = this.realtimeMetrics.weekly_volume;
                        weeklyVolumeChart.data.labels = weeklyData.map(d => d.day_short);
                        weeklyVolumeChart.data.datasets[0].data = weeklyData.map(d => d.avg_open || 0);
                        weeklyVolumeChart.data.datasets[1].data = weeklyData.map(d => d.avg_closed || 0);
                        weeklyVolumeChart.update();
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
        
        openClaudePanel() {
            this.showAIPanel = true;
            // Auto-run executive report if nothing loaded yet
            if (!this.claudeActiveAction && !this.claudeLoading) {
                this.runClaudeAction('executive_report');
            }
        },

        resetClaudeResults() {
            this.claudeReport = null;
            this.claudeDiagnosis = null;
            this.claudeRadar = null;
            this.claudeForecast = null;
            this.claudeOptimizer = null;
            this.claudeError = null;
            this.claudeNeedsConfig = false;
        },

        async runClaudeAction(action) {
            this.claudeActiveAction = action;
            this.resetClaudeResults();
            this.claudeLoading = true;
            const labels = {
                executive_report: 'Construyendo reporte ejecutivo integral...',
                operations_diagnosis: 'Diagnosticando operación y SLAs...',
                risk_radar: 'Escaneando señales de riesgo...',
                staffing_forecast: 'Proyectando demanda y staffing...',
                campaign_optimizer: 'Optimizando mezcla de campañas...'
            };
            this.claudeLoadingLabel = labels[action] || 'Procesando...';
            this.claudeElapsed = 0;
            if (this.claudeTimer) clearInterval(this.claudeTimer);
            this.claudeTimer = setInterval(() => { this.claudeElapsed++; }, 1000);

            try {
                const url = `api/claude_insights.php?action=${encodeURIComponent(action)}&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`;
                const res = await fetch(url);
                const data = await res.json();

                if (data.needs_configuration) {
                    this.claudeNeedsConfig = true;
                    return;
                }
                if (!data.success) {
                    this.claudeError = data.error || 'No se pudo generar el análisis';
                    return;
                }

                this.claudeModel = data.model || '';
                this.claudeUsage = data.usage || null;

                switch (action) {
                    case 'executive_report':
                        this.claudeReport = data.report;
                        if (!data.report) this.claudeError = 'Claude respondió pero no fue JSON válido. Intenta de nuevo.';
                        break;
                    case 'operations_diagnosis':
                        this.claudeDiagnosis = data.diagnosis;
                        if (!data.diagnosis) this.claudeError = 'Respuesta no estructurada. Reintenta.';
                        break;
                    case 'risk_radar':
                        this.claudeRadar = data.radar;
                        if (!data.radar) this.claudeError = 'Respuesta no estructurada. Reintenta.';
                        break;
                    case 'staffing_forecast':
                        this.claudeForecast = data.forecast;
                        if (!data.forecast) this.claudeError = 'Respuesta no estructurada. Reintenta.';
                        break;
                    case 'campaign_optimizer':
                        this.claudeOptimizer = data.optimizer;
                        if (!data.optimizer) this.claudeError = 'Respuesta no estructurada. Reintenta.';
                        break;
                }
            } catch (e) {
                this.claudeError = e.message || 'Error de red';
            } finally {
                this.claudeLoading = false;
                if (this.claudeTimer) { clearInterval(this.claudeTimer); this.claudeTimer = null; }
            }
        },

        copyClaudeReport() {
            const payload = {
                generated_at: new Date().toISOString(),
                period: { start: this.filters.startDate, end: this.filters.endDate },
                model: this.claudeModel,
                action: this.claudeActiveAction,
                report: this.claudeReport,
                diagnosis: this.claudeDiagnosis,
                radar: this.claudeRadar,
                forecast: this.claudeForecast,
                optimizer: this.claudeOptimizer
            };
            const text = JSON.stringify(payload, null, 2);
            if (navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
        },

        async analyzeAgent(agent) {
            this.selectedAgent = agent;
            this.showAgentModal = true;
            this.agentAnalysisLoading = true;
            this.agentAnalysis = null;
            this.claudeCoaching = null;
            this.claudeCoachingAgent = null;
            this.claudeCoachingRanking = null;
            this.claudeCoachingError = null;

            try {
                const url = `api/claude_insights.php?action=agent_coaching&agent_id=${agent.id}&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`;
                const res = await fetch(url);
                const data = await res.json();

                if (data.needs_configuration) {
                    this.claudeCoachingError = 'Claude no está configurado. Agrega la clave en Ajustes → API de IA Global.';
                    return;
                }
                if (!data.success) {
                    this.claudeCoachingError = data.error || 'No se pudo generar el coaching';
                    return;
                }

                this.claudeCoaching = data.coaching;
                this.claudeCoachingAgent = data.agent;
                this.claudeCoachingRanking = data.team_ranking;
                // Mantener compat con modal existente
                this.agentAnalysis = {
                    analysis: {
                        diagnosis: data.coaching?.narrative || '',
                        strengths: data.coaching?.strengths || [],
                        improvements: data.coaching?.gaps || [],
                        relocation: { recommended: false }
                    }
                };
            } catch (e) {
                this.claudeCoachingError = e.message;
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
