<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Check permission
if (!userHasPermission('helpdesk_suggestions')) {
    header("Location: ../unauthorized.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

require_once __DIR__ . '/../header_agent.php';
?>

<style>
/* ===== Buzón de Sugerencias — UI premium con identidad Evallish ===== */
.sg-wrap{max-width:1200px;}
.sg-banner{position:relative; overflow:hidden; background:linear-gradient(120deg, var(--ag-brand) 0%, #33589c 55%, #3E63A8 100%);
    color:#fff; border-radius:var(--ag-radius); padding:22px 26px; margin-bottom:18px; box-shadow:0 10px 26px rgba(36,72,134,.22);}
.sg-banner::before{content:'\f0eb'; font-family:'Font Awesome 6 Free'; font-weight:900; position:absolute; right:-6px; bottom:-24px; font-size:120px; opacity:.10;}
.sg-banner h3{margin:0 0 6px; font-size:16px; font-weight:800; display:flex; align-items:center; gap:9px;}
.sg-banner p{margin:0; font-size:13px; opacity:.92; max-width:760px; line-height:1.55;}

.sg-filters{display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px;}
.sg-select{position:relative; flex:1; min-width:180px;}
.sg-select select{width:100%; appearance:none; background:#fff; border:1.5px solid var(--ag-border); border-radius:12px;
    padding:12px 38px 12px 14px; font-family:inherit; font-size:13.5px; font-weight:600; color:var(--ag-text); cursor:pointer; transition:.18s;}
.sg-select select:focus{outline:none; border-color:var(--ag-blue); box-shadow:0 0 0 3px rgba(72,150,254,.12);}
.sg-select .chev{position:absolute; right:14px; top:50%; transform:translateY(-50%); color:var(--ag-faint); pointer-events:none; font-size:12px;}

.sg-grid{display:grid; gap:14px;}
.sg-card{background:#fff; border:1px solid var(--ag-border); border-left:4px solid var(--ag-blue); border-radius:14px;
    padding:20px; box-shadow:var(--ag-shadow-sm); cursor:pointer; transition:.18s;}
.sg-card:hover{transform:translateY(-3px); box-shadow:var(--ag-shadow);}
.sg-card.t-improvement{border-left-color:var(--ag-blue);}
.sg-card.t-new_feature{border-left-color:var(--ag-green);}
.sg-card.t-complaint{border-left-color:var(--ag-red);}
.sg-card.t-compliment{border-left-color:var(--ag-amber);}
.sg-card.t-other{border-left-color:#64748B;}
.sg-card .sg-title{font-size:16px; font-weight:700; color:var(--ag-text); margin:0 0 10px;}
.sg-badges{display:flex; gap:7px; flex-wrap:wrap; margin-bottom:12px;}
.sg-desc{color:var(--ag-muted); font-size:13.5px; line-height:1.55; margin-bottom:14px;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;}
.sg-meta{display:flex; align-items:center; gap:16px; flex-wrap:wrap; font-size:12px; color:var(--ag-muted);
    padding-top:12px; border-top:1px solid var(--ag-border);}
.sg-meta .sg-votes{margin-left:auto; display:flex; align-items:center; gap:6px; font-weight:700; color:var(--ag-brand);}

.sg-badge{padding:4px 11px; border-radius:20px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;}
.sg-badge.dept{background:#EEF1F6; color:var(--ag-muted);}
.st-pending{background:var(--ag-amber-bg); color:#B54708;}
.st-under_review{background:#E8F1FE; color:#1D5DB8;}
.st-approved{background:#E4F6F6; color:#0B7A7A;}
.st-implemented{background:var(--ag-green-bg); color:#0B7A4B;}
.st-rejected{background:var(--ag-red-bg); color:#B42318;}
.ty-improvement{background:#E8F1FE; color:#1D5DB8;}
.ty-new_feature{background:var(--ag-green-bg); color:#0B7A4B;}
.ty-complaint{background:var(--ag-red-bg); color:#B42318;}
.ty-compliment{background:var(--ag-amber-bg); color:#B54708;}
.ty-other{background:#EEF1F6; color:var(--ag-muted);}

.sg-loading,.sg-empty{text-align:center; padding:60px 20px; color:var(--ag-muted);}
.sg-empty i{font-size:44px; color:#CBD5E9; margin-bottom:14px; display:block;}
.sg-empty h3{color:var(--ag-text); font-size:16px; margin:0 0 4px;}
.sg-empty p{margin:0; font-size:13px;}

/* Modal */
.sg-modal{display:none; position:fixed; inset:0; z-index:1000; background:rgba(15,23,42,.5); backdrop-filter:blur(4px);}
.sg-modal-content{background:#fff; margin:44px auto; padding:30px; border-radius:18px; max-width:680px; max-height:86vh;
    overflow-y:auto; box-shadow:0 24px 60px rgba(15,23,42,.28); font-family:'Plus Jakarta Sans','Inter',sans-serif;}
.sg-modal h2{margin:0 0 4px; font-size:19px; font-weight:800; color:var(--ag-text); display:flex; align-items:center; gap:9px;}
.sg-modal .sub{color:var(--ag-muted); font-size:13px; margin:0 0 22px;}
.sg-close{float:right; font-size:26px; font-weight:700; cursor:pointer; color:var(--ag-faint); line-height:1;}
.sg-close:hover{color:var(--ag-text);}
.sg-field{margin-bottom:16px;}
.sg-field label{display:block; margin-bottom:6px; font-weight:600; font-size:12.5px; color:var(--ag-text);}
.sg-field input[type=text],.sg-field select,.sg-field textarea{width:100%; padding:11px 14px; border:1.5px solid var(--ag-border);
    border-radius:11px; font-family:inherit; font-size:13.5px; color:var(--ag-text); background:#fff; transition:.18s;}
.sg-field input:focus,.sg-field select:focus,.sg-field textarea:focus{outline:none; border-color:var(--ag-blue); box-shadow:0 0 0 3px rgba(72,150,254,.12);}
.sg-field textarea{min-height:130px; resize:vertical;}
.sg-check{display:flex; align-items:flex-start; gap:10px; background:var(--ag-soft); border:1px solid var(--ag-border); border-radius:11px; padding:12px 14px;}
.sg-check input{margin-top:2px; width:16px; height:16px; accent-color:var(--ag-brand);}
.sg-check .txt small{color:var(--ag-muted); font-size:11.5px;}
.sg-detail-box{background:var(--ag-soft); border:1px solid var(--ag-border); border-radius:12px; padding:16px 18px; margin-bottom:18px; font-size:13px; color:var(--ag-text);}
.sg-detail-box p{margin:0 0 6px;}
.sg-review-box{background:#E8F1FE; border:1px solid rgba(72,150,254,.3); border-left:4px solid var(--ag-blue); border-radius:12px; padding:16px 18px; margin-bottom:18px; font-size:13px; color:var(--ag-text);}
</style>

<div class="agent-dashboard sg-wrap">

    <div class="ag-pagehead">
        <div>
            <h1><i class="fas fa-lightbulb" style="color:var(--ag-amber);"></i> Buzón de Sugerencias</h1>
            <p>Comparte ideas y comentarios para mejorar Evallish.</p>
        </div>
        <div class="ag-head-actions">
            <button class="ag-btn ag-btn-primary" onclick="openCreateSuggestionModal()"><i class="fas fa-plus"></i> Nueva sugerencia</button>
        </div>
    </div>

    <div class="sg-banner">
        <h3><i class="fas fa-wand-magic-sparkles"></i> Comparte tus ideas</h3>
        <p>Valoramos tu opinión. Envía sugerencias de mejora, nuevas funciones o comentarios sobre cualquier área. Tu aporte nos ayuda a crear un mejor lugar de trabajo.</p>
    </div>

    <!-- KPIs -->
    <div class="ag-grid ag-kpis" id="sgStats" style="margin-bottom:18px;">
        <div class="ag-card ag-kpi"><div class="top"><div class="ico" style="background:var(--ag-brand-tint);color:var(--ag-brand)"><i class="fas fa-lightbulb"></i></div></div><div class="val" id="sgKpiTotal">0</div><div class="lbl">Sugerencias</div></div>
        <div class="ag-card ag-kpi"><div class="top"><div class="ico" style="background:var(--ag-amber-bg);color:var(--ag-amber)"><i class="fas fa-hourglass-half"></i></div></div><div class="val" id="sgKpiPending">0</div><div class="lbl">Pendientes</div></div>
        <div class="ag-card ag-kpi"><div class="top"><div class="ico" style="background:#E8F1FE;color:var(--ag-blue)"><i class="fas fa-magnifying-glass"></i></div></div><div class="val" id="sgKpiReview">0</div><div class="lbl">En revisión</div></div>
        <div class="ag-card ag-kpi"><div class="top"><div class="ico" style="background:var(--ag-green-bg);color:var(--ag-green)"><i class="fas fa-circle-check"></i></div></div><div class="val" id="sgKpiImpl">0</div><div class="lbl">Implementadas</div></div>
    </div>

    <!-- Filtros -->
    <div class="sg-filters">
        <div class="sg-select">
            <select id="filterDepartment" onchange="loadSuggestions()">
                <option value="">Todos los departamentos</option>
                <option value="IT">IT</option>
                <option value="HR">RRHH</option>
                <option value="Payroll">Nómina</option>
                <option value="Operations">Operaciones</option>
                <option value="Facilities">Instalaciones</option>
                <option value="Training">Capacitación</option>
                <option value="General">General</option>
            </select><i class="fas fa-chevron-down chev"></i>
        </div>
        <div class="sg-select">
            <select id="filterStatus" onchange="loadSuggestions()">
                <option value="">Todos los estados</option>
                <option value="pending">Pendiente</option>
                <option value="under_review">En revisión</option>
                <option value="approved">Aprobada</option>
                <option value="implemented">Implementada</option>
                <option value="rejected">Rechazada</option>
            </select><i class="fas fa-chevron-down chev"></i>
        </div>
        <div class="sg-select">
            <select id="filterType" onchange="loadSuggestions()">
                <option value="">Todos los tipos</option>
                <option value="improvement">Mejora</option>
                <option value="new_feature">Nueva función</option>
                <option value="complaint">Queja</option>
                <option value="compliment">Elogio</option>
                <option value="other">Otro</option>
            </select><i class="fas fa-chevron-down chev"></i>
        </div>
    </div>

    <!-- Lista -->
    <div class="sg-grid" id="suggestionsList">
        <div class="ag-skel-grid">
            <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="ag-skel-card">
                <div class="ag-skel ag-skel-line" style="width:45%;"></div>
                <div class="ag-skel ag-skel-line" style="width:92%;"></div>
                <div class="ag-skel ag-skel-line" style="width:65%; margin-bottom:0;"></div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- Crear -->
<div id="createSuggestionModal" class="sg-modal">
    <div class="sg-modal-content">
        <span class="sg-close" onclick="closeCreateSuggestionModal()">&times;</span>
        <h2><i class="fas fa-lightbulb" style="color:var(--ag-amber);"></i> Enviar una sugerencia</h2>
        <p class="sub">Comparte tus ideas, comentarios o sugerencias para ayudarnos a mejorar.</p>

        <form id="createSuggestionForm" onsubmit="createSuggestion(event)">
            <div class="ag-row2">
                <div class="sg-field">
                    <label>Departamento *</label>
                    <select id="suggestionDepartment" required>
                        <option value="">Selecciona un departamento</option>
                        <option value="IT">IT</option>
                        <option value="HR">RRHH</option>
                        <option value="Payroll">Nómina</option>
                        <option value="Operations">Operaciones</option>
                        <option value="Facilities">Instalaciones</option>
                        <option value="Training">Capacitación</option>
                        <option value="General">General</option>
                    </select>
                </div>
                <div class="sg-field">
                    <label>Tipo *</label>
                    <select id="suggestionType" required>
                        <option value="improvement">Mejora</option>
                        <option value="new_feature">Nueva función</option>
                        <option value="complaint">Queja</option>
                        <option value="compliment">Elogio</option>
                        <option value="other">Otro</option>
                    </select>
                </div>
            </div>
            <div class="sg-field">
                <label>Título *</label>
                <input type="text" id="suggestionTitle" required placeholder="Resumen breve de tu sugerencia">
            </div>
            <div class="sg-field">
                <label>Descripción *</label>
                <textarea id="suggestionDescription" required placeholder="Da detalles sobre tu sugerencia…"></textarea>
            </div>
            <div class="sg-field">
                <label class="sg-check">
                    <input type="checkbox" id="isAnonymous">
                    <span class="txt">Enviar de forma anónima<br><small>Tu identidad quedará oculta para otros usuarios (los administradores sí pueden verla).</small></span>
                </label>
            </div>
            <button type="submit" class="ag-btn ag-btn-primary" style="width:100%;"><i class="fas fa-paper-plane"></i> Enviar sugerencia</button>
        </form>
    </div>
</div>

<!-- Ver -->
<div id="viewSuggestionModal" class="sg-modal">
    <div class="sg-modal-content">
        <span class="sg-close" onclick="closeViewSuggestionModal()">&times;</span>
        <div id="suggestionDetails"></div>
    </div>
</div>

<script>
let currentSuggestion = null;

const SG_STATUS = {pending:'Pendiente', under_review:'En revisión', approved:'Aprobada', implemented:'Implementada', rejected:'Rechazada'};
const SG_TYPE = {improvement:'Mejora', new_feature:'Nueva función', complaint:'Queja', compliment:'Elogio', other:'Otro'};
const SG_DEPT = {IT:'IT', HR:'RRHH', Payroll:'Nómina', Operations:'Operaciones', Facilities:'Instalaciones', Training:'Capacitación', General:'General'};

document.addEventListener('DOMContentLoaded', function() {
    loadSuggestions();
    setInterval(loadSuggestions, 60000);
});

function loadSuggestions() {
    const department = document.getElementById('filterDepartment').value;
    const status = document.getElementById('filterStatus').value;
    const type = document.getElementById('filterType').value;

    let url = '../hr/suggestions_api.php?action=get_suggestions';
    if (department) url += '&department=' + encodeURIComponent(department);
    if (status) url += '&status=' + status;
    if (type) url += '&suggestion_type=' + type;

    fetch(url)
        .then(response => response.json())
        .then(data => { if (data.success) displaySuggestions(data.suggestions); })
        .catch(() => {});
}

function displaySuggestions(suggestions) {
    const container = document.getElementById('suggestionsList');

    // KPIs
    document.getElementById('sgKpiTotal').textContent = suggestions.length;
    document.getElementById('sgKpiPending').textContent = suggestions.filter(s => s.status === 'pending').length;
    document.getElementById('sgKpiReview').textContent = suggestions.filter(s => s.status === 'under_review').length;
    document.getElementById('sgKpiImpl').textContent = suggestions.filter(s => s.status === 'implemented').length;

    if (suggestions.length === 0) {
        container.innerHTML = `
            <div class="sg-empty">
                <i class="fas fa-lightbulb"></i>
                <h3>Aún no hay sugerencias</h3>
                <p>¡Sé el primero en compartir tus ideas!</p>
            </div>`;
        return;
    }

    container.innerHTML = suggestions.map(s => `
        <div class="sg-card t-${s.suggestion_type}" onclick="viewSuggestion(${s.id})">
            <h3 class="sg-title">${escapeHtml(s.title)}</h3>
            <div class="sg-badges">
                <span class="sg-badge st-${s.status}">${SG_STATUS[s.status] || s.status}</span>
                <span class="sg-badge ty-${s.suggestion_type}">${SG_TYPE[s.suggestion_type] || s.suggestion_type}</span>
                <span class="sg-badge dept">${SG_DEPT[s.department] || s.department}</span>
            </div>
            <div class="sg-desc">${escapeHtml(s.description)}</div>
            <div class="sg-meta">
                <span><i class="fas fa-user" style="margin-right:5px;"></i>${escapeHtml(s.user_name || 'Anónimo')}</span>
                <span><i class="fas fa-clock" style="margin-right:5px;"></i>${formatDate(s.created_at)}</span>
                <span class="sg-votes"><i class="fas fa-thumbs-up"></i> ${s.votes_count || 0} votos</span>
            </div>
        </div>
    `).join('');
}

function openCreateSuggestionModal() { document.getElementById('createSuggestionModal').style.display = 'block'; }
function closeCreateSuggestionModal() {
    document.getElementById('createSuggestionModal').style.display = 'none';
    document.getElementById('createSuggestionForm').reset();
}

function createSuggestion(event) {
    event.preventDefault();
    const submitBtn = event.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando…';

    const formData = new FormData();
    formData.append('action', 'create_suggestion');
    formData.append('department', document.getElementById('suggestionDepartment').value);
    formData.append('title', document.getElementById('suggestionTitle').value);
    formData.append('description', document.getElementById('suggestionDescription').value);
    formData.append('suggestion_type', document.getElementById('suggestionType').value);
    formData.append('is_anonymous', document.getElementById('isAnonymous').checked ? 1 : 0);

    fetch('../hr/suggestions_api.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar sugerencia';
        if (data.success) {
            alert('✓ ¡Sugerencia enviada!\n\nGracias por tu aporte. La revisaremos y te daremos seguimiento.');
            closeCreateSuggestionModal();
            loadSuggestions();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar sugerencia';
        alert('No se pudo enviar la sugerencia. Intenta de nuevo.');
    });
}

function viewSuggestion(suggestionId) {
    fetch(`../hr/suggestions_api.php?action=get_suggestion&suggestion_id=${suggestionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySuggestionDetails(data.suggestion);
                document.getElementById('viewSuggestionModal').style.display = 'block';
            }
        });
}

function closeViewSuggestionModal() { document.getElementById('viewSuggestionModal').style.display = 'none'; }

function displaySuggestionDetails(s) {
    currentSuggestion = s;
    let html = `
        <h2>${escapeHtml(s.title)}</h2>
        <div class="sg-badges" style="margin:12px 0 18px;">
            <span class="sg-badge st-${s.status}">${SG_STATUS[s.status] || s.status}</span>
            <span class="sg-badge ty-${s.suggestion_type}">${SG_TYPE[s.suggestion_type] || s.suggestion_type}</span>
            <span class="sg-badge dept">${SG_DEPT[s.department] || s.department}</span>
        </div>
        <div class="sg-detail-box">
            <p><strong>Enviada por:</strong> ${escapeHtml(s.user_name || 'Anónimo')}</p>
            <p><strong>Fecha:</strong> ${formatDate(s.created_at)}</p>
            <p style="margin:0;"><strong>Votos:</strong> ${s.votes_count || 0}</p>
        </div>
        <div style="margin-bottom:22px;">
            <h4 style="margin:0 0 8px; color:var(--ag-text); font-size:14px;">Descripción</h4>
            <p style="white-space:pre-wrap; color:var(--ag-muted); font-size:13.5px; line-height:1.6; margin:0;">${escapeHtml(s.description)}</p>
        </div>`;

    if (s.reviewed_by_name) {
        html += `
            <div class="sg-review-box">
                <h4 style="margin:0 0 8px; color:var(--ag-text); font-size:14px;">Revisión</h4>
                <p style="margin:0 0 5px;"><strong>Revisada por:</strong> ${escapeHtml(s.reviewed_by_name)}</p>
                <p style="margin:0 0 5px;"><strong>Fecha:</strong> ${formatDate(s.reviewed_at)}</p>
                ${s.review_notes ? `<p style="margin:0;"><strong>Notas:</strong><br>${escapeHtml(s.review_notes)}</p>` : ''}
            </div>`;
    }

    html += `
        <div style="display:flex; gap:10px; margin-top:6px;">
            <button class="ag-btn ag-btn-primary" onclick="voteSuggestion('up')"><i class="fas fa-thumbs-up"></i> A favor</button>
            <button class="ag-btn ag-btn-ghost" onclick="voteSuggestion('down')"><i class="fas fa-thumbs-down"></i> En contra</button>
        </div>`;

    document.getElementById('suggestionDetails').innerHTML = html;
}

function voteSuggestion(voteType) {
    const formData = new FormData();
    formData.append('action', 'vote_suggestion');
    formData.append('suggestion_id', currentSuggestion.id);
    formData.append('vote_type', voteType);

    fetch('../hr/suggestions_api.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) { viewSuggestion(currentSuggestion.id); loadSuggestions(); }
        else { alert('Error: ' + data.error); }
    });
}

function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text ?? ''; return div.innerHTML; }

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const now = new Date();
    const diffDays = Math.floor((now - date) / 86400000);
    if (diffDays === 0) return 'Hoy';
    if (diffDays === 1) return 'Ayer';
    if (diffDays < 7) return `hace ${diffDays} días`;
    return date.toLocaleDateString('es-DO');
}

window.onclick = function(event) {
    if (event.target.classList.contains('sg-modal')) event.target.style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
