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

<style>
/* ===== Mis Tickets — UI premium con identidad Evallish ===== */
.tk-wrap{max-width:1200px;}
.tk-filters{display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px;}
.tk-select{position:relative; flex:1; min-width:180px;}
.tk-select select{width:100%; appearance:none; background:#fff; border:1.5px solid var(--ag-border); border-radius:12px;
    padding:12px 38px 12px 14px; font-family:inherit; font-size:13.5px; font-weight:600; color:var(--ag-text); cursor:pointer; transition:.18s;}
.tk-select select:focus{outline:none; border-color:var(--ag-blue); box-shadow:0 0 0 3px rgba(72,150,254,.12);}
.tk-select .chev{position:absolute; right:14px; top:50%; transform:translateY(-50%); color:var(--ag-faint); pointer-events:none; font-size:12px;}

.tk-grid{display:grid; gap:14px;}
.tk-card{background:#fff; border:1px solid var(--ag-border); border-left:4px solid var(--ag-blue); border-radius:14px;
    padding:20px; box-shadow:var(--ag-shadow-sm); cursor:pointer; transition:.18s;}
.tk-card:hover{transform:translateY(-3px); box-shadow:var(--ag-shadow);}
.tk-card.p-low{border-left-color:var(--ag-green);}
.tk-card.p-medium{border-left-color:var(--ag-blue);}
.tk-card.p-high{border-left-color:var(--ag-amber);}
.tk-card.p-critical{border-left-color:var(--ag-red);}
.tk-head{display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;}
.tk-num{font-size:14px; font-weight:800; color:var(--ag-brand); letter-spacing:.3px;}
.tk-subject{font-size:15.5px; font-weight:700; color:var(--ag-text); margin:0 0 8px;}
.tk-desc{color:var(--ag-muted); font-size:13.5px; line-height:1.55; margin-bottom:14px;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;}
.tk-meta{display:flex; align-items:center; gap:16px; flex-wrap:wrap; font-size:12px; color:var(--ag-muted);
    padding-top:12px; border-top:1px solid var(--ag-border);}
.tk-meta i{margin-right:5px;}

