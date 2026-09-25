"""Single-process authenticated agent. Deploy behind the supplied TLS Nginx listener.

Only loopback binding is supported. Every request is HMAC authenticated and replay
checked; operation IDs persist to avoid duplicate privileged operations.
"""
import hashlib
import hmac
import json
import os
import re
import signal
import sqlite3
import time
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from operations import Operations
from validation import Rejected


class Agent:
    def __init__(self, secret, state):
        if len(secret) < 32:
            raise RuntimeError('Agent secret must contain at least 32 characters')
        self.secret = secret.encode()
        Path(state).mkdir(parents=True, exist_ok=True, mode=0o700)
        self.db = sqlite3.connect(Path(state) / 'requests.sqlite')
        self.db.execute('CREATE TABLE IF NOT EXISTS nonces (nonce TEXT PRIMARY KEY, expires INTEGER)')
        self.db.execute('CREATE TABLE IF NOT EXISTS requests (id TEXT PRIMARY KEY, digest TEXT, status TEXT, result TEXT)')
        self.ops = Operations(state)

    def authenticate(self, headers, body):
        stamp = headers.get('X-Timestamp', '')
        nonce = headers.get('X-Nonce', '')
        if not stamp.isdigit() or abs(time.time() - int(stamp)) > 60 or not re.fullmatch(r'[a-f0-9]{32}', nonce):
            raise Rejected('Invalid request timestamp or nonce')
        expected = hmac.new(self.secret, stamp.encode() + b'\n' + nonce.encode() + b'\n' + body, hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, headers.get('X-Signature', '')):
            raise Rejected('Invalid request signature')
        try:
            self.db.execute('DELETE FROM nonces WHERE expires < ?', (int(time.time()),))
            self.db.execute('INSERT INTO nonces VALUES (?,?)', (nonce, int(time.time()) + 120))
            self.db.commit()
        except sqlite3.IntegrityError:
            self.db.rollback()
            raise Rejected('Replay rejected')

    def execute(self, data):
        request = data.get('request_id', '')
        operation = data.get('operation')
        payload = data.get('payload')
        if not isinstance(request, str) or not re.fullmatch(r'[a-zA-Z0-9_-]{16,100}', request) or not isinstance(payload, dict):
            raise Rejected('Invalid request')
        # Bind idempotency to exact operation and payload, not only caller-supplied ID.
        digest = hashlib.sha256(json.dumps([operation, payload], sort_keys=True).encode()).hexdigest()
        previous = self.db.execute('SELECT digest,status,result FROM requests WHERE id=?', (request,)).fetchone()
        if previous:
            if previous[0] != digest:
                raise Rejected('Idempotency key reused with different payload')
            if previous[1] != 'completed':
                raise Rejected('Prior operation needs reconciliation; it will not be executed twice')
            return json.loads(previous[2])
        self.db.execute('INSERT INTO requests VALUES (?,?,?,NULL)', (request, digest, 'started'))
        self.db.commit()
        result = self.ops.execute(operation, payload)
        self.db.execute("UPDATE requests SET status='completed',result=? WHERE id=?", (json.dumps(result), request))
        self.db.commit()
        return result


class Handler(BaseHTTPRequestHandler):
    server_version = 'VPSManagerAgent/0.1'
    def do_POST(self):
        self.connection.settimeout(15)
        try:
            if self.path != '/v1/execute':
                self.respond(404, {'error': 'Not found'}); return
            length = int(self.headers.get('Content-Length', '0'))
            if length < 1 or length > 2097152:
                raise Rejected('Invalid body size')
            body = self.rfile.read(length)
            self.server.agent.authenticate(self.headers, body)
            data = json.loads(body)
            if not isinstance(data, dict):
                raise Rejected('JSON object required')
            def expired(signum, frame):
                raise TimeoutError('Operation exceeded 170 seconds; reconcile before retry')
            signal.signal(signal.SIGALRM, expired); signal.alarm(170)
            try:
                result = self.server.agent.execute(data)
            finally:
                signal.alarm(0)
            self.respond(200, result)
        except (Rejected, ValueError, KeyError) as exc:
            self.respond(422, {'error': str(exc)[:300]})
        except Exception as exc:
            self.log_error('Operation failed: %s', type(exc).__name__)
            self.respond(500, {'error': 'Agent operation failed. Inspect journal and reconcile state.'})

    def respond(self, status, result):
        body = json.dumps(result).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers(); self.wfile.write(body)


if __name__ == '__main__':
    if os.name != 'posix' or os.geteuid() != 0:
        raise SystemExit('The agent requires a Linux root service; the web panel must remain unprivileged.')
    secret = Path(os.getenv('VPM_AGENT_SECRET_FILE', '/etc/vpsmanager/agent.secret')).read_text().strip()
    server = HTTPServer(('127.0.0.1', 9081), Handler)
    server.agent = Agent(secret, os.getenv('VPM_AGENT_STATE', '/var/lib/vpsmanager-agent'))
    server.serve_forever()
