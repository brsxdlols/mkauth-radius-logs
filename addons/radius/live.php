<?php
require_once __DIR__.'/access.php';
if (!radius_has_permission($conn,$radiusUser,'perm_clientes')) { http_response_code(403); exit('Sem permissão.'); }
$login = trim((string)($_GET['login'] ?? ''));
if (strlen($login)>64) { http_response_code(422); exit('Login inválido.'); }
$user = (string)(!empty($_SESSION['MKA_Usuario']) ? $_SESSION['MKA_Usuario'] : ($_SESSION['MM_Usuario'] ?? ''));
$st=$conn->prepare('SELECT cli_grupos FROM sis_acesso WHERE login=? LIMIT 1');$st->bind_param('s',$user);$st->execute();$access=$st->get_result()->fetch_assoc();
$groups=$access?array_map('trim',explode(',',(string)$access['cli_grupos'])):array();
$full=$access && (trim((string)$access['cli_grupos'])==='' || in_array('full_clientes',$groups,true));
require_once __DIR__.'/client_links.php';
if (!$full) {
    $allowed=radius_live_clients($conn,array($login),false,$groups);
    if (!$access || !isset($allowed[strtolower($login)])) { http_response_code(403);exit('Cliente fora dos grupos permitidos.'); }
}
session_write_close();header('Cache-Control: no-store');
require_once __DIR__.'/radius_lib.php';
require_once __DIR__.'/history.php';
require_once __DIR__.'/current_state.php';
function radius_live_type($entry) {
    if($entry['type']==='outros' && preg_match('/rlm_sql|\(sql\)|connections to reach .*spares/i',$entry['line'])) return 'sql';
    return $entry['type'];
}
if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    $data=radius_read_logs('todos',2000);$rows=array();
    $limit=(int)($_GET['limit']??100);if(!in_array($limit,array(100,250,500,1000,2000),true))$limit=100;
    $selected=isset($_GET['types']) ? explode(',',(string)$_GET['types']) : array('conectados','erros','multiplos');
    foreach ($data['entries'] as $entry) {
        if ($login!=='' && strcasecmp(trim($entry['login']),$login)!==0) continue;
        if ($login==='' && !in_array(radius_live_type($entry),$selected,true)) continue;
        // FreeRADIUS can log credentials in [login/password]. Never display passwords.
        $type=radius_live_type($entry);$labels=radius_type_labels();
        $rows[]=array('type'=>$type,'label'=>$labels[$type],'login'=>$entry['login'],'line'=>$entry['line']);
        if (count($rows)>=$limit) break;
    }
    $history=null;
    if($login!=='' && isset($_GET['history']) && count($rows)<5){$history=radius_latest_history($login,5);}
    $logins=array_column($rows,'login');if($login!=='')$logins[]=$login;
    if($history && !empty($history['row']))$logins[]=$history['row']['login'];
    $clients=radius_live_clients($conn,$logins,$full,$groups);
    foreach($rows as &$row){$match=$clients[strtolower(trim($row['login']))]??array();$row['name']=$match['name']??'';$row['client_url']=$match['url']??'';}unset($row);
    if($history && !empty($history['row'])){$match=$clients[strtolower(trim($history['row']['login']))]??array();$history['row']['name']=$match['name']??'';$history['row']['client_url']=$match['url']??'';}
        $current=$login!=='' ? radius_current_state($conn,$login,$clients[strtolower(trim($login))] ?? array()) : null;
    $range=radius_log_range(array_column($data['entries'],'line'));
    if($login!==''){$rows=radius_monitor_events($conn,$current,$rows,$history);if($history){$history['row']=null;$history['note']='Últimos 5 eventos do login; conexão, desconexão e tentativas de autenticação.';}}
    echo json_encode(array('current'=>$current,'log_range'=>$range,'rows'=>$rows,'history'=>$history,'error'=>$data['error'],'updated_at'=>$data['updated_at']),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
?>
<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Log RADIUS</title>
<link rel="stylesheet" href="radius.css?v=450"><style>body{font:14px system-ui;background:#f3f7fc;color:#17324f;padding:16px;margin:0}button{padding:9px 14px;border:1px solid #b8cbe4;border-radius:8px;background:white;cursor:pointer}.radius-log-list{border-radius:12px;max-height:none;min-height:80px}h2{margin-top:0}</style>
<h2>Log RADIUS <?= $login!==''?'— '.radius_escape($login):'— geral' ?></h2><button id="toggle">Pausar</button><p id="status" role="status">Carregando…</p><p id="log-range"></p><p id="history-note"></p><p>Atualização a cada 3 segundos, filtro nos eventos carregados.<?php if($login!==''): ?> Login OK histórico não comprova conexão atual; ausência de log não comprova ausência de requisição.<?php endif; ?></p><div class="log-search"><input id="search" aria-label="Buscar nome ou login" placeholder="Digite nome ou login para filtrar"><label>Linhas <select id="line-limit"><option>100</option><option>250</option><option>500</option><option>1000</option><option>2000</option></select></label></div><main id="logs" class="radius-log-list"></main>
<style>.live-filters{display:flex;flex-wrap:wrap;gap:8px;border:1px solid #d7e2f0;border-radius:12px;padding:14px;margin:16px 0;background:#fff}.live-filters legend{font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;padding:0 6px}.live-filters label{position:relative;cursor:pointer;margin:0}.live-filters input{position:absolute;opacity:0;width:1px;height:1px}.live-filters span{display:block;border:1px solid #d9e3f1;border-radius:8px;background:white;color:#475569;padding:8px 13px;font-size:12px;font-weight:700;transition:background .15s,box-shadow .15s}.live-filters input:checked+span{background:#2563eb;border-color:#2563eb;color:#fff}.live-filters label:hover span{box-shadow:0 3px 10px #2563eb26}.live-filters input:focus-visible+span{outline:3px solid #93c5fd;outline-offset:2px}</style>
<style>html{height:100%;overflow:hidden}body{box-sizing:border-box;height:100%;display:flex;flex-direction:column;overflow:hidden;gap:8px}body>*{flex-shrink:0}body p{margin:3px 0}#history-note:empty{display:none}.live-filters{margin:3px 0}#logs{flex:1 1 0;min-height:0;max-height:none;overflow:auto;overscroll-behavior:contain}.log-search{display:flex;gap:12px;padding:8px 0}.log-search input{flex:1;min-width:0}.log-search input,.log-search select{padding:10px;border:1px solid #d7e2f0;border-radius:8px;background:white;color:#17324f}#toggle{align-self:flex-start}h2{margin:0;font-size:20px}</style>
<script src="compact_notice.js?v=450"></script>
<script>
const toggleColors=document.createElement('style');toggleColors.textContent='#toggle{background:#e3344f;border-color:#e3344f;color:#fff;font-weight:600;transition:background .15s}#toggle:hover{background:#c9253e}#toggle.is-paused{background:#2563eb;border-color:#2563eb}#toggle.is-paused:hover{background:#1d4ed8}';document.head.append(toggleColors);
document.getElementById('toggle').addEventListener('click',function(){this.classList.toggle('is-paused');});
// Compact toolbar: reserve the modal height for events rather than controls.
const compactStyle=document.createElement('style');compactStyle.textContent='body{padding:10px 14px;gap:6px}.log-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}.log-heading h2{font-size:18px;margin:0}.log-heading button{padding:5px 12px;font-size:12px}.live-filters{padding:6px 0;margin:0;border:0;background:transparent;gap:6px}.live-filters legend{display:none}.live-filters span{padding:6px 10px;font-size:11px}#status{font-size:11px;color:#708198;margin:0}.log-search{padding:0;gap:8px}.log-search input,.log-search select{padding:7px 9px;font-size:12px}#history-note{font-size:11px}';document.head.append(compactStyle);
const headingBar=document.createElement('header');headingBar.className='log-heading';const heading=document.querySelector('h2'),pause=document.getElementById('toggle');heading.before(headingBar);headingBar.append(heading,pause);
if(<?= $login===''?'true':'false' ?>){const note=document.getElementById('history-note').nextElementSibling;if(note&&note.tagName==='P')note.remove();}
const hiddenStyle=document.createElement('style');hiddenStyle.textContent='#logs [hidden]{display:none!important}';document.head.append(hiddenStyle);
const radiusTypes=document.createElement('fieldset');radiusTypes.className='live-filters';
const isGeneral=<?= $login===''?'true':'false' ?>;
if(!isGeneral){
 document.body.classList.add('radius-individual');
 const explanation=document.getElementById('history-note').nextElementSibling;
 explanation.textContent='Últimos 5 eventos • atualização a cada 3 segundos. Estado atual separado do histórico.';
 const fitStyle=document.createElement('style');
 fitStyle.textContent='.radius-individual{gap:5px}.radius-individual #history-note{display:none}.radius-individual #log-range,.radius-individual #history-note+p{font-size:11px;line-height:1.4;margin:0}.radius-individual #logs{padding:8px}.radius-individual .radius-current-state{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;gap:3px 12px;padding:9px 12px!important;font-size:12px!important;line-height:1.4!important}.radius-individual .radius-current-state>div:nth-child(n+3){grid-column:1/-1}.radius-individual .radius-log-entry{grid-template-columns:minmax(130px,180px) minmax(0,1fr);gap:10px;padding:8px 12px;margin-bottom:5px;min-height:0}.radius-individual .radius-log-meta{gap:4px}.radius-individual .radius-log-entry code{font-size:11px;line-height:1.4}.radius-individual .radius-client-link{font-size:11px;line-height:1.3}.radius-individual .log-search input{padding:6px 9px}@media(max-width:600px){.radius-individual .radius-log-entry{grid-template-columns:1fr}.radius-individual .radius-current-state{grid-template-columns:1fr}.radius-individual .radius-current-state>div{grid-column:1}}';
 document.head.append(fitStyle);
}
if(!isGeneral){document.getElementById('line-limit').closest('label').hidden=true;const style=document.createElement('style');style.textContent='.radius-log-entry.type-desconectados .radius-log-badge{background:#4c2632;color:#fda4af}.radius-log-entry.type-desconectados code{color:#fda4af}';document.head.append(style);}
if(isGeneral){const legend=document.createElement('legend');legend.textContent='Exibir eventos';radiusTypes.append(legend);for(const [value,label,checked] of [['conectados','Login OK',true],['erros','Login incorreto',true],['multiplos','Duplicados',true],['sql','SQL',false],['outros','Informações',false]]){const wrap=document.createElement('label'),input=document.createElement('input');input.type='checkbox';input.value=value;input.checked=checked;const caption=document.createElement('span');caption.textContent=label;wrap.append(input,caption);radiusTypes.append(wrap);}document.getElementById('status').before(radiusTypes);}
let running=true,timer,controller,busy=false,first=true,saved=[],historyRange=null;const status=document.getElementById('status'),logs=document.getElementById('logs');
function paintCurrent(current){
 if(isGeneral||!current)return;
 const entry=document.createElement('article');entry.className='radius-current-state';
 const online=current.state==='online',offline=current.state==='offline';
 entry.style.cssText='margin:0 0 7px;padding:12px 14px;border:1px solid '+(online?'#15966c':offline?'#64748b':'#1e293b')+';border-left-width:4px;border-radius:10px;background:'+(online?'#075e45':offline?'#374151':'#0a0f16')+';color:#f8fafc;font:13px/1.6 system-ui';
 const badge=document.createElement('span');badge.textContent=current.state==='online'?'CONECTADO — ONLINE':current.state==='offline'?'DESCONECTADO — OFFLINE':'ESTADO INDISPONÍVEL';
 badge.style.cssText='display:inline-block;padding:4px 9px;border-radius:6px;font-weight:700;color:'+(online?'#d1fae5':offline?'#f8fafc':'#cbd5e1')+';background:'+(online?'#047857':offline?'#4b5563':'#334155');
 const name=document.createElement('div');name.textContent=(current.name?current.name+' • ':'')+current.login;
 const detail=document.createElement('div');detail.textContent='Estado atual no MK-AUTH, consultado em '+current.checked_at+'.';detail.style.color=online?'#c3f4de':offline?'#d1d5db':'#94a3b8';
 entry.append(badge,name,detail);
 if(!saved.length){const note=document.createElement('div');note.textContent='Sem registro de conexão no histórico consultado. O estado atual acima é independente da retenção dos logs.';note.style.cssText='margin-top:8px;color:#94a3b8';entry.append(note);}
 logs.append(entry);
}
async function update(){if(!running||document.hidden||busy)return;busy=true;controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),8000);try{const r=await fetch('live.php?data=1&limit='+document.getElementById('line-limit').value+(first?'&history=1':'')+(isGeneral?'&types='+encodeURIComponent(Array.from(radiusTypes.querySelectorAll('input:checked'),e=>e.value).join(',')):'')+'&login='+encodeURIComponent(<?= json_encode($login,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>),{cache:'no-store',signal:controller.signal});if(!r.ok)throw Error('Acesso indisponível ('+r.status+')');const d=await r.json();first=false;if(d.history){historyRange=d.history.range;document.getElementById('history-note').textContent=d.history.note;if(d.history.row)saved=[d.history.row];}if(isGeneral)saved=d.rows;else{const merged=new Map([...saved,...d.rows].map(row=>[row.event_key||row.line,row]));const all=[...merged.values()];saved=all.filter(row=>row.source!=='accounting_start'||!all.some(other=>other.source==='radius'&&other.type==='conectados'&&Math.abs(other.event_time-row.event_time)<=30)).sort((a,b)=>(b.event_time||0)-(a.event_time||0)).slice(0,5);}logs.replaceChildren();paintCurrent(d.current);const range=historyRange?{...historyRange,to:d.log_range?.to||historyRange.to}:d.log_range;document.getElementById('log-range').textContent=range&&range.from?'Logs consultados: '+range.from+' até '+range.to+' ('+range.files+' arquivo'+(range.files===1?'':'s')+(range.complete?'':'; busca parcial')+').':'Período dos logs indisponível.';status.textContent=d.error||('Atualizado em '+d.updated_at);for(const row of saved){const el=document.createElement('article');el.className='radius-log-entry type-'+row.type;const meta=document.createElement('div');meta.className='radius-log-meta';const badge=document.createElement('span');badge.className='radius-log-badge';badge.textContent=row.label;const name=document.createElement(row.client_url?'a':(row.login?'button':'span'));name.className='radius-client-link';name.textContent=row.name?row.name+' • '+row.login:row.login;if(row.client_url){name.href=row.client_url;name.target='_blank';name.rel='noopener noreferrer';}else if(row.login){name.type='button';name.style.cssText='border:0;background:transparent;padding:0;text-align:left;font:inherit;color:inherit;cursor:pointer';name.onclick=()=>mkaCompactNotice('Login RADIUS','Usuário não encontrado no sistema ou tentativa incorreta');}meta.append(badge,name);const code=document.createElement('code');code.textContent=row.line;el.append(meta,code);logs.append(el);}applySearch();}catch(e){status.textContent='Falha na atualização: '+e.message;}finally{busy=false;clearTimeout(timeout);if(running&&!document.hidden)timer=setTimeout(update,3000);}}
radiusTypes.addEventListener('change',()=>{saved=[];logs.replaceChildren();clearTimeout(timer);if(!busy)update();});
function applySearch(){const q=document.getElementById('search').value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();for(const el of logs.children)el.hidden=!el.textContent.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().includes(q);}
document.getElementById('search').addEventListener('input',applySearch);document.getElementById('line-limit').addEventListener('change',()=>{clearTimeout(timer);if(!busy)update();});
document.getElementById('toggle').onclick=function(){running=!running;this.textContent=running?'Pausar':'Retomar';clearTimeout(timer);if(running)update();};document.addEventListener('visibilitychange',()=>{clearTimeout(timer);if(!document.hidden&&running)update();});window.addEventListener('pagehide',()=>{running=false;clearTimeout(timer);if(controller)controller.abort();});update();
</script><script src="assets/radius_preferences.js?v=450"></script>
<script src="client_status.js?v=450"></script></html>
