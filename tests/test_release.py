import base64
import hashlib
import io
import json
from pathlib import Path
import re
import tarfile

root = Path(__file__).resolve().parents[1]
installer = (root / 'install.sh').read_text()
encoded = installer.split("cat <<'VPS_MANAGER_PAYLOAD'\n", 1)[1].split('\nVPS_MANAGER_PAYLOAD', 1)[0]
package = base64.b64decode(encoded)
digest = re.search(r"'([a-f0-9]{64})' \"\$vpm_work/package.tar.gz\"", installer).group(1)
assert hashlib.sha256(package).hexdigest() == digest
manifest = json.loads((root / 'dist/release-manifest.json').read_text())
expected = {entry['path']: entry for entry in manifest['files']}
with tarfile.open(fileobj=io.BytesIO(package), mode='r:gz') as archive:
    assert len(archive.getmembers()) == len(expected)
    for member in archive.getmembers():
        assert member.isfile() and not member.issym() and not member.islnk()
        assert not member.name.startswith('/') and '..' not in Path(member.name).parts
        assert not any(part in ['.tools', '.env', 'storage', 'node_modules'] for part in Path(member.name).parts)
        content = archive.extractfile(member).read()
        assert hashlib.sha256(content).hexdigest() == expected[member.name]['sha256'], member.name
        assert content == (root / member.name).read_bytes().replace(b'\r\n', b'\n'), member.name
print(f'PASS: installer payload and {len(expected)} source files verified; no secret directories packaged.')
