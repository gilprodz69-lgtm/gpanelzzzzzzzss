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
from operations import Operations, PHP_VERSIONS
from runtime import run
from validation import Rejected
ops=Operations()
cron_fixtures=[]
def fetch(host, path='/'):
    context=ssl.create_default_context(cafile=f'/etc/vpsmanager/tls/{host}/fullchain.pem')
    with socket.create_connection(('127.0.0.1',443),timeout=10) as raw:
        with context.wrap_socket(raw,server_hostname=host) as conn:
            conn.sendall(f'GET {path} HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\n\r\n'.encode())
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
        token=ops.files({**files,'action':'upload_begin','path':'uploaded.txt','size':5})['id']
        ops.files({**files,'action':'upload_chunk','id':token,'offset':0,'content':'aGVsbG8='})
        ops.files({**files,'action':'upload_finish','id':token})
        assert fetch(p['domain'],'/uploaded.txt').endswith(b'hello')
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
            ops.change_php({**p,'php_version':'8.4'})
            assert fetch(p['domain'],'/version.php').endswith(b'8.4')
            assert fetch('alias.example.invalid','/version.php').endswith(b'8.4')
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
