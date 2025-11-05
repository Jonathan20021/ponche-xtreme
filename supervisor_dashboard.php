<?php
session_start();
require_once 'db.php';

// Check permissions
ensurePermission('supervisor_dashboard');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

include 'header.php';
?>

<style>
.supervisor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
}

.agent-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.agent-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--punch-gradient);
    transition: all 0.3s ease;
}

.agent-card:hover {
    transform: translateY(-2px);
    border-color: var(--border-hover);
    box-shadow: var(--card-shadow-hover);
}

/* Theme Variables */
.theme-dark {
    --card-bg: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.95));
    --border-color: rgba(148, 163, 184, 0.1);
    --border-hover: rgba(148, 163, 184, 0.3);
    --card-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.3);
    --text-primary: #f1f5f9;
    --text-secondary: #94a3b8;
    --text-muted: #64748b;
    --punch-status-bg: rgba(15, 23, 42, 0.6);
    --stat-badge-bg: rgba(99, 102, 241, 0.1);
    --stat-badge-border: rgba(99, 102, 241, 0.2);
    --stat-badge-text: #a5b4fc;
    --filter-btn-bg: rgba(30, 41, 59, 0.8);
    --filter-btn-border: rgba(148, 163, 184, 0.2);
    --filter-btn-text: #94a3b8;
    --filter-btn-active-bg: rgba(99, 102, 241, 0.2);
    --filter-btn-active-border: #6366f1;
    --filter-btn-active-text: #a5b4fc;
    --summary-card-bg: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.95));
    --last-update-bg: rgba(15, 23, 42, 0.4);
}

.theme-light {
    --card-bg: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.95));
    --border-color: rgba(203, 213, 225, 0.5);
    --border-hover: rgba(99, 102, 241, 0.4);
    --card-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.1);
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-muted: #64748b;
    --punch-status-bg: rgba(248, 250, 252, 0.8);
    --stat-badge-bg: rgba(99, 102, 241, 0.08);
    --stat-badge-border: rgba(99, 102, 241, 0.2);
    --stat-badge-text: #4f46e5;
    --filter-btn-bg: rgba(255, 255, 255, 0.9);
    --filter-btn-border: rgba(203, 213, 225, 0.5);
    --filter-btn-text: #475569;
    --filter-btn-active-bg: rgba(99, 102, 241, 0.1);
    --filter-btn-active-border: #6366f1;
    --filter-btn-active-text: #4f46e5;
    --summary-card-bg: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.95));
    --last-update-bg: rgba(248, 250, 252, 0.6);
}

.agent-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.agent-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--punch-color-start, #6366f1), var(--punch-color-end, #4338ca));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 600;
    color: white;
    flex-shrink: 0;
}

.agent-info {
    flex: 1;
    min-width: 0;
}

.agent-name {
    font-weight: 600;
    font-size: 1rem;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.agent-department {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.punch-status {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem;
    background: var(--punch-status-bg);
    border-radius: 8px;
    margin-bottom: 0.75rem;
}

.punch-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--punch-color-start), var(--punch-color-end));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
    color: white;
    flex-shrink: 0;
}

.punch-details {
    flex: 1;
    min-width: 0;
}

.punch-type {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-primary);
    margin-bottom: 0.125rem;
}

.punch-duration {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.agent-stats {
    display: flex;
    gap: 0.5rem;
    font-size: 0.75rem;
}

.stat-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.625rem;
    background: var(--stat-badge-bg);
    border: 1px solid var(--stat-badge-border);
    border-radius: 6px;
    color: var(--stat-badge-text);
}

.stat-badge.paid {
    background: rgba(34, 197, 94, 0.1);
    border-color: rgba(34, 197, 94, 0.2);
    color: #86efac;
}

.stat-badge.unpaid {
    background: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.2);
    color: #fca5a5;
}

