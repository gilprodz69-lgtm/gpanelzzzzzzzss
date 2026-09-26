"""Destructive test fixtures only on the disposable GitHub Actions VM."""
import base64
import os
from pathlib import Path
import socket
import ssl
import http.client
import sys
import time
import json
import pwd
import subprocess
from unittest.mock import patch
if os.getenv('GITHUB_ACTIONS') != 'true' or os.geteuid() != 0:
    raise SystemExit('Run only on the disposable GitHub Actions runner as root')
sys.path.insert(0, str(Path(__file__).resolve().parents[1]/'agent'))
from operations import Operations, PHP_VERSIONS, repair_site_runtime
from runtime import run
from validation import Rejected
# Match the deployed agent service, not the CI shell's more permissive mask.
os.umask(0o077)
ops=Operations()
cron_fixtures=[]
def fetch(host, path='/', cookie=''):
    context=ssl.create_default_context(cafile=f'/etc/vpsmanager/tls/{host}/fullchain.pem')
    with socket.create_connection(('127.0.0.1',443),timeout=10) as raw:
        with context.wrap_socket(raw,server_hostname=host) as conn:
            conn.sendall(f'GET {path} HTTP/1.1\r\nHost: {host}\r\nCookie: {cookie}\r\nConnection: close\r\n\r\n'.encode())
            response=http.client.HTTPResponse(conn)
            response.begin()
            result=response.read()
            assert response.status==200,(response.status,result[:300])
    return result

