import sys
from pathlib import Path
from unittest.mock import patch
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'agent'))
import tls
from validation import Rejected
class TlsTests(unittest.TestCase):
    def test_ipv4_uses_shortlived_profile(self):
        with patch('tls.run') as run:
            tls.issue('8.8.8.8','test@example.com')
        args=run.call_args.args[0]
        self.assertIn('--ip-address',args);self.assertIn('shortlived',args);self.assertNotIn('-d',args)
    def test_domain_uses_domain_identifier(self):
        with patch('tls.run') as run:tls.issue('site.example.com','test@example.com')
        self.assertIn('-d',run.call_args.args[0]);self.assertNotIn('--ip-address',run.call_args.args[0])
    def test_https_redirect_and_acme_exception(self):
        content=tls.secure_config('server { listen 80; server_name site.example.com; location / { return 200; } }','site.example.com','/cert.pem','/key.pem')
        self.assertIn('listen 443 ssl;',content);self.assertIn('ssl_certificate /cert.pem;',content)
        self.assertIn('location /.well-known/acme-challenge/',content)
        self.assertIn('location / { return 301 https://site.example.com$request_uri; }',content)
    def test_bad_host_and_nonpublic_ip_rejected(self):
        for host in ['127.0.0.1','192.168.1.1','8.8.8.8;id','x.com\ninclude x;','http://site.com']:
            with self.assertRaises(Rejected):tls.host(host)
if __name__=='__main__':unittest.main(verbosity=2)
