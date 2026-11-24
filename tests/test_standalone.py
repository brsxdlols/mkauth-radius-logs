"""Isolated native-layout fixture, scratch database and localhost PHP server only."""
import hashlib, http.cookiejar, json, os, re, secrets, shlex, socket, subprocess, sys, tempfile, time
from pathlib import Path
import urllib.request, urllib.error, urllib.parse
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'installers'))
from deploy import deploy
from rollback import rollback
from install_menu import patch_bytes, MARKER

def php(code,env):
    r=subprocess.run(['php','-r',code],env=env,capture_output=True,text=True)
    if r.returncode:raise RuntimeError(r.stderr[-1200:])
    return r.stdout

def main():
    if not os.environ.get('MYSQL_TEST_PASSWORD'):raise SystemExit('Set MYSQL_TEST_PASSWORD for a disposable test schema; never point fixtures at native tables.')
    with tempfile.TemporaryDirectory(prefix='radius-standalone-test-')as folder:
        base=Path(folder);native=base/'mk';admin=native/'admin';addons=admin/'addons';addons.mkdir(parents=True);(admin/'scripts').mkdir();(native/'include').mkdir();sessions=base/'sessions';sessions.mkdir()
        core=native/'include/addons.inc.hhvm';core.write_text('<?php // Native addon-loader fixture. Identity remains checked by access.php.\n')
        legacy=b"var customized='caf\xe9';\nadd_menu.provedor('{\"plink\": \"' + minha_url + 'addons/radius/\", \"ptext\": \"<b>Radius Logs</b>\"}');\n"
        (addons/'addon.js').write_bytes(legacy);(admin/'scripts/mk-auth.js').write_bytes(b'// native fixture\n')
        patched=patch_bytes(legacy);assert b"caf\xe9"in patched and b'add_menu.provedor('not in patched and patch_bytes(patched)==patched and patched.count(MARKER.encode())==1
        print('PASS legacy bytes, shortcut migration and idempotent menu patch')
        log=base/'radius.log';log.write_text('Mon Nov 23 12:00:00 2026 : Auth: Login OK: [alpha/fixture-password] (from client NAS-A port 1 cli AA:BB:CC:DD:EE:FF)\nMon Nov 23 12:01:00 2026 : Auth: Login incorrect: [beta/fixture-password] (from client NAS-B port 2 cli BB:BB:CC:DD:EE:FF)\n')
        deploy(ROOT/'addons/radius',admin,core,base/'backup','root',str(log))
        assert not (addons/'dashboard').exists()and not (addons/'shared').exists()and not (addons/'busca_inteligente').exists()
        manifest=json.loads((addons/'radius/package_features.json').read_text());assert manifest['complete_standalone']
        first=(addons/'addon.js').read_bytes();deploy(ROOT/'addons/radius',admin,core,base/'repeat','root',str(log));assert (addons/'addon.js').read_bytes()==first
        rollback(admin,base/'repeat');assert (addons/'radius/current_state.php').is_file()
        # A native loader symlink must reject and roll back the addon swap.
        old=(addons/'radius/index.php').read_bytes();script=admin/'scripts/mk-auth.js';installed_script=script.read_bytes();script.unlink();script.symlink_to(base/'external');(base/'external').write_bytes(b'// untouched')
        try:deploy(ROOT/'addons/radius',admin,core,base/'failed','root',str(log))
        except ValueError:pass
        else:raise AssertionError('Native loader symlink accepted')
        assert (addons/'radius/index.php').read_bytes()==old and (base/'external').read_bytes()==b'// untouched'
        script.unlink();script.write_bytes(installed_script);print('PASS complete package without dashboard, repeat, rollback and failed-swap recovery')
        schema='codex_radius_standalone_'+secrets.token_hex(6);env=dict(os.environ,MYSQL_TEST_SCHEMA=schema)
        connect="$d=new mysqli(getenv('MYSQL_TEST_HOST')?:'127.0.0.1',getenv('MYSQL_TEST_USER')?:'root',getenv('MYSQL_TEST_PASSWORD'));"
        php(connect+"$d->query('CREATE DATABASE `'.getenv('MYSQL_TEST_SCHEMA').'` CHARACTER SET utf8mb4');",env)
        server=None;output=None
        try:
            sql=["CREATE TABLE sis_acesso(login VARCHAR(64),cli_grupos VARCHAR(255))","CREATE TABLE sis_perm(usuario VARCHAR(64),nome VARCHAR(64),permissao VARCHAR(8))","CREATE TABLE sis_cliente(id INT PRIMARY KEY,login VARCHAR(64),nome VARCHAR(255),uuid_cliente VARCHAR(64),grupo VARCHAR(64),cli_ativado CHAR(1),bloqueado VARCHAR(8),pgcorte VARCHAR(8),pgaviso VARCHAR(8),ip VARCHAR(64),mac VARCHAR(64),autoip VARCHAR(8),automac VARCHAR(8),simultaneo VARCHAR(8)) ENGINE=InnoDB","CREATE TABLE sis_adicional(id INT PRIMARY KEY,username VARCHAR(64),login VARCHAR(64),simultaneo VARCHAR(8)) ENGINE=InnoDB","CREATE TABLE radacct(radacctid INT PRIMARY KEY,username VARCHAR(64),acctstarttime DATETIME,acctupdatetime DATETIME,acctstoptime DATETIME NULL,INDEX(username))","CREATE TABLE sis_opcao(nome VARCHAR(64) PRIMARY KEY,valor VARCHAR(255))","INSERT INTO sis_acesso VALUES('full','full_clientes'),('limited','A')","INSERT INTO sis_perm VALUES('full','perm_clientes','sim'),('full','perm_config','sim'),('full','perm_altcliente','sim'),('limited','perm_clientes','sim'),('limited','perm_altcliente','sim')","INSERT INTO sis_cliente VALUES(1,'alpha','Alpha','uuid-alpha','A','s','nao','sim','sim','192.0.2.1','AA','sim','sim','nao'),(2,'beta','Beta','uuid-beta','B','n','sim','nao','nao','192.0.2.2','BB','nao','nao','nao')","INSERT INTO sis_adicional VALUES(3,'alpha-extra','alpha','nao')","INSERT INTO radacct VALUES(1,'alpha',NOW()-INTERVAL 1 HOUR,NOW(),NULL),(2,'beta',NOW()-INTERVAL 2 HOUR,NOW(),NOW())"]
            php(connect+"$d->select_db(getenv('MYSQL_TEST_SCHEMA'));foreach(json_decode("+json.dumps(json.dumps(sql))+",true)as $s){if(!$d->query($s))throw new RuntimeException($d->error);}",env)
            (native/'include/conexao.php').write_text("<?php $LOADMYSQL=new mysqli(getenv('MYSQL_TEST_HOST')?:'127.0.0.1',getenv('MYSQL_TEST_USER')?:'root',getenv('MYSQL_TEST_PASSWORD'),getenv('MYSQL_TEST_SCHEMA'));date_default_timezone_set('America/Fortaleza');\n")
            for f in ['topo.php','baixo.hhvm','menu.js.hhvm','cliente_det.hhvm']:(admin/f).write_text('')
            users={u:secrets.token_hex(16)for u in ['full','limited']}
            for user,sid in users.items():php("session_save_path("+json.dumps(str(sessions))+");session_name('mka');session_id('"+sid+"');session_start();$_SESSION=array('MKA_Logado'=>true,'MKA_Usuario'=>'"+user+"');session_write_close();",env)
            with socket.socket()as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
            router=base/'router.php';router.write_text("<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if(substr($p,-5)==='.hhvm'){require $_SERVER['DOCUMENT_ROOT'].$p;return true;}return false;")
            output=(base/'php-server.log').open('w');server=subprocess.Popen(['php','-d','session.save_path='+str(sessions),'-S','127.0.0.1:'+str(port),'-t',str(native),str(router)],stdout=output,stderr=output,env=env)
            def request(path,user='full',data=None,raw=False,form=False):
                headers={'Cookie':'mka='+users[user]}if user else {}
                if data is not None:
                    data=(urllib.parse.urlencode(data)if form else json.dumps(data)).encode();headers['Content-Type']='application/x-www-form-urlencoded'if form else 'application/json'
                req=urllib.request.Request('http://127.0.0.1:'+str(port)+'/admin/addons/radius/'+path,headers=headers,data=data)
                try:
                    with urllib.request.urlopen(req,timeout=10)as response:code,body=response.status,response.read().decode()
                except urllib.error.HTTPError as e:code,body=e.code,e.read().decode()
                return code,body if raw else (json.loads(body)if code==200 else body)
            for i in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.05)
            code,body=request('index.php',raw=True);assert code==200 and '4.5.0'in body and 'fixture-password'not in body and 'cleanSessionsButton"'not in body
            assert request('index.php',None,raw=True)[0]==403 and request('index.php','limited',raw=True)[0]==403
            code,data=request('live.php?data=1&login=alpha','limited');assert code==200 and data['current']['state']=='online'and all(r['login']=='alpha'for r in data['rows'])
            assert request('live.php?data=1&login=beta','limited')[0]==403
            code,data=request('client_status.php','limited',{'logins':['alpha','alpha-extra','beta']});assert code==200 and 'alpha'in data and 'alpha-extra'in data and 'beta'not in data
            code,pref=request('preferences.php');assert code==200 and pref['hide_disabled']
            assert request('preferences.php',data={'csrf':'bad','hide_disabled':False})[0]==403
            code,pref=request('preferences.php',data={'csrf':pref['csrf'],'hide_disabled':False});assert code==200 and pref['hide_disabled']is False
            assert request('run_script.hhvm',data={})[0]==405
            code,tool=request('connection_settings_api.php','limited');assert code==200 and not tool['can_global']
            assert request('connection_settings_api.php','limited',{'csrf':tool['csrf'],'action':'preview','selection':'all','clear':'both'},form=True)[0]==403
            assert request('connection_settings_api.php','limited',{'csrf':'bad','action':'preview','selection':'client','login':'alpha','clear':'both'},form=True)[0]==403
            code,tool=request('connection_settings_api.php');assert code==200 and tool['can_global']
            code,preview=request('connection_settings_api.php',data={'csrf':tool['csrf'],'action':'preview','selection':'client','login':'alpha','clear':'ip'},form=True);assert code==200 and preview['changed']==1
            code,done=request('connection_settings_api.php',data={'csrf':tool['csrf'],'action':'execute','preview_key':preview['preview_key'],'confirm':'1'},form=True);assert code==200 and done['changed']==1
            remaining=php(connect+"$d->select_db(getenv('MYSQL_TEST_SCHEMA'));echo $d->query('SELECT COUNT(*) n FROM radacct')->fetch_assoc()['n'];",env);assert remaining=='2'
            print('PASS standalone HTTP, native session/permissions, group isolation, passwords, preference CSRF, connection preview and read-only accounting')
        finally:
            if server is not None:server.terminate();server.wait(timeout=5)
            if output is not None:output.close()
            php(connect+"$d->query('DROP DATABASE `'.getenv('MYSQL_TEST_SCHEMA').'`');",env)
        rollback(admin,base/'backup');assert (addons/'addon.js').read_bytes()==legacy and not (addons/'radius').exists()
        print('PASS initial installation rollback restores native loader bytes')
        shellenv=dict(os.environ,MKAUTH_ROOT=str(native),MKAUTH_ADMIN=str(admin),MKAUTH_WEB_USER='root',MKAUTH_BACKUP_ROOT=str(base/'shell-backup'),RADIUS_LOG_FILE=str(log))
        subprocess.run(['sh',str(ROOT/'installers/install.sh')],env=shellenv,check=True)
        assert (addons/'radius/package_features.json').is_file()
        print('PASS actual shell installer in disposable native-layout fixture')

if __name__=='__main__':main()
