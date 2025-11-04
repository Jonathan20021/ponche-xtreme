<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Check permission
if (!userHasPermission('helpdesk_tickets')) {
    header("Location: ../unauthorized.php");
    exit;
}

$user_id = $_SESSION['user_id'];

require_once __DIR__ . '/../header_agent.php';
?>

<link rel="stylesheet" href="../assets/css/theme.css">

<style>
.helpdesk-container {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.page-header h1 {
    margin: 0;
    color: #333;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.tickets-grid {
    display: grid;
    gap: 20px;
}

.ticket-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: all 0.3s;
    cursor: pointer;
    border-left: 4px solid #007bff;
}

.ticket-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.ticket-card.priority-critical {
    border-left-color: #dc3545;
}

.ticket-card.priority-high {
    border-left-color: #fd7e14;
}

.ticket-card.priority-medium {
    border-left-color: #ffc107;
}

.ticket-card.priority-low {
    border-left-color: #28a745;
}

.ticket-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.ticket-number {
    font-size: 18px;
    font-weight: bold;
    color: #667eea;
}

.ticket-badges {
    display: flex;
    gap: 8px;
}

.badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-status-open { background: #cce5ff; color: #004085; }
.badge-status-in_progress { background: #fff3cd; color: #856404; }
.badge-status-pending { background: #e2e3e5; color: #383d41; }
.badge-status-resolved { background: #d4edda; color: #155724; }
.badge-status-closed { background: #d6d8db; color: #1b1e21; }

.ticket-subject {
    font-size: 16px;
    font-weight: 600;
    color: #333;
    margin-bottom: 10px;
}

.ticket-description {
    color: #666;
    font-size: 14px;
    margin-bottom: 15px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.ticket-meta {
    display: flex;
    gap: 20px;
    font-size: 13px;
    color: #999;
}

.ticket-meta i {
    margin-right: 5px;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
}

.modal-content {
    background: white;
    margin: 50px auto;
    padding: 40px;
    border-radius: 16px;
    max-width: 700px;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
}

.close {
    float: right;
    font-size: 32px;
    font-weight: bold;
    cursor: pointer;
    color: #999;
    line-height: 1;
}

.close:hover {
    color: #333;
}

.form-group {
    margin-bottom: 24px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.3s;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #667eea;
}

.form-group textarea {
    min-height: 150px;
    resize: vertical;
    font-family: inherit;
}

.loading {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: #999;
}

.empty-state i {
    font-size: 80px;
    margin-bottom: 20px;
    opacity: 0.3;
}

.empty-state h3 {
    margin: 0 0 10px 0;
    color: #666;
}

.filters-bar {
    background: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.filters-bar select {
    padding: 10px 15px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    min-width: 150px;
}

.stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-box {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-box .value {
    font-size: 32px;
    font-weight: bold;
    color: #667eea;
}

.stat-box .label {
    font-size: 14px;
    color: #666;
    margin-top: 5px;
}

.comments-section {
    margin-top: 30px;
    padding-top: 30px;
    border-top: 2px solid #e0e0e0;
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
}

.comment-author {
    font-weight: bold;
    color: #333;
}

.comment-date {
    color: #999;
}

.comment-text {
    color: #555;
    white-space: pre-wrap;
}
</style>

<div class="helpdesk-container">
    <div class="page-header">
        <h1><i class="fas fa-headset"></i> My Support Tickets</h1>
        <button class="btn btn-primary" onclick="openCreateTicketModal()">
            <i class="fas fa-plus"></i> Create Ticket
        </button>
    </div>

    <!-- Stats Bar -->
    <div class="stats-bar">
        <div class="stat-box">
            <div class="value" id="totalTickets">0</div>
            <div class="label">Total Tickets</div>
        </div>
        <div class="stat-box">
            <div class="value" id="openTickets">0</div>
            <div class="label">Open</div>
        </div>
        <div class="stat-box">
            <div class="value" id="resolvedTickets">0</div>
            <div class="label">Resolved</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
        <select id="filterStatus" onchange="loadTickets()">
            <option value="">All Statuses</option>
            <option value="open">Open</option>
            <option value="in_progress">In Progress</option>
            <option value="pending">Pending</option>
            <option value="resolved">Resolved</option>
            <option value="closed">Closed</option>
        </select>
        <select id="filterPriority" onchange="loadTickets()">
            <option value="">All Priorities</option>
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="critical">Critical</option>
        </select>
        <select id="filterCategory" onchange="loadTickets()">
            <option value="">All Categories</option>
        </select>
    </div>

    <!-- Tickets List -->
    <div class="tickets-grid" id="ticketsList">
        <div class="loading">
            <i class="fas fa-spinner fa-spin fa-3x"></i>
            <p>Loading your tickets...</p>
        </div>
    </div>
</div>

<!-- Create Ticket Modal -->
<div id="createTicketModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeCreateTicketModal()">&times;</span>
        <h2 style="margin-top: 0;"><i class="fas fa-plus-circle"></i> Create Support Ticket</h2>
        <p style="color: #666; margin-bottom: 30px;">Describe your issue or request and we'll help you as soon as possible.</p>
        
        <form id="createTicketForm" onsubmit="createTicket(event)">
            <div class="form-group">
                <label>Category *</label>
                <select id="ticketCategory" required>
                    <option value="">Select a category</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Subject *</label>
                <input type="text" id="ticketSubject" required placeholder="Brief summary of your issue">
            </div>
            
            <div class="form-group">
                <label>Description *</label>
                <textarea id="ticketDescription" required placeholder="Please provide detailed information about your issue or request..."></textarea>
            </div>
            
            <div class="form-group">
                <label>Priority</label>
                <select id="ticketPriority">
                    <option value="low">Low - Can wait</option>
                    <option value="medium" selected>Medium - Normal priority</option>
                    <option value="high">High - Urgent</option>
                    <option value="critical">Critical - Blocking work</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%;">
                <i class="fas fa-paper-plane"></i> Submit Ticket
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

document.addEventListener('DOMContentLoaded', function() {
    loadCategories();
    loadTickets();
    updateStats();
    
    // Refresh every 30 seconds
    setInterval(() => {
        loadTickets();
        updateStats();
    }, 30000);
});

function loadCategories() {
    fetch('../hr/helpdesk_api.php?action=get_categories')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                categories = data.categories;
                
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

function loadTickets() {
    const status = document.getElementById('filterStatus').value;
    const priority = document.getElementById('filterPriority').value;
    const category = document.getElementById('filterCategory').value;
    
    let url = '../hr/helpdesk_api.php?action=get_tickets';
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

function updateStats() {
    fetch('../hr/helpdesk_api.php?action=get_tickets')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const tickets = data.tickets;
                document.getElementById('totalTickets').textContent = tickets.length;
                document.getElementById('openTickets').textContent = 
                    tickets.filter(t => t.status === 'open' || t.status === 'in_progress').length;
                document.getElementById('resolvedTickets').textContent = 
                    tickets.filter(t => t.status === 'resolved' || t.status === 'closed').length;
            }
        });
}

function displayTickets(tickets) {
    const container = document.getElementById('ticketsList');
    
    if (tickets.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-ticket-alt"></i>
                <h3>No tickets yet</h3>
                <p>Create your first support ticket to get help from our team</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = tickets.map(ticket => `
        <div class="ticket-card priority-${ticket.priority}" onclick="viewTicket(${ticket.id})">
            <div class="ticket-header">
                <span class="ticket-number">#${ticket.ticket_number}</span>
                <div class="ticket-badges">
                    <span class="badge badge-status-${ticket.status}">${ticket.status.replace('_', ' ')}</span>
                </div>
            </div>
            <div class="ticket-subject">${escapeHtml(ticket.subject)}</div>
            <div class="ticket-description">${escapeHtml(ticket.description)}</div>
            <div class="ticket-meta">
                <span><i class="fas fa-tag"></i> ${ticket.category_name}</span>
                <span><i class="fas fa-flag"></i> ${ticket.priority}</span>
                <span><i class="fas fa-clock"></i> ${formatDate(ticket.created_at)}</span>
            </div>
        </div>
    `).join('');
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
    
    const submitBtn = event.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    
    const formData = new FormData();
    formData.append('action', 'create_ticket');
    formData.append('category_id', document.getElementById('ticketCategory').value);
    formData.append('subject', document.getElementById('ticketSubject').value);
    formData.append('description', document.getElementById('ticketDescription').value);
    formData.append('priority', document.getElementById('ticketPriority').value);
    
    fetch('../hr/helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Ticket';
        
        if (data.success) {
            alert('✓ Ticket created successfully!\n\nTicket Number: ' + data.ticket_number + '\n\nYou will receive email updates about your ticket.');
            closeCreateTicketModal();
            loadTickets();
            updateStats();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Ticket';
        alert('Error creating ticket. Please try again.');
    });
}

function viewTicket(ticketId) {
    fetch(`../hr/helpdesk_api.php?action=get_ticket&ticket_id=${ticketId}`)
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
    
    let html = `
        <h2 style="margin-top: 0;">#${ticket.ticket_number}</h2>
        <h3 style="margin: 10px 0 20px 0; color: #333;">${escapeHtml(ticket.subject)}</h3>
        
        <div style="display: flex; gap: 10px; margin-bottom: 20px;">
            <span class="badge badge-status-${ticket.status}">${ticket.status.replace('_', ' ')}</span>
            <span class="badge" style="background: ${ticket.category_color}; color: white;">${ticket.category_name}</span>
            <span class="badge" style="background: #6c757d; color: white;">${ticket.priority}</span>
        </div>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <p style="margin: 0 0 10px 0;"><strong>Created:</strong> ${formatDate(ticket.created_at)}</p>
            ${ticket.assigned_to_name ? `<p style="margin: 0;"><strong>Assigned to:</strong> ${ticket.assigned_to_name}</p>` : '<p style="margin: 0;"><strong>Status:</strong> Waiting for assignment</p>'}
        </div>
        
        <div style="margin-bottom: 30px;">
            <h4>Description</h4>
            <p style="white-space: pre-wrap; color: #555;">${escapeHtml(ticket.description)}</p>
        </div>
    `;
    
    // Comments section
    html += `
        <div class="comments-section">
            <h4>Updates & Comments (${ticket.comments.length})</h4>
            ${ticket.comments.length === 0 ? '<p style="color: #999;">No comments yet. Our team will respond soon.</p>' : ''}
            ${ticket.comments.map(comment => `
                <div class="comment">
                    <div class="comment-header">
                        <span class="comment-author">${comment.user_name}</span>
                        <span class="comment-date">${formatDate(comment.created_at)}</span>
                    </div>
                    <div class="comment-text">${escapeHtml(comment.comment)}</div>
                </div>
            `).join('')}
            
            <div style="margin-top: 25px;">
                <h4>Add Comment</h4>
                <form onsubmit="addComment(event)">
                    <div class="form-group">
                        <textarea id="newComment" required placeholder="Type your comment or additional information..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-comment"></i> Add Comment
                    </button>
                </form>
            </div>
        </div>
    `;
    
    document.getElementById('ticketDetails').innerHTML = html;
}

function addComment(event) {
    event.preventDefault();
    
    const submitBtn = event.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting...';
    
    const formData = new FormData();
    formData.append('action', 'add_comment');
    formData.append('ticket_id', currentTicket.id);
    formData.append('comment', document.getElementById('newComment').value);
    
    fetch('../hr/helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-comment"></i> Add Comment';
        
        if (data.success) {
            viewTicket(currentTicket.id);
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
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins} min ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
    if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
    
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
