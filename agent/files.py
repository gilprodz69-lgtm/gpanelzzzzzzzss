"""File operations run after dropping root to the site's Unix account."""
import base64
import os
import shutil
import stat
import zipfile
from pathlib import Path
from validation import inside, choice, Rejected

MAX_FILE = 1024 * 1024
MAX_ARCHIVE = 100 * 1024 * 1024


def operate(root, payload):
    action = choice(payload.get('action'), ['list', 'read', 'write', 'mkdir', 'delete', 'rename', 'copy', 'zip', 'unzip', 'chmod'])
    path = inside(root, payload.get('path', ''), allow_root=action == 'list')
    if action == 'list':
        result = []
        for entry in sorted(path.iterdir(), key=lambda p: (not p.is_dir(), p.name.lower())):
            if entry.is_symlink():
                continue
            info = entry.stat()
            result.append({'name': entry.name, 'directory': entry.is_dir(), 'size': info.st_size,
                           'modified': int(info.st_mtime), 'mode': oct(stat.S_IMODE(info.st_mode))[2:]})
            if len(result) >= 1000:
                break
        return {'data': result}
    if action == 'read':
        if path.stat().st_size > MAX_FILE:
            raise Rejected('File exceeds 1 MiB editor limit')
        return {'content': base64.b64encode(path.read_bytes()).decode(), 'encoding': 'base64'}
    if action == 'write':
        content = base64.b64decode(payload.get('content', ''), validate=True)
        if len(content) > MAX_FILE:
            raise Rejected('File exceeds 1 MiB upload limit')
        # O_NOFOLLOW prevents a final-component symlink switch by a concurrent site process.
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o640)
        with os.fdopen(fd, 'wb') as out:
            out.write(content)
    elif action == 'mkdir':
        path.mkdir(mode=0o750)
    elif action == 'delete':
        if path.is_dir():
            path.rmdir()  # Nonempty directory removal requires explicit child operations.
        else:
            path.unlink()
    elif action in ['rename', 'copy']:
        target = inside(root, payload.get('target', ''))
        if target.exists():
            raise Rejected('Destination already exists')
        if action == 'rename':
            path.rename(target)
        else:
            if path.is_dir() or path.stat().st_size > MAX_ARCHIVE:
                raise Rejected('Copy supports files up to 100 MiB')
            shutil.copyfile(path, target, follow_symlinks=False)
    elif action == 'chmod':
        mode = choice(payload.get('mode'), ['600', '640', '644', '700', '750', '755'])
        os.chmod(path, int(mode, 8), follow_symlinks=False)
    elif action == 'zip':
        target = inside(root, payload.get('target', ''))
        if target.exists() or (path.is_dir() and target.is_relative_to(path)):
            raise Rejected('Invalid archive destination')
        candidates = list(path.rglob('*')) if path.is_dir() else [path]
        if len(candidates) > 5000 or sum(p.lstat().st_size for p in candidates) > MAX_ARCHIVE:
            raise Rejected('Archive exceeds interactive limit')
        with zipfile.ZipFile(target, 'x', zipfile.ZIP_DEFLATED) as archive:
            for entry in candidates:
                if entry.is_symlink():
                    raise Rejected('Symbolic links cannot be archived')
                if entry.is_file():
                    archive.write(entry, str(entry.relative_to(path.parent)))
    elif action == 'unzip':
        target = inside(root, payload.get('target', ''))
        if target.exists():
            raise Rejected('Extract into a new directory')
        with zipfile.ZipFile(path) as archive:
            members = archive.infolist()
            if len(members) > 5000 or sum(m.file_size for m in members) > MAX_ARCHIVE:
                raise Rejected('Archive exceeds extraction limits')
            for member in members:
                inside(target, member.filename)
                mode = member.external_attr >> 16
                if stat.S_ISLNK(mode) or (stat.S_IFMT(mode) and not (stat.S_ISREG(mode) or stat.S_ISDIR(mode))):
                    raise Rejected('Unsafe archive entry')
            target.mkdir(mode=0o750)
            for member in members:
                destination = inside(target, member.filename)
                if member.is_dir():
                    destination.mkdir(mode=0o750, parents=True, exist_ok=True)
                else:
                    destination.parent.mkdir(mode=0o750, parents=True, exist_ok=True)
                    with archive.open(member) as src, open(destination, 'xb') as dst:
                        shutil.copyfileobj(src, dst)
                    destination.chmod(0o640)
    return {'message': 'Operation completed'}
