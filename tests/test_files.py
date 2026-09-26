"""Exercise file operations, limits, private trash and upload integrity without root."""
import base64
import io
import os
from pathlib import Path
import sys
import tempfile
import unittest
import zipfile
import hashlib
from unittest.mock import patch
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
    def test_batch_transfer_preflight_and_nested_selection(self):
        (self.root/'src').mkdir();(self.root/'dest').mkdir();(self.root/'src/a').write_text('a');(self.root/'src/b').write_text('b')
        self.call('copy_many',paths=['src/a','src/b'],target='dest')
        self.assertEqual((self.root/'dest/b').read_text(),'b')
        with self.assertRaises(Rejected):self.call('move_many',paths=['src/a','src/b'],target='dest')
        self.assertTrue((self.root/'src/a').exists())
        self.call('move_many',paths=['src/a','src/b'],target='')
        self.assertFalse((self.root/'src/a').exists());self.assertEqual((self.root/'a').read_text(),'a')
        with self.assertRaises(Rejected):self.call('copy_many',paths=['dest','dest/a'],target='src')
        with self.assertRaises(Rejected):self.call('move_many',paths=['dest'],target='dest')
        with self.assertRaises(Rejected):self.call('delete_tree',path='')
        self.call('delete_tree',path='dest');self.assertFalse((self.root/'dest').exists())
    def test_extract_current_folder_preserve_and_replace(self):
        (self.root/'keep.txt').write_text('original')
        with zipfile.ZipFile(self.root/'site.zip','w') as z:
            z.writestr('keep.txt','updated');z.writestr('assets/a.txt','a');z.writestr('assets/b.txt','b')
        result=self.call('unzip',path='site.zip',target='')
        self.assertEqual(result['skipped'],1);self.assertEqual((self.root/'keep.txt').read_text(),'original')
        self.assertEqual((self.root/'assets/b.txt').read_text(),'b')
        self.call('unzip',path='site.zip',target='',overwrite=True)
        self.assertEqual((self.root/'keep.txt').read_text(),'updated')
        with zipfile.ZipFile(self.root/'bad.zip','w') as z:z.writestr('new.txt','no');z.writestr('../outside','no')
        with self.assertRaises(Rejected):self.call('unzip',path='bad.zip',target='')
        self.assertFalse((self.root/'new.txt').exists())
    def test_missing_trash_does_not_create_orphan(self):
        with self.assertRaises(Rejected):self.call('trash',path='missing')
        self.assertEqual(list((self.private/'trash').iterdir()),[])
    def test_large_block_retry_integrity(self):
        raw=os.urandom(8*1024*1024)
        token=self.call('upload_begin',path='fast.bin',size=len(raw)+3)['id']
        data=base64.b64encode(raw).decode()
        self.assertEqual(self.call('upload_chunk',id=token,offset=0,content=data)['offset'],len(raw))
        self.assertEqual(self.call('upload_chunk',id=token,offset=0,content=data)['offset'],len(raw))
        with self.assertRaises(Rejected):self.call('upload_chunk',id=token,offset=0,content='YmFk')
        with self.assertRaises(Rejected):self.call('upload_chunk',id=token,offset=len(raw),content='')
        with self.assertRaises(Rejected):self.call('upload_chunk',id=token,offset=len(raw),content=base64.b64encode(raw+b'x').decode())
        self.call('upload_chunk',id=token,offset=len(raw),content='ZW5k')
        self.call('upload_finish',id=token)
        self.assertEqual(hashlib.sha256((self.root/'fast.bin').read_bytes()).digest(),hashlib.sha256(raw+b'end').digest())
    def test_large_zip_transfer_copy_extract(self):
        # A real ZIP above the previous 100 MiB limit; no allocation of the whole file.
        source=Path(self.tmp.name)/'source.zip'
        block=b'large-file-test\x00'*65536
        with zipfile.ZipFile(source,'w',zipfile.ZIP_STORED) as archive:
            with archive.open('payload.bin','w') as stream:
                for _ in range(108):stream.write(block)
        size=source.stat().st_size;self.assertGreater(size,100*1024*1024)
        token=self.call('upload_begin',path='large.zip',size=size)['id']
        with source.open('rb') as stream:
            offset=0
            while chunk:=stream.read(1048576):
                self.call('upload_chunk',id=token,offset=offset,content=base64.b64encode(chunk).decode());offset+=len(chunk)
        with patch('files.shutil.copyfileobj',side_effect=AssertionError('Same-device upload must not copy')):
            self.call('upload_finish',id=token)
        with source.open('rb') as stream:expected=hashlib.file_digest(stream,'sha256').hexdigest()
        digest=hashlib.sha256();offset=0
        while offset<size:
            chunk=base64.b64decode(self.call('download',path='large.zip',offset=offset)['content']);digest.update(chunk);offset+=len(chunk)
        self.assertEqual(expected,digest.hexdigest())
        self.call('unzip',path='large.zip',target='extracted')
        self.assertEqual((self.root/'extracted/payload.bin').stat().st_size,len(block)*108)
        self.call('zip',path='extracted',target='repacked.zip')
        self.call('copy',path='large.zip',target='copy.zip')
        self.assertEqual(self.call('info',path='copy.zip')['size'],size)
    def test_upload_storage_validation_and_cancel(self):
        for size in [-1,True,'100',1.5]:
            with self.assertRaises(Rejected):self.call('upload_begin',path='file.zip',size=size)
        with patch('files.shutil.disk_usage') as usage:
            usage.return_value.free=10
            with self.assertRaisesRegex(Rejected,'Espaço'):self.call('upload_begin',path='file.zip',size=11)
        token=self.call('upload_begin',path='large.zip',size=101*1024*1024)['id']
        self.call('upload_cancel',id=token)
        self.assertEqual(list((self.private/'uploads').iterdir()),[])
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
