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

.status-completed .agent-card::before {
    background: linear-gradient(90deg, #22c55e, #15803d);
}

.status-completed .agent-card .punch-duration {
    color: #22c55e;
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

.theme-light .ninja-edit-btn {
    background: rgba(99, 102, 241, 0.12);
    border-color: rgba(99, 102, 241, 0.35);
    color: #4338ca;
}

.theme-light .punch-edit-controls {
    background: rgba(99, 102, 241, 0.08);
    border-color: rgba(99, 102, 241, 0.3);
}

.theme-light .punch-edit-row button {
    background: rgba(16, 185, 129, 0.15);
    color: #047857;
    border-color: rgba(16, 185, 129, 0.35);
}

.theme-light .punch-edit-row button:hover:not(:disabled) {
    background: rgba(16, 185, 129, 0.25);
}

.theme-light .punch-edit-cancel {
    background: rgba(239, 68, 68, 0.12);
    color: #b91c1c;
    border-color: rgba(239, 68, 68, 0.35);
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

/* Modal Styles */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 1rem;
}

.modal-overlay.active {
    display: flex;
}

.modal-container {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    max-width: 1200px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.modal-close {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--filter-btn-bg);
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close:hover {
    background: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

.modal-body {
    padding: 1.5rem;
}

.modal-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .modal-grid {
        grid-template-columns: 1fr;
    }
}

.modal-section {
    background: var(--punch-status-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
}

.modal-section-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.punch-timeline {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    max-height: 400px;
    overflow-y: auto;
}

.punch-timeline-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    transition: all 0.2s;
}

.punch-timeline-item:hover {
    border-color: var(--border-hover);
}

.punch-timeline-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--item-color-start), var(--item-color-end));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}

.punch-timeline-content {
    flex: 1;
}

.punch-timeline-type {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-primary);
}

