"""Original Linux implementation. All executable names and templates are controlled here."""
import json
import os
import re
import shutil
import sqlite3
import tarfile
import time
from pathlib import Path
from validation import integer, domain, identifier, choice, password, inside, cron, source, Rejected
from runtime import run, atomic_write, unprivileged, wait_socket, reload_nginx
import files
import tls
PHP_VERSIONS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4']


class Operations:
    SERVICES = ['nginx', *[f'php{v}-fpm' for v in PHP_VERSIONS], 'mariadb', 'redis-server', 'docker', 'cron']
    def __init__(self, state='/var/lib/vpsmanager-agent', root='/srv/vpsmanager'):
        self.state = Path(state)
        self.root = Path(root)
        self.state.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.catalog = sqlite3.connect(self.state / 'inventory.sqlite')
        self.catalog.row_factory = sqlite3.Row
        self.catalog.execute('CREATE TABLE IF NOT EXISTS resources (tenant INTEGER, kind TEXT, id INTEGER, owner INTEGER, data TEXT, PRIMARY KEY(tenant,kind,id))')

    def save(self, kind, p, data):
        self.catalog.execute('INSERT INTO resources VALUES (?,?,?,?,?)', (integer(p['tenant_id']), kind, integer(p['resource_id']), integer(p['owner_id']), json.dumps(data)))
        self.catalog.commit()

    def find(self, kind, p, key='resource_id'):
        row = self.catalog.execute('SELECT owner,data FROM resources WHERE tenant=? AND kind=? AND id=?', (integer(p['tenant_id']), kind, integer(p[key]))).fetchone()
        if not row or row['owner'] != integer(p['owner_id']):
            raise Rejected('Resource is not registered for this account')
        return json.loads(row['data'])

    def forget(self, kind, p):
        self.catalog.execute('DELETE FROM resources WHERE tenant=? AND kind=? AND id=?', (p['tenant_id'], kind, p['resource_id']))
        self.catalog.commit()

    def execute(self, operation, p):
        allowed = ['metrics', 'services', 'service_action', 'files', 'restore_backup', 'database_password', 'change_php']
        kinds = ['site', 'domain', 'database', 'ssl', 'sftp', 'backup', 'cron', 'firewall', 'container']
        allowed += [f'{verb}_{kind}' for verb in ['create', 'delete'] for kind in kinds]
        if operation not in allowed:
            raise Rejected('Operation is not allowed')
        return getattr(self, operation)(p)

    def metrics(self, p):
        def cpu_sample():
            values = [int(n) for n in Path('/proc/stat').read_text().splitlines()[0].split()[1:]]
            return sum(values[:8]), values[3] + values[4]
        first, idle = cpu_sample()
        time.sleep(0.5)
        second, idle2 = cpu_sample()
        mem = {line.split(':')[0]: int(line.split()[1]) for line in Path('/proc/meminfo').read_text().splitlines()}
        disk = shutil.disk_usage(self.root if self.root.exists() else '/')
        rx = tx = 0
        for line in Path('/proc/net/dev').read_text().splitlines()[2:]:
            name, values = line.split(':')
            if name.strip() != 'lo':
                numbers = values.split(); rx += int(numbers[0]); tx += int(numbers[8])
        return {'cpu': round(100 * (1 - (idle2-idle)/max(1,second-first)), 2),
                'ram': round(100 * (1-mem['MemAvailable']/mem['MemTotal']), 2),
                'disk': round(100 * disk.used/disk.total, 2), 'load_avg': os.getloadavg()[0],
                'uptime': int(float(Path('/proc/uptime').read_text().split()[0])), 'network_rx': rx, 'network_tx': tx,
                'cpu_count': os.cpu_count() or 1, 'memory_total': mem['MemTotal'] * 1024,
                'memory_used': (mem['MemTotal'] - mem['MemAvailable']) * 1024,
                'disk_total': disk.total, 'disk_used': disk.used}

    def services(self, p):
        result = []
        for service in self.SERVICES:
            try:
                status = run(['/usr/bin/systemctl', 'show', service, '--property=ActiveState', '--value']).strip()
            except RuntimeError:
                status = 'unavailable'
            result.append({'name': service, 'status': status})
        return {'data': result}

    def service_action(self, p):
        service = choice(p.get('service'), self.SERVICES)
        action = choice(p.get('action'), ['start', 'stop', 'restart'])
        if service == 'nginx' and action != 'stop':
            run(['/usr/sbin/nginx', '-t'])
        run(['/usr/bin/systemctl', action, service])
        return {'message': 'Service operation completed'}

    def create_site(self, p):
        import pwd
        import grp
        host = tls.host(p.get('domain'))
        if host == os.getenv('VPM_PANEL_HOST') and not host.replace('.', '').isdigit():
            raise Rejected('This hostname is reserved for the control panel')
        version = choice(p.get('php_version'), PHP_VERSIONS)
        tenant = integer(p['tenant_id']); rid = integer(p['resource_id'])
        username = f'vpm{tenant}s{rid}'
        identifier(username)
        binary = f'/usr/sbin/php-fpm{version}'
        if not Path(binary).is_file():
            raise Rejected('Requested PHP version is not installed')
        if self.catalog.execute("SELECT 1 FROM resources WHERE kind IN ('site','domain') AND json_extract(data,'$.domain')=?", (host,)).fetchone():
            raise Rejected('Domain already exists on this server')
        home = self.root / f't{tenant}' / f's{rid}'
        nginx = Path(f'/etc/nginx/conf.d/vpm-{tenant}-{rid}.conf')
        pool = Path(f'/etc/php/{version}/fpm/pool.d/vpm-{tenant}-{rid}.conf')
        if home.exists() or nginx.exists() or pool.exists():
            raise Rejected('Existing provisioning artifacts require operator reconciliation')
        home.parent.mkdir(parents=True, exist_ok=True, mode=0o711)
        home.parent.chmod(0o711)
        home.mkdir(mode=0o750)
        run(['/usr/sbin/useradd', '--system', '--user-group', '--home-dir', str(home), '--shell', '/usr/sbin/nologin', username])
        account = pwd.getpwnam(username); webgroup = grp.getgrnam('www-data').gr_gid
        os.chown(home, 0, webgroup); home.chmod(0o751)
        public = home / 'public_html'; public.mkdir(mode=0o750); os.chown(public, account.pw_uid, webgroup); public.chmod(0o2750)
        (public / 'index.html').write_text('<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Site ativo</title><h1>Seu site está pronto.</h1></html>')
        os.chown(public / 'index.html', account.pw_uid, webgroup)
        (public / 'index.html').chmod(0o640)
        socket = f'/run/php/vpm-{tenant}-{rid}.sock'
        pool_text = f'''[{username}]
user = {username}
group = {username}
listen = {socket}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
security.limit_extensions = .php
php_admin_value[open_basedir] = {public}:/usr/share/php
php_admin_value[upload_tmp_dir] = {public}/.tmp
php_admin_value[session.save_path] = {public}/.tmp
php_admin_value[memory_limit] = 128M
php_admin_value[upload_max_filesize] = 16M
php_admin_value[post_max_size] = 16M
php_admin_value[max_execution_time] = 60
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
'''
        temp = public / '.tmp'; temp.mkdir(mode=0o700); os.chown(temp, account.pw_uid, account.pw_gid)
        nginx_text = f'''server {{
    listen 80;
    server_name {host};
    root {public};
    index index.php index.html;
    disable_symlinks on;
    client_max_body_size 16m;
    access_log /var/log/nginx/vpm-{tenant}-{rid}.access.log;
    error_log /var/log/nginx/vpm-{tenant}-{rid}.error.log;
    location / {{ try_files $uri $uri/ /index.php?$query_string; }}
    location ~ /\\.(?!well-known/) {{ deny all; }}
    location ~ \\.php$ {{
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:{socket};
    }}
}}
'''
        cert, key = tls.local_certificate(host)
        nginx_text = tls.secure_config(nginx_text, host, cert, key)
        atomic_write(pool, pool_text)
        atomic_write(nginx, nginx_text)
        try:
            run([binary, '-t']); run(['/usr/sbin/nginx', '-t'])
        except Exception:
            pool.unlink(missing_ok=True); nginx.unlink(missing_ok=True)
            raise
        run(['/usr/bin/systemctl', 'reload', f'php{version}-fpm'])
        wait_socket(socket)
        reload_nginx()
        self.save('site', p, {'domain': host, 'home': str(home), 'public': str(public), 'username': username, 'php': version, 'nginx': str(nginx), 'pool': str(pool)})
        result = {'domain': host, 'path': str(public), 'https': True, 'trusted': False}
        try:
            result.update(tls.upgrade_certificate(nginx, host, p.get('email', os.getenv('VPM_ACME_EMAIL', ''))))
        except Exception:
            result['message'] = 'HTTPS ativo com certificado local. Valide DNS/porta 80 e solicite certificado público no módulo SSL.'
        return result

    def delete_site(self, p):
        s = self.find('site', p)
        # Preserve web files for recovery; delete only agent-owned service configuration.
        Path(s['nginx']).unlink(missing_ok=True); Path(s['pool']).unlink(missing_ok=True)
        run(['/usr/sbin/nginx', '-t']); reload_nginx()
        run(['/usr/bin/systemctl', 'reload', f"php{s['php']}-fpm"])
        run(['/usr/sbin/usermod', '--lock', s['username']])
        self.forget('site', p)
        return {'message': 'Site disabled; data retained for operator recovery'}

    def create_domain(self, p):
        s = self.find('site', p, 'website_id'); host = domain(p['alias'])
        kind = choice(p['type'], ['alias', 'redirect', 'parked'])
        if self.catalog.execute("SELECT 1 FROM resources WHERE kind IN ('site','domain') AND json_extract(data,'$.domain')=?", (host,)).fetchone():
            raise Rejected('Domain already in use')
        path = Path(f"/etc/nginx/conf.d/vpm-domain-{integer(p['tenant_id'])}-{integer(p['resource_id'])}.conf")
        if kind == 'redirect':
            content = f"server {{ listen 80; server_name {host}; return 301 https://{domain(p['target'])}$request_uri; }}\n"
        elif kind == 'parked':
            content = f"server {{ listen 80; server_name {host}; return 204; }}\n"
        else:
            content = Path(s['nginx']).read_text()
            if 'listen 443' in content:
                content = content[content.index('server {', content.index('server {') + 1):]
                content = re.sub(r'    ssl_(?:certificate|certificate_key|protocols) [^;]+;\n', '', content).replace('listen 443 ssl;', 'listen 80;')
            content = content.replace('server_name ' + s['domain'] + ';', 'server_name ' + host + ';')
        cert, key = tls.local_certificate(host)
        content = tls.secure_config(content, host, cert, key)
        atomic_write(path, content)
        try:
            run(['/usr/sbin/nginx', '-t']); reload_nginx()
        except Exception:
            path.unlink(missing_ok=True); raise
        self.save('domain', p, {'domain': host, 'path': str(path)})
        result = {'domain': host, 'https': True, 'trusted': False}
        try:
            result.update(tls.upgrade_certificate(path, host, os.getenv('VPM_ACME_EMAIL', '')))
        except Exception:
            result['message'] = 'Certificado local; validação pública pendente.'
        return result

    def delete_domain(self, p):
        d = self.find('domain', p); Path(d['path']).unlink(missing_ok=True)
        run(['/usr/sbin/nginx', '-t']); reload_nginx(); self.forget('domain', p)
        return {'message': 'Domain removed'}

    def create_database(self, p):
        name = identifier(p['name']); secret = password(p['password'])
        if not name.startswith(f"u{integer(p['owner_id'])}_"):
            raise Rejected('Database prefix mismatch')
        # MariaDB mysql_native_password hash avoids passing cleartext in command lines/SQL literals.
        import hashlib
        digest = '*' + hashlib.sha1(hashlib.sha1(secret.encode()).digest()).hexdigest().upper()
        grant_name = name.replace('_', '\\_')
        sql = f"CREATE DATABASE `{name}` CHARACTER SET utf8mb4; CREATE USER '{name}'@'localhost' IDENTIFIED BY PASSWORD '{digest}'; GRANT ALL PRIVILEGES ON `{grant_name}`.* TO '{name}'@'localhost';"
        run(['/usr/bin/mariadb', '--protocol=socket', '--batch'], input_text=sql)
        self.save('database', p, {'name': name})
        return {'name': name, 'username': name, 'host': 'localhost'}

    def delete_database(self, p):
        d = self.find('database', p); name = identifier(d['name'])
        run(['/usr/bin/mariadb', '--protocol=socket', '--batch'], input_text=f"DROP DATABASE IF EXISTS `{name}`; DROP USER IF EXISTS '{name}'@'localhost';")
        self.forget('database', p); return {'message': 'Database removed'}

    def database_password(self, p):
        import hashlib
        d = self.find('database', p)
        name = identifier(d['name']); secret = password(p['password'])
        digest = '*' + hashlib.sha1(hashlib.sha1(secret.encode()).digest()).hexdigest().upper()
        run(['/usr/bin/mariadb', '--protocol=socket', '--batch'], input_text=f"SET PASSWORD FOR '{name}'@'localhost' = '{digest}';")
        return {'message': 'Password updated'}

    def change_php(self, p):
        s = self.find('site', p)
        version = choice(p.get('php_version'), PHP_VERSIONS)
        if version == s['php']:
            return {'php_version': version}
        if not Path(f'/usr/sbin/php-fpm{version}').is_file():
            raise Rejected('Requested PHP version is not installed')
        old_pool = Path(s['pool']); previous = old_pool.read_text()
        new_pool = Path(f'/etc/php/{version}/fpm/pool.d') / old_pool.name
        if new_pool.exists():
            raise Rejected('Destination PHP pool already exists')
        old_socket = re.search(r'^listen = (.+)$', previous, re.M).group(1)
        new_socket = f"/run/php/vpm-{integer(p['tenant_id'])}-{integer(p['resource_id'])}-php{version}.sock"
        configs = {}
        for config in Path('/etc/nginx/conf.d').glob('vpm-*.conf'):
            text = config.read_text()
            if f'fastcgi_pass unix:{old_socket};' in text:
                configs[config] = text
        atomic_write(new_pool, previous.replace('listen = ' + old_socket, 'listen = ' + new_socket))
        try:
            run([f'/usr/sbin/php-fpm{version}', '-t'])
            run(['/usr/bin/systemctl', 'reload', f'php{version}-fpm'])
            wait_socket(new_socket)
            for config, text in configs.items():
                atomic_write(config, text.replace(f'fastcgi_pass unix:{old_socket};', f'fastcgi_pass unix:{new_socket};'))
            run(['/usr/sbin/nginx', '-t']); reload_nginx()
            old_pool.unlink()
            run(['/usr/bin/systemctl', 'reload', f"php{s['php']}-fpm"])
        except Exception:
            new_pool.unlink(missing_ok=True)
            atomic_write(old_pool, previous)
            for config, text in configs.items():
                atomic_write(config, text)
            run(['/usr/bin/systemctl', 'reload', f'php{version}-fpm'])
            run(['/usr/bin/systemctl', 'reload', f"php{s['php']}-fpm"])
            run(['/usr/sbin/nginx', '-t']); reload_nginx()
            raise
        # Keep existing cron commands in sync with this site's PHP runtime.
        for row in self.catalog.execute("SELECT data FROM resources WHERE tenant=? AND kind='cron'", (p['tenant_id'],)).fetchall():
            cron_path = Path(json.loads(row['data'])['path'])
            content = cron_path.read_text()
            marker = f" {s['username']} /usr/bin/php{s['php']} "
            if marker in content:
                atomic_write(cron_path, content.replace(marker, f" {s['username']} /usr/bin/php{version} "), 0o644)
        s.update(php=version, pool=str(new_pool))
        self.catalog.execute("UPDATE resources SET data=? WHERE tenant=? AND kind='site' AND id=?", (json.dumps(s), p['tenant_id'], p['resource_id']))
        self.catalog.commit()
        return {'php_version': version}

    def create_ssl(self, p):
        s = self.find('site', p, 'website_id'); email = p.get('email', '')
        if not isinstance(email, str) or not re.fullmatch(r'[a-zA-Z0-9_.+%-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,63}', email):
            raise Rejected('Invalid ACME email')
        config = Path(s['nginx'])
        if 'listen 443' not in config.read_text():
            cert, key = tls.local_certificate(s['domain'])
            atomic_write(config, tls.secure_config(config.read_text(), s['domain'], cert, key))
            run(['/usr/sbin/nginx', '-t']); reload_nginx()
        tls.upgrade_certificate(config, s['domain'], email)
        self.save('ssl', p, {'domain': s['domain']}); return {'domain': s['domain']}

    def delete_ssl(self, p):
        self.find('ssl', p)
        s = self.find('site', p, 'website_id')
        cert, key = tls.local_certificate(s['domain'])
        config = Path(s['nginx']); before = config.read_text()
        content = re.sub(r'ssl_certificate\s+[^;]+;', f'ssl_certificate {cert};', before)
        content = re.sub(r'ssl_certificate_key\s+[^;]+;', f'ssl_certificate_key {key};', content)
        atomic_write(config, content)
        try:
            run(['/usr/sbin/nginx', '-t']); reload_nginx()
        except Exception:
            atomic_write(config, before)
            raise
        # Keep shared ACME files intact: the panel or another host may still reference them.
        self.forget('ssl', p)
        return {'message': 'Certificate detached. HTTPS remains enabled with a local certificate.'}

    def create_sftp(self, p):
        import pwd
        s = self.find('site', p, 'website_id'); name = identifier(p['name']); secret = password(p['password'])
        if len(name) > 30 or not name.startswith(f"s{integer(p['owner_id'])}_"):
            raise Rejected('Invalid SFTP account')
        owner = pwd.getpwnam(s['username'])
        run(['/usr/sbin/useradd', '--no-create-home', '--non-unique', '--uid', str(owner.pw_uid), '--gid', str(owner.pw_gid), '--home-dir', '/public_html', '--shell', '/usr/sbin/nologin', name])
        run(['/usr/sbin/chpasswd'], input_text=f'{name}:{secret}\n')
        config = Path(f'/etc/ssh/sshd_config.d/vpm-{name}.conf')
        atomic_write(config, f'Match User {name}\n    ChrootDirectory {s["home"]}\n    ForceCommand internal-sftp -d /public_html\n    AllowTcpForwarding no\n    X11Forwarding no\n    PermitTunnel no\n    PasswordAuthentication yes\nMatch all\n')
        try:
            run(['/usr/sbin/sshd', '-t']); run(['/usr/bin/systemctl', 'reload', 'ssh'])
        except Exception:
            config.unlink(missing_ok=True); run(['/usr/sbin/usermod', '--lock', name]); raise
        self.save('sftp', p, {'name': name, 'config': str(config)})
        return {'username': name, 'protocol': 'sftp'}

    def delete_sftp(self, p):
        d = self.find('sftp', p); run(['/usr/sbin/userdel', d['name']]); Path(d['config']).unlink(missing_ok=True)
        run(['/usr/sbin/sshd', '-t']); run(['/usr/bin/systemctl', 'reload', 'ssh']); self.forget('sftp', p)
        return {'message': 'SFTP account removed'}

    def create_cron(self, p):
        s = self.find('site', p, 'website_id'); schedule = cron(p['schedule']); script = inside(s['public'], p['path'])
        if script.suffix != '.php' or not script.is_file() or not re.fullmatch(r'[a-zA-Z0-9_/.-]+', str(script)):
            raise Rejected('PHP script must exist inside this site')
        path = Path(f"/etc/cron.d/vpm-{integer(p['tenant_id'])}-{integer(p['resource_id'])}")
        atomic_write(path, f"SHELL=/bin/sh\nPATH=/usr/bin:/bin\n{schedule} {s['username']} /usr/bin/php{s['php']} {script} > /dev/null 2>&1\n", 0o644)
        self.save('cron', p, {'path': str(path)}); return {'message': 'Cron installed'}

    def delete_cron(self, p):
        d = self.find('cron', p); Path(d['path']).unlink(missing_ok=True); self.forget('cron', p); return {'message': 'Cron removed'}

    def create_firewall(self, p):
        port = integer(p['port'], 1, 65535); protocol = choice(p['protocol'], ['tcp', 'udp']); action = choice(p['action'], ['allow', 'deny']); origin = source(p['source'])
        protected = {22, 80, 443, 9443} | {int(n) for n in os.getenv('VPM_PROTECTED_PORTS', '').split(',') if n.isdigit()}
        if port in protected:
            raise Rejected('Administrative port is protected')
        args = [action, 'from', origin, 'to', 'any', 'port', str(port), 'proto', protocol]
        run(['/usr/sbin/ufw', *args]); self.save('firewall', p, {'args': args}); return {'message': 'Firewall rule installed'}

    def delete_firewall(self, p):
        d = self.find('firewall', p); run(['/usr/sbin/ufw', '--force', 'delete', *d['args']]); self.forget('firewall', p); return {'message': 'Firewall rule removed'}

    def create_container(self, p):
        name = identifier(p['name']); image = p.get('image', '')
        if not name.startswith(f"u{integer(p['owner_id'])}_") or not isinstance(image, str) or not re.fullmatch(r'[a-z0-9][a-z0-9._/-]*:[a-zA-Z0-9_.-]+', image):
            raise Rejected('Invalid container')
        allowed = os.getenv('VPM_DOCKER_IMAGES', '').split(',')
        if image not in allowed:
            raise Rejected('Image is not in the operator allowlist')
        memory = integer(p['memory_mb'], 64, 4096); cpu = integer(p['cpu'], 1, 4)
        output = run(['/usr/bin/docker', 'run', '-d', '--name', name, '--label', f"vpm.tenant={integer(p['tenant_id'])}", '--label', f"vpm.owner={integer(p['owner_id'])}", '--memory', f'{memory}m', '--cpus', str(cpu), '--pids-limit', '128', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--read-only', '--network=none', '--user=65534:65534', image], timeout=150)
        self.save('container', p, {'name': name, 'container_id': output.strip()}); return {'container_id': output.strip()}

    def delete_container(self, p):
        d = self.find('container', p); run(['/usr/bin/docker', 'rm', '-f', d['container_id']]); self.forget('container', p); return {'message': 'Container removed'}

    def files(self, p):
        import pwd
        s = self.find('site', p, 'website_id'); account = pwd.getpwnam(s['username'])
        private = Path(s['home']) / '.file-manager'
        private.mkdir(mode=0o700, exist_ok=True); os.chown(private, account.pw_uid, account.pw_gid)
        return unprivileged(account, lambda: files.operate(s['public'], p, private))

    def create_backup(self, p):
        import pwd
        import hashlib
        s = self.find('site', p, 'website_id'); account = pwd.getpwnam(s['username'])
        directory = Path(s['home']) / 'backups'
        directory.mkdir(mode=0o700, exist_ok=True); os.chown(directory, account.pw_uid, account.pw_gid)
        destination = directory / f"backup-{integer(p['resource_id'])}.tar.gz"
        def archive_site():
            with tarfile.open(destination, 'x:gz', dereference=False) as archive:
                archive.add(s['public'], arcname='public_html', recursive=True)
            return {'size': destination.stat().st_size}
        result = unprivileged(account, archive_site)
        digest = hashlib.file_digest(open(destination, 'rb'), 'sha256').hexdigest()
        self.save('backup', p, {'path': str(destination), 'site': s, 'sha256': digest})
        return {**result, 'sha256': digest}

    def delete_backup(self, p):
        import pwd
        d = self.find('backup', p); account = pwd.getpwnam(d['site']['username'])
        unprivileged(account, lambda: Path(d['path']).unlink(missing_ok=True))
        self.forget('backup', p); return {'message': 'Backup removed'}

    def restore_backup(self, p):
        import pwd
        import hashlib
        d = self.find('backup', p); account = pwd.getpwnam(d['site']['username'])
        def restore():
            with open(d['path'], 'rb') as archive_file:
                if hashlib.file_digest(archive_file, 'sha256').hexdigest() != d['sha256']:
                    raise Rejected('Backup integrity check failed')
                archive_file.seek(0)
                with tarfile.open(fileobj=archive_file, mode='r:gz') as archive:
                    members = archive.getmembers()
                    if sum(m.size for m in members) > 10 * 1024**3 or len(members) > 100000:
                        raise Rejected('Backup exceeds restore limit')
                    for member in members:
                        if not member.name.startswith('public_html/') and member.name != 'public_html':
                            raise Rejected('Invalid backup root')
                        inside(d['site']['home'], member.name)
                        if not (member.isfile() or member.isdir()):
                            raise Rejected('Links and special files are not restored')
                    archive.extractall(d['site']['home'], members=members, filter='data')
            return {'message': 'Files restored; existing extra files retained'}
        return unprivileged(account, restore)