with patch('tls.issue',side_effect=RuntimeError('ACME is exercised separately against public DNS')):
    for index,version in enumerate(PHP_VERSIONS,1):
        p={'tenant_id':999,'owner_id':999,'resource_id':index,'domain':f'php{index}.example.invalid','php_version':version,'email':'ci@example.invalid'}
        result=ops.create_site(p);assert result['https'] and not result['trusted']
        files={**p,'website_id':index}
        ops.files({**files,'action':'write','path':'version.php','content':base64.b64encode(b'<?php echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;').decode()})
        assert fetch(p['domain'],'/version.php').endswith(version.encode())
        session_code=b'<?php session_start(); $_SESSION["count"]=($_SESSION["count"]??0)+1; echo $_SESSION["count"].":".(is_writable(ini_get("upload_tmp_dir"))?"ok":"bad");'
        ops.files({**files,'action':'write','path':'session-probe.php','content':base64.b64encode(session_code).decode()})
        assert fetch(p['domain'],'/session-probe.php',f'PHPSESSID=citest{index}').endswith(b'1:ok')
        assert fetch(p['domain'],'/session-probe.php',f'PHPSESSID=citest{index}').endswith(b'2:ok')
        assert '.php-runtime/sessions' in Path(ops.find('site',p)['pool']).read_text()
        if index==1:
            site=ops.find('site',p);pool=Path(site['pool']);original_pool=pool.read_text()
            ops.files({**files,'action':'mkdir','path':'.tmp'})
            ops.files({**files,'action':'write','path':'.tmp/sess_legacy','content':base64.b64encode(b'count|i:42;').decode()})
            pool.write_text(original_pool.replace(str(Path(site['home'])/'.php-runtime/sessions'),site['public']+'/.tmp').replace(str(Path(site['home'])/'.php-runtime/tmp'),site['public']+'/.tmp'))
            assert repair_site_runtime(site)
            assert (Path(site['home'])/'.php-runtime/sessions/sess_legacy').read_text()=='count|i:42;'
            assert not repair_site_runtime(site)
            ops.files({**files,'action':'delete_tree','path':'.tmp'})
            assert fetch(p['domain'],'/session-probe.php','PHPSESSID=citest1').endswith(b'3:ok')
            print('PASS private PHP sessions persist after public temp deletion and legacy migration',flush=True)
        token=ops.files({**files,'action':'upload_begin','path':'uploaded.txt','size':5})['id']
        ops.files({**files,'action':'upload_chunk','id':token,'offset':0,'content':'aGVsbG8='})
        ops.files({**files,'action':'upload_finish','id':token})
        assert fetch(p['domain'],'/uploaded.txt').endswith(b'hello')
        ops.files({**files,'action':'mkdir','path':'web-folder'})
        ops.files({**files,'action':'write','path':'web-folder/index.html','content':base64.b64encode(b'readable-by-nginx').decode()})
        assert fetch(p['domain'],'/web-folder/').endswith(b'readable-by-nginx')
        if index==1:
            import tempfile,zipfile,hashlib
            with tempfile.TemporaryFile() as source:
                with zipfile.ZipFile(source,'w',zipfile.ZIP_STORED) as archive:
                    with archive.open('payload.bin','w') as data:
                        for _ in range(101):data.write(b'a'*1048576)
                size=source.tell();source.seek(0);expected=hashlib.file_digest(source,'sha256').hexdigest();source.seek(0)
                transfer=ops.files({**files,'action':'upload_begin','path':'large.zip','size':size})['id'];offset=0
                while chunk:=source.read(1048576):
                    ops.files({**files,'action':'upload_chunk','id':transfer,'offset':offset,'content':base64.b64encode(chunk).decode()});offset+=len(chunk)
                ops.files({**files,'action':'upload_finish','id':transfer})
            site=ops.find('site',files,'website_id');zip_path=Path(site['public'])/'large.zip'
            with zip_path.open('rb') as data:assert hashlib.file_digest(data,'sha256').hexdigest()==expected
            ops.files({**files,'action':'unzip','path':'large.zip','target':'large-extracted'})
            assert (Path(site['public'])/'large-extracted/payload.bin').stat().st_size==101*1048576
            # Nginx must retain read access after the atomic upload publication.
            import grp
            assert zip_path.stat().st_gid==grp.getgrnam('www-data').gr_gid
            for path in ['large.zip','large-extracted']:
                ops.files({**files,'action':'trash','path':path})
            for item in ops.files({**files,'action':'trash_list'})['data']:
                ops.files({**files,'action':'purge','id':item['id']})
            print('PASS 101 MiB ZIP upload and extraction under isolated site user, integrity and web group',flush=True)
            ops.files({**files,'action':'mkdir','path':'batch'})
            ops.files({**files,'action':'copy_many','paths':['uploaded.txt','web-folder'],'target':'batch'})
            assert fetch(p['domain'],'/batch/web-folder/').endswith(b'readable-by-nginx')
            ops.files({**files,'action':'mkdir','path':'moved'})
            ops.files({**files,'action':'move_many','paths':['batch/uploaded.txt','batch/web-folder'],'target':'moved'})
            ops.files({**files,'action':'zip','path':'moved','target':'direct.zip'})
            ops.files({**files,'action':'delete_tree','path':'moved'})
            ops.files({**files,'action':'unzip','path':'direct.zip','target':''})
            assert fetch(p['domain'],'/moved/web-folder/').endswith(b'readable-by-nginx')
            ops.files({**files,'action':'delete_tree','path':'moved'})
            print('PASS bulk copy/move, direct ZIP extraction and permanent folder deletion',flush=True)
        ops.files({**files,'action':'trash','path':'uploaded.txt'})
        item=ops.files({**files,'action':'trash_list'})['data'][0]
        ops.files({**files,'action':'restore','id':item['id']})
        assert fetch(p['domain'],'/uploaded.txt').endswith(b'hello')
        ops.create_backup({**files,'resource_id':100+index})
        ops.files({**files,'action':'write','path':'version.php','content':'Y2hhbmdlZA=='})
        ops.restore_backup({**files,'resource_id':100+index})
        assert fetch(p['domain'],'/version.php').endswith(version.encode())
        ops.delete_backup({**files,'resource_id':100+index})
        cron={**files,'resource_id':200+index,'schedule':'* * * * *','path':'cron-check.php'}
        try:
            ops.create_cron(cron)
            raise AssertionError('Cron accepted a missing script')
        except Rejected:
            pass
        content=b'<?php file_put_contents(__DIR__."/cron-result.json", json_encode([PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION, posix_geteuid()]));'
        ops.files({**files,'action':'write','path':'cron-check.php','content':base64.b64encode(content).decode()})
        ops.create_cron(cron)
        site=ops.find('site',files,'website_id')
        cron_fixtures.append((p,cron,site,'8.4' if index==1 else version))
        if index==1:
            ops.create_domain({**files,'resource_id':50,'alias':'alias.example.invalid','type':'alias'})
            assert fetch('alias.example.invalid','/version.php').endswith(version.encode())
            sub={**files,'resource_id':51,'alias':'blog.'+p['domain'],'type':'subdomain','parent_domain':p['domain']}
            ops.create_domain(sub)
            assert b'Subdominio ativo' in fetch(sub['alias'])
            ops.files({**files,'action':'write','path':sub['alias']+'/version.php','content':base64.b64encode(b'<?php echo "subdomain:".PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;').decode()})
            assert fetch(sub['alias'],'/version.php').endswith(b'subdomain:'+version.encode())
            assert not fetch(p['domain'],'/version.php').endswith(b'subdomain:'+version.encode())
            original=ops.find('site',p);before=Path(original['nginx']).read_text()
            real_run=run;injected=[False]
            def fail_validation(args,**kwargs):
                if args==['/usr/sbin/nginx','-t'] and not injected[0]:
                    injected[0]=True;raise RuntimeError('Injected Nginx validation failure')
                return real_run(args,**kwargs)
            for failure in ['nginx','php']:
                guard=patch('operations.run',side_effect=fail_validation) if failure=='nginx' else patch.object(ops,'change_php',side_effect=RuntimeError('Injected PHP failure'))
                with guard:
                    try:ops.update_site({**p,'previous_domain':p['domain'],'domain':'rollback.example.invalid','php_version':'8.4'})
                    except RuntimeError:pass
                    else:raise AssertionError('Expected injected edit failure')
                assert ops.find('site',p)==original
                assert Path(original['nginx']).read_text()==before
                assert fetch(p['domain'],'/version.php').endswith(version.encode())
            try:ops.update_site({**p,'previous_domain':p['domain'],'domain':'alias.example.invalid','php_version':'8.4'})
            except Rejected:pass
            else:raise AssertionError('Duplicate hostname accepted')
            # Keep an old Nginx worker alive while switching PHP. It must finish
            # before the previous pool disappears.
            import concurrent.futures
            ops.files({**files,'action':'write','path':'slow.php','content':base64.b64encode(b'<?php file_put_contents(__DIR__."/slow-started", "yes"); sleep(3); echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;').decode()})
            with concurrent.futures.ThreadPoolExecutor() as executor:
                pending=executor.submit(fetch,p['domain'],'/slow.php')
                marker=Path(original['public'])/'slow-started';deadline=time.monotonic()+10
                while not marker.exists() and time.monotonic()<deadline:time.sleep(.05)
                assert marker.exists()
                ops.change_php({**p,'php_version':'8.4'})
                assert pending.done() and pending.result().endswith(b'7.4')
            assert fetch(p['domain'],'/version.php').endswith(b'8.4')
            ops.update_site({**p,'previous_domain':p['domain'],'domain':'edited.example.invalid','php_version':'8.4'})
            p['domain']='edited.example.invalid'
            # Serve a public-IP hostname only through the runner's loopback connection.
            ops.update_site({**p,'previous_domain':p['domain'],'domain':'93.184.216.34','php_version':'8.4'})
            assert fetch('93.184.216.34','/version.php').endswith(b'8.4')
            ops.update_site({**p,'previous_domain':'93.184.216.34','php_version':'8.4'})
            assert ops.find('site',p)['public']==original['public']
            print('PASS site editing, IP to domain, duplicate rejection, Nginx/PHP rollback and preserved files',flush=True)
            assert fetch(p['domain'],'/version.php').endswith(b'8.4')
            assert fetch('alias.example.invalid','/version.php').endswith(b'8.4')
            assert fetch(sub['alias'],'/version.php').endswith(b'subdomain:8.4')
            old_sub=sub['alias']
            ops.update_domain({**sub,'previous_domain':old_sub,'alias':'news.'+p['domain']})
            assert fetch('news.'+p['domain'],'/version.php').endswith(b'subdomain:8.4')
            assert (Path(site['public'])/old_sub/'version.php').is_file()
            print('PASS domain rename retains document root and PHP content',flush=True)
            ops.delete_domain(sub)
            assert (Path(site['public'])/sub['alias']/'version.php').is_file()
            assert not Path('/etc/nginx/conf.d/vpm-domain-999-51.conf').exists()
            print('PASS subdomain separate content, HTTPS, PHP inheritance and preserved files on removal',flush=True)
            ops.delete_domain({**files,'resource_id':50})
        print('PASS PHP',version,'HTTPS, files, upload, trash and backup restoration',flush=True)