.punch-timeline-time {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.punch-timeline-actions {
    margin-top: 0.5rem;
    display: flex;
    justify-content: flex-end;
}

.ninja-edit-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.6rem;
    border-radius: 6px;
    border: 1px solid rgba(99, 102, 241, 0.3);
    background: rgba(99, 102, 241, 0.15);
    color: #c7d2fe;
    font-size: 0.7rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ninja-edit-btn:hover {
    background: rgba(99, 102, 241, 0.25);
    border-color: rgba(99, 102, 241, 0.5);
}

.punch-edit-controls {
    margin-top: 0.75rem;
    padding: 0.75rem;
    border-radius: 8px;
    border: 1px dashed var(--border-color);
    background: rgba(99, 102, 241, 0.1);
}

.punch-edit-controls.is-hidden {
    display: none;
}

.punch-edit-row {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.punch-edit-row select {
    flex: 1;
    min-width: 160px;
    background: var(--card-bg);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 0.35rem 0.5rem;
    font-size: 0.8rem;
}

.punch-edit-row button {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.4);
    border-radius: 6px;
    padding: 0.35rem 0.75rem;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.punch-edit-row button:hover:not(:disabled) {
    background: rgba(16, 185, 129, 0.25);
}

.punch-edit-row button[disabled] {
    opacity: 0.6;
    cursor: not-allowed;
}

.punch-edit-cancel {
    background: rgba(239, 68, 68, 0.1);
    color: #fca5a5;
    border-color: rgba(239, 68, 68, 0.3);
}

.punch-edit-cancel:hover:not(:disabled) {
    background: rgba(239, 68, 68, 0.2);
}

.punch-edit-status {
    margin-top: 0.5rem;
    font-size: 0.7rem;
    color: var(--text-secondary);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}

.stat-box {
    background: var(--punch-status-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}

.stat-box-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
}

.stat-box-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.chart-container {
    position: relative;
    height: 300px;
    margin-top: 1rem;
}

.agent-card {
    cursor: pointer;
}

.agent-card:active {
    transform: scale(0.98);
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

<!-- Modal de Detalles del Agente -->
<div class="modal-overlay" id="agentModal" onclick="closeModalOnOverlay(event)">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-user-circle"></i>
                <span id="modalAgentName">Cargando...</span>
            </div>
            <button class="modal-close" onclick="closeAgentModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <!-- Estadísticas Rápidas -->
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-box-value" id="modalTotalPunches">-</div>
                    <div class="stat-box-label">
                        <i class="fas fa-fingerprint"></i> Total Punches
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-box-value text-green-400" id="modalPaidTime">-</div>
                    <div class="stat-box-label">
                        <i class="fas fa-dollar-sign"></i> Tiempo Pagado
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-box-value text-orange-400" id="modalUnpaidTime">-</div>
                    <div class="stat-box-label">
                        <i class="fas fa-pause-circle"></i> Tiempo No Pagado
                    </div>
                </div>
            </div>

            <!-- Grid de Secciones -->
            <div class="modal-grid">
                <!-- Historial de Punches -->
                <div class="modal-section">
                    <div class="modal-section-title">
                        <i class="fas fa-history"></i>
                        Historial del Día
                        <span class="pulse-dot" style="margin-left: auto;"></span>
                    </div>
                    <div class="punch-timeline" id="punchTimeline">
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-spinner fa-spin"></i>
                            <p class="text-sm mt-2">Cargando historial...</p>
                        </div>
                    </div>
                </div>

                <!-- Gráfica de Distribución -->
                <div class="modal-section">
                    <div class="modal-section-title">
                        <i class="fas fa-chart-pie"></i>
                        Distribución de Tiempo
                    </div>
                    <div class="chart-container">
                        <canvas id="timeDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Detalles por Tipo de Punch -->
            <div class="modal-section">
                <div class="modal-section-title">
                    <i class="fas fa-list-ul"></i>
                    Desglose por Tipo de Punch
                </div>
                <div id="punchBreakdown">
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let agentsData = [];
let punchTypesCache = [];
let currentFilter = 'all';
let refreshInterval;

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
            if (Array.isArray(data.types_available)) {
                punchTypesCache = data.types_available;
            }
            updateStats(data);
            
            // Aplicar el filtro actual en lugar de mostrar todos
            filterAgents(currentFilter);
            
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
             data-user-id="${agent.user_id}"
             onclick="openAgentModal(${agent.user_id}, '${agent.full_name}')"
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

// ===== MODAL FUNCTIONALITY =====
let currentAgentId = null;
let modalRefreshInterval = null;
let agentChart = null;

function openAgentModal(userId, fullName) {
    currentAgentId = userId;
    document.getElementById('modalAgentName').textContent = fullName;
    document.getElementById('agentModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Cargar datos del agente
    loadAgentDetails(userId);
    
    // Iniciar actualización automática del modal cada 3 segundos
    if (modalRefreshInterval) {
        clearInterval(modalRefreshInterval);
    }
    modalRefreshInterval = setInterval(() => {
        if (currentAgentId) {
            loadAgentDetails(currentAgentId);
        }
    }, 3000);
}

function closeAgentModal() {
    closePunchEditors();
    document.getElementById('agentModal').classList.remove('active');
    document.body.style.overflow = 'auto';
    currentAgentId = null;
    
    // Detener actualización del modal
    if (modalRefreshInterval) {
        clearInterval(modalRefreshInterval);
        modalRefreshInterval = null;
    }
    
    // Destruir gráfica
    if (agentChart) {
        agentChart.destroy();
        agentChart = null;
    }
}

function closeModalOnOverlay(event) {
    if (event.target.id === 'agentModal') {
        closeAgentModal();
    }
}

async function loadAgentDetails(userId) {
    try {
        const timestamp = new Date().getTime();
        const response = await fetch(`supervisor_agent_details_api.php?user_id=${userId}&_=${timestamp}`, {
            cache: 'no-cache',
            headers: {
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
            }
        });
        const data = await response.json();
        
        if (data.success) {
            if (Array.isArray(data.attendance_types) && data.attendance_types.length) {
                punchTypesCache = data.attendance_types;
            }
            updateModalStats(data.stats);
            updatePunchTimeline(data.punches);
            updatePunchBreakdown(data.stats.by_type);
            updateChart(data.chart_data);
        } else {
            console.error('Error:', data.error);
        }
    } catch (error) {
        console.error('Error al cargar detalles:', error);
    }
}

function updateModalStats(stats) {
    document.getElementById('modalTotalPunches').textContent = stats.total_punches;
    document.getElementById('modalPaidTime').textContent = stats.total_paid_time_formatted;
    document.getElementById('modalUnpaidTime').textContent = stats.total_unpaid_time_formatted;
}

function escapeHtml(value) {
    if (value === null || value === undefined) {
        return '';
    }
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function buildPunchOptions(selectedSlug) {
    const selected = (selectedSlug || '').toUpperCase();
    if (!Array.isArray(punchTypesCache) || punchTypesCache.length === 0) {
        return selected ? `<option value="${selected}" selected>${selected}</option>` : '';
    }
    return punchTypesCache
        .map(type => {
            const slug = (type.slug || '').toUpperCase();
            if (!slug) {
                return '';
            }
            const label = escapeHtml(type.label || slug);
            const isSelected = slug === selected ? 'selected' : '';
            return `<option value="${slug}" ${isSelected}>${label}</option>`;
        })
        .join('');
}

function closePunchEditors(exceptId = null) {
    document.querySelectorAll('.punch-edit-controls').forEach(ctrl => {
        if (!exceptId || ctrl.id !== `punch-edit-${exceptId}`) {
            ctrl.classList.add('is-hidden');
        }
    });
}

function setPunchEditStatus(punchId, message, isError = false) {
    const statusEl = document.getElementById(`punch-edit-status-${punchId}`);
    if (!statusEl) {
        return;
    }
    statusEl.textContent = message;
    statusEl.style.color = isError ? '#f87171' : 'var(--text-secondary)';
}

function openPunchNinja(event, punchId, currentType) {
    event.preventDefault();
    event.stopPropagation();

    const controls = document.getElementById(`punch-edit-${punchId}`);
    const select = document.getElementById(`punch-select-${punchId}`);

    if (!controls || !select) {
        return;
    }

    select.innerHTML = buildPunchOptions(currentType);
    select.value = (currentType || '').toUpperCase();

    const isHidden = controls.classList.contains('is-hidden');
    closePunchEditors(punchId);

    if (isHidden) {
        controls.classList.remove('is-hidden');
        setPunchEditStatus(punchId, '');
        select.focus();
    } else {
        controls.classList.add('is-hidden');
    }
}

function cancelPunchEdit(punchId) {
    const controls = document.getElementById(`punch-edit-${punchId}`);
    if (controls) {
        controls.classList.add('is-hidden');
    }
    setPunchEditStatus(punchId, '');
}

async function submitPunchEdit(punchId) {
    const select = document.getElementById(`punch-select-${punchId}`);
    if (!select) {
        return;
    }
    const newType = (select.value || '').toUpperCase();
    if (!newType) {
        setPunchEditStatus(punchId, 'Selecciona un tipo válido.', true);
        return;
    }
    if (!currentAgentId) {
        setPunchEditStatus(punchId, 'No hay un agente seleccionado.', true);
        return;
    }

    const controls = document.getElementById(`punch-edit-${punchId}`);
    if (!controls) {
        return;
    }

    const buttons = controls.querySelectorAll('button');
    buttons.forEach(btn => {
        btn.disabled = true;
    });
    setPunchEditStatus(punchId, 'Actualizando...', false);

    try {
        const response = await fetch('supervisor_update_punch_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                punch_id: punchId,
                user_id: currentAgentId,
                new_type: newType
            })
        });
        const data = await response.json();

        if (data.success) {
            setPunchEditStatus(punchId, 'Punch actualizado correctamente.', false);
            await loadAgentDetails(currentAgentId);
            refreshData();
            setTimeout(() => {
                cancelPunchEdit(punchId);
            }, 800);
        } else {
            setPunchEditStatus(punchId, data.error || 'No se pudo actualizar el punch.', true);
        }
    } catch (error) {
        setPunchEditStatus(punchId, 'Error: ' + error.message, true);
    } finally {
        buttons.forEach(btn => {
            btn.disabled = false;
        });
    }
}

function updatePunchTimeline(punches) {
    const timeline = document.getElementById('punchTimeline');
    
    if (punches.length === 0) {
        timeline.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="fas fa-inbox text-2xl mb-2"></i>
                <p class="text-sm">No hay punches registrados hoy</p>
            </div>
        `;
        return;
    }
    
    timeline.innerHTML = punches.map(punch => {
        const safeLabel = escapeHtml(punch.type_label);
        const safeTime = escapeHtml(punch.time);
        const sanitizedType = (punch.type || '').toUpperCase();
        const jsCurrentType = sanitizedType.replace(/\\/g, '\\\\').replace(/'/g, '\\\'');
        const ninjaControls = punchTypesCache.length > 0 ? `
            <div class="punch-timeline-actions">
                <button type="button" class="ninja-edit-btn" onclick="openPunchNinja(event, ${punch.id}, '${jsCurrentType}')">
                    <i class="fas fa-user-ninja"></i> Ninja
                </button>
            </div>
            <div class="punch-edit-controls is-hidden" id="punch-edit-${punch.id}">
                <div class="punch-edit-row">
                    <select id="punch-select-${punch.id}">
                        ${buildPunchOptions(sanitizedType)}
                    </select>
                    <button type="button" onclick="submitPunchEdit(${punch.id})">Aplicar</button>
                    <button type="button" class="punch-edit-cancel" onclick="cancelPunchEdit(${punch.id})">Cancelar</button>
                </div>
                <div class="punch-edit-status" id="punch-edit-status-${punch.id}"></div>
            </div>
        ` : '';
        
        return `
            <div class="punch-timeline-item" style="--item-color-start: ${punch.color_start}; --item-color-end: ${punch.color_end};">
                <div class="punch-timeline-icon">
                    <i class="${punch.icon}"></i>
                </div>
                <div class="punch-timeline-content">
                    <div class="punch-timeline-type">${safeLabel}</div>
                    <div class="punch-timeline-time">
                        <i class="fas fa-clock"></i> ${safeTime}
                        ${punch.is_paid ? '<span class="ml-2 text-green-400"><i class="fas fa-dollar-sign"></i> Pagado</span>' : '<span class="ml-2 text-orange-400"><i class="fas fa-pause-circle"></i> No pagado</span>'}
                    </div>
                    ${ninjaControls}
                </div>
            </div>
        `;
    }).join('');
}

function updatePunchBreakdown(byType) {
    const breakdown = document.getElementById('punchBreakdown');
    
    if (Object.keys(byType).length === 0) {
        breakdown.innerHTML = `
            <div class="text-center text-muted py-4">
                <p class="text-sm">No hay datos disponibles</p>
            </div>
        `;
        return;
    }
    
    const items = Object.entries(byType).map(([type, data]) => {
        const paidBadge = data.is_paid ? 'paid' : 'unpaid';
        const paidLabel = data.is_paid ? 'Pagado' : 'No Pagado';
        
        return `
            <div class="flex items-center justify-between p-3 bg-opacity-50 rounded-lg mb-2" style="background: var(--punch-status-bg);">
                <div class="flex-1">
                    <div class="font-semibold text-sm" style="color: var(--text-primary);">${data.label}</div>
                    <div class="text-xs" style="color: var(--text-secondary);">
                        ${data.count} ${data.count === 1 ? 'vez' : 'veces'} • ${data.total_time_formatted}
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <div class="stat-badge ${paidBadge} text-xs">
                        ${paidLabel}
                    </div>
                    <div class="text-lg font-bold" style="color: var(--text-primary);">
                        ${data.percentage}%
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    breakdown.innerHTML = items;
}

function updateChart(chartData) {
    const canvas = document.getElementById('timeDistributionChart');
    const ctx = canvas.getContext('2d');
    
    // Destruir gráfica anterior si existe
    if (agentChart) {
        agentChart.destroy();
    }
    
    // Crear nueva gráfica
    agentChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: chartData.labels,
            datasets: [{
                data: chartData.data,
                backgroundColor: chartData.colors,
                borderWidth: 2,
                borderColor: getComputedStyle(document.body).getPropertyValue('--border-color') || '#1e293b'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: getComputedStyle(document.body).getPropertyValue('--text-primary') || '#f1f5f9',
                        padding: 15,
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const isPaid = chartData.isPaid[context.dataIndex];
                            const paidLabel = isPaid ? '💰 Pagado' : '⏸️ No Pagado';
                            return `${label}: ${value.toFixed(1)} min (${paidLabel})`;
                        }
                    }
                }
            }
        }
    });
}

// Cerrar modal con tecla ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && currentAgentId) {
        closeAgentModal();
    }
});
</script>

<?php include 'footer.php'; ?>
