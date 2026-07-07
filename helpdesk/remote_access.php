<?php
/**
 * Bóveda de Accesos Remotos — credenciales de AnyDesk/RustDesk/etc. de los
 * agentes (muchos trabajan desde casa). Solo el equipo de soporte. Las
 * contraseñas se guardan CIFRADAS y se revelan con auditoría.
 */
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/helpdesk_support.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../index.php'); exit; }
if (!userHasPermission('helpdesk') || !isHelpdeskSupport($_SESSION['role'] ?? '')) {
    header('Location: ../unauthorized.php'); exit;
}
$conn = getMysqli();
ensureHelpdeskSupportTables($conn);

// Usuarios para asignar una credencial (agentes principalmente).
$users = [];
$ures = $conn->query("SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name");
while ($row = $ures->fetch_assoc()) { $users[] = $row; }

require_once __DIR__ . '/../header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{ --rv-bg:#F4F6FB; --rv-card:#FFFFFF; --rv-ink:#161F35; --rv-muted:#5B6B88; --rv-faint:#8A97AE; --rv-line:#E6EBF3; --rv-soft:#F7F9FC; --rv-brand:#2A4CCC; --rv-brand-tint:#EAF0FF; --rv-shadow:0 1px 2px rgba(20,30,60,.04),0 8px 24px rgba(20,30,60,.06);}
.theme-dark{ --rv-bg:#0F1626; --rv-card:#161F33; --rv-ink:#EAF0FB; --rv-muted:#9FB0CC; --rv-faint:#7C8AA6; --rv-line:#243149; --rv-soft:#1B2438; --rv-brand-tint:#20304F;}
.rv *{box-sizing:border-box;}
.rv{font-family:'Inter','Plus Jakarta Sans',system-ui,sans-serif; color:var(--rv-ink); padding:22px 26px; max-width:1200px; margin:0 auto;}
.rv-head{display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:8px;}
.rv-title{font-size:22px; font-weight:800; letter-spacing:-.3px; display:flex; align-items:center; gap:11px;} .rv-title i{color:var(--rv-brand);}
.rv-sub{color:var(--rv-muted); font-size:13.5px; margin:0 0 18px;}
.rv-note{display:flex; gap:10px; align-items:flex-start; background:#FFF7E6; border:1px solid #F5E2B8; color:#7A5B12; border-radius:12px; padding:11px 14px; font-size:12.5px; margin-bottom:18px;}
.theme-dark .rv-note{background:#2A2415; border-color:#4A3E1E; color:#E4C878;}
.rv-toolbar{display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;}
.rv-search{flex:1; min-width:180px; position:relative;}
.rv-search i{position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--rv-faint); font-size:13px;}
.rv-search input{width:100%; padding:11px 14px 11px 36px; border:1px solid var(--rv-line); border-radius:11px; font-size:13.5px; background:var(--rv-card); color:var(--rv-ink); font-family:inherit;}
.rv-btn{border:none; border-radius:11px; padding:11px 18px; font-size:13.5px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px; font-family:inherit;}
.rv-btn.primary{background:var(--rv-brand); color:#fff;} .rv-btn.primary:hover{background:#2340B8;}
.rv-btn.ghost{background:var(--rv-soft); color:var(--rv-ink); border:1px solid var(--rv-line);}
.rv-grid{display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:14px;}
.rv-cardx{background:var(--rv-card); border:1px solid var(--rv-line); border-radius:15px; padding:17px 18px; box-shadow:var(--rv-shadow);}
.rv-cardx .rh{display:flex; align-items:center; gap:11px; margin-bottom:12px;}
.rv-tool{width:38px; height:38px; border-radius:11px; display:grid; place-items:center; color:#fff; font-size:16px; flex-shrink:0;}
.rv-tool.anydesk{background:#EF443B;} .rv-tool.rustdesk{background:#0071FF;} .rv-tool.teamviewer{background:#0E5FB5;} .rv-tool.other{background:#6C7A94;}
.rv-cardx .who{min-width:0;} .rv-cardx .lb{font-weight:800; font-size:14.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
.rv-cardx .ag{font-size:12px; color:var(--rv-muted);}
.rv-kv{display:flex; align-items:center; gap:8px; font-size:12.5px; margin-bottom:8px; color:var(--rv-muted);}
.rv-kv .k{width:74px; color:var(--rv-faint); font-weight:600;} .rv-kv .v{font-weight:700; color:var(--rv-ink); font-variant-numeric:tabular-nums; word-break:break-all;}
.rv-copy{cursor:pointer; color:var(--rv-brand); font-size:12px;}
.rv-pass{display:flex; align-items:center; gap:8px; margin-top:6px; padding-top:10px; border-top:1px solid var(--rv-line);}
.rv-pass code{flex:1; font-size:13px; background:var(--rv-soft); border:1px solid var(--rv-line); border-radius:8px; padding:7px 10px; letter-spacing:1px; color:var(--rv-ink); word-break:break-all;}
.rv-cardx .acts{display:flex; gap:6px; margin-top:12px; padding-top:12px; border-top:1px solid var(--rv-line);}
.rv-mini{border:1px solid var(--rv-line); background:var(--rv-soft); color:var(--rv-muted); border-radius:9px; padding:7px 11px; font-size:12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;}
.rv-mini:hover{color:var(--rv-ink);} .rv-mini.rev{color:var(--rv-brand); background:var(--rv-brand-tint); border-color:#D3DEFB;} .rv-mini.del:hover{color:#E0393B; border-color:#F3C6C6;}
.rv-empty{text-align:center; padding:56px 20px; color:var(--rv-faint);} .rv-empty i{font-size:40px; opacity:.4; display:block; margin-bottom:12px;}
/* modal */
.rv-modal{display:none; position:fixed; inset:0; z-index:1000; background:rgba(15,23,42,.5); backdrop-filter:blur(4px);}
.rv-modal .box{background:var(--rv-card); max-width:520px; margin:44px auto; border-radius:18px; padding:26px; box-shadow:0 24px 60px rgba(15,23,42,.3); max-height:88vh; overflow-y:auto;}
.rv-modal h2{margin:0 0 18px; font-size:18px; font-weight:800; display:flex; align-items:center; gap:9px;}
.rv-field{margin-bottom:14px;} .rv-field label{display:block; font-size:12px; font-weight:700; color:var(--rv-faint); text-transform:uppercase; letter-spacing:.3px; margin-bottom:5px;}
.rv-field input,.rv-field select,.rv-field textarea{width:100%; padding:11px 13px; border:1px solid var(--rv-line); border-radius:11px; font-size:13.5px; font-family:inherit; color:var(--rv-ink); background:var(--rv-soft);}
.rv-field textarea{min-height:70px; resize:vertical;}
.rv-row{display:grid; grid-template-columns:1fr 1fr; gap:12px;}
</style>

<div class="rv" id="rv">
  <div class="rv-head">
    <div>
      <div class="rv-title"><i class="fas fa-key"></i> Accesos Remotos</div>
    </div>
    <div style="display:flex; gap:10px;">
      <a class="rv-btn ghost" href="console.php"><i class="fas fa-headset"></i> Consola</a>
      <button class="rv-btn primary" onclick="openForm()"><i class="fas fa-plus"></i> Nueva credencial</button>
    </div>
  </div>
  <p class="rv-sub">Credenciales de conexión remota (AnyDesk, RustDesk, TeamViewer…) de los agentes que trabajan desde casa.</p>
  <div class="rv-note"><i class="fas fa-shield-halved" style="margin-top:1px;"></i><div>Las contraseñas se guardan <b>cifradas</b> y solo el equipo de soporte puede verlas. Cada vez que revelas una contraseña queda registrado quién la vio.</div></div>

  <div class="rv-toolbar">
    <div class="rv-search"><i class="fas fa-magnifying-glass"></i><input type="text" id="rvSearch" placeholder="Buscar por agente, etiqueta o ID…" autocomplete="off"></div>
  </div>
  <div class="rv-grid" id="rvGrid"><div class="rv-empty"><i class="fas fa-spinner fa-spin"></i>Cargando…</div></div>
</div>

<!-- Modal -->
<div class="rv-modal" id="rvModal">
  <div class="box">
    <h2 id="rvModalTitle"><i class="fas fa-key" style="color:var(--rv-brand)"></i> Nueva credencial</h2>
    <input type="hidden" id="fId">
    <div class="rv-field"><label>Agente (dueño de la máquina)</label>
      <select id="fUser"><option value="">— Sin agente / máquina compartida —</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> · <?= htmlspecialchars($u['role']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="rv-field"><label>Etiqueta *</label><input type="text" id="fLabel" placeholder="Ej. PC casa de Juan" maxlength="150"></div>
    <div class="rv-row">
      <div class="rv-field"><label>Herramienta</label><select id="fTool"><option value="anydesk">AnyDesk</option><option value="rustdesk">RustDesk</option><option value="teamviewer">TeamViewer</option><option value="other">Otra</option></select></div>
      <div class="rv-field"><label>ID remoto</label><input type="text" id="fRemoteId" placeholder="123 456 789" maxlength="120"></div>
    </div>
    <div class="rv-field"><label>Contraseña</label><input type="text" id="fPassword" placeholder="(déjalo vacío para no cambiarla al editar)" autocomplete="off"></div>
    <div class="rv-field"><label>IP / Hostname (opcional)</label><input type="text" id="fIp" placeholder="192.168.x.x o nombre-pc" maxlength="150"></div>
    <div class="rv-field"><label>Notas (opcional)</label><textarea id="fNotes" placeholder="Detalles útiles para conectarse…"></textarea></div>
    <div style="display:flex; gap:10px; margin-top:8px;">
      <button class="rv-btn primary" onclick="saveForm()" id="rvSaveBtn"><i class="fas fa-save"></i> Guardar</button>
      <button class="rv-btn ghost" onclick="closeForm()">Cancelar</button>
    </div>
  </div>
</div>

<script>
(function(){
  const API='../hr/helpdesk_api.php';
  let items=[], search='';
  const $=s=>document.querySelector(s);
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const TOOL={anydesk:'AnyDesk',rustdesk:'RustDesk',teamviewer:'TeamViewer',other:'Otra'};
  const ICON={anydesk:'fa-bolt',rustdesk:'fa-r',teamviewer:'fa-desktop',other:'fa-plug'};

  async function api(action,params={},method='GET'){
    let url=API+'?action='+action, opt={method};
    if(method==='GET'){ url+='&'+new URLSearchParams(params);} else { opt.body=new URLSearchParams(params);}
    const r=await fetch(url,opt); try{return await r.json();}catch(e){return{success:false,error:'Respuesta inválida'};}
  }
  async function load(){
    const d=await api('get_remote',{search});
    items=d.items||[];
    if(!items.length){ $('#rvGrid').innerHTML='<div class="rv-empty"><i class="fas fa-key"></i>No hay credenciales guardadas.<br>Agrega la primera con “Nueva credencial”.</div>'; return; }
    $('#rvGrid').innerHTML=items.map(it=>`
      <div class="rv-cardx" data-id="${it.id}">
        <div class="rh">
          <div class="rv-tool ${esc(it.tool)}"><i class="fas ${ICON[it.tool]||'fa-plug'}"></i></div>
          <div class="who"><div class="lb">${esc(it.label)}</div><div class="ag">${it.user_name?esc(it.user_name):'Máquina compartida'} · ${TOOL[it.tool]||esc(it.tool)}</div></div>
        </div>
        ${it.remote_id?`<div class="rv-kv"><span class="k">ID</span><span class="v">${esc(it.remote_id)}</span><span class="rv-copy" onclick="copyTxt('${esc(it.remote_id)}')"><i class="fas fa-copy"></i></span></div>`:''}
        ${it.ip_hostname?`<div class="rv-kv"><span class="k">IP/Host</span><span class="v">${esc(it.ip_hostname)}</span></div>`:''}
        <div class="rv-pass" id="pass-${it.id}">
          ${it.has_password==1?`<code>••••••••••</code><button class="rv-mini rev" onclick="reveal(${it.id})"><i class="fas fa-eye"></i> Ver</button>`:'<span style="font-size:12px;color:var(--rv-faint)">Sin contraseña guardada</span>'}
        </div>
        <div class="acts">
          <button class="rv-mini" onclick="edit(${it.id})"><i class="fas fa-pen"></i> Editar</button>
          <button class="rv-mini del" onclick="del(${it.id})"><i class="fas fa-trash"></i> Borrar</button>
        </div>
      </div>`).join('');
  }
  window.reveal=async function(id){
    const box=$('#pass-'+id);
    box.innerHTML='<code>Descifrando…</code>';
    const d=await api('reveal_remote',{id},'POST');
    if(!d.success){ box.innerHTML='<code style="color:#E0393B">Error</code>'; return; }
    box.innerHTML=`<code id="pv-${id}">${esc(d.password||'(vacía)')}</code>
      <button class="rv-mini" onclick="copyTxt(document.getElementById('pv-${id}').textContent)"><i class="fas fa-copy"></i></button>
      <button class="rv-mini" onclick="load()"><i class="fas fa-eye-slash"></i></button>`;
    if(d.notes){ box.insertAdjacentHTML('afterend',`<div class="rv-kv" style="margin-top:8px"><span class="k">Notas</span><span class="v" style="font-weight:500">${esc(d.notes)}</span></div>`); }
    setTimeout(()=>{ if(document.getElementById('pv-'+id)) load(); }, 45000); // auto-oculta
  };
  window.copyTxt=t=>{ navigator.clipboard.writeText(t).then(()=>toast('Copiado')); };
  window.edit=function(id){ const it=items.find(x=>x.id==id); if(!it) return; openForm(); $('#rvModalTitle').innerHTML='<i class="fas fa-pen" style="color:var(--rv-brand)"></i> Editar credencial'; $('#fId').value=it.id; $('#fUser').value=it.user_id||''; $('#fLabel').value=it.label; $('#fTool').value=it.tool; $('#fRemoteId').value=it.remote_id||''; $('#fIp').value=it.ip_hostname||''; $('#fPassword').value=''; $('#fPassword').placeholder='(déjalo vacío para conservar la actual)'; $('#fNotes').value=''; };
  window.del=async function(id){ if(!confirm('¿Borrar esta credencial?')) return; const d=await api('delete_remote',{id},'POST'); if(d.success){ toast('Borrada'); load(); } };
  window.openForm=function(){ $('#rvModal').style.display='block'; };
  window.closeForm=function(){ $('#rvModal').style.display='none'; $('#fId').value=''; ['fUser','fLabel','fRemoteId','fIp','fPassword','fNotes'].forEach(i=>$('#'+i).value=''); $('#fTool').value='anydesk'; $('#rvModalTitle').innerHTML='<i class="fas fa-key" style="color:var(--rv-brand)"></i> Nueva credencial'; $('#fPassword').placeholder='(déjalo vacío para no cambiarla al editar)'; };
  window.saveForm=async function(){
    const label=$('#fLabel').value.trim(); if(!label){ toast('La etiqueta es requerida',true); return; }
    $('#rvSaveBtn').disabled=true;
    const p={ id:$('#fId').value||0, user_id:$('#fUser').value, label, tool:$('#fTool').value, remote_id:$('#fRemoteId').value.trim(), ip_hostname:$('#fIp').value.trim(), notes:$('#fNotes').value };
    const pw=$('#fPassword').value; if(pw!=='') p.password=pw;
    const d=await api('save_remote',p,'POST'); $('#rvSaveBtn').disabled=false;
    if(d.success){ toast('Guardado'); closeForm(); load(); } else toast(d.error||'Error',true);
  };
  let tT; function toast(m,e){ let el=$('#rvToast'); if(!el){ el=document.createElement('div'); el.id='rvToast'; el.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:9999;padding:12px 20px;border-radius:12px;font-weight:700;font-size:13.5px;box-shadow:0 8px 30px rgba(0,0,0,.2);color:#fff'; document.body.appendChild(el);} el.style.background=e?'#E0393B':'#161F35'; el.textContent=m; el.style.opacity='1'; clearTimeout(tT); tT=setTimeout(()=>el.style.opacity='0',2000); }
  let sT; $('#rvSearch').addEventListener('input',e=>{ clearTimeout(sT); sT=setTimeout(()=>{ search=e.target.value; load(); },300); });
  $('#rvModal').addEventListener('click',e=>{ if(e.target.id==='rvModal') closeForm(); });
  load();
})();
</script>

</main>
</body>
</html>
