#!/usr/bin/env python3
"""Keep Radius only in the Provider menu, preserving other entries."""
import shutil,sys,os,tempfile,json,hashlib
from pathlib import Path
MARKER='/* MKA-SUITE-RADIUS-APPLICATIONS-MENU */'
BLOCK=r'''
/* MKA-SUITE-RADIUS-APPLICATIONS-MENU */
;(function(){
 function addRadius(){
  var groups=Array.from(document.querySelectorAll('.navbar-start .has-dropdown'));
  function groupMenu(pattern){var group=groups.find(function(n){var label=n.querySelector('.navbar-link');return label&&pattern.test(label.textContent);});return group?group.querySelector('.navbar-dropdown'):null;}
  var applications=document.getElementById('menu_amsis')||groupMenu(/APLICATIVOS|ADDONS/i),provider=document.getElementById('menu_provedor')||groupMenu(/PROVEDOR/i);
  function ensure(menu){if(!menu)return false;var links=Array.from(menu.querySelectorAll('a')).filter(function(a){return /\/addons\/radius\/?(?:[?#]|$)/.test(a.getAttribute('href')||'');});if(links.length){links.slice(1).forEach(function(a){a.remove();});links[0].innerHTML='<i class="fa fa-fw fa-list-alt" aria-hidden="true"></i>&nbsp; LOG RADIUS';return true;}var link=document.createElement('a');link.className='navbar-item';link.href='/admin/addons/radius/';link.innerHTML='<i class="fa fa-fw fa-list-alt" aria-hidden="true"></i>&nbsp; LOG RADIUS';menu.appendChild(link);return true;}
  if(applications)Array.from(applications.querySelectorAll('a')).forEach(function(a){if(/\/addons\/radius\/?(?:[?#]|$)/.test(a.getAttribute('href')||''))a.remove();});return ensure(provider);
 }
 function start(){if(addRadius())return;var root=document.querySelector('.navbar-start')||document.body;if(!root||typeof MutationObserver==='undefined')return;var observer=new MutationObserver(function(){if(addRadius())observer.disconnect();});observer.observe(root,{childList:true,subtree:true});window.setTimeout(function(){observer.disconnect();},30000);}
 if(document.readyState==='complete')start();else window.addEventListener('load',start,{once:true});
})();
'''
def patch(text):
    if MARKER in text:
        start=text.index(MARKER);end=text.index('})();',start)+len('})();')
        return text[:start]+BLOCK.strip()+text[end:]
    return text.rstrip()+'\n'+BLOCK

def patch_bytes(source):
    # The owned block is ASCII. Do not decode/re-encode custom/native scripts:
    # legacy MK-AUTH installations may use Latin-1, CP1252 or mixed bytes.
    legacy=b"add_menu.provedor('{\"plink\": \"' + minha_url + 'addons/radius/\", \"ptext\": \"<b>Radius Logs</b>\"}');"
    source=source.replace(legacy,b'')
    marker=MARKER.encode('ascii');block=BLOCK.strip().encode('ascii')
    if marker in source:
        start=source.index(marker);closing=source.index(b'})();',start)+len(b'})();')
        return source[:start]+block+source[closing:]
    return source+(b'\n' if source and not source.endswith(b'\n') else b'')+block+b'\n'
def atomic(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,name=tempfile.mkstemp(prefix='.radius-menu-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb')as f:f.write(data)
        if path.exists():
            st=path.stat();os.chmod(name,st.st_mode&0o7777);os.chown(name,st.st_uid,st.st_gid)
        else:os.chmod(name,0o644)
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

def restore(changes):
    for path,old in reversed(changes):
        if old is None:
            if path.exists():path.unlink()
        else:atomic(path,old)

def install(admin,backup):
    backup=Path(backup);changes=[]
    for relative in ['addons/addon.js','scripts/mk-auth.js']:
        path=Path(admin)/relative
        if path.is_symlink() or any(p.is_symlink()for p in path.parents):raise ValueError('Caminho simbolico preservado: '+str(path))
        if not path.exists() and relative.startswith('scripts/'):continue
        old=path.read_bytes()if path.exists()else None;new=patch_bytes(old or b'')
        if new!=old:changes.append((path,old,new,relative))
    backup.mkdir(parents=True,exist_ok=True);backup.chmod(0o700)
    manifest=[];applied=[]
    try:
        for i,(path,old,new,relative)in enumerate(changes):
            if old is not None:(backup/str(i)).write_bytes(old);(backup/str(i)).chmod(0o600)
            manifest.append({'relative':relative,'before':str(i)if old is not None else None,'installed_sha256':hashlib.sha256(new).hexdigest()})
            if (path.read_bytes()if path.exists()else None)!=old:raise RuntimeError('Menu alterado durante instalacao.')
            atomic(path,new);applied.append((path,old))
        (backup/'files.json').write_text(json.dumps(manifest,indent=2))
    except Exception:restore(applied);raise
    print('Atalho LOG RADIUS no Provedor; carregamento independente e sem duplicar menus.')
    return applied

if __name__=='__main__':install(sys.argv[1],sys.argv[2])
