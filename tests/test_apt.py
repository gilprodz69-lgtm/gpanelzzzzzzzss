"""Installer lock handling: bounded waiting and no retry for unrelated failures."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]

class AptTests(unittest.TestCase):
    def invoke(self, behavior, wait=4):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp)
            fake = path / 'apt-get'
            fake.write_text('''#!/bin/bash
echo "$*" >> "$VPM_TEST_CALLS"
''' + behavior)
            fake.chmod(0o755)
            result = subprocess.run(['bash','-c','set -Eeuo pipefail; source "$1"; vpm_apt update; echo FINISHED','test',str(ROOT/'scripts/apt-safe.sh')],
                env={**os.environ,'PATH':tmp+':'+os.environ['PATH'],'VPM_TEST_CALLS':str(path/'calls'),'VPM_APT_WAIT_SECONDS':str(wait)},capture_output=True,text=True,timeout=15)
            return result,(path/'calls').read_text().splitlines()

    def test_lock_released(self):
        result,calls=self.invoke('''if [[ $(wc -l < "$VPM_TEST_CALLS") == 1 ]]; then
echo 'E: Could not get lock /var/lib/apt/lists/lock. It is held by process 123'; exit 100
fi
exit 0
''',6)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(len(calls),2)
        self.assertIn('DPkg::Lock::Timeout=',calls[0])
        self.assertIn('Aguardando',result.stdout)

    def test_unrelated_failure_not_retried(self):
        result,calls=self.invoke("echo 'E: Unable to locate package missing'; exit 100\n")
        self.assertEqual(result.returncode,100)
        self.assertEqual(len(calls),1)
        self.assertNotIn('FINISHED',result.stdout)

    def test_lock_timeout(self):
        result,calls=self.invoke("echo 'E: Could not get lock /var/lib/dpkg/lock-frontend. It is held by process 123'; exit 100\n",1)
        self.assertEqual(result.returncode,100)
        self.assertIn('Nenhum bloqueio foi removido',result.stderr)

    @unittest.skipUnless(os.getenv('GITHUB_ACTIONS')=='true' and hasattr(os,'geteuid') and os.geteuid()==0,'Disposable root CI only')
    def test_actual_dpkg_lock(self):
        # POSIX record locks used by APT, not BSD flock locks. No package is removed.
        import sys
        holder=subprocess.Popen([sys.executable,'-c',"import fcntl,time; f=open('/var/lib/dpkg/lock-frontend','a'); fcntl.lockf(f,fcntl.LOCK_EX); print('locked',flush=True); time.sleep(3)"],stdout=subprocess.PIPE,text=True)
        self.assertEqual(holder.stdout.readline().strip(),'locked')
        try:
            result=subprocess.run(['bash','-c','set -Eeuo pipefail; source "$1"; vpm_apt install -y --no-upgrade bash','test',str(ROOT/'scripts/apt-safe.sh')],env={**os.environ,'VPM_APT_WAIT_SECONDS':'30','DEBIAN_FRONTEND':'noninteractive'},capture_output=True,text=True,timeout=50)
            self.assertEqual(result.returncode,0,result.stdout+result.stderr)
            self.assertIn('Waiting for cache lock',result.stdout)
        finally:
            holder.wait(timeout=5);holder.stdout.close()

if __name__=='__main__': unittest.main()
