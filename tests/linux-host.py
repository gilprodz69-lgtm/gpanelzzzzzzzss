"""Destructive test fixtures only on the disposable GitHub Actions VM."""
import base64
import os
from pathlib import Path
import socket
import ssl
import sys
from unittest.mock import patch
if os.getenv('GITHUB_ACTIONS') != 'true' or os.geteuid() != 0:
    raise SystemExit('Run only on the disposable GitHub Actions runner as root')
sys.path.insert(0, str(Path(__file__).resolve().parents[1]/'agent'))
from operations import Operations, PHP_VERSIONS
from runtime import run
ops=Operations()
def fetch(host, path='/'):
    context=ssl.create_default_context(cafile=f'/etc/vpsmanager/tls/{host}/fullchain.pem')
    with socket.create_connection(('127.0.0.1',443),timeout=10) as raw:
        with context.wrap_socket(raw,server_hostname=host) as conn:
            conn.sendall(f'GET {path} HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\n\r\n'.encode())
            result=b''
            while data:=conn.recv(65536):result+=data
    assert b'200 OK' in result,result[:300]
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
        if index==1:
            ops.create_domain({**files,'resource_id':50,'alias':'alias.example.invalid','type':'alias'})
            assert fetch('alias.example.invalid','/version.php').endswith(version.encode())
            ops.change_php({**p,'php_version':'8.4'})
            assert fetch(p['domain'],'/version.php').endswith(b'8.4')
            assert fetch('alias.example.invalid','/version.php').endswith(b'8.4')
            ops.delete_domain({**files,'resource_id':50})
        ops.delete_site(p)
        print('PASS PHP',version,'HTTPS, files, upload, trash and backup restoration',flush=True)

db={'tenant_id':999,'owner_id':999,'resource_id':999,'name':'u999_integration','password':'IntegrationOnly_73622'}
ops.create_database(db)
sql="SHOW GRANTS FOR 'u999_integration'@'localhost';"
grants=run(['/usr/bin/mariadb','--protocol=socket','--batch'],input_text=sql)
assert 'u999\\_integration' in grants,grants
ops.database_password({**db,'password':'AnotherTestOnly_66373'})
ops.delete_database(db)
print('PASS database creation, scoped grant, password change and removal',flush=True)
metrics=ops.metrics({});assert metrics['memory_total']>0 and metrics['disk_total']>0
print('PASS live host metrics',flush=True)
