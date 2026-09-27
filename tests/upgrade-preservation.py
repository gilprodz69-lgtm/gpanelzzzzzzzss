"""Disposable CI host only: prove update preserves an existing site and both cron formats."""
import base64
import hashlib
import json
import os
from pathlib import Path
import sys
import ssl

assert os.environ.get('GITHUB_ACTIONS') == 'true' and os.geteuid() == 0
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'agent'))
from operations import Operations

ops = Operations()
state = Path('/tmp/vpm-upgrade-preservation.json')
p = {'tenant_id':998,'owner_id':998,'resource_id':998,'domain':'preserve.example.invalid','php_version':'8.3'}
legacy = {**p,'website_id':998,'resource_id':9981,'schedule':'0 0 1 1 *','path':'cron.php'}
custom = {**p,'website_id':998,'resource_id':9982,'schedule':'0 0 1 1 *','command_type':'custom','command':"printf '%s' 'preserved task'"}

def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()

def available():
    # Use localhost with a Host header rather than resolving fixture DNS.
    import http.client
    conn=http.client.HTTPSConnection('127.0.0.1',context=ssl._create_unverified_context(),timeout=10)
    conn.request('GET','/',headers={'Host':p['domain']})
    response=conn.getresponse();content=response.read();conn.close()
    assert response.status==200 and b'preserve-live-site' in content

if sys.argv[1]=='prepare':
    ops.create_site(p)
    site=ops.find('site',p)
    for name,content in [('index.html',b'preserve-live-site'),('cron.php',b'<?php echo "task";')]:
        ops.files({**p,'website_id':998,'action':'write','path':name,'content':base64.b64encode(content).decode()})
    ops.create_cron(legacy);ops.create_cron(custom)
    # Simulate legacy inventory, which has only the crontab path.
    old=ops.find('cron',legacy)
    ops.catalog.execute("UPDATE resources SET data=? WHERE tenant=998 AND kind='cron' AND id=9981",(json.dumps({'path':old['path']}),));ops.catalog.commit()
    info=ops.find('cron',custom)
    paths=[site['pool'],site['nginx'],str(Path(site['public'])/'index.html'),str(Path(site['public'])/'cron.php'),old['path'],info['path'],info['wrapper']]
    state.write_text(json.dumps({path:digest(path) for path in paths}));state.chmod(0o600)
    available()
    print('PASS prepared existing site, legacy PHP cron and custom cron before update')
elif sys.argv[1]=='verify':
    for path,expected in json.loads(state.read_text()).items():assert digest(path)==expected,path
    available()
    ops.delete_cron(legacy);ops.delete_cron(custom);ops.delete_site(p);state.unlink()
    print('PASS update preserved site content, PHP pool, Nginx config, legacy cron and custom wrapper exactly')
else:raise AssertionError('Unknown test mode')
