"""Pure validation and authentication tests: no privileged OS changes."""
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import time
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'agent'))
from validation import domain, identifier, inside, cron, integer, Rejected
from server import Agent


class AgentTests(unittest.TestCase):
    def test_domain(self):
        self.assertEqual(domain('site.example.com'), 'site.example.com')
        for value in ['a.com;id', 'a.com\nserver {}', '*.example.com', '-foo.com', 'a.com/path', 'a.com$(id)']:
            with self.assertRaises(Rejected): domain(value)

    def test_identifier(self):
        for value in ['--privileged', "foo'; DROP TABLE x", 'foo bar', '/etc/passwd', 'root\n']:
            with self.assertRaises(Rejected): identifier(value)

    def test_path(self):
        with tempfile.TemporaryDirectory() as directory:
            for value in ['../etc/passwd', '/etc/passwd', 'folder/../../secret', 'a\\b', 'a\x00']:
                with self.assertRaises(Rejected): inside(directory, value)
            self.assertEqual(inside(directory, 'index.php'), Path(directory) / 'index.php')

    def test_cron(self):
        self.assertEqual(cron('*/5 0-23 * * 0,6'), '*/5 0-23 * * 0,6')
        for value in ['* * * * * root reboot', '61 * * * *', '*/0 * * * *', '* * * * 7', '* * * * $(id)', '@reboot']:
            with self.assertRaises(Rejected): cron(value)

    def test_bool_not_an_id(self):
        with self.assertRaises(Rejected): integer(True)

    def test_hmac_and_replay(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = Agent('a' * 64, directory)
            body = b'{"operation":"metrics"}'
            stamp = str(int(time.time())); nonce = 'b' * 32
            signature = hmac.new(b'a' * 64, f'{stamp}\n{nonce}\n'.encode() + body, hashlib.sha256).hexdigest()
            headers = {'X-Timestamp': stamp, 'X-Nonce': nonce, 'X-Signature': signature}
            agent.authenticate(headers, body)
            with self.assertRaises(Rejected): agent.authenticate(headers, body)
            headers['X-Nonce'] = 'c' * 32
            with self.assertRaises(Rejected): agent.authenticate(headers, body)
            agent.db.close(); agent.ops.catalog.close()

    def test_idempotency_payload_binding(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = Agent('a' * 64, directory)
            calls = []
            agent.ops.execute = lambda operation, payload: calls.append(operation) or {'ok': True}
            data = {'operation': 'test', 'payload': {}, 'request_id': 'request-123456789'}
            self.assertEqual(agent.execute(data), {'ok': True})
            self.assertEqual(agent.execute(data), {'ok': True})
            self.assertEqual(len(calls), 1)
            with self.assertRaises(Rejected): agent.execute({**data, 'payload': {'changed': True}})
            agent.db.close(); agent.ops.catalog.close()

    def test_arbitrary_operation_denied(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = Agent('a' * 64, directory)
            with self.assertRaises(Rejected): agent.ops.execute('shell_exec', {'command': 'id'})
            agent.db.close(); agent.ops.catalog.close()


if __name__ == '__main__': unittest.main(verbosity=2)
