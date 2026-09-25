"""File operations run after dropping root to the site's Unix account."""
import base64
import os
import shutil
import stat
import zipfile
import json
import time
import uuid
import errno
from pathlib import Path
from validation import inside, choice, Rejected

MAX_FILE = 1024 * 1024


def bounded_tree(path):
    entries = [path]
    for parent, directories, names in os.walk(path, followlinks=False) if path.is_dir() else []:
        for name in directories + names:
            entry = Path(parent) / name
            if entry.is_symlink():
                raise Rejected('Symbolic links are not accessible')
            entries.append(entry)
    return entries

def operate(root, payload, private=None):
    action = choice(payload.get('action'), ['list', 'read', 'write', 'mkdir', 'delete', 'rename', 'copy', 'zip', 'unzip', 'chmod', 'trash', 'trash_list', 'restore', 'purge', 'download', 'upload_begin', 'upload_chunk', 'upload_finish', 'upload_cancel', 'info'])
    if action in ['trash', 'trash_list', 'restore', 'purge', 'upload_begin', 'upload_chunk', 'upload_finish', 'upload_cancel']:
        if private is None:
            raise Rejected('Private file storage unavailable')
        private = Path(private)
        if action.startswith('upload_'):
            return upload(root, private, payload)
        directory = private / 'trash'
        directory.mkdir(mode=0o700, exist_ok=True)
        if action == 'trash_list':
            rows = []
            for metadata in directory.glob('*.json'):
                item = json.loads(metadata.read_text())
                if (directory / metadata.stem).exists():
                    rows.append(dict(item, id=metadata.stem))
                    if len(rows) >= 1000:
                        break
            return {'data': sorted(rows, key=lambda item: item['deleted_at'], reverse=True)}
        if action == 'trash':
            path = inside(root, payload.get('path', ''))
            token = uuid.uuid4().hex
            bounded_tree(path)
            metadata = {'name': path.name, 'path': str(path.relative_to(root)), 'directory': path.is_dir(), 'deleted_at': int(time.time())}
            (directory / (token + '.json')).write_text(json.dumps(metadata))
            path.rename(directory / token)
        else:
            token = transfer_id(payload.get('id'))
            item = directory / token
            metadata_path = directory / (token + '.json')
            metadata = json.loads(metadata_path.read_text())
            if action == 'restore':
                destination = inside(root, metadata['path'])
                if destination.exists():
                    raise Rejected('A file already exists at the original location')
                item.rename(destination)
            else:
                bounded_tree(item)
                shutil.rmtree(item) if item.is_dir() else item.unlink()
            metadata_path.unlink()
        return {'message': 'Operation completed'}
    path = inside(root, payload.get('path', ''), allow_root=action in ['list', 'info'])
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
    if action == 'info':
        entries = bounded_tree(path)
        return {'name': path.name, 'items': len(entries), 'size': sum(p.stat().st_size for p in entries if p.is_file()), 'mode': oct(stat.S_IMODE(path.stat().st_mode))[2:], 'modified': int(path.stat().st_mtime)}
    if action == 'download':
        size = path.stat().st_size
        if not path.is_file():
            raise Rejected('Archive folders before downloading')
        offset = payload.get('offset', 0)
        if type(offset) is not int or offset < 0 or offset > size:
            raise Rejected('Invalid offset')
        with open(path, 'rb') as stream:
            stream.seek(offset)
            data = stream.read(MAX_FILE)
        return {'content': base64.b64encode(data).decode(), 'size': size, 'offset': offset, 'modified': path.stat().st_mtime_ns}
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
            if target.is_relative_to(path):
                raise Rejected('Invalid copy destination')
            bounded_tree(path)
            if path.is_dir():
                shutil.copytree(path, target, symlinks=True)
            else:
                shutil.copyfile(path, target, follow_symlinks=False)
                target.chmod(0o640)
    elif action == 'chmod':
        mode = choice(payload.get('mode'), ['600', '640', '644', '700', '750', '755'])
        os.chmod(path, int(mode, 8), follow_symlinks=False)
    elif action == 'zip':
        target = inside(root, payload.get('target', ''))
        if target.exists() or (path.is_dir() and target.is_relative_to(path)):
            raise Rejected('Invalid archive destination')
        candidates = bounded_tree(path)
        with zipfile.ZipFile(target, 'x', zipfile.ZIP_DEFLATED) as archive:
            for entry in candidates:
                if entry.is_symlink():
                    raise Rejected('Symbolic links cannot be archived')
                if entry.is_file() or entry.is_dir():
                    archive.write(entry, str(entry.relative_to(path.parent)))
    elif action == 'unzip':
        target = inside(root, payload.get('target', ''))
        if target.exists():
            raise Rejected('Extract into a new directory')
        with zipfile.ZipFile(path) as archive:
            members = archive.infolist()
            if sum(m.file_size for m in members) > shutil.disk_usage(target.parent).free:
                raise Rejected('Espaço livre insuficiente para descompactar o ZIP')
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

