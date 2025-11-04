<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

// Check permission using section_permissions
if (!userHasPermission('helpdesk')) {
    header("Location: ../unauthorized.php");
    exit;
}

$isAdmin = ($role === 'Admin' || $role === 'HR');

require_once __DIR__ . '/../header.php';
?>

<link rel="stylesheet" href="../assets/css/theme.css">

<style>
.helpdesk-container {
    padding: 20px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border-left: 4px solid #007bff;
}

.stat-card.warning {
    border-left-color: #ffc107;
}

.stat-card.danger {
    border-left-color: #dc3545;
}

.stat-card.success {
    border-left-color: #28a745;
}

.stat-card h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #666;
    text-transform: uppercase;
}

.stat-card .value {
    font-size: 32px;
    font-weight: bold;
    color: #333;
}

.filters-section {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.tickets-section {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.ticket-card {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    transition: all 0.3s;
    cursor: pointer;
}

.ticket-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.ticket-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.ticket-number {
    font-weight: bold;
    color: #007bff;
    font-size: 16px;
}

.ticket-badges {
    display: flex;
    gap: 8px;
}

.badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.badge-priority-low { background: #d4edda; color: #155724; }
.badge-priority-medium { background: #fff3cd; color: #856404; }
.badge-priority-high { background: #f8d7da; color: #721c24; }
.badge-priority-critical { background: #721c24; color: white; }

.badge-status-open { background: #cce5ff; color: #004085; }
.badge-status-in_progress { background: #fff3cd; color: #856404; }
.badge-status-pending { background: #e2e3e5; color: #383d41; }
.badge-status-resolved { background: #d4edda; color: #155724; }
.badge-status-closed { background: #d6d8db; color: #1b1e21; }

.ticket-subject {
    font-size: 16px;
    font-weight: 500;
    margin-bottom: 8px;
    color: #333;
}

.ticket-meta {
    display: flex;
    gap: 20px;
    font-size: 13px;
    color: #666;
}

.ticket-meta i {
    margin-right: 5px;
}

.sla-warning {
    color: #dc3545;
    font-weight: bold;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-success {
    background: #28a745;
    color: white;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
}

.modal-content {
    background: white;
    margin: 50px auto;
    padding: 30px;
    border-radius: 10px;
    max-width: 800px;
    max-height: 80vh;
    overflow-y: auto;
}

.close {
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    color: #999;
}

.close:hover {
    color: #333;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}

.form-group textarea {
    min-height: 120px;
    resize: vertical;
}

.comments-section {
    margin-top: 30px;
    border-top: 2px solid #e0e0e0;
    padding-top: 20px;
}

.comment {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
}

.comment-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    font-size: 13px;
    color: #666;
}

.comment-author {
    font-weight: bold;
    color: #333;
}

.comment-internal {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
}

.ai-badge {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    margin-left: 8px;
}

.loading {
    text-align: center;
    padding: 40px;
    color: #666;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}

.empty-state i {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.3;
}

.chart-container {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
</style>

<div class="helpdesk-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <h1><i class="fas fa-ticket-alt"></i> Helpdesk Dashboard</h1>
        <button class="btn btn-primary" onclick="openCreateTicketModal()">
            <i class="fas fa-plus"></i> New Ticket
        </button>
    </div>

    <!-- Statistics -->
    <div class="stats-grid" id="statsGrid">
        <div class="stat-card">
            <h3>Total Tickets</h3>
            <div class="value" id="totalTickets">-</div>
        </div>
        <div class="stat-card warning">
            <h3>Open Tickets</h3>
            <div class="value" id="openTickets">-</div>
        </div>
        <div class="stat-card">
            <h3>In Progress</h3>
            <div class="value" id="inProgressTickets">-</div>
        </div>
        <div class="stat-card success">
            <h3>Resolved</h3>
            <div class="value" id="resolvedTickets">-</div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="stat-card danger">
            <h3>SLA Breaches</h3>
            <div class="value" id="slaBreaches">-</div>
        </div>
        <div class="stat-card">
            <h3>Avg Resolution (hrs)</h3>
            <div class="value" id="avgResolution">-</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="filters-section">
        <h3 style="margin-bottom: 15px;">Filters</h3>
        <div class="filters-grid">
            <div class="form-group">
                <label>Status</label>
                <select id="filterStatus" onchange="loadTickets()">
                    <option value="">All Statuses</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="pending">Pending</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
            <div class="form-group">
                <label>Priority</label>
                <select id="filterPriority" onchange="loadTickets()">
                    <option value="">All Priorities</option>
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                </select>
            </div>
            <div class="form-group">
                <label>Category</label>
                <select id="filterCategory" onchange="loadTickets()">
                    <option value="">All Categories</option>
                </select>
            </div>
            <?php if ($isAdmin): ?>
            <div class="form-group">
                <label>Assigned To</label>
                <select id="filterAssigned" onchange="loadTickets()">
                    <option value="">All Agents</option>
                    <option value="unassigned">Unassigned</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tickets List -->
    <div class="tickets-section">
        <h3 style="margin-bottom: 20px;">Tickets</h3>
        <div id="ticketsList">
            <div class="loading">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p>Loading tickets...</p>
            </div>
        </div>
    </div>
</div>

<!-- Create Ticket Modal -->
<div id="createTicketModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeCreateTicketModal()">&times;</span>
        <h2><i class="fas fa-plus-circle"></i> Create New Ticket</h2>
        <form id="createTicketForm" onsubmit="createTicket(event)">
            <div class="form-group">
                <label>Category *</label>
                <select id="ticketCategory" required>
                    <option value="">Select Category</option>
                </select>
            </div>
            <div class="form-group">
                <label>Subject *</label>
                <input type="text" id="ticketSubject" required placeholder="Brief description of the issue">
            </div>
            <div class="form-group">
                <label>Description *</label>
                <textarea id="ticketDescription" required placeholder="Detailed description of your issue or request"></textarea>
            </div>
            <div class="form-group">
                <label>Priority</label>
                <select id="ticketPriority">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Create Ticket
            </button>
        </form>
    </div>
</div>

<!-- View Ticket Modal -->
<div id="viewTicketModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeViewTicketModal()">&times;</span>
        <div id="ticketDetails"></div>
    </div>
</div>

<script>
let categories = [];
let currentTicket = null;

// Load initial data
document.addEventListener('DOMContentLoaded', function() {
    loadCategories();
    loadStatistics();
    loadTickets();
    
    // Refresh every 30 seconds
    setInterval(() => {
        loadStatistics();
        loadTickets();
    }, 30000);
});

function loadCategories() {
    fetch('helpdesk_api.php?action=get_categories')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                categories = data.categories;
                
                // Populate category selects
                const categorySelects = ['ticketCategory', 'filterCategory'];
                categorySelects.forEach(selectId => {
                    const select = document.getElementById(selectId);
                    if (select) {
                        data.categories.forEach(cat => {
                            const option = document.createElement('option');
                            option.value = cat.id;
                            option.textContent = cat.name;
                            select.appendChild(option);
                        });
                    }
                });
            }
        });
}

function loadStatistics() {
    <?php if ($isAdmin): ?>
    fetch('helpdesk_api.php?action=get_statistics')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const stats = data.statistics;
                document.getElementById('totalTickets').textContent = stats.total_tickets || 0;
                document.getElementById('openTickets').textContent = stats.open_tickets || 0;
                document.getElementById('inProgressTickets').textContent = stats.in_progress_tickets || 0;
                document.getElementById('resolvedTickets').textContent = stats.resolved_tickets || 0;
                document.getElementById('slaBreaches').textContent = 
                    (parseInt(stats.response_breaches || 0) + parseInt(stats.resolution_breaches || 0));
                document.getElementById('avgResolution').textContent = 
                    stats.avg_resolution_hours ? parseFloat(stats.avg_resolution_hours).toFixed(1) : '0';
            }
        });
    <?php endif; ?>
}

function loadTickets() {
    const status = document.getElementById('filterStatus').value;
    const priority = document.getElementById('filterPriority').value;
    const category = document.getElementById('filterCategory').value;
    
    let url = 'helpdesk_api.php?action=get_tickets';
    if (status) url += '&status=' + status;
    if (priority) url += '&priority=' + priority;
    if (category) url += '&category_id=' + category;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayTickets(data.tickets);
            }
        });
}

function displayTickets(tickets) {
    const container = document.getElementById('ticketsList');
    
    if (tickets.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No tickets found</h3>
                <p>Create a new ticket to get started</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = tickets.map(ticket => {
        const slaWarning = (ticket.sla_response_breached == 1 || ticket.sla_resolution_breached == 1) 
            ? '<span class="sla-warning"><i class="fas fa-exclamation-triangle"></i> SLA Breached</span>' 
            : '';
        
        return `
            <div class="ticket-card" onclick="viewTicket(${ticket.id})">
                <div class="ticket-header">
                    <span class="ticket-number">#${ticket.ticket_number}</span>
                    <div class="ticket-badges">
                        <span class="badge badge-priority-${ticket.priority}">${ticket.priority.toUpperCase()}</span>
                        <span class="badge badge-status-${ticket.status}">${ticket.status.replace('_', ' ').toUpperCase()}</span>
                    </div>
                </div>
                <div class="ticket-subject">${escapeHtml(ticket.subject)}</div>
                <div class="ticket-meta">
                    <span><i class="fas fa-tag"></i> ${ticket.category_name}</span>
                    <span><i class="fas fa-user"></i> ${ticket.user_name}</span>
                    <span><i class="fas fa-clock"></i> ${formatDate(ticket.created_at)}</span>
                    ${ticket.assigned_to_name ? `<span><i class="fas fa-user-check"></i> ${ticket.assigned_to_name}</span>` : ''}
                    ${slaWarning}
                </div>
            </div>
        `;
    }).join('');
}

function openCreateTicketModal() {
    document.getElementById('createTicketModal').style.display = 'block';
}

function closeCreateTicketModal() {
    document.getElementById('createTicketModal').style.display = 'none';
    document.getElementById('createTicketForm').reset();
}

function createTicket(event) {
    event.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'create_ticket');
    formData.append('category_id', document.getElementById('ticketCategory').value);
    formData.append('subject', document.getElementById('ticketSubject').value);
    formData.append('description', document.getElementById('ticketDescription').value);
    formData.append('priority', document.getElementById('ticketPriority').value);
    
    fetch('helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Ticket created successfully! Ticket #' + data.ticket_number);
            closeCreateTicketModal();
            loadStatistics();
            loadTickets();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function viewTicket(ticketId) {
    fetch(`helpdesk_api.php?action=get_ticket&ticket_id=${ticketId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayTicketDetails(data.ticket);
                document.getElementById('viewTicketModal').style.display = 'block';
            }
        });
}

function closeViewTicketModal() {
    document.getElementById('viewTicketModal').style.display = 'none';
}

function displayTicketDetails(ticket) {
    currentTicket = ticket;
    const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    
    const aiAnalysis = ticket.ai_analysis ? JSON.parse(ticket.ai_analysis) : null;
    
    let html = `
        <h2>#${ticket.ticket_number} - ${escapeHtml(ticket.subject)}</h2>
        <div style="display: flex; gap: 10px; margin: 15px 0;">
            <span class="badge badge-priority-${ticket.priority}">${ticket.priority.toUpperCase()}</span>
            <span class="badge badge-status-${ticket.status}">${ticket.status.replace('_', ' ').toUpperCase()}</span>
            <span class="badge" style="background: ${ticket.category_color}; color: white;">${ticket.category_name}</span>
        </div>
        
        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Created by:</strong> ${ticket.user_name} (${ticket.user_email})</p>
            <p><strong>Created at:</strong> ${formatDate(ticket.created_at)}</p>
            ${ticket.assigned_to_name ? `<p><strong>Assigned to:</strong> ${ticket.assigned_to_name}</p>` : '<p><strong>Status:</strong> Unassigned</p>'}
            <p><strong>SLA Response Deadline:</strong> ${formatDate(ticket.sla_response_deadline)}</p>
            <p><strong>SLA Resolution Deadline:</strong> ${formatDate(ticket.sla_resolution_deadline)}</p>
        </div>
        
        <div style="margin: 20px 0;">
            <h3>Description</h3>
            <p style="white-space: pre-wrap;">${escapeHtml(ticket.description)}</p>
        </div>
    `;
    
    if (aiAnalysis && isAdmin) {
        html += `
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <h3><i class="fas fa-robot"></i> AI Analysis</h3>
                <p><strong>Suggested Category:</strong> ${aiAnalysis.category || 'N/A'}</p>
                <p><strong>Suggested Priority:</strong> ${aiAnalysis.priority || 'N/A'}</p>
                <p><strong>Analysis:</strong> ${aiAnalysis.analysis || 'N/A'}</p>
                ${aiAnalysis.suggested_response ? `<p><strong>Suggested Response:</strong> ${aiAnalysis.suggested_response}</p>` : ''}
            </div>
        `;
    }
    
    if (isAdmin) {
        html += `
            <div style="margin: 20px 0;">
                <button class="btn btn-primary" onclick="updateTicketStatus()">Update Status</button>
                <button class="btn btn-success" onclick="assignTicket()">Assign Ticket</button>
                <button class="btn btn-primary" onclick="getAISuggestion()"><i class="fas fa-robot"></i> AI Suggest Response</button>
            </div>
        `;
    }
    
    // Comments section
    html += `
        <div class="comments-section">
            <h3>Comments (${ticket.comments.length})</h3>
            ${ticket.comments.map(comment => `
                <div class="comment ${comment.is_internal == 1 ? 'comment-internal' : ''}">
                    <div class="comment-header">
                        <span class="comment-author">${comment.user_name}${comment.is_internal == 1 ? ' <span class="badge" style="background: #ffc107; color: #333;">Internal</span>' : ''}${comment.is_ai_generated == 1 ? '<span class="ai-badge">AI</span>' : ''}</span>
                        <span>${formatDate(comment.created_at)}</span>
                    </div>
                    <div style="white-space: pre-wrap;">${escapeHtml(comment.comment)}</div>
                </div>
            `).join('')}
            
            <div style="margin-top: 20px;">
                <h4>Add Comment</h4>
                <form onsubmit="addComment(event)">
                    <div class="form-group">
                        <textarea id="newComment" required placeholder="Type your comment here..."></textarea>
                    </div>
                    ${isAdmin ? `
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="isInternal"> Internal Comment (not visible to ticket creator)
                        </label>
                    </div>
                    ` : ''}
                    <button type="submit" class="btn btn-primary">Add Comment</button>
                </form>
            </div>
        </div>
    `;
    
    document.getElementById('ticketDetails').innerHTML = html;
}

function addComment(event) {
    event.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'add_comment');
    formData.append('ticket_id', currentTicket.id);
    formData.append('comment', document.getElementById('newComment').value);
    
    const isInternalCheckbox = document.getElementById('isInternal');
    if (isInternalCheckbox) {
        formData.append('is_internal', isInternalCheckbox.checked ? 1 : 0);
    }
    
    fetch('helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            viewTicket(currentTicket.id);
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function updateTicketStatus() {
    const newStatus = prompt('Enter new status (open, in_progress, pending, resolved, closed):', currentTicket.status);
    if (!newStatus) return;
    
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('ticket_id', currentTicket.id);
    formData.append('status', newStatus);
    
    fetch('helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Status updated successfully!');
            viewTicket(currentTicket.id);
            loadStatistics();
            loadTickets();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function getAISuggestion() {
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    
    const formData = new FormData();
    formData.append('action', 'ai_suggest_response');
    formData.append('ticket_id', currentTicket.id);
    
    fetch('helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-robot"></i> AI Suggest Response';
        
        if (data.success) {
            document.getElementById('newComment').value = data.suggestion;
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString();
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
