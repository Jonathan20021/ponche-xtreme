<?php
/**
 * Consola de Soporte — panel del equipo técnico (IT/Desarrollador/Admin/HR).
 * Cola de tickets + detalle con hilo, respuestas (público/interno), asignación,
 * estado/prioridad/categoría, SLA, adjuntos, contexto del solicitante y macros.
 * Consume hr/helpdesk_api.php (backend unificado).
 */
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/helpdesk_support.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
if (!userHasPermission('helpdesk') || !isHelpdeskSupport($_SESSION['role'] ?? '')) {
    header('Location: ../unauthorized.php');
    exit;
}

$conn = getMysqli();
$cats = [];
$catRes = $conn->query("SELECT id, name, color FROM helpdesk_categories ORDER BY name");
while ($row = $catRes->fetch_assoc()) { $cats[] = $row; }

require_once __DIR__ . '/../header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
  --hd-bg:#F4F6FB; --hd-card:#FFFFFF; --hd-ink:#161F35; --hd-muted:#5B6B88; --hd-faint:#8A97AE;
  --hd-line:#E6EBF3; --hd-soft:#F7F9FC; --hd-brand:#2A4CCC; --hd-brand-tint:#EAF0FF;
  --hd-shadow:0 1px 2px rgba(20,30,60,.04),0 8px 24px rgba(20,30,60,.06);
  --hd-shadow-sm:0 1px 2px rgba(20,30,60,.06);
}
.theme-dark{ --hd-bg:#0F1626; --hd-card:#161F33; --hd-ink:#EAF0FB; --hd-muted:#9FB0CC; --hd-faint:#7C8AA6; --hd-line:#243149; --hd-soft:#1B2438; --hd-brand-tint:#20304F; }
.hdc *{box-sizing:border-box;}
.hdc{font-family:'Inter','Plus Jakarta Sans',system-ui,sans-serif; color:var(--hd-ink); padding:22px 26px; max-width:1500px; margin:0 auto;}
.hdc-head{display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px;}
.hdc-title{font-size:22px; font-weight:800; letter-spacing:-.3px; display:flex; align-items:center; gap:11px;}
.hdc-title i{color:var(--hd-brand);}
.hdc-metrics{display:flex; gap:10px; flex-wrap:wrap;}
.hdc-metric{background:var(--hd-card); border:1px solid var(--hd-line); border-radius:12px; padding:9px 14px; min-width:96px; box-shadow:var(--hd-shadow-sm);}
.hdc-metric .n{font-size:20px; font-weight:800; letter-spacing:-.5px; line-height:1; font-variant-numeric:tabular-nums;}
.hdc-metric .l{font-size:11px; color:var(--hd-muted); font-weight:600; margin-top:4px; text-transform:uppercase; letter-spacing:.4px;}
.hdc-metric.warn .n{color:#E0393B;} .hdc-metric.ok .n{color:#0E9F6E;} .hdc-metric.brand .n{color:var(--hd-brand);}

.hdc-body{display:grid; grid-template-columns:minmax(360px,1.1fr) 1.9fr; gap:18px; align-items:start;}
@media(max-width:1100px){ .hdc-body{grid-template-columns:1fr;} .hdc-detail{position:relative;} }

.hdc-queue{background:var(--hd-card); border:1px solid var(--hd-line); border-radius:16px; box-shadow:var(--hd-shadow); overflow:hidden;}
.hdc-tabs{display:flex; gap:2px; padding:10px 10px 0;}
.hdc-tab{flex:1; text-align:center; padding:9px 8px; font-size:12.5px; font-weight:700; color:var(--hd-muted); border:none; background:transparent; cursor:pointer; border-radius:9px 9px 0 0; border-bottom:2.5px solid transparent;}
.hdc-tab.active{color:var(--hd-brand); border-bottom-color:var(--hd-brand); background:var(--hd-soft);}
.hdc-tab .c{display:inline-block; min-width:18px; margin-left:5px; font-size:11px; background:var(--hd-line); color:var(--hd-muted); border-radius:9px; padding:1px 6px;}
.hdc-filters{display:flex; gap:8px; padding:12px; border-bottom:1px solid var(--hd-line); flex-wrap:wrap; align-items:center;}
.hdc-search{flex:1; min-width:150px; position:relative;}
.hdc-search i{position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--hd-faint); font-size:13px;}
.hdc-search input{width:100%; padding:9px 12px 9px 34px; border:1px solid var(--hd-line); border-radius:10px; font-size:13px; background:var(--hd-soft); color:var(--hd-ink); font-family:inherit;}
.hdc-sel{padding:9px 10px; border:1px solid var(--hd-line); border-radius:10px; font-size:12.5px; background:var(--hd-card); color:var(--hd-ink); font-family:inherit; cursor:pointer;}
.hdc-search input:focus,.hdc-sel:focus{outline:2px solid var(--hd-brand-tint); border-color:var(--hd-brand);}
.hdc-list{max-height:66vh; overflow-y:auto;}
.hdc-row{display:flex; gap:11px; padding:13px 14px; border-bottom:1px solid var(--hd-line); cursor:pointer; transition:background .12s;}
.hdc-row:hover{background:var(--hd-soft);}
.hdc-row.active{background:var(--hd-brand-tint);}
.hdc-pri{width:4px; border-radius:3px; flex-shrink:0;}
.hdc-pri.critical{background:#E0393B;} .hdc-pri.high{background:#F79009;} .hdc-pri.medium{background:#4A6CF7;} .hdc-pri.low{background:#98A6C0;}
.hdc-row .main{flex:1; min-width:0;}
.hdc-row .top{display:flex; align-items:center; gap:8px; margin-bottom:3px;}
.hdc-row .num{font-size:11px; font-weight:700; color:var(--hd-faint); font-variant-numeric:tabular-nums;}
.hdc-row .subj{font-size:13.5px; font-weight:700; color:var(--hd-ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
.hdc-row .meta{display:flex; align-items:center; gap:9px; font-size:11.5px; color:var(--hd-muted); flex-wrap:wrap;}
.hdc-row .who{display:flex; align-items:center; gap:6px;}
.hdc-avatar{width:22px; height:22px; border-radius:50%; background:var(--hd-brand); color:#fff; font-size:10px; font-weight:700; display:grid; place-items:center; flex-shrink:0;}
.hdc-badge{font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:999px; white-space:nowrap;}
.st-open{background:#E7F1FF; color:#1D5DD8;} .st-in_progress{background:#FEF0DA; color:#B25E09;} .st-pending{background:#EDEBFB; color:#5B48D0;}
.st-resolved{background:#E4F7EE; color:#0B7A4B;} .st-closed{background:#EDF1F7; color:#5B6B88;} .st-cancelled{background:#FBE9E9; color:#B42318;}
.hdc-pager{display:flex; align-items:center; justify-content:center; gap:14px; padding:12px; font-size:12.5px; color:var(--hd-muted);}
.hdc-pager button{border:1px solid var(--hd-line); background:var(--hd-card); border-radius:9px; width:32px; height:32px; cursor:pointer; color:var(--hd-ink);}
.hdc-pager button:disabled{opacity:.4; cursor:default;}

.hdc-detail{background:var(--hd-card); border:1px solid var(--hd-line); border-radius:16px; box-shadow:var(--hd-shadow); min-height:66vh; display:flex; flex-direction:column;}
.hdc-empty{flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; color:var(--hd-faint); text-align:center; padding:40px;}
.hdc-empty i{font-size:40px; opacity:.4;}
.hdc-dhead{padding:18px 20px; border-bottom:1px solid var(--hd-line);}
.hdc-dhead .r1{display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:6px;}
.hdc-dhead .num{font-size:12px; font-weight:700; color:var(--hd-faint);}
.hdc-dhead h2{font-size:18px; font-weight:800; margin:0; letter-spacing:-.3px;}
.hdc-controls{display:flex; gap:8px; flex-wrap:wrap; padding:14px 20px; border-bottom:1px solid var(--hd-line); background:var(--hd-soft); align-items:center;}
.hdc-ctl{display:flex; flex-direction:column; gap:3px;}
.hdc-ctl label{font-size:10px; font-weight:700; color:var(--hd-faint); text-transform:uppercase; letter-spacing:.3px; padding-left:2px;}
.hdc-sla{padding:10px 20px; font-size:12.5px; display:flex; gap:16px; flex-wrap:wrap; border-bottom:1px solid var(--hd-line);}
.hdc-sla .lbl{color:var(--hd-muted); font-weight:600;} .hdc-sla b{font-weight:800;}
.hdc-sla .ok{color:#0E9F6E;} .hdc-sla .warn{color:#E0393B;}
.hdc-dbody{display:grid; grid-template-columns:1.7fr .8fr; gap:0; flex:1; min-height:0;}
@media(max-width:820px){ .hdc-dbody{grid-template-columns:1fr;} }
.hdc-thread{padding:18px 20px; overflow-y:auto; max-height:52vh;}
.hdc-side{padding:18px; border-left:1px solid var(--hd-line); background:var(--hd-soft); overflow-y:auto;}
.hdc-msg{margin-bottom:16px;}
.hdc-msg .mh{display:flex; align-items:center; gap:9px; margin-bottom:6px;}
.hdc-msg .nm{font-weight:700; font-size:13px;}
.hdc-msg .tm{font-size:11px; color:var(--hd-faint); margin-left:auto;}
.hdc-msg .bubble{background:var(--hd-soft); border:1px solid var(--hd-line); border-radius:12px; padding:12px 14px; font-size:13.5px; line-height:1.55; white-space:pre-wrap; word-break:break-word;}
.hdc-msg.first .bubble{background:var(--hd-brand-tint); border-color:#D3DEFB;}
.hdc-msg.internal .bubble{background:#FFF7E6; border-color:#F5E2B8;}
.hdc-msg.internal .nm::after{content:'· nota interna'; color:#B25E09; font-weight:700; font-size:10.5px; margin-left:6px;}
.hdc-atts{display:flex; gap:8px; flex-wrap:wrap; margin-top:9px;}
.hdc-att{display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:600; color:var(--hd-brand); background:var(--hd-card); border:1px solid var(--hd-line); border-radius:9px; padding:5px 10px; text-decoration:none;}
.hdc-att img{max-width:150px; max-height:110px; border-radius:8px; border:1px solid var(--hd-line); display:block;}
.hdc-reply{border-top:1px solid var(--hd-line); padding:14px 20px; background:var(--hd-card);}
.hdc-reply textarea{width:100%; min-height:74px; border:1px solid var(--hd-line); border-radius:11px; padding:11px 13px; font-size:13.5px; font-family:inherit; color:var(--hd-ink); resize:vertical; background:var(--hd-soft);}
.hdc-reply-bar{display:flex; align-items:center; gap:10px; margin-top:10px; flex-wrap:wrap;}
.hdc-toggle{display:flex; align-items:center; gap:7px; font-size:12.5px; font-weight:600; color:var(--hd-muted); cursor:pointer;}
.hdc-btn{border:none; border-radius:10px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:7px; font-family:inherit;}
.hdc-btn.primary{background:var(--hd-brand); color:#fff;} .hdc-btn.primary:hover{background:#2340B8;}
.hdc-btn.ghost{background:var(--hd-soft); color:var(--hd-ink); border:1px solid var(--hd-line);}
.hdc-iconbtn{border:1px solid var(--hd-line); background:var(--hd-soft); color:var(--hd-muted); width:36px; height:36px; border-radius:10px; cursor:pointer;}
.hdc-side h4{font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:var(--hd-faint); margin:0 0 10px;}
.hdc-side .rq{display:flex; align-items:center; gap:10px; margin-bottom:14px;}
.hdc-side .rq .info{min-width:0;} .hdc-side .rq .nm{font-weight:700; font-size:13.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
.hdc-side .rq .rl{font-size:11.5px; color:var(--hd-muted);}
.hdc-otk{display:block; padding:9px 11px; border:1px solid var(--hd-line); border-radius:10px; margin-bottom:7px; text-decoration:none; color:var(--hd-ink); background:var(--hd-card);}
.hdc-otk:hover{border-color:var(--hd-brand);}
.hdc-otk .s{font-size:12.5px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
.hdc-otk .m{font-size:11px; color:var(--hd-faint); margin-top:3px; display:flex; gap:8px;}
.hdc-chipfile{display:inline-flex; align-items:center; gap:6px; font-size:11.5px; background:var(--hd-brand-tint); color:var(--hd-brand); border-radius:8px; padding:4px 9px; margin-top:8px;}
.hdc-cannedmenu{position:absolute; background:var(--hd-card); border:1px solid var(--hd-line); border-radius:12px; box-shadow:var(--hd-shadow); width:300px; max-height:280px; overflow-y:auto; z-index:50; display:none;}
.hdc-cannedmenu a{display:block; padding:10px 13px; border-bottom:1px solid var(--hd-line); text-decoration:none; color:var(--hd-ink); font-size:12.5px;}
.hdc-cannedmenu a:hover{background:var(--hd-soft);} .hdc-cannedmenu a b{display:block; font-weight:700; margin-bottom:2px;}
.hdc-cannedmenu .empty{padding:14px; color:var(--hd-faint); font-size:12px;}
.hdc-skel{padding:14px;} .hdc-skel .l{height:14px; background:linear-gradient(90deg,var(--hd-line),var(--hd-soft),var(--hd-line)); background-size:200% 100%; animation:hdsk 1.2s infinite; border-radius:6px; margin-bottom:10px;}
@keyframes hdsk{0%{background-position:200% 0}100%{background-position:-200% 0}}
</style>

<div class="hdc" id="hdc">
  <div class="hdc-head">
    <div class="hdc-title"><i class="fas fa-headset"></i> Consola de Soporte</div>
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <div class="hdc-metrics" id="hdcMetrics"></div>
      <a href="remote_access.php" class="hdc-btn ghost" style="text-decoration:none;"><i class="fas fa-key"></i> Accesos remotos</a>
    </div>
  </div>

  <div class="hdc-body">
    <!-- COLA -->
    <div class="hdc-queue">
      <div class="hdc-tabs" id="hdcTabs">
        <button class="hdc-tab active" data-view="">Todos</button>
        <button class="hdc-tab" data-view="unassigned">Sin asignar <span class="c" id="cnt-unassigned">0</span></button>
        <button class="hdc-tab" data-view="mine">Míos <span class="c" id="cnt-mine">0</span></button>
        <button class="hdc-tab" data-view="unresolved">Sin resolver</button>
      </div>
      <div class="hdc-filters">
        <div class="hdc-search"><i class="fas fa-magnifying-glass"></i><input type="text" id="hdcSearch" placeholder="Buscar # / asunto / persona…" autocomplete="off"></div>
        <select class="hdc-sel" id="fStatus"><option value="">Estado</option><option value="open">Abierto</option><option value="in_progress">En progreso</option><option value="pending">Pendiente</option><option value="resolved">Resuelto</option><option value="closed">Cerrado</option></select>
        <select class="hdc-sel" id="fPriority"><option value="">Prioridad</option><option value="critical">Crítica</option><option value="high">Alta</option><option value="medium">Media</option><option value="low">Baja</option></select>
        <select class="hdc-sel" id="fCategory"><option value="">Categoría</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select>
      </div>
      <div class="hdc-list" id="hdcList"><div class="hdc-skel"><div class="l" style="width:70%"></div><div class="l" style="width:90%"></div><div class="l" style="width:60%"></div></div></div>
      <div class="hdc-pager" id="hdcPager" style="display:none;">
        <button data-p="prev"><i class="fas fa-chevron-left"></i></button>
        <span>Página <b id="pgNow">1</b> de <span id="pgTot">1</span> · <span id="pgTotal">0</span> tickets</span>
        <button data-p="next"><i class="fas fa-chevron-right"></i></button>
      </div>
    </div>

    <!-- DETALLE -->
    <div class="hdc-detail" id="hdcDetail">
      <div class="hdc-empty"><i class="fas fa-ticket"></i><p>Selecciona un ticket de la cola para verlo y responder.</p></div>
    </div>
  </div>
</div>

<script>
(function(){
  const API = '../hr/helpdesk_api.php';
  const ME = <?= (int)$_SESSION['user_id'] ?>;
  const CATS = <?= json_encode($cats, JSON_UNESCAPED_UNICODE) ?>;
  let AGENTS = [], CANNED = [];
  let state = { view:'', search:'', status:'', priority:'', category:'', page:1, total:0, limit:25, current:null, pendingFiles:[] };

  const $ = s => document.querySelector(s);
  const esc = s => String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const initials = n => { const p=String(n||'?').trim().split(/\s+/); return ((p[0]?.[0]||'')+(p[1]?.[0]||'')).toUpperCase()||'?'; };
  const ST = {open:'Abierto',in_progress:'En progreso',pending:'Pendiente',resolved:'Resuelto',closed:'Cerrado',cancelled:'Cancelado'};
  const PR = {low:'Baja',medium:'Media',high:'Alta',critical:'Crítica'};
  function ago(d){ if(!d) return ''; const t=new Date(d.replace(' ','T')), s=(Date.now()-t)/1000; if(s<60)return'ahora'; if(s<3600)return Math.floor(s/60)+' min'; if(s<86400)return Math.floor(s/3600)+' h'; if(s<604800)return Math.floor(s/86400)+' d'; return t.toLocaleDateString('es-DO',{day:'2-digit',month:'short'}); }
  function fdt(d){ if(!d) return '—'; return new Date(d.replace(' ','T')).toLocaleString('es-DO',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}); }

  async function api(action, params={}, method='GET'){
    let url = API+'?action='+action, opt={method};
    if(method==='GET'){ url += '&'+new URLSearchParams(params); }
    else { const fd = params instanceof FormData ? params : new URLSearchParams(params); opt.body = fd; }
    const r = await fetch(url, opt); const txt = await r.text();
    try { return JSON.parse(txt); } catch(e){ return {success:false, error:'Respuesta inválida'}; }
  }

  async function loadStats(){
    const d = await api('get_statistics'); if(!d.success) return; const s=d.statistics||{};
    const breaches=(parseInt(s.response_breaches||0)+parseInt(s.resolution_breaches||0));
    $('#hdcMetrics').innerHTML = [
      ['brand', s.open_tickets||0, 'Abiertos'],
      ['', s.in_progress_tickets||0, 'En progreso'],
      [breaches>0?'warn':'', breaches, 'SLA vencido'],
      ['ok', s.resolved_tickets||0, 'Resueltos'],
      ['', s.total_tickets||0, 'Total'],
    ].map(m=>`<div class="hdc-metric ${m[0]}"><div class="n">${m[1]}</div><div class="l">${m[2]}</div></div>`).join('');
  }

  async function loadCounts(){
    const u = await api('get_tickets',{view:'unassigned',limit:1}); if(u.success) $('#cnt-unassigned').textContent=u.total;
    const m = await api('get_tickets',{view:'mine',limit:1}); if(m.success) $('#cnt-mine').textContent=m.total;
  }

  async function loadTickets(){
    $('#hdcList').innerHTML='<div class="hdc-skel"><div class="l" style="width:70%"></div><div class="l" style="width:90%"></div><div class="l" style="width:60%"></div></div>';
    const d = await api('get_tickets',{view:state.view,search:state.search,status:state.status,priority:state.priority,category_id:state.category,page:state.page,limit:state.limit});
    if(!d.success){ $('#hdcList').innerHTML='<div class="hdc-empty" style="min-height:200px"><i class="fas fa-triangle-exclamation"></i><p>No se pudieron cargar los tickets.</p></div>'; return; }
    state.total=d.total||0;
    if(!d.tickets.length){ $('#hdcList').innerHTML='<div class="hdc-empty" style="min-height:200px"><i class="fas fa-inbox"></i><p>Sin tickets en esta vista.</p></div>'; }
    else{
      $('#hdcList').innerHTML = d.tickets.map(t=>`
        <div class="hdc-row ${state.current&&state.current==t.id?'active':''}" data-id="${t.id}">
          <div class="hdc-pri ${t.priority}"></div>
          <div class="main">
            <div class="top"><span class="num">#${esc(t.ticket_number)}</span><span class="subj">${esc(t.subject)}</span></div>
            <div class="meta">
              <span class="who"><span class="hdc-avatar" style="width:18px;height:18px;font-size:9px">${initials(t.user_name)}</span>${esc(t.user_name||'—')}</span>
              <span class="hdc-badge st-${t.status}">${ST[t.status]||t.status}</span>
              ${t.category_name?`<span style="color:${esc(t.category_color||'#888')};font-weight:700">●</span> ${esc(t.category_name)}`:''}
              <span style="margin-left:auto">${ago(t.created_at)}</span>
              ${t.assigned_to_name?`<span class="hdc-avatar" title="Asignado a ${esc(t.assigned_to_name)}">${initials(t.assigned_to_name)}</span>`:'<i class="fas fa-user-slash" title="Sin asignar" style="color:var(--hd-faint)"></i>'}
            </div>
          </div>
        </div>`).join('');
    }
    const pages=Math.max(1,Math.ceil(state.total/state.limit));
    $('#hdcPager').style.display = state.total>state.limit ? 'flex':'none';
    $('#pgNow').textContent=state.page; $('#pgTot').textContent=pages; $('#pgTotal').textContent=state.total;
    $('#hdcPager').querySelector('[data-p="prev"]').disabled = state.page<=1;
    $('#hdcPager').querySelector('[data-p="next"]').disabled = state.page>=pages;
  }

  async function openTicket(id){
    state.current=id; state.pendingFiles=[];
    document.querySelectorAll('.hdc-row').forEach(r=>r.classList.toggle('active', r.dataset.id==id));
    $('#hdcDetail').innerHTML='<div class="hdc-skel" style="padding:24px"><div class="l" style="width:40%"></div><div class="l" style="width:80%"></div><div class="l"></div><div class="l" style="width:70%"></div></div>';
    const d = await api('get_ticket',{ticket_id:id}); if(!d.success){ $('#hdcDetail').innerHTML='<div class="hdc-empty"><i class="fas fa-triangle-exclamation"></i><p>No se pudo cargar el ticket.</p></div>'; return; }
    renderDetail(d.ticket);
    loadRequesterContext(id);
  }

  function slaLine(t){
    function part(label, deadline, doneAt, breached){
      if(doneAt) return `<span><span class="lbl">${label}:</span> <b class="ok">cumplido</b></span>`;
      if(!deadline) return '';
      const ms=new Date(deadline.replace(' ','T'))-Date.now(); const h=Math.round(Math.abs(ms)/3600000*10)/10;
      const late = ms<0 || breached==1;
      return `<span><span class="lbl">${label}:</span> <b class="${late?'warn':'ok'}">${late?('vencido hace '+h+'h'):('vence en '+h+'h')}</b></span>`;
    }
    const a=part('1ra respuesta', t.sla_response_deadline, t.first_response_at, t.sla_response_breached);
    const b=part('Resolución', t.sla_resolution_deadline, t.resolved_at, t.sla_resolution_breached);
    return (a||b) ? `<div class="hdc-sla">${a}${b}</div>` : '';
  }

  function renderDetail(t){
    const msgs = [{nm:t.user_name,tm:t.created_at,body:t.description,first:true,internal:false,atts:(t.attachments||[]).filter(a=>!a.comment_id)}]
      .concat((t.comments||[]).map(c=>({nm:c.user_name,tm:c.created_at,body:c.comment,first:false,internal:c.is_internal==1,atts:(t.attachments||[]).filter(a=>a.comment_id==c.id)})));
    const optSel=(arr,val,fmt)=>arr.map(o=>`<option value="${o[0]}" ${o[0]==val?'selected':''}>${fmt?fmt(o[1]):o[1]}</option>`).join('');
    const statuses=[['open','Abierto'],['in_progress','En progreso'],['pending','Pendiente'],['resolved','Resuelto'],['closed','Cerrado'],['cancelled','Cancelado']];
    const prios=[['low','Baja'],['medium','Media'],['high','Alta'],['critical','Crítica']];
    const agentOpts='<option value="">Sin asignar</option>'+AGENTS.map(a=>`<option value="${a.id}" ${a.id==t.assigned_to?'selected':''}>${esc(a.full_name)}</option>`).join('');
    const catOpts=CATS.map(c=>`<option value="${c.id}" ${c.id==t.category_id?'selected':''}>${esc(c.name)}</option>`).join('');
    $('#hdcDetail').innerHTML=`
      <div class="hdc-dhead">
        <div class="r1"><span class="num">#${esc(t.ticket_number)}</span><span class="hdc-badge st-${t.status}">${ST[t.status]||t.status}</span>
          <span class="hdc-badge" style="background:var(--hd-soft);color:var(--hd-muted)">${PR[t.priority]||t.priority}</span></div>
        <h2>${esc(t.subject)}</h2>
      </div>
      <div class="hdc-controls">
        <div class="hdc-ctl"><label>Estado</label><select class="hdc-sel" id="cStatus">${optSel(statuses,t.status)}</select></div>
        <div class="hdc-ctl"><label>Prioridad</label><select class="hdc-sel" id="cPriority">${optSel(prios,t.priority)}</select></div>
        <div class="hdc-ctl"><label>Asignado</label><select class="hdc-sel" id="cAssign">${agentOpts}</select></div>
        <div class="hdc-ctl"><label>Categoría</label><select class="hdc-sel" id="cCategory">${catOpts}</select></div>
      </div>
      ${slaLine(t)}
      <div class="hdc-dbody">
        <div class="hdc-thread" id="hdcThread">
          ${msgs.map(m=>`
            <div class="hdc-msg ${m.first?'first':''} ${m.internal?'internal':''}">
              <div class="mh"><span class="hdc-avatar">${initials(m.nm)}</span><span class="nm">${esc(m.nm)}</span><span class="tm">${fdt(m.tm)}</span></div>
              <div class="bubble">${esc(m.body)}${m.atts.length?`<div class="hdc-atts">${m.atts.map(attHtml).join('')}</div>`:''}</div>
            </div>`).join('')}
        </div>
        <div class="hdc-side">
          <h4>Solicitante</h4>
          <div class="rq"><span class="hdc-avatar" style="width:34px;height:34px;font-size:13px">${initials(t.user_name)}</span><div class="info"><div class="nm">${esc(t.user_name)}</div><div class="rl">${esc(t.user_email||'')}</div></div></div>
          <h4>Otros tickets</h4>
          <div id="hdcOther"><div style="font-size:12px;color:var(--hd-faint)">Cargando…</div></div>
          <h4 style="margin-top:16px;"><i class="fas fa-key" style="color:var(--hd-brand)"></i> Acceso remoto</h4>
          <div id="hdcRemote"><div style="font-size:12px;color:var(--hd-faint)">Cargando…</div></div>
        </div>
      </div>
      <div class="hdc-reply" style="position:relative">
        <textarea id="hdcReply" placeholder="Escribe una respuesta al solicitante…"></textarea>
        <div id="hdcFiles"></div>
        <div class="hdc-reply-bar">
          <button class="hdc-btn primary" id="btnReply"><i class="fas fa-paper-plane"></i> Responder</button>
          <label class="hdc-toggle"><input type="checkbox" id="chkInternal"> <i class="fas fa-lock" style="color:#B25E09"></i> Nota interna</label>
          <input type="file" id="hdcFileInput" accept="image/*,application/pdf,.txt" style="display:none">
          <button class="hdc-iconbtn" id="btnAttach" title="Adjuntar"><i class="fas fa-paperclip"></i></button>
          <button class="hdc-iconbtn" id="btnCanned" title="Respuestas guardadas"><i class="fas fa-bolt"></i></button>
          <div class="hdc-cannedmenu" id="cannedMenu"></div>
        </div>
      </div>`;
    // wire controls
    $('#cStatus').onchange = e=>saveField('update_status',{ticket_id:t.id,status:e.target.value},'Estado actualizado');
    $('#cPriority').onchange = e=>saveField('update_priority',{ticket_id:t.id,priority:e.target.value},'Prioridad actualizada');
    $('#cAssign').onchange = e=>saveField('assign_ticket',{ticket_id:t.id,assigned_to:e.target.value},'Asignación actualizada');
    $('#cCategory').onchange = e=>saveField('update_ticket_category',{ticket_id:t.id,category_id:e.target.value},'Categoría actualizada');
    $('#btnReply').onclick = ()=>sendReply(t.id);
    $('#btnAttach').onclick = ()=>$('#hdcFileInput').click();
    $('#hdcFileInput').onchange = e=>{ for(const f of e.target.files) state.pendingFiles.push(f); renderFiles(); e.target.value=''; };
    $('#btnCanned').onclick = toggleCanned;
    loadTicketRemote(t.user_id);
  }
  async function loadTicketRemote(userId){
    const box=$('#hdcRemote'); if(!box) return;
    if(!userId){ box.innerHTML='<div style="font-size:12px;color:var(--hd-faint)">—</div>'; return; }
    const d=await api('get_remote_for_user',{user_id:userId});
    if(!d.success||!d.items.length){ box.innerHTML='<div style="font-size:12px;color:var(--hd-faint)">Sin credenciales. <a href="remote_access.php" style="color:var(--hd-brand)">Agregar</a></div>'; return; }
    box.innerHTML=d.items.map(r=>`<div class="hdc-otk"><div class="s">${esc(r.label)} · ${esc(r.tool)}</div><div class="m">${r.remote_id?('ID '+esc(r.remote_id)):''} ${r.has_password==1?`<a href="#" data-rev="${r.id}" style="color:var(--hd-brand)">ver contraseña</a>`:''}</div><div id="rev-${r.id}"></div></div>`).join('');
    box.querySelectorAll('[data-rev]').forEach(a=>a.onclick=async e=>{ e.preventDefault(); const id=a.dataset.rev; const dd=await api('reveal_remote',{id},'POST'); if(dd.success){ document.getElementById('rev-'+id).innerHTML=`<code style="display:inline-block;margin-top:5px;background:var(--hd-soft);border:1px solid var(--hd-line);border-radius:6px;padding:3px 8px;font-size:12px">${esc(dd.password||'(vacía)')}</code>`; } });
  }
  function attHtml(a){
    const url=`../hr/helpdesk_attachment.php?id=${a.id}`;
    return a.is_image==1 ? `<a class="hdc-att" href="${url}" target="_blank"><img src="${url}" alt="${esc(a.file_name)}"></a>`
      : `<a class="hdc-att" href="${url}" target="_blank"><i class="fas fa-paperclip"></i> ${esc(a.file_name)}</a>`;
  }
  function renderFiles(){
    $('#hdcFiles').innerHTML = state.pendingFiles.map((f,i)=>`<span class="hdc-chipfile"><i class="fas fa-paperclip"></i> ${esc(f.name)} <a href="#" data-rm="${i}" style="color:inherit">✕</a></span>`).join('');
    document.querySelectorAll('#hdcFiles [data-rm]').forEach(a=>a.onclick=e=>{e.preventDefault(); state.pendingFiles.splice(+a.dataset.rm,1); renderFiles();});
  }
  async function loadRequesterContext(id){
    const d = await api('get_requester_tickets',{ticket_id:id});
    const box=$('#hdcOther'); if(!box) return;
    if(!d.success||!d.tickets.length){ box.innerHTML='<div style="font-size:12px;color:var(--hd-faint)">Sin otros tickets.</div>'; return; }
    box.innerHTML=d.tickets.map(t=>`<a class="hdc-otk" data-id="${t.id}"><div class="s">#${esc(t.ticket_number)} · ${esc(t.subject)}</div><div class="m"><span class="hdc-badge st-${t.status}">${ST[t.status]||t.status}</span><span>${ago(t.created_at)}</span></div></a>`).join('');
    box.querySelectorAll('[data-id]').forEach(a=>a.onclick=()=>openTicket(a.dataset.id));
  }
  async function saveField(action, params, okMsg){
    const d = await api(action, params, 'POST');
    if(d.success){ toast(okMsg); openTicket(state.current); loadStats(); loadCounts(); loadTickets(); }
    else toast(d.error||'Error', true);
  }
  async function sendReply(id){
    const ta=$('#hdcReply'), txt=ta.value.trim(); const internal=$('#chkInternal').checked?1:0;
    if(!txt && !state.pendingFiles.length){ toast('Escribe una respuesta o adjunta un archivo', true); return; }
    $('#btnReply').disabled=true;
    let commentId=null;
    if(txt){ const d=await api('add_comment',{ticket_id:id,comment:txt,is_internal:internal},'POST'); if(!d.success){ toast(d.error||'Error al responder', true); $('#btnReply').disabled=false; return; } commentId=d.comment_id||null; }
    for(const f of state.pendingFiles){ const fd=new FormData(); fd.append('ticket_id',id); if(commentId) fd.append('comment_id',commentId); fd.append('file',f); await api('upload_attachment',fd,'POST'); }
    ta.value=''; state.pendingFiles=[]; $('#btnReply').disabled=false;
    toast('Respuesta enviada'); openTicket(id); loadTickets();
  }
  function toggleCanned(e){
    e.stopPropagation(); const m=$('#cannedMenu'); if(m.style.display==='block'){ m.style.display='none'; return; }
    m.innerHTML = CANNED.length ? CANNED.map(c=>`<a data-id="${c.id}"><b>${esc(c.title)}</b>${esc(c.body).slice(0,80)}…</a>`).join('') : '<div class="empty">Sin respuestas guardadas. Créalas en Categorías/Ajustes.</div>';
    m.style.right='20px'; m.style.bottom='64px'; m.style.display='block';
    m.querySelectorAll('[data-id]').forEach(a=>a.onclick=()=>{ const c=CANNED.find(x=>x.id==a.dataset.id); $('#hdcReply').value += (($('#hdcReply').value?'\n\n':'')+c.body); m.style.display='none'; $('#hdcReply').focus(); });
  }
  document.addEventListener('click',()=>{ const m=$('#cannedMenu'); if(m) m.style.display='none'; });

  let toastT;
  function toast(msg, err){ let el=$('#hdcToast'); if(!el){ el=document.createElement('div'); el.id='hdcToast'; el.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:9999;padding:12px 20px;border-radius:12px;font-weight:700;font-size:13.5px;box-shadow:0 8px 30px rgba(0,0,0,.2);color:#fff'; document.body.appendChild(el);} el.style.background=err?'#E0393B':'#161F35'; el.textContent=msg; el.style.opacity='1'; clearTimeout(toastT); toastT=setTimeout(()=>el.style.opacity='0',2200); }

  // Events
  $('#hdcTabs').addEventListener('click',e=>{ const b=e.target.closest('.hdc-tab'); if(!b) return; document.querySelectorAll('.hdc-tab').forEach(t=>t.classList.remove('active')); b.classList.add('active'); state.view=b.dataset.view; state.page=1; loadTickets(); });
  let searchT; $('#hdcSearch').addEventListener('input',e=>{ clearTimeout(searchT); searchT=setTimeout(()=>{ state.search=e.target.value; state.page=1; loadTickets(); },350); });
  ['fStatus','fPriority','fCategory'].forEach(id=>$('#'+id).addEventListener('change',e=>{ state[id==='fStatus'?'status':id==='fPriority'?'priority':'category']=e.target.value; state.page=1; loadTickets(); }));
  $('#hdcList').addEventListener('click',e=>{ const r=e.target.closest('.hdc-row'); if(r) openTicket(r.dataset.id); });
  $('#hdcPager').addEventListener('click',e=>{ const b=e.target.closest('button'); if(!b) return; if(b.dataset.p==='next') state.page++; else state.page=Math.max(1,state.page-1); loadTickets(); });

  // Init
  (async function(){
    const a=await api('get_agents'); if(a.success) AGENTS=a.agents;
    const c=await api('get_canned'); if(c.success) CANNED=c.canned;
    loadStats(); loadCounts(); loadTickets();
    setInterval(()=>{ loadStats(); loadCounts(); if(!state.current) loadTickets(); }, 45000);
  })();
})();
</script>

</main>
</body>
</html>
