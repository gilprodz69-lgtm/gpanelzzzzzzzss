"""Strict validators shared by privileged operations. No shell grammar is accepted."""
import ipaddress
import re
from pathlib import Path


class Rejected(ValueError):
    pass


def integer(value, minimum=1, maximum=2**31 - 1):
    if isinstance(value, bool) or not isinstance(value, int) or not minimum <= value <= maximum:
        raise Rejected('Invalid integer')
    return value


def domain(value):
    if not isinstance(value, str) or len(value) > 253 or not re.fullmatch(r'(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}', value):
        raise Rejected('Invalid domain')
    return value


def identifier(value):
    if not isinstance(value, str) or not re.fullmatch(r'[a-z][a-z0-9_]{0,39}', value):
        raise Rejected('Invalid identifier')
    return value


def choice(value, choices):
    if value not in choices:
        raise Rejected('Invalid option')
    return value


def password(value):
    if not isinstance(value, str) or not 12 <= len(value.encode()) <= 72 or '\x00' in value or '\n' in value or '\r' in value:
        raise Rejected('Invalid password')
    return value


def inside(root, relative, allow_root=False):
    if not isinstance(relative, str) or '\x00' in relative or '\\' in relative:
        raise Rejected('Invalid path')
    rel = Path(relative)
    if rel.is_absolute() or '..' in rel.parts:
        raise Rejected('Path traversal denied')
    root = Path(root).resolve()
    candidate = root / rel
    # Refuse symlinks at every path component, including dangling links.
    current = root
    for part in rel.parts:
        current = current / part
        if current.is_symlink():
            raise Rejected('Symbolic links are not accessible')
    resolved = candidate.resolve()
    if not resolved.is_relative_to(root) or (resolved == root and not allow_root):
        raise Rejected('Path outside site')
    return resolved


def cron(value):
    if not isinstance(value, str) or len(value) > 80:
        raise Rejected('Invalid schedule')
    fields = value.split(' ')
    if len(fields) != 5:
        raise Rejected('Five cron fields required')
    for field, (low, high) in zip(fields, [(0, 59), (0, 23), (1, 31), (1, 12), (0, 6)]):
        for item in field.split(','):
            match = re.fullmatch(r'(\*|\d+(?:-\d+)?)(?:/(\d+))?', item)
            if not match:
                raise Rejected('Invalid cron field')
            if match[2] and not 1 <= int(match[2]) <= high + 1:
                raise Rejected('Invalid cron step')
            if match[1] != '*':
                numbers = [int(n) for n in match[1].split('-')]
                if any(n < low or n > high for n in numbers) or numbers != sorted(numbers):
                    raise Rejected('Cron value out of range')
    return value


def source(value):
    return 'any' if value == 'any' else str(ipaddress.ip_address(value))
