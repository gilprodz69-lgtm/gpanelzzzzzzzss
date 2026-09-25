"""Deterministic source bundle and single-command installer, using an explicit allowlist."""
import base64
import gzip
import hashlib
import io
import json
from pathlib import Path
import tarfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
VERSION = (ROOT / 'VERSION').read_text().strip()
DIST = ROOT / 'dist'
DIST.mkdir(exist_ok=True)
FOLDERS = ['app', 'agent', 'database', 'deploy', 'public', 'resources', 'docs']
SCRIPT_NAMES = ['console.php', 'health.php', 'register-local-server.php', 'install-ubuntu.sh', 'update.sh', 'install-runtime.sh', 'upgrade-ubuntu.sh', 'upgrade-config.php', 'panel-certificate.py', 'wait-agent.py']
SINGLE_FILES = ['README.md', 'VERSION', '.env.example', 'composer.json', 'compose.yaml', '.dockerignore']
paths = [ROOT / name for name in SINGLE_FILES]
paths += [ROOT / 'scripts' / name for name in SCRIPT_NAMES]
for folder in FOLDERS:
    paths += [p for p in (ROOT / folder).rglob('*') if p.is_file() and '__pycache__' not in p.parts and p.suffix != '.pyc']
paths = sorted(set(paths), key=lambda p: p.relative_to(ROOT).as_posix())
secrets = []
env = ROOT / '.env'
if env.exists():
    for line in env.read_text().splitlines():
        key, _, value = line.partition('=')
        if key in ['APP_KEY', 'DB_PASSWORD'] and len(value) >= 12:
            secrets.append(value.encode())
access = ROOT / 'storage' / 'LOCAL-ACCESS.txt'
if access.exists():
    for line in access.read_text().splitlines():
        if line.startswith('Senha: '): secrets.append(line[7:].encode())
