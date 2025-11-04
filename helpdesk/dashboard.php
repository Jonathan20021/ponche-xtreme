<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if (!userHasPermission('helpdesk')) {
    header("Location: ../unauthorized.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'employee';
$isAdmin = ($user_role === 'Admin' || $user_role === 'HR');

require_once __DIR__ . '/../header.php';
?>

<link rel="stylesheet" href="helpdesk_styles.css">

<style>
.admin-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.quick-actions {
    background: var(--bg-card);
    border-radius: var(--radius);
    padding: 32px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-md);
    margin-bottom: 32px;
}

.quick-actions h3 {
    margin-bottom: 24px;
    color: var(--text-primary);
    font-weight: 700;
    font-size: 20px;
    letter-spacing: -0.5px;
}

.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

.action-card {
    background: linear-gradient(135deg, #667eea05 0%, #764ba205 100%);
    color: var(--text-primary);
    padding: 28px;
    border-radius: var(--radius);
    text-align: center;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: block;
    border: 2px solid var(--border);
    position: relative;
    overflow: hidden;
}

.action-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--accent), var(--accent-light));
    transform: scaleX(0);
    transition: var(--transition);
}

.action-card:hover {
    border-color: var(--accent);
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}

.action-card:hover::before {
    transform: scaleX(1);
}

.action-icon {
    font-size: 36px;
    margin-bottom: 16px;
    color: var(--accent);
    transition: var(--transition);
}

.action-card:hover .action-icon {
    transform: scale(1.1) rotate(5deg);
}

.action-title {
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.3px;
}

.tickets-table {
    width: 100%;
    border-collapse: collapse;
}

.tickets-table thead th {
    background: #f9fafb;
    padding: 12px 16px;
    text-align: left;
    font-weight: 500;
    color: #6b7280;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #e5e7eb;
}

.tickets-table tbody tr {
    background: white;
    transition: all 0.15s ease;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
}

.tickets-table tbody tr:hover {
    background: #f9fafb;
}

.tickets-table tbody td {
    padding: 14px 16px;
    font-size: 13px;
    color: #374151;
}

.assign-select {
    padding: 6px 10px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    background: white;
}

.assign-select:focus {
    outline: none;
    border-color: #111827;
}

.status-select {
    padding: 6px 10px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    font-weight: 500;
    background: white;
}
</style>

<div class="helpdesk-wrapper">
    <div class="helpdesk-container">
        <div class="page-header">
            <div class="header-content">
                <div class="header-title">
                    <div class="header-icon">
                        <i class="fas fa-headset"></i>
                    </div>
                    <div class="header-text">
                        <h1>Gestión de Tickets</h1>
                        <p>Panel administrativo del sistema de soporte</p>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="btn btn-secondary" onclick="loadAllTickets()">
                        <i class="fas fa-sync-alt"></i>
                        Actualizar
                    </button>
                </div>
            </div>
        </div>

        <div class="stats-grid" id="adminStats"></div>

        <div class="quick-actions">
            <h3 style="margin-bottom: 20px; color: #1f2937; font-weight: 700;">Acciones Rápidas</h3>
            <div class="actions-grid">
                <a href="my_tickets.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-list"></i></div>
                    <div class="action-title">Ver Todos los Tickets</div>
                </a>
                <a href="create_ticket.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-plus"></i></div>
                    <div class="action-title">Crear Ticket</div>
                </a>
                <a href="categories.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-folder"></i></div>
                    <div class="action-title">Gestionar Categorías</div>
                </a>
                <a href="suggestions.php" class="action-card">
                    <div class="action-icon"><i class="fas fa-lightbulb"></i></div>
                    <div class="action-title">Sugerencias</div>
                </a>
            </div>
        </div>

        <div class="filters-section" id="adminFilters"></div>

        <div class="tickets-section">
            <div class="section-header">
                <h2 class="section-title">Tickets Recientes</h2>
            </div>
            <div style="overflow-x: auto;">
                <table class="tickets-table" id="ticketsTable">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Usuario</th>
                            <th>Asunto</th>
                            <th>Categoría</th>
                            <th>Prioridad</th>
                            <th>Estado</th>
                            <th>Asignado</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="ticketsTableBody">
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #667eea;"></i>
                                <p style="margin-top: 15px; color: #6b7280;">Cargando tickets...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="ticketModal" class="modal"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadAdminStatistics();
    loadAdminFilters();
    loadAllTickets();
    loadAgents();
    setInterval(loadAllTickets, 60000);
});

let agents = [];

function loadAgents() {
    fetch('../hr/helpdesk_api.php?action=get_agents')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                agents = data.agents;
            }
        });
}

function loadAdminStatistics() {
    fetch('../hr/helpdesk_api.php?action=get_statistics')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayAdminStats(data.statistics);
            }
        });
}

function displayAdminStats(stats) {
    const html = `
        <div class="stat-card total">
            <div class="stat-header">
                <div>
                    <div class="stat-value">${stats.total || 0}</div>
                    <div class="stat-label">Total Tickets</div>
                </div>
                <div class="stat-icon"><i class="fas fa-ticket-alt"></i></div>
            </div>
        </div>
        <div class="stat-card open">
            <div class="stat-header">
                <div>
                    <div class="stat-value">${stats.open || 0}</div>
                    <div class="stat-label">Abiertos</div>
                </div>
                <div class="stat-icon"><i class="fas fa-folder-open"></i></div>
            </div>
        </div>
        <div class="stat-card in-progress">
            <div class="stat-header">
                <div>
                    <div class="stat-value">${stats.in_progress || 0}</div>
                    <div class="stat-label">En Progreso</div>
                </div>
                <div class="stat-icon"><i class="fas fa-spinner"></i></div>
            </div>
        </div>
        <div class="stat-card resolved">
            <div class="stat-header">
                <div>
                    <div class="stat-value">${stats.resolved || 0}</div>
                    <div class="stat-label">Resueltos</div>
                </div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
    `;
    document.getElementById('adminStats').innerHTML = html;
}

