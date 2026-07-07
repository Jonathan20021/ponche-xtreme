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

/* Toolbar (buscador + filtros) */
.tk-toolbar{display:flex; gap:10px; flex-wrap:wrap; align-items:center; background:var(--ag-card); border:1px solid var(--ag-border);
    border-radius:16px; padding:12px; box-shadow:var(--ag-shadow-sm); margin-bottom:18px;}
.tk-search{position:relative; flex:1 1 240px; min-width:200px;}
.tk-search i{position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--ag-faint); font-size:13px; pointer-events:none;}
.tk-search input{width:100%; border:1.5px solid var(--ag-border); border-radius:12px; padding:11px 14px 11px 38px;
    font-family:inherit; font-size:13.5px; font-weight:500; color:var(--ag-text); background:var(--ag-soft); transition:.18s var(--ag-ease);}
.tk-search input:focus{outline:none; background:#fff; border-color:var(--ag-blue); box-shadow:0 0 0 3px rgba(72,150,254,.14);}
.tk-select{position:relative; flex:0 1 190px; min-width:150px;}
.tk-select select{width:100%; appearance:none; background:#fff; border:1.5px solid var(--ag-border); border-radius:12px;
    padding:11px 36px 11px 14px; font-family:inherit; font-size:13px; font-weight:600; color:var(--ag-text); cursor:pointer; transition:.18s var(--ag-ease);}
.tk-select select:hover{border-color:#D4DBE8;}
.tk-select select:focus{outline:none; border-color:var(--ag-blue); box-shadow:0 0 0 3px rgba(72,150,254,.12);}
.tk-select .chev{position:absolute; right:13px; top:50%; transform:translateY(-50%); color:var(--ag-faint); pointer-events:none; font-size:11px;}

/* KPIs clicables */
.tk-kpi{cursor:pointer; position:relative; transition:transform .18s var(--ag-ease), box-shadow .18s var(--ag-ease), border-color .18s var(--ag-ease);}
.tk-kpi:hover{transform:translateY(-3px); box-shadow:var(--ag-shadow);}
.tk-kpi.active{border-color:var(--ag-brand); box-shadow:0 0 0 3px rgba(36,72,134,.10), var(--ag-shadow-sm);}
.tk-kpi.active::after{content:''; position:absolute; left:16px; right:16px; bottom:0; height:3px; border-radius:3px 3px 0 0; background:var(--ag-brand);}

/* Lista de tickets */
.tk-grid{display:grid; gap:13px;}
.tk-card{position:relative; background:#fff; border:1px solid var(--ag-border); border-radius:16px;
    padding:18px 20px 18px 22px; box-shadow:var(--ag-shadow-sm); cursor:pointer; overflow:hidden;
    transition:transform .18s var(--ag-ease), box-shadow .18s var(--ag-ease), border-color .18s var(--ag-ease);}
.tk-card::before{content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--ag-blue);}
.tk-card:hover{transform:translateY(-3px); box-shadow:var(--ag-shadow); border-color:#DCE3F0;}
.tk-card:hover .tk-go{opacity:1; transform:translateX(0);}
.tk-card.p-low::before{background:var(--ag-green);}
.tk-card.p-medium::before{background:var(--ag-blue);}
.tk-card.p-high::before{background:var(--ag-amber);}
.tk-card.p-critical::before{background:var(--ag-red);}
.tk-head{display:flex; align-items:center; gap:10px; margin-bottom:9px;}
.tk-prio-ico{width:30px; height:30px; border-radius:9px; display:grid; place-items:center; font-size:12px; flex-shrink:0;}
.tk-prio-ico.p-low{background:var(--ag-green-bg); color:#0B7A4B;}
.tk-prio-ico.p-medium{background:#E8F1FE; color:#1D5DB8;}
.tk-prio-ico.p-high{background:var(--ag-amber-bg); color:#B54708;}
.tk-prio-ico.p-critical{background:var(--ag-red-bg); color:#B42318;}
.tk-num{font-size:12px; font-weight:800; color:var(--ag-brand); letter-spacing:.3px; font-variant-numeric:tabular-nums;}
.tk-head .tk-badge{margin-left:auto;}
.tk-subject{font-size:15.5px; font-weight:700; color:var(--ag-text); margin:0 0 6px; line-height:1.35;
    display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden;}
.tk-desc{color:var(--ag-muted); font-size:13px; line-height:1.55; margin-bottom:14px;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;}
.tk-meta{display:flex; align-items:center; gap:8px 14px; flex-wrap:wrap; font-size:12px; color:var(--ag-muted);
    padding-top:12px; border-top:1px solid var(--ag-border);}
.tk-meta .m{display:inline-flex; align-items:center; gap:6px;}
.tk-meta .m i{color:var(--ag-faint); font-size:11px;}
.tk-go{position:absolute; right:16px; bottom:16px; color:var(--ag-brand); font-size:13px; opacity:0; transform:translateX(-4px);
    transition:opacity .18s var(--ag-ease), transform .18s var(--ag-ease);}

/* Badges */
.tk-badge{padding:4px 11px; border-radius:20px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap;}
.tk-dot{display:inline-block; width:6px; height:6px; border-radius:50%; margin-right:5px; vertical-align:middle;}
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

.tk-empty{text-align:center; padding:56px 20px; color:var(--ag-muted);}
.tk-empty .ico{width:74px; height:74px; margin:0 auto 16px; border-radius:20px; display:grid; place-items:center;
    background:linear-gradient(135deg,var(--ag-brand-tint),#F3F6FD); color:var(--ag-brand); font-size:30px;}
.tk-empty h3{color:var(--ag-text); font-size:16.5px; margin:0 0 5px; font-weight:700;}
.tk-empty p{margin:0 0 18px; font-size:13px;}

/* Modal premium */
.tk-modal{display:none; position:fixed; inset:0; z-index:1000; background:rgba(15,23,42,.55); backdrop-filter:blur(5px);
    padding:24px; overflow-y:auto;}
.tk-modal.show{display:flex; align-items:flex-start; justify-content:center; animation:tk-fade .2s var(--ag-ease);}
.tk-modal-content{position:relative; background:#fff; margin:32px auto; border-radius:20px; max-width:680px; width:100%;
    box-shadow:0 30px 80px rgba(15,23,42,.32); font-family:'Plus Jakarta Sans','Inter',sans-serif; overflow:hidden;}
.tk-modal.show .tk-modal-content{animation:tk-modal-in .3s var(--ag-ease);}
.tk-modal-head{position:relative; padding:24px 28px 20px; border-bottom:1px solid var(--ag-border);
    background:linear-gradient(180deg,var(--ag-brand-tint),#fff);}
.tk-modal-head::before{content:''; position:absolute; left:0; top:0; right:0; height:3px; background:linear-gradient(90deg,var(--ag-brand),var(--ag-blue));}
.tk-modal-head h2{margin:0; font-size:19px; font-weight:800; color:var(--ag-text); display:flex; align-items:center; gap:11px; letter-spacing:-.3px;}
.tk-modal-head h2 .hic{width:38px; height:38px; border-radius:11px; background:var(--ag-brand); color:#fff; display:grid; place-items:center; font-size:15px; flex-shrink:0;}
.tk-modal-head .sub{color:var(--ag-muted); font-size:13px; margin:8px 0 0; padding-left:49px;}
.tk-modal-body{padding:24px 28px 28px; max-height:calc(90vh - 120px); overflow-y:auto;}
.tk-x{position:absolute; top:18px; right:18px; width:34px; height:34px; border-radius:10px; border:1px solid var(--ag-border);
    background:#fff; color:var(--ag-muted); cursor:pointer; display:grid; place-items:center; font-size:14px; transition:.18s var(--ag-ease); z-index:2;}
.tk-x:hover{background:var(--ag-red-bg); color:#B42318; border-color:transparent;}

/* Campos */
.tk-field{margin-bottom:17px;}
.tk-field label{display:block; margin-bottom:7px; font-weight:700; font-size:12px; color:var(--ag-text); text-transform:uppercase; letter-spacing:.3px;}
.tk-field input[type=text],.tk-field select,.tk-field textarea{width:100%; padding:12px 14px; border:1.5px solid var(--ag-border);
    border-radius:12px; font-family:inherit; font-size:13.5px; color:var(--ag-text); background:var(--ag-soft); transition:.18s var(--ag-ease);}
.tk-field input:focus,.tk-field select:focus,.tk-field textarea:focus{outline:none; background:#fff; border-color:var(--ag-blue); box-shadow:0 0 0 3px rgba(72,150,254,.12);}
.tk-field textarea{min-height:130px; resize:vertical; line-height:1.55;}

/* Selector de prioridad segmentado */
.tk-prio-seg{display:grid; grid-template-columns:repeat(4,1fr); gap:8px;}
.tk-prio-seg .seg{display:flex; align-items:center; justify-content:center; gap:7px; padding:11px 8px; border:1.5px solid var(--ag-border);
    background:#fff; border-radius:12px; font-family:inherit; font-size:12.5px; font-weight:700; color:var(--ag-muted); cursor:pointer; transition:.15s var(--ag-ease);}
.tk-prio-seg .seg .dot{width:9px; height:9px; border-radius:50%;}
.tk-prio-seg .seg:hover{border-color:#D4DBE8; color:var(--ag-text);}
.tk-prio-seg .seg.active{border-color:var(--ag-brand); background:var(--ag-brand-tint); color:var(--ag-brand); box-shadow:0 0 0 3px rgba(36,72,134,.08);}
@media(max-width:520px){ .tk-prio-seg{grid-template-columns:repeat(2,1fr);} }

/* Detalle */
.tk-detail-box{display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px;
    background:var(--ag-soft); border:1px solid var(--ag-border); border-radius:14px; padding:16px 18px; margin-bottom:18px;}
.tk-detail-box .di{display:flex; align-items:center; gap:11px;}
.tk-detail-box .di .ic{width:34px; height:34px; border-radius:10px; background:#fff; border:1px solid var(--ag-border); display:grid; place-items:center; color:var(--ag-brand); font-size:13px; flex-shrink:0;}
.tk-detail-box .di .k{font-size:11px; color:var(--ag-faint); font-weight:600; text-transform:uppercase; letter-spacing:.3px;}
.tk-detail-box .di .v{font-size:13.5px; color:var(--ag-text); font-weight:700; margin-top:1px;}
.tk-sec-title{font-size:11px; font-weight:800; color:var(--ag-faint); text-transform:uppercase; letter-spacing:.5px; margin:0 0 10px;}
.tk-descblock{color:var(--ag-text); font-size:14px; line-height:1.65; white-space:pre-wrap; margin:0;}

/* Hilo de comentarios */
.tk-thread{margin-top:24px; padding-top:22px; border-top:1px solid var(--ag-border);}
.tk-msg{display:flex; gap:11px; margin-bottom:14px;}
.tk-msg .av{width:34px; height:34px; border-radius:50%; background:var(--ag-brand); color:#fff; font-size:12px; font-weight:700;
    display:grid; place-items:center; flex-shrink:0;}
.tk-msg .bub{flex:1; min-width:0; background:var(--ag-soft); border:1px solid var(--ag-border); border-radius:4px 14px 14px 14px; padding:12px 15px;}
.tk-msg .ch{display:flex; justify-content:space-between; align-items:baseline; gap:10px; margin-bottom:5px;}
.tk-msg .ca{font-weight:700; color:var(--ag-text); font-size:13px;}
.tk-msg .cd{color:var(--ag-faint); font-size:11.5px; white-space:nowrap;}
.tk-msg .ct{color:var(--ag-muted); font-size:13.5px; white-space:pre-wrap; line-height:1.55; word-break:break-word;}
.tk-noco{color:var(--ag-muted); font-size:13px; background:var(--ag-soft); border:1px dashed var(--ag-border); border-radius:12px; padding:16px; text-align:center;}

/* Adjuntos */
.tk-files{display:flex; flex-wrap:wrap; gap:8px; margin-top:10px;}
.tk-file-chip{display:inline-flex; align-items:center; gap:6px; font-size:11.5px; background:var(--ag-brand-tint); color:var(--ag-brand); border-radius:9px; padding:6px 11px; font-weight:600;}
.tk-file-chip a{color:inherit; text-decoration:none; cursor:pointer; font-weight:700; margin-left:2px;}
.tk-atts{display:flex; flex-wrap:wrap; gap:9px; margin-top:12px;}
.tk-att{display:inline-flex; align-items:center; gap:7px; font-size:12px; font-weight:600; color:var(--ag-brand); background:#fff; border:1px solid var(--ag-border); border-radius:10px; padding:6px 11px; text-decoration:none; transition:.15s var(--ag-ease);}
.tk-att:hover{border-color:var(--ag-blue); box-shadow:var(--ag-shadow-sm);}
.tk-att img{max-width:170px; max-height:120px; border-radius:9px; border:1px solid var(--ag-border); display:block;}
.tk-fileinput{display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
.tk-fileinput .btn{display:inline-flex; align-items:center; gap:7px; font-size:12.5px; font-weight:700; color:var(--ag-brand); background:var(--ag-brand-tint); border:1px solid #D3DEFB; border-radius:11px; padding:9px 14px; cursor:pointer; transition:.15s var(--ag-ease);}
.tk-fileinput .btn:hover{background:#E1EAFB;}

/* Timeline (reusa look ag-tl) */
.tk-timeline{margin-top:22px; padding-top:20px; border-top:1px solid var(--ag-border);}
.tk-tl-item{position:relative; display:flex; gap:13px; font-size:12.5px; color:var(--ag-muted); padding-bottom:14px;}
.tk-tl-item:not(:last-child)::before{content:''; position:absolute; left:5px; top:14px; bottom:-2px; width:2px; background:var(--ag-border);}
.tk-tl-dot{width:12px; height:12px; border-radius:50%; background:#fff; border:3px solid var(--ag-brand); margin-top:2px; flex-shrink:0; z-index:1;}

.tk-actions{display:flex; gap:8px; flex-wrap:wrap; margin:0 0 20px;}
.tk-actions .ag-btn{font-size:12.5px;}

/* Toasts */
.tk-toast-wrap{position:fixed; top:20px; right:20px; z-index:2000; display:flex; flex-direction:column; gap:10px; max-width:min(360px,calc(100vw - 40px));}
.tk-toast{display:flex; align-items:center; gap:11px; background:#fff; border:1px solid var(--ag-border); border-left:4px solid var(--ag-blue);
    border-radius:13px; padding:13px 16px; box-shadow:var(--ag-shadow-lg); font-size:13.5px; font-weight:600; color:var(--ag-text);
    animation:tk-toast-in .3s var(--ag-ease);}
.tk-toast i{font-size:16px; flex-shrink:0;}
.tk-toast.out{animation:tk-toast-out .3s var(--ag-ease) forwards;}
.tk-toast.success{border-left-color:var(--ag-green);} .tk-toast.success i{color:var(--ag-green);}
.tk-toast.error{border-left-color:var(--ag-red);} .tk-toast.error i{color:var(--ag-red);}
.tk-toast.info{border-left-color:var(--ag-blue);} .tk-toast.info i{color:var(--ag-blue);}

@keyframes tk-fade{from{opacity:0;} to{opacity:1;}}
@keyframes tk-modal-in{from{opacity:0; transform:translateY(14px) scale(.98);} to{opacity:1; transform:none;}}
@keyframes tk-toast-in{from{opacity:0; transform:translateX(40px);} to{opacity:1; transform:none;}}
@keyframes tk-toast-out{to{opacity:0; transform:translateX(40px);}}
@media(prefers-reduced-motion:reduce){
  .tk-card,.tk-kpi,.tk-modal,.tk-modal-content,.tk-toast,.tk-go{animation:none!important; transition:none!important;}
  .tk-card:hover,.tk-kpi:hover{transform:none;}
}
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

    <!-- KPIs (clic para filtrar) -->
    <div class="ag-grid ag-kpis" style="margin-bottom:18px;">
        <div class="ag-card ag-kpi tk-kpi" data-filter="" role="button" tabindex="0" aria-label="Ver todos los tickets"><div class="top"><div class="ico" style="background:var(--ag-brand-tint);color:var(--ag-brand)"><i class="fas fa-ticket"></i></div></div><div class="val" id="totalTickets">0</div><div class="lbl">Total de tickets</div></div>
        <div class="ag-card ag-kpi tk-kpi" data-filter="open" role="button" tabindex="0" aria-label="Filtrar abiertos"><div class="top"><div class="ico" style="background:#E8F1FE;color:var(--ag-blue)"><i class="fas fa-envelope-open-text"></i></div></div><div class="val" id="openTickets">0</div><div class="lbl">Abiertos</div></div>
        <div class="ag-card ag-kpi tk-kpi" data-filter="in_progress" role="button" tabindex="0" aria-label="Filtrar en progreso"><div class="top"><div class="ico" style="background:var(--ag-amber-bg);color:var(--ag-amber)"><i class="fas fa-spinner"></i></div></div><div class="val" id="progressTickets">0</div><div class="lbl">En progreso</div></div>
        <div class="ag-card ag-kpi tk-kpi" data-filter="resolved" role="button" tabindex="0" aria-label="Filtrar resueltos"><div class="top"><div class="ico" style="background:var(--ag-green-bg);color:var(--ag-green)"><i class="fas fa-circle-check"></i></div></div><div class="val" id="resolvedTickets">0</div><div class="lbl">Resueltos</div></div>
    </div>

    <!-- Toolbar: buscador + filtros -->
    <div class="tk-toolbar">
        <div class="tk-search">
            <i class="fas fa-search"></i>
            <input type="text" id="searchTickets" placeholder="Buscar por número, asunto o descripción…" oninput="renderTickets()" aria-label="Buscar tickets">
        </div>
        <div class="tk-select">
            <select id="filterStatus" onchange="loadTickets()" aria-label="Filtrar por estado">
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
            <select id="filterPriority" onchange="loadTickets()" aria-label="Filtrar por prioridad">
                <option value="">Todas las prioridades</option>
                <option value="low">Baja</option>
                <option value="medium">Media</option>
                <option value="high">Alta</option>
                <option value="critical">Crítica</option>
            </select><i class="fas fa-chevron-down chev"></i>
        </div>
        <div class="tk-select">
            <select id="filterCategory" onchange="loadTickets()" aria-label="Filtrar por categoría">
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
<div id="createTicketModal" class="tk-modal" role="dialog" aria-modal="true" aria-labelledby="createTicketTitle">
    <div class="tk-modal-content">
        <button class="tk-x" type="button" onclick="closeCreateTicketModal()" aria-label="Cerrar"><i class="fas fa-times"></i></button>
        <div class="tk-modal-head">
            <h2 id="createTicketTitle"><span class="hic"><i class="fas fa-circle-plus"></i></span> Crear ticket de soporte</h2>
            <p class="sub">Describe tu problema o solicitud y te ayudaremos lo antes posible.</p>
        </div>
        <div class="tk-modal-body">
            <form id="createTicketForm" onsubmit="createTicket(event)">
                <div class="tk-field">
                    <label for="ticketCategory">Categoría *</label>
                    <select id="ticketCategory" required><option value="">Selecciona una categoría</option></select>
                </div>
                <div class="tk-field">
                    <label for="ticketSubject">Asunto *</label>
                    <input type="text" id="ticketSubject" required placeholder="Resumen breve de tu problema" maxlength="180">
                </div>
                <div class="tk-field">
                    <label for="ticketDescription">Descripción *</label>
                    <textarea id="ticketDescription" required placeholder="Da detalles sobre tu problema o solicitud…"></textarea>
                </div>
                <div class="tk-field">
                    <label>Prioridad</label>
                    <input type="hidden" id="ticketPriority" value="medium">
                    <div class="tk-prio-seg" id="prioSeg" role="radiogroup" aria-label="Prioridad">
                        <button type="button" class="seg" data-v="low" role="radio" aria-checked="false"><span class="dot" style="background:var(--ag-green)"></span>Baja</button>
                        <button type="button" class="seg active" data-v="medium" role="radio" aria-checked="true"><span class="dot" style="background:var(--ag-blue)"></span>Media</button>
                        <button type="button" class="seg" data-v="high" role="radio" aria-checked="false"><span class="dot" style="background:var(--ag-amber)"></span>Alta</button>
                        <button type="button" class="seg" data-v="critical" role="radio" aria-checked="false"><span class="dot" style="background:var(--ag-red)"></span>Crítica</button>
                    </div>
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
                <button type="submit" class="ag-btn ag-btn-primary" style="width:100%; margin-top:4px;"><i class="fas fa-paper-plane"></i> Enviar ticket</button>
            </form>
        </div>
    </div>
</div>

<!-- Ver -->
<div id="viewTicketModal" class="tk-modal" role="dialog" aria-modal="true" aria-label="Detalle del ticket">
    <div class="tk-modal-content">
        <button class="tk-x" type="button" onclick="closeViewTicketModal()" aria-label="Cerrar"><i class="fas fa-times"></i></button>
        <div class="tk-modal-body" id="ticketDetails" style="max-height:calc(90vh - 40px);"></div>
    </div>
</div>

<script>
let categories = [];
let currentTicket = null;
let loadedTickets = [];

const TK_STATUS = {open:'Abierto', in_progress:'En progreso', pending:'Pendiente', resolved:'Resuelto', closed:'Cerrado', cancelled:'Cancelado'};
const TK_PRIORITY = {low:'Baja', medium:'Media', high:'Alta', critical:'Crítica'};
const TK_PRIO_ICON = {low:'fa-angle-down', medium:'fa-equals', high:'fa-angle-up', critical:'fa-angles-up'};

document.addEventListener('DOMContentLoaded', function() {
    loadCategories();
    loadTickets();
    updateStats();
    initPrioSeg();
    initKpiFilters();
    setInterval(() => { loadTickets(); updateStats(); }, 30000);
});

// Selector de prioridad segmentado -> escribe en #ticketPriority
function initPrioSeg() {
    const seg = document.getElementById('prioSeg');
    if (!seg) return;
    seg.querySelectorAll('.seg').forEach(btn => {
        btn.addEventListener('click', () => setPriority(btn.dataset.v));
    });
}
function setPriority(v) {
    document.getElementById('ticketPriority').value = v;
    document.querySelectorAll('#prioSeg .seg').forEach(b => {
        const on = b.dataset.v === v;
        b.classList.toggle('active', on);
        b.setAttribute('aria-checked', on ? 'true' : 'false');
    });
}

// KPIs clicables -> filtran por estado
function initKpiFilters() {
    document.querySelectorAll('.tk-kpi').forEach(kpi => {
        const apply = () => {
            document.getElementById('filterStatus').value = kpi.dataset.filter;
            loadTickets();
        };
        kpi.addEventListener('click', apply);
        kpi.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); apply(); } });
    });
}
function syncKpiActive() {
    const st = document.getElementById('filterStatus').value;
    document.querySelectorAll('.tk-kpi').forEach(kpi => {
        kpi.classList.toggle('active', (kpi.dataset.filter || '') === st && st !== '');
    });
}

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
    syncKpiActive();

    let url = '../hr/helpdesk_api.php?action=get_tickets';
    if (status) url += '&status=' + status;
    if (priority) url += '&priority=' + priority;
    if (category) url += '&category_id=' + category;

    fetch(url)
        .then(response => response.json())
        .then(data => { if (data.success) { loadedTickets = data.tickets; renderTickets(); } })
        .catch(() => {});
}

// Filtro de búsqueda cliente sobre los tickets ya cargados.
function renderTickets() {
    const q = (document.getElementById('searchTickets').value || '').trim().toLowerCase();
    let list = loadedTickets;
    if (q) {
        list = loadedTickets.filter(t =>
            (t.ticket_number || '').toLowerCase().includes(q) ||
            (t.subject || '').toLowerCase().includes(q) ||
            (t.description || '').toLowerCase().includes(q));
    }
    displayTickets(list, q);
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

function displayTickets(tickets, q) {
    const container = document.getElementById('ticketsList');

    if (tickets.length === 0) {
        const searching = !!q;
        container.innerHTML = `
            <div class="tk-empty">
                <div class="ico"><i class="fas fa-${searching ? 'magnifying-glass' : 'ticket-simple'}"></i></div>
                <h3>${searching ? 'Sin resultados' : 'Aún no tienes tickets'}</h3>
                <p>${searching ? 'Prueba con otro término de búsqueda.' : 'Crea tu primer ticket para recibir ayuda de nuestro equipo.'}</p>
                ${searching ? '' : '<button class="ag-btn ag-btn-primary" onclick="openCreateTicketModal()"><i class="fas fa-plus"></i> Nuevo ticket</button>'}
            </div>`;
        return;
    }

    container.innerHTML = tickets.map(ticket => {
        const nAtt = (ticket.attachments || []).length;
        return `
        <div class="tk-card p-${ticket.priority}" onclick="viewTicket(${ticket.id})" role="button" tabindex="0" onkeydown="if(event.key==='Enter')viewTicket(${ticket.id})">
            <div class="tk-head">
                <span class="tk-prio-ico p-${ticket.priority}" title="Prioridad ${TK_PRIORITY[ticket.priority] || ''}"><i class="fas ${TK_PRIO_ICON[ticket.priority] || 'fa-equals'}"></i></span>
                <span class="tk-num">#${escapeHtml(ticket.ticket_number)}</span>
                <span class="tk-badge st-${ticket.status}">${TK_STATUS[ticket.status] || ticket.status}</span>
            </div>
            <div class="tk-subject">${escapeHtml(ticket.subject)}</div>
            <div class="tk-desc">${escapeHtml(ticket.description)}</div>
            <div class="tk-meta">
                <span class="m"><i class="fas fa-tag"></i>${escapeHtml(ticket.category_name || '—')}</span>
                <span class="m"><span class="tk-badge pr-${ticket.priority}">${TK_PRIORITY[ticket.priority] || ticket.priority}</span></span>
                <span class="m"><i class="fas fa-clock"></i>${formatDate(ticket.created_at)}</span>
                ${ticket.assigned_to_name ? `<span class="m"><i class="fas fa-headset"></i>${escapeHtml(ticket.assigned_to_name)}</span>` : ''}
                ${nAtt ? `<span class="m"><i class="fas fa-paperclip"></i>${nAtt}</span>` : ''}
            </div>
            <span class="tk-go"><i class="fas fa-arrow-right"></i></span>
        </div>`;
    }).join('');
}

function openCreateTicketModal() {
    const m = document.getElementById('createTicketModal');
    m.classList.add('show');
    setTimeout(() => { const el = document.getElementById('ticketSubject'); if (el) el.focus(); }, 60);
}
function closeCreateTicketModal() {
    document.getElementById('createTicketModal').classList.remove('show');
    document.getElementById('createTicketForm').reset();
    setPriority('medium');
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
                closeCreateTicketModal();
                showToast('Ticket ' + data.ticket_number + ' creado. Te avisaremos por correo.', 'success');
                loadTickets();
                updateStats();
            });
        } else {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar ticket';
            showToast(data.error || 'No se pudo crear el ticket.', 'error');
        }
    })
    .catch(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar ticket';
        showToast('No se pudo crear el ticket. Intenta de nuevo.', 'error');
    });
}

function viewTicket(ticketId) {
    fetch(`../hr/helpdesk_api.php?action=get_ticket&ticket_id=${ticketId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayTicketDetails(data.ticket);
                document.getElementById('viewTicketModal').classList.add('show');
            } else {
                showToast(data.error || 'No se pudo abrir el ticket.', 'error');
            }
        }).catch(() => showToast('No se pudo abrir el ticket.', 'error'));
}

function closeViewTicketModal() { document.getElementById('viewTicketModal').classList.remove('show'); }

function displayTicketDetails(ticket) {
    currentTicket = ticket;
    const atts = ticket.attachments || [];
    const descAtts = atts.filter(a => !a.comment_id);
    const canReopen = (ticket.status === 'resolved' || ticket.status === 'closed');
    const canCancel = (ticket.status === 'open' || ticket.status === 'in_progress' || ticket.status === 'pending');
    const catColor = ticket.category_color || '#64748B';

    let html = `
        <div class="tk-num" style="font-size:12px;">#${escapeHtml(ticket.ticket_number)}</div>
        <h2 style="margin:6px 0 14px; font-size:20px; font-weight:800; color:var(--ag-text); letter-spacing:-.3px; line-height:1.3;">${escapeHtml(ticket.subject)}</h2>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px;">
            <span class="tk-badge st-${ticket.status}">${TK_STATUS[ticket.status] || ticket.status}</span>
            <span class="tk-badge pr-${ticket.priority}">${TK_PRIORITY[ticket.priority] || ticket.priority}</span>
            <span class="tk-badge" style="background:${catColor}1A; color:${catColor};"><span class="tk-dot" style="background:${catColor}"></span>${escapeHtml(ticket.category_name || '—')}</span>
        </div>
        <div class="tk-detail-box">
            <div class="di"><span class="ic"><i class="fas fa-calendar-day"></i></span><div><div class="k">Creado</div><div class="v">${formatDate(ticket.created_at)}</div></div></div>
            <div class="di"><span class="ic"><i class="fas fa-headset"></i></span><div><div class="k">Atiende</div><div class="v">${ticket.assigned_to_name ? escapeHtml(ticket.assigned_to_name) : 'Esperando asignación'}</div></div></div>
        </div>
        ${(canCancel || canReopen) ? `<div class="tk-actions">
            ${canReopen ? `<button class="ag-btn ag-btn-primary" onclick="changeMyTicket('open')"><i class="fas fa-rotate-left"></i> Reabrir ticket</button>` : ''}
            ${canCancel ? `<button class="ag-btn ag-btn-ghost" onclick="changeMyTicket('cancelled')"><i class="fas fa-ban"></i> Cancelar ticket</button>` : ''}
        </div>` : ''}
        <div style="margin-bottom:6px;">
            <p class="tk-sec-title">Descripción</p>
            <p class="tk-descblock">${escapeHtml(ticket.description)}</p>
            ${descAtts.length ? `<div class="tk-atts">${descAtts.map(attHtml).join('')}</div>` : ''}
        </div>`;

    html += `
        <div class="tk-thread">
            <p class="tk-sec-title">Actualizaciones y comentarios (${ticket.comments.length})</p>
            ${ticket.comments.length === 0 ? '<div class="tk-noco"><i class="fas fa-comments" style="margin-right:6px;"></i>Aún no hay comentarios. Nuestro equipo responderá pronto.</div>' : ''}
            ${ticket.comments.map(comment => {
                const cAtts = atts.filter(a => a.comment_id == comment.id);
                return `<div class="tk-msg">
                    <span class="av">${initials(comment.user_name)}</span>
                    <div class="bub">
                        <div class="ch"><span class="ca">${escapeHtml(comment.user_name)}</span><span class="cd">${formatDate(comment.created_at)}</span></div>
                        <div class="ct">${escapeHtml(comment.comment)}</div>
                        ${cAtts.length ? `<div class="tk-atts">${cAtts.map(attHtml).join('')}</div>` : ''}
                    </div>
                </div>`;
            }).join('')}
            <div style="margin-top:18px;">
                <p class="tk-sec-title">Agregar comentario</p>
                <form onsubmit="addComment(event)">
                    <div class="tk-field" style="margin-bottom:12px;">
                        <textarea id="newComment" placeholder="Escribe tu comentario o adjunta una captura…" style="min-height:96px;"></textarea>
                    </div>
                    <div class="tk-fileinput" style="margin-bottom:12px;">
                        <label class="btn" for="commentFiles"><i class="fas fa-paperclip"></i> Adjuntar</label>
                        <input type="file" id="commentFiles" multiple accept="image/*,application/pdf,.txt" style="display:none;" onchange="renderCommentFiles()">
                        <span id="commentFilesList" style="font-size:12px;color:var(--ag-muted);"></span>
                    </div>
                    <button type="submit" class="ag-btn ag-btn-primary"><i class="fas fa-paper-plane"></i> Enviar comentario</button>
                </form>
            </div>
        </div>`;

    if (ticket.status_history && ticket.status_history.length) {
        html += `<div class="tk-timeline">
            <p class="tk-sec-title">Historial</p>
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
        .then(d => {
            if (d.success) {
                showToast(status === 'cancelled' ? 'Ticket cancelado.' : 'Ticket reabierto.', 'success');
                viewTicket(currentTicket.id); loadTickets(); updateStats();
            } else { showToast(d.error || 'No se pudo actualizar.', 'error'); }
        }).catch(() => showToast('No se pudo actualizar.', 'error'));
}

function addComment(event) {
    event.preventDefault();
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const txt = document.getElementById('newComment').value.trim();
    const fi = document.getElementById('commentFiles');
    const hasFiles = fi && fi.files && fi.files.length;
    if (!txt && !hasFiles) { showToast('Escribe un comentario o adjunta un archivo.', 'error'); return; }
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando…';
    const done = () => { showToast('Comentario enviado.', 'success'); viewTicket(currentTicket.id); };

    if (txt) {
        const fd = new FormData();
        fd.append('action', 'add_comment');
        fd.append('ticket_id', currentTicket.id);
        fd.append('comment', txt);
        fetch('../hr/helpdesk_api.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(d => {
            if (!d.success) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar comentario'; showToast(d.error || 'No se pudo comentar.', 'error'); return; }
            uploadTicketFiles(currentTicket.id, fi, d.comment_id || null).then(done);
        }).catch(() => { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar comentario'; showToast('No se pudo comentar.', 'error'); });
    } else {
        uploadTicketFiles(currentTicket.id, fi, null).then(done);
    }
}

function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text ?? ''; return div.innerHTML; }
function initials(name) {
    const p = (name || '').trim().split(/\s+/);
    return ((p[0] || '')[0] || '').toUpperCase() + ((p[1] || '')[0] || '').toUpperCase();
}

// ---- Toasts ----
function showToast(msg, type) {
    type = type || 'info';
    let wrap = document.getElementById('tkToastWrap');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'tkToastWrap'; wrap.className = 'tk-toast-wrap'; document.body.appendChild(wrap); }
    const icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', info: 'fa-circle-info' };
    const el = document.createElement('div');
    el.className = 'tk-toast ' + type;
    el.setAttribute('role', 'status');
    el.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i><span>${escapeHtml(msg)}</span>`;
    wrap.appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 300); }, 3800);
}

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
        ? `<a class="tk-att" href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="${escapeHtml(a.file_name)}"></a>`
        : `<a class="tk-att" href="${url}" target="_blank" rel="noopener"><i class="fas fa-file-lines"></i> ${escapeHtml(a.file_name)}</a>`;
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString.replace(' ', 'T'));
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

// Cerrar modales: clic fuera + tecla ESC
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('tk-modal')) event.target.classList.remove('show');
});
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.getElementById('createTicketModal').classList.remove('show');
        document.getElementById('viewTicketModal').classList.remove('show');
    }
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