.status-offline .agent-card::before {
    background: linear-gradient(90deg, #6b7280, #4b5563);
}

.status-not-today .agent-card::before {
    background: linear-gradient(90deg, #f59e0b, #d97706);
}

/* Light theme adjustments for status colors */
.theme-light .stat-badge.paid {
    background: rgba(34, 197, 94, 0.1);
    border-color: rgba(34, 197, 94, 0.3);
    color: #15803d;
}

.theme-light .stat-badge.unpaid {
    background: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.3);
    color: #b91c1c;
}

.theme-light .summary-value.text-green-400 {
    color: #15803d !important;
}

.theme-light .summary-value.text-blue-400 {
    color: #1e40af !important;
}

.theme-light .summary-value.text-orange-400 {
    color: #c2410c !important;
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.loading-spinner {
    width: 50px;
    height: 50px;
    border: 4px solid rgba(99, 102, 241, 0.2);
    border-top-color: #6366f1;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.filter-bar {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}

.filter-btn {
    padding: 0.5rem 1rem;
    background: var(--filter-btn-bg);
    border: 1px solid var(--filter-btn-border);
    border-radius: 8px;
    color: var(--filter-btn-text);
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
}

.filter-btn:hover {
    border-color: var(--filter-btn-active-border);
    color: var(--filter-btn-active-text);
}

.filter-btn.active {
    background: var(--filter-btn-active-bg);
    border-color: var(--filter-btn-active-border);
    color: var(--filter-btn-active-text);
}

.stats-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.summary-card {
    background: var(--summary-card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
}

.summary-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
}

.summary-label {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.last-update {
    text-align: center;
    color: var(--text-muted);
    font-size: 0.75rem;
    margin-top: 1rem;
    padding: 0.5rem;
    background: var(--last-update-bg);
    border-radius: 6px;
}

.pulse-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    background: #22c55e;
    border-radius: 50%;
    margin-right: 0.5rem;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}
</style>

<div class="container-fluid py-4">
    <div class="glass-card mb-4">
        <div class="panel-heading">
            <div>
                <h1 class="text-primary text-2xl font-semibold mb-2">
                    <i class="fas fa-users-cog text-cyan-400"></i>
                    Monitor de Agentes en Tiempo Real
                </h1>
                <p class="text-muted text-sm">Vista en vivo del estado actual de todos los agentes</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 px-3 py-2 bg-green-500/10 border border-green-500/20 rounded-lg">
                    <span class="pulse-dot"></span>
                    <span class="text-green-400 text-sm font-medium">EN VIVO</span>
                </div>
                <button onclick="refreshData()" class="btn-secondary" id="refreshBtn">
                    <i class="fas fa-sync-alt"></i>
                    Actualizar
                </button>
            </div>
        </div>
    </div>

    <!-- Stats Summary -->
    <div class="stats-summary" id="statsSummary">
        <div class="summary-card">
            <div class="summary-value" id="totalAgents">-</div>
            <div class="summary-label">
                <i class="fas fa-users"></i> Total Agentes
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-value text-green-400" id="activeAgents">-</div>
            <div class="summary-label">
                <i class="fas fa-check-circle"></i> Activos Hoy
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-value text-blue-400" id="paidPunches">-</div>
            <div class="summary-label">
                <i class="fas fa-dollar-sign"></i> En Punch Pagado
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-value text-orange-400" id="unpaidPunches">-</div>
            <div class="summary-label">
                <i class="fas fa-pause-circle"></i> En Pausa/Break
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
        <button class="filter-btn active" data-filter="all" onclick="filterAgents('all')">
            <i class="fas fa-users"></i> Todos
        </button>
        <button class="filter-btn" data-filter="active" onclick="filterAgents('active')">
            <i class="fas fa-check-circle"></i> Activos
        </button>
        <button class="filter-btn" data-filter="paid" onclick="filterAgents('paid')">
            <i class="fas fa-dollar-sign"></i> Punch Pagado
        </button>
        <button class="filter-btn" data-filter="unpaid" onclick="filterAgents('unpaid')">
            <i class="fas fa-pause-circle"></i> Pausas/Breaks
        </button>
        <button class="filter-btn" data-filter="offline" onclick="filterAgents('offline')">
            <i class="fas fa-times-circle"></i> Sin Registro Hoy
        </button>
    </div>

    <!-- Agents Grid -->
    <div class="supervisor-grid" id="agentsGrid">
        <div class="text-center py-8 text-muted">
            <i class="fas fa-spinner fa-spin text-4xl mb-3"></i>
            <p>Cargando datos en tiempo real...</p>
        </div>
    </div>

    <div class="last-update" id="lastUpdate">
        Última actualización: Cargando...
    </div>
</div>

<script>
let refreshInterval;
let currentFilter = 'all';
let agentsData = [];

// Inicializar al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    refreshData();
    startAutoRefresh();
});

function startAutoRefresh() {
    // Actualizar cada 5 segundos
    refreshInterval = setInterval(refreshData, 5000);
}

function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
}

