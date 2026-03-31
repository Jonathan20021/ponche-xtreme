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
    [x-cloak] {
        display: none !important;
    }
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" x-data="voiceAiReports()">
    <div class="mb-8 rounded-3xl border border-cyan-500/20 bg-slate-900/80 overflow-hidden relative">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(34,211,238,0.18),_transparent_38%),radial-gradient(circle_at_bottom_right,_rgba(249,115,22,0.15),_transparent_35%)]"></div>
        <div class="relative p-6 md:p-8 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-cyan-300/80 text-xs uppercase tracking-[0.35em] mb-3">GoHighLevel Communications</p>
                <h1 class="text-3xl md:text-4xl font-bold text-white">Reporteria integral de llamadas, mensajes y uso</h1>
                <p class="text-slate-300 mt-3 text-sm md:text-base">
                    Dashboard operativo construido sobre los endpoints publicos de Conversations, Phone System, Users y Voice AI para exprimir toda la data operativa disponible del location.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <span class="px-4 py-2 rounded-full text-sm font-semibold"
                    :class="configStatus.is_ready ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-400/30' : 'bg-amber-500/15 text-amber-200 border border-amber-400/30'">
                    <i class="fas mr-2" :class="configStatus.is_ready ? 'fa-circle-check' : 'fa-triangle-exclamation'"></i>
                    <span x-text="configStatus.is_ready ? 'Integracion lista' : 'Configuracion pendiente'"></span>
                </span>
                <button @click="exportCsv" type="button"
                    class="px-4 py-2 rounded-full bg-cyan-500 hover:bg-cyan-400 text-slate-950 text-sm font-semibold transition-colors">
                    <i class="fas fa-file-csv mr-2"></i>Exportar CSV
                </button>
            </div>
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
                <button @click="resetFilters" type="button" class="px-4 py-3 rounded-xl border border-slate-700 text-slate-200 hover:bg-slate-800 transition-colors">Resetear</button>
                <button @click="fetchDashboard" type="button" :disabled="isLoading || !configStatus.is_ready"
                    class="px-5 py-3 rounded-xl bg-cyan-500 hover:bg-cyan-400 disabled:opacity-60 text-slate-950 font-semibold transition-colors">
                    <i class="fas mr-2" :class="isLoading ? 'fa-spinner fa-spin' : 'fa-rotate-right'"></i>
                    Actualizar dashboard
                </button>
            </div>
        </div>
    </section>

    <div x-show="error" class="mb-8 rounded-2xl border border-rose-500/30 bg-rose-500/10 px-5 py-4 text-rose-100" style="display:none;">
        <i class="fas fa-circle-exclamation mr-2"></i><span x-text="error"></span>
    </div>

    <div x-show="notice" class="mb-8 rounded-2xl border border-cyan-500/25 bg-cyan-500/10 px-5 py-4 text-cyan-100" style="display:none;">
        <i class="fas fa-circle-info mr-2"></i><span x-text="notice"></span>
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
                        <th class="px-6 py-4 text-right">Recording</th>
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
                                <a x-show="interaction.has_recording" :href="(interaction.recording_urls || [])[0]" target="_blank"
                                    class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-700 text-slate-100 hover:bg-slate-700 transition-colors inline-flex items-center">
                                    Abrir
                                </a>
                                <span x-show="!interaction.has_recording" class="text-slate-500">--</span>
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
                    <h2 class="text-xl font-semibold text-white">Detalle de llamada</h2>
                    <p class="text-sm text-slate-400" x-text="activeCall ? (activeCall.call_id || 'Sin ID') : ''"></p>
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
                                        <dt class="text-slate-500">Tipo</dt>
                                        <dd class="text-slate-200" x-text="activeCall.call_type || 'Unknown'"></dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500">Estado</dt>
                                        <dd class="text-slate-200" x-text="activeCall.status || 'Unknown'"></dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500">Sentiment</dt>
                                        <dd class="text-slate-200" x-text="activeCall.sentiment || '--'"></dd>
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
                interaction_sources: [],
                interaction_users: []
            },
            dashboard: {
                kpis: {},
                distributions: {
                    channels: {},
                    directions: {},
                    statuses: {},
                    sources: {}
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
                users_catalog: [],
                numbers_catalog: [],
                recent_interactions: [],
                recent_calls: [],
                recent_inbound_calls: [],
                recent_outbound_calls: [],
                recent_messages: [],
                summary: {}
            },
            activeCall: null,
            filters: {
                integration_id: voiceAiInitialConfig.selected_integration_id ? String(voiceAiInitialConfig.selected_integration_id) : '',
                start_date: voiceAiDefaults.start_date,
                end_date: voiceAiDefaults.end_date,
                interaction_channel: '',
                direction: '',
                status: '',
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
                recent_inbound_calls: 8,
                recent_outbound_calls: 8,
                recent_interactions: 15
            },
            tablePagination: {},

            init() {
                Chart.defaults.color = '#94a3b8';
                Chart.defaults.borderColor = 'rgba(71, 85, 105, 0.45)';
                Chart.defaults.font.family = "'Inter', sans-serif";
                this.resetTablePages();
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

            buildQueryParams() {
                const fastMode = this.filters.fast_mode === '0' ? '0' : '1';
                const withComparison = fastMode === '1' ? '0' : (this.filters.with_comparison ? '1' : '0');

                return new URLSearchParams({
                    integration_id: this.filters.integration_id || '',
                    start_date: this.filters.start_date || '',
                    end_date: this.filters.end_date || '',
                    interaction_channel: this.filters.interaction_channel || '',
                    direction: this.filters.direction || '',
                    status: this.filters.status || '',
                    source: this.filters.source || '',
                    user_id: this.filters.user_id || '',
                    search: this.filters.search || '',
                    fast_mode: fastMode,
                    with_comparison: withComparison,
                    sort_order: this.filters.sort_order || 'desc'
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

            async fetchDashboard() {
                if (!this.configStatus.is_ready) {
                    return;
                }

                this.isLoading = true;
                this.error = null;
                this.notice = null;

                try {
                    const response = await fetch(`api/voice_ai_reports.php?action=dashboard&${this.buildQueryParams().toString()}`, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const payload = await this.parseJsonResponse(response, 'No se pudo cargar la reporteria de comunicaciones.');

                    if (!response.ok || !payload.success) {
                        throw new Error(payload.message || 'No se pudo cargar la reporteria de comunicaciones.');
                    }

                    this.meta = payload.meta || {};
                    this.availableFilters = payload.available_filters || this.availableFilters;
                    this.dashboard = payload.dashboard || this.dashboard;
                    this.configStatus = payload.config_status || this.configStatus;
                    if (!this.filters.integration_id && this.configStatus.selected_integration_id) {
                        this.filters.integration_id = String(this.configStatus.selected_integration_id);
                    }
                    this.resetTablePages();
                    const interactionTotals = this.meta.interaction_totals || {};
                    const totalMs = Number((this.meta.performance_ms || {}).total_ms || 0);
                    const loadSpeed = totalMs > 0 ? `Carga API: ${totalMs} ms.` : '';
                    if ((this.meta.voice_ai_total || 0) === 0 && (interactionTotals.call || 0) > 0) {
                        this.notice = `Voice AI no devolvio call logs, pero Conversations API si devolvio ${interactionTotals.call} llamadas y ${interactionTotals.sms || 0} SMS para este rango. ${loadSpeed}`.trim();
                    } else if ((interactionTotals.tracked_total || 0) > 0) {
                        this.notice = `Se cargaron ${interactionTotals.tracked_total} interacciones rastreadas para el rango consultado. ${loadSpeed}`.trim();
                    } else if ((this.meta.conversations_total || 0) > 0) {
                        this.notice = `Hay ${this.meta.conversations_total} conversaciones en el location, aunque el rango actual no devolvio interacciones. ${loadSpeed}`.trim();
                    } else if (loadSpeed) {
                        this.notice = loadSpeed;
                    }
                    this.renderCharts();
                } catch (error) {
                    this.error = error.message || 'Ocurrio un error al cargar el dashboard.';
                } finally {
                    this.isLoading = false;
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

            async openCallDetail(callId) {
                if (!callId) {
                    return;
                }

                this.detailModal = true;
                this.detailLoading = true;
                this.activeCall = null;

                try {
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
                    this.error = error.message || 'No se pudo cargar el detalle.';
                    this.detailModal = false;
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
            }
        }));
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
