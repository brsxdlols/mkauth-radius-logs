import hashlib, json, shutil, sys, tempfile
from pathlib import Path
from install_menu import atomic

def rollback(admin,backup):
    admin,backup=map(Path,(admin,backup));target=admin/'addons/radius';menu=backup/'menu'
    if not admin.is_absolute()or admin.name!='admin':raise RuntimeError('Destino invalido.')
    for p in (target,backup):
        if p.is_symlink()or any(x.is_symlink()for x in p.parents):raise RuntimeError('Caminho simbolico recusado.')
    rows=json.loads((menu/'files.json').read_text());changes=[]
    for row in rows:
        if row['relative']not in ('addons/addon.js','scripts/mk-auth.js'):raise RuntimeError('Menu inesperado no backup.')
        path=admin/row['relative']
        if path.is_symlink()or not path.is_file()or hashlib.sha256(path.read_bytes()).hexdigest()!=row['installed_sha256']:raise RuntimeError('Menu alterado depois da instalacao; preservado para revisao.')
        name=row['before']
        if name is not None and not str(name).isdigit():raise RuntimeError('Backup de menu invalido.')
        changes.append((path,(menu/name).read_bytes()if name is not None else None,path.read_bytes()))
    meta=json.loads((backup/'install-meta.json').read_text())
    if meta['had_radius']and not (backup/'radius').is_dir():raise RuntimeError('Backup Radius ausente.')
    temp=Path(tempfile.mkdtemp(prefix='.radius-rollback-',dir=str(admin/'addons')));applied=[];swapped=False
    try:
        if meta['had_radius']:shutil.copytree(backup/'radius',temp/'restored',symlinks=True)
        if target.exists():target.rename(temp/'before')
        if meta['had_radius']:(temp/'restored').rename(target)
        swapped=True
        for path,old,current in changes:
            if old is None:path.unlink()
            else:atomic(path,old)
            applied.append((path,current))
    except Exception:
        for path,current in reversed(applied):atomic(path,current)
        if swapped and target.exists():target.rename(temp/'failed')
        if (temp/'before').exists():(temp/'before').rename(target)
        raise
    finally:shutil.rmtree(temp)
    print('Rollback dos arquivos e menus concluido; configuracoes no banco preservadas.')

if __name__=='__main__':
    try:rollback(*sys.argv[1:])
    except Exception as e:print('ERRO: '+str(e),file=sys.stderr);raise SystemExit(1)