async function refreshData() {
    const refreshBtn = document.getElementById('refreshBtn');
    const icon = refreshBtn.querySelector('i');
    
    icon.classList.add('fa-spin');
    
    try {
        const response = await fetch('supervisor_realtime_api.php');
        const data = await response.json();
        
        if (data.success) {
            agentsData = data.agents;
            updateStats(data);
            renderAgents(agentsData);
            updateLastUpdateTime(data.timestamp);
        } else {
            console.error('Error:', data.error);
        }
    } catch (error) {
        console.error('Error al obtener datos:', error);
    } finally {
        icon.classList.remove('fa-spin');
    }
}

function updateStats(data) {
    const activeCount = data.agents.filter(a => a.status === 'active').length;
    const paidCount = data.agents.filter(a => a.status === 'active' && a.current_punch.is_paid === 1).length;
    const unpaidCount = data.agents.filter(a => a.status === 'active' && a.current_punch.is_paid === 0).length;
    
    document.getElementById('totalAgents').textContent = data.total_agents;
    document.getElementById('activeAgents').textContent = activeCount;
    document.getElementById('paidPunches').textContent = paidCount;
    document.getElementById('unpaidPunches').textContent = unpaidCount;
}

function renderAgents(agents) {
    const grid = document.getElementById('agentsGrid');
    
    if (agents.length === 0) {
        grid.innerHTML = `
            <div class="text-center py-8 text-muted col-span-full">
                <i class="fas fa-users-slash text-4xl mb-3"></i>
                <p>No hay agentes para mostrar</p>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = agents.map(agent => createAgentCard(agent)).join('');
}

function createAgentCard(agent) {
    const initials = agent.full_name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
    const statusClass = `status-${agent.status}`;
    const paidBadge = agent.current_punch.is_paid === 1 ? 'paid' : 'unpaid';
    const paidLabel = agent.current_punch.is_paid === 1 ? 'Pagado' : 'No Pagado';
    
    return `
        <div class="agent-card ${statusClass}" 
             data-status="${agent.status}" 
             data-paid="${agent.current_punch.is_paid}"
             style="--punch-gradient: linear-gradient(90deg, ${agent.current_punch.color_start}, ${agent.current_punch.color_end}); --punch-color-start: ${agent.current_punch.color_start}; --punch-color-end: ${agent.current_punch.color_end};">
            
            <div class="agent-header">
                <div class="agent-avatar">${initials}</div>
                <div class="agent-info">
                    <div class="agent-name" title="${agent.full_name}">${agent.full_name}</div>
                    <div class="agent-department">
                        <i class="fas fa-building text-xs"></i> ${agent.department}
                    </div>
                </div>
            </div>
            
            <div class="punch-status">
                <div class="punch-icon">
                    <i class="${agent.current_punch.icon}"></i>
                </div>
                <div class="punch-details">
                    <div class="punch-type">${agent.current_punch.label}</div>
                    <div class="punch-duration">
                        <i class="fas fa-clock text-xs"></i> ${agent.current_punch.duration_formatted}
                    </div>
                </div>
            </div>
            
            <div class="agent-stats">
                <div class="stat-badge">
                    <i class="fas fa-fingerprint"></i>
                    ${agent.punches_today} punches hoy
                </div>
                <div class="stat-badge ${paidBadge}">
                    <i class="fas ${agent.current_punch.is_paid === 1 ? 'fa-dollar-sign' : 'fa-pause-circle'}"></i>
                    ${paidLabel}
                </div>
            </div>
        </div>
    `;
}

function filterAgents(filter) {
    currentFilter = filter;
    
    // Update button states
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-filter="${filter}"]`).classList.add('active');
    
    // Filter agents
    let filtered = agentsData;
    
    switch(filter) {
        case 'active':
            filtered = agentsData.filter(a => a.status === 'active');
            break;
        case 'paid':
            filtered = agentsData.filter(a => a.status === 'active' && a.current_punch.is_paid === 1);
            break;
        case 'unpaid':
            filtered = agentsData.filter(a => a.status === 'active' && a.current_punch.is_paid === 0);
            break;
        case 'offline':
            filtered = agentsData.filter(a => a.status !== 'active');
            break;
    }
    
    renderAgents(filtered);
}

function updateLastUpdateTime(timestamp) {
    const date = new Date(timestamp);
    const formatted = date.toLocaleString('es-DO', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    document.getElementById('lastUpdate').innerHTML = `
        <span class="pulse-dot"></span>
        Última actualización: ${formatted} - Actualización automática cada 5 segundos
    `;
}

// Limpiar intervalo al salir de la página
window.addEventListener('beforeunload', function() {
    stopAutoRefresh();
});
</script>

<?php include 'footer.php'; ?>
