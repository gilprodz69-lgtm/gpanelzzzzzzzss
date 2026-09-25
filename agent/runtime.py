"""OS boundary: fixed executable paths, bounded processes, no shell invocation."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import socket
import time


def run(argv, input_text=None, timeout=60, user=None):
    options = dict(input=input_text, text=True, capture_output=True, timeout=timeout, check=False,
                   env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C.UTF-8'})
    if user is not None:
        options.update(user=user.pw_uid, group=user.pw_gid, extra_groups=[])
    result = subprocess.run(argv, **options)
    if result.returncode:
        # Do not return process arguments or stderr: these may include secrets.
        raise RuntimeError(f'{Path(argv[0]).name} failed (exit {result.returncode}). Consult system journal.')
    return result.stdout[:1048576]


def atomic_write(path, content, mode=0o640):
    path = Path(path)
    fd, name = tempfile.mkstemp(prefix='.vpm-', dir=path.parent)
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, 'w') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def wait_socket(path, timeout=10):
    """systemctl reload returns before FPM has bound newly added pools."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
                client.settimeout(0.25)
                client.connect(path)
                return
        except OSError:
            time.sleep(0.1)
    raise RuntimeError('PHP-FPM pool did not become ready. Inspect the PHP-FPM journal.')


def unprivileged(user, function):
    """Use a child with permanently dropped IDs for files, archives and restoration."""
    read_fd, write_fd = os.pipe()
    pid = os.fork()
    if pid == 0:
        os.close(read_fd)
        try:
            os.setgroups([])
            os.setgid(user.pw_gid)
            os.setuid(user.pw_uid)
            result = {'ok': True, 'result': function()}
        except Exception as exc:
            result = {'ok': False, 'error': str(exc)[:300]}
        with os.fdopen(write_fd, 'w') as out:
            json.dump(result, out)
        os._exit(0)
    os.close(write_fd)
    with os.fdopen(read_fd) as inp:
        raw = inp.read(2097152)
    os.waitpid(pid, 0)
    result = json.loads(raw)
    if not result['ok']:
        raise RuntimeError(result['error'])
    return result['result']
