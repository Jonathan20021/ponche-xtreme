// Helpdesk JavaScript Functions
let allTickets = [];

document.addEventListener('DOMContentLoaded', function() {
    loadStatistics();
    loadFilters();
    loadTickets();
    setInterval(loadTickets, 60000); // Auto-refresh every 60 seconds
});

function loadStatistics() {
    fetch('../hr/helpdesk_api.php?action=get_my_statistics')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayStatistics(data.statistics);
            }
        });
}

function displayStatistics(stats) {
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
    document.getElementById('statsGrid').innerHTML = html;
}

function loadFilters() {
    fetch('../hr/helpdesk_api.php?action=get_categories')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayFilters(data.categories);
            }
        });
}

function displayFilters(categories) {
    const html = `
        <div class="filters-grid">
            <div class="filter-group">
                <label><i class="fas fa-filter"></i> Estado</label>
                <select id="filterStatus" onchange="loadTickets()">
                    <option value="">Todos los estados</option>
                    <option value="open">Abierto</option>
                    <option value="in_progress">En Progreso</option>
                    <option value="pending">Pendiente</option>
                    <option value="resolved">Resuelto</option>
                    <option value="closed">Cerrado</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-exclamation-circle"></i> Prioridad</label>
                <select id="filterPriority" onchange="loadTickets()">
                    <option value="">Todas las prioridades</option>
                    <option value="low">Baja</option>
                    <option value="medium">Media</option>
                    <option value="high">Alta</option>
                    <option value="critical">Crítica</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-folder"></i> Categoría</label>
                <select id="filterCategory" onchange="loadTickets()">
                    <option value="">Todas las categorías</option>
                    ${categories.map(cat => `<option value="${cat.id}">${escapeHtml(cat.name)}</option>`).join('')}
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Buscar</label>
                <input type="text" id="searchQuery" placeholder="Buscar tickets..." onkeyup="searchTickets()">
            </div>
        </div>
    `;
    document.getElementById('filtersSection').innerHTML = html;
}

function loadTickets() {
    const status = document.getElementById('filterStatus')?.value || '';
    const priority = document.getElementById('filterPriority')?.value || '';
    const category = document.getElementById('filterCategory')?.value || '';
    
    let url = '../hr/helpdesk_api.php?action=get_my_tickets';
    if (status) url += '&status=' + status;
    if (priority) url += '&priority=' + priority;
    if (category) url += '&category_id=' + category;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allTickets = data.tickets;
                displayTickets(data.tickets);
            }
        });
}

function displayTickets(tickets) {
    const container = document.getElementById('ticketsSection');
    
    if (tickets.length === 0) {
        container.innerHTML = `
            <div class="section-header">
                <h2 class="section-title">Mis Tickets</h2>
            </div>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                <h3 style="font-size: 24px; font-weight: 700; color: #374151; margin-bottom: 10px;">No hay tickets</h3>
                <p style="color: #6b7280; font-size: 16px;">No se encontraron tickets con los filtros seleccionados</p>
            </div>
        `;
        return;
    }
    
    const ticketsHtml = tickets.map(ticket => `
        <div class="ticket-card" onclick="viewTicket(${ticket.id})">
            <div class="ticket-header">
                <span class="ticket-number">#${escapeHtml(ticket.ticket_number)}</span>
                <div class="ticket-badges">
                    <span class="badge badge-status-${ticket.status}">${formatStatus(ticket.status)}</span>
                    <span class="badge badge-priority-${ticket.priority}">${formatPriority(ticket.priority)}</span>
                </div>
            </div>
            <h3 class="ticket-subject">${escapeHtml(ticket.subject)}</h3>
            <p class="ticket-description">${escapeHtml(ticket.description)}</p>
            <div class="ticket-meta">
                <span><i class="fas fa-folder"></i> ${escapeHtml(ticket.category_name)}</span>
                <span><i class="fas fa-clock"></i> ${formatDate(ticket.created_at)}</span>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = `
        <div class="section-header">
            <h2 class="section-title">Mis Tickets (${tickets.length})</h2>
        </div>
        <div class="tickets-grid">${ticketsHtml}</div>
    `;
}

function searchTickets() {
    const query = document.getElementById('searchQuery').value.toLowerCase();
    if (!query) {
        displayTickets(allTickets);
        return;
    }
    
    const filtered = allTickets.filter(ticket => 
        ticket.ticket_number.toLowerCase().includes(query) ||
        ticket.subject.toLowerCase().includes(query) ||
        ticket.description.toLowerCase().includes(query)
    );
    
    displayTickets(filtered);
}

function viewTicket(ticketId) {
    fetch(`../hr/helpdesk_api.php?action=get_ticket&ticket_id=${ticketId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayTicketModal(data.ticket);
            }
        });
}

function displayTicketModal(ticket) {
    const modalHtml = `
        <div class="modal-header">
            <h2 class="modal-title">Detalles del Ticket</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div style="margin-bottom: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                    <div>
                        <h3 style="font-size: 24px; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
                            ${escapeHtml(ticket.subject)}
                        </h3>
                        <p style="color: #6b7280; font-size: 14px;">Ticket #${escapeHtml(ticket.ticket_number)}</p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <span class="badge badge-status-${ticket.status}">${formatStatus(ticket.status)}</span>
                        <span class="badge badge-priority-${ticket.priority}">${formatPriority(ticket.priority)}</span>
                    </div>
                </div>
                
                <div style="background: #f9fafb; padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                    <h4 style="font-weight: 600; margin-bottom: 10px; color: #374151;">Descripción</h4>
                    <p style="color: #6b7280; line-height: 1.6; white-space: pre-wrap;">${escapeHtml(ticket.description)}</p>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                    <div>
                        <p style="color: #6b7280; font-size: 13px; margin-bottom: 5px;">Categoría</p>
                        <p style="font-weight: 600; color: #1f2937;">${escapeHtml(ticket.category_name)}</p>
                    </div>
                    <div>
                        <p style="color: #6b7280; font-size: 13px; margin-bottom: 5px;">Creado</p>
                        <p style="font-weight: 600; color: #1f2937;">${formatDate(ticket.created_at)}</p>
                    </div>
                    <div>
                        <p style="color: #6b7280; font-size: 13px; margin-bottom: 5px;">Asignado a</p>
                        <p style="font-weight: 600; color: #1f2937;">${ticket.assigned_name || 'Sin asignar'}</p>
                    </div>
                    <div>
                        <p style="color: #6b7280; font-size: 13px; margin-bottom: 5px;">Última actualización</p>
                        <p style="font-weight: 600; color: #1f2937;">${formatDate(ticket.updated_at)}</p>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('ticketModal').innerHTML = modalHtml;
    document.getElementById('ticketModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('ticketModal').style.display = 'none';
}

// Utility Functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatStatus(status) {
    const statuses = {
        'open': 'Abierto',
        'in_progress': 'En Progreso',
        'pending': 'Pendiente',
        'resolved': 'Resuelto',
        'closed': 'Cerrado',
        'cancelled': 'Cancelado'
    };
    return statuses[status] || status;
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
    const now = new Date();
    const diffMs = now - date;
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffDays === 0) return 'Hoy';
    if (diffDays === 1) return 'Ayer';
    if (diffDays < 7) return `Hace ${diffDays} días`;
    
    return date.toLocaleDateString('es-ES', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('ticketModal');
    if (event.target == modal) {
        closeModal();
    }
}
