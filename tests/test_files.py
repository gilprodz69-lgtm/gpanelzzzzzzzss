"""Exercise file operations, limits, private trash and upload integrity without root."""
import base64
import io
import os
from pathlib import Path
import sys
import tempfile
import unittest
import zipfile
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'agent'))
from files import operate
from validation import Rejected

class FileTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name) / 'public_html'; self.root.mkdir()
        self.private = Path(self.tmp.name) / 'private'; self.private.mkdir()
    def tearDown(self):
        self.tmp.cleanup()
    def call(self, action, **payload):
        return operate(self.root, {'action': action, **payload}, self.private)
    def test_upload_download_integrity(self):
        raw = b'a\0binary\xff' * 200000
        token = self.call('upload_begin', path='large.bin', size=len(raw))['id']
        with self.assertRaises(Rejected): self.call('upload_chunk', id=token, offset=1, content='YQ==')
        for offset in range(0,len(raw),1048576):
            self.call('upload_chunk', id=token, offset=offset, content=base64.b64encode(raw[offset:offset+1048576]).decode())
        self.call('upload_finish', id=token)
        result = b''
        while len(result)<len(raw): result += base64.b64decode(self.call('download', path='large.bin', offset=len(result))['content'])
        self.assertEqual(result, raw)
    def test_upload_no_overwrite_and_incomplete(self):
        (self.root/'exists').write_text('preserve')
        with self.assertRaises(Rejected): self.call('upload_begin', path='exists', size=1)
        token=self.call('upload_begin', path='new', size=2)['id']
        with self.assertRaises(Rejected): self.call('upload_finish', id=token)
        self.call('upload_cancel', id=token)
        self.assertFalse((self.root/'new').exists())
    def test_trash_restore_and_purge_folder(self):
        self.call('mkdir', path='assets'); (self.root/'assets/a.txt').write_text('contents')
        self.call('trash', path='assets')
        self.assertFalse((self.root/'assets').exists())
        rows=self.call('trash_list')['data']; self.assertEqual(len(rows),1)
        self.call('restore', id=rows[0]['id'])
        self.assertEqual((self.root/'assets/a.txt').read_text(),'contents')
        self.call('trash', path='assets'); self.call('purge', id=self.call('trash_list')['data'][0]['id'])
        self.assertEqual(self.call('trash_list')['data'],[])
    def test_restore_conflict_preserves_both(self):
        (self.root/'a').write_text('old');self.call('trash', path='a');(self.root/'a').write_text('new')
        with self.assertRaises(Rejected):self.call('restore', id=self.call('trash_list')['data'][0]['id'])
        self.assertEqual((self.root/'a').read_text(),'new')
    def test_copy_move_zip_extract(self):
        self.call('mkdir', path='dir');(self.root/'dir/a.txt').write_text('hello')
        self.call('copy', path='dir', target='copy'); self.call('rename', path='copy', target='renamed')
        self.call('zip', path='renamed', target='archive.zip'); self.call('unzip', path='archive.zip', target='extracted')
        self.assertEqual((self.root/'extracted/renamed/a.txt').read_text(),'hello')
        with self.assertRaises(Rejected):self.call('copy', path='dir', target='dir/nested')
    def test_zip_slip_and_symlink(self):
        for name,mode in [('../escape',0),('symlink',0o120777)]:
            archive=self.root/'evil.zip'
            with zipfile.ZipFile(archive,'w') as z:
                info=zipfile.ZipInfo(name);info.external_attr=mode<<16;z.writestr(info,'outside')
            with self.assertRaises(Rejected):self.call('unzip',path='evil.zip',target='out')
            self.assertFalse((self.root/'out').exists())
    def test_path_and_token_isolation(self):
        for action in ['trash','copy','download','info','upload_begin']:
            with self.assertRaises(Rejected):self.call(action,path='../private',target='x',size=1)
        for action in ['restore','purge','upload_chunk']:
            with self.assertRaises(Rejected):self.call(action,id='../outside')
    @unittest.skipUnless(os.name=='posix','POSIX permissions and O_NOFOLLOW')
    def test_editor_and_permissions(self):
        self.call('write',path='index.php',content=base64.b64encode(b'<?php echo 123;').decode())
        self.call('chmod',path='index.php',mode='640')
        self.assertEqual(self.call('list')['data'][0]['mode'],'640')
        with self.assertRaises(Rejected):self.call('chmod',path='index.php',mode='777')

if __name__=='__main__':unittest.main(verbosity=2)