.tk-badge{padding:4px 11px; border-radius:20px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;}
.st-open{background:#E8F1FE; color:#1D5DB8;}
.st-in_progress{background:var(--ag-amber-bg); color:#B54708;}
.st-pending{background:#EEF1F6; color:var(--ag-muted);}
.st-resolved{background:var(--ag-green-bg); color:#0B7A4B;}
.st-closed{background:#E5E8EE; color:#475569;}
.st-cancelled{background:var(--ag-red-bg); color:#B42318;}
.pr-low{background:var(--ag-green-bg); color:#0B7A4B;}
.pr-medium{background:#E8F1FE; color:#1D5DB8;}
.pr-high{background:var(--ag-amber-bg); color:#B54708;}
.pr-critical{background:var(--ag-red-bg); color:#B42318;}

.tk-loading,.tk-empty{text-align:center; padding:60px 20px; color:var(--ag-muted);}
.tk-empty i{font-size:44px; color:#CBD5E9; margin-bottom:14px; display:block;}
.tk-empty h3{color:var(--ag-text); font-size:16px; margin:0 0 4px;}
.tk-empty p{margin:0; font-size:13px;}

/* Modal */
.tk-modal{display:none; position:fixed; inset:0; z-index:1000; background:rgba(15,23,42,.5); backdrop-filter:blur(4px);}
.tk-modal-content{background:#fff; margin:44px auto; padding:30px; border-radius:18px; max-width:700px; max-height:86vh;
    overflow-y:auto; box-shadow:0 24px 60px rgba(15,23,42,.28); font-family:'Plus Jakarta Sans','Inter',sans-serif;}
.tk-modal h2{margin:0 0 4px; font-size:19px; font-weight:800; color:var(--ag-text); display:flex; align-items:center; gap:9px;}
.tk-modal .sub{color:var(--ag-muted); font-size:13px; margin:0 0 22px;}
.tk-close{float:right; font-size:26px; font-weight:700; cursor:pointer; color:var(--ag-faint); line-height:1;}
.tk-close:hover{color:var(--ag-text);}
.tk-field{margin-bottom:16px;}
.tk-field label{display:block; margin-bottom:6px; font-weight:600; font-size:12.5px; color:var(--ag-text);}
.tk-field input[type=text],.tk-field select,.tk-field textarea{width:100%; padding:11px 14px; border:1.5px solid var(--ag-border);
    border-radius:11px; font-family:inherit; font-size:13.5px; color:var(--ag-text); background:#fff; transition:.18s;}
.tk-field input:focus,.tk-field select:focus,.tk-field textarea:focus{outline:none; border-color:var(--ag-blue); box-shadow:0 0 0 3px rgba(72,150,254,.12);}
.tk-field textarea{min-height:130px; resize:vertical;}
.tk-detail-box{background:var(--ag-soft); border:1px solid var(--ag-border); border-radius:12px; padding:16px 18px; margin-bottom:18px; font-size:13px; color:var(--ag-text);}
.tk-detail-box p{margin:0 0 6px;}
.tk-comments{margin-top:24px; padding-top:22px; border-top:1px solid var(--ag-border);}
.tk-comment{background:var(--ag-soft); border:1px solid var(--ag-border); border-radius:11px; padding:13px 15px; margin-bottom:12px;}
.tk-comment .ch{display:flex; justify-content:space-between; margin-bottom:6px; font-size:12px;}
.tk-comment .ca{font-weight:700; color:var(--ag-text);}
.tk-comment .cd{color:var(--ag-muted);}
.tk-comment .ct{color:var(--ag-muted); font-size:13px; white-space:pre-wrap; line-height:1.5;}

/* adjuntos + timeline + acciones */
.tk-files{display:flex; flex-wrap:wrap; gap:8px; margin-top:9px;}
.tk-file-chip{display:inline-flex; align-items:center; gap:6px; font-size:11.5px; background:var(--ag-brand-tint); color:var(--ag-brand); border-radius:8px; padding:5px 10px;}
.tk-file-chip a{color:inherit; text-decoration:none; cursor:pointer; font-weight:700;}
.tk-atts{display:flex; flex-wrap:wrap; gap:9px; margin-top:11px;}
.tk-att{display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; color:var(--ag-brand); background:#fff; border:1px solid var(--ag-border); border-radius:9px; padding:6px 11px; text-decoration:none;}
.tk-att:hover{border-color:var(--ag-blue);}
.tk-att img{max-width:170px; max-height:120px; border-radius:8px; border:1px solid var(--ag-border); display:block;}
.tk-fileinput{display:flex; align-items:center; gap:9px;}
.tk-fileinput .btn{display:inline-flex; align-items:center; gap:7px; font-size:12.5px; font-weight:700; color:var(--ag-brand); background:var(--ag-brand-tint); border:1px solid #D3DEFB; border-radius:10px; padding:9px 13px; cursor:pointer;}
.tk-timeline{margin-top:22px; padding-top:20px; border-top:1px solid var(--ag-border);}
.tk-tl-item{display:flex; gap:11px; font-size:12.5px; color:var(--ag-muted); margin-bottom:11px;}
.tk-tl-dot{width:9px; height:9px; border-radius:50%; background:var(--ag-blue); margin-top:4px; flex-shrink:0;}
.tk-actions{display:flex; gap:8px; flex-wrap:wrap; margin:4px 0 18px;}
.tk-actions .ag-btn{font-size:12.5px;}
</style>

<div class="agent-dashboard tk-wrap">

    <div class="ag-pagehead">
        <div>
            <h1><i class="fas fa-headset" style="color:var(--ag-brand);"></i> Mis Tickets de Soporte</h1>
            <p>Reporta un problema o solicitud y nuestro equipo te ayuda.</p>
        </div>
        <div class="ag-head-actions">
            <button class="ag-btn ag-btn-primary" onclick="openCreateTicketModal()"><i class="fas fa-plus"></i> Nuevo ticket</button>
        </div>
    </div>

    <!-- KPIs -->
    <div class="ag-grid ag-kpis" style="margin-bottom:18px;">
        <div class="ag-card ag-kpi"><div class="top"><div class="ico" style="background:var(--ag-brand-tint);color:var(--ag-brand)"><i class="fas fa-ticket"></i></div></div><div class="val" id="totalTickets">0</div><div class="lbl">Total de tickets</div></div>
        <div class="ag-card ag-kpi"><div class="top"><div class="ico" style="background:#E8F1FE;color:var(--ag-blue)"><i class="fas fa-envelope-open-text"></i></div></div><div class="val" id="openTickets">0</div><div class="lbl">Abiertos</div></div>
        <div class="ag-card ag-kpi"><div class="top"><div class="ico" style="background:var(--ag-amber-bg);color:var(--ag-amber)"><i class="fas fa-spinner"></i></div></div><div class="val" id="progressTickets">0</div><div class="lbl">En progreso</div></div>
        <div class="ag-card ag-kpi"><div class="top"><div class="ico" style="background:var(--ag-green-bg);color:var(--ag-green)"><i class="fas fa-circle-check"></i></div></div><div class="val" id="resolvedTickets">0</div><div class="lbl">Resueltos</div></div>
    </div>

    <!-- Filtros -->
    <div class="tk-filters">
        <div class="tk-select">
            <select id="filterStatus" onchange="loadTickets()">
                <option value="">Todos los estados</option>
                <option value="open">Abierto</option>
                <option value="in_progress">En progreso</option>
                <option value="pending">Pendiente</option>
                <option value="resolved">Resuelto</option>
                <option value="closed">Cerrado</option>
                <option value="cancelled">Cancelado</option>
            </select><i class="fas fa-chevron-down chev"></i>
        </div>
        <div class="tk-select">
            <select id="filterPriority" onchange="loadTickets()">
                <option value="">Todas las prioridades</option>
                <option value="low">Baja</option>
                <option value="medium">Media</option>
                <option value="high">Alta</option>
                <option value="critical">Crítica</option>
            </select><i class="fas fa-chevron-down chev"></i>
        </div>
        <div class="tk-select">
            <select id="filterCategory" onchange="loadTickets()">
                <option value="">Todas las categorías</option>
            </select><i class="fas fa-chevron-down chev"></i>
        </div>
    </div>

    <!-- Lista -->
    <div class="tk-grid" id="ticketsList">
        <div class="ag-skel-grid">
            <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="ag-skel-card">
                <div class="ag-skel ag-skel-line" style="width:30%;"></div>
                <div class="ag-skel ag-skel-line" style="width:88%;"></div>
                <div class="ag-skel ag-skel-line" style="width:60%; margin-bottom:0;"></div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- Crear -->
<div id="createTicketModal" class="tk-modal">
    <div class="tk-modal-content">
        <span class="tk-close" onclick="closeCreateTicketModal()">&times;</span>
        <h2><i class="fas fa-circle-plus" style="color:var(--ag-brand);"></i> Crear ticket de soporte</h2>
        <p class="sub">Describe tu problema o solicitud y te ayudaremos lo antes posible.</p>

        <form id="createTicketForm" onsubmit="createTicket(event)">
            <div class="tk-field">
                <label>Categoría *</label>
                <select id="ticketCategory" required><option value="">Selecciona una categoría</option></select>
            </div>
            <div class="tk-field">
                <label>Asunto *</label>
                <input type="text" id="ticketSubject" required placeholder="Resumen breve de tu problema">
            </div>
            <div class="tk-field">
                <label>Descripción *</label>
                <textarea id="ticketDescription" required placeholder="Da detalles sobre tu problema o solicitud…"></textarea>
            </div>
            <div class="tk-field">
                <label>Prioridad</label>
                <select id="ticketPriority">
                    <option value="low">Baja — Puede esperar</option>
                    <option value="medium" selected>Media — Prioridad normal</option>
                    <option value="high">Alta — Urgente</option>
                    <option value="critical">Crítica — Bloquea el trabajo</option>
                </select>
            </div>
            <div class="tk-field">
                <label>Adjuntar capturas o archivos (opcional)</label>
                <div class="tk-fileinput">
                    <label class="btn" for="ticketFiles"><i class="fas fa-paperclip"></i> Elegir archivos</label>
                    <input type="file" id="ticketFiles" multiple accept="image/*,application/pdf,.txt" style="display:none;" onchange="renderCreateFiles()">
                    <span style="font-size:12px; color:var(--ag-muted);">Imagen, PDF o texto · hasta 10 MB c/u</span>
                </div>
                <div id="ticketFilesList" class="tk-files"></div>
            </div>
            <button type="submit" class="ag-btn ag-btn-primary" style="width:100%;"><i class="fas fa-paper-plane"></i> Enviar ticket</button>
        </form>
    </div>
</div>

<!-- Ver -->
<div id="viewTicketModal" class="tk-modal">
    <div class="tk-modal-content">
        <span class="tk-close" onclick="closeViewTicketModal()">&times;</span>
        <div id="ticketDetails"></div>
    </div>
</div>

<script>
let categories = [];
let currentTicket = null;

const TK_STATUS = {open:'Abierto', in_progress:'En progreso', pending:'Pendiente', resolved:'Resuelto', closed:'Cerrado', cancelled:'Cancelado'};
const TK_PRIORITY = {low:'Baja', medium:'Media', high:'Alta', critical:'Crítica'};

document.addEventListener('DOMContentLoaded', function() {
    loadCategories();
    loadTickets();
    updateStats();
    setInterval(() => { loadTickets(); updateStats(); }, 30000);
});

function loadCategories() {
    fetch('../hr/helpdesk_api.php?action=get_categories')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                categories = data.categories;
                ['ticketCategory', 'filterCategory'].forEach(selectId => {
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
        }).catch(() => {});
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
        .then(data => { if (data.success) displayTickets(data.tickets); })
        .catch(() => {});
}

function updateStats() {
    fetch('../hr/helpdesk_api.php?action=get_tickets')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const t = data.tickets;
                document.getElementById('totalTickets').textContent = t.length;
                document.getElementById('openTickets').textContent = t.filter(x => x.status === 'open').length;
                document.getElementById('progressTickets').textContent = t.filter(x => x.status === 'in_progress').length;
                document.getElementById('resolvedTickets').textContent = t.filter(x => x.status === 'resolved' || x.status === 'closed').length;
            }
        }).catch(() => {});
}

function displayTickets(tickets) {
    const container = document.getElementById('ticketsList');

    if (tickets.length === 0) {
        container.innerHTML = `
            <div class="tk-empty">
                <i class="fas fa-ticket-simple"></i>
                <h3>Aún no tienes tickets</h3>
                <p>Crea tu primer ticket para recibir ayuda de nuestro equipo.</p>
            </div>`;
        return;
    }

    container.innerHTML = tickets.map(ticket => `
        <div class="tk-card p-${ticket.priority}" onclick="viewTicket(${ticket.id})">
            <div class="tk-head">
                <span class="tk-num">#${escapeHtml(ticket.ticket_number)}</span>
                <span class="tk-badge st-${ticket.status}">${TK_STATUS[ticket.status] || ticket.status}</span>
            </div>
            <div class="tk-subject">${escapeHtml(ticket.subject)}</div>
            <div class="tk-desc">${escapeHtml(ticket.description)}</div>
            <div class="tk-meta">
                <span><i class="fas fa-tag"></i>${escapeHtml(ticket.category_name || '—')}</span>
                <span class="tk-badge pr-${ticket.priority}">${TK_PRIORITY[ticket.priority] || ticket.priority}</span>
                <span><i class="fas fa-clock"></i>${formatDate(ticket.created_at)}</span>
            </div>
        </div>
    `).join('');
}

function openCreateTicketModal() { document.getElementById('createTicketModal').style.display = 'block'; }
function closeCreateTicketModal() {
    document.getElementById('createTicketModal').style.display = 'none';
    document.getElementById('createTicketForm').reset();
    const fl = document.getElementById('ticketFilesList'); if (fl) fl.innerHTML = '';
}

function createTicket(event) {
    event.preventDefault();
    const submitBtn = event.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creando…';

    const formData = new FormData();
    formData.append('action', 'create_ticket');
    formData.append('category_id', document.getElementById('ticketCategory').value);
    formData.append('subject', document.getElementById('ticketSubject').value);
    formData.append('description', document.getElementById('ticketDescription').value);
    formData.append('priority', document.getElementById('ticketPriority').value);

    fetch('../hr/helpdesk_api.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            uploadTicketFiles(data.ticket_id, document.getElementById('ticketFiles'), null).then(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar ticket';
                alert('✓ ¡Ticket creado!\n\nNúmero de ticket: ' + data.ticket_number + '\n\nRecibirás actualizaciones por correo.');
                closeCreateTicketModal();
                loadTickets();
                updateStats();
            });
        } else {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar ticket';
            alert('Error: ' + data.error);
        }
    })
    .catch(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar ticket';
        alert('No se pudo crear el ticket. Intenta de nuevo.');
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

function closeViewTicketModal() { document.getElementById('viewTicketModal').style.display = 'none'; }

function displayTicketDetails(ticket) {
    currentTicket = ticket;
    const atts = ticket.attachments || [];
    const descAtts = atts.filter(a => !a.comment_id);
    const canReopen = (ticket.status === 'resolved' || ticket.status === 'closed');
    const canCancel = (ticket.status === 'open' || ticket.status === 'in_progress' || ticket.status === 'pending');

    let html = `
        <div class="tk-num" style="font-size:13px;">#${escapeHtml(ticket.ticket_number)}</div>
        <h2 style="margin:6px 0 14px;">${escapeHtml(ticket.subject)}</h2>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px;">
            <span class="tk-badge st-${ticket.status}">${TK_STATUS[ticket.status] || ticket.status}</span>
            <span class="tk-badge pr-${ticket.priority}">${TK_PRIORITY[ticket.priority] || ticket.priority}</span>
            <span class="tk-badge" style="background:${ticket.category_color || '#EEF1F6'}20; color:${ticket.category_color || '#64748B'};">${escapeHtml(ticket.category_name || '—')}</span>
        </div>
        <div class="tk-detail-box">
            <p><strong>Creado:</strong> ${formatDate(ticket.created_at)}</p>
            ${ticket.assigned_to_name ? `<p style="margin:0;"><strong>Atiende:</strong> ${escapeHtml(ticket.assigned_to_name)}</p>` : '<p style="margin:0;"><strong>Estado:</strong> Esperando asignación</p>'}
        </div>
        ${(canCancel || canReopen) ? `<div class="tk-actions">
            ${canCancel ? `<button class="ag-btn" style="background:var(--ag-soft);border:1px solid var(--ag-border);color:var(--ag-text)" onclick="changeMyTicket('cancelled')"><i class="fas fa-ban"></i> Cancelar ticket</button>` : ''}
            ${canReopen ? `<button class="ag-btn ag-btn-primary" onclick="changeMyTicket('open')"><i class="fas fa-rotate-left"></i> Reabrir ticket</button>` : ''}
        </div>` : ''}
        <div style="margin-bottom:22px;">
            <h4 style="margin:0 0 8px; color:var(--ag-text); font-size:14px;">Descripción</h4>
            <p style="white-space:pre-wrap; color:var(--ag-muted); font-size:13.5px; line-height:1.6; margin:0;">${escapeHtml(ticket.description)}</p>
            ${descAtts.length ? `<div class="tk-atts">${descAtts.map(attHtml).join('')}</div>` : ''}
        </div>`;

    html += `
        <div class="tk-comments">
            <h4 style="margin:0 0 12px; color:var(--ag-text); font-size:14px;">Actualizaciones y comentarios (${ticket.comments.length})</h4>
            ${ticket.comments.length === 0 ? '<p style="color:var(--ag-muted); font-size:13px;">Aún no hay comentarios. Nuestro equipo responderá pronto.</p>' : ''}
            ${ticket.comments.map(comment => {
                const cAtts = atts.filter(a => a.comment_id == comment.id);
                return `<div class="tk-comment">
                    <div class="ch"><span class="ca">${escapeHtml(comment.user_name)}</span><span class="cd">${formatDate(comment.created_at)}</span></div>
                    <div class="ct">${escapeHtml(comment.comment)}</div>
                    ${cAtts.length ? `<div class="tk-atts">${cAtts.map(attHtml).join('')}</div>` : ''}
                </div>`;
            }).join('')}
            <div style="margin-top:20px;">
                <h4 style="margin:0 0 8px; color:var(--ag-text); font-size:14px;">Agregar comentario</h4>
                <form onsubmit="addComment(event)">
                    <div class="tk-field">
                        <textarea id="newComment" placeholder="Escribe tu comentario o adjunta una captura…"></textarea>
                    </div>
                    <div class="tk-fileinput" style="margin-bottom:12px;">
                        <label class="btn" for="commentFiles"><i class="fas fa-paperclip"></i> Adjuntar</label>
                        <input type="file" id="commentFiles" multiple accept="image/*,application/pdf,.txt" style="display:none;" onchange="renderCommentFiles()">
                        <span id="commentFilesList" style="font-size:12px;color:var(--ag-muted);"></span>
                    </div>
                    <button type="submit" class="ag-btn ag-btn-primary"><i class="fas fa-comment"></i> Agregar comentario</button>
                </form>
            </div>
        </div>`;

    if (ticket.status_history && ticket.status_history.length) {
        html += `<div class="tk-timeline">
            <h4 style="margin:0 0 12px; color:var(--ag-text); font-size:14px;">Historial</h4>
            ${ticket.status_history.map(h => `<div class="tk-tl-item"><span class="tk-tl-dot"></span><div><b style="color:var(--ag-text)">${TK_STATUS[h.new_status] || h.new_status}</b> · ${escapeHtml(h.changed_by_name || '')} · ${formatDate(h.created_at)}</div></div>`).join('')}
        </div>`;
    }

    document.getElementById('ticketDetails').innerHTML = html;
}

function renderCommentFiles() {
    const fi = document.getElementById('commentFiles');
    document.getElementById('commentFilesList').textContent = fi.files.length ? (fi.files.length + ' archivo(s) listo(s)') : '';
}

function changeMyTicket(status) {
    if (status === 'cancelled' && !confirm('¿Seguro que quieres cancelar este ticket?')) return;
    const fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('ticket_id', currentTicket.id);
    fd.append('status', status);
    fetch('../hr/helpdesk_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) { viewTicket(currentTicket.id); loadTickets(); updateStats(); } else { alert('Error: ' + (d.error || '')); } });
}

function addComment(event) {
    event.preventDefault();
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const txt = document.getElementById('newComment').value.trim();
    const fi = document.getElementById('commentFiles');
    const hasFiles = fi && fi.files && fi.files.length;
    if (!txt && !hasFiles) { alert('Escribe un comentario o adjunta un archivo.'); return; }
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando…';
    const done = () => viewTicket(currentTicket.id);

    if (txt) {
        const fd = new FormData();
        fd.append('action', 'add_comment');
        fd.append('ticket_id', currentTicket.id);
        fd.append('comment', txt);
        fetch('../hr/helpdesk_api.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(d => {
            if (!d.success) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-comment"></i> Agregar comentario'; alert('Error: ' + d.error); return; }
            uploadTicketFiles(currentTicket.id, fi, d.comment_id || null).then(done);
        });
    } else {
        uploadTicketFiles(currentTicket.id, fi, null).then(done);
    }
}

function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text ?? ''; return div.innerHTML; }

// ---- Adjuntos ----
function renderCreateFiles() {
    const fi = document.getElementById('ticketFiles');
    const box = document.getElementById('ticketFilesList');
    box.innerHTML = Array.from(fi.files).map(f => `<span class="tk-file-chip"><i class="fas fa-paperclip"></i> ${escapeHtml(f.name)}</span>`).join('');
}
// Sube cada archivo del input al ticket (opcionalmente ligado a un comentario).
function uploadTicketFiles(ticketId, fileInput, commentId) {
    if (!fileInput || !fileInput.files || !fileInput.files.length) return Promise.resolve();
    const jobs = Array.from(fileInput.files).map(f => {
        const fd = new FormData();
        fd.append('action', 'upload_attachment');
        fd.append('ticket_id', ticketId);
        if (commentId) fd.append('comment_id', commentId);
        fd.append('file', f);
        return fetch('../hr/helpdesk_api.php', { method: 'POST', body: fd }).then(r => r.json()).catch(() => null);
    });
    return Promise.all(jobs);
}
function attHtml(a) {
    const url = `../hr/helpdesk_attachment.php?id=${a.id}`;
    return a.is_image == 1
        ? `<a class="tk-att" href="${url}" target="_blank"><img src="${url}" alt="${escapeHtml(a.file_name)}"></a>`
        : `<a class="tk-att" href="${url}" target="_blank"><i class="fas fa-paperclip"></i> ${escapeHtml(a.file_name)}</a>`;
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const diffMs = new Date() - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    if (diffMins < 1) return 'Ahora';
    if (diffMins < 60) return `hace ${diffMins} min`;
    if (diffHours < 24) return `hace ${diffHours} h`;
    if (diffDays < 7) return `hace ${diffDays} días`;
    return date.toLocaleDateString('es-DO');
}

window.onclick = function(event) {
    if (event.target.classList.contains('tk-modal')) event.target.style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