manifest = {'version': VERSION, 'files': []}
buffer = io.BytesIO()
with tarfile.open(fileobj=buffer, mode='w', format=tarfile.PAX_FORMAT) as archive:
    for path in paths:
        name = path.relative_to(ROOT).as_posix()
        if path.is_symlink() or not path.resolve().is_relative_to(ROOT):
            raise SystemExit('Unsafe source path: ' + name)
        data = path.read_bytes()
        if path.suffix in ['.php', '.py', '.sh', '.js', '.css', '.md', '.json', '.yaml', '.yml', '.service', '.timer', '.conf']:
            data = data.replace(b'\r\n', b'\n')
        if any(secret in data for secret in secrets):
            raise SystemExit('Local secret found in release file: ' + name)
        info = tarfile.TarInfo(name)
        info.size = len(data); info.mode = 0o755 if path.suffix == '.sh' else 0o644
        info.mtime = 0; info.uid = info.gid = 0; info.uname = info.gname = 'root'
        archive.addfile(info, io.BytesIO(data))
        manifest['files'].append({'path': name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
compressed = gzip.compress(buffer.getvalue(), compresslevel=9, mtime=0)
archive_name = f'vps-manager-{VERSION}.tar.gz'
(DIST / archive_name).write_bytes(compressed)
digest = hashlib.sha256(compressed).hexdigest()
payload = base64.encodebytes(compressed).decode()
installer = '''#!/usr/bin/env bash
# VPS Manager __VERSION__ — self-contained distribution.
# Run as root on a fresh Ubuntu 24.04 VPS. Prompts are read from /dev/tty.
set -Eeuo pipefail
vpm_payload() {
cat <<'VPS_MANAGER_PAYLOAD'
__PAYLOAD__VPS_MANAGER_PAYLOAD
}
vpm_install() {
    [[ $EUID -eq 0 ]] || { echo 'Execute como root ou use: curl -fsSL URL | sudo bash' >&2; return 1; }
    for required in base64 tar gzip sha256sum mktemp; do command -v "$required" >/dev/null || { echo "Dependência inicial ausente: $required" >&2; return 1; }; done
    vpm_work=$(mktemp -d /tmp/vpsmanager-install.XXXXXXXX)
    trap 'rm -rf -- "$vpm_work"' EXIT
    vpm_payload | base64 --decode > "$vpm_work/package.tar.gz"
    printf '%s  %s\\n' '__SHA256__' "$vpm_work/package.tar.gz" | sha256sum --check --status
    mkdir "$vpm_work/project"
    tar -xzf "$vpm_work/package.tar.gz" -C "$vpm_work/project" --no-same-owner
    if [[ ${VPM_VERIFY_ONLY:-0} == 1 ]]; then
        test -f "$vpm_work/project/public/index.php"
        test -f "$vpm_work/project/scripts/install-ubuntu.sh"
        bash -n "$vpm_work/project/scripts/install-ubuntu.sh"
        echo 'OK: checksum, extração e sintaxe do instalador.'
        return 0
    fi
    if [[ -d /opt/vpsmanager/current ]]; then
        echo 'Painel instalado detectado. 1) Atualizar Painel  2) Cancelar'
        vpm_choice=${VPM_UPDATE:-}
        if [[ -z "$vpm_choice" ]]; then
            [[ -r /dev/tty ]] || { echo 'Defina VPM_UPDATE=1 para atualizar sem interação.' >&2; return 1; }
            printf 'Opção [1]: ' > /dev/tty
            read -r vpm_choice < /dev/tty
            vpm_choice=${vpm_choice:-1}
        fi
        [[ "$vpm_choice" == 1 ]] || { echo 'Cancelado.'; return 0; }
        bash "$vpm_work/project/scripts/upgrade-ubuntu.sh" "$@"
    else
        bash "$vpm_work/project/scripts/install-ubuntu.sh" "$@"
    fi
}
vpm_install "$@"
'''.replace('__VERSION__', VERSION).replace('__PAYLOAD__', payload).replace('__SHA256__', digest)
(ROOT / 'install.sh').write_text(installer, encoding='utf-8', newline='\n')
installer_digest = hashlib.sha256(installer.encode()).hexdigest()
(DIST / 'SHA256SUMS').write_text(f'{digest}  {archive_name}\n{installer_digest}  ../install.sh\n', encoding='utf-8', newline='\n')
(DIST / 'release-manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8', newline='\n')
# Public repository upload list is separate from the production runtime bundle.
publish = set(paths + [ROOT / '.gitignore', ROOT / 'package.json', ROOT / 'package-lock.json', ROOT / 'install.sh', ROOT / 'scripts/build-release.py', ROOT / 'scripts/dev.ps1', ROOT / 'scripts/verify.ps1'])
publish.update(p for p in (ROOT / 'tests').rglob('*') if p.is_file() and '__pycache__' not in p.parts)
publish.update(p for p in (ROOT / '.github').rglob('*') if p.is_file())
publish.update([DIST / archive_name, DIST / 'SHA256SUMS', DIST / 'release-manifest.json'])
entries = []
with zipfile.ZipFile(DIST / 'github-upload.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(publish, key=lambda p: p.relative_to(ROOT).as_posix()):
        if not path.exists(): continue
        name = path.relative_to(ROOT).as_posix()
        data = path.read_bytes()
        if any(secret in data for secret in secrets): raise SystemExit('Secret found in upload: ' + name)
        archive.writestr(name, data)
        entries.append({'path': name, 'encoding': 'base64' if path.suffix == '.gz' else 'utf-8', 'content': base64.b64encode(data).decode() if path.suffix == '.gz' else data.decode('utf-8')})
# Tool handoff file contains only files already vetted for publication, not local secrets.
(ROOT / '.tools').mkdir(exist_ok=True)
(ROOT / '.tools/publish-files.json').write_text(json.dumps(entries, ensure_ascii=False), encoding='utf-8')
print(f'{len(paths)} runtime files; {len(entries)} repository files; {len(compressed):,} bytes compressed.')
print(f'Package SHA256: {digest}')
print(f'Installer SHA256: {installer_digest}')