# Exercise the actual daemon and all PHP runtimes concurrently (at most one minute).
run(['/usr/bin/systemctl','is-active','cron'])
deadline=time.monotonic()+85
while True:
    pending=[]
    for p,cron,site,version in cron_fixtures:
        marker=Path(site['public'])/'cron-result.json'
        try:
            result=json.loads(marker.read_text())
        except (FileNotFoundError,json.JSONDecodeError):
            pending.append(p['domain']);continue
        if result!=[version,pwd.getpwnam(site['username']).pw_uid]:
            pending.append(p['domain'])
    if not pending:break
    assert time.monotonic()<deadline,('Cron did not execute with the correct PHP/user',pending)
    time.sleep(2)
for p,cron,site,version in cron_fixtures:
    path=Path(ops.find('cron',cron)['path'])
    assert path.stat().st_mode & 0o777==0o644
    ops.delete_cron(cron)
    assert not path.exists()
    ops.delete_site(p)
print('PASS real scheduled PHP execution, site user isolation, PHP change and cron removal',flush=True)

db={'tenant_id':999,'owner_id':999,'resource_id':999,'name':'u999_integration','username':'u999_separate','password':'IntegrationOnly_73622'}
ops.create_database(db)
sql="SHOW GRANTS FOR 'u999_separate'@'localhost';"
grants=run(['/usr/bin/mariadb','--protocol=socket','--batch','--raw'],input_text=sql)
assert 'u999\\_integration' in grants,grants
def db_login(secret,query):
    return subprocess.run(['/usr/bin/mariadb','--protocol=socket','--batch','--skip-column-names','--user=u999_separate',db['name']],input=query,text=True,capture_output=True,env={**os.environ,'MYSQL_PWD':secret},timeout=10)