function loadAdminFilters() {
    fetch('../hr/helpdesk_api.php?action=get_categories')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayAdminFilters(data.categories);
            }
        });
}

function displayAdminFilters(categories) {
    const html = `
        <div class="filters-grid">
            <div class="filter-group">
                <label><i class="fas fa-filter"></i> Estado</label>
                <select id="filterStatus" onchange="loadAllTickets()">
                    <option value="">Todos</option>
                    <option value="open">Abierto</option>
                    <option value="in_progress">En Progreso</option>
                    <option value="pending">Pendiente</option>
                    <option value="resolved">Resuelto</option>
                    <option value="closed">Cerrado</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-exclamation-circle"></i> Prioridad</label>
                <select id="filterPriority" onchange="loadAllTickets()">
                    <option value="">Todas</option>
                    <option value="low">Baja</option>
                    <option value="medium">Media</option>
                    <option value="high">Alta</option>
                    <option value="critical">Crítica</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-folder"></i> Categoría</label>
                <select id="filterCategory" onchange="loadAllTickets()">
                    <option value="">Todas</option>
                    ${categories.map(cat => `<option value="${cat.id}">${cat.name}</option>`).join('')}
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Buscar</label>
                <input type="text" id="searchQuery" placeholder="Buscar..." onkeyup="loadAllTickets()">
            </div>
        </div>
    `;
    document.getElementById('adminFilters').innerHTML = html;
}

function loadAllTickets() {
    const status = document.getElementById('filterStatus')?.value || '';
    const priority = document.getElementById('filterPriority')?.value || '';
    const category = document.getElementById('filterCategory')?.value || '';
    const search = document.getElementById('searchQuery')?.value || '';
    
    let url = '../hr/helpdesk_api.php?action=get_tickets';
    if (status) url += '&status=' + status;
    if (priority) url += '&priority=' + priority;
    if (category) url += '&category_id=' + category;
    if (search) url += '&search=' + encodeURIComponent(search);
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayTicketsTable(data.tickets);
            }
        });
}

function displayTicketsTable(tickets) {
    const tbody = document.getElementById('ticketsTableBody');
    
    if (tickets.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 40px;">
                    <i class="fas fa-inbox" style="font-size: 48px; color: #d1d5db;"></i>
                    <p style="margin-top: 15px; color: #6b7280;">No se encontraron tickets</p>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = tickets.map(ticket => `
        <tr onclick="viewTicketDetails(${ticket.id})">
            <td><strong style="color: #667eea;">#${ticket.ticket_number}</strong></td>
            <td>${ticket.user_name}</td>
            <td>${ticket.subject}</td>
            <td><span class="badge" style="background: ${ticket.category_color}20; color: ${ticket.category_color};">${ticket.category_name}</span></td>
            <td><span class="badge badge-priority-${ticket.priority}">${formatPriority(ticket.priority)}</span></td>
            <td>
                <select class="status-select badge-status-${ticket.status}" 
                        onclick="event.stopPropagation()" 
                        onchange="updateTicketStatus(${ticket.id}, this.value)">
                    <option value="open" ${ticket.status === 'open' ? 'selected' : ''}>Abierto</option>
                    <option value="in_progress" ${ticket.status === 'in_progress' ? 'selected' : ''}>En Progreso</option>
                    <option value="pending" ${ticket.status === 'pending' ? 'selected' : ''}>Pendiente</option>
                    <option value="resolved" ${ticket.status === 'resolved' ? 'selected' : ''}>Resuelto</option>
                    <option value="closed" ${ticket.status === 'closed' ? 'selected' : ''}>Cerrado</option>
                </select>
            </td>
            <td>
                <select class="assign-select" 
                        onclick="event.stopPropagation()" 
                        onchange="assignTicket(${ticket.id}, this.value)">
                    <option value="">Sin asignar</option>
                    ${agents.map(agent => `
                        <option value="${agent.id}" ${ticket.assigned_to == agent.id ? 'selected' : ''}>
                            ${agent.full_name}
                        </option>
                    `).join('')}
                </select>
            </td>
            <td>${formatDate(ticket.created_at)}</td>
            <td>
                <button class="btn btn-secondary" style="padding: 8px 16px; font-size: 13px;" 
                        onclick="event.stopPropagation(); viewTicketDetails(${ticket.id})">
                    <i class="fas fa-eye"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

function updateTicketStatus(ticketId, newStatus) {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('ticket_id', ticketId);
    formData.append('status', newStatus);
    
    fetch('../hr/helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadAllTickets();
            loadAdminStatistics();
        }
    });
}

function assignTicket(ticketId, agentId) {
    const formData = new FormData();
    formData.append('action', 'assign_ticket');
    formData.append('ticket_id', ticketId);
    formData.append('assigned_to', agentId);
    
    fetch('../hr/helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadAllTickets();
        }
    });
}

function viewTicketDetails(ticketId) {
    window.location.href = `my_tickets.php?ticket=${ticketId}`;
}

function exportTickets() {
    alert('Función de exportación en desarrollo');
}

function formatPriority(priority) {
    const priorities = {
        'low': 'Baja',
        'medium': 'Media',
        'high': 'Alta',
        'critical': 'Crítica'
    };
    return priorities[priority] || priority;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('es-ES', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}
</script>

</main>
</body>
</html>
