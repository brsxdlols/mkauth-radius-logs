"""Stage the complete Radius package and roll back files if deployment fails."""
import grp, hashlib, json, os, shutil, subprocess, sys, tempfile
from pathlib import Path
from install_menu import install as install_menu, restore as restore_menu

def php_string(value):return "'"+str(value).replace('\\','\\\\').replace("'","\\'")+"'"
def deploy(source,admin,core,backup,web_user,log_file=''):
    source,admin,core,backup=map(Path,(source,admin,core,backup))
    if not admin.is_absolute()or admin.name!='admin':raise RuntimeError('Destino administrativo invalido.')
    addons=admin/'addons';target=addons/'radius'
    for p in (admin,addons,target,backup):
        if p.is_symlink()or any(x.is_symlink()for x in p.parents):raise RuntimeError('Caminho simbolico preservado: '+str(p))
    if target.exists()and not target.is_dir():raise RuntimeError('Destino Radius nao e um diretorio.')
    backup.mkdir(parents=True,exist_ok=True);backup.chmod(0o700);addons.mkdir(exist_ok=True)
    had_radius=target.exists()
    if had_radius:shutil.copytree(target,backup/'radius',symlinks=True)
    for p in source.rglob('*'):
        if p.is_symlink():raise RuntimeError('Pacote com link simbolico recusado.')
    temp=Path(tempfile.mkdtemp(prefix='.radius-install-',dir=str(addons)));stage=temp/'radius';previous=temp/'before';menus=[];swapped=False
    try:
        shutil.copytree(source,stage)
        if log_file:(stage/'log_config.php').write_text('<?php return '+php_string(log_file)+';\n',encoding='utf8')
        elif (target/'log_config.php').is_file()and not (target/'log_config.php').is_symlink():shutil.copy2(target/'log_config.php',stage/'log_config.php')
        try:gid=grp.getgrnam(web_user).gr_gid
        except KeyError:gid=0
        for p in [stage]+list(stage.rglob('*')):
            p.chmod(0o755 if p.is_dir()else 0o644);os.chown(str(p),0,gid)
            if p.is_file()and p.suffix in ('.php','.hhvm'):subprocess.run(['php','-l',str(p)],check=True,stdout=subprocess.DEVNULL)
        (stage/'addons.class.php').symlink_to(core)
        if target.exists():target.rename(previous)
        try:stage.rename(target)
        except Exception:
            if previous.exists():previous.rename(target)
            raise
        swapped=True
        menus=install_menu(admin,backup/'menu')
        files={p.relative_to(target).as_posix():hashlib.sha256(p.read_bytes()).hexdigest()for p in target.rglob('*')if p.is_file()and not p.is_symlink()}
        (backup/'installed-files.json').write_text(json.dumps(files,indent=2))
        (backup/'install-meta.json').write_text(json.dumps({'had_radius':had_radius,'version':'4.5.0'}))
    except Exception:
        if menus:restore_menu(menus)
        if swapped:
            target.rename(temp/'failed')
            if previous.exists():previous.rename(target)
        raise
    finally:
        # Only this generated private staging directory is removed.
        if temp.parent!=addons or not temp.name.startswith('.radius-install-'):raise RuntimeError('Staging inesperado.')
        shutil.rmtree(temp)

if __name__=='__main__':
    try:deploy(*sys.argv[1:])
    except Exception as e:print('ERRO: Radius preservado/restaurado: '+str(e),file=sys.stderr);raise SystemExit(1)
