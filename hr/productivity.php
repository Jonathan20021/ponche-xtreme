<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

ensurePermission('productivity_dashboard');

$campaigns = $pdo->query("SELECT id, name FROM campaigns ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$supervisors = $pdo->query("
    SELECT DISTINCT u.id, u.full_name
    FROM employees e
    INNER JOIN users u ON u.id = e.supervisor_id
    WHERE e.supervisor_id IS NOT NULL
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/../header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-bold text-white">
                <i class="fas fa-bullseye text-amber-400 mr-2"></i> Productividad
            </h1>
            <p class="text-slate-400">KPIs por campana/equipo, metas, coaching y gamificacion.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 lg:col-span-2">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Filtros de KPIs</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="text-xs uppercase text-slate-400">Desde</label>
                    <input type="date" id="kpiStart" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" value="<?= date('Y-m-01') ?>">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Hasta</label>
                    <input type="date" id="kpiEnd" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" value="<?= date('Y-m-t') ?>">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Scope</label>
                    <select id="kpiScope" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <option value="campaign">Campana</option>
                        <option value="team">Equipo (Supervisor)</option>
                        <option value="user">Agente</option>
                    </select>
                </div>
            </div>
            <div class="mt-4">
                <button id="refreshKpis" class="px-4 py-2 rounded bg-cyan-600 text-white hover:bg-cyan-500 transition-colors">
                    <i class="fas fa-sync mr-2"></i> Actualizar KPIs
                </button>
            </div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Resumen</h3>
            <div id="kpiTotals" class="space-y-2 text-sm text-slate-300">
                <div class="text-slate-500">Cargando...</div>
            </div>
        </div>
    </div>

    <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5 mb-8">
        <h3 class="text-lg font-semibold text-slate-200 mb-4">KPIs</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-900/60 text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="p-3">Scope</th>
                        <th class="p-3">Horas planificadas</th>
                        <th class="p-3">Horas netas</th>
                        <th class="p-3">Horas payroll</th>
                        <th class="p-3">Adherencia</th>
                        <th class="p-3">Asistencia</th>
                        <th class="p-3">Volumen</th>
                    </tr>
                </thead>
                <tbody id="kpiTableBody" class="divide-y divide-slate-700 text-slate-200">
                    <tr><td colspan="7" class="p-6 text-center text-slate-500">Cargando...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Metas</h3>
            <form id="goalForm" class="space-y-3">
                <div>
                    <label class="text-xs uppercase text-slate-400">Scope</label>
                    <select name="scope_type" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <option value="campaign">Campana</option>
                        <option value="team">Equipo</option>
                        <option value="user">Agente</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Campana</label>
                    <select name="campaign_id" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <option value="">--</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Supervisor</label>
                    <select name="supervisor_id" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <option value="">--</option>
                        <?php foreach ($supervisors as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Agente</label>
                    <select name="user_id" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <option value="">--</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">KPI</label>
                    <select name="kpi_key" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <option value="adherence_percent">Adherencia %</option>
                        <option value="attendance_percent">Asistencia %</option>
                        <option value="real_net_hours">Horas netas</option>
                        <option value="payroll_hours">Horas payroll</option>
                        <option value="volume">Volumen</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs uppercase text-slate-400">Meta</label>
                        <input type="number" step="0.01" name="target_value" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                    </div>
                    <div>
                        <label class="text-xs uppercase text-slate-400">Tipo</label>
                        <select name="target_direction" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                            <option value="target">Target</option>
                            <option value="min">Min</option>
                            <option value="max">Max</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs uppercase text-slate-400">Desde</label>
                        <input type="date" name="start_date" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div>
                        <label class="text-xs uppercase text-slate-400">Hasta</label>
                        <input type="date" name="end_date" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" value="<?= date('Y-m-t') ?>">
                    </div>
                </div>
                <button class="w-full px-4 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-500 transition-colors">Guardar meta</button>
            </form>
            <div class="mt-4">
                <button id="refreshGoals" class="text-sm text-cyan-300 hover:text-cyan-200">Ver metas actuales</button>
                <ul id="goalList" class="mt-3 space-y-2 text-sm text-slate-300"></ul>
            </div>
        </div>

        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Coaching</h3>
            <form id="coachingForm" class="space-y-3">
                <input type="hidden" name="supervisor_id" value="<?= (int) ($_SESSION['user_id'] ?? 0) ?>">
                <div>
                    <label class="text-xs uppercase text-slate-400">Agente</label>
                    <select name="user_id" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Campana</label>
                    <select name="campaign_id" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <option value="">--</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Fecha</label>
                    <input type="date" name="session_date" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" value="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Tema</label>
                    <input type="text" name="topic" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" placeholder="Feedback, QA, proceso...">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Notas</label>
                    <textarea name="notes" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" rows="3"></textarea>
                </div>
                <button class="w-full px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-500 transition-colors">Guardar coaching</button>
            </form>
            <div class="mt-4">
                <button id="refreshCoaching" class="text-sm text-cyan-300 hover:text-cyan-200">Ver sesiones</button>
                <ul id="coachingList" class="mt-3 space-y-2 text-sm text-slate-300"></ul>
            </div>
        </div>

        <div class="bg-slate-800/60 border border-slate-700 rounded-xl p-5">
            <h3 class="text-lg font-semibold text-slate-200 mb-4">Gamificacion</h3>
            <form id="pointsForm" class="space-y-3">
                <div>
                    <label class="text-xs uppercase text-slate-400">Agente</label>
                    <select name="user_id" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Campana</label>
                    <select name="campaign_id" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200">
                        <option value="">--</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Puntos</label>
                    <input type="number" name="points" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" value="10">
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-400">Motivo</label>
                    <input type="text" name="reason" class="w-full mt-1 px-3 py-2 rounded bg-slate-900 border border-slate-700 text-slate-200" placeholder="Meta alcanzada, QA, etc">
                </div>
                <button class="w-full px-4 py-2 rounded bg-amber-600 text-white hover:bg-amber-500 transition-colors">Asignar puntos</button>
            </form>
            <div class="mt-4">
                <button id="refreshLeaderboard" class="text-sm text-cyan-300 hover:text-cyan-200">Ver ranking</button>
                <ol id="leaderboard" class="mt-3 space-y-2 text-sm text-slate-300 list-decimal list-inside"></ol>
            </div>
        </div>
    </div>
</div>

<script>
const apiBase = '../api/productivity.php';

async function fetchKpis() {
    const start = document.getElementById('kpiStart').value;
    const end = document.getElementById('kpiEnd').value;
    const scope = document.getElementById('kpiScope').value;
    const res = await fetch(`${apiBase}?action=summary&start_date=${start}&end_date=${end}&scope=${scope}`);
    const data = await res.json();
    if (!data.success) return;

    const totals = data.totals || {};
    document.getElementById('kpiTotals').innerHTML = `
        <div><span class="text-slate-400">Horas planificadas:</span> ${totals.scheduled_hours ?? 0}</div>
        <div><span class="text-slate-400">Horas netas:</span> ${totals.real_net_hours ?? 0}</div>
        <div><span class="text-slate-400">Horas payroll:</span> ${totals.payroll_hours ?? 0}</div>
        <div><span class="text-slate-400">Adherencia:</span> ${totals.adherence_percent ?? 0}%</div>
        <div><span class="text-slate-400">Asistencia:</span> ${totals.attendance_percent ?? 0}%</div>
    `;

    const rows = data.rows || [];
    if (!rows.length) {
        document.getElementById('kpiTableBody').innerHTML = '<tr><td colspan="7" class="p-6 text-center text-slate-500">Sin datos</td></tr>';
        return;
    }
    document.getElementById('kpiTableBody').innerHTML = rows.map(row => `
        <tr class="hover:bg-slate-700/30 transition-colors">
            <td class="p-3">
                <div class="font-medium text-white">${row.label}</div>
                ${row.campaign_code ? `<div class="text-xs text-slate-500">${row.campaign_code}</div>` : ''}
            </td>
            <td class="p-3 text-slate-300">${row.scheduled_hours}</td>
            <td class="p-3 text-slate-300">${row.real_net_hours}</td>
            <td class="p-3 text-slate-300">${row.payroll_hours}</td>
            <td class="p-3 text-slate-300">${row.adherence_percent}%</td>
            <td class="p-3 text-slate-300">${row.attendance_percent}%</td>
            <td class="p-3 text-slate-300">${row.volume ?? '-'}</td>
        </tr>
    `).join('');
}

async function refreshGoals() {
    const res = await fetch(`${apiBase}?action=goals`);
    const data = await res.json();
    const list = document.getElementById('goalList');
    if (!data.success || !data.goals?.length) {
        list.innerHTML = '<li class="text-slate-500">Sin metas registradas.</li>';
        return;
    }
    list.innerHTML = data.goals.map(goal => `
        <li class="bg-slate-900/60 border border-slate-700 rounded px-3 py-2">
            <div class="text-slate-200 font-medium">${goal.kpi_key} (${goal.target_direction})</div>
            <div class="text-xs text-slate-400">Meta: ${goal.target_value} | ${goal.start_date} - ${goal.end_date}</div>
        </li>
    `).join('');
}

async function refreshCoaching() {
    const res = await fetch(`${apiBase}?action=coaching`);
    const data = await res.json();
    const list = document.getElementById('coachingList');
    if (!data.success || !data.sessions?.length) {
        list.innerHTML = '<li class="text-slate-500">Sin sesiones registradas.</li>';
        return;
    }
    list.innerHTML = data.sessions.map(item => `
        <li class="bg-slate-900/60 border border-slate-700 rounded px-3 py-2">
            <div class="text-slate-200 font-medium">${item.user_name ?? 'Agente'} - ${item.topic}</div>
            <div class="text-xs text-slate-400">${item.session_date} | ${item.status}</div>
        </li>
    `).join('');
}

async function refreshLeaderboard() {
    const res = await fetch(`${apiBase}?action=leaderboard`);
    const data = await res.json();
    const list = document.getElementById('leaderboard');
    if (!data.success || !data.leaders?.length) {
        list.innerHTML = '<li class="text-slate-500">Sin datos.</li>';
        return;
    }
    list.innerHTML = data.leaders.map(item => `
        <li>${item.full_name ?? item.username} - ${item.points}</li>
    `).join('');
}

document.getElementById('refreshKpis').addEventListener('click', fetchKpis);
document.getElementById('refreshGoals').addEventListener('click', refreshGoals);
document.getElementById('refreshCoaching').addEventListener('click', refreshCoaching);
document.getElementById('refreshLeaderboard').addEventListener('click', refreshLeaderboard);

document.getElementById('goalForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const data = new URLSearchParams(new FormData(form));
    data.append('action', 'set_goal');
    const res = await fetch(apiBase, { method: 'POST', body: data });
    const payload = await res.json();
    if (payload.success) {
        form.reset();
        refreshGoals();
    }
});

document.getElementById('coachingForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const data = new URLSearchParams(new FormData(form));
    data.append('action', 'add_coaching');
    const res = await fetch(apiBase, { method: 'POST', body: data });
    const payload = await res.json();
    if (payload.success) {
        form.reset();
        refreshCoaching();
    }
});

document.getElementById('pointsForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const data = new URLSearchParams(new FormData(form));
    data.append('action', 'award_points');
    const res = await fetch(apiBase, { method: 'POST', body: data });
    const payload = await res.json();
    if (payload.success) {
        form.reset();
        refreshLeaderboard();
    }
});

fetchKpis();
refreshGoals();
refreshCoaching();
refreshLeaderboard();
</script>

</body>
</html>
