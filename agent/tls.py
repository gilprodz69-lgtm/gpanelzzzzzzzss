"""HTTPS defaults shared by site provisioning and panel installation."""
import ipaddress
from pathlib import Path
from runtime import run, atomic_write
from validation import domain, Rejected

ACME = '/opt/vpsmanager/acme/bin/certbot'
WEBROOT = '/var/lib/vpsmanager-acme'

def host(value):
    try:
        address = ipaddress.ip_address(value)
    except ValueError:
        return domain(value)
    if address.version != 4 or not address.is_global:
        raise Rejected('Use a public IPv4 address')
    return str(address)

def local_certificate(name):
    name = host(name)
    directory = Path('/etc/vpsmanager/tls') / name
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    cert, key = directory / 'fullchain.pem', directory / 'privkey.pem'
    if not cert.exists() or not key.exists():
        kind = 'IP' if name.replace('.', '').isdigit() else 'DNS'
        run(['/usr/bin/openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-sha256', '-nodes', '-days', '365', '-subj', f'/CN={name}', '-addext', f'subjectAltName={kind}:{name}', '-keyout', str(key), '-out', str(cert)])
        key.chmod(0o600)
    return str(cert), str(key)

def issue(name, email):
    name = host(name)
    args = [ACME, 'certonly', '--non-interactive', '--agree-tos', '--email', email, '--webroot', '-w', WEBROOT, '--cert-name', name, '--keep-until-expiring']
    if name.replace('.', '').isdigit():
        args += ['--preferred-profile', 'shortlived', '--ip-address', name]
    else:
        args += ['-d', name]
    run(args, timeout=120)
    return f'/etc/letsencrypt/live/{name}/fullchain.pem', f'/etc/letsencrypt/live/{name}/privkey.pem'

def secure_config(content, name, cert, key):
    # Content is our own fixed Nginx template. Preserve the body of the web host.
    content = content.replace('listen 80;', 'listen 443 ssl;', 1)
    content = content.replace('listen 443 ssl;', f'listen 443 ssl;\n    ssl_certificate {cert};\n    ssl_certificate_key {key};\n    ssl_protocols TLSv1.2 TLSv1.3;', 1)
    return f'''server {{
    listen 80;
    server_name {name};
    location /.well-known/acme-challenge/ {{ root {WEBROOT}; }}
    location / {{ return 301 https://{name}$request_uri; }}
}}
''' + content

def upgrade_certificate(path, name, email):
    import re
    cert, key = issue(name, email)
    path = Path(path)
    before = path.read_text()
    content = re.sub(r'ssl_certificate\s+[^;]+;', f'ssl_certificate {cert};', before)
    content = re.sub(r'ssl_certificate_key\s+[^;]+;', f'ssl_certificate_key {key};', content)
    atomic_write(path, content)
    try:
        run(['/usr/sbin/nginx', '-t'])
        run(['/usr/bin/systemctl', 'reload', 'nginx'])
    except Exception:
        atomic_write(path, before)
        raise
    return {'https': True, 'trusted': True, 'domain': name}
