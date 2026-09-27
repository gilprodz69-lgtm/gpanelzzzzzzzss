"""PHP-FPM overrides for one content-serving domain, without changing its parent."""
import json
import re
from pathlib import Path
from runtime import atomic_write, run, wait_socket, reload_nginx
from validation import choice, integer, Rejected


def change(ops, p):
    from operations import PHP_VERSIONS
    d=ops.find('domain',p);s=ops.find('site',p,'website_id')
    if d.get('website_id')!=integer(p['website_id']) or d.get('type') not in ('alias','subdomain'):
        raise Rejected('Domain does not serve PHP for this site')
    version=choice(p.get('php_version'),PHP_VERSIONS)
    if not Path(f'/usr/sbin/php-fpm{version}').is_file():raise Rejected('Requested PHP version is not installed')
    if d.get('php')==version:return {'php_version':version}
    tenant=integer(p['tenant_id']);rid=integer(p['resource_id'])
    name=f'vpm-domain-{tenant}-{rid}'
    old_pool=Path(d['pool']) if d.get('pool') else None
    previous=old_pool.read_text() if old_pool else Path(s['pool']).read_text()
    new_pool=Path(f'/etc/php/{version}/fpm/pool.d/{name}.conf')
    if new_pool.exists():raise Rejected('Destination PHP pool already exists; reconcile first')
    socket=f'/run/php/{name}-php{version}.sock'
    pool=re.sub(r'^\[[^\]\r\n]+\]$',f'[{name}]',previous,count=1,flags=re.M)
    pool,count=re.subn(r'^listen\s*=\s*[^\r\n]+$',f'listen = {socket}',pool,count=1,flags=re.M)
    if count!=1:raise Rejected('Invalid source PHP pool')
    nginx=Path(d['path']);before=nginx.read_text()
    content,count=re.subn(r'fastcgi_pass unix:[^;]+;',f'fastcgi_pass unix:{socket};',before)
    if count!=1:raise Rejected('Domain PHP configuration is unavailable')
    try:
        atomic_write(new_pool,pool)
        run([f'/usr/sbin/php-fpm{version}','-t'])
        run(['/usr/bin/systemctl','reload',f'php{version}-fpm']);wait_socket(socket)
        atomic_write(nginx,content);run(['/usr/sbin/nginx','-t']);reload_nginx(drain=True)
        if old_pool:
            old_pool.unlink();run(['/usr/bin/systemctl','reload',f"php{d['php']}-fpm"])
        updated={**d,'php':version,'pool':str(new_pool)}
        ops.catalog.execute("UPDATE resources SET data=? WHERE tenant=? AND kind='domain' AND id=?",(json.dumps(updated),tenant,rid));ops.catalog.commit()
    except Exception:
        ops.catalog.rollback()
        if old_pool:
            atomic_write(old_pool,previous);run(['/usr/bin/systemctl','reload',f"php{d['php']}-fpm"])
            old_socket=re.search(r'^listen\s*=\s*(.+)$',previous,re.M).group(1);wait_socket(old_socket)
        atomic_write(nginx,before);run(['/usr/sbin/nginx','-t']);reload_nginx(drain=True)
        new_pool.unlink(missing_ok=True);run(['/usr/bin/systemctl','reload',f'php{version}-fpm'])
        raise
    return {'php_version':version}


def remove(ops,p):
    d=ops.find('domain',p);nginx=Path(d['path']);before=nginx.read_text()
    pool=Path(d['pool']) if d.get('pool') else None
    pool_before=pool.read_text() if pool else None
    try:
        nginx.unlink();run(['/usr/sbin/nginx','-t']);reload_nginx(drain=True)
        if pool:
            pool.unlink();run(['/usr/bin/systemctl','reload',f"php{d['php']}-fpm"])
        ops.forget('domain',p)
    except Exception:
        if pool:
            atomic_write(pool,pool_before);run(['/usr/bin/systemctl','reload',f"php{d['php']}-fpm"])
        atomic_write(nginx,before);run(['/usr/sbin/nginx','-t']);reload_nginx()
        raise
    return {'message':'Domain removed; files preserved'}