assert db_login(db['password'],'CREATE TABLE sample(id INT); INSERT INTO sample VALUES(7);').returncode==0
assert db_login(db['password'],'SELECT * FROM mysql.user;').returncode!=0
info=ops.database_info(db);assert info['tables']==1 and info['charset']=='utf8mb4' and info['size_bytes']>=0
try:
    ops.create_database({**db,'resource_id':998,'name':'u999_collision'})
    raise AssertionError('Duplicate user accepted')
except RuntimeError:pass
assert run(['/usr/bin/mariadb','--batch','--skip-column-names'],input_text="SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='u999_collision';").strip()=='0'
ops.database_password({**db,'password':'AnotherTestOnly_66373'})
assert db_login(db['password'],'SELECT 1;').returncode!=0
assert db_login('AnotherTestOnly_66373','SELECT * FROM sample;').stdout.strip()=='7'
ops.delete_database(db)
assert db_login('AnotherTestOnly_66373','SELECT 1;').returncode!=0
# An inventory created by previous versions has no username field.
legacy={**db,'resource_id':997,'name':'u999_legacy'};legacy.pop('username')
ops.create_database(legacy)
ops.catalog.execute("UPDATE resources SET data=? WHERE tenant=999 AND kind='database' AND id=997",(json.dumps({'name':'u999_legacy'}),));ops.catalog.commit()
ops.database_password({**legacy,'password':'LegacyTestOnly_73622'});ops.delete_database(legacy)
print('PASS independent database/user, scoped login, statistics, collision rollback, password change, legacy compatibility and removal',flush=True)
metrics=ops.metrics({});assert metrics['memory_total']>0 and metrics['disk_total']>0
print('PASS live host metrics',flush=True)
