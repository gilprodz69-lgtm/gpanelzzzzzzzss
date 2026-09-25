"""Install or refresh the panel certificate; called only by root installer/updater."""
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'agent'))
from tls import upgrade_certificate
if __name__ == '__main__':
    try:
        upgrade_certificate('/etc/nginx/conf.d/vpsmanager-panel.conf', sys.argv[1], sys.argv[2])
    except Exception as exc:
        print('Certificado público não emitido: '+str(exc), file=sys.stderr)
        raise SystemExit(1)
