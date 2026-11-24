(function () {
    const list = document.getElementById('logs') || document.getElementById('radiusLogList');
    if (!list) return;
    const style = document.createElement('style');
    style.textContent = '.radius-status-flags{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px}.radius-status-flag{display:inline-block;border:0;border-radius:6px;padding:3px 7px;font:600 10px/1.4 Arial,sans-serif;background:#334155;color:#e2e8f0}.radius-status-flag.is-blocked{background:#582632;color:#fda4af}.radius-status-flag.is-action{cursor:pointer}.radius-status-flag.is-action:hover{background:#793244}.radius-hide-disabled{display:inline-flex;align-items:center;gap:7px;font:12px Arial,sans-serif;color:#475569;margin:6px 0}.radius-status-hidden{display:none!important}';
    document.head.append(style);
    const label = document.createElement('label'), input = document.createElement('input');
    input.type='checkbox'; label.className='radius-hide-disabled';
    label.append(input, document.createTextNode('Ocultar logins desativados'));
    const toolbar = document.querySelector('.log-search') || document.querySelector('.radius-filters');
    const gear=document.createElement('button');gear.type='button';
    gear.textContent='⚙';gear.title='Configurações do Log RADIUS';gear.setAttribute('aria-label','Configurações do Log RADIUS');
    gear.style.cssText='display:inline-grid;place-items:center;width:34px;height:34px;flex:none;align-self:flex-start;border:1px solid #d7e2f0;border-radius:9px;background:#fff;color:#2563eb;font:22px/1 Arial;cursor:pointer';
    if(list.id==='logs'){gear.style.padding='0';gear.style.boxSizing='border-box';gear.style.alignSelf='center';}
    if(toolbar)toolbar.append(gear);else list.before(gear);
    const settings=document.createElement('dialog');settings.setAttribute('aria-label','Configurações do Log RADIUS');
    settings.style.cssText='box-sizing:border-box;width:min(420px,90vw);padding:24px;border:1px solid #dce5f1;border-radius:16px;background:#fff;color:#18324a;box-shadow:0 24px 70px #0004;font:14px/1.5 Arial,sans-serif';
    const title=document.createElement('h3');title.textContent='Configurações do Log RADIUS';title.style.cssText='margin:0 0 16px;font-size:18px';
    const help=document.createElement('p');help.textContent='A escolha fica salva no servidor para todos os usu\u00e1rios e navegadores. Para mostrar novamente, desmarque.';help.style.cssText='font-size:12px;color:#64748b;margin:12px 0';
    const feedback=document.createElement('p');feedback.setAttribute('role','status');feedback.style.cssText='font-size:12px;color:#16804a;min-height:18px';
    const close=document.createElement('button');close.type='button';close.textContent='Concluir';close.style.cssText='border:0;border-radius:9px;padding:10px 18px;background:#2563eb;color:#fff;cursor:pointer;font-weight:600';
    settings.append(title,label,help,feedback,close);document.body.append(settings);
    gear.onclick=function(){feedback.textContent='';settings.showModal();};close.onclick=function(){settings.close();};settings.addEventListener('close',function(){gear.focus();});

    let cache={}, busy=false, pending;
    function loginOf(entry) {
        const node=entry.querySelector('.radius-client-link');
        if(!node)return '';
        const text=node.textContent.trim();
        return (text.includes(' • ')?text.slice(text.lastIndexOf(' • ')+3):text).trim().toLowerCase();
    }
    function paint() {
        observer.disconnect();
        for(const entry of list.querySelectorAll('.radius-log-entry')) {
            const state=cache[loginOf(entry)];
            entry.classList.toggle('radius-status-hidden',!!(input.checked && state && state.disabled));
            const signature=state?JSON.stringify(state):'';
            if(entry.dataset.clientStatus===signature)continue;
            entry.dataset.clientStatus=signature;
            entry.querySelector('.radius-status-flags')?.remove();
            if(!state)continue;
            const flags=document.createElement('div');flags.className='radius-status-flags';
            if(state.disabled){const badge=document.createElement('span');badge.className='radius-status-flag';badge.textContent='Desativado';flags.append(badge);}
            if(state.blocked){const badge=document.createElement(state.missing_pages?'button':'span');badge.className='radius-status-flag is-blocked'+(state.missing_pages?' is-action':'');badge.textContent='Bloqueado';if(state.missing_pages){badge.type='button';badge.onclick=function(){mkaCompactNotice('Bloqueado','Cliente sem PGCORTE/PGAVISO marcados');};}flags.append(badge);}
            entry.querySelector('.radius-log-meta')?.append(flags);
        }
        observer.observe(list,{childList:true,subtree:true});
    }
    const observer=new MutationObserver(function(){paint();clearTimeout(pending);pending=setTimeout(refresh,150);});
    async function refresh(force) {
        if(busy || document.hidden)return;
        const keys=[...new Set([...list.querySelectorAll('.radius-log-entry')].map(loginOf).filter(Boolean))];
        const missing=force?keys:keys.filter(key=>!(key in cache));
        if(!missing.length)return;
        busy=true;
        try { const response=await fetch('/admin/addons/radius/client_status.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({logins:missing.slice(0,2000)})});
            if(!response.ok)throw new Error('Status indisponível');
            const data=await response.json();for(const key of missing)cache[key]=data[key]||null;paint();
        } catch (_) { /* Never label or hide customers when their status is unknown. */ }
        finally {busy=false;}
    }
    window.mkaRadiusPreference(input,feedback,paint);

    paint();refresh(true);
    const timer=setInterval(function(){refresh(true);},15000);
    window.addEventListener('pagehide',function(){clearInterval(timer);clearTimeout(pending);observer.disconnect();});
})();
