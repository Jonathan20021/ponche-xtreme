<?php
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/authorization_functions.php';
require_once __DIR__ . '/lib/voice_ai_client.php';

ensurePermission('voice_ai_reports');

$configStatus = voiceAiGetConfigStatus($pdo);
$canManageConfig = userHasPermission('settings');
$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-d');

require_once __DIR__ . '/header.php';
?>

<style>
    [x-cloak] { display: none !important; }

    /* =========================================================
       Voice AI Reports — Design System (unified visual tokens)
       ========================================================= */
    .va-shell {
        --va-bg:           rgba(2, 6, 23, 0.55);
        --va-card:         rgba(15, 23, 42, 0.72);
        --va-card-elev:    rgba(15, 23, 42, 0.88);
        --va-border:       rgba(51, 65, 85, 0.65);
        --va-border-soft:  rgba(30, 41, 59, 0.85);
        --va-accent:       #22d3ee;
        --va-accent-soft:  rgba(34, 211, 238, 0.16);
        --va-text:         #e2e8f0;
        --va-muted:        #94a3b8;
    }
    .va-card {
        background: var(--va-card);
        border: 1px solid var(--va-border);
        border-radius: 1rem;
        backdrop-filter: blur(8px);
        transition: border-color .2s ease;
    }
    .va-card:hover { border-color: rgba(34, 211, 238, 0.35); }
    .va-card-flat {
        background: var(--va-card);
        border: 1px solid var(--va-border-soft);
        border-radius: 1rem;
    }
    .va-kpi {
        background: linear-gradient(135deg, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.6));
        border: 1px solid var(--va-border-soft);
        border-radius: 1rem;
        padding: 1.25rem;
        position: relative;
        overflow: hidden;
    }
    .va-kpi::before {
        content: ''; position: absolute; inset: 0;
        background: radial-gradient(80% 100% at 100% 0%, var(--va-accent-soft), transparent 55%);
        opacity: 0.55; pointer-events: none;
    }
    .va-chip {
        display: inline-flex; align-items: center; gap: 0.5rem;
        padding: 0.4rem 0.85rem; border-radius: 9999px;
        background: rgba(15, 23, 42, 0.8); border: 1px solid var(--va-border);
        color: var(--va-muted); font-size: 0.75rem; font-weight: 500;
    }
    .va-chip-active {
        background: var(--va-accent); color: #0f172a; border-color: var(--va-accent);
    }
    .va-section-title {
        display: flex; align-items: center; gap: 0.75rem;
        font-weight: 600; color: #f8fafc; font-size: 1rem;
    }
    .va-section-title::before {
        content: ''; width: 3px; height: 1.25rem; border-radius: 9999px;
        background: linear-gradient(180deg, #22d3ee, #0ea5e9);
    }
    .va-table thead th {
        font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em;
        color: var(--va-muted); padding: 0.75rem 1rem;
        background: rgba(2, 6, 23, 0.6); border-bottom: 1px solid var(--va-border-soft);
    }
    .va-table tbody td { padding: 0.75rem 1rem; font-size: 0.875rem; color: var(--va-text); }
    .va-table tbody tr { border-bottom: 1px solid rgba(30, 41, 59, 0.6); }
    .va-table tbody tr:hover { background: rgba(30, 41, 59, 0.35); }

    /* Tab rails for the mega section */
    .va-tab-rail { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .va-tab {
        display: inline-flex; align-items: center; gap: 0.5rem;
        padding: 0.55rem 1rem; border-radius: 9999px; font-size: 0.8125rem; font-weight: 600;
        border: 1px solid var(--va-border); background: rgba(2, 6, 23, 0.6);
        color: var(--va-text); cursor: pointer; transition: all .15s ease;
    }
    .va-tab:hover { border-color: rgba(34, 211, 238, 0.5); color: #f8fafc; }
    .va-tab.is-active { background: var(--va-accent); color: #0f172a; border-color: var(--va-accent); }
    .va-tab-group-sep { width: 1px; align-self: stretch; background: var(--va-border-soft); margin: 0 0.25rem; }

    /* Filter bar */
    .va-filter-grid { display: grid; gap: 1rem; grid-template-columns: repeat(1, 1fr); }
    @media (min-width: 768px) { .va-filter-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (min-width: 1280px) { .va-filter-grid { grid-template-columns: repeat(9, 1fr); } }

    .va-input, .va-select {
        width: 100%;
        background: rgba(2, 6, 23, 0.85);
        border: 1px solid var(--va-border);
        border-radius: 0.75rem; padding: 0.65rem 0.9rem;
        color: var(--va-text); font-size: 0.875rem;
        transition: border-color .15s ease;
    }
    .va-input:focus, .va-select:focus { outline: none; border-color: var(--va-accent); }

    .va-btn-primary {
        background: var(--va-accent); color: #0f172a; font-weight: 600;
        padding: 0.65rem 1.1rem; border-radius: 0.75rem; font-size: 0.875rem;
        transition: all .15s ease;
    }
    .va-btn-primary:hover:not(:disabled) { background: #67e8f9; }
    .va-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

    .va-btn-ghost {
        background: transparent; color: var(--va-text);
        border: 1px solid var(--va-border);
        padding: 0.65rem 1.1rem; border-radius: 0.75rem; font-size: 0.875rem;
    }
    .va-btn-ghost:hover { border-color: var(--va-accent); color: #f8fafc; }
</style>

<div class="va-shell max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" x-data="voiceAiReports()">
    <!-- Professional hero header -->
    <header class="mb-6 rounded-2xl border border-slate-800 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 overflow-hidden relative">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_0%_0%,_rgba(34,211,238,0.12),_transparent_42%),radial-gradient(circle_at_100%_100%,_rgba(99,102,241,0.1),_transparent_40%)] pointer-events-none"></div>
        <div class="relative px-6 md:px-8 py-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-4">
                <div class="h-12 w-12 rounded-2xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center">
                    <i class="fas fa-satellite-dish text-cyan-300 text-xl"></i>
                </div>
                <div>
                    <p class="text-cyan-300/80 text-[10px] uppercase tracking-[0.35em] font-semibold">GoHighLevel · Comms Intelligence</p>
                    <h1 class="text-xl md:text-2xl font-bold text-white mt-1">Reportería integral · llamadas, mensajes &amp; disposiciones</h1>
                    <p class="text-slate-400 text-xs md:text-sm mt-1">
                        Conversations + Phone System + Voice AI + Pipeline unificados con capa IA opcional.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="va-chip"
                    :class="configStatus.is_ready ? '!text-emerald-300 !border-emerald-500/40 !bg-emerald-500/10' : '!text-amber-300 !border-amber-500/40 !bg-amber-500/10'">
                    <i class="fas" :class="configStatus.is_ready ? 'fa-circle-check' : 'fa-triangle-exclamation'"></i>
                    <span x-text="configStatus.is_ready ? 'Integración lista' : 'Configuración pendiente'"></span>
                </span>
                <span x-show="configStatus.integration_name" class="va-chip" style="display:none;">
                    <i class="fas fa-building"></i>
                    <span x-text="configStatus.integration_name"></span>
                </span>
                <button @click="exportCsv" type="button" class="va-btn-primary">
                    <i class="fas fa-file-csv mr-2"></i>Exportar CSV
                </button>
            </div>
        </div>
    </header>

    <!-- Location Selector -->
    <div x-show="(configStatus.integrations || []).length > 1" style="display:none;" class="mb-8 rounded-2xl border border-cyan-500/20 bg-slate-950/50 p-5 backdrop-blur-sm">
        <div class="flex items-center gap-3 mb-4">
            <i class="fas fa-map-pin text-cyan-400"></i>
            <h3 class="text-sm font-semibold text-slate-200">Selecciona una ubicación</h3>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
            <template x-for="integration in configStatus.integrations || []" :key="integration.integration_id">
                <button
                    @click="changeLocation(String(integration.integration_id))"
                    :class="filters.integration_id === String(integration.integration_id) 
                        ? 'bg-cyan-500 text-slate-950 border-cyan-400' 
                        : 'bg-slate-900 text-slate-300 border-slate-700 hover:border-cyan-500/50'"
                    class="px-4 py-3 rounded-xl border transition-all text-left">
                    <div class="font-medium text-sm" x-text="integration.integration_name"></div>
                    <div class="text-xs opacity-75 mt-1" x-text="integration.location_id"></div>
                    <div class="text-xs mt-1" :class="integration.is_default ? 'text-cyan-300' : 'opacity-50'" x-show="integration.is_default">
                        <i class="fas fa-star mr-1"></i>Por defecto
                    </div>
                </button>
            </template>
        </div>
    </div>

    <div x-show="!configStatus.is_ready" class="mb-8 rounded-2xl border border-amber-500/25 bg-amber-500/10 p-5 text-amber-100" style="display:none;">
        <div class="flex items-start gap-3">
            <i class="fas fa-triangle-exclamation mt-1"></i>
            <div>
                <p class="font-semibold">Falta completar la integracion.</p>
                <p class="text-sm text-amber-100/80 mt-1">
                    La API de Voice AI exige `Version: 2021-07-28` y `locationId` en la consulta. La PIT ya quedo guardada, pero necesitas completar el Location ID para consumir los reportes.
                    Puedes encontrarlo en GoHighLevel: Settings > Business Profile > Location ID.
                </p>
            </div>
        </div>
    </div>

    <?php if ($canManageConfig): ?>
        <section class="mb-8 rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <div class="flex items-center justify-between gap-4 mb-5">
                <div>
                    <h2 class="text-xl font-semibold text-white">Configuracion multicuenta</h2>
                    <p class="text-sm text-slate-400 mt-1">Administra varias cuentas/location IDs de GoHighLevel y elige cual queda por defecto.</p>
                </div>
                <span class="text-xs text-slate-400">
                    Cuenta activa: <?= htmlspecialchars(($configStatus['integration_name'] ?? 'Sin cuenta')) ?> |
                    Token actual: <?= htmlspecialchars($configStatus['token_masked'] ?: 'no configurado') ?>
                </span>
            </div>

            <form @submit.prevent="saveConfig" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-8 gap-4">
                <div class="xl:col-span-2">
                    <label class="block text-sm font-medium text-slate-300 mb-2">Cuenta / campana</label>
                    <select x-model="configForm.integration_id" @change="loadConfigIntegration(configForm.integration_id)" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                        <option value="">Nueva cuenta</option>
                        <template x-for="integration in configStatus.integrations || []" :key="integration.integration_id">
                            <option :value="String(integration.integration_id)" x-text="`${integration.integration_name} (${integration.location_id})`"></option>
                        </template>
                    </select>
                </div>
                <div class="xl:col-span-2">
                    <label class="block text-sm font-medium text-slate-300 mb-2">Nombre visible</label>
                    <input x-model="configForm.integration_name" type="text" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3"
                        placeholder="Evallish BPO LLC (MCA SERVICE)">
                </div>
                <div class="xl:col-span-2">
                    <label class="block text-sm font-medium text-slate-300 mb-2">Private Integration Token</label>
                    <input x-model="configForm.api_key" type="password" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3"
                        placeholder="Dejar vacio para conservar la PIT actual">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Location ID</label>
                    <input x-model="configForm.location_id" type="text" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3"
                        placeholder="ve9EPM428h8vShlRW1KT">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Timezone</label>
                    <input x-model="configForm.timezone" type="text" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3"
                        placeholder="America/La_Paz">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Page size</label>
                    <input x-model.number="configForm.page_size" type="number" min="10" max="50"
                        class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Max paginas</label>
                    <input x-model.number="configForm.max_pages" type="number" min="1" max="50"
                        class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Pag. interacciones</label>
                    <input x-model.number="configForm.interaction_page_size" type="number" min="10" max="100"
                        class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Max interacciones</label>
                    <input x-model.number="configForm.interaction_max_pages" type="number" min="1" max="250"
                        class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                </div>
                <div class="xl:col-span-2 flex items-center gap-3 pt-7">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-300">
                        <input x-model="configForm.set_as_default" type="checkbox" class="rounded border-slate-700 bg-slate-950 text-cyan-400">
                        Marcar como cuenta por defecto
                    </label>
                </div>
                <div class="md:col-span-2 xl:col-span-8 flex items-center justify-between gap-3">
                    <button @click="startNewIntegration" type="button" class="px-4 py-3 rounded-xl border border-slate-700 text-slate-200 hover:bg-slate-800 transition-colors">
                        Nueva cuenta
                    </button>
                    <button type="submit" :disabled="savingConfig"
                        class="px-5 py-3 rounded-xl bg-emerald-500 hover:bg-emerald-400 disabled:opacity-60 text-slate-950 font-semibold transition-colors">
                        <i class="fas mr-2" :class="savingConfig ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                        Guardar cuenta
                    </button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="mb-8 rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-5">
            <div>
                <h2 class="text-xl font-semibold text-white">Filtros de reporteria</h2>
                <p class="text-sm text-slate-400 mt-1">Puedes consultar historicos completos por rango sobre llamadas, SMS, email y actividad por usuario. En interacciones, el rango se manda directo a la API oficial.</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs text-slate-400">
                <span class="px-3 py-1 rounded-full bg-slate-800 border border-slate-700">Paginas: <span x-text="meta.pages_fetched || 0"></span></span>
                <span class="px-3 py-1 rounded-full bg-slate-800 border border-slate-700">Interacciones leidas: <span x-text="meta.fetched_count || 0"></span></span>
                <span class="px-3 py-1 rounded-full bg-slate-800 border border-slate-700">Interacciones filtradas: <span x-text="meta.filtered_count || 0"></span></span>
                <span x-show="meta.truncated" style="display:none;" class="px-3 py-1 rounded-full bg-amber-500/15 border border-amber-400/30 text-amber-200">Muestra parcial por limite de paginas</span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-9 gap-4 mt-6">
            <div class="xl:col-span-2">
                <label class="block text-sm font-medium text-slate-300 mb-2">Cuenta</label>
                <select x-model="filters.integration_id" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                    <template x-for="integration in configStatus.integrations || []" :key="integration.integration_id">
                        <option :value="String(integration.integration_id)" x-text="`${integration.integration_name} (${integration.location_id})`"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Desde</label>
                <input x-model="filters.start_date" type="date" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Hasta</label>
                <input x-model="filters.end_date" type="date" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Canal</label>
                <select x-model="filters.interaction_channel" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                    <option value="">Todos</option>
                    <template x-for="type in availableFilters.interaction_channels" :key="type">
                        <option :value="type" x-text="type"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Direccion</label>
                <select x-model="filters.direction" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                    <option value="">Todas</option>
                    <template x-for="action in availableFilters.interaction_directions" :key="action">
                        <option :value="action" x-text="action"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Estado</label>
                <select x-model="filters.status" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                    <option value="">Todos</option>
                    <template x-for="status in availableFilters.interaction_statuses" :key="status">
                        <option :value="status" x-text="status"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Disposicion</label>
                <select x-model="filters.disposition" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                    <option value="">Todas</option>
                    <template x-for="disposition in availableFilters.interaction_dispositions" :key="disposition">
                        <option :value="disposition" x-text="disposition"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Usuario</label>
                <select x-model="filters.user_id" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                    <option value="">Todos</option>
                    <template x-for="user in availableFilters.interaction_users" :key="user.id">
                        <option :value="user.id" x-text="user.name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Origen</label>
                <select x-model="filters.source" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                    <option value="">Todos</option>
                    <template x-for="source in availableFilters.interaction_sources" :key="source">
                        <option :value="source" x-text="source"></option>
                    </template>
                </select>
            </div>
            <div class="xl:col-span-2">
                <label class="block text-sm font-medium text-slate-300 mb-2">Busqueda</label>
                <input x-model="filters.search" type="text" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3"
                    placeholder="contacto, telefono, usuario, cuerpo, estado, canal">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Modo de carga</label>
                <select x-model="filters.fast_mode" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                    <option value="1">Rapido</option>
                    <option value="0">Completo</option>
                </select>
            </div>
            <div class="flex items-end">
                <label class="inline-flex items-center gap-2 text-sm text-slate-300 pb-2">
                    <input x-model="filters.with_comparison" type="checkbox" :disabled="filters.fast_mode === '1'" class="rounded border-slate-700 bg-slate-950 text-cyan-400 disabled:opacity-50">
                    Comparar periodo anterior
                </label>
            </div>
        </div>

        <div class="mt-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <p class="text-sm text-slate-400">Las horas de uso por usuario se calculan como actividad operacional estimada desde eventos reales de la API, no como login del navegador.</p>
            <div class="flex flex-wrap gap-3">
                <button @click="openApiHealthModal" type="button" class="px-4 py-3 rounded-xl border border-cyan-500/50 text-cyan-200 hover:bg-cyan-500/10 transition-colors">
                    <i class="fas fa-heart-pulse mr-2"></i>Estado API
                </button>
                <button @click="resetFilters" type="button" class="px-4 py-3 rounded-xl border border-slate-700 text-slate-200 hover:bg-slate-800 transition-colors">Resetear</button>
                <button @click="fetchDashboard" type="button" :disabled="isLoading || !configStatus.is_ready"
                    class="px-5 py-3 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold transition-colors">
                    <i class="fas mr-2" :class="isLoading ? 'fa-spinner fa-spin' : 'fa-rotate-right'"></i>
                    Actualizar dashboard
                </button>
            </div>
        </div>

        <div x-show="isLoading" class="mt-4 rounded-2xl border border-cyan-500/20 bg-slate-950/60 px-4 py-4" style="display:none;">
            <div class="flex items-center justify-between gap-4 text-sm">
                <div class="flex items-center gap-3 text-cyan-200">
                    <div class="h-5 w-5 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                    <span x-text="loadingStage || 'Consultando API...'">Consultando API...</span>
                </div>
                <div class="text-slate-400">Modo: <span class="text-slate-200" x-text="filters.fast_mode === '1' ? 'Rapido' : 'Completo'"></span></div>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-800">
                <div class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-sky-400 to-emerald-400 transition-all duration-500"
                    :style="`width: ${loadingStageProgress}%`"></div>
            </div>
        </div>
    </section>

    <div x-show="error" class="mb-8 rounded-2xl border border-rose-500/30 bg-rose-500/10 px-5 py-4 text-rose-100" style="display:none;">
        <i class="fas fa-circle-exclamation mr-2"></i><span x-text="error"></span>
    </div>

    <div x-show="notice" class="mb-8 rounded-2xl border border-cyan-500/25 bg-cyan-500/10 px-5 py-4 text-cyan-100" style="display:none;">
        <i class="fas fa-circle-info mr-2"></i><span x-text="notice"></span>
    </div>

    <div x-show="lastLoadedAt" class="mb-8 text-xs text-slate-400 flex items-center justify-end gap-2" style="display:none;">
        <i class="fas fa-clock"></i>
        <span>Ultima carga:</span>
        <span x-text="formatDate(lastLoadedAt)"></span>
        <span class="text-slate-500">|</span>
        <span x-text="cacheHit ? 'resultado desde cache' : 'resultado en vivo API'"></span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 mb-8">
        <template x-for="kpi in kpiList()" :key="kpi.label">
            <article class="rounded-2xl border border-slate-700/70 bg-slate-900/70 p-5 backdrop-blur-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-slate-400" x-text="kpi.label"></p>
                        <h3 class="text-3xl font-bold text-white mt-2" x-text="kpi.formatted"></h3>
                    </div>
                    <div class="h-11 w-11 rounded-2xl flex items-center justify-center"
                        :class="iconWrapClass(kpi.color)">
                        <i class="fas text-lg" :class="kpi.icon"></i>
                    </div>
                </div>
                <p class="text-xs mt-4" :class="deltaClass(kpi.comparison)">
                    <span x-text="formatDelta(kpi.comparison)"></span>
                </p>
            </article>
        </template>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <h2 class="text-lg font-semibold text-white mb-4">Interacciones por dia</h2>
            <div class="relative h-80">
                <div x-show="isLoading" style="display:none;" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/50 rounded-xl">
                    <div class="h-9 w-9 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>
                <canvas id="voiceAiTimelineChart"></canvas>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <h2 class="text-lg font-semibold text-white mb-4">Distribucion por canal</h2>
            <div class="relative h-80">
                <div x-show="isLoading" style="display:none;" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/50 rounded-xl">
                    <div class="h-9 w-9 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>
                <canvas id="voiceAiTypeChart"></canvas>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <h2 class="text-lg font-semibold text-white mb-4">Estados de interaccion</h2>
            <div class="relative h-80">
                <div x-show="isLoading" style="display:none;" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/50 rounded-xl">
                    <div class="h-9 w-9 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>
                <canvas id="voiceAiStatusChart"></canvas>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <h2 class="text-lg font-semibold text-white mb-4">Origen operativo</h2>
            <div class="relative h-80">
                <div x-show="isLoading" style="display:none;" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/50 rounded-xl">
                    <div class="h-9 w-9 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>
                <canvas id="voiceAiActionChart"></canvas>
            </div>
        </section>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700/70">
                <h2 class="text-lg font-semibold text-white">Disposiciones de llamadas</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Disposicion</th>
                            <th class="px-6 py-4 text-right">Total</th>
                            <th class="px-6 py-4 text-right">Inbound</th>
                            <th class="px-6 py-4 text-right">Outbound</th>
                            <th class="px-6 py-4 text-right">Duracion promedio</th>
                            <th class="px-6 py-4 text-right">Grabadas</th>
                            <th class="px-6 py-4 text-right">Usuarios</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="row in dashboard.call_dispositions" :key="row.disposition">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4 font-medium text-white" x-text="row.disposition"></td>
                                <td class="px-6 py-4 text-right" x-text="row.total"></td>
                                <td class="px-6 py-4 text-right" x-text="row.inbound"></td>
                                <td class="px-6 py-4 text-right" x-text="row.outbound"></td>
                                <td class="px-6 py-4 text-right" x-text="formatDuration(row.avg_duration_seconds)"></td>
                                <td class="px-6 py-4 text-right" x-text="row.recorded_calls"></td>
                                <td class="px-6 py-4 text-right" x-text="row.users"></td>
                            </tr>
                        </template>
                        <tr x-show="dashboard.call_dispositions.length === 0 && !isLoading" style="display:none;">
                            <td colspan="7" class="px-6 py-8 text-center text-slate-500">No hay disposiciones disponibles para este rango.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700/70">
                <h2 class="text-lg font-semibold text-white">Disposicion principal por usuario</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Usuario</th>
                            <th class="px-6 py-4 text-right">Llamadas</th>
                            <th class="px-6 py-4 text-left">Disposicion top</th>
                            <th class="px-6 py-4 text-right">Total top</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="row in dashboard.disposition_by_user" :key="row.user_id || row.user_name">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4 font-medium text-white" x-text="row.user_name"></td>
                                <td class="px-6 py-4 text-right" x-text="row.total_calls"></td>
                                <td class="px-6 py-4" x-text="row.top_disposition"></td>
                                <td class="px-6 py-4 text-right" x-text="row.top_disposition_calls"></td>
                            </tr>
                        </template>
                        <tr x-show="dashboard.disposition_by_user.length === 0 && !isLoading" style="display:none;">
                            <td colspan="4" class="px-6 py-8 text-center text-slate-500">Sin datos de disposicion por usuario en este rango.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <h2 class="text-lg font-semibold text-white mb-4">Llamadas inbound vs outbound</h2>
            <div class="relative h-72">
                <div x-show="isLoading" style="display:none;" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/50 rounded-xl">
                    <div class="h-9 w-9 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>
                <canvas id="voiceAiCallDirectionChart"></canvas>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <h2 class="text-lg font-semibold text-white mb-4">Mensajes inbound vs outbound</h2>
            <div class="relative h-72">
                <div x-show="isLoading" style="display:none;" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/50 rounded-xl">
                    <div class="h-9 w-9 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>
                <canvas id="voiceAiMessageDirectionChart"></canvas>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <h2 class="text-lg font-semibold text-white mb-4">Volumen por dia de semana</h2>
            <div class="relative h-72">
                <div x-show="isLoading" style="display:none;" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/50 rounded-xl">
                    <div class="h-9 w-9 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>
                <canvas id="voiceAiWeekdayChart"></canvas>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <h2 class="text-lg font-semibold text-white mb-4">Picos horarios de llamadas</h2>
            <div class="relative h-72">
                <div x-show="isLoading" style="display:none;" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/50 rounded-xl">
                    <div class="h-9 w-9 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>
                <canvas id="voiceAiHourDirectionChart"></canvas>
            </div>
        </section>
        <section class="xl:col-span-2 rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <h2 class="text-lg font-semibold text-white mb-4">Direccion por canal</h2>
            <div class="relative h-80">
                <div x-show="isLoading" style="display:none;" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/50 rounded-xl">
                    <div class="h-9 w-9 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>
                <canvas id="voiceAiChannelDirectionChart"></canvas>
            </div>
        </section>
        <section class="xl:col-span-2 rounded-2xl border border-slate-700/70 bg-slate-900/70 p-6 backdrop-blur-sm">
            <h2 class="text-lg font-semibold text-white mb-4">Resultados por disposicion</h2>
            <div class="relative h-80">
                <div x-show="isLoading" style="display:none;" class="absolute inset-0 z-10 flex items-center justify-center bg-slate-950/50 rounded-xl">
                    <div class="h-9 w-9 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>
                <canvas id="voiceAiDispositionChart"></canvas>
            </div>
        </section>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
        <section x-show="dashboard.agents_catalog && dashboard.agents_catalog.length > 0" class="xl:col-span-2 rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden" style="display:none;">
            <div class="px-6 py-5 border-b border-slate-700/70 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-white">Agentes Voice AI configurados</h2>
                    <p class="text-sm text-slate-400 mt-1">Datos traidos directamente del endpoint de agentes para este location.</p>
                </div>
                <span class="text-xs text-slate-400">Total: <span x-text="dashboard.agents_catalog.length"></span></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Agente</th>
                            <th class="px-6 py-4 text-left">Negocio</th>
                            <th class="px-6 py-4 text-left">Idioma</th>
                            <th class="px-6 py-4 text-left">Timezone</th>
                            <th class="px-6 py-4 text-right">Duracion max.</th>
                            <th class="px-6 py-4 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="agent in paginatedItems('agents_catalog', dashboard.agents_catalog)" :key="agent.id">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white" x-text="agent.agent_name"></div>
                                    <div class="text-xs text-slate-400" x-text="agent.id"></div>
                                </td>
                                <td class="px-6 py-4" x-text="agent.business_name || '--'"></td>
                                <td class="px-6 py-4" x-text="agent.language || '--'"></td>
                                <td class="px-6 py-4" x-text="agent.timezone || '--'"></td>
                                <td class="px-6 py-4 text-right" x-text="formatDuration(agent.max_call_duration)"></td>
                                <td class="px-6 py-4 text-right" x-text="agent.actions_count"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-800 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="text-xs text-slate-400">
                    Mostrando <span x-text="tableRangeStart('agents_catalog', dashboard.agents_catalog)"></span>-<span x-text="tableRangeEnd('agents_catalog', dashboard.agents_catalog)"></span>
                    de <span x-text="dashboard.agents_catalog.length"></span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs text-slate-400">
                        Filas
                        <select @change="setTablePageSize('agents_catalog', $event.target.value)" :value="ensureTablePagination('agents_catalog').size" class="ml-2 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-slate-200">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                        </select>
                    </label>
                    <div class="text-xs text-slate-400">
                        Pagina <span x-text="ensureTablePagination('agents_catalog').page"></span> de <span x-text="tableTotalPages('agents_catalog', dashboard.agents_catalog)"></span>
                    </div>
                    <button @click="prevTablePage('agents_catalog', dashboard.agents_catalog)" :disabled="ensureTablePagination('agents_catalog').page <= 1" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Anterior</button>
                    <button @click="nextTablePage('agents_catalog', dashboard.agents_catalog)" :disabled="ensureTablePagination('agents_catalog').page >= tableTotalPages('agents_catalog', dashboard.agents_catalog)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Siguiente</button>
                </div>
            </div>
        </section>

        <section x-show="dashboard.conversations_snapshot && dashboard.conversations_snapshot.length > 0" class="xl:col-span-2 rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden" style="display:none;">
            <div class="px-6 py-5 border-b border-slate-700/70 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-white">Conversaciones recientes</h2>
                    <p class="text-sm text-slate-400 mt-1">Snapshot actual de la bandeja de conversaciones del location.</p>
                </div>
                <span class="text-xs text-slate-400">Total API: <span x-text="meta.conversations_total || 0"></span></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Contacto</th>
                            <th class="px-6 py-4 text-left">Ultimo mensaje</th>
                            <th class="px-6 py-4 text-left">Direccion</th>
                            <th class="px-6 py-4 text-right">No leidos</th>
                            <th class="px-6 py-4 text-left">Telefono</th>
                            <th class="px-6 py-4 text-left">Fecha</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="conversation in paginatedItems('conversations_snapshot', dashboard.conversations_snapshot)" :key="conversation.id">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white" x-text="conversation.contact_name || conversation.full_name"></div>
                                    <div class="text-xs text-slate-400" x-text="conversation.company_name || conversation.email || ''"></div>
                                </td>
                                <td class="px-6 py-4 max-w-[360px]">
                                    <div class="truncate" x-text="conversation.last_message_body || '--'"></div>
                                    <div class="text-xs text-slate-400" x-text="conversation.last_message_type || ''"></div>
                                </td>
                                <td class="px-6 py-4" x-text="conversation.last_message_direction || '--'"></td>
                                <td class="px-6 py-4 text-right" x-text="conversation.unread_count"></td>
                                <td class="px-6 py-4" x-text="conversation.phone || '--'"></td>
                                <td class="px-6 py-4" x-text="formatDate(conversation.last_message_date)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-800 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="text-xs text-slate-400">
                    Mostrando <span x-text="tableRangeStart('conversations_snapshot', dashboard.conversations_snapshot)"></span>-<span x-text="tableRangeEnd('conversations_snapshot', dashboard.conversations_snapshot)"></span>
                    de <span x-text="dashboard.conversations_snapshot.length"></span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs text-slate-400">
                        Filas
                        <select @change="setTablePageSize('conversations_snapshot', $event.target.value)" :value="ensureTablePagination('conversations_snapshot').size" class="ml-2 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-slate-200">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                        </select>
                    </label>
                    <div class="text-xs text-slate-400">
                        Pagina <span x-text="ensureTablePagination('conversations_snapshot').page"></span> de <span x-text="tableTotalPages('conversations_snapshot', dashboard.conversations_snapshot)"></span>
                    </div>
                    <button @click="prevTablePage('conversations_snapshot', dashboard.conversations_snapshot)" :disabled="ensureTablePagination('conversations_snapshot').page <= 1" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Anterior</button>
                    <button @click="nextTablePage('conversations_snapshot', dashboard.conversations_snapshot)" :disabled="ensureTablePagination('conversations_snapshot').page >= tableTotalPages('conversations_snapshot', dashboard.conversations_snapshot)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Siguiente</button>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700/70">
                <h2 class="text-lg font-semibold text-white">Cola actual de usuarios activos</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Usuario</th>
                            <th class="px-6 py-4 text-left">Correo</th>
                            <th class="px-6 py-4 text-right">Conversaciones asignadas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="user in paginatedItems('queue_by_user', dashboard.queue_by_user)" :key="user.user_id">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white" x-text="user.user_name"></div>
                                    <div class="text-xs text-slate-400" x-text="user.role || '--'"></div>
                                </td>
                                <td class="px-6 py-4" x-text="user.email || '--'"></td>
                                <td class="px-6 py-4 text-right" x-text="user.assigned_conversations"></td>
                            </tr>
                        </template>
                        <tr x-show="dashboard.queue_by_user.length === 0 && !isLoading" style="display:none;">
                            <td colspan="3" class="px-6 py-8 text-center text-slate-500">No hay asignaciones visibles para este location.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-800 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="text-xs text-slate-400">
                    Mostrando <span x-text="tableRangeStart('queue_by_user', dashboard.queue_by_user)"></span>-<span x-text="tableRangeEnd('queue_by_user', dashboard.queue_by_user)"></span>
                    de <span x-text="dashboard.queue_by_user.length"></span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs text-slate-400">
                        Filas
                        <select @change="setTablePageSize('queue_by_user', $event.target.value)" :value="ensureTablePagination('queue_by_user').size" class="ml-2 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-slate-200">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                        </select>
                    </label>
                    <div class="text-xs text-slate-400">
                        Pagina <span x-text="ensureTablePagination('queue_by_user').page"></span> de <span x-text="tableTotalPages('queue_by_user', dashboard.queue_by_user)"></span>
                    </div>
                    <button @click="prevTablePage('queue_by_user', dashboard.queue_by_user)" :disabled="ensureTablePagination('queue_by_user').page <= 1" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Anterior</button>
                    <button @click="nextTablePage('queue_by_user', dashboard.queue_by_user)" :disabled="ensureTablePagination('queue_by_user').page >= tableTotalPages('queue_by_user', dashboard.queue_by_user)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Siguiente</button>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700/70">
                <h2 class="text-lg font-semibold text-white">Numeros activos del location</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Numero</th>
                            <th class="px-6 py-4 text-left">Alias</th>
                            <th class="px-6 py-4 text-left">Capacidades</th>
                            <th class="px-6 py-4 text-right">Default</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="number in paginatedItems('numbers', dashboard.numbers)" :key="number.sid || number.phone_number">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white" x-text="number.phone_number"></div>
                                    <div class="text-xs text-slate-400" x-text="number.type || '--'"></div>
                                </td>
                                <td class="px-6 py-4" x-text="number.friendly_name || '--'"></td>
                                <td class="px-6 py-4" x-text="formatCapabilities(number)"></td>
                                <td class="px-6 py-4 text-right" x-text="number.is_default ? 'Si' : 'No'"></td>
                            </tr>
                        </template>
                        <tr x-show="dashboard.numbers.length === 0 && !isLoading" style="display:none;">
                            <td colspan="4" class="px-6 py-8 text-center text-slate-500">No se encontraron numeros activos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-800 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="text-xs text-slate-400">
                    Mostrando <span x-text="tableRangeStart('numbers', dashboard.numbers)"></span>-<span x-text="tableRangeEnd('numbers', dashboard.numbers)"></span>
                    de <span x-text="dashboard.numbers.length"></span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs text-slate-400">
                        Filas
                        <select @change="setTablePageSize('numbers', $event.target.value)" :value="ensureTablePagination('numbers').size" class="ml-2 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-slate-200">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                        </select>
                    </label>
                    <div class="text-xs text-slate-400">
                        Pagina <span x-text="ensureTablePagination('numbers').page"></span> de <span x-text="tableTotalPages('numbers', dashboard.numbers)"></span>
                    </div>
                    <button @click="prevTablePage('numbers', dashboard.numbers)" :disabled="ensureTablePagination('numbers').page <= 1" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Anterior</button>
                    <button @click="nextTablePage('numbers', dashboard.numbers)" :disabled="ensureTablePagination('numbers').page >= tableTotalPages('numbers', dashboard.numbers)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Siguiente</button>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700/70">
                <h2 class="text-lg font-semibold text-white">Actividad por usuario</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Usuario</th>
                            <th class="px-6 py-4 text-right">Interacciones</th>
                            <th class="px-6 py-4 text-right">Calls</th>
                            <th class="px-6 py-4 text-right">Mensajes</th>
                            <th class="px-6 py-4 text-right">Horas act. est.</th>
                            <th class="px-6 py-4 text-right">Ventana prom.</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="agent in paginatedItems('agents', dashboard.agents)" :key="agent.user_id || agent.user_name">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white" x-text="agent.user_name"></div>
                                    <div class="text-xs text-slate-400" x-text="agent.last_activity_at ? formatDate(agent.last_activity_at) : 'Sin actividad'"></div>
                                </td>
                                <td class="px-6 py-4 text-right" x-text="agent.interactions"></td>
                                <td class="px-6 py-4 text-right" x-text="agent.calls"></td>
                                <td class="px-6 py-4 text-right" x-text="agent.messages"></td>
                                <td class="px-6 py-4 text-right" x-text="agent.active_hours_estimated"></td>
                                <td class="px-6 py-4 text-right" x-text="formatDuration(agent.avg_daily_window_seconds)"></td>
                            </tr>
                        </template>
                        <tr x-show="dashboard.agents.length === 0 && !isLoading" style="display:none;">
                            <td colspan="6" class="px-6 py-8 text-center text-slate-500">No hay actividad de usuario para el rango seleccionado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-800 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="text-xs text-slate-400">
                    Mostrando <span x-text="tableRangeStart('agents', dashboard.agents)"></span>-<span x-text="tableRangeEnd('agents', dashboard.agents)"></span>
                    de <span x-text="dashboard.agents.length"></span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs text-slate-400">
                        Filas
                        <select @change="setTablePageSize('agents', $event.target.value)" :value="ensureTablePagination('agents').size" class="ml-2 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-slate-200">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                        </select>
                    </label>
                    <div class="text-xs text-slate-400">
                        Pagina <span x-text="ensureTablePagination('agents').page"></span> de <span x-text="tableTotalPages('agents', dashboard.agents)"></span>
                    </div>
                    <button @click="prevTablePage('agents', dashboard.agents)" :disabled="ensureTablePagination('agents').page <= 1" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Anterior</button>
                    <button @click="nextTablePage('agents', dashboard.agents)" :disabled="ensureTablePagination('agents').page >= tableTotalPages('agents', dashboard.agents)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Siguiente</button>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700/70">
                <h2 class="text-lg font-semibold text-white">Top contactos</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Contacto</th>
                            <th class="px-6 py-4 text-right">Interacciones</th>
                            <th class="px-6 py-4 text-right">Calls</th>
                            <th class="px-6 py-4 text-right">Telefono</th>
                            <th class="px-6 py-4 text-right">Asignado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="contact in paginatedItems('contacts', dashboard.contacts)" :key="contact.contact_id || contact.contact_name || contact.contact_phone">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white" x-text="contact.contact_name"></div>
                                    <div class="text-xs text-slate-400" x-text="contact.last_activity_at ? formatDate(contact.last_activity_at) : 'Sin fecha'"></div>
                                </td>
                                <td class="px-6 py-4 text-right" x-text="contact.interactions"></td>
                                <td class="px-6 py-4 text-right" x-text="contact.calls"></td>
                                <td class="px-6 py-4 text-right" x-text="contact.contact_phone || '--'"></td>
                                <td class="px-6 py-4 text-right" x-text="assignedUserName(contact.assigned_to)"></td>
                            </tr>
                        </template>
                        <tr x-show="dashboard.contacts.length === 0 && !isLoading" style="display:none;">
                            <td colspan="5" class="px-6 py-8 text-center text-slate-500">No hay contactos en el conjunto filtrado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-800 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="text-xs text-slate-400">
                    Mostrando <span x-text="tableRangeStart('contacts', dashboard.contacts)"></span>-<span x-text="tableRangeEnd('contacts', dashboard.contacts)"></span>
                    de <span x-text="dashboard.contacts.length"></span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs text-slate-400">
                        Filas
                        <select @change="setTablePageSize('contacts', $event.target.value)" :value="ensureTablePagination('contacts').size" class="ml-2 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-slate-200">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                        </select>
                    </label>
                    <div class="text-xs text-slate-400">
                        Pagina <span x-text="ensureTablePagination('contacts').page"></span> de <span x-text="tableTotalPages('contacts', dashboard.contacts)"></span>
                    </div>
                    <button @click="prevTablePage('contacts', dashboard.contacts)" :disabled="ensureTablePagination('contacts').page <= 1" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Anterior</button>
                    <button @click="nextTablePage('contacts', dashboard.contacts)" :disabled="ensureTablePagination('contacts').page >= tableTotalPages('contacts', dashboard.contacts)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Siguiente</button>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700/70">
                <h2 class="text-lg font-semibold text-white">Numeros con mayor actividad</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Numero</th>
                            <th class="px-6 py-4 text-right">Interacciones</th>
                            <th class="px-6 py-4 text-right">Calls</th>
                            <th class="px-6 py-4 text-right">Mensajes</th>
                            <th class="px-6 py-4 text-right">Inbound</th>
                            <th class="px-6 py-4 text-right">Outbound</th>
                            <th class="px-6 py-4 text-right">Contactos unicos</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="number in paginatedItems('numbers_usage', dashboard.numbers_usage)" :key="number.business_number">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white" x-text="number.business_number"></div>
                                    <div class="text-xs text-slate-400" x-text="number.friendly_name || '--'"></div>
                                </td>
                                <td class="px-6 py-4 text-right" x-text="number.interactions"></td>
                                <td class="px-6 py-4 text-right" x-text="number.calls"></td>
                                <td class="px-6 py-4 text-right" x-text="number.messages"></td>
                                <td class="px-6 py-4 text-right" x-text="number.inbound"></td>
                                <td class="px-6 py-4 text-right" x-text="number.outbound"></td>
                                <td class="px-6 py-4 text-right" x-text="number.unique_contacts"></td>
                            </tr>
                        </template>
                        <tr x-show="dashboard.numbers_usage.length === 0 && !isLoading" style="display:none;">
                            <td colspan="7" class="px-6 py-8 text-center text-slate-500">No hay actividad por numero en el rango seleccionado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-800 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="text-xs text-slate-400">
                    Mostrando <span x-text="tableRangeStart('numbers_usage', dashboard.numbers_usage)"></span>-<span x-text="tableRangeEnd('numbers_usage', dashboard.numbers_usage)"></span>
                    de <span x-text="dashboard.numbers_usage.length"></span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs text-slate-400">
                        Filas
                        <select @change="setTablePageSize('numbers_usage', $event.target.value)" :value="ensureTablePagination('numbers_usage').size" class="ml-2 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-slate-200">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                        </select>
                    </label>
                    <div class="text-xs text-slate-400">
                        Pagina <span x-text="ensureTablePagination('numbers_usage').page"></span> de <span x-text="tableTotalPages('numbers_usage', dashboard.numbers_usage)"></span>
                    </div>
                    <button @click="prevTablePage('numbers_usage', dashboard.numbers_usage)" :disabled="ensureTablePagination('numbers_usage').page <= 1" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Anterior</button>
                    <button @click="nextTablePage('numbers_usage', dashboard.numbers_usage)" :disabled="ensureTablePagination('numbers_usage').page >= tableTotalPages('numbers_usage', dashboard.numbers_usage)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Siguiente</button>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700/70">
                <h2 class="text-lg font-semibold text-white">Breakdown de mensajeria</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Canal</th>
                            <th class="px-6 py-4 text-right">Total</th>
                            <th class="px-6 py-4 text-right">Inbound</th>
                            <th class="px-6 py-4 text-right">Outbound</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="channel in paginatedItems('message_breakdown', dashboard.message_breakdown)" :key="channel.channel">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4 font-medium text-white" x-text="channel.channel"></td>
                                <td class="px-6 py-4 text-right" x-text="channel.total"></td>
                                <td class="px-6 py-4 text-right" x-text="channel.inbound"></td>
                                <td class="px-6 py-4 text-right" x-text="channel.outbound"></td>
                            </tr>
                        </template>
                        <tr x-show="dashboard.message_breakdown.length === 0 && !isLoading" style="display:none;">
                            <td colspan="4" class="px-6 py-8 text-center text-slate-500">No hay mensajes disponibles para este rango.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-800 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="text-xs text-slate-400">
                    Mostrando <span x-text="tableRangeStart('message_breakdown', dashboard.message_breakdown)"></span>-<span x-text="tableRangeEnd('message_breakdown', dashboard.message_breakdown)"></span>
                    de <span x-text="dashboard.message_breakdown.length"></span>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs text-slate-400">
                        Filas
                        <select @change="setTablePageSize('message_breakdown', $event.target.value)" :value="ensureTablePagination('message_breakdown').size" class="ml-2 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-slate-200">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                        </select>
                    </label>
                    <div class="text-xs text-slate-400">
                        Pagina <span x-text="ensureTablePagination('message_breakdown').page"></span> de <span x-text="tableTotalPages('message_breakdown', dashboard.message_breakdown)"></span>
                    </div>
                    <button @click="prevTablePage('message_breakdown', dashboard.message_breakdown)" :disabled="ensureTablePagination('message_breakdown').page <= 1" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Anterior</button>
                    <button @click="nextTablePage('message_breakdown', dashboard.message_breakdown)" :disabled="ensureTablePagination('message_breakdown').page >= tableTotalPages('message_breakdown', dashboard.message_breakdown)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Siguiente</button>
                </div>
            </div>
        </section>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700/70">
                <h2 class="text-lg font-semibold text-white">Ultimas llamadas inbound</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Fecha</th>
                            <th class="px-6 py-4 text-left">Contacto</th>
                            <th class="px-6 py-4 text-left">Usuario</th>
                            <th class="px-6 py-4 text-left">Estado</th>
                            <th class="px-6 py-4 text-right">Duracion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="call in paginatedItems('recent_inbound_calls', dashboard.recent_inbound_calls)" :key="call.id || call.date_added">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4" x-text="formatDate(call.date_added)"></td>
                                <td class="px-6 py-4" x-text="call.contact_name || call.contact_phone || '--'"></td>
                                <td class="px-6 py-4" x-text="call.user_name || '--'"></td>
                                <td class="px-6 py-4" x-text="call.status || '--'"></td>
                                <td class="px-6 py-4 text-right" x-text="formatDuration(call.duration_seconds)"></td>
                            </tr>
                        </template>
                        <tr x-show="dashboard.recent_inbound_calls.length === 0 && !isLoading" style="display:none;">
                            <td colspan="5" class="px-6 py-8 text-center text-slate-500">Sin llamadas inbound en este rango.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700/70">
                <h2 class="text-lg font-semibold text-white">Ultimas llamadas outbound</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-950/60 text-slate-400">
                        <tr>
                            <th class="px-6 py-4 text-left">Fecha</th>
                            <th class="px-6 py-4 text-left">Contacto</th>
                            <th class="px-6 py-4 text-left">Usuario</th>
                            <th class="px-6 py-4 text-left">Estado</th>
                            <th class="px-6 py-4 text-right">Duracion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 text-slate-200">
                        <template x-for="call in paginatedItems('recent_outbound_calls', dashboard.recent_outbound_calls)" :key="call.id || call.date_added">
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4" x-text="formatDate(call.date_added)"></td>
                                <td class="px-6 py-4" x-text="call.contact_name || call.contact_phone || '--'"></td>
                                <td class="px-6 py-4" x-text="call.user_name || '--'"></td>
                                <td class="px-6 py-4" x-text="call.status || '--'"></td>
                                <td class="px-6 py-4 text-right" x-text="formatDuration(call.duration_seconds)"></td>
                            </tr>
                        </template>
                        <tr x-show="dashboard.recent_outbound_calls.length === 0 && !isLoading" style="display:none;">
                            <td colspan="5" class="px-6 py-8 text-center text-slate-500">Sin llamadas outbound en este rango.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="rounded-2xl border border-cyan-500/20 bg-gradient-to-br from-slate-900/90 to-slate-950/90 overflow-hidden mb-8">
        <div class="px-6 py-5 border-b border-slate-700/70 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-white">Voice AI Call Logs</h2>
                <p class="text-sm text-slate-400 mt-1">Llamadas nativas del endpoint de Voice AI con detalle remoto completo.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs text-slate-400">
                <span>Transcripts: <span class="text-slate-100" x-text="dashboard.voice_ai_coverage.transcripts || 0"></span></span>
                <span>|</span>
                <span>Summaries: <span class="text-slate-100" x-text="dashboard.voice_ai_coverage.summaries || 0"></span></span>
                <span>|</span>
                <span>Recordings: <span class="text-slate-100" x-text="dashboard.voice_ai_coverage.recordings || 0"></span></span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-950/60 text-slate-400">
                    <tr>
                        <th class="px-6 py-4 text-left">Fecha</th>
                        <th class="px-6 py-4 text-left">Contacto</th>
                        <th class="px-6 py-4 text-left">Agente</th>
                        <th class="px-6 py-4 text-left">Estado</th>
                        <th class="px-6 py-4 text-left">Resumen</th>
                        <th class="px-6 py-4 text-right">Duracion</th>
                        <th class="px-6 py-4 text-right">Accion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800 text-slate-200">
                    <template x-for="call in paginatedItems('voice_ai_recent_calls', dashboard.voice_ai_recent_calls)" :key="call.call_id || call.started_at">
                        <tr class="hover:bg-slate-800/40">
                            <td class="px-6 py-4" x-text="formatDate(call.started_at)"></td>
                            <td class="px-6 py-4">
                                <div x-text="call.contact_name || 'Sin contacto'"></div>
                                <div class="text-xs text-slate-400" x-text="call.contact_phone || call.contact_id || ''"></div>
                            </td>
                            <td class="px-6 py-4" x-text="call.agent_name || 'Sin agente'"></td>
                            <td class="px-6 py-4" x-text="call.status || 'Unknown'"></td>
                            <td class="px-6 py-4 max-w-[420px]">
                                <div class="truncate" x-text="call.summary || 'Sin summary disponible'"></div>
                                <div class="text-xs text-slate-400" x-text="call.call_id || ''"></div>
                            </td>
                            <td class="px-6 py-4 text-right" x-text="formatDuration(call.duration_seconds)"></td>
                            <td class="px-6 py-4 text-right">
                                <button @click="openCallDetail(call)" type="button" class="px-3 py-2 rounded-lg border border-cyan-500/40 text-cyan-200 hover:bg-cyan-500/10 transition-colors inline-flex items-center">
                                    Ver detalle API
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="dashboard.voice_ai_recent_calls.length === 0 && !isLoading" style="display:none;">
                        <td colspan="7" class="px-6 py-8 text-center text-slate-500">No hay call logs de Voice AI en el rango seleccionado.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4 border-t border-slate-800 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="text-xs text-slate-400">
                Mostrando <span x-text="tableRangeStart('voice_ai_recent_calls', dashboard.voice_ai_recent_calls)"></span>-<span x-text="tableRangeEnd('voice_ai_recent_calls', dashboard.voice_ai_recent_calls)"></span>
                de <span x-text="dashboard.voice_ai_recent_calls.length"></span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="text-xs text-slate-400">
                    Filas
                    <select @change="setTablePageSize('voice_ai_recent_calls', $event.target.value)" :value="ensureTablePagination('voice_ai_recent_calls').size" class="ml-2 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-slate-200">
                        <option value="5">5</option>
                        <option value="8">8</option>
                        <option value="15">15</option>
                        <option value="25">25</option>
                    </select>
                </label>
                <div class="text-xs text-slate-400">
                    Pagina <span x-text="ensureTablePagination('voice_ai_recent_calls').page"></span> de <span x-text="tableTotalPages('voice_ai_recent_calls', dashboard.voice_ai_recent_calls)"></span>
                </div>
                <button @click="prevTablePage('voice_ai_recent_calls', dashboard.voice_ai_recent_calls)" :disabled="ensureTablePagination('voice_ai_recent_calls').page <= 1" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Anterior</button>
                <button @click="nextTablePage('voice_ai_recent_calls', dashboard.voice_ai_recent_calls)" :disabled="ensureTablePagination('voice_ai_recent_calls').page >= tableTotalPages('voice_ai_recent_calls', dashboard.voice_ai_recent_calls)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Siguiente</button>
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-700/70 bg-slate-900/70 overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-700/70 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-white">Actividad reciente</h2>
                <p class="text-sm text-slate-400 mt-1">Feed combinado de llamadas, SMS y email devueltos por la API.</p>
            </div>
            <div class="text-xs text-slate-400">
                Recordings: <span class="text-slate-200" x-text="dashboard.summary.recorded_call_count || 0"></span>
                <span class="mx-2">|</span>
                Calls: <span class="text-slate-200" x-text="dashboard.summary.call_total || 0"></span>
                <span class="mx-2">|</span>
                SMS: <span class="text-slate-200" x-text="dashboard.summary.sms_total || 0"></span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-950/60 text-slate-400">
                    <tr>
                        <th class="px-6 py-4 text-left">Fecha</th>
                        <th class="px-6 py-4 text-left">Canal</th>
                        <th class="px-6 py-4 text-left">Usuario</th>
                        <th class="px-6 py-4 text-left">Contacto</th>
                        <th class="px-6 py-4 text-left">Direccion</th>
                        <th class="px-6 py-4 text-left">Estado</th>
                        <th class="px-6 py-4 text-right">Duracion</th>
                        <th class="px-6 py-4 text-left">Detalle</th>
                            <th class="px-6 py-4 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800 text-slate-200">
                    <template x-for="interaction in paginatedItems('recent_interactions', dashboard.recent_interactions)" :key="interaction.id || interaction.date_added">
                        <tr class="hover:bg-slate-800/50">
                            <td class="px-6 py-4" x-text="formatDate(interaction.date_added)"></td>
                            <td class="px-6 py-4">
                                <span class="px-2.5 py-1 rounded-full text-xs bg-cyan-500/15 text-cyan-200 border border-cyan-400/20" x-text="interaction.channel || 'Unknown'"></span>
                            </td>
                            <td class="px-6 py-4" x-text="interaction.user_name || 'Sin usuario'"></td>
                            <td class="px-6 py-4">
                                <div x-text="interaction.contact_name || 'Sin contacto'"></div>
                                <div class="text-xs text-slate-400" x-text="interaction.contact_phone || ''"></div>
                            </td>
                            <td class="px-6 py-4" x-text="interaction.direction || 'unknown'"></td>
                            <td class="px-6 py-4" x-text="interaction.status || 'unknown'"></td>
                            <td class="px-6 py-4 text-right" x-text="formatDuration(interaction.duration_seconds)"></td>
                            <td class="px-6 py-4 max-w-[360px]">
                                <div class="truncate" x-text="interaction.body || interaction.error || '--'"></div>
                                <div class="text-xs text-slate-400" x-text="interaction.source || '--'"></div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button @click="openCallDetail(interaction)" type="button"
                                        class="px-3 py-2 rounded-lg border border-cyan-500/40 text-cyan-200 hover:bg-cyan-500/10 transition-colors inline-flex items-center">
                                        Ver
                                    </button>
                                    <a x-show="interaction.has_recording" :href="(interaction.recording_urls || [])[0]" target="_blank"
                                        class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-700 text-slate-100 hover:bg-slate-700 transition-colors inline-flex items-center">
                                        Abrir
                                    </a>
                                    <span x-show="!interaction.has_recording" class="text-slate-500">--</span>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="dashboard.recent_interactions.length === 0 && !isLoading" style="display:none;">
                        <td colspan="8" class="px-6 py-8 text-center text-slate-500">No hay interacciones para mostrar.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4 border-t border-slate-800 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="text-xs text-slate-400">
                Mostrando <span x-text="tableRangeStart('recent_interactions', dashboard.recent_interactions)"></span>-<span x-text="tableRangeEnd('recent_interactions', dashboard.recent_interactions)"></span>
                de <span x-text="dashboard.recent_interactions.length"></span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="text-xs text-slate-400">
                    Filas
                    <select @change="setTablePageSize('recent_interactions', $event.target.value)" :value="ensureTablePagination('recent_interactions').size" class="ml-2 rounded-lg bg-slate-950 border border-slate-700 px-2 py-1 text-slate-200">
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </label>
                <div class="text-xs text-slate-400">
                    Pagina <span x-text="ensureTablePagination('recent_interactions').page"></span> de <span x-text="tableTotalPages('recent_interactions', dashboard.recent_interactions)"></span>
                </div>
                <button @click="prevTablePage('recent_interactions', dashboard.recent_interactions)" :disabled="ensureTablePagination('recent_interactions').page <= 1" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Anterior</button>
                <button @click="nextTablePage('recent_interactions', dashboard.recent_interactions)" :disabled="ensureTablePagination('recent_interactions').page >= tableTotalPages('recent_interactions', dashboard.recent_interactions)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 disabled:opacity-40">Siguiente</button>
            </div>
        </div>
    </section>

    <div x-show="detailModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4" style="display:none;">
        <div class="w-full max-w-4xl rounded-3xl border border-slate-700 bg-slate-900 shadow-2xl overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-700 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-white">Detalle de interaccion</h2>
                    <p class="text-sm text-slate-400" x-text="activeCall ? (activeCall.call_id || activeCall.channel || 'Sin ID') : ''"></p>
                </div>
                <button @click="detailModal = false" type="button" class="h-10 w-10 rounded-full bg-slate-800 text-slate-200 hover:bg-slate-700">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="p-6 space-y-6 max-h-[80vh] overflow-y-auto">
                <div x-show="detailLoading" style="display:none;" class="flex items-center justify-center py-12">
                    <div class="h-10 w-10 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                </div>

                <template x-if="activeCall && !detailLoading">
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4">
                                <p class="text-xs uppercase tracking-wider text-slate-500">Fecha</p>
                                <p class="text-sm text-white mt-2" x-text="formatDate(activeCall.started_at)"></p>
                            </div>
                            <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4">
                                <p class="text-xs uppercase tracking-wider text-slate-500">Duracion</p>
                                <p class="text-sm text-white mt-2" x-text="formatDuration(activeCall.duration_seconds)"></p>
                            </div>
                            <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4">
                                <p class="text-xs uppercase tracking-wider text-slate-500">Agente</p>
                                <p class="text-sm text-white mt-2" x-text="activeCall.agent_name || 'Sin agente'"></p>
                            </div>
                            <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4">
                                <p class="text-xs uppercase tracking-wider text-slate-500">Contacto</p>
                                <p class="text-sm text-white mt-2" x-text="activeCall.contact_name || activeCall.contact_phone || 'Sin contacto'"></p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-5">
                                <h3 class="text-white font-semibold mb-3">Resumen IA</h3>
                                <p class="text-sm text-slate-300 whitespace-pre-wrap" x-text="activeCall.summary || 'Sin resumen disponible.'"></p>
                            </div>
                            <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-5">
                                <h3 class="text-white font-semibold mb-3">Metadatos</h3>
                                <dl class="space-y-3 text-sm">
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500">Canal / tipo</dt>
                                        <dd class="text-slate-200" x-text="activeCall.call_type || 'Unknown'"></dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500">Estado</dt>
                                        <dd class="text-slate-200" x-text="activeCall.status || 'Unknown'"></dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500">Disposicion</dt>
                                        <dd class="text-slate-200" x-text="activeCall.disposition || '--'"></dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500">Sentiment</dt>
                                        <dd class="text-slate-200" x-text="activeCall.sentiment || '--'"></dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500">Origen</dt>
                                        <dd class="text-slate-200" x-text="activeCall.source || '--'"></dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500">Business number</dt>
                                        <dd class="text-slate-200 text-right" x-text="activeCall.business_number || '--'"></dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500">Acciones</dt>
                                        <dd class="text-slate-200 text-right" x-text="(activeCall.action_types || []).join(', ') || '--'"></dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500">Recording</dt>
                                        <dd class="text-right">
                                            <a x-show="activeCall.recording_url" :href="activeCall.recording_url" target="_blank" class="text-cyan-300 hover:text-cyan-200">Abrir</a>
                                            <span x-show="!activeCall.recording_url" class="text-slate-200">--</span>
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-5">
                            <h3 class="text-white font-semibold mb-3">Transcript</h3>
                            <p class="text-sm text-slate-300 whitespace-pre-wrap" x-text="activeCall.transcript || 'Sin transcript disponible.'"></p>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MEGA — Reportes avanzados GHL + capa IA de Claude
         ============================================================ -->
    <section class="mb-8 va-card overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-800 bg-slate-950/60 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-xl bg-indigo-500/15 border border-indigo-500/30 flex items-center justify-center">
                    <i class="fas fa-layer-group text-indigo-300"></i>
                </div>
                <div>
                    <p class="text-indigo-300/80 text-[10px] uppercase tracking-[0.3em] font-semibold">Extensiones API + IA</p>
                    <h2 class="text-lg font-bold text-white">Centro de análisis avanzado</h2>
                    <p class="text-xs text-slate-400">Disposiciones, pipeline, citas, automatizaciones y narrativas Claude.</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="va-chip" :class="megaAi.enabled ? '!text-emerald-300 !border-emerald-500/40 !bg-emerald-500/10' : '!text-amber-300 !border-amber-500/40 !bg-amber-500/10'">
                    <i class="fas fa-robot"></i>
                    <span x-text="megaAi.enabled ? 'IA activa' : 'IA desactivada'"></span>
                </span>
                <button @click="fetchAiHealth()" type="button" class="va-btn-ghost">
                    <i class="fas fa-heart-pulse mr-1.5"></i> Revisar IA
                </button>
            </div>
        </div>

        <div class="px-6 pt-5 pb-4 border-b border-slate-800 bg-slate-950/30">
            <div class="flex flex-col gap-3">
                <div>
                    <p class="text-[10px] uppercase tracking-[0.25em] text-slate-500 mb-2 font-semibold">Data · desde la API</p>
                    <div class="va-tab-rail">
                        <template x-for="tab in megaTabs.filter(t => t.group === 'data')" :key="tab.key">
                            <button @click="setMegaTab(tab.key)" type="button" class="va-tab"
                                :class="activeMegaTab === tab.key ? 'is-active' : ''">
                                <i class="fas" :class="tab.icon"></i>
                                <span x-text="tab.label"></span>
                            </button>
                        </template>
                    </div>
                </div>
                <div>
                    <p class="text-[10px] uppercase tracking-[0.25em] text-slate-500 mb-2 font-semibold">Inteligencia · Claude AI</p>
                    <div class="va-tab-rail">
                        <template x-for="tab in megaTabs.filter(t => t.group === 'ai')" :key="tab.key">
                            <button @click="setMegaTab(tab.key)" type="button" class="va-tab"
                                :class="activeMegaTab === tab.key ? 'is-active' : ''">
                                <i class="fas" :class="tab.icon"></i>
                                <span x-text="tab.label"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: IA Executive -->
        <div x-show="activeMegaTab === 'ai_executive'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Resumen ejecutivo con IA</h3>
                    <p class="text-sm text-slate-400">Veredicto, KPIs críticos, qué funcionó, qué preocupa y acciones sugeridas.</p>
                </div>
                <button @click="runAiInsight('executive')" type="button" :disabled="aiInsights.executive.loading"
                    class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                    <i class="fas mr-2" :class="aiInsights.executive.loading ? 'fa-spinner fa-spin' : 'fa-wand-magic-sparkles'"></i>
                    Generar resumen
                </button>
            </div>
            <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-5 min-h-[140px]">
                <div x-show="aiInsights.executive.error" class="text-rose-300 text-sm" x-text="aiInsights.executive.error" style="display:none;"></div>
                <div x-show="!aiInsights.executive.content && !aiInsights.executive.loading && !aiInsights.executive.error" class="text-slate-500 text-sm italic">
                    Aún no se ha generado un resumen. Pulsa “Generar resumen”.
                </div>
                <div x-show="aiInsights.executive.content" x-html="renderMarkdown(aiInsights.executive.content)" class="prose prose-invert prose-sm max-w-none" style="display:none;"></div>
                <p x-show="aiInsights.executive.meta" class="text-xs text-slate-500 mt-4" x-text="aiInsights.executive.meta" style="display:none;"></p>
            </div>
        </div>

        <!-- Tab: IA Coaching -->
        <div x-show="activeMegaTab === 'ai_coaching'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Coaching para agentes (IA)</h3>
                    <p class="text-sm text-slate-400">Top y bottom 3 con fortalezas, riesgos y acciones concretas.</p>
                </div>
                <button @click="runAiInsight('coaching')" type="button" :disabled="aiInsights.coaching.loading"
                    class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                    <i class="fas mr-2" :class="aiInsights.coaching.loading ? 'fa-spinner fa-spin' : 'fa-headset'"></i>
                    Generar coaching
                </button>
            </div>
            <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-5 min-h-[140px]">
                <div x-show="aiInsights.coaching.error" class="text-rose-300 text-sm" x-text="aiInsights.coaching.error" style="display:none;"></div>
                <div x-show="!aiInsights.coaching.content && !aiInsights.coaching.loading && !aiInsights.coaching.error" class="text-slate-500 text-sm italic">
                    Aún no hay diagnóstico de coaching.
                </div>
                <div x-show="aiInsights.coaching.content" x-html="renderMarkdown(aiInsights.coaching.content)" class="prose prose-invert prose-sm max-w-none" style="display:none;"></div>
            </div>
        </div>

        <!-- Tab: IA Risk + Opportunity -->
        <div x-show="activeMegaTab === 'ai_risk'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Riesgo &amp; oportunidades (IA)</h3>
                    <p class="text-sm text-slate-400">Churn, leads calientes y alertas operativas cruzando llamadas + pipeline.</p>
                </div>
                <button @click="runAiInsight('risk')" type="button" :disabled="aiInsights.risk.loading"
                    class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                    <i class="fas mr-2" :class="aiInsights.risk.loading ? 'fa-spinner fa-spin' : 'fa-shield-halved'"></i>
                    Ejecutar análisis
                </button>
            </div>
            <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-5 min-h-[140px]">
                <div x-show="aiInsights.risk.error" class="text-rose-300 text-sm" x-text="aiInsights.risk.error" style="display:none;"></div>
                <div x-show="!aiInsights.risk.content && !aiInsights.risk.loading && !aiInsights.risk.error" class="text-slate-500 text-sm italic">
                    Aún no se ha ejecutado el análisis.
                </div>
                <div x-show="aiInsights.risk.content" x-html="renderMarkdown(aiInsights.risk.content)" class="prose prose-invert prose-sm max-w-none" style="display:none;"></div>
            </div>
        </div>

        <!-- Tab: IA Anomalies -->
        <div x-show="activeMegaTab === 'ai_anomalies'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Detección de anomalías (IA)</h3>
                    <p class="text-sm text-slate-400">Outliers y desviaciones materiales respecto al patrón del periodo.</p>
                </div>
                <button @click="runAiInsight('anomalies')" type="button" :disabled="aiInsights.anomalies.loading"
                    class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                    <i class="fas mr-2" :class="aiInsights.anomalies.loading ? 'fa-spinner fa-spin' : 'fa-triangle-exclamation'"></i>
                    Buscar anomalías
                </button>
            </div>
            <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-5 min-h-[140px]">
                <div x-show="aiInsights.anomalies.error" class="text-rose-300 text-sm" x-text="aiInsights.anomalies.error" style="display:none;"></div>
                <div x-show="!aiInsights.anomalies.content && !aiInsights.anomalies.loading && !aiInsights.anomalies.error" class="text-slate-500 text-sm italic">
                    Aún no se han buscado anomalías.
                </div>
                <div x-show="aiInsights.anomalies.content" x-html="renderMarkdown(aiInsights.anomalies.content)" class="prose prose-invert prose-sm max-w-none" style="display:none;"></div>
            </div>
        </div>

        <!-- Tab: IA Forecast -->
        <div x-show="activeMegaTab === 'ai_forecast'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Forecast 7 días (IA)</h3>
                    <p class="text-sm text-slate-400">Predicción con tendencia + estacionalidad + recomendaciones de staffing.</p>
                </div>
                <button @click="runAiInsight('forecast')" type="button" :disabled="aiInsights.forecast.loading"
                    class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                    <i class="fas mr-2" :class="aiInsights.forecast.loading ? 'fa-spinner fa-spin' : 'fa-arrow-trend-up'"></i>
                    Generar forecast
                </button>
            </div>
            <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-5 min-h-[140px]">
                <div x-show="aiInsights.forecast.error" class="text-rose-300 text-sm" x-text="aiInsights.forecast.error" style="display:none;"></div>
                <div x-show="!aiInsights.forecast.content && !aiInsights.forecast.loading && !aiInsights.forecast.error" class="text-slate-500 text-sm italic">
                    Aún no hay forecast.
                </div>
                <div x-show="aiInsights.forecast.content" x-html="renderMarkdown(aiInsights.forecast.content)" class="prose prose-invert prose-sm max-w-none" style="display:none;"></div>
            </div>
        </div>

        <!-- Tab: IA Natural Q&A -->
        <div x-show="activeMegaTab === 'ai_chat'" class="p-6 space-y-6" style="display:none;">
            <div>
                <h3 class="text-lg font-semibold text-white">Pregunta natural sobre los datos</h3>
                <p class="text-sm text-slate-400">Ejemplos: “¿cuál es el canal con más volumen?”, “¿qué agente tiene mejor disposición de ventas?”, “¿cuántas interacciones el martes?”</p>
            </div>
            <form @submit.prevent="askNatural" class="flex flex-col md:flex-row gap-3">
                <input x-model="naturalQuestion" type="text" required
                    placeholder="Escribe tu pregunta sobre el periodo..."
                    class="flex-1 rounded-xl bg-slate-950 border border-slate-700 text-slate-100 px-4 py-3">
                <button type="submit" :disabled="aiInsights.chat.loading || !naturalQuestion.trim()"
                    class="px-5 py-3 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                    <i class="fas mr-2" :class="aiInsights.chat.loading ? 'fa-spinner fa-spin' : 'fa-paper-plane'"></i>
                    Preguntar
                </button>
            </form>
            <div class="space-y-3">
                <template x-for="(item, idx) in naturalHistory" :key="idx">
                    <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                        <p class="text-sm text-cyan-300 font-semibold">
                            <i class="fas fa-user mr-2"></i>
                            <span x-text="item.question"></span>
                        </p>
                        <div x-show="item.answer" x-html="renderMarkdown(item.answer)" class="prose prose-invert prose-sm max-w-none mt-3" style="display:none;"></div>
                        <p x-show="item.error" class="text-rose-300 text-sm mt-2" x-text="item.error" style="display:none;"></p>
                    </div>
                </template>
                <p x-show="naturalHistory.length === 0" class="text-slate-500 text-sm italic" style="display:none;">
                    Sin preguntas aún.
                </p>
            </div>
        </div>

        <!-- ============================================================
             Tab: Dispositions — full breakdown cruzando conversations + Voice AI
             ============================================================ -->
        <div x-show="activeMegaTab === 'dispositions'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Desglose completo de disposiciones</h3>
                    <p class="text-sm text-slate-400">
                        Cruzado desde <code class="text-cyan-300">/conversations/messages/export</code> +
                        <code class="text-cyan-300">/voice-ai/dashboard/call-logs</code>. Incluye outcome, duración, grabación,
                        transcripción, distribución por usuario, hora y día.
                    </p>
                </div>
                <button @click="loadDispositionsReport()" type="button" :disabled="dispositionsReport.loading"
                    class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold transition-colors">
                    <i class="fas mr-2" :class="dispositionsReport.loading ? 'fa-spinner fa-spin' : 'fa-rotate-right'"></i>
                    Actualizar
                </button>
            </div>

            <!-- Empty / error states -->
            <div x-show="dispositionsReport.error" class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-rose-200 text-sm" style="display:none;">
                <i class="fas fa-circle-exclamation mr-2"></i><span x-text="dispositionsReport.error"></span>
            </div>
            <div x-show="!dispositionsReport.loaded && !dispositionsReport.loading && !dispositionsReport.error"
                class="rounded-xl border border-slate-800 bg-slate-950/40 p-8 text-center text-slate-400 text-sm" style="display:none;">
                Pulsa "Actualizar" para cargar el desglose de disposiciones del periodo.
            </div>

            <template x-if="dispositionsReport.loaded && dispositionsReport.data">
                <div class="space-y-6">
                    <!-- KPI band -->
                    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-3">
                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Total llamadas</p>
                            <p class="text-2xl font-bold text-white mt-1" x-text="(dispositionsReport.data.summary || {}).total_calls || 0"></p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Disposiciones únicas</p>
                            <p class="text-2xl font-bold text-cyan-300 mt-1" x-text="(dispositionsReport.data.summary || {}).dispositions_unique || 0"></p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Top disposición</p>
                            <p class="text-sm font-semibold text-white mt-2 truncate" x-text="(dispositionsReport.data.summary || {}).top_disposition || '--'"></p>
                            <p class="text-xs text-slate-400 mt-1" x-text="`${(dispositionsReport.data.summary || {}).top_disposition_share_pct || 0}% del total`"></p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Sin disposición</p>
                            <p class="text-2xl font-bold mt-1"
                                :class="((dispositionsReport.data.summary || {}).no_disposition_pct || 0) > 30 ? 'text-rose-300' : 'text-amber-300'"
                                x-text="`${(dispositionsReport.data.summary || {}).no_disposition_pct || 0}%`"></p>
                            <p class="text-xs text-slate-400 mt-1" x-text="`${(dispositionsReport.data.summary || {}).no_disposition_count || 0} llamadas`"></p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Grabación</p>
                            <p class="text-2xl font-bold text-emerald-300 mt-1" x-text="`${(dispositionsReport.data.summary || {}).recording_coverage_pct || 0}%`"></p>
                            <p class="text-xs text-slate-400 mt-1" x-text="`${(dispositionsReport.data.summary || {}).recorded_calls || 0} grabadas`"></p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Transcripción</p>
                            <p class="text-2xl font-bold text-sky-300 mt-1" x-text="`${(dispositionsReport.data.summary || {}).transcript_coverage_pct || 0}%`"></p>
                            <p class="text-xs text-slate-400 mt-1" x-text="`${(dispositionsReport.data.summary || {}).transcribed_calls || 0} con transcript`"></p>
                        </div>
                    </div>

                    <!-- Outcome strip -->
                    <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-5">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-sm font-semibold text-slate-200">Outcomes (clasificación automática)</h4>
                            <span class="text-xs text-slate-500">Basado en nombre de disposición + call status</span>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                            <template x-for="outcome in ['positive','negative','pending','unreachable','other','unknown']" :key="outcome">
                                <div class="rounded-lg border px-3 py-2.5"
                                    :class="dispositionOutcomeColor(outcome)">
                                    <p class="text-[10px] uppercase tracking-widest opacity-70" x-text="({positive:'Positivo',negative:'Negativo',pending:'Pendiente',unreachable:'No alcanzado',other:'Otro',unknown:'Sin outcome'})[outcome]"></p>
                                    <p class="text-lg font-bold mt-1"
                                        x-text="`${(((dispositionsReport.data.summary || {}).outcome_pct || {})[outcome] || 0)}%`"></p>
                                    <p class="text-xs opacity-75"
                                        x-text="`${(((dispositionsReport.data.summary || {}).outcome_counts || {})[outcome] || 0)} llamadas`"></p>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Charts: donut + direction -->
                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                        <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-5">
                            <h4 class="text-sm font-semibold text-slate-200 mb-3">Distribución por disposición</h4>
                            <div class="relative h-72"><canvas id="vaDispoDonut"></canvas></div>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-5">
                            <h4 class="text-sm font-semibold text-slate-200 mb-3">Inbound vs Outbound por disposición</h4>
                            <div class="relative h-72"><canvas id="vaDispoDirectionChart"></canvas></div>
                        </div>
                    </div>

                    <!-- Charts: timeline + duration -->
                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                        <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-5">
                            <h4 class="text-sm font-semibold text-slate-200 mb-3">Evolución diaria (top 6)</h4>
                            <div class="relative h-72"><canvas id="vaDispoTimeline"></canvas></div>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-5">
                            <h4 class="text-sm font-semibold text-slate-200 mb-3">Duración por disposición</h4>
                            <div class="relative h-72"><canvas id="vaDispoDurationChart"></canvas></div>
                        </div>
                    </div>

                    <!-- Disposition stats table -->
                    <div class="rounded-xl border border-slate-800 overflow-hidden">
                        <div class="px-5 py-3 border-b border-slate-800 bg-slate-950/50 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-slate-200">Tabla completa</h4>
                            <span class="text-xs text-slate-400" x-text="`${(dispositionsReport.data.stats || []).length} disposiciones`"></span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-950/60 text-slate-400 text-xs uppercase tracking-wider">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Disposición</th>
                                        <th class="px-4 py-3 text-left">Outcome</th>
                                        <th class="px-4 py-3 text-right">Total</th>
                                        <th class="px-4 py-3 text-right">%</th>
                                        <th class="px-4 py-3 text-right">Inbound</th>
                                        <th class="px-4 py-3 text-right">Outbound</th>
                                        <th class="px-4 py-3 text-right">Dur. prom</th>
                                        <th class="px-4 py-3 text-right">Grabadas</th>
                                        <th class="px-4 py-3 text-right">Usuarios</th>
                                        <th class="px-4 py-3 text-right">Contactos</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/70">
                                    <template x-for="row in (dispositionsReport.data.stats || [])" :key="row.disposition">
                                        <tr class="hover:bg-slate-800/30">
                                            <td class="px-4 py-3 text-white font-medium" x-text="row.disposition"></td>
                                            <td class="px-4 py-3">
                                                <span class="px-2 py-0.5 rounded-full text-[11px] border capitalize"
                                                    :class="dispositionOutcomeColor(row.outcome)"
                                                    x-text="row.outcome"></span>
                                            </td>
                                            <td class="px-4 py-3 text-right font-mono text-cyan-200" x-text="row.total"></td>
                                            <td class="px-4 py-3 text-right text-slate-300" x-text="`${row.share_pct || 0}%`"></td>
                                            <td class="px-4 py-3 text-right text-slate-300" x-text="row.inbound"></td>
                                            <td class="px-4 py-3 text-right text-slate-300" x-text="row.outbound"></td>
                                            <td class="px-4 py-3 text-right text-slate-300" x-text="formatDuration(row.avg_duration_seconds)"></td>
                                            <td class="px-4 py-3 text-right text-emerald-300" x-text="`${row.recording_pct || 0}%`"></td>
                                            <td class="px-4 py-3 text-right text-slate-300" x-text="row.users_unique"></td>
                                            <td class="px-4 py-3 text-right text-slate-300" x-text="row.contacts_unique"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- By user heatmap-style matrix -->
                    <div class="rounded-xl border border-slate-800 overflow-hidden">
                        <div class="px-5 py-3 border-b border-slate-800 bg-slate-950/50">
                            <h4 class="text-sm font-semibold text-slate-200">Disposición por usuario</h4>
                            <p class="text-xs text-slate-400 mt-0.5">Usuario ordenado por volumen total</p>
                        </div>
                        <div class="overflow-x-auto max-h-[500px]">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-950/60 text-slate-400 text-xs uppercase tracking-wider sticky top-0">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Usuario</th>
                                        <th class="px-4 py-3 text-right">Llamadas</th>
                                        <th class="px-4 py-3 text-right">Dur. prom</th>
                                        <th class="px-4 py-3 text-left">Top disposición</th>
                                        <th class="px-4 py-3 text-right">% positivas</th>
                                        <th class="px-4 py-3 text-right">Positivas</th>
                                        <th class="px-4 py-3 text-right">No alcanzadas</th>
                                        <th class="px-4 py-3 text-right">Sin outcome</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/70">
                                    <template x-for="u in (dispositionsReport.data.by_user || [])" :key="u.user_id || u.user_name">
                                        <tr class="hover:bg-slate-800/30">
                                            <td class="px-4 py-3 text-white font-medium" x-text="u.user_name"></td>
                                            <td class="px-4 py-3 text-right font-mono text-cyan-200" x-text="u.total_calls"></td>
                                            <td class="px-4 py-3 text-right text-slate-300" x-text="formatDuration(u.avg_duration_seconds)"></td>
                                            <td class="px-4 py-3">
                                                <span class="text-white" x-text="u.top_disposition || '--'"></span>
                                                <span class="text-xs text-slate-400 ml-1" x-text="u.top_disposition_count ? `(${u.top_disposition_count})` : ''"></span>
                                            </td>
                                            <td class="px-4 py-3 text-right"
                                                :class="(u.positive_rate_pct || 0) >= 30 ? 'text-emerald-300' : (u.positive_rate_pct || 0) >= 10 ? 'text-amber-300' : 'text-rose-300'"
                                                x-text="`${u.positive_rate_pct || 0}%`"></td>
                                            <td class="px-4 py-3 text-right text-emerald-300" x-text="(u.outcomes || {}).positive || 0"></td>
                                            <td class="px-4 py-3 text-right text-sky-300" x-text="(u.outcomes || {}).unreachable || 0"></td>
                                            <td class="px-4 py-3 text-right text-slate-400" x-text="(u.outcomes || {}).unknown || 0"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Sources strip -->
                    <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-4">
                        <div class="flex flex-wrap items-center gap-3 text-xs">
                            <span class="text-slate-400 font-semibold uppercase tracking-wider">Fuentes API:</span>
                            <span class="px-2.5 py-1 rounded-full bg-slate-800 text-slate-300">
                                conversations: <span class="text-cyan-300 font-mono" x-text="(dispositionsReport.data.sources || {}).conversations_messages_export || 0"></span>
                            </span>
                            <span class="px-2.5 py-1 rounded-full bg-slate-800 text-slate-300">
                                voice-ai: <span class="text-cyan-300 font-mono" x-text="(dispositionsReport.data.sources || {}).voice_ai_dashboard_call_logs || 0"></span>
                            </span>
                            <span class="px-2.5 py-1 rounded-full bg-slate-800 text-slate-300">
                                cruzadas: <span class="text-cyan-300 font-mono" x-text="(dispositionsReport.data.sources || {}).cross_referenced || 0"></span>
                            </span>
                            <span class="ml-auto text-slate-500" x-text="`Generado en ${dispositionsReport.data.elapsed_ms || 0} ms`"></span>
                        </div>
                        <template x-if="(dispositionsReport.data.warnings || []).length">
                            <div class="mt-3 pt-3 border-t border-slate-800 space-y-1">
                                <template x-for="w in dispositionsReport.data.warnings" :key="w">
                                    <p class="text-xs text-amber-300"><i class="fas fa-triangle-exclamation mr-1.5"></i><span x-text="w"></span></p>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <!-- Tab: Opportunities -->
        <div x-show="activeMegaTab === 'opportunities'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Pipeline &amp; oportunidades</h3>
                    <p class="text-sm text-slate-400">Datos directos del endpoint <code>/opportunities/search</code>.</p>
                </div>
                <div class="flex gap-2">
                    <button @click="loadMegaReport('opportunities')" type="button" :disabled="megaReports.opportunities.loading"
                        class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                        <i class="fas mr-2" :class="megaReports.opportunities.loading ? 'fa-spinner fa-spin' : 'fa-rotate-right'"></i>
                        Actualizar
                    </button>
                    <button @click="runAiInsight('opportunities')" type="button" :disabled="aiInsights.opportunities.loading"
                        class="px-4 py-2 rounded-xl border border-cyan-500/40 text-cyan-200 hover:bg-cyan-500/10">
                        <i class="fas mr-2" :class="aiInsights.opportunities.loading ? 'fa-spinner fa-spin' : 'fa-wand-magic-sparkles'"></i>
                        IA sobre pipeline
                    </button>
                </div>
            </div>
            <div x-show="megaReports.opportunities.data && megaReports.opportunities.data.summary" class="grid grid-cols-2 md:grid-cols-4 gap-3" style="display:none;">
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <p class="text-xs text-slate-400">Total oportunidades</p>
                    <p class="text-xl font-bold text-white mt-1" x-text="(megaReports.opportunities.data.summary || {}).total || 0"></p>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <p class="text-xs text-slate-400">Monto total</p>
                    <p class="text-xl font-bold text-emerald-300 mt-1" x-text="formatMoney((megaReports.opportunities.data.summary || {}).total_value || 0)"></p>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <p class="text-xs text-slate-400">Win rate</p>
                    <p class="text-xl font-bold text-cyan-300 mt-1" x-text="`${(megaReports.opportunities.data.summary || {}).win_rate_pct || 0}%`"></p>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <p class="text-xs text-slate-400">Ticket promedio</p>
                    <p class="text-xl font-bold text-amber-300 mt-1" x-text="formatMoney((megaReports.opportunities.data.summary || {}).avg_ticket || 0)"></p>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <div class="rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200">Por estado</div>
                    <div class="p-4">
                        <template x-for="(count, status) in ((megaReports.opportunities.data || {}).summary || {}).by_status || {}" :key="status">
                            <div class="flex items-center justify-between text-sm py-1.5 border-b border-slate-800/60 last:border-0">
                                <span class="text-slate-200 capitalize" x-text="status"></span>
                                <span class="text-cyan-300 font-mono" x-text="count"></span>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200">Por pipeline</div>
                    <div class="p-4">
                        <template x-for="(count, pipeline) in ((megaReports.opportunities.data || {}).summary || {}).by_pipeline || {}" :key="pipeline">
                            <div class="flex items-center justify-between text-sm py-1.5 border-b border-slate-800/60 last:border-0">
                                <span class="text-slate-200" x-text="pipeline"></span>
                                <span class="text-cyan-300 font-mono" x-text="count"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-700 overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200">Top 25 oportunidades</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-950/60 text-slate-400">
                            <tr>
                                <th class="px-5 py-3 text-left">Oportunidad</th>
                                <th class="px-5 py-3 text-left">Pipeline / etapa</th>
                                <th class="px-5 py-3 text-left">Contacto</th>
                                <th class="px-5 py-3 text-right">Monto</th>
                                <th class="px-5 py-3 text-left">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <template x-for="opp in (megaReports.opportunities.data?.opportunities || []).slice(0, 25)" :key="opp.id">
                                <tr class="hover:bg-slate-800/50">
                                    <td class="px-5 py-3 text-white" x-text="opp.name || '--'"></td>
                                    <td class="px-5 py-3 text-slate-300" x-text="`${opp.pipeline_name || '--'} · ${opp.stage_name || '--'}`"></td>
                                    <td class="px-5 py-3 text-slate-300" x-text="opp.contact_name || opp.contact_email || opp.contact_phone || '--'"></td>
                                    <td class="px-5 py-3 text-right text-emerald-300 font-mono" x-text="formatMoney(opp.monetary_value)"></td>
                                    <td class="px-5 py-3 text-slate-200 capitalize" x-text="opp.status"></td>
                                </tr>
                            </template>
                            <tr x-show="(megaReports.opportunities.data?.opportunities || []).length === 0">
                                <td colspan="5" class="px-5 py-8 text-center text-slate-500">Sin oportunidades para el rango o plan actual.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div x-show="aiInsights.opportunities.content || aiInsights.opportunities.error" class="rounded-xl border border-cyan-500/25 bg-cyan-500/5 p-4" style="display:none;">
                <p class="text-xs uppercase tracking-widest text-cyan-300 mb-2">Narrativa de IA</p>
                <div x-show="aiInsights.opportunities.error" class="text-rose-300 text-sm" x-text="aiInsights.opportunities.error" style="display:none;"></div>
                <div x-show="aiInsights.opportunities.content" x-html="renderMarkdown(aiInsights.opportunities.content)" class="prose prose-invert prose-sm max-w-none" style="display:none;"></div>
            </div>
        </div>

        <!-- Tab: Appointments -->
        <div x-show="activeMegaTab === 'appointments'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Calendario &amp; citas</h3>
                    <p class="text-sm text-slate-400">Citas agregadas desde <code>/calendars/events</code> para todos los calendarios del location.</p>
                </div>
                <button @click="loadMegaReport('appointments')" type="button" :disabled="megaReports.appointments.loading"
                    class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                    <i class="fas mr-2" :class="megaReports.appointments.loading ? 'fa-spinner fa-spin' : 'fa-rotate-right'"></i>
                    Actualizar
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <p class="text-xs text-slate-400">Total citas</p>
                    <p class="text-xl font-bold text-white mt-1" x-text="(megaReports.appointments.data?.summary || {}).total || 0"></p>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <p class="text-xs text-slate-400">Show rate</p>
                    <p class="text-xl font-bold text-emerald-300 mt-1" x-text="`${(megaReports.appointments.data?.summary || {}).show_rate_pct || 0}%`"></p>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <p class="text-xs text-slate-400">Canceladas</p>
                    <p class="text-xl font-bold text-rose-300 mt-1" x-text="(megaReports.appointments.data?.summary || {}).cancelled || 0"></p>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <p class="text-xs text-slate-400">Calendarios activos</p>
                    <p class="text-xl font-bold text-cyan-300 mt-1" x-text="Object.keys((megaReports.appointments.data?.summary || {}).by_calendar || {}).length"></p>
                </div>
            </div>

            <div class="rounded-xl border border-slate-700 overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200">Próximas 30 citas</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-950/60 text-slate-400">
                            <tr>
                                <th class="px-5 py-3 text-left">Título</th>
                                <th class="px-5 py-3 text-left">Calendario</th>
                                <th class="px-5 py-3 text-left">Inicio</th>
                                <th class="px-5 py-3 text-left">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <template x-for="appt in (megaReports.appointments.data?.appointments || []).slice(0, 30)" :key="appt.id">
                                <tr class="hover:bg-slate-800/50">
                                    <td class="px-5 py-3 text-white" x-text="appt.title || '--'"></td>
                                    <td class="px-5 py-3 text-slate-300" x-text="appt.calendar_name || '--'"></td>
                                    <td class="px-5 py-3 text-slate-300" x-text="formatDate(appt.start_time)"></td>
                                    <td class="px-5 py-3 text-slate-200 capitalize" x-text="appt.status || '--'"></td>
                                </tr>
                            </template>
                            <tr x-show="(megaReports.appointments.data?.appointments || []).length === 0">
                                <td colspan="4" class="px-5 py-8 text-center text-slate-500">Sin citas en el rango o el plan no incluye calendarios.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Forms -->
        <div x-show="activeMegaTab === 'forms'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Forms &amp; encuestas</h3>
                    <p class="text-sm text-slate-400">Submissions de formularios y encuestas del periodo.</p>
                </div>
                <div class="flex gap-2">
                    <button @click="loadMegaReport('forms')" type="button" :disabled="megaReports.forms.loading"
                        class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                        <i class="fas fa-file-lines mr-2"></i> Forms
                    </button>
                    <button @click="loadMegaReport('surveys')" type="button" :disabled="megaReports.surveys.loading"
                        class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                        <i class="fas fa-clipboard-list mr-2"></i> Surveys
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <div class="rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200 flex items-center justify-between">
                        <span>Formularios</span>
                        <span class="text-xs text-slate-400"
                            x-text="`${(megaReports.forms.data?.summary || {}).total_submissions || 0} envíos`"></span>
                    </div>
                    <div class="p-4 space-y-2">
                        <template x-for="(count, name) in ((megaReports.forms.data?.summary || {}).by_form) || {}" :key="name">
                            <div class="flex items-center justify-between text-sm py-1.5 border-b border-slate-800/60 last:border-0">
                                <span class="text-slate-200" x-text="name"></span>
                                <span class="text-cyan-300 font-mono" x-text="count"></span>
                            </div>
                        </template>
                        <p x-show="!megaReports.forms.data?.summary?.total_submissions" class="text-xs text-slate-500 italic">Sin envíos.</p>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200 flex items-center justify-between">
                        <span>Encuestas</span>
                        <span class="text-xs text-slate-400"
                            x-text="`${(megaReports.surveys.data?.summary || {}).total_submissions || 0} respuestas`"></span>
                    </div>
                    <div class="p-4 space-y-2">
                        <template x-for="(count, name) in ((megaReports.surveys.data?.summary || {}).by_survey) || {}" :key="name">
                            <div class="flex items-center justify-between text-sm py-1.5 border-b border-slate-800/60 last:border-0">
                                <span class="text-slate-200" x-text="name"></span>
                                <span class="text-cyan-300 font-mono" x-text="count"></span>
                            </div>
                        </template>
                        <p x-show="!megaReports.surveys.data?.summary?.total_submissions" class="text-xs text-slate-500 italic">Sin respuestas.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: Automations -->
        <div x-show="activeMegaTab === 'automations'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Workflows &amp; campañas</h3>
                    <p class="text-sm text-slate-400">Catálogo directo desde los endpoints <code>/workflows/</code> y <code>/campaigns/</code>.</p>
                </div>
                <div class="flex gap-2">
                    <button @click="loadMegaReport('workflows')" type="button" :disabled="megaReports.workflows.loading"
                        class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                        <i class="fas fa-diagram-project mr-2"></i> Workflows
                    </button>
                    <button @click="loadMegaReport('campaigns')" type="button" :disabled="megaReports.campaigns.loading"
                        class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                        <i class="fas fa-bullhorn mr-2"></i> Campañas
                    </button>
                </div>
            </div>
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <div class="rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200 flex items-center justify-between">
                        <span>Workflows</span>
                        <span class="text-xs text-slate-400" x-text="`${(megaReports.workflows.data?.workflows || []).length} totales`"></span>
                    </div>
                    <div class="overflow-x-auto max-h-96">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-950/60 text-slate-400 sticky top-0">
                                <tr>
                                    <th class="px-4 py-2 text-left">Nombre</th>
                                    <th class="px-4 py-2 text-left">Estado</th>
                                    <th class="px-4 py-2 text-right">Versión</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                <template x-for="wf in (megaReports.workflows.data?.workflows || [])" :key="wf.id">
                                    <tr class="hover:bg-slate-800/50">
                                        <td class="px-4 py-2 text-slate-200" x-text="wf.name"></td>
                                        <td class="px-4 py-2 text-slate-300 capitalize" x-text="wf.status"></td>
                                        <td class="px-4 py-2 text-right text-slate-300" x-text="wf.version"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200 flex items-center justify-between">
                        <span>Campañas</span>
                        <span class="text-xs text-slate-400" x-text="`${(megaReports.campaigns.data?.campaigns || []).length} totales`"></span>
                    </div>
                    <div class="overflow-x-auto max-h-96">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-950/60 text-slate-400 sticky top-0">
                                <tr>
                                    <th class="px-4 py-2 text-left">Nombre</th>
                                    <th class="px-4 py-2 text-left">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                <template x-for="camp in (megaReports.campaigns.data?.campaigns || [])" :key="camp.id">
                                    <tr class="hover:bg-slate-800/50">
                                        <td class="px-4 py-2 text-slate-200" x-text="camp.name"></td>
                                        <td class="px-4 py-2 text-slate-300 capitalize" x-text="camp.status"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: Contacts Growth -->
        <div x-show="activeMegaTab === 'contacts'" class="p-6 space-y-6" style="display:none;">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-white">Crecimiento de contactos</h3>
                    <p class="text-sm text-slate-400">Contactos nuevos en el rango, por origen, usuario y tag.</p>
                </div>
                <button @click="loadMegaReport('contacts_growth')" type="button" :disabled="megaReports.contacts_growth.loading"
                    class="px-4 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold">
                    <i class="fas mr-2" :class="megaReports.contacts_growth.loading ? 'fa-spinner fa-spin' : 'fa-users'"></i>
                    Actualizar
                </button>
            </div>
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200">Por origen</div>
                    <div class="p-4 space-y-1 max-h-80 overflow-y-auto">
                        <template x-for="(count, source) in (megaReports.contacts_growth.data?.summary?.by_source) || {}" :key="source">
                            <div class="flex items-center justify-between text-sm py-1 border-b border-slate-800/60 last:border-0">
                                <span class="text-slate-200" x-text="source"></span>
                                <span class="text-cyan-300 font-mono" x-text="count"></span>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200">Por usuario asignado</div>
                    <div class="p-4 space-y-1 max-h-80 overflow-y-auto">
                        <template x-for="(count, user) in (megaReports.contacts_growth.data?.summary?.by_user) || {}" :key="user">
                            <div class="flex items-center justify-between text-sm py-1 border-b border-slate-800/60 last:border-0">
                                <span class="text-slate-200" x-text="user"></span>
                                <span class="text-cyan-300 font-mono" x-text="count"></span>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-700 overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-700 bg-slate-950/50 text-sm font-semibold text-slate-200">Top tags</div>
                    <div class="p-4 space-y-1 max-h-80 overflow-y-auto">
                        <template x-for="(count, tag) in (megaReports.contacts_growth.data?.summary?.by_tag) || {}" :key="tag">
                            <div class="flex items-center justify-between text-sm py-1 border-b border-slate-800/60 last:border-0">
                                <span class="text-slate-200" x-text="tag"></span>
                                <span class="text-cyan-300 font-mono" x-text="count"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

    </section>

    <div x-show="apiHealthModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4" style="display:none;">
        <div class="w-full max-w-5xl max-h-[90vh] overflow-y-auto rounded-3xl border border-slate-700 bg-slate-900 shadow-2xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-700 sticky top-0 bg-slate-900 z-10">
                <div>
                    <h2 class="text-xl font-semibold text-white">Estado de API y Cobertura UI</h2>
                    <p class="text-sm text-slate-400 mt-1">Validacion en vivo de endpoints y que se esta renderizando en el dashboard.</p>
                </div>
                <button @click="apiHealthModal = false" type="button" class="h-10 w-10 rounded-full bg-slate-800 text-slate-200 hover:bg-slate-700">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="p-6 space-y-6">
                <section class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Integracion</p>
                        <p class="text-sm text-slate-200 mt-2" x-text="apiHealth.context.integration_name || '--'"></p>
                    </div>
                    <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Location ID</p>
                        <p class="text-sm text-cyan-200 mt-2 break-all" x-text="apiHealth.context.location_id || '--'"></p>
                    </div>
                    <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Rango</p>
                        <p class="text-sm text-slate-200 mt-2" x-text="`${filters.start_date || '--'} → ${filters.end_date || '--'}`"></p>
                    </div>
                    <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Modo</p>
                        <p class="text-sm text-slate-200 mt-2" x-text="filters.fast_mode === '1' ? 'Rapido' : 'Completo'"></p>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-700 bg-slate-950/40 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <h3 class="text-base font-semibold text-white">Validacion de endpoints</h3>
                        <button @click="validateApiEndpoints" :disabled="apiHealthLoading"
                            class="px-4 py-2 rounded-lg bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 text-sm font-semibold">
                            <i class="fas mr-2" :class="apiHealthLoading ? 'fa-spinner fa-spin' : 'fa-vial-circle-check'"></i>
                            Revalidar API
                        </button>
                    </div>

                    <div x-show="apiHealthLoading" class="flex items-center gap-3 text-cyan-200 text-sm" style="display:none;">
                        <div class="h-5 w-5 rounded-full border-2 border-cyan-300 border-t-transparent animate-spin"></div>
                        <span>Probando endpoints contra API...</span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3" x-show="!apiHealthLoading" style="display:none;">
                        <template x-for="endpoint in apiHealth.endpoints" :key="endpoint.name">
                            <article class="rounded-xl border border-slate-700 bg-slate-900/60 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <h4 class="text-sm font-semibold text-slate-100" x-text="endpoint.label"></h4>
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold"
                                        :class="endpoint.success ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40' : 'bg-rose-500/20 text-rose-300 border border-rose-500/40'"
                                        x-text="endpoint.success ? 'OK' : 'Error'"></span>
                                </div>
                                <div class="mt-3 text-xs text-slate-400 space-y-1">
                                    <p>HTTP: <span class="text-slate-200" x-text="endpoint.status"></span></p>
                                    <p>Tiempo: <span class="text-slate-200" x-text="`${endpoint.elapsed_ms} ms`"></span></p>
                                    <p x-show="endpoint.summary" style="display:none;" x-text="endpoint.summary"></p>
                                    <p x-show="endpoint.message" style="display:none;" class="text-rose-300" x-text="endpoint.message"></p>
                                </div>
                            </article>
                        </template>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-700 bg-slate-950/40 p-5">
                    <h3 class="text-base font-semibold text-white mb-4">Cobertura de reportes en UI</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        <template x-for="item in uiCoverageChecks()" :key="item.key">
                            <div class="rounded-xl border p-3"
                                :class="item.ok ? 'border-emerald-500/40 bg-emerald-500/10' : 'border-amber-500/40 bg-amber-500/10'">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium" :class="item.ok ? 'text-emerald-200' : 'text-amber-200'" x-text="item.label"></p>
                                    <i class="fas" :class="item.ok ? 'fa-circle-check text-emerald-300' : 'fa-circle-minus text-amber-300'"></i>
                                </div>
                                <p class="text-xs mt-2 text-slate-300" x-text="item.detail"></p>
                            </div>
                        </template>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-700 bg-slate-950/40 p-5" x-show="meta.performance_ms" style="display:none;">
                    <h3 class="text-base font-semibold text-white mb-4">Desglose de rendimiento (ultima carga)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                        <div class="rounded-lg border border-slate-700 p-3">
                            <p class="text-slate-400">Interacciones API</p>
                            <p class="text-cyan-200 mt-1" x-text="`${(meta.performance_ms || {}).interactions_fetch_ms || 0} ms`"></p>
                        </div>
                        <div class="rounded-lg border border-slate-700 p-3">
                            <p class="text-slate-400">Totales interacción</p>
                            <p class="text-cyan-200 mt-1" x-text="`${(meta.performance_ms || {}).interaction_totals_ms || 0} ms`"></p>
                        </div>
                        <div class="rounded-lg border border-slate-700 p-3">
                            <p class="text-slate-400">Voice AI calls</p>
                            <p class="text-cyan-200 mt-1" x-text="`${(meta.performance_ms || {}).voice_ai_calls_ms || 0} ms`"></p>
                        </div>
                        <div class="rounded-lg border border-slate-700 p-3">
                            <p class="text-slate-400">Tiempo total</p>
                            <p class="text-emerald-200 mt-1" x-text="`${(meta.performance_ms || {}).total_ms || 0} ms`"></p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>

<script>
    const voiceAiInitialConfig = <?= json_encode($configStatus, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const voiceAiDefaults = <?= json_encode([
        'start_date' => $defaultStart,
        'end_date' => $defaultEnd,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
    document.addEventListener('alpine:init', () => {
        const charts = {};

        const createEmptyDashboard = () => ({
            kpis: {},
            distributions: {
                channels: {},
                directions: {},
                statuses: {},
                sources: {},
                call_dispositions: {}
            },
            timeline: {
                by_day: {},
                by_weekday: {},
                by_day_channels: {},
                by_hour: {},
                by_hour_direction: {
                    inbound: {},
                    outbound: {}
                }
            },
            agents: [],
            contacts: [],
            queue_by_user: [],
            numbers: [],
            numbers_usage: [],
            message_breakdown: [],
            call_dispositions: [],
            disposition_by_user: [],
            users_catalog: [],
            numbers_catalog: [],
            recent_interactions: [],
            recent_calls: [],
            voice_ai_recent_calls: [],
            recent_inbound_calls: [],
            recent_outbound_calls: [],
            recent_messages: [],
            summary: {},
            voice_ai_coverage: {},
            agents_catalog: [],
            conversations_snapshot: []
        });

        const upsertChart = (id, config) => {
            const canvas = document.getElementById(id);
            if (!canvas) return;

            if (charts[id]) {
                charts[id].data = config.data;
                charts[id].options = config.options;
                charts[id].update();
                return;
            }

            charts[id] = new Chart(canvas.getContext('2d'), config);
        };

        Alpine.data('voiceAiReports', () => ({
            isLoading: false,
            savingConfig: false,
            detailLoading: false,
            detailModal: false,
            error: null,
            notice: null,
            meta: {},
            configStatus: voiceAiInitialConfig,
            availableFilters: {
                interaction_channels: [],
                interaction_directions: [],
                interaction_statuses: [],
                interaction_dispositions: [],
                interaction_sources: [],
                interaction_users: []
            },
            dashboard: createEmptyDashboard(),
            activeCall: null,
            filters: {
                integration_id: voiceAiInitialConfig.selected_integration_id ? String(voiceAiInitialConfig.selected_integration_id) : '',
                start_date: voiceAiDefaults.start_date,
                end_date: voiceAiDefaults.end_date,
                interaction_channel: '',
                direction: '',
                status: '',
                disposition: '',
                source: '',
                user_id: '',
                search: '',
                fast_mode: '1',
                with_comparison: false,
                sort_order: 'desc'
            },
            configForm: {
                integration_id: voiceAiInitialConfig.selected_integration_id ? String(voiceAiInitialConfig.selected_integration_id) : '',
                integration_name: voiceAiInitialConfig.selected_integration_name || '',
                api_key: '',
                location_id: voiceAiInitialConfig.location_id || '',
                timezone: voiceAiInitialConfig.timezone || 'UTC',
                page_size: voiceAiInitialConfig.page_size || 50,
                max_pages: voiceAiInitialConfig.max_pages || 10,
                interaction_page_size: voiceAiInitialConfig.interaction_page_size || 100,
                interaction_max_pages: voiceAiInitialConfig.interaction_max_pages || 200,
                set_as_default: voiceAiInitialConfig.selected_integration_id && voiceAiInitialConfig.default_integration_id
                    ? String(voiceAiInitialConfig.selected_integration_id) === String(voiceAiInitialConfig.default_integration_id)
                    : false
            },
            tablePaginationDefaults: {
                agents_catalog: 5,
                conversations_snapshot: 8,
                queue_by_user: 8,
                numbers: 8,
                numbers_usage: 8,
                agents: 8,
                contacts: 8,
                message_breakdown: 8,
                call_dispositions: 8,
                disposition_by_user: 8,
                recent_inbound_calls: 8,
                recent_outbound_calls: 8,
                voice_ai_recent_calls: 8,
                recent_interactions: 15
            },
            tablePagination: {},
            apiHealthModal: false,
            apiHealthLoading: false,
            apiHealth: {
                context: {
                    integration_name: '',
                    location_id: ''
                },
                endpoints: []
            },
            dashboardCache: {},
            cacheTtlMs: 90000,
            cacheHit: false,
            lastLoadedAt: null,
            activeDashboardRequest: null,
            loadingStage: '',
            loadingStageProgress: 0,

            activeMegaTab: 'dispositions',
            megaTabs: [
                { key: 'dispositions',  label: 'Disposiciones',    icon: 'fa-list-check',  group: 'data' },
                { key: 'opportunities', label: 'Pipeline',         icon: 'fa-sack-dollar', group: 'data' },
                { key: 'appointments',  label: 'Citas',            icon: 'fa-calendar-check', group: 'data' },
                { key: 'forms',         label: 'Forms/Surveys',    icon: 'fa-clipboard-list', group: 'data' },
                { key: 'automations',   label: 'Workflows/Campañas', icon: 'fa-diagram-project', group: 'data' },
                { key: 'contacts',      label: 'Contactos',        icon: 'fa-users',       group: 'data' },
                { key: 'ai_executive',  label: 'Resumen IA',       icon: 'fa-wand-magic-sparkles', group: 'ai' },
                { key: 'ai_coaching',   label: 'Coaching IA',      icon: 'fa-headset',     group: 'ai' },
                { key: 'ai_risk',       label: 'Riesgo & Oport.',  icon: 'fa-shield-halved', group: 'ai' },
                { key: 'ai_anomalies',  label: 'Anomalías',        icon: 'fa-triangle-exclamation', group: 'ai' },
                { key: 'ai_forecast',   label: 'Forecast',         icon: 'fa-arrow-trend-up', group: 'ai' },
                { key: 'ai_chat',       label: 'Pregunta natural', icon: 'fa-comments',    group: 'ai' }
            ],
            dispositionsReport: { loading: false, data: null, error: null, loaded: false },
            megaAi: { enabled: true, model: '', message: '' },
            megaReports: {
                opportunities: { loading: false, data: null, error: null },
                appointments: { loading: false, data: null, error: null },
                forms: { loading: false, data: null, error: null },
                surveys: { loading: false, data: null, error: null },
                workflows: { loading: false, data: null, error: null },
                campaigns: { loading: false, data: null, error: null },
                contacts_growth: { loading: false, data: null, error: null }
            },
            aiInsights: {
                executive: { loading: false, content: '', error: null, meta: '' },
                coaching: { loading: false, content: '', error: null, meta: '' },
                risk: { loading: false, content: '', error: null, meta: '' },
                anomalies: { loading: false, content: '', error: null, meta: '' },
                forecast: { loading: false, content: '', error: null, meta: '' },
                opportunities: { loading: false, content: '', error: null, meta: '' },
                chat: { loading: false, content: '', error: null, meta: '' }
            },
            naturalQuestion: '',
            naturalHistory: [],

            init() {
                Chart.defaults.color = '#94a3b8';
                Chart.defaults.borderColor = 'rgba(71, 85, 105, 0.45)';
                Chart.defaults.font.family = "'Inter', sans-serif";
                this.resetTablePages();
                
                // Cargar location guardada en localStorage o usar la default
                const savedLocation = localStorage.getItem('voiceai_selected_location');
                const defaultLocationId = this.configStatus.default_integration_id ? String(this.configStatus.default_integration_id) : null;
                const availableLocationIds = (this.configStatus.integrations || []).map(i => String(i.integration_id));
                
                let initialLocationId = null;
                if (savedLocation && availableLocationIds.includes(savedLocation)) {
                    initialLocationId = savedLocation;
                } else if (defaultLocationId && availableLocationIds.includes(defaultLocationId)) {
                    initialLocationId = defaultLocationId;
                } else if (availableLocationIds.length > 0) {
                    initialLocationId = availableLocationIds[0];
                }

                if (initialLocationId) {
                    this.filters.integration_id = initialLocationId;
                    this.configForm.integration_id = initialLocationId;
                }

                if (!this.filters.integration_id && this.configStatus.selected_integration_id) {
                    this.filters.integration_id = String(this.configStatus.selected_integration_id);
                }
                this.loadConfigIntegration(this.configForm.integration_id);

                this.$nextTick(() => {
                    this.renderCharts();
                    if (this.configStatus.is_ready) {
                        this.fetchDashboard();
                    }
                });
            },

            normalizeDashboardPayload(incomingDashboard) {
                const base = createEmptyDashboard();
                const incoming = (incomingDashboard && typeof incomingDashboard === 'object') ? incomingDashboard : {};
                const incomingTimeline = (incoming.timeline && typeof incoming.timeline === 'object') ? incoming.timeline : {};

                return {
                    ...base,
                    ...incoming,
                    kpis: (incoming.kpis && typeof incoming.kpis === 'object') ? incoming.kpis : base.kpis,
                    distributions: {
                        ...base.distributions,
                        ...((incoming.distributions && typeof incoming.distributions === 'object') ? incoming.distributions : {})
                    },
                    timeline: {
                        ...base.timeline,
                        ...incomingTimeline,
                        by_hour_direction: {
                            ...base.timeline.by_hour_direction,
                            ...((incomingTimeline.by_hour_direction && typeof incomingTimeline.by_hour_direction === 'object') ? incomingTimeline.by_hour_direction : {})
                        }
                    },
                    agents: Array.isArray(incoming.agents) ? incoming.agents : base.agents,
                    contacts: Array.isArray(incoming.contacts) ? incoming.contacts : base.contacts,
                    queue_by_user: Array.isArray(incoming.queue_by_user) ? incoming.queue_by_user : base.queue_by_user,
                    numbers: Array.isArray(incoming.numbers) ? incoming.numbers : base.numbers,
                    numbers_usage: Array.isArray(incoming.numbers_usage) ? incoming.numbers_usage : base.numbers_usage,
                    message_breakdown: Array.isArray(incoming.message_breakdown) ? incoming.message_breakdown : base.message_breakdown,
                    call_dispositions: Array.isArray(incoming.call_dispositions) ? incoming.call_dispositions : base.call_dispositions,
                    disposition_by_user: Array.isArray(incoming.disposition_by_user) ? incoming.disposition_by_user : base.disposition_by_user,
                    users_catalog: Array.isArray(incoming.users_catalog) ? incoming.users_catalog : base.users_catalog,
                    numbers_catalog: Array.isArray(incoming.numbers_catalog) ? incoming.numbers_catalog : base.numbers_catalog,
                    recent_interactions: Array.isArray(incoming.recent_interactions) ? incoming.recent_interactions : base.recent_interactions,
                    recent_calls: Array.isArray(incoming.recent_calls) ? incoming.recent_calls : base.recent_calls,
                    voice_ai_recent_calls: Array.isArray(incoming.voice_ai_recent_calls) ? incoming.voice_ai_recent_calls : base.voice_ai_recent_calls,
                    recent_inbound_calls: Array.isArray(incoming.recent_inbound_calls) ? incoming.recent_inbound_calls : base.recent_inbound_calls,
                    recent_outbound_calls: Array.isArray(incoming.recent_outbound_calls) ? incoming.recent_outbound_calls : base.recent_outbound_calls,
                    recent_messages: Array.isArray(incoming.recent_messages) ? incoming.recent_messages : base.recent_messages,
                    summary: (incoming.summary && typeof incoming.summary === 'object') ? incoming.summary : base.summary,
                    voice_ai_coverage: (incoming.voice_ai_coverage && typeof incoming.voice_ai_coverage === 'object') ? incoming.voice_ai_coverage : base.voice_ai_coverage,
                    agents_catalog: Array.isArray(incoming.agents_catalog) ? incoming.agents_catalog : base.agents_catalog,
                    conversations_snapshot: Array.isArray(incoming.conversations_snapshot) ? incoming.conversations_snapshot : base.conversations_snapshot
                };
            },

            kpiList() {
                return Object.values(this.dashboard.kpis || {});
            },

            resetTablePages() {
                this.tablePagination = Object.fromEntries(
                    Object.entries(this.tablePaginationDefaults).map(([key, size]) => [key, {
                        page: 1,
                        size
                    }])
                );
            },

            ensureTablePagination(key) {
                if (!this.tablePagination[key]) {
                    this.tablePagination[key] = {
                        page: 1,
                        size: this.tablePaginationDefaults[key] || 10
                    };
                }

                return this.tablePagination[key];
            },

            paginatedItems(key, items) {
                const list = Array.isArray(items) ? items : [];
                const state = this.ensureTablePagination(key);
                const totalPages = Math.max(1, Math.ceil(list.length / state.size));

                if (state.page > totalPages) {
                    state.page = totalPages;
                }
                if (state.page < 1) {
                    state.page = 1;
                }

                const start = (state.page - 1) * state.size;
                return list.slice(start, start + state.size);
            },

            tableTotalPages(key, items) {
                const list = Array.isArray(items) ? items : [];
                const state = this.ensureTablePagination(key);
                return Math.max(1, Math.ceil(list.length / state.size));
            },

            tableRangeStart(key, items) {
                const list = Array.isArray(items) ? items : [];
                if (list.length === 0) {
                    return 0;
                }

                const state = this.ensureTablePagination(key);
                return ((state.page - 1) * state.size) + 1;
            },

            tableRangeEnd(key, items) {
                const list = Array.isArray(items) ? items : [];
                if (list.length === 0) {
                    return 0;
                }

                const state = this.ensureTablePagination(key);
                return Math.min(state.page * state.size, list.length);
            },

            setTablePage(key, page, items) {
                const state = this.ensureTablePagination(key);
                const totalPages = this.tableTotalPages(key, items);
                state.page = Math.min(Math.max(1, page), totalPages);
            },

            nextTablePage(key, items) {
                const state = this.ensureTablePagination(key);
                this.setTablePage(key, state.page + 1, items);
            },

            prevTablePage(key, items) {
                const state = this.ensureTablePagination(key);
                this.setTablePage(key, state.page - 1, items);
            },

            setTablePageSize(key, size) {
                const state = this.ensureTablePagination(key);
                const nextSize = Number(size);
                state.size = Number.isFinite(nextSize) && nextSize > 0 ? nextSize : (this.tablePaginationDefaults[key] || 10);
                state.page = 1;
            },

            iconWrapClass(color) {
                const map = {
                    cyan: 'bg-cyan-500/15 text-cyan-300 border border-cyan-400/20',
                    emerald: 'bg-emerald-500/15 text-emerald-300 border border-emerald-400/20',
                    amber: 'bg-amber-500/15 text-amber-200 border border-amber-400/20',
                    blue: 'bg-blue-500/15 text-blue-300 border border-blue-400/20',
                    orange: 'bg-orange-500/15 text-orange-200 border border-orange-400/20',
                    indigo: 'bg-indigo-500/15 text-indigo-200 border border-indigo-400/20'
                };
                return map[color] || 'bg-slate-800 text-slate-200 border border-slate-700';
            },

            deltaClass(comparison) {
                if (!comparison || comparison.delta_pct === null) {
                    return 'text-slate-500';
                }
                return comparison.delta >= 0 ? 'text-emerald-300' : 'text-rose-300';
            },

            formatDelta(comparison) {
                if (!comparison || comparison.delta_pct === null) {
                    return 'Sin base comparativa';
                }

                const sign = comparison.delta >= 0 ? '+' : '';
                return `${sign}${comparison.delta_pct}% vs periodo anterior`;
            },

            resetFilters() {
                const currentIntegrationId = this.filters.integration_id || (this.configStatus.selected_integration_id ? String(this.configStatus.selected_integration_id) : '');
                this.filters = {
                    integration_id: currentIntegrationId,
                    start_date: voiceAiDefaults.start_date,
                    end_date: voiceAiDefaults.end_date,
                    interaction_channel: '',
                    direction: '',
                    status: '',
                    disposition: '',
                    source: '',
                    user_id: '',
                    search: '',
                    fast_mode: '1',
                    with_comparison: false,
                    sort_order: 'desc'
                };
            },

            startNewIntegration() {
                this.configForm = {
                    integration_id: '',
                    integration_name: '',
                    api_key: '',
                    location_id: '',
                    timezone: this.configStatus.timezone || 'America/La_Paz',
                    page_size: this.configStatus.page_size || 50,
                    max_pages: this.configStatus.max_pages || 10,
                    interaction_page_size: this.configStatus.interaction_page_size || 100,
                    interaction_max_pages: this.configStatus.interaction_max_pages || 200,
                    set_as_default: false
                };
            },

            loadConfigIntegration(integrationId) {
                if (!integrationId) {
                    this.startNewIntegration();
                    return;
                }

                const integration = (this.configStatus.integrations || []).find((item) => String(item.integration_id) === String(integrationId));
                if (!integration) {
                    return;
                }

                this.configForm = {
                    integration_id: String(integration.integration_id),
                    integration_name: integration.integration_name || '',
                    api_key: '',
                    location_id: integration.location_id || '',
                    timezone: integration.timezone || 'America/La_Paz',
                    page_size: integration.page_size || 50,
                    max_pages: integration.max_pages || 10,
                    interaction_page_size: integration.interaction_page_size || 100,
                    interaction_max_pages: integration.interaction_max_pages || 200,
                    set_as_default: String(integration.integration_id) === String(this.configStatus.default_integration_id || '')
                };
            },

            changeLocation(integrationId) {
                if (!integrationId || this.filters.integration_id === integrationId) {
                    return;
                }

                this.filters.integration_id = integrationId;
                localStorage.setItem('voiceai_selected_location', integrationId);
                this.resetTablePages();
                this.invalidateMegaCaches();

                if (this.configStatus.is_ready) {
                    this.fetchDashboard();
                }
            },

            invalidateMegaCaches() {
                // Clear dashboard cache (uses query string as key)
                this.dashboardCache = {};
                // Clear dispositions report
                this.dispositionsReport = { loading: false, data: null, error: null, loaded: false };
                // Clear mega reports (pipeline/appointments/forms/surveys/etc)
                Object.keys(this.megaReports).forEach(k => {
                    this.megaReports[k] = { loading: false, data: null, error: null };
                });
                // Clear AI insights
                Object.keys(this.aiInsights).forEach(k => {
                    this.aiInsights[k] = { loading: false, content: '', error: null, meta: '' };
                });
            },

            buildQueryParams() {
                const fastMode = this.filters.fast_mode === '0' ? '0' : '1';
                const withComparison = fastMode === '1' ? '0' : (this.filters.with_comparison ? '1' : '0');
                const selectedIntegration = (this.configStatus.integrations || []).find(
                    (item) => String(item.integration_id) === String(this.filters.integration_id || '')
                );
                const callPageSize = fastMode === '1'
                    ? 25
                    : Number(selectedIntegration?.page_size || this.configStatus.page_size || 50);
                const callMaxPages = fastMode === '1'
                    ? 3
                    : Number(selectedIntegration?.max_pages || this.configStatus.max_pages || 10);
                const interactionPageSize = fastMode === '1'
                    ? 50
                    : Number(selectedIntegration?.interaction_page_size || this.configStatus.interaction_page_size || 100);
                const interactionMaxPages = fastMode === '1'
                    ? 12
                    : Number(selectedIntegration?.interaction_max_pages || this.configStatus.interaction_max_pages || 50);

                return new URLSearchParams({
                    integration_id: this.filters.integration_id || '',
                    start_date: this.filters.start_date || '',
                    end_date: this.filters.end_date || '',
                    interaction_channel: this.filters.interaction_channel || '',
                    direction: this.filters.direction || '',
                    status: this.filters.status || '',
                    disposition: this.filters.disposition || '',
                    source: this.filters.source || '',
                    user_id: this.filters.user_id || '',
                    search: this.filters.search || '',
                    fast_mode: fastMode,
                    with_comparison: withComparison,
                    sort_order: this.filters.sort_order || 'desc',
                    page_size: String(callPageSize),
                    max_pages: String(callMaxPages),
                    interaction_page_size: String(interactionPageSize),
                    interaction_max_pages: String(interactionMaxPages)
                });
            },

            async parseJsonResponse(response, fallbackMessage) {
                const rawText = await response.text();

                if (!rawText) {
                    throw new Error(fallbackMessage || 'El servidor devolvio una respuesta vacia.');
                }

                try {
                    return JSON.parse(rawText);
                } catch (error) {
                    const cleaned = rawText
                        .replace(/<br\s*\/?>/gi, ' ')
                        .replace(/<[^>]+>/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim();

                    throw new Error(cleaned || fallbackMessage || 'El servidor devolvio una respuesta no valida.');
                }
            },

            buildNotice(meta) {
                const interactionTotals = meta.interaction_totals || {};
                const totalMs = Number((meta.performance_ms || {}).total_ms || 0);
                const loadSpeed = totalMs > 0 ? `Carga API: ${totalMs} ms.` : '';
                const selectedIntegration = (this.configStatus.integrations || []).find(
                    (item) => String(item.integration_id) === String(this.filters.integration_id || '')
                );
                const selectedLabel = selectedIntegration
                    ? `${selectedIntegration.integration_name} (${selectedIntegration.location_id})`
                    : 'la ubicacion seleccionada';
                const totalInteractions = (interactionTotals.call || 0) + (interactionTotals.sms || 0) + (interactionTotals.whatsapp || 0) + (interactionTotals.email || 0);

                if ((meta.voice_ai_total || 0) === 0 && totalInteractions === 0) {
                    return `No se encontraron interacciones para ${selectedLabel} entre ${this.filters.start_date || 'la fecha inicial'} y ${this.filters.end_date || 'la fecha final'}. Prueba ampliar el rango o cambiar de ubicacion. ${loadSpeed}`.trim();
                }
                if ((meta.voice_ai_total || 0) === 0 && (interactionTotals.call || 0) > 0) {
                    return `Voice AI no devolvio call logs, pero Conversations API si devolvio ${interactionTotals.call} llamadas y ${interactionTotals.sms || 0} SMS para este rango. ${loadSpeed}`.trim();
                }
                if ((interactionTotals.tracked_total || 0) > 0) {
                    return `Se cargaron ${interactionTotals.tracked_total} interacciones rastreadas para el rango consultado. ${loadSpeed}`.trim();
                }
                if ((meta.conversations_total || 0) > 0) {
                    return `Hay ${meta.conversations_total} conversaciones en el location, aunque el rango actual no devolvio interacciones. ${loadSpeed}`.trim();
                }
                return loadSpeed;
            },

            buildLocalInteractionDetail(item) {
                if (!item || typeof item !== 'object') {
                    return null;
                }

                return {
                    call_id: item.call_id || item.alt_id || item.id || '',
                    started_at: item.date_added || item.date_updated || '',
                    duration_seconds: Number(item.duration_seconds || 0),
                    agent_name: item.user_name || 'Sin agente',
                    contact_name: item.contact_name || '',
                    contact_phone: item.contact_phone || item.counterparty_phone || '',
                    status: item.status || 'Unknown',
                    call_type: item.channel ? `${item.channel} / ${item.direction || 'unknown'}` : (item.direction || 'unknown'),
                    disposition: item.call_disposition || '--',
                    sentiment: item.sentiment || '--',
                    action_types: [],
                    recording_url: Array.isArray(item.recording_urls) && item.recording_urls.length ? item.recording_urls[0] : '',
                    summary: item.body || item.error || 'Sin resumen disponible.',
                    transcript: item.body || 'Sin transcript disponible.',
                    channel: item.channel || '--',
                    source: item.source || '--',
                    business_number: item.business_number || '--',
                    from: item.from || '--',
                    to: item.to || '--'
                };
            },

            applyDashboardPayload(payload, fromCache = false) {
                this.meta = payload.meta || {};
                this.availableFilters = {
                    ...this.availableFilters,
                    ...(payload.available_filters || {})
                };
                this.dashboard = this.normalizeDashboardPayload(payload.dashboard);
                this.configStatus = payload.config_status || this.configStatus;
                if (!this.filters.integration_id && this.configStatus.selected_integration_id) {
                    this.filters.integration_id = String(this.configStatus.selected_integration_id);
                }
                this.resetTablePages();
                this.notice = this.buildNotice(this.meta);
                this.cacheHit = fromCache;
                this.lastLoadedAt = new Date().toISOString();
                this.renderCharts();
            },

            async fetchDashboard() {
                if (!this.configStatus.is_ready) {
                    return;
                }

                // When user triggers a manual refresh, also drop the mega
                // caches so every tab re-fetches with the current filters.
                this.invalidateMegaCaches();

                const query = this.buildQueryParams().toString();
                const cacheEntry = this.dashboardCache[query];
                const now = Date.now();

                this.error = null;
                this.notice = null;

                if (cacheEntry && (now - cacheEntry.timestamp) < this.cacheTtlMs) {
                    this.applyDashboardPayload(cacheEntry.payload, true);
                    return;
                }

                if (this.activeDashboardRequest) {
                    this.activeDashboardRequest.abort();
                }

                const controller = new AbortController();
                this.activeDashboardRequest = controller;
                this.isLoading = true;
                this.loadingStage = 'Consultando endpoints principales...';
                this.loadingStageProgress = 15;

                try {
                    const response = await fetch(`api/voice_ai_reports.php?action=dashboard&${query}`, {
                        headers: {
                            'Accept': 'application/json'
                        },
                        signal: controller.signal
                    });
                    this.loadingStage = 'Procesando respuesta de la API...';
                    this.loadingStageProgress = 68;
                    const payload = await this.parseJsonResponse(response, 'No se pudo cargar la reporteria de comunicaciones.');

                    if (!response.ok || !payload.success) {
                        throw new Error(payload.message || 'No se pudo cargar la reporteria de comunicaciones.');
                    }

                    this.dashboardCache[query] = {
                        timestamp: now,
                        payload
                    };
                    this.loadingStage = 'Renderizando metricas y graficos...';
                    this.loadingStageProgress = 92;
                    this.applyDashboardPayload(payload, false);
                } catch (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    this.error = error.message || 'Ocurrio un error al cargar el dashboard.';
                } finally {
                    if (this.activeDashboardRequest === controller) {
                        this.activeDashboardRequest = null;
                    }
                    this.loadingStage = '';
                    this.loadingStageProgress = 0;
                    this.isLoading = false;
                }
            },

            uiCoverageChecks() {
                const d = this.dashboard || {};
                return [{
                        key: 'kpis',
                        label: 'KPIs ejecutivos',
                        ok: Object.keys(d.kpis || {}).length > 0,
                        detail: `${Object.keys(d.kpis || {}).length} KPI(s) cargados`
                    },
                    {
                        key: 'channels',
                        label: 'Distribucion por canal',
                        ok: Object.keys((d.distributions || {}).channels || {}).length > 0,
                        detail: `${Object.keys((d.distributions || {}).channels || {}).length} canal(es)`
                    },
                    {
                        key: 'statuses',
                        label: 'Estados de interaccion',
                        ok: Object.keys((d.distributions || {}).statuses || {}).length > 0,
                        detail: `${Object.keys((d.distributions || {}).statuses || {}).length} estado(s)`
                    },
                    {
                        key: 'timeline',
                        label: 'Timeline interacciones',
                        ok: Object.keys((d.timeline || {}).by_day || {}).length > 0,
                        detail: `${Object.keys((d.timeline || {}).by_day || {}).length} punto(s) de tiempo`
                    },
                    {
                        key: 'activity_by_user',
                        label: 'Actividad por usuario',
                        ok: Array.isArray(d.agents) && d.agents.length > 0,
                        detail: `${(d.agents || []).length} usuario(s) en actividad`
                    },
                    {
                        key: 'top_contacts',
                        label: 'Top contactos',
                        ok: Array.isArray(d.contacts) && d.contacts.length > 0,
                        detail: `${(d.contacts || []).length} contacto(s)`
                    },
                    {
                        key: 'recent_feed',
                        label: 'Actividad reciente',
                        ok: Array.isArray(d.recent_interactions) && d.recent_interactions.length > 0,
                        detail: `${(d.recent_interactions || []).length} interaccion(es) recientes`
                    },
                    {
                        key: 'message_breakdown',
                        label: 'Breakdown de mensajeria',
                        ok: Array.isArray(d.message_breakdown) && d.message_breakdown.length > 0,
                        detail: `${(d.message_breakdown || []).length} fila(s)`
                    },
                    {
                        key: 'dispositions_table',
                        label: 'Tabla de disposiciones',
                        ok: Array.isArray(d.call_dispositions) && d.call_dispositions.length > 0,
                        detail: `${(d.call_dispositions || []).length} disposicion(es)`
                    }
                ];
            },

            async validateApiEndpoints() {
                this.apiHealthLoading = true;

                const buildSummary = (payload) => {
                    const totals = payload?.meta?.interaction_totals || {};
                    const calls = totals.call || 0;
                    const sms = totals.sms || 0;
                    const filtered = payload?.meta?.filtered_count || 0;
                    return `Llamadas: ${calls}, SMS: ${sms}, Filtradas: ${filtered}`;
                };

                try {
                    const query = this.buildQueryParams().toString();
                    const endpoints = [{
                            name: 'config_status',
                            label: 'Configuracion activa',
                            url: `api/voice_ai_reports.php?action=config_status&integration_id=${encodeURIComponent(this.filters.integration_id || '')}`
                        },
                        {
                            name: 'dashboard',
                            label: 'Dashboard principal',
                            url: `api/voice_ai_reports.php?action=dashboard&${query}`
                        },
                        {
                            name: 'disposition_analytics',
                            label: 'Analitica de disposiciones',
                            url: `api/voice_ai_reports.php?action=disposition_analytics&${query}`
                        },
                        {
                            name: 'call_quality',
                            label: 'Calidad de llamadas',
                            url: `api/voice_ai_reports.php?action=call_quality&${query}`
                        },
                        {
                            name: 'comprehensive_report',
                            label: 'Reporte integral',
                            url: `api/voice_ai_reports.php?action=comprehensive_report&${query}`
                        }
                    ];

                    const responses = await Promise.all(endpoints.map(async (endpoint) => {
                        const started = performance.now();
                        let response;
                        let payload;

                        try {
                            response = await fetch(endpoint.url, {
                                headers: {
                                    'Accept': 'application/json'
                                }
                            });
                            payload = await this.parseJsonResponse(response, `Error consultando ${endpoint.label}.`);
                        } catch (error) {
                            return {
                                name: endpoint.name,
                                label: endpoint.label,
                                status: 0,
                                elapsed_ms: Math.round(performance.now() - started),
                                success: false,
                                message: error.message || `No se pudo consultar ${endpoint.label}.`,
                                summary: ''
                            };
                        }

                        const endpointSuccess = response.ok && !!payload.success;
                        let summary = '';
                        if (endpoint.name === 'dashboard') {
                            summary = buildSummary(payload);
                        } else if (endpoint.name === 'config_status') {
                            summary = payload?.config_status?.is_ready ? 'Integracion lista' : 'Configuracion incompleta';
                        } else if (endpoint.name === 'disposition_analytics') {
                            summary = `Disposiciones: ${(payload?.disposition_stats || []).length}`;
                        } else if (endpoint.name === 'call_quality') {
                            summary = `Calls analizadas: ${payload?.quality_metrics?.total_calls || 0}`;
                        } else if (endpoint.name === 'comprehensive_report') {
                            summary = `Warnings: ${(payload?.warnings || []).filter(Boolean).length}`;
                        }

                        return {
                            name: endpoint.name,
                            label: endpoint.label,
                            status: response.status,
                            elapsed_ms: Math.round(performance.now() - started),
                            success: endpointSuccess,
                            message: endpointSuccess ? '' : (payload?.message || 'Fallo de endpoint'),
                            summary
                        };
                    }));

                    this.apiHealth.endpoints = responses;
                } finally {
                    this.apiHealthLoading = false;
                }
            },

            async openApiHealthModal() {
                const selectedIntegration = (this.configStatus.integrations || []).find(
                    (item) => String(item.integration_id) === String(this.filters.integration_id || '')
                );

                this.apiHealth.context = {
                    integration_name: selectedIntegration?.integration_name || this.configStatus.integration_name || '',
                    location_id: selectedIntegration?.location_id || this.configStatus.location_id || ''
                };
                this.apiHealthModal = true;

                if (!this.apiHealth.endpoints.length) {
                    await this.validateApiEndpoints();
                }
            },

            async saveConfig() {
                this.savingConfig = true;
                this.error = null;

                try {
                    const response = await fetch('api/voice_ai_reports.php?action=save_config', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(this.configForm)
                    });

                    const payload = await this.parseJsonResponse(response, 'No se pudo guardar la configuracion.');

                    if (!response.ok || !payload.success) {
                        throw new Error(payload.message || 'No se pudo guardar la configuracion.');
                    }

                    this.configStatus = payload.config_status || this.configStatus;
                    if (this.configStatus.selected_integration_id) {
                        this.filters.integration_id = String(this.configStatus.selected_integration_id);
                    }
                    this.loadConfigIntegration(this.configStatus.selected_integration_id ? String(this.configStatus.selected_integration_id) : '');
                    this.configForm.api_key = '';

                    if (this.configStatus.is_ready) {
                        await this.fetchDashboard();
                    }
                } catch (error) {
                    this.error = error.message || 'Error al guardar la configuracion.';
                } finally {
                    this.savingConfig = false;
                }
            },

            exportCsv() {
                if (!this.configStatus.is_ready) {
                    this.error = 'Completa la configuracion antes de exportar.';
                    return;
                }

                window.location.href = `api/voice_ai_export.php?${this.buildQueryParams().toString()}`;
            },

            async openCallDetail(callOrInteraction) {
                if (!callOrInteraction) {
                    return;
                }

                const fallbackItem = typeof callOrInteraction === 'object' ? callOrInteraction : null;
                const callId = typeof callOrInteraction === 'string'
                    ? callOrInteraction
                    : (callOrInteraction.voice_ai_call_id || callOrInteraction.call_id || callOrInteraction.alt_id || callOrInteraction.id || '');

                this.detailModal = true;
                this.detailLoading = true;
                this.activeCall = fallbackItem ? this.buildLocalInteractionDetail(fallbackItem) : null;

                try {
                    if (!callId) {
                        this.detailLoading = false;
                        return;
                    }

                    const integrationSuffix = this.filters.integration_id ? `&integration_id=${encodeURIComponent(this.filters.integration_id)}` : '';
                    const response = await fetch(`api/voice_ai_reports.php?action=call_detail&call_id=${encodeURIComponent(callId)}${integrationSuffix}`, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const payload = await this.parseJsonResponse(response, 'No se pudo cargar el detalle de la llamada.');

                    if (!response.ok || !payload.success) {
                        throw new Error(payload.message || 'No se pudo cargar el detalle de la llamada.');
                    }

                    this.activeCall = payload.call || null;
                } catch (error) {
                    if (!this.activeCall && fallbackItem) {
                        this.activeCall = this.buildLocalInteractionDetail(fallbackItem);
                    }
                    if (!this.activeCall) {
                        this.error = error.message || 'No se pudo cargar el detalle.';
                        this.detailModal = false;
                    }
                } finally {
                    this.detailLoading = false;
                }
            },

            assignedUserName(userId) {
                if (!userId) {
                    return '--';
                }
                const user = (this.dashboard.users_catalog || []).find((item) => item.id === userId);
                return user ? user.name : userId;
            },

            formatCapabilities(number) {
                const capabilities = [];
                if (number.sms_enabled) capabilities.push('SMS');
                if (number.mms_enabled) capabilities.push('MMS');
                if (number.voice_enabled) capabilities.push('Voice');
                return capabilities.length ? capabilities.join(', ') : '--';
            },

            formatDuration(seconds) {
                const total = Number(seconds || 0);
                if (!Number.isFinite(total) || total <= 0) {
                    return '0s';
                }

                const hours = Math.floor(total / 3600);
                const minutes = Math.floor((total % 3600) / 60);
                const secs = total % 60;
                const parts = [];
                if (hours > 0) parts.push(`${hours}h`);
                if (minutes > 0) parts.push(`${minutes}m`);
                if (secs > 0 || parts.length === 0) parts.push(`${secs}s`);
                return parts.join(' ');
            },

            formatDate(value) {
                if (!value) {
                    return '--';
                }

                const date = new Date(value);
                if (Number.isNaN(date.getTime())) {
                    return value;
                }

                return new Intl.DateTimeFormat('es-DO', {
                    dateStyle: 'medium',
                    timeStyle: 'short'
                }).format(date);
            },

            chartPalette(size) {
                const palette = ['#22d3ee', '#10b981', '#f59e0b', '#38bdf8', '#f97316', '#818cf8', '#fb7185', '#facc15', '#4ade80', '#c084fc'];
                return Array.from({
                    length: size
                }, (_, index) => palette[index % palette.length]);
            },

            setMegaTab(key) {
                this.activeMegaTab = key;
                if (key === 'dispositions'  && !this.dispositionsReport.loaded)      this.loadDispositionsReport();
                if (key === 'opportunities' && !this.megaReports.opportunities.data) this.loadMegaReport('opportunities');
                if (key === 'appointments'  && !this.megaReports.appointments.data)  this.loadMegaReport('appointments');
                if (key === 'forms'         && !this.megaReports.forms.data)         { this.loadMegaReport('forms'); this.loadMegaReport('surveys'); }
                if (key === 'automations'   && !this.megaReports.workflows.data)     { this.loadMegaReport('workflows'); this.loadMegaReport('campaigns'); }
                if (key === 'contacts'      && !this.megaReports.contacts_growth.data) this.loadMegaReport('contacts_growth');
            },

            async loadDispositionsReport() {
                this.dispositionsReport.loading = true;
                this.dispositionsReport.error = null;
                try {
                    const response = await fetch(`api/voice_ai_reports.php?action=dispositions_full&${this.buildMegaQuery()}`, { headers: { Accept: 'application/json' } });
                    const payload = await this.parseJsonResponse(response, 'No se pudo cargar el reporte de disposiciones.');
                    if (!response.ok || !payload.success) throw new Error(payload.message || 'Error al cargar disposiciones.');
                    this.dispositionsReport.data = payload;
                    this.dispositionsReport.loaded = true;
                    this.$nextTick(() => this.renderDispositionsCharts());
                } catch (e) {
                    this.dispositionsReport.error = e.message || 'Error desconocido.';
                } finally {
                    this.dispositionsReport.loading = false;
                }
            },

            dispositionOutcomeColor(outcome) {
                return ({
                    positive: 'text-emerald-300 bg-emerald-500/15 border-emerald-500/30',
                    negative: 'text-rose-300 bg-rose-500/15 border-rose-500/30',
                    pending:  'text-amber-300 bg-amber-500/15 border-amber-500/30',
                    unreachable: 'text-sky-300 bg-sky-500/15 border-sky-500/30',
                    other:    'text-slate-300 bg-slate-500/15 border-slate-500/30',
                    unknown:  'text-slate-400 bg-slate-500/10 border-slate-600/40'
                })[outcome] || 'text-slate-300 bg-slate-500/15 border-slate-500/30';
            },

            renderDispositionsCharts() {
                const data = this.dispositionsReport.data;
                if (!data) return;
                const stats = Array.isArray(data.stats) ? data.stats.slice(0, 12) : [];
                if (!stats.length) return;

                const palette = this.chartPalette(stats.length);

                upsertChart('vaDispoDonut', {
                    type: 'doughnut',
                    data: {
                        labels: stats.map(s => s.disposition),
                        datasets: [{ data: stats.map(s => s.total), backgroundColor: palette, borderWidth: 0 }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: {
                            legend: { position: 'right', labels: { color: '#cbd5e1', boxWidth: 10, boxHeight: 10 } }
                        }
                    }
                });

                upsertChart('vaDispoDirectionChart', {
                    type: 'bar',
                    data: {
                        labels: stats.map(s => s.disposition),
                        datasets: [
                            { label: 'Inbound',  data: stats.map(s => s.inbound),  backgroundColor: '#22d3ee' },
                            { label: 'Outbound', data: stats.map(s => s.outbound), backgroundColor: '#f97316' }
                        ]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { color: '#cbd5e1' } } },
                        scales: { x: { stacked: true, ticks: { color: '#94a3b8' } }, y: { stacked: true, beginAtZero: true, ticks: { color: '#94a3b8' } } }
                    }
                });

                const timeline = Array.isArray(data.timeline) ? data.timeline : [];
                const topDispositions = stats.slice(0, 6).map(s => s.disposition);
                const timelineDatasets = topDispositions.map((name, i) => ({
                    label: name,
                    data: timeline.map(t => t[name] || 0),
                    borderColor: palette[i],
                    backgroundColor: palette[i] + '33',
                    fill: true,
                    tension: 0.3
                }));
                upsertChart('vaDispoTimeline', {
                    type: 'line',
                    data: { labels: timeline.map(t => t.date), datasets: timelineDatasets },
                    options: {
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { color: '#cbd5e1' } } },
                        scales: { y: { beginAtZero: true, stacked: false, ticks: { color: '#94a3b8' } }, x: { ticks: { color: '#94a3b8' } } }
                    }
                });

                const buckets = data.duration_buckets || {};
                const labels = Object.keys(buckets).slice(0, 8);
                const bucketKeys = ['0-30s', '31-120s', '2-5m', '5-15m', '15m+'];
                const bucketPalette = ['#f97316', '#facc15', '#4ade80', '#22d3ee', '#818cf8'];
                upsertChart('vaDispoDurationChart', {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: bucketKeys.map((bk, i) => ({
                            label: bk,
                            data: labels.map(l => (buckets[l] || {})[bk] || 0),
                            backgroundColor: bucketPalette[i]
                        }))
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { color: '#cbd5e1' } } },
                        scales: {
                            x: { stacked: true, ticks: { color: '#94a3b8' } },
                            y: { stacked: true, beginAtZero: true, ticks: { color: '#94a3b8' } }
                        }
                    }
                });
            },

            formatMoney(value) {
                const n = Number(value || 0);
                if (!Number.isFinite(n)) return '$0';
                return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(n);
            },

            renderMarkdown(text) {
                if (!text) return '';
                const escape = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                const lines = String(text).split(/\r?\n/);
                let html = '';
                let listType = null;
                let inTable = false;
                let tableRows = [];

                const flushList = () => {
                    if (listType) { html += `</${listType}>`; listType = null; }
                };
                const flushTable = () => {
                    if (inTable) {
                        html += '<table class="min-w-full text-sm border border-slate-700 my-3"><tbody>';
                        for (const row of tableRows) {
                            const cells = row.split('|').slice(1, -1).map(c => c.trim());
                            html += '<tr>' + cells.map(c => `<td class="border border-slate-700 px-3 py-2">${escape(c)}</td>`).join('') + '</tr>';
                        }
                        html += '</tbody></table>';
                        tableRows = [];
                        inTable = false;
                    }
                };

                for (let raw of lines) {
                    const line = raw.trimEnd();
                    if (/^\|.*\|$/.test(line)) {
                        if (!inTable) { flushList(); inTable = true; }
                        if (/^\|[\s\-:|]+\|$/.test(line)) continue;
                        tableRows.push(line);
                        continue;
                    } else if (inTable) {
                        flushTable();
                    }

                    if (/^###\s+/.test(line)) { flushList(); html += `<h3 class="text-base font-semibold text-white mt-4">${escape(line.replace(/^###\s+/, ''))}</h3>`; continue; }
                    if (/^##\s+/.test(line))  { flushList(); html += `<h2 class="text-lg font-semibold text-cyan-200 mt-4">${escape(line.replace(/^##\s+/, ''))}</h2>`; continue; }
                    if (/^#\s+/.test(line))   { flushList(); html += `<h1 class="text-xl font-bold text-white mt-4">${escape(line.replace(/^#\s+/, ''))}</h1>`; continue; }
                    if (/^[-*]\s+/.test(line)) {
                        if (listType !== 'ul') { flushList(); html += '<ul class="list-disc ml-5 space-y-1 text-slate-200">'; listType = 'ul'; }
                        html += `<li>${this.mdInline(line.replace(/^[-*]\s+/, ''))}</li>`;
                        continue;
                    }
                    if (/^\d+\.\s+/.test(line)) {
                        if (listType !== 'ol') { flushList(); html += '<ol class="list-decimal ml-5 space-y-1 text-slate-200">'; listType = 'ol'; }
                        html += `<li>${this.mdInline(line.replace(/^\d+\.\s+/, ''))}</li>`;
                        continue;
                    }
                    if (line.trim() === '') { flushList(); html += '<div class="h-2"></div>'; continue; }
                    flushList();
                    html += `<p class="text-slate-200 leading-relaxed">${this.mdInline(line)}</p>`;
                }
                flushList();
                flushTable();
                return html;
            },

            mdInline(s) {
                const escape = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                let out = escape(s);
                out = out.replace(/\*\*(.+?)\*\*/g, '<strong class="text-white">$1</strong>');
                out = out.replace(/`([^`]+)`/g, '<code class="bg-slate-800 text-cyan-200 px-1 rounded">$1</code>');
                out = out.replace(/\*(.+?)\*/g, '<em>$1</em>');
                return out;
            },

            buildMegaQuery() {
                const qp = this.buildQueryParams();
                return qp.toString();
            },

            async loadMegaReport(reportKey) {
                const endpoints = {
                    opportunities: 'opportunities_report',
                    appointments: 'appointments_report',
                    forms: 'forms_report',
                    surveys: 'surveys_report',
                    workflows: 'workflows_report',
                    campaigns: 'campaigns_report',
                    contacts_growth: 'contacts_growth'
                };
                const action = endpoints[reportKey];
                if (!action) return;
                const slot = this.megaReports[reportKey];
                slot.loading = true;
                slot.error = null;
                try {
                    const response = await fetch(`api/voice_ai_reports.php?action=${action}&${this.buildMegaQuery()}`, { headers: { Accept: 'application/json' } });
                    const payload = await this.parseJsonResponse(response, 'No se pudo cargar el reporte.');
                    if (!response.ok || !payload.success) throw new Error(payload.message || 'Error al cargar reporte.');
                    slot.data = payload;
                } catch (e) {
                    slot.error = e.message || 'Error desconocido.';
                } finally {
                    slot.loading = false;
                }
            },

            dashboardHasData() {
                const d = this.dashboard || {};
                const kpis = d.kpis || {};
                for (const k in kpis) {
                    if ((kpis[k]?.value || 0) > 0) return true;
                }
                if (Array.isArray(d.recent_interactions) && d.recent_interactions.length > 0) return true;
                if (Array.isArray(d.call_dispositions) && d.call_dispositions.length > 0) return true;
                if (Array.isArray(d.agents) && d.agents.length > 0) return true;
                return false;
            },

            async runAiInsight(kind) {
                const actionMap = {
                    executive: 'ai_executive_summary',
                    coaching: 'ai_agent_coaching',
                    risk: 'ai_risk_opportunity',
                    anomalies: 'ai_anomalies',
                    forecast: 'ai_forecast',
                    opportunities: 'ai_opportunities'
                };
                const action = actionMap[kind];
                if (!action) return;
                const slot = this.aiInsights[kind];
                slot.loading = true;
                slot.error = null;

                // Prefetch dashboard if we don't have any data yet —
                // saves the user from the "Sin datos disponibles" answer.
                if (!this.dashboardHasData() && this.configStatus.is_ready) {
                    slot.meta = 'Cargando dashboard primero...';
                    try {
                        await this.fetchDashboard();
                    } catch (e) {
                        // ignore, the AI call will still surface the issue
                    }
                }

                try {
                    const response = await fetch(`api/voice_ai_reports.php?action=${action}&${this.buildMegaQuery()}`, { headers: { Accept: 'application/json' } });
                    const payload = await this.parseJsonResponse(response, 'No se pudo generar el análisis.');
                    if (!payload.success) {
                        // Treat the backend's "no data" response as a soft
                        // error (message field), not a hard failure.
                        throw new Error(payload.error || payload.message || 'Fallo al generar.');
                    }
                    slot.content = payload.content || '';
                    slot.meta = `Modelo: ${payload.model || '?'} · ${payload.cached ? 'cache hit' : 'cache miss'} · ${payload.generated_at || ''}`;
                } catch (e) {
                    slot.error = e.message || 'Error desconocido.';
                } finally {
                    slot.loading = false;
                }
            },

            async askNatural() {
                if (!this.naturalQuestion.trim()) return;
                const entry = { question: this.naturalQuestion.trim(), answer: '', error: null };
                this.naturalHistory.unshift(entry);
                const originalQ = entry.question;
                this.naturalQuestion = '';
                this.aiInsights.chat.loading = true;

                if (!this.dashboardHasData() && this.configStatus.is_ready) {
                    try { await this.fetchDashboard(); } catch (e) {}
                }

                try {
                    const response = await fetch(`api/voice_ai_reports.php?action=ai_natural_query&${this.buildMegaQuery()}`, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                        body: JSON.stringify({ question: originalQ })
                    });
                    const payload = await this.parseJsonResponse(response, 'No se pudo procesar la pregunta.');
                    if (!response.ok || !payload.success) throw new Error(payload.error || payload.message || 'Error al consultar.');
                    entry.answer = payload.content || '';
                } catch (e) {
                    entry.error = e.message || 'Error desconocido.';
                } finally {
                    this.aiInsights.chat.loading = false;
                }
            },

            async fetchAiHealth() {
                try {
                    const response = await fetch('api/voice_ai_reports.php?action=ai_health', { headers: { Accept: 'application/json' } });
                    const payload = await response.json();
                    this.megaAi = {
                        enabled: !!payload.enabled,
                        model: payload.model || '',
                        message: payload.message || ''
                    };
                    if (!payload.success) {
                        this.error = `IA: ${payload.message || 'sin estado'}`;
                    } else {
                        this.notice = `IA lista — modelo ${payload.model || '(default)'}`;
                    }
                } catch (e) {
                    this.error = 'No se pudo verificar la IA: ' + e.message;
                }
            },

            renderCharts() {
                const timelineLabels = Object.keys(this.dashboard.timeline.by_day || {});
                const timelineValues = Object.values(this.dashboard.timeline.by_day || {});
                const typeLabels = Object.keys(this.dashboard.distributions.channels || {});
                const typeValues = Object.values(this.dashboard.distributions.channels || {});
                const statusLabels = Object.keys(this.dashboard.distributions.statuses || {});
                const statusValues = Object.values(this.dashboard.distributions.statuses || {});
                const actionEntries = Object.entries(this.dashboard.distributions.sources || {}).slice(0, 10);
                const callDirectionEntries = Object.entries(this.dashboard.distributions.call_directions || {})
                    .filter(([key]) => ['inbound', 'outbound'].includes(key));
                const messageDirectionEntries = Object.entries(this.dashboard.distributions.message_directions || {})
                    .filter(([key]) => ['inbound', 'outbound'].includes(key));
                const dispositionEntries = Object.entries(this.dashboard.distributions.call_dispositions || {}).slice(0, 15);
                const weekdayEntries = Object.entries(this.dashboard.timeline.by_weekday || {});
                const hourLabels = Object.keys((this.dashboard.timeline.by_hour_direction || {}).inbound || {});
                const hourInboundValues = Object.values((this.dashboard.timeline.by_hour_direction || {}).inbound || {});
                const hourOutboundValues = Object.values((this.dashboard.timeline.by_hour_direction || {}).outbound || {});
                const channelDirectionEntries = Object.entries(this.dashboard.distributions.channel_directions || {});

                upsertChart('voiceAiTimelineChart', {
                    type: 'line',
                    data: {
                        labels: timelineLabels,
                        datasets: [{
                            label: 'Interacciones',
                            data: timelineValues,
                            borderColor: '#22d3ee',
                            backgroundColor: 'rgba(34, 211, 238, 0.18)',
                            fill: true,
                            tension: 0.35
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });

                upsertChart('voiceAiTypeChart', {
                    type: 'doughnut',
                    data: {
                        labels: typeLabels,
                        datasets: [{
                            data: typeValues,
                            backgroundColor: this.chartPalette(typeLabels.length)
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });

                upsertChart('voiceAiStatusChart', {
                    type: 'bar',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            label: 'Estados',
                            data: statusValues,
                            backgroundColor: this.chartPalette(statusLabels.length)
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            x: {
                                ticks: {
                                    color: '#cbd5e1'
                                }
                            },
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });

                upsertChart('voiceAiActionChart', {
                    type: 'bar',
                    data: {
                        labels: actionEntries.map(([label]) => label),
                        datasets: [{
                            label: 'Origen',
                            data: actionEntries.map(([, value]) => value),
                            backgroundColor: '#f97316'
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true
                            }
                        }
                    }
                });

                upsertChart('voiceAiCallDirectionChart', {
                    type: 'bar',
                    data: {
                        labels: callDirectionEntries.map(([label]) => label.toUpperCase()),
                        datasets: [{
                            label: 'Llamadas',
                            data: callDirectionEntries.map(([, value]) => value),
                            backgroundColor: ['#22d3ee', '#f97316']
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });

                upsertChart('voiceAiMessageDirectionChart', {
                    type: 'bar',
                    data: {
                        labels: messageDirectionEntries.map(([label]) => label.toUpperCase()),
                        datasets: [{
                            label: 'Mensajes',
                            data: messageDirectionEntries.map(([, value]) => value),
                            backgroundColor: ['#10b981', '#f59e0b']
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });

                upsertChart('voiceAiWeekdayChart', {
                    type: 'bar',
                    data: {
                        labels: weekdayEntries.map(([label]) => label),
                        datasets: [{
                            label: 'Interacciones',
                            data: weekdayEntries.map(([, value]) => value),
                            backgroundColor: '#818cf8'
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });

                upsertChart('voiceAiHourDirectionChart', {
                    type: 'line',
                    data: {
                        labels: hourLabels,
                        datasets: [{
                                label: 'Inbound',
                                data: hourInboundValues,
                                borderColor: '#22d3ee',
                                backgroundColor: 'rgba(34, 211, 238, 0.14)',
                                fill: true,
                                tension: 0.3
                            },
                            {
                                label: 'Outbound',
                                data: hourOutboundValues,
                                borderColor: '#f97316',
                                backgroundColor: 'rgba(249, 115, 22, 0.12)',
                                fill: true,
                                tension: 0.3
                            }
                        ]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });

                upsertChart('voiceAiChannelDirectionChart', {
                    type: 'bar',
                    data: {
                        labels: channelDirectionEntries.map(([channel]) => channel),
                        datasets: [{
                                label: 'Inbound',
                                data: channelDirectionEntries.map(([, values]) => values.inbound || 0),
                                backgroundColor: '#22d3ee'
                            },
                            {
                                label: 'Outbound',
                                data: channelDirectionEntries.map(([, values]) => values.outbound || 0),
                                backgroundColor: '#f97316'
                            }
                        ]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        },
                        responsive: true,
                        scales: {
                            x: {
                                stacked: true
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true
                            }
                        }
                    }
                });

                upsertChart('voiceAiDispositionChart', {
                    type: 'bar',
                    data: {
                        labels: dispositionEntries.map(([label]) => label),
                        datasets: [{
                            label: 'Llamadas',
                            data: dispositionEntries.map(([, value]) => value),
                            backgroundColor: this.chartPalette(dispositionEntries.length)
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
        }));
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
