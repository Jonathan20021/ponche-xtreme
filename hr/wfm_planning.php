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
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Pronostico (carga CSV)</h3>
            <form id="forecastForm" class="grid grid-cols-1 md:grid-cols-3 gap-4" enctype="multipart/form-data">
                <div>
                    <label class="text-xs uppercase text-slate-400">Campana</label>
                    <select name="campaign_id" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" required>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs uppercase text-slate-400">Archivo CSV</label>
                    <input type="file" name="report_file" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" accept=".csv" required>
                </div>
                <div class="md:col-span-3">
                    <button class="px-4 py-2 rounded bg-cyan-600 text-white hover:bg-cyan-500 transition-colors">
                        <i class="fas fa-upload mr-2"></i> Cargar pronostico
                    </button>
                    <p class="text-xs text-slate-500 mt-2">
                        Columnas sugeridas: interval_start, interval_minutes, offered_volume, aht_seconds, target_sl, target_answer_seconds, occupancy_target, shrinkage.
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
                    <input type="date" id="gapStart" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" value="<?= date('Y-m-01') ?>">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Hasta</label>
                    <input type="date" id="gapEnd" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" value="<?= date('Y-m-t') ?>">
                </div>
                <button id="refreshGap" class="px-4 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-500 transition-colors">
                    <i class="fas fa-sync mr-2"></i> Actualizar dimensionamiento
                </button>
            </div>
        </div>
    </div>

    <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 mb-8">
        <h3 class="text-lg font-semibold text-slate-200 mb-4">Dimensionamiento (Horas)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-900/60 text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="p-3">Campana</th>
                        <th class="p-3">Requeridas</th>
                        <th class="p-3">Planificadas</th>
                        <th class="p-3">Gap</th>
                        <th class="p-3">Cobertura</th>
                    </tr>
                </thead>
                <tbody id="gapTotals" class="divide-y divide-slate-700 text-slate-200">
                    <tr><td colspan="5" class="p-6 text-center text-slate-500">Cargando...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 mb-8">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-200">Alertas intradia</h3>
            <button id="generateAlerts" class="px-3 py-2 rounded bg-rose-600 text-white hover:bg-rose-500 transition-colors">
                <i class="fas fa-bell mr-2"></i> Generar alertas de hoy
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-900/60 text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="p-3">Fecha</th>
                        <th class="p-3">Agente</th>
                        <th class="p-3">Campana</th>
                        <th class="p-3">Tipo</th>
                        <th class="p-3">Detalle</th>
                    </tr>
                </thead>
                <tbody id="alertTable" class="divide-y divide-slate-700 text-slate-200">
                    <tr><td colspan="5" class="p-6 text-center text-slate-500">Cargando...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const planningApi = '../api/wfm_planning.php';

async function refreshGap() {
    const start = document.getElementById('gapStart').value;
    const end = document.getElementById('gapEnd').value;
    const res = await fetch(`${planningApi}?action=staffing_gap&start_date=${start}&end_date=${end}`);
    const data = await res.json();
    if (!data.success) return;
    const rows = data.totals || [];
    if (!rows.length) {
        document.getElementById('gapTotals').innerHTML = '<tr><td colspan="5" class="p-6 text-center text-slate-500">Sin datos</td></tr>';
        return;
    }
    document.getElementById('gapTotals').innerHTML = rows.map(row => `
        <tr class="hover:bg-slate-700/30 transition-colors">
            <td class="p-3">
                <div class="font-medium text-white">${row.campaign_name}</div>
                <div class="text-xs text-slate-500">${row.campaign_code ?? ''}</div>
            </td>
            <td class="p-3 text-slate-300">${row.required_hours}</td>
            <td class="p-3 text-slate-300">${row.scheduled_hours}</td>
            <td class="p-3 ${row.gap_hours >= 0 ? 'text-emerald-400' : 'text-rose-400'}">${row.gap_hours}</td>
            <td class="p-3 text-slate-300">${row.coverage_percent}%</td>
        </tr>
    `).join('');
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
    const formData = new FormData(form);
    const res = await fetch('../api/campaign_staffing.php', { method: 'POST', body: formData });
    const payload = await res.json();
    const status = document.getElementById('forecastStatus');
    if (payload.success) {
        status.textContent = `Cargado. Insertados: ${payload.inserted}, Actualizados: ${payload.updated}, Omitidos: ${payload.skipped}`;
        refreshGap();
    } else {
        status.textContent = payload.error || 'Error cargando pronostico';
    }
});

refreshGap();
refreshAlerts();
</script>

</body>
</html>