def transfer_id(value):
    import re
    if not isinstance(value, str) or not re.fullmatch('[a-f0-9]{32}', value):
        raise Rejected('Invalid transfer ID')
    return value

def upload(root, private, payload):
    directory = private / 'uploads'
    directory.mkdir(mode=0o700, exist_ok=True)
    for stale in directory.iterdir():
        if stale.is_file() and time.time() - stale.stat().st_mtime > 86400:
            stale.unlink()
    action = payload['action']
    if action == 'upload_begin':
        target = inside(root, payload.get('path', ''))
        if target.exists():
            raise Rejected('Destination already exists; rename or move it first')
        size = payload.get('size')
        if type(size) is not int or not 0 <= size <= 9007199254740991:
            raise Rejected('Invalid upload size')
        if size > shutil.disk_usage(directory).free:
            raise Rejected('Espaço livre insuficiente para enviar este arquivo')
        if len(list(directory.glob('*.json'))) >= 10:
            raise Rejected('Too many unfinished uploads')
        token = uuid.uuid4().hex
        (directory / (token + '.json')).write_text(json.dumps({'path': str(target.relative_to(root)), 'size': size}))
        (directory / token).touch(mode=0o600)
        return {'id': token}
    token = transfer_id(payload.get('id'))
    data_path = directory / token
    metadata_path = directory / (token + '.json')
    metadata = json.loads(metadata_path.read_text())
    if action == 'upload_chunk':
        content = base64.b64decode(payload.get('content', ''), validate=True)
        if type(payload.get('offset')) is not int or payload['offset'] != data_path.stat().st_size or len(content) > MAX_FILE or data_path.stat().st_size + len(content) > metadata['size']:
            raise Rejected('Invalid upload offset or size')
        with open(data_path, 'ab') as out:
            out.write(content)
        os.utime(metadata_path, None)
        return {'offset': data_path.stat().st_size}
    if action == 'upload_finish':
        target = inside(root, metadata['path'])
        if data_path.stat().st_size != metadata['size'] or target.exists():
            raise Rejected('Incomplete upload or destination exists')
        # Publish on the same filesystem without copying the whole upload or
        # requiring twice its space. Assign the destination's group for nginx.
        os.chmod(data_path, 0o640)
        if os.name=='posix' and data_path.stat().st_gid!=target.parent.stat().st_gid:
            # Transfers started by older agents may have the site's private group.
            publish_cross_device(data_path, target)
        else:
            try:
                os.link(data_path, target)
            except OSError as exc:
                if exc.errno != errno.EXDEV:
                    raise
                publish_cross_device(data_path, target)
    data_path.unlink()
    metadata_path.unlink()
    return {'message': 'Upload completed' if action == 'upload_finish' else 'Upload cancelled'}


def publish_cross_device(data_path, target):
    # Mounts on another filesystem need a bounded-memory copy and their own space.
    if data_path.stat().st_size > shutil.disk_usage(target.parent).free:
        raise Rejected('Espaço livre insuficiente no destino do upload')
    import tempfile
    descriptor, temp_name = tempfile.mkstemp(prefix='.vpm-upload-', dir=target.parent)
    try:
        with os.fdopen(descriptor, 'wb') as out, open(data_path, 'rb') as source:
            shutil.copyfileobj(source, out)
        os.chmod(temp_name, 0o640)
        os.link(temp_name, target)
    finally:
        os.unlink(temp_name)
