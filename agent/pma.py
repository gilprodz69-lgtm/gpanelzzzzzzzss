"""Short-lived phpMyAdmin accounts; no credentials are persisted by the agent."""
import re
import time
from runtime import run
from validation import Rejected, identifier, integer


def schema(catalog):
    catalog.execute('CREATE TABLE IF NOT EXISTS pma_leases (username TEXT PRIMARY KEY, expires INTEGER NOT NULL)')
    catalog.commit()


def sql(query):
    return run(['/usr/bin/mariadb', '--protocol=socket', '--batch', '--skip-column-names'], input_text=query)


def cleanup(catalog):
    schema(catalog)
    for username, expires in catalog.execute('SELECT username,expires FROM pma_leases WHERE expires<=?', (int(time.time()),)).fetchall():
        if not re.fullmatch(r'vpm_sso_[a-f0-9]{20}', username):
            raise Rejected('Invalid phpMyAdmin lease')
        sql(f"DROP USER IF EXISTS '{username}'@'localhost';")
        # DROP USER does not terminate existing connections. Kill those as well.
        for connection in sql(f"SELECT ID FROM information_schema.PROCESSLIST WHERE USER='{username}';").splitlines():
            if connection.isdigit():
                try: sql(f'KILL {connection};')
                except RuntimeError: pass  # Connection may have closed between SELECT and KILL.
        catalog.execute('DELETE FROM pma_leases WHERE username=?', (username,))
        catalog.commit()


def create(ops, p):
    database = identifier(ops.find('database', p)['name'])
    username, digest = p.get('username', ''), p.get('password_hash', '')
    expires = integer(p.get('expires_at'))
    if not re.fullmatch(r'vpm_sso_[a-f0-9]{20}', username) or not re.fullmatch(r'\*[A-F0-9]{40}', digest) or not time.time() < expires <= time.time()+900:
        raise Rejected('Invalid phpMyAdmin lease')
    cleanup(ops.catalog)
    # Record before creating: cleanup also recovers a process interrupted mid-operation.
    ops.catalog.execute('INSERT INTO pma_leases VALUES (?,?)', (username, expires)); ops.catalog.commit()
    grant = database.replace('_', '\\_')
    try:
        sql(f"CREATE USER '{username}'@'localhost' IDENTIFIED BY PASSWORD '{digest}' WITH MAX_USER_CONNECTIONS 5;")
        sql(f"GRANT ALL PRIVILEGES ON `{grant}`.* TO '{username}'@'localhost';")
    except Exception:
        sql(f"DROP USER IF EXISTS '{username}'@'localhost';")
        raise
    return {'expires_at': expires}


if __name__ == '__main__':
    import sqlite3
    with sqlite3.connect('/var/lib/vpsmanager-agent/inventory.sqlite', timeout=30) as catalog:
        cleanup(catalog)
